# The Code Half (`code/`) — Design Proposal

*Proposal — 2026-08-05. Status: design exploration; the implementation ruling below is authoritative for the first functional skeleton. Owner: code-design (Task #18).*

> **Implementation ruling (2026-08-07).** Duo's first complete code-half
> transport is an opt-in, descriptor-hashed `code/wp-content` payload of
> vendored plugins, themes, and user mu-plugins. It stages additions and
> updates, runs lifecycle reconciliation while outgoing code still exists,
> then prunes only previously Duo-owned paths and records the revision after
> target hash verification. This works for public, premium, private, and
> in-house code without a package-registry dependency. The full-webroot,
> Composer, SSH release-directory, and agent-self-delivery design explored
> below remains the next layer, not behavior the current skeleton claims.
> Code and state retain independent descriptors, revisions, ledgers, and
> mutation engines; the combined promotion artifact only binds and sequences
> them. `active_plugins`/`template`/`stylesheet` are the one explicit lifecycle
> bridge between database intent and executable files.

DESIGN.md §6 disposes of the entire code half in one line: **"Bedrock/Composer: solved the code half; adopt it, focus on the data half."** That line is correct as far as it goes and wrong as a spec — "adopt Bedrock" is not a layout, a deploy story, or an answer to how code-half facts (which plugin is active, which theme is active) interact with the state half's classification machinery. This document is the implementable version of that line: a concrete `code/` layout for both dependency-management modes, deploy semantics per transport, a concrete design for the cross-partition invariant DESIGN.md §3.4 names but never specifies (`active_plugins ⊆ plugins in code/`), a worked plugin-upgrade example, a sandbox spike outline, the minimal engine touchpoints, and an honest risk register.

**Scope boundary.** This proposal treats `state/`, the ledger, capture/apply/canary, and the manifest classification pipeline as fixed (built, spike-proven, not to be redesigned here). It extends that machinery at exactly the points where code-half facts leak into it — `active_plugins`/`template`/`stylesheet`, plugin version awareness — and otherwise treats `code/` as materialization ("get the right files onto the right machine"), which is a mostly-orthogonal concern to `state/`'s materialization ("get the right database rows into the right database").

**Verification note.** Where a claim rests on WordPress/Composer/Bedrock ecosystem facts, I mark it **(verified)** with what was checked, or **(recall)** where it rests on training knowledge not independently re-checked in this session — per the task's instruction to distinguish the two. One verification materially changed a recommendation below: **WPackagist was acquired by WP Engine in March 2026**, and Bedrock's own maintainers (Roots) responded by launching an independent alternative that Bedrock itself now uses. Since the plan of record is literally "adopt Bedrock," this is decision-relevant, not trivia — see §1.1.

Contents: [§1 Layout & modes](#1-layout--modes) · [§2 Deploy semantics](#2-deploy-semantics) · [§3 The cross-partition invariant](#3-the-cross-partition-invariant-concretely) · [§4 Plugin update workflow](#4-plugin-update-workflow) · [§5 Sandbox demonstration plan](#5-sandbox-demonstration-plan) · [§6 Engine touchpoints](#6-engine-touchpoints) · [§7 Open questions & risks](#7-open-questions--risks)

## Recommendations at a glance

| Question | Recommendation |
|---|---|
| Default mode | **Mixed, per-plugin.** Composer (wpackagist/WP Packages) for anything public; vendored wholesale for premium/unknown. Not a site-wide toggle — a per-package decision made in `code/composer.json`'s `require` (present = composer-managed) vs. physical presence under `wp-content/plugins/` with no `require` entry (vendored). |
| Composer repository | **WP Packages** (`wp-packages.org`, `wp-plugin/*`/`wp-theme/*`) as primary, not classic WPackagist — verified current Bedrock practice post the WP Engine acquisition (§1.1). WPackagist remains a documented fallback (same shape, different prefix/URL). |
| Directory naming | Keep stock `wp-content/` (not Bedrock's `web/app/` rename) — required by DESIGN.md's own constraint #1 ("plugins/themes work completely unmodified"), which a wp-content rename risks breaking for plugins with hardcoded paths. This is where this proposal diverges from Bedrock's convention, not from DESIGN.md's. |
| Core placement | **Full-tree management**: `code/` *is* the WordPress webroot; core is composer-managed and lands directly under `code/`. Justified in §2.2 against the wp-content-only alternative. |
| Deploy strategy | **Resolve-in-place** for `local`/`docker` (composer install runs at the destination, which already has git/filesystem access); **resolve-then-rsync** for `ssh` (composer runs off-box, only rsync + PHP required on the target — no git or package-registry egress needed in production). |
| Invariant enforcement | `active_plugins`/`template`/`stylesheet` become **`managed`**-classified core-manifest options (not `authored`) — captured by bespoke code, excluded from the generic direct-SQL apply path, and reconciled by a **new `duo deploy` step that runs outside apply's hook-free canary** (activation must fire hooks; apply must not). Plan-time checks live in the existing `Apply::build_plan()`, so they show up in ordinary `duo plan`/`duo status`, not just at deploy time. |
| version_range | A new optional manifest field, `{"min": "8.0.0", "max": "10.0.0"}` (max-exclusive), checked with two `version_compare()` calls (no semver-range parser — keeps the dependency-free agent dependency-free). Warns at plan, hard-blocks apply by default, `--force-code-mismatch` overrides. |
| 3 highest-risk open questions | (1) wp-admin/file-system plugin updates silently drift an environment's code out from under git — needs `DISALLOW_FILE_MODS` as policy, not just detection. (2) Composer/registry availability becomes a new dependency in the deploy critical path. (3) Rollback-after-migration is fundamentally bounded by plugin authors' (non-)support for down-migrations — Duo cannot make this safe by itself. |

---

## 1. Layout & modes

### 1.0 What "adopt Bedrock" means here, precisely

DESIGN.md §6: *"Bedrock/Composer: solved the code half; adopt it, focus on the data half."*

Two separable things live inside "Bedrock": (a) **composer-driven dependency resolution** — core, plugins, and themes as versioned Composer packages, resolved via `composer/installers` path-mapping, with `composer.lock` as the single source of truth for exact versions; and (b) **Bedrock's directory convention** — renaming `wp-content/` to `web/app/`, moving core into `web/wp/`, and loading a rewritten `wp-config.php` that redefines `WP_CONTENT_DIR`/`WP_CONTENT_URL` to match.

This proposal adopts (a) in full and explicitly **rejects (b)**. DESIGN.md's own constraint #1 is non-negotiable: *"Plugins/themes work completely unmodified (no ecosystem cooperation required)."* Renaming `wp-content/` is a well-known Bedrock footgun for exactly this reason — some plugins/themes hardcode `/wp-content/` in generated markup, enqueued asset URLs, or license-check callbacks, and Bedrock users periodically hit and patch around this. A tool whose entire premise is "unmodified plugins" cannot adopt a convention whose failure mode is "some unmodified plugins break." DESIGN.md's own sketches (§3.3's tree and spec/repo-format.md's layout) already independently arrived at the same conclusion — both show plain `wp-content/plugins/`, not `web/app/plugins/` — so this isn't a new divergence introduced by this proposal, it's making explicit and justifying a choice the design sketches had already made implicitly. Concretely: composer.json lives at `code/composer.json`, and `composer/installers`' `installer-paths` extra is configured to route packages into `code/wp-content/plugins/{$name}/` and `code/wp-content/themes/{$name}/` — standard, first-class `composer/installers` configuration, not a hack (confirmed by WP Packages' own documented example, §1.1).

### 1.1 The composer registry landscape — verified, not recalled

**(verified via WebSearch + WebFetch, this session, against wp-packages.org, wordpress.org/news, and github.com/roots/bedrock — all postdate this model's January 2026 training cutoff)**: In March 2026, **WPackagist (wpackagist.org) was acquired by WP Engine**, a private-equity-backed hosting company. This triggered public concern in the WordPress community about a single commercial host controlling a piece of shared build infrastructure that most of the ecosystem's Composer-based WordPress projects (Bedrock included) depend on. In direct response, **Roots — Bedrock's own maintainers — launched an independent alternative, "WP Packages" (`wp-packages.org`)**, going live March 16, 2026 (briefly named "WP Composer" until a trademark objection from Composer's co-creator forced a rename days later). WP Packages mirrors the same free wordpress.org plugin/theme directory, syncs every ~5 minutes (vs. WPackagist's ~90-minute cycle), and resolves noticeably faster. **Bedrock's live `composer.json` (fetched this session from `github.com/roots/bedrock`, `master` branch) already uses WP Packages** as its repository, requiring `wp-theme/twentytwentyfive` and pinning `roots/wordpress` (exact version, not a range) for core.

WPackagist itself is not dead — WP Engine has committed to keeping it free and operational, and it remains a valid fallback — but given that (1) the plan of record explicitly says "adopt Bedrock" and (2) Bedrock itself has already moved off WPackagist as of this writing, **this proposal recommends WP Packages as Duo's primary/documented default composer repository**, with WPackagist named as an equivalent, swappable fallback for teams with a reason to prefer it (e.g., already-established internal tooling, or distrust of a newer service). The two are structurally interchangeable — same package shape, different `repositories` URL and package-name prefix — so this is a one-line `composer.json` change either way, not a design fork.

| | WP Packages (recommended default) | WPackagist (documented fallback) |
|---|---|---|
| Repository URL | `https://repo.wp-packages.org` | `https://wpackagist.org` |
| Plugin package | `wp-plugin/<slug>` | `wpackagist-plugin/<slug>` |
| Theme package | `wp-theme/<slug>` | `wpackagist-theme/<slug>` |
| Operator | Roots (independent, open source) | WP Engine (as of March 2026) |
| Sync cadence | ~5 min | ~90 min |
| Core package | `roots/wordpress` (meta), `roots/wordpress-no-content` (core only, no bundled default content), `roots/wordpress-full` (core + bundled themes/plugins) | `johnpbloch/wordpress` (classic; still functions) |

Core-package choice: **`roots/wordpress`**, matching Bedrock's own current, empirically-observed `composer.json` (exact pin, e.g. `"roots/wordpress": "7.0.2"`, not a caret range — core version bumps are deliberate, reviewed events, not auto-resolved). `roots/wordpress-no-content`/`-full` are documented siblings; `-full` bundles default themes/plugins and is explicitly **not** recommended, because it would silently populate `code/` with entities (Akismet, Hello Dolly, a default theme) that no `composer.json` `require` line or `site.duo.json` decision put there — a hole in the "only what's declared is present" property the cross-partition invariant (§3) depends on. Both wpackagist and WP Packages leave premium/unlisted plugins (ACF PRO, Gravity Forms, purchased page builders, in-house client plugins) out of scope entirely — they mirror **only** the free wordpress.org directory. That gap is exactly what vendored mode (§1.3) exists for.

### 1.2 Composer-managed mode

```jsonc
// code/composer.json
{
  "name": "acme/example-site",
  "type": "project",
  "repositories": [
    { "type": "composer", "url": "https://repo.wp-packages.org" }
    // fallback / alternative source, same shape:
    // { "type": "composer", "url": "https://wpackagist.org" }
  ],
  "require": {
    "php": ">=8.1",
    "composer/installers": "^2.2",
    "roots/wordpress": "6.7.2",
    "wp-plugin/woocommerce": ">=9.0 <10.0",
    "wp-plugin/akismet": "^5.3",
    "wp-theme/twentytwentyfive": "^3.0"
  },
  "extra": {
    "wordpress-install-dir": ".",
    "installer-paths": {
      "wp-content/plugins/{$name}/": ["type:wordpress-plugin"],
      "wp-content/themes/{$name}/": ["type:wordpress-theme"],
      "wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"]
    }
  },
  "config": {
    "allow-plugins": {
      "composer/installers": true,
      "roots/wordpress-core-installer": true
    }
  }
}
```

**(verified via WebFetch against wp-packages.org/docs)**: this exact `installer-paths` shape (`wp-content/plugins/{$name}/`, `wp-content/themes/{$name}/` — stock naming, not `web/app/...`) is WP Packages' own documented example, not a Duo-specific hack layered on top of Bedrock conventions. `wordpress-install-dir: "."` places core directly in the same directory as `composer.json` — i.e., directly under `code/`, which is the full-tree decision justified in §2.2. `roots/wordpress-core-installer` **(verified, forked from `johnpbloch/wordpress-core-installer`)** is the composer plugin that reads `wordpress-install-dir` and unpacks core there.

`composer.lock` is committed (pins exact resolved versions/hashes — this is the artifact conflict-of-truth for "what version is this branch on," and is what §4's worked example diffs). `vendor/` and every composer-managed plugin/theme directory are **not** committed — they're regenerated by `composer install` (§2). Concretely, `code/.gitignore` needs one line per composer-managed package name (see §1.4).

### 1.3 Vendored mode

Premium and unknown-provenance plugins/themes are **committed wholesale**: their full source lands directly under `code/wp-content/plugins/<slug>/` or `code/wp-content/themes/<slug>/`, tracked by git like any other file in the repo, with no `composer.json` entry at all — there is nothing to declare, since there's no registry package backing them.

```
code/wp-content/plugins/
  acf-pro/                    # vendored: premium, committed wholesale
    acf.php
    ...
  gravityforms/               # vendored: premium, committed wholesale
    ...
  woocommerce/                # composer-managed: NOT committed (gitignored)
```

This is git-history-heavy (binary/text diffs on every vendor release), which is the accepted cost of "no registry exists for this package" — it's the same tradeoff every WP shop already makes when they FTP a premium plugin onto a server, just captured in git instead of silently on disk. Two escape hatches exist for teams that outgrow plain vendoring and are worth naming even though they are **not** this proposal's default:

- **Composer `"type": "package"` repositories** — a manually-maintained `repositories` entry pointing directly at a versioned download URL (common technique for vendors like ACF PRO or Gravity Forms that expose a stable, license-keyed download URL per version). Bespoke per vendor, real maintenance cost; worth adopting *ad hoc* for specific high-churn premium plugins, not worth building general tooling around for v0.
- **A private Composer proxy/mirror (e.g. Satis)** for organizations big enough to want one. Out of scope here; noted as a §7 risk mitigation, not a §1 recommendation.

### 1.4 The mixed case

The common real case is both at once, coexisting as siblings under the same `wp-content/plugins/`:

```
code/
  composer.json              # requires: roots/wordpress, wp-plugin/woocommerce, wp-theme/twentytwentyfive
  composer.lock               # committed — pins exact resolved versions
  .gitignore                  # see below
  wp-config.php               # thin, env-bound values NOT here (§1.6) — committed on local/ssh, gitignored on docker
  wp-admin/  wp-includes/  index.php  wp-*.php   # composer-managed core — gitignored
  wp-content/
    plugins/
      woocommerce/            # composer-managed — gitignored, regenerated by `composer install`
      acf-pro/                # vendored — committed wholesale
    themes/
      twentytwentyfive/       # composer-managed — gitignored
      acme-child/             # vendored (site's own custom theme) — committed wholesale
    mu-plugins/
      duo-loader.php          # the agent — see §1.7
      duo/
    uploads/                  # never in git — env-local, see spec/repo-format.md `media/`
```

`code/.gitignore` needs to distinguish composer-managed directories (ignore) from vendored ones (keep) even though both live under the same parent. Two mechanisms, pick one per repo:

```gitignore
# code/.gitignore — Option A (default): explicit per-package ignores.
# Add/remove a line here in the SAME commit as the matching composer.json change.
/vendor/
/wp-admin/
/wp-includes/
/index.php
/wp-*.php
/wp-content/plugins/woocommerce/
/wp-content/themes/twentytwentyfive/
/wp-content/uploads/
```

```gitignore
# code/.gitignore — Option B: invert the default when composer-managed
# packages are the majority and vendored ones are few.
/wp-content/plugins/*
!/wp-content/plugins/acf-pro/
!/wp-content/plugins/gravityforms/
/wp-content/themes/*
!/wp-content/themes/acme-child/
```

Recommend **Option A as the default** (fewer git-negation surprises; a team already reviews `composer.json` diffs, so a matching `.gitignore` line in the same PR is low-friction and auditable) and document Option B as the swap for composer-heavy repos.

### 1.5 Core placement — decided here, justified in §2.2

Core is composer-managed and lands directly under `code/` (full-tree management: `code/` *is* the WordPress webroot). See §2.2 for the wp-content-only alternative and why it's rejected.

### 1.6 Where wp-config / env-bound config does NOT live

Never in `state/` (obvious — it's not authored content) and never with real values inside git-tracked `code/` either. `wp-config.php` needs DB credentials, salts, `WP_HOME`/`WP_SITEURL`, and Duo's own env-bound switches (`DUO_JOURNAL`, `DUO_MANIFESTS_DIR`) — every one of these is exactly the `env`-portability class DESIGN.md §1 defines (*"env-bound (siteurl, API keys, salts, file paths)"*). Handling splits cleanly by transport, and both halves are already precedented elsewhere in this codebase:

- **`docker`**: the official `wordpress:php8.3-apache` image's entrypoint **(recall — docker-library/wordpress's well-known startup behavior, not re-verified this session)** auto-generates `wp-config.php` from `WORDPRESS_DB_*` environment variables at container start if the file is absent. The existing sandbox already relies on exactly this (`docker-compose.yml`'s `x-wp-env`/`WORDPRESS_CONFIG_EXTRA` blocks). Recommendation: keep doing this — add `/wp-config.php` to `code/.gitignore` and let the entrypoint keep writing it into the now-bind-mounted `code/` directory. Simplest option, zero new code, matches current practice.
- **`local`/`ssh`**: no docker entrypoint to lean on, so `code/wp-config.php` should be a **thin, committed bootstrap** (Bedrock's actual pattern, minus its directory rename): it contains only logic — read `getenv()` / a gitignored `.env` — and defines the WordPress constants from those. The file is git-tracked (its *logic* is stable across environments) but contains **zero secrets or env-specific values itself**.

Either way, the invariant is the same one DESIGN.md already states for `state/`'s `env`-class values: *"Env-bound values bind via per-env config, never committed."* This proposal applies the identical rule to the code half's own env-bound surface.

### 1.7 How the duo agent itself ships

spec/repo-format.md's layout already places `wp-content/mu-plugins/duo/ # the agent` *inside* `code/`, i.e., the agent is part of the code half's contract, not a side channel. Recommendation: **the agent ships the same way every other code-half dependency does — as a composer package**, using a `"type": "vcs"` repository entry pointing at this platform repo (or a dedicated package once one is published), typed `wordpress-muplugin` so `composer/installers` places it correctly:

```jsonc
"repositories": [
  { "type": "composer", "url": "https://repo.wp-packages.org" },
  { "type": "vcs", "url": "https://github.com/<org>/duo-wp.git" }   // proposed — not published today
],
"require": {
  "duotronic/duo-agent": "0.5.0"   // proposed package name — illustrative, not an existing package
}
```

This unifies the update model — bumping the agent is a `composer.json`/`composer.lock` diff exactly like bumping WooCommerce, reviewable the same way, gated by the same materialization step. **v0-simplest fallback**, for teams not ready to stand up a VCS repository entry: vendor the agent wholesale (§1.3's mechanism, applied to the agent itself) and update it by copying in new source on upgrade. Either way, there is no "hot reload" problem to solve: wp-cli/PHP-FPM boot a fresh PHP process per invocation, so a newly-materialized agent version simply takes effect on the *next* `wp duo …` call — no special-casing needed, stated here only to pre-empt the question.

**Sandbox-specific, deliberate divergence from the above** (flagged so it isn't mistaken for the general recommendation): sandbox/docker-compose.yml today bind-mounts the agent straight from the *platform* repo's working tree (`../agent:/var/www/html/wp-content/mu-plugins/duo:ro`) so that iterating on `agent/src/*.php` is instant — no rebuild, no re-materialize. This is correct and should **stay exactly as-is** for the sandbox, because the sandbox's job there is developing the agent itself, not demonstrating agent distribution. §5's new spike keeps this mount for mu-plugins and only bind-mounts `code/` for the plugins/themes/composer.json layer being newly demonstrated — see §5.1.

---

## 2. Deploy semantics

**"Materializing code"** means: given a git revision of the site repo, produce, on a target environment, a `wp-content/` (and, in full-tree mode, a full webroot) whose contents exactly match what that revision's `code/composer.json` + `composer.lock` + vendored files declare — before any state (`duo apply`) or plugin-activation reconciliation (§3.4) touches that environment.

### 2.1 Core placement: full-tree vs. wp-content-only

| | Full-tree (`code/` = webroot) | wp-content-only (`code/` holds only `wp-content/`; core provisioned separately per env) |
|---|---|---|
| Core version | One source of truth (`composer.lock`, in git, branchable) | Core lives outside the repo — each env's core version is that env's own fact, not a repo fact |
| `composer install` output | Reproduces the **entire** webroot deterministically | Reproduces only `wp-content/`; core must already be correct via a separate, undocumented mechanism |
| Transport story | `code/` is self-contained — rsync/mount it alone and you have a runnable site | Two locations to keep in sync (repo's `wp-content/` + env's independently-managed core) |
| Core upgrades | A normal branch + `composer.lock` diff, reviewable, testable pre-merge like any other dependency bump | An out-of-band, per-environment operation with no git history |
| Matches DESIGN.md's own sketch | Yes — §3.3's tree shows `code/composer.json` at the top of `code/`, implying `code/` is the project root WP Packages/Bedrock composer.json convention already assumes | Would require inventing a different layout than DESIGN.md's own sketch |

**Recommend full-tree.** The wp-content-only alternative exists in real Bedrock-adjacent setups (some teams don't want core in their app repo at all), but for Duo specifically it reintroduces exactly the problem DESIGN.md's whole state-half design exists to solve for content: *a fact that matters (which core version is running) living somewhere ungoverned by the repo.* Given DESIGN.md's own layout sketch already puts `composer.json` at the top of `code/` (implying core is in scope, not carved out), wp-content-only would be a **bigger** divergence from the existing sketch than full-tree is. If a future team wants wp-content-only for a specific host constraint (e.g., a managed host that owns and forbids modifying core), that's a `wordpress-install-dir`-and-`.gitignore` change confined to §1.2/§1.4, not a change to anything in §3 onward — the invariant and deploy-ordering design here are agnostic to this choice.

### 2.2 Per-transport materialization

The `cli/` orchestrator already has exactly three transports (`local`, `docker`, `ssh` — cli/src/{Local,Docker,Ssh}Transport.php), each knowing how to run `wp <args>` and raw shell snippets against one environment. Materializing code needs one new capability layered onto the same abstraction — **not** a fourth transport.

| Transport | Who runs `composer install` | Sync mechanism | Why |
|---|---|---|---|
| `local` | Directly on the target — `duo` already shells out locally | None needed — git checkout/pull on that same filesystem already placed `code/`; composer just resolves the gitignored parts in place | `local`'s whole premise is "the machine `duo` runs on," so "resolve in place" is free |
| `docker` | A dedicated one-shot `composer:2`-image service (`docker compose run --rm composer install --no-dev`, working dir = the bind-mounted `code/`), **not** the `wordpress:cli-*` wp-cli image | None needed — `code/` is bind-mounted from the host, so the container and the host share the same files live; whatever composer resolves on the host side is immediately visible to `wp-*`/`cli-*` | Reuses the existing bind-mount pattern (§5.1); avoids needing composer baked into the `wordpress:cli` image **(recall, not verified this session — the official `wordpress:cli` image is Alpine-based and does not obviously ship composer by default; confirm empirically when building the spike; a dedicated `composer:2` one-shot service sidesteps the question entirely)** |
| `ssh` | **Off-box** — resolved by the orchestrator's own machine (or CI), never on the target | **rsync** the fully-resolved `code/` tree to the target, into a timestamped release directory, then atomically flip a `current` symlink `wp_path` points at (`rsync -a --delete --link-dest=<previous-release> ... host:releases/<rev>/code/` + `ssh host ln -sfn releases/<rev>/code current`) | See below |

**Why rsync (with a symlink-swap refinement), not git-checkout-on-env or a bespoke artifact pipeline, for `ssh`.** Three options exist and the mission asked them weighed explicitly:

1. **git checkout on the environment** — the target clones/pulls the site repo itself and runs `composer install` locally. Rejected as the default: it requires git *and* composer *and* network egress to the package registry from production, which hardened production environments routinely firewall off (and reasonably so — outbound access from prod to arbitrary package registries is real attack surface). It also means the target needs `.git` history sitting on disk, which many ops teams avoid on principle.
2. **rsync of a pre-resolved tree** (recommended) — resolution happens somewhere that *does* have git/composer/network (the `local` transport machine, or CI), and only the **result** — plain files, no `.git`, no registry credentials — reaches the target via rsync. The target needs only SSH + rsync + PHP/WP itself. This is the direct code-half analogue of the "code up, content down" discipline design-review-v0.md finding #22 already credits to Pantheon/WP Engine multidev and that DESIGN.md §3.4 already adopts for the *state* half's default branch workflow (*"materialize fresh from a prod snapshot + apply the branch delta"*) — same philosophy, applied to code.
3. **Build-artifact + atomic symlink swap** (Capistrano-style `releases/<n>/` + `current`) — superior for atomicity and instant rollback, but is really a *refinement* of rsync, not a competing mechanism: rsync into a fresh release directory (using `--link-dest` against the previous release for hardlink dedup, keeping disk cost low) and then swap the symlink. **Recommendation: fold this into the rsync approach rather than treating it as a third, separate option** — get atomic cutover and cheap rollback (§2.3) essentially for free on top of rsync, without standing up a separate artifact-storage/versioning system.

Materialization success must be self-verifying before anything downstream trusts it: `duo deploy` should check its own exit status (composer's exit code; for ssh, rsync's exit code) plus a cheap sanity probe (`wp core is-installed`/`wp plugin list` over the transport) **before** writing the new code revision into the ledger (`duo_kv['code_revision']`, reusing the *existing*, already-generic `duo_kv` table — no schema change, see §6). A failed or partial deploy simply never advances that marker, so the *existing* drift-style plan machinery (§3) naturally reports "code not yet deployed" on the next `plan`/`status` rather than needing bespoke partial-failure recovery.

### 2.3 Rollback story

State already has a rollback story (`wp db export` snapshot before apply, per spec/repo-format.md's closing apply-semantics line: *"Snapshot/rollback is the orchestrator's job in v0"* — exercised in spike_a_round_trip.sh). Code rollback should be symmetric and is transport-shaped the same way materialization is:

- **local/docker**: `git checkout <previous-rev> -- code/` (or the whole repo) + re-run materialization (`composer install` regenerates the exact previous state — deterministic, because `composer.lock` pins exact versions/hashes).
- **ssh**, with the release-directory refinement from §2.2: if the previous release is still retained on disk, rollback is **just the symlink flip** — instant, no re-resolve, no rsync. If it's been pruned, fall back to full re-materialize-and-rsync of that older revision, the general-case path.

**The one honest caveat, stated plainly because it is a real limit, not a solved problem**: code rollback and state rollback are not independent. If a plugin has already run a forward migration (§4) against the database, rolling back *only* the code can strand the database in a schema shape the older code doesn't understand (WooCommerce's HPOS tables existing while HPOS-unaware Woo code loads, for instance). Duo does not control whether plugin authors ship down-migrations — most don't. The safe rule this proposal recommends stating explicitly to operators: **roll back code before any migration has run against production data, or roll back code and restore the pre-migration DB snapshot together — never roll back code alone against an already-migrated database.** This is carried into §7 as a first-class risk, not just a footnote here.

---

## 3. The cross-partition invariant, concretely

DESIGN.md §3.4 names the invariant and stops: *"**Guards**: … cross-partition invariant (`active_plugins` ⊆ plugins in `code/`); drift detection with capture-first workflow."* Today, `active_plugins` is not in the core manifest's options at all (verified by reading `manifests/core.json` in full — no `active_plugins`/`template`/`stylesheet` keys exist), so nothing currently captures it, checks it, or enforces the invariant. This section is the concrete design.

### 3.1 Classification: `managed`, not `authored`

```jsonc
// manifests/core.json — proposed additions to "options"
"active_plugins": {"class": "managed"},
"template":       {"class": "managed"},
"stylesheet":     {"class": "managed"}
```

**Why `managed` and not `authored`.** `managed` is not a new class invented for this proposal — it is the *existing* value the core manifest already uses for `_menu_item_object_id`, `_wp_attached_file`, and friends: keys that are unambiguously authored-domain facts, but whose capture/apply handling is bespoke rather than routed through the generic direct-SQL options/postmeta pipeline (menus already do this — see spec/repo-format.md's menu section: *"the one theme-mod key the core manifest classifies authored in v0"* is handled entirely outside the generic options loop). `active_plugins`/`template`/`stylesheet` need exactly that same treatment, for a reason specific to them: **applying them via direct SQL is actively wrong.** `activate_plugin()` and `switch_theme()` exist because plugins/themes do real one-time setup in `register_activation_hook`/`switch_theme`/`after_switch_theme` (WooCommerce's own installer, for instance — see §4) — a raw `UPDATE wp_options SET option_value=... WHERE option_name='active_plugins'` would make WordPress *believe* a plugin is active while skipping every side effect that makes it actually work. Values are plain portable strings (plugin file paths, theme directory slugs) — no `ref` typing needed, since they're already stable across environments by construction (that stability *is* the invariant).

### 3.2 Plan-time checks

Both checks run inside the existing `Apply::build_plan()` — the same method that already computes collisions, conflicts, and referential delete guards — so they surface in ordinary `duo plan`/`duo status`, not only when explicitly deploying. A new plan bucket, `code_mismatch`, holds the results:

1. **Directory/file existence** (`missing_in_code`): for every entry in the target `active_plugins` list, confirm the plugin's main file exists under the environment's actual plugin directory (WordPress's own plugin-validation primitives — the same ones `activate_plugin()` itself uses internally — are the right tool here, not a hand-rolled `file_exists()`, since they correctly handle both `slug/slug.php` and legacy single-file `slug.php` plugins). For `template`/`stylesheet`, confirm **both** theme directories exist — a child theme's `switch_theme()` needs its parent (`template`) present too, an easy detail to miss.
2. **Version-range compatibility** (`outside_version_range`): where the active plugin's manifest declares a `version_range` (§4.3), compare the *actually-installed* version (read via WordPress's own plugin-header parser, not `composer.lock` — this makes the check identical for composer-managed and vendored plugins, since vendored plugins have no lockfile at all) against the range.
3. **Materialization staleness** (`code_revision_stale`): compare the immutable compiled artifact's opaque code-descriptor revision against `duo_kv['code_revision']`, the marker written only after code-stage, lifecycle reconciliation, target verification, and code-finalize (§2.2). A mismatch means this artifact's code payload has not reached this environment. It is kept as a **separate** issue rather than overloading ordinary state `drift`: state drift means *the environment changed unexpectedly*; code staleness means the artifact's required code half has not completed its verified materialization sequence.

### 3.3 Failure modes and wording

Matching the codebase's existing voice (`"duo: slug collisions need explicit resolution…"`, `"duo: deletes blocked by referential guards…"`):

```
duo: active_plugins in state/options/core.json declares 'woocommerce/woocommerce.php'
but code/wp-content/plugins/woocommerce/woocommerce.php does not exist in this
environment. Run 'duo deploy <env>' first, or this branch's code/ changes
haven't reached this environment yet.
```

```
duo: code drift — code/composer.lock in the repo (woocommerce 9.4.1) does not
match what's deployed on this environment (woocommerce 9.1.0, read from
wp-content/plugins/woocommerce/woocommerce.php). Run 'duo deploy <env>' before
'duo apply' — applying state that assumes a newer plugin's schema onto older
plugin code is exactly the silent-corruption class this tool exists to prevent.
```

```
duo: woocommerce 7.9.0 is active in this environment, outside the 'woocommerce'
manifest's declared version_range (>=8.0.0 <10.0.0, pinned by site.duo.json).
Classification guarantees for this plugin are NOT validated against this
version — apply may silently misclassify fields. Update code/ (bump the
plugin), or pin an older manifest, or pass --force-code-mismatch to proceed
at your own risk.
```

```
duo: state/options/core.json's active_plugins no longer lists
'legacy-plugin/legacy-plugin.php', but code/wp-content/plugins/legacy-plugin/
is still present and the plugin is still active in this environment. The
cross-partition invariant (active_plugins ⊆ plugins in code/) doesn't forbid
this by itself — but if this removal was intentional, deactivate it
(this environment will do so automatically on the next 'duo deploy'); if
it wasn't, add it back to active_plugins.
```

**Blocking posture.** Lifecycle compatibility rows (`missing_in_code`,
`outside_version_range`, and the activation/theme reconciliation rows) retain
the explicit `--force-code-mismatch` escape hatch. `code_revision_stale` is
different: it is the code-before-state ordering witness for a code-enabled
artifact, so `duo apply` always refuses it. The only recovery is the host
`duo deploy <env>` workflow, which stages, retires and activates in separate
fresh WordPress processes, verifies, and finalizes
the exact descriptor. Neither `--force-code-mismatch` nor
`--force-code-drift` may cross this boundary.

### 3.4 What `apply` does vs. what `deploy` does — and why the split exists

DESIGN.md §3.4 states apply's hook-free posture as a blanket rule: *"two-phase apply as the engine default … via direct low-level writes (**no WP hooks ⇒ no emails/webhooks re-fire**)."* Activating a plugin categorically requires the opposite — hooks *must* fire, because that's how the plugin does its one-time setup. These two requirements cannot both be satisfied inside the same canary-armed transaction, so this proposal does not try to; it **draws the boundary DESIGN.md's own merge-conflict line already implies**: *"Plugin version skew across branches: merge **code first, run migrations**, re-capture, then **merge state**"* (§3.4). That sentence already separates "get the code right" from "apply state" as sequential, distinct phases for the branch-merge case. This proposal generalizes the same separation to **every** deploy, not just branch merges, and gives it a name:

```
duo deploy <env>              duo apply <env>
─────────────────────    →    ────────────────────────
1. materialize code/          (existing, unchanged)
   (composer/rsync, §2)       three-way plan → canary
2. retire outgoing plugins    armed → hook-free direct
   in reverse dependency      SQL for posts/terms/
   order in one process       menus/remaining options
3. start a fresh process      → rebuild pass → canary
   and reconcile additions,   disarmed
   active_plugins order,
   template/stylesheet via
   activate_plugin()/
   deactivate_plugins()/
   switch_theme() — hooks
   FIRE deliberately here.
   NOT canary-armed.
4. plugin/theme migrations
   run as a side effect of
   steps 2–3 (§4) — Duo does
   not drive these directly.
```

`duo apply`'s canary (`agent/src/Canary.php`) keeps meaning exactly what it means today — zero content-CRUD hooks, zero mail, zero HTTP during the state-materialization window (§6 makes this a hard constraint, not just a description). `duo deploy` is a separate window where hooks are not just tolerated but required, and it never touches posts/terms/menus/content options. A distinct reporting-only observer records deploy-window mail/HTTP attempts without blocking them or weakening apply's hard canary. Running deploy before apply (enforced by lifecycle rows in §3.2's `code_mismatch` block, and composed by host-level `duo promote`) is what makes DESIGN.md's "merge code first, run migrations, then merge state" ordering happen at the tooling level rather than being a discipline operators have to remember unassisted.

That separation also needs a boundary before the hook, not only after it. A
WordPress activation/deactivation/theme hook can commit an authored option and
then throw. Deploy therefore records a durable pre-hook attempt in the exact
promotion session before entering each mutating lifecycle phase. Success
consumes it atomically with the canonical handoff. Failure leaves it unresolved,
blocking every materializer/lifecycle/apply continuation and every different
owner/artifact until the retained pre-lifecycle checkpoint and known
pre-promotion code revision are restored. Plan/status exposes the receipt as a
non-forceable `incomplete_lifecycle` finding. This remains mandatory on a first
sync where no three-way base exists.

On DESIGN.md §4's *"Env orchestration — none owned in v1 (host-agnostic)"*: `duo deploy` doesn't contradict this. It materializes files onto (and reconciles plugin state within) an environment the operator has already provisioned and pointed a webserver at — precisely parallel to how `duo apply` already materializes rows into a database the operator already provisioned. Neither verb creates infrastructure, manages DNS/TLS, or owns webserver config; both assume an already-running target. Same non-goal boundary as today, extended to cover code the same way it already covers state.

---

## 4. Plugin update workflow

### 4.1 Worked example: bumping WooCommerce 8.x → 9.x on a branch

1. **Branch, bump.** `git checkout -b feature/woo-9`. Edit `code/composer.json`'s constraint (`"wp-plugin/woocommerce": "^8.0"` → `"^9.0"`), run `composer update wp-plugin/woocommerce` locally. `composer.lock`'s diff is small and reviewable — old→new version, updated dependency hashes — a genuine advantage of composer-managed mode over vendored-wholesale, where the equivalent change would be a multi-thousand-line diff across the entire plugin source with no meaningful review surface.
2. **Manifest check.** If 9.x crosses a boundary the pinned `woocommerce` manifest doesn't cover — DESIGN.md's own example: *"a manifest for Woo 7 must not claim Woo 9"* — bump `manifests/woocommerce.json`'s new `version_range` (§4.3) in the same PR, or push the manifest update as a prerequisite commit.
3. **CI gates it** — the *existing* conformance harness (`sandbox/conformance/run.sh`), extended per §4.4 to install the exact pinned version rather than always-latest, runs its clean-room capture→apply→re-capture round-trip against Woo 9.x specifically. If the manifest's classification rules no longer match 9.x's real behavior, the round-trip diff is non-empty and CI fails loudly — this is DESIGN.md §3.1.2/§8's manifest-treadmill defense, now also exercising the **exact version this branch is bumping to**, rather than whatever `plugin install woocommerce` happens to resolve as "latest" on the day CI happens to run (today's actual behavior — see §4.4).
4. **Merge.** `main` now has code/composer.lock at Woo 9.x plus (if needed) an updated manifest.
5. **`duo deploy prod`** materializes the new code (§2), then reconciles `active_plugins` (§3.4) — WooCommerce was already active, so no `activate_plugin()` call is needed purely for activation *state*; what changed is the code loaded underneath it. `deploy` explicitly forces one full WP bootstrap over the transport (any wp-cli call boots `plugins_loaded` fully) immediately after materializing, so WooCommerce's own updater (`WC_Install`) notices the version change deterministically on `deploy`'s own schedule — not whenever the next stray visitor or cron tick happens to hit the site.
6. **Migrations run inside WooCommerce's own code**, self-triggered, exactly as they do on a manual admin-panel update today: WooCommerce compares `get_option('woocommerce_version')` (currently loaded code) against `WC_VERSION` (the constant the *new* code defines), detects the mismatch, and runs its installer/upgrade routines — synchronously for lightweight steps, via its own Action Scheduler background jobs for heavier ones (schema changes, large data migrations). **`duo deploy` guarantees the update *process starts* deterministically; it does not guarantee instant completion** — see §7 for the honest caveat on large-catalog migrations.
7. **`woocommerce_db_version`/`woocommerce_version` update themselves** as a side effect of step 6. Duo does not touch either value directly, and this proposal does not change their existing `{"class": "env", "required": false}` classification in `manifests/woocommerce.json` (the `required` key is DUO-3232's later, unrelated addition — every `class: "env"` rule now carries one — but the `env` classification itself, this proposal's actual subject, is unchanged) — that classification is already correct. This is the crux worth stating explicitly: **"migrations re-run per environment" is not a mechanism Duo builds.** It falls straight out of two things that are *already true*: code is deployed identically everywhere via the same git revision + composer resolution, and `woocommerce_db_version` is already, correctly, excluded from `state/` — so each environment's own copy of the plugin code independently notices its own staleness and self-heals, with zero coordination and zero migration-state ever entering the repo. The only thing this proposal adds is the **ordering guarantee** (§3.4) that this self-healing has a chance to run before `duo apply` writes content into whatever schema the new code now expects.
8. **`duo apply prod`** now runs — canary-armed, hook-free, direct SQL — safely, because the schema it's writing into already matches the code that's been running since step 6.
9. **Staging, dev, and any other environment** repeat steps 5–8 independently, on their own schedule, from the same git revision. No cross-environment coordination, no shared migration ledger — each environment's `duo deploy` + WooCommerce's own updater does the same self-healing locally.

### 4.2 `woocommerce_db_version` — no manifest change needed, ordering is the fix

To be explicit since the mission calls this out specifically: **no change to `manifests/woocommerce.json`'s existing `"woocommerce_db_version": {"class": "env", "required": false}` / `"woocommerce_version": {"class": "env", "required": false}` is proposed** (the `required` key is DUO-3232's later, unrelated addition to the grammar). Both are already correctly excluded from `state/` today. What was missing wasn't classification — it was the *ordering guarantee* (§3.4) that code (and the migrations it triggers) lands before state apply runs against it. That's the actual gap this proposal closes for the worked example above.

### 4.3 `version_range` mechanics

**Where declared**: a new, optional top-level key on each plugin manifest (`manifests/<name>.json`), sibling to the existing `options`/`post_meta`/`actions`/`deletions` keys:

```jsonc
// manifests/woocommerce.json — proposed addition
"version_range": {"min": "8.0.0", "max": "10.0.0"}   // min inclusive, max exclusive
```

**Why `{"min", "max"}` and not a composer-style constraint string** (`">=8.0 <10.0"`, `"^8.0"`): the agent (`agent/`) is explicitly, deliberately dependency-free — DESIGN.md §4: *"a drop-in agent must not vendor libraries."* A full semver-range parser (`composer/semver` or equivalent) is exactly the kind of dependency that constraint rules out. PHP's built-in `version_compare()` already does everything a min/max pair needs with zero new dependency:

```
satisfies(installed, range) := version_compare(installed, range.min, '>=')
                             && version_compare(installed, range.max, '<')
```

The `cli/` orchestrator has the identical dependency-free constraint (its own README: *"dependency-free PHP 8+: no composer, no vendored packages"*), so this shape is consistent with both halves of the existing toolchain, not just the agent.

**What reads the installed version**: WordPress's own plugin-header parser (`get_plugin_data()` or equivalent — the same mechanism WP's own plugin-list admin screen and update checker already use to read the `Version:` header comment out of a plugin's main file). This is the right primitive specifically because it reads what's **physically on disk**, correctly for both composer-managed plugins (where it happens to agree with `composer.lock`) and vendored ones (which have no lockfile to check at all) — one check, uniform across both modes.

**Plan-time warning wording**: given in §3.3 (the third message block) — placed there rather than duplicated here because it shares the same `code_mismatch` plan bucket and the same blocking posture (`outside_version_range` hard-blocks apply by default, `--force-code-mismatch` overrides) as the directory-existence check.

**Relationship to the broader manifest-versioning workstream** (referenced in the mission as task #11 item 4): this proposal deliberately stays narrow. It adds a single optional field to today's one-file-per-plugin manifest shape and the plan-time check that reads it — nothing here assumes or requires manifests becoming multiple versioned files (`manifests/woocommerce-8.json`, `manifests/woocommerce-9.json`, …), which DESIGN.md §3.1.2's *"versioned artifacts pinned to plugin version ranges"* language gestures toward as a further-future shape. If/when that fuller redesign lands, `version_range` is the natural field that would select *which* manifest file auto-loads for an installed version — but that file-selection mechanism is that workstream's to design, not this proposal's. What's specified here is useful on its own, today, even as a single-file field: a declared compatibility window with a loud warning outside it, better than the silent-drift status quo.

### 4.4 Conformance harness gating

Today, `sandbox/conformance/run.sh`'s `install_env()` always runs `wp plugin install <slug> --activate` — whatever WordPress.org resolves as latest at the moment CI happens to execute, an accidental, unpinned version (confirmed by reading the script — no `--version` flag, no read of any manifest field). Two small, additive changes close this gap:

1. **`sandbox/conformance/manifests.json`** gains a `test_version` field per entry (e.g., `"test_version": "9.4.1"` for `woocommerce`) — the *specific* version CI verifies against right now. This is intentionally **separate** from the manifest's own `version_range`: `version_range` is "what the manifest claims to support" (a runtime compatibility check, §4.3), `test_version` is "what CI actually exercises" (a point-in-range pin, bumped on its own cadence — plausibly more often, even automatable via a Dependabot-style bot watching the registry). They should agree in spirit (test_version should fall inside version_range) but are different knobs for different audiences.
2. **`sandbox/conformance/run.sh`**'s `install_env()` reads `test_version` and installs it explicitly: `wp plugin install woocommerce --version="$TEST_VERSION" --activate` (wp-cli's `plugin install` already supports a `--version` flag **(recall — standard, long-standing wp-cli feature, not re-verified this session)**).

This makes a plugin-version bump in `code/` and a `version_range` bump in the manifest **mutually self-verifying** through the same CI job that already exists for manifest changes generally — no new pipeline, no new job, just closing the "which version did we actually just test" gap in the existing one.

---

## 5. Sandbox demonstration plan

### 5.1 docker-compose changes

New, additive, isolated profile (matching the existing `spikee`/`conf` profile pattern precisely — does not touch or risk envs A–E or the conformance envs). Proposed name **`spikef`** (envs A–E already exist; F is the next free letter, and this proposal's own scope maps naturally onto it — named here as a suggestion for whoever picks this up, not a claim that any coordination with another agent has happened).

The one structural change that matters: **replace the persistent named volume backing `/var/www/html` with a bind mount of the site repo's own `code/` directory**, so the environment's webroot is no longer the docker image's baked-in default — it's whatever this specific site repo's `code/` says it should be.

```yaml
# sandbox/docker-compose.yml — additive, profile: [spikef]
  db-f1: {<<: *db, profiles: [spikef], volumes: [dbf1:/var/lib/mysql]}

  wp-f1:
    image: wordpress:php8.3-apache
    profiles: [spikef]
    depends_on: {db-f1: {condition: service_healthy}}
    ports: ["8808:80"]
    environment: {<<: *wp-env, WORDPRESS_DB_HOST: db-f1}
    volumes: &vol-f1
      - ./siterepo/f1/code:/var/www/html          # NEW — was a named volume; now code/ itself
      - ../agent:/var/www/html/wp-content/mu-plugins/duo:ro          # unchanged — see §1.7's sandbox carve-out
      - ../agent/duo-loader.php:/var/www/html/wp-content/mu-plugins/duo-loader.php:ro
      - ../manifests:/duo-manifests:ro
      - ./siterepo/f1:/siterepo                    # unchanged — whole-repo access for --repo=

  cli-f1:
    image: wordpress:cli-php8.3
    profiles: [spikef]
    user: "33:33"
    depends_on: {db-f1: {condition: service_healthy}}
    environment: {<<: *wp-env, WORDPRESS_DB_HOST: db-f1}
    volumes: *vol-f1

  composer-f1:                                     # NEW — one-shot materialization service
    image: composer:2
    profiles: [spikef]
    working_dir: /app
    volumes: [./siterepo/f1/code:/app]
    entrypoint: ["composer"]

  # (wp-f2 / cli-f2 / composer-f2 / db-f2: identical, second env, ports 8809)

volumes:
  dbf1: {}
  dbf2: {}
  # NOTE: no wpf1/wpf2 named volumes — code/ is a bind mount, not a named volume, for this profile
```

No named volume is declared for `/var/www/html` under this profile — that is the entire point of the change (§2.2's "docker: bind-mount, no separate sync step" design). `uploads/` still needs to survive independent of `code/` churn (media is `state/`'s concern per spec, not code/'s) — for the spike, `wp-content/uploads/` can stay inside the bind-mounted tree (simplest, matches how a real full-tree deployment behaves — uploads is a subdirectory of the webroot on disk, just excluded from git via `code/.gitignore`, not excluded from the filesystem).

### 5.2 Script outline — `sandbox/tests/spike_f_code.sh`

Following the existing spike scripts' exact shape (helper functions, `say`/`pass`/`fail`, `wp_f1`/`wp_f2` wrappers):

```
1. boot: docker compose --profile spikef up -d db-f1 db-f2  (webserver/cli come up after code exists)
2. author code/ on the host (simulating a developer): write siterepo/f1/code/composer.json
   per §1.2 (roots/wordpress pinned, wp-plugin/woocommerce, wp-theme/twentytwentyfive),
   plus a vendored dummy plugin directory (siterepo/f1/code/wp-content/plugins/acme-vendored/
   with a minimal valid plugin header) to exercise the mixed case.
3. materialize: docker compose --profile spikef run --rm composer-f1 install --no-dev
   → assert code/wp-admin, code/wp-includes, code/wp-content/plugins/woocommerce all now exist.
4. bring up wp-f1/cli-f1; core install; write state/options/core.json by hand (bootstrap —
   no capture yet) with active_plugins: ["akismet/akismet.php"] only (NOT woocommerce, NOT
   the vendored plugin yet) — this is the pre-invariant-exercise baseline.
5. git init the site repo, commit code/ + the bootstrap state/, this becomes "main".
6. ACCEPTANCE 1 — plugin added on a branch appears + activates on apply:
   git checkout -b add-woo
   edit state/options/core.json: add "woocommerce/woocommerce.php" to active_plugins
   (composer.json/lock already has it from step 2 — this branch only flips activation intent)
   commit, merge to main, pull on f2.
   duo deploy f2   → assert: code/wp-content/plugins/woocommerce now exists on f2
                       (already did, from step 3's materialization — assert reconciliation
                       specifically: wp plugin list --status=active on f2 now includes
                       woocommerce, AND a WooCommerce-installer side effect fired — e.g.
                       assert wc_get_page_id('shop') resolves to a real page, proving
                       register_activation_hook actually ran, not just an option flip).
   pass "plugin added on a branch appears and activates on apply".
7. ACCEPTANCE 2 — plugin removed fails the invariant until deactivated:
   on f1: rm -rf siterepo/f1/code/wp-content/plugins/acme-vendored (the vendored plugin from
   step 2), but LEAVE it in active_plugins (simulating a developer who forgot to also update
   state) — commit, push, pull on f2.
   duo plan f2 --format=json → assert plan.code_mismatch contains one row,
     issue == "missing_in_code", plugin == "acme-vendored/acme-vendored.php".
   duo apply f2 → assert non-zero exit, stderr matches the §3.3 "does not exist in this
     environment" wording.
   fix: edit state/options/core.json on f1, remove acme-vendored from active_plugins
   (deactivation-via-state, matching §3.3's failure-mode wording), commit, push, pull on f2.
   duo plan f2 --format=json → assert code_mismatch is now empty.
   duo apply f2 → assert exit 0.
   pass "plugin removed fails the invariant check until deactivated, and recovers cleanly".
8. ACCEPTANCE 3 (stretch, if time permits) — version_range warning:
   add "version_range": {"min": "9.0.0", "max": "99.0.0"} to a copy of manifests/woocommerce.json
   used by this spike's site.duo.json; f2 is still running whatever WooCommerce version step 6
   installed (likely < 9.0.0 depending on wpackagist/wp-packages resolution at spike-build time).
   duo status f2 → assert output contains "outside the 'woocommerce' manifest's declared
     version_range" (§3.3's wording).
   pass "version drift against a manifest's version_range surfaces as a plan-time warning".
```

Each acceptance step asserts on exit codes and specific plan/error content, not just "the command ran," matching the existing spike scripts' own standard (`assert_exit`/explicit `grep -q` assertions in `cli_smoke.sh`, `spike_d_woo.sh`'s explicit BLOCKED/FORCED assertions).

---

## 6. Engine touchpoints

Minimal list — file, hook point, one-line description. No implementation shown, per the requested altitude for this section.

| File | Hook point | Change |
|---|---|---|
| `manifests/core.json` | `options` map | Add `active_plugins`, `template`, `stylesheet`, each `{"class": "managed"}` (data change, not code — listed because it's required and minimal). |
| `agent/src/Capture.php` | `build_options()` (or a new sibling private method called from `build()`) | Bespoke read of `active_plugins`/`template`/`stylesheet` into the same options output, bypassing the generic `authored_options()`-driven loop (they're `managed`, not `authored`) — no ref-tokenization needed, values are already portable strings. |
| `agent/src/Apply.php` | `build_plan()` | Add the two §3.2 checks (directory/file existence via WP's plugin-validation primitives; `version_range` compatibility via `version_compare()`), populating a new `code_mismatch` plan bucket; also add the `code_revision_stale` check against `duo_kv['code_revision']`. |
| `agent/src/Apply.php` | `apply_options()` | Exclude `active_plugins`/`template`/`stylesheet` from the generic direct-SQL upsert loop — they must never be written via raw `$wpdb`. |
| `agent/src/Apply.php` | `run()` | New precondition: hard-block (exit non-zero) when `code_mismatch` contains an `missing_in_code`/`outside_version_range` row tied to a currently-active entry, unless `--force-code-mismatch`. |
| `agent/src/Deploy.php` *(new file)* | n/a (new class, parallel to `Apply`/`Capture`) | Reconciles `active_plugins`/`template`/`stylesheet` against `state/options/core.json` using real WP APIs (`activate_plugin()`/`deactivate_plugins()`/`switch_theme()` — hooks fire deliberately); writes `duo_kv['code_revision']` on verified success. Runs **outside** `Canary::arm()`/`disarm()` — never inside the canary-armed window. |
| `agent/src/Cli.php` | new method, registered the same way as `capture`/`plan`/`apply` | New `wp duo reconcile-code --repo=<path>` subcommand — the agent-side half of `duo deploy` (see next row), invoked by the orchestrator over the existing transport abstraction. |
| `cli/src/DeployCode.php` *(new file)* | n/a (new class, parallel to `Doctor`/`PlanSummary`) | Orchestrator-side materialization: runs composer install (local/docker, in place) or resolve-then-rsync (ssh), then invokes `wp duo reconcile-code` over the transport. |
| `cli/src/Transport.php` | new abstract method (e.g. `deployCode()`) | Each transport implements how materialization reaches it: local/docker run composer directly via the *existing* raw-command path (no new primitive needed there); ssh gets one new capability — resolve off-box, then rsync to `host`+`wp_path` (today private properties on `SshTransport`, would need exposing or handling internally). |
| `cli/duo` | verb dispatch | New `deploy <env>` verb, wired the same way `envs`/`doctor`/`status`/`capture`/`plan`/`apply` already are; usage text updated. |
| `cli/src/Doctor.php` | `run()` | Optional 5th check: "`code/` present and non-empty" — cheap orchestrator-side fast-fail, belt-and-suspenders alongside the agent-side check in `Apply::build_plan()` (which stays authoritative). |
| `manifests/woocommerce.json` (and, illustratively, other manifests) | `version_range` key | Data change: add the new optional field (§4.3). |
| `sandbox/conformance/manifests.json`, `sandbox/conformance/run.sh` | `install_env()` | Add `test_version` field; install that exact version instead of always-latest (§4.4). |

**What explicitly does NOT need engine work:**

- **`agent/src/Ledger.php`** — `duo_kv` is already a generic key-value table; `code_revision` is just a new key, read/written through the *existing* `kv_get()`/`kv_set()`. Zero schema or code change.
- **`agent/src/Policy.php`** — the `managed` class value and the generic `rule()`/`option_rule()` lookup already exist and already correctly hand back whatever a manifest declares; the special interpretation of `managed` happens at call sites (Capture/Apply), exactly as it already does for `_menu_item_*`. Zero code change.
- **`agent/src/Tokens.php`** — `active_plugins`/`template`/`stylesheet` values need no ref-tokenization (portable strings by construction). Untouched.
- **`agent/src/Canary.php`** — must stay **exactly** as it is; this is a hard constraint, not just an absence of need. The temptation to special-case plugin-activation hooks inside the canary should be resisted — the entire value of the canary is that "armed" means one fixed thing. Deploy's hook-firing work living structurally outside the canary-armed window (§3.4) is what keeps this true, not a canary code change.
- **`agent/src/Journal.php`** — the provenance journal's capability×surface signal already handles whatever writes `activate_plugin()`/`switch_theme()` perform, correctly, with zero special-casing (they'd be attributed to the CLI/admin surface like any other write, if journaling happens to be on during a deploy).
- **`agent/src/Blocks.php`, `Canon.php`, `Uuid.php`** — unrelated to code/, untouched.
- **The existing three-way plan/conflict/collision logic for posts/terms/menus** — unchanged; code materialization is orthogonal to content-entity plan buckets.
- **Merge semantics** — `code/composer.json`/`composer.lock` conflicts are ordinary git merge conflicts on JSON text, resolved with git's normal tooling (`composer why`/regenerate-the-lock-after-merge is standard PHP-ecosystem practice); no Duo-specific merge driver is needed for code, mirroring DESIGN.md §3.4's existing git-native-first stance for state.

---

## 7. Open questions & risks

Ordered roughly by severity, matching DESIGN.md's own "loud, blocking, scoped guarantees" posture — named honestly rather than smoothed over.

1. **[highest] wp-admin/filesystem-initiated updates are silent code drift, and detection alone is not a fix.** If an admin clicks "Update Now" in wp-admin (or auto-updates fire), WordPress writes new plugin files directly onto disk — under full-tree management, that's physically inside the git working tree, entirely outside git/composer. §3.2's `code_revision_stale`/env-vs-code checks can *detect* this after the fact, but detection-only leaves a window where the environment is already running unreviewed code. **Recommendation, not yet built anywhere in this proposal's engine touchpoints**: Duo-managed environments should set `DISALLOW_FILE_MODS` **(recall — standard, well-known WP constant that disables the entire plugin/theme install/update/delete UI, not independently re-verified this session)** as a matter of policy in `wp-config.php` (§1.6), closing the hole at the source rather than only detecting it afterward. This should probably be a `doctor`-checked requirement, not just a recommendation in prose — flagged here as scope for a follow-up task rather than silently added to §6's list, since it's policy/hardening, not code-half layout per se.

   **DUO-3231 update**: both halves built. Detection: a new `code_drift` plan bucket (spec/repo-format.md's "Code-half facts & deploy" section) — narrower and complementary to `code_mismatch`/`code_revision_stale` above, since it catches an out-of-band version change that stays *inside* a pinned `version_range` (invisible to `code_mismatch`) without needing the materialization transport `code_revision_stale` is still blocked on. Baseline recorded in `duo_kv` at every successful `duo deploy`/`duo capture` (`Deploy::record_code_versions()`); compared on the next plan/apply/deploy (`Deploy::code_drift()`); same blocking-with-escape-hatch posture as `code_mismatch` (`--force-code-drift`). Posture: `wp duo doctor` now checks `DISALLOW_FILE_MODS` as its 5th check — **advisory only** (never fails `doctor`'s exit code, printed `[WARN]` not `[FAIL]`), matching this risk entry's own "as a matter of policy," not a hard requirement — an operator who hasn't set it is still fully served by `code_drift` catching the after-the-fact symptom.

2. **[highest] Composer/registry availability becomes a new dependency in the deploy critical path.** local/docker materialization (§2.2) needs live network access to WP Packages/wpackagist/Packagist at deploy time. A registry outage during an urgent hotfix is a new failure mode this design introduces that a purely-vendored/manually-FTP'd WP site never had. The ssh transport's off-box-resolve-then-rsync design (§2.2) already sidesteps this for production specifically (production needs no registry access at all), which is a real mitigation, not just a workaround — but local/docker environments (including, notably, CI) still carry the exposure. A private registry mirror (Satis or similar) is the standard fix for organizations that hit this in practice; not proposed as a v0 requirement here, named as the known escape hatch.

3. **[highest] Rollback-after-migration safety is fundamentally bounded by plugin authors, not by Duo.** §2.3 states the rule (never roll back code alone against an already-migrated database) but the rule is a discipline, not a guarantee — Duo has no way to *detect* "this rollback target predates an irreversible migration" short of maintaining its own migration-awareness per plugin, which is exactly the manifest-treadmill cost DESIGN.md's existential risk #2 already warns against taking on unboundedly. Left as an explicit operator responsibility, stated loudly rather than papered over.

4. **How capture-side detects environment-installed-but-not-in-code plugins.** §3.2's checks are `active_plugins`-driven (code ⊇ what's declared active). The fuller, symmetric check — does *every* plugin directory physically present on the environment (active or not) correspond to something `code/` declares — is not designed in this proposal beyond the sketch in §3's framing. Concretely: `get_plugins()` enumerates everything on disk regardless of activation state; diffing that against `composer.lock` + vendored-commit history and failing loudly on anything unaccounted-for (mirroring the existing "unclassified meta keys abort capture loudly" posture) is the natural extension, proposed here as a **follow-up**, not built out to the plan-bucket/wording level §3.2/§3.3 reached for the `active_plugins`-driven direction. Lower urgency than risk #1 because an *inactive* stray plugin is lower severity than an active one violating the invariant, but it's the same underlying hole (filesystem-level drift) approached from the other direction.

5. **Symlink strategies.** The ssh-transport release-symlink refinement (§2.2/§2.3) assumes symlinks work on the target — not universally true: some shared/managed hosts disallow them outright, and `open_basedir` restrictions or a handful of plugins doing realpath-sensitive checks can misbehave across a symlink boundary. Recommend treating symlink-swap as optional hardening with a documented fallback (rsync straight into the live docroot, accepting a brief non-atomic window) for hosts that disallow it — not a hard requirement of the ssh transport. Unrelated aside worth heading off: this is **not** about `wp-content/uploads/` symlinking to external storage, which is out of code/'s scope entirely (handled by the existing `media/<sha256>` mechanism in `spec/repo-format.md`).

6. **File permissions across transports.** Real and host-variable, not fully solvable in the abstract. Tensions worth naming: (a) the recommendation in risk #1 (`DISALLOW_FILE_MODS`) implies the webserver process ideally should not need *write* access to `code/` at all post-materialization — stronger than typical WP hosting defaults, and some plugins will show harmless-but-alarming "not writable" notices in wp-admin's site-health screen as a result; worth documenting as expected, not a bug. (b) The user running `composer install`/rsync at deploy time needs write access; the webserver's runtime user ideally doesn't — classic build/run user separation, easy to state, hard to guarantee uniformly since ssh targets' user/host setup is explicitly not something Duo owns (DESIGN.md §4's host-agnostic stance). (c) `rsync -a` preserves permissions/ownership *from the source* (e.g. a laptop or CI runner's own user), which will generally not match the target's expected webserver user — needs an explicit `--chmod`/ownership-fixup step, not just "rsync -a and hope."

7. **Migration completion timing.** §4.1 step 6 is explicit that `duo deploy` triggers migration *start* deterministically, not completion — WooCommerce's own background-processed migrations (large catalogs especially) can leave a real window where `duo apply` (step 8) could still race an in-flight migration if run too eagerly. Not resolved here beyond naming it; a poll/wait step or an explicit human gate for major version bumps on large sites is the likely answer, left for whoever implements §3.4/§6's `Deploy.php`.

8. **The agent's own composer-package distribution (§1.7) is a recommendation, not a verified-working mechanism.** No `duotronic/duo-agent` package exists; the VCS-repository approach is standard composer practice in the abstract but untested for this specific repo/agent. The vendored-wholesale fallback is lower-risk and may be the pragmatic v0 answer even though it's the less elegant one.

9. **Consistent with existing non-goals, not a gap**: `active_sitewide_plugins` (network/multisite-wide activation, a *different* option from `active_plugins`) is out of scope, matching DESIGN.md §5's existing "Multisite (v1)" exclusion. Noted here only so it isn't mistaken for an oversight.

---

**Sources consulted this session** (WebSearch/WebFetch, distinguished throughout from recalled/training knowledge per-claim above):
- [WP Packages is Working the Way Open Source Should — WordPress News](https://wordpress.org/news/2026/03/wp-packages/)
- [Introducing WP Composer as a WPackagist Replacement — Roots](https://roots.io/introducing-wp-composer-as-a-wpackagist-replacement/)
- [WP Packages vs WPackagist](https://wp-packages.org/wp-packages-vs-wpackagist), [WP Packages docs](https://wp-packages.org/docs)
- [WP Engine Acquires WPackagist — WP Engine](https://wpengine.com/blog/wp-engine-acquires-wpackagist/); [WP Engine Acquires WPackagist. WordPress X Account Calls It a "Parasite." — The Repository](https://www.therepository.email/wp-engine-acquires-wpackagist-wordpress-calls-it-a-parasite)
- [Announcing the roots/wordpress Composer Package — Roots](https://roots.io/announcing-the-roots-wordpress-composer-package/); [Composer with WordPress Resources — Roots](https://roots.io/composer-wordpress-resources/)
- [roots/bedrock composer.json, `master` branch, GitHub](https://github.com/roots/bedrock/blob/master/composer.json)
- [composer/installers — Packagist.org](https://packagist.org/packages/composer/installers)
- [WPackagist.org](https://wpackagist.org/)

---

## Phase 1: implemented (2026-08-06)

§3's legacy lifecycle invariant, `wp duo deploy`, and §4's `version_range`
mechanics shipped (tasks #32/#39/#40; spec v0.9 section "Code-half facts &
deploy"): managed-class capture of `active_plugins`/`template`/`stylesheet`,
the plan `code_mismatch` bucket, deploy's hook-firing reconciliation outside
the canary, and forceable lifecycle compatibility reporting. The 2026-08-07
first functional skeleton extends that with a descriptor-hashed code payload:
`code_revision_stale` is now a non-forceable ordering gate until host
stage → lifecycle → finalize completes. `sandbox/tests/spike_g_code.sh`
remains evidence for the earlier bind-mounted lifecycle leg, not proof of
the new materializer path.

The skeleton's promotion boundary is deliberately split as well: only
`promotion-begin` may create/recover the bounded cross-process owner/artifact
row, while stage, lifecycle, finalize, and apply must continue that exact live
session and carry the host-observed outer artifact hash. Each mutation process
holds a connection-scoped database advisory fence across long hooks and
filesystem walks. Manual checkpoint import still requires external maintenance
exclusion because importing the database can replace any lock row stored in it.
The two fresh lifecycle processes publish ordered positive phase receipts—even
for no-op phases—and code-finalize requires both. Absence of an unresolved hook
attempt is therefore not mistaken for proof that lifecycle was ever run.
Each mutating lifecycle phase first stores a pre-hook ambiguity receipt in that
same exact session. A different begin cannot overwrite it; only the original
owner/artifact can temporarily reacquire for the documented checkpoint import,
and stage/finalize/lifecycle/apply remain blocked until that import restores the
pre-hook row. Status remains non-zero with an `INCOMPLETE_LIFECYCLE` section
throughout that interval.
Finalize's completed descriptor/revision and all temporary stage-marker deletes
form one database transaction, leaving the complete staged record retryable if
any statement or commit fails rather than exposing a partially-cleared ledger.
The fresh-process canonical convergence verifier likewise receives temporary
snapshots of the locked in-memory policy and compiled artifact, hash-validates
their association, and never reopens a checkout that may move after locked
preflight.

The fatal-safe host control bootstrap is registered at WP-CLI's
`after_wp_config_load` boundary. The current v0 implementation proves and
requires the standard content/MU paths and refuses explicit `WPMU_PLUGIN_DIR`
or `SUNRISE` before compiler/target mutation. Supporting custom WordPress or
Bedrock layouts remains an explicit agent-locator/layout-contract milestone;
it must not be implemented by guessing around configured bootstrap code.

Two operational findings from the spike worth carrying forward: (1) docker nested bind mounts pin their source directory at container-create time (`rprivate`) — author `code/` before creating the long-lived containers, and `--force-recreate` them after any rm-and-recreate of the mount source; in-place content changes propagate live. (2) Recovering from "code removed while still active" cannot use `wp plugin deactivate`/wp-admin (both validate the plugin on disk) — reconcile via canonical from an environment that still has the code, or direct `active_plugins` option surgery as last resort.
