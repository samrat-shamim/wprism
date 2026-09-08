# QR collector launch preflight — still no native Save verdict

The retained launch attempts used clean runtime source
`ddeba41040e644832d24555c4bc9ecaa01a1d21f` and the locked Lite 2.0.1.1 ZIP.
WPForms remains experimental/unready. None of these attempts invoked the
checked-in Save collector or established QR persistence, Apply or readiness.

Terra reviewed the scratch owner before launch. The owner was corrected to
use the same journal overlay for CLI and web, retain hashes of all fifteen
explicit producer inputs plus CLI/browser versions, and bind the browser
session and private stage records to the acquired parent lease. Admission
also checks exact collector argv and generated invocation bytes; a retained
source hash alone does not prove that program executed. This is provenance
for a disposable diagnostic run, not a sealed capability certificate.

## Failed attempts and corrections

All attempts used freshly acquired pair namespace `wpfqr02`, ports 9546/9547.
Each failed attempt completed the shared owner's mandatory teardown before
the next acquisition; foreign pairs were untouched.

| Attempt | Failure | Retained native archive |
| --- | --- | --- |
| v1 | The scratch owner had not initialized the canonical lease-directory context; no sites were created. | `sandbox/tmp/wpforms-qr-save.af9xM8/` |
| v2 | Its private stage-name grammar rejected underscores in native table names. | `sandbox/tmp/wpforms-qr-save.spyxj9/` |
| v3 | `compose run` consumed the enclosing table-roster stdin, so column evidence was incomplete and refused. | `sandbox/tmp/wpforms-qr-save.7MHVgv/` |
| v4 | Both initial 26-table snapshots completed, but the post-Builder full dump exceeded the selected 2-MiB private transport budget. | `sandbox/tmp/wpforms-qr-save.vJWB67/` |

The stdin defect was reproduced using the actual scratch `capture()` function:
the uncorrected function observed 13 of 26 stages with a stdin-reading child;
closing stdin for this noninteractive owner preserved all 26. The shared
private capture helper is unchanged because other owners legitimately supply
stdin. No failure is rewritten as PASS.

The v4 browser used Playwright CLI 0.1.19 and Chrome 152.0.7977.82, named
session `wpfqr02-source-7740`. It logged in, unchecked Lite Connect and skipped
external guided setup. The scratch wrapper incorrectly rejected valid empty
JSON results from two fill commands; subsequent UI preparation used the
existing opaque-output transport, without weakening Save admission. The
optional challenge intercepted creation and Skip clicks, which returned
explicit timeouts. The native intro was dismissed, but no form was created.
These preparation failures prevent any complete-browser-flow claim.

## Complete-dump budget evidence

The retained post-Builder dump is **5,574,808 bytes**, SHA-256
`2848da8b79dd82626c1d2e2e4edc68a2ac08c43f66d08f0e7d67f5d3bd25cd40`.
Its independent roster contains 26 tables. The native option
`_wpforms_transient_wpforms_prepared_templates_data_1` contains a complete
**4,996,683-byte** value; SQL escaping makes its INSERT larger. Dropping that
row would make a full-database preservation claim false.

The shared `EvidenceSizeProfile::NATIVE_DATABASE` now permits an explicitly
selected 16-MiB command stream without enlarging filesystem tree/record
budgets. `SqlDumpEvidence` accepts that caller-selected profile, retaining its
existing 2-MiB default and all table, schema, row, cell and framing limits.
Deterministic tests reproduced the old refusal, admit the exact new boundary,
refuse one extra byte and retain the complete large scalar. The SQL suite has
175 assertions; private-output/tree coverage has 142. The former includes
actual 16-MiB default and 64-MiB larger-profile child-process memory ceilings;
this is not a streaming-memory guarantee for arbitrary databases.

An offline replay first read the actual private dump with the new profile,
admitted all 26 schema sections, and projected all four columns of all 181
options rows. The complete largest value hashes to
`c5ed043cea6f1f994fd06b83039cbde9c8b0f2ec65066289c4a425468c661561`.
Its column roster came from the initial snapshot: this replay is a transport,
schema and projection check, **not** a post-Builder metadata-equality or
physical-preservation verdict. Nothing was filtered, normalized or restored.

## Retirement and next run

The v4 owner exited nonzero after its private reader refused the oversized
dump. Pair containers, both databases, exact site roots and lease were
retired. The one named browser was then explicitly closed; no other browser
or pair was closed. Private evidence remains outside disposable roots in
the archives above and `sandbox/tmp/qr-browser-save.oOoqzW/`; outer statuses
are `qr-save-native-v{1,2,3,4}.{stdout,stderr,exit}`.

The next producer must select the native database profile at both transport
and SQL admission, use command-specific UI result checks, and dismiss the
challenge before entering the Builder. Native execution and bounded timing
controls for the Save collector remain pending, followed by complete physical
admission and the cross-site sequence in
[the QR design](native-qr-destination-design.md).
