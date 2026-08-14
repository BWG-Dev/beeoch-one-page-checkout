<?php
/**
 * Cart mutations.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Cart;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * A thin wrapper over WC()->cart. Deliberately thin.
 *
 * Everything here delegates to WooCommerce's own cart API rather than writing to cart
 * contents directly, because that API fires the hooks other plugins depend on. In
 * particular `set_quantity()` fires `woocommerce_after_cart_item_quantity_update`, which
 * Product Bundles uses (`WC_PB_Cart::update_quantity_in_cart`) to propagate a container's
 * quantity to its children, and WPGens uses to recalculate points. Touching
 * `WC()->cart->cart_contents` directly would skip both and desynchronise the cart.
 *
 * This class validates and delegates. It does not calculate anything.
 */
class Mutations {

	/**
	 * Policy resolver.
	 */
	private ItemPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param ItemPolicy $policy Policy resolver.
	 */
	public function __construct( ItemPolicy $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Set a line's quantity.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $quantity      Requested quantity.
	 * @return array{ok:bool, code:string, quantity:int}
	 */
	public function set_quantity( string $cart_item_key, int $quantity ): array {
		$cart = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart : null;

		if ( null === $cart ) {
			return $this->fail( 'no_cart' );
		}

		$cart_item = $cart->get_cart_item( $cart_item_key );

		if ( ! is_array( $cart_item ) || array() === $cart_item ) {
			// Stale key: the line was removed in another tab, or by the stock-protection MU plugin.
			return $this->fail( 'unknown_item' );
		}

		if ( ! $this->policy->for_item( $cart_item_key, $cart_item )['editable_quantity'] ) {
			// Enforced server-side as well as in the UI — the client is not trusted.
			return $this->fail( 'not_editable' );
		}

		$quantity = $this->clamp( $quantity, $cart_item );

		if ( $quantity < 1 ) {
			// Removal is a separate operation with its own policy check. Not this method's job.
			return $this->fail( 'removal_not_supported' );
		}

		if ( (int) ( $cart_item['quantity'] ?? 0 ) === $quantity ) {
			return array(
				'ok'       => true,
				'code'     => 'unchanged',
				'quantity' => $quantity,
			);
		}

		/*
		 * $refresh_totals = true. WooCommerce recalculates, which is what lets every
		 * pricing, coupon and points plugin respond on its own hooks.
		 */
		$updated = $cart->set_quantity( $cart_item_key, $quantity, true );

		if ( ! $updated ) {
			return $this->fail( 'rejected' );
		}

		return array(
			'ok'       => true,
			'code'     => 'updated',
			'quantity' => $quantity,
		);
	}

	/**
	 * Remove a line from the cart.
	 *
	 * Delegates to `WC()->cart->remove_cart_item()` for the same reason `set_quantity()` is
	 * used above: it fires `woocommerce_cart_item_removed` and friends, which Product Bundles
	 * relies on to take a container's children with it, and coupon and points plugins rely on
	 * to recalculate. Unsetting the cart contents directly would skip all of that.
	 *
	 * @param string $cart_item_key Cart item key.
	 * @return array{ok:bool, code:string, quantity:int}
	 */
	public function remove_item( string $cart_item_key ): array {
		$cart = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart : null;

		if ( null === $cart ) {
			return $this->fail( 'no_cart' );
		}

		$cart_item = $cart->get_cart_item( $cart_item_key );

		if ( ! is_array( $cart_item ) || array() === $cart_item ) {
			// Already gone — a second click, or another tab got there first.
			return $this->fail( 'unknown_item' );
		}

		if ( ! $this->policy->for_item( $cart_item_key, $cart_item )['removable'] ) {
			// Bundle children and renewal carts, enforced server-side as well as in the UI.
			return $this->fail( 'not_removable' );
		}

		if ( ! $cart->remove_cart_item( $cart_item_key ) ) {
			return $this->fail( 'rejected' );
		}

		$cart->calculate_totals();

		return array(
			'ok'       => true,
			'code'     => 'removed',
			'quantity' => 0,
		);
	}

	/**
	 * Hold the requested quantity inside what WooCommerce would allow.
	 *
	 * Uses the product's own max-purchase rule so stock, backorder settings and any
	 * per-product limit are applied by the same logic the cart page uses.
	 *
	 * @param int                  $quantity  Requested quantity.
	 * @param array<string, mixed> $cart_item Cart item.
	 */
	private function clamp( int $quantity, array $cart_item ): int {
		$quantity = max( 0, $quantity );
		$product  = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return $quantity;
		}

		$max = $product->get_max_purchase_quantity();

		if ( is_numeric( $max ) && (int) $max > 0 ) {
			$quantity = min( $quantity, (int) $max );
		}

		return $quantity;
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $code Machine-readable reason.
	 * @return array{ok:bool, code:string, quantity:int}
	 */
	private function fail( string $code ): array {
		return array(
			'ok'       => false,
			'code'     => $code,
			'quantity' => 0,
		);
	}
}
