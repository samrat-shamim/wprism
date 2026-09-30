# Adapter actions, providers, and schema settlement

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

## Declaring repair work: actions and providers

Apply writes rows directly and fires no hooks — and the hooks it skips are also
what maintain a plugin's derived state: indexables, lookup tables, generated
CSS, blanket caches. A manifest declares that repair **as data**, in a top-level
`"actions"` list. The full grammar is the "Structured rebuild actions and
providers" bullet in
[spec/repo-format.md § Adapter manifests (package format)](../../spec/repo-format.md#adapter-manifests-package-format);
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

Both kinds may declare `triggers` and `effects`. `triggers` normally uses the
same exact canonical-surface grammar apply projects from authored work
(`(post|term|table|option|entity):<name>`). A manifest declaring
`post-kind-action-trigger/v1` may additionally use `post:*`, which matches only
concrete `post:<type>` surfaces and gives an entity-scoped provider the concrete
kind/id rows, never the wildcard. Use that bounded trigger when the plugin owns
the same derived behavior for every registered CPT and an enumerated list would
silently miss future or site-defined types. It grants no term, table, option or
`entity:` authority; a provider declaring durable batch-context channels must
still use exact post-type triggers because marker ownership is per concrete
type. Omit `triggers` and the action is unscoped, firing for any non-empty
surface set, while a read-only apply fires nothing.
`effects` feeds the bounded-reversibility inventory; omitting it records an
explicit irreversible fallback row rather than silently claiming reversibility.

Treat a trigger list as a dependency-closure claim, not a performance hint.
List triggers only when every input capable of changing the derived output is
inside that closed surface set. Native permalink or routing work commonly
depends on options, terms, authors, every registered post type, and plugin
callbacks; a partial `post:*` list is then false. Omit `triggers` so every
non-empty authored apply repairs the projection, and make the provider's
bounded readback witness those effective inputs. Scoped apply admits that
global action only when it is `kind:provider`, its declaration and effects are
already hashed by the scope contract, and the exact capability successfully
negotiates an operation-bound invoke/reconcile contract. Untriggered native
actions and legacy providers still refuse before mutation.

Assert that boundary at its two public projections. The scope contract's
`potential_actions[].declaration` carries the provider, capability and effects;
the scoped plan intentionally carries only
`selected_actions[].{manifest,index,declaration_hash}`. Derive that hash with
`Canon::encode()` over the contract declaration and compare the complete
three-field plan row. Requiring private provider fields in the plan both tests
a shape the product does not publish and misses the actual declaration hash.
When a live harness loads `Canon`, resolve it from the runner-exported
`PAIR_SOURCE_ROOT`; `WPRISM_SOURCE_ROOT` is only an optional caller override and
is not part of the conformance-hook environment contract.

### Providers

A provider declares `{"id", "version", "source", "plugin", "capabilities"}`.
Ids are globally unique across pinned manifests — a conflict is a refusal,
because pin order must never decide which code runs — and its `plugin` must
match the manifest's own `plugin` claim, so the executable half stays inside
the version window the declarative half was certified for.

`"source": "manifest"` is code you ship:
`package/runtime/providers/<id>.php`, under
the same trust boundary as interpreters, digest-bound into the adapter
identity. `"source": "plugin"` is advertised by the installed plugin itself
through the `wprism_providers` filter and trusted as part of it; that file is
deliberately *not* digest-bound, because the installed plugin — checked against
`version_range` — is its identity anchor.

Every manifest-shipped provider, interpreter, and regenerator crosses the same
engine loader. It derives path, expected class, component hash, and adapter hash
from the already validated artifact-identity row; refuses symlinks, byte/stat
drift, preloaded or autoload-substituted symbols, and normalized PHP class-name
collisions; and invalidates an enabled opcode cache before the one permitted
load. Do not `require` another package executable or recreate these checks in
adapter code. Put shared loading mechanics in that engine boundary and keep the
runtime file to its declared plugin semantics.

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

`verified: true` is not taken on your word where the engine can check it. Around
your call it reads, itself, every surface you declared that it has a complete
bounded reader for — `option:<name>` today, because that is the only one of the
five surface kinds naming an extent that can be witnessed both completely and
without scanning a core table twice per apply — and compares the two readings.
Two things then refuse: a receipt whose `before !== after` when **every**
declared `writes` surface is observable and **none** of them moved, and a
surface you declared under `reads` that moved across the call (declare it under
`writes` if your capability writes it). Neither can displace the malformed,
unverified, or over-budget refusals; all three are decided first. A capability
whose declared surfaces the engine cannot read — anything `table:`, `post:`,
`term:` or `entity:` — is never refused for this: the gap is the reader's, and
negotiation publishes it under `surface_observation` before apply mutates
anything, rather than discovering it mid-write. The cost is one checked read per
observable surface per pass, two passes per invocation, and exactly zero for a
capability that declared none.

Your `before`/`after` are **public output** — they reach `wp wprism apply
--format=json` — so the engine publishes a bounded projection of them rather
than your bytes. A string over 512 bytes, one carrying control bytes or invalid
UTF-8, one matching the shared secret grammar, a map key breaking the same
rules, a container nested past 4 levels or holding over 128 entries, and a
whole value still over 8 KiB after all of that are each replaced by
`<wprism:receipt-witness/v1:<reason>:sha256:<digest>>`. The digest is taken over
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
`wprism plan` / `wprism status` (`regen_context`, which reports not-ok while one
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
channel on purpose — one retry vocabulary, one `wprism plan` / `wprism status`
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
path (`\WPrism\Db`), and share its discipline. WordPress's `wpdb` read methods
return an empty-looking value on a failed query rather than throwing:
`get_var()`/`get_row()` return `null`, while core `get_col()`/`get_results()`
return an empty array after `query()` reset `last_result`; compatible drivers
may additionally return a non-array failure shape. A capability that trusted
only the bare value could therefore clear a `verified: true` receipt on a query
that never ran. Route every
decision-making read through
`\WPrism\ProviderSdk::checked_get_var|checked_get_col|checked_get_row|checked_get_results($sql, $context)`,
which first applies the engine's closed SQL lexer and refuses mutation verbs,
multiple statements, comments, output/file functions, variable assignment, and
unreviewed stored/UDF calls before `wpdb` transport. It then clears
`last_error`, runs the read, and fails on both the read's own failure shape
*and* any driver error — never trust an empty result as convergence. Like `Db`,
the `$context` is operation-level, never value-level:
`last_error` and the rendered SQL can echo option/meta payloads, so the SDK
keeps the SQL and the driver text out of its failure and names only your
context. Keep values out of your own messages the same way.

Put related reads inside
`ProviderSdk::database_read_contract_snapshot($context, $read)`. The engine
derives its physical read profile from the capability's already-validated
`reads` and `writes` surfaces, starts one server-enforced read-only consistent
snapshot, and refuses a query outside that profile. If the projection needs
only part of the declared profile, use
`database_read_snapshot($context, $physicalTables, $read)`; the explicit list
must be a subset of the active contract and is a narrowing, never adapter-minted
authority. Use the checked read methods *inside* either callback. Bare checked
reads retain failure and non-mutation hygiene for identity-pinned existing
providers, but do not establish a snapshot or a table boundary and are not
sufficient for new provider evidence. Construction is not authority: only the
exact runtime object returned by the engine's digest/provenance loader can
activate either database scope; a direct, cloned, or unserialized runtime cannot
reuse its declaration to mint a profile.

One native database callback is limited to 1,024 statements, 16 MiB of total
rendered SQL, and 1 MiB per statement. Engine transaction controls do not
consume that callback budget. Capture partitions its separately bounded core
entity/option/chunk work inside the same snapshot. Authored Apply uses the same
engine machinery per adoption, entity phase, option record, widget allocation,
and regeneration-context record inside its one transaction. An options document
is a carrier, not one callback: its records retain independent budgets. Shared
admission, final certification, COMMIT and recovery keep their aggregate limits;
work partitioning grants no extra table, transport or transaction authority.
The opaque partition authority is returned only to the transaction owner.
Providers do not receive that authority
and must not use the engine's work-partition APIs. Calling or reentering a core
helper from native code does not replenish the callback's quota; an oversized
operation needs a genuinely bounded semantic design, not a counter reset.

Large engine-owned keyed strings use `Db::upsert_keyed_strings()`: at most
256 distinct UTF-8 key/value pairs and 7 MiB of aggregate value bytes, split
at complete UTF-8 boundaries into at most 256-KiB data fragments. Whole-field
WordPress validation and a full-width unique-key proof precede the first
write. The original physical transaction owns every append and the complete
key/length/hash readback; an interrupted batch poisons continuation so catching
an exception cannot publish a prefix. WordPress itself finishes placeholder
escaping before the generated fragments enter the unchanged SQL gate. Neither
chunking nor reentry replenishes the enclosing statement or byte quota.
This is shared engine storage, not a new provider permission or a general
large authored-row capability: providers still use their declared SDK boundary.
WPForms Lite 2.0.1.1 exposed the need through a 1,044,395-byte code descriptor,
not through plugin-specific storage semantics.

Audit native API reads with cold caches as well as warm ones before narrowing
that table list. For example, WordPress's `url_to_postid()` creates a `WP_Query`
that can prime post metadata: an id-only answer still needs `postmeta` read
authority. A native lookup can also create, update or delete transient rows;
its name does not make it read-only. Declare those physical dependencies in
the adapter; never widen the engine's table gate to accommodate an incomplete
profile. Exact `option:` surfaces admit leading underscores (private options,
transients and shadow keys), with the same 128-byte bound and no wildcards;
the other four surface namespaces keep their existing first-byte grammar.

Capture and Plan observations cannot borrow a provider's cache-write authority.
If a native getter rebuilds a transient on a cold or expired cache, select a
side-effect-free native interpretation API over bounded physical inputs, or
report the unsupported observation. Do not warm the cache, toggle a permanent
plugin constant, bypass native validation, or widen the capture table profile.
For term inputs, `ProviderSdk::term_rows($taxonomy, $maxRows, $maxBytes, $context)`
reads one exact taxonomy from the caller's already-established snapshot. Both
physical term tables must already be readable. The engine admits a size roster
before bounded hash/value batches, preserves raw driver bytes, and refuses an
orphan, duplicate identity, failed read, changed roster or exceeded budget.
It does not apply WordPress filters or interpret language, flag, locale or other
plugin semantics. Polylang's capture hook uses this split with its public native
language factory, retaining custom-flag refusals without calling its cache-backed
language-list getter. Exercise empty, cold, warm-but-stale and hostile native
callback cases through the actual protected capture hook; pure helper tests
cannot establish that the adapter chose the safe native API.

When declared plugin tables may legitimately be absent, use
`ProviderSdk::database_schema_snapshot($context, $physicalTables, $read)`.
The engine first discovers exact presence inside an empty read-only profile,
then proves every present table is InnoDB and brackets the callback with the
same complete presence map. The callback receives that map and gains read
authority only for tables proved present. Inside a fresh observer the API
reuses the already-established complete read-only contract profile; it never
nests a transaction or reuses a writable/narrower profile. Keep column and
index expectations in the adapter—the engine owns topology consistency, not a
third party's schema semantics.

For complete physical input and preservation witnesses, use
`ProviderSdk::physical_table_rows($descriptor, $context)` inside an already
active contract snapshot or transaction. A consuming manifest must declare
`provider-physical-table-rows/v1` alongside `spec-window/v1`; older v3 engines
then refuse the named dependency before loading executable behavior. The
provider-protocol feature alone does not version SDK methods.
Its closed descriptor names `table`,
ordered unique `columns`, an `identity` list of one to four distinct selected
positive-integer columns (for example `['ID']` or
`['object_id', 'term_taxonomy_id']`),
`max_rows`, `max_raw_bytes`, and `mode` (`rows` or `digest`). It observes the
**whole table** in ascending numeric lexicographic identity-tuple order. No SQL fragments, predicates,
callbacks, native parsing or schema assumptions enter this API. Exact driver
strings and SQL nulls remain distinct; `rows` adds bounded payloads to the same
`row_count`, `raw_bytes` and versioned `rows_sha256` witness returned by `digest`.

The hard frontiers are 16,384 rows, 32 columns, 262,144 observed cells, 32 MiB
of aggregate raw bytes, 1 MiB per cell and 4 MiB per transferred batch. Caller
budgets may only narrow them. The initial size query is limited by both the
row and cell frontier, plus one overflow witness. Size admission precedes payload hashing; batches
are tied to exact identities, lengths and hashes, and final readback rejects a
changed roster. The active profile still grants table access and owns the
transaction and statement budget. A descriptor cannot create or widen it.

When a supported public native API reads options internally and accepts no
input array, `ProviderSdk::native_option_inputs($inputs, $native, $context)`
witnesses its actual selected **current transactional** inputs. Declare `provider-native-option-inputs/v1`
with `spec-window/v1`. Each input names exactly `name`, `default`,
`passed_default` and `reads`; the engine reads its own physical raw/plain
expectation. At most 8 names / 32 total reads / 1 MiB per selected value are
admitted; names are canonical ASCII option identities and defaults are bounded
plain data. Special core-transformed/cache-reserved keys are outside this API.
Use it only inside the authorized mutation callback. The capsule must prove
that its initial reconstruction computation precedes its first write; this
API does not certify that ordering or that its rows predate the transaction.
Later fixed-point passes may witness current transactional inputs again.
Fresh observers and uncertain-commit classifiers stay callback-free.

A before/after getter equality cannot prove an intermediate native input.
The scope refuses pre-existing selected/generic option and alloptions hooks,
and checks the **saved pre-isolation** catch-all presence, not the database
gate's masked empty view. It refuses installation/config mode and substituted
or external caches. The standard core cache's public read view permits raw
entry admission without calling a getter that may clone an object. Stale,
decoded, object-shaped or presence-inconsistent selected entries refuse; no
cache clearing or repopulation manufactures a passing premise. Exact terminal
values, defaults, counts, topology and cleanup are verified. A bounded,
argument-free call trace additionally proves the observer was reached directly
from core `get_option()`: a synthetic matching filter pair cannot count as a
native read. `passed_default` is observed on the absent-row path, where core
actually exposes it. A caught refusal
poisons the database boundary so the caller cannot continue to commit.
Native request-local cache warming remains a non-rollback effect. This scope
does not sandbox arbitrary native PHP, bound all its allocations, or prove
unselected dependencies: the capsule must still declare and physically witness
the complete native computation and its unchanged remainder.

For a public native API whose integer-only dependency is `get_post_type()`,
`ProviderSdk::checked_native_post_types($ids, $context)` admits and invokes that
exact getter itself. Declare `provider-native-post-types/v1` with
`spec-window/v1`. It accepts at most 128 unique positive native integer IDs and
returns types (or exact absence `false`) in caller order. It requires the same
authorized mutation callback and existing posts-table read authority. Zero is
not an absence probe: core can substitute the global post.

Cold core reads use `SELECT *`, so admission proves the standard 23-column
posts schema and sizes every field before native allocation: 1 MiB per cell,
8 MiB total. Added/missing columns refuse; the exact bounded column-name
inventory grants no arbitrary schema access. Selected warm cache entries must
be exact inert `stdClass`/final `WP_Post` objects with matching physical
ID/type/raw filter, closed scalar properties and the same allocation limits.
Other bounded cached fields need not equal physical values because the getter
does not consume them. No cache clearing or replacement manufactures a
premise. Standard core getter/sanitizer/cache provenance, current routing and
post-call physical/cache state are checked. The call may warm request-local
cache and a caught refusal poisons the transaction.

This is a **type-only current read**, not a callback witness or full post,
permalink, metadata, registry or historical-cache proof. If an audited native
consumer repeats the getter immediately afterward, the capsule must prove no
intervening callback can change its admitted premise; observers/classifiers
remain physical-only.

Keep plugin eligibility, subset/remainder selection, native reconstruction and
postcondition meaning in the capsule. Do not copy a SQL pager into a provider
or add a permissive SQL/visitor language merely to migrate a legacy helper.
When extraction introduces a shared engine class, follow the
[new-class ownership and generated-file checklist](../dev-setup.md#generated-artifacts-and-architecture):
explicit dependencies and the module assignment are required alongside the
classmap and public API fixture. Package validation alone does not check that
engine/tooling boundary.

For current native links, `ProviderSdk::checked_native_permalinks($ids,
$context)` owns **both** `home_url()` and the complete `get_permalink($id)`
batch. Declare `provider-native-permalinks/v1` with `spec-window/v1`. It returns
`home` and ordered `permalinks` (including exact absence `false`), admits at
most 128 distinct positive integer IDs, and accepts an empty batch for a
home-only read. Both posts/options table authority and the authorized mutation
callback are required; fresh observers and classifiers remain physical-only.

Its current closed native API families are the builtin post-link branch
(including templates/template parts), pages and ordinarily registered custom
types: plain/pretty/index.php links, front pages, hierarchical ancestors,
and the five stock publish/draft/pending/future/private statuses. Private links
require the already initialized anonymous core user, never a user swap or a
capability grant. Category and author tokens, attachments, revisions, custom
statuses and unreviewed relevant hook participants refuse. This frontier is
not a claim that every plugin or permalink extension composes with the reader.

The engine uses the bounded whole-table physical reader for the standard
23-column post roster (8,192 rows / 16 MiB), then admits complete selected and
ancestor cache fields, exact selected physical option/cache bytes, native
registry and request-scheme inputs, and the relevant hook topology. The stock
constant-home filter and, for private links, the three stock capability filters
are reviewed participants. A defined `WP_HOME` is separate immutable
configuration authority and may intentionally override the durable `home`
row; the returned batch uses that effective native home. WP-CLI's stock
`home_url` closure is separately admitted at its exact priority/arity, Runner
source provenance and normalized token identity, without captures, bound
objects or namespace-shadowed functions. Its intentional scheme override
retains the effective option's scheme even under an HTTPS request. This is
not permission to remove the CLI hook, admit other Runner callbacks or accept
foreign URL filters. The complete physical
options table and warm alloptions cache are separately allocation-bounded
(8,192 entries / 16 MiB, 1 MiB per value), including unrelated entries, because
the native loader can load that whole map. Native functions/classes retain core provenance.
An independent native `WP_Rewrite` instance checks current initialization and
selected custom permastructs; it is never installed into globals. The target's
lazy page structure may populate normally but cannot already contain a stale
route. No target cache or rewrite state is cleared, seeded or repaired. The
reader rechecks native inputs and physical rows after the batch and poisons the
transaction on refusal. These are current-input checks, not historical replay,
HTTP routing evidence or a sandbox for arbitrary extension PHP. Each consuming
capsule still owes actual provider, native-consumer and lifecycle evidence.

The native reader's focused harness is
`make regress-native-permalinks-live`, with `NATIVE_PERMALINK_PAIR`,
`NATIVE_PERMALINK_PORT1`, `NATIVE_PERMALINK_PORT2` and exact
`WPRISM_EXPECTED_SOURCE_SHA` required. It owns one MariaDB pair and retains
private concrete URL/preservation records for host admission after cleanup.
Its fixture-only loader join exercises the real SDK and WordPress, not a
Policy-loaded adapter or a capability/readiness claim.

Do not spell an exact presence probe as raw `SHOW TABLES LIKE '$table'` (or
`SHOW TABLE STATUS LIKE '$table'`): `_` and `%` are LIKE wildcards. Bind
`$wpdb->esc_like($table)` through `%s`; the profile gate decodes only that
exact wpdb spelling back to a physical identifier. The closed SHOW grammar
admits only the reviewed table-presence and table-metadata forms (`CREATE
TABLE`, `COLUMNS`, `FULL COLUMNS`, `INDEX`, and the primary-key `KEYS` probe).
A database operand in `SHOW ... FROM database` is never table authority. The
engine also proves a byte-safe `character_set_client` from the server's
`CHARACTER_SETS.MAXLEN` metadata (single-byte sets plus the exact UTF-8
families) and a compatible `sql_mode` before every profile, including a
zero-table profile, so provider code must not issue `SET` or try to establish
its own lexer premises.

Provider DML follows the same rule. Wrap one atomic native operation in
`ProviderSdk::database_write_contract_transaction($context, $write,
$classifyPhysicalPostimage)`. The engine derives the complete read/write
profile, owns the session identity, isolation, transaction controls, rollback,
and ambiguous-commit settlement, and invokes the classifier in a fresh
read-only snapshot only when the commit outcome needs physical proof. The
classifier returns exactly `DATABASE_POSTIMAGE_APPLIED`,
`DATABASE_POSTIMAGE_NOT_APPLIED`, or `DATABASE_POSTIMAGE_UNKNOWN`; partial or
unreadable state is recovery debt. Inside `$write`, use only the SDK's typed
mutation methods (`database_insert()`, `database_update()`, `database_delete()`
or `database_delete_all()`), each
of which rechecks active transaction authority and writable-table membership.
If the plugin operation needs another mutation shape, add that generic typed
operation to the SDK and its engine tests first. Never send raw DML, call
`Db::start*()`, or author `START`/`COMMIT`/`ROLLBACK` in a package executable.
`database_insert($table, $data, $context, $format = null)` and
`database_update($table, $data, $where, $context, $format = null,
$whereFormat = null)` reuse the engine's existing wpdb field codec and return
affected-row counts. A manifest consuming either must declare
`provider-typed-row-mutations/v1` alongside `spec-window/v1`. This requirement
is independent of physical observation: declare only the APIs the capsule
actually uses, before publishing its new digest. Neither feature grants a
table, transaction, predicate or native hook effect.
Update/delete predicates must be nonempty. The capsule
still owns semantic row selection and before/after preservation proofs; these
methods do not infer an upsert key, regenerate IDs, run native hooks or clear
WordPress caches. Reconcile existing derived rows in place when physical
identity must survive retries, then prove the resulting fixed point.

#### Fresh-process capabilities

Use `manifest-provider-fresh-process/v1` only when a site-wide, idempotent
plugin operation depends on a newly bootstrapped WordPress runtime and cannot
be proved in the applying process. The provider remains the owner of the
plugin-specific call and its complete value-level projection. The engine owns
everything reusable: the WP-CLI command, process/session lifetime, canonical
stdin and receipt transport, one absolute deadline, frozen policy authority,
identity revalidation, cache fencing, database isolation, recovery posture,
and retry boundary. Adapter code must not create a process, construct a command,
parse transport, or issue transaction-control SQL.

Failed child transport is private evidence, not an empty generic error. The
shared bounded process lifecycle retains its status and independently named
stdout/stderr through `PrivateEvidenceException`; the provider dispatcher
emits a distinct request-hashed failure document using the existing bounded
Throwable graph. Neither a zero exit nor a failure document can satisfy the
success receipt grammar. Public command refusals and recovery requirements
remain unchanged. The private recorder still owns its field/graph limits,
binary encoding, original byte counts/hashes and explicit truncation markers:
an incomplete diagnostic is not an exact-cause certificate. Do not add a
plugin-owned subprocess logger or publish opaque child output to recover a
missing cause.

When two successful fresh children disagree, the engine retains a private
`wprism-provider-postimage-comparison/v1` record alongside the unchanged
recovery refusal. Each compared value carries its exact PHP-serialized byte
count and SHA-256, preserving the types and key order the strict comparison
used. Up to 1,024 bytes per value are retained as base64; larger values carry
`retained_complete: false` and no byte payload. These are inert diagnostic
bytes, never runtime unserialization input. The bounded record fits the private
Throwable recorder without field truncation. A hash-only omission is not an
exact postimage reconstruction, and the comparison grants no retry bypass.
This belongs in the shared process boundary, not a plugin-owned logger.

The same rule applies to engine-owned native children. `rewrite.flush` uses
the shared rejected-capture helper for unknown exit statuses, warnings and
malformed or invalid receipts, and retains a launch exception privately. Its
reviewed native-error whitelist, receipt grammar and public sentences remain
separate from transport diagnostics. Test raw whitespace and binary streams,
field truncation with original hashes, parser causes and the real CLI private
writer; also prove a rejected post-mutation receipt does not trigger another
native invocation or masquerade as a rollback. Reuse this engine mechanism
instead of putting another child logger in a plugin executable.

Typed engine refusals can retain private causes without changing their class,
code, standard previous chain or operator sentence. The callback-free
`PrivateEvidenceCarrierException` base binds those causes once through final
engine methods; the private recorder must never duck-call a similarly named
method on an arbitrary plugin throwable. The native database boundary retains
its exact rejected SQL and physical-table profile through this channel before
rethrowing the same isolation refusal. Use that evidence to identify a missing
semantic read, not to widen the profile speculatively or publish SQL publicly.
The existing query refusal, poisoned state and rollback authority remain the
gate; diagnostic retention grants no additional table access.

Native route reads include cold-cache dependencies. Rank Math's four-plugin
lane retained WordPress's exact `update_meta_cache('term')` query, exposing an
omitted `termmeta` read. The capsule declares that table and folds all four
metadata columns into its existing bounded dependency projection; that read
admission alone grants no new write authority.
Test both cold and warm cache paths, duplicate and unrelated rows, failed and
oversized reads, and dependency changes during mutation and between independent
boots. A cache-priming read never authorizes a metadata or option write. A
post-commit observer mismatch is recovery debt, not evidence that the completed
mutation or a competing writer rolled back.

Separate native computation from durable evidence. The measured Rank/Woo
lookup also writes two exact transient options, so its native source/edge
expectation and complete edge/count/marker verification run inside the
existing authorized write transaction. The mutation receipt retains its full
native-computation hash and two-pass idempotence proof. Read-only preimages,
ambiguous-commit classification, post-commit readback and the second fresh boot
use only complete bounded database projections; they do not call route,
eligibility, cache-backed option or plugin settings APIs. Rank's factual source
witness includes every post, even inaccessible types, so the observer never
borrows a writer-selected identity list. This explicitly separates two claims:
native correctness was proved at mutation time, while the later observer
proves durable input/output equality. It does not claim to recompute arbitrary
filter behavior in a future request.

Named option effects are still mapped to physical table authority, not SQL
row-key grants. A provider needing a native cache write must declare its exact
cache effects and prove all unrelated option bytes remain unchanged in the
transaction. Do not exclude a cache prefix or use a collation-sensitive SQL
predicate to hide nearby names. The Rank provider binds every option and
non-marker post-meta row and excludes only its two exact reviewed cache names
and one exact marker key. SQL checkpoints cover the database effects; external
object-cache/filter effects retain their explicit non-rollback declaration.
Test cold, warm, partial, expired, repeatedly evicted and external-cache paths,
hostile callbacks in every read-only phase, unrelated-row drift, ambiguous
commits and cross-boot drift. Warming a fixture is not a cache authority model.

`adapter-package-validate` is a static regression guard for that boundary in
package runtime PHP. It refuses known direct process, transaction, raw-DML and
include spellings, but it is not a hostile-PHP sandbox; digest review and
trusted package provenance are the executable trust boundary. A small
path-and-SHA-pinned registry lets unchanged shipped legacy adapters keep
running until their owner is recertified. The sole runtime exception is also
loader-object-, capability-, digest-, API- and exact-statement-bound; it exists
only for WooCommerce's frozen named mutex calls. Debt rows are not authoring
examples or reusable API. If you touch one, migrate it to the named engine
boundary and remove its row. Never refresh the recorded hash.

The manifest must declare both `manifest-provider-runtime/v1` and
`manifest-provider-fresh-process/v1`. On the manifest-sourced provider, add a
sorted, unique `fresh_process_capabilities` list. Every named capability must
exist in `capabilities` and `contracts`, have `scope: "site"`, declare
`idempotent: true`, and fit the engine's fixed timeout ceiling. Its runtime
implements the normal `invoke_<capability>()` mutation plus
`observe_fresh_postimage_<capability>()` and
`project_fresh_postimage_<capability>()`. Keep all three hooks narrowly about
plugin semantics; if another adapter could reuse a line without knowing the
plugin, that line belongs in the engine or SDK.

The engine runs two independent WordPress boots under one deadline. The first
invokes the provider mutation and projects the claimed complete postimage. The
second invokes no provider mutation: it runs the observer callback under the
canonical `$wpdb` read-only boundary and independently projects durable state.
The parent accepts success only when those projections are exactly equal.
Before both boots it flushes the parent's persistent object cache, and each
child revalidates the compiled artifact, adapter digest, shipped disposition,
provider source, plugin lifecycle/version, capability, and authored arguments.
A timeout, parent death, warning on stderr, malformed or noncanonical envelope,
identity drift, or projection mismatch is recovery debt rather than a retryable
success.

That equality proves the durable postimage, not an independent native
recomputation. For reviewed target-local derived display state, such as a
localized label retained by a normally initialized plugin service, keep the
public native consumer and validate its bounded output before writing. A
same-child reconstruction fixed point and the separate physical observer do
not promise identical labels after a later locale/catalog change. Declare that
environmental meaning and test the actual native consumer under the relevant
locales; do not label option rows as physical provenance for an initialization-
time translation. Nor should a provider read private properties, reinitialize
the service or invent a source-literal-only restriction to prove a cross-boot
invariant its contract never promised. Authored/portable values and other
undeclared environment dependencies gain no exemption from this distinction.

The observer boundary is database-specific, not a general PHP sandbox. It
prevents writes only through the canonical `$wpdb` transport and grants only
the tables implied by declared `option:*` and `table:*` surfaces. Filesystem,
object-cache, network, alternate-database, and plugin-bootstrap effects remain
the adapter's declared recovery obligations; never use them as proof. Do not
use cache-backed helpers such as `get_option()` for durable evidence. Read an
option with `ProviderSdk::checked_durable_option()`, which performs an exact,
bounded size/hash preflight, rejects duplicate and collation-alias rows, and
decodes serialized plain data without constructing classes. Put related table
reads inside one SDK-managed consistent snapshot.

For generated files, use `ProviderSdk::filesystem_tree_snapshot()` for a
confined, entry/depth/byte-bounded observation with full second-pass byte
verification. Parse a digest-witnessed PHP return-literal through
`ProviderSdk::php_literal_data()`; never include a target-generated index
to inspect it. A plugin's public reconstruction/deletion API remains plugin
semantics, covered by its declared irreversible filesystem effect and exact
postcondition tests. The observer is not a mutation lock or a rollback
promise. A future generic filesystem mutation API needs declarative path
authority and engine-discoverable durable recovery, not just an opaque
`provider_resource` token.

For one optional regular file, use
`ProviderSdk::filesystem_file_snapshot($containmentRoot, $canonicalPath)` and
declare `provider-filesystem-file-snapshot/v1` with `spec-window/v1`. The path
is relative to an existing directory root; every parent must exist without
symlinks below that root. The result contains `path`, `state` (`present` or
`absent`), and `file` (null when absent; otherwise `bytes`, `mtime`, permission
`mode`, and `sha256`). An empty file is present. The engine reads the parent
roster twice, checks exact spelling and path identities, and reuses the tree
reader's two byte passes. Missing or unreadable parents, aliases, nonregular
leaves, symlinks, observed races, and files over 16 MiB refuse. Paths are bounded
to 4,096 bytes and 128 components; parent rosters retain the existing
100,000-entry limit. Sibling file contents are not read. Observation grants no
file write, lock, restoration or native-callback authority.

Visual Portfolio 3.8.1 exposed this distinction: its deferred rewrite method
calls WordPress's default hard flush, which may create an absent `.htaccess`.
The existing tree reader can witness a present file but refuses an absent
root. Do not catch that refusal and invent an empty hash. An optional file
witness is evidence for the adapter's separately declared filesystem effect;
it does not make that effect reversible or qualify archive/settings Apply.

A fresh-process package test must cover more than the happy child exit. Exercise
distinct mutation and observer process identities, idempotent replay, exact
postimage mismatch, a poisoned parent cache before each boot, and cache-flush
`false`, non-boolean, and throw outcomes before both phases. Also cover DML and
transaction-control refusal in the observer, malformed/noncanonical transport,
deadline expiry, parent death with descendants, source/digest/disposition/plugin
identity drift between negotiation and execution, and the recovery-required
result when mutation completed but observation did not. The real-process suite
must prove these through separate PHP boots; fakes alone cannot establish the
fresh-runtime claim. Include at least one participant-declared composition
scenario when another adapter can share the same trigger, table, lifecycle, or
plugin-incompatibility boundary.

### Schema settlement is a host deploy phase

Strict target observation never invents a table that the target does not
already have. If a supported plugin creates an authored table only after a
module is enabled, declare that prerequisite with `schema-settlement/v1`; do
not make observation fabricate an empty table, hide the absence in an apply
provider, or overload lifecycle settlement with DDL.

A schema action is a provider action with the exact phase-specific members
below. Rank Math's declaration is the worked example (abridged to one table):

```json
{
  "args": [],
  "capability": "prepare_schema",
  "effects": [
    {
      "id": "rank-math-schema-redirections",
      "kind": "database",
      "mode": "restorable",
      "selector": {
        "scope": "database_checkpoint",
        "type": "table",
        "value": "rank_math_redirections"
      }
    }
  ],
  "kind": "provider",
  "phase": "schema_settle",
  "prepares": ["rank_math_redirections"],
  "provider": "rank-math-state",
  "readiness": "inspect_schema"
}
```

The manifest must declare `schema-settlement/v1`. `args` is exactly `[]`;
`triggers` is forbidden; `prepares` is a non-empty, sorted, duplicate-free list
of tables declared by that manifest; and `readiness` names a second capability
on the same provider. When the provider declares manifest-owned contracts, the
readiness contract has `args: []`, `idempotent: true`, `scope: "site"`, reads
exactly `table:<name>` for every prepared table, and writes nothing. The
preparation contract is also argument-free, idempotent, and site-scoped; both
its `reads` and `writes` lists equal those same prepared table surfaces. These
manifest-owned facts are rejected offline rather than deferred to a live
provider. Plugin-sourced providers advertise independently, so the identical
contract is enforced during live negotiation. Every prepared table has exactly
one matching effect: `kind: "database"`,
`mode: "restorable"`, and a `database_checkpoint` table selector. Two pinned
manifests cannot both acquire schema-settlement authority over the same table.

This phase runs only through host `wprism deploy`; direct `wp wprism deploy`
refuses it. The host checks plugin lifecycle and schema readiness without
mutating, then takes and authenticates the exact database checkpoint. Before
the first lifecycle/provider mutation, the host publishes an external ordered
provider intent covering every applicable phase (for Rank Math,
`lifecycle-retire`, `lifecycle-activate`, `schema-settle`, and
`lifecycle-settle`). When code staging is required, it precedes that intent and
uses its own checkpoint/session receipt. Each fresh lifecycle/provider phase
then runs under the existing durable promotion session, advancing the intent
atomically after successful completion. An
interrupted or failed phase leaves visible recovery debt that fences ordinary
policy loads and host mutation (including adopt, unadopt, and checkpoint prune)
until recovery restores the bound checkpoint. Recovery admits only the exact
retained checkpoint named by that debt; a retry cannot silently continue from
an unrecorded phase or select a different signed/retained row.

Exercise that recovery path, not only the failing provider call. Inject one
preparation failure after the checkpoint and durable schema intent exist, run
host `wprism recover` for the exact retained id, and prove the pre-checkpoint
plugin state plus both database-local and external debt are restored exactly.
If that fixture changes an authored value to manufacture its pre-checkpoint
state, establish the change through capture, commit, and apply. A direct target
edit is ordinary drift after recovery, and the product must refuse to overwrite
it rather than letting the fixture disguise that refusal as failed recovery.
An offline boundary fixture must feed recovery the bytes the agent actually
writes: `SchemaSettlementIntent` persists `Canon::encode()` output (sorted,
pretty JSON with one trailing LF), while recovery's own control records use a
different compact canonical codec. Constructing the fixture with the consumer
codec can make an impossible byte shape pass offline while every real recovery
refuses before database reset.

The checkpoint is bound to the database selected before mutation. The host
asks the isolated control plane for a credential-free digest of `DB_HOST`,
`DB_NAME`, and `$table_prefix`, makes the isolated `db export -` process recheck
that digest, and authenticates it as the first encrypted checkpoint record.
Recovery authenticates the complete ciphertext and compares the current
wp-config target before aborting or acquiring any lease; recovery begin,
reset/import, schema settlement, and lifecycle settlement recheck the same
digest in their own processes. External intents retain only the digest, never
database coordinates or credentials. A checkpoint created before this target
binding existed refuses honestly instead of restoring through a compatibility
fallback.

The readiness and prepare capabilities return `before` and `after` maps keyed
exactly by `prepares`. A readiness row is `{present, schema_hash}` and must be
unchanged across the read-only call. A prepare row is
`{present, schema_hash, row_count, rows_sha256}`. An existing table must remain
byte/content/structure identical. A previously absent table must become present
with `row_count: 0`: schema settlement is create-only and may not seed authored
rows. If durable plugin identity or state proves that a missing table once
existed, readiness refuses before the lease and checkpoint instead of treating
loss as an installation opportunity.

`rows_sha256` is a complete witness, not a sample. A multi-query witness must be
one coherent repeatable-read snapshot and must refuse a storage engine that
cannot provide it. Providers for tables with unbounded payload columns must
first enforce database-side row, per-row-byte, and total-byte limits, reapply
the per-row bound inside every value-bearing query, then hash deterministic
fixed-size primary-key keyset chunks whose worst-case page size is intentional.
Do not materialize the whole table to count or hash it, and do not cap a query
in a way that makes rows beyond the cap invisible. The
[`rank-math-state.php`](../../adapter-packages/rank-math/package/runtime/providers/rank-math-state.php)
provider demonstrates the bounded implementation and its package-local suite
proves mutation beyond the first chunk changes the witness.

### Declaring a plugin incompatibility

When two plugin adapters describe independently valid state models that cannot
safely coexist—such as competing SEO suites claiming the same conceptual site
authority—declare the boundary rather than choosing a winner by pin or plugin
load order. The declaring manifest opts into `plugin-incompatibility/v1` and
adds `incompatible_plugins`, a non-empty, sorted, duplicate-free list of exact
WordPress plugin basenames:

```json
{
  "engine_features": ["plugin-incompatibility/v1", "spec-window/v1"],
  "incompatible_plugins": ["wordpress-seo/wp-seo.php"],
  "plugin": "seo-by-rank-math/rank-math.php"
}
```

Only a plugin-owning manifest may declare the section, and it cannot name its
own basename. One side's declaration is sufficient: if any pinned manifest
claims the named plugin, the shared policy finalizer emits the same refusal in
either pin order before compilation, capture publication, promotion leases,
lifecycle hooks, or providers. This is a non-surface compatibility constraint,
not an operator composition override; the remedy is to pin only one adapter.
Canonical `active_plugins` is checked during repository authorization as well:
omitting the competing adapter pin cannot authorize its active plugin. Include
that one-pin case in the refusal evidence. The guard reads desired state, so a
compatible repository can still deactivate a conflicting target plugin.
Use a participant-declared integration scenario to prove both orders against
the exact supported plugin artifacts.

An adapter needing no executable semantics declares neither key and stays purely
declarative. Most should. For worked examples,
[`adapter-packages/woocommerce/package/manifest.json`](../../adapter-packages/woocommerce/package/manifest.json) pairs a
manifest-sourced provider with a triggered, effect-declaring native
`transient.delete`, and the
[`adapter-packages/wprism-agency-cpt/package/manifest.json`](../../adapter-packages/wprism-agency-cpt/package/manifest.json) fixture
shows a plugin-advertised one.
