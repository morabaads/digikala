<?php
/**
 * «دیجی‌کالا» meta box on the product edit screen.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Mapping\ProductMapper;
use DKBridge\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Meta box with readiness report and overrides.
 */
class MetaBox {

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
	 * Hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes_product', array( $this, 'add' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	/**
	 * Add the box.
	 */
	public function add() {
		add_meta_box( 'dkb-box', __( 'دیجی‌کالا', 'dk-bridge' ), array( $this, 'render' ), 'product', 'normal', 'high' );
	}

	/**
	 * Render.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render( $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}
		$item = $this->plugin->items()->get( $post->ID );
		$m    = ProductMapper::META;
		$get  = static function ( $key ) use ( $product, $m ) {
			return $product->get_meta( $m[ $key ] );
		};
		wp_nonce_field( 'dkb_meta', 'dkb_meta_nonce' );

		echo '<div class="dkb-box" dir="rtl" data-id="' . (int) $post->ID . '">';
		echo '<div class="dkb-box-head">' . Views::score_ring( $item ? $item['score'] : null, 'large' ) . '<div class="dkb-box-status">' . Views::state_badge( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $item && $item['last_error'] ) {
			echo '<div class="dkb-error-line">' . esc_html( $item['last_error'] ) . '</div>';
		}
		echo '<div class="dkb-actions">'
			. '<button type="button" class="button" data-dkb-box="validate">' . esc_html__( 'بررسی قوانین', 'dk-bridge' ) . '</button> '
			. '<button type="button" class="button" data-dkb-box="autofix">' . esc_html__( 'اصلاح خودکار', 'dk-bridge' ) . '</button> '
			. '<button type="button" class="button" data-dkb-box="preview">' . esc_html__( 'پیش‌نمایش درخواست', 'dk-bridge' ) . '</button> '
			. '<button type="button" class="button button-primary" data-dkb-box="push-now">' . esc_html__( 'ارسال به دیجی‌کالا', 'dk-bridge' ) . '</button> ';
		if ( $item && 'in_review' === $item['state'] ) {
			echo '<button type="button" class="button" data-dkb-box="status">' . esc_html__( 'به‌روزرسانی وضعیت', 'dk-bridge' ) . '</button>';
		}
		echo '</div></div></div>';

		// Match candidates.
		if ( $item && 'match_review' === $item['state'] && ! empty( $item['context']['match_candidates'] ) ) {
			echo '<div class="dkb-matches"><h4>' . esc_html__( 'این کالا احتمالاً در دیجی‌کالا وجود دارد. برای جلوگیری از رد شدن به‌عنوان «تکراری»، روی کالای موجود بفروشید:', 'dk-bridge' ) . '</h4><ul>';
			foreach ( $item['context']['match_candidates'] as $c ) {
				echo '<li>' . ( $c['image'] ? '<img src="' . esc_url( $c['image'] ) . '" width="40" height="40" alt=""> ' : '' ) . '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://www.digikala.com/product/dkp-' . (int) $c['id'] . '/' ) . '">' . esc_html( $c['title'] ) . '</a> <span class="dkb-muted">' . esc_html( sprintf( __( 'شباهت %d٪', 'dk-bridge' ), (int) round( $c['score'] * 100 ) ) ) . ( null !== $c['sellers'] ? ' · ' . esc_html( sprintf( __( '%d فروشنده', 'dk-bridge' ), $c['sellers'] ) ) : '' ) . '</span> ';
				echo $c['can_sell'] ? '<button type="button" class="button button-small" data-dkb-match="' . (int) $c['id'] . '">' . esc_html__( 'فروش روی این کالا', 'dk-bridge' ) . '</button>' : '<span class="dkb-bad">' . esc_html__( 'مجوز فروش ندارید', 'dk-bridge' ) . '</span>';
				echo '</li>';
			}
			echo '</ul><button type="button" class="button" data-dkb-match="new">' . esc_html__( 'نه، کالای جدید بساز', 'dk-bridge' ) . '</button></div>';
		}

		// AI hints from Digikala.
		if ( $item && ! empty( $item['context']['ai_hints'] ) ) {
			echo '<div class="dkb-ai"><h4>' . esc_html__( 'توصیه‌های هوش مصنوعی دیجی‌کالا برای تصاویر', 'dk-bridge' ) . '</h4><ul>';
			foreach ( $item['context']['ai_hints'] as $att => $hints ) {
				foreach ( (array) $hints as $h ) {
					echo '<li>' . esc_html( $h ) . ' <span class="dkb-muted">(#' . (int) $att . ')</span></li>';
				}
			}
			echo '</ul></div>';
		}

		echo '<div class="dkb-report">' . Views::issues( $item ? (array) $item['issues'] : array() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="dkb-box-result"></div>';

		// Overrides.
		echo '<details class="dkb-overrides" ' . ( $get( 'title_fa' ) ? 'open' : '' ) . '><summary>' . esc_html__( 'محتوای اختصاصی دیجی‌کالا (محتوای سایت تغییر نمی‌کند)', 'dk-bridge' ) . '</summary><table class="form-table">';
		$this->row( 'title_fa', __( 'عنوان فارسی', 'dk-bridge' ), 'text', $get( 'title_fa' ), $product->get_name() );
		$this->row( 'title_en', __( 'عنوان انگلیسی', 'dk-bridge' ), 'text', $get( 'title_en' ), '', 'ltr' );
		$this->row( 'nature', __( 'ماهیت کالا', 'dk-bridge' ), 'text', $get( 'nature' ), __( 'مثلاً «گوشی موبایل» — پیش‌فرض: نام گروه دیجی‌کالا', 'dk-bridge' ) );
		$this->row( 'nature_en', __( 'ماهیت کالا (انگلیسی)', 'dk-bridge' ), 'text', $get( 'nature_en' ), 'Mobile Phone', 'ltr' );
		$this->row( 'model', __( 'مدل', 'dk-bridge' ), 'text', $get( 'model' ), $product->get_sku() );
		$this->row( 'brand', __( 'برند', 'dk-bridge' ), 'text', $get( 'brand' ), __( 'خودکار', 'dk-bridge' ) );
		$this->row( 'brand_id', __( 'شناسهٔ برند دیجی‌کالا', 'dk-bridge' ), 'number', $get( 'brand_id' ), '' );
		echo '<tr><th>' . esc_html__( 'گروه دیجی‌کالا (اختصاصی)', 'dk-bridge' ) . '</th><td><input type="number" name="dkb_meta[_dkb_category_id]" value="' . esc_attr( (string) $product->get_meta( '_dkb_category_id' ) ) . '" class="small-text"> <input type="text" name="dkb_meta[_dkb_category_title]" value="' . esc_attr( (string) $product->get_meta( '_dkb_category_title' ) ) . '" placeholder="' . esc_attr__( 'عنوان گروه', 'dk-bridge' ) . '"><p class="description">' . esc_html__( 'خالی = از نگاشت دسته‌ها استفاده شود.', 'dk-bridge' ) . '</p></td></tr>';
		$this->row( 'description', __( 'توضیحات', 'dk-bridge' ), 'textarea', $get( 'description' ), __( 'خالی = توضیحات پاک‌سازی‌شدهٔ محصول', 'dk-bridge' ) );
		$this->row( 'advantages', __( 'نقاط قوت (هر خط یکی)', 'dk-bridge' ), 'textarea', $get( 'advantages' ), '' );
		$this->row( 'disadvantages', __( 'نقاط ضعف (هر خط یکی)', 'dk-bridge' ), 'textarea', $get( 'disadvantages' ), '' );
		$this->row( 'general_mefa_id', __( 'شناسهٔ عمومی کالا', 'dk-bridge' ), 'text', $get( 'general_mefa_id' ), '' );
		$this->row( 'warranty_id', __( 'شناسهٔ گارانتی', 'dk-bridge' ), 'number', $get( 'warranty_id' ), '' );
		$this->row( 'bundle', __( 'کالای همراه (باندل)', 'dk-bridge' ), 'text', $get( 'bundle' ), __( 'مثلاً «پاور بانک» — در عنوان «به همراه …» اضافه می‌شود', 'dk-bridge' ) );
		$this->row( 'production_date', __( 'تاریخ تولید (میلادی)', 'dk-bridge' ), 'date', $get( 'production_date' ), '', 'ltr' );
		$this->row( 'expiry_date', __( 'تاریخ انقضا (میلادی)', 'dk-bridge' ), 'date', $get( 'expiry_date' ), '', 'ltr' );
		echo '<tr><th>' . esc_html__( 'قیمت‌گذاری هوشمند', 'dk-bridge' ) . '</th><td><select name="dkb_meta[_dkb_price_strategy]"><option value="">' . esc_html__( 'پیش‌فرض تنظیمات', 'dk-bridge' ) . '</option>';
		foreach ( \DKBridge\Intelligence\RepricingEngine::STRATEGIES as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( (string) $product->get_meta( '_dkb_price_strategy' ), $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ' . esc_html__( 'کف قیمت (ریال):', 'dk-bridge' ) . ' <input type="number" class="small-text" style="width:120px" name="dkb_meta[_dkb_price_floor]" value="' . esc_attr( (string) $product->get_meta( '_dkb_price_floor' ) ) . '"> ' . esc_html__( 'سقف قیمت (ریال):', 'dk-bridge' ) . ' <input type="number" class="small-text" style="width:120px" name="dkb_meta[_dkb_price_ceiling]" value="' . esc_attr( (string) $product->get_meta( '_dkb_price_ceiling' ) ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'ویژگی‌ها', 'dk-bridge' ) . '</th><td>'
			. '<label><input type="checkbox" name="dkb_meta[_dkb_fake]" value="yes" ' . checked( 'yes', $get( 'fake' ), false ) . '> ' . esc_html__( 'کالای غیراصل (نشان غیراصل)', 'dk-bridge' ) . '</label><br>'
			. '<label><input type="checkbox" name="dkb_meta[_dkb_is_iranian]" value="yes" ' . checked( 'yes', $get( 'is_iranian' ), false ) . '> ' . esc_html__( 'تولید ایران', 'dk-bridge' ) . '</label><br>'
			. '<label><input type="checkbox" name="dkb_meta[_dkb_exclude]" value="yes" ' . checked( 'yes', $get( 'exclude' ), false ) . '> ' . esc_html__( 'این محصول به دیجی‌کالا ارسال/همگام نشود', 'dk-bridge' ) . '</label>'
			. '</td></tr>';
		echo '</table></details>';
		echo '</div>';
	}

	/**
	 * Field row.
	 *
	 * @param string $key         Key.
	 * @param string $label       Label.
	 * @param string $type        Type.
	 * @param mixed  $value       Value.
	 * @param string $placeholder Placeholder.
	 * @param string $dir         Direction.
	 */
	private function row( $key, $label, $type, $value, $placeholder, $dir = 'rtl' ) {
		$name = 'dkb_meta[' . ProductMapper::META[ $key ] . ']';
		echo '<tr><th><label>' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea name="' . esc_attr( $name ) . '" rows="3" class="large-text" dir="' . esc_attr( $dir ) . '" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="' . ( 'number' === $type ? 'small-text' : 'large-text' ) . '" dir="' . esc_attr( $dir ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
		}
		echo '</td></tr>';
	}

	/**
	 * Save overrides.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function save( $product ) {
		if ( ! isset( $_POST['dkb_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dkb_meta_nonce'] ) ), 'dkb_meta' ) ) {
			return;
		}
		$raw     = isset( $_POST['dkb_meta'] ) && is_array( $_POST['dkb_meta'] ) ? wp_unslash( $_POST['dkb_meta'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$allowed = array_merge( array_values( ProductMapper::META ), array( '_dkb_category_id', '_dkb_category_title', '_dkb_price_strategy', '_dkb_price_floor', '_dkb_price_ceiling' ) );
		$checks  = array( '_dkb_fake', '_dkb_is_iranian', '_dkb_exclude' );
		foreach ( $allowed as $key ) {
			if ( in_array( $key, array( '_dkb_product_type_ids', '_dkb_division_id' ), true ) ) {
				continue;
			}
			if ( in_array( $key, $checks, true ) ) {
				if ( isset( $raw[ $key ] ) ) {
					$product->update_meta_data( $key, 'yes' );
				} elseif ( '_dkb_is_iranian' === $key && ! $this->plugin->settings()->get( 'default_is_iranian' ) ) {
					$product->delete_meta_data( $key ); // Follow the global default.
				} else {
					$product->update_meta_data( $key, 'no' );
				}
				continue;
			}
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$value = in_array( $key, array( '_dkb_description', '_dkb_advantages', '_dkb_disadvantages' ), true ) ? sanitize_textarea_field( $raw[ $key ] ) : sanitize_text_field( $raw[ $key ] );
			if ( '' === $value ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $value );
			}
		}
	}
}
