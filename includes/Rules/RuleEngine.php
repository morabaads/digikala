<?php
/**
 * Runs all rules, applies auto-fixes and computes the readiness score.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules;

use DKBridge\Mapping\ProductData;

/**
 * Rule engine.
 */
class RuleEngine {

	/**
	 * Score weights mirror Digikala's content score (`score_details`).
	 */
	const WEIGHTS = array(
		'category'     => 1,
		'brand'        => 1,
		'type'         => 1,
		'model'        => 1,
		'originality'  => 1,
		'division'     => 1,
		'mefa'         => 1,
		'description'  => 2,
		'advantage'    => 2,
		'disadvantage' => 2,
		'attributes'   => 4,
		'title_fa'     => 1,
		'title_en'     => 2,
		'photo'        => 6,
		'dimensions'   => 1,
	);

	/**
	 * Section labels.
	 */
	const LABELS = array(
		'category'     => 'گروه کالایی',
		'brand'        => 'برند',
		'type'         => 'نوع کالا',
		'model'        => 'مدل',
		'originality'  => 'اصالت',
		'division'     => 'زیرگروه',
		'mefa'         => 'شناسهٔ کالا',
		'description'  => 'توضیحات',
		'advantage'    => 'نقاط قوت',
		'disadvantage' => 'نقاط ضعف',
		'attributes'   => 'ویژگی‌ها',
		'title_fa'     => 'عنوان فارسی',
		'title_en'     => 'عنوان انگلیسی',
		'photo'        => 'تصاویر',
		'dimensions'   => 'ابعاد و وزن',
		'commerce'     => 'قیمت و موجودی',
		'compliance'   => 'مدارک و قوانین',
	);

	/**
	 * Rules.
	 *
	 * @var Rule[]
	 */
	private $rules;

	/**
	 * Constructor.
	 *
	 * @param Rule[] $rules Rules.
	 */
	public function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Default rule set.
	 *
	 * @param array $config Config.
	 * @return RuleEngine
	 */
	public static function default_engine( array $config ) {
		$rules = array(
			new Checks\CatalogRule( $config ),
			new Checks\TitleRule( $config ),
			new Checks\EnglishTitleRule( $config ),
			new Checks\DescriptionRule( $config ),
			new Checks\AttributeRule( $config ),
			new Checks\DimensionRule( $config ),
			new Checks\ImageRule( $config ),
			new Checks\PriceStockRule( $config ),
			new Checks\ComplianceRule( $config ),
		);
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the rule set. Add your own \DKBridge\Rules\Rule subclasses.
			 *
			 * @param Rule[] $rules  Rules.
			 * @param array  $config Config.
			 */
			$rules = apply_filters( 'dkb_rules', $rules, $config );
		}
		return new self( $rules );
	}

	/**
	 * Evaluate a product.
	 *
	 * @param ProductData $p        Product (mutated when $autofix).
	 * @param bool        $autofix  Apply fixes.
	 * @return array{issues:Issue[],changes:string[],score:array,blocking:bool}
	 */
	public function run( ProductData $p, $autofix = false ) {
		$changes = array();
		$issues  = array();
		foreach ( $this->rules as $rule ) {
			$found = $rule->check( $p );
			if ( $autofix && $found && array_filter(
				$found,
				static function ( Issue $i ) {
					return $i->fixable;
				}
			) ) {
				$changes = array_merge( $changes, $rule->fix( $p, $found ) );
				$found   = $rule->check( $p );
			}
			$issues = array_merge( $issues, $found );
		}
		// Second pass: fixes of later rules (brand → misc) can affect earlier ones (title).
		if ( $autofix && $changes ) {
			$issues = array();
			foreach ( $this->rules as $rule ) {
				$found = $rule->check( $p );
				if ( array_filter(
					$found,
					static function ( Issue $i ) {
						return $i->fixable && Issue::INFO !== $i->severity;
					}
				) ) {
					$changes = array_merge( $changes, $rule->fix( $p, $found ) );
					$found   = $rule->check( $p );
				}
				$issues = array_merge( $issues, $found );
			}
		}

		usort(
			$issues,
			static function ( Issue $a, Issue $b ) {
				$rank = array(
					Issue::ERROR   => 0,
					Issue::WARNING => 1,
					Issue::INFO    => 2,
				);
				return $rank[ $a->severity ] - $rank[ $b->severity ];
			}
		);

		$score = self::score( $p, $issues );
		return array(
			'issues'   => $issues,
			'changes'  => array_values( array_unique( $changes ) ),
			'score'    => $score,
			'blocking' => (bool) array_filter(
				$issues,
				static function ( Issue $i ) {
					return Issue::ERROR === $i->severity;
				}
			),
		);
	}

	/**
	 * Compute the readiness score.
	 *
	 * @param ProductData $p      Product.
	 * @param Issue[]     $issues Issues.
	 * @return array{percent:int,current:float,max:float,sections:array}
	 */
	public static function score( ProductData $p, array $issues ) {
		$applicable = self::WEIGHTS;
		if ( ! $p->available_product_types ) {
			unset( $applicable['type'] );
		}
		if ( ! $p->available_divisions ) {
			unset( $applicable['division'] );
		}
		if ( ! in_array( $p->mefa_type, array( 'general', 'exclusive' ), true ) ) {
			unset( $applicable['mefa'] );
		}
		if ( ! $p->attribute_defs ) {
			unset( $applicable['attributes'] );
		}

		$worst = array();
		foreach ( $issues as $issue ) {
			$s = $issue->section;
			if ( Issue::INFO === $issue->severity ) {
				continue;
			}
			if ( ! isset( $worst[ $s ] ) || Issue::ERROR === $issue->severity ) {
				$worst[ $s ] = $issue->severity;
			}
		}

		$sections = array();
		$current  = 0.0;
		$max      = 0.0;
		foreach ( $applicable as $section => $weight ) {
			$value = (float) $weight;
			if ( 'photo' === $section ) {
				$value = min( $weight, 2 * count( $p->images ) );
			}
			if ( 'attributes' === $section ) {
				$filled = 0;
				foreach ( $p->attribute_defs as $id => $def ) {
					if ( isset( $p->attributes[ $id ] ) && '' !== $p->attributes[ $id ] && array() !== $p->attributes[ $id ] ) {
						++$filled;
					}
				}
				$value = $weight * ( $filled / max( 1, count( $p->attribute_defs ) ) );
			}
			if ( isset( $worst[ $section ] ) ) {
				$value = Issue::ERROR === $worst[ $section ] ? 0.0 : min( $value, $weight / 2 );
			}
			$sections[ $section ] = array(
				'label'   => self::LABELS[ $section ],
				'current' => round( $value, 2 ),
				'max'     => $weight,
			);
			$current += $value;
			$max     += $weight;
		}
		return array(
			'percent'  => $max > 0 ? (int) round( 100 * $current / $max ) : 0,
			'current'  => round( $current, 2 ),
			'max'      => $max,
			'sections' => $sections,
		);
	}
}
