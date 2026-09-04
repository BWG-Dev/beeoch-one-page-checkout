---
name: discuss-expensive-operations-first
description: "Flag token-expensive operations and agree an approach before running them, rather than just executing"
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 1887ba9f-9298-47dc-b1f7-5965a0a79b48
  modified: 2026-08-13T15:29:04.605Z
---

Before running an operation likely to consume a large number of tokens, raise it and agree the
approach first instead of executing straight away.

**Why:** the user is watching token spend on this project and wants the call on whether a costly
step is worth it — especially likely here, where the WordPress install has 45 active plugins,
~500 MB of SQL, and 215 MB of uploads, so naive greps and full-file reads get expensive fast.

**How to apply:** cheap, targeted probes need no discussion — run them. Flag first for things
like: scanning or decompressing the full DB dump, reading large plugin trees wholesale, grepping
across `wp-content/plugins/` without a narrow filter, or spawning multi-agent searches. Say what
it will cost and what it will yield, and offer a narrower alternative. Prefer SQL aggregate
queries, `--report-changed-only`, background jobs writing to a file, and reading only the files
the audit actually needs. Never read `uploads/` or WordPress core files.

Related: [[local-stack-and-cli-gotchas]]
