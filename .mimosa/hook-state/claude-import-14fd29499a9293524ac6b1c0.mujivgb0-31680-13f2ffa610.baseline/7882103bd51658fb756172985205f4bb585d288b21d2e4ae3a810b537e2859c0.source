<?php
/**
 * Minimal WordPress runtime stubs for standalone test execution.
 *
 * Loaded by tests/*.php before the plugin classes. Provides just enough of
 * the WordPress API surface (options, transients, HTTP, i18n, hooks, errors,
 * escaping) for the classes under test to behave deterministically. Network
 * calls are intercepted via $GLOBALS['insightistic_test_http_queue'].
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- test bootstrap, WordPress is not loaded.

/*
 * Plugin class files begin with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
 * Define it so the classes load outside a real WordPress runtime.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) {
		return $s;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $s, $d = null ) {
		return $s;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $s, $c, $d = null ) {
		return $s;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) {
		return (string) $s;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $v ) {
		return json_encode( $v );
	}
}

/* ------------------------------------------------------------------ */
/* Options / transients (in-memory).                                   */
/* ------------------------------------------------------------------ */

$GLOBALS['insightistic_test_options']   = array();
$GLOBALS['insightistic_test_transients'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['insightistic_test_options'] ) ? $GLOBALS['insightistic_test_options'][ $name ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['insightistic_test_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value ) {
		if ( ! array_key_exists( $name, $GLOBALS['insightistic_test_options'] ) ) {
			$GLOBALS['insightistic_test_options'][ $name ] = $value;
		}
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['insightistic_test_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['insightistic_test_transients'] ) ? $GLOBALS['insightistic_test_transients'][ $name ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $ttl = 0 ) {
		$GLOBALS['insightistic_test_transients'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		unset( $GLOBALS['insightistic_test_transients'][ $name ] );
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* HTTP interception.                                                  */
/*                                                                      */
/* Tests push responses onto                                           */
/*   $GLOBALS['insightistic_test_http_queue']                          */
/* Each item: ['status' => int, 'body' => string|WP_Error-ish].        */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['insightistic_test_http_calls'][] = array( 'url' => $url, 'args' => $args );
		$queue = &$GLOBALS['insightistic_test_http_queue'];
		if ( empty( $queue ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		}
		$next = array_shift( $queue );
		if ( isset( $next['wp_error'] ) ) {
			return new WP_Error( $next['wp_error'], $next['message'] ?? 'HTTP failure' );
		}
		return array( 'response' => array( 'code' => $next['status'] ?? 200 ), 'body' => $next['body'] ?? '{}' );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return wp_remote_post( $url, $args );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_wp_error( $response ) ? 0 : ( $response['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_wp_error( $response ) ? '' : ( $response['body'] ?? '' );
	}
}

/* ------------------------------------------------------------------ */
/* Hooks (no-op containers).                                           */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ) {
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* Misc helpers referenced by plugin classes.                          */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return ! empty( $GLOBALS['insightistic_test_current_user_can'] );
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( ...$args ) {
		return ! empty( $GLOBALS['insightistic_test_nonce_ok'] );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $d ) {
		throw new RuntimeException( 'wp_send_json_error: ' . ( is_string( $d ) ? $d : wp_json_encode( $d ) ) );
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $d ) {
		throw new RuntimeException( 'wp_send_json_success' );
	}
}
if ( ! function_exists( 'get_transient_keys_with_prefix' ) ) {
	function get_transient_keys_with_prefix( $prefix ) {
		return array();
	}
}

$GLOBALS['insightistic_test_http_queue'] = array();
$GLOBALS['insightistic_test_http_calls'] = array();
