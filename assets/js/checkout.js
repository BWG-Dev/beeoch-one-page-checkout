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

	/*
	 * Marks that our script is alive, used by CSS to hide blocks that are about to be
	 * moved. Set immediately rather than on ready: if the script never runs the class is
	 * absent and nothing is hidden, so a JS failure cannot leave the coupon box invisible.
	 */
	document.body.classList.add( "beeoch-opc-js" );

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

		f.action.val( pending.action || 'set_quantity' );
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

	/**
	 * Remove a line.
	 *
	 * Rides the same request as a quantity change — same carrier fields, same nonce, same
	 * replay token — with a different action. No confirmation step: the refresh that follows
	 * shows the result immediately, and a mis-click is recoverable by re-adding, whereas a
	 * confirm dialog on every removal is friction on the common case.
	 */
	$( document.body ).on( 'click', '.beeoch-opc-qty__remove', function ( event ) {
		event.preventDefault();

		var $control = $( this ).closest( '.beeoch-opc-qty' );

		if ( $control.attr( 'data-state' ) === 'busy' ) {
			return;
		}

		$control.attr( 'data-state', 'busy' );

		pending = { key: $control.attr( 'data-beeoch-opc-key' ), quantity: 0, action: 'remove_item' };

		window.clearTimeout( timer );
		timer = window.setTimeout( dispatch, 0 );
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
		 *
		 */
		gather();
		wrapRows();

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
	 * Rows we build client-side, because these blocks arrive by DOM move rather than being
	 * rendered by us. Server-side rows are configured in Promotions::BLOCKS instead.
	 *
	 * @type {Array<{selector: string, title: string, icon: string}>}
	 */
	var WRAP = [
		{
			selector: '.e-coupon-box',
			title: 'Have a coupon?',
			icon: '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>'
		},
		{
			/*
			 * The gift card differs from the other three in two ways.
			 *
			 * It has no toggle of its own, so there is nothing to render inert — our row is
			 * simply the only behaviour it has ever had.
			 *
			 * And it is re-rendered on EVERY checkout refresh, because PW Gift Cards draws it
			 * from `woocommerce_review_order_before_submit`, inside the replaced payment
			 * fragment. Each refresh therefore produces a fresh, unwrapped copy: gather()
			 * moves it in and this wraps it again. The guard is per-element, so the new copy
			 * is correctly treated as new rather than skipped.
			 *
			 * Consequence worth knowing: the row returns to collapsed after any refresh,
			 * since the wrapped element the customer opened has been replaced.
			 */
			selector: '[id="pwgc-redeem-gift-card-form"]',
			title: 'Have a gift card?',
			icon: '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>'
		}
	];

	/**
	 * Put a gathered block inside the same <details> row the server-side blocks use.
	 *
	 * The plugin's own toggle is left alone but rendered inert: its trigger is hidden and its
	 * content pinned visible by CSS, so only our row opens and closes. That is the whole
	 * reason this approach works where the previous one did not — there is never a moment
	 * when two toggles disagree about what is visible.
	 *
	 * The block's existing children are MOVED into our body, so every handler the plugin
	 * bound survives; jQuery preserves them across a move.
	 */
	function wrapRows() {
		$.each( WRAP, function ( _, spec ) {
			$( '.beeoch-opc-promo__body' ).find( spec.selector ).each( function () {
				var $block = $( this );

				if ( $block.data( 'beeochWrapped' ) || ! $block.children().length ) {
					return;
				}

				$block.data( "beeochWrapped", true ).addClass( "beeoch-opc-wrapped" );

				var $body = $( '<div/>', { 'class': 'beeoch-opc-acc__body' } );

				// Move, not clone — cloning would drop the plugin's event handlers.
				$block.children().appendTo( $body );

				var $summary = $( '<summary/>', { 'class': 'beeoch-opc-acc__head' } )
					.append( $( '<span/>', { 'class': 'beeoch-opc-acc__icon' } ).html( icon( spec.icon ) ) )
					.append( $( '<h3/>', { 'class': 'beeoch-opc-acc__title', text: spec.title } ) );

				/*
				 * `name` groups all four rows into an exclusive accordion: opening one closes
				 * the others, natively, with no JavaScript. Must match the value used by the
				 * server-rendered rows in Promotions::wrap_in_row().
				 */
				$block.append(
					$( '<details/>', { 'class': 'beeoch-opc-acc', name: 'beeoch-opc-promo' } )
						.append( $summary, $body )
				);
			} );
		} );
	}

	/**
	 * Wrap icon paths in an SVG that inherits the surrounding colour and size.
	 *
	 * @param {string} paths Inner SVG markup.
	 * @return {string} Complete SVG element.
	 */
	function icon( paths ) {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" ' +
			'stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">' +
			paths + '</svg>';
	}

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

	/**
	 * Fallback for the exclusive accordion.
	 *
	 * The rows carry `name="beeoch-opc-promo"`, which groups them natively — Chrome 120+,
	 * Safari 17.2+, Firefox 130+ close the others automatically. This closes them by hand
	 * for anything older, and is harmless where the native behaviour already applies because
	 * the others are shut by the time it runs.
	 *
	 * Bound with `capture: true` deliberately: the `toggle` event does not bubble, so a
	 * delegated listener would never fire without it.
	 */
	document.addEventListener(
		'toggle',
		function ( event ) {
			var opened = event.target;

			if ( ! opened || ! opened.matches || ! opened.matches( '.beeoch-opc-acc[open]' ) ) {
				return;
			}

			var rows = document.querySelectorAll( '.beeoch-opc-promo .beeoch-opc-acc[open]' );

			Array.prototype.forEach.call( rows, function ( row ) {
				if ( row !== opened ) {
					row.open = false;
				}
			} );
		},
		true
	);

	$( function () {
		bind();
		gather();
		wrapRows();
	} );
} )( jQuery );
