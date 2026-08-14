# Test Matrix — One Page Checkout

**Status:** baseline not yet captured — blocked on Phase 0.

Two columns matter: **Baseline** is how the site behaves *today* with the separate Cart and
Checkout pages; **One Page** is behavior after our plugin. A test passes when One Page matches
Baseline, not when it merely "works". Capture the baseline **before** activating our plugin —
once it's active, the original behavior is no longer observable.

Status values: `☐` not run · `✅` pass · `❌` fail · `⚠️` differs but accepted (note why) · `N/A`

---

## 1. Cart contents

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 1.1 | Add simple product, view checkout | ☐ | ☐ | |
| 1.2 | Add variable product (variation shown correctly) | ☐ | ☐ | |
| 1.3 | Product with add-ons / custom cart item data | ☐ | ☐ | |
| 1.4 | Increase quantity | ☐ | ☐ | totals recalc |
| 1.5 | Decrease quantity | ☐ | ☐ | |
| 1.6 | Set quantity to 0 | ☐ | ☐ | should remove |
| 1.7 | Quantity above stock | ☐ | ☐ | error surfaces |
| 1.8 | Quantity below min / above max (if step rules exist) | ☐ | ☐ | |
| 1.9 | Remove single item | ☐ | ☐ | |
| 1.10 | Remove last item → empty cart state | ☐ | ☐ | what renders? |
| 1.11 | Undo remove (if Woo's undo link is kept) | ☐ | ☐ | |
| 1.12 | Rapid successive qty clicks (race condition) | N/A | ☐ | no double-apply |
| 1.13 | Backordered / out-of-stock item in cart | ☐ | ☐ | |
| 1.14 | Item price change while in session | ☐ | ☐ | |

## 2. Promotions

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 2.1 | Apply valid coupon | ☐ | ☐ | |
| 2.2 | Remove coupon | ☐ | ☐ | |
| 2.3 | Invalid coupon code | ☐ | ☐ | error message placement |
| 2.4 | Expired coupon | ☐ | ☐ | |
| 2.5 | Coupon with minimum spend, met / not met | ☐ | ☐ | |
| 2.6 | Coupon invalidated by a qty decrease | ☐ | ☐ | auto-removal + notice |
| 2.7 | Free-shipping coupon | ☐ | ☐ | shipping methods update |
| 2.8 | Apply gift card | ☐ | ☐ | |
| 2.9 | Remove gift card | ☐ | ☐ | |
| 2.10 | Invalid gift card code | ☐ | ☐ | |
| 2.11 | Gift card partially covering total | ☐ | ☐ | remaining balance |
| 2.12 | Gift card fully covering total | ☐ | ☐ | **gateway may disappear** |
| 2.13 | Apply Pollen Points | ☐ | ☐ | |
| 2.14 | Remove Pollen Points | ☐ | ☐ | |
| 2.15 | Points exceeding cart total | ☐ | ☐ | |
| 2.16 | Points + coupon stacked | ☐ | ☐ | |
| 2.17 | Points + gift card + coupon all stacked | ☐ | ☐ | high-risk combination |
| 2.18 | Points earned display (post-order) | ☐ | ☐ | |

## 3. Free Gift

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 3.1 | Gift offer appears when eligible | ☐ | ☐ | |
| 3.2 | Select a gift | ☐ | ☐ | |
| 3.3 | Change gift selection | ☐ | ☐ | |
| 3.4 | Remove gift | ☐ | ☐ | |
| 3.5 | Gift price is 0 in totals | ☐ | ☐ | |
| 3.6 | Qty increase crosses eligibility threshold → offer appears | ☐ | ☐ | |
| 3.7 | Qty decrease drops below threshold → gift removed | ☐ | ☐ | **most likely failure** |
| 3.8 | Removing the qualifying product removes the gift | ☐ | ☐ | |
| 3.9 | Gift cannot have its quantity edited by our controls | ☐ | ☐ | |
| 3.10 | Gift survives a checkout AJAX refresh | ☐ | ☐ | |
| 3.11 | Gift appears correctly on the created order | ☐ | ☐ | |

## 4. Addresses, shipping, tax

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 4.1 | Change billing country → fields update | ☐ | ☐ | |
| 4.2 | Change billing state/postcode → tax recalcs | ☐ | ☐ | |
| 4.3 | "Ship to a different address" toggle | ☐ | ☐ | |
| 4.4 | Change shipping address → rates update | ☐ | ☐ | |
| 4.5 | Switch shipping method | ☐ | ☐ | total updates |
| 4.6 | Shipping-class-driven rate changes after qty edit | ☐ | ☐ | |
| 4.7 | Free shipping threshold crossed by qty change | ☐ | ☐ | |
| 4.8 | No shipping methods available for an address | ☐ | ☐ | |
| 4.9 | Tax-exempt customer | ☐ | ☐ | |
| 4.10 | Virtual-only cart (shipping section hidden) | ☐ | ☐ | |
| 4.11 | Address autocomplete / validation plugin still functions | ☐ | ☐ | |

## 5. Accounts & validation

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 5.1 | Guest checkout | ☐ | ☐ | |
| 5.2 | Logged-in checkout | ☐ | ☐ | |
| 5.3 | Returning customer, saved addresses prefill | ☐ | ☐ | |
| 5.4 | Log in from the checkout page | ☐ | ☐ | cart persists |
| 5.5 | Create account during checkout | ☐ | ☐ | |
| 5.6 | Required field left empty | ☐ | ☐ | error placement |
| 5.7 | Invalid email / phone / postcode format | ☐ | ☐ | |
| 5.8 | Terms & conditions unchecked | ☐ | ☐ | |
| 5.9 | Custom fields from a field-editor plugin | ☐ | ☐ | |
| 5.10 | Errors scroll into view | ☐ | ☐ | |

## 6. Payment & order creation

⚠️ Use gateway **test/sandbox mode only**. See [LOCAL-SAFETY.md](LOCAL-SAFETY.md).

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 6.1 | Switch between gateways | ☐ | ☐ | fields swap correctly |
| 6.2 | Gateway list updates after a totals change | ☐ | ☐ | |
| 6.3 | Successful order (each active gateway) | ☐ | ☐ | one row per gateway |
| 6.4 | Declined / failed payment | ☐ | ☐ | |
| 6.5 | Retry after failure | ☐ | ☐ | cart intact |
| 6.6 | Order totals match what checkout displayed | ☐ | ☐ | |
| 6.7 | Order line items include gift + discounts + fees | ☐ | ☐ | |
| 6.8 | Order confirmation page | ☐ | ☐ | |
| 6.9 | Order emails (captured locally, not sent) | ☐ | ☐ | |
| 6.10 | Points/gift-card balances updated post-order | ☐ | ☐ | |
| 6.11 | Stock decremented correctly | ☐ | ☐ | |
| 6.12 | Zero-total order (fully covered by gift card/points) | ☐ | ☐ | |

## 7. Routing & navigation

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 7.1 | Direct visit to `/cart` | ☐ | ☐ | redirects to checkout |
| 7.2 | Direct visit to `/checkout` | ☐ | ☐ | |
| 7.3 | Visit `/cart` with an empty cart | ☐ | ☐ | no redirect loop |
| 7.4 | Visit `/checkout` with an empty cart | ☐ | ☐ | |
| 7.5 | "View cart" links in menus / mini-cart / notices | ☐ | ☐ | |
| 7.6 | Add-to-cart redirect setting (if "redirect to cart" is on) | ☐ | ☐ | |
| 7.7 | Plugin-triggered redirect to cart on error | ☐ | ☐ | no loop, error visible |
| 7.8 | Browser back after a qty change | ☐ | ☐ | stale DOM? |
| 7.9 | Browser forward | ☐ | ☐ | |
| 7.10 | Page refresh mid-checkout | ☐ | ☐ | state preserved |
| 7.11 | Two tabs editing the same cart | ☐ | ☐ | |
| 7.12 | Session expiry / long idle then submit | ☐ | ☐ | |

## 8. Resilience

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 8.1 | AJAX request fails (throttle/offline) | N/A | ☐ | UI recovers, no lost state |
| 8.2 | Slow AJAX — double submit prevented | N/A | ☐ | |
| 8.3 | Nonce expired | ☐ | ☐ | |
| 8.4 | JS disabled | ☐ | ☐ | degrade or block gracefully |
| 8.5 | Console free of errors through a full flow | ☐ | ☐ | |
| 8.6 | No PHP notices/warnings in debug.log | ☐ | ☐ | |

## 9. Layout

| # | Case | Baseline | One Page | Notes |
|---|---|---|---|---|
| 9.1 | Mobile (375px) | ☐ | ☐ | |
| 9.2 | Tablet (768px) | ☐ | ☐ | |
| 9.3 | Desktop (1440px) | ☐ | ☐ | |
| 9.4 | Our CSS doesn't leak to other pages | N/A | ☐ | |
| 9.5 | Keyboard navigation through qty/remove controls | ☐ | ☐ | |
| 9.6 | Screen-reader announcement of totals change | ☐ | ☐ | aria-live |

## 10. Regression sweep

| # | Case | Status | Notes |
|---|---|---|---|
| 10.1 | Shop / category / product pages unaffected | ☐ | |
| 10.2 | Mini-cart / cart fragments still update | ☐ | |
| 10.3 | My Account pages unaffected | ☐ | |
| 10.4 | Admin order screens unaffected | ☐ | |
| 10.5 | Plugin deactivation cleanly restores the old cart + checkout | ☐ | **must pass — this is the rollback path** |

---

Expand this matrix once the plugin inventory is known — each cart/checkout plugin found in
Phase 2 likely deserves its own rows.
