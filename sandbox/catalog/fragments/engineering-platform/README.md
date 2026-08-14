# Engineering-platform catalog implementation

This directory is the Thread 1-owned Platform P0 surface permitted by the
foundation ownership ledger. It contains:

- `schema.json`: the catalog-fragment contract;
- `Catalog.php` and `catalog.php`: fragment validation and deterministic
  aggregate generation;
- `Runner.php` and `runner.php`: the serial, streamed, timeout-bounded P0
  runner with retained logs and a canonical run receipt;
- `platform.catalog.json`: the nonempty Thread 1 fragment and profiles;
- `Doctor.php`, `doctor.php`, and `bootstrap-receipt.php`: read-only diagnosis
  and the explicit development-bootstrap receipt; and
- self-tests plus PHPUnit coverage for validation failures.

The complete aggregate is generated only after the sibling owner fragments
exist and pass validation. Do not add behavior suites to this fragment merely
to make aggregate validation green; they remain owned by their behavior
thread. Production code must not require files from this directory.
