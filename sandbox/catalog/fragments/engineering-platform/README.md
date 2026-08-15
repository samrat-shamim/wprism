# Engineering-platform catalog implementation

This directory is the Thread 1-owned Platform P0 surface permitted by the
foundation ownership ledger. It contains:

- `schema.json`: the catalog-fragment contract;
- `Catalog.php` and `catalog.php`: fragment validation and deterministic
  aggregate generation;
- `Runner.php` and `runner.php`: the serial, streamed, timeout-bounded P0
  runner with retained logs and a canonical run receipt;
- `platform.catalog.json`: the nonempty Thread 1 fragment and profiles;
- `requirements.json`: content-addressed non-offline authority definitions;
- `generated/catalog.json`: the deterministic complete P0 aggregate;
- `delegate-check.php`: fail-closed delegation to behavior-owner checks;
- `Doctor.php`, `doctor.php`, and `bootstrap-receipt.php`: read-only diagnosis
  and the explicit development-bootstrap receipt;
- `deptrac.yaml`: the baseline-free production/development dependency boundary;
- `Hygiene.php` and `hygiene.php`: tracked artifact, credential, and workflow
  pin checks;
- `loader-runtime-probe.php`: the PHP 8.0–8.4 source/dist compatibility receipt
  and fail-closed matrix aggregator; and
- self-tests plus PHPUnit coverage for validation failures.

The complete aggregate is generated only after every sibling owner fragment
passes validation. `catalog.php verify` fails when its checked-in bytes drift.
Do not add behavior suites to this fragment merely to make aggregate validation
green; they remain owned by their behavior thread. Production code must not
require files from this directory.
