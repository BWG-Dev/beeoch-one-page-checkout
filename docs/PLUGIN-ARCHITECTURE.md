# Plugin Architecture — One Page Checkout

**Status:** Implemented and working locally, 2026-08-13 — plugin v1.20.0. Milestones 1-5, 8 and 9
of §14 are done and owner-confirmed: cart quantity editing, item removal, and all four promotion
actions (coupon, store credit, points, gift card) apply correctly. Milestones 6, 7 and 10 remain.
Not yet exercised: last-item removal, Product Bundles, Subscriptions, and any test order.
**Depends on:** [CHECKOUT-HOOK-MAP.md](CHECKOUT-HOOK-MAP.md) · [CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md)

Every decision below traces to a Phase 2 finding. Where a decision is a judgement call rather
than a consequence of evidence, it says so.

---

## 1. What this plugin is

An **orchestrator**. It decides *where* things render and *when* the checkout refreshes. It does
not decide what a coupon is worth, whether a gift card is valid, or how many points a customer
has — [PROJECT.md](PROJECT.md) §2.

Concretely, it does four things and nothing else:

1. Adds quantity and remove controls to the existing checkout order-review table.
2. Relocates existing plugin output into the target layout.
3. Coordinates the refresh cycle after a cart change.
4. Repairs plugin JavaScript that only re-binds on cart-page events.

**Slug:** `beeoch-one-page-checkout` · **Namespace:** `Beeoch\OPC` · **Prefix:** `beeoch_opc_`

## 2. The three constraints that drove the design

| # | Constraint | Source | Consequence |
|---|---|---|---|
| 1 | Rendering shipping mutates cart state (Subscriptions' paired `maybe_set/unset_free_trial` ×3) | Compat Finding 7 | **No partial refresh.** The order-review block is atomic |
| 2 | PW Gift Cards and iThemeland re-bind only on cart-page events | Compat Finding 6 | A **re-binding shim** is mandatory, not optional |
| 3 | Five fragments, three plugin-owned, fail silently if their selector vanishes | Compat Finding 9 | Selector **existence** is a hard contract |

A refinement to constraint 3 discovered during design: the three plugin-owned fragments are
produced by callbacks on `woocommerce_update_order_review_fragments`, which build their HTML
independently of page position. So **position is flexible; existence is mandatory.** We may
relocate those blocks freely, provided the elements remain in the DOM.

## 3. Rendering strategy — filters first, templates last

[PROJECT.md](PROJECT.md) §2 requires minimising template overrides. Phase 2 makes that achievable
to an extent worth stating plainly:

**The quantity control needs no template override at all.** `review-order.php` renders the
quantity through a filter:

```php
apply_filters( 'woocommerce_checkout_cart_item_quantity',
    ' <strong class="product-quantity">&times;&nbsp;2</strong>', $cart_item, $cart_item_key );
```

> ⚠️ **Corrected 2026-08-13.** This section originally claimed the filter had "zero callbacks —
> verified, no contention". **That was wrong, and the verification had not been done**: the Phase 2
> probe never captured this hook, and the claim came from grepping the results for
> `cart_item_quantity`, which matched only the unrelated
> `woocommerce_after_cart_item_quantity_update`. The filter actually carries two callbacks:
>
> ```
> @1   WCSG_Checkout::add_gifting_option_checkout        (Subscriptions Gifting)
> @10  WC_Subscriptions_Cart::checkout_cart_item_details (recurring price, trial, sign-up fee)
> ```
>
> Subscriptions injects recurring pricing here *because the classic checkout has no separate
> Price column*. Replacing the filter's string would have silently deleted subscription pricing
> from checkout.

We therefore **wrap rather than replace**: run at priority 20, after both, and keep the incoming
markup intact inside the control. CSS hides only the core `.product-quantity` element, so anything
a plugin appended stays visible. If that markup ever changes shape the worst outcome is a stray
"× 2" beside the stepper — untidy, not broken.

`woocommerce_cart_item_name` is likewise occupied (`WC_PB_Display::cart_item_title` @10, UpsellWP
@100), so the remove control must follow the same wrap-don't-replace rule.

This means:

- No override of `review-order.php`. The stale `astra-child` copy stays exactly where it is,
  untouched, and remains functionally identical to core (Compat Finding 2).
- Every plugin filtering those same rows keeps working — we are one more filter in the chain.
- `woocommerce_get_item_data` — which carries bundle, subscription, gift-card and free-gift line
  data through **12 callbacks** — is left completely alone.

**Layout relocation** uses `remove_action` + `add_action` at our chosen hook, never
reimplementation. This is the "relocate an existing callback" rule from §2, and it is why the
target layout is achievable without owning any business logic.

If a template override later proves unavoidable, our plugin registers it via
`wc_get_template` — the child theme is never edited — and it gets justified in
[CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md) §9 first.

## 4. Structure

```
beeoch-one-page-checkout/
├── beeoch-one-page-checkout.php     bootstrap + environment guard only
├── src/
│   ├── Plugin.php                   wiring; nothing else instantiates directly
│   ├── Support/
│   │   ├── Environment.php          dependency + version guards (§5)
│   │   └── Flag.php                 parallel-run switch (§6)
│   ├── Orchestration/
│   │   ├── Layout.php               WHERE each block renders
│   │   ├── Relocations.php          the remove_action/add_action table
│   │   ├── RefreshCycle.php         server side of the refresh contract
│   │   └── PluginLoadFilter.php     option_active_plugins for our AJAX (§8)
│   ├── Cart/
│   │   ├── AjaxController.php       wc-ajax=beeoch_update_cart
│   │   ├── Mutations.php            thin wrapper over WC()->cart
│   │   └── ItemPolicy.php           what each line item permits (§9)
│   ├── Render/
│   │   ├── QuantityControl.php      the free-filter injection (§3)
│   │   └── RemoveControl.php
│   ├── Compat/
│   │   ├── Registry.php             shims register themselves; each is independently disableable
│   │   ├── GiftCardsPW.php          re-bind shim
│   │   ├── FreeGiftsIThemeland.php  re-bind shim
│   │   ├── LoyaltyWPGens.php        restore the plugin's own callback (§10)
│   │   └── Subscriptions.php        atomicity guard
│   └── Diagnostics/
│       ├── FragmentAssert.php       the five-selector check (§11)
│       └── Comparator.php           old-vs-new differ, dev only
├── templates/                       empty by intent
└── assets/{js,css}/
```

`Compat/` is deliberately one file per third-party plugin. When one of them updates and breaks,
the blast radius is one file, and the shim can be switched off without touching orchestration.

## 5. Bootstrap and guards

The plugin refuses to run rather than half-run. On missing dependencies it deactivates itself and
shows an admin notice — it never renders a partial checkout.

Hard requirements: PHP 8.1+, WooCommerce 11.x, classic checkout (no Blocks). Elementor Pro is
**not** a hard dependency — the widget is a thin wrapper (Compat Finding 1), so our filters work
with or without it. That is worth preserving: it means a future theme change does not strand us.

Checked but non-fatal — each drives a `Compat` shim, and their absence simply skips it:
PW Gift Cards, WPGens Loyalty, Advanced Coupons, Product Bundles, Subscriptions, All Products for
Subscriptions, iThemeland Free Gifts, UpsellWP, Side Cart.

## 6. Parallel running

Per [CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md) §10. `Support/Flag.php` is the only
place that answers "is the new checkout active for this request?":

```php
Flag::is_active()   // query param, user capability, or site option
```

Everything else asks the flag. No scattered conditionals.

Three states: **off** (plugin adds nothing — today's checkout, byte-identical), **flagged**
(new checkout for testing), **on** (new checkout for everyone). Rollback at any stage is
deactivation.

The flag lives on the **real** checkout page (ID 371), because `is_checkout()` resolves against
that ID and PW Gift Cards, WPGens and UpsellWP all gate on it. A separate page would silently
change plugin behaviour and make the comparison meaningless.

⚠️ **The query-argument mechanism is not comparison-clean.** Observed on the first flagged
render: the WOOF products-filter plugin harvests arbitrary query arguments into its own
front-end state, so `?beeoch_new=1` appears in the page as:

```js
woof_current_values = {"beeoch_new":"1"};
```

Harmless in itself, but it means the flag *is itself a variable* in any old-vs-new diff, which
weakens the claim that "our rendering is the only difference". Before the Comparator is used for
real sign-off runs, the flag should switch to a **cookie or user-meta** carrier so that no query
argument is present on either side of the comparison. `Support\Flag` is the only place that
would change.

## 7. The refresh contract

**Superseded by §8 — one round trip, not two.** As built:

```
qty change / remove
   ↓  debounced client-side (350 ms), coalescing rapid clicks
write edit + token + nonce into hidden fields inside form.checkout
   ↓
trigger update_checkout                    standard WooCommerce
   ↓
wc-ajax=update_order_review                already on the BWG allow-list
   ↓  woocommerce_checkout_update_order_review @5
   ↓     MutationHandler: verify nonce, reject replayed token
   ↓     Mutations: WC()->cart->set_quantity( $key, $qty, true )
   ↓  WooCommerce calculates totals and assembles every fragment
   ↓
updated_checkout                           clear carrier fields; Compat shims re-bind
```

The original two-trip design existed to avoid reimplementing fragment assembly. Riding the
existing action achieves the same thing more directly — we never assemble a fragment at all.

**We call `set_quantity()`, not our own quantity logic.** It fires
`woocommerce_after_cart_item_quantity_update`, which **Product Bundles already handles**
(`WC_PB_Cart->update_quantity_in_cart`) along with WPGens' points recalculation. Bundle child
quantity propagation therefore comes for free — provided we use the standard API and nothing else.

**Re-entrancy.** `beeoch-checkout-stock-protection.php` binds `updated_checkout` and re-triggers
`update_checkout` when it detects its own removal notice, with a 1.5 s guard. Our client code must
debounce, ignore refreshes it did not initiate, and never trigger a refresh from inside an
`updated_checkout` handler. Otherwise the two ping-pong.

## 8. ~~Our AJAX endpoint must strip plugins itself~~ — dissolved, no custom endpoint

**Revised 2026-08-13 during milestone 5.** The original plan — a `wc-ajax=beeoch_update_cart`
endpoint plus a `PluginLoadFilter` re-applying the BWG loader's removal list — **cannot be built
from a regular plugin.** `option_active_plugins` is read while WordPress loads, long before
`plugins_loaded`. That is precisely why the BWG loader ships as an MU plugin, and a regular
plugin has no hook early enough to filter it.

The options were: ship a second MU plugin, ask for our action to be added to client-owned code,
or duplicate the removal list and accept drift. All are worse than the alternative:

**Carry the edit on `update_order_review`, an action already on the loader's allow-list.**

`woocommerce_checkout_update_order_review` fires after `check_ajax_referer()` and before totals
are calculated (`WC_AJAX::update_order_review`, lines 397-405) — exactly the window a mutation
needs. The client writes the edit into hidden fields inside `form.checkout`; WooCommerce
serialises them into `post_data`; `Cart\MutationHandler` reads and applies it.

What this buys:

- **The stripped-plugin set on a cart edit is identical to a checkout render by construction**,
  not by a duplicated list. Verified: 36 active → 30 after, same 6 stripped, identical lists.
- One round trip instead of two — §7's two-trip design is superseded.
- Fragment assembly stays entirely WooCommerce's.
- Nothing is added to client-owned code, and no second MU plugin exists to maintain.
- `PluginLoadFilter` is not needed and was never written.

**Replay protection is now mandatory**, because a mutation lives on an action that fires for
every refresh — including the stock-protection MU plugin's re-trigger. Each edit carries a token;
a token already applied is ignored. Verified: replaying a token with a different quantity leaves
the cart unchanged.

**The flag must reach the AJAX request.** WooCommerce posts to `/?wc-ajax=update_order_review`,
which would otherwise miss the flag and ignore the edit. `Layout::carry_flag_to_ajax()` appends it
via the `woocommerce_ajax_get_endpoint` filter.

> ⚠️ **Never use `add_query_arg()` on that filter.** At that point the URL still holds
> WooCommerce's literal placeholder `?wc-ajax=%%endpoint%%`, which checkout.js later swaps out
> with a plain string replace. `add_query_arg()` re-encodes the query string, producing
> `%25%25endpoint%25%25`; the replace then matches nothing, every AJAX call goes to a nonexistent
> endpoint, and WooCommerce receives page HTML instead of JSON. `data.fragments` is undefined, so
> the unblock loop never runs and **the order summary stays blocked forever** — on page load, not
> just on interaction. Append by concatenation instead.
>
> This cost a debugging cycle in milestone 5. It fails silently in every direction: no PHP error,
> HTTP 200, no JavaScript exception, and the request has no `dataType` so jQuery does not even
> raise a parse error. Only a browser test surfaces it — which is why the emitted `wc_ajax_url`
> is worth adding to the Comparator's checks (§11).

## 8b. The Promotions panel — consolidating what was scattered

Built 2026-08-13 after two CSS passes failed to improve the checkout. The lesson: the problem
was **arrangement, not appearance**. Five promotional entry points across three hooks read as a
pile of unrelated widgets no matter how they are styled.

`Render\Promotions` gathers them into one `<details>` panel — native, so it is keyboard and
screen-reader accessible with no JavaScript — rendered on
`woocommerce_checkout_before_order_review`, which sits **outside `#order_review`** and therefore
outside every replaced fragment.

**Relocated server-side** via `Support\Hooks::detach()`, which matches on class + method so it can
remove a callback without owning the plugin's instance. Each block is then captured and re-run
verbatim — nothing is reimplemented, per §2:

| Block | Source hook | Captured |
|---|---|---|
| Advanced Coupons tabbed box | `woocommerce_checkout_order_review` @11 | ~1,640 bytes |
| WPGens points conversion | `woocommerce_checkout_order_review` @10 | ~1,510 bytes |

Verified safe: neither appears in the refreshed fragments, so relocating them cannot duplicate
them on a cart update.

**Two blocks resisted hook relocation and needed a DOM move instead** — the one place this plugin
breaks its own "CSS yes, DOM no" rule:

- **`.e-coupon-box`** — Elementor Pro renders it from a callback that *also emits structural
  markup*: it closes `#order_review` and opens `.e-checkout__order_review-2` around the coupon
  form. Detaching it leaves unbalanced divs and destroys the page. (This is also why core's
  `woocommerce_checkout_coupon_form` reports "not found": Elementor removes it and substitutes
  its own.)
- **`#pwgc-redeem-gift-card-form`** — PW Gift Cards renders it from
  `woocommerce_review_order_before_submit`, inside the replaced payment fragment. Suppressing it
  there would mean it never refreshes.

The move is re-run after every `updated_checkout`, and jQuery preserves event handlers across a
reparent, so the plugins' own bindings survive.

> **Trap, cost one round trip.** Use `[id="..."]`, never `#id`, when a node may exist twice.
> jQuery resolves `#id` through `getElementById`, which returns only the first match. After a
> refresh both the moved copy and the freshly rendered one exist; the moved copy comes first, so
> `#id` matched only it, the "fresh" set was empty, and the new copy stayed in place — the
> customer saw the gift-card form twice.

**Residual risk:** a future plugin update that renames those selectors silently stops the gather,
and the blocks revert to their original positions. A graceful failure, but it should be part of
the post-update checklist.

## 8c. Uniform promotion rows — the approach that worked, and the one that did not

Consolidating the four blocks into one panel (§8b) fixed *arrangement*. It did not make them
look or behave alike: they shipped three different collapsible implementations and one with
none, so the panel still read as a pile of unrelated widgets.

### The approach that failed

Drive each plugin's existing toggle, and restyle its header to match the others. Abandoned
after four rounds, each fix revealing the next problem:

| Symptom | Cause |
|---|---|
| Points opened and shut instantly; store credit never opened | Advanced Coupons and WPGens delegate to `h3`. Our injected heading matched their selector, so one click ran **their** handler *and* our forwarded trigger |
| Store credit could not be reopened | We collapsed it with `.hide()`, an inline `display:none`. ACFW shows its panel with a CSS **class**, which an inline style outranks — permanently |
| Points row grew two headings | WPGens nests two elements both matching `.wpgens-accordion`, and jQuery resolves `.find('> h3')` loosely, so both matched the same inner `h3` |
| Initial collapse silently did nothing | Plugins bind after our DOM-ready pass, so triggering their toggle hit nothing |
| Gift-card form flashed in the wrong place | It re-renders inside the replaced payment fragment on every refresh |

The root cause was one thing throughout: **two toggles coexisting**, ours and the plugin's,
each with its own idea of what was visible.

### The approach that worked

**Take the plugin's toggle out of play rather than driving it.**

1. Hide its trigger (`h3`, nudge paragraph, or label).
2. Pin its content permanently visible — `display`, `height`, `max-height`, `overflow`,
   `opacity`, `visibility`. jQuery's slide animations touch all of them, and the script may
   act on either the inner wrapper or the content div.
3. Wrap the block in our own `<details>`, which becomes the only thing that opens and closes.

`<details>` needs no JavaScript, is keyboard operable and screen-reader correct for free,
and cannot fall out of step with a plugin's state — because the plugin no longer has one.

Note the interaction between steps 2 and 3: pinning the content visible also defeats the
closed state of `<details>`, so our own `.beeoch-opc-acc__body` is hidden when the row is
shut. A `display:none` ancestor beats any `!important` on a descendant, so both hold.

### Where each row is built

| Row | Wrapped | Its toggle |
|---|---|---|
| Store credit (Advanced Coupons) | server-side, `Promotions::BLOCKS` `wrap => true` | made inert |
| Pollen Points (WPGens) | server-side | made inert |
| Coupon (Elementor) | client-side, `WRAP` in `checkout.js` | made inert |
| Gift card (PW) | client-side | never had one |

Server-side is preferred — it is in the markup on first paint, with no flash and no timing
race. The two client-side rows exist only because those blocks arrive by DOM move (§8b).

⚠️ **The gift-card row re-collapses after every refresh.** PW renders it inside the replaced
payment fragment, so each refresh produces a fresh element; `gather()` moves it and
`wrapRows()` wraps it again. The row the customer opened no longer exists. Acceptable, but it
should not surprise anyone later.

### CSS specificity — three lessons that each cost a round

These are not general advice; they are the specific traps in this codebase.

1. **Count classes before writing an override.** Elementor's checkout rules are consistently
   three classes deep (`.elementor-widget-woocommerce-checkout-page .woocommerce .x`), and so
   are several of our own. A two-class override silently loses. This caused "the CSS does
   nothing" more than once, including a whole styling pass that appeared to have no effect.
2. **An ID beats any number of classes, and `!important` does not change that.** PW Gift Cards
   styles `#pwgc-redeem-button` and `#pwgc-redeem-gift-card-form` by ID; our
   `.class .class input[id="..."]` selectors never came close, because attribute selectors
   only count at class level. `!important` breaks ties between declarations of *equal* weight
   — it does not promote a selector to a higher tier.
3. **Size in `rem`, not `em`, inside these rows.** `em` resolves against the element's own
   font-size, and the four plugins nest their content at different sizes. Identical
   declarations produced a button shorter than its input, and one row's button visibly larger
   than another's. Both vanished once heights, font-sizes and padding moved to `rem`.

Shared values live in variables (`--opc-btn-size`, `--opc-btn-weight`) so the rows cannot
drift apart again.

### On `!important`

The file uses it freely inside `.beeoch-opc-acc__body`, which is a deliberate consequence of
this design: we have decided our row owns presentation and the plugins' own styling should not
apply. It is scoped to that subtree and cannot leak. Outside it, `!important` appears only
where a plugin sets the property **inline**, which specificity cannot reach at all.

### What is deliberately not styled

The row heading. An earlier revision declared font-family, size and weight on it so the
buttons could share them, using a `h3 { font-size: 1.2rem }` rule read from the theme's
stylesheet — which turned out not to be the rule that wins. It made correct headings wrong.
**The theme styles the heading; we set only layout on it.**

## 9. Line-item editability

`Cart/ItemPolicy.php` answers, per cart line: *may quantity change? may it be removed? if not,
why not?* The UI renders from that answer — it never assumes a uniform row. Compat Finding 5
(Product Bundles) makes this mandatory; naive controls on every row corrupt bundles.

| Line type | Quantity | Remove | Rationale |
|---|---|---|---|
| Simple / variation | ✅ | ✅ | |
| Bundle **container** | ✅ | ✅ | `set_quantity` propagates to children via WC_PB |
| Bundle **child** | ❌ | ❌ | derived from container; edited independently it corrupts the bundle |
| Subscription | ✅ | ✅ | but recurring totals must re-render — never a partial refresh |
| Free gift (iThemeland) | ❌ | ⚠️ policy | auto-added by eligibility rules; removing it may re-add on next recalculation |
| Gift card product | ✅ | ✅ | |
| Renewal / resubscribe cart | ❌ | ❌ | `WCS_Cart_Renewal` owns cart contents entirely |

Policy is expressed as a filter (`beeoch_opc_item_policy`) so a future line type is a small
addition rather than a rewrite.

**The free-gift row is an open product decision** — see §13.

## 10. Compatibility shims

Each shim is small, single-purpose, and independently disableable.

**`GiftCardsPW`** — `pwgc_bind_redeem_form` binds only to `updated_wc_div` and
`updated_shipping_method`, never `updated_checkout` (`pw-gift-cards.js:63-64`). Today it survives
because the redeem form renders at `before_checkout_form`, outside the refreshed region. **The
moment we relocate it into the Promotions block, it stops re-binding.** The shim re-fires the
initialiser on `updated_checkout`.

**`FreeGiftsIThemeland`** — DataTable, `pagination_gifts()` and the carousel init on
`updated_cart_totals` / `updated_wc_div` only. On a checkout-only page they never fire, so the
gift picker renders inert. The shim emits the expected events after our refresh, or calls the
initialisers directly.

**`LoyaltyWPGens`** — the child theme removes WPGens' own callbacks and hand-rolls a replacement
"by reflection" (Compat Finding 8). The shim removes the **theme's** closure and restores the
plugin's callback, relocated to our layout. This deletes a maintenance trap and restores §2
compliance. **Done from our plugin — `astra-child` is not edited.** Needs verification that the
plugin's own guest/role gating produces acceptable output, since bypassing it was the theme's
stated motive.

**`Subscriptions`** — asserts atomicity: if anything attempts a partial re-render of the review
block, fail loudly in dev rather than silently corrupt trial state.

Shims re-fire **initialisers**, they do not reimplement plugin behaviour. That distinction is what
keeps them inside §2.

## 11. Diagnostics

**`FragmentAssert`** — on every refresh in dev mode, assert all five selectors are present. This
is the cheapest possible guard against the highest-consequence silent failure, and it runs in CI
as easily as in a browser.

**`Comparator`** — extends the Phase 2 audit probe into an old-vs-new differ: same cart, both
paths, compare totals, fees, taxes, applied coupons, available gateways, fragments present, and
hooks fired in order. A hook that fires on the old path and not the new one is a defect found
mechanically rather than by eye.

Both are dev-only and must not ship enabled.

## 12. Invariants

Never, under any flag:

- Touch order creation, `woocommerce_checkout_process`, or `woocommerce_after_checkout_validation`
- Hand-calculate a subtotal, discount, fee, tax, or shipping amount
- Edit WordPress core, WooCommerce, a third-party plugin, or `astra-child`
- Remove a callback we do not re-attach somewhere equivalent
- Add a hook between priority 9999 and 10000 on the totals hooks (Hook Map §2.6)
- Render the shipping section outside a full order-review render

## 12b. Accepted trade-offs

**AvaTax call volume — accepted, 2026-08-13.** Avalara is called on every totals calculation, and
this design recalculates on every quantity change, so a customer adjusting quantity three times
makes three tax API calls where the old checkout made none until the address changed.

Raised and accepted by the project owner: editable quantity on checkout is the requirement, and
recalculating is what makes the totals correct. The client-side debounce already coalesces rapid
clicks into one request. Not a defect and not to be re-litigated — recorded so nobody rediscovers
it as a surprise later.

## 13. Open decisions

1. ~~**Does `/cart/` stay?**~~ **Decided 2026-08-13 (project owner):** do not spend time rewriting
   links — redirect `/cart/` to `/checkout/` instead.

   **Implementation is two parts, not one.** The redirect alone is not sufficient and is unsafe on
   its own:

   - ⚠️ **Loop hazard.** `/checkout/` already 302-redirects to `/cart/` on an empty cart (verified).
     Adding `/cart/ → /checkout/` creates an infinite loop the moment the cart empties. Requires
     `add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false' )` **and** an
     empty-cart state rendered on checkout.
   - **Filter the URL at source**: `woocommerce_get_cart_url` → checkout URL. One line, and it
     re-points Side Cart, the Elementor mini-cart override, and every `wc_get_cart_url()` caller
     without a redirect hop. This does most of the work the link-rewriting exercise would have.
   - **Redirect as backstop** on `template_redirect`, for hardcoded `/cart/` links the filter
     cannot reach. Must skip AJAX, REST and admin requests, and must stay off for administrators
     while parallel running, so the comparator can still reach the old cart page.

   Scheduled as its own milestone (§14 #10) because it is a cutover concern, not a checkout-UI one.
2. **Free-gift row behaviour** — if a customer removes a free gift, should it stay removed?
   iThemeland recomputes eligibility on every totals calculation, so it may simply come back.
   Product decision, not technical.
3. **The numbered requirements** — `CHECKOUT-COMPATIBILITY.md` references "requirement 10 (use the
   existing visual design)" and "requirement 11", but the numbered list is not in `docs/`. Needed
   before UI work, since they constrain the design.
4. **WPGens role gating** — see `LoyaltyWPGens` above.

## 14. Build order

Each milestone is independently verifiable, and the first three ship no user-visible change.

| # | Milestone | Verified by |
|---|---|---|
| 1 | Bootstrap, guards, `Flag`, empty orchestration | Plugin activates; checkout byte-identical with flag off |
| 2 | `FragmentAssert` + `Comparator` | Differ reports zero delta old-vs-old |
| 3 | `ItemPolicy` + read-only rendering of policy | Correct verdicts per line type, incl. a bundle |
| 4 | Quantity control via the free filter | Stepper renders; no template override; no visual regression |
| 5 | ✅ **Done** — mutation on `update_order_review` (no custom endpoint, §8) | Quantity persists; replay and bad-nonce rejected; stripped-plugin list identical to a checkout render (36→30, same 6); browser-confirmed |
| 6 | Refresh cycle + re-entrancy guard | No ping-pong with stock protection; all five fragments each time |
| 7 | Compat shims | Gift card redeem + free-gift picker work **after** a refresh, not just on load |
| 8 | ✅ **Done** — promotions consolidated (§8b) and given uniform collapsible rows (§8c) | All four rows share one heading, chevron and input+button treatment; each still applies correctly; browser-confirmed |
| 9 | ✅ **Done** — remove control | Owner-confirmed 2026-08-13: increase, decrease and remove all working, and all four promotion actions apply correctly. Not yet exercised: last-item removal, bundles, subscriptions |
| 10 | `/cart/` cutover — URL filter + redirect + empty-cart state | No redirect loop on an empty cart; Side Cart and mini-cart links land on checkout; admins still reach `/cart/` |

Milestone 7 is the one to schedule generously. It is where the silent failures live, and the only
honest way to verify it is to refresh and *then* interact — not to load the page and assume.
