# Authoring an adapter manifest

A manifest is how Duo learns what one plugin's state *means*: which keys are
portable authored intent, which are environment-local noise, which hold entity
references that must be rewritten across environments, and which tables it may
touch at all. The engine holds no plugin names and no plugin logic — every
plugin-specific fact lives in a manifest.

This guide is the authoring loop. The normative format is
[spec/repo-format.md § Manifests (registry format)](../../spec/repo-format.md#manifests-registry-format),
and everything a manifest may declare is enumerated there. Read it alongside
this page rather than instead of it.

## What a manifest is, and what it is not

A manifest is a JSON file in the platform repository's `manifests/` directory,
pinned by name from a site's
[`site.duo.json`](../../spec/repo-format.md#siteduojson). It declares
classification rules, reference shapes, deletion capability, derived-state
repair actions, and a compatibility window.

It is **not** a certification. A manifest cannot certify itself merely by
existing beside the agent — that separation is deliberate, and the agent says
so in the exact words it refuses with: `no reviewed disposition entry — a
manifest cannot certify itself merely by existing beside the agent`. See
[Dispositions](#dispositions-the-reviewed-claim-source) below.

## Directory conventions

```
manifests/
  <name>.json                        # the manifest; basename is the pin name
  dispositions.json                  # the reviewed support boundary; NOT a manifest
  interpreters/<name>.php            # \Duo\Interpreters\<Name>
  regenerators/<name>.php            # \Duo\Regenerators\<Name>
  providers/<id>.php                 # \Duo\Providers\<Id>
  capabilities/platform.json         # duo-platform-boundary/v1: the ONE platform
                                     #   /environment boundary, hand-reviewed
  capabilities/adapter-authorities.json  # duo-adapter-authorities/v1: the
                                     #   agent-owned trust root; ships {"keys":{}}
```

Both files under `capabilities/` are hand-authored and reviewed, not generated.
`platform.json` is the object a site-adapter certificate signs as
`platform_sha256`, so it has exactly one on-disk representation; the agent
refuses at load time if its `agent_version`/`spec_version` disagree with the
running `DUO_AGENT_VERSION`/`DUO_SPEC_VERSION`.

Four rules that will bite you if you learn them the hard way:

- **The file basename is the pin name, and it is enforced.** `Policy::load()`
  resolves a pin to `<manifests_dir>/<name>.json`, while the disposition loader
  keys coverage by file basename and `AdapterRegistry` keys the loaded library
  by the manifest's own `"name"` field. A disagreement would be one adapter
  under two identities, so it is refused the moment the manifest is read — by
  name, with both values:

  ```
  duo: shipped adapter '<path>' declares name 'y' but its file name is 'x' — a pin
  names the file while every downstream identity (dispositions, digests,
  diagnostics) keys off the declared name, so the two disagreeing is ambiguous
  identity. Make the declared name match the file name
  ```

  The same sentence refuses a site-installed adapter (see below), and
  `duo manifest-validate <dir>` reports it per manifest offline, before any
  target is contacted.
- **`dispositions.json` is excluded from manifest globbing.** It is reviewed
  data *about* manifests, not a manifest.
- **A regression fixture is marked by its disposition, not by its name.** Give
  it `"status": "excluded"` and the generated document prints it as shipping
  "for regression use only" and carrying no product claim; the projected claim
  reports `authored_state.status: unsupported` and
  `plugin_execution.status: not-a-product-claim`. `duo-agency-cpt` is the one
  shipped example. A name prefix decides nothing — that rule is gone with the
  generator that read it.
- **Interpreter, regenerator, and provider code ships with the manifest, not
  the engine.** A declared interpreter name resolves to
  `manifests/interpreters/<name>.php` and must define
  `\Duo\Interpreters\<CamelCase(name)>` with
  `post_meta_rule(string $key, array $allMeta): ?array`; it may additionally
  define `term_meta_rule()` and `user_meta_rule()` with the same signature and
  nullable-defer semantics. A regenerator, declared under a post type's
  `regen_dependency`, resolves to `manifests/regenerators/<name>.php` and must
  define `\Duo\Regenerators\<CamelCase(name)>` with
  `regenerate(int $localId): void`. A manifest-sourced provider resolves to
  `manifests/providers/<id>.php` and must define
  `\Duo\Providers\<CamelCase(id)>` with `identity()`, `capabilities()`, and
  `invoke()`. A missing file is a loud load-time error naming the exact path.

That last one is worth stating without euphemism: **Duo loads PHP shipped
inside the manifests directory today.** The trust argument is not that it
doesn't — it is that the manifests directory is operator-controlled and deploys
with the agent itself, so loading code from it is the same trust decision as
running the agent at all. Content digests strengthen that: all three files —
interpreter, manifest-sourced provider, and (since DUO-3360) regenerator — join
the per-adapter content digest, so editing any of them is a *changed adapter*
rather than invisible drift behind a stable manifest digest. Two
implementations can no longer share one manifest revision's identity.

<a id="editing-a-shipped-manifest-moves-its-identity"></a>

> **Editing a shipped manifest's bytes, or a hook file it names, moves that
> adapter's identity — and deployed sites refuse until you re-pin.**
> `ArtifactPolicyIdentity::manifest_rows()` builds one row per manifest
> carrying the manifest array, its disposition, and `hash_file('sha256', …)`
> of each named interpreter, provider and regenerator file.
> `manifest_hash()` is sha256 over the canonical encoding of all those rows;
> `resolved_adapters()` hashes each row *individually* into that adapter's
> `digest`. It is the **same row** folded both ways, so a site repo's
> per-manifest content pin, `adapter_digest`, the digest `duo assess` reports,
> and the contract that pins it are all this one row hashed. Change a byte and
> a deployed site with a compiled artifact refuses with
> `compiled_artifact_manifest_mismatch` — *compiled manifest/interpreter set
> does not match active pins* — and the `site.duo.json` content pin stops
> matching too.
>
> The remedy is recompile and re-pin: rebuild the artifact and update the
> reviewed pin, which `wp duo manifest-pin --repo=<site-repo> --name=<name>`
> emits as a copy-pasteable object. Updating a pin is an explicit review act
> and is never automatic.
>
> What does **not** move identity: renaming a PHP namespace, or moving an
> `agent/src` class file. `manifest_rows()` folds manifest JSON bytes,
> disposition bytes, and the sha256 of the named hook files — nothing else.

## The minimal worked example

[`manifests/contact-form-7.json`](../../manifests/contact-form-7.json) is about
as small as a real adapter gets. Stripped of its notes, it is six keys:

```json
{
  "spec_version": 2,
  "name": "contact-form-7",
  "plugin": "contact-form-7/wp-contact-form-7.php",
  "version_range": {"min": "6.0.0", "max": "7.0.0"},
  "notes": ["…"],
  "post_meta": {
    "_form": {"class": "authored"},
    "_hash": {"class": "authored"}
  }
}
```

- `spec_version` must equal the engine's own `DUO_SPEC_VERSION` **exactly**.
  Absent and declared-wrong are the same failure, both refused at load.
- `plugin` is the plugin basename; `version_range` is `{min, max}` with min
  inclusive and max exclusive, checked with two `version_compare()` calls. One
  plugin per manifest. Declaring a plugin without a well-formed range is
  refused at load, before any target contact — there is no "latest" or
  unbounded form, because an unbounded claim is not certifiable. Two pinned
  manifests naming the same plugin with different ranges is also refused
  outright: manifest precedence must never depend on pin order.
- `notes` is where the *evidence* for every rule lives. Read CF7's: each entry
  names what was verified live, against which version, through which code
  path. That is the standard. A rule without an evidence note is a guess with
  better formatting.

### The caveat that catches everyone

CF7's own notes carry it: `wpcf7_contact_form` **must** be in the site's
`policy.post_types` in `site.duo.json` for any of these rules to take effect.
**A manifest classifies; the site scopes.** A manifest classifies keys within
entities that are already in scope; the scope list itself is site-local policy.
`duo init` proposes that scope for you — every `post_types`/`taxonomies` entry
of class `authored` in a selected adapter goes into the proposal — so on an
init-owned repository a manifest with `"post_types": {"wpcf7_contact_form":
{"class": "authored"}}` does carry its own scope. A hand-authored
`site.duo.json`, or a scope you narrowed afterwards, still has to name the
entity, and `duo classify` is how a type left local is re-decided later.

For an interpreter-shaped adapter, where meta semantics live in data rather
than in a static key list, [`manifests/acf.json`](../../manifests/acf.json) is
the reference.

### Deleting what you author

Authoring a post type does not make its rows deletable through Duo. A capture
that finds an authored row gone mints a *deletion intent*, and the engine
refuses that intent — loudly, at capture — unless a pinned adapter declares the
destructive effects the kind needs: `duo: deletion intent for post:<type> is
unsupported — no pinned adapter declares its reverse-reference checks and
cascade effects`. WordPress's own cascade behaviour is never inferred. Declare
it:

```json
"deletions": {
  "post:wpforms": {
    "cascades": ["postmeta", "post_revisions", "term_relationships"],
    "guards": []
  }
}
```

`cascades` is the closed required set per kind (`post`: postmeta,
post_revisions, term_relationships; `term`: termmeta, term_taxonomy,
term_relationships; `menu`: those plus menu_items); `guards` lists the
reverse references that must be empty before a delete is allowed (`{table,
column, id_kind, reason}` — `manifests/core.json`'s `post:attachment` shows a
comments guard and a child-posts guard). An empty guard list is a claim that
nothing references the row: make it only when it is true. The T6 walk's
WPForms adapter declares exactly the block above, because a WPForms Lite form
is referenced by nothing Duo manages; without it S2 stopped at the first
target-side capture after a form was deleted.

## Precedence, in one sentence each

- **Site policy always wins.** A rule in `site.duo.json`'s `policy` outranks
  every manifest, for every section.
- **A non-core manifest outranks `core`.** The loader keeps scanning past a
  `core` match specifically so a plugin's own declaration takes it — a
  reclassification of a core option by a plugin manifest is legal and loud.
- **Patterns are the last resort.** `option_patterns`, `meta_patterns`, and
  their kin are consulted only after every exact rule has missed.
- **Anything still unmatched is unclassified**, which is a loud abort, not a
  default.

## Declaring repair work: actions and providers

Apply writes rows directly and fires no hooks — and the hooks it skips are also
what maintain a plugin's derived state: indexables, lookup tables, generated
CSS, blanket caches. A manifest declares that repair **as data**, in a top-level
`"actions"` list. The full grammar is the "Structured rebuild actions and
providers" bullet in
[spec/repo-format.md § Manifests (registry format)](../../spec/repo-format.md#manifests-registry-format);
below is the shape of the decision, not the schema.

Every entry declares a `kind`, and choosing between the two is the whole design:

- **`"kind": "native"`** names an action from the engine's **closed
  vocabulary** — operations whose meaning belongs to WordPress core and is
  identical no matter which plugin declared them. v1 is exactly one action,
  `transient.delete`, taking a bounded-string `name`. Reviewed engine code
  executes it and confirms the result by reading the value back.
- **`"kind": "provider"`** names a capability of a provider declared in the
  *same* manifest's top-level `"providers"` list — anything plugin-specific.

A manifest cannot mint a native action: an unknown name, an unknown argument
key, or a mistyped argument is refused at manifest load, before any target
contact. Arguments are typed scalars checked against a per-action schema, never
command strings handed to a shell, `eval`, or WP-CLI — which is exactly what
keeps executable text out of the channel. **The free-form `rebuilders` channel
is gone**: a manifest declaring it, even as an empty list and even inside a
frozen policy snapshot, is refused at load — porting an older manifest starts
there.

Both kinds may declare `triggers` and `effects`. `triggers` uses the same
canonical-surface grammar apply projects from authored work
(`(post|term|table|option|entity):<name>`); omit it and the action is unscoped,
firing for any non-empty surface set, while a read-only apply fires nothing.
`effects` feeds the bounded-reversibility inventory; omitting it records an
explicit irreversible fallback row rather than silently claiming reversibility.

### Providers

A provider declares `{"id", "version", "source", "plugin", "capabilities"}`.
Ids are globally unique across pinned manifests — a conflict is a refusal,
because pin order must never decide which code runs — and its `plugin` must
match the manifest's own `plugin` claim, so the executable half stays inside
the version window the declarative half was certified for.

`"source": "manifest"` is code you ship: `manifests/providers/<id>.php`, under
the same trust boundary as interpreters, digest-bound into the adapter
identity. `"source": "plugin"` is advertised by the installed plugin itself
through the `duo_providers` filter and trusted as part of it; that file is
deliberately *not* digest-bound, because the installed plugin — checked against
`version_range` — is its identity anchor.

A provider may additionally declare `"requires"`, a closed object naming the
environment its executable half needs before the engine will load it:

- `functions` / `classes` — non-empty lists of PHP symbol names (a leading
  backslash and namespace separators are allowed), checked with
  `function_exists()` / `class_exists()`.
- `plugin_version` / `wordpress_version` / `php_version` — each a `{min, max}`
  window, min inclusive and max exclusive, the same arithmetic `version_range`
  uses. `plugin_version` bounds the owning plugin *independently* of the
  manifest's own `version_range`, so a provider may require a tighter window
  than its adapter is certified across; `wordpress_version` reads
  `get_bloginfo('version')`; `php_version` reads `PHP_VERSION`.

The object must be non-empty and every key comes from that closed set — an
unknown key or an empty `{}` is refused at load. `requires` is manifest bytes,
so it folds into the certified adapter identity: adding it to a shipped manifest
is a bundle event, not a free edit. When any declared requirement is unmet,
negotiation refuses **before the provider file is loaded or its plugin registry
is consulted** — strictly earlier than any check the provider code could run
itself — with a single `provider_requirement_unmet` problem naming the provider,
its owning plugin, the declaring manifest, every unmet requirement at once, and
a remediation. Nothing provider- or value-controlled reaches that message: only
load-validated symbol names and version strings.

Before the first target mutation, apply **negotiates** every provider its
selected actions reach: contract shape, exact identity match, owning plugin
installed and active and in range, every declared `requires` satisfied, a
well-formed capability declaration
(argument schema, read/write surface summary, site or entity scope,
`idempotent` — required `true`, since apply's retry machinery re-fires the
rebuild pass — and a `timeout_seconds` budget), and the manifest's arguments
valid against it. Any miss refuses with per-problem remediation, so an
incompatible capability fails *before* destructive writes, never after commit.

Invocation returns a receipt whose `verified` must be exactly `true` on the
strength of a **value-level readback**. Command-success-only verification is
refused — an exit code is not evidence that derived state was repaired. A
successful native action surfaces in apply's output as
`native action fired: <action> (verified)`.

Your `before`/`after` are **public output** — they reach `wp duo apply
--format=json` — so the engine publishes a bounded projection of them rather
than your bytes. A string over 512 bytes, one carrying control bytes or invalid
UTF-8, one matching the shared secret grammar, a map key breaking the same
rules, a container nested past 4 levels or holding over 128 entries, and a
whole value still over 8 KiB after all of that are each replaced by
`<duo:receipt-witness/v1:<reason>:sha256:<digest>>`. The digest is taken over
the raw value at every level, so **equal values still publish equal bytes and
unequal ones still publish unequal bytes**: `before === after` stays decidable
from the published receipt, which is the whole point of returning both. A
receipt carrying an object, a resource, or a non-finite number is refused
outright — the engine will not summarize what it cannot read. Nothing retains
the raw values anywhere; if you want a specific fact to survive publication,
summarize it yourself (a count, a hash, a short outcome string) instead of
returning raw state and hoping it fits. The secrecy and control screens are
per-leaf and pattern-based (C0/DEL control bytes; the shared refusal screen's
secret grammar): a credential split across entries, base64/hex-encoded, or
carried in C1/zero-width/bidi codepoints is NOT detected — do not put
credentials in receipts at all.

An argument may be a scalar, a `list<string>`, or a `list<object>` — the last
declaring its own closed `fields` vocabulary of `bool`/`int`/`string`, exactly
one level deep, so a row is structured without becoming a free-form payload. An
entity-scoped capability may additionally declare
`"context": ["deletions", "reparents", "retry", "always_on_write"]` (any subset,
no duplicates, refused on `scope: "site"`). Declared channels arrive alongside
the entity batch under the reserved `entities` argument; declare none and the
argument stays exactly the bare row list it has always been.

What each channel delivers — and, where it is narrower than the batch
regenerator channel an adapter may be migrating from, what it does *not*:

- `deletions` — `{kind, uuid, id, post_type, parent_id, child_ids}` per
  tombstone on your own triggering surfaces, for deletes this apply actually
  executed (`--with-deletes`), that a previous incomplete apply had already made
  absent, or that an earlier incomplete apply left a durable receipt for. A
  tombstone this run only planned is not delivered. `id` is `0` when the ledger
  mapping is already gone. `parent_id`/`child_ids` are the engine's pre-delete
  inventory, taken because your capability declared this channel; all six keys
  are always present, so an empty `child_ids` means "no declared children were
  tombstoned", never "the engine did not look".
- `reparents` — `{kind, uuid, id, root_id, old_parent_id, new_parent_id}`, one
  row per derived root, unioned with any durable reparent marker an earlier
  incomplete apply left outstanding. **Bounded by capture**: the engine records a
  reparent receipt for post types with a batch `regen_dependency`, or whose
  surface a `reparents`-declaring capability in the same run triggers on — a
  move on any other post type produces no row. One row per root is how a chained
  A→B→C move survives a scalar row grammar; regroup them by `uuid` if your repair
  wants the accumulated root set, and do not collapse to the old/new pair, which
  silently strands the first root.
- `retry` — whether this apply is retrying an incomplete one.
- `always_on_write` — a flag stating you fired on an always-on basis. It mirrors
  `regen_dependency`'s flag, which suppresses a per-candidate existence check
  and never creates candidates: **it does not make your capability fire on an
  empty run.** A capability fires when its entity batch or an evidence channel
  carries something; otherwise apply records an explicit skip receipt.

Declaring a channel also makes you the OWNER of the durable bookkeeping behind
it, which is what makes the channels a retry queue rather than a one-shot read.
A `regen_delete_context:`/`regen_reparent_context:` marker on a surface your
action triggers on is deleted only after your receipt says `verified: true` —
and only on your own triggering surfaces, so your receipt never retires another
adapter's outstanding evidence. Ownership is decided from the PINNED manifest,
not from what a given run happened to select: an apply that touched nothing on
your surface leaves your markers alone rather than reading "no work here" as "no
consumer exists". They are swept, with a warning naming the marker and the
channel nobody consumed, when a run does reach the surface and no negotiated
capability wants the channel, or when no pinned action claims it at all. Two
consequences to plan for: a marker for a plugin you have pinned but deactivated
persists rather than decaying, and every outstanding receipt is listed in
`duo plan` / `duo status` (`regen_context`, which reports not-ok while one
stands) — visible debt rather than silent debt. Exactly one capability may
consume a given channel on a given surface; a second one is refused at
negotiation, because the clear is per-marker and the second consumer would lose
the evidence its own retry needs. The entity batch works the same
way through `regen_pending:<uuid>`: armed before the call for every post-kind
entity delivered, cleared on a verified receipt, and unioned back into a later
run's batch when it was not — which is the only path by which a failed repair
retries at all, since a plan's content hash never reflects derived state. Every
entity row is also filtered against that run's deletions (ids and `child_ids`),
so a deleted id never reaches you as live work. This is where `idempotent: true`
earns its keep: a retry re-delivers exactly the batch that failed.

Those three marker prefixes are SHARED with the batch `regen_dependency`
channel on purpose — one retry vocabulary, one `duo plan` / `duo status`
projection — so a post type may be claimed by only one of them. A capability
declaring any channel while triggering on a post type that also declares an
enabled batch `regen_dependency` is refused at negotiation, before any target
mutation, naming both claimants: that refusal is what a half-finished migration
looks like, and the fix is to finish it (drop the `regen_dependency`; its
`batch`, `verify`, and `effects` belong on the action now).

One thing the contract does not give you: a heartbeat. `invoke()` receives a
capability name and typed arguments and nothing else, so apply renews the
promotion lease immediately before and after your call and cannot renew during
it. That does not make a long call self-abort — the lease heartbeat tolerates an
expired lease while the promotion's process fence is continuous, so the renewal
on the far side still succeeds unless another writer actually took the lock in
the meantime. What a long call really costs is that window in which the lock is
acquirable by someone else, so declare `timeout_seconds` at the lease TTL and an
overrun is reported once, with its measured duration, instead of later as a lock
loss nobody can attribute.

A capability's own database reads are the read twin of the engine's mutation
path (`\Duo\Db`), and share its discipline. WordPress's `wpdb` read methods
return an empty-looking value on a failed query rather than throwing —
`get_var()` returns `false`, and the `get_col`/`get_row`/`get_results` shape
collapses to a non-array — so a capability that trusted the bare return could
clear a `verified: true` receipt on a query that never ran. Route every
decision-making read through
`\Duo\ProviderSdk::checked_get_var|checked_get_col|checked_get_row|checked_get_results($sql, $context)`,
which clears `last_error`, runs the read, and fails on both the read's own
failure shape *and* any driver error — never trust an empty result as
convergence. Like `Db`, the `$context` is operation-level, never value-level:
`last_error` and the rendered SQL can echo option/meta payloads, so the SDK
keeps the SQL and the driver text out of its failure and names only your
context. Keep values out of your own messages the same way.

An adapter needing no executable semantics declares neither key and stays purely
declarative. Most should. For worked examples,
[`manifests/woocommerce.json`](../../manifests/woocommerce.json) pairs a
manifest-sourced provider with a triggered, effect-declaring native
`transient.delete`, and the
[`manifests/duo-agency-cpt.json`](../../manifests/duo-agency-cpt.json) fixture
shows a plugin-advertised one.

## Checking the grammar offline

Almost everything above is refusable without a WordPress anywhere: a manifest is
data, and the validators that read it are the pure half of policy load, which
runs before any target is contacted. `duo manifest-validate` is that half,
exposed on its own so you can iterate on a declaration in seconds instead of
reinstalling an agent to find out you transposed a letter.

```sh
duo manifest-validate manifests/
duo manifest-validate manifests/ --manifest=contact-form-7
duo manifest-validate manifests/ --pins=core,woocommerce --format=json
duo manifest-validate manifests/ --site=/path/to/site-repo
duo manifest-validate ./untrusted-adapter-package --no-code
```

It needs no environment, no database, and no docker. Every manifest in the
directory is loaded on its own first — so one broken file does not hide the
verdict on the other nine — and then the requested pin set is co-loaded, which
is the only way the cross-manifest guards run at all (one owner per declared
name, overlapping option namespaces, conflicting plugin claims, duplicate
provider ids, duplicate table `id_kind`s). `--manifest` narrows what is checked
individually; `--pins`/`--all` choose the co-loaded set. A declared
`interpreter` or `regen_dependency.regenerator` is resolved too: the named file
must exist under `interpreters/`/`regenerators/` in the same directory and must
define the contract class.

Refusals are the engine's own, printed verbatim with their exact coordinates
(`manifest 'x' actions[0].args.name …`, `table 'y' … identity.columns …`). A
per-manifest row carries that manifest's file path; the pin-set row carries the
paths of everything co-loaded, because a cross-manifest refusal names manifests
rather than one file. Exit status is `0` when everything is valid, `1` when
anything is not, and `2` for a usage or IO problem.

### Point it only at a manifests directory you trust

Resolving a declared interpreter or regenerator means **loading that PHP**: the
file's top level runs when it is `require`d, and its constructor runs when the
class contract is checked. There is no way to answer "does this file define
`\Duo\Interpreters\Acme` with the right method" without doing that. So this
command is exactly as safe as the directory you point it at — which is the same
trust decision running the agent itself already makes about its manifests
directory, no more and no less. Treat a manifests directory as code, not as
data, and do not run this against a package you would not install.

For the one case that boundary does not cover — a **first look at an unfamiliar
out-of-tree package** — `--no-code` validates every declaration and skips the
code half entirely:

```sh
duo manifest-validate ./untrusted-adapter-package --no-code
```

Nothing is loaded and nothing is instantiated, so a hostile `interpreters/*.php`
never runs. The trade is real and the report states it rather than implying a
clean bill: the run prints `manifest code: --no-code …` in its header and carries
an explicit not-performed row in the deferred list. A `--no-code` pass therefore
means "the declarations are well-formed", never "this package is fine" — read
the code, then re-run without the flag from a directory you trust.

### `--site`, and why leaving it off can refuse a valid manifest

Two of the guards above are not functions of the manifests alone. They read the
SITE half of policy as input:

- a table declared in `site.duo.json`'s `policy.tables` extends the legal
  ref/token/ledger **kind vocabulary** exactly as a manifest-declared one does,
  so `"ref": "my_site_thing"` is legal on that site and nowhere else;
- a `policy.options.<name>` rule is the ratified **resolution** when two
  manifests declare one option name differently — the guard skips a name the
  site has already decided.

Run without `--site`, this command loads with no site policy at all, so either
guard can refuse a manifest its real site accepts — and the second one's
remediation ("add an explicit `site.duo.json` policy.options override") is
advice to add something you may already have. Point `--site` at your duo site
repo (the directory holding `site.duo.json`) and both guards get their real
input:

```sh
duo manifest-validate manifests/ --site=/path/to/site-repo
```

Without it, a refusal from either guard is **annotated**, never rewritten — the
engine's message is printed exactly as it stands, followed by a note saying the
refusal may be resolvable by a `site.duo.json` this run was not given. The
missing site half is also a permanent entry in the deferred list below, so it is
stated on every run rather than only when it happens to bite.

Two things it is deliberately not. It is **not a gate** — nothing runs it for
you, and passing it is not a certification, a disposition, or permission to
promote. And it is **not complete coverage**: every run, passing or failing,
ends with the list of what it did not check — the site-policy half just
described, plus the checks that genuinely need a live target (live table schema,
`taxonomy_patterns` expansion, installed plugin/theme versions, provider
negotiation, native-action execution, capability evaluation, and lint's live id
cross-reference) — each marked `deferred` and each naming the engine function
that owns it. Read that list as the honest boundary of what just happened.

### The JSON report, for editors

`--format=json` emits the same verdict as a document an editor or language
server can consume directly:

```json
{
  "format": "duo-manifest-validation/v1",
  "spec_version": 2,
  "manifests_dir": "/path/to/manifests",
  "site": null,
  "code": "resolved",
  "status": "ok",
  "manifests": [{"name": "core", "file": "/path/to/manifests/core.json",
                 "status": "ok", "message": null}],
  "pinned_set": {"names": ["core"],
                 "files": {"core": "/path/to/manifests/core.json"},
                 "status": "ok", "message": null},
  "deferred": [{"status": "deferred", "surface": "tables",
                "check": "Snapshot::assert_row_schema() …", "why": "…"}],
  "summary": {"checked": 1, "ok": 1, "error": 0}
}
```

`site` is the resolved `--site` repo, or `null` when the run had none; `code` is
`"resolved"` or `"skipped"` (`--no-code`). A
`manifests[]` row whose `status` is `error` carries the engine message in
`message` and the file it belongs to in `file`; the `pinned_set` row carries
`files`, the path of every co-loaded manifest. A row may additionally carry:

- `pinned_set_note` when the manifest failed *in isolation* but is valid inside
  the requested pin set — the legitimate case of an adapter naming another
  adapter's declared `id_kind`, where the repair is a pin rather than an edit;
- `site_policy_note` (on a manifest row or on `pinned_set`) when the refusal came
  from one of the two site-sensitive guards and the run had no `--site`.

### The grammar document

```sh
duo manifest-validate --emit-schema
```

prints the closed vocabularies, bounded patterns, and native-action argument
schemas as one versioned JSON document (`duo-manifest-grammar/v1`) — the raw
material for editor completion, a schema-aware linter, or a review checklist.

Every set in it is read out of the engine at emission time, never written down
in the emitter. That is the only property that makes it worth trusting: a
hand-maintained copy would keep offering `verbatim` for a release after the
engine stopped accepting it. Vocabularies whose legal values depend on which
manifests are pinned — ref, token, and ledger kinds, which extend by *declaring
a table* — publish the engine-owned base only, named as such; the declared half
belongs to a pin set plus one `site.duo.json`, not to the engine.

The document also carries a `coverage` field stating what it does **not**
publish, so a consumer never has to infer the boundary: `vocabularies` is VALUE
vocabularies only (the closed sets of legal KEYS are not there — several depend
on a sibling value, so there is no flat set to publish), the sets are
unconditional (`mode: "prevented"` is legal only for mail/http/queue effects,
and no set can say that), `patterns` is the named subset (roughly twenty further
inline PCREs in the engine have no published name), and the pin-dependent
vocabularies publish only their base. Build on it, but build knowing that a
document-clean manifest can still be refused by a rule the document does not
describe — which is what running the validator itself is for.

## The authoring loop

### 1. Observe

Exercise the plugin on a real environment — create the entities through the
plugin's *own* admin code path, not by hand-writing postmeta, because the
whole point is to learn what the plugin actually writes. The provenance journal
records those writes; `wp duo journal-report --manifests=<names>` aggregates
them and scores proposals against the manifests you already have.
`wp duo journal-reset` truncates the journal when you want a clean observation
window for one specific interaction.

Note what the journal will *not* do: a bare authenticated write never proposes
`authored`. Proposals come from evidence and are deliberately conservative.

### 2. Propose

```sh
duo pending dev
```

The review queue shows unclassified meta on in-scope entities, entity types
with live rows and no scope disposition, and journal-observed unclassified
options. Each item carries whatever evidence exists — entity counts, journal
surfaces, a ref-hint, a secret flag — and **never a guessed classification**.
An item with no evidence for a proposal prints `-`.

### 3. Draft

```sh
duo classify dev
```

Interactive triage reads decisions from stdin, so it is pipe-testable. Under
the hood every decision batches into a single agent call, and that call has two
wp-cli parsing traps that were confirmed empirically rather than assumed:

- **Use the `=` form.** `--set=post_meta:foo=runtime` works;
  `--set post_meta:foo=runtime` does not — wp-cli parses the space form as a
  bare boolean flag and the value lands in positional arguments, silently.
- **Repeating the flag does not accumulate.** `--set=a --set=b` keeps only
  `b`. Pass multiple rules as one semicolon-joined value:
  `--set='post_meta:foo=runtime;options:bar=authored,ref=post'`.

Both apply whenever you drive `wp duo classify` directly. `duo classify` builds
the joined value for you.

### 4. Export the site-local rules into a manifest

```sh
wp duo policy-to-manifest --repo=<path> --match='^wpcf7_' --name=contact-form-7
```

`--match` is a PCRE body without delimiters, tested against each key; `--name`
becomes the manifest's `name` field. This exports **only site-local policy
rules** — the ones you just classified into `site.duo.json`. It is the
promotion path from "one site decided this" to "the library declares this",
and it is deliberately one-directional: nothing reads a manifest back into site
policy.

Move the emitted JSON into `manifests/<name>.json`, add `plugin`,
`version_range`, and the evidence notes by hand, and drop the now-redundant
site-local rules from `site.duo.json`. Run
`duo manifest-validate manifests/ --manifest=<name>` on the result before going
further — see [Checking the grammar offline](#checking-the-grammar-offline);
the hand-added parts are exactly the ones no export path checked.

### 4b. Or start from a draft, when there are no site-local rules yet

`policy-to-manifest` promotes rules you have *already classified*. For a plugin
nothing knows about yet, there are none — so `duo adapter-draft` proposes them
instead, from the repository's captured `state/**` plus, with `--seed`, from
what `duo coverage` saw on the live site:

```sh
duo coverage prod --format=json > coverage.json
duo adapter-draft <site-repo> --name=wpforms \
  --seed=coverage.json --out=<site-repo>/adapters/wpforms.json
```

Why `--seed` earns its place: the offline proposers read `state/**`, so they
can only see surfaces Duo **already captures** — and the surfaces you are
writing an adapter *for* are exactly the ones it does not. An option prefix
invisible to every installed adapter, and a live table no manifest declares,
are invisible to the draft generator and plainly visible to `duo coverage`.
`--seed` turns each into a candidate with coverage's own observation quoted:

| coverage finding | proposed as |
|---|---|
| `options.invisible_groups[].prefix` | an `option_namespaces` match **and** an `option_patterns` rule |
| `tables.undeclared[]` | a `tables.<logical_name>` declaration |
| a scope-gate-refused post type | a `post_types.<name>` declaration |

The third one needs the richer seed: `duo coverage` reports options and tables
and nothing else, so pass a `wp duo assess-inventory --format=json` document
instead when you want post types too. The draft records which families its seed
actually supplied in `_draft.seed`, so a short draft is never a silent one.

**Every seeded candidate is `runtime`, and that is a default, not an
observation.** An undeclared table is one Duo has never read a row of; calling
it `authored` on that evidence would put live operational rows into your
repository. What `runtime` buys is honest: the surface becomes *declared and
excluded* instead of reading `unclassified / block` in assess. Promote the
parts that really are authored configuration by hand — and then their columns,
primary key and identity are live facts an offline draft cannot supply, which
is what each candidate's `questions` say.

Everything under `_draft` is inert: `Policy::load()` never applies a proposal,
and the trigger keys are renamed so no validator mis-collects one. Ratify by
hand, delete the rest, then `duo adapter inspect <name> --repo=<site-repo>`.
`--out` refuses to overwrite an existing draft without `--force`, because that
file holds your ratifications; re-running with `--force` is safe, since human
edits in the prior draft are carried forward.

### 5. Pin it

```sh
wp duo manifest-pin --name=contact-form-7
```

This prints the exact canonical `{"digest": …, "name": …, "source": …}` object
to paste into `site.duo.json`'s `manifests` array (keys are canonical, so they
print in alphabetical order). `source` is always present and always the source
the adapter actually resolved from — `shipped` here, `site` for one installed
at `adapters/<name>.json`, `plugin` for one a plugin bundles — because a pin
that named only a name and a digest could not say WHICH definition it
reviewed, and a pin that names a source refuses loudly the day a different
source starts answering to that name. The digest is content-addressed against
the same per-manifest digest compiled artifacts record in `resolved_adapters`,
including a declared interpreter's name and bytes, and load refuses a mismatch
before any policy consumer or target contact.

Without `--repo`, `manifest-pin` deliberately does **not** load
`site.duo.json`; that is the shipped-library path. With `--repo=<site>`, it
loads the repository's policy and site adapter source but overrides only the
selected manifest pin, so a stale digest for that adapter cannot prevent you
from computing its reviewed replacement. Unrelated repository errors still
refuse. Updating a pin is an explicit review act; it is never automatic.

### 6. Exercise it

Re-run the loop on a clean environment: capture, apply to a second environment,
recapture, and diff. A round-trip whose recaptured `state/` is byte-identical
is the only evidence that the classification is right. `wp duo lint` is the
companion check — it flags id-shaped values at undeclared paths, which is
exactly the shape a missing `ref`/`json_refs` declaration takes. Its findings
are plan-time signals, not proof of corruption; each carries its own caveat
note, because small ids legitimately coincide with counts, versions, and
ordering indexes.

Real worked narratives, with the empirical grounding for each decision, are in
[docs/grind/r1a-forms.md](../grind/r1a-forms.md) and
[docs/grind/r1c-agency.md](../grind/r1c-agency.md).

## Dispositions: the reviewed claim source

`manifests/dispositions.json` (`duo-manifest-dispositions/v1`) is separate from
every manifest **so that declaration cannot imply certification**. It is
hand-authored and reviewed, and it is the *only* authored source of a product
capability claim: `ManifestDispositions::claim_from_disposition()` projects the
claim, `AdapterRegistry` evaluates that projection against a live target, and
`tools/capability-doc.php` renders it into
[docs/capabilities.md](../capabilities.md). There is no second, generated
document for it to agree with.

Coverage is an **exact one-for-one set**, in both directions. A manifest with
no entry, or an entry with no manifest, is a loud load failure naming both
sides:

```
duo: manifest disposition coverage mismatch; missing=[<manifest with no entry>], extra=[<entry with no manifest>]
```

So shipping `manifests/<name>.json` without adding its entry does not produce
an unreviewed adapter — it produces an agent that refuses to load a policy at
all, and a red `make release-gate` (`capability-doc.php --check` enforces the
same rule so the document cannot describe a library the agent would reject).

### What each status means now

| Status | What it says |
|---|---|
| `certified` | Declared by the manifest, reviewed by a human who wrote the `reason` down, and exercised by the conformance suites the entry's `evidence.tests` name. It does **not** mean a bundle digest seals the claim to a run or an artifact set. |
| `experimental` | Reviewed, and deliberately not production-authorizing. The projection reads `Experimental`, which `duo release` refuses on before it freezes anything — including through a conditional path. |
| `excluded` | Reviewed as carrying no product claim. The generated document prints these as shipping "for regression use only"; the claim reports `authored_state.status: unsupported`. `duo-agency-cpt` is the one shipped example. |
| `uncovered` | **Runtime-synthesized only.** A disposition may never declare it — `validate_entry()` refuses that — and the agent emits it for a manifest with no reviewed entry, with the reason `no reviewed disposition entry — a manifest cannot certify itself merely by existing beside the agent`. It is a blocker, never a skip. |

An entry names supported versions, entity and field sections, operations,
lifecycle phases, deletion semantics, explicit unsupported behavior, every
table whose default keyspace is authored, and its `evidence` citation — the
suite names a reviewer wrote down. That citation is reported verbatim wherever
it surfaces; nothing re-derives a status from it, which is exactly why
`duo adapter inspect` prints the cited test ids and no per-test verdict.

Three cross-checks bind an entry to the manifest it describes, refusing rather
than papering over: a disposition naming a plugin must agree with that
manifest's own `plugin`/`version_range` bytes; its declared sections must exist
in the manifest; and its supported deletion selectors must be exactly the
manifest's declared `deletions` keys. Otherwise the document would advertise an
operation no manifest implements, or hide one no reviewer blessed.

Capability *reduction* is a legitimate outcome of this review. Behavior that
works but cannot be proven is removed and refused rather than shipped
under-proven — and the reviewer writes the reason into the entry so the
generated page can print it.

### Adding a shipped adapter

Nothing here is an allowlist edit; every step is data or a convention-named
file.

1. **Write the manifest** at `manifests/<name>.json`. `php cli/duo
   manifest-validate manifests --manifest=<name>` runs the engine's real
   validators over it offline, with no WordPress and no environment.
2. **Add the reviewed entry** to `manifests/dispositions.json`, with a
   `reason` a human wrote. Coverage is exact, so this is not optional
   bookkeeping — see the refusal above.
3. **Add the conformance checks.** Add
   `sandbox/conformance/entries/<name>.json` and the mirroring key in
   `sandbox/conformance/manifests.json` (the entry declares the pin set, the
   plugin/theme artifacts, and the post types and taxonomies the round trip
   must preserve), plus any of the three optional hooks the harness invokes if
   present: `seeds/<name>.sh` before capture, `postdeploy/<name>.sh` between
   deploy and apply, `checks/<name>.sh` after apply. Pin every plugin/theme
   version and its SHA-256 in `sandbox/conformance/artifacts.lock.json`. Run
   it with:

   ```sh
   bash sandbox/conformance/run.sh <name>
   ```

   A `certified` entry whose manifest declares a `plugin` must cite
   `conformance-<name>` in its `evidence.tests`, and that citation is only
   discoverable if `sandbox/conformance/entries/<name>.json` exists —
   `sandbox/tests/offline/policy/regress_manifest_dispositions.php` proves both offline.
4. **Regenerate the public prose** and check it in:

   ```sh
   php tools/capability-doc.php generate   # rewrites docs/capabilities.md + the README block
   make release-gate                       # capability-doc.php --check, then classmap-generate.php --check
   ```

   Never hand-edit `docs/capabilities.md` or the README's generated block, and
   never restate their rows in prose — a hand-copied claim is exactly the
   failure mode the generator exists to prevent.

A profile (`fse` is the shipped one) follows the same shape under
`dispositions.json`'s `profiles` key, with its own conformance entry.

**A shipped manifest is a shipped byte sequence.** Before you edit an existing
one, read [the identity warning above](#editing-a-shipped-manifest-moves-its-identity):
the edit moves `manifest_hash`/`adapter_digest`, and deployed sites refuse with
`compiled_artifact_manifest_mismatch` until the artifact is recompiled and the
pin re-issued.

### Authoring a SITE adapter instead

If the adapter is for your own site rather than the shipped library, none of
the above applies: an out-of-tree adapter has no reviewed disposition **by
construction** and never acquires one. It is data-only, it lives at
`adapters/<name>.json` in your site repository, and you certify it under your
own key:

```sh
duo adapter keygen --out=<secret-key-file> [--key-id=<id>]
duo adapter certify <site-repo> --name=<n> --secret-key-file=<f> [--key-id=<id>] [--reason=<text>] [--pin]
duo adapter pin <site-repo> --name=<n> [--source=site|plugin]
```

`certify` binds the signed statement to `manifests/capabilities/platform.json`
— the shipped platform boundary, folded in as `platform_sha256` over its exact
bytes. That pin is re-checked on every load, so a certificate cut against an
older boundary refuses by name once the shipped file moves: `duo: site adapter
'<name>' certification platform boundary disagrees with the current agent-owned
platform`. Re-sign with `duo adapter certify … --pin`.

**Be exact about what a site certificate attests.** It says two things, and the
bundle records that rather than leaving it to be assumed: *this organization's
key approves these exact adapter bytes*, and *the engine's own validators
accept the manifest's grammar*. It does not attest that the adapter was
exercised against a live site, that its deletion semantics were reviewed, or
that Duo endorses it. That is why the bundle carries a single named test,
`manifest-grammar`, whose result records `exercised: false` beside the grammar
verdict and your stated reason — `evidence.tests: ["something"]` is otherwise
indistinguishable downstream from a reviewed conformance run, and
`exercised: false` exists to stop exactly that collapse. `duo adapter list`
reads `site_signed`; the projection reads `Site-certified`, never
`Platform-certified`. The full mechanics are in
[Site-installed adapters and external certification](#site-installed-adapters-and-external-certification)
below.

## Site-installed adapters and external certification

A site may install an additional, data-only adapter at
`adapters/<name>.json`. Names are canonical lowercase ASCII slugs; the file
basename and manifest `name` must be identical, refused at load with the same
ambiguous-identity sentence the shipped library gets. This source overlays the
shipped library, and shadows a shipped name only when the repository says so
explicitly (see [Overriding a shipped adapter](#overriding-a-shipped-adapter)).
It cannot supply an interpreter,
regenerator, manifest-owned provider, trust root, disposition, or other PHP.
Plugin-owned providers remain valid because their executable identity is the
installed, active, version-bounded plugin and the ordinary provider
negotiation/receipt contract—not the site manifest.

Without a certificate, the adapter is usable for plan/apply but is visibly
`uncertified`; readiness and host promotion remain blocked.

There are two ways to certify one, and which you want depends on **whose
approval the certificate represents**.

### Your organization's own approval (`duo adapter certify`)

This is the path for an adapter you authored for your own site. The product
spec calls the result *Site-certified*: "customer-organization approval through
Duo's certification protocol, explicitly not a Duo endorsement". You hold the
key, you sign your own adapters, and the projection names you.

```sh
# 1. Mint the organization key. ONCE, and never inside a site repository —
#    a site repo is committed and published, so a key in one is a published key.
duo adapter keygen --out=~/.duo-keys/acme-org.key
#    key-id:     site-1a2b3c4d5e6f
#    public-key: <base64>

# 2. Certify the installed adapter and write the pin in one step.
duo adapter certify <site-repo> --name=<name> \
  --secret-key-file=~/.duo-keys/acme-org.key \
  --reason='Acme reviewed this adapter against its own catalog schema.' --pin
```

`certify` does five things and prints what each one produced:

1. Registers the public key in the site's own
   `adapters/authorities.json` — the **site trust root**, in exactly the
   shipped `duo-adapter-authorities/v1` grammar. Keys there are trusted only
   for adapters in that repository. A key present in both the shipped file and
   the site file: the shipped record wins.
2. Runs the engine's real manifest validators over the adapter. A manifest the
   engine will not load is never signed.
3. Signs `adapters/certifications/<name>.json` — the bundle is derived and
   built by the engine, never by hand, and never reaches disk. What it may
   claim is [What a site-rooted certificate may
   prove](#what-a-site-rooted-certificate-may-prove).
4. Immediately verifies what it just wrote, through the live verifier.
5. Prints the `{digest, name, source}` pin object; `--pin` writes it into
   `site.duo.json`.

**What this certificate says, and what it does not.** It says: this
organization's key approves *these exact bytes*, and the engine's validators
accept the manifest's grammar. It does not say the adapter was exercised
against a live site — the signed bundle records `exercised: false` and carries
it onto the claim, so nobody downstream can read `certified` as "somebody ran
it" — and it declares deletion semantics **unsupported**, because a validator
run reviews none. Assess reads `Site-certified` for the surfaces it governs,
prints `certified by <key-id> (site trust root); contract attestation unsigned`
once, and `duo release`/`duo promote` admit the adapter through their existing
certified-and-exactly-pinned gate: a valid signature without the exact
`{name, source: "site", digest}` pin stays `signed_unpinned` and blocked.

**The certificate binds bytes, so an edit breaks it.** Any change to
`adapters/<name>.json` moves the digest; the pin then refuses and the claim
drops back to uncertified. Re-run `duo adapter certify … --pin` after every
edit. That is the mechanism working, not a bug to route around.

**Key custody is yours.** A lost key cannot re-sign. A leaked key can certify
any adapter in a repository whose `adapters/authorities.json` names it. Back it
up where you back up deploy keys; production-grade custody (HSMs, rotation,
revocation workflow) is out of scope for this profile.

### Promoting a plugin-bundled adapter

An adapter a plugin bundles (`<plugin>/duo-adapter.json`) can never be
certified where it lives: certification binds `adapters/<name>.json` inside the
signed statement, so no certificate can name a bundled file at all. The
promotion path is to install it as a repository package first — copy it to
`adapters/<name>.json`, run `duo adapter pin <site-repo> --name=<n>`, then
`duo adapter certify`. The site copy wins by precedence and the bundled copy
reports as not installed; the plugin stays active throughout and nothing breaks
in between. (Replacing a *shipped* name is a different act with its own rules —
see [Overriding a shipped adapter](#overriding-a-shipped-adapter).)

### A reviewer's approval under the agent-owned trust root

This is the original path and it is unchanged. It is for a reviewer who
exercised the adapter and holds a key the *agent* trusts, and it produces a
richer bundle — real tests, real artifacts, a real evidence repository:

1. Produce a passing `duo-site-adapter-certification-bundle/v1` scoped exactly
   to `{"kind":"site_adapter","name":"<name>"}` whose bound inputs contain
   exactly the raw `adapters/<name>.json` bytes and whose ratification contains
   exactly one certified disposition for that name.
2. Install the signing public-key record under **one of the two trust roots**.
   Each `keys.<key-id>` record fixes Ed25519, the
   `site_adapter_certification` scope, `trusted` or `revoked` status, exact
   `adapter_names` and permitted `trust_tiers`, and the canonical public key —
   the same six-key grammar in both files:

   - the agent-owned `manifests/capabilities/adapter-authorities.json`, which
     only this project can fill. A certificate under one of its keys is
     `third_party_signed`, trust root `platform`.
   - **the site's own `adapters/authorities.json`**, held by the customer
     organization. A certificate under one of its keys is `site_signed`, trust
     root `site` — the product's *Site-certified*, which is customer-
     organization approval and explicitly **not** a Duo endorsement.

   The site record is a living registry: `duo adapter certify` appends each
   newly certified name (and its tier) to the key's record. A certificate under
   the site root binds the key's *identity* — id, algorithm, public key,
   scope, status, fingerprint, trust root — and the record it was signed over;
   the record's `adapter_names`/`trust_tiers` and its `revoked` status are
   enforced live against the current file on every verification. So certifying
   a second adapter under the same key leaves the first certificate (and the
   digest its pin binds) intact, while rotating the public key under the same
   id or revoking the key invalidates every certificate under it at once. The
   platform record binds whole: that file is reviewed and shipped, and never
   grows under an operator's hand.

   A key id present in both files resolves to the shipped record, always: a
   certificate that claimed the site root for such an id is refused by name.
   `adapters/authorities.json` is reserved inside `adapters/` — it is never an
   adapter, and a malformed one refuses the whole site source, because every
   certificate in the repository is judged against it. Private keys never live
   in the repository.
3. With a mode-0600 private-key file, sign and immediately verify the evidence:

   ```sh
   php scripts/adapter-certification.php sign \
     --manifest-dir=manifests --repo=<site-repo> --name=<name> \
     --bundle=<bundle-dir> --evidence-repo=<reviewed-checkout> \
     --authority=<key-id> --secret-key-file=<private-key>

   php scripts/adapter-certification.php verify \
     --manifest-dir=manifests --repo=<site-repo> --name=<name>
   ```

   The only site output is the canonical, path-derived
   `adapters/certifications/<name>.json` envelope. The tool prints a non-secret
   summary, never the key or certificate body.
4. Generate and commit the final source-and-digest pin:

   ```sh
   wp duo manifest-pin --repo=<site-repo> --name=<name>
   ```

### Certifying under your own root

Steps 1 and 3 above are the REVIEWER's path: a real conformance bundle on disk,
signed with an agent-owned key. Under a site root there is no bundle to
produce, and no step 1 — one command does the whole thing:

```sh
php scripts/adapter-certification.php sign-site \
  --manifest-dir=manifests --repo=<site-repo> --name=<name> \
  --authority=<key-id> --secret-key-file=<private-key> \
  --reason='grammar verified by the site operator; not exercised'
```

(`duo adapter certify` is the host verb over the same entry point.) It derives
the ratification from your manifest, runs the **real loader** for the grammar
verdict — an adapter that does not load is refused with the loader's own
message, because a certificate for bytes no command can use is the emptiest
possible claim — builds the bundle in memory, and writes only
`adapters/certifications/<name>.json`. There is no bundle directory to keep:
an unexercised bundle's assets are already inside the signed statement.

### What a site-rooted certificate may prove

A reviewer's bundle states a passing exercise. An operator certifying their own
adapter usually cannot produce one, so the bundle declares what it proves:
`evidence` is `{"exercised": <bool>, "grammar": "ok", "reason": "<text>"}`.
`exercised: false` requires empty `tests` and `artifacts`, is accepted **only**
under a site trust root, and rides onto the resulting claim — so `certified`
never reads as "somebody ran it". `exercised: true` is the reviewer's shape and
the only one an agent-owned key may sign; `sign-site` refuses an agent-owned
key by name.

The derived ratification claims nothing an unexercised check cannot support:
no `delete` operation, no lifecycle phases, every intent-only table marked
unsupported, and every open-ended `default_class: authored` keyspace recorded
`unsupported` rather than justified. The full wire contract is
[round-3-adapter-walk-bundle.md](../proposals/round-3-adapter-walk-bundle.md).

Every catalog and diagnostic row carries `trust_root` (`platform` for a shipped
row, `site` or `platform` for a signed out-of-tree one, `null` when nothing
signed) and `principal` (the authority key id), so "certified" always comes
with the name of whoever said so.

A valid signature without that exact `{name,source:"site",digest}` pin is
reported as `signed_unpinned` and remains blocked. A present malformed,
tampered, stale-platform, unknown-key, or revoked-key certificate is a policy
load refusal; it never falls back to unsigned support. The final adapter digest
binds the source manifest plus authority, signed statement, envelope, bundle,
ratification, and platform proof facts, while unrelated shipped adapter
digests and `duo capabilities --all` remain unchanged.

## Overriding a shipped adapter

A site adapter whose name collides with a shipped one is refused — silently
replacing a reviewed definition is never on offer. The repository can *state*
the replacement instead: pin the name with its source.

```json
{"manifests": ["core", {"name": "woocommerce", "source": "site"}]}
```

That pin selects `adapters/woocommerce.json` for the name. The shipped copy
leaves the loaded set and is reported on every run as `not_installed` with
reason code `shadowed_by_site`, naming the site copy that won; exactly one
definition answers to the name, so the cross-manifest guards see no conflict.

The host verb does both halves in one command: `duo adapter pin <site-repo>
--name=woocommerce --source=site` writes the `{name, source:"site"}` statement
first (printing `override: site.duo.json now names the site copy of shipped
adapter 'woocommerce'`), loads the repository with the site copy in force, and
completes the pin with the digest — the same `{name,source:"site",digest}`
object `wp duo manifest-pin --repo=<site-repo> --name=woocommerce` prints once
the override statement exists. Commit the object it writes.

**An override inherits exactly the shipped executable grants.** The usual
out-of-tree rule refuses an `interpreter`, a `regen_dependency.regenerator`
or a `providers[]` row with `source: "manifest"` in a site adapter, because
that code lives in the agent's own tree. A copy of a shipped adapter carries
those declarations already, and they are the shipped grant repeated: an
override keeps every one that is byte-for-byte what `manifests/<name>.json`
declares, and may add or edit none. Widening (a second manifest-sourced
provider, one more capability on the inherited one, another adapter's
interpreter) is refused with the override's own remediation — repeat the
shipped declaration verbatim or drop the change. The override therefore
carries the shipped tier its inherited code implies (a `compatibility_shim`
adapter stays `compatibility_shim`; it is not laundered into declarative), and
a site key may certify that tier.

Three things the override deliberately is not:

- A **name-only** pin is not an override. Precedence stays
  `shipped > site > plugin` for every one of them, and the refusal stands.
- An **unreadable** `site.duo.json` yields no overrides, so a broken policy
  file can never silently swap which definition is in force.
- The site copy never inherits the shipped adapter's reviewed claim. It carries
  the site's own certification words; a signed override reads `Site-certified`,
  never `Platform-certified`.

## Adapters a plugin bundles

A plugin may ship an adapter of its own: exactly one `duo-adapter.json`, at the
root of its own directory. Only ACTIVE plugins are scanned — activating the
plugin is the operator consent that installs the adapter — and a single-file
plugin, having no directory, cannot bundle one.

Two rules are specific to this source, and both differ from the site source on
purpose.

**Declare the plugin that owns you.** The file name is a constant here, so it
carries no identity: the manifest's own `name` is the identity, and the
manifest MUST also declare `plugin` equal to the exact basename of the plugin
bundling it — the file, not just its directory, since a directory can hold
more than one plugin and only the one you name is what version and activation
checks will ask about. That claim anchors the manifest to the code it ships with, exactly
as a plugin-owned provider's class is anchored to its plugin directory, and it
is what lets a frozen policy rebuild `plugins/<plugin-dir>/duo-adapter.json`
without reopening the plugin. Because `plugin` is mandatory, the compatibility
contract applies transitively: declare `version_range` too, or the adapter is
refused as unbounded support.

**A name collision with a reviewed adapter is reported, not fatal.** Adapter
sources rank `shipped > site > plugin`. If a shipped or site adapter already
answers to your name, your bundle is not loaded, and it prints on every run as
an installed-but-not-loaded row naming the winner — nothing breaks and nothing
is deactivated. (Two active plugins bundling one name have no such rule
available: both are dropped and the pair draws one `source_collision`
refusal.) Everything else in this source is refused per adapter rather than
whole-directory: a malformed bundle, a bad name, an anchor mismatch, a
`duo-adapter.json` that is a symlink or a directory instead of a real file, a
plugin directory duo cannot list (make it readable, or the near-miss check
cannot run and the adapter is refused rather than guessed at), a near-miss
inside the reserved `duo-adapter*` namespace, or a reach for executable
privilege drops that one adapter and leaves every other plugin's alone. It becomes fatal only if a repository pins
that name, which fails with the refusal's own message.

**A bundled adapter cannot be certified in place**, and no field or companion
file changes that: certification hashes `adapters/<name>.json` and binds
`source: "site"` and that exact path inside the signed statement. So a bundled
adapter is `uncertified` by construction — plan and apply available, readiness
and host promotion blocked, identical to an unsigned site adapter. To certify
one, promote it:

1. Install the same adapter as a repository package at `adapters/<name>.json`.
2. Obtain a signed `adapters/certifications/<name>.json` (the section above).
3. `wp duo manifest-pin --repo=<site-repo> --name=<name>`, and commit the
   emitted `{name,source:"site",digest}` pin.

The site copy then wins by precedence and the bundled copy reports as not
installed. The plugin stays active throughout; nothing has to be deactivated
and no command breaks in between. Run `wp duo adapter-survey [--repo=<path>]`
on the target to see all three sources, since the host-side `duo adapter`
commands are WordPress-free and cannot reach the plugin directory.

For redacted live proposal evidence, use `duo adapter-observe <env>
[--out=<local-file>|--format=json]`; its target half is `wp duo
adapter-observe --repo=<target-site-repo> --format=json`. The host calls the
configured target repository once and accepts only the canonical,
hash-validated `duo-adapter-observation/v1` projection. It never exposes
target values, IDs, titles, paths, messages, SQL, or credentials; `--out` is
create-only. The embedded adapter-source rows are a bounded projection of
`duo-adapter-sources/v2`, not a claim to preserve the full
`duo-adapter-catalog/v2` catalog. This is proposal evidence, not authoritative
`adapter-draft --evidence` input, and it does not certify an adapter or alter
a capability claim.

Normal plugin/provider registration and capability negotiation remain enabled
so the target can report installed runtime facts. Third-party callbacks may
have side effects before or during collection; Duo itself invokes no provider
action and makes no explicit mutation after observer entry. The document
defers table semantics, apply/rollback, version lifecycle, publication, and
certification.

## Planned: what an adapter cannot express yet

Four shipped channels cover what a plugin needs to *do*: **native actions** for
core-owned operations, **providers** for plugin-owned ones, **regenerators**
for per-entity derived rebuild, **interpreters** for schema-driven
classification. What is still missing sits above them.

**REMOTE adapter discovery, executable adapter packages, compatibility shims,
and a public capability catalog** are **Planned**. Site-repository discovery,
plugin-bundled discovery, packaged installation (into the site source, with a
signed certificate), derived trust tiers, loud unsigned support, and
agent-authority signed evidence all ship now. What remains absent is a
remote/registry mechanism that tells you an adapter you do not already have
EXISTS, and any way for an out-of-tree adapter to introduce executable code
outside an installed plugin; do not work around that boundary with manifest
fields or copied PHP.

What ships for the adapters you already have is the **installed-adapter
catalog**: `duo adapter list|inspect|doctor` reports the two host-reachable
sources offline (and `wp duo adapter-survey` all three, on the target),
printing each adapter's derived trust tier — `declarative_manifest`,
`native_action`, `plugin_provider`, or `compatibility_shim`, computed from the
privileges its own declarations actually reach and never self-declared — next
to a `tier_basis` naming the exact declaration that produced it. `doctor`
reports the discovery conditions that make every other command refuse
(shadowing, ambiguous identity, case-confusable names, an invalid identity
slug, a certificate that does not verify) as rows rather than dying on them,
each row naming which source it is about and whether it refused that whole
source or just one adapter.
Separately, `duo plan` and `duo status` now carry `provider_problems` rows for
every declared provider capability an environment cannot supply, with
remediation.

### One naming trap

The word **provider** carries four unrelated meanings here, all shipped. Three
are not the adapter provider this guide is about: bounded provider-resource
*selectors* in the spec, the target-owned *recovery* providers of the SSH
verified-rollback profile (exclusion, checkpoint, code-release, upload,
effect), and one attachment filter. Check which one an error means before
hunting for the wrong contract.
