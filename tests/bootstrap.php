<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'QRIP_VERSION', 'test' );
define( 'QRIP_URL', 'https://example.test/wp-content/plugins/qrip/' );
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
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
			if ( ! empty( $args['s'] ) && false === stripos( $post->post_title, $args['s'] ) ) { continue; }
			if ( isset( $args['meta_query'] ) && ! self::matches_meta_query( $post->ID, $args['meta_query'] ) ) { continue; }
			$this->posts[] = $post;
		}
	}
	private static function matches_meta_query( $id, $query ) {
		$relation = strtoupper( $query['relation'] ?? 'AND' ); $results = array();
		foreach ( $query as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) { continue; }
			if ( isset( $clause['key'] ) ) {
				$actual = $GLOBALS['qrip_test_meta'][ $id ][ $clause['key'] ] ?? null; $compare = strtoupper( $clause['compare'] ?? '=' ); $expected = $clause['value'] ?? null;
				$results[] = 'NOT EXISTS' === $compare ? null === $actual : ( '!=' === $compare ? null !== $actual && $actual !== $expected : ( 'LIKE' === $compare ? null !== $actual && false !== stripos( (string) $actual, (string) $expected ) : ( 'IN' === $compare ? in_array( (string) $actual, array_map( 'strval', (array) $expected ), true ) : $actual === $expected ) ) );
			} else { $results[] = self::matches_meta_query( $id, $clause ); }
		}
		return 'OR' === $relation ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_title( $value ) { $value = strtolower( $value ); $value = preg_replace( '/[^a-z0-9]+/', '-', $value ); return trim( $value, '-' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function remove_accents( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
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
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function wp_slash( $value ) { return $value; }
function get_current_user_id() { return $GLOBALS['qrip_test_current_user'] ?? 7; }
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
function update_post_meta( $id, $key, $value ) { $GLOBALS['qrip_test_meta'][ (int) $id ][ $key ] = (string) $value; return true; }
function get_posts( $args = array() ) { $ids = array(); foreach ( $GLOBALS['qrip_test_posts'] ?? array() as $id => $post ) { if ( isset( $args['post_type'] ) && $args['post_type'] !== $post->post_type ) { continue; } if ( isset( $args['meta_key'] ) && ( $GLOBALS['qrip_test_meta'][ $id ][ $args['meta_key'] ] ?? null ) !== ( $args['meta_value'] ?? null ) ) { continue; } $ids[] = (int) $id; } return $ids; }
function wp_get_attachment_url( $id ) { return $GLOBALS['qrip_test_attachment_urls'][ $id ] ?? false; }
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
function current_user_can( $capability ) { return $GLOBALS['qrip_test_capabilities'][ $capability ] ?? true; }
function get_post_mime_type() { return 'application/pdf'; }
function add_action() {}
function wp_enqueue_style( $handle ) { $GLOBALS['qrip_test_styles'][] = $handle; }
function wp_enqueue_script( $handle ) { $GLOBALS['qrip_test_scripts'][] = $handle; }
function wp_enqueue_media() { $GLOBALS['qrip_test_media_enqueued'] = true; }
function wp_localize_script() {}
function plugins_url( $path ) { return 'https://example.test/wp-content/plugins/qrip/' . $path; }
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-qrip-core.php';
require dirname( __DIR__ ) . '/includes/class-qrip-admin.php';
