<?php
/**
 * Exercises the product creation pipeline against a fake Digikala API.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Api\ApiException;
use DKBridge\Api\Client;
use DKBridge\Api\Digikala;
use DKBridge\Api\TokenStore;
use DKBridge\Data\ItemRepository;
use DKBridge\Mapping\AttributeMapper;
use DKBridge\Mapping\CategoryMapper;
use DKBridge\Mapping\ProductData;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Settings;
use DKBridge\Support\Logger;
use DKBridge\Sync\Publisher;
use PHPUnit\Framework\TestCase;

/**
 * In-memory replacement for the wpdb-backed repository.
 */
class MemoryItems extends ItemRepository {
	public $rows = array();
	private $next = 1;

	public function get( $product_id, $variation_id = 0 ) {
		foreach ( $this->rows as $row ) {
			if ( $row['wc_product_id'] === (int) $product_id && $row['wc_variation_id'] === (int) $variation_id ) {
				return $row;
			}
		}
		return null;
	}

	public function upsert( $product_id, $variation_id, array $fields ) {
		$row = $this->get( $product_id, $variation_id );
		if ( ! $row ) {
			$row = array(
				'id'                => $this->next++,
				'wc_product_id'     => (int) $product_id,
				'wc_variation_id'   => (int) $variation_id,
				'dk_product_id'     => null,
				'dk_draft_id'       => null,
				'dk_variant_id'     => null,
				'state'             => 'local',
				'moderation_status' => null,
				'score'             => null,
				'issues'            => array(),
				'context'           => array(),
				'last_error'        => null,
				'attempts'          => 0,
			);
			$this->rows[ $row['id'] ] = $row;
		}
		$this->update( $row['id'], $fields );
		return $this->get( $product_id, $variation_id );
	}

	public function update( $id, array $fields ) {
		$this->rows[ $id ] = array_merge( $this->rows[ $id ], $fields );
	}

	public function merge_context( array $item, array $values ) {
		$ctx = array_merge( (array) $this->rows[ $item['id'] ]['context'], $values );
		$this->update( $item['id'], array( 'context' => $ctx ) );
		return $ctx;
	}
}

class PipelineTest extends TestCase {

	/** @var array */
	public $requests = array();
	/** @var array path-prefix => list of responses */
	public $routes = array();
	/** @var string */
	private $image;

	protected function setUp(): void {
		dkb_test_reset();
		$this->requests = array();
		$this->routes   = array();
		update_option( Settings::OPTION, array( 'rate_limit' => 1000, 'use_ai_check' => true ) );
		( new TokenStore() )->save( 'T', 'R', time() + 3600 );

		$this->image = sys_get_temp_dir() . '/dkb-pipeline-' . uniqid() . '.png';
		$img         = imagecreatetruecolor( 800, 800 );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
		imagefilledrectangle( $img, 200, 200, 600, 600, imagecolorallocate( $img, 10, 10, 200 ) );
		imagepng( $img, $this->image );
		imagedestroy( $img );
	}

	protected function tearDown(): void {
		if ( file_exists( $this->image ) ) {
			unlink( $this->image );
		}
	}

	private function route( $prefix, array $data, $code = 200 ) {
		$this->routes[ $prefix ][] = array(
			'code' => $code,
			'body' => json_encode( array( 'status' => $code < 300 ? 'ok' : 'error', 'data' => $data, 'message' => $code < 300 ? '' : 'Validation Failed', 'errors' => $code < 300 ? array() : $data ) ),
		);
	}

	private function publisher( MemoryItems $items ) {
		$settings = new Settings();
		$self     = $this;
		$client   = new Client(
			$settings,
			new TokenStore(),
			new Logger( 'error' ),
			function ( $method, $url, $args ) use ( $self ) {
				$path             = substr( parse_url( $url, PHP_URL_PATH ), strlen( '/open-api/v1' ) );
				$self->requests[] = $method . ' ' . $path;
				foreach ( $self->routes as $prefix => $list ) {
					if ( 0 === strpos( $method . ' ' . $path, $prefix ) && $list ) {
						$res = array_shift( $self->routes[ $prefix ] );
						return $res + array( 'headers' => array() );
					}
				}
				return array( 'code' => 200, 'body' => '{"status":"ok","data":{}}', 'headers' => array() );
			},
			function () {}
		);
		$api    = new Digikala( $client );
		$mapper = new ProductMapper( $settings, $api, new CategoryMapper(), new AttributeMapper() );
		return new Publisher( $settings, $api, $mapper, $items, new Logger( 'error' ) );
	}

	private function data() {
		$p              = new ProductData();
		$p->wc_id       = 7;
		$p->title_fa    = 'گوشی موبایل سامسونگ مدل A54';
		$p->title_en    = 'Samsung Model A54';
		$p->category_id = 11;
		$p->brand_id    = 18;
		$p->model       = 'A54';
		$p->description = 'توضیحات کامل کالا';
		$p->advantages  = array( 'باتری قوی' );
		$p->package     = array( 'length' => 20, 'width' => 10, 'height' => 5, 'weight' => 400 );
		$p->images      = array(
			array( 'attachment_id' => 3, 'path' => $this->image, 'url' => '', 'is_main' => true ),
		);
		return $p;
	}

	private function create( Publisher $publisher, ProductData $data, array $item ) {
		$m = new \ReflectionMethod( Publisher::class, 'create_product' );
		$m->setAccessible( true );
		$product = new class() {
			public function get_id() {
				return 7;
			}
		};
		$m->invoke( $publisher, $product, $data, $item );
	}

	public function test_full_creation_pipeline() {
		$this->route( 'POST /product-creation/product/detail/validation', array( 'is_valid' => true, 'draft_product_id' => 55 ) );
		$this->route( 'GET /product-creation/55/auto-title', array( 'title_fa' => 'گوشی موبایل سامسونگ مدل A54', 'enable_edit' => true, 'hint' => array( 'hint_fa' => 'ماهیت کالا + برند کالا + مدل کالا' ) ) );
		$this->route( 'POST /product-creation/auto-title/save', array( 'isValid' => true, 'data' => array( 'title_fa' => 'گوشی موبایل سامسونگ مدل A54' ) ) );
		$this->route( 'POST /product-creation/images/upload', array( 'isValid' => true, 'data' => array( 'id' => 'IMG1' ) ) );
		$this->route( 'POST /product-creation/images/ai', array( 'isValid' => true, 'data' => array( 'بک گراند عکس بهتر است سفید باشد' ) ) );
		$this->route( 'POST /product-creation/save', array( 'data' => array( 'product_id' => 999 ) ) );

		$items = new MemoryItems();
		$item  = $items->upsert( 7, 0, array( 'state' => 'processing' ) );
		$this->create( $this->publisher( $items ), $this->data(), $item );

		$this->assertSame(
			array(
				'POST /product-creation/product/detail/validation',
				'POST /product-creation/attributes',
				'GET /product-creation/55/auto-title',
				'POST /product-creation/auto-title/save',
				'POST /product-creation/images/upload',
				'POST /product-creation/images/ai',
				'POST /product-creation/save',
			),
			$this->requests
		);
		$row = $items->get( 7 );
		$this->assertSame( 'in_review', $row['state'] );
		$this->assertSame( 999, $row['dk_product_id'] );
		$this->assertSame( 55, $row['dk_draft_id'] );
		$this->assertSame( array( 3 => array( 'بک گراند عکس بهتر است سفید باشد' ) ), $row['context']['ai_hints'] );
		$this->assertSame( 'ماهیت کالا + برند کالا + مدل کالا', $row['context']['title_hint'] );
	}

	public function test_resume_skips_completed_steps_and_uploaded_images() {
		$items = new MemoryItems();
		$item  = $items->upsert( 7, 0, array( 'state' => 'processing' ) );

		// First run fails at the final save step.
		$this->route( 'POST /product-creation/product/detail/validation', array( 'is_valid' => true, 'draft_product_id' => 55 ) );
		$this->route( 'POST /product-creation/auto-title/save', array( 'isValid' => true ) );
		$this->route( 'POST /product-creation/images/upload', array( 'data' => array( 'id' => 'IMG1' ) ) );
		$this->route( 'POST /product-creation/save', array( 'category_id' => array( 'invalid' ) ), 400 );
		try {
			$this->create( $this->publisher( $items ), $this->data(), $item );
			$this->fail( 'expected failure' );
		} catch ( ApiException $e ) {
			$this->assertSame( 400, $e->http_status() );
		}

		// Second run: details/attributes/images are not sent again.
		$this->requests = array();
		$this->route( 'POST /product-creation/auto-title/save', array( 'isValid' => true ) );
		$this->route( 'POST /product-creation/save', array( 'data' => array( 'product_id' => 1000 ) ) );
		$this->create( $this->publisher( $items ), $this->data(), $items->get( 7 ) );
		$this->assertNotContains( 'POST /product-creation/product/detail/validation', $this->requests );
		$this->assertNotContains( 'POST /product-creation/attributes', $this->requests );
		$this->assertNotContains( 'POST /product-creation/images/upload', $this->requests );
		$this->assertSame( 1000, $items->get( 7 )['dk_product_id'] );
	}

	public function test_rejected_details_raise_validation_error() {
		$this->route( 'POST /product-creation/product/detail/validation', array( 'is_valid' => false, 'errors' => array( 'برای فروش در این گروه کالایی نیاز به مدرک است' ) ) );
		$items = new MemoryItems();
		$item  = $items->upsert( 7, 0, array() );
		try {
			$this->create( $this->publisher( $items ), $this->data(), $item );
			$this->fail( 'expected failure' );
		} catch ( ApiException $e ) {
			$this->assertFalse( $e->is_transient() );
			$this->assertStringContainsString( 'نیاز به مدرک', $e->full_message() );
		}
	}

	public function test_title_retry_with_digikala_suggestion() {
		$this->route( 'POST /product-creation/product/detail/validation', array( 'is_valid' => true, 'draft_product_id' => 55 ) );
		$this->route( 'GET /product-creation/55/auto-title', array( 'title_fa' => 'گوشی موبایل سامسونگ مدل A54 Galaxy', 'enable_edit' => true ) );
		$this->route( 'POST /product-creation/auto-title/save', array( 'isValid' => false ) );
		$this->route( 'POST /product-creation/auto-title/save', array( 'isValid' => true ) );
		$this->route( 'POST /product-creation/images/upload', array( 'data' => array( 'id' => 'IMG1' ) ) );
		$this->route( 'POST /product-creation/save', array( 'data' => array( 'product_id' => 5 ) ) );
		$items = new MemoryItems();
		$this->create( $this->publisher( $items ), $this->data(), $items->upsert( 7, 0, array() ) );
		$this->assertSame( 2, count( array_keys( $this->requests, 'POST /product-creation/auto-title/save', true ) ) );
		$this->assertSame( 5, $items->get( 7 )['dk_product_id'] );
	}
}
