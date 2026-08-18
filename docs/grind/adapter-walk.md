# Operator-authored adapters — the four-scenario walk

Driver: [`sandbox/tests/grind_adapter_walk.sh`](../../sandbox/tests/grind_adapter_walk.sh).
Fixtures: [`sandbox/tests/fixtures/adapter-walk/`](../../sandbox/tests/fixtures/adapter-walk/)
and the walk-owned plugin [`sandbox/fixtures/acme-catalog/`](../../sandbox/fixtures/acme-catalog/).
Specification: [round-3 T6 §4](../proposals/round-3-adapter-walk.md).

`grind_mup.sh` proves the loop works for a site whose plugins the platform
already knows. This proves the other half of the product: an operator whose
site runs a plugin Duo has never reviewed can **author, certify and override
adapters themselves**, and the same loop then runs around — or through — that
plugin.

Four scenarios, each on a fresh `pair.sh reset` of one dedicated pair:

| # | scenario | the question it answers |
|---|---|---|
| S1 | a published plugin with no adapter, kept deliberately unmanaged | "I run WPForms and I do not want Duo touching it. Can I still use Duo?" |
| S2 | the same plugin, with an adapter the operator authored and certified | "Can I make Duo manage my forms without waiting for a platform adapter?" |
| S3 | an in-house plugin that bundles its own adapter | "My developer shipped an adapter with our plugin. What do I do with it?" |
| S4 | a shipped adapter overridden by a certified site copy | "The platform adapter is nearly right. Can I extend it for my site?" |

**S1's last assertion is why the walk is a gate rather than a demo.** The
unmanaged plugin is outside every release gate — and "excluded" must not read
as "unprotected". A row the plugin owns in its own custom table, and an option
in its own namespace, are written *after* the release checkpoint; recovery then
has to make the printed `maximum_loss_boundary` sentence literally true for
them, exactly as it does for a managed row. If unmanaged state survived a
recovery whose claim said it would not, the claim would be true only about the
state Duo happens to manage, which is not what the sentence says.

---

## How the orchestrator runs it

```sh
make grind-adapter-walk WALK_PORT1=9500 WALK_PORT2=9501
```

`make grind-adapter-walk` is wired by the orchestrator; the driver itself takes
every input from the environment. A candidate-bound run — the form that
produces evidence — adds the source gate `sandbox/bin/pair.sh` enforces
*before* it drops a database:

```sh
DUO_SOURCE_ROOT=$(pwd -P) DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
  make grind-adapter-walk WALK_PORT1=9500 WALK_PORT2=9501
```

One scenario at a time, for a shorter feedback loop while the product is being
built:

```sh
WALK_SCENARIOS=S2 bash sandbox/tests/grind_adapter_walk.sh
```

### Inputs

| Variable | Default | What it is |
|---|---|---|
| `WALK_PAIR` | `awalk` | `pair.sh` pair name. Grammar `[a-z][a-z0-9]*`. A custom name **requires** explicit ports, for the reason `conformance/run.sh` requires them: two sweeps on one port pair collide at bind time, loudly but confusingly late. |
| `WALK_PORT1` / `WALK_PORT2` | `9500` / `9501` | Published host ports for side 1 and side 2. |
| `WALK_SCENARIOS` | `S1,S2,S3,S4` | Comma list, run in the order given. Each entry gets its own `pair.sh reset`. |
| `WALK_KEEP` | unset | `1` leaves the pair, both site repos and the evidence directory in place. |
| `WALK_THEME_SLUG` / `WALK_THEME_VERSION` | `twentytwentyone` / `2.8` | The pinned theme. Unlike `grind_mup.sh`, the default is a theme this estate already pins, so a first run needs no knob. |
| `WALK_WOO_VERSION` | `11.0.0` | The pinned WooCommerce artifact, installed in **every** scenario. |
| `WALK_WPFORMS_VERSION` | `2.0.0.4` | The pinned subject plugin (§4 names WPForms Lite 2.0.0.4, `role: exercise-fixture`). |
| `WALK_KEY_ID` | `acme-ops-2026` | The organization key id certification runs under. It is an operator-chosen label, not a secret, and it is the value the projection's `principal` must read back. |
| `DUO_EXPECTED_SOURCE_SHA` | unset | Forwarded to `pair.sh`'s candidate-source gate. Unset means this run is **not** bound to a commit, and the driver says so. |
| `DUO_WORDPRESS_ORG_OFFLINE` | `0` | Forwarded to `pair.sh` and `fetch-artifact.sh`. |

WooCommerce is installed in every scenario, not only in S4. The site under test
should look like a real shop that also runs the subject plugin; one
`install_side` path serves all four; and S1's claim — "the rest of the site
stays normally managed while this plugin is not" — needs a normally-managed
adapter present to be worth making.

### Offline modes (no docker, no pair, no network)

```sh
bash sandbox/tests/grind_adapter_walk.sh --self-check   # every pure helper vs recorded documents
bash sandbox/tests/grind_adapter_walk.sh --dry-run      # --self-check, then the resolved plan
```

`--self-check` runs each jq/bash helper against the PASS document in
`sandbox/tests/fixtures/adapter-walk/` **and** against a hand-mutated FAIL
document, because a helper that cannot fail proves nothing about the run that
trusts it. It also writes the exact `.duo-envs.json` the run writes and pushes
it through `\Duo\Orchestrator\CommandEnvironmentProvider::fromEnvironment()` —
the real `EnvironmentLifecycle` provider-config schema — so a malformed
provider block refuses on a laptop instead of at materialization time with a
snapshot already taken.

`--dry-run` walks every selected scenario and prints the argv of every external
command, shell-quoted, with its arguments already resolved. It is a plan, not a
replay: there is one copy of each step body, and every external call goes
through `run`/`run_in`/`duo_ok`/`duo_refused`, which print instead of
executing. A dry run creates nothing — no `sandbox/tmp`, no `sandbox/siterepo`
— and its cleanup trap removes nothing.

### Where the evidence lands

Everything the run reads is kept under a `mktemp -d` inside `sandbox/tmp/`,
with one directory per scenario:

```
<scratch>/.duo-envs.json                        the machine-local registry
<scratch>/reference-env-provider.json           duo-reference-env-provider-config/v1
<scratch>/keys/<name>.key                       the organization secret key, mode 0600, OUTSIDE both repos
<scratch>/evidence/<S>/init*.txt                init's proposal / refusal renders
<scratch>/evidence/<S>/capture-refused.txt      the typed capture stop
<scratch>/evidence/<S>/classify-*.txt           the exported and applied classification batch
<scratch>/evidence/<S>/coverage.json            duo-coverage-report/v1
<scratch>/evidence/<S>/assess-*.json|txt        duo-assess-report/v1 and the human view the leak gate reads
<scratch>/evidence/<S>/adapter-list-*.json|txt  duo-adapter-catalog/v2
<scratch>/evidence/<S>/adapter-survey*.json     the TARGET's survey (the only place the plugin source exists)
<scratch>/evidence/<S>/keygen.txt certify.txt   the two host verbs' receipts
<scratch>/evidence/<S>/rehearse.txt             banner + "what a release would touch"
<scratch>/evidence/<S>/authorization-plan.json  duo-authorization-plan/v1
<scratch>/evidence/<S>/release.txt              promote's own `promote phase:` receipts
<scratch>/evidence/<S>/verify.json              duo-verify-report/v1
<scratch>/evidence/<S>/checkpoint-catalog.json  duo-checkpoint-catalog/v1
<scratch>/evidence/<S>/recover.txt              the claim, printed before acting
<scratch>/evidence/<S>/reap-{1,2}.txt
```

The scratch directory is removed by the exit trap unless `WALK_KEEP=1`. The
path is printed on the last line of a successful run.

### What cleanup guarantees

The exit trap destroys **exactly this pair** and removes exactly
`sandbox/siterepo/<pair>{1,2}` and `sandbox/siterepo/origin-<pair>.git`, then
*verifies* the removal: any surviving container, volume, network or
`wp_<pair>{1,2}` schema turns a passing run into a failing one. It never
touches another agent's pair. Under `--dry-run` it exits immediately, having
created nothing to remove.

---

## Words this walk asserts

The contract is binding on both sides — the walk asserts the words, the product
emits them. The full table lives at the top of the driver, in the
`## Words this walk asserts` block, so a change to an assertion and a change to
the table cannot drift apart. Its `source` column separates the two kinds:

- **`§n`** — the contract fixes the literal, and the walk asserts it verbatim.
- **`WALK`** — the contract names the thing but not the spelling, and this walk
  picked one. There are six: `shadowed_by_site` as the `not_installed[]`
  `reason_code` on the shadowed shipped copy (§3.3 fixes the word, not the
  field); `trust_root` / `principal` as top-level keys on each catalog row,
  and `certification_trust_root` / `certification_principal` on each assess
  operation projection beside `certification_provenance` (§3.2 fixes the
  facts, not their placement); `site` as `trust_root`'s value for a site-signed adapter;
  `key-id: <id>` as the first field of `duo adapter keygen`'s output;
  `secret_key_inside_repository` as the reason code for keygen refusing a path
  inside the site repository (§3.1 fixes the refusal, not its code); and
  `draft_output_exists` for `adapter-draft --out` refusing to overwrite (§3.5,
  same).

Two spellings the walk deliberately does **not** assert, because the contract
names the effect rather than the string: the heading the init advisories print
under (§3.4 says "an `advisories` heading"; the walk asserts only the
`UNMANAGED PLUGIN …` row), and the prose of `duo adapter certify`'s printed pin
object (the walk reads the pin out of `site.duo.json`, which §3.1 does fix).

---

## S1 — a published plugin with no adapter, kept unmanaged

| # | Command | Assertion, and why it is the assertion |
|---|---|---|
| 1 | `pair.sh reset` + `up --http --artifacts`; WooCommerce + theme + WPForms Lite on both sides; two forms, two products and a page on side 1 | The pair is healthy and both sides carry the **exact** pinned artifacts. Side 1 is authored (activated, set up); side 2 gets extension **files only**, so the release's own deploy phase is what reconciles activation. |
| 2 | `duo init <env> --yes` | Refuses with `active_plugin_without_adapter`, prints `UNSUPPORTED PLUGIN wpforms-lite/wpforms.php [active_plugin_without_adapter]`, and its remediation names **both** new remedies: `--allow-unmanaged-plugins` and `duo adapter certify` (§3.4). A refusal that names one way out teaches the operator there is one. |
| 3 | `duo init <env> --allow-unmanaged-plugins --yes` | Proceeds; the same finding now prints `UNMANAGED PLUGIN wpforms-lite/wpforms.php [active_plugin_without_adapter]` under advisories, and — because init's own confirmation runs the baseline capture, whose scope gate refuses any plugin-registered type with rows that no rule names — the decision carries through: `UNMANAGED SCOPE post_type:wpforms [unmanaged_scope_left_local]` and `policy.scope.post_type.wpforms.class = runtime` in `site.duo.json`. The generated `policy.post_types` must *not* have taken the plugin's post type into authored scope. |
| 4 | `duo capture <env>` green; `duo pending <env>` holds no scope gap | The reviewed rule makes capture green. The gate is then proven live in the negative: with the rule removed from a scratch copy of `site.duo.json`, capture refuses `incomplete_policy_scope` naming `scope:post_type:wpforms`; the rule is restored and capture is green again. Loud and blocking is the product; a capture that silently skipped the plugin's entities would be the defect. |
| 5 | `duo coverage <env> --format=json` | Every undeclared table row publishes `logical_name` (§3.7 bug 1). Without it, assess's `table:<name>` identities — which are built from `logical_name` — can never appear on a live site, so this is checked by key name rather than by counting rows. |
| 6 | `duo assess <env> --format=json`, then `duo assess <env>` | `plugin:wpforms-lite` exists and projects `unclassified / block / Not qualified / Uncertified / unknown / unknown` with next action `install adapter`; `table:wpforms_*` rows exist; the unknown block names `option-prefix:wpforms` and counts a non-zero invisible-option total; the human view prints `N undeclared table(s)` (§3.6 / §3.7 bug 2) and its next-actions roll-up counts at least two `install adapter` findings; **no** unclassified row answers `nothing — supported`; and the human view leaks no UUID and no 32-or-more-hex identifier. |
| 7 | `duo contract <env> propose` → jq review → `accept` | The plugin surface and its tables are decided `state_class: runtime`, `handling: preserve local`, `decided_by: operator`, with the next action removed — §3.6's ordinary operator decision, which projects `Unsupported` and puts the surface outside every release gate. The accepted contract is then checked to carry **no** remaining `unresolved` surface: a leftover is a surface this walk did not anticipate, and §4 says such a stop is the work list. |
| 8 | `duo rehearse preview --from <env> --branch main` | Containment banner is the **first** line and appears **once**, byte for byte; "what a release would touch" is present; side 2 carries a materialized site repository. |
| 9 | Edit the landing page on the preview; `duo capture preview` twice | Capture is deterministic: two captures of the same converged environment differ by zero bytes. |
| 10 | `git commit` + `git push origin HEAD:main`; then revert the live page body on side 2 and capture once | The revert is `grind_mup.sh`'s step 7b, here for the same reason: side 2 is both the preview and the release target, so the authored edit is already live the moment it is captured, and a release with nothing to apply proves nothing. The one capture that follows the revert is what puts the target's ledger back in agreement with its live rows, which is the capture-first workflow `release_target_not_clean` names. |
| 11 | `duo release <target> --from=<sha> --plan-only --format=json`, then `--yes` | The plan validates as `duo-authorization-plan/v1`, cites the **accepted** `contract_digest`, embeds a literal recovery claim, names the profile **with the reason it was selected**, authorizes at least one entity change, and wrote nothing to `.duo/releases/`. The release then prints `authorization frozen: <path>` and promote's own receipts in the order `promotion-begin → checkpoint → lifecycle-retire → lifecycle-activate → apply`. |
| 12 | `duo verify <target> --format=json` | `verdict: pass`, `convergence.status: pass`, both declared journeys pass, `uncovered_surfaces` present as a list. The pre-recovery projection of every in-scope surface is recorded here. |
| 13 | Write a row into `wp_wpforms_tasks_meta` and set a `wpforms_*` option on the target, **after** the checkpoint | **The gate.** Both writes belong to the plugin the contract excluded. They are ordinary target state inside the database checkpoint's boundary. |
| 14 | `duo recover <target> --list --format=json` → `--restore=<id> --writers-excluded` | (a) The claim is printed *before* the first driven step — asserted by line number, because a claim printed after recovery started was read too late to stop. (b) The `maximum_loss_boundary` printed at recovery is byte-identical to the frozen plan's. (c) The post-checkpoint table row **and** the post-checkpoint option are **gone**. (d) The page body is back at its pre-release value. |
| 15 | `duo assess <target> --format=json` | The projection of every surface the frozen plan named in scope is byte-identical to the pre-release one, and `plugin:wpforms-lite` still projects `runtime / preserve local / Unsupported` — the operator's decision survived the loop it was made for. |
| 16 | `duo rehearse preview --reap`, twice | The first receipt says `destroyed` or `detached`; the second says the same and exits 0. |

## S2 — the operator authors and certifies an adapter

| # | Command | Assertion, and why it is the assertion |
|---|---|---|
| 1 | Fresh pair; WooCommerce + theme + WPForms Lite; one form and a shop on side 1 | As S1. |
| 2 | Seed `site.duo.json` by hand (the **adoption-seed** shape) | §4 orders `coverage`, `adapter-draft`, `manifest-validate`, `keygen` and `certify` *before* the init they then expect to succeed — so a repository has to exist first. The bytes written are exactly `InitPlanner::existing_config()`'s `adoption-seed` shape, which is the product's own named seam for a repository that predates init, not a way around its `existing_configuration` blocker. No capture runs before init: a ledger row would make the environment initialized in fact, which is a different blocker with a different meaning. |
| 3 | `duo coverage <env> --format=json` | Reports an invisible option group for `wpforms_`, which is what `--seed` has to consume. |
| 4 | `duo adapter-draft <repo> --name=wpforms --seed=<coverage.json> --out=adapters/wpforms.json` | The draft exists and carries the seed's option-prefix proposal as an `option_namespaces` entry (§3.5). Running it a second time against the same `--out` refuses (`draft_output_exists`): a draft that silently replaced a hand-finished manifest would destroy the operator's own work. |
| 5 | jq: finish the draft into a minimal honest manifest | A draft is a proposal; a manifest is a claim. Every rule is one the walk can defend from the installed plugin: `post_types.wpforms` is `authored` with `body: "verbatim"` (WPForms stores each form as a `wpforms` post whose `post_content` is the form's own JSON, and verbatim byte-preserves `post_content` for serialized-data bodies); `wpforms_settings` is the one operator-edited option and the rest of the namespace is `runtime`; the four custom tables are declared `runtime`, the grammar's own word for *declared, deliberately not captured* — the same declaration `manifests/woocommerce.json` makes for Action Scheduler's tables. |
| 6 | `duo manifest-validate <repo>/adapters --site=<repo>` | Reports `[ok] wpforms`. `--site` matters: two of the cross-manifest guards take the site half of policy as input, so validating without it can refuse a manifest the real site accepts. |
| 7 | `duo init <env> --yes` | Refuses with `adapter_source_uncertified`, and the blocker's remediation names `duo adapter certify` (§3.4). An installed adapter is not a certified one, and this is where that stops being a sentence. |
| 8 | `duo adapter list --repo=<repo> --format=json` | The freshly installed adapter's row reads `site` / `uncertified`. |
| 9 | `duo adapter keygen --out=<repo>/wpforms.key` | Refuses (`secret_key_inside_repository`). Asserted **before** the real key is generated, because a key accidentally committed is a key in a git history forever (§3.1). |
| 10 | `duo adapter keygen --out=<scratch>/wpforms.key --key-id=<id>` | Prints `key-id: <id>`, writes the secret mode `0600`, outside both site repos. |
| 11 | `duo adapter certify <repo> --name=wpforms --secret-key-file=… --reason=… --pin` | Registers the public key in `adapters/authorities.json` as a `duo-adapter-authorities/v1` record with scope `site_adapter_certification`, status `trusted`, and `wpforms` in its `adapters[]`; writes `adapters/certifications/wpforms.json`; and `--pin` writes the exact `{name, source: "site", digest}` object into `site.duo.json`. The secret key's own bytes are then checked not to appear in `site.duo.json` or anywhere under `adapters/`. |
| 12 | `duo adapter list --repo=<repo>` | The row reads `site / site_signed / site / <key-id>` (§3.2) and the human view prints the word `site_signed`. |
| 13 | `duo init <env> --yes` | Succeeds, with **no** `--allow-unmanaged-plugins`: the plugin has an owning adapter now, and needing the flag here would mean certification bought nothing. This is also §3.4's own remediation carried out — "certify it …, then rerun `duo init`" necessarily means init running on a repository whose non-seed content is exactly an adapter, its certificate and its pin. The adapter's authored post type is then in `policy.post_types`. |
| 14 | `duo capture <env>`, `duo assess <env>` | `post_type:wpforms` projects `authored / manage / Ready / Site-certified / prevented / provider-state restorable`; the projection's certification triple reads `Site-certified / site / <key-id>`; and the human view prints, exactly once, `certified by <key-id> (site trust root); contract attestation unsigned` — the first half names the authority, the second refuses to let a site signature read as more than it is. |
| 15 | contract → rehearse → author a NEW form on the preview → capture ×2 → merge → revert → release → verify | The whole loop, on a surface no platform adapter governs. After the release, `wp post list --post_type=wpforms` on the target carries the authored form: the release wrote the operator's own managed state, not just bytes. |
| 16 | recover → assess → reap ×2 | As S1, steps 16–18. |

## S3 — a plugin that bundles its own adapter

| # | Command | Assertion, and why it is the assertion |
|---|---|---|
| 1 | Fresh pair; WooCommerce + theme; `sandbox/fixtures/acme-catalog/` copied into the **live** plugin directory on both sides and activated on side 1 | The fixture is installed live rather than only into the repository's `code/` tree, for two reasons: `duo init` builds the code baseline *from* the live wp-content, so a plugin that is live before init is a plugin the code half carries afterwards; and the bundled adapter source exists only in `WP_PLUGIN_DIR`, which is the whole point of the scenario. |
| 2 | Seed `site.duo.json`; one `acme_item` and one `acme_kind` term on side 1 | As S2 step 2. |
| 3 | `wp duo adapter-survey --repo=/siterepo --format=json` **on the target** | The bundled adapter is discovered from the active plugin and reads `source: plugin`, `certification: uncertified`. It has to be read on the target: the host-side `duo adapter` commands are WordPress-free and cannot reach `WP_PLUGIN_DIR`, a boundary the catalog command prints on every run. |
| 4 | `duo init <env> --yes` | Refuses `adapter_source_uncertified`, and the blocker carries the **promotion path** as its remediation: `install this adapter as a repository package at adapters/acme-catalog.json, …`. A bundled adapter cannot be certified in place — certification binds `source: "site"` and the exact `adapters/<name>.json` path inside the signed statement — so "get it signed" would be advice that wastes an afternoon proving it. |
| 5 | `cp duo-adapter.json adapters/acme-catalog.json`; `duo manifest-validate --site` | The promotion, which is a file copy and nothing more. |
| 6 | `duo adapter keygen` + `duo adapter certify --pin` | As S2 steps 9–11. |
| 7 | `duo adapter list --repo` and a second `wp duo adapter-survey` | The site copy answers to the name (`site / site_signed / site / <key-id>`), and the bundled copy is reported as installed-but-not-loaded with the **site** copy as its winner. The plugin stayed active throughout and nothing had to be deactivated. |
| 8 | `duo init <env> --yes` | Succeeds; `acme_item` reaches `policy.post_types` **and** `acme_kind` reaches `policy.taxonomies` from the promoted adapter's authored declarations. |
| 9 | `duo capture <env>`, `duo assess <env>` | `post_type:acme_item` reads `authored / manage / Ready / Site-certified / …`, and `table:acme_catalog_index` is **still** a named finding. The fixture's own bundled manifest deliberately leaves that table undeclared; certification must not silently absorb a surface nobody declared. |
| 10 | contract (three journeys, including the `acme_item` archive) → rehearse → author a second item → capture ×2 → merge → revert → release → verify | A release that writes a catalog item and cannot prove the archive renders it has verified bytes rather than behaviour, which is why S3 declares the third journey. |
| 11 | recover → assess → reap ×2 | As S1, steps 16–18. |

## S4 — overriding a shipped adapter

| # | Command | Assertion, and why it is the assertion |
|---|---|---|
| 1 | Fresh pair; WooCommerce + theme; `duo init <env> --yes` | The ordinary path, and the baseline the override is measured against: `post_type:product` reads **`Platform-certified`** before anything else happens. |
| 2 | `cp manifests/woocommerce.json adapters/woocommerce.json` + one authored option | The added option is `woocommerce_store_address_2`, and the walk first asserts the shipped manifest does **not** already declare it — a copy that differed in nothing would make "the site copy won" unobservable. |
| 3 | `duo adapter pin <repo> --name=woocommerce --source=site` | Writes the explicit `{name, source: "site", digest}` pin. §3.3: precedence stays `shipped > site > plugin` for name-only pins, and the explicit site pin is the override. Today this is a whole-source refusal. |
| 4 | `duo adapter list --repo=<repo>` | The shipped copy is reported as `shadowed_by_site` with the **site** copy as its winner, in both the JSON and the human view; the loaded `woocommerce` row's source is `site`. All three facts are asserted together because any two without the third describe a different situation — `shadowed_by_site` with a *shipped* winner is precedence running the ordinary way. |
| 5 | `duo assess <env>` with the override pinned but **not yet certified** | `post_type:product` reads `Not qualified / Uncertified`, its next action is **`certify adapter`** (§3.6's new closed-set word), and the roll-up counts it. S4 is the only scenario that can make this claim without a conditional: its repository was initialized *before* the override, so the WooCommerce surfaces are already in policy scope while the adapter governing them is uncertified. The word matters — the adapter exists, and `install adapter` here would tell the operator to redo what they just did. |
| 6 | `duo adapter keygen` + `duo adapter certify --pin` | As S2 steps 9–11. |
| 7 | `duo capture <env>`, `duo assess <env>` | `post_type:product` now reads `Site-certified / site / <key-id>`. A signed override is `Site-certified`, **never** `Platform-certified`: the customer organization's approval is explicitly not a Duo endorsement, and this row is where that distinction is either kept or lost. There is no second `duo init` — the override is a change to the pin set, which `duo capture` reads on its next run. |
| 8 | contract → rehearse → edit → capture ×2 → merge → revert → release → verify → recover → assess → reap ×2 | The ordinary loop still converges with a site-owned copy of a shipped adapter governing the catalog. |

---

## How to read a run

A passing run ends with:

```
✔ GRIND_ADAPTER_WALK PASSED (S1,S2,S3,S4)
evidence: /…/sandbox/tmp/grind-adapter-walk.XXXXXX/evidence
```

Anything else is a failure, and there are exactly two shapes:

- **`FAIL: <S>: …`** — one named assertion, on stderr, with the file to read.
  The driver stops at the first one; the exit trap still destroys the pair and
  still verifies the destruction, so a failed run leaves no resources behind
  (use `WALK_KEEP=1` when you want the corpse).
- **`FAIL: duo <verb> refused unexpectedly [<reason_code>]`** — a product stop
  the walk did not expect, reported **by its typed reason code**. This is §4's
  own rule mechanised: the walk is the exercise, and its stops are the work
  list. Every command goes through `duo_ok` or `duo_refused`, so a surprise is
  always a named bug report rather than "a command failed". `duo_refused`
  additionally fails when a gate it expected to fire *succeeded* — an assertion
  that a gate fires is worthless if the gate quietly stopped firing.

`--self-check` fails with `GRIND_ADAPTER_WALK SELF-CHECK FAILED (n)` and one
`FAIL:` line per helper. That means the driver's own readers drifted from the
document formats; regenerate the fixtures with

```sh
php sandbox/tests/fixtures/adapter-walk/make-fixtures.php
```

and read the diff.

---

## The fixtures, and where "generated" stops

Every fixture a shipped builder can produce is produced by it and validated by
the shipped validator before it is written — `AssessReport::build()`,
`AssessRenderer::render()`, `Init::render()`, `ClassificationBatch::template()`,
`AuthorizationPlan::build()`, `RecoveryClaim::build()`, `JourneyOracle::report()`,
`CheckpointCatalog::fromStatus()`. That is `sandbox/tests/fixtures/mup/`'s rule
and its reason: a hand-written fixture records what its author *believed* a
document looks like, so a jq path that silently matches nothing keeps passing
after the real shape moves.

This walk asserts a contract being built in parallel, so some of its documents
are shapes no shipped builder can mint yet: the `site_signed` catalog word,
`shadowed_by_site`, `Site-certified` with a named principal, the `certify
adapter` gap action, `logical_name` on an undeclared-table row, and the
`UNMANAGED PLUGIN` init advisory. Those are constructed from the contract text
with a `_provenance` note naming the section, and where a shipped validator can
be asked at all, `make-fixtures.php` **asks it and prints the refusal**:

```
contract-new shapes the shipped validators do not accept yet (expected while T6 is being built):
  - the `certify adapter` gap action (§3.6) is not yet accepted by the shipped validator: …
```

That note is the honest status line, and it is self-clearing: while it appears,
the product has not landed that half of the contract; when it stops appearing,
the fixture became a validated one and nothing else has to change.

Two FAIL fixtures are **today's output, byte for byte** —
`coverage.fail-no-logical-name.json` and
`assess-human.no-undeclared-table-line.txt`. They are recorded as failures
because §3.7 bugs 1 and 2 say they are.

---

## Deliberate deviations from §4

1. **S2 and S3 seed the site repository instead of running `duo init` twice.**
   §4 orders `coverage` / `adapter-draft` / `manifest-validate` / `certify`
   before the init they then expect to succeed, and all four resolve a
   directory holding `site.duo.json`. The seed is exactly
   `InitPlanner::existing_config()`'s `adoption-seed` shape — the product's own
   named case for a repository that predates init — and it is
   `MUP_BOOTSTRAP=manual` in `grind_mup.sh` for the same reason.

2. **S4 runs `duo init` once, at the start.** The override is a change to the
   pin set, which `duo capture` reads on its next run; re-initializing an
   already-initialized repository is a different operation with its own blocker
   (`existing_configuration`) and asserting it here would be asserting
   something the override does not need. Running init *before* the override
   also buys the `Platform-certified` baseline S4's whole claim rests on.

3. **The undeclared tables are decided `runtime / preserve local` in the
   contract, not left unresolved.** §3.6 gives the decision for the
   `plugin:<slug>` surface and is silent about the tables that plugin created.
   They are the same plugin's runtime state and the site is making the same
   choice about them, so the walk decides them the same way — and then asserts
   that **no** `unresolved` surface remains, so a surface the walk did not
   anticipate fails the run instead of passing quietly.

4. **The journeys are declared against surface ids, not labels.**
   `affected_surfaces` carries `post_type:page` / `post_type:product` /
   `post_type:acme_item` so `uncovered_surfaces` is computed against the same
   identifiers the projection and the plan's `scope.surfaces` use.

5. **The theme default is `twentytwentyone 2.8`, not Storefront.**
   `sandbox/bin/fetch-artifact.sh` refuses an unpinned artifact rather than
   falling through to the wordpress.org catalog, and Storefront is not pinned
   in this tree — so defaulting to it would make every first run fail at
   preflight for a reason that has nothing to do with adapters.

---

## Risks a machine without docker cannot retire

Everything below is reachable only on a live pair. Each is stated so the first
live run knows what to look at rather than rediscovering it.

1. **Almost every product word this walk asserts does not exist yet.**
   `--allow-unmanaged-plugins`, `duo adapter keygen|certify|pin`,
   `adapter-draft --seed|--out`, the `plugin:<slug>` surface, `Site-certified`,
   `certify adapter`, `site_signed`, `shadowed_by_site` and `logical_name` on a
   coverage row are all being built in parallel. Until they land, the walk's
   first live run is a work list, which is what §4 says it is for.

2. **`duo init` on a repository that carries an adapter and a pin.** §3.4's own
   remediation ("certify it …, then rerun `duo init`") requires it, and S2 and
   S3 are where it is exercised. If `existing_configuration` fires there, that
   is the finding, and the failure names the code.

3. **`duo coverage` before init.** S2 and S3 run it against the adoption seed.
   If coverage's `Ledger::ensure()` creates ledger *tables* and init reads
   their existence (rather than rows) as "already initialized", the subsequent
   init blocks. The blocker's own text says "rows", so this should read as a
   pass; it is unverified.

4. **The bundled adapter's discovery depends on activation.** Only ACTIVE
   plugins are scanned for `duo-adapter.json`. `install_acme` activates the
   fixture on side 1 before the survey; on side 2 the plugin arrives as files
   and is activated by the release's own lifecycle phase, which is also when
   its activation hook creates `acme_catalog_index`. If the lifecycle phase
   does not run the activation hook, that table never exists on the target and
   S3's undeclared-table row is a side-1-only fact.

5. **WPForms Lite's tables appear on activation.** Side 2 gets the plugin as
   files only, so its `wpforms_*` tables arrive when the release activates it.
   S1's post-checkpoint `INSERT` runs after the release, so the table exists by
   then — but if a future WPForms defers table creation to first use, the
   `INSERT` fails and S1 stops at that step rather than at the gate.

6. **`/?post_type=product` depends on the theme's archive template.** The
   catalog journey expects HTTP 200 containing a product name. A query-string
   URL is used rather than `/shop/` precisely to avoid a permalink dependency,
   but the theme still has to render titles on a post-type archive.

7. **File ownership across the bind mount.** Capture publishes as uid 33 inside
   the container while the host runs `git add`. The cleanup trap chmods both
   site repos before removing them, but whether host-side `git commit` succeeds
   after a container-side capture is platform-dependent and unverified here.

8. **The `preview` and `<pair>2` registry entries name the same physical side.**
   They must stay pointed at the same service and repo path, or the walk
   captures one environment and releases another. Nothing in the registry
   enforces that; only this document does.

9. **Wall time and pair budget.** Unmeasured, and four times a `grind_mup` run
   by construction: four resets, four `up`s, three artifact installs per side
   per scenario, four materializations, four promotions, four recoveries and
   eight reaps. `WALK_SCENARIOS` exists so a single scenario can be iterated
   without paying for the other three.
