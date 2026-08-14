<?php
/**
 * Applies cart edits during the standard checkout refresh.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Cart;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the cart edit on WooCommerce's own `update_order_review` request.
 *
 * ## Why there is no custom AJAX endpoint
 *
 * The original design called for `wc-ajax=beeoch_update_cart` plus a filter re-applying the
 * BWG conditional loader's plugin-strip list to it. That is not implementable from a regular
 * plugin: `option_active_plugins` is read while WordPress loads, long before `plugins_loaded`,
 * which is exactly why the BWG loader ships as an MU plugin. A regular plugin cannot filter it.
 *
 * Rather than add a second MU plugin, we attach the mutation to `update_order_review` — an
 * action already on the loader's allow-list. Consequences, all of them good:
 *
 *   - the stripped-plugin set on a cart edit is identical to a checkout render, by
 *     construction rather than by a duplicated list that could drift;
 *   - one round trip instead of two;
 *   - fragment assembly stays entirely WooCommerce's, so the eight-fragment contract holds
 *     without us reproducing any of it;
 *   - nothing needs adding to client-owned code.
 *
 * `woocommerce_checkout_update_order_review` fires after `check_ajax_referer()` and before
 * totals are calculated (WC_AJAX::update_order_review, lines 397-405), which is precisely
 * the window a cart mutation needs.
 *
 * ## Idempotency
 *
 * The stock-protection MU plugin re-triggers `update_checkout` when it removes an item, and
 * a customer can click faster than a round trip. Every mutation therefore carries a token,
 * and a token already applied is ignored. Without this a single "+" click could apply twice.
 */
class MutationHandler {

	/**
	 * Nonce action for our own verification, layered on top of WooCommerce's.
	 */
	public const NONCE_ACTION = 'beeoch_opc_mutate';

	/**
	 * Session key holding the last applied mutation token.
	 */
	private const SESSION_TOKEN = 'beeoch_opc_last_token';

	/**
	 * Mutation performer.
	 */
	private Mutations $mutations;

	/**
	 * Constructor.
	 *
	 * @param Mutations $mutations Mutation performer.
	 */
	public function __construct( Mutations $mutations ) {
		$this->mutations = $mutations;
	}

	/**
	 * Hook in.
	 */
	public function register(): void {
		// Priority 5: before anything that reads cart contents on this action.
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'handle' ), 5, 1 );
	}

	/**
	 * Apply a cart edit if this refresh carries one.
	 *
	 * @param string $post_data Serialised checkout form, as passed by WooCommerce.
	 */
	public function handle( $post_data ): void {
		$fields = $this->parse( (string) $post_data );
		$action = (string) ( $fields['beeoch_opc_action'] ?? '' );

		if ( 'set_quantity' !== $action && 'remove_item' !== $action ) {
			// An ordinary refresh — address change, shipping choice, or the stock plugin's re-trigger.
			return;
		}

		if ( ! wp_verify_nonce( (string) ( $fields['beeoch_opc_nonce'] ?? '' ), self::NONCE_ACTION ) ) {
			$this->log( 'nonce failed' );

			return;
		}

		$token = (string) ( $fields['beeoch_opc_token'] ?? '' );

		if ( '' === $token || $this->already_applied( $token ) ) {
			// Replay: a re-triggered refresh still carrying the previous edit.
			return;
		}

		$key      = (string) ( $fields['beeoch_opc_key'] ?? '' );
		$quantity = (int) ( $fields['beeoch_opc_qty'] ?? 0 );

		if ( '' === $key ) {
			return;
		}

		$this->remember( $token );

		$result = 'remove_item' === $action
			? $this->mutations->remove_item( $key )
			: $this->mutations->set_quantity( $key, $quantity );

		/*
		 * Removing the last line empties the cart, and WooCommerce answers the NEXT refresh
		 * with "Sorry, your session has expired" — its empty-cart branch runs before anything
		 * else in WC_AJAX::update_order_review. That message is alarming and wrong: nothing
		 * expired, the customer emptied their own cart. Suppress that branch for this request
		 * and say what actually happened.
		 */
		if ( $result['ok'] && 'removed' === $result['code'] && $this->cart_is_empty() ) {
			add_filter( 'woocommerce_checkout_update_order_review_expired', '__return_false' );

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice(
					__( 'Your cart is now empty.', 'beeoch-opc' ),
					'notice'
				);
			}
		}

		if ( ! $result['ok'] ) {
			$this->log( sprintf( '%s refused: %s (key=%s qty=%d)', $action, $result['code'], $key, $quantity ) );

			/*
			 * Surface only what the customer can act on. "not_editable" and "unknown_item"
			 * mean the UI is out of step with the cart; the refresh that follows this
			 * request re-renders it correctly, which is the useful correction.
			 */
			if ( 'rejected' === $result['code'] && function_exists( 'wc_add_notice' ) ) {
				wc_add_notice(
					__( 'That quantity is not available. Your cart has been left unchanged.', 'beeoch-opc' ),
					'error'
				);
			}
		}
	}

	/**
	 * Whether the cart now holds nothing.
	 */
	private function cart_is_empty(): bool {
		return function_exists( 'WC' ) && WC()->cart && 0 === WC()->cart->get_cart_contents_count();
	}

	/**
	 * Turn the serialised form into an array.
	 *
	 * @param string $post_data Serialised form data.
	 * @return array<string, string>
	 */
	private function parse( string $post_data ): array {
		if ( '' === $post_data ) {
			return array();
		}

		$fields = array();
		parse_str( $post_data, $fields );

		return array_map(
			static fn( $value ): string => is_scalar( $value ) ? (string) $value : '',
			$fields
		);
	}

	/**
	 * Whether this token has already been applied.
	 *
	 * @param string $token Mutation token.
	 */
	private function already_applied( string $token ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		return (string) WC()->session->get( self::SESSION_TOKEN, '' ) === $token;
	}

	/**
	 * Record a token as applied.
	 *
	 * Recorded before the mutation runs, not after: if the mutation throws, a retry of the
	 * same token must not run it a second time.
	 *
	 * @param string $token Mutation token.
	 */
	private function remember( string $token ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_TOKEN, $token );
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
			error_log( 'BEEOCH-OPC [mutation] ' . $message );
		}
	}
}
