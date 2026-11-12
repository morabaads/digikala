<?php
/**
 * Tests for the HTTP client (with a fake transport).
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Api\ApiException;
use DKBridge\Api\Client;
use DKBridge\Api\Digikala;
use DKBridge\Api\DryRunException;
use DKBridge\Api\TokenStore;
use DKBridge\Settings;
use DKBridge\Support\Logger;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase {

	/** @var array */
	private $requests = array();
	/** @var array */
	private $responses = array();
	/** @var int[] */
	private $sleeps = array();

	protected function setUp(): void {
		dkb_test_reset();
		$this->requests  = array();
		$this->responses = array();
		$this->sleeps    = array();
	}

	private function client( array $settings = array() ) {
		update_option( Settings::OPTION, array_merge( array( 'seller_code' => 'S123', 'rate_limit' => 1000 ), $settings ) );
		$tokens = new TokenStore();
		$tokens->save( 'ACCESS-1', 'REFRESH-1', time() + 3600 );
		$self = $this;
		return new Client(
			new Settings(),
			$tokens,
			new Logger( 'error' ),
			function ( $method, $url, $args ) use ( $self ) {
				$self->requests[] = compact( 'method', 'url', 'args' );
				$next             = array_shift( $self->responses );
				return $next ? $next : array( 'code' => 200, 'body' => '{"status":"ok","data":{}}', 'headers' => array() );
			},
			function ( $seconds ) use ( $self ) {
				$self->sleeps[] = $seconds;
			}
		);
	}

	private function respond( $code, $body, array $headers = array() ) {
		$this->responses[] = array(
			'code'    => $code,
			'body'    => is_string( $body ) ? $body : json_encode( $body ),
			'headers' => $headers,
		);
	}

	public function test_get_builds_url_and_headers() {
		$this->respond( 200, array( 'status' => 'ok', 'data' => array( 'items' => array( array( 'id' => 1 ) ) ) ) );
		$api   = new Digikala( $this->client() );
		$items = $api->search_products( 'گوشی سامسونگ', array( 'categories' => array( 11, 12 ), 'fake' => false ) );

		$this->assertSame( array( array( 'id' => 1 ) ), $items );
		$req = $this->requests[0];
		$this->assertSame( 'GET', $req['method'] );
		$this->assertStringStartsWith( 'https://seller.digikala.com/open-api/v1/product-creation/search/v2?', $req['url'] );
		$query = urldecode( parse_url( $req['url'], PHP_URL_QUERY ) );
		$this->assertStringContainsString( 'search[keyword]=گوشی سامسونگ', $query );
		$this->assertStringContainsString( 'search[categories]=11,12', $query );
		$this->assertStringContainsString( 'search[fake]=false', $query );
		$this->assertSame( 'Bearer ACCESS-1', $req['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $req['args']['headers']['Content-Type'] );
		$this->assertSame( 'DigikalaTrustedBot/SelfToken/S123', $req['args']['headers']['User-Agent'] );
	}

	public function test_ids_filter_is_underscore_joined() {
		$this->assertSame( 'search%5Bids%5D=1_2_3', Client::build_query( array( 'search' => array( 'ids' => array( 1, 2, 3 ) ) ) ) );
	}

	public function test_post_sends_json_body() {
		$this->respond( 200, array( 'status' => 'ok', 'data' => array( 'batch_id' => 7, 'status' => 'processing' ) ) );
		$api = new Digikala( $this->client() );
		$res = $api->batch_stock( array( array( 'variant_id' => 5, 'payload' => array( 'seller_stock' => 3 ) ) ) );
		$this->assertSame( 7, $res['batch_id'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 300, $body['deadline'] );
		$this->assertSame( 3, $body['items'][0]['payload']['seller_stock'] );
	}

	public function test_retries_after_429() {
		$this->respond( 429, array( 'status' => 'error', 'message' => 'limit' ), array( 'Retry-After' => '2' ) );
		$this->respond( 200, array( 'status' => 'ok', 'data' => array( 'ok' => 1 ) ) );
		$res = $this->client()->get( '/variants' );
		$this->assertSame( array( 'ok' => 1 ), $res );
		$this->assertSame( array( 2 ), $this->sleeps );
		$this->assertCount( 2, $this->requests );
	}

	public function test_long_429_is_transient_exception() {
		$this->respond( 429, array( 'status' => 'error', 'message' => 'limit' ), array( 'Retry-After' => '900' ) );
		try {
			$this->client()->get( '/variants' );
			$this->fail( 'expected exception' );
		} catch ( ApiException $e ) {
			$this->assertTrue( $e->is_transient() );
			$this->assertSame( 900, $e->retry_after() );
		}
	}

	public function test_refreshes_token_on_401() {
		$this->respond( 401, array( 'status' => 'error', 'message' => 'expired' ) );
		$this->respond(
			200,
			array(
				'status' => 'ok',
				'data'   => array(
					'access_token'            => 'ACCESS-2',
					'refresh_token'           => 'REFRESH-2',
					'access_token_expires_at' => array( 'date' => '2030-01-01 00:00:00.000000', 'timezone' => 'Asia/Tehran' ),
				),
			)
		);
		$this->respond( 200, array( 'status' => 'ok', 'data' => array( 'done' => true ) ) );

		$res = $this->client()->get( '/profile' );
		$this->assertSame( array( 'done' => true ), $res );
		$this->assertStringEndsWith( '/auth/refresh-token', $this->requests[1]['url'] );
		$refresh_body = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( array( 'access_token' => 'ACCESS-1', 'refresh_token' => 'REFRESH-1' ), $refresh_body );
		$this->assertSame( 'Bearer ACCESS-2', $this->requests[2]['args']['headers']['Authorization'] );
		$tokens = new TokenStore();
		$this->assertSame( 'REFRESH-2', $tokens->refresh_token() );
		$this->assertGreaterThan( time(), $tokens->expires_at() );
	}

	public function test_server_errors_back_off_then_throw() {
		for ( $i = 0; $i < 4; $i++ ) {
			$this->respond( 502, 'Bad gateway' );
		}
		try {
			$this->client()->get( '/variants' );
			$this->fail( 'expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 502, $e->http_status() );
			$this->assertTrue( $e->is_transient() );
		}
		$this->assertSame( array( 2, 4, 8 ), $this->sleeps );
	}

	public function test_network_errors_retry_once() {
		$this->responses[] = array( 'code' => 0, 'body' => '', 'headers' => array(), 'error' => 'cURL error 7' );
		$this->responses[] = array( 'code' => 0, 'body' => '', 'headers' => array(), 'error' => 'cURL error 7' );
		try {
			$this->client()->get( '/' );
			$this->fail( 'expected exception' );
		} catch ( ApiException $e ) {
			$this->assertSame( 0, $e->http_status() );
			$this->assertSame( 'cURL error 7', $e->getMessage() );
		}
		$this->assertCount( 2, $this->requests );
	}

	public function test_validation_error_is_permanent_with_details() {
		$this->respond( 400, array( 'status' => 'error', 'message' => 'Validation Failed', 'code' => 400, 'errors' => array( 'model' => array( 'مدل الزامی است' ) ) ) );
		try {
			$this->client()->post( '/product-creation/product/detail/validation', array( 'category_id' => 1 ) );
			$this->fail( 'expected exception' );
		} catch ( ApiException $e ) {
			$this->assertFalse( $e->is_transient() );
			$this->assertStringContainsString( 'model: مدل الزامی است', $e->full_message() );
		}
	}

	public function test_status_error_in_200_body_is_failure() {
		$this->respond( 200, array( 'status' => 'error', 'message' => 'bad' ) );
		$this->expectException( ApiException::class );
		$this->client()->get( '/variants' );
	}

	public function test_dry_run_blocks_mutations_but_allows_reads() {
		$client = $this->client( array( 'dry_run' => true ) );
		$this->respond( 200, array( 'status' => 'ok', 'data' => array( 'x' => 1 ) ) );
		$this->assertSame( array( 'x' => 1 ), $client->get( '/categories/tree' ) );
		try {
			$client->post( '/product-creation/save', array( 'draft_product_id' => 3 ) );
			$this->fail( 'expected dry run' );
		} catch ( DryRunException $e ) {
			$this->assertSame( '/product-creation/save', $e->path() );
			$this->assertSame( array( 'draft_product_id' => 3 ), $e->body() );
		}
		$this->assertCount( 1, $this->requests );
	}

	public function test_local_rate_limit() {
		$client = $this->client( array( 'rate_limit' => 2, 'rate_window' => 3600 ) );
		$client->get( '/a' );
		$client->get( '/b' );
		try {
			$client->get( '/c' );
			$this->fail( 'expected local throttle' );
		} catch ( ApiException $e ) {
			$this->assertSame( 429, $e->http_status() );
			$this->assertGreaterThan( 0, $e->retry_after() );
		}
		$this->assertCount( 2, $this->requests );
	}

	public function test_multipart_body() {
		$file = tempnam( sys_get_temp_dir(), 'dkb' ) . '.jpg';
		file_put_contents( $file, 'JPEGDATA' );
		list( $body, $type ) = Client::multipart( $file );
		unlink( $file );
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $type );
		$this->assertStringContainsString( 'name="file"', $body );
		$this->assertStringContainsString( 'JPEGDATA', $body );
	}

	public function test_upload_image_returns_inner_data() {
		$file = tempnam( sys_get_temp_dir(), 'dkb' );
		file_put_contents( $file, 'x' );
		$this->respond( 200, array( 'status' => 'ok', 'data' => array( 'isValid' => true, 'data' => array( 'id' => '4NObd', 'url' => 'u', 'tempFile' => true ) ) ) );
		$res = ( new Digikala( $this->client() ) )->upload_image( $file );
		unlink( $file );
		$this->assertSame( '4NObd', $res['id'] );
		$this->assertStringStartsWith( 'multipart/form-data', $this->requests[0]['args']['headers']['Content-Type'] );
	}

	public function test_token_store_encrypts_at_rest() {
		$store = new TokenStore();
		$store->save( 'SECRET-TOKEN-VALUE', 'R' );
		$raw = get_option( TokenStore::OPTION );
		$this->assertStringNotContainsString( 'SECRET-TOKEN-VALUE', json_encode( $raw ) );
		$this->assertSame( 'SECRET-TOKEN-VALUE', $store->access_token() );
	}

	public function test_seller_code_from_jwt_claims() {
		$jwt = 'h.' . rtrim( strtr( base64_encode( json_encode( array( 'iat' => 1, 'data' => array( 'seller_id' => 123456 ) ) ) ), '+/', '-_' ), '=' ) . '.s';
		$this->assertSame( '123456', TokenStore::seller_code_from_token( $jwt ) );
		$this->assertSame( '', TokenStore::seller_code_from_token( 'not-a-jwt' ) );
	}

	public function test_self_token_user_agent() {
		$this->respond( 200, array( 'status' => 'ok', 'data' => array() ) );
		$this->client( array( 'auth_mode' => 'self_token', 'seller_code' => '987' ) )->get( '/' );
		$this->assertSame( 'DigikalaTrustedBot/SelfToken/987', $this->requests[0]['args']['headers']['User-Agent'] );
	}

	public function test_parse_expiry_in_tehran_time() {
		$ts = TokenStore::parse_expiry( array( 'date' => '2025-02-07 00:00:00.000000', 'timezone' => 'Asia/Tehran' ) );
		$this->assertSame( gmmktime( 20, 30, 0, 2, 6, 2025 ), $ts );
	}
}
