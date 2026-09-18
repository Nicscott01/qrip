<?php
use PHPUnit\Framework\TestCase;
final class CoreTest extends TestCase {
	protected function setUp(): void { $GLOBALS['qrip_test_post_types'] = array(); $GLOBALS['qrip_test_attachment_urls'] = array(); $GLOBALS['qrip_test_posts'] = array(); $GLOBALS['qrip_test_meta'] = array(); $GLOBALS['qrip_test_capabilities'] = array(); $GLOBALS['qrip_test_current_user'] = 7; $GLOBALS['qrip_test_query_vars'] = array(); $GLOBALS['qrip_test_status'] = 0; $GLOBALS['qrip_test_nocache'] = false; $GLOBALS['wp_query'] = new QRip_Test_Query(); $_GET = array(); $_POST = array(); $_SERVER['REQUEST_METHOD'] = 'GET'; unset( $GLOBALS['wpdb'] ); }
	public function test_slug_and_managed_url(): void { $this->assertSame( 'mstoronto-2026-booth', QRip_Core::normalize_slug( 'MSToronto 2026 Booth' ) ); $this->assertTrue( QRip_Core::valid_slug( 'mstoronto-2026' ) ); $this->assertFalse( QRip_Core::valid_slug( 'bad_slug' ) ); $this->assertSame( 'https://example.test/go/mstoronto', QRip_Core::managed_url( 'mstoronto' ) ); }
	public function test_unsafe_urls_are_rejected(): void { foreach ( array( '//example.com', '/relative', 'javascript:alert(1)', 'data:text/plain,x', 'file:///tmp/a', 'https://', ' https://example.com' ) as $url ) { $this->assertFalse( QRip_Core::valid_destination( $url ) ); } $this->assertTrue( QRip_Core::valid_destination( 'https://example.com/a?b=c' ) ); }
	public function test_qr_is_2048_with_white_quiet_zone_and_managed_payload(): void { $svg = QRip_Core::qr_result( 'mstoronto', 'svg' )->getString(); $this->assertStringContainsString( 'width="2048px"', $svg ); $this->assertSame( 'https://example.test/go/mstoronto', QRip_Core::managed_url( 'mstoronto' ) ); $image = imagecreatefromstring( QRip_Core::qr_result( 'mstoronto', 'png' )->getString() ); $this->assertSame( 2048, imagesx( $image ) ); $this->assertSame( 2048, imagesy( $image ) ); $this->assertGreaterThanOrEqual( 250, imagecolorsforindex( $image, imagecolorat( $image, 0, 0 ) )["red"] ); imagedestroy( $image ); }
	public function test_destination_types_are_strict(): void { $this->assertSame( 'url', QRip_Core::destination_type( 'url' ) ); $this->assertSame( 'media', QRip_Core::destination_type( 'media' ) ); $this->assertFalse( QRip_Core::destination_type( 'download_monitor' ) ); $this->assertFalse( QRip_Core::destination_type( '' ) ); }
	public function test_media_destination_resolves_current_attachment_url(): void {
		$GLOBALS['qrip_test_post_types'][12] = 'attachment';
		$GLOBALS['qrip_test_attachment_urls'][12] = 'https://example.test/uploads/guide-v1.pdf';
		$record = array( 'destination_type' => 'media', 'attachment_id' => 12, 'destination' => 'https://stale.test/file.pdf' );
		$this->assertSame( 'https://example.test/uploads/guide-v1.pdf', QRip_Core::resolve_destination( $record ) );
		$GLOBALS['qrip_test_attachment_urls'][12] = 'https://example.test/uploads/guide-v2.pdf';
		$this->assertSame( 'https://example.test/uploads/guide-v2.pdf', QRip_Core::resolve_destination( $record ) );
	}
	public function test_missing_invalid_and_non_attachment_media_are_unavailable(): void {
		$this->assertSame( QRip_Core::ERROR_DESTINATION_UNAVAILABLE, QRip_Core::resolve_media_destination( 0 )->get_error_code() );
		$GLOBALS['qrip_test_post_types'][7] = 'post';
		$this->assertTrue( is_wp_error( QRip_Core::resolve_media_destination( 7 ) ) );
		$GLOBALS['qrip_test_post_types'][8] = 'attachment';
		$GLOBALS['qrip_test_attachment_urls'][8] = 'file:///private/report.pdf';
		$this->assertTrue( is_wp_error( QRip_Core::resolve_media_destination( 8 ) ) );
	}
	public function test_utm_only_applies_to_url_destinations(): void {
		$record = array( 'destination_type' => 'url', 'destination' => 'https://example.com/report', 'utm_source' => 'print', 'utm_medium' => 'qr' );
		$this->assertSame( 'https://example.com/report?utm_source=print&utm_medium=qr', QRip_Core::resolve_destination( $record ) );
		$record['destination_type'] = 'media'; $record['attachment_id'] = 12;
		$GLOBALS['qrip_test_post_types'][12] = 'attachment'; $GLOBALS['qrip_test_attachment_urls'][12] = 'https://example.test/report.pdf';
		$this->assertSame( 'https://example.test/report.pdf', QRip_Core::resolve_destination( $record ) );
	}
	public function test_media_assets_load_only_on_qrip_edit_screens(): void {
		$GLOBALS['qrip_test_styles'] = array(); $GLOBALS['qrip_test_scripts'] = array(); $GLOBALS['qrip_test_media_enqueued'] = false;
		QRip_Admin::enqueue_assets( 'post.php' );
		$this->assertFalse( $GLOBALS['qrip_test_media_enqueued'] ); $this->assertSame( array(), $GLOBALS['qrip_test_scripts'] );
		QRip_Admin::enqueue_assets( 'toplevel_page_qrip' );
		$this->assertFalse( $GLOBALS['qrip_test_media_enqueued'] ); $this->assertContains( 'qrip-admin', $GLOBALS['qrip_test_scripts'] );
		$_GET['action'] = 'edit'; QRip_Admin::enqueue_assets( 'toplevel_page_qrip' );
		$this->assertTrue( $GLOBALS['qrip_test_media_enqueued'] );
	}
}
