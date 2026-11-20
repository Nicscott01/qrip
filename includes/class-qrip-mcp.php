<?php
defined( 'ABSPATH' ) || exit;

use AgentConnectorForWp\Support\Config;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Transport\HttpTransport;

/** Dedicated MCP transport for the QRip abilities. */
class QRip_MCP {
	const SERVER_ID = 'qrip';
	const ROUTE_NAMESPACE = 'qrip/v1';
	const ROUTE = 'mcp';

	public static function init() {
		add_action( 'mcp_adapter_init', array( __CLASS__, 'register_server' ) );
	}

	public static function register_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) || ! class_exists( HttpTransport::class ) || ! function_exists( 'wp_register_ability' ) || ! self::enabled() ) { return; }
		$adapter->create_server(
			self::SERVER_ID, self::ROUTE_NAMESPACE, self::ROUTE, 'QRip',
			'Manage first-party QR redirects and retrieve their public SVG and PNG images.', QRIP_VERSION,
			array( HttpTransport::class ), class_exists( ErrorLogMcpErrorHandler::class ) ? ErrorLogMcpErrorHandler::class : null,
			null, QRip_Abilities::names(), array(), array(), array( __CLASS__, 'transport_permission' )
		);
	}

	public static function enabled() {
		$default = ! class_exists( Config::class ) || ! method_exists( Config::class, 'can_boot' ) || Config::can_boot();
		return (bool) apply_filters( 'qrip_mcp_server_enabled', $default );
	}

	public static function transport_permission() {
		return is_user_logged_in() && current_user_can( 'qrip_manage_codes' )
			&& ( ! class_exists( Config::class ) || ! method_exists( Config::class, 'domain_lock_ok' ) || Config::domain_lock_ok() );
	}

	public static function endpoint() {
		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE );
	}
}
