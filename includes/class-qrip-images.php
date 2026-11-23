<?php
defined( 'ABSPATH' ) || exit;

/** Public, derived image files for managed QR URLs. */
class QRip_Images {
	const RENDER_VERSION = '1';
	const DIRECTORY = 'qrip';
	private const FORMATS = array( 'svg' => 'image/svg+xml', 'png' => 'image/png' );

	public static function settings() {
		return array( 'error_correction' => 'M', 'size_px' => 2048, 'quiet_zone_px' => 96, 'foreground' => '#000000', 'background' => '#ffffff', 'styling_supported' => false );
	}

	public static function ensure( array $record, $force = false ) {
		return self::collect( $record, true, $force );
	}

	/** Inspect the current cache without creating files or directories. */
	public static function existing( array $record ) {
		return self::collect( $record, false )['images'];
	}

	/** Predict the URL a forced render would report for each format, without touching the filesystem. */
	public static function planned( array $record ) {
		$urls = array_fill_keys( array_keys( self::FORMATS ), null );
		$id = (int) ( $record['id'] ?? 0 );
		$slug = $record['slug'] ?? '';
		if ( $id < 1 || ! QRip_Core::valid_slug( $slug ) || QRip_Core::STATUS_DELETED === ( $record['status'] ?? '' ) ) { return $urls; }
		$storage = self::storage();
		if ( ! $storage || is_link( $storage['dir'] ) ) { return $urls; }
		$directory = $storage['dir'] . '/' . $id;
		if ( is_link( $directory ) || ! self::can_write_under( $directory ) ) { return $urls; }
		foreach ( self::FORMATS as $format => $mime_type ) {
			if ( 'png' === $format && ! extension_loaded( 'gd' ) ) { continue; }
			$urls[ $format ] = $storage['url'] . '/' . $id . '/' . self::filename( $slug, $format );
		}
		return $urls;
	}

	private static function filename( $slug, $format ) {
		return $slug . '-qr-' . substr( hash( 'sha256', QRip_Core::managed_url( $slug ) . '|' . self::RENDER_VERSION . '|' . $format ), 0, 12 ) . '.' . $format;
	}

	/** Check the directory, or its nearest existing ancestor when it has not been created yet. */
	private static function can_write_under( $path ) {
		clearstatcache();
		while ( ! file_exists( $path ) ) {
			$parent = dirname( $path );
			if ( $parent === $path ) { return false; }
			$path = $parent;
		}
		return is_dir( $path ) && is_writable( $path );
	}

	private static function storage() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) { return null; }
		return array( 'dir' => rtrim( $uploads['basedir'], '/\\' ) . '/' . self::DIRECTORY, 'url' => rtrim( $uploads['baseurl'], '/' ) . '/' . self::DIRECTORY );
	}

	private static function collect( array $record, $write, $force = false ) {
		$result = array( 'images' => array(), 'written' => array(), 'removed' => 0 );
		foreach ( self::FORMATS as $format => $mime_type ) {
			try {
				$download_filename = QRip_Core::download_filename( $record + array( 'name' => '' ), $format );
			} catch ( Throwable ) {
				$download_filename = 'qrip-qr.' . $format;
			}
			$result['images'][ $format ] = array( 'url' => null, 'available' => false, 'mime_type' => $mime_type, 'width' => 2048, 'height' => 2048, 'bytes' => null, 'download_filename' => $download_filename );
		}

		$lock = false;
		try {
			$id = (int) ( $record['id'] ?? 0 );
			$slug = $record['slug'] ?? '';
			if ( $id < 1 || ! QRip_Core::valid_slug( $slug ) || QRip_Core::STATUS_DELETED === ( $record['status'] ?? '' ) ) { return $result; }
			$storage = self::storage();
			if ( ! $storage || is_link( $storage['dir'] ) ) { return $result; }
			$directory = $storage['dir'] . '/' . $id;
			if ( $write ) {
				if ( ! self::make_directory( $storage['dir'] ) ) { return $result; }
				$lock = self::lock_cache( $storage['dir'] );
				if ( ! $lock || ! self::make_directory( $directory ) ) { return $result; }
			}
			if ( is_link( $directory ) || ! is_dir( $directory ) ) { return $result; }

			$keep = array( 'index.php' );
			foreach ( self::FORMATS as $format => $mime_type ) {
				$filename = self::filename( $slug, $format );
				$keep[] = $filename;
				$path = $directory . '/' . $filename;
				try {
					if ( $write && ( $force || ! self::file_size( $path ) ) ) {
						if ( ! self::write( $path, QRip_Core::qr_result( $slug, $format )->getString() ) ) { continue; }
						$result['written'][] = $format;
					}
					$bytes = self::file_size( $path );
					if ( ! $bytes ) { continue; }
					$result['images'][ $format ]['url'] = $storage['url'] . '/' . $id . '/' . $filename;
					$result['images'][ $format ]['available'] = true;
					$result['images'][ $format ]['bytes'] = $bytes;
				} catch ( Throwable ) {
					// Each format can fail independently, including on hosts without GD.
				}
			}
			if ( $write ) { $result['removed'] = self::remove_files( $directory, $keep ); }
		} catch ( Throwable ) {
			// Images are a cache and must never prevent the record from being used.
		} finally {
			if ( is_resource( $lock ) ) { @fclose( $lock ); }
		}
		return $result;
	}

	private static function make_directory( $directory ) {
		if ( is_link( $directory ) || ! wp_mkdir_p( $directory ) ) { return false; }
		$index = $directory . '/index.php';
		if ( ! file_exists( $index ) && ! is_link( $index ) ) {
			$file = @fopen( $index, 'xb' );
			if ( $file ) {
				$contents = '<?php // Silence is golden.';
				$written = @fwrite( $file, $contents );
				@fclose( $file );
				if ( strlen( $contents ) !== $written ) { @unlink( $index ); return false; }
			}
		}
		return ! is_link( $index ) && is_file( $index );
	}

	/** Serialize cache writes and cleanup without leaving a lock in record directories. */
	private static function lock_cache( $directory ) {
		$index = $directory . '/index.php';
		if ( is_link( $index ) || ! is_file( $index ) ) { return false; }
		$file = @fopen( $index, 'rb' );
		if ( ! $file || @flock( $file, LOCK_EX ) ) { return $file; }
		@fclose( $file );
		return false;
	}

	private static function file_size( $path ) {
		clearstatcache( true, $path );
		return ! is_link( $path ) && is_file( $path ) && is_readable( $path ) ? @filesize( $path ) : false;
	}

	private static function write( $path, $contents ) {
		$temporary = dirname( $path ) . '/.qrip-' . bin2hex( random_bytes( 12 ) ) . '.tmp';
		$file = @fopen( $temporary, 'xb' );
		if ( ! $file ) { return false; }
		try {
			$written = @fwrite( $file, $contents );
			$closed = @fclose( $file );
			return strlen( $contents ) === $written && $closed && @rename( $temporary, $path );
		} finally {
			if ( is_resource( $file ) ) { @fclose( $file ); }
			if ( is_file( $temporary ) ) { @unlink( $temporary ); }
		}
	}

	private static function remove_files( $directory, array $keep ) {
		$removed = 0;
		foreach ( @scandir( $directory ) ?: array() as $filename ) {
			if ( '.' === $filename || '..' === $filename || in_array( $filename, $keep, true ) ) { continue; }
			$path = $directory . '/' . $filename;
			if ( ( is_link( $path ) || ! is_dir( $path ) ) && @unlink( $path ) ) { ++$removed; }
		}
		return $removed;
	}

	public static function delete_for_record( $id ) {
		$lock = false;
		try {
			$id = (int) $id;
			$storage = $id > 0 ? self::storage() : null;
			if ( ! $storage || is_link( $storage['dir'] ) ) { return; }
			$lock = self::lock_cache( $storage['dir'] );
			$directory = $storage['dir'] . '/' . $id;
			if ( is_link( $directory ) || ! is_dir( $directory ) ) { return; }
			self::remove_files( $directory, array() );
			@rmdir( $directory );
		} catch ( Throwable ) {
			// Cleanup is best effort after the retirement transaction commits.
		} finally {
			if ( is_resource( $lock ) ) { @fclose( $lock ); }
		}
	}
}
