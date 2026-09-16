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
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_title( $value ) { $value = strtolower( $value ); $value = preg_replace( '/[^a-z0-9]+/', '-', $value ); return trim( $value, '-' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function remove_accents( $value ) { return $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function __( $value ) { return $value; }
function add_query_arg( $args, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function get_post_type( $id ) { return $GLOBALS['qrip_test_post_types'][ $id ] ?? false; }
function wp_get_attachment_url( $id ) { return $GLOBALS['qrip_test_attachment_urls'][ $id ] ?? false; }
function add_action() {}
function current_user_can( $capability ) { return 'upload_files' === $capability; }
function wp_enqueue_style( $handle ) { $GLOBALS['qrip_test_styles'][] = $handle; }
function wp_enqueue_script( $handle ) { $GLOBALS['qrip_test_scripts'][] = $handle; }
function wp_enqueue_media() { $GLOBALS['qrip_test_media_enqueued'] = true; }
function wp_localize_script() {}
function plugins_url( $path ) { return 'https://example.test/wp-content/plugins/qrip/' . $path; }
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-qrip-core.php';
require dirname( __DIR__ ) . '/includes/class-qrip-admin.php';
