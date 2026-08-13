<?php
/**
 * Guards the checkout fragment contract.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Asserts that every expected fragment survives each checkout refresh.
 *
 * Rationale — docs/CHECKOUT-COMPATIBILITY.md Finding 9. update_order_review returns five
 * fragments, three of them owned by third-party plugins. If a layout change removes one of
 * those selectors from the DOM, the owning plugin's UI silently stops updating: no error,
 * no warning, just a store-credit or points balance frozen at its pre-change value while
 * the totals beside it move. That is the single highest-consequence failure mode in this
 * project and the cheapest one to detect automatically.
 *
 * Runs in development only. Reports rather than throws — a failed assertion must not be
 * able to break a checkout that is otherwise working.
 */
class FragmentAssert {

	/**
	 * Fragments observed on this site, captured 2026-08-13 and corrected the same day.
	 *
	 * Ownership is recorded because when one disappears, the owner is who to look at.
	 *
	 * Note the three Side Cart entries. The initial Phase 2 audit reported five fragments,
	 * having extracted them with a pattern that assumed each fragment's HTML began with
	 * '<'. Side Cart's begin with escaped whitespace, so they were missed. This class found
	 * them on its first run — which is the argument for asserting the contract in code
	 * rather than transcribing it from a one-off capture.
	 *
	 * @var array<string, string>
	 */
	private const EXPECTED = array(
		'.woocommerce-checkout-review-order-table' => 'WooCommerce core',
		'.woocommerce-checkout-payment'            => 'WooCommerce core',
		'.acfw-store-credit-user-balance'          => 'Advanced Coupons',
		'.wpgens-points-earning-notice-fragment'   => 'WPGens Loyalty',
		'div.xoo-wsc-container'                    => 'Side Cart (XooTiX)',
		'div.xoo-wsc-sc-cont'                      => 'Side Cart (XooTiX)',
		// Ships empty on checkout, but its absence would still break Side Cart's JS.
		'div.xoo-wsc-slider'                       => 'Side Cart (XooTiX)',
	);

	/**
	 * Fragments that legitimately come and go with cart or customer state.
	 *
	 * The contract is not a fixed set. Observed 2026-08-13: the WPGens points redemption
	 * block is absent for a customer with no points balance — confirmed by a control refresh
	 * carrying no cart edit, which produced the same seven fragments. Treating these as
	 * required would generate false alarms and train us to ignore the log, which would defeat
	 * the purpose of having it.
	 *
	 * Absence is reported at notice level; a fragment moving between states is worth seeing,
	 * just not worth alarming about.
	 *
	 * @var array<string, string>
	 */
	private const CONDITIONAL = array(
		'.wpgens-points-redemption-block' => 'WPGens Loyalty — renders only with a points balance',
	);

	/**
	 * Hook in.
	 */
	public function register(): void {
		// PHP_INT_MAX: assert on what actually ships, after every other filter has run.
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'assert' ), PHP_INT_MAX );
	}

	/**
	 * Compare the outgoing fragment set against the contract.
	 *
	 * @param array<string, string> $fragments Fragments about to be returned.
	 * @return array<string, string> Unmodified.
	 */
	public function assert( $fragments ): array {
		$fragments = is_array( $fragments ) ? $fragments : array();

		$expected = self::expected();
		$missing  = array_diff_key( $expected, $fragments );

		if ( array() !== $missing ) {
			foreach ( $missing as $selector => $owner ) {
				$this->report(
					sprintf(
						'MISSING fragment "%s" (owned by %s) — that plugin\'s UI will now silently stop updating.',
						$selector,
						$owner
					)
				);
			}
		}

		// State-dependent fragments: worth seeing, not worth alarming about.
		foreach ( array_diff_key( self::CONDITIONAL, $fragments ) as $selector => $note ) {
			$this->report(
				sprintf( 'conditional fragment "%s" absent this refresh (%s).', (string) $selector, $note ),
				'notice'
			);
		}

		/*
		 * A fragment appearing that we do not know about is informational, not a failure:
		 * it means a plugin was added or activated, and the contract needs updating.
		 */
		$unexpected = array_diff_key( $fragments, $expected, self::CONDITIONAL );

		foreach ( array_keys( $unexpected ) as $selector ) {
			$this->report(
				sprintf( 'NEW fragment "%s" not in the recorded contract — worth adding if it is expected.', (string) $selector ),
				'notice'
			);
		}

		return $fragments;
	}

	/**
	 * The expected fragment set, filterable so a shim can register a new one.
	 *
	 * @return array<string, string> Selector => owner.
	 */
	public static function expected(): array {
		/**
		 * Filter the expected checkout fragment contract.
		 *
		 * @param array<string, string> $expected Selector => owning plugin.
		 */
		return (array) apply_filters( 'beeoch_opc_expected_fragments', self::EXPECTED );
	}

	/**
	 * Write to the debug log with a greppable prefix.
	 *
	 * @param string $message Message.
	 * @param string $level   'error' or 'notice'.
	 */
	private function report( string $message, string $level = 'error' ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- development instrumentation.
		error_log( sprintf( 'BEEOCH-OPC [fragment-%s] %s', $level, $message ) );
	}
}
