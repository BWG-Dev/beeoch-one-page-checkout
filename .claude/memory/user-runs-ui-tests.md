---
name: user-runs-ui-tests
description: The user performs browser/UI testing themselves; hand them a test script instead of driving the UI
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 1887ba9f-9298-47dc-b1f7-5965a0a79b48
  modified: 2026-08-13T18:15:55.517Z
---

Do not drive the browser or simulate front-end interaction to verify UI work. Build the change,
verify what can be checked server-side, then hand the user a short numbered test script and let
them run it.

**Why:** the user prefers to do UI testing themselves, and the curl-based harness proved both
unreliable and token-expensive on this project — WooCommerce guest sessions needed a priming
request before add-to-cart persisted, plugin activate/deactivate cycles silently dropped the
cart, and several "failures" turned out to be bad assertions rather than real defects.

**How to apply:** still verify everything that does not need a browser — PHP lint, which hooks a
plugin registers, cart/session state via WP-CLI `eval`, AJAX responses inspected as JSON,
database assertions. Stop before "click the button and see". When handing over, give the exact
URL, any login required, the numbered steps, and what correct looks like at each one. If a flag
or mode has to be set for testing, set it and say so.

Related: [[discuss-expensive-operations-first]]
