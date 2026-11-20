<?php

use PHPUnit\Framework\TestCase;

final class QRip_AbilitiesTest extends TestCase {
	private string $uploads;
	private const BASE_URL = 'https://example.test/uploads';
	private const CREATE = array( 'name' => 'Campaign Guide', 'destination_url' => 'https://example.com/guide' );

	protected function setUp(): void {
		$this->uploads = dirname( __DIR__ ) . '/vendor/.qrip-test-' . bin2hex( random_bytes( 8 ) );
		$GLOBALS['qrip_test_uploads'] = array( 'basedir' => $this->uploads, 'baseurl' => self::BASE_URL, 'error' => false );
		foreach ( array( 'upload_calls', 'posts', 'meta', 'post_types', 'attachment_urls', 'object_caps', 'hooks', 'abilities', 'ability_categories' ) as $fixture ) {
			$GLOBALS[ 'qrip_test_' . $fixture ] = array();
		}
		$GLOBALS['qrip_test_capabilities'] = array( 'manage_options' => true, 'read' => true, 'edit_posts' => true, 'upload_files' => true );
		$GLOBALS['qrip_test_current_user'] = 7;
		$GLOBALS['wpdb'] = new QRip_Test_DB();
		QRip_Abilities::init();
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['qrip_test_uploads'], $GLOBALS['qrip_test_home_url'], $GLOBALS['qrip_test_hooks'], $GLOBALS['qrip_test_object_caps'], $GLOBALS['wpdb'] );
		if ( ! is_dir( $this->uploads ) ) { return; }
		$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->uploads, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $entries as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) { rmdir( $entry->getPathname() ); }
			else { unlink( $entry->getPathname() ); }
		}
		rmdir( $this->uploads );
	}

	private function ability( string $name ): array { return $GLOBALS['qrip_test_abilities'][ $name ]; }

	private function run_ability( string $name, $input = null, ?string $error = null ) {
		$ability = $this->ability( $name );
		$input ??= $ability['input_schema']['default'];
		$this->assert_schema( $input, $ability['input_schema'], $name . ' input' );
		$this->assertTrue( $ability['permission_callback']( $input ), $name );
		$result = $ability['execute_callback']( $input );
		if ( null !== $error ) {
			$this->assertInstanceOf( WP_Error::class, $result, $name );
			$this->assertSame( $error, $result->get_error_code(), $name );
		} else {
			$this->assertNotInstanceOf( WP_Error::class, $result, $name );
			$this->assert_schema( $result, $ability['output_schema'], $name . ' output' );
		}
		return $result;
	}

	private function assert_schema( $value, array $schema, string $path ): void {
		$type = match ( true ) {
			is_array( $value ) => array_is_list( $value ) ? 'array' : 'object',
			$value instanceof stdClass => 'object',
			is_int( $value ) => 'integer',
			is_bool( $value ) => 'boolean',
			is_string( $value ) => 'string',
			null === $value => 'null',
			default => get_debug_type( $value ),
		};
		$this->assertContains( $type, (array) $schema['type'], $path );
		if ( 'object' === $type ) {
			$properties = (array) $value;
			foreach ( $schema['required'] ?? array() as $key ) { $this->assertArrayHasKey( $key, $properties, $path ); }
			foreach ( $properties as $key => $property ) {
				if ( false === ( $schema['additionalProperties'] ?? true ) ) { $this->assertArrayHasKey( $key, $schema['properties'], $path ); }
				if ( isset( $schema['properties'][ $key ] ) ) { $this->assert_schema( $property, $schema['properties'][ $key ], $path . '.' . $key ); }
			}
		}
		if ( 'array' === $type && isset( $schema['items'] ) ) {
			foreach ( $value as $index => $item ) { $this->assert_schema( $item, $schema['items'], $path . '[' . $index . ']' ); }
		}
		if ( isset( $schema['enum'] ) ) { $this->assertContains( $value, $schema['enum'], $path ); }
		if ( 'integer' === $type && isset( $schema['minimum'] ) ) { $this->assertGreaterThanOrEqual( $schema['minimum'], $value, $path ); }
		if ( 'integer' === $type && isset( $schema['maximum'] ) ) { $this->assertLessThanOrEqual( $schema['maximum'], $value, $path ); }
		if ( 'string' !== $type ) { return; }
		if ( isset( $schema['pattern'] ) ) { $this->assertMatchesRegularExpression( '~' . $schema['pattern'] . '~u', $value, $path ); }
		if ( isset( $schema['minLength'] ) ) { $this->assertGreaterThanOrEqual( $schema['minLength'], mb_strlen( $value ), $path ); }
		if ( isset( $schema['maxLength'] ) ) { $this->assertLessThanOrEqual( $schema['maxLength'], mb_strlen( $value ), $path ); }
		if ( 'uri' === ( $schema['format'] ?? '' ) ) { $this->assertMatchesRegularExpression( '~^[a-z][a-z0-9+.-]*:[^\s<>]+$~i', $value, $path ); }
		if ( 'date-time' === ( $schema['format'] ?? '' ) ) { $this->assertMatchesRegularExpression( '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$~', $value, $path ); }
	}

	private function file_path( array $image ): string { return $this->uploads . substr( $image['url'], strlen( self::BASE_URL ) ); }

	private function add_attachment(): int {
		$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Findable White Paper' ) );
		$GLOBALS['qrip_test_attachment_urls'][ $id ] = self::BASE_URL . '/guide.pdf';
		update_post_meta( $id, '_wp_attached_file', '2026/09/brochure-archive.pdf' );
		return $id;
	}

	public function test_registration_uses_six_private_abilities_and_strict_schemas(): void {
		$names = array( 'qrip/list-codes', 'qrip/get-code', 'qrip/create-code', 'qrip/update-code', 'qrip/change-slug', 'qrip/regenerate-images' );
		$this->assertSame( $names, QRip_Abilities::names() );
		$this->assertSame( $names, array_keys( $GLOBALS['qrip_test_abilities'] ) );
		$this->assertSame( array( 'qrip' ), array_keys( $GLOBALS['qrip_test_ability_categories'] ) );
		$this->assertSame( 'QR codes', $GLOBALS['qrip_test_ability_categories']['qrip']['label'] );
		$annotations = array( array( true, false, true ), array( true, false, true ), array( false, false, false ), array( false, true, true ), array( false, true, true ), array( false, false, true ) );
		$required = array( array(), array(), array( 'name' ), array( 'id' ), array( 'id', 'slug' ), array( 'id' ) );
		foreach ( $names as $index => $name ) {
			$ability = $this->ability( $name );
			$this->assertDoesNotMatchRegularExpression( '/delete|trash|retire/', $name );
			$this->assertSame( 'qrip', $ability['category'] );
			$this->assertSame( array_combine( array( 'readonly', 'destructive', 'idempotent' ), $annotations[ $index ] ), $ability['meta']['annotations'] );
			$this->assertSame( array( 'public' => false, 'type' => 'tool' ), $ability['meta']['mcp'] );
			$this->assertFalse( $ability['meta']['show_in_rest'] );
			$this->assertFalse( $ability['meta']['public'] );
			$this->assertSame( $required[ $index ], $ability['input_schema']['required'] );
			foreach ( array( 'input_schema', 'output_schema' ) as $direction ) {
				$this->assertSame( 'object', $ability[ $direction ]['type'] );
				$this->assertFalse( $ability[ $direction ]['additionalProperties'] );
			}
			$this->assertSame( array_keys( $ability['output_schema']['properties'] ), $ability['output_schema']['required'] );
			foreach ( $ability['input_schema']['properties'] as $schema ) {
				if ( 'string' === $schema['type'] ) { $this->assertArrayHasKey( 'maxLength', $schema ); }
				if ( 'integer' === $schema['type'] ) { $this->assertArrayHasKey( 'minimum', $schema ); }
			}
		}
		$this->assertArrayNotHasKey( 'slug', $this->ability( 'qrip/update-code' )['input_schema']['properties'] );
		$this->assertSame( array( 'codes' => array(), 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0 ), $this->run_ability( 'qrip/list-codes' ) );
	}

	public function test_permissions_respect_site_record_and_attachment_caps(): void {
		$first = wp_insert_post( array( 'post_type' => QRip_Core::POST_TYPE, 'post_title' => 'First' ) );
		$second = wp_insert_post( array( 'post_type' => QRip_Core::POST_TYPE, 'post_title' => 'Second' ) );
		$post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'A regular post' ) );
		$attachment = $this->add_attachment();
		$media = array( 'destination_type' => 'media', 'attachment_id' => $attachment );
		$id_inputs = array( 'qrip/get-code' => array(), 'qrip/update-code' => array( 'name' => 'Updated' ), 'qrip/change-slug' => array( 'slug' => 'updated' ), 'qrip/regenerate-images' => array() );
		$GLOBALS['qrip_test_capabilities']['manage_options'] = false;
		foreach ( $id_inputs + array( 'qrip/list-codes' => array(), 'qrip/create-code' => self::CREATE ) as $name => $input ) {
			$this->assertFalse( $this->ability( $name )['permission_callback']( isset( $id_inputs[ $name ] ) ? $input + array( 'id' => $first ) : $input ) );
		}
		$this->assertFalse( $this->ability( 'qrip/get-code' )['permission_callback']( array( 'slug' => 'unknown' ) ) );
		$GLOBALS['qrip_test_capabilities']['manage_options'] = true;
		foreach ( $id_inputs as $name => $input ) {
			$this->assertFalse( $this->ability( $name )['permission_callback']( array( 'id' => $post ) + $input ) );
			$this->run_ability( $name, array( 'id' => 999 ) + $input, 'qrip_not_found' );
		}
		$GLOBALS['qrip_test_object_caps']['edit_post'][ $first ] = array( 'do_not_allow' );
		$can_update = $this->ability( 'qrip/update-code' )['permission_callback'];
		$this->assertFalse( $can_update( array( 'id' => $first, 'notes' => 'new' ) ) );
		$this->assertTrue( $can_update( array( 'id' => $second, 'notes' => 'new' ) ) );
		$GLOBALS['qrip_test_capabilities']['upload_files'] = false;
		$can_create = $this->ability( 'qrip/create-code' )['permission_callback'];
		$this->assertFalse( $can_create( array( 'name' => 'Media' ) + $media ) );
		$this->assertFalse( $can_update( array( 'id' => $second, 'destination_type' => 'media' ) ) );
		$GLOBALS['qrip_test_capabilities']['upload_files'] = true;
		$GLOBALS['qrip_test_object_caps']['read_post'][ $attachment ] = array( 'do_not_allow' );
		$this->assertFalse( $can_create( array( 'name' => 'Media' ) + $media ) );
		$this->assertFalse( $can_update( array( 'id' => $second, 'attachment_id' => $attachment ) ) );
		unset( $GLOBALS['qrip_test_object_caps']['read_post'][ $attachment ] );
		$this->assertTrue( $can_update( array( 'id' => $second ) + $media ) );
		$GLOBALS['qrip_test_capabilities']['manage_options'] = false;
		foreach ( $id_inputs as $name => $input ) { $this->assertFalse( $this->ability( $name )['permission_callback']( array( 'id' => 999 ) + $input ) ); }
	}

	public function test_create_get_list_and_edits_return_stable_public_images(): void {
		$create = $this->run_ability( 'qrip/create-code', self::CREATE );
		$code = $create['code'];
		$id = $code['id'];
		$this->assertSame( 'campaign-guide', $code['slug'] );
		$this->assertSame( 'https://example.test/go/campaign-guide', $code['managed_url'] );
		$this->assertSame( '2026-09-16T12:00:00Z', $code['created_at'] );
		$this->assertSame( $code['created_at'], $code['updated_at'] );
		$this->assertNull( $code['last_scan_at'] );
		$this->assertSame( 0, $code['scan_count'] );
		$this->assertSame( QRip_Images::settings(), $code['image_settings'] );
		$before = array();
		foreach ( $code['images'] as $format => $image ) {
			$hash = substr( hash( 'sha256', $code['managed_url'] . '|1|' . $format ), 0, 12 );
			$this->assertSame( self::BASE_URL . '/qrip/' . $id . '/campaign-guide-qr-' . $hash . '.' . $format, $image['url'] );
			$this->assertTrue( $image['available'] );
			$this->assertSame( 2048, $image['width'] );
			$this->assertSame( 2048, $image['height'] );
			$this->assertSame( 'campaign-guide-qr.' . $format, $image['download_filename'] );
			$file = $this->file_path( $image );
			$this->assertFileExists( $file );
			$before[ $format ] = file_get_contents( $file );
			$this->assertSame( strlen( $before[ $format ] ), $image['bytes'] );
			$this->assertSame( QRip_Core::qr_result( $code['slug'], $format )->getString(), $before[ $format ] );
		}
		$png = imagecreatefromstring( $before['png'] );
		$this->assertSame( array( 2048, 2048 ), array( imagesx( $png ), imagesy( $png ) ) );
		$corner = imagecolorsforindex( $png, imagecolorat( $png, 0, 0 ) );
		$this->assertGreaterThanOrEqual( 250, min( $corner['red'], $corner['green'], $corner['blue'] ) );
		$this->assertSame( 0, $corner['alpha'] );
		$this->assertStringContainsString( 'width="2048px" height="2048px"', $before['svg'] );
		$this->assertSame( '<?php // Silence is golden.', file_get_contents( $this->uploads . '/qrip/index.php' ) );
		$this->assertSame( '<?php // Silence is golden.', file_get_contents( $this->uploads . '/qrip/' . $id . '/index.php' ) );
		$this->assertSame( $code, $this->run_ability( 'qrip/get-code', (object) array( 'slug' => $code['slug'] ) )['code'] );
		$this->assertSame( array( $code ), $this->run_ability( 'qrip/list-codes' )['codes'] );
		$updated = $this->run_ability( 'qrip/update-code', array( 'id' => $id, 'name' => 'New name', 'destination_url' => 'https://example.com/revised', 'status' => 'paused' ) )['code'];
		$this->assertSame( 'https://example.com/revised', $updated['redirects_to'] );
		foreach ( $updated['images'] as $format => $image ) {
			$this->assertSame( $code['images'][ $format ]['url'], $image['url'] );
			$this->assertSame( $before[ $format ], file_get_contents( $this->file_path( $image ) ) );
			$this->assertSame( 'new-name-qr.' . $format, $image['download_filename'] );
		}
		foreach ( $GLOBALS['qrip_test_upload_calls'] as $call ) { $this->assertSame( array( null, false ), $call ); }
	}

	public function test_slug_changes_and_site_url_changes_replace_the_old_files(): void {
		$code = $this->run_ability( 'qrip/create-code', self::CREATE )['code'];
		$id = $code['id'];
		$original_paths = array_map( array( $this, 'file_path' ), $code['images'] );
		$stale = dirname( $original_paths['svg'] ) . '/stale-file';
		file_put_contents( $stale, 'old data' );
		$moved = $this->run_ability( 'qrip/change-slug', array( 'id' => $id, 'slug' => 'new-slug', 'expected_version' => $code['version'] ) );
		$this->assertSame( array( array( 'field' => 'slug', 'before' => 'campaign-guide', 'after' => 'new-slug' ) ), $moved['changes'] );
		$this->assertSame( 0, QRip_Core::find_by_slug( $code['slug'] ) );
		$this->assertFileDoesNotExist( $stale );
		foreach ( $moved['code']['images'] as $format => $image ) {
			$this->assertNotSame( $code['images'][ $format ]['url'], $image['url'] );
			$this->assertFileExists( $this->file_path( $image ) );
			$this->assertFileDoesNotExist( $original_paths[ $format ] );
		}
		$restore = $moved['restore'];
		$this->assertSame( 'qrip/change-slug', $restore['ability'] );
		$restored = $this->run_ability( $restore['ability'], $restore['input'] )['code'];
		$this->assertSame( $code['slug'], $restored['slug'] );
		$this->assertSame( $code['version'], $restored['version'] );
		$this->assertSame( $code['images'], $restored['images'] );

		$GLOBALS['qrip_test_home_url'] = 'https://new.example.test';
		$rehosted = $this->run_ability( 'qrip/get-code', array( 'id' => $id ) )['code'];
		$this->assertSame( 'https://new.example.test/go/campaign-guide', $rehosted['managed_url'] );
		foreach ( $rehosted['images'] as $format => $image ) {
			$this->assertNotSame( $restored['images'][ $format ]['url'], $image['url'] );
			$this->assertFileExists( $this->file_path( $image ) );
			$this->assertFileDoesNotExist( $original_paths[ $format ] );
		}
	}

	public function test_regeneration_recreates_missing_files_and_retirement_removes_them(): void {
		$code = $this->run_ability( 'qrip/create-code', self::CREATE )['code'];
		$input = array( 'id' => $code['id'] );
		foreach ( $code['images'] as $image ) { unlink( $this->file_path( $image ) ); }
		$generated = $this->run_ability( 'qrip/regenerate-images', $input );
		$this->assertTrue( $generated['changed'] );
		$this->assertFalse( $generated['dry_run'] );
		$this->assertNull( $generated['restore'] );
		$this->assertSame( $code['images'], $generated['code']['images'] );
		$this->assertSame( array(
			array( 'field' => 'images.svg.url', 'before' => null, 'after' => $code['images']['svg']['url'] ),
			array( 'field' => 'images.png.url', 'before' => null, 'after' => $code['images']['png']['url'] ),
		), $generated['changes'] );
		foreach ( $code['images'] as $image ) { $this->assertFileExists( $this->file_path( $image ) ); }
		$forced = $this->run_ability( 'qrip/regenerate-images', $input );
		$this->assertTrue( $forced['changed'] );
		$this->assertSame( array(), $forced['changes'] );
		$this->assertSame( $code['version'], $forced['code']['version'] );
		$this->assertTrue( QRip_Core::retire_record( $code['id'] ) );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/qrip/' . $code['id'] );
		$this->run_ability( 'qrip/get-code', array( 'slug' => $code['slug'] ), 'qrip_code_retired' );
		$this->run_ability( 'qrip/update-code', $input + array( 'notes' => 'new' ), 'qrip_code_retired' );
		$this->run_ability( 'qrip/change-slug', $input + array( 'slug' => 'new' ), 'qrip_code_retired' );
		$this->run_ability( 'qrip/regenerate-images', $input, 'qrip_code_retired' );
		$this->run_ability( 'qrip/create-code', self::CREATE, 'qrip_slug_duplicate' );
	}

	public function test_unavailable_uploads_return_null_image_links(): void {
		$GLOBALS['qrip_test_uploads']['error'] = 'Failed to configure uploads.';
		$code = $this->run_ability( 'qrip/create-code', self::CREATE )['code'];
		$this->assertDirectoryDoesNotExist( $this->uploads );
		$GLOBALS['qrip_test_uploads']['error'] = false;
		mkdir( $this->uploads );
		file_put_contents( $this->uploads . '/qrip', 'A regular file blocks directory creation.' );
		$regenerated = $this->run_ability( 'qrip/regenerate-images', array( 'id' => $code['id'] ) );
		$this->assertFalse( $regenerated['changed'] );
		$this->assertSame( $code['images'], $regenerated['code']['images'] );
		foreach ( $code['images'] as $image ) {
			$this->assertFalse( $image['available'] );
			$this->assertNull( $image['url'] );
			$this->assertNull( $image['bytes'] );
		}
	}

	public function test_update_journals_normalized_fields_and_restores_url_and_media_state(): void {
		$attachment = $this->add_attachment();
		$notes = 'Keep this \\ in the record';
		$code = $this->run_ability( 'qrip/create-code', self::CREATE + array( 'utm' => array( 'utm_source' => 'print', 'utm_medium' => 'qr' ), 'notes' => $notes ) )['code'];
		$this->assertSame( $notes, $code['notes'] );
		$input = array( 'id' => $code['id'] );
		$edit = $this->run_ability( 'qrip/update-code', $input + array( 'name' => '<b>Renamed</b>', 'status' => 'paused', 'utm' => (object) array( 'utm_medium' => '', 'utm_campaign' => 'fall' ) ) );
		$this->assertSame( array(
			array( 'field' => 'name', 'before' => 'Campaign Guide', 'after' => 'Renamed' ),
			array( 'field' => 'status', 'before' => 'active', 'after' => 'paused' ),
			array( 'field' => 'utm.utm_medium', 'before' => 'qr', 'after' => '' ),
			array( 'field' => 'utm.utm_campaign', 'before' => '', 'after' => 'fall' ),
		), $edit['changes'] );
		$this->assertSame( 'https://example.com/guide?utm_source=print&utm_campaign=fall', $edit['code']['redirects_to'] );
		$this->assertSame( $notes, $edit['code']['notes'] );
		$conflict = $this->run_ability( 'qrip/update-code', $input + array( 'notes' => 'stale', 'expected_version' => $code['version'] ), 'qrip_version_conflict' );
		$this->assertSame( array( 'status' => 409, 'current_version' => $edit['code']['version'] ), $conflict->get_error_data() );
		$this->run_ability( 'qrip/change-slug', $input + array( 'slug' => 'old-conflict', 'expected_version' => $code['version'] ), 'qrip_version_conflict' );
		$undo = $edit['restore'];
		$this->assertSame( 'qrip/update-code', $undo['ability'] );
		$this->assertSame( $edit['code']['version'], $undo['input']['expected_version'] );
		$this->assertSame( $code, $this->run_ability( $undo['ability'], $undo['input'] )['code'] );

		$this->run_ability( 'qrip/update-code', $input + array( 'destination_type' => 'media' ), 'qrip_attachment' );
		$media = $this->run_ability( 'qrip/update-code', $input + array( 'destination_type' => 'media', 'attachment_id' => $attachment ) );
		$this->assertNull( $media['code']['destination_url'] );
		$this->assertSame( $attachment, $media['code']['attachment_id'] );
		$this->assertSame( array_fill_keys( QRip_Core::utm_keys(), '' ), $media['code']['utm'] );
		$this->assertSame( self::BASE_URL . '/guide.pdf', $media['code']['redirects_to'] );
		$this->assertContains( array( 'field' => 'utm.utm_source', 'before' => 'print', 'after' => null ), $media['changes'] );
		$this->run_ability( 'qrip/update-code', $input + array( 'destination_type' => 'url' ), 'qrip_destination' );
		$undo = $media['restore'];
		$this->assertSame( $code['utm'], $undo['input']['utm'] );
		$this->assertArrayNotHasKey( 'attachment_id', $undo['input'] );
		$restored = $this->run_ability( $undo['ability'], $undo['input'] );
		$this->assertSame( $code, $restored['code'] );
		$restore_media = $restored['restore'];
		$this->assertArrayNotHasKey( 'utm', $restore_media['input'] );
		$this->assertArrayNotHasKey( 'destination_url', $restore_media['input'] );
		$this->assertSame( $media['code'], $this->run_ability( $restore_media['ability'], $restore_media['input'] )['code'] );
	}

	public function test_destination_fields_that_would_be_ignored_are_rejected(): void {
		$attachment = $this->add_attachment();
		$this->run_ability( 'qrip/create-code', self::CREATE + array( 'attachment_id' => $attachment ), 'qrip_destination_mismatch' );
		$this->run_ability( 'qrip/create-code', array( 'name' => 'Media', 'destination_type' => 'media', 'attachment_id' => $attachment, 'utm' => array( 'utm_source' => 'print' ) ), 'qrip_destination_mismatch' );
		$this->run_ability( 'qrip/create-code', array( 'name' => 'Media', 'destination_type' => 'media', 'attachment_id' => $attachment, 'destination_url' => 'https://example.com/' ), 'qrip_destination_mismatch' );
		$this->assertSame( array( $attachment ), array_keys( $GLOBALS['qrip_test_posts'] ) );
		$url = $this->run_ability( 'qrip/create-code', self::CREATE )['code'];
		$this->run_ability( 'qrip/update-code', array( 'id' => $url['id'], 'attachment_id' => $attachment ), 'qrip_destination_mismatch' );
		$media = $this->run_ability( 'qrip/create-code', array( 'name' => 'Media', 'destination_type' => 'media', 'attachment_id' => $attachment ) )['code'];
		$this->run_ability( 'qrip/update-code', array( 'id' => $media['id'], 'destination_url' => 'https://example.com/' ), 'qrip_destination_mismatch' );
		$this->run_ability( 'qrip/update-code', array( 'id' => $media['id'], 'utm' => array( 'utm_campaign' => 'fall' ) ), 'qrip_destination_mismatch' );
		$this->assertFalse( $this->run_ability( 'qrip/update-code', array( 'id' => $media['id'], 'utm' => array( 'utm_campaign' => '' ) ) )['changed'] );
		$switched = $this->run_ability( 'qrip/update-code', array( 'id' => $media['id'], 'destination_type' => 'url', 'destination_url' => 'https://example.com/file', 'utm' => array( 'utm_campaign' => 'fall' ) ) );
		$this->assertSame( 'https://example.com/file?utm_campaign=fall', $switched['code']['redirects_to'] );
	}

	public function test_dry_runs_and_noop_edits_leave_posts_meta_and_images_untouched(): void {
		$dry_create = $this->run_ability( 'qrip/create-code', self::CREATE + array( 'dry_run' => true ) );
		$this->assertTrue( $dry_create['dry_run'] );
		$this->assertTrue( $dry_create['changed'] );
		$this->assertNull( $dry_create['code'] );
		$this->assertNull( $dry_create['restore'] );
		$this->assertSame( array( 'field' => 'name', 'before' => null, 'after' => 'Campaign Guide' ), $dry_create['changes'][0] );
		$this->assertSame( array(), $GLOBALS['qrip_test_posts'] );
		$this->assertSame( array(), $GLOBALS['qrip_test_meta'] );
		$id = QRip_Core::save_record( array( 'name' => 'Campaign Guide', 'slug' => 'campaign-guide', 'destination' => 'https://example.com/guide', 'notes' => 'Unchanged' ) );
		$posts = serialize( $GLOBALS['qrip_test_posts'] );
		$meta = $GLOBALS['qrip_test_meta'];
		$input = array( 'id' => $id );
		$update = $this->run_ability( 'qrip/update-code', $input + array( 'name' => 'Proposed', 'dry_run' => true ) );
		$this->assertSame( 'Campaign Guide', $update['code']['name'] );
		$this->assertSame( array( array( 'field' => 'name', 'before' => 'Campaign Guide', 'after' => 'Proposed' ) ), $update['changes'] );
		$this->assertNull( $update['restore'] );
		$move = $this->run_ability( 'qrip/change-slug', $input + array( 'slug' => 'proposed', 'dry_run' => true ) );
		$this->assertSame( 'campaign-guide', $move['code']['slug'] );
		$this->assertSame( array( array( 'field' => 'slug', 'before' => 'campaign-guide', 'after' => 'proposed' ) ), $move['changes'] );
		$this->assertNull( $move['restore'] );
		foreach ( array( 'qrip/update-code' => array( 'notes' => '<b>Unchanged</b>' ), 'qrip/change-slug' => array( 'slug' => 'campaign-guide' ) ) as $ability => $fields ) {
			$noop = $this->run_ability( $ability, $input + $fields );
			$this->assertFalse( $noop['changed'] );
			$this->assertSame( array(), $noop['changes'] );
			$this->assertNull( $noop['restore'] );
		}
		$this->run_ability( 'qrip/update-code', $input, 'qrip_nothing_to_update' );
		$this->assertSame( $posts, serialize( $GLOBALS['qrip_test_posts'] ) );
		$this->assertSame( $meta, $GLOBALS['qrip_test_meta'] );
		$this->assertDirectoryDoesNotExist( $this->uploads );
	}

	public function test_create_undo_pauses_and_get_accepts_exactly_one_lookup(): void {
		$this->run_ability( 'qrip/create-code', array( 'name' => str_repeat( 'a', 101 ) ) + self::CREATE, 'qrip_slug' );
		$this->run_ability( 'qrip/create-code', array( 'destination_url' => 'file:///tmp/guide' ) + self::CREATE, 'qrip_destination' );
		$created = $this->run_ability( 'qrip/create-code', self::CREATE );
		$restore = $created['restore'];
		$this->assertSame( 'qrip/update-code', $restore['ability'] );
		$this->assertSame( array( 'id' => $created['code']['id'], 'expected_version' => $created['code']['version'], 'status' => 'paused' ), $restore['input'] );
		$paused = $this->run_ability( $restore['ability'], $restore['input'] );
		$this->assertSame( 'paused', $paused['code']['status'] );
		$this->assertSame( $created['code']['managed_url'], $paused['code']['managed_url'] );
		$this->assertSame( $paused['code']['id'], QRip_Core::find_by_slug( $created['code']['slug'] ) );
		$this->run_ability( 'qrip/get-code', (object) array(), 'qrip_invalid_lookup' );
		$this->run_ability( 'qrip/get-code', array( 'id' => $paused['code']['id'], 'slug' => 'campaign-guide' ), 'qrip_invalid_lookup' );
		$this->run_ability( 'qrip/get-code', array( 'slug' => 'unknown' ), 'qrip_not_found' );
	}

	public function test_listing_unions_search_results_sorts_before_pagination_and_checks_read_access(): void {
		$attachment = $this->add_attachment();
		$older = QRip_Core::save_record( array( 'name' => 'Findable Title', 'slug' => 'findable-title', 'destination' => 'https://example.com' ) );
		$middle = QRip_Core::save_record( array( 'name' => 'A different name', 'slug' => 'other', 'destination' => 'https://example.com/findable' ) );
		$newer = QRip_Core::save_record( array( 'name' => 'Media case', 'slug' => 'media', 'status' => 'paused', 'destination_type' => 'media', 'attachment_id' => $attachment ) );
		$deleted = QRip_Core::save_record( array( 'name' => 'Findable retired', 'slug' => 'retired', 'destination' => 'https://example.com' ) );
		$this->assertTrue( QRip_Core::retire_record( $deleted ) );
		unset( $GLOBALS['qrip_test_meta'][ $middle ][ QRip_Core::META_STATUS ] );
		$GLOBALS['qrip_test_posts'][ $middle ]->post_modified_gmt = '2026-09-17 12:00:00';
		$GLOBALS['qrip_test_posts'][ $newer ]->post_modified_gmt = '2026-09-18 12:00:00';
		$page = $this->run_ability( 'qrip/list-codes', array( 'search' => 'findable', 'per_page' => 2 ) );
		$this->assertSame( array( $newer, $middle ), array_column( $page['codes'], 'id' ) );
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( 2, $page['total_pages'] );
		$page = $this->run_ability( 'qrip/list-codes', array( 'search' => 'findable', 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( array( $older ), array_column( $page['codes'], 'id' ) );
		$page = $this->run_ability( 'qrip/list-codes', array( 'status' => 'active', 'search' => 'findable' ) );
		$this->assertSame( array( $middle, $older ), array_column( $page['codes'], 'id' ) );
		$page = $this->run_ability( 'qrip/list-codes', array( 'status' => 'paused', 'search' => 'brochure-archive.pdf' ) );
		$this->assertSame( array( $newer ), array_column( $page['codes'], 'id' ) );
		$this->assertSame( array(), $this->run_ability( 'qrip/list-codes', array( 'page' => PHP_INT_MAX, 'per_page' => 50 ) )['codes'] );
		$GLOBALS['qrip_test_object_caps']['read_post'][ $newer ] = array( 'do_not_allow' );
		$this->assertFalse( $this->ability( 'qrip/get-code' )['permission_callback']( array( 'slug' => 'media' ) ) );
		$page = $this->run_ability( 'qrip/list-codes', array( 'search' => 'findable' ) );
		$this->assertSame( array( $middle, $older ), array_column( $page['codes'], 'id' ) );
		$this->assertSame( 2, $page['total'] );
	}
}
