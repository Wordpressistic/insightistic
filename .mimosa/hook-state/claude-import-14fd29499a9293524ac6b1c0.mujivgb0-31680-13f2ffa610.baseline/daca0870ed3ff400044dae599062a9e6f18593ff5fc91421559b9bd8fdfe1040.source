<?php
/**
 * Tests for the non-breaking WPistic platform entitlement bridge.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require_once __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) );
	}
}

if ( ! class_exists( 'Insightistic_License_Manager', false ) ) {
	class Insightistic_License_Manager {
		public static $connected = false;

		public static function is_connected() {
			return self::$connected;
		}
	}
}

if ( ! class_exists( '\\WPistic\\Sdk\\Licensing', false ) ) {
	eval(
		'namespace WPistic\\Sdk;
		class Licensing {
			public static $instance = null;
			public static function instance() { return self::$instance; }
		}'
	);
}

class Insightistic_Test_Entitlements {
	private $booleans;
	private $numbers;

	public function __construct( $booleans = array(), $numbers = array() ) {
		$this->booleans = $booleans;
		$this->numbers  = $numbers;
	}

	public function allows( $key ) {
		return ! empty( $this->booleans[ $key ] );
	}

	public function getMax( $key ) {
		return isset( $this->numbers[ $key ] ) ? (int) $this->numbers[ $key ] : 0;
	}
}

class Insightistic_Test_Licensing {
	private $state;
	private $entitlements;

	public function __construct( $state, $entitlements ) {
		$this->state        = $state;
		$this->entitlements = $entitlements;
	}

	public function state() {
		return $this->state;
	}

	public function entitlements() {
		return $this->entitlements;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-insightistic-platform-bridge.php';

$failures = array();

function insightistic_bridge_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
		echo "FAIL: {$message}\n";
		return;
	}
	echo "PASS: {$message}\n";
}

// No WPistic activation: preserve the existing Insightistic account source.
\WPistic\Sdk\Licensing::$instance = null;
Insightistic_License_Manager::$connected = true;
insightistic_bridge_assert( 'legacy' === Insightistic_Platform_Bridge::source(), 'legacy connector remains the source when WPistic is not activated' );
insightistic_bridge_assert( false === Insightistic_Platform_Bridge::allows( 'insightistic.white_label' ), 'missing WPistic activation never grants paid features' );

// Active WPistic license takes commercial precedence while the old connector may coexist.
\WPistic\Sdk\Licensing::$instance = new Insightistic_Test_Licensing(
	array(
		'activation_token' => 'test-token',
		'status'           => 'active',
	),
	new Insightistic_Test_Entitlements(
		array(
			'insightistic.white_label'      => true,
			'insightistic.agency.dashboard' => true,
		),
		array(
			'insightistic.sites.max'              => 50,
			'insightistic.ai.monthly_credits'     => 5000,
			'insightistic.client_workspaces.max'  => 25,
		)
	)
);
Insightistic_License_Manager::$connected = true;
insightistic_bridge_assert( 'wpistic' === Insightistic_Platform_Bridge::source(), 'WPistic becomes the entitlement authority when activated' );
insightistic_bridge_assert( true === Insightistic_Platform_Bridge::allows( 'insightistic.white_label' ), 'boolean LTD entitlement is exposed through the bridge' );
insightistic_bridge_assert( 50 === Insightistic_Platform_Bridge::max( 'insightistic.sites.max' ), 'numeric site limit is exposed through the bridge' );

$snapshot = Insightistic_Platform_Bridge::snapshot();
insightistic_bridge_assert( 5000 === $snapshot['aiMonthlyCredits'], 'snapshot exposes AI allowance without secrets' );
insightistic_bridge_assert( 25 === $snapshot['clientWorkspacesMax'], 'snapshot exposes client workspace allowance' );

// Grace mode remains valid during a temporary WPistic API outage.
\WPistic\Sdk\Licensing::$instance = new Insightistic_Test_Licensing(
	array(
		'activation_token' => 'cached-token',
		'status'           => 'grace_period',
	),
	new Insightistic_Test_Entitlements( array(), array( 'insightistic.sites.max' => 10 ) )
);
Insightistic_License_Manager::$connected = false;
insightistic_bridge_assert( true === Insightistic_Platform_Bridge::wpistic_connected(), 'WPistic grace period remains connected' );
insightistic_bridge_assert( 10 === Insightistic_Platform_Bridge::max( 'insightistic.sites.max' ), 'cached grace-period limits remain usable' );

// Revoked/invalid central state must not accidentally unlock features.
\WPistic\Sdk\Licensing::$instance = new Insightistic_Test_Licensing(
	array(
		'activation_token' => 'revoked-token',
		'status'           => 'revoked',
	),
	new Insightistic_Test_Entitlements(
		array( 'insightistic.white_label' => true ),
		array( 'insightistic.sites.max' => 150 )
	)
);
Insightistic_License_Manager::$connected = false;
insightistic_bridge_assert( 'none' === Insightistic_Platform_Bridge::source(), 'revoked WPistic license is not treated as active' );
insightistic_bridge_assert( false === Insightistic_Platform_Bridge::allows( 'insightistic.white_label' ), 'revoked license cannot unlock boolean features' );
insightistic_bridge_assert( 0 === Insightistic_Platform_Bridge::max( 'insightistic.sites.max' ), 'revoked license cannot expose paid limits' );

if ( $failures ) {
	echo "\nPLATFORM BRIDGE TESTS: FAIL (" . count( $failures ) . ")\n";
	exit( 1 );
}

echo "\nPLATFORM BRIDGE TESTS: PASS\n";
