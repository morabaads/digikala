<?php
/**
 * The resumable product publishing pipeline.
 *
 * @package DKBridge
 */

namespace DKBridge\Sync;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Api\DryRunException;
use DKBridge\Data\ItemRepository;
use DKBridge\Images\ImageProcessor;
use DKBridge\Mapping\PayloadBuilder;
use DKBridge\Mapping\ProductData;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Mapping\VariantMapper;
use DKBridge\Matching\Similarity;
use DKBridge\Rules\Issue;
use DKBridge\Rules\RuleEngine;
use DKBridge\Settings;
use DKBridge\Support\Logger;
use DKBridge\Text\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Publisher.
 *
 * local → (preflight) → match → details → attributes → title → images → save
 *       → in_review → approved → variants → live
 */
class Publisher {

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var ProductMapper */
	private $mapper;
	/** @var ItemRepository */
	private $items;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param Digikala       $api      API.
	 * @param ProductMapper  $mapper   Mapper.
	 * @param ItemRepository $items    Items.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct( Settings $settings, Digikala $api, ProductMapper $mapper, ItemRepository $items, Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->mapper   = $mapper;
		$this->items    = $items;
		$this->logger   = $logger;
	}

	/**
	 * Build data and run the rule engine.
	 *
	 * @param \WC_Product $product  Product.
	 * @param bool        $autofix  Apply fixes in memory.
	 * @param bool        $with_api Load Digikala category data.
	 * @param bool        $store    Store score & issues.
	 * @return array{data:ProductData,result:array}
	 */
	public function evaluate( $product, $autofix = false, $with_api = true, $store = true ) {
		$data   = $this->mapper->build( $product, $with_api );
		$engine = RuleEngine::default_engine( $this->mapper->rule_config( $data ) );
		$result = $engine->run( $data, $autofix );
		if ( $store ) {
			$item   = $this->items->get( $product->get_id() );
			$fields = array(
				'score'  => $result['score']['percent'],
				'issues' => array_map(
					static function ( Issue $i ) {
						return $i->to_array();
					},
					$result['issues']
				),
			);
			if ( ! $item || in_array( $item['state'], array( 'local', 'ready', 'blocked' ), true ) ) {
				$fields['state'] = $this->is_blocked( $result ) ? 'blocked' : 'ready';
			}
			$this->items->upsert( $product->get_id(), 0, $fields );
		}
		return array(
			'data'   => $data,
			'result' => $result,
		);
	}

	/**
	 * Whether a rule result blocks publishing.
	 *
	 * @param array $result Engine result.
	 * @return bool
	 */
	public function is_blocked( array $result ) {
		return $result['blocking'] || $result['score']['percent'] < (int) $this->settings->get( 'min_score', 60 );
	}

	/**
	 * Put a product into the publish queue.
	 *
	 * @param int $product_id Product.
	 * @param int $delay      Delay.
	 */
	public function enqueue( $product_id, $delay = 0 ) {
		$item = $this->items->get( $product_id );
		if ( ! $item || ! in_array( $item['state'], array( 'in_review', 'live' ), true ) ) {
			$this->items->upsert( $product_id, 0, array( 'state' => 'queued' ) );
		}
		Queue::push( 'dkb_publish_item', array( (int) $product_id ), $delay );
	}

	/**
	 * Job entry point.
	 *
	 * @param int $product_id Product.
	 * @return array|null Final item row.
	 */
	public function run( $product_id ) {
		$product_id = (int) $product_id;
		if ( ! Queue::lock( 'item_' . $product_id, 600 ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		try {
			if ( ! $product || 'yes' === $product->get_meta( '_dkb_exclude' ) ) {
				return null;
			}
			$item = $this->items->get( $product_id );
			if ( ! $item ) {
				$item = $this->items->upsert( $product_id, 0, array( 'state' => 'queued' ) );
			}
			/**
			 * Fires before a product is processed.
			 *
			 * @param \WC_Product $product Product.
			 * @param array       $item    Item.
			 */
			do_action( 'dkb_before_push', $product, $item );

			if ( $item['dk_product_id'] ) {
				$approved = in_array( (string) $item['moderation_status'], StatusPoller::APPROVED, true ) || in_array( $item['state'], array( 'approved', 'live' ), true );
				if ( 'rejected' === $item['state'] || in_array( (string) $item['moderation_status'], StatusPoller::REJECTED, true ) ) {
					$this->resubmit( $product, $item );
				} elseif ( $approved ) {
					$this->create_variants( $product, $item );
				} else {
					// Still in review — the poller (or webhook) moves it forward.
					$this->items->update( $item['id'], array( 'state' => 'in_review' ) );
				}
				return $this->items->get( $product_id );
			}

			$this->items->update( $item['id'], array( 'state' => 'processing' ) );
			$eval = $this->evaluate( $product, (bool) $this->settings->get( 'auto_fix_on_push', true ), true, false );
			$data = $eval['data'];
			$this->items->update(
				$item['id'],
				array(
					'score'  => $eval['result']['score']['percent'],
					'issues' => array_map(
						static function ( Issue $i ) {
							return $i->to_array();
						},
						$eval['result']['issues']
					),
				)
			);
			if ( $this->is_blocked( $eval['result'] ) ) {
				$this->items->update(
					$item['id'],
					array(
						'state'      => 'blocked',
						'last_error' => __( 'محصول ایراد مانع ارسال دارد یا امتیاز آمادگی آن کمتر از حداقل است.', 'dk-bridge' ),
					)
				);
				return $this->items->get( $product_id );
			}

			$item    = $this->items->get( $product_id );
			$context = $item['context'];

			// Smart match: avoid creating duplicates.
			$decision = isset( $context['match_decision'] ) ? $context['match_decision'] : null;
			if ( null === $decision && $this->settings->get( 'smart_match' ) ) {
				$candidates = $this->find_matches( $data );
				$best       = $candidates ? $candidates[0] : null;
				if ( $best && $best['score'] >= (float) $this->settings->get( 'match_threshold', 0.85 ) && $best['can_sell'] ) {
					if ( $this->settings->get( 'auto_use_matches' ) ) {
						$decision = (int) $best['id'];
					} else {
						$this->items->update(
							$item['id'],
							array(
								'state'   => 'match_review',
								'context' => array_merge( $context, array( 'match_candidates' => $candidates ) ),
							)
						);
						return $this->items->get( $product_id );
					}
				} else {
					$decision = 'new';
				}
				$context = $this->items->merge_context( $item, array( 'match_decision' => $decision ) );
				$item    = $this->items->get( $product_id );
			}

			if ( is_numeric( $decision ) && (int) $decision > 0 ) {
				$this->adopt( $product, $item, (int) $decision );
			} else {
				$this->create_product( $product, $data, $item );
			}
		} catch ( DryRunException $e ) {
			$item = $this->items->get( $product_id );
			if ( $item ) {
				$this->items->update(
					$item['id'],
					array(
						'state'      => 'dry_run',
						'last_error' => null,
						'context'    => array_merge(
							$item['context'],
							array(
								'dry_run' => array(
									'path' => $e->path(),
									'body' => $e->body(),
								),
							)
						),
					)
				);
			}
		} catch ( ApiException $e ) {
			$this->fail( $product_id, $e );
		} catch ( \Throwable $e ) {
			$this->fail( $product_id, new ApiException( $e->getMessage(), 0 ) );
			$this->logger->error( 'publish', $e->getMessage(), array( 'trace' => $e->getTraceAsString() ), $product_id );
		} finally {
			Queue::unlock( 'item_' . $product_id );
		}
		return $this->items->get( $product_id );
	}

	/**
	 * Record a failure and schedule a retry for transient errors.
	 *
	 * @param int          $product_id Product.
	 * @param ApiException $e          Error.
	 */
	private function fail( $product_id, ApiException $e ) {
		$item = $this->items->get( $product_id );
		if ( ! $item ) {
			return;
		}
		$attempts = (int) $item['attempts'] + 1;
		$message  = $e->full_message();
		if ( $e->is_transient() && $attempts <= 6 ) {
			$delay = (int) min( 6 * HOUR_IN_SECONDS, max( $e->retry_after(), 60 * pow( 2, $attempts ) ) );
			$this->items->update(
				$item['id'],
				array(
					'state'      => $item['dk_product_id'] ? $item['state'] : 'queued',
					'attempts'   => $attempts,
					'last_error' => $message,
				)
			);
			Queue::push( $item['dk_product_id'] ? 'dkb_create_variants' : 'dkb_publish_item', array( (int) $product_id ), $delay );
			$this->logger->warning( 'publish', sprintf( 'خطای موقت؛ تلاش دوباره تا %d ثانیهٔ دیگر: %s', $delay, $message ), array(), $product_id );
			return;
		}
		$this->items->update(
			$item['id'],
			array(
				'state'      => 'error',
				'attempts'   => $attempts,
				'last_error' => $message,
			)
		);
		$this->logger->error( 'publish', $message, array( 'errors' => $e->errors() ), $product_id );
	}

	/**
	 * Search Digikala for the same product.
	 *
	 * @param ProductData $p Data.
	 * @return array[] [{id,title,score,can_sell,image,commission,price_type,min_price}]
	 */
	public function find_matches( ProductData $p ) {
		$keywords = array();
		if ( '' !== $p->model && ! $p->brand_misc && ( '' !== $p->brand_fa || '' !== $p->brand_en ) ) {
			$keywords[] = trim( ( '' !== $p->brand_en ? $p->brand_en : $p->brand_fa ) . ' ' . $p->model );
		}
		$keywords[] = $p->title_fa;
		$found      = array();
		foreach ( array_unique( array_filter( $keywords ) ) as $keyword ) {
			$filters = $p->category_id ? array( 'categories' => array( (int) $p->category_id ) ) : array();
			foreach ( $this->api->search_products( $keyword, $filters ) as $row ) {
				if ( empty( $row['id'] ) || isset( $found[ $row['id'] ] ) ) {
					continue;
				}
				$title = isset( $row['title'] ) ? (string) $row['title'] : '';
				$score = Similarity::score( $p->title_fa, $title );
				if ( '' !== $p->model && false !== mb_strpos( Normalizer::fold( $title ), Normalizer::fold( $p->model ) ) ) {
					$score = min( 1.0, $score + 0.15 );
				} elseif ( '' !== $p->model ) {
					$score *= 0.7;
				}
				if ( $score < 0.5 ) {
					continue;
				}
				$found[ $row['id'] ] = array(
					'id'         => (int) $row['id'],
					'title'      => $title,
					'score'      => round( $score, 3 ),
					'can_sell'   => ! isset( $row['commission']['canSell'] ) || (bool) $row['commission']['canSell'],
					'commission' => isset( $row['commission']['commission'] ) ? (float) $row['commission']['commission'] : null,
					'image'      => isset( $row['image_src'] ) ? (string) $row['image_src'] : '',
					'price_type' => isset( $row['price_type'] ) && is_string( $row['price_type'] ) ? $row['price_type'] : '',
					'min_price'  => isset( $row['min_price'] ) ? (int) $row['min_price'] : null,
					'sellers'    => isset( $row['number_of_sellers'] ) ? (int) $row['number_of_sellers'] : null,
				);
			}
			if ( count( $found ) >= 5 ) {
				break;
			}
		}
		$found = array_values( $found );
		usort(
			$found,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $found, 0, 5 );
	}

	/**
	 * User decision for a match review.
	 *
	 * @param int        $product_id Product.
	 * @param int|string $decision   DK product id or 'new'.
	 */
	public function decide_match( $product_id, $decision ) {
		$item = $this->items->get( $product_id );
		if ( ! $item ) {
			$item = $this->items->upsert( $product_id, 0, array() );
		}
		$this->items->merge_context( $item, array( 'match_decision' => 'new' === $decision ? 'new' : (int) $decision ) );
		$this->enqueue( $product_id );
	}

	/**
	 * Sell on an existing Digikala product.
	 *
	 * @param \WC_Product $product       Product.
	 * @param array       $item          Item.
	 * @param int         $dk_product_id DKP.
	 */
	private function adopt( $product, array $item, $dk_product_id ) {
		$check = $this->api->be_seller( $dk_product_id );
		if ( isset( $check['commission']['canSell'] ) && ! $check['commission']['canSell'] ) {
			throw new ApiException( __( 'دیجی‌کالا اجازهٔ فروش این کالا را به شما نمی‌دهد (محدودیت مدرک یا گروه).', 'dk-bridge' ), 403 );
		}
		try {
			$this->api->assign( $dk_product_id );
		} catch ( ApiException $e ) {
			// Already assigned products return a validation error; that is fine.
			if ( 400 !== $e->http_status() ) {
				throw $e;
			}
		}
		$this->items->update(
			$item['id'],
			array(
				'dk_product_id'     => $dk_product_id,
				'state'             => 'approved',
				'moderation_status' => 'approved',
				'last_error'        => null,
				'attempts'          => 0,
			)
		);
		$this->logger->info( 'publish', sprintf( 'کالا به کالای موجود DKP-%d متصل شد.', $dk_product_id ), array(), $product->get_id() );
		$this->create_variants( $product, $this->items->get( $product->get_id() ) );
	}

	/**
	 * Create a new product on Digikala (resumable).
	 *
	 * @param \WC_Product $product Product.
	 * @param ProductData $data    Data (already auto-fixed).
	 * @param array       $item    Item.
	 * @throws ApiException On API errors.
	 */
	private function create_product( $product, ProductData $data, array $item ) {
		$pid     = $product->get_id();
		$context = $item['context'];
		$draft   = (int) $item['dk_draft_id'];

		// 1) Basic details → draft.
		$details = PayloadBuilder::details( $data, $draft ?: null );
		$details = apply_filters( 'dkb_product_payload', $details, 'details', $data, $product );
		// Hash the content only (the draft id itself must not force a resend).
		$hashed = $details;
		unset( $hashed['draft_product_id'] );
		$hash = sha1( wp_json_encode( $hashed ) );
		if ( ! $draft || ( isset( $context['details_hash'] ) ? $context['details_hash'] : '' ) !== $hash ) {
			$res = $this->api->validate_details( $details );
			if ( isset( $res['is_valid'] ) && ! $res['is_valid'] ) {
				throw new ApiException( __( 'اطلاعات پایهٔ کالا توسط دیجی‌کالا تأیید نشد.', 'dk-bridge' ), 400, isset( $res['errors'] ) ? (array) $res['errors'] : array() );
			}
			$new_draft = isset( $res['draft_product_id'] ) ? (int) $res['draft_product_id'] : ( isset( $res['bind']['draft_product_id'] ) ? (int) $res['bind']['draft_product_id'] : 0 );
			if ( ! $new_draft ) {
				throw new ApiException( __( 'شناسهٔ پیش‌نویس از دیجی‌کالا دریافت نشد.', 'dk-bridge' ), 500 );
			}
			$draft   = $new_draft;
			$context = array_merge( $context, array( 'details_hash' => $hash ) );
			$this->items->update(
				$item['id'],
				array(
					'dk_draft_id' => $draft,
					'context'     => $context,
				)
			);
			$this->logger->info( 'publish', sprintf( 'پیش‌نویس %d ساخته شد.', $draft ), array(), $pid );
		}

		// 2) Attributes & item dimensions.
		$attrs = PayloadBuilder::attributes( $data, $draft );
		$attrs = apply_filters( 'dkb_product_payload', $attrs, 'attributes', $data, $product );
		$hash  = sha1( wp_json_encode( $attrs ) );
		if ( ( isset( $context['attributes_hash'] ) ? $context['attributes_hash'] : '' ) !== $hash ) {
			$res = $this->api->save_attributes( $attrs );
			if ( ( isset( $res['isValid'] ) && ! $res['isValid'] ) || ( isset( $res['is_valid'] ) && ! $res['is_valid'] ) ) {
				throw new ApiException( __( 'ویژگی‌های کالا تأیید نشد.', 'dk-bridge' ), 400, isset( $res['errors'] ) ? (array) $res['errors'] : array() );
			}
			$context = $this->items->merge_context( $item, array_merge( $context, array( 'attributes_hash' => $hash ) ) );
		}

		// 3) Title: honour Digikala's formula when editing is locked.
		$suggest = array();
		try {
			$suggest = $this->api->auto_title( $draft );
		} catch ( ApiException $e ) {
			$this->logger->debug( 'publish', 'auto-title unavailable: ' . $e->getMessage(), array(), $pid );
		}
		if ( ! empty( $suggest['title_fa'] ) && ( isset( $suggest['enable_edit'] ) && ! $suggest['enable_edit'] ) ) {
			$data->title_fa = (string) $suggest['title_fa'];
		}
		$title_body = apply_filters( 'dkb_product_payload', PayloadBuilder::title( $data, $draft ), 'title', $data, $product );
		$res        = $this->api->save_title( $title_body );
		if ( isset( $res['isValid'] ) && ! $res['isValid'] ) {
			if ( ! empty( $suggest['title_fa'] ) && $suggest['title_fa'] !== $title_body['title_fa'] ) {
				$title_body['title_fa'] = (string) $suggest['title_fa'];
				$res                    = $this->api->save_title( $title_body );
			}
			if ( isset( $res['isValid'] ) && ! $res['isValid'] ) {
				throw new ApiException( __( 'عنوان کالا توسط دیجی‌کالا پذیرفته نشد.', 'dk-bridge' ), 400, isset( $res['errors'] ) ? (array) $res['errors'] : array() );
			}
		}
		$context = $this->items->merge_context(
			$this->items->get( $pid ),
			array(
				'title_saved'    => isset( $res['data']['title_fa'] ) ? $res['data']['title_fa'] : $title_body['title_fa'],
				'title_hint'     => isset( $suggest['hint']['hint_fa'] ) ? $suggest['hint']['hint_fa'] : '',
				'title_suggest'  => isset( $suggest['title_fa'] ) ? $suggest['title_fa'] : '',
			)
		);

		// 4) Images.
		$image_ids = $this->upload_images( $data, $pid );
		if ( ! $image_ids ) {
			throw new ApiException( __( 'هیچ تصویری آپلود نشد.', 'dk-bridge' ), 400 );
		}

		// 5) Save.
		$save = apply_filters( 'dkb_product_payload', PayloadBuilder::save( $data, $draft, $image_ids ), 'save', $data, $product );
		$res  = $this->api->save_product( $save );
		$dkp  = isset( $res['data']['product_id'] ) ? (int) $res['data']['product_id'] : ( isset( $res['product_id'] ) ? (int) $res['product_id'] : 0 );
		if ( ! $dkp ) {
			throw new ApiException( __( 'شناسهٔ کالا پس از ذخیره دریافت نشد.', 'dk-bridge' ), 500, isset( $res['errors'] ) ? (array) $res['errors'] : array() );
		}
		$this->items->update(
			$item['id'],
			array(
				'dk_product_id'     => $dkp,
				'state'             => 'in_review',
				'moderation_status' => 'in_review',
				'payload_hash'      => $data->hash(),
				'attempts'          => 0,
				'last_error'        => null,
			)
		);
		$this->logger->info( 'publish', sprintf( 'کالا با شناسهٔ DKP-%d برای بررسی محتوا ارسال شد.', $dkp ), array(), $pid );
		/**
		 * Fires after a product was submitted to Digikala.
		 *
		 * @param int $product_id    WC product.
		 * @param int $dk_product_id DKP.
		 */
		do_action( 'dkb_after_push', $pid, $dkp );
	}

	/**
	 * Process & upload images (cached per file version).
	 *
	 * @param ProductData $data Data.
	 * @param int         $pid  Product.
	 * @return string[] Uploaded ids in order.
	 * @throws ApiException When the main image fails.
	 */
	public function upload_images( ProductData $data, $pid ) {
		$item      = $this->items->get( $pid );
		$context   = $item['context'];
		$uploaded  = isset( $context['images'] ) ? (array) $context['images'] : array();
		$hints     = isset( $context['ai_hints'] ) ? (array) $context['ai_hints'] : array();
		$options   = $this->mapper->image_options();
		$processor = new ImageProcessor( $options );
		$upload    = wp_upload_dir();
		$dir       = trailingslashit( $upload['basedir'] ) . 'dk-bridge';
		$ids       = array();

		foreach ( $data->images as $img ) {
			$key = $img['attachment_id'] . '-' . md5( $img['path'] . '|' . filemtime( $img['path'] ) . '|' . wp_json_encode( $options ) );
			if ( isset( $uploaded[ $key ] ) ) {
				$ids[] = $uploaded[ $key ];
				continue;
			}
			$dest = $dir . '/' . $key . '.jpg';
			$out  = $processor->process( $img['path'], $dest );
			if ( ! $out['ok'] ) {
				$this->logger->warning( 'images', sprintf( 'پردازش تصویر %d ناموفق بود (%s).', $img['attachment_id'], $out['error'] ), array(), $pid );
				if ( ! empty( $img['is_main'] ) ) {
					throw new ApiException( __( 'پردازش تصویر اصلی ناموفق بود.', 'dk-bridge' ), 400 );
				}
				continue;
			}
			try {
				$res = $this->api->upload_image( $out['path'] );
			} finally {
				if ( file_exists( $out['path'] ) ) {
					wp_delete_file( $out['path'] );
				}
			}
			if ( empty( $res['id'] ) ) {
				throw new ApiException( __( 'شناسهٔ تصویر آپلودشده دریافت نشد.', 'dk-bridge' ), 500 );
			}
			$uploaded[ $key ] = (string) $res['id'];
			$ids[]            = (string) $res['id'];
			if ( $this->settings->get( 'use_ai_check' ) ) {
				try {
					$ai = $this->api->image_ai( $res['id'], ! empty( $img['is_main'] ) );
					if ( ! empty( $ai['data'] ) ) {
						$hints[ $img['attachment_id'] ] = array_values( array_map( 'strval', (array) $ai['data'] ) );
					}
				} catch ( ApiException $e ) {
					$this->logger->debug( 'images', 'AI check failed: ' . $e->getMessage(), array(), $pid );
				}
			}
			$this->items->update(
				$item['id'],
				array(
					'context' => array_merge(
						$context,
						array(
							'images'   => $uploaded,
							'ai_hints' => $hints,
						)
					),
				)
			);
			$item    = $this->items->get( $pid );
			$context = $item['context'];
		}
		return $ids;
	}

	/**
	 * Create Digikala variants for an approved product.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $item    Parent item.
	 * @throws ApiException On errors.
	 */
	public function create_variants( $product, array $item ) {
		$dkp    = (int) $item['dk_product_id'];
		$config = $this->api->variant_config( $dkp );
		if ( ! empty( $config['global_error_message'] ) ) {
			$this->items->update(
				$item['id'],
				array(
					'last_error' => (string) $config['global_error_message'],
				)
			);
			$this->logger->info( 'variants', (string) $config['global_error_message'], array(), $product->get_id() );
			return;
		}

		// Commission & fixed-price info for accurate pricing.
		$extra = array();
		try {
			$seller = $this->api->be_seller( $dkp );
			if ( isset( $seller['commission']['commission'] ) ) {
				$extra['commission'] = (float) $seller['commission']['commission'];
			}
			if ( ! empty( $seller['price_type'] ) && is_string( $seller['price_type'] ) ) {
				$extra['price_type'] = $seller['price_type'];
			}
			if ( ! empty( $seller['referencePrice'] ) ) {
				$extra['reference_price'] = (int) $seller['referencePrice'];
			}
			// Remember the real commission for the repricer and promotion planner.
			$this->items->merge_context(
				$item,
				array_filter(
					array(
						'commission'  => isset( $extra['commission'] ) ? $extra['commission'] : null,
						'price_type'  => isset( $extra['price_type'] ) ? $extra['price_type'] : null,
						'fulfil_cost' => isset( $seller['fulfillmentAndDeliveryCost']['minimum_cost'] ) ? (int) $seller['fulfillmentAndDeliveryCost']['minimum_cost'] : null,
					),
					static function ( $v ) {
						return null !== $v;
					}
				)
			);
			$item = $this->items->get( $product->get_id() );
		} catch ( ApiException $e ) {
			$this->logger->debug( 'variants', 'be-seller failed: ' . $e->getMessage(), array(), $product->get_id() );
		}

		$warranty = (int) $product->get_meta( '_dkb_warranty_id' ) ?: (int) $this->settings->get( 'warranty_id' );
		$options  = isset( $config['warranty_options'] ) ? (array) $config['warranty_options'] : array();
		$allowed  = array_map( 'intval', wp_list_pluck( $options, 'id' ) );
		if ( ! $warranty || ( $allowed && ! in_array( $warranty, $allowed, true ) ) ) {
			$warranty = 0;
			foreach ( $options as $opt ) {
				if ( false !== mb_strpos( (string) $opt['title'], 'اصالت' ) ) {
					$warranty = (int) $opt['id'];
					break;
				}
			}
			if ( ! $warranty && $allowed ) {
				$warranty = $allowed[0];
			}
		}
		if ( ! $warranty ) {
			throw new ApiException( __( 'هیچ گارانتی معتبری برای این کالا در دسترس نیست؛ از پنل فروشنده گارانتی ثبت کنید.', 'dk-bridge' ), 400 );
		}

		$shipping = (string) $this->settings->get( 'shipping_type' );
		if ( ! empty( $config['is_only_sbs'] ) ) {
			$shipping = 'seller';
		}
		$defaults = array(
			'warranty_id'      => $warranty,
			'site'             => (string) $this->settings->get( 'site', 'digikala' ),
			'shipping_type'    => $shipping,
			'lead_time'        => (int) $this->settings->get( 'lead_time' ),
			'lead_time_max'    => isset( $config['lead_time_max'] ) ? (int) $config['lead_time_max'] : 0,
			'seller_lead_time' => (int) $this->settings->get( 'seller_lead_time' ),
			'order_limit'      => (int) $this->settings->get( 'max_per_order' ),
		);

		// Rows to create.
		if ( $product->is_type( 'variable' ) ) {
			$rows = $this->mapper->variations( $product );
		} else {
			$rows = array(
				array(
					'wc_id'     => $product->get_id(),
					'sku'       => (string) $product->get_sku(),
					'label'     => $product->get_name(),
					'color'     => '',
					'color_hex' => '',
					'size'      => '',
					'stock'     => $this->mapper->stock( $product ),
					'package'   => $this->mapper->dimensions( $product ),
				),
			);
			// Single-variant products in colored categories still need a color.
			$color = (string) $product->get_attribute( 'pa_color' ) ?: (string) $product->get_attribute( 'رنگ' ) ?: (string) $product->get_attribute( 'color' );
			if ( '' !== $color ) {
				$rows[0]['color'] = trim( explode( ',', $color )[0] );
			}
		}
		foreach ( $rows as $i => $row ) {
			$source            = wc_get_product( $row['wc_id'] );
			$price             = $this->mapper->price( $source ? $source : $product, $extra );
			$rows[ $i ]['price'] = (int) $price['price'];
		}

		$api      = $this->api;
		$resolved = VariantMapper::resolve(
			$rows,
			$config,
			static function ( $theme_id ) use ( $api, $dkp ) {
				return $api->theme_values( $theme_id, $dkp );
			}
		);

		$created = 0;
		$errors  = $resolved['errors'];
		foreach ( $resolved['rows'] as $row ) {
			$is_parent = (int) $row['wc_id'] === (int) $product->get_id();
			$row_item  = $is_parent ? $item : $this->items->upsert(
				$product->get_id(),
				(int) $row['wc_id'],
				array(
					'dk_product_id' => $dkp,
				)
			);
			if ( ! empty( $row_item['dk_variant_id'] ) ) {
				continue;
			}
			$payload = PayloadBuilder::variant( $row, $defaults, isset( $row['theme'] ) ? $row['theme'] : array() );
			/**
			 * Filter the variant creation payload.
			 *
			 * @param array       $payload Payload.
			 * @param array       $row     Variation row.
			 * @param \WC_Product $product Product.
			 */
			$payload = apply_filters( 'dkb_variant_payload', $payload, $row, $product );
			try {
				$res = $this->api->create_variant( $dkp, $payload );
			} catch ( ApiException $e ) {
				if ( $e->is_transient() || $e instanceof DryRunException ) {
					throw $e;
				}
				$errors[] = sprintf( '%s: %s', $row['label'], $e->full_message() );
				continue;
			}
			$variant_id = isset( $res['id'] ) ? (int) $res['id'] : 0;
			if ( $variant_id ) {
				++$created;
				$this->items->update(
					$row_item['id'],
					array(
						'dk_variant_id' => $variant_id,
						'dk_product_id' => $dkp,
						'state'         => 'live',
						'dirty_price'   => 1,
						'last_error'    => null,
					)
				);
				$this->logger->info( 'variants', sprintf( 'تنوع %d برای «%s» ساخته شد.', $variant_id, $row['label'] ), array(), $product->get_id() );
			}
		}

		$item       = $this->items->get( $product->get_id() );
		$has_live   = $item['dk_variant_id'] || array_filter( wp_list_pluck( $this->items->children( $product->get_id() ), 'dk_variant_id' ) );
		$this->items->update(
			$item['id'],
			array(
				'state'      => $has_live ? 'live' : ( $errors ? 'error' : $item['state'] ),
				'last_error' => $errors ? implode( "\n", $errors ) : null,
				'attempts'   => 0,
			)
		);
		if ( $errors ) {
			$this->logger->warning( 'variants', implode( "\n", $errors ), array(), $product->get_id() );
		}
	}

	/**
	 * Resubmit a rejected product via product-edit.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $item    Item.
	 */
	private function resubmit( $product, array $item ) {
		$eval = $this->evaluate( $product, true, true, false );
		if ( $this->is_blocked( $eval['result'] ) ) {
			$this->items->update(
				$item['id'],
				array(
					'state'      => 'rejected',
					'last_error' => __( 'برای ارسال دوباره، ابتدا ایرادهای مانع را برطرف کنید.', 'dk-bridge' ),
				)
			);
			return;
		}
		$data      = $eval['data'];
		$image_ids = $this->upload_images( $data, $product->get_id() );
		$payload   = PayloadBuilder::details( $data );
		unset( $payload['draft_product_id'] );
		$payload['title_fa'] = $data->title_fa;
		$payload['title_en'] = $data->title_en;
		$payload['length']   = (int) ceil( $data->item['length'] );
		$payload['width']    = (int) ceil( $data->item['width'] );
		$payload['height']   = (int) ceil( $data->item['height'] );
		$payload['weight']   = (int) ceil( $data->item['weight'] );
		$grouped             = array();
		foreach ( $data->attributes as $id => $value ) {
			$group                = isset( $data->attribute_groups[ $id ] ) ? $data->attribute_groups[ $id ] : 0;
			$grouped[ $group ][]  = array(
				'id'    => (int) $id,
				'value' => $value,
			);
		}
		if ( $grouped ) {
			$payload['attributes'] = $grouped;
		}
		$save                     = PayloadBuilder::save( $data, 0, $image_ids );
		$payload['photos_detail'] = $save['photos_detail'];
		$payload                  = apply_filters( 'dkb_product_payload', $payload, 'edit', $data, $product );

		$this->api->product_edit( (int) $item['dk_product_id'], $payload );
		$this->api->product_publish( (int) $item['dk_product_id'] );
		$this->items->update(
			$item['id'],
			array(
				'state'             => 'in_review',
				'moderation_status' => 'in_review',
				'payload_hash'      => $data->hash(),
				'last_error'        => null,
			)
		);
		$this->logger->info( 'publish', 'کالای اصلاح‌شده دوباره برای بررسی ارسال شد.', array(), $product->get_id() );
	}

	/**
	 * Preview every request body without calling mutating endpoints.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function preview( $product ) {
		$eval = $this->evaluate( $product, true, true, false );
		$data = $eval['data'];
		$rows = $data->variations ? $data->variations : array(
			array(
				'wc_id'   => $product->get_id(),
				'sku'     => (string) $product->get_sku(),
				'price'   => $data->price,
				'stock'   => $data->stock,
				'package' => $data->package,
			),
		);
		$defaults = array(
			'warranty_id'      => (int) $this->settings->get( 'warranty_id' ),
			'site'             => (string) $this->settings->get( 'site' ),
			'shipping_type'    => (string) $this->settings->get( 'shipping_type' ),
			'lead_time'        => (int) $this->settings->get( 'lead_time' ),
			'seller_lead_time' => (int) $this->settings->get( 'seller_lead_time' ),
			'order_limit'      => (int) $this->settings->get( 'max_per_order' ),
		);
		return array(
			'score'      => $eval['result']['score'],
			'changes'    => $eval['result']['changes'],
			'details'    => PayloadBuilder::details( $data, 0 ),
			'attributes' => PayloadBuilder::attributes( $data, 0 ),
			'title'      => PayloadBuilder::title( $data, 0 ),
			'images'     => array_map(
				static function ( $img ) {
					return array(
						'attachment_id' => $img['attachment_id'],
						'url'           => $img['url'],
						'is_main'       => $img['is_main'],
					);
				},
				$data->images
			),
			'variants'   => array_map(
				static function ( $row ) use ( $defaults ) {
					return PayloadBuilder::variant( $row, $defaults );
				},
				$rows
			),
			'price_info' => $data->price_info,
		);
	}
}
