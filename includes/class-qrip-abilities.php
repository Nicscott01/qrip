<?php
defined( 'ABSPATH' ) || exit;

/** Agent-facing WordPress abilities for QR codes. */
class QRip_Abilities {
	private const LIST_CODES = 'qrip/list-codes';
	private const GET_CODE = 'qrip/get-code';
	private const CREATE_CODE = 'qrip/create-code';
	private const UPDATE_CODE = 'qrip/update-code';
	private const CHANGE_SLUG = 'qrip/change-slug';
	private const REGENERATE_IMAGES = 'qrip/regenerate-images';
	private const SLUG_LENGTH = 100;

	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		if ( ! function_exists( 'wp_register_ability' ) ) { return; }
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	public static function names() {
		return array( self::LIST_CODES, self::GET_CODE, self::CREATE_CODE, self::UPDATE_CODE, self::CHANGE_SLUG, self::REGENERATE_IMAGES );
	}

	public static function register_category() {
		wp_register_ability_category( 'qrip', array( 'label' => 'QR codes', 'description' => "Create and manage this site's first-party QR codes." ) );
	}

	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( 'qrip_manage_codes' === $cap ) { return array( 'manage_options' ); }
		if ( ! in_array( $cap, array( 'qrip_read_code', 'qrip_edit_code' ), true ) ) { return $caps; }
		$id = (int) ( $args[0] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post ) { return array( 'manage_options' ); }
		if ( QRip_Core::POST_TYPE !== $post->post_type ) { return array( 'do_not_allow' ); }
		$post_cap = 'qrip_read_code' === $cap ? 'read_post' : 'edit_post';
		return array_values( array_unique( array_merge( array( 'manage_options' ), map_meta_cap( $post_cap, $user_id, $id ) ) ) );
	}

	public static function can_manage() { return current_user_can( 'qrip_manage_codes' ); }

	public static function can_read( $input ) {
		$id = self::lookup_id( (array) $input );
		return $id ? current_user_can( 'qrip_read_code', $id ) : self::can_manage();
	}

	public static function can_edit( $input ) {
		$input = (array) $input;
		return current_user_can( 'qrip_edit_code', (int) ( $input['id'] ?? 0 ) );
	}

	public static function can_create( $input ) { return self::can_manage() && self::can_select_media( (array) $input ); }
	public static function can_update( $input ) { return self::can_edit( $input ) && self::can_select_media( (array) $input ); }

	private static function can_select_media( array $input ) {
		if ( QRip_Core::DESTINATION_MEDIA !== ( $input['destination_type'] ?? null ) && ! isset( $input['attachment_id'] ) ) { return true; }
		return current_user_can( 'upload_files' ) && ( ! isset( $input['attachment_id'] ) || current_user_can( 'read_post', (int) $input['attachment_id'] ) );
	}

	private static function object_schema( array $properties, ?array $required = null ) {
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required ?? array_keys( $properties ), 'additionalProperties' => false );
	}

	private static function nullable( array $schema ) {
		$schema['type'] = array( $schema['type'], 'null' );
		return $schema;
	}

	public static function register() {
		$id = array( 'type' => 'integer', 'minimum' => 1 );
		$slug = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => self::SLUG_LENGTH, 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$' );
		$version = array( 'type' => 'string', 'maxLength' => 16, 'pattern' => '^[a-f0-9]{16}$' );
		$dry_run = array( 'type' => 'boolean', 'default' => false );
		$editable = array(
			'name' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ),
			'destination_type' => array( 'type' => 'string', 'maxLength' => 5, 'enum' => QRip_Core::destination_types() ),
			'destination_url' => array( 'type' => 'string', 'maxLength' => 2048, 'format' => 'uri' ),
			'attachment_id' => $id,
			'status' => array( 'type' => 'string', 'maxLength' => 6, 'enum' => array( QRip_Core::STATUS_ACTIVE, QRip_Core::STATUS_PAUSED ) ),
			'notes' => array( 'type' => 'string', 'maxLength' => 5000 ),
			'utm' => self::object_schema( array_fill_keys( QRip_Core::utm_keys(), array( 'type' => 'string', 'maxLength' => 200 ) ), array() ),
		);
		$code = self::code_schema( $id, $version, $editable );
		$write_result = self::write_schema( $code, $id, $version, $editable );
		$nonnegative = array( 'type' => 'integer', 'minimum' => 0 );
		$per_page = $id + array( 'maximum' => 50, 'default' => 20 );

		self::register_ability( self::LIST_CODES, 'List QR codes', 'Find QR codes by name, slug, destination URL, or Media Library title or filename. Returns active and paused codes, newest edits first. This read can recreate missing public image files.',
			self::object_schema( array(
				'search' => array( 'type' => 'string', 'maxLength' => 200 ),
				'status' => array( 'type' => 'string', 'maxLength' => 6, 'enum' => array_merge( $editable['status']['enum'], array( 'any' ) ), 'default' => 'any' ),
				'page' => $id + array( 'default' => 1 ), 'per_page' => $per_page,
			), array() ) + array( 'default' => (object) array() ),
			self::object_schema( array( 'codes' => array( 'type' => 'array', 'items' => $code ), 'total' => $nonnegative, 'page' => $id, 'per_page' => $id + array( 'maximum' => 50 ), 'total_pages' => $nonnegative ) ),
			'list_codes', 'can_manage', true, false, true );
		self::register_ability( self::GET_CODE, 'Get QR code', 'Get a QR code by exactly one ID or slug, including its version, current destination and public images. Retired codes are unavailable. This read can recreate missing public image files.',
			self::object_schema( array( 'id' => $id, 'slug' => $slug ), array() ), self::object_schema( array( 'code' => $code ) ),
			'get_code', 'can_read', true, false, true );

		$create_fields = $editable;
		$create_fields['destination_type']['default'] = QRip_Core::DESTINATION_URL;
		$create_fields['status']['default'] = QRip_Core::STATUS_ACTIVE;
		self::register_ability( self::CREATE_CODE, 'Create QR code', 'Create a QR code and return its public SVG and PNG links. Supply an absolute HTTP(S) destination URL or an attachment ID for a media destination. Omit the slug to derive it from the name. Existing slugs cause an error. Use dry_run to preview. Undo pauses the code.',
			self::object_schema( $create_fields + array( 'slug' => $slug, 'dry_run' => $dry_run ), array( 'name' ) ), $write_result,
			'create_code', 'can_create', false, false, false );
		self::register_ability( self::UPDATE_CODE, 'Update QR code', 'Update selected QR code fields, including its destination and status, while preserving its managed URL. Omitted fields retain their values. Use dry_run to preview and expected_version to detect intervening edits. Use change-slug to edit the URL.',
			self::object_schema( array( 'id' => $id, 'expected_version' => $version ) + $editable + array( 'dry_run' => $dry_run ), array( 'id' ) ), $write_result,
			'update_code', 'can_update', false, true, true );
		self::register_ability( self::CHANGE_SLUG, 'Change QR code URL', 'Move a QR code to a new managed URL. Every printed or shared copy of the old QR code stops working. The old slug becomes reusable. Use dry_run to preview or update-code to change a destination while keeping existing copies working.',
			self::object_schema( array( 'id' => $id, 'slug' => $slug, 'expected_version' => $version, 'dry_run' => $dry_run ), array( 'id', 'slug' ) ), $write_result,
			'change_slug', 'can_edit', false, true, true );
		self::register_ability( self::REGENERATE_IMAGES, 'Regenerate QR images', 'Force QRip to render the public SVG and PNG for the current managed URL. The QR record and styling stay the same. The files are derived from the managed URL and have no undo step.',
			self::object_schema( array( 'id' => $id ) ), $write_result, 'regenerate_images', 'can_edit', false, false, true );
	}

	private static function register_ability( $name, $label, $description, $input, $output, $execute, $permission, $readonly, $destructive, $idempotent ) {
		wp_register_ability( $name, array(
			'label' => $label, 'description' => $description, 'category' => 'qrip',
			'input_schema' => $input, 'output_schema' => $output,
			'permission_callback' => array( __CLASS__, $permission ), 'execute_callback' => array( __CLASS__, $execute ),
			'meta' => array(
				'annotations' => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $idempotent ),
				'show_in_rest' => false, 'public' => false, 'mcp' => array( 'public' => false, 'type' => 'tool' ),
			),
		) );
	}

	private static function code_schema( array $id, array $version, array $editable ) {
		// Admin-created text can predate the narrower agent input limits.
		$string = array( 'type' => 'string' );
		$uri = $string + array( 'format' => 'uri' );
		$positive = array( 'type' => 'integer', 'minimum' => 1 );
		$boolean = array( 'type' => 'boolean' );
		$image = self::object_schema( array(
			'url' => self::nullable( $uri ), 'available' => $boolean, 'mime_type' => $string + array( 'enum' => array( 'image/svg+xml', 'image/png' ) ),
			'width' => $positive, 'height' => $positive, 'bytes' => self::nullable( $positive ), 'download_filename' => $string,
		) );
		return self::object_schema( array(
			'id' => $id, 'name' => $string, 'slug' => $string, 'managed_url' => $uri,
			'status' => $editable['status'], 'destination_type' => $editable['destination_type'],
			'destination_url' => self::nullable( $string ), 'attachment_id' => self::nullable( $id ),
			'utm' => self::object_schema( array_fill_keys( QRip_Core::utm_keys(), $string ) ),
			'redirects_to' => self::nullable( $uri ), 'destination_available' => $boolean, 'notes' => $string,
			'scan_count' => array( 'type' => 'integer', 'minimum' => 0 ),
			'last_scan_at' => self::nullable( $string + array( 'format' => 'date-time' ) ),
			'created_at' => $string + array( 'format' => 'date-time' ), 'updated_at' => $string + array( 'format' => 'date-time' ),
			'version' => $version, 'edit_url' => $uri,
			'images' => self::object_schema( array( 'svg' => $image, 'png' => $image ) ),
			'image_settings' => self::object_schema( array(
				'error_correction' => $string, 'size_px' => $positive, 'quiet_zone_px' => $positive,
				'foreground' => $string, 'background' => $string, 'styling_supported' => $boolean,
			) ),
		) );
	}

	private static function write_schema( array $code, array $id, array $version, array $editable ) {
		$string = array( 'type' => 'string' );
		$value = array( 'type' => array( 'string', 'integer', 'null' ) );
		$change = self::object_schema( array( 'field' => $string, 'before' => $value, 'after' => $value ) );
		$restore_fields = array( 'id' => $id, 'expected_version' => $version );
		foreach ( array_keys( $editable + array( 'slug' => true ) ) as $field ) {
			$restore_fields[ $field ] = $code['properties'][ $field ];
		}
		$restore = self::object_schema( array(
			'ability' => $string + array( 'enum' => array( self::UPDATE_CODE, self::CHANGE_SLUG ) ),
			'input' => self::object_schema( $restore_fields, array( 'id', 'expected_version' ) ),
			'description' => $string,
		) );
		return self::object_schema( array(
			'dry_run' => array( 'type' => 'boolean' ), 'changed' => array( 'type' => 'boolean' ),
			'changes' => array( 'type' => 'array', 'items' => $change ), 'code' => self::nullable( $code ), 'restore' => self::nullable( $restore ),
		) );
	}

	public static function list_codes( $input = null ) {
		$input = (array) $input;
		$search = sanitize_text_field( $input['search'] ?? '' );
		$page = (int) min( PHP_INT_MAX, $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? 20 );
		$status = $input['status'] ?? 'any';
		$status_query = array( 'relation' => 'OR', array( 'key' => QRip_Core::META_STATUS, 'compare' => 'NOT EXISTS' ) );
		if ( QRip_Core::STATUS_PAUSED === $status ) {
			$status_query = array( array( 'key' => QRip_Core::META_STATUS, 'value' => $status ) );
		} else {
			$status_query[] = 'any' === $status
				? array( 'key' => QRip_Core::META_STATUS, 'value' => QRip_Core::STATUS_DELETED, 'compare' => '!=' )
				: array( 'key' => QRip_Core::META_STATUS, 'value' => array( QRip_Core::STATUS_ACTIVE, '' ), 'compare' => 'IN' );
		}
		$all = array( 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true );
		$query = $all + array( 'post_type' => QRip_Core::POST_TYPE, 'post_status' => 'any' );
		$title_search = array( 's' => $search, 'search_columns' => array( 'post_title' ) );
		$ids = get_posts( $query + array( 'meta_query' => $status_query ) + $title_search );
		if ( '' !== $search ) {
			$attachments = $all + array( 'post_type' => 'attachment', 'post_status' => 'inherit' );
			$attachment_ids = array_merge( get_posts( $attachments + $title_search ), get_posts( $attachments + array(
				'meta_query' => array( array( 'key' => '_wp_attached_file', 'value' => $search, 'compare' => 'LIKE' ) ),
			) ) );
			$search_query = array( 'relation' => 'OR',
				array( 'key' => QRip_Core::META_SLUG, 'value' => $search, 'compare' => 'LIKE' ),
				array( 'key' => QRip_Core::META_DESTINATION, 'value' => $search, 'compare' => 'LIKE' ),
			);
			if ( $attachment_ids ) { $search_query[] = array( 'key' => QRip_Core::META_ATTACHMENT_ID, 'value' => array_unique( $attachment_ids ), 'compare' => 'IN' ); }
			$ids = array_merge( $ids, get_posts( $query + array( 'meta_query' => array( 'relation' => 'AND', $status_query, $search_query ) ) ) );
		}

		$modified = array();
		foreach ( array_unique( $ids ) as $id ) {
			$post = get_post( $id );
			if ( $post && current_user_can( 'qrip_read_code', $id ) ) { $modified[ $id ] = $post->post_modified_gmt; }
		}
		uksort( $modified, static function ( $left, $right ) use ( $modified ) {
			return ( $modified[ $right ] <=> $modified[ $left ] ) ?: ( $right <=> $left );
		} );
		$total = count( $modified );
		$total_pages = (int) ceil( $total / $per_page );
		$codes = array();
		if ( $page <= $total_pages ) {
			foreach ( array_slice( array_keys( $modified ), ( $page - 1 ) * $per_page, $per_page ) as $id ) {
				$record = self::load_record( $id );
				if ( ! is_wp_error( $record ) ) { $codes[] = self::serialize_code( $record ); }
			}
		}
		return array( 'codes' => $codes, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'total_pages' => $total_pages );
	}

	private static function lookup_id( array $input ) {
		return isset( $input['id'] ) ? (int) $input['id'] : ( isset( $input['slug'] ) ? QRip_Core::find_by_slug( $input['slug'] ) : 0 );
	}

	private static function load_record( $id ) {
		$record = $id > 0 ? QRip_Core::record( $id ) : false;
		if ( ! $record ) { return new WP_Error( 'qrip_not_found', __( 'QR code not found.', 'qrip' ), array( 'status' => 404 ) ); }
		if ( QRip_Core::STATUS_DELETED === $record['status'] ) { return new WP_Error( 'qrip_code_retired', __( 'This QR code has been permanently retired.', 'qrip' ), array( 'status' => 410 ) ); }
		return $record;
	}

	public static function get_code( $input ) {
		$input = (array) $input;
		if ( isset( $input['id'] ) === isset( $input['slug'] ) ) { return new WP_Error( 'qrip_invalid_lookup', __( 'Provide exactly one of id or slug.', 'qrip' ) ); }
		$record = self::load_record( self::lookup_id( $input ) );
		return is_wp_error( $record ) ? $record : array( 'code' => self::serialize_code( $record ) );
	}

	private static function editable_state( array $record ) {
		$is_media = QRip_Core::DESTINATION_MEDIA === $record['destination_type'];
		$utm = array();
		foreach ( QRip_Core::utm_keys() as $key ) { $utm[ $key ] = $is_media ? '' : (string) ( $record['utm'][ $key ] ?? $record[ $key ] ?? '' ); }
		return array(
			'name' => (string) $record['name'], 'slug' => (string) $record['slug'], 'status' => $record['status'], 'notes' => (string) $record['notes'],
			'destination_type' => $record['destination_type'], 'destination_url' => $is_media ? null : (string) $record['destination'],
			'attachment_id' => $is_media ? ( (int) $record['attachment_id'] ?: null ) : null, 'utm' => $utm,
		);
	}

	private static function version( array $state ) { return substr( hash( 'sha256', (string) wp_json_encode( $state ) ), 0, 16 ); }

	private static function utc_date( $date ) { return gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $date . ' UTC' ) ); }

	private static function serialize_code( array $record, ?array $images = null ) {
		$id = $record['id'];
		$post = get_post( $id );
		$state = self::editable_state( $record );
		$destination = QRip_Core::resolve_destination( $record );
		$available = ! is_wp_error( $destination );
		return array( 'id' => $id ) + $state + array(
			'managed_url' => QRip_Core::managed_url( $record['slug'] ), 'redirects_to' => $available ? $destination : null, 'destination_available' => $available,
			'scan_count' => max( 0, $record['scan_count'] ), 'last_scan_at' => $record['last_scan_at'] ? self::utc_date( $record['last_scan_at'] ) : null,
			'created_at' => self::utc_date( $post->post_date_gmt ?? $record['created'] ), 'updated_at' => self::utc_date( $post->post_modified_gmt ?? $record['updated'] ),
			'version' => self::version( $state ), 'edit_url' => admin_url( 'admin.php?page=qrip&action=edit&id=' . $id ),
			'images' => $images ?? QRip_Images::ensure( $record )['images'], 'image_settings' => QRip_Images::settings(),
		);
	}

	private static function core_input( array $input ) {
		$destination = $input['destination_url'] ?? '';
		// esc_url_raw() can supply a scheme for a bare host. Require an absolute URL from agents.
		return array( 'destination' => QRip_Core::valid_destination( $destination ) ? $destination : '' ) + $input + (array) ( $input['utm'] ?? array() );
	}

	private static function change_values( array $state ) {
		$utm = $state['utm'] ?? array();
		unset( $state['utm'] );
		if ( QRip_Core::DESTINATION_URL === ( $state['destination_type'] ?? null ) ) {
			foreach ( $utm as $key => $value ) { $state[ 'utm.' . $key ] = $value; }
		}
		return $state;
	}

	private static function changes( array $before, array $after ) {
		$changes = array();
		foreach ( array_keys( $before + $after ) as $field ) {
			$old = $before[ $field ] ?? null;
			$new = $after[ $field ] ?? null;
			if ( $old !== $new ) { $changes[] = array( 'field' => $field, 'before' => $old, 'after' => $new ); }
		}
		return $changes;
	}

	private static function write_result( $dry_run, array $changes, $code, $restore = null, $written = false ) {
		return array( 'dry_run' => $dry_run, 'changed' => $written || ! empty( $changes ), 'changes' => $changes, 'code' => $code, 'restore' => $restore );
	}

	private static function save_and_read( array $input, $id = 0 ) {
		// WordPress unslashes values passed through update_post_meta().
		foreach ( array_merge( array( 'notes' ), QRip_Core::utm_keys() ) as $key ) {
			if ( isset( $input[ $key ] ) ) { $input[ $key ] = wp_slash( $input[ $key ] ); }
		}
		$result = QRip_Core::save_record( $input, $id );
		return is_wp_error( $result ) ? $result : self::load_record( $result );
	}

	/** Reject destination fields that the effective destination type would silently ignore. */
	private static function destination_mismatch( array $input, $type ) {
		if ( QRip_Core::DESTINATION_URL === $type && isset( $input['attachment_id'] ) ) {
			return new WP_Error( 'qrip_destination_mismatch', __( 'attachment_id applies only to Media Library destinations. Set destination_type to media.', 'qrip' ) );
		}
		if ( QRip_Core::DESTINATION_MEDIA === $type && ( isset( $input['destination_url'] ) || array_filter( array_map( 'strval', (array) ( $input['utm'] ?? array() ) ), 'strlen' ) ) ) {
			return new WP_Error( 'qrip_destination_mismatch', __( 'destination_url and UTM values apply only to web-address destinations. Set destination_type to url.', 'qrip' ) );
		}
		return null;
	}

	public static function create_code( $input ) {
		$input = (array) $input;
		$mismatch = self::destination_mismatch( $input, $input['destination_type'] ?? QRip_Core::DESTINATION_URL );
		if ( $mismatch ) { return $mismatch; }
		$input['slug'] = $input['slug'] ?? QRip_Core::normalize_slug( $input['name'] );
		$data = self::core_input( $input );
		$prepared = QRip_Core::prepare_record( $data );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		if ( strlen( $prepared['slug'] ) > self::SLUG_LENGTH ) {
			return new WP_Error( 'qrip_slug', __( 'Choose a slug of 100 characters or fewer.', 'qrip' ) );
		}
		if ( rest_sanitize_boolean( $input['dry_run'] ?? false ) ) {
			return self::write_result( true, self::changes( array(), self::change_values( self::editable_state( $prepared ) ) ), null );
		}
		$saved = self::save_and_read( $data );
		if ( is_wp_error( $saved ) ) { return $saved; }
		$code = self::serialize_code( $saved );
		$restore = array(
			'ability' => self::UPDATE_CODE,
			'input' => array( 'id' => $code['id'], 'expected_version' => $code['version'], 'status' => QRip_Core::STATUS_PAUSED ),
			'description' => 'QRip has no delete ability. Undoing a create pauses the code so /go/{slug} returns 410; the record and its slug stay reserved. Permanent retirement is an admin-only action in wp-admin.',
		);
		return self::write_result( false, self::changes( array(), self::change_values( self::editable_state( $saved ) ) ), $code, $restore );
	}

	private static function editable_record( array $input ) {
		$record = self::load_record( (int) $input['id'] );
		if ( is_wp_error( $record ) || ! isset( $input['expected_version'] ) ) { return $record; }
		$current_version = self::version( self::editable_state( $record ) );
		if ( $current_version !== $input['expected_version'] ) {
			return new WP_Error( 'qrip_version_conflict', __( 'The QR code changed. Fetch its current version before retrying.', 'qrip' ), array( 'status' => 409, 'current_version' => $current_version ) );
		}
		return $record;
	}

	public static function update_code( $input ) {
		$input = (array) $input;
		$record = self::editable_record( $input );
		if ( is_wp_error( $record ) ) { return $record; }
		$before = self::editable_state( $record );
		$edits = array_intersect_key( $input, $before );
		unset( $edits['slug'] );
		if ( ! $edits ) { return new WP_Error( 'qrip_nothing_to_update', __( 'Provide at least one editable field.', 'qrip' ) ); }
		$mismatch = self::destination_mismatch( $edits, $edits['destination_type'] ?? $before['destination_type'] );
		if ( $mismatch ) { return $mismatch; }
		if ( isset( $edits['utm'] ) ) { $edits['utm'] = array_replace( $before['utm'], (array) $edits['utm'] ); }
		return self::edit( $record, $before, array_replace( $before, $edits ), rest_sanitize_boolean( $input['dry_run'] ?? false ), self::UPDATE_CODE );
	}

	public static function change_slug( $input ) {
		$input = (array) $input;
		$record = self::editable_record( $input );
		if ( is_wp_error( $record ) ) { return $record; }
		$dry_run = rest_sanitize_boolean( $input['dry_run'] ?? false );
		$before = self::editable_state( $record );
		if ( $before['slug'] === $input['slug'] ) {
			return self::write_result( $dry_run, array(), self::serialize_code( $record, QRip_Images::existing( $record ) ) );
		}
		$after = array_replace( $before, array( 'slug' => $input['slug'] ) );
		return self::edit( $record, $before, $after, $dry_run, self::CHANGE_SLUG );
	}

	private static function edit( array $record, array $before, array $after, $dry_run, $ability ) {
		$id = $record['id'];
		$data = self::core_input( $after );
		$prepared = QRip_Core::prepare_record( $data, $id );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$old_values = self::change_values( $before );
		$projected = self::changes( $old_values, self::change_values( self::editable_state( $prepared ) ) );
		if ( $dry_run || ! $projected ) {
			return self::write_result( $dry_run, $projected, self::serialize_code( $record, QRip_Images::existing( $record ) ) );
		}

		$saved = self::save_and_read( $data, $id );
		if ( is_wp_error( $saved ) ) { return $saved; }
		$code = self::serialize_code( $saved );
		$changes = self::changes( $old_values, self::change_values( self::editable_state( $saved ) ) );
		$restore = $changes ? self::restore_edit( $ability, $before, array( 'id' => $id, 'expected_version' => $code['version'] ) ) : null;
		return self::write_result( false, $changes, $code, $restore );
	}

	private static function restore_edit( $ability, array $before, array $input ) {
		if ( self::CHANGE_SLUG === $ability ) {
			$input['slug'] = $before['slug'];
			$description = 'Moves the code back to its previous URL. Fails if that slug was taken in the meantime.';
		} else {
			unset( $before['slug'] );
			if ( QRip_Core::DESTINATION_MEDIA === $before['destination_type'] ) {
				unset( $before['destination_url'], $before['utm'] );
			} else {
				unset( $before['attachment_id'] );
			}
			$input += $before;
			$description = 'Restores every editable field to its value before this update. Fails with qrip_version_conflict if the code changed again since.';
		}
		return array( 'ability' => $ability, 'input' => $input, 'description' => $description );
	}

	public static function regenerate_images( $input ) {
		$input = (array) $input;
		$record = self::load_record( (int) $input['id'] );
		if ( is_wp_error( $record ) ) { return $record; }
		$before = QRip_Images::existing( $record );
		$result = QRip_Images::ensure( $record, true );
		$old_urls = array();
		$new_urls = array();
		foreach ( $result['images'] as $format => $image ) {
			$field = 'images.' . $format . '.url';
			$old_urls[ $field ] = $before[ $format ]['url'];
			$new_urls[ $field ] = $image['url'];
		}
		return self::write_result( false, self::changes( $old_urls, $new_urls ), self::serialize_code( $record, $result['images'] ), null, ! empty( $result['written'] ) );
	}
}
