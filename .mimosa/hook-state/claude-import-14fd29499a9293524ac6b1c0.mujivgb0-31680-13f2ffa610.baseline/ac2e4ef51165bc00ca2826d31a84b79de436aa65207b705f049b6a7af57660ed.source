<?php
/**
 * Encryption helper class for Insightistic.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Encryption
 *
 * Authenticated encryption for sensitive credentials.
 *
 * Format (version 1):  base64( "\x01" . salt(16) . iv(16) . hmac(32) . ciphertext )
 *   - AES-256-CBC for confidentiality, HMAC-SHA256 (encrypt-then-MAC) for integrity.
 *   - Per-record random salt feeds HKDF so every value uses a unique enc/mac key.
 *
 * The base secret is, in priority order:
 *   1. The INSIGHTISTIC_ENCRYPTION_KEY constant (recommended: define in wp-config.php).
 *   2. A random per-site secret stored once in the `insightistic_crypto_secret` option.
 *
 * This intentionally does NOT key directly off wp_salt(), so rotating the
 * WordPress salts no longer renders every stored credential undecryptable.
 *
 * Legacy values written by Insightistic < 3.3.0 (base64( cipher . '::' . iv )
 * keyed on wp_salt('auth')) are still readable via the legacy fallback, so
 * upgrades are seamless and a one-time migration re-encrypts them.
 */
class Insightistic_Encryption {

	/** Current format version marker (first byte of the decoded blob). */
	const VERSION = "\x01";

	/** Option that stores the random per-site base secret. */
	const SECRET_OPTION = 'insightistic_crypto_secret';

	/**
	 * Encrypt a string.
	 *
	 * @param string $data Plain text to encrypt.
	 * @return string|false Base64-encoded cipher text, or false on failure.
	 */
	public static function encrypt( $data ) {
		if ( '' === $data || null === $data ) {
			return false;
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}

		$secret = self::base_secret();
		if ( '' === $secret ) {
			return false;
		}

		try {
			$salt = random_bytes( 16 );
			$iv   = random_bytes( 16 );
		} catch ( \Exception $e ) {
			return false;
		}

		$enc_key = hash_hkdf( 'sha256', $secret, 32, 'insightistic-aes', $salt );
		$mac_key = hash_hkdf( 'sha256', $secret, 32, 'insightistic-hmac', $salt );

		$cipher = openssl_encrypt( $data, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return false;
		}

		$mac  = hash_hmac( 'sha256', $salt . $iv . $cipher, $mac_key, true );
		$blob = self::VERSION . $salt . $iv . $mac . $cipher;

		return base64_encode( $blob ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a string.
	 *
	 * @param string $data Base64-encoded cipher text.
	 * @return string|false Plain text, or false on failure.
	 */
	public static function decrypt( $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		// Versioned (>= 3.3.0) format: 1 + 16 + 16 + 32 = 65 bytes of header.
		if ( false !== $decoded && strlen( $decoded ) > 65 && self::VERSION === $decoded[0] ) {
			$salt   = substr( $decoded, 1, 16 );
			$iv     = substr( $decoded, 17, 16 );
			$mac    = substr( $decoded, 33, 32 );
			$cipher = substr( $decoded, 65 );

			$secret  = self::base_secret();
			$mac_key = hash_hkdf( 'sha256', $secret, 32, 'insightistic-hmac', $salt );
			$calc    = hash_hmac( 'sha256', $salt . $iv . $cipher, $mac_key, true );

			// Constant-time integrity check before attempting decryption.
			if ( ! hash_equals( $calc, $mac ) ) {
				return false;
			}

			$enc_key = hash_hkdf( 'sha256', $secret, 32, 'insightistic-aes', $salt );
			$plain   = openssl_decrypt( $cipher, 'aes-256-cbc', $enc_key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? false : $plain;
		}

		// Legacy (< 3.3.0) fallback.
		return self::decrypt_legacy( $data );
	}

	/**
	 * Decrypt a value written by Insightistic < 3.3.0.
	 *
	 * @param string $data Base64-encoded legacy cipher text.
	 * @return string|false
	 */
	private static function decrypt_legacy( $data ) {
		$decoded = base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded || strpos( $decoded, '::' ) === false ) {
			return false;
		}

		list( $encrypted_data, $iv ) = explode( '::', $decoded, 2 );

		$key   = wp_salt( 'auth' );
		$plain = openssl_decrypt( $encrypted_data, 'AES-256-CBC', $key, 0, $iv );

		return false === $plain ? false : $plain;
	}

	/**
	 * Check whether a stored value looks encrypted (either format).
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		if ( empty( $value ) ) {
			return false;
		}
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return false;
		}
		if ( strlen( $decoded ) > 65 && self::VERSION === $decoded[0] ) {
			return true;
		}
		return strpos( $decoded, '::' ) !== false;
	}

	/**
	 * Whether a value uses the legacy (< 3.3.0) format and should be migrated.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_legacy( $value ) {
		if ( empty( $value ) ) {
			return false;
		}
		$decoded = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded ) {
			return false;
		}
		if ( strlen( $decoded ) > 65 && self::VERSION === $decoded[0] ) {
			return false;
		}
		return strpos( $decoded, '::' ) !== false;
	}

	/**
	 * Resolve the base secret used for key derivation.
	 *
	 * @return string
	 */
	private static function base_secret() {
		if ( defined( 'INSIGHTISTIC_ENCRYPTION_KEY' ) && INSIGHTISTIC_ENCRYPTION_KEY ) {
			return (string) INSIGHTISTIC_ENCRYPTION_KEY;
		}

		$secret = get_option( self::SECRET_OPTION );
		if ( $secret ) {
			return (string) $secret;
		}

		// Generate once, store without autoloading.
		try {
			$secret = base64_encode( random_bytes( 48 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \Exception $e ) {
			// Last-resort fallback so encryption still works on exotic hosts.
			$secret = wp_salt( 'secure_auth' ) . wp_salt( 'auth' );
		}
		add_option( self::SECRET_OPTION, $secret, '', 'no' );

		return $secret;
	}
}
