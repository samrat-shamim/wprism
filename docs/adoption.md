# Adopting an existing WordPress host

`duo adopt <env>` installs Duo onto an already-running WordPress site reached
through the orchestrator's SSH transport. It is the bootstrap step before the
first `duo pending`, `duo classify`, or `duo capture`; it does not claim that
the site's pre-existing plugin state is already classified.

## Prerequisites

The machine running `duo` needs PHP 8+ with Sodium, `ssh`, `scp`, and `tar`.
The target needs PHP 8+ with Sodium and `fsync()`, a working `wp` command,
`tar`, and a WordPress install. It does **not**
need Git. The SSH account must be able to write:

- WordPress's actual `WPMU_PLUGIN_DIR` (discovered with `wp eval`, never
  guessed from `wp_path`);
- the environment's configured `repo_path`.

Configure the target in `site.duo.json` or the machine-local,
gitignored `.duo-envs.json`:

```json
{
  "envs": {
    "production": {
      "transport": "ssh",
      "host": "deploy@wp.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/home/deploy/site-repo"
    }
  }
}
```

For signed rollback receipts, put the controller key in the machine-local
`.duo-envs.json` overlay and keep it mode `0600`:

```json
{
  "envs": {
    "production": {
      "transport": "ssh",
      "host": "deploy@wp.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/home/deploy/site-repo",
      "rollback_key_id": "production-2026-01",
      "rollback_signing_key": ".keys/production-rollback.ed25519"
    }
  }
}
```

The key file contains canonical base64 of an Ed25519 secret key. Relative
paths resolve from the registry file. Adoption derives and transfers only the
public key; the secret never enters the archive, SSH command, or target.
Rotation adds a new key id because installed key ids are immutable.

For a host participating in the verified rollback profile, also configure its
target-owned exclusion provider and four raw recovery adapters. Values are
absolute argv arrays, not shell strings, and contain no token or credential.
The schema and stdin/stdout protocol are in
[recovery-runtime.md](recovery-runtime.md). Adoption fails unless the provider
attests traffic, background jobs, package/self updates, filesystem writers,
fail-closed disconnect behavior, and every adapter probes without site code.

If the environment needs a dedicated SSH configuration, set `ssh_config` to
that file. Relative paths resolve from the registry file that defines the
environment. The same file is passed to both `ssh -F` and `scp -F`, so port,
identity, proxy, and host-key policy stay identical:

```json
{
  "envs": {
    "production": {
      "transport": "ssh",
      "host": "duo-production",
      "ssh_config": ".ssh/production.conf",
      "wp_path": "/var/www/html",
      "repo_path": "/home/deploy/site-repo"
    }
  }
}
```

## Install or update

Run from a Duo source checkout whose `cli/`, `agent/`, `manifests/`, and `recovery/`
directories belong to the release you intend to install:

```sh
cli/duo doctor production   # expected to report the agent/repo missing first
cli/duo adopt production
```

Adoption performs these operations:

1. verifies SSH reachability and an installed WordPress;
2. discovers `WPMU_PLUGIN_DIR` through the target's own `wp` command;
3. sends one archive containing this checkout's complete `agent/`,
   `manifests/`, and public recovery-runtime trees;
4. stages and rollback-protects the live paths, then installs:
   - `WPMU_PLUGIN_DIR/duo/` (the agent),
   - `WPMU_PLUGIN_DIR/duo-loader.php` (the required top-level loader),
   - `WPMU_PLUGIN_DIR/manifests/` (the manifest library),
   - `repo_path/.duo/control/recovery-runtime/` (outside managed code);
5. creates or verifies the protected `repo_path/.duo/control/` root and its
   stable target identity, and installs the configured public verification
   key without copying controller secrets; when `rollback_recovery` is set it
   installs the path-only configuration and probes the exclusion provider plus
   all four isolated adapters;
6. creates `repo_path/site.duo.json` only when it is absent, initially pinning
   `core` with post/page/attachment and category/post_tag scope;
7. starts fresh wp-cli processes to prove the remote `DUO_AGENT_VERSION`
   exactly matches this checkout and that `Policy::load()` can read the seed
   plus installed manifest library;
8. verifies the recovery runtime can read and validate the external target;
9. discards the prior release's rollback copies only after those checks pass;
10. runs the normal `duo doctor` checks. The command is successful only when
   every blocking doctor check passes.

An existing `site.duo.json` is never overwritten. Re-running the command is
the update mechanism: the current agent and manifest trees are replaced by
the exact trees beside the invoking CLI, while the site's policy remains
untouched. Symlink destinations are refused rather than followed, and an
install failure restores the previous agent, loader, and manifests and
removes a seed created by that failed run. A target-side adoption lock refuses
overlapping operators. If the host process is killed so abruptly that
`.duo-adopt-lock` remains, adoption fails loudly and requires operator
inspection rather than guessing whether the interrupted release should be
committed or restored.

The control root holds `target.json`, `target.lock`, immutable public keys,
and the installed runtime. Receipts and signed event chains live beside it at
`repo_path/.duo/rollback/<receipt-id>/`; re-adoption updates only the runtime
and preserves the stable identity and all generations. This is the authority
substrate plus the fatal-safe executor, not automatic resource rollback: the
current in-place promotion still uses the operator procedure until later
checkpoint, code/storage, verifier, and integrated crash-matrix slices drive
this executor through a complete promotion.

## Why the manifest directory is installed beside the agent

`DUO_MANIFESTS_DIR` is a process environment variable. A value injected into
a container, service manager, or interactive shell is not generally present
in a later SSH login, so it cannot be the installation contract for an SSH
transport. The earlier hand-run adoption proof worked around that by copying
manifests to the agent's literal `/duo-manifests` fallback, which also assumes
the SSH account can modify a root-level path.

`duo adopt` instead uses the other existing `Policy::manifests_dir()`
fallback: `manifests/` beside the installed `duo/` directory. This layout is
stable across fresh SSH sessions, stays inside the operator-writable
mu-plugin directory, and requires neither an environment variable nor root
filesystem access. Adoption verifies that exact resolved path before it
reports success.

## Version visibility and remaining boundary

Every adoption prints and verifies the exact `DUO_AGENT_VERSION` it installed;
rerun the same release's `duo adopt` command to repair or update a stale copy.
`duo doctor` independently confirms that an agent is present, but it does not
know which source checkout or release an operator intended and therefore
cannot compare against an expected version when run alone. Packaging a signed
release descriptor that gives standalone doctor such an expectation remains
a named future distribution concern; presence is not presented as a
standalone freshness guarantee.

## First-capture runbook

Before capture, record checksums for state that must remain local to the
source runtime (plugin tokens, caches, derived indexes, custom-table rows).
The exact query is plugin-specific; keep both the command and its output with
the change record. Then measure the site and export a review batch:

```sh
cli/duo coverage production --format=json > production-coverage.json
cli/duo pending production
cli/duo classify production --export-batch=production-review.json
```

`coverage` is a survey, not a green gate. Reconcile its active plugin owners,
option counts, and custom-table counts with the inventory you expect. A plugin
with uncovered state needs a manifest/schema issue; it cannot be made safe by
classifying an unrelated option. The exported JSON contains the pending
command's redacted evidence but never live values. Review every decision:

```json
{
  "section": "options",
  "key": "legacy_banner",
  "class": "authored",
  "ref": null,
  "cast": null,
  "allow_secret": false
}
```

Use `runtime` for environment-local operational state, `derived` for state the
declared regeneration path rebuilds, `env` for separately provisioned values,
and `authored` only for portable intent. `managed` is reserved for lifecycle-
managed options. Attach `ref`/`cast` only when the value's schema justifies it.
An authored secret requires the row's explicit `allow_secret: true`; that is a
reviewed escape hatch, not a recommendation to store secrets in Git.

Apply the complete batch and immediately check for a next queue:

```sh
cli/duo classify production --apply-batch=production-review.json
cli/duo pending production
```

The artifact is bound to the environment and the exact pending evidence by
SHA-256. If a write arrives after export, apply refuses before changing
`site.duo.json`; export and review a new batch. Partial batches and surfaces
that actually require a manifest or table schema refuse for the same reason.
One successful batch can expose another layer, so "review queue is empty"—not
the batch command alone—is the capture gate.

Capture only after the queue is empty, then repeat the runtime checksums:

```sh
cli/duo capture production
```

Any changed runtime checksum is a failed adoption, even when capture exits 0.
Keep the source repo as the canonical artifact; do not copy its WordPress
database to prove portability.

## Prove a second environment before promotion

Install matching plugin/theme code on an independent target and run `duo
adopt` there. Transfer only the source site repo over the normal SSH release
channel. Record target runtime checksums, then plan and apply:

```sh
cli/duo plan target --adopt-by-slug=posts,terms,menus --default-author=admin
cli/duo apply target --adopt-by-slug=posts,terms,menus --default-author=admin
cli/duo capture target
diff -ru source-state-copy/ target-state-copy/
cli/duo plan target --adopt-by-slug=posts,terms,menus --default-author=admin
```

The acceptance result is all four facts together: apply's convergence canary
passes; source and target canonical `state/` trees are byte-identical after
target recapture; the source runtime checksums did not change during capture;
and the target runtime checksums did not change during apply. A clean final
plan is the final receipt. The `--adopt-by-slug` allowance is for independently
created WordPress defaults with the same natural key; inspect every collision
before granting it and narrow the listed entity kinds where possible.

## Repository certification transcript

The reproducible two-host transcript lives in
`sandbox/tests/certify_ssh_adoption_roundtrip.sh` and runs as:

```sh
make certify-ssh-adoption-roundtrip
```

It builds two hosts with separate WordPress volumes and databases, exposes
only SSH, adopts both through the product CLI, measures coverage, proves that
a post-export write makes the batch stale without changing policy, captures
the source, transfers only the repo through SSH, applies it to the target,
and byte-diffs the two recaptures. Its named success receipts are:

```text
Warning: adopted env post 1 as 019fdf2e-5e44-7677-89d1-c5917d7234f9 (posts/post/019fdf2e-5e44-7677-89d1-c5917d7234f9--hello-world.md)
Warning: adopted env post 4 as 019fdf2e-5e45-741f-9565-cc3c412f0453 (posts/page/019fdf2e-5e45-741f-9565-cc3c412f0453--adoption-handbook.md)
Warning: adopted env post 2 as 019fdf2e-5e45-753f-8cec-f79df00ac07c (posts/page/019fdf2e-5e45-753f-8cec-f79df00ac07c--sample-page.md)
Warning: adopted env post 3 as 019fdf2e-5e45-77cd-b9f2-5a2b0fc1dc34 (posts/page/019fdf2e-5e45-77cd-b9f2-5a2b0fc1dc34--privacy-policy.md)
Warning: adopted env term 1 as 019fdf2e-5e45-7c2e-a076-e65d389686b4 (terms/category/019fdf2e-5e45-7c2e-a076-e65d389686b4--uncategorized.json)
ok: fresh, complete reviewed batch applies once; stale batch is mutation-free
ok: source canonical state captured and source runtime checksum preserved
ok: cross-environment apply converged authored state and preserved target runtime state
ok: target recapture is byte-identical to source and the final plan is clean
✔ CERTIFY_SSH_ADOPTION_ROUNDTRIP PASSED
```

Those five warnings are expected and reviewed in this fixture: each is the
explicit receipt for `--adopt-by-slug=posts,terms` attaching an independently
created WordPress default or same-slug page to the source UUID. They are not
suppressed as harmless output; an unexpected slug or entity kind is a reason
to stop before granting that adoption flag.

This transcript complements the phase-1 aged-site fixture (10+ active plugins,
three deliberately unmanifested owners). It tests the remaining cross-host
boundary rather than replacing that inventory evidence with a toy claim.
