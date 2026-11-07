<?php
/**
 * Encrypted storage for access / refresh tokens.
 *
 * @package DKBridge
 */

namespace DKBridge\Api;

use DKBridge\Security\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Token store.
 */
class TokenStore {

	const OPTION = 'dkb_tokens';

	/**
	 * Raw stored array.
	 *
	 * @return array
	 */
	private function raw() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Access token (plain).
	 *
	 * @return string
	 */
	public function access_token() {
		$raw = $this->raw();
		return isset( $raw['access'] ) ? Crypto::decrypt( $raw['access'] ) : '';
	}

	/**
	 * Refresh token (plain).
	 *
	 * @return string
	 */
	public function refresh_token() {
		$raw = $this->raw();
		return isset( $raw['refresh'] ) ? Crypto::decrypt( $raw['refresh'] ) : '';
	}

	/**
	 * Access token expiry (unix time, 0 = unknown).
	 *
	 * @return int
	 */
	public function expires_at() {
		$raw = $this->raw();
		return isset( $raw['expires_at'] ) ? (int) $raw['expires_at'] : 0;
	}

	/**
	 * Whether any access token is stored.
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== $this->access_token();
	}

	/**
	 * Save tokens.
	 *
	 * @param string     $access     Access token.
	 * @param string     $refresh    Refresh token ('' keeps old one when $keep_refresh).
	 * @param int|string $expires_at Unix timestamp or DK date string.
	 */
	public function save( $access, $refresh = '', $expires_at = 0 ) {
		$raw           = $this->raw();
		$raw['access'] = Crypto::encrypt( trim( (string) $access ) );
		if ( '' !== (string) $refresh ) {
			$raw['refresh'] = Crypto::encrypt( trim( (string) $refresh ) );
		}
		$raw['expires_at'] = self::parse_expiry( $expires_at );
		$raw['saved_at']   = time();
		update_option( self::OPTION, $raw, false );
	}

	/**
	 * Save a Digikala token response payload (data part).
	 *
	 * @param array $data Response data with access_token, refresh_token, access_token_expires_at.
	 */
	public function save_response( array $data ) {
		$expiry = 0;
		if ( isset( $data['access_token_expires_at']['date'] ) ) {
			$expiry = $data['access_token_expires_at'];
		}
		$this->save(
			isset( $data['access_token'] ) ? $data['access_token'] : '',
			isset( $data['refresh_token'] ) ? $data['refresh_token'] : '',
			$expiry
		);
	}

	/**
	 * Remove tokens.
	 */
	public function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Parse an expiry value: unix int, or DK object {date, timezone}, or a date string.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	public static function parse_expiry( $value ) {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		$timezone = 'Asia/Tehran';
		if ( is_array( $value ) ) {
			$timezone = ! empty( $value['timezone'] ) ? $value['timezone'] : $timezone;
			$value    = isset( $value['date'] ) ? $value['date'] : '';
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return 0;
		}
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( $timezone ) );
			return $date->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Try to read the expiry from the JWT payload itself.
	 *
	 * @param string $jwt Token.
	 * @return int
	 */
	public static function jwt_expiry( $jwt ) {
		$parts = explode( '.', (string) $jwt );
		if ( count( $parts ) < 2 ) {
			return 0;
		}
		$payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
		return is_array( $payload ) && isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
	}
}
