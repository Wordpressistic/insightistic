<?php
/**
 * Encryption regression tests.
 *
 * Round-trip, per-record salt uniqueness, tamper detection (encrypt-then-MAC),
 * legacy (< 3.3.0) format decryption, and the legacy -> v1 re-encryption
 * migration path that keeps credentials readable across upgrades.
 *
 * @package Insightistic
 */

// PHPCS:ignoreFile -- standalone test, WordPress is not loaded.

require __DIR__ . '/wp-stubs.php';

/* The legacy format derives its key from wp_salt('auth'). */
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-fixed-0123456789abcdef0123456789abcdef';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-insightistic-encryption.php';

require __DIR__ . '/lib.php';

echo "== Encryption tests ==\n";

/* The crypto paths require openssl; skip honestly where it is unavailable. */
if ( ! function_exists( 'openssl_encrypt' ) ) {
	echo "SKIP: the openssl extension is not available in this PHP build.\n";
	echo "      (CI and the Docker release gate run this suite with openssl enabled.)\n";
	test_summary( 'ENCRYPTION TESTS (SKIPPED)' );
}

/* Round-trip. */
$ct = Insightistic_Encryption::encrypt( 'super-secret-measurement-secret' );
assert_true( false !== $ct && '' !== $ct, 'encrypt() returns cipher text' );
assert_same( 'super-secret-measurement-secret', Insightistic_Encryption::decrypt( $ct ), 'decrypt(encrypt(x)) === x' );

/* Random salt per record: two encryptions differ, both decrypt. */
$a = Insightistic_Encryption::encrypt( 'same-input' );
$b = Insightistic_Encryption::encrypt( 'same-input' );
assert_true( $a !== $b, 'per-record salt: identical inputs encrypt differently' );
assert_same( 'same-input', Insightistic_Encryption::decrypt( $a ), 'first nonce decrypts' );
assert_same( 'same-input', Insightistic_Encryption::decrypt( $b ), 'second nonce decrypts' );

/* Tamper detection. */
$decoded = base64_decode( $ct, true );
assert_true( false !== $decoded && isset( $decoded[40] ), 'v1 blob long enough to tamper with' );
$tampered = base64_encode( substr( $decoded, 0, 40 ) . ( 'X' === $decoded[40] ? 'Y' : 'X' ) . substr( $decoded, 41 ) );
assert_same( false, Insightistic_Encryption::decrypt( $tampered ), 'tampered ciphertext rejected by HMAC' );

assert_same( false, Insightistic_Encryption::decrypt( 'not-base64!!!' ), 'non-base64 input returns false' );
assert_same( false, Insightistic_Encryption::decrypt( base64_encode( 'garbage' ) ), 'undecodable blob returns false' );
assert_same( false, Insightistic_Encryption::decrypt( '' ), 'empty input returns false' );
assert_same( false, Insightistic_Encryption::encrypt( '' ), 'empty plaintext not encrypted' );

/* is_encrypted() recognises both formats. */
assert_true( Insightistic_Encryption::is_encrypted( $ct ), 'is_encrypted() recognises v1 format' );

/* ------------------------------------------------------------------ */
/* Legacy (< 3.3.0) format: base64( openssl_b64_cipher . '::' . iv )   */
/* keyed directly on wp_salt('auth').                                  */
/* ------------------------------------------------------------------ */

$legacy_plain = 'legacy-key-AIzaSyDEADBEEF';
$iv           = openssl_random_pseudo_bytes( 16 );
$legacy_inner = openssl_encrypt( $legacy_plain, 'AES-256-CBC', wp_salt( 'auth' ), 0, $iv );
$legacy_stored = base64_encode( $legacy_inner . '::' . $iv );

assert_same( $legacy_plain, Insightistic_Encryption::decrypt( $legacy_stored ), 'legacy (<3.3.0) credential still decrypts' );
assert_true( Insightistic_Encryption::is_encrypted( $legacy_stored ), 'is_encrypted() recognises legacy format' );

/* ------------------------------------------------------------------ */
/* Migration path: legacy value -> decrypt -> re-encrypt -> decrypt.   */
/* ------------------------------------------------------------------ */

$re_encrypted = Insightistic_Encryption::encrypt( Insightistic_Encryption::decrypt( $legacy_stored ) );
assert_same( $legacy_plain, Insightistic_Encryption::decrypt( $re_encrypted ), 'migrated credential survives re-encryption round-trip' );
assert_true( Insightistic_Encryption::is_encrypted( $re_encrypted ), 'migrated value now stored in v1 format' );

/* A v1 value that is NOT legacy must never hit the legacy fallback path. */
assert_same( false, Insightistic_Encryption::decrypt( base64_encode( "\x01short-blob" ) ), 'truncated v1 blob returns false, not a legacy crash' );

/* Per-site secret is persisted, not regenerated per request. */
$secret = get_option( 'insightistic_crypto_secret', '' );
assert_true( is_string( $secret ) && strlen( $secret ) >= 32, 'per-site base secret generated and stored (>=32 bytes)' );

test_summary( 'ENCRYPTION TESTS' );
