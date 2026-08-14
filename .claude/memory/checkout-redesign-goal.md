---
name: checkout-redesign-goal
description: "The real goal is a modern, intuitive checkout — CSS-only styling failed twice, the promotions blocks need relocating, not restyling"
metadata: 
  node_type: memory
  type: project
  originSessionId: 1887ba9f-9298-47dc-b1f7-5965a0a79b48
  modified: 2026-08-13T21:58:57.668Z
---

The objective is not a tidier default checkout. It is a **modern, intuitive checkout** that stays
compatible and functional. Stated by the project owner 2026-08-13, after two CSS attempts
produced no visible improvement.

**The actual problem, in the owner's words:** points, rewards, coupon and gift-card sections are
"messy", and the page is otherwise "the default checkout". Four separate plugins each inject their
own promotional block at a different hook, so the page reads as a pile of unrelated widgets rather
than one designed flow.

**Why CSS-only did not work, twice:**

1. First attempt added a second grid on `form.checkout` and inside `#customer_details`. It broke
   the page — `.col-2` (shipping) renders but is EMPTY unless "ship to a different address" is
   ticked, so billing was squeezed beside a blank bordered box.
2. Second attempt set Elementor's own design tokens (`--sections-*`, `--forms-*`,
   `--order-summary-*`, `--purchase-button-*`) instead of fighting its three-class selectors.
   Technically correct, still no visible improvement reported.

**Conclusion, since proven correct:** restyling could not fix this, because the problem was
*arrangement*, not appearance. The fix belonged in the orchestration layer.

**Done 2026-08-13 (plugin v0.4.1, confirmed working by the owner):** a "Discounts & rewards"
`<details>` panel now consolidates the Advanced Coupons box, WPGens points, Elementor's coupon
box and the PW gift-card redeem form. Two were relocated server-side by detaching and re-running
their callbacks; two needed a DOM move because Elementor's coupon callback also emits structural
markup and PW's form lives inside a replaced fragment. Details in
`docs/PLUGIN-ARCHITECTURE.md` §8b.

**Constraints that still bind any redesign:** the order-review block must re-render atomically
(Subscriptions' paired shipping hooks); all eight refresh fragments must keep their selectors
present; relocation must move existing callbacks rather than reimplement them. See
`docs/CHECKOUT-COMPATIBILITY.md` Findings 6, 7 and 9.

Before more visual work, get the owner's direction on the target design — a reference site, a
sketch, or which sections should merge. Building blind produced two dead ends.

Related: [[user-runs-ui-tests]]
