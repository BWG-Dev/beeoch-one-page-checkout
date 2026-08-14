# BEE-OCH One Page Checkout

Merges the WooCommerce **Cart** experience into **Checkout**, so customers can review and edit
their cart, apply every promotion the store offers, and pay — without leaving the page.

Built as an **orchestrator**, not a checkout rewrite. It decides *where* things render and *when*
the checkout refreshes. It does not decide what a coupon is worth, whether a gift card is valid,
or how many points a customer has — those stay with the plugins that own them.

## What it does

- **Quantity and remove controls** on each line of the order summary, applied through
  WooCommerce's own cart API so Product Bundles, points and coupons all recalculate normally.
- **One promotions panel** gathering four blocks that previously appeared at four different
  hooks: coupon, Advanced Coupons store credit, WPGens Pollen Points, and PW gift cards. Each is
  the plugin's own callback, re-run in a new place — nothing is reimplemented.
- **`/cart/` retired**: its URL is re-pointed at checkout and direct visits redirect, with the
  empty-cart state handled on checkout itself.

## Requirements

PHP 8.1+, WordPress 6.5+, WooCommerce 11.x, and a **classic** checkout. The plugin refuses to
activate rather than half-run: on a missing dependency it deactivates itself with an admin notice.

Elementor Pro is supported but not required.

## Install

No build step. Copy the folder to `wp-content/plugins/` and activate, or upload a zip of it.

## Rollback

Three levels, cheapest first:

| | How | Effect |
|---|---|---|
| Kill switch | set option `beeoch_opc_mode` to `off` | Original checkout returns instantly; plugin stays active |
| Deactivate | deactivate the plugin | Same, plus all hooks removed |
| Remove | delete the folder | Nothing of it remains |

Nothing outside this plugin is modified — no template overrides, no theme edits, no changes to
third-party plugin files — so any of the three fully restores the previous checkout.

`beeoch_opc_mode` accepts `on` (default), `off`, and `flagged` (renders the new checkout only for
users with `edit_shop_orders`/`manage_options` and `?beeoch_new=1`, for side-by-side comparison).

## Documentation

The design, the compatibility audit it is built on, and the reasoning behind each decision live in
the project's `docs/` folder, outside this repository:

- `PLUGIN-ARCHITECTURE.md` — structure, invariants, build order, accepted trade-offs
- `CHECKOUT-COMPATIBILITY.md` — per-plugin audit and findings
- `CHECKOUT-HOOK-MAP.md` — what actually runs on a checkout request, captured at runtime
- `TEST-MATRIX.md` — acceptance testing

## Status

Cart editing, removal and the promotions panel are working and confirmed in a browser. Not yet
exercised: last-item removal, Product Bundles, Subscriptions, and a completed test order.
Outstanding work is tracked as milestones 6, 7 and 10 in `PLUGIN-ARCHITECTURE.md` §14.
