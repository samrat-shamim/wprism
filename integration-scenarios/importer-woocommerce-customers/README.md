# Importer and WooCommerce customer templates

Participants: Users/Customers Import Export 2.7.5 and WooCommerce 11.0.1.
This scenario covers native customer fixtures, settled full Apply of two
existing user templates, and scoped export Apply with a pending import change. It does not change production readiness.

The offline gate exercises shipped policies in both pin orders. It captures
saved export forms using the Importer policy, then materializes them on a target
with WooCommerce added. Target WordPress user IDs differ from source IDs and
Woo customer lookup IDs. The Snapshot/ledger writer preserves seeded billing,
shipping, sessions, HPOS orders and addresses, customer lookup rows, job history,
and excluded product/case-variant templates. Address and order-statistic mapping
fields cross the same declaration path. Repeat is exact; a missing WordPress
login refuses even if Woo retains that customer's lookup row.

The live premise gate installs both exact artifact locks through shared pair and
artifact machinery. Native admin requests create customers with different source
and target addresses, plus processing, completed and pending orders. Saved
mappings are updated through the native Importer AJAX implementation. Actual CSV
export must read target addresses, count three orders, sum only the two paid
orders, and report the plugin's native average. Actual CSV import must update
the two mapped customer addresses. A fresh Woo request must retain every observed
order and every unmapped customer/address field.

The database observer uses an unfiltered transaction-consistent dump, separate
full table and column inventories before and after collection, and the shared
lossless SQL-literal parser. It retains every discovered table, schema, column
record and cell, including decimal totals and unsigned IDs. Tests reject missing
columns, empty order witnesses, last-digit decimal mutations, changed schemas,
failed exports, diagnostics, and a child command that consumes loop stdin.
These complete images are observations; this gate does not assert an exact
allowed database transition for Apply or for native CSV jobs.

Run from any directory with a clean candidate checkout:

```sh
WPRISM_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
IMPORTER_WOO_PAIR=<unique-pair> IMPORTER_WOO_PORT1=<even-port> \
IMPORTER_WOO_PORT2=<successor-port> \
bash integration-scenarios/importer-woocommerce-customers/tests/live/regress_importer_woocommerce_native_premise.sh
```

The wrapper requires an owned disposable pair and verifies teardown. Private
streams remain under `sandbox/tmp/`; credentials, CSV passwords and database
values do not belong in published evidence.

The full Apply gate first adopts the combined authored scope and proves compiler-validated
baseline intent and complete media convergence. Native Saves change only the
batch size in the existing export and import user templates. Independent source
snapshots establish the intended forms, UUIDs and hashes; target identity bindings
establish the existing local rows. Plan must select exactly those two updates
and preserve the complete unchanged roster. Declared upload/provider inventories
and explicitly optional environment values are reports; selected actions,
required missing values, warnings and pending work still refuse.

Around both actual update and repeat Apply, the gate compares complete database
images, schema and column records, populated Importer operational file trees,
and repository state/configuration/media. Update permits only the two intended
form cells, their baseline hashes, the selected applied revision and a fresh
bounded direct Apply session. Repeat permits only its fresh session. No other
row, schema or file change is allowed. The clean public receipts must report two
updates and then zero, without executable actions, drift or warnings. Transferred
templates are reopened and consumed through native CSV paths; fresh customer and
order getters and final canonical recapture check the resulting behavior.

Run the full Apply gate with the same owned-pair variables:

```sh
bash integration-scenarios/importer-woocommerce-customers/tests/live/regress_importer_woocommerce_apply.sh
```

The exact preservation window covers the settled template update and repeat.
Initial adoption and subsequent native CSV jobs have separate assertions and
are not covered by that allowed database transition. Verified opposing native plugin load orders
remain follow-up qualification. The Importer-only fixed-table oracle cannot
substantiate this combination's preservation.


The scoped lane starts with the same two native Saves, then uses a host-minted
scope contract to select only the export template. Its complete preservation
window permits that form cell and ledger baseline, one terminal scoped session,
and the matching promotion session. The full applied revision, pending import,
other database rows, schemas, columns, operational files and repository bytes
must remain unchanged. The session binds the semantic desired-document hash
separately from the captured-file hash used by the ledger.

Repeating the same request must return the same terminal receipt and change no
database or operational-file byte. A subsequent full Plan must identify only
the pending import. Completing that import is a separate window; both native
template consumers, customer/order getters and final canonical recapture then
verify the resulting behavior. This qualifies settled scoped Apply and replay,
not scoped interruption, tamper or concurrent-writer recovery.

Run with the same owned-pair variables:

```sh
bash integration-scenarios/importer-woocommerce-customers/tests/live/regress_importer_woocommerce_scoped_apply.sh
```

The Apply fixture now includes a native simple product and a variable product
with one global-attribute variation. The source alone creates the attribute
definition and term; baseline Apply must materialize them on an unprovisioned
target. Native stock updates then establish target quantities 37 and 41, distinct
from the source's 19 and 23. Fresh native getters and three bound product lookup
rows must agree before/after the measured Apply, replay, and final native CSV
consumers. Missing, aliased, empty or changed witnesses fail independently of the
complete database comparison. This is representative catalog evidence, not a
claim about every WooCommerce product type.

Cross-site recapture uses Capture `--out` evidence trees and the shared repository
compiler/convergence checker. It never overwrites the transferred source checkout: a
scope contract binds the complete compiled artifact, including source file bytes.
WooCommerce declares persistence timestamps derived, so raw post-file equality
is not its authored-intent contract. The checker permits no target-only entity
exception and compares all compiled entity signatures, policy/code/effect and
deletion inputs, and the complete media catalog. Within each measured Apply
window the complete database and repository bytes still require exact preservation
outside the already enumerated template/session transitions.

The populated baseline exposed a missing Woo declaration: dynamic `pa_*`
taxonomies need `hierarchical: false` before their target registration exists.
This matches `includes/class-wc-post-types.php` in all three locked artifacts
11.0.0, 11.0.1 and 11.1.0; it uses the existing generic declaration resolver.
Changing that shipped manifest changes the Woo adapter digest and requires
recompilation and re-pinning on deployed sites.

Custom (non-global) variation attributes remain a separate qualification gap:
a native `Size` variation generated `attribute_size`, and Capture correctly
refused `incomplete_state_discovery` / `unclassified_state` for that unreviewed
post-meta surface. This scenario uses the reviewed global-attribute path and
does not claim custom-attribute coverage.
