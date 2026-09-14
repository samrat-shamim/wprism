# Importer and WooCommerce customer templates

Participants: Users/Customers Import Export 2.7.5 and WooCommerce 11.0.1.
This scenario covers native customer fixtures and settled full Apply of two
existing user templates. It does not change production readiness.

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

The full Apply gate first adopts the combined authored scope and proves exact
baseline canonical state and media convergence. Native Saves change only the
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
are not covered by that allowed database transition. Scoped Apply, independent
target product-catalog witnesses and verified opposing native plugin load orders
remain follow-up qualification. The Importer-only fixed-table oracle cannot
substantiate this combination's preservation.
