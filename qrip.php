<?php
/**
 * Plugin Name: QRip
 * Description: First-party, editable QR-code redirects for durable printed materials.
 * Version: 1.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.4
 * Author: QRip
 * Text Domain: qrip
 */

defined( 'ABSPATH' ) || exit;

define( 'QRIP_VERSION', '1.1.0' );
define( 'QRIP_FILE', __FILE__ );
define( 'QRIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'QRIP_URL', plugin_dir_url( __FILE__ ) );

require_once QRIP_DIR . 'vendor/autoload.php';
require_once QRIP_DIR . 'includes/class-qrip-core.php';
require_once QRIP_DIR . 'includes/class-qrip-admin.php';

QRip_Core::init();
QRip_Admin::init();

register_activation_hook( __FILE__, array( 'QRip_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'QRip_Core', 'deactivate' ) );
