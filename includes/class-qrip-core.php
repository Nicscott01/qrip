<?php
defined( 'ABSPATH' ) || exit;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

/** Core QRip model, routing, validation, and image generation. */
class QRip_Core {
	const POST_TYPE = 'qr_redirect';
	const QUERY_VAR = 'qrip_go';
	const META_SLUG = '_qrip_slug';
	const META_DESTINATION = '_qrip_destination_url';
	const META_STATUS = '_qrip_status';
	const META_NOTES = '_qrip_notes';
	const META_SCANS = '_qrip_scan_count';
	const META_LAST_SCAN = '_qrip_last_scan_at';
	const META_CREATED_BY = '_qrip_created_by';
	const META_UPDATED_BY = '_qrip_updated_by';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route_redirect' ), 0 );
	}

	public static function activate() {
		self::register_post_type();
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	public static function deactivate() { flush_rewrite_rules(); }

	public static function register_post_type() {
		register_post_type( self::POST_TYPE, array(
			'labels' => array( 'name' => __( 'QR Codes', 'qrip' ), 'singular_name' => __( 'QR Code', 'qrip' ) ),
			'public' => false, 'publicly_queryable' => false, 'show_ui' => false, 'show_in_menu' => false,
			'exclude_from_search' => true, 'rewrite' => false, 'query_var' => false, 'supports' => array( 'title', 'revisions' ),
			'capability_type' => 'post', 'map_meta_cap' => true,
		) );
	}

	public static function add_rewrite_rule() { add_rewrite_rule( '^go/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' ); }
	public static function query_vars( $vars ) { $vars[] = self::QUERY_VAR; return $vars; }

	public static function managed_url( $slug ) { return home_url( '/go/' . rawurlencode( $slug ) ); }
	public static function valid_slug( $slug ) { return is_string( $slug ) && (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ); }
	public static function normalize_slug( $slug ) { return sanitize_title( remove_accents( trim( (string) $slug ) ) ); }

	public static function valid_destination( $url ) {
		if ( ! is_string( $url ) || $url !== trim( $url ) || '' === $url || 0 === strpos( $url, '//' ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) { return false; }
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) && '' !== $parts['host'];
	}

	public static function find_by_slug( $slug ) {
		$posts = get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => self::META_SLUG, 'meta_value' => $slug, 'no_found_rows' => true ) );
		return $posts ? (int) $posts[0] : 0;
	}

	public static function is_unique_slug( $slug, $exclude = 0 ) { $id = self::find_by_slug( $slug ); return ! $id || $id === (int) $exclude; }

	public static function record( $id ) {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) { return false; }
		$data = array( 'id' => (int) $id, 'name' => $post->post_title, 'slug' => get_post_meta( $id, self::META_SLUG, true ), 'destination' => get_post_meta( $id, self::META_DESTINATION, true ), 'status' => get_post_meta( $id, self::META_STATUS, true ) ?: 'active', 'notes' => get_post_meta( $id, self::META_NOTES, true ), 'scan_count' => (int) get_post_meta( $id, self::META_SCANS, true ), 'last_scan_at' => get_post_meta( $id, self::META_LAST_SCAN, true ), 'created_by' => (int) get_post_meta( $id, self::META_CREATED_BY, true ), 'updated_by' => (int) get_post_meta( $id, self::META_UPDATED_BY, true ), 'created' => $post->post_date, 'updated' => $post->post_modified );
		foreach ( self::utm_keys() as $key ) { $data[ $key ] = get_post_meta( $id, '_qrip_' . $key, true ); }
		return $data;
	}

	public static function utm_keys() { return array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ); }

	public static function save_record( $input, $id = 0 ) {
		$name = sanitize_text_field( $input['name'] ?? '' ); $slug = self::normalize_slug( $input['slug'] ?? '' ); $destination = esc_url_raw( trim( (string) ( $input['destination'] ?? '' ) ) );
		$status = ( $input['status'] ?? 'active' ) === 'paused' ? 'paused' : 'active';
		if ( '' === $name ) { return new WP_Error( 'qrip_name', __( 'Name is required.', 'qrip' ) ); }
		if ( ! self::valid_slug( $slug ) ) { return new WP_Error( 'qrip_slug', __( 'Use a URL-safe slug with lowercase letters, numbers, and hyphens.', 'qrip' ) ); }
		if ( ! self::is_unique_slug( $slug, $id ) ) { return new WP_Error( 'qrip_slug_duplicate', __( 'That slug is already in use.', 'qrip' ) ); }
		if ( ! self::valid_destination( $destination ) ) { return new WP_Error( 'qrip_destination', __( 'Destination URL must be an absolute http or https URL.', 'qrip' ) ); }
		$user = get_current_user_id();
		$post_data = array( 'ID' => (int) $id, 'post_type' => self::POST_TYPE, 'post_title' => $name, 'post_status' => 'publish' );
		$post_id = $id ? wp_update_post( wp_slash( $post_data ), true ) : wp_insert_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $post_id ) ) { return $post_id; }
		update_post_meta( $post_id, self::META_SLUG, $slug ); update_post_meta( $post_id, self::META_DESTINATION, $destination ); update_post_meta( $post_id, self::META_STATUS, $status ); update_post_meta( $post_id, self::META_NOTES, sanitize_textarea_field( $input['notes'] ?? '' ) ); update_post_meta( $post_id, self::META_UPDATED_BY, $user );
		if ( ! $id ) { update_post_meta( $post_id, self::META_CREATED_BY, $user ); update_post_meta( $post_id, self::META_SCANS, 0 ); }
		foreach ( self::utm_keys() as $key ) { update_post_meta( $post_id, '_qrip_' . $key, sanitize_text_field( $input[ $key ] ?? '' ) ); }
		return (int) $post_id;
	}

	public static function destination_with_utm( $record ) {
		$args = array(); foreach ( self::utm_keys() as $key ) { if ( ! empty( $record[ $key ] ) ) { $args[ $key ] = $record[ $key ]; } }
		return $args ? add_query_arg( $args, $record['destination'] ) : $record['destination'];
	}

	public static function route_redirect() {
		$slug = get_query_var( self::QUERY_VAR ); if ( ! is_string( $slug ) || ! self::valid_slug( $slug ) ) { return; }
		$id = self::find_by_slug( $slug ); if ( ! $id ) { global $wp_query; $wp_query->set_404(); status_header( 404 ); return; }
		$record = self::record( $id ); nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow', true );
		if ( 'paused' === $record['status'] ) { status_header( 410 ); wp_die( esc_html__( 'This link is no longer active.', 'qrip' ), esc_html__( 'Link unavailable', 'qrip' ), array( 'response' => 410 ) ); }
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s", $id, self::META_SCANS ) );
		update_post_meta( $id, self::META_LAST_SCAN, $now );
		wp_redirect( self::destination_with_utm( $record ), 302, 'QRip' ); exit;
	}

	public static function qr_result( $slug, $format ) {
		$data = self::managed_url( $slug );
		$qr = new QrCode( data: $data, encoding: new Encoding( 'UTF-8' ), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 1856, margin: 96, roundBlockSizeMode: RoundBlockSizeMode::None, foregroundColor: new Color( 0, 0, 0 ), backgroundColor: new Color( 255, 255, 255 ) );
		$writer = 'svg' === $format ? new SvgWriter() : new PngWriter();
		return $writer->write( $qr );
	}

	public static function download_filename( $record, $format ) { return sanitize_file_name( sanitize_title( $record['name'] ) . '-qr.' . $format ); }
}
