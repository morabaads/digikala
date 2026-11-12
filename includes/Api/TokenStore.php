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

	/**
	 * Decoded JWT payload (claims), or empty array.
	 *
	 * @param string $jwt Token.
	 * @return array
	 */
	public static function jwt_claims( $jwt ) {
		$parts = explode( '.', (string) $jwt );
		if ( count( $parts ) < 2 ) {
			return array();
		}
		$b64     = strtr( $parts[1], '-_', '+/' );
		$payload = json_decode( (string) base64_decode( str_pad( $b64, (int) ( ceil( strlen( $b64 ) / 4 ) * 4 ), '=' ) ), true );
		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Try to find the seller code inside the token claims.
	 *
	 * @param string $jwt Token.
	 * @return string Empty when not found.
	 */
	public static function seller_code_from_token( $jwt ) {
		$claims = self::jwt_claims( $jwt );
		$search = static function ( $data ) use ( &$search ) {
			foreach ( array( 'seller_code', 'sellerCode', 'seller_id', 'sellerId', 'marketplace_seller_id', 'marketplaceSellerId', 'supplier_code', 'seller' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
					return trim( (string) $data[ $key ] );
				}
			}
			foreach ( $data as $value ) {
				if ( is_array( $value ) ) {
					$found = $search( $value );
					if ( '' !== $found ) {
						return $found;
					}
				}
			}
			return '';
		};
		return $search( $claims );
	}
}
