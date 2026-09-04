# Checkout Hook Map

**Status:** ✅ Checkout populated from runtime capture, 2026-08-13. Cart page still outstanding.

Purpose: a single place showing **who is attached to what**, so the orchestrator can relocate
presentation without silently detaching business logic. Populated from the real codebase, not
from documentation.

---

## 1. How this was populated

The runtime pass described in the original plan was built and run:
`site/wp-content/mu-plugins/zzz-beeoch-audit-probe.php` — inert unless a request carries
`?beeoch_audit=1`, **delete it when Phase 2 closes.**

It resolves every callback to its owning file via `ReflectionFunction::getFileName()`, so
attribution is exact rather than inferred. Two captures were taken, on **PHP 8.1.31** with a
2-item cart, and both are kept in `site/beeoch-audit/`:

| Capture | Request |
|---|---|
| `checkout-probe-page.txt` | `GET /checkout/` |
| `checkout-probe-ajax-update_order_review.txt` | `POST /?wc-ajax=update_order_review` |

`by-plugin.tsv` is the flattened hook table (338 rows) used to build section 2.

This matters more than a grep pass would have, because the **BWG Conditional Plugin Loader**
changes which plugins load per request, so "registered in the codebase" and "running on
checkout" are different sets.

## 2. What actually runs on checkout

### 2.1 The rendering chain — settled

The Elementor Pro widget (`woocommerce-checkout-page.default`) does **not** override templates.
`form-checkout.php`, `form-billing.php`, `form-shipping.php`, `payment.php` and `terms.php` all
resolve to **WooCommerce core**. The widget is a thin wrapper around the standard template chain,
so ordinary checkout hooks fire inside it normally.

One template is overridden and one plugin injects its own:

| Template | Winner |
|---|---|
| `checkout/review-order.php` | `astra-child` (v5.2.0 vs core 11.0.0) |
| `checkout/pw-gift-cards.php`, `coupon-area-pw-gift-card.php`, `payment-method-pw-gift-card.php` | PW Gift Cards |

**The stale override is not the hazard the earlier docs assumed.** Diffed against core 11.0.0:
every `do_action` and every filter is present and identical, so no hooks are being lost. The only
substantive differences are core's hardened `$_product instanceof WC_Product` guard (the override
uses a weaker `$_product &&`, which can fatal if a filter returns a truthy non-product) and a
dead commented-out `wpslash_tipping_custom_position` call from a removed plugin.

### 2.2 Checkout hook table

Verdicts follow the legend in §2.4. Priorities are shown where they matter.

| Hook | Callbacks (`plugin → callback @ priority`) | Relocate? | Notes |
|---|---|---|---|
| `woocommerce_before_checkout_form` | woocommerce → `output_all_notices` @10 · wpgens-loyalty → `Referrals_Core->auto_apply_referral` @10 · pw-gift-cards → `Redeeming->…` @40 | `wrap` | Referral auto-apply is business logic; the gift-card UI is presentation |
| `woocommerce_checkout_before_customer_details` | woocommerce → `wc_get_pay_buttons` @30 | `move` | Express-pay buttons |
| `woocommerce_checkout_billing` | woocommerce → `checkout_form_billing` + `checkout_form_shipping` @10 · checkout-upsell → `CheckoutUpsells` @10 · woocommerce → `OrderAttributionController` @10 | `wrap` | Billing **and shipping** both render from this hook |
| `woocommerce_checkout_shipping` / `_after_customer_details` | woocommerce → `OrderAttributionController` @10 | `keep` | Order attribution — hidden fields, must survive |
| `woocommerce_checkout_order_review` | woocommerce → `woocommerce_order_review` @10 · **astra-child closure @10** · wpgens-loyalty → `Points_Checkout->display_points_conversion` @10 · advanced-coupons-free → `Checkout->display_checkout_…` @11 · woocommerce → `woocommerce_checkout_payment` @20 · all-products-for-subscriptions → `Manage_Add_Cart->options_template` @999 | `move` | The whole promotions + review region. See §2.3 for the astra-child issue |
| `woocommerce_review_order_before_cart_contents` / `_after_cart_contents` | *(nothing attached)* | `move` | **Free insertion points for our cart-editing UI** |
| `woocommerce_review_order_before_shipping` | subscriptions → `Cart_Resubscribe->maybe_set_free_trial` · `Switcher->maybe_set_free_…` · `Synchroniser::maybe_set…` @10 | **`keep`** | ⚠️ see §2.3 |
| `woocommerce_review_order_after_shipping` | subscriptions → the matching three `maybe_unset_…` @10 | **`keep`** | ⚠️ paired with the above |
| `woocommerce_review_order_before_order_total` | advanced-coupons-free → `Store_Credits\Checkout->display…` @10 · pw-gift-cards → `Redeeming->…` @10 | `move` | Store-credit and gift-card total rows |
| `woocommerce_review_order_after_order_total` | subscriptions → `display_recurring_totals` @10 | `keep` | Recurring totals block |
| `woocommerce_review_order_before_payment` / `_after_payment` / `_before_submit` / `_after_submit` | checkout-upsell → `CheckoutUpsells` @10 (all four) · pw-gift-cards → `Redeeming->…` @10 (before_submit) | `move` | UpsellWP is the most render-invasive plugin on the page |
| `woocommerce_checkout_fields` (filter) | astra-child closure @10 · woo-checkout-field-editor-pro | `keep` | Field definitions |
| `woocommerce_checkout_process` | astra-child closure @10 | `keep` | Validation |
| `woocommerce_checkout_update_order_meta` | 13 callbacks, incl. astra-child `save_shipping_method_to_meta` @10 | `keep` | |
| `woocommerce_checkout_order_processed` | 14 callbacks, incl. astra-child `mailchimp-integration.php` @20 | `keep` | |
| `woocommerce_available_payment_gateways` (filter) | subscriptions ×6 · advanced-coupons → `Payment_Methods_Restrict` @110 | `keep` | See §2.5 |
| `woocommerce_package_rates` (filter) | astra-child → `patricks_sort_woocommerce_available_shipping_methods` @10 | `keep` | Shipping-method ordering |
| `woocommerce_before_calculate_totals` | 19 callbacks — see §2.6 | `keep` | |
| `woocommerce_after_calculate_totals` | 13 callbacks — see §2.6 | `keep` | |
| `woocommerce_cart_calculate_fees` | subscriptions ×5 · advanced-coupons `Shipping_Overrides` @10 · wpgens-loyalty `add_shipping_discount` @10 | `keep` | Points discount is a **fee**, not a coupon |

### 2.3 ⚠️ The constraint that shapes the whole design

WooCommerce Subscriptions brackets the shipping render with paired state mutations, from three
independent subsystems:

```
woocommerce_review_order_before_shipping  →  maybe_set_free_trial    (Resubscribe, Switcher, Synchroniser)
      … shipping rows render …
woocommerce_review_order_after_shipping   →  maybe_unset_free_trial  (the same three)
```

Rendering the shipping section **mutates cart state and then restores it**. If a partial refresh
ever fires `before_shipping` without reaching `after_shipping`, the cart is left holding modified
trial/sync state.

**Therefore: the order review block is an atomic rendering unit.** Live quantity editing must
re-render all of it, exactly as `update_order_review` already does. Do not build per-row or
per-section partial refreshes.

**Second issue, in our own client's code.** `astra-child/functions.php:1320` removes WPGens' own
callbacks and substitutes a hand-written reimplementation:

```php
remove_action( 'woocommerce_checkout_order_review',
    [ 'WPGL_Points_Checkout', 'display_points_to_be_earned' ], 10 );
add_action( 'woocommerce_checkout_order_review', function () {
    wpgl_render_cart_checkout_points_to_earn_notice();
}, 10 );
```

Its own comments concede the approach — *"we reproduce by calling the PUBLIC display method"*,
*"copy their markup and call their calc by reflection"* — re-deriving point totals from
`get_earning_actions()` to bypass the plugin's guest/role checks. This is the exact pattern
[PROJECT.md](PROJECT.md) §2 prohibits.

Recommended: our plugin `remove_action`s the theme closure and restores WPGens' own callback,
relocating it rather than reimplementing it. Per project rule, **this is done from our plugin —
`astra-child` is not edited.**

### 2.4 Legend for "Relocate?"

- `move` — presentation only; safe for the orchestrator to detach and re-render elsewhere.
- `keep` — business logic; must stay attached exactly where it is.
- `wrap` — needs a wrapper/filter because the callback can't be cleanly removed.
- `?` — undecided, needs investigation.

Anything touching validation, order creation, totals calculation, or gateway processing is
`keep` by default.

### 2.5 Payment gateways

Subscriptions filters `available_payment_gateways` six ways, including
`check_cod_gateway_used_for_subscriptions`. Since the safety lockdown left **Cash on Delivery as
the only active gateway**, subscription carts may legitimately show no payment method locally.
Expect this during testing; it is not a defect.

### 2.6 Totals are not final until priority 10000

Extreme priorities on the totals hooks, worth knowing before we attach anything:

| Priority | Callback | Plugin |
|---|---|---|
| 9999 | `iThemeland_cart_hook->set_price` | Free Gifts (`before_calculate_totals`) |
| 10000 | `CUW\…\Cart->applyDiscount` | UpsellWP (`before_calculate_totals`) |
| 9999 | `Store_Credits\Checkout->…` | Advanced Coupons (`after_calculate_totals`) |
| 2000 | `Apply_Notification` | Advanced Coupons (`after_calculate_totals`) |
| 1000 | `WC_Cart_Session->set_session` | WooCommerce (`after_calculate_totals`) |

**Coupons mutate as a side effect of recalculation.** Advanced Coupons `Auto_Apply` and
`Defer_URL_Coupon`, plus dynamic-pricing `applyFakeCoupons`, all run on
`woocommerce_after_calculate_totals`. A quantity change can therefore silently add or remove a
coupon. Our UI must re-render the promotions block on every cart mutation — refreshing totals
alone will show a discount the coupon list does not explain.

## 3. AJAX / REST endpoint map

| Endpoint | Type | Owner | Purpose | Nonce | Used by our plugin? |
|---|---|---|---|---|---|
| `wc-ajax=update_order_review` | WC AJAX | WooCommerce | Checkout refresh | `update-order-review` | **Yes — central. Verified working; see §5** |
| `wc-ajax=checkout` | WC AJAX | WooCommerce | Order submission | `woocommerce-process_checkout` | Untouched |
| `wc-ajax=apply_coupon` / `remove_coupon` | WC AJAX | WooCommerce | Coupons | | Yes — already in the BWG allowlist |
| `wc-ajax=get_refreshed_fragments` | WC AJAX | WooCommerce | Mini-cart | | Indirect |
| `wc-ajax=add_to_cart` | WC AJAX | WooCommerce | | | Indirect |
| `wc-ajax=xoo_wsc_refresh_fragments` | WC AJAX | Side Cart | Side-cart refresh | | Must keep working |
| `/wc/store/v1/*` | Store API | WooCommerce | Blocks | | **Not in use — no Blocks checkout** |
| *our cart-mutation endpoint* | WC AJAX | **us** | qty change / remove | own nonce | To build — see §6 |

### 3.1 ⚠️ The BWG loader will not cover our endpoint

`mu-plugins/bwg-conditional-loader.php` strips non-checkout plugins on the checkout page and on
seven named `wc-ajax` actions. Verified from both captures: the strip list is **identical** on
`update_order_review` and on page render.

⚠️ **The local plugin set has since diverged from production.** Non-checkout plugins were removed
locally on 2026-08-13 to slim the environment, which changes how much the loader has left to do:

| | Active before loader | After loader | Stripped |
|---|---|---|---|
| Original capture | 51 | 35 | 16 |
| After local cleanup | 35 | 29 | 6 |
| Production | ~70 | — | up to 23 |

The hook map itself is unaffected — re-capture after the cleanup produced 337 rows versus 338,
the only difference being one `wp-phpmyadmin-extension` callback. **Every checkout-path
attribution is unchanged.**

But two caveats follow for testing: performance measured locally will flatter production, and any
regression caused by an interaction with a *removed* plugin cannot reproduce here. The strip list
in the loader's source, not the local count, is what production does.

A **new** action of ours — say `wc-ajax=beeoch_update_cart` — is not in that list, so all 16
would load on every cart edit. That is both a performance regression and a behavioural
difference from the rest of checkout.

Two options, and the second is preferred:

1. Add our action to the loader's array — but that file is client-owned custom code, not ours.
2. **Our plugin registers its own `option_active_plugins` filter** applying the same strip list to
   our action. Keeps all our code in our plugin, per the project rule.

Either way our own plugin must never appear in a removal list.

## 4. JavaScript event map

| Event | Emitted by | Listened to by | Notes |
|---|---|---|---|
| `update_checkout` | Woo checkout.js / plugins | Woo checkout.js | Trigger for a refresh |
| `updated_checkout` | Woo checkout.js | plugins, gateways | **Re-bind point after every refresh** |
| `checkout_error` | Woo checkout.js | | |
| `wc_fragments_refreshed` | cart-fragments.js | mini-cart, Side Cart | |
| `updated_wc_div` / `updated_cart_totals` | Woo cart.js | cart-page code | **Never fire on checkout** — see risk below |
| `country_to_state_changed` | Woo | address fields | |

Already confirmed in the wild: `mu-plugins/beeoch-checkout-stock-protection.php` binds
`updated_checkout` and re-triggers `update_checkout` when it detects its own removal notice, with
a 1.5-second re-entry guard. **Our refresh cycle must not fight this** — a naive
"refresh on every change" loop plus this callback can ping-pong.

**Key risk retained from the original plan:** plugins binding `updated_wc_div` or
`updated_cart_totals` go dead on a checkout-only page, because those events never fire there.
Cart-page bindings in Side Cart, dynamic pricing, and the free-gift plugin all need checking
against this — that is the outstanding **Step C-2** work.

## 5. DOM contract — verified

`update_order_review` returns **eight** fragments. Captured live:

| Selector | Owner |
|---|---|
| `.woocommerce-checkout-review-order-table` | WooCommerce core |
| `.woocommerce-checkout-payment` | WooCommerce core |
| `.acfw-store-credit-user-balance` | Advanced Coupons |
| `.wpgens-points-redemption-block` | WPGens |
| `.wpgens-points-earning-notice-fragment` | WPGens |
| `div.xoo-wsc-container` | Side Cart (XooTiX) |
| `div.xoo-wsc-sc-cont` | Side Cart (XooTiX) |
| `div.xoo-wsc-slider` | Side Cart (XooTiX) — empty on checkout, still contractual |

**This is a hard contract.** Six of the eight belong to third-party plugins; if our markup
restructures the page such that a selector no longer exists, that plugin's UI silently stops
updating — no error, just stale numbers. Any new layout must keep all eight present in the DOM.

> **Corrected 2026-08-13** from five to eight. The Side Cart fragments were missed by the original
> extraction, which assumed each fragment's HTML began with `<`. `Diagnostics\FragmentAssert`
> flagged them on its first run. The live assertion, not the captured list, is now authoritative.

Additional selectors to preserve: `form.checkout`, `#order_review`, `.cart_item`.

Templates rendered during the AJAX refresh — narrower than a page load, as expected:
`review-order.php` (astra-child), `payment.php`, `payment-method.php`,
`payment-method-pw-gift-card.php`.

## 6. Cart editing flow — validated

The intended sequence survives the audit, with one amendment: **do not hand-build fragments.**
Drive the existing cycle, because it already re-renders the review table atomically, which is
what §2.3 requires.

```
qty change / remove clicked
        ↓
our wc-ajax endpoint (own nonce + the §3.1 plugin-strip filter)
        ↓
WC()->cart->set_quantity( $cart_item_key, $qty, true )
        ↓
respond, then let the page trigger update_checkout
        ↓
update_order_review re-renders ALL five fragments
        ↓
updated_checkout fires → gateways, gift card, points, upsells re-bind
```

Answers to the original open questions:

- **Is one round-trip enough?** No — use two. A mutation call, then the standard refresh. Merging
  them would mean reimplementing `update_order_review`'s fragment assembly, and we would lose the
  three plugin-owned fragments in §5.
- **Do any plugins hook `woocommerce_update_cart_action_cart_updated`?** Not on the checkout
  request — it did not appear in either capture. Still to be confirmed against the **cart page**,
  which has not been probed.
- **Race conditions on rapid clicks:** real, and compounded by the stock-protection mu-plugin's
  own `update_checkout` re-trigger. Debounce on the client and guard re-entry on the server.
- **Free-gift eligibility timing:** iThemeland works via `set_price` @9999 on
  `before_calculate_totals` and `check_session_…` on `after_calculate_totals` — so it recomputes
  on every totals calculation. Our flow triggers that naturally. **Not yet verified** for the
  add/remove-gift transition specifically.

## 7. Still outstanding

- **Cart page hooks** (§2 cart table) — not probed. Needed only if we keep `/cart/` alive; the
  project intends checkout to become the sole surface, so this may be scope we can drop.
- **Cart-page JS bindings** going dead on checkout (§4) — the highest-value remaining check.
- **Subscriptions' 76 callbacks** — only the checkout-render ones are mapped. The renewal and
  switching paths are unexamined.
- **`CHECKOUT-COMPATIBILITY.md`** — not yet updated with these findings.
