<?php
/**
 * Adds a self-contained QRip release archive to the Composer repository index.
 *
 * Usage: php scripts/update-composer-repository.php v1.2.3 RELEASE_URL [INDEX_PATH]
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php update-composer-repository.php TAG RELEASE_URL [INDEX_PATH]\n" );
	exit( 1 );
}

$tag     = $argv[1];
$version = ltrim( $tag, 'v' );
$url     = $argv[2];
$path    = $argv[3] ?? dirname( __DIR__ ) . '/packages.json';

if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
	fwrite( STDERR, "Version must be a semantic version and URL must be absolute.\n" );
	exit( 1 );
}

$index = file_exists( $path ) ? json_decode( file_get_contents( $path ), true ) : array( 'packages' => array() );
if ( ! is_array( $index ) || ! isset( $index['packages'] ) || ! is_array( $index['packages'] ) ) {
	fwrite( STDERR, "Invalid Composer repository index.\n" );
	exit( 1 );
}

$index['packages']['qrip/qrip'][ $version ] = array(
	'name'    => 'qrip/qrip',
	'version' => $version,
	'type'    => 'wordpress-plugin',
	'dist'    => array(
		'url'  => $url,
		'type' => 'zip',
	),
);

ksort( $index['packages']['qrip/qrip'], SORT_NATURAL );
file_put_contents( $path, json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
