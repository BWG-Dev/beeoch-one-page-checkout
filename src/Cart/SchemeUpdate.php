<?php
/**
 * Applies subscription plan changes made at checkout.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Cart;

use WCS_ATT_Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a plan change chosen on the checkout page.
 *
 * ## Why there is almost nothing here
 *
 * The switcher rendered by `SubscriptionOptions` is All Products for Subscriptions' own
 * template, so its radios keep that plugin's field name:
 *
 *     cart[<cart_item_key>][convert_to_sub]
 *
 * Those radios sit inside `form.checkout`, and WooCommerce's checkout.js serialises the entire
 * form into `post_data` on every refresh. So the customer's choice already arrives on the
 * request we ride — no extra round trip, no carrier fields of our own, nothing to keep in step
 * with the quantity mutation that may be travelling alongside it.
 *
 * Applying it is then one call to `WCS_ATT_Cart::update_cart_item_data()`, which reads the
 * posted value, compares it to the active plan, writes the new one and recalculates. Validating
 * the plan key, deciding what a plan costs and rebuilding the schedule all stay where they
 * belong. This class moves data into the shape that method expects and gets out of the way.
 *
 * ## The `$_POST` handoff
 *
 * `WCS_ATT_Cart::get_posted_subscription_scheme()` reads `$_POST['cart']` directly. On this
 * request the cart fields are not there — they are inside the serialised `post_data` string, so
 * `$_POST['cart']` is unset.
 *
 * The choice was between reproducing their loop against `wcsatt_data` internals, or presenting
 * the data where their code already looks for it. Reproducing it would mean owning a copy of
 * logic about recurring billing that could drift from theirs without anyone noticing — the
 * worst possible thing to have a stale copy of. So the values are placed in `$_POST['cart']`
 * for the duration of one call and the previous state is restored in a `finally`, including
 * the difference between "was unset" and "was empty".
 */
class SchemeUpdate {

	/**
	 * After MutationHandler (5), so a quantity edit in the same request lands first.
	 */
	private const PRIORITY = 6;

	/**
	 * Hook in.
	 */
	public function register(): void {
		// The plugin is optional. Without it there are no plans to switch between.
		if ( ! class_exists( 'WCS_ATT_Cart' ) ) {
			return;
		}

		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'handle' ), self::PRIORITY, 1 );
	}

	/**
	 * Apply any plan change carried by this refresh.
	 *
	 * @param string $post_data Serialised checkout form, as passed by WooCommerce.
	 */
	public function handle( $post_data ): void {
		$fields = array();
		parse_str( (string) $post_data, $fields );

		$cart = $fields['cart'] ?? null;

		if ( ! is_array( $cart ) || array() === $cart ) {
			// An ordinary refresh: no line carries a plan selection.
			return;
		}

		if ( ! $this->has_selection( $cart ) ) {
			return;
		}

		$had_cart      = array_key_exists( 'cart', $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read for restoration only; the request is already nonce-checked by WC_AJAX before this action fires.
		$previous_cart = $had_cart ? $_POST['cart'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$_POST['cart'] = $cart;

		try {
			WCS_ATT_Cart::update_cart_item_data( true );
		} catch ( \Throwable $e ) {
			$this->log( 'plan update failed: ' . $e->getMessage() );
		} finally {
			if ( $had_cart ) {
				$_POST['cart'] = $previous_cart;
			} else {
				unset( $_POST['cart'] );
			}
		}
	}

	/**
	 * Whether any line actually carries a plan selection.
	 *
	 * `cart[...]` can be present for other reasons, and handing their method a payload with no
	 * `convert_to_sub` in it would be a wasted pass over every line on every refresh.
	 *
	 * @param array<string, mixed> $cart Posted cart fields.
	 */
	private function has_selection( array $cart ): bool {
		foreach ( $cart as $line ) {
			if ( is_array( $line ) && isset( $line['convert_to_sub'] ) ) {
				return true;
			}
		}

		return false;
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
