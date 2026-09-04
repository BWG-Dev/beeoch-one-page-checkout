# Local Setup Runbook

**Source:** `https://beeoch.nextsitehosting.com` — a **staging** site (`WP Environment Type: staging`),
not production. Confirmed from the WooCommerce system report dated 2026-08-12.

**Target:** `C:\wamp64\www\Bee Orc\` served at `http://beeoch.local`

---

## 0. Stack decisions (locked from the system report)

| Component | Source (staging) | Local — use this | Note |
|---|---|---|---|
| PHP | 8.1.32 | **php8.1.31** | Closest available. Do **not** use 8.2+ — 70 active plugins, several old. |
| Database | MariaDB 11.4.7 | **mariadb11.5.2** | Do **not** use MySQL 9.1.0 — collation/`sql_mode` differences will bite. |
| WordPress | 7.0.3 | 7.0.3 exactly | |
| WooCommerce | 11.0.1 | comes with the copy | |
| Web server | nginx 1.28.0 | Apache 2.4.62.1 | No `.htaccess` exists on the server; we generate a standard WP one locally. |
| Multisite | No | No | |
| HPOS | **Enabled**, sync **enabled** | preserve | Orders live in `wp_wc_orders*`. Matters for the DB plan. |
| Object cache | Redis (external) | **removed** | |
| Page cache | NitroPack | **removed** | |

Set the WAMP PHP version to 8.1.31 and the DBMS to MariaDB before importing.

---

## 1. Files — pull over SFTP

Site is nginx, so there is no `.htaccess` to fetch. Media is small (**886 attachments**), so the
whole uploads folder is worth taking.

### Take

```
/                          wp-config.php, index.php, wp-load.php, wp-settings.php, wp-blog-header.php, etc.
/wp-admin/                 all
/wp-includes/              all
/wp-content/plugins/       ALL (91 plugins — 70 active, 21 inactive)
/wp-content/mu-plugins/    ALL  ← critical, see below
/wp-content/themes/astra/          parent theme
/wp-content/themes/astra-child/    child theme "Bee-Och 2024"  ← contains a checkout override
/wp-content/languages/     all
/wp-content/uploads/       all, minus the exclusions below
```

### Skip

```
/wp-content/uploads/wc-logs/
/wp-content/uploads/woocommerce_uploads/     (keep the empty folder structure)
/wp-content/uploads/nitropack/
/wp-content/uploads/ewww/  /uploads/shortpixel*/
/wp-content/uploads/backup*  /uploads/*.sql  /uploads/ai1wm-backups/
/wp-content/cache/  /wp-content/wp-rocket-config/  /wp-content/nitropack/
/wp-content/upgrade/  /wp-content/uploads/wpforms/logs/
/wp-content/themes/*                          (any theme other than astra + astra-child)
```

### Drop-ins — special handling

These sit in `wp-content/` and load before plugins. Fetch them so we can read them, but they
must be **removed or renamed** before the local site is loaded:

| File | What it is | Local action |
|---|---|---|
| `object-cache.php` | Redis Object Cache | **delete locally** — no Redis on WAMP; site will fatal or hang |
| `advanced-cache.php` | NitroPack | **delete locally** |
| `db.php` | Query Monitor | **keep** — useful for the audit |

### Highest-priority files — grab these first, they're tiny

I can start the audit off these alone, before the rest finishes transferring:

```
/wp-content/mu-plugins/                                          ← all of it
/wp-content/themes/astra-child/                                  ← whole child theme
/wp-content/plugins/cartflows/
/wp-content/plugins/woo-gift-cards/          (PW Gift Cards Pro)
/wp-content/plugins/gens-loyalty*/           (WPGens Loyalty Program)
/wp-content/plugins/*free-gift*/             (iThemeland Free Gifts)
/wp-content/plugins/woocommerce/templates/checkout/
/wp-config.php
```

---

## 2. Database

**Plan A — full dump minus pure log tables. This is the recommended route.**

Total DB is 4.0 GB. Roughly 500 MB of that is self-contained log/tracking data that is safe to
drop and in several cases contains customer PII we have no reason to hold locally.

Trimming *further* than this — excluding orders — is where it gets dangerous, because HPOS data
sync is enabled: orders exist in both `wp_wc_orders*` **and** as `shop_order` posts in
`wp_posts`/`wp_postmeta` (60,553 of them). Removing one side and not the other produces a store
that looks fine and behaves wrong. If we need to trim orders, we do it **locally after import**,
where mistakes are free and repeatable.

### Export via WP Migrate Lite

The site already has **WP phpMyAdmin** installed, but a browser-driven 4 GB export will time out.
WP Migrate Lite chunks the export, writes it to disk, and does the URL/path search-replace during
export — so the dump arrives already pointed at the local URL with serialized data intact.

1. Install **WP Migrate Lite** on staging (WP admin → Plugins → Add New).
2. Migrate → **Export**.
3. Find & Replace:
   - `https://beeoch.nextsitehosting.com` → `http://beeoch.local`
   - `//beeoch.nextsitehosting.com` → `//beeoch.local`
   - path: `/var/www/...` (whatever it reports) → `C:/wamp64/www/Bee Orc`
4. **Advanced options → exclude data from these tables** (structure is kept):

```
wp_actionscheduler_logs
wp_actionscheduler_actions
wp_actionscheduler_claims
wp_cartflows_ca_cart_abandonment
wp_cartflows_ca_email_history
wp_cartflows_ca_email_tracking
wp_mailchimp_jobs
wp_mailchimp_carts
wp_wpml_mails
wp_wpmailsmtp_debug_events
wp_wpmailsmtp_emails_log
wp_wpmailsmtp_email_tracking_events
wp_wpmailsmtp_email_tracking_links
wp_e_submissions
wp_e_submissions_actions_log
wp_e_submissions_values
wp_404_to_301
wp_wfknownfilelist
wp_wffilemods
wp_wfhits
wp_wfhoover
wp_wfstatus
wp_wflivetraffichuman
wp_wfblockediplog
wp_wfsecurityevents
wp_wfauditevents
wp_wpr_rucss_used_css
wp_wpr_above_the_fold
wp_jetpack_sync_queue
wp_tinvwl_analytics
```

5. Compress with gzip. Expect roughly **500–800 MB** for the resulting `.sql.gz`.
6. Pull the file down over SFTP from `wp-content/uploads/wp-migrate-db/`.

### Tables that must keep their data — do not add these to the exclusion list

| Table group | Why |
|---|---|
| `wp_options` | Every WooCommerce and plugin setting |
| `wp_posts` / `wp_postmeta` | Products (157), variations (287), coupons (49,234), CartFlows steps, pages |
| `wp_users` / `wp_usermeta` | Test accounts, saved addresses, points balances |
| `wp_pimwick_gift_card*` | **Active gift card data** |
| `wp_wpgens_loyalty_*` | **Active points/rewards data ("Pollen Points")** |
| `wp_acfw_*` | Advanced Coupons store credit + virtual coupons |
| `wp_woocommerce_tax_rate*` | 8.5 MB of tax rates |
| `wp_woocommerce_shipping_zone*` | Shipping config |
| `wp_woocommerce_bundled_item*` | Product Bundles definitions |
| `wp_wc_product_*_lookup` | Product lookups |
| `wp_cuw_*` | UpsellWP offers |
| `wp_snippets`, `wp_wpr_*` (config ones) | Custom PHP snippets — see the audit |

**Plan B — only if the download proves impractical.** Additionally exclude the order tables
(`wp_wc_orders`, `wp_wc_orders_meta`, `wp_wc_order_*`, `wp_woocommerce_order_item*`,
`wp_wc_customer_lookup`, `wp_comments`, `wp_commentmeta`) — saves ~2.6 GB. This requires a
matching local cleanup: delete `shop_order` / `shop_order_refund` / `shop_subscription` rows from
`wp_posts`+`wp_postmeta`, then disable HPOS data sync locally so WooCommerce doesn't try to
backfill. I'll run that as a scripted step. Cost: no subscription or order test data, which we'd
then have to create by hand.

---

## 3. Local build sequence

Once files and dump are in place:

1. Create the vhost `beeoch.local` + `hosts` entry; point WAMP at PHP 8.1.31 + MariaDB 11.5.2.
2. Create the local database (utf8mb4).
3. Delete `wp-content/object-cache.php` and `wp-content/advanced-cache.php`.
4. Import the dump.
5. Rewrite `wp-config.php`: local DB creds, `WP_ENVIRONMENT_TYPE=local`, `DISABLE_WP_CRON`,
   `WP_DEBUG_LOG`, remove Redis constants, remove `WP_CACHE`.
6. Generate a standard WordPress `.htaccess`.
7. Verify `home` / `siteurl` options.
8. **Run the [LOCAL-SAFETY.md](LOCAL-SAFETY.md) lockdown — before the first browser load.**
   Deactivate: NitroPack, Redis Object Cache, Wordfence, Password Protected, Avalara AvaTax,
   Authorize.Net, ShipStation WR, Mailchimp, CartFlows Cart Abandonment, Metorik, PixelYourSite,
   TikTok, Site Kit, Tidio, Turnstile CAPTCHA, Jetpack.
9. Load the store, add a product, open the checkout step, confirm it renders.
10. Begin the Phase 2 audit.

---

## 4. Notes carried over from the report

- The real checkout is **`/checkout/`, an Elementor page using the Elementor Pro WooCommerce
  Checkout widget** — same on staging and production. See
  [CHECKOUT-COMPATIBILITY.md](CHECKOUT-COMPATIBILITY.md) Finding 1.
- ⚠️ Staging's WooCommerce **Checkout page** setting pointed at the retired CartFlows test step
  `#160020`. Confirm it is back on `/checkout/` **before** the DB export; otherwise fix it locally
  after import.
- **CartFlows is unused** and was deactivated 2026-08-12. Note that **Cart Abandonment Recovery**
  (Pro + free) is a *separate* Brainstorm Force plugin and stays active — it remains a local
  safety item.
- Staging is otherwise confirmed to mirror production.
- `astra-child/woocommerce/checkout/review-order.php` is an override at template version 5.2.0
  against a WooCommerce 11.0.0 core template — six majors stale, and directly in our path.
- `wp-content/mu-plugins/` contains **BWG Conditional Plugin Loader**, which conditionally loads
  plugins. Until it's read, no plugin list can be trusted to reflect what actually runs on the
  checkout request.
- **Password Protected** is active on staging — this is why the Product Bundles loopback test
  fails. Deactivate locally.
- **Disable Emails** (WebAware) is already active on staging, which is good, but we re-verify
  locally rather than rely on it.
