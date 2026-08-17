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

		$requested = max( 0, $quantity );
		$max       = $this->max_quantity( $cart_item );
		$quantity  = $this->clamp( $requested, $max );

		if ( $quantity < 1 ) {
			// Removal is a separate operation with its own policy check. Not this method's job.
			return $this->fail( 'removal_not_supported' );
		}

		/*
		 * Whether the customer asked for more than they can have.
		 *
		 * Reported rather than absorbed. The clamp has always been here, so an over-stock
		 * request was already held to what WooCommerce allows — but silently, so asking for 200
		 * of a product with 199 in stock simply produced 199 with no explanation, and asking
		 * again produced nothing at all. Being right about the number is not the same as
		 * telling the customer what happened.
		 */
		$clamped = $requested > $quantity;

		if ( (int) ( $cart_item['quantity'] ?? 0 ) === $quantity ) {
			/*
			 * Nothing to apply — but if we got here by clamping, this is the case that most
			 * needs a message: the line is already at the limit, so the customer's click
			 * changed nothing and no refresh would otherwise explain why.
			 */
			return $this->result( 'unchanged', $quantity, $requested, $max, $clamped, $cart_item );
		}

		/*
		 * $refresh_totals = true. WooCommerce recalculates, which is what lets every
		 * pricing, coupon and points plugin respond on its own hooks.
		 */
		$updated = $cart->set_quantity( $cart_item_key, $quantity, true );

		if ( ! $updated ) {
			return $this->fail( 'rejected' );
		}

		return $this->result( 'updated', $quantity, $requested, $max, $clamped, $cart_item );
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

		return $this->result( 'removed', 0, 0, null, false, $cart_item );
	}

	/**
	 * Upper bound for this line, or null when unlimited.
	 *
	 * Delegates to the product's own max-purchase rule so stock, backorder settings and
	 * "sold individually" are applied by exactly the logic the cart page uses. WooCommerce
	 * returns -1 for no limit.
	 *
	 * @param array<string, mixed> $cart_item Cart item.
	 */
	private function max_quantity( array $cart_item ): ?int {
		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$max = $product->get_max_purchase_quantity();

		return ( is_numeric( $max ) && (int) $max > 0 ) ? (int) $max : null;
	}

	/**
	 * Hold a requested quantity inside the given bound.
	 *
	 * @param int      $quantity Requested quantity.
	 * @param int|null $max      Upper bound, or null for unlimited.
	 */
	private function clamp( int $quantity, ?int $max ): int {
		$quantity = max( 0, $quantity );

		return ( null === $max ) ? $quantity : min( $quantity, $max );
	}

	/**
	 * Build a success result.
	 *
	 * @param string               $code      Machine-readable outcome.
	 * @param int                  $quantity  Quantity actually applied.
	 * @param int                  $requested Quantity the customer asked for.
	 * @param int|null             $max       Upper bound, or null for unlimited.
	 * @param bool                 $clamped   Whether the request was reduced to fit.
	 * @param array<string, mixed> $cart_item Cart item.
	 * @return array{ok:bool, code:string, quantity:int, requested:int, max:int|null, clamped:bool, name:string}
	 */
	private function result( string $code, int $quantity, int $requested, ?int $max, bool $clamped, array $cart_item ): array {
		$product = $cart_item['data'] ?? null;

		return array(
			'ok'        => true,
			'code'      => $code,
			'quantity'  => $quantity,
			'requested' => $requested,
			'max'       => $max,
			'clamped'   => $clamped,
			// Carried so the caller can name the line in a message without a second lookup.
			'name'      => $product instanceof WC_Product ? $product->get_name() : '',
		);
	}

	/**
	 * Build a failure result.
	 *
	 * @param string $code Machine-readable reason.
	 * @return array{ok:bool, code:string, quantity:int, requested:int, max:int|null, clamped:bool, name:string}
	 */
	private function fail( string $code ): array {
		return array(
			'ok'        => false,
			'code'      => $code,
			'quantity'  => 0,
			'requested' => 0,
			'max'       => null,
			'clamped'   => false,
			'name'      => '',
		);
	}
}
