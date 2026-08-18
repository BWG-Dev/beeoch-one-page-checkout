<?php
/**
 * Where our controls render, and what they need to render with.
 *
 * @package Beeoch\OPC
 */

declare( strict_types=1 );

namespace Beeoch\OPC\Orchestration;

use Beeoch\OPC\Cart\ItemPolicy;
use Beeoch\OPC\Cart\MutationHandler;
use Beeoch\OPC\Cart\Mutations;
use Beeoch\OPC\Cart\SchemeUpdate;
use Beeoch\OPC\Render\ItemName;
use Beeoch\OPC\Render\Promotions;
use Beeoch\OPC\Render\QuantityControl;
use Beeoch\OPC\Render\SubscriptionOptions;
use Beeoch\OPC\Support\Flag;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the presentation and cart-editing layer.
 *
 * Only reached when the flag is active, so nothing here can affect a normal request.
 * Kept separate from the render classes themselves so that moving a block later is a
 * change to this file rather than to the thing being moved.
 */
class Layout {

	/**
	 * Policy resolver, shared by every consumer so they cannot disagree.
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
		( new ItemName() )->register();
		( new QuantityControl( $this->policy ) )->register();
		( new SubscriptionOptions() )->register();
		( new Promotions() )->register();
		( new MutationHandler( new Mutations( $this->policy ) ) )->register();
		( new SchemeUpdate() )->register();
		( new CartRedirect() )->register();

		/*
		 * Priority 20, printed in the head as normal.
		 *
		 * A previous revision moved this to the footer on the theory that the WOOF products
		 * filter's later stylesheets were overriding ours. That theory was wrong — those
		 * sheets contain no rule matching the review table, the product cell or the variation
		 * list. Reverted rather than left in place, since a footer stylesheet is worse for
		 * rendering and was justified by a diagnosis that did not hold.
		 */
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'carrier_fields' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'woocommerce_ajax_get_endpoint', array( $this, 'carry_flag_to_ajax' ), 10, 1 );
	}

	/**
	 * Front-end assets.
	 *
	 * Checkout only. Loading these anywhere else would be dead weight on every page of a
	 * store that already ships a great deal of CSS and JavaScript.
	 */
	public function enqueue(): void {
		if ( ! $this->is_checkout_render() ) {
			return;
		}

		wp_enqueue_style(
			'beeoch-opc',
			BEEOCH_OPC_URL . 'assets/css/checkout.css',
			array(),
			BEEOCH_OPC_VERSION
		);

		wp_enqueue_script(
			'beeoch-opc',
			BEEOCH_OPC_URL . 'assets/js/checkout.js',
			array( 'jquery', 'wc-checkout' ),
			BEEOCH_OPC_VERSION,
			true
		);

		wp_localize_script(
			'beeoch-opc',
			'beeochOpc',
			array(
				'version' => BEEOCH_OPC_VERSION,
				'i18n'    => array(
					/* translators: %d: number of units still in stock. */
					'stockMax' => __( 'Only %d left in stock.', 'beeoch-opc' ),
					'minOne'   => __( 'Use the × button to remove this item.', 'beeoch-opc' ),
				),
			)
		);
	}

	/**
	 * Hidden fields carrying a cart edit on the next refresh.
	 *
	 * They live inside `form.checkout` because WooCommerce's checkout.js sends
	 * `$( 'form.checkout' ).serialize()` as `post_data`, which is what our handler reads.
	 *
	 * Placed on `woocommerce_checkout_before_order_review` — inside the form but outside
	 * `#order_review`, so they survive the fragment replacement rather than being recreated
	 * mid-flight while a request is being assembled.
	 */
	public function carrier_fields(): void {
		printf(
			'<input type="hidden" id="beeoch_opc_action" name="beeoch_opc_action" value="" />
			<input type="hidden" id="beeoch_opc_key" name="beeoch_opc_key" value="" />
			<input type="hidden" id="beeoch_opc_qty" name="beeoch_opc_qty" value="" />
			<input type="hidden" id="beeoch_opc_token" name="beeoch_opc_token" value="" />
			<input type="hidden" id="beeoch_opc_nonce" name="beeoch_opc_nonce" value="%s" />',
			esc_attr( wp_create_nonce( MutationHandler::NONCE_ACTION ) )
		);
	}

	/**
	 * Keep the flag switched on across AJAX requests.
	 *
	 * WooCommerce posts the refresh to /?wc-ajax=update_order_review, a separate request that
	 * must reach the same flag verdict as the page that rendered the form — otherwise the
	 * controls render but their edits are ignored. Appending the argument to the AJAX
	 * endpoint is the least invasive way to achieve that.
	 *
	 * Worth replacing with a cookie carrier before the Comparator is used for sign-off: a
	 * query argument is visible to other plugins, and the WOOF products filter already
	 * harvests it into its own front-end state.
	 *
	 * @param string $url AJAX endpoint URL.
	 */
	public function carry_flag_to_ajax( $url ): string {
		$url = (string) $url;

		if ( Flag::MODE_FLAGGED !== Flag::mode() ) {
			return $url;
		}

		/*
		 * Concatenated deliberately — do NOT use add_query_arg() here.
		 *
		 * At this point the URL still contains WooCommerce's literal placeholder,
		 * `?wc-ajax=%%endpoint%%`, which checkout.js later swaps for the action name via a
		 * plain string replace. add_query_arg() parses and re-encodes the query string,
		 * turning `%%endpoint%%` into `%25%25endpoint%25%25`. The replace then fails to
		 * match, the request goes to a nonexistent endpoint, and WooCommerce receives page
		 * HTML instead of JSON — so `data.fragments` is undefined, the unblock loop never
		 * runs, and the order summary stays blocked forever with no error raised.
		 */
		$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';

		return $url . $separator . rawurlencode( Flag::query_arg() ) . '=1';
	}

	/**
	 * Scope hook for our stylesheet.
	 *
	 * Everything visual is namespaced under this class, so the entire restyle is contained
	 * by the flag: with the flag off the class is absent and not one rule can apply. It also
	 * keeps our specificity above Elementor's without resorting to `!important`.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();

		if ( $this->is_checkout_render() ) {
			$classes[] = 'beeoch-opc-active';
		}

		return $classes;
	}

	/**
	 * Whether this request renders the checkout for a customer.
	 */
	private function is_checkout_render(): bool {
		return function_exists( 'is_checkout' )
			&& is_checkout()
			&& ! is_order_received_page();
	}
}
