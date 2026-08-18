<?php
/**
 * Quantity stepper for checkout line items.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Render;

use Beeoch\OPC\Cart\ItemPolicy;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Renders an editable quantity control inside the checkout order-review table.
 *
 * Delivered through the `woocommerce_checkout_cart_item_quantity` filter rather than a
 * template override. review-order.php pipes its quantity markup through that filter, so we
 * need no copy of the template — which means the child theme's existing override keeps
 * working untouched, and every other plugin filtering the same rows is unaffected.
 *
 * ## Why this wraps instead of replaces
 *
 * The filter is NOT unoccupied. On this site:
 *
 *   @1   WCSG_Checkout::add_gifting_option_checkout       — subscription gifting UI
 *   @10  WC_Subscriptions_Cart::checkout_cart_item_details — recurring price, free trial and
 *        sign-up fee lines, which Subscriptions puts here precisely because the classic
 *        checkout has no separate Price column
 *
 * So the incoming $html is not merely "x 2" — for a subscription line it carries pricing the
 * customer needs in order to understand what they are agreeing to. Discarding it would delete
 * that from checkout silently. We therefore run at priority 20, after both, and keep the
 * incoming markup intact inside our wrapper. CSS hides only the core `.product-quantity`
 * element, leaving anything a plugin appended visible.
 *
 * Degradation is deliberate: if that markup ever changes shape, the worst outcome is the
 * original "x 2" appearing next to the stepper — visually untidy, not broken.
 */
class QuantityControl {

	/**
	 * Runs after Subscriptions (@1 and @10) have contributed.
	 */
	private const PRIORITY = 20;

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
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'woocommerce_checkout_cart_item_quantity', array( $this, 'render' ), self::PRIORITY, 3 );
	}

	/**
	 * Wrap the quantity markup with a stepper.
	 *
	 * @param string               $html          Markup so far, from core plus any earlier filter.
	 * @param array<string, mixed> $cart_item     Cart item.
	 * @param string               $cart_item_key Cart item key.
	 * @return string
	 */
	public function render( $html, $cart_item, $cart_item_key ): string {
		$html = (string) $html;

		if ( ! is_array( $cart_item ) || ! is_string( $cart_item_key ) || '' === $cart_item_key ) {
			return $html;
		}

		$verdict = $this->policy->for_item( $cart_item_key, $cart_item );

		/*
		 * Nothing this line permits — bundle children, renewal carts. Return the markup exactly
		 * as received so those rows are indistinguishable from today.
		 *
		 * The two permissions are checked SEPARATELY, and that distinction is the whole point.
		 * An earlier version returned here whenever the quantity was not editable, which
		 * silently took the remove button with it: a free gift is deliberately fixed at one —
		 * `editable_quantity => false` — but is removable, so it rendered with no control at
		 * all and could not be taken out of the cart from checkout. The policy had always said
		 * `removable => true`; nothing ever read it.
		 */
		if ( ! $verdict['editable_quantity'] && ! $verdict['removable'] ) {
			return $html;
		}

		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		$quantity = (int) ( $cart_item['quantity'] ?? 0 );
		$max      = $this->max_quantity( $product );
		$min      = 1;

		/*
		 * The remove control lives in the same wrapper as the stepper so the two read as one
		 * set of controls for the line. It is rendered only when ItemPolicy allows it —
		 * bundle children and renewal carts get a stepper but no remove, because removing
		 * them corrupts state their plugin owns.
		 */
		$remove = '';

		if ( $verdict['removable'] ) {
			$remove = sprintf(
				'<button type="button" class="beeoch-opc-qty__remove" data-beeoch-opc-remove="1" aria-label="%s" title="%s">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="6" y1="18" x2="18" y2="6"/></svg>
				</button>',
				esc_attr(
					sprintf(
						/* translators: %s: product name. */
						__( 'Remove %s from your order', 'beeoch-opc' ),
						$product->get_name()
					)
				),
				esc_attr__( 'Remove', 'beeoch-opc' )
			);
		}

		/*
		 * A line that may be removed but not re-counted gets the remove control alone. The
		 * quantity is still shown, by the passthrough markup that came in — for a free gift
		 * that is "× 1", which is the correct and complete statement of it.
		 */
		if ( ! $verdict['editable_quantity'] ) {
			return sprintf(
				'<span class="beeoch-opc-qty beeoch-opc-qty--fixed" data-beeoch-opc-key="%1$s" data-state="pending">
					%2$s
					<span class="beeoch-opc-qty__passthrough">%3$s</span>
				</span>',
				esc_attr( $cart_item_key ),
				$remove, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
				$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-filtered markup from core and other plugins.
			);
		}

		return sprintf(
			'<span class="beeoch-opc-qty" data-beeoch-opc-key="%1$s" data-min="%2$d" data-max="%3$s" data-state="pending">
				<button type="button" class="beeoch-opc-qty__step" data-beeoch-opc-delta="-1" aria-label="%4$s">&minus;</button>
				<input type="number" class="beeoch-opc-qty__input" value="%5$d" min="%2$d"%6$s step="1"
					inputmode="numeric" autocomplete="off" aria-label="%7$s" />
				<button type="button" class="beeoch-opc-qty__step" data-beeoch-opc-delta="1" aria-label="%8$s">+</button>
				%10$s
				<span class="beeoch-opc-qty__passthrough">%9$s</span>
			</span>',
			esc_attr( $cart_item_key ),
			$min,
			null === $max ? '' : esc_attr( (string) $max ),
			esc_attr__( 'Decrease quantity', 'beeoch-opc' ),
			$quantity,
			null === $max ? '' : sprintf( ' max="%d"', $max ),
			esc_attr(
				sprintf(
					/* translators: %s: product name. */
					__( 'Quantity for %s', 'beeoch-opc' ),
					$product->get_name()
				)
			),
			esc_attr__( 'Increase quantity', 'beeoch-opc' ),
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-filtered markup from core and other plugins; escaping it would render tags as text.
			$remove // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
		);
	}

	/**
	 * Upper bound for this line.
	 *
	 * Delegates to WooCommerce so stock, backorder settings and per-product limits are
	 * honoured by the same rules the cart page uses. Returns null when unlimited.
	 *
	 * @param WC_Product $product Product.
	 */
	private function max_quantity( WC_Product $product ): ?int {
		$max = $product->get_max_purchase_quantity();

		// WooCommerce uses -1 for "no limit".
		return ( is_numeric( $max ) && (int) $max > 0 ) ? (int) $max : null;
	}
}
