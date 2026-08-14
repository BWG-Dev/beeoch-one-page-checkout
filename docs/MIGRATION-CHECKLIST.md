# Migration Checklist — Production → Local WAMP

**Target:** `C:\wamp64\www\Bee Orc\`
**Goal:** *checkout behavioral parity*, not a byte-for-byte clone.

The database is the expensive-to-fake part and the cheap-to-copy part. Files are the opposite —
`wp-content/uploads` is usually 90%+ of the disk footprint and ~0% of checkout behavior.

---

## A. Files — copy these

Preserve the production directory layout under `C:\wamp64\www\Bee Orc\`.

| Path | Copy? | Notes |
|---|---|---|
| WordPress core (`wp-admin`, `wp-includes`, root `*.php`) | ✅ Yes | Or a clean download of the **same** WP version. Version must match production. |
| `wp-config.php` | ✅ Yes | We will rewrite DB creds + add local safety constants. Bring it so we can see production constants (HPOS flags, gateway keys, custom defines). |
| `.htaccess` | ✅ Yes | Small, occasionally has redirects that matter. |
| `wp-content/themes/<active-theme>` | ✅ Yes | Full theme. |
| `wp-content/themes/<child-theme>` | ✅ Yes, if one exists | Critical — child themes are the usual home of checkout overrides. |
| `wp-content/themes/*` (other, inactive) | ❌ Skip | Except keep one default (`twentytwentyfour` etc.) as a fallback for isolation testing. |
| `wp-content/mu-plugins/` | ✅ Yes — all of it | Invisible in the plugins screen, frequently contains checkout hacks. |
| `wp-content/plugins/` | ✅ **All of them**, active and inactive | See note below. |
| `wp-content/uploads/` | ⚠️ Partial | See "Uploads" below. |
| `wp-content/languages/` | ✅ Yes | Small; translated strings can be used as DOM selectors by bad plugins. |
| `wp-content/upgrade/`, `*/cache/`, `wp-content/backup*`, `wp-content/ai1wm-backups/` | ❌ Skip | Regenerable junk, often huge. |
| Any `vendor/` at site root (Composer-managed WP) | ✅ Yes | If the site is Composer-managed, tell me — it changes plugin install flow. |

**Why all plugins, even inactive ones:** the audit needs to know what *could* be reactivated,
and inactive plugins are cheap. If total plugin size is a problem, the must-have set is anything
matching: `woocommerce*`, `*checkout*`, `*cart*`, `*coupon*`, `*gift*`, `*card*`, `*point*`,
`*reward*`, `*loyal*`, `*ship*`, `*tax*`, `*stripe*`, `*paypal*`, `*payment*`, `*gateway*`,
`*subscription*`, `*bundle*`, `*discount*`, `*fee*`, plus the theme's required plugins.

### Uploads — what we actually need

Skip the bulk. We need enough images that the checkout page doesn't render as broken boxes:

- Copy the **2 most recent months** of `uploads/YYYY/MM/`, **or**
- Copy nothing and let placeholder images show — acceptable for logic work, annoying for layout work.
- Do copy: any `uploads/woocommerce_uploads/` **structure** (can be empty), the site logo, and
  anything the theme references directly.
- Never copy: `uploads/wc-logs/`, `uploads/backups/`, `uploads/*.sql`, `uploads/wpallimport/`.

**Recommended:** copy 2 months of uploads. If layout fidelity matters more later, we can add more.

---

## B. Database — copy the whole thing (preferred)

A full `mysqldump` is by far the safest option. WooCommerce checkout behavior is spread across
`wp_options`, `wp_postmeta`, `wp_termmeta`, `wp_usermeta`, and WooCommerce's custom tables, and
a hand-picked subset reliably misses something.

```bash
# On production (or via hosting panel / phpMyAdmin export)
mysqldump --single-transaction --quick --routines --events \
  --no-tablespaces \
  -u USER -p DBNAME > beeorch.sql
```

### If a full dump is too large

Dump **schema for everything + data for everything except** the log/bloat tables:

```bash
# 1) full schema
mysqldump --no-data -u USER -p DBNAME > schema.sql

# 2) data, excluding bulky non-essential tables
mysqldump --no-create-info -u USER -p DBNAME \
  --ignore-table=DBNAME.wp_actionscheduler_logs \
  --ignore-table=DBNAME.wp_actionscheduler_actions \
  --ignore-table=DBNAME.wp_wc_download_log \
  --ignore-table=DBNAME.wp_woocommerce_log \
  --ignore-table=DBNAME.wp_wfhits \
  --ignore-table=DBNAME.wp_wf_ \
  --ignore-table=DBNAME.wp_statistics_visitor \
  > data.sql
```

**Tables that must come with data — do not exclude these:**

| Table | Why |
|---|---|
| `wp_options` | WooCommerce settings, gateway config, shipping/tax config, plugin settings, active_plugins |
| `wp_posts` / `wp_postmeta` | Products, variations, coupons, pages (incl. the Checkout page and its content) |
| `wp_terms` / `wp_term_*` | Product categories, attributes, shipping classes |
| `wp_users` / `wp_usermeta` | Test accounts, saved addresses, **rewards/points balances often live here** |
| `wp_woocommerce_sessions` | Can be truncated, but keep the table |
| `wp_woocommerce_tax_rates`, `wp_woocommerce_tax_rate_locations` | Tax config |
| `wp_woocommerce_shipping_zone*` | Shipping zones/methods/locations |
| `wp_wc_product_meta_lookup`, `wp_wc_*_lookup` | WooCommerce lookups |
| Any table matching `%gift%`, `%point%`, `%reward%`, `%loyal%`, `%credit%` | Gift card and Pollen Points storage |
| `wp_actionscheduler_actions` (schema at minimum) | Scheduled jobs — see LOCAL-SAFETY.md |

**Orders:** we do not need years of history. A few hundred recent orders are plenty; refund and
subscription-renewal behavior is easier to test with a handful of real examples than with none.
If you exclude order tables, keep their **schema** (and tell me whether HPOS is on).

### Before importing

Note the production DB engine and version — the local stack has MySQL 9.1.0 and MariaDB 11.5.2.
Importing a MariaDB dump into MySQL 9 (or vice versa) can fail on collations
(`utf8mb4_unicode_520_ci`, `utf8mb3`) and on `sql_mode` strictness. I will handle search-replace
of the site URL with WP-CLI (`wp search-replace`) rather than a raw SQL find/replace, so
serialized data stays intact.

---

## C. Configuration facts I need from you

Cheap to answer, expensive to guess wrong:

1. **Production URL** (for search-replace) and intended **local URL**
   (e.g. `http://localhost/Bee%20Orc/` or a vhost like `http://beeorch.local`).
   A vhost is strongly preferred — a subfolder install breaks some plugins' path assumptions.
2. **PHP version** in production (`phpinfo()` or hosting panel).
3. **Database engine + version** (MySQL vs MariaDB, exact version).
4. **WordPress version** and **WooCommerce version**.
5. Is **HPOS** (WooCommerce → Settings → Advanced → Features → Order data storage) enabled?
6. Is this a **multisite**?
7. Is the site behind **Cloudflare / a CDN / a reverse proxy** that plugins detect?
8. Any **server-level** config that matters (Nginx rules, Redis object cache, Varnish)?
9. Read-only **admin access to production** (or screenshots of the Plugins screen and
   WooCommerce → Settings) would let me finish half the audit before the copy even lands.

---

## D. What NOT to copy

- Full `uploads/` media library
- Full order history / `wp_actionscheduler_logs` / analytics tables
- Security-plugin scan tables (Wordfence, Sucuri)
- Backup archives inside `wp-content`
- Server logs
- Real customer PII beyond what's needed — if the dump contains real customers, say so and I'll
  plan an anonymization pass before we do anything that could email them.

---

## E. After the copy lands — my sequence

1. Verify file tree and identify WP/Woo versions.
2. Create local DB, import dump, WP-CLI search-replace URLs.
3. Rewrite `wp-config.php` for local (DB creds, `WP_DEBUG`, `WP_ENVIRONMENT_TYPE=local`).
4. **Run the LOCAL-SAFETY.md lockdown before loading the site in a browser** — kill outbound
   email, put gateways in test/disabled mode, neutralize webhooks and cron.
5. Confirm the store loads, a product adds to cart, and `/checkout` renders.
6. Begin Phase 1 (checkout architecture) and Phase 2 (compatibility audit).

Nothing gets tested against a live gateway, shipping API, ERP, or CRM. Step 4 happens first.
