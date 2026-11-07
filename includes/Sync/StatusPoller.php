<?php
/**
 * Polls Digikala moderation status of submitted products.
 *
 * @package DKBridge
 */

namespace DKBridge\Sync;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Data\ItemRepository;
use DKBridge\Settings;
use DKBridge\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Status poller.
 */
class StatusPoller {

	const APPROVED = array( 'approved', 'edit_after_approved', 'in_review_after_approved' );
	const REJECTED = array( 'removed', 'duplicate', 'waiting_for_photo', 'rejected' );

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var ItemRepository */
	private $items;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param Digikala       $api      API.
	 * @param ItemRepository $items    Items.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct( Settings $settings, Digikala $api, ItemRepository $items, Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->items    = $items;
		$this->logger   = $logger;
	}

	/**
	 * Poll all in-review items.
	 *
	 * @return int Updated.
	 */
	public function poll() {
		if ( ! Queue::lock( 'poll_moderation', 1700 ) ) {
			return 0;
		}
		$updated = 0;
		try {
			foreach ( $this->items->in_state( array( 'in_review' ), 100 ) as $item ) {
				if ( $this->refresh( $item ) ) {
					++$updated;
				}
			}
		} finally {
			Queue::unlock( 'poll_moderation' );
		}
		return $updated;
	}

	/**
	 * Refresh one item.
	 *
	 * @param array $item Item.
	 * @return bool Changed.
	 */
	public function refresh( array $item ) {
		if ( empty( $item['dk_product_id'] ) ) {
			return false;
		}
		try {
			$data = $this->api->seller_products( array( 'multi_search' => (string) $item['dk_product_id'] ), 1, 10 );
		} catch ( ApiException $e ) {
			$this->logger->warning( 'moderation', $e->full_message(), array(), $item['wc_product_id'] );
			return false;
		}
		$status = null;
		foreach ( isset( $data['items'] ) ? (array) $data['items'] : array() as $row ) {
			if ( isset( $row['product_id'] ) && (int) $row['product_id'] === (int) $item['dk_product_id'] ) {
				$status = isset( $row['moderation_status']['status'] ) ? $row['moderation_status']['status'] : ( isset( $row['status_data'] ) ? $row['status_data'] : null );
				break;
			}
		}
		return null !== $status ? $this->apply_status( $item, (string) $status ) : false;
	}

	/**
	 * Apply a moderation status.
	 *
	 * @param array  $item   Item.
	 * @param string $status Status.
	 * @return bool Changed.
	 */
	public function apply_status( array $item, $status ) {
		if ( $status === $item['moderation_status'] ) {
			return false;
		}
		$fields = array( 'moderation_status' => $status );
		if ( in_array( $status, self::APPROVED, true ) ) {
			$fields['state'] = 'approved';
			Queue::push( 'dkb_create_variants', array( (int) $item['wc_product_id'] ) );
			$this->logger->info( 'moderation', sprintf( 'کالای DKP-%d تأیید شد؛ ساخت تنوع‌ها در صف قرار گرفت.', $item['dk_product_id'] ), array(), $item['wc_product_id'] );
			$this->notify( $item, true, $status );
		} elseif ( in_array( $status, self::REJECTED, true ) ) {
			$fields['state']      = 'rejected';
			$fields['last_error'] = self::reason( $status );
			$this->logger->warning( 'moderation', sprintf( 'کالای DKP-%d رد شد: %s', $item['dk_product_id'], self::reason( $status ) ), array(), $item['wc_product_id'] );
			$this->notify( $item, false, $status );
		}
		$this->items->update( $item['id'], $fields );
		return true;
	}

	/**
	 * Human reason for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function reason( $status ) {
		$map = array(
			'removed'           => 'کالا توسط کارشناس محتوا رد/حذف شد. دلیل را در پنل فروشنده ببینید، ایراد را اصلاح و دوباره ارسال کنید.',
			'duplicate'         => 'کالا تکراری تشخیص داده شد؛ از گزینهٔ «فروش روی کالای موجود» استفاده کنید.',
			'waiting_for_photo' => 'دیجی‌کالا تصاویر بهتری می‌خواهد (پس‌زمینهٔ سفید، بدون واترمارک و متن).',
			'rejected'          => 'کالا رد شد.',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	/**
	 * Email notification.
	 *
	 * @param array  $item     Item.
	 * @param bool   $approved Approved.
	 * @param string $status   Status.
	 */
	private function notify( array $item, $approved, $status ) {
		$to = (string) $this->settings->get( 'notify_email' );
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}
		$product = wc_get_product( $item['wc_product_id'] );
		$name    = $product ? $product->get_name() : '#' . $item['wc_product_id'];
		$subject = $approved ? sprintf( '[DK Bridge] کالای «%s» در دیجی‌کالا تأیید شد', $name ) : sprintf( '[DK Bridge] کالای «%s» در دیجی‌کالا رد شد', $name );
		$body    = $approved ? sprintf( "کالای «%s» با شناسهٔ DKP-%d تأیید شد و تنوع‌ها در حال ساخت هستند.\nhttps://www.digikala.com/product/dkp-%d/", $name, $item['dk_product_id'], $item['dk_product_id'] ) : sprintf( "کالای «%s» (DKP-%d) رد شد.\nدلیل: %s\n\n%s", $name, $item['dk_product_id'], self::reason( $status ), admin_url( 'post.php?post=' . (int) $item['wc_product_id'] . '&action=edit' ) );
		wp_mail( $to, $subject, $body );
	}
}
