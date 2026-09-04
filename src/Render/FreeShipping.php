<?php
/**
 * Free-shipping progress bar, above the order review.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Render;

defined( 'ABSPATH' ) || exit;

/**
 * "Spend $X more for free shipping", read live from the store's own shipping rule.
 *
 * ## History
 *
 * This message used to come from a WPCode snippet, `bee_free_shipping_cart_notice()`, hooked
 * on `woocommerce_before_cart` — the classic Cart page template, which `CartRedirect` sends
 * every customer past on the way to this checkout, so it never appeared here. That snippet
 * turned out to be dead in a second way too: it lived in the `wp_snippets` table, which the
 * currently installed WPCode Lite no longer reads at all (it moved to a `wpcode` custom post
 * type some version back). Nothing on this site still calls that function.
 *
 * ## The approach
 *
 * Rather than resurrect a second, hand-typed copy of the threshold — which is exactly how it
 * went stale unnoticed before — this reads the live "Free Shipping" method configured on the
 * matching shipping zone and mirrors the same qualification test WooCommerce's own
 * `WC_Shipping_Free_Shipping::is_available()` runs, so the number on screen can never drift
 * from the rule that actually decides whether shipping is free. It becomes a standalone bar
 * above the order review, registered as a fragment so it stays true after every cart edit.
 */
class FreeShipping {

	/**
	 * The element replaced on every refresh. Also the fragment key.
	 */
	private const SELECTOR = '.beeoch-opc-shipping-bar';

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render' ), 2 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'fragment' ) );
	}

	/**
	 * Print the bar on a full page render.
	 */
	public function render(): void {
		echo $this->wrapper(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from plugin markup below.
	}

	/**
	 * Replace the bar on every checkout refresh.
	 *
	 * @param array<string, string> $fragments Fragments.
	 * @return array<string, string>
	 */
	public function fragment( $fragments ) {
		if ( ! is_array( $fragments ) ) {
			return $fragments;
		}

		$fragments[ self::SELECTOR ] = $this->wrapper();

		return $fragments;
	}

	/**
	 * The bar, wrapper included.
	 *
	 * Printed even when empty, the same reasoning as `FreeGift::wrapper()`: a fragment can only
	 * replace a node that exists, so the bar needs to stay in the DOM to be able to come back
	 * once the cart drops back under the threshold.
	 */
	private function wrapper(): string {
		return sprintf(
			'<div class="%s">%s</div>',
			trim( self::SELECTOR, '.' ),
			$this->inner() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built via wc_print_notice(), which escapes through wc_kses_notice().
		);
	}

	/**
	 * The notice markup, or empty when there is no qualifying free-shipping method, the
	 * threshold is already met, or WooCommerce has nothing to check yet.
	 */
	private function inner(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		$method = $this->find_method();

		if ( null === $method || ! in_array( $method->requires, array( 'min_amount', 'either', 'both' ), true ) ) {
			// No rule to prompt toward, or the rule turns on by coupon rather than by spend.
			return '';
		}

		$remaining = (float) $method->min_amount - $this->qualifying_total( $method );

		if ( $remaining <= 0 ) {
			return '';
		}

		$message = sprintf(
			/* translators: %s: price remaining until the order qualifies for free shipping. */
			__( 'Get free shipping if you order %s more!', 'beeoch-opc' ),
			wc_price( $remaining )
		);

		return (string) wc_print_notice( $message, 'notice', array(), true );
	}

	/**
	 * The cart total this store's rule qualifies against.
	 *
	 * Mirrors `WC_Shipping_Free_Shipping::is_available()` exactly — same subtotal, same
	 * discount handling — so this bar and the actual shipping option can never disagree about
	 * whether the cart qualifies.
	 *
	 * @param \WC_Shipping_Free_Shipping $method The store's configured method.
	 */
	private function qualifying_total( \WC_Shipping_Free_Shipping $method ): float {
		$total = (float) WC()->cart->get_displayed_subtotal();

		if ( 'no' === $method->ignore_discounts ) {
			$total -= (float) WC()->cart->get_discount_total();

			if ( WC()->cart->display_prices_including_tax() ) {
				$total -= (float) WC()->cart->get_discount_tax();
			}
		}

		return $total;
	}

	/**
	 * The Free Shipping method on whichever zone matches the cart's current package, if any.
	 *
	 * A zone can carry more than one Free Shipping instance — this store has two: one that
	 * requires a coupon (nothing to prompt toward by spending more) and one that requires a
	 * minimum amount. Only the latter kind is a candidate; `inner()` still checks `requires`
	 * itself since a store could configure just the coupon kind and nothing else to fall back
	 * to.
	 *
	 * A cart can also carry more than one shipping package (split shipments); the first
	 * package with a matching, amount-based method wins, since every zone on this store
	 * shares one threshold today and a second, disagreeing one would have no single number to
	 * show.
	 */
	private function find_method(): ?\WC_Shipping_Free_Shipping {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return null;
		}

		foreach ( WC()->cart->get_shipping_packages() as $package ) {
			$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

			if ( ! $zone ) {
				continue;
			}

			foreach ( $zone->get_shipping_methods( true ) as $candidate ) {
				if (
					$candidate instanceof \WC_Shipping_Free_Shipping
					&& in_array( $candidate->requires, array( 'min_amount', 'either', 'both' ), true )
				) {
					return $candidate;
				}
			}
		}

		return null;
	}
}
