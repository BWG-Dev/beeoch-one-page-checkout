<?php
/**
 * The consolidated Promotions panel.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Render;

use Beeoch\OPC\Support\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers the store's scattered promotional inputs into one panel.
 *
 * ## The problem this solves
 *
 * Four plugins each inject a promotional block at a different hook, so the checkout reads as
 * a pile of unrelated widgets:
 *
 *   before_checkout_form        @10  core coupon form
 *   before_checkout_form        @40  PW Gift Cards redeem form
 *   checkout_order_review       @10  WPGens points conversion notice
 *   checkout_order_review       @11  Advanced Coupons tabbed box
 *
 * No amount of styling fixes that, because the problem is arrangement. This detaches each
 * one and renders it inside a single labelled panel, in a deliberate order.
 *
 * ## Why it is safe
 *
 * - Each block is the plugin's own callback, captured and invoked verbatim. Nothing is
 *   reimplemented, so coupon rules, gift-card validation and points maths stay with their
 *   owners (PROJECT.md §2).
 * - The panel renders on `woocommerce_checkout_before_order_review`, which sits OUTSIDE
 *   `#order_review` and therefore outside the replaced fragments. That matters specifically
 *   for PW Gift Cards, whose `pwgc_bind_redeem_form` binds on cart-page events and an
 *   initial page-load call, never on `updated_checkout` (CHECKOUT-COMPATIBILITY.md Finding
 *   6). Keeping it out of a refreshed region preserves its binding without a shim.
 * - Fragment selectors are unaffected: the elements still exist in the DOM, and the fragment
 *   contract cares about existence, not position.
 * - Anything not found is skipped, so a deactivated plugin degrades to one fewer row rather
 *   than a fatal.
 */
class Promotions {

	/**
	 * Blocks to gather, in the order they should appear.
	 *
	 * Ordered by how commonly they are used, cheapest intent first: a coupon code is the
	 * most likely thing a customer arrives holding.
	 *
	 * @var array<int, array{hook:string, class:string, method:string, priority:int|null, label:string}>
	 */
	private const BLOCKS = array(
		array(
			'hook'     => 'woocommerce_before_checkout_form',
			'class'    => '',
			'method'   => 'woocommerce_checkout_coupon_form',
			'priority' => 10,
			'label'    => 'coupon',
		),
		array(
			'hook'     => 'woocommerce_checkout_order_review',
			'class'    => 'ACFWF\Models\Checkout',
			'method'   => 'display_checkout_tabbed_box',
			'priority' => 11,
			'label'    => 'advanced-coupons',
		),
		array(
			'hook'     => 'woocommerce_before_checkout_form',
			'class'    => 'PW_Gift_Cards_Redeeming',
			'method'   => 'woocommerce_before_checkout_form',
			'priority' => 40,
			'label'    => 'gift-card',
		),
		array(
			'hook'     => 'woocommerce_checkout_order_review',
			'class'    => 'WPGL_Points_Checkout',
			'method'   => 'display_points_conversion_notice',
			'priority' => 10,
			'label'    => 'points',
		),
	);

	/**
	 * Detached callbacks, keyed by label.
	 *
	 * @var array<string, callable>
	 */
	private array $blocks = array();

	/**
	 * Hook in.
	 */
	public function register(): void {
		/*
		 * Collection happens in two phases, because "when is every callback registered?"
		 * and "when has this hook already fired?" pull in opposite directions.
		 *
		 * An earlier version collected everything on `wp` and silently missed PW Gift
		 * Cards, which registers later than that — the panel rendered with three of four
		 * blocks and the gift-card form stayed where it was.
		 *
		 * Phase 1, priority 0 on `woocommerce_before_checkout_form`: the latest possible
		 * moment that is still before that hook's own callbacks run. Removing them here
		 * prevents them rendering in place.
		 *
		 * Phase 2, at render time: `woocommerce_checkout_order_review` has not fired yet, so
		 * its callbacks can be detached as late as we like — and later is safer, since every
		 * plugin has certainly registered by the time checkout is drawing.
		 */
		add_action( 'woocommerce_before_checkout_form', array( $this, 'collect_early' ), 0 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render' ), 5 );
	}

	/**
	 * Phase 1 — detach blocks attached to `woocommerce_before_checkout_form`.
	 */
	public function collect_early(): void {
		$this->collect_from( 'woocommerce_before_checkout_form' );
	}

	/**
	 * Detach every configured block registered on one hook.
	 *
	 * @param string $hook Hook to harvest.
	 */
	private function collect_from( string $hook ): void {
		foreach ( self::BLOCKS as $block ) {
			if ( $block['hook'] !== $hook || isset( $this->blocks[ $block['label'] ] ) ) {
				continue;
			}

			$callback = Hooks::detach( $block['hook'], $block['class'], $block['method'], $block['priority'] );

			if ( null !== $callback ) {
				$this->blocks[ $block['label'] ] = $callback;
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'BEEOCH-OPC [relocate] not found on %s: %s%s — it will render in its original position.',
						$block['hook'],
						'' === $block['class'] ? '' : $block['class'] . '::',
						$block['method']
					)
				);
			}
		}
	}

	/**
	 * Render the panel.
	 */
	public function render(): void {
		// Phase 2 — hooks that have not fired yet, so late detachment is safe and surer.
		foreach ( array_unique( array_column( self::BLOCKS, 'hook' ) ) as $hook ) {
			if ( 'woocommerce_before_checkout_form' !== $hook ) {
				$this->collect_from( (string) $hook );
			}
		}

		if ( array() === $this->blocks ) {
			return;
		}

		$sections = array();

		foreach ( $this->blocks as $label => $callback ) {
			$markup = Hooks::capture( $callback );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'BEEOCH-OPC [relocate] captured "%s": %d bytes', $label, strlen( $markup ) ) );
			}

			// A block with nothing to say — no points balance, say — adds only noise.
			if ( '' === trim( wp_strip_all_tags( $markup ) ) && ! str_contains( $markup, '<input' ) ) {
				continue;
			}

			$sections[] = sprintf(
				'<div class="beeoch-opc-promo__item beeoch-opc-promo__item--%s">%s</div>',
				esc_attr( $label ),
				$markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated markup, already escaped by its author.
			);
		}

		if ( array() === $sections ) {
			return;
		}

		/*
		 * <details> rather than a JavaScript accordion: keyboard accessible and screen-reader
		 * friendly for free, and it keeps working if our script fails to load. Open by
		 * default is deliberate — a collapsed promo panel on a store with points, gift cards
		 * and store credit hides the things regular customers came to use.
		 */
		printf(
			'<details class="beeoch-opc-promo" open>
				<summary class="beeoch-opc-promo__summary">%s</summary>
				<div class="beeoch-opc-promo__body">%s</div>
			</details>',
			esc_html__( 'Discounts &amp; rewards', 'beeoch-opc' ),
			implode( '', $sections ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts above.
		);
	}
}
