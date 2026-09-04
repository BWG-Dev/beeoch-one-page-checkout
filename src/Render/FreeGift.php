<?php
/**
 * Free gift call-out, shown open and kept current.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Render;

use Beeoch\OPC\Support\Hooks;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the free-gift offer where it can be seen, and keeps it true.
 *
 * ## The two problems
 *
 * iThemeland Free Gifts announces eligibility with `wc_add_notice()` from
 * `woocommerce_before_checkout_form`, and hides the gift picker behind a link that opens a
 * modal. Two consequences, which were the two complaints:
 *
 * 1. It is easy to miss. One line of notice text at the top of the page, with the actual
 *    choosing a click away behind "Here".
 * 2. **It goes stale, and stale here means wrong.** `woocommerce_before_checkout_form` fires
 *    only on a full page render. Reducing the cart below the qualifying total re-runs the
 *    totals but never re-runs that hook, so the page kept insisting the customer had earned a
 *    gift they no longer qualified for. Nothing corrected it short of a manual reload.
 *
 * ## The approach
 *
 * Their notice callback is detached and invoked by us instead, with the notice it produces
 * harvested rather than left in the queue. That single move fixes both:
 *
 * - eligibility, the message text and its `[popup_link]`/`[cart_link]` substitutions all stay
 *   theirs, read from the same options the client edits in the plugin's settings screen;
 * - the result becomes a block we own, in the order summary, next to the totals it depends on;
 * - and because we own it, it can be registered as an order-review fragment and re-rendered on
 *   every refresh, which is what makes it stop lying.
 *
 * The picker is borrowed the same way — `display_gifts_in_Coupon_dropdown()` is their inline
 * renderer for the cart page, found but deliberately NOT detached, since it still has work to
 * do where it is. Rendering it here is what "open by default" means: the gifts are visible and
 * choosable without opening anything.
 *
 * Every step degrades. No picker leaves the message with their "Here" link, and the modal it
 * opens is still in the footer untouched. No message leaves an empty block. A missing plugin
 * leaves nothing at all.
 */
class FreeGift {

	/**
	 * The element replaced on every refresh. Also the fragment key.
	 */
	private const SELECTOR = '.beeoch-opc-gift';

	/**
	 * The class both borrowed callbacks belong to.
	 *
	 * Their callbacks are registered as `array( $this, 'method' )`, so the class name is
	 * required to find them — `Hooks::matches()` treats an empty class as meaning a plain
	 * function and would never match an object method.
	 */
	private const THEIR_CLASS = 'iThemeland_front_order';

	/**
	 * Their eligibility notice renderer, once taken over.
	 *
	 * @var callable|null
	 */
	private $notice = null;

	/**
	 * Their inline picker renderer, borrowed but left registered.
	 *
	 * @var callable|null
	 */
	private $picker = null;

	/**
	 * Hook in.
	 */
	public function register(): void {
		// The plugin is optional. Without it there is no offer to show.
		if ( ! class_exists( self::THEIR_CLASS ) ) {
			return;
		}

		// Priority 0: the latest moment still before their own callback at 5 would have run.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'take_over' ), 0 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'render' ), 4 );
		add_filter( 'woocommerce_update_order_review_fragments', array( $this, 'fragment' ) );
	}

	/**
	 * Take their notice off the page and borrow their picker.
	 *
	 * Called from two places, and both are needed. Their display callbacks are not registered
	 * at plugin load: they appear only once the cart has been loaded and totalled, which was
	 * confirmed by probing `$wp_filter` at three points — nothing before
	 * `woocommerce_before_checkout_form`, and all of them after `calculate_totals()`.
	 *
	 * On a page render that ordering is fine, since the cart loads long before the checkout
	 * template. On an `update_order_review` request `woocommerce_before_checkout_form` never
	 * fires at all, so without the second call from `fragment()` there would be no callback to
	 * borrow and the block would render empty on every refresh — which is the exact bug this
	 * class exists to fix.
	 *
	 * Both lookups are cached, so whichever call arrives first does the work.
	 */
	public function take_over(): void {
		if ( null === $this->notice ) {
			$this->notice = Hooks::detach(
				'woocommerce_before_checkout_form',
				self::THEIR_CLASS,
				'display_gifts_click_notice_checkout_popup'
			);
		}

		if ( null === $this->picker ) {
			$this->picker = $this->find_picker();
		}
	}

	/**
	 * Find whichever inline renderer this store has configured.
	 *
	 * Their plugin hooks exactly one of these, chosen by its `position` setting:
	 *
	 *     beside_coupon → woocommerce_cart_coupon       → display_gifts_in_Coupon_dropdown()
	 *     bottom_cart   → woocommerce_after_cart_table  → display_gifts_bottom_cart()
	 *     above_cart    → woocommerce_before_cart_table → display_gifts_bottom_cart()
	 *
	 * Looking for all three rather than assuming one is the difference between working on this
	 * store and working only on a store configured the way the first one I read happened to be.
	 * The first attempt here hard-coded `beside_coupon` and found nothing, because this store
	 * does not use that position.
	 *
	 * Found, never detached: these are still wanted on the cart page, which this plugin
	 * redirects but does not remove.
	 *
	 * @return callable|null
	 */
	private function find_picker(): ?callable {
		/**
		 * Which of their layouts to render inline.
		 *
		 * 'dropdown' asks for `display_gifts_in_Coupon_dropdown()` specifically, whatever the
		 * store's own `position` and `layout` settings say. 'store' uses whichever renderer
		 * they have hooked, honouring those settings.
		 *
		 * 'store' is the default. 'dropdown' was tried first, on the reasoning that a carousel
		 * built for the full width of a cart page is the wrong shape for this column — but
		 * their dropdown renderer passes `is_child => true`, and what that produces is 38 bare
		 * `<option>` elements with no `<select>` around them. It is designed to be the inner
		 * half of a control something else supplies, so standalone it is not a picker at all.
		 * The option is kept because it becomes useful the moment that changes, but it is not
		 * something to default to.
		 *
		 * The carousel's two real problems are both solved elsewhere and neither needed a
		 * different layout: it is initialised by triggering their own `it-enhanced-carousel`
		 * event from checkout.js, and it is stopped from widening the column by §43.
		 *
		 * @param string $layout Either 'store' or 'dropdown'.
		 */
		$layout = (string) apply_filters( 'beeoch_opc_gift_layout', 'store' );

		if ( 'dropdown' === $layout ) {
			$direct = $this->their_method( 'display_gifts_in_Coupon_dropdown' );

			if ( null !== $direct ) {
				return $direct;
			}
		}

		$candidates = array(
			array( 'woocommerce_cart_coupon', 'display_gifts_in_Coupon_dropdown' ),
			array( 'woocommerce_after_cart_table', 'display_gifts_bottom_cart' ),
			array( 'woocommerce_before_cart_table', 'display_gifts_bottom_cart' ),
		);

		foreach ( $candidates as $candidate ) {
			$found = Hooks::find( $candidate[0], self::THEIR_CLASS, $candidate[1] );

			if ( null !== $found ) {
				return $found;
			}
		}

		$this->log( 'no inline picker registered — falling back to their modal link' );

		return null;
	}

	/**
	 * A method on their object, whether or not it is hooked anywhere.
	 *
	 * Their renderers are all methods on one instance, and which of them is registered depends
	 * on the store's `position` setting — `display_gifts_in_Coupon_dropdown()` is only hooked
	 * when that is `beside_coupon`, which it is not here. The instance is reached through the
	 * notice callback already taken over, so the dropdown can be rendered without the store
	 * having to be reconfigured to use it everywhere else.
	 *
	 * @param string $method Method name.
	 * @return callable|null
	 */
	private function their_method( string $method ): ?callable {
		if ( ! is_array( $this->notice ) || ! isset( $this->notice[0] ) || ! is_object( $this->notice[0] ) ) {
			return null;
		}

		$instance = $this->notice[0];

		if ( ! method_exists( $instance, $method ) || ! is_callable( array( $instance, $method ) ) ) {
			return null;
		}

		return array( $instance, $method );
	}

	/**
	 * Print the block on a full page render.
	 */
	public function render(): void {
		echo $this->wrapper(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from plugin markup below.
	}

	/**
	 * Replace the block on every checkout refresh.
	 *
	 * This is the whole of the staleness fix. WooCommerce swaps these nodes after each
	 * `update_order_review`, so the offer is re-evaluated against the cart that now exists
	 * rather than the one that existed when the page was first drawn.
	 *
	 * @param array<string, string> $fragments Fragments.
	 * @return array<string, string>
	 */
	public function fragment( $fragments ) {
		if ( ! is_array( $fragments ) ) {
			return $fragments;
		}

		/*
		 * Their callbacks are collected on `woocommerce_before_checkout_form`, which does not
		 * fire on an AJAX refresh — so on this request they have not been taken over yet.
		 */
		$this->take_over();

		$fragments[ self::SELECTOR ] = $this->wrapper();

		return $fragments;
	}

	/**
	 * The block, wrapper included.
	 *
	 * The wrapper is printed even when there is no offer. A fragment can only replace a node
	 * that exists, so a block that disappeared when the customer became ineligible could never
	 * come back when they qualified again — it would need a page reload, which is the failure
	 * this class exists to remove.
	 */
	private function wrapper(): string {
		return sprintf(
			'<div class="%s">%s</div>',
			trim( self::SELECTOR, '.' ),
			$this->inner()
		);
	}

	/**
	 * The offer itself, or an empty string when there is nothing to offer.
	 */
	private function inner(): string {
		/*
		 * Still called, still load-bearing — just no longer printed by default.
		 *
		 * Their callback reports eligibility by SIDE EFFECT: it adds a notice when this cart
		 * qualifies and does nothing when it does not. An empty message is therefore the answer
		 * "no gifts for this cart", which is what withdraws the block when the total drops. It
		 * has to keep running whether or not anyone reads it.
		 */
		$message = $this->message();

		if ( '' === $message ) {
			return '';
		}

		$picker = $this->picker_markup();

		/**
		 * Whether to print the eligibility message and its icon.
		 *
		 * Off by default. Once the picker is shown open, the message is the third statement of
		 * the same fact: the picker carries the plugin's own heading ("Your order qualifies for
		 * FREE Gifts!") and the gifts themselves are visible below it, so a line of prose and a
		 * gift icon above them only take space in the summary column.
		 *
		 * It comes back automatically when there is no picker to speak for itself — see below —
		 * so turning this on is only needed to have both at once.
		 *
		 * @param bool $show Whether to print the message.
		 */
		$show_message = (bool) apply_filters( 'beeoch_opc_gift_show_message', false );

		/*
		 * With no picker there is nothing else in the block, so the message is printed
		 * regardless of the filter. It carries their "Here" link, which opens the modal — the
		 * only remaining way to choose a gift if the inline picker could not be rendered.
		 * Suppressing it here would leave an empty box and no route to the offer at all.
		 */
		if ( '' === $picker ) {
			$show_message = true;
		}

		return sprintf(
			'<div class="beeoch-opc-gift__inner">%s%s</div>',
			$show_message ? sprintf(
				'<span class="beeoch-opc-gift__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
				</span>
				<div class="beeoch-opc-gift__body"><p class="beeoch-opc-gift__message">%s</p></div>',
				$message // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-generated, already escaped by its author.
			) : '',
			'' === $picker ? '' : sprintf( '<div class="beeoch-opc-gift__picker">%s</div>', $picker ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Run their eligibility check and take the message it produces.
	 *
	 * Their callback communicates by side effect — it adds a WooCommerce notice rather than
	 * returning anything — so it is run against a snapshot of the queue and anything it added
	 * is lifted back out. The queue is restored either way, including when their code throws.
	 *
	 * Harvesting rather than reimplementing means the qualifying rules, the wording and its
	 * `[popup_link]` and `[cart_link]` substitutions stay in the plugin, where the client edits
	 * them in its settings screen.
	 */
	private function message(): string {
		if ( ! is_callable( $this->notice ) || ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_set_notices' ) ) {
			return '';
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			// Notices need a session; without one their callback has nowhere to write.
			return '';
		}

		$before = wc_get_notices();

		try {
			call_user_func( $this->notice );
			$after = wc_get_notices();
		} catch ( \Throwable $e ) {
			$this->log( 'eligibility check failed: ' . $e->getMessage() );
			$after = $before;
		} finally {
			// Their notice must not also appear in the notice area; this block replaces it.
			wc_set_notices( $before );
		}

		$added = array_slice(
			$after['notice'] ?? array(),
			count( $before['notice'] ?? array() )
		);

		$parts = array();

		foreach ( $added as $entry ) {
			$text = is_array( $entry ) ? ( $entry['notice'] ?? '' ) : $entry;
			$text = trim( (string) $text );

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Their inline gift picker, or an empty string if it produced nothing.
	 */
	private function picker_markup(): string {
		if ( ! is_callable( $this->picker ) ) {
			return '';
		}

		try {
			return trim( Hooks::capture( $this->picker ) );
		} catch ( \Throwable $e ) {
			$this->log( 'picker failed: ' . $e->getMessage() );

			return '';
		}
	}

	/**
	 * Development logging.
	 *
	 * @param string $message Message.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'BEEOCH-OPC [gift] ' . $message );
		}
	}
}
