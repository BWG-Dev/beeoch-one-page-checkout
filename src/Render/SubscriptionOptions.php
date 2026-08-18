<?php
/**
 * Subscription plan switcher on checkout line items.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Render;

use WC_Product;
use WCS_ATT_Display_Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Brings All Products for Subscriptions' per-line plan switcher to checkout.
 *
 * The cart page lets a customer move a line between a one-off purchase and a subscription, and
 * between subscription frequencies. Checkout did not, so anyone arriving from a product page
 * had no way to change their mind without going back.
 *
 * ## Nothing here is reimplemented
 *
 * The switcher already exists and already works. `WCS_ATT_Display_Cart::show_cart_item_subscription_options()`
 * builds it — every option label, every price string, every frequency — and is attached to
 * `woocommerce_cart_item_price` at priority 1000. The checkout template simply never calls that
 * filter; it uses `woocommerce_cart_item_subtotal` for the total column. So the feature was not
 * missing, it was unreachable.
 *
 * We call their method and place its output. Prices, plan descriptions and which options exist
 * for a given product all remain entirely theirs — none of that is duplicated here, which
 * matters more for this feature than for any other in this plugin, because it decides what a
 * customer is agreeing to pay every month.
 *
 * ## The `is_cart()` guard
 *
 * Their method starts with:
 *
 *     if ( ! is_cart() || $is_mini_cart ) { return $price; }
 *
 * `is_cart()` ends in `apply_filters( 'woocommerce_is_cart', false )`, so it can be answered
 * truthfully for the length of one call. Two deliberate choices keep that window as small as it
 * can be:
 *
 * 1. Their static method is called DIRECTLY rather than by re-running the
 *    `woocommerce_cart_item_price` filter. Running the filter would execute every other
 *    callback on it as well, with `is_cart()` lying to all of them.
 * 2. The filter is added immediately before the call and removed immediately after, in a
 *    `finally`, so an exception inside their code cannot leave the site believing every page
 *    is the cart.
 */
class SubscriptionOptions {

	/**
	 * After QuantityControl (20), so the switcher sits below the stepper.
	 */
	private const PRIORITY = 30;

	/**
	 * Hook in.
	 */
	public function register(): void {
		// The plugin is optional. Without it this feature simply does not exist.
		if ( ! class_exists( 'WCS_ATT_Display_Cart' ) ) {
			return;
		}

		add_filter( 'woocommerce_checkout_cart_item_quantity', array( $this, 'render' ), self::PRIORITY, 3 );
	}

	/**
	 * Append the plan switcher to a line's controls.
	 *
	 * `woocommerce_checkout_cart_item_quantity` is a checkout-only filter, so unlike
	 * `ItemName` this needs no gate against the mini-cart.
	 *
	 * @param string               $html          Markup so far, including our quantity stepper.
	 * @param array<string, mixed> $cart_item     Cart item.
	 * @param string               $cart_item_key Cart item key.
	 * @return string
	 */
	public function render( $html, $cart_item, $cart_item_key ): string {
		$html = (string) $html;

		if ( ! is_array( $cart_item ) || ! is_string( $cart_item_key ) || '' === $cart_item_key ) {
			return $html;
		}

		// Only items the plugin is tracking can have a plan.
		if ( empty( $cart_item['wcsatt_data'] ) ) {
			return $html;
		}

		$options = $this->options_markup( $cart_item, $cart_item_key );

		if ( '' === $options ) {
			return $html;
		}

		return $html . sprintf(
			'<span class="beeoch-opc-plan" data-beeoch-opc-plan="%s">%s</span>',
			esc_attr( $cart_item_key ),
			$options // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated markup, escaped by its own template.
		);
	}

	/**
	 * Ask the plugin for this line's plan options.
	 *
	 * @param array<string, mixed> $cart_item     Cart item.
	 * @param string               $cart_item_key Cart item key.
	 */
	private function options_markup( array $cart_item, string $cart_item_key ): string {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}

		/*
		 * The price is passed in because their method uses it as the description of whichever
		 * option is currently active. Passing an empty string would render the selected plan
		 * with no price against it — the one option the customer most needs to see priced.
		 */
		$price = (string) WC()->cart->get_product_price( $product );

		add_filter( 'woocommerce_is_cart', '__return_true' );

		try {
			$output = (string) WCS_ATT_Display_Cart::show_cart_item_subscription_options( $price, $cart_item, $cart_item_key );
		} catch ( \Throwable $e ) {
			$this->log( sprintf( 'plan options failed for %s: %s', $cart_item_key, $e->getMessage() ) );

			$output = '';
		} finally {
			remove_filter( 'woocommerce_is_cart', '__return_true' );
		}

		return $this->extract_options( $output );
	}

	/**
	 * Take the options list out of whatever their method returned.
	 *
	 * The return value has two shapes. When a subscription plan overrides the price it is the
	 * options list alone; otherwise it is the price followed by the options list. Rather than
	 * guess which case applies — and risk printing the price a second time, since this checkout
	 * already shows it in the total column — the list is extracted by its own wrapper.
	 *
	 * Anything without that wrapper means their method declined to offer options for this line:
	 * a forced subscription, a single plan with no alternative, or a product type it does not
	 * support. All of those correctly produce no switcher.
	 *
	 * @param string $output Their return value.
	 */
	private function extract_options( string $output ): string {
		$start = strpos( $output, '<ul class="wcsatt-options' );

		if ( false === $start ) {
			return '';
		}

		$end = strrpos( $output, '</ul>' );

		if ( false === $end || $end < $start ) {
			return '';
		}

		return substr( $output, $start, $end - $start + strlen( '</ul>' ) );
	}

	/**
	 * Development logging.
	 *
	 * @param string $message Message.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'BEEOCH-OPC [plan] ' . $message );
		}
	}
}
