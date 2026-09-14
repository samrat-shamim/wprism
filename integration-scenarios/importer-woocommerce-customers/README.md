# Importer and WooCommerce native customer foundation

Participants: Users/Customers Import Export 2.7.5 and WooCommerce 11.0.1.
This milestone establishes native fixtures and complete observations for the
combination. It does not qualify combined Apply or change production readiness.

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

Follow-up qualification remains explicit: combined full/scoped Apply and its
exact permitted database, schema, file and ledger changes; independent target
catalog witnesses; verified opposing plugin load orders; transferred-template
reopen/consumption; canonical recapture and stable repeat. The Importer-only
fixed-table oracle cannot substantiate this combination's preservation.
