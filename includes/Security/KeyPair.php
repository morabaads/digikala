<?php
/**
 * RSA key pair for Digikala's "self token" registration.
 *
 * Digikala asks the seller for an RSA-4096 public key (seller panel → ثبت توکن اختصاصی →
 * اطلاعات کلید عمومی). Sensitive values such as the validation / authorization code are
 * encrypted with that public key and must be decrypted with the private key, which never
 * leaves this site and is stored encrypted at rest.
 *
 * Decryption tries RSA-OAEP with SHA-256, RSA-OAEP with SHA-1 and PKCS#1 v1.5, in that
 * order. PHP's OpenSSL extension only supports OAEP with SHA-1, so OAEP-SHA-256 is decoded
 * here (RFC 8017 §7.1.2) on top of a raw RSA operation.
 *
 * @package DKBridge
 */

namespace DKBridge\Security;

/**
 * Key pair manager.
 */
class KeyPair {

	const OPTION = 'dkb_keypair';
	const BITS   = 4096;

	/**
	 * Generate a new key pair (does not store it).
	 *
	 * @param int $bits Key size.
	 * @return array{private:string,public:string}
	 * @throws \RuntimeException When OpenSSL fails.
	 */
	public static function generate( $bits = self::BITS ) {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			throw new \RuntimeException( 'افزونهٔ OpenSSL روی PHP سرور فعال نیست.' );
		}
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => (int) $bits,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
				'digest_alg'       => 'sha256',
			)
		);
		if ( ! $key ) {
			throw new \RuntimeException( 'ساخت کلید RSA ناموفق بود: ' . (string) openssl_error_string() );
		}
		$private = '';
		openssl_pkey_export( $key, $private );
		$details = openssl_pkey_get_details( $key );
		return array(
			'private' => (string) $private,
			'public'  => (string) $details['key'],
		);
	}

	/**
	 * Public key from a private key PEM.
	 *
	 * @param string $private_pem Private key.
	 * @return array{public:string,bits:int}
	 * @throws \InvalidArgumentException For invalid keys.
	 */
	public static function inspect( $private_pem ) {
		$key = openssl_pkey_get_private( trim( (string) $private_pem ) );
		if ( ! $key ) {
			throw new \InvalidArgumentException( 'کلید خصوصی معتبر نیست (قالب PEM لازم است).' );
		}
		$details = openssl_pkey_get_details( $key );
		if ( OPENSSL_KEYTYPE_RSA !== $details['type'] ) {
			throw new \InvalidArgumentException( 'کلید باید از نوع RSA باشد.' );
		}
		return array(
			'public' => (string) $details['key'],
			'bits'   => (int) $details['bits'],
		);
	}

	/**
	 * SHA-256 fingerprint of a public key (hex, colon separated) for visual comparison.
	 *
	 * @param string $public_pem Public key.
	 * @return string
	 */
	public static function fingerprint( $public_pem ) {
		$b64 = preg_replace( '/-----[^-]+-----|\s+/', '', (string) $public_pem );
		$der = base64_decode( $b64, true );
		if ( false === $der ) {
			return '';
		}
		return implode( ':', str_split( substr( hash( 'sha256', $der ), 0, 32 ), 2 ) );
	}

	// ------------------------------------------------------------- Storage.

	/**
	 * Stored key info (without the private key).
	 *
	 * @return array{public:string,fingerprint:string,bits:int,created_at:int}|null
	 */
	public static function stored() {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $raw ) || empty( $raw['public'] ) ) {
			return null;
		}
		return array(
			'public'      => (string) $raw['public'],
			'fingerprint' => self::fingerprint( $raw['public'] ),
			'bits'        => isset( $raw['bits'] ) ? (int) $raw['bits'] : 0,
			'created_at'  => isset( $raw['created_at'] ) ? (int) $raw['created_at'] : 0,
		);
	}

	/**
	 * Persist a private key (encrypted) and its public key.
	 *
	 * @param string $private_pem Private key.
	 * @return array Stored info.
	 */
	public static function store( $private_pem ) {
		$info = self::inspect( $private_pem );
		update_option(
			self::OPTION,
			array(
				'private'    => Crypto::encrypt( trim( $private_pem ) ),
				'public'     => $info['public'],
				'bits'       => $info['bits'],
				'created_at' => time(),
			),
			false
		);
		return self::stored();
	}

	/**
	 * Generate and store a new pair.
	 *
	 * @return array Stored info.
	 */
	public static function create() {
		$pair = self::generate();
		return self::store( $pair['private'] );
	}

	/**
	 * Stored private key PEM (decrypted).
	 *
	 * @return string
	 */
	private static function private_pem() {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) && ! empty( $raw['private'] ) ? Crypto::decrypt( $raw['private'] ) : '';
	}

	/**
	 * Remove the stored pair.
	 */
	public static function delete() {
		delete_option( self::OPTION );
	}

	// ------------------------------------------------------------ Decrypt.

	/**
	 * Whether a string looks like an RSA-encrypted, base64 encoded value (not a JWT / plain code).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_encrypted( $value ) {
		$value = trim( (string) $value );
		if ( 2 === substr_count( $value, '.' ) ) {
			return false; // JWT.
		}
		$bytes = self::b64decode( $value );
		return null !== $bytes && strlen( $bytes ) >= 128 && 0 === strlen( $bytes ) % 128;
	}

	/**
	 * Decrypt with the stored private key.
	 *
	 * @param string $cipher Base64 / base64url cipher text.
	 * @return string Plain text.
	 * @throws \RuntimeException When no key is stored or decryption fails.
	 */
	public static function decrypt_stored( $cipher ) {
		$pem = self::private_pem();
		if ( '' === $pem ) {
			throw new \RuntimeException( 'هنوز کلید خصوصی ساخته یا وارد نشده است.' );
		}
		return self::decrypt( $cipher, $pem );
	}

	/**
	 * Decrypt a value encrypted with our public key.
	 *
	 * @param string $cipher      Base64 / base64url (whitespace tolerated). Longer inputs are decrypted block by block.
	 * @param string $private_pem Private key.
	 * @return string
	 * @throws \RuntimeException On failure.
	 */
	public static function decrypt( $cipher, $private_pem ) {
		$bytes = self::b64decode( $cipher );
		if ( null === $bytes || '' === $bytes ) {
			throw new \RuntimeException( 'مقدار رمزشده Base64 معتبر نیست.' );
		}
		$key = openssl_pkey_get_private( $private_pem );
		if ( ! $key ) {
			throw new \RuntimeException( 'کلید خصوصی قابل خواندن نیست.' );
		}
		$k = (int) ( openssl_pkey_get_details( $key )['bits'] / 8 );
		if ( 0 !== strlen( $bytes ) % $k ) {
			throw new \RuntimeException( sprintf( 'طول دادهٔ رمزشده (%d بایت) با کلید %d بیتی نمی‌خواند؛ احتمالاً با کلید دیگری رمز شده است.', strlen( $bytes ), $k * 8 ) );
		}
		$out = '';
		foreach ( str_split( $bytes, $k ) as $block ) {
			$out .= self::decrypt_block( $block, $key, $k );
		}
		return $out;
	}

	/**
	 * Decrypt one RSA block trying the supported paddings.
	 *
	 * @param string                       $block Block.
	 * @param \OpenSSLAsymmetricKey|resource $key   Key.
	 * @param int                          $k     Modulus length in bytes.
	 * @return string
	 * @throws \RuntimeException When no padding matches.
	 */
	private static function decrypt_block( $block, $key, $k ) {
		// 1) OAEP + SHA-256 (decoded manually).
		$em = '';
		if ( openssl_private_decrypt( $block, $em, $key, OPENSSL_NO_PADDING ) ) {
			$plain = self::oaep_decode( $em, $k, 'sha256' );
			if ( null !== $plain ) {
				return $plain;
			}
		}
		// 2) OAEP + SHA-1 and 3) PKCS#1 v1.5 via OpenSSL. Recent OpenSSL versions answer an
		// invalid PKCS#1 v1.5 block with random bytes ("implicit rejection") instead of an
		// error, so only accept clean text; Digikala's codes and tokens are printable.
		$plain = '';
		if ( openssl_private_decrypt( $block, $plain, $key, OPENSSL_PKCS1_OAEP_PADDING ) && self::is_text( $plain ) ) {
			return $plain;
		}
		$plain = '';
		if ( openssl_private_decrypt( $block, $plain, $key, OPENSSL_PKCS1_PADDING ) && preg_match( '/^[\x20-\x7E]{8,}$/', $plain ) ) {
			return $plain;
		}
		while ( openssl_error_string() ) {
			// Drain the OpenSSL error queue.
		}
		throw new \RuntimeException( 'رمزگشایی ناموفق بود؛ این مقدار با کلید عمومی فعلی شما رمز نشده است.' );
	}

	/**
	 * Valid UTF-8 without control characters (except tab / newline).
	 *
	 * @param string $s Value.
	 * @return bool
	 */
	public static function is_text( $s ) {
		return '' !== $s && 1 === preg_match( '//u', $s ) && ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $s );
	}

	/**
	 * EME-OAEP decoding (RFC 8017 §7.1.2) with MGF1 using the same hash, empty label.
	 *
	 * @param string $em   Encoded message (k bytes).
	 * @param int    $k    Modulus length.
	 * @param string $hash Hash algorithm.
	 * @return string|null Message, or null when the padding is invalid.
	 */
	public static function oaep_decode( $em, $k, $hash = 'sha256' ) {
		$em    = str_pad( $em, $k, "\0", STR_PAD_LEFT );
		$h_len = strlen( hash( $hash, '', true ) );
		if ( $k < 2 * $h_len + 2 || "\0" !== $em[0] ) {
			return null;
		}
		$masked_seed = substr( $em, 1, $h_len );
		$masked_db   = substr( $em, 1 + $h_len );
		$seed        = $masked_seed ^ self::mgf1( $masked_db, $h_len, $hash );
		$db          = $masked_db ^ self::mgf1( $seed, $k - $h_len - 1, $hash );
		$l_hash      = hash( $hash, '', true );
		if ( ! hash_equals( $l_hash, substr( $db, 0, $h_len ) ) ) {
			return null;
		}
		$rest = substr( $db, $h_len );
		$pos  = strpos( $rest, "\x01" );
		if ( false === $pos || '' !== trim( substr( $rest, 0, $pos ), "\0" ) ) {
			return null;
		}
		return (string) substr( $rest, $pos + 1 );
	}

	/**
	 * EME-OAEP encoding (used by tests to produce OAEP-SHA-256 cipher texts with PHP only).
	 *
	 * @param string $message Message.
	 * @param int    $k       Modulus length.
	 * @param string $hash    Hash.
	 * @return string
	 */
	public static function oaep_encode( $message, $k, $hash = 'sha256' ) {
		$h_len  = strlen( hash( $hash, '', true ) );
		$ps     = str_repeat( "\0", $k - strlen( $message ) - 2 * $h_len - 2 );
		$db     = hash( $hash, '', true ) . $ps . "\x01" . $message;
		$seed   = random_bytes( $h_len );
		$m_db   = $db ^ self::mgf1( $seed, $k - $h_len - 1, $hash );
		$m_seed = $seed ^ self::mgf1( $m_db, $h_len, $hash );
		return "\0" . $m_seed . $m_db;
	}

	/**
	 * MGF1 mask generation.
	 *
	 * @param string $seed Seed.
	 * @param int    $len  Length.
	 * @param string $hash Hash.
	 * @return string
	 */
	private static function mgf1( $seed, $len, $hash ) {
		$t = '';
		for ( $i = 0; strlen( $t ) < $len; $i++ ) {
			$t .= hash( $hash, $seed . pack( 'N', $i ), true );
		}
		return substr( $t, 0, $len );
	}

	/**
	 * Decode base64 / base64url with optional whitespace and missing padding.
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	public static function b64decode( $value ) {
		$value = preg_replace( '/\s+/', '', (string) $value );
		if ( '' === $value || ! preg_match( '/^[A-Za-z0-9+\/=_-]+$/', $value ) ) {
			return null;
		}
		$value = strtr( rtrim( $value, '=' ), '-_', '+/' );
		$value = str_pad( $value, (int) ( ceil( strlen( $value ) / 4 ) * 4 ), '=' );
		$out   = base64_decode( $value, true );
		return false === $out ? null : $out;
	}
}
