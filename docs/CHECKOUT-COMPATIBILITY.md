# Checkout Compatibility Audit

**Status:** 🟢 **Code-level pass done for the checkout request, 2026-08-13.** Findings 1, 2, 3
and 5 are resolved; Findings 6–9 are new and were only discoverable at runtime.

Evidence base: the runtime capture described in [CHECKOUT-HOOK-MAP.md](CHECKOUT-HOOK-MAP.md) §1,
taken on the local copy at PHP 8.1.31 with a live cart. Raw captures kept in `site/beeoch-audit/`.

Two things this document previously assumed turned out to be wrong, both in our favour — see
Findings 1 and 2. The genuinely dangerous items are the new Findings 6 and 7.

Still outstanding: the **cart page** has not been probed, and Subscriptions' renewal/switching
paths are unexamined.

---

## 1. Environment facts

| Item | Value |
|---|---|
| Source environment | **Staging** — `https://beeoch.nextsitehosting.com` (`WP_ENVIRONMENT_TYPE=staging`) |
| WordPress | 7.0.3 |
| WooCommerce | 11.0.1 (DB 11.0.1) |
| PHP | 8.1.32 |
| Database | MariaDB 11.4.7, 4.0 GB, prefix `wp_` |
| Web server | nginx 1.28.0 |
| Multisite | No |
| **HPOS** | **Enabled**, data sync **enabled**, `OrdersTableDataStore` |
| Object cache | Redis (external, drop-in active) |
| Page cache | NitroPack (`advanced-cache.php`) |
| Theme | **Bee-Och 2024** 1.0 — child of **Astra** 4.13.9, classic theme |
| Theme author | wildspiritdevelopment.com |
| MU plugins | 2 (see §5) |
| Active plugins | **70** |
| Inactive plugins | 21 |
| Store base | United States — Wisconsin |
| Currency | USD |
| Scale | 157 products, 287 variations, **49,234 coupons**, 60,553 orders, 3,060 subscriptions |

---

## 2. Checkout architecture (Phase 1 — partially answered)

| Question | Finding |
|---|---|
| Cart page | `#370` → `/cart/` — contains `[woocommerce_cart]` |
| **Checkout page** | **`#371` → `/checkout/`** — an Elementor page using the Elementor Pro WooCommerce Checkout widget. ✅ Page ID confirmed; widget confirmed rendering as `data-widget_type="woocommerce-checkout-page.default"` |
| Classic or Blocks? | **Classic** — no Cart/Checkout Blocks |
| Blocks features | `experimental-blocks` **disabled** in WooCommerce features |
| Store API in use? | ✅ **Not in use** — no `/wc/store/v1` traffic on the checkout request |
| Rendering owner | **WooCommerce core templates**, mounted inside an Elementor Pro widget — see Finding 1 |
| CartFlows | **Not used.** Deactivated 2026-08-12; **not installed locally at all.** Treat as inert. |
| Theme checkout override | `astra-child/woocommerce/checkout/review-order.php` — v5.2.0, **wins**, but functionally identical to core — see Finding 2 |
| Template precedence | ✅ **Resolved empirically** — no three-way contest exists. Elementor Pro registers **no** checkout template overrides |
| Other Woo overrides (theme) | `single-product/stock.php`, `single-product-reviews.php` — not checkout |
| Other Woo overrides (plugins) | Elementor Pro `cart/mini-cart.php` (no version header); Kadence email templates (not checkout) |

### 🔴 Finding 1 — The checkout is rendered by an Elementor Pro widget

**Client-confirmed 2026-08-12.** The real checkout is `/checkout/`, an **Elementor page using
Elementor Pro's WooCommerce Checkout widget** — not a bare `[woocommerce_checkout]` shortcode.
Identical on staging and production. (The CartFlows step in the original system report was a
staging test and has been retired; CartFlows is now deactivated and unused.)

#### ✅ Resolved 2026-08-13 — the widget is a thin wrapper, not a rendering layer

The concern was overstated. Runtime capture shows **Elementor Pro overrides no checkout
templates.** Every template resolves to WooCommerce core:

| Template | Resolves to |
|---|---|
| `checkout/form-checkout.php` | `plugin:woocommerce` |
| `checkout/form-billing.php` | `plugin:woocommerce` |
| `checkout/form-shipping.php` | `plugin:woocommerce` |
| `checkout/payment.php`, `payment-method.php` | `plugin:woocommerce` |
| `checkout/terms.php` | `plugin:woocommerce` |

The widget invokes the standard template chain, so the ordinary `woocommerce_checkout_*` and
`woocommerce_review_order_*` hooks all fire inside it — point 3 above is answered, and no hooks
are suppressed. Elementor Pro contributes only 11 callbacks on the whole checkout request, none of
them structural (the notable one is `before_calculate_totals` @10 in
`modules/woocommerce/module.php:596`).

The `cart/mini-cart.php` override is real but belongs to the **cart/mini-cart**, not checkout.

What remains true from the original finding: point 4. The widget still wraps everything in
Elementor markup, so our CSS lives inside that structure.

**Revised risk: Low.** Reading the Elementor Pro widget internals is no longer necessary — the
empirical answer supersedes it.

**Phase 3 implication — now decided.** The project owner confirmed (2026-08-13) that either a
custom checkout or the Elementor component is acceptable, provided it stays compatible and
functional. Given the widget is a thin wrapper that does not fight us, the recommendation is to
**keep it as the mount point and orchestrate inside it.** This preserves Elementor editing,
avoids a hard rewrite, and means deactivating our plugin restores today's checkout exactly. It
does *not* require committing to Elementor widgets as our delivery format.

### 🔴 Finding 2 — Stale checkout template override

`astra-child/woocommerce/checkout/review-order.php` declares template version **5.2.0** against
a WooCommerce **11.0.0** core template. That's roughly six major versions of drift, in the exact
template that renders the order review table — line items, totals, shipping, and the hooks
`woocommerce_review_order_*` that our design depends on.

#### ✅ Resolved 2026-08-13 — stale in version, identical in substance

The override does win (confirmed at runtime), but the feared consequence does not follow. Diffed
line by line against core 11.0.0:

- **Every `do_action` is present and identical** — `before/after_cart_contents`,
  `before/after_shipping`, `before/after_order_total`. No hooks are lost.
- Every filter is present — `woocommerce_cart_item_product`, `_visible`, `_class`, `_name`,
  `woocommerce_checkout_cart_item_quantity`, `woocommerce_cart_item_subtotal`.

Only two substantive differences, neither structural:

1. Core hardened the guard from `$_product &&` to `$_product instanceof WC_Product`. The override
   keeps the weaker check, so a filter returning a truthy non-product would fatal here where core
   would skip the row. Low likelihood, trivial to fix.
2. The override carries a dead commented-out `do_action('wpslash_tipping_custom_position')` from a
   removed tipping plugin.

So the premise that "some plugin integrations may already be silently broken in production" is
**not supported**. Six majors of version drift produced no hook loss in this particular template.

**Revised risk: Low.** If we replace this template, our plugin registers the override — the child
theme is not edited (project rule: all custom code lives in our plugin).

### 🟡 Finding 3 — Cart fragments are disabled

**Disable Cart Fragments** (Optimocha 2.4.1) is **active**. It switches off WooCommerce's
`wc-cart-fragments` AJAX.

#### ✅ Largely resolved 2026-08-13 — it does not affect the checkout refresh

The two mechanisms are distinct and easily conflated:

| Mechanism | Purpose | Status |
|---|---|---|
| `wc-cart-fragments` | mini-cart badge refresh, sitewide | disabled by this plugin |
| `wc-ajax=update_order_review` | **checkout refresh** | ✅ verified working — returns 5 fragments |

Our design depends only on the second. Verified by live POST: `update_order_review` responds
`{"result":"success"}` with all five fragments intact.

**Remaining question:** Side Cart binds `wc_fragments_refreshed` (`xoo-wsc-main.js:105`), which is
a cart-fragments event. Whether Side Cart's own refresh path substitutes for it is unconfirmed.

**Revised risk: Low** for checkout; **open** for Side Cart.

---

## 3. Feature ownership — resolved

The report shows several overlapping plugins, with clear winners. Legacy tables still hold data,
which is a trap: the presence of a table does not mean the plugin is live.

### Gift Cards → **PW WooCommerce Gift Cards Pro 3.45** (Pimwick)

| | |
|---|---|
| Active | ✅ PW WooCommerce Gift Cards **Pro** 3.45 |
| Inactive | PW WooCommerce Gift Cards (free) 2.48 — correctly disabled, Pro supersedes it |
| Inactive | Advanced Gift Cards for WooCommerce (Rymera) 1.4.2 — **legacy**, but `wp_acfw_gift_cards` still exists |
| Tables | `wp_pimwick_gift_card`, `wp_pimwick_gift_card_activity` (both hold data) |
| Product type | `pw-gift-card` registered — also `advanced_gift_card` from the legacy plugin |
| License | active, keyed |

**This is the Gift Card feature to relocate.** _TBD: render hook, AJAX endpoint, whether the
callback is removable._

### Points / "Pollen Points" → **Loyalty Program for WooCommerce 1.3.2** (WPGens)

| | |
|---|---|
| Active | ✅ Loyalty Program for WooCommerce (WPGens) 1.3.2 — update 1.3.8 available |
| Inactive | WPGens Points and Rewards 1.2.7 — earlier WPGens product |
| Inactive | **YITH WooCommerce Points and Rewards Premium 4.23.0** — legacy, but `wp_yith_ywpar_points_log` holds **18.5 MB** and `ywpar-*` post types persist |
| Tables | `wp_wpgens_loyalty_points_balance`, `_log`, `_ranks`, `_rank_rewards` (all hold data) |
| Related | WPGens Refer a Friend PREMIUM 4.4.2 (inactive), `wp_gens_raf*` tables |
| ⚠️ Custom patch | **"WPGens Free Shipping Coupon Fix" by BennettWebGroup 1.1.0 — ACTIVE** |

The store migrated **YITH → WPGens**. Confirm with the client that "Pollen Points" is the WPGens
Loyalty Program and that the YITH data is dead. The custom BennettWebGroup patch tells us WPGens
has a known conflict with free-shipping coupons — read it before touching anything in this area.

### Free Gift → **iThemeland Free Gifts For WooCommerce 2.6.0**

✅ **Ownership resolved 2026-08-13 — they do not overlap.** Both are live on checkout, doing
different jobs:

| Plugin | Role | Evidence |
|---|---|---|
| **iThemeland Free Gifts** | owns free-gift **pricing** | `cart_hook->set_price` @9999 on `before_calculate_totals`; `front-order->check_session_…` on `after_calculate_totals` |
| **UpsellWP Pro** | owns checkout **offers** | 5 render hooks — `before/after_payment`, `before/after_submit`, `checkout_billing` |

Eligibility timing is answered: iThemeland recomputes on **every totals calculation**, so our
refresh cycle triggers it naturally without special handling.

⚠️ Its **UI**, however, is wired to cart-page events only — see Finding 6. That is the real
problem with putting a Free Gift block on checkout.

### Coupons / discounts — four systems stacked

| Plugin | Version | Active | Notes |
|---|---|---|---|
| Advanced Coupons **Premium** | 104.0.4.1 | ✅ | BOGO, cart conditions, **store credit** (`wp_acfw_store_credits` has data), virtual coupons |
| Advanced Coupons Free | 4.7.5 | ✅ | required base |
| Acowebs WooCommerce Dynamic Pricing | 5.0.0 | ✅ | 12 `awdp_pt_rules` |
| _(leftover)_ WooDiscountRules | — | ❌ | `wp_wdr_rules`, `wp_wdr_order_discounts` tables remain |

Advanced Coupons **store credit** is a second balance system alongside gift cards and points.
Clarify with the client whether it's in use — it may be what they call something else entirely.

---

## 4. Plugins affecting Cart / Checkout — triage

`full` = needs its own detailed section · `noted` = touches checkout, peripheral · `safety` = must be neutralized locally

| Plugin | Ver | Area | Depth |
|---|---|---|---|
| **Elementor Pro** | 4.2.1 | **renders the checkout page itself** | **full** — see Finding 1 |
| Elementor | 4.2.2 | page framework | full |
| ~~CartFlows~~ | 3.1.4 | **not used — deactivated 2026-08-12** | none |
| **PW Gift Cards Pro** | 3.45 | gift cards | **full** |
| **WPGens Loyalty Program** | 1.3.2 | points | **full** |
| **iThemeland Free Gifts** | 2.6.0 | free gift | **full** |
| **Advanced Coupons Premium** | 104.0.4.1 | coupons, store credit | **full** |
| **WooCommerce Product Bundles** | 8.5.10 | cart line items | **full** — see §6 |
| **WooCommerce Subscriptions** | 9.1.0 | checkout rules | **full** |
| **All Products for Subscriptions** | 6.1.0 | cart/checkout | **full** |
| **Checkout Field Editor** (ThemeHigh) | 2.1.9 | billing/shipping fields | **full** |
| **Side Cart WooCommerce** (XootiX) | 2.7.7 | cart UX, cart URL | **full** |
| **Disable Cart Fragments** | 2.4.1 | AJAX fragments | **full** |
| **Avalara AvaTax** | 3.9.0 | tax — external API | **full** + safety |
| **Authorize.Net Gateway** (SkyVerge) | 3.10.18 | payment | **full** + safety |
| Acowebs Dynamic Pricing | 5.0.0 | pricing | full |
| UpsellWP Pro | 2.2.2 | cart offers | full |
| WooCommerce Tax | 3.6.12 | tax — external API | noted + safety |
| WooCommerce USPS Shipping | 5.4.0 | live rates — external API | noted + safety |
| WooCommerce Shipping | 2.3.13 | shipping | noted |
| ShipStation Integration **WR** (Bennett Web Group) | 0.4.0 | fulfillment — **custom fork** | full + safety |
| Cart Abandonment Recovery Pro + Free | 1.0.0 / 2.1.3 | checkout tracking, emails | noted + safety |
| Cloudflare Turnstile CAPTCHA | 1.42.1 | may gate checkout | noted + safety |
| Variation Swatches | 2.3.0 | variations | noted |
| TI WooCommerce Wishlist | 2.12.0 | — | noted |
| Sequential Order Numbers | 1.8.0 | order creation | noted |
| PDF Invoices (×3) | — | post-order | noted |
| Cancellation Surveys & Offers | 2.0.0 | subscriptions | noted |
| Cart Abandonment Recovery Pro + Free | 1.0.0 / 2.1.3 | ⚠️ **independent of CartFlows** — still active after its deactivation | noted + safety |
| WPCode Lite | 2.3.8 | **5 custom snippets** | **full** — see §5 |
| Mailchimp for WooCommerce | 6.2 | cart sync | safety |
| Metorik Helper | 2.0.10 | order sync | safety |
| PixelYourSite / TikTok / Site Kit | — | checkout tracking | safety |
| NitroPack / Redis Object Cache | — | caching | safety |
| Password Protected | 2.8.4 | blocks the site | safety |
| Wordfence | — | blocks local requests | safety |
| Jetpack (inactive) | 16.1.1 | | safety |

---

## 5. 🔴 Finding 4 — Custom code from a prior developer

There is pre-existing bespoke code, largely by **Bennett Web Group**. This is the least
documented and highest-risk surface, and it must be read before anything else.

| Item | Type | Status | Why it matters |
|---|---|---|---|
| **BWG Conditional Plugin Loader** 1.1 | **MU plugin** | active | ✅ **Read.** Filters `option_active_plugins` to *remove* 23 listed non-checkout plugins on the checkout page and on 7 named `wc-ajax` actions. **16** are actually stripped locally (7 of the 23 aren't installed). Nothing checkout-critical is unloaded. ⚠️ Two consequences in [CHECKOUT-HOOK-MAP.md](CHECKOUT-HOOK-MAP.md) §3.1 |
| **BEE-OCH Checkout Stock Protection** 1.1.0 | **MU plugin** | active | ✅ **Read.** Removes zero-stock items during `woocommerce_check_cart_items` @1, adds partial-stock errors @20, mutates the cart on `woocommerce_cart_loaded_from_session` @20, and injects `wp_footer` JS that re-triggers `update_checkout` on detecting its own notice (1.5 s re-entry guard). **Our refresh cycle must not ping-pong with it.** Not documented anywhere before this audit |
| **Bee-Och Performance — Cart/Checkout Dequeue** 1.0.0 | plugin | **inactive** | Present on disk, still inactive. Shows someone has already fought asset loading here |
| **WPGens Free Shipping Coupon Fix** 1.1.0 | plugin | active | Confirmed on the checkout path — 3 callbacks, on `applied_coupon` @20 and `removed_coupon` @20 |
| **ShipStation Integration WR** 0.4.0 | plugin | active on staging | **Deactivated locally** by the safety lockdown |
| **WPCode Lite** — snippets | DB-stored PHP | active | ✅ **Confirmed present locally: 3 published, 2 draft.** See Finding 10 — one fires on order completion. Content not yet read |
| `wp_snippets` table | DB | leftover | **19 rows.** Whether anything still executes is unverified |

**Status:** both MU plugins have now been read — they were the blocking unknowns, and the
conditional loader's behaviour is confirmed by runtime capture rather than inferred from its
source. The remaining unread custom code is the WPCode snippet bodies and the `wp_snippets` rows.

---

## 6. 🟡 Finding 5 — Product Bundles complicates cart editing

**WooCommerce Product Bundles 8.5.10** is active (`wp_woocommerce_bundled_items`,
`wp_wc_order_bundle_lookup`). Bundles create parent/child cart item relationships: bundled child
items are tied to a container item, and their quantity is derived from the container's.

This lands squarely on the "change quantity / remove item" requirement. Naively exposing a
quantity input and a remove link on every cart line will corrupt bundles — removing a child while
leaving the container, or letting a child's quantity drift from its parent's.

**Subscriptions** (9.1.0) and **All Products for Subscriptions** (6.1.0) add a parallel concern:
subscription line items carry recurring-total and sign-up-fee semantics, and some carts disallow
mixing.

Our cart-editing layer must ask each line item what editing it permits, rather than assuming a
uniform row. Design note for Phase 3.

---

## 6b. 🔴 Finding 6 — Plugin UI wired to cart-page-only events

**This is the most likely way this project ships a silent bug.**

`updated_wc_div`, `updated_cart_totals` and `updated_shipping_method` fire on `/cart/` and never
on checkout. Several plugins bind their re-initialisation to those events. Today that is harmless,
because the affected UI lives on the cart page. The moment checkout becomes the sole cart surface,
those bindings are dead code — and they fail **silently**, leaving stale or inert UI rather than
an error.

Grepped across the 69 scripts actually enqueued on checkout:

| Plugin | Cart-only binding | Also on `updated_checkout`? | Consequence |
|---|---|---|---|
| **PW Gift Cards** | `pwgc_bind_redeem_form` on `updated_wc_div` + `updated_shipping_method` (`pw-gift-cards.js:63-64`) | ❌ **No** — only `pwgc_bind_remove_link` is (`:68`) | **If we move the redeem form into a refreshed fragment, gift card redemption stops working after the first refresh.** It survives today only because the form renders at `before_checkout_form`, outside `#order_review` |
| **iThemeland Free Gifts** | DataTable init + `pagination_gifts(1)` on `updated_cart_totals` (`:23`, `:377`); carousel on `updated_wc_div` (`:482`) | ❌ Only for payment-method handling (`:205`) | **The Free Gift picker's pagination and carousel never initialise on checkout.** Directly blocks the "Free Gift" item in the PROJECT.md §3 target layout |
| **astra-child** | `update-subscription.js:10` on `updated_cart_totals` | partial | See Finding 8 |
| Side Cart | `updated_shipping_method` (`:316`), correctly guarded by `isCheckoutPage \|\| isCartPage` | ✅ `wc_fragments_refreshed updated_checkout` (`:105`) | Low risk |
| Subscriptions `wcs-cart.js` | `updated_cart_totals` | ✅ | Low risk |

**Mitigation pattern:** our plugin re-fires the initialisers on `updated_checkout`, or emits the
cart-page event the plugin expects after our refresh completes. Either way this is our plugin's
job — we do not patch the third-party files.

**Risk: High.** Every relocated block needs checking against this table before it moves.

## 6c. 🔴 Finding 7 — Rendering shipping mutates cart state

WooCommerce Subscriptions brackets the shipping render with paired state changes from three
independent subsystems (Resubscribe, Switcher, Synchroniser):

```
woocommerce_review_order_before_shipping  →  maybe_set_free_trial    ×3
woocommerce_review_order_after_shipping   →  maybe_unset_free_trial  ×3
```

Any partial refresh that fires `before_shipping` without reaching `after_shipping` leaves the cart
holding modified trial/sync state.

**Consequence: the order review block is an atomic rendering unit.** No per-row or per-section
refreshes. Live quantity editing must re-render all of it, exactly as `update_order_review`
already does. This constrains the entire cart-editing design — details in
[CHECKOUT-HOOK-MAP.md](CHECKOUT-HOOK-MAP.md) §2.3 and §6.

**Risk: High.**

## 6d. 🟡 Finding 8 — The child theme reimplements WPGens points logic

`astra-child/functions.php:1320` removes WPGens' own callbacks and substitutes a hand-written
replacement, on both cart and checkout:

```php
remove_action( 'woocommerce_checkout_order_review',
    [ 'WPGL_Points_Checkout', 'display_points_to_be_earned' ], 10 );
```

The replacement re-derives point totals from `WPGL_Points_Core::get_earning_actions()` in order to
bypass the plugin's guest/role checks. Its own comments are explicit: *"we reproduce by calling
the PUBLIC display method"*, *"copy their markup and call their calc by reflection."*

This is exactly the pattern [PROJECT.md](PROJECT.md) §2 forbids — hand-calculating what a plugin
owns. It will drift from WPGens on any plugin update, and an update to 1.3.8 is already available.

**Recommendation:** our plugin restores WPGens' own callback and relocates it, rather than
carrying the reimplementation forward. Done from our plugin; `astra-child` is not edited.

**Risk: Medium** — it works today, but it is a maintenance trap and it sits in the Promotions
block we are rebuilding.

## 6e. 🔴 Finding 9 — The fragment DOM contract

`update_order_review` returns **eight** fragments, six of them plugin-owned:

| Selector | Owner |
|---|---|
| `.woocommerce-checkout-review-order-table` | WooCommerce core |
| `.woocommerce-checkout-payment` | WooCommerce core |
| `.acfw-store-credit-user-balance` | Advanced Coupons |
| `.wpgens-points-redemption-block` | WPGens |
| `.wpgens-points-earning-notice-fragment` | WPGens |
| `div.xoo-wsc-container` | Side Cart (XooTiX) |
| `div.xoo-wsc-sc-cont` | Side Cart (XooTiX) |
| `div.xoo-wsc-slider` | Side Cart (XooTiX) — ships empty, still contractual |

> **Corrected 2026-08-13.** This finding originally said *five*. The three Side Cart fragments
> were missed because the extraction pattern assumed each fragment's HTML began with `<`, and
> Side Cart's begin with escaped whitespace. The omission was caught automatically on the first
> run of `Diagnostics\FragmentAssert` — the argument for asserting this contract in code rather
> than transcribing it from a one-off capture.

If our layout restructures the DOM such that any of these selectors no longer exists, that
plugin's UI stops updating — **no error, just stale numbers.** Store credit and points balances
showing pre-change values while totals show post-change values is precisely the class of bug that
survives casual testing and reaches production.

**These five selectors must be preserved.** They are also the cheapest automated regression check
we have: assert all five are present in every refresh response.

**Risk: High.**

## 6f. 🟡 Finding 10 — WPCode snippets are live and DB-stored

WPCode (plugin slug `insert-headers-and-footers`) is active and contributes 3 callbacks on the
checkout request. Confirmed in the local database — **3 published snippets**, stored as `wpcode`
posts and therefore invisible to any file-level grep:

| Snippet | Relevance |
|---|---|
| `WooCommerce Purchase DataLayer Push (Bee-Och)` | **fires on order completion** — directly on our path |
| `GTM Container - Head (Bee-Och)` | tracking |
| `GTM Container - Body (Bee-Och)` | tracking |

(2 further snippets are drafts and inert. Separately, `wp_snippets` holds **19 rows** of Code
Snippets residue — whether any still executes is unverified.)

#### ✅ Read 2026-08-13

**`WooCommerce Purchase DataLayer Push`** hooks `woocommerce_thankyou` @10 and reads the **order**,
not the cart:

```php
add_action('woocommerce_thankyou', 'beeoch_purchase_datalayer', 10, 1);
```

It guards against double-firing with a `_ga_tracked` order meta flag, then pushes a GA4 `purchase`
event with `transaction_id`, `value`, `tax`, `shipping`, `currency` and line items.

**Good news for Phase 3:** because it reads the finished order on a page downstream of checkout,
it is structurally insulated from the checkout rebuild. Provided we leave order creation alone —
which §2 requires anyway — it keeps working untouched.

Two pre-existing issues, neither introduced by us, both worth raising with the client:

1. It calls `$order->save()` during page render — a database write on a GET request.
2. `$order->get_items()` returns **Product Bundles container *and* child items**, so bundle
   purchases are likely double-counted in GA4 revenue.

**Revised risk: Low** for our project; the bundle double-count is an existing analytics accuracy
bug worth reporting.

### 🔴 Finding 10b — Five client-side trackers, three injection channels

Reading the snippets exposed something the plugin audit could not see. Beyond WPCode there are
**two further database-stored injection channels**, all writing into `<head>` on every page
including checkout:

| Channel | Storage | Carries |
|---|---|---|
| WPCode snippets | `wpcode` posts + `wpcode_snippets` **cached option** | GTM container |
| **Elementor Custom Code** | `elementor_snippet` posts | GA4, Facebook Pixel, Crazy Egg, Hyros, Hotjar (draft) |
| **Legacy Insert-Headers-and-Footers** | `ihaf_insert_header` option | Hyros universal script |

**Relevance to the audit, not just to safety:** any of these can inject JavaScript into the
checkout page, outside every hook and template we mapped. `Crazy Egg` and `Hotjar` bind to DOM
events for session recording, so a restructured checkout changes what they capture. Elementor
Custom Code in particular is a place future changes could be made by the client without touching
any file we control.

All were disabled locally — see [LOCAL-SAFETY.md](LOCAL-SAFETY.md) §4e for the restore
instructions. **They remain active on staging and production.**

⚠️ Hyros appears here *and* as 6 active webhooks (LOCAL-SAFETY §4b), yet appears nowhere in the
original system-report-derived inventory. Treat the plugin list as a lower bound on what runs.

## 7. Cart URL dependencies

| Item | Finding |
|---|---|
| Cart page | `#370` `/cart/` — `[woocommerce_cart]` |
| Checkout page | ✅ `#371` `/checkout/` — **corrected locally.** The imported DB had `woocommerce_checkout_page_id = 160020`, a retired `cartflows_step` post; since CartFlows isn't installed locally that would have rendered nothing |
| Side Cart WooCommerce | active — _TBD whether it links to `/cart/`_ |
| CartFlows | ✅ **Not installed locally.** No step routing exists, so the redirect-loop concern below is moot |
| Elementor Pro mini-cart override | overrides `cart/mini-cart.php` — _TBD which URL it links to_ |
| `wc_get_cart_url()` callers | _TBD_ |

✅ The CartFlows redirect-loop risk is **retired** — the plugin is absent. Redirect strategy now
depends only on Side Cart's links, the mini-cart override, and `wc_get_cart_url()` callers.

⚠️ Empty-cart behaviour confirmed: `/checkout/` 302-redirects to `/cart/` when the cart is empty.
If checkout becomes the sole surface, this redirect is itself something we must handle.

---

## 8. Open questions for the client

**Answered 2026-08-12:**

- ✅ Real checkout = `/checkout/`, an Elementor page using the Elementor Pro WooCommerce Checkout
  widget. Same on staging and production.
- ✅ The CartFlows step was the only staging experiment; CartFlows is unused and now deactivated.

**Answered 2026-08-13 by the code pass:**

- ✅ **Which plugin renders the Free Gift block** — neither exclusively. iThemeland owns free-gift
  *pricing*, UpsellWP owns checkout *offers*. See §3.
- ✅ **Checkout page setting** — it was indeed still pointing at the retired CartFlows step
  `#160020`. Corrected locally to `#371`. **Still needs fixing on staging/production.**
- ✅ **Elementor editing expectation** — project owner confirms either approach is acceptable
  provided compatibility is preserved. See Finding 1.

**Still open — need the client or prior developer:**

1. Is "Pollen Points" the **WPGens Loyalty Program**? Is the YITH data dead? *(The code pass shows
   WPGens is what runs on checkout, but the naming question stands.)*
2. Is Advanced Coupons **store credit** in use, and is it distinct from gift cards? *(It renders a
   row at `review_order_before_order_total` and owns one of the five fragments, so it is at least
   wired in.)*
3. Who maintains the Bennett Web Group custom code, and is it documented anywhere? *(Now more
   pressing — the stock-protection MU plugin was undocumented and mutates the cart.)*
4. Confirm no vendor-side registration was affected by the local copy — see
   [LOCAL-SAFETY.md](LOCAL-SAFETY.md) §7.

## 9. Template overrides we introduce

Project rule: **all custom code lives in our plugin.** Any override below is registered by our
plugin via the template-locating filters — `astra-child` is never edited.

| Template | Reason a hook was not enough | Woo version copied from | Review date |
|---|---|---|---|
| _(none yet)_ | | | |

## 10. Phase 3 delivery strategy — parallel running

Agreed 2026-08-13: build the new checkout **alongside** the existing one and compare, rather than
replacing in place.

**Primary — flag on the real checkout page.** Our plugin renders the new layout on `/checkout/`
only behind a flag (query parameter, or a specific user role). This matters for a specific
reason: `is_checkout()` resolves against page ID 371, and PW Gift Cards, WPGens and UpsellWP all
gate their rendering on it. On the real page with a flag, every plugin behaves exactly as it does
today and our rendering is the only variable.

**Secondary — a separate preview page** for client review. WooCommerce exposes a
`woocommerce_is_checkout` filter so a second page can report as checkout, but it is an imperfect
disguise: plugins testing `is_page( wc_get_page_id('checkout') )` directly are not fooled. Good
for visual preview, **not** trustworthy for correctness testing.

**Comparison is on computed outcomes, not markup** — markup differs by design:

| Check | Source |
|---|---|
| Totals, fees, taxes, shipping | `WC()->cart` after `calculate_totals()` |
| Applied coupons | `get_coupons()` — catches the auto-apply side effects |
| Available gateways | `woocommerce_available_payment_gateways` |
| **All five fragments present** | Finding 9 — the silent-failure detector |
| Hooks fired, in order | the audit probe, extended into a differ |

Strongest test: place an order through each path with an identical cart and diff the resulting
orders — line items, totals, meta, coupons, points awarded, gift card usage. Safe locally given
COD-only plus the mail and network guards.

**Why this is the low-risk option:** deactivating our plugin restores today's checkout exactly.
That rollback only exists because we edit neither the theme nor the Elementor page.

**What it does not prove:** that the *cutover* is safe. Parallel running validates correctness,
not the removal of `/cart/` — redirects, Side Cart links and `wc_get_cart_url()` callers (§7)
remain a separate exercise.
