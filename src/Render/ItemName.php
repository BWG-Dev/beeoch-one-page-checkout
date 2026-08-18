<?php
/**
 * Thumbnail and product link on checkout line items.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Render;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Gives each order-summary line a thumbnail and a link back to the product.
 *
 * ## Why this filter, and why it must be gated
 *
 * The child theme's `checkout/review-order.php` prints the product name through
 * `woocommerce_cart_item_name` — the CART filter — where WooCommerce core would have used
 * `woocommerce_checkout_cart_item_name`. That is convenient, since cart-page decorations
 * already reach this checkout, but it means the filter is emphatically NOT checkout-only.
 * On this site the same hook is also used by:
 *
 *   - Elementor Pro's mini-cart template
 *   - Side Cart (`xoo-wsc-body.php`)
 *   - Product Bundles (`WC_PB_Display`)
 *
 * Both carts render their own thumbnails already, so filtering unconditionally would give
 * them a second one. The `$in_review` flag confines us to the checkout review table.
 *
 * `woocommerce_review_order_before_cart_contents` / `..._after_cart_contents` are the gate
 * because they live INSIDE the template. That matters more than it looks: the review table is
 * re-rendered on every `update_order_review` request, where the surrounding checkout actions
 * never fire, so a gate hung on `woocommerce_checkout_before_order_review` would have worked
 * on first paint and silently stopped working after any refresh.
 *
 * ## Deferring to the plugins that own these decisions
 *
 * The image and the URL come from `woocommerce_cart_item_thumbnail` and
 * `woocommerce_cart_item_permalink` — the same two filters WooCommerce's own cart template
 * uses. So Product Bundles suppressing a child item's image, or any plugin hiding a link for a
 * product that should not be navigated to, is honoured here for free rather than being
 * something we would have to reproduce and keep in step.
 */
class ItemName {

	/**
	 * Late, so the incoming markup already carries whatever Bundles and others contributed.
	 */
	private const PRIORITY = 100;

	/**
	 * Whether the review table is currently rendering.
	 */
	private bool $in_review = false;

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'woocommerce_review_order_before_cart_contents', array( $this, 'open' ), 0 );
		add_action( 'woocommerce_review_order_after_cart_contents', array( $this, 'close' ), 999 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'render' ), self::PRIORITY, 3 );
	}

	/**
	 * Enter the review table.
	 */
	public function open(): void {
		$this->in_review = true;
	}

	/**
	 * Leave the review table.
	 */
	public function close(): void {
		$this->in_review = false;
	}

	/**
	 * Wrap the product name with a thumbnail and a link.
	 *
	 * @param string               $name          Name markup so far, from core plus any earlier filter.
	 * @param array<string, mixed> $cart_item     Cart item.
	 * @param string               $cart_item_key Cart item key.
	 * @return string
	 */
	public function render( $name, $cart_item, $cart_item_key ): string {
		$name = (string) $name;

		if ( ! $this->in_review || ! is_array( $cart_item ) ) {
			return $name;
		}

		$product = $cart_item['data'] ?? null;

		if ( ! $product instanceof WC_Product ) {
			return $name;
		}

		$key = is_string( $cart_item_key ) ? $cart_item_key : '';
		$url = $this->permalink( $product, $cart_item, $key );

		return sprintf(
			'<span class="beeoch-opc-item">%s<span class="beeoch-opc-item__text">%s</span></span>',
			$this->media( $product, $cart_item, $key, $url ),
			$this->title( $name, $url )
		);
	}

	/**
	 * The product URL, or an empty string when this line should not link anywhere.
	 *
	 * @param WC_Product           $product   Product.
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param string               $key       Cart item key.
	 */
	private function permalink( WC_Product $product, array $cart_item, string $key ): string {
		/**
		 * Whether a catalogue-hidden product should still be linked.
		 *
		 * Default false, matching `is_visible()` — the same rule WooCommerce's cart template
		 * applies, so checkout links exactly where the cart page linked before it.
		 *
		 * This is a live question on this store rather than a theoretical one: 18 of its 29
		 * purchasable simple products are catalogue-hidden, so most lines get no link under the
		 * default. Their product pages generally still resolve, so linking them would satisfy
		 * "titles should be clickable" more completely — but hidden usually means hidden on
		 * purpose (a bundle component, a sample, a subscription-only variant), and sending
		 * customers to a page the store chose to keep out of its catalogue is a merchandising
		 * decision, not a formatting one.
		 *
		 * Flip it with:
		 *
		 *   add_filter( 'beeoch_opc_link_hidden_products', '__return_true' );
		 *
		 * @param bool                 $link      Whether to link hidden products.
		 * @param WC_Product           $product   Product.
		 * @param array<string, mixed> $cart_item Cart item.
		 */
		$link_hidden = (bool) apply_filters( 'beeoch_opc_link_hidden_products', false, $product, $cart_item );

		$url = ( $link_hidden || $product->is_visible() ) ? $product->get_permalink( $cart_item ) : '';

		/** This filter is documented in WooCommerce's cart/cart.php template. */
		return (string) apply_filters( 'woocommerce_cart_item_permalink', $url, $cart_item, $key );
	}

	/**
	 * The thumbnail, linked when there is somewhere to go.
	 *
	 * The image link is hidden from assistive technology and taken out of the tab order: it
	 * points at exactly the same place as the title link beside it, and two adjacent links to
	 * one destination is noise to anyone navigating by keyboard or screen reader.
	 *
	 * @param WC_Product           $product   Product.
	 * @param array<string, mixed> $cart_item Cart item.
	 * @param string               $key       Cart item key.
	 * @param string               $url       Product URL, possibly empty.
	 */
	private function media( WC_Product $product, array $cart_item, string $key, string $url ): string {
		/** This filter is documented in WooCommerce's cart/cart.php template. */
		$image = (string) apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image(), $cart_item, $key );

		// A plugin returning nothing means "no image for this line" — a bundle child, typically.
		if ( '' === trim( $image ) ) {
			return '';
		}

		if ( '' === $url ) {
			return sprintf( '<span class="beeoch-opc-item__media">%s</span>', $image );
		}

		return sprintf(
			'<a class="beeoch-opc-item__media" href="%s" tabindex="-1" aria-hidden="true">%s</a>',
			esc_url( $url ),
			$image
		);
	}

	/**
	 * The name, linked unless something already linked it.
	 *
	 * An anchor inside an anchor is invalid and browsers recover from it by closing the outer
	 * one early, which would break the row rather than merely look wrong. Several plugins here
	 * filter this same name, so the check is on the markup we were handed rather than on an
	 * assumption about which of them ran.
	 *
	 * @param string $name Name markup.
	 * @param string $url  Product URL, possibly empty.
	 */
	private function title( string $name, string $url ): string {
		if ( '' === $url || false !== stripos( $name, '<a ' ) ) {
			return $name;
		}

		return sprintf( '<a class="beeoch-opc-item__link" href="%s">%s</a>', esc_url( $url ), $name );
	}
}
