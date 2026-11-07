<?php
/**
 * Symmetric encryption for secrets stored in wp_options.
 *
 * @package DKBridge
 */

namespace DKBridge\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts with libsodium secretbox (fallback: AES-256-GCM via OpenSSL).
 * The key is derived from the site's secret salts, so a database dump alone
 * does not reveal the tokens.
 */
class Crypto {

	/**
	 * Derive a 32 byte key.
	 *
	 * @return string
	 */
	private static function key() {
		$material = ( defined( 'DKB_ENCRYPTION_KEY' ) ? DKB_ENCRYPTION_KEY : '' )
			. ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'dkb' );
		return hash( 'sha256', 'dk-bridge|' . $material, true );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 * @return string Base64 cipher text with scheme prefix.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) );
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return 'o1:' . base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt a string produced by encrypt().
	 *
	 * @param string $encoded Cipher text.
	 * @return string Plain text or '' on failure.
	 */
	public static function decrypt( $encoded ) {
		$encoded = (string) $encoded;
		if ( '' === $encoded || strlen( $encoded ) < 4 ) {
			return '';
		}
		$scheme = substr( $encoded, 0, 3 );
		$raw    = base64_decode( substr( $encoded, 3 ), true );
		if ( false === $raw ) {
			return '';
		}
		if ( 's1:' === $scheme && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
			return false === $plain ? '' : $plain;
		}
		if ( 'o1:' === $scheme ) {
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	/**
	 * Mask a secret for display.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( $secret ) {
		$secret = (string) $secret;
		if ( strlen( $secret ) <= 8 ) {
			return '' === $secret ? '' : '••••';
		}
		return substr( $secret, 0, 4 ) . str_repeat( '•', 8 ) . substr( $secret, -4 );
	}
}
