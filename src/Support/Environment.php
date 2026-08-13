<?php
/**
 * Dependency and environment guards.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether it is safe for this plugin to run at all.
 *
 * The rule is refuse-or-run, never half-run: a partially booted checkout orchestrator
 * is worse than an absent one, because it can relocate a block and then fail to render it.
 */
class Environment {

	/**
	 * Minimum WooCommerce version.
	 *
	 * The hook and template contract this plugin depends on was mapped against 11.0.
	 * See docs/CHECKOUT-HOOK-MAP.md.
	 */
	private const MIN_WOOCOMMERCE = '11.0';

	/**
	 * Reasons the environment was rejected.
	 *
	 * @var array<int, string>
	 */
	private array $failures = array();

	/**
	 * Run every hard requirement.
	 */
	public function is_satisfied(): bool {
		$this->failures = array();

		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$this->failures[] = sprintf(
				/* translators: %s: current PHP version. */
				__( 'PHP 8.1 or newer is required. This server runs %s.', 'beeoch-opc' ),
				PHP_VERSION
			);
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->failures[] = __( 'WooCommerce is not active.', 'beeoch-opc' );

			// Nothing below can be evaluated without WooCommerce.
			return array() === $this->failures;
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WOOCOMMERCE, '<' ) ) {
			$this->failures[] = sprintf(
				/* translators: 1: required version, 2: installed version. */
				__( 'WooCommerce %1$s or newer is required. This site runs %2$s.', 'beeoch-opc' ),
				self::MIN_WOOCOMMERCE,
				WC_VERSION
			);
		}

		if ( $this->is_blocks_checkout() ) {
			$this->failures[] = __(
				'The Checkout page uses the WooCommerce Checkout block. This plugin orchestrates the classic checkout only.',
				'beeoch-opc'
			);
		}

		return array() === $this->failures;
	}

	/**
	 * Whether the configured checkout page is built with the Checkout block.
	 *
	 * Audited 2026-08-13: this site is classic, rendered by the Elementor Pro widget which
	 * calls the standard WooCommerce templates. The check exists so that a future migration
	 * to Blocks disables this plugin loudly instead of producing a subtly broken checkout.
	 */
	private function is_blocks_checkout(): bool {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return false;
		}

		$page_id = wc_get_page_id( 'checkout' );

		if ( $page_id <= 0 ) {
			return false;
		}

		return has_block( 'woocommerce/checkout', $page_id );
	}

	/**
	 * Deactivate and explain, rather than run in a degraded state.
	 */
	public function handle_unsatisfied(): void {
		$failures = $this->failures;

		add_action(
			'admin_notices',
			static function () use ( $failures ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				printf(
					'<div class="notice notice-error"><p><strong>%s</strong></p><ul style="list-style:disc;margin-left:20px">%s</ul></div>',
					esc_html__( 'BEE-OCH One Page Checkout could not start:', 'beeoch-opc' ),
					implode(
						'',
						array_map(
							static fn( string $failure ): string => '<li>' . esc_html( $failure ) . '</li>',
							$failures
						)
					)
				);
			}
		);
	}
}
