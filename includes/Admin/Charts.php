<?php
/**
 * Dependency-free inline SVG charts for admin screens.
 *
 * Colors are the first two slots of a validated categorical palette, in fixed
 * order (series 1 blue, series 2 orange). Text never takes a series color.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Chart helpers (all output escaped).
 */
class Charts {

	const SERIES = array( '#2a78d6', '#eb6834' );

	/**
	 * Sparkline for a KPI tile (single series, no legend).
	 *
	 * @param float[] $values Values in time order.
	 * @param string  $label  Accessible label.
	 * @return string
	 */
	public static function sparkline( array $values, $label ) {
		$values = array_values( array_map( 'floatval', $values ) );
		$n      = count( $values );
		if ( $n < 2 ) {
			return '';
		}
		$w   = 120;
		$h   = 32;
		$max = max( $values );
		$min = min( $values );
		$rng = $max - $min > 0 ? $max - $min : 1;
		$pts = array();
		foreach ( $values as $i => $v ) {
			$pts[] = round( $i * ( $w - 4 ) / ( $n - 1 ) + 2, 1 ) . ',' . round( $h - 3 - ( $v - $min ) * ( $h - 6 ) / $rng, 1 );
		}
		return '<svg class="dkb-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" role="img" aria-label="' . esc_attr( $label ) . '"><polyline fill="none" stroke="' . self::SERIES[0] . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg>';
	}

	/**
	 * Multi-series line chart with legend, per-day hover targets and a table view.
	 *
	 * @param string[]               $dates  X labels (Y-m-d).
	 * @param array<string,float[]>  $series name => values (max 2).
	 * @param string                 $title  Title.
	 * @param string                 $unit   Unit label for values.
	 * @return string
	 */
	public static function lines( array $dates, array $series, $title, $unit = '' ) {
		$n = count( $dates );
		if ( $n < 2 || ! $series ) {
			return '';
		}
		$w      = 720;
		$h      = 220;
		$pad_l  = 64;
		$pad_r  = 12;
		$pad_t  = 12;
		$pad_b  = 28;
		$all    = array();
		foreach ( $series as $vals ) {
			$all = array_merge( $all, array_map( 'floatval', $vals ) );
		}
		$max = max( 1.0, max( $all ) );
		$x   = static function ( $i ) use ( $n, $w, $pad_l, $pad_r ) {
			return round( $pad_l + $i * ( $w - $pad_l - $pad_r ) / ( $n - 1 ), 1 );
		};
		$y   = static function ( $v ) use ( $max, $h, $pad_t, $pad_b ) {
			return round( $h - $pad_b - (float) $v * ( $h - $pad_t - $pad_b ) / $max, 1 );
		};

		$svg  = '<svg class="dkb-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr( $title ) . '">';
		// Recessive grid: 4 horizontal lines with labels.
		for ( $g = 0; $g <= 4; $g++ ) {
			$val  = $max * $g / 4;
			$gy   = $y( $val );
			$svg .= '<line x1="' . $pad_l . '" x2="' . ( $w - $pad_r ) . '" y1="' . $gy . '" y2="' . $gy . '" stroke="#e6e5e0" stroke-width="1"/>';
			$svg .= '<text x="' . ( $pad_l - 6 ) . '" y="' . ( $gy + 4 ) . '" text-anchor="end" font-size="10" fill="#52514e">' . esc_html( self::compact( $val ) ) . '</text>';
		}
		// X labels: first, middle, last.
		foreach ( array_unique( array( 0, (int) floor( ( $n - 1 ) / 2 ), $n - 1 ) ) as $i ) {
			$svg .= '<text x="' . $x( $i ) . '" y="' . ( $h - 8 ) . '" text-anchor="middle" font-size="10" fill="#52514e">' . esc_html( substr( $dates[ $i ], 5 ) ) . '</text>';
		}
		$k = 0;
		foreach ( $series as $name => $vals ) {
			$pts = array();
			foreach ( array_values( $vals ) as $i => $v ) {
				$pts[] = $x( $i ) . ',' . $y( $v );
			}
			$svg .= '<polyline fill="none" stroke="' . self::SERIES[ $k % 2 ] . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . esc_attr( implode( ' ', $pts ) ) . '"/>';
			++$k;
		}
		// Hover targets wider than the marks: one column per day with a native tooltip.
		$col = ( $w - $pad_l - $pad_r ) / max( 1, $n - 1 );
		foreach ( $dates as $i => $d ) {
			$tip = $d;
			foreach ( $series as $name => $vals ) {
				$vals = array_values( $vals );
				$tip .= "\n" . $name . ': ' . number_format( (float) $vals[ $i ] ) . ( $unit ? ' ' . $unit : '' );
			}
			$svg .= '<rect class="dkb-hit" x="' . round( $x( $i ) - $col / 2, 1 ) . '" y="' . $pad_t . '" width="' . round( $col, 1 ) . '" height="' . ( $h - $pad_t - $pad_b ) . '" fill="transparent"><title>' . esc_html( $tip ) . '</title></rect>';
		}
		$svg .= '</svg>';

		$legend = '<div class="dkb-legend">';
		$k      = 0;
		foreach ( $series as $name => $vals ) {
			$legend .= '<span><i style="background:' . self::SERIES[ $k % 2 ] . '"></i>' . esc_html( $name ) . '</span>';
			++$k;
		}
		$legend .= '</div>';

		$table = '<details class="dkb-table-view"><summary>' . esc_html__( 'نمایش جدول', 'dk-bridge' ) . '</summary><table class="widefat striped"><thead><tr><th>' . esc_html__( 'تاریخ', 'dk-bridge' ) . '</th>';
		foreach ( array_keys( $series ) as $name ) {
			$table .= '<th>' . esc_html( $name ) . '</th>';
		}
		$table .= '</tr></thead><tbody>';
		foreach ( $dates as $i => $d ) {
			$table .= '<tr><td>' . esc_html( $d ) . '</td>';
			foreach ( $series as $vals ) {
				$vals   = array_values( $vals );
				$table .= '<td>' . esc_html( number_format( (float) $vals[ $i ] ) ) . '</td>';
			}
			$table .= '</tr>';
		}
		$table .= '</tbody></table></details>';

		return '<figure class="dkb-figure"><figcaption>' . esc_html( $title ) . '</figcaption>' . $legend . $svg . $table . '</figure>';
	}

	/**
	 * Compact number (۱٫۲M style, Latin digits for axis legibility).
	 *
	 * @param float $v Value.
	 * @return string
	 */
	public static function compact( $v ) {
		$v = (float) $v;
		if ( $v >= 1e9 ) {
			return round( $v / 1e9, 1 ) . 'B';
		}
		if ( $v >= 1e6 ) {
			return round( $v / 1e6, 1 ) . 'M';
		}
		if ( $v >= 1e3 ) {
			return round( $v / 1e3, 1 ) . 'K';
		}
		return (string) round( $v );
	}
}
