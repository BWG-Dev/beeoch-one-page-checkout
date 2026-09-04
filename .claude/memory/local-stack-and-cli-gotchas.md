---
name: local-stack-and-cli-gotchas
description: Bee Orc local WAMP stack coordinates and the Git Bash path-mangling trap that silently no-ops WP-CLI commands
metadata: 
  node_type: memory
  type: project
  originSessionId: 1887ba9f-9298-47dc-b1f7-5965a0a79b48
  modified: 2026-08-13T15:12:42.394Z
---

Running WP-CLI against the Bee Orc local install (`C:\wamp64\www\Bee Orc\site`):

- MariaDB 11.5.2 listens on **port 3307**, not 3306 — MySQL 9.1.0 holds 3306. `DB_HOST` must be
  `127.0.0.1:3307`. Client: `C:/wamp64/bin/mariadb/mariadb11.5.2/bin/mysql.exe -uroot -P3307`.
- PHP CLI: `C:/wamp64/bin/php/php8.1.31/php.exe`. WP-CLI phar: `C:/wp-cli/wp-cli.phar`.
- Always pass `--skip-plugins --skip-themes`; loading 69 active plugins makes every command crawl.

**The trap:** in the Bash tool (Git Bash / MSYS), any argument starting with `/` is silently
rewritten to a Windows path before the program sees it. `search-replace '/var/www/...'` became
`search-replace 'C:/Program Files/Git/var/www/...'` and reported "Made 0 replacements" — a
success message for work that never happened. Set `MSYS_NO_PATHCONV=1` and use Windows-style
paths (`C:/wamp64/...`) for the php/phar binaries in the same command, or the loader itself
fails to resolve.

**Why it matters:** a 0-replacement result here reads as "nothing to change", not "the search
string was corrupted". Verify search-replace outcomes with a direct SQL `LIKE` count rather than
trusting the summary line. Note that `wp search-replace X X` refuses to run (identical values),
so it is useless as a verification probe.

Related: [[beeoch-local-db-import]]
