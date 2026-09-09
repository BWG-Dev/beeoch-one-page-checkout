<?php
/**
 * My Account login/register form height, mobile only.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro's own WooCommerce "My Account" widget equalizes the login and register forms'
 * heights on init (`equalizeElementHeight()` in its `woocommerce-my-account` bundle, setting an
 * inline `height` on both `.woocommerce-form-login` and `.woocommerce-form-register` to match
 * whichever is taller — desktop-only reasoning, since the two forms sit side by side there).
 *
 * On mobile the columns stack, so the shorter (login) form is left with a large block of empty
 * space below "Lost your password?" before the taller (register) form's content begins — that
 * fixed height doesn't fit content anywhere the forms aren't side by side.
 *
 * A stylesheet `!important` overrides an inline style regardless of when that inline style was
 * set (the JS only re-runs on specific events, e.g. registration password validation — it isn't
 * resize-aware), so this doesn't need to fight the JS or hook after it; the CSS simply always
 * wins for rendering.
 *
 * Independent of the one-page-checkout flag on purpose — unrelated concern, must keep working
 * even with the flag off.
 */
class AccountFormHeight {

	/**
	 * Hook in.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Print the override, account page only.
	 */
	public function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_register_style( 'beeoch-opc-account', false, array(), BEEOCH_OPC_VERSION );
		wp_enqueue_style( 'beeoch-opc-account' );
		wp_add_inline_style(
			'beeoch-opc-account',
			'@media ( max-width: 1024px ) {' .
				'.woocommerce-form-login, .woocommerce-form-register {' .
					'height: auto !important;' .
					'min-height: auto !important;' .
				'}' .
			'}'
		);
	}
}
