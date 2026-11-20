<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'QRIP_VERSION', 'test' );
define( 'QRIP_URL', 'https://example.test/wp-content/plugins/qrip/' );
class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class QRip_Test_Die extends RuntimeException {
	public $status;
	public function __construct( $message, $status = 0 ) { $this->status = $status; parent::__construct( $message ); }
}
class QRip_Test_Redirect extends RuntimeException {
	public $url;
	public function __construct( $url ) { $this->url = $url; parent::__construct( $url ); }
}
class QRip_Test_Query {
	public $is_404 = false;
	public function set_404() { $this->is_404 = true; }
}
class QRip_Test_DB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $fail_operation = '';
	public $queries = array();
	private $snapshot_posts;
	private $snapshot_meta;
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) { $replacement = is_int( $arg ) || ctype_digit( (string) $arg ) ? (string) (int) $arg : "'" . addslashes( (string) $arg ) . "'"; $query = preg_replace( '/%[ds]/', $replacement, $query, 1 ); }
		return $query;
	}
	public function query( $query ) {
		$this->queries[] = $query;
		if ( 'START TRANSACTION' === $query ) { $this->snapshot_posts = serialize( $GLOBALS['qrip_test_posts'] ); $this->snapshot_meta = serialize( $GLOBALS['qrip_test_meta'] ); }
		if ( 'ROLLBACK' === $query ) { $GLOBALS['qrip_test_posts'] = unserialize( $this->snapshot_posts ); $GLOBALS['qrip_test_meta'] = unserialize( $this->snapshot_meta ); }
		if ( 'COMMIT' === $query ) { $this->snapshot_posts = null; $this->snapshot_meta = null; }
		if ( false !== strpos( $query, 'CAST(meta_value AS UNSIGNED) + 1' ) && preg_match( '/post_id = (\d+)/', $query, $matches ) ) { $id = (int) $matches[1]; $GLOBALS['qrip_test_meta'][ $id ][ QRip_Core::META_SCANS ] = (string) ( (int) ( $GLOBALS['qrip_test_meta'][ $id ][ QRip_Core::META_SCANS ] ?? 0 ) + 1 ); }
		return 'fail-query' === $this->fail_operation ? false : true;
	}
	public function get_row( $query ) { preg_match( '/ID\s*=\s*(\d+)/', $query, $matches ); $id = (int) ( $matches[1] ?? 0 ); $post = $GLOBALS['qrip_test_posts'][ $id ] ?? null; return $post ? (object) array( 'ID' => $id, 'post_type' => $post->post_type ) : null; }
	public function get_col() { return array(); }
	public function update( $table, $data, $where ) {
		if ( 'fail-update' === $this->fail_operation ) { return false; }
		$id = (int) ( $where['ID'] ?? 0 );
		if ( isset( $GLOBALS['qrip_test_posts'][ $id ] ) ) { foreach ( $data as $key => $value ) { $GLOBALS['qrip_test_posts'][ $id ]->{$key} = $value; } }
		return 1;
	}
	public function delete( $table, $where ) {
		if ( 'fail-delete' === $this->fail_operation ) { return false; }
		$id = (int) ( $where['post_id'] ?? 0 );
		if ( $table === $this->postmeta && $id ) { unset( $GLOBALS['qrip_test_meta'][ $id ] ); }
		return 1;
	}
	public function insert( $table, $data ) {
		if ( 'fail-insert' === $this->fail_operation ) { return false; }
		$id = (int) $data['post_id']; $GLOBALS['qrip_test_meta'][ $id ][ $data['meta_key'] ] = $data['meta_value']; return 1;
	}
}
class WP_Query {
	public $posts = array();
	public function __construct( $args = array() ) {
		foreach ( $GLOBALS['qrip_test_posts'] ?? array() as $post ) {
			if ( isset( $args['post_type'] ) && $args['post_type'] !== $post->post_type ) { continue; }
			if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && $args['post_status'] !== ( $post->post_status ?? 'publish' ) ) { continue; }
			if ( ! empty( $args['s'] ) && false === stripos( $post->post_title, $args['s'] ) ) { continue; }
			if ( isset( $args['meta_key'] ) && ( $GLOBALS['qrip_test_meta'][ $post->ID ][ $args['meta_key'] ] ?? null ) !== ( $args['meta_value'] ?? null ) ) { continue; }
			if ( isset( $args['meta_query'] ) && ! self::matches_meta_query( $post->ID, $args['meta_query'] ) ) { continue; }
			$this->posts[] = $post;
		}
		if ( ( $args['posts_per_page'] ?? -1 ) > 0 ) { $this->posts = array_slice( $this->posts, 0, $args['posts_per_page'] ); }
		if ( 'ids' === ( $args['fields'] ?? '' ) ) { $this->posts = array_column( $this->posts, 'ID' ); }
	}
	private static function matches_meta_query( $id, $query ) {
		$relation = strtoupper( $query['relation'] ?? 'AND' ); $results = array();
		foreach ( $query as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) { continue; }
			if ( isset( $clause['key'] ) ) {
				$actual = $GLOBALS['qrip_test_meta'][ $id ][ $clause['key'] ] ?? null; $compare = strtoupper( $clause['compare'] ?? '=' ); $expected = $clause['value'] ?? null;
				$results[] = match ( $compare ) {
					'NOT EXISTS' => null === $actual,
					'!=' => null !== $actual && $actual !== $expected,
					'LIKE' => null !== $actual && false !== stripos( (string) $actual, (string) $expected ),
					'IN' => null !== $actual && in_array( (string) $actual, array_map( 'strval', (array) $expected ), true ),
					default => $actual === $expected,
				};
			} else { $results[] = self::matches_meta_query( $id, $clause ); }
		}
		return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_title( $value ) { $value = strtolower( $value ); $value = preg_replace( '/[^a-z0-9]+/', '-', $value ); return trim( $value, '-' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_file_name( $value ) { return basename( $value ); }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function remove_accents( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return ( $GLOBALS['qrip_test_home_url'] ?? 'https://example.test' ) . $path; }
function __( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_attr( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function esc_html__( $value ) { return esc_html( $value ); }
function esc_html_e( $value ) { echo esc_html( $value ); }
function esc_attr_e( $value ) { echo esc_attr( $value ); }
function add_query_arg( $args, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function rest_sanitize_boolean( $value ) { return is_string( $value ) && in_array( strtolower( $value ), array( 'false', '0' ), true ) ? false : (bool) $value; }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_current_user_id() { return $GLOBALS['qrip_test_current_user'] ?? 7; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function current_time( $type, $gmt = false ) { return $gmt ? '2026-09-16 12:00:00' : '2026-09-16 08:00:00'; }
function get_post( $id ) { return $GLOBALS['qrip_test_posts'][ (int) $id ] ?? null; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['qrip_test_meta'][ (int) $id ][ $key ] ?? ''; }
function wp_cache_delete() {}
function clean_post_cache() {}
function get_post_type( $id ) { return $GLOBALS['qrip_test_post_types'][ $id ] ?? ( $GLOBALS['qrip_test_posts'][ (int) $id ]->post_type ?? false ); }
function get_query_var( $query_var ) { return $GLOBALS['qrip_test_query_vars'][ $query_var ] ?? ''; }
function status_header( $status ) { $GLOBALS['qrip_test_status'] = $status; }
function nocache_headers() { $GLOBALS['qrip_test_nocache'] = true; }
function wp_redirect( $url ) { throw new QRip_Test_Redirect( $url ); }
function update_post_meta( $id, $key, $value ) { $GLOBALS['qrip_test_meta'][ (int) $id ][ $key ] = wp_unslash( (string) $value ); return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['qrip_test_meta'][ $id ][ $key ] ); }
function wp_insert_post( $data, $wp_error = false ) {
	$data = wp_unslash( $data );
	$id = max( array_keys( $GLOBALS['qrip_test_posts'] ) ?: array( 0 ) ) + 1;
	$data['ID'] = $id;
	$GLOBALS['qrip_test_posts'][ $id ] = (object) ( $data + array(
		'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ),
		'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ),
	) );
	return $id;
}
function wp_update_post( $data, $wp_error = false ) {
	$data = wp_unslash( $data );
	$id = $data['ID'];
	if ( ! get_post( $id ) ) { return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post.' ) : 0; }
	$GLOBALS['qrip_test_posts'][ $id ] = (object) ( $data + array(
		'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ),
	) + (array) get_post( $id ) );
	return $id;
}
function get_posts( $args = array() ) { return ( new WP_Query( $args ) )->posts; }
function wp_get_attachment_url( $id ) { return $GLOBALS['qrip_test_attachment_urls'][ $id ] ?? false; }
function wp_upload_dir( $time = null, $create_dir = true ) {
	$GLOBALS['qrip_test_upload_calls'][] = array( $time, $create_dir );
	return $GLOBALS['qrip_test_uploads'] ?? array( 'error' => 'Uploads fixture unavailable.' );
}
function wp_mkdir_p( $directory ) { return is_dir( $directory ) || @mkdir( $directory, 0777, true ); }
function wp_prepare_attachment_for_js() { return false; }
function wp_basename( $path ) { return basename( $path ); }
function checked( $checked, $current ) { echo $checked === $current ? ' checked="checked"' : ''; }
function selected( $selected, $current ) { echo $selected === $current ? ' selected="selected"' : ''; }
function disabled( $disabled ) { echo $disabled ? ' disabled="disabled"' : ''; }
function submit_button( $text ) { echo '<p><button type="submit">' . esc_html( $text ) . '</button></p>'; }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="nonce-' . esc_attr( $action ) . '">'; }
function check_admin_referer( $action ) { if ( ( $_POST['_wpnonce'] ?? '' ) !== 'nonce-' . $action ) { throw new QRip_Test_Die( 'Invalid nonce', 403 ); } return 1; }
function wp_safe_redirect( $url ) { $GLOBALS['qrip_test_redirect'] = $url; return true; }
function wp_nonce_url( $url, $action ) { return add_query_arg( array( '_wpnonce' => 'nonce-' . $action ), $url ); }
function wp_die( $message, $title = '', $args = array() ) { $status = is_int( $title ) ? $title : ( $args['response'] ?? 0 ); throw new QRip_Test_Die( (string) $message, $status ); }
function map_meta_cap( $capability, $user_id, ...$args ) {
	$caps = array( $capability );
	if ( in_array( $capability, array( 'read_post', 'edit_post' ), true ) ) {
		$id = $args[0] ?? 0;
		$caps = get_post_type( $id ) ? array( 'read_post' === $capability ? 'read' : 'edit_posts' ) : array( 'do_not_allow' );
		$caps = $GLOBALS['qrip_test_object_caps'][ $capability ][ $id ] ?? $caps;
	}
	return apply_filters( 'map_meta_cap', $caps, $capability, $user_id, $args );
}
function current_user_can( $capability, ...$args ) {
	if ( ! is_user_logged_in() ) { return false; }
	foreach ( map_meta_cap( $capability, get_current_user_id(), ...$args ) as $cap ) {
		if ( 'do_not_allow' === $cap || empty( $GLOBALS['qrip_test_capabilities'][ $cap ] ) ) { return false; }
	}
	return true;
}
function get_post_mime_type() { return 'application/pdf'; }
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['qrip_test_hooks'][ $tag ][ $priority ][] = array( $callback, $accepted_args );
	return true;
}
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { return add_filter( $tag, $callback, $priority, $accepted_args ); }
function apply_filters( $tag, $value, ...$args ) {
	$priorities = $GLOBALS['qrip_test_hooks'][ $tag ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as list( $callback, $count ) ) { $value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $count ) ); }
	}
	return $value;
}
function do_action( $tag, ...$args ) {
	$priorities = $GLOBALS['qrip_test_hooks'][ $tag ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as list( $callback, $count ) ) { $callback( ...array_slice( $args, 0, $count ) ); }
	}
}
function wp_register_ability( $name, $args ) { $GLOBALS['qrip_test_abilities'][ $name ] = apply_filters( 'wp_register_ability_args', $args, $name ); }
function wp_register_ability_category( $name, $args ) { $GLOBALS['qrip_test_ability_categories'][ $name ] = $args; }
function wp_enqueue_style( $handle ) { $GLOBALS['qrip_test_styles'][] = $handle; }
function wp_enqueue_script( $handle ) { $GLOBALS['qrip_test_scripts'][] = $handle; }
function wp_enqueue_media() { $GLOBALS['qrip_test_media_enqueued'] = true; }
function wp_localize_script() {}
function plugins_url( $path ) { return 'https://example.test/wp-content/plugins/qrip/' . $path; }
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-qrip-core.php';
require dirname( __DIR__ ) . '/includes/class-qrip-admin.php';
require dirname( __DIR__ ) . '/includes/class-qrip-images.php';
require dirname( __DIR__ ) . '/includes/class-qrip-abilities.php';
require dirname( __DIR__ ) . '/includes/class-qrip-mcp.php';
