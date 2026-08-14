# Bee Orch — One Page Checkout

**Status:** Phase 0 complete — local site running at `http://beeoch.local`. Phase 2 audit is next.
**Last updated:** 2026-08-13

---

## 1. Goal

Merge the existing WooCommerce **Cart** and **Checkout** experiences into a single enhanced
Checkout page, delivered as a purpose-built plugin for this store.

The Checkout page becomes the only cart/checkout surface. Users can view cart items, change
quantities, and remove products without leaving Checkout. Everything WooCommerce and the
installed plugins already do — coupons, gift cards, Pollen Points, free gifts, shipping, taxes,
fees, validation, gateways, order creation — must keep working exactly as it does today.

## 2. Engineering principle

This is a **compatibility and orchestration problem**, not a checkout rewrite.

```
Existing WooCommerce + existing plugins   ← own all business rules
                 ↓
   One Page Checkout orchestrator         ← owns placement, layout, cart editing, AJAX coordination
```

The plugin decides **where** things render and **when** the checkout refreshes. It does not
decide what a coupon is worth, whether a gift card is valid, or how many points a customer has.

Hard rules:

- Prefer WooCommerce hooks/filters and existing plugin APIs over reimplementation.
- Prefer relocating an existing callback over rewriting it.
- Never edit WordPress core, WooCommerce core, or third-party plugin files.
- Never hand-calculate subtotals, discounts, fees, shipping, or taxes.
- Minimize template overrides; if one is unavoidable, justify it in
  [CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md) before writing it.
- All permanent custom code lives in our plugin.

## 3. Target layout (conceptual — subject to client feedback)

```
Checkout
├── Cart Items ......... details, qty controls, remove, Free Gift
├── Promotions ......... Coupon, Gift Card, Pollen Points
├── Customer Details ... Billing, Shipping
├── Shipping Methods
├── Order Review
├── Payment Methods
└── Place Order
```

Presentation stays separated from checkout business logic so the layout can move later without
touching the orchestration layer.

## 4. Phases

| Phase | Description | Status |
|---|---|---|
| 0 | Prepare local WAMP environment (files + DB + config) | ✅ **Complete** 2026-08-13 — see [LOCAL-SETUP.md](LOCAL-SETUP.md) |
| 1 | Determine actual checkout architecture (classic / blocks / third-party / theme) | ✅ **Confirmed from running code** — classic (non-Blocks), rendered by the Elementor Pro widget `woocommerce-checkout-page.default` on page ID **371**. |
| 2 | Plugin & theme compatibility audit → [CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md), [CHECKOUT-HOOK-MAP.md](CHECKOUT-HOOK-MAP.md) | ✅ **Done for the checkout request** 2026-08-13. Findings 1–3, 5 resolved; 6–10 new. Not covered: the cart page, Subscriptions' renewal/switching paths, WPCode snippet bodies |
| 3 | Propose plugin architecture | ✅ **Proposed** 2026-08-13 → [PLUGIN-ARCHITECTURE.md](PLUGIN-ARCHITECTURE.md). Awaiting sign-off on the 4 open decisions in §13 |
| 4 | Implementation | Not started — build order in [PLUGIN-ARCHITECTURE.md](PLUGIN-ARCHITECTURE.md) §14 |
| 5 | Test against [TEST-MATRIX.md](TEST-MATRIX.md) | Not started |

No implementation begins before the Phase 2 audit is written down.

## 5. Current state of this folder

Updated 2026-08-13. `C:\wamp64\www\Bee Orc\` contains:

```
.idea/      PhpStorm project metadata only
docs/       this documentation
site/       the local WordPress install — DocumentRoot for http://beeoch.local
```

**The local site is up and checkout renders.** Verified end to end: added a simple product to
the cart over HTTP and loaded `/checkout/` — HTTP 200, no fatals, billing fields, order review,
coupon field, and Place Order all present.

| Item | Value |
|---|---|
| URL | `http://beeoch.local` (vhost + hosts entry in place) |
| Database | `beeoch_local` on **MariaDB 11.5.2, port 3307** (MySQL 9.1.0 holds 3306) |
| PHP | 8.1.31 |
| Import | 205/205 tables, 733 MB, from `beeoch_staging_without_orders_and_userdata.sql.gz` |
| Active plugins | 45 (69 in the dump, minus the 18-plugin safety lockdown and 6 absent from disk) |
| Checkout page | **371** — was wrongly set to 160020, a retired CartFlows step; corrected locally |
| Payment gateway | Cash on Delivery only — Authorize.Net deactivated |

**What the dump does and does not contain.** Orders are absent as the filename promises, and so
are gift card balances (`wp_pimwick_gift_card*`), Advanced Coupons store credit (`wp_acfw_*`),
and Pollen Points balances (`wp_wpgens_loyalty_points_balance` / `_log`). Those three are core
checkout surfaces for this project, so **that test data has to be created by hand locally.**
Products (89 published), coupons (48,783), tax rates, shipping zones, and Product Bundles
definitions all came through intact.

⚠️ **The dump is *not* free of user data despite its name.** `wp_users` holds ~230 real customer
accounts with live email addresses. Treat as production PII — see [LOCAL-SAFETY.md](LOCAL-SAFETY.md) §6.

The dump was a raw `mariadb-dump`, not the WP Migrate export [LOCAL-SETUP.md](LOCAL-SETUP.md) §2
assumed, so no search-replace had been applied. Done locally after import: ~538k URL replacements
across four passes (including Elementor's JSON-escaped `https:\/\/` form) and 93,494 path
replacements. Content tables verify clean.

The source is a **staging** site — `https://beeoch.nextsitehosting.com` — not production. Access
is WP admin + SFTP. Stack decisions and the copy procedure are in [LOCAL-SETUP.md](LOCAL-SETUP.md);
the full environment inventory is in [CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md) §1.

Headline: WP 7.0.3 · WooCommerce 11.0.1 · PHP 8.1.32 · MariaDB 11.4.7 · HPOS on ·
Astra + "Bee-Och 2024" child theme · **70 active plugins**.

**Checkout = `/checkout/`, an Elementor page built with the Elementor Pro WooCommerce Checkout
widget.** Classic (non-Blocks). Elementor Pro therefore owns checkout rendering and is the layer
our plugin must cooperate with — see [CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md)
Finding 1. Staging otherwise mirrors production; CartFlows was a retired experiment and is off.

## 6. Open questions

**For the client / prior developer:**

1. Is "Pollen Points" the **WPGens Loyalty Program**, and is the legacy YITH points data dead?
2. Is Advanced Coupons **store credit** in use, and how does it differ from gift cards here?
3. Which plugin renders the Free Gift block — **iThemeland Free Gifts**, or **UpsellWP**?
4. Who owns the **Bennett Web Group** custom code (2 MU plugins + 2 plugins), and is it documented?
5. ~~Does the client expect to keep editing the checkout layout **in Elementor** afterwards?~~
   **Answered 2026-08-13 (project owner):** either a custom checkout or the Elementor Pro checkout
   component is acceptable — **the binding constraint is that it stays compatible with, and
   functional under, the current setup**, not which technology renders it.

   *Recommendation on that basis:* keep the Elementor Pro `woocommerce-checkout-page` widget as
   the mount point and have our plugin orchestrate inside it, rather than replacing it. Reasons:
   it is what renders today so it is the lowest-compatibility-risk path; it preserves the
   client's ability to edit the page in Elementor should they want it later; and it keeps us on
   the "relocate existing callbacks, don't rewrite" principle in §2. Replacing the widget with a
   PHP-rendered layout stays available as a fallback if the widget proves too closed to
   orchestrate — Phase 2 will establish which.

**To answer from code once the copy lands:**

6. ~~What does **BWG Conditional Plugin Loader** actually load and unload?~~ **Answered
   2026-08-13** — `mu-plugins/bwg-conditional-loader.php` filters `option_active_plugins` and
   *removes* 23 non-checkout plugins (SEO, PDF invoices, YouTube feeds, image optimizer, admin
   tools…) on the checkout page and on 7 named `wc-ajax` actions: `update_order_review`,
   `checkout`, `add_to_cart`, `apply_coupon`, `remove_coupon`, `get_refreshed_fragments`,
   `xoo_wsc_refresh_fragments`. Nothing checkout-critical is unloaded.
   **Two consequences for our plugin:** (a) any *new* `wc-ajax` action we introduce is not in that
   list, so those 23 plugins **will** load on our AJAX requests — a parity and performance
   difference we must either accept or patch into the loader; (b) our plugin must never be added
   to the removal list.
7. How does the **Elementor Pro Checkout widget** render, which WooCommerce templates does it
   register, and which standard checkout hooks fire inside it? *(Partly answered: the widget is
   confirmed rendering as `data-widget_type="woocommerce-checkout-page.default"` on page 371. The
   template and hook questions remain.)*
7b. **New — undocumented mu-plugin.** `mu-plugins/beeoch-checkout-stock-protection.php` (Bennett
   Web Group, v1.1.0) is not mentioned anywhere in the original inventory and sits directly in our
   path. It **removes cart items** during `woocommerce_check_cart_items` at priority 1, adds
   partial-stock errors at priority 20, mutates the cart on
   `woocommerce_cart_loaded_from_session`, and injects `wp_footer` JS that re-triggers
   `update_checkout` when it detects its own removal notice. Any cart-editing UI we build has to
   cooperate with this or it will fight us over cart state and refresh cycles.
8. Which template actually wins for `checkout/review-order.php` — core, Elementor Pro, or
   `astra-child` (stale at v5.2.0) — and which hooks are consequently not firing today?
9. Does any plugin depend on the `/cart` endpoint internally?
10. What do the 5 **WPCode** snippets do?

## 7. Documents

| File | Purpose |
|---|---|
| `PROJECT.md` | This file — goal, principles, phase tracking |
| `MIGRATION-CHECKLIST.md` | What to copy from production and how |
| `CHECKOUT-COMPATIBILITY.md` | Per-plugin audit (Phase 2) |
| `CHECKOUT-HOOK-MAP.md` | Hook/filter/AJAX/JS event map (Phase 2) |
| `PLUGIN-ARCHITECTURE.md` | Plugin design, invariants, build order (Phase 3) |
| `TEST-MATRIX.md` | Acceptance testing |
| `LOCAL-SAFETY.md` | Preventing production side effects from the local copy |
