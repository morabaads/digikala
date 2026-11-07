<?php
/**
 * Small HTML helpers for admin screens.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Data\ItemRepository;

defined( 'ABSPATH' ) || exit;

/**
 * View helpers (all output escaped).
 */
class Views {

	/**
	 * Colored state badge.
	 *
	 * @param array|null $item Item.
	 * @return string
	 */
	public static function state_badge( $item ) {
		$state = $item ? $item['state'] : 'local';
		$label = isset( ItemRepository::STATES[ $state ] ) ? ItemRepository::STATES[ $state ] : $state;
		$html  = '<span class="dkb-badge dkb-state-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
		if ( $item && $item['dk_product_id'] ) {
			$html .= ' <a class="dkb-dkp" target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://www.digikala.com/product/dkp-' . (int) $item['dk_product_id'] . '/' ) . '">DKP-' . (int) $item['dk_product_id'] . '</a>';
		}
		return $html;
	}

	/**
	 * Readiness score ring.
	 *
	 * @param int|null $score Score.
	 * @param string   $size  normal|small|large.
	 * @return string
	 */
	public static function score_ring( $score, $size = 'normal' ) {
		if ( null === $score ) {
			return '<span class="dkb-ring dkb-ring-' . esc_attr( $size ) . ' dkb-ring-none" title="' . esc_attr__( 'هنوز بررسی نشده', 'dk-bridge' ) . '">–</span>';
		}
		$score = max( 0, min( 100, (int) $score ) );
		$class = $score >= 85 ? 'good' : ( $score >= 60 ? 'ok' : 'bad' );
		return sprintf(
			'<span class="dkb-ring dkb-ring-%1$s dkb-ring-%2$s" style="--p:%3$d" title="%4$s"><span>%3$d</span></span>',
			esc_attr( $size ),
			esc_attr( $class ),
			$score,
			esc_attr__( 'امتیاز آمادگی دیجی‌کالا', 'dk-bridge' )
		);
	}

	/**
	 * Severity label.
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	public static function severity( $severity ) {
		$map = array(
			'error'   => __( 'خطا', 'dk-bridge' ),
			'warning' => __( 'هشدار', 'dk-bridge' ),
			'info'    => __( 'اطلاع', 'dk-bridge' ),
		);
		return '<span class="dkb-sev dkb-sev-' . esc_attr( $severity ) . '">' . esc_html( isset( $map[ $severity ] ) ? $map[ $severity ] : $severity ) . '</span>';
	}

	/**
	 * Issue list.
	 *
	 * @param array $issues Issues (arrays).
	 * @param int   $limit  Limit.
	 * @return string
	 */
	public static function issues( array $issues, $limit = 0 ) {
		if ( ! $issues ) {
			return '<p class="dkb-ok">' . esc_html__( 'ایرادی پیدا نشد.', 'dk-bridge' ) . '</p>';
		}
		$html = '<ul class="dkb-issues">';
		foreach ( $limit ? array_slice( $issues, 0, $limit ) : $issues as $issue ) {
			$html .= '<li>' . self::severity( $issue['severity'] ) . ' ' . esc_html( $issue['message'] ) . ( ! empty( $issue['fixable'] ) ? ' <span class="dkb-fixable">' . esc_html__( 'قابل اصلاح خودکار', 'dk-bridge' ) . '</span>' : '' ) . '</li>';
		}
		if ( $limit && count( $issues ) > $limit ) {
			/* translators: %d: count */
			$html .= '<li class="dkb-more">' . esc_html( sprintf( __( 'و %d مورد دیگر…', 'dk-bridge' ), count( $issues ) - $limit ) ) . '</li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Settings field row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $type  text|number|checkbox|select|textarea|password.
	 * @param mixed  $value Value.
	 * @param array  $args  options, help, attrs.
	 * @return string
	 */
	public static function field( $key, $label, $type, $value, array $args = array() ) {
		$id    = 'dkb_' . $key;
		$name  = 'dkb[' . $key . ']';
		$attrs = '';
		foreach ( isset( $args['attrs'] ) ? $args['attrs'] : array() as $k => $v ) {
			$attrs .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		switch ( $type ) {
			case 'checkbox':
				$input = '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . $attrs . '> ' . ( isset( $args['label'] ) ? esc_html( $args['label'] ) : '' ) . '</label>';
				break;
			case 'select':
				$input = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $attrs . '>';
				foreach ( $args['options'] as $k => $v ) {
					$input .= '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $v ) . '</option>';
				}
				$input .= '</select>';
				break;
			case 'textarea':
				$input = '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text"' . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>';
				break;
			default:
				$input = '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" class="' . ( 'number' === $type ? 'small-text' : 'regular-text' ) . '"' . $attrs . '>';
		}
		$help = isset( $args['help'] ) ? '<p class="description">' . wp_kses_post( $args['help'] ) . '</p>' : '';
		return '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . $input . $help . '</td></tr>';
	}
}
