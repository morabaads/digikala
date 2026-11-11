<?php
/**
 * The daily assistant: one prioritized to-do list across all modules.
 *
 * @package DKBridge
 */

namespace DKBridge\Modules;

use DKBridge\Api\ApiException;
use DKBridge\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds "what needs my attention today".
 */
class AssistantService {

	const CACHE = 'dkb_assistant_tasks';

	/** @var Plugin */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Tasks sorted by priority (1 = most urgent).
	 *
	 * @param bool $refresh Ignore cache.
	 * @return array[] {priority, area, title, detail, count, link}
	 */
	public function tasks( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$tasks = array();
		$add   = static function ( $priority, $area, $title, $detail, $count, $page ) use ( &$tasks ) {
			if ( $count > 0 ) {
				$tasks[] = array(
					'priority' => $priority,
					'area'     => $area,
					'title'    => $title,
					'detail'   => $detail,
					'count'    => (int) $count,
					'link'     => admin_url( 'admin.php?page=' . $page ),
				);
			}
		};
		$p = $this->plugin;

		// Content pipeline (local data, always available).
		$counts = $p->items()->counts();
		$add( 2, 'محتوا', 'کالاهای ردشده در بررسی محتوا', 'دلیل رد را ببینید، اصلاح خودکار بزنید و دوباره ارسال کنید.', $counts['rejected'], 'dk-bridge-products&dkb_state=rejected' );
		$add( 2, 'محتوا', 'کالاهای دارای خطا', 'خطاهای ارسال یا ساخت تنوع.', $counts['error'], 'dk-bridge-products&dkb_state=error' );
		$add( 3, 'محتوا', 'کالای مشابه پیدا شده', 'تصمیم بگیرید روی کالای موجود بفروشید یا کالای جدید بسازید.', $counts['match_review'], 'dk-bridge-products&dkb_state=match_review' );
		$add( 4, 'محتوا', 'کالاهای نیازمند اصلاح پیش از ارسال', 'امتیاز آمادگی کمتر از حداقل یا خطای مانع.', $counts['blocked'], 'dk-bridge-products&dkb_state=blocked' );
		$add( 5, 'محتوا', 'دسته‌های نگاشت‌نشده', 'محصولات این دسته‌ها قابل ارسال نیستند.', $p->categories()->unmapped_count(), 'dk-bridge-categories' );

		if ( $p->is_connected() ) {
			$summary = $p->variants()->summary();
			$add( 2, 'قیمت', 'بای‌باکس از دست رفته', 'تنوع‌هایی که رقیب دارند و برنده نیستید؛ قیمت‌گذاری هوشمند را بررسی کنید.', (int) ( isset( $summary['losing'] ) ? $summary['losing'] : 0 ), 'dk-bridge-pricing' );
			$add( 4, 'کالا', 'تنوع‌های متصل‌نشده به ووکامرس', 'موجودی و قیمت این تنوع‌ها همگام نمی‌شود.', (int) ( isset( $summary['total'], $summary['linked'] ) ? $summary['total'] - $summary['linked'] : 0 ), 'dk-bridge-catalog&linked=0' );
			$add( 4, 'موجودی', 'موجودی راکد در انبار دیجی‌کالا', 'برای این کالاها پروموشن یا تخفیف هوشمند در نظر بگیرید.', (int) ( isset( $summary['dead_stock'] ) ? $summary['dead_stock'] : 0 ), 'dk-bridge-inventory&dead=1' );

			try {
				$inv    = $p->inventory_service()->overview( array(), 1 );
				$urgent = 0;
				foreach ( $inv['rows'] as $r ) {
					if ( 'out' === $r['health'] && $r['sold'] > 0 ) {
						++$urgent;
					}
				}
				$add( 1, 'موجودی', 'کالای پرفروش ناموجود شده', 'هر روز ناموجودی یعنی فروش ازدست‌رفته و افت رتبه.', $urgent, 'dk-bridge-inventory&health=out' );
				$add( 3, 'موجودی', 'کالای رو به اتمام', 'پوشش موجودی کمتر از آستانه.', $inv['summary']['low'], 'dk-bridge-inventory&health=low' );
				$add( 2, 'موجودی', 'مغایرت موجودی سایت و دیجی‌کالا', 'با یک کلیک دوباره همگام کنید.', $inv['summary']['discrepancy'], 'dk-bridge-inventory&discrepancy=1' );
			} catch ( \Throwable $e ) {
				$p->logger()->debug( 'assistant', $e->getMessage() );
			}

			try {
				$c       = $p->logistics()->commitments();
				$delayed = 0;
				$today   = 0;
				foreach ( $c['items'] as $row ) {
					$delayed += isset( $row['commitment']['delayed'] ) ? (int) $row['commitment']['delayed'] : 0;
					$today   += isset( $row['commitment']['today'] ) ? (int) $row['commitment']['today'] : 0;
				}
				$add( 1, 'لجستیک', 'تعهد ارسال با تأخیر', 'تأخیر جریمه دارد و امتیاز عملکرد را کم می‌کند؛ همین امروز محموله بسازید.', $delayed, 'dk-bridge-logistics' );
				$add( 1, 'لجستیک', 'تعهد ارسال امروز', 'سفارش‌هایی که امروز باید به انبار برسند.', $today, 'dk-bridge-logistics' );
			} catch ( \Throwable $e ) {
				$p->logger()->debug( 'assistant', $e->getMessage() );
			}

			try {
				$sbs = $p->logistics()->sbs_orders( array( 'critical_only' => true ) );
				$add( 1, 'لجستیک', 'سفارش‌های ارسال توسط فروشنده که مهلتشان گذشته', 'وضعیت و کد رهگیری را ثبت کنید.', count( $sbs ), 'dk-bridge-logistics&tab=sbs' );
			} catch ( ApiException $e ) {
				$p->logger()->debug( 'assistant', $e->getMessage() );
			}

			try {
				$closing = 0;
				foreach ( $p->promotions()->eligible( true ) as $promo ) {
					if ( ! empty( $promo['join_deadline'] ) && strtotime( $promo['join_deadline'] ) < time() + 2 * DAY_IN_SECONDS ) {
						++$closing;
					}
				}
				$add( 3, 'پروموشن', 'مهلت پیوستن به پروموشن کمتر از ۴۸ ساعت', 'برنامهٔ سودآور پیوستن را ببینید.', $closing, 'dk-bridge-promotions' );
			} catch ( ApiException $e ) {
				$p->logger()->debug( 'assistant', $e->getMessage() );
			}

			try {
				$perf   = $p->api()->performance();
				$alerts = AnalyticsService::performance_alerts( $perf );
				$add( 2, 'عملکرد', 'شاخص عملکرد زیر هدف', implode( ' ', $alerts ), count( $alerts ), 'dk-bridge-analytics' );
			} catch ( ApiException $e ) {
				$p->logger()->debug( 'assistant', $e->getMessage() );
			}
		}

		usort(
			$tasks,
			static function ( $a, $b ) {
				return $a['priority'] !== $b['priority'] ? $a['priority'] - $b['priority'] : $b['count'] - $a['count'];
			}
		);
		set_transient( self::CACHE, $tasks, 10 * MINUTE_IN_SECONDS );
		return $tasks;
	}
}
