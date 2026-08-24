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
	var HINT_MS = 5000;

	// Translated strings, with English fallbacks so a missing localisation never blanks a hint.
	var I18N = settings.i18n || {};

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
	 * Say why a quantity change was refused, next to the control that refused it.
	 *
	 * When the bounds reject a click, no request is sent — so there is no refresh, and the
	 * server has no opportunity to explain. Without this the customer clicks "+" and nothing
	 * whatsoever happens, which reads as a broken button rather than as a stock limit.
	 *
	 * Placed beside the stepper rather than in WooCommerce's notice group at the top of the
	 * form: the notice group is what the server uses, and it is usually scrolled out of sight
	 * when someone is working in the order summary.
	 *
	 * `role="status"` so it is announced. A silent no-op is worse with a screen reader, not
	 * better.
	 */
	function hint( $control, message ) {
		if ( ! message ) {
			return;
		}

		$control.siblings( '.beeoch-opc-qty__hint' ).remove();

		var $hint = $( '<span/>', {
			'class': 'beeoch-opc-qty__hint',
			role: 'status',
			text: message
		} );

		$control.after( $hint );

		window.setTimeout( function () {
			$hint.fadeOut( 200, function () {
				$hint.remove();
			} );
		}, HINT_MS );
	}

	/**
	 * The upper bound this control was rendered with, or NaN when unlimited.
	 */
	function maxOf( $control ) {
		return parseInt( $control.attr( 'data-max' ), 10 );
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
		var current = parseInt( $input.val(), 10 ) || 0;
		var next = clamp( $control, current + delta );

		if ( next === current ) {
			/*
			 * The bounds refused this. Previously the handler returned here in silence, so
			 * pressing "+" on a line already at its stock limit did nothing at all and gave
			 * the customer no way to tell a limit from a bug.
			 */
			var max = maxOf( $control );

			if ( delta > 0 && ! isNaN( max ) && current >= max ) {
				hint( $control, ( I18N.stockMax || 'Only %d left in stock.' ).replace( '%d', max ) );
			} else if ( delta < 0 ) {
				hint( $control, I18N.minOne );
			}

			return;
		}

		$control.siblings( '.beeoch-opc-qty__hint' ).remove();

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

	/**
	 * A plan change refreshes the totals.
	 *
	 * The radios are All Products for Subscriptions' own, named `cart[key][convert_to_sub]`,
	 * and they sit inside `form.checkout` — so WooCommerce serialises them into `post_data`
	 * without any help from us. Nothing needs to be carried, queued or de-duplicated here; the
	 * refresh alone delivers the choice, and `SchemeUpdate` applies it server-side.
	 *
	 * Deliberately not routed through queue(): that carries OUR mutation fields with a nonce
	 * and a single-use token, and a plan change is neither. Sending one would consume a token
	 * for an edit the server was never asked to make.
	 */
	/**
	 * Refresh after the free-gift plugin changes the cart.
	 *
	 * Adding or swapping a gift is their own AJAX call, and it knows nothing about this
	 * checkout — it neither triggers `update_checkout` nor reloads. Without this the gift lands
	 * in the cart while the totals, the line items and the eligibility message all continue to
	 * describe the cart as it was a moment ago.
	 *
	 * Matched on the request body rather than by wrapping their code, so no assumption is made
	 * about which of their handlers ran. `update_order_review` is itself an admin-ajax-free
	 * `wc-ajax` call whose body carries none of these action names, so this cannot re-trigger
	 * itself.
	 */
	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		var body = ( settings && typeof settings.data === 'string' ) ? settings.data : '';

		if ( /action=[^&]*(itg_|pw_gift|wgb_)/i.test( body ) ) {
			$( document.body ).trigger( 'update_checkout' );
		}
	} );

	/**
	 * Start the gift carousel.
	 *
	 * The gift plugin renders its picker as an owl-carousel and initialises it on document
	 * ready — but our copy is rebuilt on every refresh, so from the second render onwards the
	 * markup exists with nothing driving it. An uninitialised owl carousel is not merely
	 * unstyled: its slides are laid out end to end at full width, which is what pushed the
	 * order summary wider than its column and squeezed the customer details beside it.
	 *
	 * `it-enhanced-carousel` is the plugin's own event for exactly this, so the carousel is
	 * built with the store's own settings — speed, loop, dots, nav, rtl — rather than a second
	 * copy of that configuration living here and drifting.
	 */
	/**
	 * Add a gift without losing the form.
	 *
	 * The gift plugin offers two controls, and only one of them is a problem here:
	 *
	 *   "Select Gift"  variable products. A <div> bound with
	 *                  jQuery(document).on('click', '.btn-select-gift-button') — delegated on
	 *                  document, so it survives our fragment replacements and opens their
	 *                  variation modal unaided. Nothing to do.
	 *
	 *   "Add Gift"     simple products. A plain <a href="?pw_add_gift=...">, with no JavaScript
	 *                  bound to it at all. Their handler runs on `wp`, adds the item and calls
	 *                  wp_safe_redirect() — a full page load.
	 *
	 * On a cart page that reload costs nothing. On checkout it discards everything typed into
	 * the form, which is a bad trade for choosing a free gift. The click is therefore fetched in
	 * the background instead: the same URL, so their handler does exactly what it always does,
	 * and the refresh that follows brings the new line, the totals and the eligibility message
	 * up to date.
	 *
	 * The redirect they issue is followed by the browser and its body discarded. That is one
	 * wasted page render server-side, in exchange for the customer keeping their address.
	 */
	$( document.body ).on( 'click', '.beeoch-opc-gift a.wgb-add-gift-btn[href*="pw_add_gift"]', function ( event ) {
		var $link = $( this );
		var url = $link.attr( 'href' );

		if ( ! url || $link.hasClass( 'beeoch-opc-busy' ) ) {
			return;
		}

		event.preventDefault();

		$link.addClass( 'beeoch-opc-busy' );
		$( '.beeoch-opc-gift' ).attr( 'data-state', 'busy' );

		$.get( url ).always( function () {
			$link.removeClass( 'beeoch-opc-busy' );

			/*
			 * Always, not done: their handler redirects, and a redirect the browser declines to
			 * follow still means the gift was added. Refreshing regardless is correct — the
			 * refresh is what tells us the truth either way.
			 */
			$( document.body ).trigger( 'update_checkout' );
		} );
	} );

	/**
	 * On mobile, put the whole order-review column — gifts, promotions, and the cart itself —
	 * above billing.
	 *
	 * Anchored on `#customer_details` — the billing form's own id — rather than on Elementor's
	 * `.e-checkout__column-start` / `-end` classes. Which of those two columns holds billing and
	 * which holds the order review is a per-site setting in the checkout widget, not something
	 * fixed by Elementor's markup; this store's local and staging copies were confirmed to
	 * differ on exactly that. An id-anchored move works no matter which column billing is
	 * configured into, so nothing here depends on that setting either way.
	 *
	 * The four are inserted before billing in the SAME relative order desktop already gives them
	 * — heading, cart table, gift, promotions — read straight off the `order:` values desktop
	 * assigns them inside `.e-checkout__order_review` (0, 1, 2, 3 respectively). This is not a
	 * new order invented for mobile; it is desktop's own ordering, simply relocated as one block
	 * to sit above billing instead of beside it. An earlier version of this function inserted
	 * gift and promotions first and the cart last, which put gift ahead of the cart on mobile
	 * even though desktop has always shown the cart first — worth remembering if "mobile doesn't
	 * match desktop" comes up again: check the `order:` values here rather than re-guessing.
	 *
	 * Gated to the same `max-width: 1024px` breakpoint the rest of the stylesheet uses for
	 * "mobile" (§38, §44), so desktop is never touched — this only ever runs below that width.
	 *
	 * Safe to call repeatedly: moving an already-correctly-placed element is a no-op. Called on
	 * first paint and after every refresh because the gift block is an order-review AJAX
	 * fragment and is replaced wholesale each time; WooCommerce's fragment swap re-inserts the
	 * new copy at the DOM position of the node it replaced, so once moved it stays moved, but
	 * this still runs every time in case any of these nodes is ever rebuilt from scratch rather
	 * than replaced in place.
	 */
	function reorderForMobile() {
		if ( ! window.matchMedia( '(max-width: 1024px)' ).matches ) {
			return;
		}

		var $billing = $( '#customer_details' );

		if ( ! $billing.length ) {
			return;
		}

		// In this order — each insertBefore places its element immediately ahead of billing,
		// so inserting later in this list is what keeps that item closest to it.
		[ '#order_review_heading', '#order_review', '.beeoch-opc-gift', '.beeoch-opc-promo' ].forEach(
			function ( selector ) {
				var $el = $( selector );

				if ( $el.length ) {
					$el.insertBefore( $billing );
				}
			}
		);
	}

	/**
	 * On mobile, give the plan control its own full-width row.
	 *
	 * `.beeoch-opc-plan` lives inside `td.product-name`, and that cell is capped at
	 * `var(--opc-name-col)` — 78% of the table — by `table-layout: fixed`, with the rest
	 * reserved for the price column. No width or `align-self` on a descendant can exceed its
	 * own containing block, so a child of that cell is hard-limited to 78% of the table no
	 * matter what it declares; that cap, not alignment, is what was making the select narrow
	 * once §37's stretch fix ruled the flex-centring theory out.
	 *
	 * The stepper is left where it is — it is small by nature and was never the complaint.
	 * Only the plan control moves, into a new `<td colspan="2">` in a row of its own, which
	 * table-layout sizes to the FULL table width by construction rather than to either column.
	 *
	 * Mobile only, and re-run on every refresh rather than tracked as "already done": the order
	 * review table is one of WooCommerce's own AJAX fragments and is replaced wholesale on every
	 * update, server-rendered fresh with `.beeoch-opc-plan` back in its original cell each time.
	 * There is nothing to preserve between refreshes, only somewhere to redo the move.
	 */
	function spanPlanFullWidth() {
		if ( ! window.matchMedia( '(max-width: 1024px)' ).matches ) {
			return;
		}

		$( '.beeoch-opc-plan' ).each( function () {
			var $plan = $( this );
			var $row = $plan.closest( 'tr' );

			if ( ! $row.length ) {
				return;
			}

			/*
			 * Marks the original row so its own border-bottom can be suppressed in CSS — with
			 * the plan control moved out, that border would otherwise sit BETWEEN the product
			 * line and its own plan control, reading as a separator inside one entry rather
			 * than the boundary at the end of it.
			 */
			$row
				.addClass( 'beeoch-opc-has-plan-row' )
				.after(
					$( '<tr/>', { 'class': 'beeoch-opc-plan-row' } ).append(
						$( '<td/>', { colspan: 2 } ).append( $plan )
					)
				);
		} );
	}

	function startCarousel( attempt ) {
		var $items = $( '.beeoch-opc-gift .it-owl-carousel-items' ).not( '.owl-loaded' );

		if ( ! $items.length ) {
			return;
		}

		/*
		 * Their listener for this event is registered inside their own `jQuery(function(){})`,
		 * so on first paint it is a race: if our ready handler runs before theirs, the trigger
		 * lands on nothing and the carousel silently never starts — which is exactly what it
		 * looked like, slides laid out end to end with no error anywhere.
		 *
		 * So the trigger is retried a few times, stopping as soon as owl marks the element
		 * `.owl-loaded`. Retrying is safe: their handler re-initialises whatever it finds, and
		 * anything already loaded is filtered out above.
		 */
		$( document.body ).trigger( 'it-enhanced-carousel' );

		attempt = attempt || 0;

		if ( attempt < 4 ) {
			window.setTimeout( function () {
				startCarousel( attempt + 1 );
			}, 250 * ( attempt + 1 ) );
		} else if ( ! $.fn.owlCarousel ) {
			/*
			 * owl is not on the page at all. Nothing more to try — §44 lays the slides out as a
			 * contained grid so the picker still works and cannot resize the checkout.
			 */
			$( '.beeoch-opc-gift' ).attr( 'data-carousel', 'unavailable' );
		}
	}

	$( document.body ).on( 'change', '.beeoch-opc-plan select, .beeoch-opc-plan input[type="radio"]', function () {
		$( this ).closest( '.beeoch-opc-plan' ).attr( 'data-state', 'busy' );
		$( document.body ).trigger( 'update_checkout' );
	} );

	$( document.body ).on( 'change', '.beeoch-opc-qty__input', function () {
		var $input = $( this );
		var $control = $input.closest( '.beeoch-opc-qty' );
		var requested = parseInt( $input.val(), 10 );
		var next = clamp( $control, requested );

		/*
		 * A typed value above the limit is held down to it. Said immediately here, and again by
		 * the server in WooCommerce's notice group — the refresh replaces this table and takes
		 * this hint with it, and the server's copy is the one that is authoritative anyway,
		 * since stock can have moved since the page was rendered.
		 */
		if ( ! isNaN( requested ) && requested > next && ! isNaN( maxOf( $control ) ) ) {
			hint( $control, ( I18N.stockMax || 'Only %d left in stock.' ).replace( '%d', next ) );
		}

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
		startCarousel();
		reorderForMobile();
		spanPlanFullWidth();

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
	var GATHER = [
		{ selector: '.e-coupon-box', slot: 'coupon' },
		{ selector: '[id="pwgc-redeem-gift-card-form"]', slot: 'gift-card' }
	];

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

		$.each( GATHER, function ( _, spec ) {
			var $slot = $body.find( '[data-beeoch-slot="' + spec.slot + '"]' );
			var $target = $slot.length ? $slot.find( '.beeoch-opc-acc__body' ).first() : $body;
			var $inPanel = $body.find( spec.selector );
			var $fresh = $( spec.selector ).not( $inPanel ).filter( function () {
				// An empty wrapper is not the block — Elementor emits `.e-coupon-box` either way.
				return $( this ).children().length > 0;
			} );

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

			/*
			 * `beeoch-opc-promo__item` marks a ROW of the panel. A block moved into a slot is
			 * not a row — the slot is — so it takes only the `--gathered` marker, which is what
			 * the flattening rules key on. Tagging it as a row as well nested one row inside
			 * another and let row-level spacing apply twice.
			 */
			$fresh
				.first()
				.addClass(
					$slot.length
						? 'beeoch-opc-promo__item--gathered'
						: 'beeoch-opc-promo__item beeoch-opc-promo__item--gathered'
				)
				.appendTo( $target );

			if ( $slot.length ) {
				/*
				 * The row already exists, so wrapRows() must not build a second one around
				 * the same block. Marking it wrapped here is what keeps the two paths from
				 * fighting: server-rendered row + moved content, or client-built row — never
				 * both for one block.
				 */
				$fresh.first().data( 'beeochWrapped', true ).addClass( 'beeoch-opc-wrapped' );
				$slot.find( '.beeoch-opc-acc' ).removeClass( 'beeoch-opc-acc--pending' );
			}
		} );

		/*
		 * Any slot still pending has nothing to receive — the plugin is inactive, or the
		 * customer is not eligible — so the reserved row is removed rather than left as a
		 * header that opens onto nothing.
		 */
		$body.find( '.beeoch-opc-acc--pending' ).closest( '[data-beeoch-slot]' ).remove();

		// A panel holding nothing but removed slots is a heading with no content.
		if ( ! $body.children().length ) {
			$body.closest( '.beeoch-opc-promo' ).remove();
		}
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
		startCarousel();
		reorderForMobile();
		spanPlanFullWidth();
	} );
} )( jQuery );
