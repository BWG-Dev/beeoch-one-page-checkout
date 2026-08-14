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
			/*
			 * `wrap` opts this block into our own <details> row.
			 *
			 * The previous approach tried to drive each plugin's existing toggle and style
			 * their headers to match. That failed repeatedly, because two toggles ended up
			 * coexisting: the plugins delegate to `h3`, so our injected heading double-fired
			 * theirs; Advanced Coupons shows via a CSS class where WPGens uses inline styles,
			 * so no single technique collapsed both; and they bind after DOM-ready, so our
			 * triggers hit nothing.
			 *
			 * This approach removes their toggle from the picture entirely rather than
			 * driving it: their header is hidden and their content forced permanently
			 * visible, so their JavaScript has nothing to act on, and OUR <details> is the
			 * only thing that opens and closes. One toggle, no collisions, no timing races.
			 *
			 * Rendered server-side, so it is in the markup on first paint — no flash.
			 */
			'wrap'     => true,
			'title'    => 'Apply store credit discounts?',
			'icon'     => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
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
			'wrap'     => true,
			'title'    => 'Apply Pollen Points discount?',
			'icon'     => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
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
	 * Find a block's configuration by label.
	 *
	 * @param string $label Block label.
	 * @return array<string, mixed>
	 */
	private function spec_for( string $label ): array {
		foreach ( self::BLOCKS as $block ) {
			if ( $block['label'] === $label ) {
				return $block;
			}
		}

		return array();
	}

	/**
	 * Put a block inside our own collapsible row.
	 *
	 * `<details>` rather than a scripted accordion: it opens and closes with no JavaScript at
	 * all, is keyboard operable and announced correctly by screen readers for free, and
	 * cannot fall out of step with a plugin's own state — because the plugin no longer has
	 * any say in whether its content is visible.
	 *
	 * Collapsed by default (no `open` attribute) so the rows read as a compact stack.
	 *
	 * @param array<string, mixed> $spec   Block configuration.
	 * @param string               $markup Plugin-rendered markup.
	 */
	private function wrap_in_row( array $spec, string $markup ): string {
		$icon = '';

		if ( ! empty( $spec['icon'] ) ) {
			$icon = sprintf(
				'<span class="beeoch-opc-acc__icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">%s</svg></span>',
				$spec['icon'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG path data defined in this file.
			);
		}

		return sprintf(
			'<details class="beeoch-opc-acc" name="beeoch-opc-promo"><summary class="beeoch-opc-acc__head">%s<h3 class="beeoch-opc-acc__title">%s</h3></summary><div class="beeoch-opc-acc__body">%s</div></details>',
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from static data.
			esc_html( (string) ( $spec['title'] ?? '' ) ),
			$markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated markup, already escaped by its author.
		);
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

			$spec = $this->spec_for( $label );

			if ( ! empty( $spec['wrap'] ) ) {
				$markup = $this->wrap_in_row( $spec, $markup );
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
		 * A plain container, not a <details>.
		 *
		 * This began as a collapsible panel with its own "Discounts & rewards" heading, but
		 * each block inside is itself collapsible — so the customer faced a collapsible
		 * holding four collapsibles, and had to open two things to reach a coupon field.
		 * Dropping the outer layer leaves four uniform rows at one level, which is what the
		 * consolidation was for.
		 */
		printf(
			'<div class="beeoch-opc-promo"><div class="beeoch-opc-promo__body">%s</div></div>',
			implode( '', $sections ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from escaped parts above.
		);
	}
}
