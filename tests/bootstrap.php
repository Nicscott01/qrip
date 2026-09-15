<?php
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_title( $value ) { $value = strtolower( $value ); $value = preg_replace( '/[^a-z0-9]+/', '-', $value ); return trim( $value, '-' ); }
function remove_accents( $value ) { return $value; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-qrip-core.php';
