---
name: opc-plugin-state
description: "Where the One Page Checkout plugin stands — deployed to staging 2026-08-13, what works, what is untested, and the local state to resume from"
metadata:
  node_type: memory
  type: project
  originSessionId: 1887ba9f-9298-47dc-b1f7-5965a0a79b48
  modified: 2026-08-14T13:36:32.086Z
---

Plugin: `beeoch-one-page-checkout`, namespace `Beeoch\OPC`, **v1.25.0**. The repo IS the plugin
folder — `github.com/BWG-Dev/beeoch-one-page-checkout`, branch `master`. Remote switched to HTTPS
with Git Credential Manager, because neither SSH key on this machine is registered on the account
that can reach BWG-Dev.

**Deployed to staging 2026-08-13; confirmed working 2026-08-14** after the page-ID fix below. Milestones 1-5, 8, 9 and 10 of
`docs/PLUGIN-ARCHITECTURE.md` §14 are done and owner-confirmed in a browser: quantity editing,
item removal, all four promotion actions applying, and the `/cart/` → checkout redirect.

**Untested — do these first on staging:**

- **Removing the LAST item.** Separate code path: WooCommerce answers the next refresh with
  "Sorry, your session has expired", and `MutationHandler` suppresses that in favour of "Your cart
  is now empty". Never triggered.
- **A completed test order.** Order creation is untouched by design, but nobody has watched one
  go through.
- **Product Bundles** — `ItemPolicy` should give the container controls and NO remove on its
  children. Unit-tested with synthetic data only.
- **Subscriptions** beyond the recurring-total row, whose overflow was fixed in v1.22.0.

**Staging specifics:**

- **The checkout page setting WAS wrong on staging** (found 2026-08-14): `woocommerce_checkout_page_id`
  pointed at retired CartFlows step `#160020`, which is unpublished — so `get_permalink()` fell back
  to `?p=160020` and 404'd, and `is_checkout()` was false on `/checkout/`, meaning the plugin
  silently did nothing at all. Correct values: **cart 370, checkout 371**. A `?p=<id>` URL always
  means the post is not published; check that before diagnosing anything else. `CartRedirect` now
  stands down when the checkout page will not resolve, so this can no longer route customers to a 404.
- **Staging carries Elementor Custom CSS that exists in no file.** The `#order_review
  td.product-name { flex-direction: column }` rule that broke the quantity/remove alignment was
  Astra's own selector copied into the Elementor component's Custom CSS with a declaration added.
  It is emitted inline, so grepping themes and plugins finds nothing and local looks innocent.
  When a staging-only style cannot be found on disk, it is Elementor Custom CSS (widget Advanced
  tab, page settings, or Site Settings) — ask rather than hunt.
- Elementor emits the coupon form inside `.e-coupon-box` on staging but NOT locally, so the coupon
  row is gathered by JS there and absent here. Local cannot exercise that path.
- Neither MU plugin may exist on staging: `zzz-beeoch-local-guard.php` blocks ALL outbound mail,
  webhooks and HTTP, and `zzz-beeoch-audit-probe.php` is Phase 2 tooling.
- Staging runs ~70 active plugins to 35 locally — including AvaTax, ShipStation, Mailchimp and
  Authorize.Net, none of which this plugin has ever run beside.

**Local state:** `beeoch_opc_mode` = `on`. The flag was retired so staging behaves like live;
`off` is still the instant kill switch and `flagged` still exists for side-by-side comparison.
Test admin `beeoch_opc_tester` is local-only and must not reach staging; its password is in the
local WAMP install, not written down here — this file is mirrored into a shared repo.

**Verification split agreed with the owner:** everything checkable server-side is mine (lint,
registered hooks, WP-CLI `eval`, AJAX JSON, DB assertions); anything visual goes to the owner as a
numbered test script. See [[user-runs-ui-tests]].

Related: [[opc-hard-won-gotchas]], [[checkout-redesign-goal]], [[local-stack-and-cli-gotchas]]
