<?php

use PHPUnit\Framework\TestCase;

final class QRip_Test_Mcp_Adapter {
	public array $calls = array();
	public function create_server( ...$args ) { $this->calls[] = $args; }
}

final class QRip_Test_Mcp_Http_Transport {}
final class QRip_Test_Mcp_Error_Handler {}

final class QRip_Test_Agent_Connector_Config {
	public static bool $can_boot = true;
	public static bool $domain_lock_ok = true;
	public static function can_boot() { return self::$can_boot; }
	public static function domain_lock_ok() { return self::$domain_lock_ok; }
}

final class QRip_McpTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['qrip_test_hooks'] = array();
		$GLOBALS['qrip_test_capabilities'] = array( 'manage_options' => true );
		$GLOBALS['qrip_test_current_user'] = 7;
		QRip_Test_Agent_Connector_Config::$can_boot = true;
		QRip_Test_Agent_Connector_Config::$domain_lock_ok = true;
		QRip_Abilities::init();
		QRip_MCP::init();
	}

	protected function tearDown(): void { unset( $GLOBALS['qrip_test_hooks'] ); }

	private function stub_adapter_classes(): void {
		$classes = array(
			QRip_Test_Mcp_Http_Transport::class => 'WP\MCP\Transport\HttpTransport',
			QRip_Test_Mcp_Error_Handler::class => 'WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
			QRip_Test_Agent_Connector_Config::class => 'AgentConnectorForWp\Support\Config',
		);
		foreach ( $classes as $class => $alias ) {
			if ( ! class_exists( $alias, false ) ) { class_alias( $class, $alias ); }
		}
	}

	public function test_dedicated_server_registers_only_qrip_tools_with_transport_permission(): void {
		$this->stub_adapter_classes();
		$adapter = new QRip_Test_Mcp_Adapter();
		do_action( 'mcp_adapter_init', $adapter );
		$this->assertCount( 1, $adapter->calls );
		$args = $adapter->calls[0];
		$this->assertSame( array( 'qrip', 'qrip/v1', 'mcp', 'QRip' ), array_slice( $args, 0, 4 ) );
		$this->assertSame( QRIP_VERSION, $args[5] );
		$this->assertSame( array( 'WP\MCP\Transport\HttpTransport' ), $args[6] );
		$this->assertSame( 'WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler', $args[7] );
		$this->assertSame( array( QRip_Abilities::names(), array(), array() ), array_slice( $args, 9, 3 ) );
		$this->assertSame( 'https://example.test/wp-json/qrip/v1/mcp', QRip_MCP::endpoint() );
		$this->assertIsCallable( $args[12] );
		$this->assertTrue( $args[12]() );
		$GLOBALS['qrip_test_capabilities']['manage_options'] = false;
		$this->assertFalse( $args[12]() );
		$GLOBALS['qrip_test_capabilities']['manage_options'] = true;
		$GLOBALS['qrip_test_current_user'] = 0;
		$this->assertFalse( $args[12]() );
		$GLOBALS['qrip_test_current_user'] = 7;
		QRip_Test_Agent_Connector_Config::$domain_lock_ok = false;
		$this->assertFalse( $args[12]() );
	}

	public function test_filter_and_agent_connector_can_disable_registration(): void {
		$this->stub_adapter_classes();
		$adapter = new QRip_Test_Mcp_Adapter();
		$this->assertTrue( QRip_MCP::enabled() );
		QRip_Test_Agent_Connector_Config::$can_boot = false;
		$this->assertFalse( QRip_MCP::enabled() );
		QRip_MCP::register_server( $adapter );
		$this->assertSame( array(), $adapter->calls );
		QRip_Test_Agent_Connector_Config::$can_boot = true;
		add_filter( 'qrip_mcp_server_enabled', static fn() => false );
		$this->assertFalse( QRip_MCP::enabled() );
		QRip_MCP::register_server( $adapter );
		$this->assertSame( array(), $adapter->calls );
	}
}
