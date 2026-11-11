<?php
/**
 * Rendering tests for dependency-light admin helpers.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Admin\Charts;
use DKBridge\Admin\Rulebook;
use PHPUnit\Framework\TestCase;

class AdminRenderTest extends TestCase {

	public function test_line_chart_has_legend_hover_targets_and_table() {
		$html = Charts::lines( array( '2026-11-01', '2026-11-02', '2026-11-03' ), array( 'دیجی‌کالا' => array( 10, 20, 30 ), 'ووکامرس' => array( 5, 0, 7 ) ), 'درآمد', 'ریال' );
		$this->assertSame( 2, substr_count( $html, '<polyline' ) );
		$this->assertSame( 3, substr_count( $html, 'class="dkb-hit"' ) );
		$this->assertStringContainsString( 'dkb-legend', $html );
		$this->assertStringContainsString( '<details class="dkb-table-view">', $html );
		$this->assertStringContainsString( '#2a78d6', $html );
		$this->assertStringContainsString( '#eb6834', $html );
	}

	public function test_sparkline_and_degenerate_input() {
		$this->assertStringContainsString( '<polyline', Charts::sparkline( array( 1, 3, 2 ), 'x' ) );
		$this->assertSame( '', Charts::sparkline( array( 1 ), 'x' ) );
		$this->assertSame( '', Charts::lines( array( '2026-11-01' ), array( 'a' => array( 1 ) ), 't' ) );
		$this->assertSame( '1.5M', Charts::compact( 1500000 ) );
	}

	public function test_rulebook_rules_have_sources_and_badges() {
		$sections = Rulebook::sections();
		$this->assertGreaterThanOrEqual( 8, count( $sections ) );
		foreach ( $sections as $rules ) {
			foreach ( $rules as $rule ) {
				$this->assertNotEmpty( $rule[0] );
				$this->assertStringStartsWith( 'https://', $rule[1] );
				$this->assertContains( $rule[2], array( 'خودکار', 'هشدار', 'راهنما' ) );
			}
		}
	}
}
