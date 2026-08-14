<?php
/**
 * Retires the cart page in favour of checkout.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Orchestration;

defined( 'ABSPATH' ) || exit;

/**
 * Makes checkout the only cart surface.
 *
 * Three parts, and all three are needed:
 *
 * 1. `woocommerce_get_cart_url` — re-points every caller of `wc_get_cart_url()` at checkout.
 *    Side Cart, the mini-cart and the Elementor cart widget all use it, so most links are
 *    corrected at source with no redirect hop and no link-rewriting exercise.
 *
 * 2. A `template_redirect` guard — catches direct hits and hardcoded links the filter cannot
 *    reach.
 *
 * 3. `woocommerce_checkout_redirect_empty_cart` → false. NOT optional: WooCommerce redirects
 *    checkout to the cart page when the cart is empty, so adding a cart → checkout redirect
 *    without this produces an infinite loop the moment a customer empties their cart. Part 3
 *    is what makes parts 1 and 2 safe.
 */
class CartRedirect {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_cart_url', array( $this, 'cart_url' ) );
		add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false' );
		add_action( 'template_redirect', array( $this, 'redirect' ), 5 );
		add_action( 'woocommerce_before_checkout_form_cart_notices', array( $this, 'empty_notice' ) );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'empty_notice' ) );
	}

	/**
	 * Point every `wc_get_cart_url()` caller at checkout.
	 *
	 * @param string $url Cart URL.
	 */
	public function cart_url( $url ): string {
		$checkout = wc_get_checkout_url();

		return $checkout ? $checkout : (string) $url;
	}

	/**
	 * Send direct visits to the cart page on to checkout.
	 */
	public function redirect(): void {
		if ( ! $this->should_redirect() ) {
			return;
		}

		wp_safe_redirect( wc_get_checkout_url(), 302 );
		exit;
	}

	/**
	 * Whether this request should be redirected.
	 *
	 * Deliberately narrow. A redirect that fires on the wrong request is far more damaging
	 * than one that fails to fire, so every branch here excludes rather than includes.
	 */
	private function should_redirect(): bool {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return false;
		}

		/*
		 * Never interfere with a request that is not a plain page view: AJAX and REST carry
		 * cart operations, and WooCommerce's own endpoints (order-pay, order-received) live
		 * under the cart/checkout pages.
		 */
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return false;
		}

		if ( ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- presence check only, nothing is read.
			return false;
		}

		/*
		 * Administrators are NOT exempt.
		 *
		 * An earlier version let anyone with `manage_woocommerce` through to the real cart
		 * page, on the reasoning that the team would want it for comparison and support. That
		 * is backwards for a staging demo: the person showing the client is an administrator,
		 * so they were the one person who never saw the redirect working.
		 *
		 * To reach the cart page deliberately, disable this from a snippet or wp-cli:
		 *
		 *   add_filter( 'beeoch_opc_redirect_cart', '__return_false' );
		 *
		 * or append ?beeoch_no_redirect=1, which is capability-gated below.
		 */
		if (
			! empty( $_GET['beeoch_no_redirect'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only escape hatch, capability-gated.
			&& current_user_can( 'manage_woocommerce' )
		) {
			return false;
		}

		/**
		 * Filter whether a cart-page request is redirected to checkout.
		 *
		 * @param bool $redirect Whether to redirect.
		 */
		return (bool) apply_filters( 'beeoch_opc_redirect_cart', true );
	}

	/**
	 * Say something useful when checkout is reached with nothing in the cart.
	 *
	 * With the empty-cart redirect disabled, checkout renders its empty state instead of
	 * bouncing to the cart page — which would otherwise be a blank form with no explanation.
	 */
	public function empty_notice(): void {
		static $shown = false;

		if ( $shown || ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->is_empty() ) {
			return;
		}

		$shown = true;

		printf(
			'<div class="woocommerce-info beeoch-opc-empty">%s <a class="button wc-backward" href="%s">%s</a></div>',
			esc_html__( 'Your cart is empty.', 'beeoch-opc' ),
			esc_url( wc_get_page_permalink( 'shop' ) ),
			esc_html__( 'Return to shop', 'beeoch-opc' )
		);
	}
}
