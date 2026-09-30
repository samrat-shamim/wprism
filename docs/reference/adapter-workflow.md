# Detailed adapter authoring workflow

Start with [the adapter authoring guide](../guides/adapter-authoring.md). This page is the detailed reference for this part of the workflow.

## The authoring loop

### The one-prompt coding-agent contract

A normal user prompt can be as short as: “Author and production-harden an
adapter for `<plugin>` from the exact installed release.” The coding agent
should complete the loop without asking the user to classify individual keys.
It owns the reversible evidence work: inspect the official artifact, complete
and verify the plugin's public onboarding lifecycle, exercise every reachable
native writer, run observation/coverage/probe/draft, ratify the smallest
defensible manifest, add adversarial offline and live tests, and run the
repository gates. It records commands, visible outputs, corrections and
assumptions; it does not record private chain-of-thought.

Verify every plugin-owned writer triggered by the visible Save action. A
successful core REST post update or `core/editor.savePost()` does not prove
that a plugin's editor state was flushed. Qi Blocks 1.5.2 attaches a debounced
style save to the editor Save-button click: the core save API persisted all
block comments while leaving `qi_blocks_global_styles` empty. Clicking the
actual Save button persisted the styles and changed frontend rendering. Trace
such event handlers, wait for their responses, and retain raw owned-row
readback plus frontend behavior. If a native request fails while a sibling
request succeeds, record both outcomes; do not invent the missing state or
claim the whole settings form saved successfully.

Continue autonomously when a command already supplies a typed recovery. In
particular, narrow an over-broad or under-broad `--match`, use `--force` after
the create-only draft output names that remedy, seed representative rows when
a probe reports an empty keyspace, and keep an unsupported surface
outside the disposition until its missing primitive exists. Use runtime/env
only when the native storage semantics justify that classification. Escalate only when progress needs new
authority or information the repository and target cannot supply: a paid
artifact or credential, permission to exercise an external server/CDN/service,
destructive deletion authority, a production-only mutation, or two plausible
product meanings whose choice changes what WPrism will overwrite. “I have not
read enough plugin code yet” is not an escalation reason.

**The target needs Git before step 1 runs.** `wp wprism init` refuses
`unsupported: repository git — Git is unavailable on the target that owns the
site repository` on a stock `wordpress:cli` image; that image has no Git
installed, and nothing below tells you so until init already refused. Install
Git on the target first (`sandbox/tests/lib/grind_lib.sh:744`'s
`init-cli.Dockerfile` build is a working reference for what the image needs).

**These six steps are not the whole command sequence** — two verbs run
*inside* the loop without a numbered step of their own. `wp wprism adapter-probe`
answers the live schema questions a draft's `questions` field names (see
[Answering the draft's live questions](#answering-the-drafts-live-questions)
below); it runs between Draft (§3/§4b) and Pin (§5), because a probe reads
what a drafted table looks like on the live target. `wprism adapter boundary`
bisects the `version_range` these steps write at Export/§4b (see [Finding the
two versions the range names](#finding-the-two-versions-the-range-names)
above); it has to run before that range is ratified, not after. Before
authoring from nothing, it is also worth asking whether an adapter for this
plugin already exists somewhere you can install from: `wprism adapter discover`
reads a published index and tells you, without writing anything (see
[Planned: what an adapter cannot express yet](adapter-constraints.md#extension-channels-and-remaining-limits)
for `discover`/`install`/`update`). Running this loop across many adapters
at once — ranking which plugin to write next, re-measuring whether it moved
the fleet's coverage — is [coverage-cohort.md](../guides/coverage-cohort.md)'s job, not
this page's; its 9-step loop is where probe, boundary, the kit and `wprism
census` all sit inside one ordered sequence.

**A sequencing trap between init and Propose.** If you already ran `wprism init
--allow-unmanaged-plugins` (the S1 unmanaged-plugin posture — see [the caveat
that catches everyone](#the-caveat-that-catches-everyone) above) before
starting this loop, it already wrote
`policy.scope.<kind>.<name> = {"class": "runtime"}` into `site.wprism.json` for
every unmanaged plugin's rowful post types and taxonomies. By the time you
reach §2 below, `wprism pending` shows **nothing** for those types: a surface
with a recorded scope decision is no longer "no scope disposition", which is
exactly the condition `wprism pending` looks for. This is not a bug to work
around — it is init doing its job — but it means the review queue will not
hand you the CPT or taxonomy you are writing the adapter *for*. Read `wprism init
--format=json`'s `unmanaged_scope_left_local` advisories (or the host
renderer's `UNMANAGED SCOPE …` lines) for what init already decided instead.

The same onboarding boundary applies to stored widget families. A populated
family with no selected `widgets{}` grammar and no active sidebar assignment
receives an exact `policy.options.widget_<type> = {"class":"runtime"}` rule,
shown as `UNMANAGED WIDGET` before confirmation. Marker-only families get no
decision. Native settings and inactive assignments remain local; init does not
invent widget identities, remove instances, or infer references from their
values. A selected non-local classification without widget grammar still
blocks instead of being downgraded. Once the adapter declares the widget type,
the structural widget grammar owns portable instances. Repeat that scope review
after native settings writers: WordPress's Customizer can turn an empty legacy
widget option into a numbered, unassigned instance. A conformance seed that
creates its site configuration directly must prove the inactive/unassigned
premise and record the same exact local decision through `wprism classify`.
Retain native before/after bytes; do not put another component's widget scope in
the plugin manifest or erase the instance to bypass capture.

An **active** assignment is different: its complete sidebar is an authored
layout, and excluding one widget option cannot make a partial layout safe to
publish. Init therefore reports `undeclared_active_widget` before confirmation,
even with `--allow-unmanaged-plugins`. Install the reviewed widget declaration,
or deliberately remove its active assignments in WordPress before requesting a
fresh proposal. Do not remove native widgets merely to turn an evidence run
green; when testing this boundary, retain the refused layout and prove both
readiness and Capture preserve it. WPForms reconnaissance exposed the previous
false-ready proposal; `regress-init-widgets` exercises the actual planner,
bounded native reader and unchanged Capture guards.

A completed baseline is not yet a clean environment. Host `init` can report
both canonical and separate code baseline commits, then exit 1 because required
`admin_email`, `home` or `siteurl` bindings have not been provisioned. Preserve
those baselines; inspect the complete plan, provision the driver's deliberately
chosen values through public `env-set --stdin`, and require a fresh host
`status` to be clean before proceeding. Do not rerun initialization, infer
intent from whatever values happen to be installed, or accept the nonzero
handoff as a successful status check. Disposable conformance uses
`establish_core_environment_bindings` as described below. WPForms reconnaissance
exercised this exact baseline → explicit provisioning → clean-status sequence;
it is distinct from a failed baseline publication or uncertain recovery.

For a failed **fresh** initialization, `.wprism/refusals/` is intentionally
absent: creating that directory would violate first-init compensation. Preserve
any sealed `.wprism-init-attempt` evidence and use a reviewed human-mode target
rerun to inspect the diagnostic; the host's redacted envelope is not the cause.
An interrupted or uncertain publication is a recovery decision, not permission
to retry blindly. See [the CLI refusal contract](../../cli/README.md).

### 1. Observe

Exercise the plugin on a real environment — create the entities through the
plugin's *own* admin code path, not by hand-writing postmeta, because the
whole point is to learn what the plugin actually writes. The provenance journal
records those writes; `wp wprism journal-report --manifests=<names>` aggregates
them and scores proposals against the manifests you already have.
`wp wprism journal-reset` truncates the journal when you want a clean observation
window for one specific interaction. It warns with the number of rows it is
about to destroy first: options no adapter declares are recorded nowhere else,
so those rows leave `wprism pending` permanently. It is not a prerequisite for
`wprism init` — observations are not ledger identity, and a site journalling from
first boot initializes with them intact.

Note what the journal will *not* do: a bare authenticated write never proposes
`authored`. Proposals come from evidence and are deliberately conservative.

Journal rows are provenance, not live schema — they tell you what the plugin
*wrote*, not what its tables *look like*. For the latter, `wp wprism adapter-probe`
(below) is the Observe-phase counterpart once you know which tables to ask
about: it needs no `--repo` and reads the target's live `SHOW COLUMNS`/`SHOW
INDEX` directly, so it is usable this early even though its answers are most
useful once a draft's `questions` name exactly what to probe.

### 2. Propose

```sh
wprism pending dev
```

The review queue shows unclassified meta on in-scope entities, entity types
with live rows and no scope disposition, and journal-observed unclassified
options. Each item carries whatever evidence exists — entity counts, journal
surfaces, a ref-hint, a secret flag — and **never a guessed classification**.
An item with no evidence for a proposal prints `-`. (If a type you expected
here is missing, re-read the sequencing trap above — it may already be
decided.)

### 3. Draft

```sh
wprism classify dev
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
- **Clearance is per row.** Put `allow_secret=true` or `allow_pii=true` inside
  only the reviewed row of a joined value. The legacy `--allow-secret` and
  `--allow-pii` flags remain single-row shorthand and refuse a multi-row set.

Both apply whenever you drive `wp wprism classify` directly. `wprism classify` builds
the joined value for you.

### 4. Export the site-local rules into a manifest

```sh
wp wprism policy-to-manifest --repo=<path> --match='^wpcf7_' --name=contact-form-7
```

`--match` is a PCRE body without delimiters, tested against each key; `--name`
becomes the manifest's `name` field. This exports **only site-local policy
rules** — the ones you just classified into `site.wprism.json`. It is the
promotion path from "one site decided this" to "the library declares this",
and it is deliberately one-directional: nothing reads a manifest back into site
policy.

Move the emitted JSON into `adapter-packages/<name>/package/manifest.json`, add `plugin`,
`version_range`, and the evidence notes by hand, and drop the now-redundant
site-local rules from `site.wprism.json`. Run
`php tools/adapter-package-validate.php --adapter=<name>` and
`php cli/wprism manifest-validate . --manifest=<name> --pins=core,<name>` on the result before going
further — see [Checking the grammar offline](adapter-validation.md#checking-the-grammar-offline);
the hand-added parts are exactly the ones no export path checked.

### 4b. Or start from a draft, when there are no site-local rules yet

`policy-to-manifest` promotes rules you have *already classified*. For a plugin
nothing knows about yet, there are none — so `wprism adapter-draft` proposes them
instead, from the repository's captured `state/**` plus, with `--seed`, from
what `wprism coverage` saw on the live site:

```sh
wprism coverage prod --format=json > coverage.json
mkdir -p <site-repo>/adapters
wprism adapter-draft <site-repo> --name=wpforms \
  --match='^_?wpforms_' \
  --seed=coverage.json --out=<site-repo>/adapters/wpforms.json
```

`adapter-draft` will not create the output directory: create `adapters/`
before the first `--out` run. Scope a coverage seed with `--match` whenever
you are authoring one plugin. Coverage intentionally reports every invisible
option family and undeclared table on the site; without that filter, unrelated
WordPress and plugin surfaces are valid candidates and can bury the adapter's
own review questions in a very large draft. A later scoped `--force` drops
unchanged, unratified machine proposals that no longer match while preserving
ratified or edited candidates.

The filter is applied to the names the input document actually contains. For
an undeclared table that is the unprefixed logical table name. For invisible
options, coverage intentionally publishes an ownership **prefix** such as
`redirection`, not every option name beneath it. Therefore an exact-looking
filter such as `^(redirection_options|redirection_(groups|items))$` can select
the tables and honestly select no option proposal. Inspect `_draft.seed` and
the candidate counts; if the plugin option family is missing, rerun against
the reported prefix (for example `^redirection`) with `--force`. A pre-existing
output refuses with `draft_output_exists` instead of guessing whether to
overwrite human edits; that named refusal is sufficient authority for the
coding agent to retry with `--force`, because regeneration preserves edited or
ratified candidates.

Why `--seed` earns its place: the offline proposers read `state/**`, so they
can only see surfaces WPrism **already captures** — and the surfaces you are
writing an adapter *for* are exactly the ones it does not. An option prefix
invisible to every installed adapter, and a live table no manifest declares,
are invisible to the draft generator and plainly visible to `wprism coverage`.
`--seed` turns each into a candidate with coverage's own observation quoted:

| coverage finding | proposed as |
|---|---|
| `options.invisible_groups[].prefix` | an `option_namespaces` match **and** an `option_patterns` rule |
| `tables.undeclared[]` | a `tables.<logical_name>` declaration |
| a scope-gate-refused post type | a `post_types.<name>` declaration |

The third one needs the richer seed: `wprism coverage` reports options and tables
and nothing else, so pass a `wp wprism assess-inventory --format=json` document
instead when you want post types too. The draft records which families its seed
actually supplied in `_draft.seed`, so a short draft is never a silent one.

`options.invisible_groups[].prefix` means **genuinely undeclared** — no rule
from any source, exact or namespace or pattern or dynamic prefix. A family your
adapter (or any pinned adapter) already declares by name is no longer seeded,
so `--seed` stops proposing an `option_namespaces` claim plus a blanket
`runtime` `option_patterns` rule over options half of which are already
`authored`. What it proposes is what nothing models yet, which is the only
thing a new adapter is for.

**Every seeded candidate is `runtime`, and that is a default, not an
observation.** An undeclared table is one WPrism has never read a row of; calling
it `authored` on that evidence would put live operational rows into your
repository. What `runtime` buys is honest: the surface becomes *declared and
excluded* instead of reading `unclassified / block` in assess. Promote the
parts that really are authored configuration by hand — and then their columns,
primary key and identity are live facts an offline draft cannot supply, which
is what each candidate's named `questions` say — and what `wp wprism
adapter-probe` answers, below.

#### Answering the draft's live questions

The questions a candidate carries are **named** — `[table_schema]`,
`[natural_key_uniqueness]`, `[lock_index]`, `[foreign_keys]`, `[eav_twin]` —
because one command can answer them. `wp wprism adapter-probe` runs on the target
and reports, per table, the real PRIMARY KEY, every column's MySQL type and
nullability, unique keys, per-column index coverage in the deletion guard's own
terms, declared foreign keys, an EAV twin, and natural-key uniqueness as one
`COUNT(*)` vs `COUNT(DISTINCT …)`:

```sh
wp wprism adapter-probe --tables=wpforms_tasks_meta,wpforms_payments \
  --natural-keys=wpforms_payments.transaction_id --format=json > probe.json
wprism adapter-draft <site-repo> --name=wpforms --evidence=probe.json \
  --out=<site-repo>/adapters/wpforms.json --force
```

`adapter-probe --format=json` emits one formatted JSON document. In a live
shell harness, use the shared `capture_wprism_json_document` helper: it keeps
stdout separate from transport stderr, accepts compact or formatted objects
and arrays, and refuses runtime diagnostics, extra stdout or a nonzero exit
before publishing the value. The compact-envelope helper is for commands that
emit their answer on one line after action receipts. AIO Login authoring reached
the probe successfully but its former last-line reader rejected the closing
brace; flattening or discarding that output would have hidden the actual schema.

Note what that example gives you on a **freshly activated** plugin, because it
is the state you are most likely to run it in. A plugin creates its tables at
activation and fills them only through use, so every one of the six
`wp_wpforms_*` tables exists and holds **zero rows** — and a natural key over an
empty keyspace measures nothing. `natural_key` comes back
`{"rows": 0, "distinct": 0, "unique": false}`, and that `false` is the absence
of an answer rather than a duplicate. The human summary says so in as many
words (`natural key wpforms_payments.transaction_id: 0 row(s) — nothing to
measure yet`); exercise the plugin until the table holds real rows before you
ratify anything from that line. `--tables=` never takes the site's table
prefix, and a table reported `absent on this target` is a name, prefix or
not-yet-activated question — never a "no rows yet" one. This verb also takes no
`--repo`: unlike `wp wprism coverage`, `pending`, `lint` and `adapter-observe`, it
reads the target's live schema and owns no repository.

Each fact lands as an `evidence[]` row at confidence 1.0 naming the question it
closes, and **nothing else moves**. If the live PRIMARY KEY is not the column
the offline proposer guessed, the guess still stands in the fragment and the
disagreement is stated beside it — the probe declares `authority: false`, and
`adapter-draft` refuses any document carrying a word outside the closed probe
vocabulary, so it cannot classify anything on your behalf. Read the rows, then
ratify by hand. The document never carries a row value: enum/set member lists
are reduced to their base type word for the same reason.

The probe is intentionally rowless, so it cannot answer a different question:
how values inside a text/blob column are framed. `SHOW COLUMNS` may say
`mediumtext` while native writers store a plain string for one action, a PHP
serialized map for another, and SQL `NULL` for a third. Exercise every native
writer variant in the admitted release and compare the raw stored bytes with
the plugin API's readback. Declare strict `php_serialized` only when every
authored value is one canonical serialized container. When one column is the
measured plain/serialized/NULL union, declare
`{"container":"php_serialized_or_text","leaves":"text"}` and the explicit
`mixed-column-codecs/v1` feature; malformed serialized-looking bytes and
non-string/non-NULL values must refuse. Redirection 5.9.0 is the reference
manifest and `regress_column_codec_grammar.php` is the mutation proof. Never
infer a codec from the first populated row or from the SQL type.

For a stored JSON container, declare `{"container":"json","leaves":"text"}`
and `json-column-codecs/v1` alongside `typed-column-codecs/v1`. Compare native
Save bytes with a decode/default-encode round trip first: this strict codec
refuses any shape or spelling the shared associative decoder cannot preserve,
including empty objects, duplicate keys, numeric-key objects that become lists,
and alternate whitespace or escaping. It admits empty lists. Capture, compiler
and Apply must agree on framing; decoded keys also participate in clearance.
A container codec grants no clearance exception. Any existing exact reviewed
`allow_pii` or `allow_secret` rule still needs independent semantic justification.

This framing only reaches ordinary text leaves. A JSON field holding a native
user ID still needs an explicit reference contract; a CSV URL still needs a
verified target-local input. The user/customer importer 2.7.5 native template
Saves demonstrate both demands, so JSON framing alone does not qualify its
templates. Run `make regress-json-column-codecs-native` with exact
`WPRISM_EXPECTED_SOURCE_SHA`; the harness owns its disposable pair and checks
native Capture, Apply, repeat, recapture, rollback and foreign-row preservation.

Use `typed-column-values/v1` for semantic values inside a strict JSON or PHP
serialized column. Declare `{container, value}` instead of `{container, leaves}`;
`value` reuses authored `object_fields`, `enum`, scalar/list `ref`, structured
`json_refs`/`key_refs`, and `plain_data`. Every reference leaf must declare
`on_unmapped: "refuse"`. Prove the native scalar type and use `cast: "string"`
when the plugin stores ID strings. Record projection requires its separate
column feature below; encoded text and mixed scalar framing remain unsupported.

For example, importer 2.7.5 stores selected users under
`filter_form_data.wt_iew_email` as string IDs. Declare exact enclosing objects
and a `ref: "user[]"` leaf, then prove different source/target IDs with the same
logins and a missing target login. Capture and Apply use the existing user
binding codec; this never grants user migration. The compiler checks canonical
reference shape without resolving environment bindings. Unknown object members
refuse, so adding a native field requires a reviewed declaration change.

Validated scalar/list reference leaves are excluded only from the PII subject;
secret clearance still inspects every original decoded key and value. All
remaining fields and ancestor key roles retain ordinary PII checks. Do not
classify contact data as a reference to obtain clearance: native values must be
strict IDs and canonical values must be tokens in that declared keyspace.
Run `make regress-column-value-contracts-native` with the exact source SHA;
it proves native typed rows and shared Gutenberg values at divergent user IDs.
A template's local CSV input still needs a separate target-binding contract.

Field definitions need their own semantic evidence. Importer 2.7.5 Saves
`user_email: ["user_email", 1]` in its field map and
`user_email: "user_email"` in its selected-column labels. These are labels,
but ordinary PII key-role scanning interprets them as contact values. With
`column-field-labels/v1`, declare the exact metadata leaf as
`{"class": "authored", "field_labels": "label_enabled"}` or use `"label"`
for a field-to-string map. Compose these leaves under `object_fields`; do not
repeat a rule for every plugin field or grant whole-column privacy clearance.

Prove that native producers and consumers use every map entry as a field code
and label, including custom fields. This declaration supplies that semantic
authority: shape validation alone cannot distinguish a person's name from a
display label. It admits only nonempty string keys, string labels and, for
`label_enabled`, a two-element tuple with integer 0 or 1. Empty maps are allowed;
unknown formats, extra tuple entries and coerced flags refuse. All stored key
and value bytes still undergo value-based privacy checks; enclosing roles and
full original secret checks remain. Options, metadata and block values cannot
borrow this column feature. Run the column-value offline suite and its native
lane; template enrollment and input-file availability need separate evidence.

Use `column-record-fields/v1` when a stored record mixes authored fields with
proven regenerable context. Add `record_fields` to the existing value leaf:

```json
{"class":"authored","plain_data":true,"record_fields":{
  "container":"object","fields":["method_export","mapping_enabled_fields"]
}}
```

Importer 2.7.5 Save As retains the previous template's `selected_template` cursor.
Normal reopen selects the requested row; the wizard rebuilds the cursor on its
next step. Native Save/reopen and identical CSV output after projection establish
that it is regenerable context. Prove this with the plugin consumer before
excluding any field. Projection does not merge target-local preimages or grant
file transport, and this declaration alone does not qualify saved templates.

The existing `RecordFields` machinery handles one object or a list of records,
including beneath `object_fields`. It retains immediate field order, absence,
types and list duplicates. Every record must retain at least one declared field;
an empty list is valid. Canonical excluded fields refuse. Structured reference
paths must start with a retained exact field; scalar references cannot compose
with projection. Column clearance still scans the original decoded data,
including excluded fields. Run the column-value offline suite and native lane.

When retained members need different codecs, compose `record_fields` with
`object_fields` using `object-record-fields/v1` and the existing surface value
and record features (`typed-column-values/v1` + `column-record-fields/v1`, or
`block-value-contracts/v1` + `block-record-fields/v1`). For example:

```json
{"class":"authored",
 "record_fields":{"container":"object","fields":["mode","input"]},
 "object_fields":{
   "mode":{"class":"authored","enum":["local"]},
   "input":{"class":"authored","input_file":{"directory":"imports","extensions":["csv"]}}
 }}
```

The input leaf additionally requires `column-input-files/v1`; it does not gain
block authority from this composition. Retained names must equal the typed
field set. Lists cannot compose with exact object fields. This keeps exclusion
and typing explicit while reusing the existing projection and child codecs.
Native extra fields still undergo whole-value JSON bounds and privacy checks;
canonical extra fields refuse before any target binding. Missing children stay
absent. A functional input field must be rebound, not dropped together with a
temporary wizard cursor. Run `regress-object-record-fields` and the relevant
native consumer lane before enrolling the adapter's complete saved form.

When one physical column has different meanings in different owned row types,
use `column-value-cases/v1`. Keep one strict container and replace `value` with
`value_cases: {column, cases}`. The selector names a declared `row_scope` set;
each `{equals, value}` case supplies its existing authored contract. Enumerate
every set member exactly once in byte order. Case lists preserve numeric string
values through JSON loading. There is no default, and every arm receives full
shape, feature, keyspace and shared-budget validation.

For importer 2.7.5, export field maps contain labels while import maps contain
expressions and literal values. They share `data.mapping_form_data` but cannot
share a field-label privacy declaration. First prove the native row selector;
then give each variant a truthful contract. This mechanism selects contracts;
import-expression semantics and target-local CSV availability still need their
own support. Test wrong-variant payloads, attempts to borrow a privacy role,
unknown selectors, both framing types, and owned variant transitions through
Capture, immutable compilation, lint, checked Apply and recapture. The shared
column-value native lane exercises selection on real typed-table writes.

Use `column-field-templates/v1` for a proven brace-expression map. Declare
`{"class":"authored","field_templates":"brace"}` for selected strings or
`"brace_enabled"` for exact `[expression, integer 0 or 1]` definitions. This
avoids repeating rules for every native field while keeping header references
separate from literal destination values. It composes beneath `object_fields`
and the appropriate import `value_cases` arm; export labels retain their own
contract. Do not classify import expressions as field labels.

When the native consumer requires a functional member, also negotiate
`column-field-template-requirements/v1` and use the compact object form. A
`brace` map declares `{"format":"brace","required_nonempty":["field"]}`;
a `brace_enabled` map declares
`{"format":"brace_enabled","required_enabled":["field"]}`. The lists are
bounded, sorted and exact. Capture, compilation and Apply all refuse a missing
or empty required expression; `required_enabled` also refuses a native flag
other than integer `1`. This asserts only the member contract established by
the native producer and consumer. It does not add a default or synthesize an
expression. Importer 2.7.5 uses it for `user_pass`: the plugin otherwise saves
a disabled mapping and later warns twice while merging an existing user.

Prove the producer's persisted tuple shape and consumer's exact brace dialect.
Importer 2.7.5 Save sanitizes definitions to two slots; its consumer substitutes
nonempty brace bodies, trims header names and interprets date/arithmetic syntax.
The engine preserves those bytes and leaves interpretation to the plugin.
Canonical text/field fragments disambiguate native braces from WPrism tokens.
Only literals receive URL transport; a URL-shaped CSV header stays unchanged.
Every stored byte still receives value-based clearance, and joined literals keep
the destination's PII and credential roles, including disabled definitions.

Test empty, unmatched and nested braces, adjacent references, whitespace and date
annotations, source/target URLs, disabled definitions, literal private values,
malformed canonical edits, graph references and whole-value bounds. A numeric
post-query prefix followed by a field, such as `?p=4{Suffix}`, refuses: the
native result could name post 41, so post 4 cannot be bound independently. The
same refusal covers intervening literal bytes such as `?p=4%{Suffix}`: a field
value of `31` completes an encoded digit.
Complete delimiter-terminated static query values retain ordinary rebinding. Run
`regress-column-field-templates` plus the capsule's native expression transport
lane, which calls the actual registered consumer after real typed-table writes.
That bounded evidence does not exercise an import job or provide its input file.
A remembered CSV path is functional state: do not project it away to make an
otherwise unsupported import template fit the declaration.

Use `column-input-files/v1` for a functional file pointer that each environment
must provide independently. Declare the exact nested field with
`{"class":"authored","input_file":{"directory":"webtoffee_import","extensions":["csv"]}}`
inside its typed column value contract. Capture records only dependency presence;
an empty draft remains empty. Plan derives the binding name from the canonical
row and field, and the operator provisions one filename in the declared content
directory through `env-set`. Apply binds that target file through the ordinary
typed writer. This feature supports ordinary mapped and natural-key rows, not
composite identities or a whole-column input leaf.

Prove the native producer's URL spelling and the actual consumer's allowed
extensions; an upload dialog's list can be broader than the import consumer's.
Do not treat remembered input as regenerable noise, transport customer CSV bytes,
or add a plugin executable to choose paths. Test missing and replaced files,
unsafe paths, empty drafts, canonical edits, rebinding after intent changes,
drift, scope, repeat Apply and rollback. Run `regress-column-input-files` and
`regress-column-input-requests`, plus the capsule's native input lane. The private
intent lock protects Apply from cooperating provisioning; point-in-time file
availability does not promise a later import job will still find identical bytes.

For scoped evidence, rotate the binding while canonical content stays identical,
then assert the complete native pointer and consume the replacement file. Scoped
authority seals hash-only native intent and preimage witnesses; locked authored
readback, recovery, and terminal replay must agree with them. Exercise unavailable
inputs and changed intent against a completed request, and prove that refusal
preserves its receipt and native rows. Canonical recapture by itself cannot detect
an old pointer because both filenames become the same dependency marker.

For a natural key, uniqueness is a **source and hostile-target invariant**, not
one source-side probe result. Populate the candidate key, probe it, then create
an independent target row with the same key. A fresh target derives the same
UUID; this exercises first binding, including equal-content rows, rather than
an identity collision. To prove explicit adoption, capture the source under an
old key, rename it through the native API, and recapture. Assert the source ID
and durable UUID remain unchanged while the current key would derive another
UUID. Then create that current key on an unmapped target: Plan must report the
collision without persisting its derived identity, unapproved Apply must refuse,
and explicit table adoption must retain the target ID and reach convergence.
Never clear or rebind a durable map to manufacture this premise. Also create
multiple matching local rows and prove refusal; capture, plan and apply reject
two rows with the same full canonical identity tuple. Do this even when the
table has a primary key: the primary key is local
storage identity, not portable identity. An identity column also cannot carry a
column codec — rewriting it during capture/materialization would make portable
UUID derivation and target lookup disagree.

Everything under `_draft` is inert: `Policy::load()` never applies a proposal,
and the trigger keys are renamed so no validator mis-collects one. Ratify by
hand, delete the rest, then `wprism adapter inspect <name> --repo=<site-repo>`.
Deleting it is not tidiness. `_draft` is in no arm of the signer's top-level key
partition, so a manifest still carrying it cannot be certified at any spec
version, and at `spec_version: 3` it is refused at load by name with "strip the
`_draft` key before install" (spec/repo-format.md § v3.3). Nothing is lost by
removing it — `wprism manifest-validate` reports the sidecar's facts, proposals and
unsupported counts on every run.
`--out` refuses to overwrite an existing draft without `--force`, because that
file holds your ratifications; re-running with `--force` is safe, since human
edits in the prior draft are carried forward.

### 5. Certify and pin the exact bytes

```sh
# Shipped adapter:
wp wprism manifest-pin --name=contact-form-7

# Site adapter, after keygen (sign, verify and pin atomically):
wprism adapter certify <site-repo> --name=<name> \
  --secret-key-file=<organization-key> \
  --reason='<what was reviewed>' --pin
wprism adapter inspect <name> --repo=<site-repo>
```

This prints the exact canonical `{"digest": …, "name": …, "source": …}` object
to paste into `site.wprism.json`'s `manifests` array (keys are canonical, so they
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
`site.wprism.json`; that is the shipped-library path. With `--repo=<site>`, it
loads the repository's policy and site adapter source but overrides only the
selected manifest pin, so a stale digest for that adapter cannot prevent you
from computing its reviewed replacement. Unrelated repository errors still
refuse. Updating a pin is an explicit review act; it is never automatic.

For a site adapter, prefer `certify … --pin` over copying a separately emitted
digest: it signs the canonical adapter, verifies the certificate it wrote and
updates the exact site-source pin in one operation. The final `inspect` must
report the site-rooted certificate as pinned/certified. If any adapter byte is
edited afterwards, rerun the same command; retaining an old signature or
relaxing the digest would turn approval of one program into approval of a
different one.

### 6. Exercise it

A capsule-owned live driver starts with these exact first three active shell
statements, before changing directory, constructing flags, or acquiring a pair:

```bash
set -euo pipefail
PACKAGE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
export WPRISM_ARTIFACT_PACKAGE="${PACKAGE_ROOT##*/}"
```

`regress-fetch-artifact` checks this preamble across discovered capsule callers.
The early physical package scope keeps later shell branches and child commands
from selecting the aggregate artifact library. A late literal package slug can
fetch the right artifact in one run while violating that caller contract.

Independent live lanes require a fresh **whole pair**, not just fresh database
rows. `pair.sh reset` deliberately clears databases and repository contents
while retaining webroot volumes. Reusing those volumes after deleting native
attachment metadata leaves generated files without ownership; the engine must
refuse to overwrite them. Between independent lanes, use the existing
`pair_live_ownership_finish_leg()`, then `pair_live_ownership_acquire()` and
`pair_live_ownership_up()`. This removes owned resources and releases the lease
before re-proving the complete namespace, while retaining private host-registry
scratch. Capsule drivers can source `tests/lib/pair_live_ownership.sh` from
`sandbox/`; it is an explicitly reviewed shared test dependency. The package
validator still requires the exact ordinary `.sh` file and refuses unreviewed
neighbors. Normalize a driver's own invocation path before changing directory
when sourcing capsule-relative hooks through `BASH_SOURCE`. The four-plugin scenario's `regress_pair_lane_isolation.php` executes
its actual dispatcher and shared ownership helper: DB-only reset reproduces
the lost-ownership mechanism, and body, creation, acquisition and teardown
faults cannot start a later lane or publish PASS. A reset is still appropriate
when retained native files are an intentional premise of the same test.

Re-run the loop on a clean environment: capture, apply to a second environment,
recapture, and compare every managed entity through the engine's declared
semantic hash basis. Retain both raw captures: byte equality is a useful
stronger check where it applies, but is not the universal contract. As
`spec/repo-format.md` §Adapter manifests states, Capture includes
observed derived fields while update preserves the target's plugin-owned
values; `Canon::post_hash_basis()` excludes only manifest-declared derived
fields. Do not copy a fixture-specific list of ignored fields or discard
unexpected files. Any target-only entities need an exact native preimage and
preservation proof, separately from managed-entity convergence. `wp wprism lint`
is the companion check — it flags id-shaped values at undeclared paths, which is
exactly the shape a missing `ref`/`json_refs` declaration takes. Its findings
are plan-time signals, not proof of corruption; each carries its own caveat
note, because small ids legitimately coincide with counts, versions, and
ordering indexes.

For host-side evidence, compile each complete staged capture with
`RepositoryCompiler::compile_staged()` and its own real repository/media root,
then use `sandbox/tests/lib/RepositoryConvergence.php`. It compares semantic
entity identities plus policy, code, effects, deletions and the complete media
catalog. An explicitly named target-only signature is admissible only after
the scenario proves its native preimage survived Apply and that Capture minted
the exact corresponding UUID. Keep before-Apply, before-Capture and
after-Capture observations separate: a declared provider may rebuild a derived
marker row, while Capture may add identity metadata; neither permits omitting
other metadata rows or accepting changes to authored fields. The four-plugin
scenario also checks each extra canonical entity against its complete preserved
authored preimage, including detached translation-group descriptions and
memberships. Retained raw bytes remain diagnostic evidence; they are not a
substitute for full compiler validation, nor permission to delete target content.

**A finding on state your out-of-tree adapter declared blocks capture.** For a
shipped or certified adapter every finding stays the advisory warning it always
was; for an adapter installed out-of-tree that nothing has certified — including
one whose certification was withdrawn, and one whose valid signature the
repository has not yet pinned — `wprism capture` refuses with
`uncertified_adapter_lint_findings` and names each locator and the adapter that
declared it. Two ways forward, and no third: declare the reference so capture
tokenizes it, or write the reviewed `lint_ok: true` on that declaration. A
`proposed_lint_ok` finding (the type-derived proposal `wp wprism lint
--evidence=<probe.json>` emits) does **not** clear the gate on its own — it is
the evidence for the review, and `lint_ok` is the review. Certifying the adapter
returns its findings to advisory.

Test reviewed non-reference fields against actual colliding small IDs. The
WPForms native writer stores `modern-markup` as string `"0"`/`"1"`; a fresh
form with post ID 1 exposed the missing review in its named option subkey.
Review the owning flag/enum declaration from the pinned native writer and
consumer, then use the existing per-subkey `lint_ok`, not a blanket parent
exception or a linter change that hides every small integer. The capsule's
`regress_settings_lint.php` drives real option capture and lint with boolean,
integer and string flag representations, an unreviewed numeric sibling, and
secret/PII refusal controls. A non-reference review grants no privacy exception.

An experimental adapter that deliberately excludes `apply` uses the narrower
`mode: "capture-plan"` conformance profile instead. It still boots a fresh
exact-artifact pair, authors state through the plugin's own APIs, runs capture,
lint, deterministic recapture, capability reporting, and the real structured
plan path, plus a convention-named `tests/conformance/capture-check.sh`.
It then stops before deploy/apply. This is evidence only for the operations the
disposition lists; it is not a partial round-trip and cannot justify adding
`apply`, `deploy`, or `promote` to that list.

Experimental capability reports deliberately exit 3 with `ready:false`, even
when capture is declared. The shared profile preserves the complete stream
and status, compares the report to independently projected shipped declarations
and their engine-derived trust tiers,
and admits only each experimental subject's `authored_state_not_certified`
blocker. Certified capture subjects instead require `ready:true` and exit 0.
Source/target/provider failures, PHP diagnostics and inconsistent answers are
not expected experimental outcomes. The real read-only plan must succeed as a
command while retaining its exact promotion blockers: experimental status and,
where promotion is undeclared, `operation_not_certified`. The engine's existing
claim projection owns the `apply + deploy` to `promote` rule. Do not discard
exit 3 through `|| true`, accept arbitrary refusals, use `--all` to lose the
live target, or treat a returned plan as production authorization.

Real worked examples, with the empirical grounding for each decision, are the
grind rounds themselves: `make grind-r1a` (forms — Contact Form 7 + Ninja
Forms) and `make grind-r1c` (the agency stack — Elementor + ACF + a
dogfooded CPT plugin). Each script's header states the fixture and the
finding behind every assertion, and the manifests those rounds produced
(`adapter-packages/contact-form-7/package/manifest.json`,
`adapter-packages/ninja-forms/package/manifest.json`, and
`adapter-packages/elementor/package/manifest.json`) carry the reasoning in
their own note strings.

### Shared tables require row ownership

Inspect native writers and readers before declaring a whole table authored.
The user/customer importer 2.7.5 saves import and export templates in
`wt_iew_mapping_template` with `item_type=user`; other WebToffee modules use
that physical table for other item types. Its native saved-template query
partitions by item type and template type. A table name alone does not grant
ownership of every row.

Use `table-row-scopes/v1` and an explicit `tables.<table>.row_scope`, such as
`{"item_type":"user"}`, for a supported ordinary row table. The scope is an
AND of byte-exact text discriminators. Discriminators must be authored text
columns and participate in a natural key when one is declared. The current
feature refuses structural refs on that table and attached-meta sidecars;
see [the complete contract](../../spec/repo-format.md#v338-table-row-scopesv1--ownership-within-shared-physical-tables).
Do not replace these unsupported structures with an unbounded table claim.

For several explicitly owned variants, also declare `table-row-scope-sets/v1`
and use a sorted set such as `"template_type": ["export", "import"]` alongside
`"item_type": "user"`. Every discriminator still has to match. Sets admit
2–16 exact strings; a singleton keeps the scalar form. Do not remove a
secondary discriminator merely to capture another row type: that would claim
all its unknown variants too. Payload semantics and local input bindings need
their own declarations and evidence.

Prove foreign-row preservation through Capture, immutable compilation, Apply,
repeat Apply, deletion and rollback. Include case-only and trailing-space
owner collisions, retained ids whose owner changed, failed ownership reads,
and sidecar export/recovery. A native ownership change must refuse before it
can become a tombstone or erase the ledger evidence. Keep exact native payload
observations separate from adapter qualification: a template Save/reopen alone
does not establish that its JSON references or local input files can migrate.

The shared native SQL probe checks byte-exact Capture, materialization,
rollback, deletion and a concurrent ownership change after a repeatable-read
snapshot. On a clean committed checkout, run it with an owned pair on each
database engine affected by a SQL change:

```sh
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) WPRISM_DB_ENGINE=mariadb \
  bash sandbox/tests/live/regress_table_row_scopes_native.sh
```

Use `WPRISM_DB_ENGINE=mysql` for the MySQL leg. The harness destroys its pair
after each leg; `ROW_SCOPE_PAIR`, `ROW_SCOPE_PORT1` and `ROW_SCOPE_PORT2` select
a distinct name and even/adjacent ports when sharing a host.
