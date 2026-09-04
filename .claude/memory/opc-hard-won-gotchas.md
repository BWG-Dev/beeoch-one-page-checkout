---
name: opc-hard-won-gotchas
description: "Traps in this checkout codebase that each cost a debugging round — silent failures, CSS precedence, and the diagnostic habit that would have avoided most of them"
metadata: 
  node_type: memory
  type: project
  originSessionId: 1887ba9f-9298-47dc-b1f7-5965a0a79b48
  modified: 2026-08-14T13:29:30.591Z
---

Each of these was found the slow way while building the One Page Checkout plugin. They fail
quietly, which is why they cost so much: no PHP error, no HTTP error, no JavaScript exception.

**Never `add_query_arg()` on `woocommerce_ajax_get_endpoint`.** The URL still holds WooCommerce's
literal `%%endpoint%%` placeholder, which checkout.js swaps with a plain string replace.
`add_query_arg()` re-encodes it to `%25%25endpoint%25%25`, the replace matches nothing, every
AJAX call reaches a nonexistent endpoint, and since core has no `error:` handler **the order
summary stays blocked forever** with nothing logged. Concatenate instead.

**CSS precedence, in the order these bit:**

1. *Count classes before writing an override.* Elementor's checkout rules are consistently three
   classes deep; two-class overrides silently lose. This produced several "the CSS does nothing"
   rounds, including a whole styling pass that appeared to have no effect.
2. *An ID beats any number of classes.* PW Gift Cards styles `#pwgc-redeem-button` by ID, and
   Astra styles `#order_review td.product-name` — no class-only selector reaches either.
   **`!important` DOES win here**, because neither declaration is itself important; what
   `!important` cannot do is break a tie against another `!important`. Both cases in this file
   were fixed by adding it, so reach for it once an ID is involved rather than adding classes.
3. *`max-width` beats `width`.* Elementor caps the product cell at
   `max-width: 150px` — five rounds of `width` rules were applied and then overruled. The owner
   found it in seconds with DevTools computed styles.
4. *Size in `rem`, not `em`, inside the promotion rows.* `em` resolves against each element's own
   font-size, and the four plugins nest content at different sizes, so identical declarations
   produced visibly different buttons.

**Uncleared floats masquerade as border bugs.** WooCommerce floats `dl.variation`'s `dt`/`dd`.
Floats do not contribute to parent height, so a cell's `border-bottom` draws ABOVE the variation
text and reads as a rule that stops halfway. It is a layout bug, not a border bug.

**Two toggles can never coexist.** Making four third-party collapsibles behave alike failed four
times while driving their toggles. What works: hide their trigger, pin their content permanently
visible, and let one `<details>` own visibility. See `PLUGIN-ARCHITECTURE.md` §8c.

**The habit that would have saved most of this:** when CSS "does nothing", ask the owner for the
computed style and the winning rule from DevTools instead of shipping another theory. Every time
that happened it resolved in one step; every time it was guessed at it took three or more. The
same applies to rendered markup — asking for the actual HTML of a block beat every attempt to
infer it from page dumps.

Related: [[opc-plugin-state]], [[user-runs-ui-tests]]
