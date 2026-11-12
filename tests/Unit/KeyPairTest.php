<?php
/**
 * Tests for RSA key generation and Digikala-compatible decryption.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Security\KeyPair;
use PHPUnit\Framework\TestCase;

class KeyPairTest extends TestCase {

	private static $pair;

	public static function setUpBeforeClass(): void {
		self::$pair = KeyPair::generate( 2048 );
	}

	private function k() {
		return 256;
	}

	private function encrypt_oaep256( $message ) {
		$em  = KeyPair::oaep_encode( $message, $this->k(), 'sha256' );
		$out = '';
		openssl_public_encrypt( $em, $out, self::$pair['public'], OPENSSL_NO_PADDING );
		return base64_encode( $out );
	}

	public function test_generates_spki_public_key_like_digikala_form() {
		$pair = KeyPair::generate( 4096 );
		$this->assertStringStartsWith( '-----BEGIN PUBLIC KEY-----', $pair['public'] );
		$this->assertStringContainsString( 'PRIVATE KEY-----', $pair['private'] );
		$this->assertSame( 4096, KeyPair::inspect( $pair['private'] )['bits'] );
		$this->assertMatchesRegularExpression( '/^([0-9a-f]{2}:){15}[0-9a-f]{2}$/', KeyPair::fingerprint( $pair['public'] ) );
	}

	public function test_decrypts_oaep_sha256() {
		$cipher = $this->encrypt_oaep256( 'auth-code-123' );
		$this->assertSame( 'auth-code-123', KeyPair::decrypt( $cipher, self::$pair['private'] ) );
	}

	public function test_decrypts_oaep_sha1_and_pkcs1() {
		foreach ( array( OPENSSL_PKCS1_OAEP_PADDING, OPENSSL_PKCS1_PADDING ) as $padding ) {
			$out = '';
			openssl_public_encrypt( 'auth-code-' . $padding . '-x9F2kQ', $out, self::$pair['public'], $padding );
			$this->assertSame( 'auth-code-' . $padding . '-x9F2kQ', KeyPair::decrypt( base64_encode( $out ), self::$pair['private'] ) );
		}
	}

	public function test_base64url_whitespace_and_multiple_blocks() {
		$a      = KeyPair::b64decode( $this->encrypt_oaep256( 'part1-' ) );
		$b      = KeyPair::b64decode( $this->encrypt_oaep256( 'part2' ) );
		$cipher = rtrim( strtr( base64_encode( $a . $b ), '+/', '-_' ), '=' );
		$this->assertSame( 'part1-part2', KeyPair::decrypt( chunk_split( $cipher, 64, "\n" ), self::$pair['private'] ) );
		$this->assertTrue( KeyPair::looks_encrypted( $cipher ) );
		$this->assertFalse( KeyPair::looks_encrypted( 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc' ) );
		$this->assertFalse( KeyPair::looks_encrypted( 'short-code' ) );
	}

	public function test_wrong_key_fails_cleanly() {
		$other  = KeyPair::generate( 2048 );
		$cipher = $this->encrypt_oaep256( 'secret' );
		$this->expectException( \RuntimeException::class );
		KeyPair::decrypt( $cipher, $other['private'] );
	}

	public function test_oaep_decode_rejects_garbage() {
		$this->assertNull( KeyPair::oaep_decode( random_bytes( 256 ), 256, 'sha256' ) );
	}
}
