# Local Environment Safety

**Status:** ✅ **Lockdown applied 2026-08-13.** 18 plugins deactivated, 18 webhooks disabled,
enforcement mu-plugin installed. Verified: no outbound call has succeeded from this install.
**Rule:** this lockdown runs **before** the local site is loaded in a browser for the first time.

> **Enforcement is now in code**, not just in this checklist.
> `wp-content/mu-plugins/zzz-beeoch-local-guard.php` blocks — and logs to `debug.log` with a
> `BEEOCH-GUARD` prefix — all outbound email, all webhook delivery, the Action Scheduler queue
> runners, and every outbound HTTP request to a non-loopback host. It self-disables unless
> `wp_get_environment_type() === 'local'`. To allow outbound HTTP temporarily (plugin installs,
> updates), define `BEEOCH_ALLOW_HTTP` in `wp-config.php`.
>
> Rollback: `active_plugins` was saved to `site/active_plugins.backup.json` before deactivation.

A production database copy carries production credentials. The moment WordPress boots with that
`wp_options` table, it can email real customers, hit a live payment gateway, push orders to a
fulfillment service, or fire webhooks — with nobody having "tested" anything on purpose. Cron
alone is enough: WP-Cron runs on page load, and Action Scheduler will happily process a queue of
production jobs it thinks are overdue.

---

## 1. Pre-flight — before the first page load

Add to `wp-config.php`, above `/* That's all, stop editing! */`:

```php
/* ---- LOCAL DEV ONLY ---- */
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
define( 'DISABLE_WP_CRON', true );          // no scheduled jobs until we vet them
define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
```

`WP_ENVIRONMENT_TYPE = local` matters beyond documentation: WooCommerce and several gateways
change behavior on it (Stripe, for one, warns or restricts live keys), and it disables auto-updates.

## 2. Outbound email — block it entirely

Nothing should leave this machine. Belt and braces:

1. Install a mail-capture plugin (WP Mail Logging / MailHog) **or** add a dev mu-plugin that
   short-circuits `pre_wp_mail` and logs instead.
2. Disable any SMTP plugin's live credentials (WP Mail SMTP, Post SMTP, SendGrid, Mailgun,
   Brevo, Klaviyo, Mailchimp).
3. In `php.ini`, leave `sendmail_path` unset so PHP `mail()` fails silently on Windows.

Test: place a test order and confirm **zero** mail attempts reach a real MTA.

## 3. Payment gateways — audit and neuter

Identified from the system report (staging, 2026-08-12):

| Gateway | Version | Status | Action |
|---|---|---|---|
| **WooCommerce Authorize.Net Gateway** (SkyVerge) | 3.10.18 | **active** | **Deactivate**, or switch to sandbox creds. `wp_woocommerce_payment_tokens` holds 1.5 MB of stored customer payment tokens — treat as PII. |
| Square | — | leftover table `wp_woocommerce_square_customers` | plugin gone; verify nothing calls it |

Note: the report's **Payment Gateway Support** section came back **empty**, which is unusual with
an active gateway. Worth confirming what's actually enabled at WooCommerce → Settings → Payments.

Rule: **replace live keys with sandbox keys, or deactivate the gateway.** Do not rely on "I just
won't click Place Order." Also disable any gateway webhook registration — several plugins
re-register webhooks against the current site URL on admin load, which can overwrite the source
site's webhook config on the gateway's side. That's an upstream-affecting write from a local
install, and it is the most likely way this project damages something real.

## 4. External integrations found — disable before first load

Many of these sync on `admin_init`, so this happens *before* browsing wp-admin, not after.

| Integration | Version | Risk | Action |
|---|---|---|---|
| **Avalara AvaTax** | 3.9.0 | calls Avalara on **every checkout total calculation**; can post transactions | **disable** — biggest checkout-time API risk |
| **WooCommerce Tax** | 3.6.12 | WooCommerce tax service API | disable; local rates in `wp_woocommerce_tax_rate*` (8.5 MB) should cover testing |
| _(leftover)_ TaxJar | — | `wp_taxjar_record_queue` remains | verify nothing runs |
| **ShipStation Integration WR** (Bennett Web Group, custom fork) | 0.4.0 | **creates real shipments**; `wp_wsi_wr_sync_*` sync tables | **disable** |
| ShipStation for WooCommerce (official) | 5.3.2 | inactive | keep inactive |
| **WooCommerce USPS Shipping** | 5.4.0 | live rate API calls | disable initially; re-enable deliberately if shipping-rate parity is needed |
| **Mailchimp for WooCommerce** | 6.2 | syncs carts/customers; `wp_mailchimp_carts`, `wp_mailchimp_jobs` | **disable** |
| MC4WP: Mailchimp for WordPress | 4.14.0 | list signups | disable |
| **Cart Abandonment Recovery Pro + Free** (CartFlows) | 1.0.0 / 2.1.3 | **emails real customers** who "abandon" a cart — and we will abandon many | **disable** |
| **Metorik Helper** | 2.0.10 | streams orders/customers to Metorik | **disable** |
| **PixelYourSite** | 11.2.3 | fires checkout events to ad platforms | disable |
| **TikTok** | 1.4.1 | pixel + catalog sync | disable |
| **Site Kit by Google** | 1.185.0 | Analytics/Ads; `wp_gla_*` Google Listings tables | disable |
| Google Listings & Ads | — | `wp_gla_*` — product sync to Merchant Center | verify inactive |
| **Tidio Chat** | 8.0.0 | external chat, loads on checkout | disable |
| **NitroPack** | 1.19.9 | remote CDN cache; **can purge the source site's cache** | **disable + delete `advanced-cache.php`** |
| **Redis Object Cache** | 2.8.0 | no Redis on WAMP → fatal/hang | **disable + delete `object-cache.php`** |
| **Wordfence** | — | blocks local requests, noisy scans | disable |
| **Password Protected** | 2.8.4 | gates the whole site; causes the failed loopback test | **disable** |
| **Cloudflare Turnstile CAPTCHA** | 1.42.1 | will fail on `beeoch.local` and may block checkout submission | **disable** |
| Jetpack | 16.1.1 | inactive, `wp_jetpack_sync_queue` present | keep inactive |
| **WooCommerce Subscriptions** | 9.1.0 | renewal payments | already in **staging mode** (live URL `disabled.beeoch...`); verify it stays that way locally |
| WooCommerce.com connection | ✔ connected | license seats may be consumed/moved by a new domain | expect nag notices; do not re-authenticate |
| Pimwick Gift Cards Pro license | keyed | domain check on activation | expect a license warning locally — do not "fix" it by moving the license |
| WooCommerce → Advanced → **Webhooks** | `wp_wc_webhooks` | fires on order/product events | ✅ **all 18 set to `disabled` 2026-08-13** — see below |
| WooCommerce **REST API keys** | `wp_woocommerce_api_keys` has rows | inbound access | review and revoke locally |
| Email: WP Mail SMTP Pro 4.7.1 | active | real SMTP creds in DB | **disable**; see §2 |
| Email: Disable Emails (WebAware) 1.8.3 | **already active on staging** | good — but verify locally rather than trust it | keep active |

### 4b. Webhooks — the gap this document originally had

The plugin inventory above came from the WooCommerce system report, which **does not list
webhooks**. Reading the database after import found 14 active ones, pointing at two live
third-party endpoints:

| Destination | Active | Topics |
|---|---|---|
| `api.hyros.com` | 6 | `order.created`, `order.updated`, `order.deleted`, `customer.created`, `customer.updated`, `customer.deleted` |
| `app.metorik.com` | 8 | `coupon.created/updated/deleted`, `customer.updated/deleted`, `order.deleted`, `product.created/deleted` |

**Hyros is an ad-attribution service that appears nowhere else in this project's documentation.**

Two points that make webhooks the highest-priority item, above plugin deactivation:

1. **Deactivating a plugin does not stop its webhooks.** These are WooCommerce core rows,
   delivered by WooCommerce itself. Killing the Metorik plugin leaves its 8 webhooks firing.
2. They fire on `customer.*` as well as `order.*`, so they do not require a test order — editing
   any of the ~230 real customer records is enough.

Status: all 18 rows set to `disabled`, and `woocommerce_webhook_should_deliver` is forced to
`false` by the guard mu-plugin so a row flipped back to active still cannot deliver.

### 4c. Confirmed blocked in practice

The guard's log immediately caught a live outbound authentication attempt on first page load:

```
BEEOCH-GUARD [http] blocked: https://public-api.wordpress.com/wpcom/v2/sites/215372972/
  jetpack-package-versions?token=<redacted>&signature=<redacted>
```

That is this local copy authenticating to WordPress.com **as the source site**, using the source
site's token — the §8 "upstream-affecting write from a local install" scenario, caught and
blocked. Also blocked: `api.wordpress.org` update checks and four IP-detection services
(`api.ipify.org`, `ident.me`, `ipecho.net`, `tnedi.me`).

Nothing succeeded. `wp_wsi_wr_sync_runs` confirms the newest ShipStation sync is `2026-08-12
12:42` — from staging, before the import — and zero webhook deliveries are logged.

### 4d. ⚠️ 212 pending subscription renewal payments

`wp_actionscheduler_actions` carries **212 pending `woocommerce_scheduled_subscription_payment`**
actions, earliest due `2026-08-14`, plus 158 pending renewal-notification actions. If Action
Scheduler ever runs, each is a real charge attempt.

Three independent things now prevent that: `DISABLE_WP_CRON`, the guard's Action Scheduler
filters, and Authorize.Net being deactivated. **Do not remove more than one of them at a time,
and never run `wp cron event run --due-now`.**

### 4e. 🔴 Client-side trackers the plugin lockdown could not reach

Found 2026-08-13 while reading the WPCode snippets. **The plugin-deactivation lockdown missed
these entirely**, because they are not plugins — they are markup stored in the database and
injected into `<head>` on every page, checkout included.

The network guard did not catch them either: `pre_http_request` blocks **server-side** requests,
and these are **browser-side** script tags. The server never makes the call, so there is nothing
for the guard to block.

Five injections across **three separate channels**, none of which a file-level grep would find:

| Tracker | Channel | Storage |
|---|---|---|
| GTM container `GTM-W93BF4MM` (head + body) | WPCode snippets | `wpcode` posts + **`wpcode_snippets` cached option** |
| Google Analytics 4 `G-7FDXFPHW67` | Elementor Custom Code | `elementor_snippet` post 17060 |
| Facebook Pixel | Elementor Custom Code | post 16270 |
| **Crazy Egg** (session recording) | Elementor Custom Code | post 53504 |
| **Hyros** `183090.t.hyros.com` | **legacy `ihaf_insert_header` option** | `wp_options` |

(Hotjar, also session recording, sits in Elementor Custom Code as a draft — inactive.)

**Why this mattered.** Opening the local site in a browser and placing a test order would have
fired a GA4 `purchase` event carrying real transaction data into the client's **production**
analytics property — via the `WooCommerce Purchase DataLayer Push` snippet, which hooks
`woocommerce_thankyou`. Crazy Egg would have recorded the session, including anything typed into
checkout fields.

**Actions taken (all reversible):**

| Action | Restore by |
|---|---|
| 2 WPCode GTM snippets → `draft` | set `post_status` back to `publish` |
| `wpcode_snippets` option deleted | WPCode rebuilds it from post status |
| 4 published `elementor_snippet` trackers → `draft` | set back to `publish` |
| `ihaf_insert_header` emptied | restore from `site/ihaf-backup-ihaf_insert_header.txt` |

Verified after the change: zero hits for googletagmanager, gtag, GTM-, connect.facebook, fbq,
hyros, crazyegg, hotjar, doubleclick and tiktok on a rendered checkout page.

⚠️ **Note the WPCode caching trap.** Setting the snippets to `draft` was *not* sufficient — WPCode
serves from the `wpcode_snippets` option, which kept emitting the drafted snippets until the
option was deleted. Any future snippet change needs that cache cleared to take effect.

**Generalised lesson for this project:** "deactivate the plugin" is not equivalent to "the
tracker is gone". Before any browser-based testing, re-run the tracker sweep against a rendered
page rather than trusting the plugin list.

## 5. Scheduled jobs

With `DISABLE_WP_CRON` set, nothing fires automatically. Before ever enabling it:

- Review WooCommerce → Status → **Scheduled Actions** for pending production jobs.
- Cancel or leave permanently paused anything that emails, syncs, or charges.
- If we need cron for a specific test, run that single hook deliberately via WP-CLI
  (`wp cron event run <hook>`), never a blanket `wp cron event run --due-now`.

## 6. Customer data

If the DB carries real customer records:

- Treat it as production PII. It stays on this machine; no copies into the repo, no logs
  containing emails or addresses, nothing pasted into external services.
- Before any flow that could email, either anonymize (`wp search-replace` on user emails to
  `user+ID@example.test`) or confirm section 2 blocks all mail. Anonymizing is the stronger move
  and worth doing if we'll be testing order emails repeatedly.
- Never commit a database dump to version control.

## 7. Verification before testing checkout

Sign-off checklist — all must be true before the first test order:

- [x] `WP_ENVIRONMENT_TYPE` is `local` — verified via `wp_get_environment_type()`
- [x] `DISABLE_WP_CRON` is `true`
- [x] Mail blocked — Disable Emails active, WP Mail SMTP Pro deactivated, `pre_wp_mail`
      short-circuited by the guard. WP Mail Logging kept active as the capture surface.
      *(Not yet exercised by a real test order — see the open item below.)*
- [x] Every payment gateway is sandbox or deactivated — Authorize.Net deactivated; only Cash on
      Delivery is offered at checkout, which charges nothing
- [x] All WooCommerce webhooks disabled — 18/18, plus filter-level enforcement
- [x] Fulfillment / ERP / CRM / marketing integrations disabled — ShipStation WR, Mailchimp ×2,
      Cart Abandonment ×2, Metorik
- [x] Analytics disabled — PixelYourSite, TikTok, Site Kit, WooCommerce Analytics.
      ⚠️ **This was initially signed off in error.** Deactivating plugins removed only the
      *server-side* and plugin-based tracking. Five further trackers were injected client-side
      from the database and survived the lockdown — see §4e.
- [x] Backup and security plugins disabled — Wordfence, NitroPack, Password Protected and
      Turnstile are absent from disk entirely, so they never load
- [x] Site URL search-replaced to the local URL — content tables verify clean; 2 inert
      `wp_options` rows remain (an `elementor_log` entry, a MalCare uninstall hook for a plugin
      that is not installed)
- [x] `home`/`siteurl` point to local — and `WP_HOME`/`WP_SITEURL` are hard-pinned in
      `wp-config.php`, so the DB cannot override them
- [ ] **Open — client/host confirmation** that this local copy is not registered anywhere
      upstream. Not something we can verify from here, and the blocked WordPress.com
      authentication in §4c makes it a real question: the WooCommerce.com connection and the
      Pimwick Gift Cards licence both do domain checks.
- [ ] **Open — customer email anonymisation** (§6). Mail is blocked three ways, so this is
      defence in depth rather than a blocker, but it is worth doing before repeated order-email
      testing.

## 8. Escalation

If a plugin is discovered that appears to have already written to a production system from this
local install — a webhook re-registration, a license domain change, an ERP sync — stop testing
and report it immediately. Don't attempt a quiet fix.
