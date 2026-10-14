<?php
/**
 * IndexNow pipeline test suite.
 *
 * Covers the eligibility matrix (draft/private/pending/future/password/
 * noindex/non-public types, attachment parent rules, publish passes), URL
 * canonicalization (fragment stripped, admin/login/ajax rejected, scheme
 * checks), queue dedupe + cap, chunk-flush math, the SaaS key flow
 * (GET key -> write file -> POST confirm) and failure paths (422 not
 * initialized, network errors) against the pinned connector contract.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'INSIGHTISTIC_VERSION' ) ) {
	define( 'INSIGHTISTIC_VERSION', '4.4.3-test' );
}

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

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://site.example' . (string) $path;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'version' === $show ? '6.5' : 'Test Site';
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return '00000000-0000-4000-8000-' . substr( sha1( mt_rand() ), 0, 12 );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

/* ------------------------------------------------------------------ */
/* Posts / meta / permalinks / types (in-memory maps).                  */
/* ------------------------------------------------------------------ */

$GLOBALS['insightistic_test_posts']       = array();
$GLOBALS['insightistic_test_post_meta']   = array();
$GLOBALS['insightistic_test_permalinks']  = array();
$GLOBALS['insightistic_test_post_types']  = array();
$GLOBALS['insightistic_test_revisions']   = array();
$GLOBALS['insightistic_test_autosaves']   = array();

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		$id = is_object( $post_id ) ? (int) $post_id->ID : (int) $post_id;
		return isset( $GLOBALS['insightistic_test_posts'][ $id ] ) ? $GLOBALS['insightistic_test_posts'][ $id ] : null;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = true ) {
		unset( $single );
		$meta = isset( $GLOBALS['insightistic_test_post_meta'][ (int) $post_id ] ) ? $GLOBALS['insightistic_test_post_meta'][ (int) $post_id ] : array();
		return isset( $meta[ $key ] ) ? $meta[ $key ] : '';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return isset( $GLOBALS['insightistic_test_permalinks'][ $id ] ) ? $GLOBALS['insightistic_test_permalinks'][ $id ] : '';
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $type ) {
		if ( ! isset( $GLOBALS['insightistic_test_post_types'][ $type ] ) ) {
			return null;
		}
		$public = (bool) $GLOBALS['insightistic_test_post_types'][ $type ];
		$obj    = new stdClass();
		$obj->public = $public;
		return $obj;
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post_id ) {
		return in_array( (int) $post_id, $GLOBALS['insightistic_test_revisions'], true );
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post_id ) {
		return in_array( (int) $post_id, $GLOBALS['insightistic_test_autosaves'], true );
	}
}

/* ------------------------------------------------------------------ */
/* Action Scheduler + WP-Cron recording stubs.                          */
/* ------------------------------------------------------------------ */

$GLOBALS['insightistic_test_actions']      = array();
$GLOBALS['insightistic_test_cron_events']  = array();
$GLOBALS['insightistic_test_next_scheduled'] = false;
$GLOBALS['insightistic_test_fs']           = null;
$GLOBALS['insightistic_test_fs_ok']        = true;

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
		$GLOBALS['insightistic_test_actions'][] = array(
			'hook'  => $hook,
			'args'  => $args,
			'group' => $group,
		);
		return 1;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		unset( $hook );
		return $GLOBALS['insightistic_test_next_scheduled'];
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['insightistic_test_cron_events'][] = array(
			'timestamp'  => $timestamp,
			'recurrence' => $recurrence,
			'hook'       => $hook,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook ) {
		$GLOBALS['insightistic_test_cron_events'][] = array(
			'timestamp'  => $timestamp,
			'recurrence' => null,
			'hook'       => $hook,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		unset( $hook );
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* Filesystem stub (records key-file writes, never touches disk).       */
/* ------------------------------------------------------------------ */

if ( ! class_exists( 'Insightistic_Test_Filesystem', false ) ) {
	class Insightistic_Test_Filesystem {
		public $writes = array();

		public function put_contents( $path, $contents, $mode = false ) {
			$this->writes[] = array(
				'path'     => $path,
				'contents' => $contents,
				'mode'     => $mode,
			);
			return ! empty( $GLOBALS['insightistic_test_fs_ok'] );
		}
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem() {
		if ( null === $GLOBALS['insightistic_test_fs'] ) {
			$GLOBALS['insightistic_test_fs'] = new Insightistic_Test_Filesystem();
		}
		$GLOBALS['wp_filesystem'] = $GLOBALS['insightistic_test_fs'];
		return true;
	}
}

/* ------------------------------------------------------------------ */
/* wp_remote_request (Saas_Client transport) — queue-driven like the    */
/* wp_remote_post stub in wp-stubs.php, but records the HTTP method.    */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['insightistic_test_http_calls'][] = array( 'url' => $url, 'args' => $args );
		$queue = &$GLOBALS['insightistic_test_http_queue'];
		if ( empty( $queue ) ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		}
		$next = array_shift( $queue );
		if ( isset( $next['wp_error'] ) ) {
			return new WP_Error( $next['wp_error'], isset( $next['message'] ) ? $next['message'] : 'HTTP failure' );
		}
		return array(
			'response' => array( 'code' => isset( $next['status'] ) ? (int) $next['status'] : 200 ),
			'body'     => isset( $next['body'] ) ? $next['body'] : '{}',
		);
	}
}

/* ------------------------------------------------------------------ */
/* Plugin-class doubles (mirrors test-platform-bridge.php).             */
/* ------------------------------------------------------------------ */

if ( ! class_exists( 'Insightistic_License_Manager', false ) ) {
	class Insightistic_License_Manager {
		public static $connected = false;

		public static function is_connected() {
			return self::$connected;
		}
	}
}

if ( ! class_exists( 'Insightistic_Encryption', false ) ) {
	class Insightistic_Encryption {
		public static function encrypt( $value ) {
			return 'enc:' . $value;
		}
		public static function decrypt( $value ) {
			return 0 === strpos( (string) $value, 'enc:' ) ? substr( (string) $value, 4 ) : $value;
		}
	}
}

require_once __DIR__ . '/lib.php';

require_once dirname( __DIR__ ) . '/includes/class-insightistic-saas-client.php';
require_once dirname( __DIR__ ) . '/includes/class-insightistic-indexnow.php';

const INDEXNOW_TEST_KEY = 'abcdef0123456789abcdef0123456789';

/**
 * Reset all test state between scenarios.
 */
function indexnow_reset() {
	$GLOBALS['insightistic_test_options']       = array();
	$GLOBALS['insightistic_test_http_queue']    = array();
	$GLOBALS['insightistic_test_http_calls']    = array();
	$GLOBALS['insightistic_test_actions']       = array();
	$GLOBALS['insightistic_test_cron_events']   = array();
	$GLOBALS['insightistic_test_next_scheduled'] = false;
	$GLOBALS['insightistic_test_posts']         = array();
	$GLOBALS['insightistic_test_post_meta']     = array();
	$GLOBALS['insightistic_test_permalinks']    = array();
	$GLOBALS['insightistic_test_post_types']    = array(
		'post'          => true,
		'page'          => true,
		'attachment'    => false,
		'nav_menu_item' => false,
		'acme_private'  => false,
	);
	$GLOBALS['insightistic_test_revisions']     = array();
	$GLOBALS['insightistic_test_autosaves']     = array();
	$GLOBALS['insightistic_test_fs']            = new Insightistic_Test_Filesystem();
	$GLOBALS['wp_filesystem']                   = $GLOBALS['insightistic_test_fs'];
	$GLOBALS['insightistic_test_fs_ok']         = true;

	Insightistic_License_Manager::$connected = true;

	// Connector credentials so signed requests go through.
	$GLOBALS['insightistic_test_options']['insightistic_connector_key_id'] = 'test-key-id';
	$GLOBALS['insightistic_test_options']['insightistic_connector_secret'] = 'enc:test-secret';
}

/**
 * Build a post object with the given attributes.
 */
function indexnow_post( $id, $status, $type = 'post', $extra = array() ) {
	$post             = new stdClass();
	$post->ID         = $id;
	$post->post_status = $status;
	$post->post_type  = $type;
	foreach ( $extra as $k => $v ) {
		$post->$k = $v;
	}
	$GLOBALS['insightistic_test_posts'][ $id ] = $post;
	return $post;
}

/**
 * Number of AS actions enqueued for a given hook.
 */
function indexnow_action_count( $hook ) {
	$n = 0;
	foreach ( $GLOBALS['insightistic_test_actions'] as $action ) {
		if ( $hook === $action['hook'] ) {
			$n++;
		}
	}
	return $n;
}

/**
 * Decoded JSON body of the nth recorded HTTP call (or null).
 */
function indexnow_http_body( $n ) {
	if ( ! isset( $GLOBALS['insightistic_test_http_calls'][ $n ] ) ) {
		return null;
	}
	$raw = isset( $GLOBALS['insightistic_test_http_calls'][ $n ]['args']['body'] )
		? $GLOBALS['insightistic_test_http_calls'][ $n ]['args']['body']
		: '';
	return json_decode( (string) $raw, true );
}

$in = new Insightistic_IndexNow();

echo "== IndexNow tests ==\n";

/* 1. URL canonicalization. */
echo "- url canonicalization\n";
indexnow_reset();
assert_same( 'https://x.example/post/', Insightistic_IndexNow::canonicalize_url( 'https://x.example/post/#utm_source=x' ), 'fragment is stripped' );
assert_same( 'https://x.example/a', Insightistic_IndexNow::canonicalize_url( '  https://x.example/a#frag  ' ), 'whitespace trimmed around fragment strip' );
assert_same( '', Insightistic_IndexNow::canonicalize_url( '' ), 'empty URL -> empty string' );
assert_same( '', Insightistic_IndexNow::canonicalize_url( '#just-a-fragment' ), 'fragment-only URL -> empty string' );

assert_true( Insightistic_IndexNow::is_submittable_url( 'https://x.example/hello-world' ), 'plain https URL is submittable' );
assert_true( Insightistic_IndexNow::is_submittable_url( 'http://x.example/a' ), 'plain http URL is submittable' );
assert_true( ! Insightistic_IndexNow::is_submittable_url( 'https://x.example/wp-admin/edit.php' ), 'wp-admin path rejected' );
assert_true( ! Insightistic_IndexNow::is_submittable_url( 'https://x.example/wp-admin/admin-ajax.php' ), 'wp-admin ajax path rejected' );
assert_true( ! Insightistic_IndexNow::is_submittable_url( 'https://x.example/admin-ajax.php' ), 'front-end admin-ajax path rejected' );
assert_true( ! Insightistic_IndexNow::is_submittable_url( 'https://x.example/wp-login.php' ), 'wp-login path rejected' );
assert_true( ! Insightistic_IndexNow::is_submittable_url( 'ftp://x.example/a' ), 'non-http scheme rejected' );
assert_true( ! Insightistic_IndexNow::is_submittable_url( '/relative/path' ), 'relative URL rejected' );

/* 2. Eligibility matrix. */
echo "- eligibility matrix\n";
indexnow_reset();
$pub = indexnow_post( 1, 'publish' );
assert_true( Insightistic_IndexNow::post_is_eligible( $pub ), 'published public-type post is eligible' );

assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 2, 'draft' ) ), 'draft is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 3, 'pending' ) ), 'pending is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 4, 'private' ) ), 'private is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 5, 'future' ) ), 'future is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 6, 'trash' ) ), 'trash is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 7, 'publish', 'post', array( 'post_password' => 'secret' ) ) ), 'password-protected is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 8, 'publish', 'acme_private' ) ), 'non-public post type is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 9, 'publish', 'nav_menu_item' ) ), 'internal post type is skipped' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( null ), 'null post is skipped' );

// Attachments.
$att_public = indexnow_post( 10, 'inherit', 'attachment', array( 'post_parent' => 1 ) );
assert_true( Insightistic_IndexNow::post_is_eligible( $att_public ), 'attachment of a published post is eligible' );
$att_draft = indexnow_post( 11, 'inherit', 'attachment', array( 'post_parent' => 2 ) );
assert_true( ! Insightistic_IndexNow::post_is_eligible( $att_draft ), 'attachment of a draft post is skipped' );
$att_unattached = indexnow_post( 12, 'inherit', 'attachment', array( 'post_parent' => 0 ) );
assert_true( Insightistic_IndexNow::post_is_eligible( $att_unattached ), 'unattached media is eligible' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( indexnow_post( 13, 'publish', 'attachment' ) ), 'attachment with publish status (odd) is skipped' );

// Noindex detection: Yoast / RankMath / AIOSEO, and a clean post stays eligible.
indexnow_reset();
$GLOBALS['insightistic_test_post_meta'][21] = array( '_yoast_wpseo_meta-robots-noindex' => '1' );
assert_true( Insightistic_IndexNow::post_is_noindex( 21 ), 'Yoast noindex meta detected' );
$GLOBALS['insightistic_test_post_meta'][22] = array( 'rank_math_robots' => array( 'index', 'follow' ) );
assert_true( ! Insightistic_IndexNow::post_is_noindex( 22 ), 'RankMath robots without noindex is indexable' );
$GLOBALS['insightistic_test_post_meta'][23] = array( 'rank_math_robots' => array( 'noindex' ) );
assert_true( Insightistic_IndexNow::post_is_noindex( 23 ), 'RankMath noindex detected' );
$GLOBALS['insightistic_test_post_meta'][24] = array( '_aioseo_robot_settings' => array( 'robots' => array( 'noindex' => 1 ) ) );
assert_true( Insightistic_IndexNow::post_is_noindex( 24 ), 'AIOSEO noindex meta detected (array form)' );
$GLOBALS['insightistic_test_post_meta'][25] = array( '_aioseo_robot_settings' => '{"robots":{"noindex":"enabled"}}' );
assert_true( Insightistic_IndexNow::post_is_noindex( 25 ), 'AIOSEO noindex meta detected (JSON form)' );
assert_true( ! Insightistic_IndexNow::post_is_noindex( 26 ), 'no SEO meta -> assume indexable' );
assert_true( ! Insightistic_IndexNow::post_is_noindex( 0 ), 'invalid post id -> not noindex' );

$noindex_post = indexnow_post( 27, 'publish' );
$GLOBALS['insightistic_test_post_meta'][27] = array( '_yoast_wpseo_meta-robots-noindex' => '1' );
assert_true( ! Insightistic_IndexNow::post_is_eligible( $noindex_post ), 'noindex post is not eligible' );

/* 3. save_post gate -> background action. */
echo "- save_post gate\n";
indexnow_reset();
$pub = indexnow_post( 30, 'publish' );
$in->maybe_submit_post( 30, $pub, true );
assert_same( 1, indexnow_action_count( Insightistic_IndexNow::SUBMIT_HOOK ), 'eligible save enqueues exactly one background action' );
assert_same( 'insightistic', $GLOBALS['insightistic_test_actions'][0]['group'], 'background action uses the insightistic group' );
assert_same( array( 'post_id' => 30 ), $GLOBALS['insightistic_test_actions'][0]['args'], 'background action carries the post id' );

indexnow_reset();
$in->maybe_submit_post( 31, indexnow_post( 31, 'draft' ), true );
assert_same( 0, indexnow_action_count( Insightistic_IndexNow::SUBMIT_HOOK ), 'draft save enqueues nothing' );

indexnow_reset();
$GLOBALS['insightistic_test_revisions'][] = 55;
$in->maybe_submit_post( 55, indexnow_post( 55, 'inherit' ), true );
assert_same( 0, indexnow_action_count( Insightistic_IndexNow::SUBMIT_HOOK ), 'revision save enqueues nothing' );

indexnow_reset();
$GLOBALS['insightistic_test_autosaves'][] = 56;
$in->maybe_submit_post( 56, indexnow_post( 56, 'inherit' ), true );
assert_same( 0, indexnow_action_count( Insightistic_IndexNow::SUBMIT_HOOK ), 'autosave enqueues nothing' );

indexnow_reset();
Insightistic_License_Manager::$connected = false;
$in->maybe_submit_post( 57, indexnow_post( 57, 'publish' ), true );
assert_same( 0, indexnow_action_count( Insightistic_IndexNow::SUBMIT_HOOK ), 'disconnected site enqueues nothing' );

indexnow_reset();
$GLOBALS['insightistic_test_options']['insightistic_indexnow'] = array( 'auto_submit_enabled' => 'no' );
$in->maybe_submit_post( 58, indexnow_post( 58, 'publish' ), true );
assert_same( 0, indexnow_action_count( Insightistic_IndexNow::SUBMIT_HOOK ), 'auto-submit off enqueues nothing' );
assert_true( ! Insightistic_IndexNow::is_enabled(), 'is_enabled() honors the stored toggle' );

/* 4. Handler: current permalink resolution + queueing. */
echo "- submit-single handler\n";
indexnow_reset();
$GLOBALS['insightistic_test_permalinks'][40] = 'https://site.example/old-slug/#utm=campaign';
indexnow_post( 40, 'publish' );
$in->handle_submit_single( 40 );
assert_same( array( 'https://site.example/old-slug/' ), Insightistic_IndexNow::queue(), 'handler queues the fragment-stripped permalink' );

indexnow_reset();
indexnow_post( 41, 'draft' );
$in->handle_submit_single( 41 );
assert_same( array(), Insightistic_IndexNow::queue(), 'handler skips non-published posts' );

indexnow_reset();
$GLOBALS['insightistic_test_permalinks'][42] = 'https://site.example/ok';
indexnow_post( 42, 'publish' );
$in->handle_submit_single( 42 );
$in->handle_submit_single( 42 );
assert_same( 1, count( Insightistic_IndexNow::queue() ), 'duplicate handler runs dedupe by URL' );

/* 5. Queue dedupe + cap. */
echo "- queue dedupe and cap\n";
indexnow_reset();
assert_true( Insightistic_IndexNow::queue_add( 'https://site.example/a' ), 'first add succeeds' );
assert_true( ! Insightistic_IndexNow::queue_add( 'https://site.example/a' ), 'second add of the same URL is a dedupe hit' );
assert_same( 1, count( Insightistic_IndexNow::queue() ), 'queue holds one entry after dedupe' );
assert_true( ! Insightistic_IndexNow::queue_add( '' ), 'empty URL is rejected' );

indexnow_reset();
for ( $i = 0; $i < Insightistic_IndexNow::QUEUE_MAX; $i++ ) {
	Insightistic_IndexNow::queue_add( 'https://site.example/p' . $i );
}
Insightistic_IndexNow::queue_add( 'https://site.example/newest' );
assert_same( Insightistic_IndexNow::QUEUE_MAX, count( Insightistic_IndexNow::queue() ), 'queue never exceeds the cap' );
assert_true( ! in_array( 'https://site.example/p0', Insightistic_IndexNow::queue(), true ), 'oldest URL dropped when the cap is hit' );
assert_true( in_array( 'https://site.example/newest', Insightistic_IndexNow::queue(), true ), 'newest URL kept when the cap is hit' );

/* 6. Flush threshold + chunk math. */
echo "- flush threshold and chunk math\n";
indexnow_reset();
Insightistic_IndexNow::queue_add( 'https://site.example/a' );
Insightistic_IndexNow::maybe_flush();
assert_same( 0, indexnow_action_count( Insightistic_IndexNow::FLUSH_HOOK ), 'queue below the threshold does not trigger a flush' );

indexnow_reset();
$GLOBALS['insightistic_test_options']['insightistic_indexnow'] = array(
	'key'                 => INDEXNOW_TEST_KEY,
	'key_location'        => '',
	'key_file_confirmed'  => true,
	'auto_submit_enabled' => 'yes',
	'last_submitted'      => '',
);
for ( $i = 0; $i < Insightistic_IndexNow::FLUSH_AT - 1; $i++ ) {
	Insightistic_IndexNow::queue_add( 'https://site.example/q' . $i );
}
$GLOBALS['insightistic_test_permalinks'][60] = 'https://site.example/q-last';
indexnow_post( 60, 'publish' );
$in->handle_submit_single( 60 );
assert_same( Insightistic_IndexNow::FLUSH_AT, count( Insightistic_IndexNow::queue() ), 'handler filled the queue to the threshold' );
assert_same( 1, indexnow_action_count( Insightistic_IndexNow::FLUSH_HOOK ), 'reaching the threshold triggers exactly one flush action' );

// Full drain: 30 queued URLs -> one 25-URL submit + one 5-URL submit.
indexnow_reset();
$GLOBALS['insightistic_test_options']['insightistic_indexnow'] = array(
	'key'                 => INDEXNOW_TEST_KEY,
	'key_location'        => '',
	'key_file_confirmed'  => true,
	'auto_submit_enabled' => 'yes',
	'last_submitted'      => '',
);
for ( $i = 0; $i < 30; $i++ ) {
	Insightistic_IndexNow::queue_add( 'https://site.example/drain/' . $i );
}
queue_http( 200, wp_json_encode( array( 'submitted' => 25, 'accepted' => 25, 'failed' => 0, 'skipped_duplicate' => 0, 'skipped_invalid_host' => 0 ) ) );
queue_http( 200, wp_json_encode( array( 'submitted' => 5, 'accepted' => 5, 'failed' => 0, 'skipped_duplicate' => 0, 'skipped_invalid_host' => 0 ) ) );
$in->flush_queue();
assert_same( 2, count( $GLOBALS['insightistic_test_http_calls'] ), '30 queued URLs drain in two chunked submits' );
$body1 = indexnow_http_body( 0 );
$body2 = indexnow_http_body( 1 );
assert_same( 25, count( $body1['urls'] ), 'first chunk carries exactly 25 URLs' );
assert_same( 'https://site.example/drain/0', $body1['urls'][0], 'first chunk keeps queue order (oldest first)' );
assert_same( 5, count( $body2['urls'] ), 'second chunk carries the remainder' );
assert_same( array(), Insightistic_IndexNow::queue(), 'queue is empty after a full drain' );
$after = Insightistic_IndexNow::settings();
assert_true( '' !== $after['last_submitted'], 'successful submit stamps last_submitted' );
$submit_url = $GLOBALS['insightistic_test_http_calls'][0]['url'];
assert_true( false !== strpos( $submit_url, '/api/connector/v1/indexnow/submit' ), 'submit goes to the pinned connector endpoint' );
assert_true( ! empty( $GLOBALS['insightistic_test_http_calls'][0]['args']['headers']['X-INS-Signature'] ), 'submit is HMAC-signed' );

// Key-file self-heal ran as part of ensure_key() before the submit.
$fs_writes = $GLOBALS['insightistic_test_fs']->writes;
assert_true( 1 === count( $fs_writes ), 'exactly one key-file write (self-heal)' );
assert_true( false !== strpos( $fs_writes[0]['path'], '/' . INDEXNOW_TEST_KEY . '.txt' ), 'key file path is ABSPATH/{key}.txt' );
assert_same( INDEXNOW_TEST_KEY, $fs_writes[0]['contents'], 'key file body is exactly the key' );

/* 7. Failure paths: 422 not initialized + network error keep the queue. */
echo "- failure paths\n";
indexnow_reset();
$GLOBALS['insightistic_test_options']['insightistic_indexnow'] = array(
	'key'                 => INDEXNOW_TEST_KEY,
	'key_location'        => '',
	'key_file_confirmed'  => true,
	'auto_submit_enabled' => 'yes',
	'last_submitted'      => '',
);
Insightistic_IndexNow::queue_add( 'https://site.example/a' );
Insightistic_IndexNow::queue_add( 'https://site.example/b' );
Insightistic_IndexNow::queue_add( 'https://site.example/c' );
queue_http( 422, wp_json_encode( array( 'code' => 'indexnow_not_initialized', 'message' => 'no key yet' ) ) );
$in->flush_queue();
assert_same( 3, count( Insightistic_IndexNow::queue() ), '422 indexnow_not_initialized keeps the queue intact' );
$log = get_option( 'insightistic_sync_log', array() );
assert_true( ! empty( $log ) && 'error' === $log[0]['level'], '422 lands in the rolling sync log as an error' );
assert_true( false !== strpos( $log[0]['message'], 'indexnow_not_initialized' ), 'log names the SaaS error code' );

indexnow_reset();
$GLOBALS['insightistic_test_options']['insightistic_indexnow'] = array(
	'key'                 => INDEXNOW_TEST_KEY,
	'key_location'        => '',
	'key_file_confirmed'  => true,
	'auto_submit_enabled' => 'yes',
	'last_submitted'      => '',
);
Insightistic_IndexNow::queue_add( 'https://site.example/a' );
queue_http_error();
$in->flush_queue();
assert_same( 1, count( Insightistic_IndexNow::queue() ), 'network failure keeps the queue intact' );

indexnow_reset();
$in->flush_queue();
assert_same( 0, count( $GLOBALS['insightistic_test_http_calls'] ), 'empty queue issues no HTTP calls' );

indexnow_reset();
Insightistic_License_Manager::$connected = false;
Insightistic_IndexNow::queue_add( 'https://site.example/a' );
$in->flush_queue();
assert_same( 0, count( $GLOBALS['insightistic_test_http_calls'] ), 'disconnected flush is a silent no-op' );

/* 8. Key provisioning: GET key -> write file -> POST confirm. */
echo "- key provisioning flow\n";
indexnow_reset();
queue_http( 200, wp_json_encode( array(
	'key'                => INDEXNOW_TEST_KEY,
	'key_location'       => 'https://site.example/' . INDEXNOW_TEST_KEY . '.txt',
	'key_file_confirmed' => false,
) ) );
queue_http( 200, wp_json_encode( array( 'key_file_confirmed' => true ) ) );
assert_true( Insightistic_IndexNow::ensure_key(), 'ensure_key succeeds on the happy path' );
assert_same( 2, count( $GLOBALS['insightistic_test_http_calls'] ), 'key flow makes exactly two calls' );
$key_call  = $GLOBALS['insightistic_test_http_calls'][0];
$conf_call = $GLOBALS['insightistic_test_http_calls'][1];
assert_same( 'GET', $key_call['args']['method'], 'key fetch uses GET' );
assert_true( false !== strpos( $key_call['url'], '/api/connector/v1/indexnow/key' ), 'key fetch hits the pinned endpoint' );
assert_true( ! empty( $key_call['args']['headers']['X-INS-Signature'] ), 'key fetch is HMAC-signed' );
assert_same( 'POST', $conf_call['args']['method'], 'confirm uses POST' );
assert_true( false !== strpos( $conf_call['url'], '/api/connector/v1/indexnow/key?confirmed=1' ), 'confirm hits the pinned endpoint with confirmed=1' );

$settings = Insightistic_IndexNow::settings();
assert_same( INDEXNOW_TEST_KEY, $settings['key'], 'key stored after fetch' );
assert_same( 'https://site.example/' . INDEXNOW_TEST_KEY . '.txt', $settings['key_location'], 'key_location stored from the SaaS response' );
assert_true( $settings['key_file_confirmed'], 'key_file_confirmed stored after confirm' );

$fs_writes = $GLOBALS['insightistic_test_fs']->writes;
assert_true( 1 === count( $fs_writes ), 'key file written once' );
assert_same( INDEXNOW_TEST_KEY, $fs_writes[0]['contents'], 'key file body is the key' );

// Second run with a confirmed key and the file present: no HTTP at all.
$GLOBALS['insightistic_test_fs']->writes   = array();
$GLOBALS['insightistic_test_http_calls']   = array();
$GLOBALS['insightistic_test_http_queue']   = array();
assert_true( Insightistic_IndexNow::ensure_key(), 'second ensure_key run succeeds from cache' );
assert_same( 0, count( $GLOBALS['insightistic_test_http_calls'] ), 'cached confirmed key makes no key/confirm calls' );
assert_true( 1 === count( $GLOBALS['insightistic_test_fs']->writes ), 'cached run still self-heals the key file presence' );

// Unconfirmed cached key -> only the confirm call.
indexnow_reset();
$GLOBALS['insightistic_test_options']['insightistic_indexnow'] = array(
	'key'                 => INDEXNOW_TEST_KEY,
	'key_location'        => '',
	'key_file_confirmed'  => false,
	'auto_submit_enabled' => 'yes',
	'last_submitted'      => '',
);
queue_http( 200, wp_json_encode( array( 'key_file_confirmed' => true ) ) );
assert_true( Insightistic_IndexNow::ensure_key(), 'cached unconfirmed key finishes confirmation' );
assert_same( 1, count( $GLOBALS['insightistic_test_http_calls'] ), 'unconfirmed key makes only the confirm call' );

// Bad key format -> refuse, never confirm.
indexnow_reset();
queue_http( 200, wp_json_encode( array( 'key' => 'not-hex', 'key_location' => 'x', 'key_file_confirmed' => false ) ) );
assert_true( ! Insightistic_IndexNow::ensure_key(), 'unexpected key format fails safely' );
assert_same( 1, count( $GLOBALS['insightistic_test_http_calls'] ), 'bad key format makes no confirm call' );

// Key fetch failure -> logged, no confirm.
indexnow_reset();
queue_http( 500, wp_json_encode( array( 'message' => 'boom' ) ) );
assert_true( ! Insightistic_IndexNow::ensure_key(), 'key fetch failure returns false' );
assert_same( 1, count( $GLOBALS['insightistic_test_http_calls'] ), 'fetch failure makes no confirm call' );
$log = get_option( 'insightistic_sync_log', array() );
assert_true( ! empty( $log ) && 'error' === $log[0]['level'], 'fetch failure is logged as an error' );

// Disconnected -> silent false.
indexnow_reset();
Insightistic_License_Manager::$connected = false;
assert_true( ! Insightistic_IndexNow::ensure_key(), 'disconnected ensure_key returns false quietly' );
assert_same( 0, count( $GLOBALS['insightistic_test_http_calls'] ), 'disconnected ensure_key makes no calls' );

/* 9. init(): cron schedule registration. */
echo "- init cron scheduling\n";
indexnow_reset();
$in->init();
assert_true( isset( $GLOBALS['insightistic_test_cron_events'][0] ), 'init schedules the flush cron when connected' );
assert_same( Insightistic_IndexNow::FLUSH_HOOK, $GLOBALS['insightistic_test_cron_events'][0]['hook'], 'flush cron uses the flush hook' );
assert_same( Insightistic_IndexNow::CRON_SCHEDULE, $GLOBALS['insightistic_test_cron_events'][0]['recurrence'], 'flush cron uses the 15-minute recurrence' );

$schedules = $in->add_cron_schedule( array() );
assert_true( isset( $schedules['insightistic_15min'] ), 'custom 15-minute recurrence registered' );
assert_same( 900, $schedules['insightistic_15min']['interval'], '15-minute recurrence interval is 900s' );

indexnow_reset();
$GLOBALS['insightistic_test_next_scheduled'] = time() + 60;
$in->init();
assert_same( 0, count( $GLOBALS['insightistic_test_cron_events'] ), 'init does not double-schedule an existing flush cron' );

test_summary( 'INDEXNOW TESTS' );
