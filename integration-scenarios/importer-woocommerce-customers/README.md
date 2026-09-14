# Importer and WooCommerce customer workflows

Participants: Users/Customers Import Export 2.7.5 and WooCommerce 11.0.1.
This scenario is under construction; it supplies no production-readiness claim.

The offline gate exercises the shipped policies in both pin orders. It captures
native saved export forms using the Importer policy, then materializes them on a
target with WooCommerce added. Target WordPress user IDs differ from source IDs
and from Woo customer lookup IDs. The real Snapshot/ledger writer preserves
seeded billing/shipping metadata, sessions, HPOS orders/addresses/metadata,
customer lookup rows, job history, and excluded product/case-variant templates.
Address and order-statistic field mappings cross the same declaration path.
Repeat is exact; a missing WordPress login refuses even when Woo retains that
customer's lookup row. These assertions cover seeded state, not a complete live
database census or native Woo API behavior.

Remaining live evidence: exact locked artifacts; fresh-request verification of
both plugin load orders; native customer creation and billing/shipping readback;
saved-template transfer, reopen, CSV import/export; independent target product,
order, session and history witnesses; full database/schema/column/file preservation
around Apply; successful repeat and exact canonical recapture. Reuse capsule
native consumers and shared pair/artifact/command machinery. The Importer-only
eleven-table oracle is insufficient for this combination and must not be cited
as its preservation evidence.

The scenario database reader uses the shared lossless SQL-literal mode with
independent table and full-column inventories. It retains every discovered
neighbor, schema, column record and cell, including decimal totals and unsigned
IDs. Offline controls detect last-digit decimal changes, omitted columns and
empty order evidence. This is an observation building block; the live producer
and exact allowed Apply transitions are still required.
