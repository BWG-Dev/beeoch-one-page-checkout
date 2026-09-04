# Deployment

Pushing **`staging`** triggers the Buddy pipeline, which deploys this repository into
`wp-content/plugins/beeoch-one-page-checkout/` on
[beeoch.nextsitehosting.com](https://beeoch.nextsitehosting.com).

The repository root is the plugin folder, so by default everything here lands on the server.
There is no build step — no Composer, no bundler, no compiled assets — so what is committed is
what runs.

## What must NOT be deployed

Some of what this repository tracks exists for the people working on it, not for the server.
None of it is harmful on staging, but a plugin folder should contain a plugin, so the Buddy
transfer action excludes these paths:

| Path | Why it is in the repo | Why it stays off the server |
|---|---|---|
| `docs/` | The audit, architecture and hook map this plugin was built from. They are the reasoning behind the code and belong with it. | Reference material, not runtime code. |
| `.claude/` | Mirrored Claude Code project memory, so the context carries between developers rather than living on one machine. | Same. |
| `.gitattributes`, `.gitignore` | Repository mechanics. | Meaningless outside a checkout. |
| `DEPLOY.md` | This file. | — |

`README.md` is deliberately **not** excluded: WordPress plugin folders normally carry one, and
it is what a developer opening the folder on the server reads first.

If the exclusion list in Buddy and the table above ever disagree, the pipeline is the truth —
please correct this file.

## Branches

| Branch | Purpose |
|---|---|
| `master` | Integration. Nothing deploys from it. |
| `staging` | Deploys to staging on push. |

## About `.claude/memory/`

These files are a **mirror**, not the live copy. Claude Code reads project memory from
`~/.claude/projects/<slug>/memory/`, where the slug comes from each developer's own local path
— so a copy committed here does not load automatically for anyone. To use it, copy the files
into your own project memory directory; to contribute back, copy your changes here and commit.

They are versioned because the alternative is that everything learned about this checkout —
which plugin owns which hook, which approaches were tried and failed, which failures are silent
— stays on one laptop.

Nothing in them is a secret. Keep it that way: no passwords, no keys, no customer data.

## Before pushing to `staging`

1. `php -l` clean on changed PHP files, `node --check` clean on changed JS.
2. Version bumped in **both** places in `beeoch-one-page-checkout.php` — the header comment and
   `BEEOCH_OPC_VERSION`. The constant is the asset cache-buster; a stale version means the
   browser keeps yesterday's CSS and the deploy looks broken.
3. Purge NitroPack on staging after the deploy, for the same reason.

## Rollback

Deployment does not change the rollback story described in `README.md`: set `beeoch_opc_mode`
to `off`, deactivate the plugin, or delete the folder. Nothing outside this plugin is modified,
so any of the three restores the previous checkout.
