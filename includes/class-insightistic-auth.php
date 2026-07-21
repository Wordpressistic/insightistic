<?php
/**
 * Shared JWT authentication helper for Insightistic.
 * Handles OAuth2 service account auth for GA4 and Search Console.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_Auth
 */
class Insightistic_Auth {

	/**
	 * Get a cached OAuth2 access token for the given scope.
	 *
	 * @param string $scope     Google API scope URL.
	 * @param string $cache_key Transient cache key suffix.
	 * @return string|WP_Error  Access token or WP_Error.
	 */
	public static function get_token( $scope, $cache_key = 'ga4' ) {
		$transient = 'insightistic_access_token_' . $cache_key;
		$cached    = get_transient( $transient );
		if ( $cached ) {
			return $cached;
		}

		$client_email = get_option( 'insightistic_api_email' );
		$enc_key      = get_option( 'insightistic_api_private_key' );

		if ( ! $client_email || ! $enc_key ) {
			return new WP_Error( 'missing_creds', __( 'Service account credentials are not configured.', 'insightistic' ) );
		}

		$private_key = Insightistic_Encryption::decrypt( $enc_key );
		if ( ! $private_key ) {
			return new WP_Error( 'decrypt_fail', __( 'Failed to decrypt the private key. Please re-save your settings.', 'insightistic' ) );
		}

		// Normalise escaped newlines.
		if ( strpos( $private_key, '\\n' ) !== false ) {
			$private_key = str_replace( '\\n', "\n", $private_key );
		}
		$private_key = trim( $private_key, "\"'" );

		if ( strpos( $private_key, '-----BEGIN PRIVATE KEY-----' ) === false ) {
			return new WP_Error( 'invalid_key', __( 'Private key must begin with -----BEGIN PRIVATE KEY-----.', 'insightistic' ) );
		}

		// Build JWT.
		$now    = time();
		$header = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = self::base64url(
			wp_json_encode(
				array(
					'iss'   => $client_email,
					'scope' => $scope,
					'aud'   => 'https://oauth2.googleapis.com/token',
					'exp'   => $now + 3600,
					'iat'   => $now,
				)
			)
		);

		$key_resource = openssl_pkey_get_private( $private_key );
		if ( ! $key_resource ) {
			return new WP_Error( 'invalid_key', __( 'The private key is not valid. Please check the format.', 'insightistic' ) );
		}

		$sig = '';
		openssl_sign( $header . '.' . $claims, $sig, $key_resource, OPENSSL_ALGO_SHA256 );
		$jwt = $header . '.' . $claims . '.' . self::base64url( $sig );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['access_token'] ) ) {
			set_transient( $transient, $body['access_token'], 3500 );
			return $body['access_token'];
		}

		$msg = $body['error_description'] ?? $body['error'] ?? __( 'Unknown authentication error.', 'insightistic' );
		return new WP_Error( 'token_error', $msg );
	}

	/**
	 * Base64url encode (no padding).
	 *
	 * @param string $data Data to encode.
	 * @return string
	 */
	private static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
}
