# `sandbox/tests/lib/` — the shared offline test harness

Three PHP files, no dependencies, no composer, no WordPress. Every offline
`regress_*.php` suite runs as `php sandbox/tests/offline/<domain>/X.php`, so
these must too. (`grind_lib.sh` also lives here; it is the grind harnesses'
shell library and has nothing to do with the PHP harness below.)

| file | provides |
| --- | --- |
| `check.php` | `duo_check*()` assertions and the end-of-suite summary/exit code |
| `wp_stubs.php` | `\DuoTest\WpStore` plus `function_exists()`-guarded WordPress function stubs |
| `FakeWpdb.php` | `\DuoTest\FakeWpdb` — a duck-typed `$wpdb` that interprets SQL against seeded rows |

## Where your suite goes, and what that costs you in `../`

The corpus root holds no suites. A suite lives in the directory of the
`Makefile` class that runs it, and the offline class is subdivided by domain:

| class | directory | depth below `sandbox/tests/` | `lib/` from a suite | repo root from a suite |
| --- | --- | --- | --- | --- |
| offline | `offline/<domain>/` | 2 | `__DIR__ . '/../../lib/'` | `__DIR__ . '/../../../../'`, `dirname(__DIR__, 4)` |
| live, grind, certify, spike | `live/` … `spike/` | 1 | `__DIR__ . '/../lib/'` | `__DIR__ . '/../../../'`, `dirname(__DIR__, 3)` |

The fifteen offline domains are named by what the suite's *subject* is, not by
what it requires — `agent/src/Kernel/*` is required by most of the corpus and
therefore decides nothing. Read the neighbours in the directory you are about
to join; if none of them is about your subject, you are probably in the wrong
one. `tools/suite-layout.review.md` records what each domain means and which
placements were arguments.

Only the offline class holds PHP suites today: `live/`, `grind/`, `certify/`
and `spike/` are shell harnesses end to end, so the one-level row above is the
rule for a helper you put beside one, not a skeleton anybody has written yet.

## A complete new suite

```php
<?php
/** Offline characterization for <what> and <why it can drift>. */
declare(strict_types=1);

// From offline/<domain>/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\TransientDbException;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

$store = WpStore::reset()->seedOptions(['home' => 'https://example.test']);
$wpdb = FakeWpdb::install();                 // assigns $GLOBALS['wpdb']
$wpdb->seedTable('wp_postmeta', [
    ['meta_id' => 7, 'post_id' => 19, 'meta_key' => 'owned', 'meta_value' => 'before'],
]);

$materializer = (new ReflectionClass(ApplyFieldMaterializer::class))->newInstanceWithoutConstructor();

$materializer->upsert_meta($wpdb->postmeta, 'post_id', 19, 'owned', 'after', 'test update');
duo_check_same('after', $wpdb->rows('wp_postmeta')[0]['meta_value'], 'upsert_meta updates in place');

$materializer->upsert_meta($wpdb->postmeta, 'post_id', 19, 'nullable', null, 'test insert');
duo_check_same(8, $wpdb->insert_id, 'the insert took the next meta_id');

// The Db.php seam: a MySQL 1213 must arrive as the retryable typed class.
$wpdb->simulateDeadlock('UPDATE');
duo_check_throws(
    static fn() => $materializer->upsert_meta($wpdb->postmeta, 'post_id', 19, 'owned', 'again', 'probe'),
    TransientDbException::class,
    'a deadlock on the authored-meta UPDATE reaches Db.php as TransientDbException'
);

duo_check_summary('my new suite');           // prints PASS/FAIL and exits 0/1
```

Add the leaf target to the `Makefile` the way every other offline suite does
(`php sandbox/tests/offline/<domain>/<name>.php`); it is picked up by
`make regress-offline-corpus` and therefore by `make regress-offline-all`.

The target name still comes from the basename alone (`regress_foo_bar.php` →
`regress-foo-bar`): the directory is not part of it and cannot disambiguate
two files that share a basename, which is why
`sandbox/tests/offline/guards/regress_bundle_coverage.sh` refuses them outright.
`sandbox/tests/offline/guards/regress_suite_wiring.php` is the other half — it
refuses a suite file sitting in a class directory that no recipe runs, so a
file dropped in the right place but never wired fails loudly instead of looking
covered.

Two `Makefile` edits go with the new file and `regress_bundle_coverage.sh`
fails the gate if either is missing: wire the leaf into
`regress-offline-corpus`, and bump the `regress-offline-all: N offline suites
green` count line. Adding the target is otherwise ordinary work. A self-test
for the *tooling* is not a suite: put it in `tests/` under PHPUnit, which the
offline corpus does not run and which needs no `Makefile` edit at all.

## Assertions (`check.php`)

- `duo_check(bool $ok, string $message)` — the primitive; `ok:` to STDOUT,
  `FAIL:` to STDERR.
- `duo_check_same($expected, $actual, $message)` — strict `===` with a compact
  first-difference diff. Prefer it over `duo_check($a === $b, ...)`: the diff is
  the whole point.
- `duo_check_json_equal($expected, $actual, $message)` — object key order
  ignored, array element order preserved. Use `duo_check_same()` on the raw
  string when byte-exact canonical output *is* the contract.
- `duo_check_throws(callable, $class, $message, ?$messageContains)`.
- `duo_check_refuses(callable, $reasonCode, $message)` — expects
  `\Duo\CommandRefusalException` with that reason code. Asserts the machine
  readable code, never the message, which the class may redact.
- `duo_check_summary(string $suite): never` — the tail. Exits 1 if anything
  failed **and** if nothing was asserted at all.
- `duo_check_closure(): \Closure` — a `(bool $ok, string $message)` closure, the
  migration seam described below.

## WordPress stubs (`wp_stubs.php`)

Everything reads and writes one `\DuoTest\WpStore` singleton (`WpStore::reset()`
per suite). Stubbed: `get_option` / `add_option` / `update_option` /
`delete_option`, `wp_upload_dir`, `add_filter` / `add_action` / `remove_filter` /
`remove_action` / `has_filter` / `has_action` / `apply_filters` / `do_action`,
`is_wp_error` (+ a minimal `WP_Error`), `wp_json_encode`, `wp_cache_get` / `set`
/ `delete` / `flush`, `sanitize_title`, `sanitize_key`, `is_serialized`,
`maybe_serialize` / `maybe_unserialize`, `esc_sql`, `get_post_types`,
`get_bloginfo`, `wp_parse_args`, `absint`, `trailingslashit` /
`untrailingslashit`, `wp_normalize_path`, `is_multisite` (always false),
`current_time`. Constants: `OBJECT`, `OBJECT_K`, `ARRAY_A`, `ARRAY_N`,
`ABSPATH`, `WP_CONTENT_DIR`, `WP_PLUGIN_DIR`, `*_IN_SECONDS`.

Three behaviours are deliberately faithful rather than convenient, because the
engine already compensates for them and a smoothed-over stub would hide the
compensation:

- `update_option()` returns **false** when the value is unchanged.
- `maybe_serialize()` leaves scalar `null`/`false` alone (which is why
  `ApplyFieldMaterializer::option_wire_value()` serializes them itself).
- `apply_filters()` truncates arguments to each callback's `accepted_args`.

`$store->cacheEvents`, `$store->firedActions`, and `$store->hooks` are the
inspection points; nothing is created on disk unless you call
`$store->ensureUploadDir()`. That directory is unique per process and per store
(pid plus a random suffix) and the next `WpStore::reset()` removes it — `make
-j8` runs the corpus concurrently in one shared temp dir, so a fixed path would
let two suites see each other's fixture files.

## The fake `$wpdb` (`FakeWpdb.php`)

`FakeWpdb::install()` returns the instance and publishes it as
`$GLOBALS['wpdb']`, which is where `agent/src/Kernel/Db.php` and every collaborator
read it from.

It holds **rows**, not answers. `seedTable()` / `rows()` / `setPrimaryKey()` /
`setAutoIncrement()` / `setUniqueKey()` / `setColumns()` describe the target;
`prepare()` renders real WordPress placeholders (`%s %d %f %i %% %1$s`, array
argument unpacking, LIKE-safe percent escaping) and the interpreter runs the
resulting SQL. The supported grammar is enumerated in the file's header
docblock — SELECT (incl. `COUNT(*)`, `DISTINCT`, `GROUP BY`, `IN`, `LIKE`,
`BINARY`, `LENGTH`, `ORDER BY`, `LIMIT`/`OFFSET`, index hints), INSERT
(`IGNORE`, `ON DUPLICATE KEY UPDATE`), REPLACE, UPDATE, DELETE, `SHOW TABLES
LIKE`, `SHOW COLUMNS`, transactions, and recorded DDL.

**Anything it cannot interpret throws `\LogicException` naming the statement.**
So do reads against a table you forgot to seed, and columns that exist in no
seeded row. That loudness is the reason this class exists: a bespoke fake that
returns `null` for an unrecognised query silently pushes the suite down a "no
row" branch the live gate never takes. Seed an empty table with
`seedTable('wp_x', [])` and extend the interpreter when you need new syntax —
do not add a `str_contains()` special case.

Also refused, and worth knowing before you plan a migration: **schema-qualified
reads** (`information_schema.COLUMNS` / `.STATISTICS`) and `SHOW INDEX`. Those
facts already live in `setColumns()` / `setUniqueKey()` / `setPrimaryKey()`, so
a synthetic `information_schema` fed from them would only be asserting this
harness's own bookkeeping. Concretely it means `Ledger::assert_read_only_schema()`,
`Ledger::prune_dead_table_map()` (a multi-table `DELETE`) and
`Snapshot::assert_all_mapped_rows_managed()` (a `LEFT JOIN`) stay
live-certification paths and cannot be moved here. `SHOW TABLES LIKE` and
`SHOW COLUMNS FROM` *are* supported — they are the offline way to probe
existence and column shape.

Facts worth knowing before you write an assertion:

- plain `=` on strings is **case-insensitive**, matching a stock
  `utf8mb4_*_ci` install; `BINARY x = BINARY %s` is byte-exact. That asymmetry
  is why the engine writes `BINARY` on identity lookups.
- two **strings** never compare numerically: `'7' = '007'` and `'1e2' = '100'`
  are false, as in MySQL. Coercion happens only when one side is an int or a
  float — a `%d`-rendered literal against a `'19'` fixture value still matches.
- `col = NULL` matches nothing **in the interpreter** (three-valued logic), but
  a `null` in the array-style `update()`/`delete()` `$where` renders `col IS
  NULL` and *does* match NULL rows — because that is the special case real
  `wpdb::update()`/`::delete()` carry.
- `LIKE` is anchored end-to-end: `LIKE 'payload'` does **not** match the stored
  value `"payload\n"`, and `_` consumes one character rather than one byte.
- **result values are strings.** `wpdb` reads mysqli's text protocol, so every
  non-NULL column — `COUNT(*)` included — arrives as a PHP string, and
  `get_var()`/`get_col()`/`get_row()`/`get_results()` reproduce that. `rows()`
  reads the *store* and keeps your fixture's PHP types. Assert against the
  `get_*()` value when you are pinning what the engine sees, or `duo_check_same()`'s
  `===` will pass offline on an `int` the live gate returns as `'1'`.
  `insert_id` / `rows_affected` / `num_rows` stay ints, as on `wpdb`.

### Failure seams

`onQuery(callable)` sees every statement and returns `null` to proceed, `false`
to fail generically, or a string to fail with that `$last_error`.
`failNextQuery($error, $matching, $times)` is the declarative form.
`simulateDeadlock()` / `simulateLockWaitTimeout()` emit the literal MySQL
1213/1205 text that `Db::checked()` maps to `TransientDbException`; anything
else becomes `DatabaseMutationException`.

Read failures return what `wpdb` really returns, which is less than most fakes
assume: **only `query()` reports `false`.** `wpdb::get_col()` builds its array
unconditionally and `wpdb::get_results()` returns `last_result`, which
`wpdb::flush()` already emptied before the failing statement ran — so both give
back `[]`, and `get_var()`/`get_row()` give back `null`. `$last_error` is the
only positive signal, and it is always set here.

So `if (!is_array($rows))` after a `get_col()` is dead code against live `wpdb`
(`RegenerationContextStore::checked_get_col()` and `ProviderSdk::checked_get_col()`
both carry one). Drive the failure branch through `$last_error`, the way
`ScopedApply.php` and `SnapshotPruner.php` do.

`queryLog()` / `queries()` / `ddlLog()` / `resetLog()`, plus the public
`$last_query` property, are the assertion surface for "which statements ran, in
what order".

## The guard: new suites must use the lib

**Every new `sandbox/tests/offline/<domain>/regress_*.php` uses this library.**
Not because duplication is untidy, but because a hand-rolled fake only answers
the exact SQL its author transcribed. Today 42 suites carry a bespoke `$wpdb` and 33
declare their own WP stubs; they disagree with each other (three different
`prepare()` return types, two different read-failure conventions, `get_option`
stubs that return `false`, a hard-coded `home`, or a differently-named global),
and each one silently degrades to `null` the moment the engine's SQL changes
shape. A new suite that adds a 43rd variant adds a new way to be quietly wrong.

If the library cannot express what you need, extend the library in the same
change and say why in its docblock. An in-file fake is acceptable only when the
subject under test *is* a wpdb behaviour the interpreter deliberately refuses to
model (JOINs, real collations, storage engines) — and that assertion probably
belongs in the live certification instead.

## Migrating the existing suites

**Only when the suite is already being edited for another reason.** These files
are the offline corpus that IS the merge gate; a mass rewrite would churn
2,114 assertion call sites and 42 fakes for no behavioural gain, and each
touched file is a chance to change what a suite actually checks.

The incremental path, in the order that keeps every step green:

1. Replace the local `$check` closure with `$check = duo_check_closure();` and
   the hand-rolled tail with `duo_check_summary('<suite>')`. The existing
   `$check(...)` call sites are untouched.
2. Replace the bespoke fake `$wpdb` with `FakeWpdb::install()` plus
   `seedTable()` calls derived from its fixture arrays. Run it: any
   `\LogicException` tells you exactly which SQL the interpreter still needs.
3. Delete the suite's local WP function stubs and require
   `__DIR__ . '/../../lib/wp_stubs.php'`.
   Note that an unconditional `function get_option() {}` at file scope is
   compiled before the `require` runs, so a partial migration cannot fatal on
   redeclaration — the suite's own stub simply keeps winning until you remove
   it.
4. Convert `$check($a === $b, ...)` to `duo_check_same($a, $b, ...)` as you
   touch each assertion, for the diff.
