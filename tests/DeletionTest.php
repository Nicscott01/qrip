<?php

use PHPUnit\Framework\TestCase;

final class DeletionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['qrip_test_post_types'] = array();
		$GLOBALS['qrip_test_post_types'][88] = 'attachment';
		$GLOBALS['qrip_test_attachment_urls'] = array( 88 => 'https://example.test/uploads/guide.pdf' );
		$GLOBALS['qrip_test_posts'] = array( 42 => (object) array( 'ID' => 42, 'post_type' => QRip_Core::POST_TYPE, 'post_title' => 'Campaign guide', 'post_date' => '2026-09-01 10:00:00', 'post_modified' => '2026-09-02 10:00:00', 'post_content' => 'content', 'post_excerpt' => 'excerpt' ) );
		$GLOBALS['qrip_test_meta'] = array( 42 => array( QRip_Core::META_SLUG => 'campaign-guide', QRip_Core::META_DESTINATION => 'https://example.com/guide', QRip_Core::META_DESTINATION_TYPE => QRip_Core::DESTINATION_MEDIA, QRip_Core::META_ATTACHMENT_ID => 88, QRip_Core::META_STATUS => QRip_Core::STATUS_ACTIVE, QRip_Core::META_NOTES => 'internal note', QRip_Core::META_SCANS => 12, QRip_Core::META_LAST_SCAN => '2026-09-02 11:00:00', QRip_Core::META_CREATED_BY => 4, QRip_Core::META_UPDATED_BY => 5, '_qrip_utm_source' => 'print', '_qrip_utm_campaign' => 'launch' ) );
		$GLOBALS['qrip_test_capabilities'] = array( 'manage_options' => true, 'upload_files' => true );
		$GLOBALS['qrip_test_current_user'] = 7;
		$GLOBALS['qrip_test_query_vars'] = array();
		$GLOBALS['qrip_test_status'] = 0;
		$GLOBALS['qrip_test_nocache'] = false;
		$GLOBALS['wp_query'] = new QRip_Test_Query();
		$GLOBALS['wpdb'] = new QRip_Test_DB();
		$_GET = array();
		$_POST = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function test_danger_zone_is_only_rendered_for_existing_records_and_is_separate(): void {
		$_GET = array( 'action' => 'edit', 'id' => 42 );
		ob_start();
		QRip_Admin::page();
		$edit = ob_get_clean();
		$this->assertStringContainsString( 'Danger zone', $edit );
		$this->assertStringContainsString( 'campaign-guide', $edit );
		$this->assertStringContainsString( 'name="qrip_delete_confirmation"', $edit );
		$this->assertStringContainsString( 'qrip-delete-confirm" disabled', $edit );
		$this->assertStringContainsString( 'class="qrip-edit-layout"', $edit );
		$this->assertStringContainsString( 'class="qrip-preview-panel"', $edit );
		$preview_position = strpos( $edit, 'class="qrip-preview-panel"' );
		$back_position = strpos( $edit, 'Back to QR Codes' );
		$danger_position = strpos( $edit, 'class="qrip-danger-zone"' );
		$this->assertNotFalse( $preview_position );
		$this->assertNotFalse( $back_position );
		$this->assertNotFalse( $danger_position );
		$this->assertLessThan( $danger_position, $preview_position );
		$this->assertLessThan( $danger_position, $back_position );
		$this->assertSame( 2, substr_count( $edit, '<form' ) );

		$_GET = array( 'action' => 'new' );
		ob_start();
		QRip_Admin::page();
		$create = ob_get_clean();
		$this->assertStringNotContainsString( 'Danger zone', $create );
		$this->assertStringNotContainsString( 'qrip-preview-panel', $create );
		$this->assertSame( 1, substr_count( $create, '<form' ) );
	}

	public function test_confirmation_is_exact_and_server_side(): void {
		$reflection = new ReflectionMethod( QRip_Admin::class, 'valid_delete_confirmation' );
		$reflection->setAccessible( true );
		$this->assertTrue( $reflection->invoke( null, 'DELETE' ) );
		$this->assertFalse( $reflection->invoke( null, '' ) );
		$this->assertFalse( $reflection->invoke( null, 'delete' ) );
		$this->assertFalse( $reflection->invoke( null, ' DELETE' ) );
		$this->assertFalse( $reflection->invoke( null, array( 'DELETE' ) ) );
	}

	public function test_unauthorized_and_invalid_nonce_deletion_are_rejected(): void {
		try {
			QRip_Admin::delete();
			$this->fail( 'Non-POST deletion should fail.' );
		} catch ( QRip_Test_Die $error ) {
			$this->assertSame( 405, $error->status );
		}

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = array( 'id' => 42, '_wpnonce' => 'nonce-qrip_delete_42', 'qrip_delete_confirmation' => 'DELETE' );
		$GLOBALS['qrip_test_capabilities']['manage_options'] = false;
		try {
			QRip_Admin::delete();
			$this->fail( 'Unauthorized deletion should fail.' );
		} catch ( QRip_Test_Die $error ) {
			$this->assertSame( 403, $error->status );
		}
		$this->assertSame( QRip_Core::STATUS_ACTIVE, QRip_Core::record( 42 )['status'] );

		$GLOBALS['qrip_test_capabilities']['manage_options'] = true;
		$_POST['_wpnonce'] = 'stale';
		try {
			QRip_Admin::delete();
			$this->fail( 'Invalid nonce should fail.' );
		} catch ( QRip_Test_Die $error ) {
			$this->assertSame( 403, $error->status );
		}
		$this->assertSame( QRip_Core::STATUS_ACTIVE, QRip_Core::record( 42 )['status'] );
	}

	public function test_retirement_keeps_only_tombstone_and_preserves_media_attachment(): void {
		$result = QRip_Core::retire_record( 42 );
		$this->assertTrue( $result );
		$this->assertSame( QRip_Core::STATUS_DELETED, QRip_Core::record( 42 )['status'] );
		$this->assertSame( 'campaign-guide', QRip_Core::record( 42 )['slug'] );
		$this->assertSame( array( QRip_Core::META_SLUG, QRip_Core::META_STATUS, QRip_Core::META_DELETED_AT, QRip_Core::META_DELETED_BY ), array_keys( $GLOBALS['qrip_test_meta'][42] ) );
		$this->assertSame( '2026-09-16 12:00:00', $GLOBALS['qrip_test_meta'][42][ QRip_Core::META_DELETED_AT ] );
		$this->assertSame( '7', $GLOBALS['qrip_test_meta'][42][ QRip_Core::META_DELETED_BY ] );
		$this->assertSame( 'https://example.test/uploads/guide.pdf', wp_get_attachment_url( 88 ) );
		$this->assertSame( QRip_Core::DELETED_TITLE, $GLOBALS['qrip_test_posts'][42]->post_title );
		$this->assertContains( 'START TRANSACTION', $GLOBALS['wpdb']->queries );
		$this->assertContains( 'COMMIT', $GLOBALS['wpdb']->queries );
	}

	public function test_failed_retirement_leaves_record_unchanged_and_repeat_is_harmless(): void {
		$GLOBALS['wpdb']->fail_operation = 'fail-insert';
		$result = QRip_Core::retire_record( 42 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( QRip_Core::STATUS_ACTIVE, QRip_Core::record( 42 )['status'] );
		$this->assertSame( 'internal note', QRip_Core::record( 42 )['notes'] );
		$this->assertContains( 'ROLLBACK', $GLOBALS['wpdb']->queries );

		$GLOBALS['wpdb']->fail_operation = '';
		$this->assertTrue( QRip_Core::retire_record( 42 ) );
		$after_first = $GLOBALS['qrip_test_meta'][42];
		$repeat = QRip_Core::retire_record( 42 );
		$this->assertInstanceOf( WP_Error::class, $repeat );
		$this->assertSame( 'qrip_already_deleted', $repeat->get_error_code() );
		$this->assertSame( $after_first, $GLOBALS['qrip_test_meta'][42] );
	}

	public function test_retired_slug_remains_reserved_for_new_and_existing_records(): void {
		$this->assertTrue( QRip_Core::retire_record( 42 ) );
		$this->assertFalse( QRip_Core::is_unique_slug( 'campaign-guide' ) );
		$this->assertFalse( QRip_Core::is_unique_slug( 'campaign-guide', 99 ) );
		$result = QRip_Core::save_record( array( 'name' => 'Replacement', 'slug' => 'campaign-guide', 'destination' => 'https://example.com/new' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'qrip_slug_duplicate', $result->get_error_code() );
	}

	public function test_retired_records_are_excluded_from_listing_and_search(): void {
		$GLOBALS['qrip_test_posts'][43] = (object) array( 'ID' => 43, 'post_type' => QRip_Core::POST_TYPE, 'post_title' => 'Retired QR code', 'post_date' => '2026-09-03 10:00:00', 'post_modified' => '2026-09-04 10:00:00' );
		$GLOBALS['qrip_test_meta'][43] = array( QRip_Core::META_SLUG => 'retired-campaign', QRip_Core::META_STATUS => QRip_Core::STATUS_DELETED );
		$listing = new ReflectionMethod( QRip_Admin::class, 'listing' );
		$listing->setAccessible( true );
		ob_start();
		$listing->invoke( null );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Campaign guide', $html );
		$this->assertStringNotContainsString( 'Retired QR code', $html );

		$_GET['s'] = 'retired-campaign';
		ob_start();
		$listing->invoke( null );
		$search_html = ob_get_clean();
		$this->assertStringNotContainsString( 'Retired QR code', $search_html );
	}

	public function test_active_paused_deleted_and_unknown_routes_remain_distinct(): void {
		$GLOBALS['qrip_test_query_vars'][ QRip_Core::QUERY_VAR ] = 'campaign-guide';
		try {
			QRip_Core::route_redirect();
			$this->fail( 'Active QR code should redirect.' );
		} catch ( QRip_Test_Redirect $redirect ) {
			$this->assertSame( 'https://example.test/uploads/guide.pdf', $redirect->url );
		}
		$this->assertSame( '13', $GLOBALS['qrip_test_meta'][42][ QRip_Core::META_SCANS ] );
		$this->assertTrue( $GLOBALS['qrip_test_nocache'] );

		$GLOBALS['qrip_test_meta'][42][ QRip_Core::META_STATUS ] = QRip_Core::STATUS_PAUSED;
		try {
			QRip_Core::route_redirect();
			$this->fail( 'Paused QR code should be gone.' );
		} catch ( QRip_Test_Die $error ) {
			$this->assertSame( 410, $error->status );
		}
		$this->assertSame( '13', $GLOBALS['qrip_test_meta'][42][ QRip_Core::META_SCANS ] );

		$GLOBALS['qrip_test_meta'][42][ QRip_Core::META_STATUS ] = QRip_Core::STATUS_ACTIVE;
		$this->assertTrue( QRip_Core::retire_record( 42 ) );
		try {
			QRip_Core::route_redirect();
			$this->fail( 'Deleted QR code should be gone.' );
		} catch ( QRip_Test_Die $error ) {
			$this->assertSame( 410, $error->status );
		}
		$this->assertSame( '', QRip_Core::record( 42 )['last_scan_at'] );

		$GLOBALS['qrip_test_query_vars'][ QRip_Core::QUERY_VAR ] = 'unknown-campaign';
		$GLOBALS['wp_query'] = new QRip_Test_Query();
		QRip_Core::route_redirect();
		$this->assertTrue( $GLOBALS['wp_query']->is_404 );
		$this->assertSame( 404, $GLOBALS['qrip_test_status'] );
	}

	public function test_success_notice_is_dismissible_and_not_shown_without_retirement_result(): void {
		$listing = new ReflectionMethod( QRip_Admin::class, 'listing' );
		$listing->setAccessible( true );
		$_GET = array( 'deleted' => '1', 'deleted_name' => 'Campaign guide', 'deleted_slug' => 'campaign-guide' );
		ob_start();
		$listing->invoke( null );
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'notice-success is-dismissible', $notice );
		$this->assertStringContainsString( 'Campaign guide', $notice );
		$this->assertStringContainsString( 'campaign-guide', $notice );

		$_GET = array();
		ob_start();
		$listing->invoke( null );
		$without_notice = ob_get_clean();
		$this->assertStringNotContainsString( 'permanently retired', $without_notice );
	}
}
