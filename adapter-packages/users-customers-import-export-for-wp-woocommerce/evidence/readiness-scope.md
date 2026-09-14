# Importer readiness scope

The [readiness contract](../../../docs/agents/adapter-production-readiness.md)
qualifies each scenario family against the adapter's declared surface. The
current surface is nine settings and exact `user/import` and `user/export`
mapping-template rows at the locked 2.7.5 artifact. This audit closes the
clean-target, dirty-target and reference families and identifies why derived-state repair does not apply to
that surface. It does not qualify the remaining families or promote the adapter.

## Identity and references

The [manifest](../package/manifest.json) declares the following identity-bearing
values. The settings are scalar values, with no entity references. The table's
`refs` list is empty; the nested export `user[]` is its only entity-reference
declaration.

| Value | Product declaration and evidence |
| --- | --- |
| Template identity | The natural key is `(template_type,item_type,name)` and the local ID kind is `iew_template`. The [export](../tests/offline/regress_export_templates.php) and [import](../tests/offline/regress_import_templates.php) suites exercise ledger binding, adoption, creation, rename and exact recapture. The native [template lane](../tests/live/regress_templates_apply.sh) and [agent roundtrip](../tests/conformance/entry.json) require different source and target template IDs, retaining adopted target IDs and creating the missing copies and blank draft. |
| Selected users | Export `filter_form_data.wt_iew_email` is a strict `user[]` reference with native string IDs, bound by login. The [roundtrip oracle](../fixtures/roundtrip-evidence.php) requires two distinct positive string IDs on each side, disjoint source/target vectors, and equal ordered login bindings. Its [mutation controls](../tests/offline/regress_roundtrip_evidence.php) reject one or all coincident IDs, integer references and a wrong target login. Native reopen and CSV export consume the target users' profiles. |
| Local CSV input | Import `wt_iew_local_file` is an environment input dependency, not an entity ID. The [native template evidence](../fixtures/templates-evidence.php) checks each missing binding, exact provisioned target pointer, empty draft and scoped pointer rotation. Actual native import consumes target CSV bytes; recapture excludes source filenames and CSV contents. |

The native checks inspect complete saved forms and rendered wizard controls;
canonical equality alone is not the reference proof. The native resave and
recapture checks also establish that rebuilding a local selection cursor does
not create authored drift. Lifecycle, failure/recovery and plugin combinations
remain separate qualification work; their absence does not undo the demonstrated
reference kinds.

## Clean target

The [clean entry](../fixtures/clean-target-entry.json) pins the exact artifact and
uses the shared deployment runner on fresh installations. Its
[native lane](../tests/live/regress_clean_target.sh) observes empty template and
history tables before provisioning three target users and a local CSV. The
[oracle](../fixtures/clean-target-evidence.php) requires the exact file roster:
existing protection files survive, and only the CSV plus the native helper's
missing protection files may appear. [Mutation controls](../tests/offline/regress_clean_target_evidence.php)
reject leaked job files and removed or changed protection files.

Public Apply creates all five templates without table adoption, preserves the
complete surrounding database and files, and exposes all nine settings. Native
reopen, export and import consume the target values. A source rename and header
edit reach the existing target row, repeated Apply leaves the full native
observation unchanged, and source/target canonical recapture is byte-identical.
The lane covers this family for the declared surface; signed deletion,
recovery and combinations retain their separate gaps.

## Dirty target

The [dirty-target lane](../tests/live/regress_dirty_target.sh) uses the exact
2.7.5 artifact and [explicit shared-runner hooks](../fixtures/dirty-target-entry.json).
Native Save first gives both original templates historical names. Capture enrolls
their identities; another native Save restores their current names and recapture
retains all five UUID/local-ID mappings. The [oracle](../fixtures/dirty-target-evidence.php)
proves both originals now differ from fresh current-key UUIDs. Independently
created, unmapped target rows therefore exercise genuine collisions.

Plan and unapproved Apply refuse with exact public/private causes, preserving
the complete database, canonical tree, policy and eleven-table/file census.
Explicit adoption retains the existing target template IDs; the standard
[post-Apply oracle](../fixtures/roundtrip-evidence.php) checks all surrounding
rows, activation defaults, local settings and operational files. Native target
edits then produce drift, and independent source edits produce three-way
conflicts. Both refuse without mutation; explicit resolution changes only the
three authored values, retains target IDs and inputs, and repeated Apply is
stable. All five templates reopen, actual CSV consumers use target-local data,
and recapture matches the source exactly.

The native settings controller replaces its entire option, including removal of
unrecognized members. The evidence treats that user-initiated Save separately
from WPrism's nine-key Apply projection, which preserves target-local members.
[Negative controls](../tests/offline/regress_dirty_target_evidence.php) reject
lost local values, incomplete raw observations, unintended Plan diagnostics,
identity rebinding and incomplete refusal preservation. Source observations use
the shared owned cron window while retaining every cron row in comparisons.
Signed deletion, failure/recovery and concurrency remain separate open families.

## Transaction failure and retry

The [recovery lane](../tests/live/regress_recovery.sh) uses the exact 2.7.5
artifact and existing shared database fault switches. Native source Save and
Capture change the settings option plus the original import/export templates.
Read-only Plan binds those three updates and preserves the complete database.
Failure at the authored commit rolls back all native changes. A second attempt
fails at ledger commit: the three native values persist while baseline hashes
and the applied revision remain stale. Ordinary retry settles them and removes
the marker; repeated Apply preserves the settled result.

The [oracle](../fixtures/recovery-evidence.php) compares complete rows, independent
column inventories and opaque schemas for all eighteen tables, canonical files,
policy and the native file/row census. Only exact fresh promotion sessions and
the phase-specific marker/ledger transitions are allowed. An incomplete retry
replays unchanged entities, so its next marker contains the complete baseline
roster, even though only three values differ. The [offline controls](../tests/offline/regress_recovery_evidence.php)
check this against the product planner and reject stale narrower markers,
changed surrounding state, malformed revisions and incomplete observations.
Both failures require their exact public refusal and fresh private injected cause.

Five native template reopens, four CSV consumers and exact canonical recapture
pass after retry. The [process-death variant](../tests/live/regress_recovery_crash.sh)
reuses this full fixture at the same two commit boundaries with actual SIGKILL.
The shared subprocess control requires kernel signal 9 and absent PHP cleanup;
the retained container record binds the exact invocation, real init, requested
fault environment and non-OOM termination. The fixture captures and removes
the stopped container before diagnostic collection, whose fresh record set must
remain empty. It permits only the crashed owner's exact durable lease alongside
the existing phase changes: `apply-session-begin` after authored rollback,
`apply-ledger` after ledger rollback. The product finalizer renews that latter
phase before opening its transaction, so it survives the interruption.

Retries wait for the observed lease to expire naturally using the existing
bounded test TTL; they never clear the lease or modify timestamps. Successful
retry and repeat must release their leases and retain complete surrounding state.
Native reopens, CSV consumers and exact recapture are required again afterward.
Other interruption points, tamper and early retry against a still-live lease
remain unqualified. Signed deletion and the remaining recovery cases retain gaps;
overall readiness and shipped package identity are unchanged.

## Derived state

There is no authored cache, index, CSS, rewrite, lookup, occurrence or generated
file in this manifest, and no declared repair action or lifecycle regenerator.
The saved `selected_template` cursor is removed by `record_fields` projection.
Normal native reopen selects the requested target row; native Save rebuilds the
cursor from that local selection. The [export fixture](../fixtures/templates-native.php)
and [import fixture](../fixtures/import-templates-native.php) inspect those
controls and saved forms, while the template lane requires exact recapture after
resave. No persistent derivative must be repaired to consume the authored form.

Generated exports, import logs, job history and users are operational data. The
native settings Save listener can delete old history and files; configuration
Apply intentionally preserves them. The [settings lane](../tests/live/regress_settings_apply.sh)
checks this distinction using populated native history and files. Running that
listener as an adapter repair would destroy target-local operational state.
The required derived-state repair family is therefore structurally
`not_applicable` for the declared surface, rather than an unimplemented action.

Eight families remain gaps in [the readiness record](production-readiness.json).
The existing positive and refusal evidence stays useful, but it does not establish
every dependency/lifecycle/platform boundary, the omitted-password native case, signed deletion and remaining recovery cases,
concurrency, or all difficult-value/data boundaries. Those require their own
concrete evidence before promotion.
