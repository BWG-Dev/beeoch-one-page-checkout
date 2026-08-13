<?php
/**
 * Per-line-item editing rules.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, for one cart line: may the quantity change, may it be removed, and if not why not.
 *
 * The UI renders from this verdict rather than assuming a uniform row. That is not a nicety —
 * docs/CHECKOUT-COMPATIBILITY.md Finding 5: exposing a quantity input and a remove link on
 * every line corrupts Product Bundles, because bundled children derive their quantity from
 * their container. Editing a child directly desynchronises the bundle in a way that survives
 * to order creation.
 *
 * Detection uses each plugin's own cart-item data rather than product-type guesses, since the
 * relationship lives in the cart item, not the product.
 */
class ItemPolicy {

	public const REASON_BUNDLE_CHILD  = 'bundle_child';
	public const REASON_RENEWAL       = 'renewal';
	public const REASON_FREE_GIFT     = 'free_gift';
	public const REASON_COMPOSITE     = 'composite_child';
	public const REASON_FILTERED      = 'filtered';

	/**
	 * Evaluate one cart line.
	 *
	 * @param string               $cart_item_key Cart item key.
	 * @param array<string, mixed> $cart_item     Cart item.
	 * @return array{editable_quantity:bool, removable:bool, reason:string, label:string}
	 */
	public function for_item( string $cart_item_key, array $cart_item ): array {
		$verdict = $this->evaluate( $cart_item );

		/**
		 * Filter the editing policy for a cart line.
		 *
		 * A new line type should be a small addition here rather than a rewrite of the UI.
		 *
		 * @param array                $verdict       Policy verdict.
		 * @param array<string, mixed> $cart_item     Cart item.
		 * @param string               $cart_item_key Cart item key.
		 */
		$verdict = (array) apply_filters( 'beeoch_opc_item_policy', $verdict, $cart_item, $cart_item_key );

		return array(
			'editable_quantity' => (bool) ( $verdict['editable_quantity'] ?? false ),
			'removable'         => (bool) ( $verdict['removable'] ?? false ),
			'reason'            => (string) ( $verdict['reason'] ?? '' ),
			'label'             => (string) ( $verdict['label'] ?? '' ),
		);
	}

	/**
	 * The default rules.
	 *
	 * @param array<string, mixed> $cart_item Cart item.
	 * @return array{editable_quantity:bool, removable:bool, reason:string, label:string}
	 */
	private function evaluate( array $cart_item ): array {
		/*
		 * A renewal, resubscribe or switch cart is owned end-to-end by WooCommerce
		 * Subscriptions — its contents mirror an existing subscription, so editing any
		 * line here means editing the subscription, which is not this plugin's job.
		 */
		if ( $this->is_subscription_managed_cart() ) {
			return $this->deny(
				self::REASON_RENEWAL,
				__( 'This cart renews an existing subscription and cannot be edited here.', 'beeoch-opc' )
			);
		}

		/*
		 * Product Bundles: a child carries a reference to its container. Its quantity is
		 * container quantity x per-bundle quantity, maintained by WC_PB_Cart on
		 * woocommerce_after_cart_item_quantity_update. Editing the container is safe and
		 * propagates automatically; editing a child is not.
		 */
		if ( ! empty( $cart_item['bundled_by'] ) ) {
			return $this->deny(
				self::REASON_BUNDLE_CHILD,
				__( 'Part of a bundle — change the bundle quantity instead.', 'beeoch-opc' )
			);
		}

		// Composite Products uses the same shape, should it ever be enabled.
		if ( ! empty( $cart_item['composite_parent'] ) ) {
			return $this->deny(
				self::REASON_COMPOSITE,
				__( 'Part of a composite product.', 'beeoch-opc' )
			);
		}

		/*
		 * Free gifts are added by eligibility rules, and iThemeland recomputes those on
		 * every totals calculation. Letting the quantity be edited invites a fight with
		 * the plugin; whether removal should stick is an open product decision
		 * (PLUGIN-ARCHITECTURE.md §13.2), so removal is left on and the decision is
		 * expressed in one place.
		 */
		if ( $this->is_free_gift( $cart_item ) ) {
			return array(
				'editable_quantity' => false,
				'removable'         => true,
				'reason'            => self::REASON_FREE_GIFT,
				'label'             => __( 'Free gift.', 'beeoch-opc' ),
			);
		}

		return array(
			'editable_quantity' => true,
			'removable'         => true,
			'reason'            => '',
			'label'             => '',
		);
	}

	/**
	 * Whether the cart is a Subscriptions-managed renewal/resubscribe/switch cart.
	 */
	private function is_subscription_managed_cart(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			foreach ( array( 'subscription_renewal', 'subscription_resubscribe', 'subscription_switch' ) as $marker ) {
				if ( ! empty( $item[ $marker ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether this line was added as a free gift.
	 *
	 * iThemeland marks its lines in cart item data. The key is checked defensively because
	 * it is not a documented public contract and may change between plugin versions —
	 * if it does, the line simply becomes normally editable rather than erroring.
	 *
	 * @param array<string, mixed> $cart_item Cart item.
	 */
	private function is_free_gift( array $cart_item ): bool {
		foreach ( array( 'it_free_gift', 'itfg_gift', 'free_gift', '_it_gift_item' ) as $key ) {
			if ( ! empty( $cart_item[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a fully-denied verdict.
	 *
	 * @param string $reason Machine reason.
	 * @param string $label  Customer-facing explanation.
	 * @return array{editable_quantity:bool, removable:bool, reason:string, label:string}
	 */
	private function deny( string $reason, string $label ): array {
		return array(
			'editable_quantity' => false,
			'removable'         => false,
			'reason'            => $reason,
			'label'             => $label,
		);
	}

	/**
	 * Evaluate the whole cart. Used by diagnostics and, later, by the renderer.
	 *
	 * @return array<string, array{editable_quantity:bool, removable:bool, reason:string, label:string}>
	 */
	public function for_cart(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$verdicts = array();

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$verdicts[ (string) $key ] = $this->for_item( (string) $key, (array) $item );
		}

		return $verdicts;
	}
}
