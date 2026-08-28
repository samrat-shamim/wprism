# Adopting an existing WordPress host

`duo adopt <env>` installs Duo onto an already-running WordPress site reached
through the orchestrator's SSH transport or an explicitly authorized
machine-local transport. It is the bootstrap step before the
first `duo pending`, `duo classify`, or `duo capture`; it does not claim that
the site's pre-existing plugin state is already classified. Machine-local
delivery is initial-only and refuses an already-installed Duo control plane;
SSH retains the established install-or-update workflow.

For a first source-checkout install, prefer the guided wrapper: `duo connect`
performs native read-only probes and creates the local seed/registry, then
`duo onboard` composes this adoption transaction, the read-only assessment,
and the reviewed init confirmation. The lower-level `adopt` command documented
here remains the update path and the place to inspect its exact transaction.

## Prerequisites

The machine running `duo` needs PHP 8+ with Sodium and `tar`; SSH adoption
also needs `ssh` and `scp`.
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

Local delivery is a privileged opt-in and must live in untracked
`.duo-envs.json`:

```json
{
  "envs": {
    "local-existing": {
      "transport": "local",
      "wp_path": "/srv/wordpress",
      "repo_path": "/srv/site-repo",
      "bootstrap": {"format": "duo-local-control-plane/v1"}
    }
  }
}
```

That exact closed object authorizes only the mechanism. Checked-in repository
configuration cannot self-label it machine-local. `duo driver-capabilities`
remains target-free; `duo adopt` separately emits a read-only eligibility
report and refuses before archive allocation or target writes unless every
target check passes. Docker has no adoption capability at this version.

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
target-owned exclusion provider, checkpoint provider, and four raw recovery
adapters. Values are absolute argv arrays, not shell strings, and contain no
token or credential. The checkpoint provider is optional only while installing
the executor substrate without database rollback capability.
Automatic promotion additionally requires all code-release, upload, and effect
providers plus the controller-only `verified_rollback` object naming the claim
TTL, external encryption-key id, and retention window. Duo selects the profile
from those declared capabilities and runtime preflight, never from the host's
filesystem layout. Code-release preflight includes an explicit
`plan_bound_code_inventory` attestation; the automatic profile is unavailable
to a legacy provider that cannot consume the signed compiled inventory.
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

## Install, or update over SSH

Run from a Duo source checkout whose `cli/`, `agent/`, `adapter-packages/`,
`platform/`, and `recovery/` directories belong to the release you intend to
install:

```sh
cli/duo doctor production   # expected to report the agent/repo missing first
cli/duo adopt production
```

Adoption performs these operations:

1. negotiates explicit transport authority; for local targets, proves a
   normalized, disjoint, ordinary, writable filesystem topology and a wholly
   absent prior Duo agent/loader/adapter-library/rollback authority without
   writes;
2. verifies reachability and installed WordPress; local adoption discovers the
   standard `WPMU_PLUGIN_DIR` through a plugin-free control bootstrap, while
   SSH preserves its existing target-discovered path contract;
3. assembles `adapter-packages/*/package/` and `platform/adapter-library/`
   into staging's `agent/adapter-library/`, then sends one archive containing
   exactly the assembled `agent/` and public recovery-runtime trees;
4. creates every transaction path exclusively, records its filesystem
   identity, stages and rollback-protects the live paths, then installs:
   - `WPMU_PLUGIN_DIR/duo/` (the agent),
   - `WPMU_PLUGIN_DIR/duo-loader.php` (the required top-level loader),
   - `WPMU_PLUGIN_DIR/duo/adapter-library/` (the embedded, atomically published
     adapter library),
   - `repo_path/.duo/control/recovery-runtime/` (outside managed code);
5. for SSH updates, stages the entire existing `repo_path/.duo/` tree
   (including artifacts and checkpoints); local bootstrap requires that tree
   absent. It creates or verifies the protected `control/` root and stable
   stable target identity, and installs the configured public verification
   key without copying controller secrets; when `rollback_recovery` is set it
   installs the path-only configuration and probes the exclusion, checkpoint,
   code-release, upload, and effect providers plus all four isolated adapters;
6. creates `repo_path/site.duo.json` only when it is absent, initially pinning
   `core` with post/page/attachment and category/post_tag scope;
7. starts fresh wp-cli processes to prove the remote `DUO_AGENT_VERSION`
   exactly matches this checkout and that `Policy::load()` can read the seed
   plus installed embedded adapter library;
8. verifies the recovery runtime can read and validate the external target;
9. runs the normal public doctor checks while the swap is still rollbackable
   (the local path uses the isolated control plane);
10. publishes a mutation-free commit marker only after every blocking
    verification passes, leaves the rollbackable phase, and only then discards
    prior SSH rollback copies. Cleanup trouble retains transaction evidence and
    never triggers restoration from a partially deleted backup.

An existing `site.duo.json` is never overwritten. Over SSH, re-running the
command is the update mechanism: the current agent and its embedded adapter
library are replaced atomically from the invoking checkout's assembled
projection, while the site's policy
remains untouched. Local bootstrap refuses a repeat and points to the existing
installed-environment update path. Symlink destinations are refused rather
than followed, and an install failure restores the previous agent (including
its library), loader, complete `.duo` tree, and repository bytes, and removes a
seed/directory leaf created by that failed run. Cleanup first rechecks each
transaction path's recorded
filesystem identity; an unexpected replacement is retained for operator
recovery rather than recursively deleted. A target-side adoption lock refuses
overlapping operators. If the host process is killed so abruptly that
`.duo-adopt-lock` remains, adoption fails loudly and requires operator
inspection rather than guessing whether the interrupted release should be
committed or restored.

The control root holds `target.json`, `target.lock`, immutable public keys,
and the installed runtime. Receipts and signed event chains live beside it at
`repo_path/.duo/rollback/<receipt-id>/`; SSH re-adoption stages the whole `.duo`
tree, updates only its runtime/declared authority data, and preserves unrelated
opaque subtrees plus the stable identity and all generations. This is the authority
substrate plus the fatal-safe executor. A configured checkpoint provider adds
the encrypted database before-image slice described in
[checkpoint-bundle.md](checkpoint-bundle.md), but it still does not make
promotion automatically recoverable: code/storage, reversibility, and the
integrated crash matrix must also close.

## Why the adapter library is embedded in the agent

Production policy resolution is bound to the installed
`WPMU_PLUGIN_DIR/duo/adapter-library/`. Adoption constructs that allowlisted
projection from the checkout's package and platform sources before upload, and
publishes it atomically with the engine. No environment variable, neighboring
flat directory, or root-level fallback can select another runtime library.
This keeps fresh SSH processes on the same engine/library generation and lets
adoption verify the exact installed projection before reporting success.

## Version visibility and remaining boundary

Every adoption prints and verifies the exact `DUO_AGENT_VERSION` it installed;
rerun the same release's `duo adopt` command to repair or update a stale copy.
`duo doctor` independently confirms that an agent is present, but it does not
know which source checkout or release an operator intended and therefore
cannot compare against an expected version when run alone. Packaging a signed
release descriptor that gives standalone doctor such an expectation remains
a named future distribution concern; presence is not presented as a
standalone freshness guarantee.

## Upgrading a managed site across the certification-evidence teardown

Two releases changed what an installed adapter library contains and what the
platform boundary says: #477 removed the certification-evidence apparatus (the
generated `manifests/capabilities/registry.json`, its evidence record, and the
per-subject certification bundles adoption used to ship), and #478 removed four
demo manifests. `DUO_AGENT_VERSION` is unchanged, so the upgrade itself is the
ordinary SSH re-adoption above. The work either side of it, in order:

**1. Drain in-flight work before you re-adopt.** A promotion caught between
phases, or a rollback generation that has not reached a terminal state, rides on
receipts the upgrade does not migrate. Check both, per environment:

```sh
cli/duo status production
cli/duo recover production --list
ls .duo/releases
```

`duo status` ends with the externally verified rollback-authority line. A
drained SSH target reads `[PASS] rollback authority: ready (no active
generation)`, or `[PASS] rollback authority: generation <n> <state>
receipt=<id>` when the last generation is terminal. A non-terminal one reads
`[FAIL] … (recovery required)`, and the same check fences `duo deploy` and
`duo promote` with `rollback generation <n> is still <state>; recovery must
reach committed or rolled_back first`. Carry it to `committed` or
`rolled_back` — `cli/duo recover production --restore=<checkpoint>
--writers-excluded` — before upgrading under it. On a target with no configured
rollback authority `duo recover` refuses with `recovery_authority_unavailable`;
that refusal is the answer, not an obstacle, because such a target holds no
rollback authority to drain.

Frozen authorization plans are local, not target-side: `duo release` writes
`.duo/releases/<plan_digest>.json` in the site repository before any target
mutation, and each plan cites the `contract_digest` it was authorized against.
Step 3 replaces that digest, so afterwards `cli/duo verify production
--plan=<digest>` refuses with `verify_contract_moved` — "the accepted
application contract has changed since this release was authorized, so its
declared journeys are not the journeys that release was verified against".
Verify or abandon every outstanding plan first; re-issuing the contract does not
make a stale plan valid again.

**2. Upgrade paired environments together.** `duo refresh` and `duo rebase`
compare the live production target against the artifact compiled locally from
`--production-ref`, and `RefreshPlan::assertProductionCodeMatches()`
(`cli/src/Refresh/RefreshPlan.php:284`) requires four things to be equal: the
policy's `site_hash`, its `manifest_hash`, the canonical encoding of its
`resolved_adapters`, and the completed code's revision and descriptor.

`resolved_adapters` is the one this teardown moves. Every row carries that
adapter's reviewed capability claim, and the claim embeds the platform boundary
verbatim (`ManifestDispositions::claim_from_disposition()` returns `platform`
and derives `environment_assumptions` from it) — and the boundary's bytes
changed deliberately, because the old text promised a WordPress-version gate
that no longer exists. A controller upgraded past the teardown, talking to a
target that is not, therefore gets

```text
production adapter contract does not match --production-ref
```

which is a refusal, not a mystery. There is no flag for it: re-adopt every
environment in the pair from the same checkout, in the same window. Its sibling
refusals from the same function are `production site_hash does not match
--production-ref`, `production manifest_hash does not match --production-ref`,
and `completed production code does not match --production-ref`.

**3. Re-issue every accepted application contract.** The format is now
`duo-application-contract/v2` (`cli/src/Contract/ApplicationContract.php:56`).
A `duo-application-contract/v1` document is refused, not migrated, and the
refusal names why rather than making an operator diff two schemas:

```text
[contract_format_invalid] this is a duo-application-contract/v1 contract: its
evidence_pins bind a generated capability registry and per-subject certification
bundles that no longer exist, so it must be re-proposed and re-accepted as
duo-application-contract/v2
remedy: regenerate the contract with duo contract propose, then review and accept it
```

v2 narrows `evidence_pins` to the two facts that still exist: the content
address of the reviewed dispositions the verdict was read from, and this
checkout's own copy of that document.

```sh
cli/duo contract production propose   # writes .duo/contract/production/proposed.json
# review and edit the proposal, then:
cli/duo contract production accept    # writes contract.json + projection.json
cli/duo contract production show
```

Run these **after** step 2, not before it. Between pulling this release and
re-adopting an environment, your checkout ships reviewed dispositions the site
has never seen, and both commands refuse with `dispositions_mismatch` naming
the two hashes — a contract would otherwise record this checkout's provenance
beside declarations the older library produced. `duo assess` keeps working
throughout that window and prints the mismatch in its evidence block; it just
writes no proposal.

The edit between those two commands is enforced, not advisory: the generated
`external_effects[]` entry arrives `decided_by: "unresolved"` and
`ApplicationContract::validate()` refuses to accept it that way
(`external_effect_unreviewed`). `accept` stages both documents and never
commits — the commit is the human's signature on the review.

**4. Re-sign any site-adapter certificates you hold.** A site certificate binds
the compatibility CELLS it was exercised against out of the platform boundary,
now `platform/adapter-library/capabilities/platform.json` and previously the `platform` block
inside the deleted `registry.json` (before spec/repo-format.md § v3.6 it bound
that whole object byte for byte, so any edit refused). Certificates cut against
the old file bind cells this boundary states differently, so verification
refuses:

```text
duo: site adapter '<name>' certification binds compatibility axis '<axis>',
whose exercised cells the agent-owned platform boundary now states differently
```

Re-sign each adapter under `adapters/certifications/` with the same key and
rewrite its pin in the same step:

```sh
cli/duo adapter certify <site-repo> --name=<adapter> \
  --secret-key-file=<your-key-file> --pin
```

`duo adapter list` is the check — an adapter that read `site_signed` before the
upgrade and does not now needs re-signing, and `duo adapter doctor` reports that
state instead of dying on it. This is only about adapters *you* signed: the
shipped `platform/adapter-library/capabilities/adapter-authorities.json` carries
`"keys": {}`,
so no platform-signed certificate exists to re-issue.

**5. Nothing else needs recompiling or re-pinning.** For the manifests that
survived, `manifest_hash`, each `adapter_digest`, `site_hash` and
`revision_hash` are byte-identical across the teardown — #477 verified them
against a resurrected copy of the deleted implementation. Do not recompile
artifacts, re-run `duo capture`, or rewrite `site.duo.json` pins as upgrade
hygiene; only `artifact_hash` moves, and it moves on the next natural compile.

The rule that *does* require a recompile is unchanged by the teardown and worth
restating, because the advice it replaces used to end in "regenerate the
registry": editing an adapter's `package/manifest.json`, or the bytes of a
`package/runtime/` provider, interpreter, or regenerator it names, moves that
adapter's identity.
`ArtifactPolicyIdentity::manifest_rows()` folds the manifest array, its
disposition, and `hash_file('sha256', …)` of each named hook file into one row;
`manifest_hash()` is that row set hashed and `resolved_adapters()` is each row
hashed individually into its `adapter_digest`. A deployed site holding a
compiled artifact then refuses it with `compiled_artifact_manifest_mismatch` —
"compiled manifest/interpreter set does not match active pins" — and the site
repository's per-manifest content pin stops matching too. The remedy is to
recompile the artifact and update the reviewed pin; `wp duo manifest-pin` emits
the copy-pasteable object. Renaming a PHP namespace or moving an `agent/src`
class file does not move identity: only manifest bytes, disposition bytes, and
those named hook files are folded.

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
classifying an unrelated option.

Its four option buckets sum to `total`. A **declared** name is never invisible,
whatever its class: an option an adapter declares `env`, `runtime` or `derived`
is *declared and excluded*, counted under `declared-excluded` (broken out per
class in `--format=json`), and carries no action — the adapter models it and
says Duo must not version it. `invisible` means **no rule from any source** — no
exact declaration, no namespace claim, no pattern, no dynamic prefix — so it is
the count that genuinely needs an adapter.

The exported JSON contains the pending command's redacted evidence but never
live values. Review every decision:

```json
{
  "section": "options",
  "key": "legacy_banner",
  "class": "authored",
  "ref": null,
  "cast": null,
  "allow_secret": false,
  "autoload": "preserve",
  "required": null
}
```

Use `runtime` for environment-local operational state, `derived` for state the
declared regeneration path rebuilds, `env` for separately provisioned values,
and `authored` only for portable intent. `managed` is reserved for lifecycle-
managed options. Attach `ref`/`cast` only when the value's schema justifies it.
An authored secret requires the row's explicit `allow_secret: true`; that is a
reviewed escape hatch, not a recommendation to store secrets in Git.

An **options** row carries two more fields, because the site grammar refuses
the rule without them and the exporter never guesses either one:

- `autoload` — required when the class is `authored` or `managed`.
  `preserve` replays whatever flag the source row already has; a literal
  (`yes|no|auto|on|off|auto-on|auto-off`) pins it, and capture then refuses a
  source row that disagrees. Leave the other field `null`.
- `required` — required when the class is `env`. `true` means an operator must
  provision this value on a fresh environment (a genuine secret or
  site-identity value); `false` means plugin-internal bookkeeping that
  self-populates and is not worth checklisting.

Neither field belongs on a `post_meta`, `term_meta`, `user_meta` or `scope`
row, and a field the chosen class does not read is refused rather than
recorded as a decision nothing acts on.

Apply the complete batch and immediately check for a next queue:

```sh
cli/duo classify production --apply-batch=production-review.json
cli/duo pending production
```

The artifact is bound to the environment and the exact pending evidence by
SHA-256. If a write arrives after export, apply refuses before changing
`site.duo.json`; export and review a new batch. Partial batches — a missing
class, or a missing `autoload`/`required` on the options row that needs it —
and surfaces that actually require a manifest or table schema refuse for the
same reason, on the host, before any remote write opens. A batch still
carrying the older `duo-classification-batch/v1` format refuses by name: that
shape has no field for the storage decision, so the remedy is a fresh export,
never an edit.
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
`sandbox/tests/certify/certify_ssh_adoption_roundtrip.sh` and runs as:

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
