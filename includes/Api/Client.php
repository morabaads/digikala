<?php
/**
 * Low level HTTP client for the Digikala Seller Open API.
 *
 * @package DKBridge
 */

namespace DKBridge\Api;

use DKBridge\Settings;
use DKBridge\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Handles auth headers, rate limiting, retries, token refresh, circuit
 * breaking, multipart uploads and dry-run mode.
 */
class Client {

	const PREFIX            = '/open-api/v1';
	const MAX_RETRIES       = 3;
	const BREAKER_THRESHOLD = 5;
	const BREAKER_COOLDOWN  = 600;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Tokens.
	 *
	 * @var TokenStore
	 */
	private $tokens;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Transport callable: function( string $method, string $url, array $args ): array{code:int, body:string, headers:array}.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Sleep callable (seconds).
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Last rate limit info returned by the API.
	 *
	 * @var array
	 */
	private $last_rate_limit = array();

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings  Settings.
	 * @param TokenStore    $tokens    Token store.
	 * @param Logger        $logger    Logger.
	 * @param callable|null $transport Optional transport (tests).
	 * @param callable|null $sleeper   Optional sleeper (tests).
	 */
	public function __construct( Settings $settings, TokenStore $tokens, Logger $logger, $transport = null, $sleeper = null ) {
		$this->settings  = $settings;
		$this->tokens    = $tokens;
		$this->logger    = $logger;
		$this->transport = $transport ? $transport : array( $this, 'wp_transport' );
		$this->sleeper   = $sleeper ? $sleeper : 'sleep';
	}

	/**
	 * GET helper.
	 *
	 * @param string $path  Path relative to /open-api/v1.
	 * @param array  $query Query params; `search` sub-array becomes search[key]=value.
	 * @return array Response `data`.
	 */
	public function get( $path, array $query = array() ) {
		return $this->request( 'GET', $path, array( 'query' => $query ) );
	}

	/**
	 * POST helper.
	 *
	 * @param string $path Path.
	 * @param array  $json Body.
	 * @return array
	 */
	public function post( $path, array $json = array() ) {
		return $this->request( 'POST', $path, array( 'json' => $json ) );
	}

	/**
	 * PUT helper.
	 *
	 * @param string $path Path.
	 * @param array  $json Body.
	 * @return array
	 */
	public function put( $path, array $json = array() ) {
		return $this->request( 'PUT', $path, array( 'json' => $json ) );
	}

	/**
	 * PATCH helper.
	 *
	 * @param string $path Path.
	 * @param array  $json Body.
	 * @return array
	 */
	public function patch( $path, array $json = array() ) {
		return $this->request( 'PATCH', $path, array( 'json' => $json ) );
	}

	/**
	 * Upload a file as multipart/form-data under field `file`.
	 *
	 * @param string $path      Path.
	 * @param string $file_path Local file.
	 * @return array
	 */
	public function upload( $path, $file_path ) {
		return $this->request( 'POST', $path, array( 'file' => $file_path ) );
	}

	/**
	 * Whether a request would mutate remote state.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path.
	 * @return bool
	 */
	public static function is_mutating( $method, $path ) {
		if ( 'GET' === $method ) {
			return false;
		}
		// Validation / inquiry / AI checks are read-only POSTs, but they create
		// drafts in some cases (detail/validation) so only list pure reads here.
		$read_only = array( '/batch/inquiry', '/product-creation/images/ai', '/auth/token', '/auth/refresh-token', '/packages/warehouses/', '/packages/order-fulfilment/variants', '/pricing/promotions/eligible/min-discounts' );
		foreach ( $read_only as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Perform a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Path.
	 * @param array  $opts   query|json|file|auth(bool).
	 * @return array Response data (the `data` key, or the full body if missing).
	 * @throws ApiException On failure.
	 */
	public function request( $method, $path, array $opts = array() ) {
		$method = strtoupper( $method );
		$path   = '/' . ltrim( $path, '/' );
		$auth   = ! isset( $opts['auth'] ) || $opts['auth'];

		if ( $this->settings->get( 'dry_run' ) && self::is_mutating( $method, $path ) ) {
			$this->logger->info( 'api', sprintf( 'DRY-RUN %s %s', $method, $path ), array( 'body' => isset( $opts['json'] ) ? $opts['json'] : ( isset( $opts['file'] ) ? basename( $opts['file'] ) : null ) ) );
			throw new DryRunException( $method, $path, isset( $opts['json'] ) ? $opts['json'] : array() );
		}

		$this->guard_breaker();

		$url = rtrim( $this->settings->get( 'base_url', 'https://seller.digikala.com' ), '/' ) . self::PREFIX . ( '/' === $path ? '/' : $path );
		if ( ! empty( $opts['query'] ) ) {
			$url .= '?' . self::build_query( $opts['query'] );
		}

		$refreshed = false;
		$attempt   = 0;
		while ( true ) {
			++$attempt;
			$this->throttle();

			$args = array(
				'method'  => $method,
				'timeout' => (int) $this->settings->get( 'timeout', 30 ),
				'headers' => $this->headers( $auth ),
			);
			if ( isset( $opts['file'] ) ) {
				list( $body, $content_type ) = self::multipart( $opts['file'] );
				$args['body']                    = $body;
				$args['headers']['Content-Type'] = $content_type;
			} elseif ( isset( $opts['json'] ) ) {
				$args['body'] = wp_json_encode( (object) $opts['json'], JSON_UNESCAPED_UNICODE );
			}

			$started  = microtime( true );
			$response = call_user_func( $this->transport, $method, $url, $args );
			$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );
			$code     = (int) $response['code'];
			$decoded  = json_decode( (string) $response['body'], true );

			$this->logger->debug(
				'api',
				sprintf( '%s %s → %d (%dms)', $method, $path, $code, $elapsed ),
				array(
					'request'  => isset( $opts['json'] ) ? $opts['json'] : null,
					'response' => is_array( $decoded ) ? $decoded : substr( (string) $response['body'], 0, 500 ),
				)
			);

			if ( is_array( $decoded ) && isset( $decoded['data']['rate_limit'] ) ) {
				$this->last_rate_limit = $decoded['data']['rate_limit'];
				update_option( 'dkb_last_rate_limit', $this->last_rate_limit, false );
			}

			if ( $code >= 200 && $code < 300 && ( ! is_array( $decoded ) || ! isset( $decoded['status'] ) || 'error' !== $decoded['status'] ) ) {
				$this->breaker_success();
				if ( ! is_array( $decoded ) ) {
					return array( 'raw' => (string) $response['body'] );
				}
				return array_key_exists( 'data', $decoded ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded;
			}

			$message = is_array( $decoded ) && ! empty( $decoded['message'] ) ? (string) $decoded['message'] : self::status_message( $code );
			$errors  = is_array( $decoded ) && isset( $decoded['errors'] ) ? (array) $decoded['errors'] : array();
			if ( 0 === $code && ! empty( $response['error'] ) ) {
				$message = (string) $response['error'];
			}

			// Expired token → refresh once.
			if ( 401 === $code && $auth && ! $refreshed && $this->can_refresh() ) {
				$refreshed = true;
				$this->refresh();
				continue;
			}

			// Rate limited.
			if ( 429 === $code ) {
				$wait = self::retry_after_seconds( $response, $decoded );
				if ( $wait <= 20 && $attempt <= self::MAX_RETRIES ) {
					call_user_func( $this->sleeper, max( 1, $wait ) );
					continue;
				}
				throw new ApiException( $message, 429, $errors, $wait );
			}

			// Transient errors → exponential backoff. Network failures (e.g. the
			// host is unreachable from this server) are retried only once so
			// admin screens do not hang.
			if ( ( ( 0 === $code && $attempt <= 1 ) || ( $code >= 500 && $attempt <= self::MAX_RETRIES ) ) ) {
				$this->breaker_failure();
				call_user_func( $this->sleeper, (int) pow( 2, $attempt ) );
				continue;
			}
			if ( 0 === $code || $code >= 500 ) {
				$this->breaker_failure();
			}

			$this->logger->warning( 'api', sprintf( '%s %s failed: %d %s', $method, $path, $code, $message ), array( 'errors' => $errors ) );
			throw new ApiException( $message, $code, $errors );
		}
	}

	/**
	 * Build the request headers.
	 *
	 * @param bool $auth Include Authorization.
	 * @return array
	 */
	private function headers( $auth ) {
		$headers = array(
			// Digikala's gateway requires Content-Type on GET requests as well.
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		$seller_code = trim( (string) $this->settings->get( 'seller_code' ) );
		if ( 'self_token' === $this->settings->get( 'auth_mode' ) && '' !== $seller_code ) {
			$headers['User-Agent'] = 'DigikalaTrustedBot/SelfToken/' . $seller_code;
		} else {
			$headers['User-Agent'] = 'DKBridge/' . ( defined( 'DKB_VERSION' ) ? DKB_VERSION : '1' ) . ' (WordPress)';
		}
		if ( $auth ) {
			if ( 'oauth' === $this->settings->get( 'auth_mode' ) && $this->tokens->expires_at() && $this->tokens->expires_at() < time() + 60 && $this->can_refresh() ) {
				$this->refresh();
			}
			$token = $this->tokens->access_token();
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
		}
		return $headers;
	}

	/**
	 * Whether a refresh is possible.
	 *
	 * @return bool
	 */
	private function can_refresh() {
		return '' !== $this->tokens->refresh_token() && '' !== $this->tokens->access_token();
	}

	/**
	 * Refresh the access token.
	 *
	 * @throws ApiException When refresh fails.
	 */
	public function refresh() {
		$lock = 'dkb_refreshing';
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 30 );
		try {
			$data = $this->request(
				'POST',
				'/auth/refresh-token',
				array(
					'auth' => false,
					'json' => array(
						'access_token'  => $this->tokens->access_token(),
						'refresh_token' => $this->tokens->refresh_token(),
					),
				)
			);
			$this->tokens->save_response( $data );
			$this->logger->info( 'auth', 'توکن دسترسی تمدید شد.' );
		} finally {
			delete_transient( $lock );
		}
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code Authorization code (already decrypted).
	 * @return array
	 */
	public function exchange_code( $code ) {
		/**
		 * Filter: decrypt the authorization_code returned to a registered app.
		 *
		 * @param string $code Raw code.
		 */
		$code = apply_filters( 'dkb_decrypt_authorization_code', $code );
		$data = $this->request(
			'POST',
			'/auth/token',
			array(
				'auth' => false,
				'json' => array( 'authorization_code' => $code ),
			)
		);
		$this->tokens->save_response( $data );
		return $data;
	}

	/**
	 * Client side token bucket so bulk jobs stay below the quota.
	 *
	 * @throws ApiException When the local budget is exhausted for longer than a few seconds.
	 */
	private function throttle() {
		$limit  = max( 1, (int) $this->settings->get( 'rate_limit', 300 ) );
		$window = max( 1, (int) $this->settings->get( 'rate_window', 60 ) );
		$bucket = (int) floor( time() / $window );
		$key    = 'dkb_rl_' . $bucket;
		$used   = (int) get_transient( $key );
		if ( $used >= $limit ) {
			$wait = ( $bucket + 1 ) * $window - time();
			if ( $wait <= 5 ) {
				call_user_func( $this->sleeper, max( 1, $wait ) );
				$this->throttle();
				return;
			}
			throw new ApiException( __( 'سهمیهٔ درخواست محلی پر شده است؛ کار بعداً ادامه می‌یابد.', 'dk-bridge' ), 429, array(), $wait );
		}
		set_transient( $key, $used + 1, $window + 5 );
	}

	/**
	 * Throw if the breaker is open.
	 *
	 * @throws ApiException When open.
	 */
	private function guard_breaker() {
		$until = (int) get_transient( 'dkb_breaker_open' );
		if ( $until > time() ) {
			throw new ApiException( __( 'به‌دلیل خطاهای پیاپی سرور دیجی‌کالا، ارسال درخواست‌ها موقتاً متوقف شده است.', 'dk-bridge' ), 503, array(), $until - time() );
		}
	}

	/**
	 * Record a server failure.
	 */
	private function breaker_failure() {
		$count = (int) get_transient( 'dkb_breaker_count' ) + 1;
		set_transient( 'dkb_breaker_count', $count, self::BREAKER_COOLDOWN );
		if ( $count >= self::BREAKER_THRESHOLD ) {
			set_transient( 'dkb_breaker_open', time() + self::BREAKER_COOLDOWN, self::BREAKER_COOLDOWN );
			delete_transient( 'dkb_breaker_count' );
			$this->logger->error( 'api', 'Circuit breaker opened after repeated server errors.' );
		}
	}

	/**
	 * Reset failure counter.
	 */
	private function breaker_success() {
		if ( get_transient( 'dkb_breaker_count' ) ) {
			delete_transient( 'dkb_breaker_count' );
		}
	}

	/**
	 * Last rate limit info.
	 *
	 * @return array
	 */
	public function rate_limit() {
		return $this->last_rate_limit ? $this->last_rate_limit : (array) get_option( 'dkb_last_rate_limit', array() );
	}

	/**
	 * Build a query string. Lists inside `search` are comma joined, except `ids` (underscore).
	 *
	 * @param array $query Query.
	 * @return string
	 */
	public static function build_query( array $query ) {
		if ( isset( $query['search'] ) && is_array( $query['search'] ) ) {
			foreach ( $query['search'] as $key => $value ) {
				if ( is_array( $value ) ) {
					$query['search'][ $key ] = implode( 'ids' === $key ? '_' : ',', $value );
				} elseif ( is_bool( $value ) ) {
					$query['search'][ $key ] = $value ? 'true' : 'false';
				}
			}
		}
		return http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Build a multipart body for a single `file` field.
	 *
	 * @param string $file_path Path.
	 * @return array{0:string,1:string}
	 * @throws ApiException When the file is unreadable.
	 */
	public static function multipart( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			throw new ApiException( 'File not readable: ' . basename( $file_path ) );
		}
		$boundary = 'dkb' . bin2hex( random_bytes( 12 ) );
		$mime     = function_exists( 'mime_content_type' ) ? mime_content_type( $file_path ) : 'image/jpeg';
		$name     = preg_replace( '/[^A-Za-z0-9._-]/', '_', basename( $file_path ) );
		$body     = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="file"; filename="' . $name . '"' . "\r\n"
			. 'Content-Type: ' . $mime . "\r\n\r\n"
			. file_get_contents( $file_path ) . "\r\n" // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			. '--' . $boundary . "--\r\n";
		return array( $body, 'multipart/form-data; boundary=' . $boundary );
	}

	/**
	 * Determine retry delay for a 429.
	 *
	 * @param array      $response Raw response.
	 * @param array|null $decoded  Decoded body.
	 * @return int
	 */
	public static function retry_after_seconds( array $response, $decoded ) {
		$headers = isset( $response['headers'] ) ? array_change_key_case( (array) $response['headers'], CASE_LOWER ) : array();
		if ( isset( $headers['retry-after'] ) && is_numeric( $headers['retry-after'] ) ) {
			return (int) $headers['retry-after'];
		}
		$reset = null;
		if ( isset( $headers['reset_time'] ) ) {
			$reset = $headers['reset_time'];
		} elseif ( isset( $headers['x-ratelimit-reset'] ) ) {
			$reset = $headers['x-ratelimit-reset'];
		} elseif ( is_array( $decoded ) && isset( $decoded['data']['rate_limit']['reset_time'] ) ) {
			$reset = $decoded['data']['rate_limit']['reset_time'];
		} elseif ( is_array( $decoded ) && isset( $decoded['reset_time'] ) ) {
			$reset = $decoded['reset_time'];
		}
		if ( null !== $reset ) {
			if ( is_numeric( $reset ) ) {
				$reset = (int) $reset;
				return $reset > 1000000000 ? max( 1, $reset - time() ) : max( 1, $reset );
			}
			$ts = TokenStore::parse_expiry( (string) $reset );
			if ( $ts ) {
				return max( 1, $ts - time() );
			}
		}
		return 30;
	}

	/**
	 * Default message for a status code.
	 *
	 * @param int $code Status.
	 * @return string
	 */
	public static function status_message( $code ) {
		$map = array(
			0   => __( 'اتصال به سرور دیجی‌کالا برقرار نشد (شبکه یا محدودیت IP).', 'dk-bridge' ),
			400 => __( 'خطای اعتبارسنجی؛ ورودی‌ها را بررسی کنید.', 'dk-bridge' ),
			401 => __( 'دسترسی رد شد؛ توکن نامعتبر یا منقضی است.', 'dk-bridge' ),
			403 => __( 'این توکن مجوز (scope) لازم برای این عملیات را ندارد.', 'dk-bridge' ),
			404 => __( 'داده‌ای برای این درخواست یافت نشد.', 'dk-bridge' ),
			429 => __( 'محدودیت تعداد درخواست؛ بعداً تلاش کنید.', 'dk-bridge' ),
			500 => __( 'خطای سرور دیجی‌کالا.', 'dk-bridge' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : sprintf( 'HTTP %d', $code );
	}

	/**
	 * Default transport using the WordPress HTTP API.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array
	 */
	public function wp_transport( $method, $url, array $args ) {
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => 0,
				'body'    => '',
				'headers' => array(),
				'error'   => $response->get_error_message(),
			);
		}
		$headers = wp_remote_retrieve_headers( $response );
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers,
		);
	}
}
