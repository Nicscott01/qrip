<?php
/** QRip uninstall intentionally retains QR records: ordinary uninstall must not destroy durable printed links. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
flush_rewrite_rules();
