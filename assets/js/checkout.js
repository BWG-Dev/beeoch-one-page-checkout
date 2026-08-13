/**
 * One Page Checkout — cart editing on the checkout page.
 *
 * The edit rides on WooCommerce's own update_order_review request: we write the intent into
 * hidden fields inside form.checkout, then trigger update_checkout. WooCommerce serialises
 * the form into post_data, our PHP reads the fields, applies the change, and WooCommerce
 * renders all eight fragments as usual. We assemble nothing ourselves.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.beeochOpc === 'undefined' ) {
		return;
	}

	var settings = window.beeochOpc;
	var DEBOUNCE_MS = 350;

	var pending = null;   // Coalesced edit awaiting dispatch.
	var timer = null;
	var inFlight = false;

	/**
	 * Hidden carrier fields living inside form.checkout.
	 */
	function fields() {
		return {
			action: $( '#beeoch_opc_action' ),
			key: $( '#beeoch_opc_key' ),
			qty: $( '#beeoch_opc_qty' ),
			token: $( '#beeoch_opc_token' )
		};
	}

	function clearFields() {
		var f = fields();
		f.action.val( '' );
		f.key.val( '' );
		f.qty.val( '' );
		f.token.val( '' );
	}

	/**
	 * A token unique to one customer intent, so a replayed refresh cannot apply it twice.
	 *
	 * The stock-protection MU plugin re-triggers update_checkout after removing an item; the
	 * carrier fields may still be populated at that moment. The server ignores a token it has
	 * already seen.
	 */
	function newToken() {
		return Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	/**
	 * Send the coalesced edit.
	 */
	function dispatch() {
		if ( ! pending || inFlight ) {
			return;
		}

		var f = fields();

		f.action.val( 'set_quantity' );
		f.key.val( pending.key );
		f.qty.val( String( pending.quantity ) );
		f.token.val( newToken() );

		pending = null;
		inFlight = true;

		$( document.body ).trigger( 'update_checkout' );
	}

	/**
	 * Queue an edit, coalescing rapid clicks on the same row into one request.
	 *
	 * @param {string} key      Cart item key.
	 * @param {number} quantity Desired quantity.
	 */
	function queue( key, quantity ) {
		pending = { key: key, quantity: quantity };

		window.clearTimeout( timer );
		timer = window.setTimeout( dispatch, DEBOUNCE_MS );
	}

	/**
	 * Read a control's bounds and hold a value inside them.
	 */
	function clamp( $control, value ) {
		var min = parseInt( $control.attr( 'data-min' ), 10 );
		var max = parseInt( $control.attr( 'data-max' ), 10 );

		if ( isNaN( value ) ) {
			return isNaN( min ) ? 1 : min;
		}

		if ( ! isNaN( min ) && value < min ) {
			value = min;
		}

		if ( ! isNaN( max ) && value > max ) {
			value = max;
		}

		return value;
	}

	/**
	 * Enable controls and wire them up. Runs on load and after every refresh, because
	 * the review table is replaced wholesale each time.
	 */
	function bind() {
		$( '.beeoch-opc-qty' ).each( function () {
			var $control = $( this );

			if ( $control.attr( 'data-state' ) === 'busy' ) {
				return;
			}

			$control.attr( 'data-state', 'ready' );
		} );
	}

	$( document.body ).on( 'click', '.beeoch-opc-qty__step', function ( event ) {
		event.preventDefault();

		var $button = $( this );
		var $control = $button.closest( '.beeoch-opc-qty' );

		if ( $control.attr( 'data-state' ) === 'busy' ) {
			return;
		}

		var $input = $control.find( '.beeoch-opc-qty__input' );
		var delta = parseInt( $button.attr( 'data-beeoch-opc-delta' ), 10 ) || 0;
		var next = clamp( $control, ( parseInt( $input.val(), 10 ) || 0 ) + delta );

		if ( next === ( parseInt( $input.val(), 10 ) || 0 ) ) {
			return;
		}

		// Optimistic: the number moves immediately, totals catch up on the refresh.
		$input.val( next );
		$control.attr( 'data-state', 'busy' );

		queue( $control.attr( 'data-beeoch-opc-key' ), next );
	} );

	$( document.body ).on( 'change', '.beeoch-opc-qty__input', function () {
		var $input = $( this );
		var $control = $input.closest( '.beeoch-opc-qty' );
		var next = clamp( $control, parseInt( $input.val(), 10 ) );

		$input.val( next );
		$control.attr( 'data-state', 'busy' );

		queue( $control.attr( 'data-beeoch-opc-key' ), next );
	} );

	/**
	 * After every refresh — ours or anyone else's.
	 *
	 * Clearing the carrier fields here rather than at dispatch time is deliberate:
	 * WooCommerce debounces update_checkout internally and serialises the form when it
	 * actually sends, so clearing earlier would empty the fields before they were read.
	 */
	$( document.body ).on( 'updated_checkout', function () {
		inFlight = false;
		clearFields();
		bind();

		/*
		 * The payment fragment has just been replaced, so the gift-card form is back in its
		 * original position and must be gathered again. The coupon box is untouched by the
		 * refresh, and gather() no-ops for anything already in the panel.
		 */
		gather();

		// An edit queued while a request was in flight goes out now.
		if ( pending ) {
			window.clearTimeout( timer );
			timer = window.setTimeout( dispatch, DEBOUNCE_MS );
		}
	} );

	/**
	 * Blocks that can only be gathered in the DOM, not via hooks.
	 *
	 * Everything else in the Promotions panel is relocated server-side by detaching the
	 * plugin's callback and re-running it inside the panel. Two blocks cannot be moved that
	 * way:
	 *
	 * `.e-coupon-box` — Elementor Pro renders it from
	 *   `Widgets\Checkout::woocommerce_checkout_order_review()`, a callback that also emits
	 *   structural markup: it closes #order_review and opens .e-checkout__order_review-2
	 *   around the coupon form. Detaching it would leave unbalanced divs and destroy the
	 *   page layout.
	 *
	 * `#pwgc-redeem-gift-card-form` — PW Gift Cards renders it from
	 *   `woocommerce_review_order_before_submit`, which is inside the replaced payment
	 *   fragment. Suppressing it there would mean it never refreshes.
	 *
	 * Moving nodes is normally forbidden in this plugin because fragment replacement undoes
	 * it. It is acceptable here only because we re-run after every `updated_checkout`, and
	 * because jQuery preserves event handlers across a move — so the plugins' own bindings
	 * survive being reparented.
	 *
	 * @type {string[]}
	 */
	/*
	 * Note the attribute selector for the gift-card form rather than '#id'.
	 *
	 * jQuery resolves '#id' through getElementById, which returns only the FIRST matching
	 * element. Immediately after a refresh there are briefly two: the copy we moved into the
	 * panel and the one the payment fragment just re-rendered. The panel copy comes first in
	 * the document, so '#id' matched only that, the "fresh" set came back empty, and the new
	 * copy was left sitting in its default position — the customer saw the form twice.
	 * '[id="..."]' goes through querySelectorAll and returns both.
	 */
	var GATHER = [ '.e-coupon-box', '[id="pwgc-redeem-gift-card-form"]' ];

	/**
	 * Move the stragglers into the Promotions panel.
	 */
	function gather() {
		var $body = $( '.beeoch-opc-promo__body' );

		if ( ! $body.length ) {
			return;
		}

		$.each( GATHER, function ( _, selector ) {
			var $inPanel = $body.find( selector );
			var $fresh = $( selector ).not( $inPanel );

			if ( ! $fresh.length ) {
				return;
			}

			/*
			 * A refresh rebuilds the payment fragment, so a newly rendered copy appears
			 * while the one we moved earlier is still in the panel. Drop the stale one
			 * first, then take only the newest — any further duplicates are discarded so a
			 * repeated refresh cannot stack copies.
			 */
			$inPanel.remove();
			$fresh.slice( 1 ).remove();

			$fresh
				.first()
				.addClass( 'beeoch-opc-promo__item beeoch-opc-promo__item--gathered' )
				.appendTo( $body );
		} );
	}

	/**
	 * Recovery net for a refresh that never completes.
	 *
	 * WooCommerce blocks '.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table'
	 * before the update_order_review request and unblocks them *only* inside the ajax success
	 * handler (checkout.js ~707 and ~762). There is no error handler. It also aborts any
	 * in-flight request when a new update starts (~619).
	 *
	 * So a refresh that 500s, times out, or is aborted with no successor leaves the order
	 * summary blocked forever, with no way back short of reloading the page. That is a core
	 * fragility rather than something we introduced — but cart editing makes refreshes far
	 * more frequent, so we own making it survivable.
	 *
	 * An abort is normal when a newer request has replaced this one, so we only intervene if
	 * nothing took its place.
	 */
	$( document ).ajaxError( function ( event, jqXHR, settings ) {
		if ( ! settings || ! settings.url || settings.url.indexOf( 'update_order_review' ) === -1 ) {
			return;
		}

		var aborted = jqXHR && jqXHR.statusText === 'abort';

		window.setTimeout( function () {
			// A newer request is running and will unblock on its own.
			if ( aborted && inFlight ) {
				return;
			}

			inFlight = false;
			clearFields();

			$( '.woocommerce-checkout-payment, .woocommerce-checkout-review-order-table' ).unblock();
			$( '.beeoch-opc-qty' ).attr( 'data-state', 'ready' );

			if ( window.console && window.console.warn ) {
				window.console.warn(
					'[beeoch-opc] checkout refresh failed (' +
						( jqXHR ? jqXHR.status + ' ' + jqXHR.statusText : 'unknown' ) +
						'); summary unblocked so the page stays usable.'
				);
			}
		}, 250 );
	} );

	$( function () {
		bind();
		gather();
	} );
} )( jQuery );
