# MySQL 8.4 dialect audit — what the shipped agent actually sends, and what the live probe must assert

Scope: every SQL construct in `agent/` whose behaviour could differ between
MariaDB 11 and MySQL 8.4 (the evidence lane's server). Line numbers are
re-verified against the branch that widened the claim, not copied from the
plan documents — several had moved.

**Status: this document is a hard prerequisite of the MySQL claim, not a
retrospective.** `platform/adapter-library/capabilities/platform.json`'s database axis now
names MySQL 8.4 alongside MariaDB 11, with a PENDING note saying the live
proof has not run. The five probe groups below are the assertions
`sandbox/tests/live/regress_core_scope_database.sh` carries, in the order
stated at the end of this file, and they are what turns that PENDING into
evidence — or into the named remedy (drop the MySQL entry, restore a
MariaDB-only engines map).

**Verdict up front: ZERO changes to shipped SQL.** Every candidate rewrite is
either not provably behaviour-preserving on the MariaDB path, or not pinnable
offline (`sandbox/tests/lib/FakeWpdb.php` records SQL text; it executes none of
it, so no offline suite can distinguish two dialects). Each risk below
therefore ends with the exact assertion its live probe must make.

Sourcing convention: facts that could not be verified from a primary source
under the authoring stream's network restrictions (allowed: `api.wordpress.org`,
`downloads.wordpress.org`, read-only docker metadata — MySQL's own
documentation is not reachable) are marked **[wp-knowledge]**. Every such fact
is a probe target, not a conclusion. A `[wp-knowledge]` line that the live run
contradicts is a finding to record here, not a reason to reinterpret the
result.

---

## 1. `VALUES(col)` in `ON DUPLICATE KEY UPDATE`

### Exact SQL

Six sites, all upserts (the plan documents listed five and had the third
Ledger line at 563; it is 619 on this branch, and they missed the
`PromotionLease` one entirely):

| path:line | statement |
| --- | --- |
| `agent/src/Repository/Ledger.php:390` | `INSERT INTO {prefix}wprism_map (uuid, entity_type, id_kind, local_id) VALUES (%s,%s,%s,%d) ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type)` |
| `agent/src/Repository/Ledger.php:417` | `INSERT INTO {prefix}wprism_state (uuid, entity_type, content_hash) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), content_hash = VALUES(content_hash)` |
| `agent/src/Repository/Ledger.php:619` | `INSERT INTO {prefix}wprism_kv (k, v) VALUES (%s,%s) ON DUPLICATE KEY UPDATE v = VALUES(v)` |
| `agent/src/Repository/SidebarState.php:496` | `INSERT INTO {options} (option_name, option_value, autoload) VALUES (%s,%s,'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)` |
| `agent/src/Repository/SidebarState.php:521` | same, for the `sidebars_widgets` option |
| `agent/src/Promotion/PromotionLease.php:385` | `VALUES(v)` used as the *then* branch of an `IF(...)` inside the lease CAS — see §2 |

`PromotionLease.php:385` is the one that matters most: `VALUES(v)` there is
not a convenience, it is how the compare-and-swap writes the new payload only
when the ownership predicate holds. If that function's semantics change, the
promotion lease silently stops being a CAS.

### MySQL 8.4 behaviour [wp-knowledge]

- `VALUES(col)` inside `ON DUPLICATE KEY UPDATE` was **deprecated in MySQL
  8.0.20**, in favour of the row-alias form `INSERT ... AS new ON DUPLICATE
  KEY UPDATE col = new.col` introduced in **8.0.19**.
- It is expected to still **function** in 8.4 (the LTS series) while emitting
  a deprecation warning; removal is signalled for a later series. I could not
  confirm from a primary source whether 8.4 warns per statement, warns once,
  or is silent — and "deprecated" in MySQL has meant "removed two series
  later" often enough that this must be measured, not assumed.

### What the live probe must assert

1. The upsert still **updates on conflict** (not just inserts): insert a row,
   re-insert the same key with a different value, `SELECT` it back and confirm
   the new value won.
2. `SHOW WARNINGS` **immediately after each** such statement — captured
   verbatim into `sandbox/tmp/`. A deprecation warning is a finding to record
   in the widening commit's rationale, not a failure.
3. `$wpdb->last_error` is empty after each (the agent's own error surface;
   `Db::query()` is what would refuse).
4. Run the real promotion acquire→renew→release cycle, not a synthetic
   upsert, so `PromotionLease.php:385`'s `IF(...)`-wrapped `VALUES(v)` is
   exercised in its actual CAS position.

### Why no code change here

The obvious rewrite is `VALUES(v)` → `new.v` with `INSERT ... AS new`. It is
**not engine-neutral**:

- MySQL added `INSERT ... AS alias` in 8.0.19 [wp-knowledge], so it is
  unavailable on any MySQL older than that — and the claim, if it widens,
  should name a `min` the way the MariaDB entry does.
- MariaDB's support for that alias syntax could not be verified from a primary
  source under this stream's restrictions. If MariaDB 11 does not accept it,
  the rewrite **breaks the only currently-claimed engine** — the exact
  opposite of the trade this lane is meant to make.

And it cannot be pinned offline: `FakeWpdb` records SQL text without executing
it, so an offline suite could assert the new *string* but never that both
engines mean the same thing by it. Rewriting on that basis would be a
speculative change to shipped code (AGENTS.md rule 9). Deferred to the live
phase with the measurement above.

---

## 2. `JSON_UNQUOTE(JSON_EXTRACT(v, '$.x'))` over a `LONGTEXT` column

### Exact SQL and schema

`wprism_kv` is created at `agent/src/Repository/Ledger.php:94-99`:

```sql
CREATE TABLE IF NOT EXISTS {prefix}wprism_kv (
    k VARCHAR(191) NOT NULL,
    v LONGTEXT NULL,
    PRIMARY KEY (k)
) <get_charset_collate()>
```

`v` is **`LONGTEXT`, not `JSON`**. Every lease predicate parses it as JSON at
query time:

| path:line | role |
| --- | --- |
| `PromotionLease.php:375-386` | the acquire CAS: two `CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v,'$.expires_at')),'0') AS UNSIGNED)` comparisons plus three `JSON_UNQUOTE(JSON_EXTRACT(v,'$.owner'/'$.artifact_hash'))` equality tests, wrapped in `IF(..., VALUES(v), v)` |
| `PromotionLease.php:490-491` | renew: `UPDATE ... WHERE k = %s AND owner = %s AND artifact_hash = %s` (both via JSON_UNQUOTE) |
| `PromotionLease.php:559-561` | expired-lease reclaim `DELETE` |
| `PromotionLease.php:819-826` | scoped-session completion `DELETE` — eight JSON predicates in one statement, including a `CAST(... AS UNSIGNED)` on `$.scoped_generation` |
| `PromotionLease.php:848-849`, `:890-891` | release / abort `DELETE` |

Two keys are involved: `promotion_lock` (`self::KEY`,
`PromotionLease.php:92`) and `promotion_session` (`:828`).

### MySQL 8.4 behaviour [wp-knowledge]

- **MariaDB** returns `NULL` from `JSON_EXTRACT` when the argument is not
  valid JSON (and generally treats malformed JSON leniently in these
  functions).
- **MySQL** raises `ER_INVALID_JSON_TEXT` (**3141**), *"Invalid JSON text in
  argument 1 to function json_extract"*, and the statement **errors** rather
  than evaluating to `NULL`.

That is a fail-open/fail-closed difference, not a cosmetic one. The
predicates above are written on the assumption that a non-matching or
unparseable row makes the predicate false — on MySQL it would instead make the
statement fail. On the acquire path, the failure lands inside `Db::query()`;
on the `DELETE` paths, `(int) $deleted !== 1` is what raises *"promotion lock
lost before release; completion refused"* — a wrong, misleading message if the
real cause was a JSON parse error.

### How reachable is it?

Not on the normal path. `wprism_kv`'s lease rows are written only by
`PromotionLease` via `wp_json_encode($payload)` (`:388` and the renew/session
writers), so `v` is always valid JSON in a healthy target. The hazard is a
**foreign/legacy/truncated** row: a partially-written `LONGTEXT`, a row left
by an older format, or manual intervention. On MariaDB the CAS then fails
closed (predicate false, lease not taken); on MySQL it would raise 3141.

### What the live probe must assert

1. **Normal path**: a real promotion acquire → renew → release cycle on the
   MySQL pair completes, with `$wpdb->last_error` empty at each step and the
   `wprism_kv` row present/absent exactly as on MariaDB.
2. **Planted-garbage path**: deliberately
   `UPDATE wp_<pair>1.wp_wprism_kv SET v='not-json' WHERE k='promotion_lock'`
   and then run an acquire. Record which of the two happens:
   - the predicate evaluates false and the lease refuses cleanly (MariaDB
     semantics), or
   - the statement errors with 3141 and the agent surfaces a refusal whose
     message names the wrong cause.
   Run the identical planted-garbage case on MariaDB in the same session so
   the two envelopes can be diffed byte-for-byte.
3. **Both keys**: repeat for `promotion_session`, whose completion `DELETE`
   (`:819-826`) has the largest JSON predicate surface and the only
   `CAST(JSON_UNQUOTE(...) AS UNSIGNED)` on a non-`expires_at` field.

### Why no code change here

The engine-neutral fix (`JSON_VALID(v)` guards, or a `COALESCE` wrapper on
every extract) would change the shipped predicates' bytes and their refusal
behaviour on the **claimed** engine, on the strength of an unmeasured
hypothesis. It also cannot be pinned offline. If the probe shows 3141, the fix
belongs in the widening commit with the measured error quoted beside it.

---

## 3. `GET_LOCK` / `IS_USED_LOCK` / `RELEASE_LOCK` name length and semantics

### Exact SQL and names

| owner | name expression | ASCII bytes |
| --- | --- | --- |
| `agent/src/Kernel/ProcessFence.php::name()` | `NAME_PREFIX` plus SHA-256 hex truncated to `64 - strlen(NAME_PREFIX)` | **64** (`7 + 57`) |
| `agent/src/Init/InitConfirmation.php::acquire_init_lease()` | `'wprism-init:'` plus 48 SHA-256 hex characters | **60** (`12 + 48`) |
| `adapter-packages/woocommerce/package/runtime/providers/woocommerce-scheduler-settings.php::acquire_provider_mutex()` | `'wprism:woocommerce:scheduler:'` plus 32 SHA-256 hex characters | **61** (`29 + 32`) |

The original audit retained pre-branding arithmetic beside branded source:
the process fence actually emitted **66**, not 63, bytes (`7 + 59`). MySQL
rejected that exact product name during the Code Snippets conformance capture.
The init and WooCommerce names fit without changing their identity inputs.

Statements are `SELECT GET_LOCK(%s, 0)`, `SELECT IS_USED_LOCK(%s)`, and
`SELECT RELEASE_LOCK(%s)`. The kernel process fence is shared by capture and
promotion; init and the WooCommerce provider own separate connection-scoped
locks that can overlap it.

### MySQL 8.4 behaviour [wp-knowledge]

- MySQL 5.7+ caps a `GET_LOCK` name at **64 characters**; a longer name is an
  error. MariaDB allows up to **192**.
- MySQL 5.7+ allows a session to hold **multiple** named locks simultaneously
  (before 5.7, `GET_LOCK` released the session's previous lock). MariaDB
  10.0.2+ behaves the same way.

The process fence now derives its suffix budget from the prefix, and its
offline regression passes the actual product-derived name through a
64-byte-limited acquisition, continuity check, and release. A synthetic
63-character SQL probe cannot establish that product invariant.

`ProcessFence` also depends on the multi-lock semantics: `isContinuous()`
re-checks `CONNECTION_ID()` and `IS_USED_LOCK(name)` and treats a
connection change as a continuity break. It never assumes it is the session's
only lock — but `InitConfirmation` holds its own `wprism-init:` lock on the same
connection at overlapping times, which would be a real bug on a pre-5.7-style
"one lock per session" server. Both claimed/probed engines are past that.

Only an exact `GET_LOCK` result of `0` means contention; `1` means acquired.
NULL, driver errors, thrown transport failures, or malformed connection/lock
results produce `process_fence_unavailable`, with driver text excluded from
public output. Capture preserves that reason instead of rewriting every
fence failure to `capture_target_writer_active`. The existing contention and
lost-continuity messages remain unchanged.

### What the live probe must assert

1. On each claimed engine, derive `ProcessFence::name()` from the installed
   candidate and prove its length is at most 64. Acquire and verify that exact
   name, acquire a distinct bounded name on the same connection, and release
   the first while proving the second remains held.
2. `SELECT GET_LOCK(REPEAT('x',65),0)` — record the exact error, so the
   64-char bound is measured on this server rather than assumed.
3. A real `wprism init` and a real promotion on the MySQL pair, confirming
   `ProcessFence::assertHeld()` never raises *"promotion process fence is not
   continuously held by this database connection"* across a normal lifecycle.

### Offline evidence and generation-fenced cutover

`sandbox/tests/offline/cli/regress_typed_refusal_envelopes.php` exercises the
real fence name, acquisition/continuity transport failures, and the unchanged
contention versus unavailable JSON envelopes. The capture-owned
`sandbox/tests/offline/capture/regress_capture_atomicity.php` verifies that the
publication workflow preserves the unavailable result and claims no lock.

The corrected 64-byte name and the prior 66-byte name are different advisory
locks on a server that accepts both. They must not authorize concurrent old
and new command processes. Supported updates already prevent this: the
installed MU loader holds the stable directory generation fence for the full
WP-CLI process lifetime, while adoption takes its exclusive side before
replacing agent bytes. Use that update path, not an in-place file copy around
the loader; legacy pre-fence loaders retain the explicit quiescence
attestation described in [adoption](adoption.md). No dual-name lock, fallback,
or mixed-generation compatibility path is introduced.

---

## 4. `get_charset_collate()` and the `VARCHAR(191)` key width

### Exact SQL

`Ledger::ensure()` (`agent/src/Repository/Ledger.php:74-105`) appends
`$wpdb->get_charset_collate()` to all four `CREATE TABLE` statements:

- `wprism_map` — `uuid CHAR(36)`, `PRIMARY KEY (uuid, id_kind)`, `UNIQUE KEY
  kind_local (id_kind, local_id)`
- `wprism_state` — `uuid VARCHAR(64)`, `PRIMARY KEY (uuid)`
- `wprism_kv` — `k VARCHAR(191)`, `PRIMARY KEY (k)`
- `wprism_journal` — `item VARCHAR(191)`, plus its own indexes

191 is the utf8mb4 legacy figure (191 × 4 bytes ≤ the old 767-byte InnoDB
index-prefix limit). It is not a MySQL-vs-MariaDB difference by itself; the
difference is **which collation each engine negotiates**, because
`get_charset_collate()` returns whatever `DB_CHARSET`/`DB_COLLATE` and the
server default resolve to.

### MySQL 8.4 vs MariaDB 11 [wp-knowledge]

- MySQL 8's default utf8mb4 collation is `utf8mb4_0900_ai_ci`.
- MariaDB 11's is `utf8mb4_general_ci` or, on newer builds,
  `utf8mb4_uca1400_ai_ci`.

A collation difference changes **key comparison semantics** — which distinct
strings collide on `wprism_kv.k` and `wprism_map.uuid`. For hex/UUID keys the
practical risk is low (both are accent-insensitive, case-insensitive over
ASCII), but "low" is not "measured", and the repository's identity guarantees
rest on these keys being distinct.

### What the live probe must assert

1. `SHOW CREATE TABLE` for all four `wp_wprism_*` tables on **both** engines,
   saved to `sandbox/tmp/`, and diffed. The diff — charset, collation, index
   definitions, storage engine — is the record.
2. `SELECT @@character_set_database, @@collation_database` on the pair's
   database on both engines.
3. An identity round trip: capture → apply → re-capture on the MySQL pair
   produces a byte-identical repository to the MariaDB pair's. That is the
   assertion that actually matters; the `SHOW CREATE TABLE` diff explains any
   failure.

---

## 5. Authentication — the lane's gating unknown (not a dialect risk, but blocking)

`pair_db_ensure_app_user()` and `pair_db_create()`
(`sandbox/lib/pair_db.sh`) establish the same principal and exact pair-schema
authority on both engines (the MySQL arm additionally pins
`mysql_native_password`):

```sql
CREATE USER IF NOT EXISTS 'wordpress'@'%' IDENTIFIED BY 'wordpress';
GRANT PROCESS ON *.* TO 'wordpress'@'%';
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS wp_<name>1;
CREATE DATABASE IF NOT EXISTS wp_<name>2;
GRANT ALL PRIVILEGES ON wp_<name>1.* TO 'wordpress'@'%';
GRANT ALL PRIVILEGES ON wp_<name>2.* TO 'wordpress'@'%';
```

### MySQL 8.4 behaviour [wp-knowledge]

- MySQL 8.0 defaults new accounts to `caching_sha2_password`; 8.4 additionally
  ships `mysql_native_password` **disabled by default** (and MySQL 9 removes
  it), so the 8.0-era escape hatch `IDENTIFIED WITH mysql_native_password` may
  not be available at all on this server.
- PHP 8.3's mysqlnd supports `caching_sha2_password`, including the RSA
  public-key exchange path used over an unencrypted TCP connection.

If that handshake fails, **the entire lane is blocked** before any dialect
question is reachable — the WordPress containers simply cannot connect.

### What the live probe must assert (run FIRST, before any suite)

1. `SELECT user, host, plugin FROM mysql.user WHERE user='wordpress'` on
   `wprism-shared-mysql` — record which auth plugin the account actually got.
2. From a throwaway pair's `cli1` container: `wp db check`. A success proves
   mysqlnd completed the handshake; a failure must be captured verbatim,
   because it is what would justify adding an engine-conditional
   `IDENTIFIED WITH ...` clause to `pair_db_ensure_app_user()` — with the
   measured error quoted in its comment, never speculatively (AGENTS.md
   rule 9). The library already carries a comment recording exactly this
   decision.

---

## Summary table

| # | risk | code change now | live probe must produce |
| --- | --- | --- | --- |
| 1 | `VALUES(col)` × 6 sites | none | conflict-update still works + `SHOW WARNINGS` after each |
| 2 | `JSON_UNQUOTE(JSON_EXTRACT(...))` over `LONGTEXT` × 6 statement groups | none | normal cycle green; planted non-JSON row's envelope, both engines |
| 3 | `GET_LOCK` names 64 / 60 / 61 bytes | prefix-derived process-fence budget and typed unavailable result | actual product name round-trips; multi-lock holds; 65-char answer recorded |
| 4 | `get_charset_collate()` → `VARCHAR(191)` keys | none | `SHOW CREATE TABLE` diff, both engines; identical recapture |
| 5 | `caching_sha2_password` | none | `wp db check` from a throwaway pair — run before everything else |

The exact product-name failure justifies the bounded §3 correction; the other
SQL findings still require their named live evidence. The platform contract's
database axis is an engine-keyed map
(`agent/src/Policy/PlatformCompatibility.php`'s `valid_database_axis()` /
`engines_label()`) that claims MySQL `[8.4.0, 8.5.0)` beside MariaDB
`[11.0.0, 12.0.0)`. Before that widening a MySQL pair refused
`platform_unsupported` on the engine axis before any statement below was ever
reached, which is exactly why probe #4's *"real round trip"* and probe #2's
*"real acquire cycle"* were unreachable; they are reachable now, and until
they have run the claim's own note says so in the word PENDING.

Sequence the live phase accordingly: §5 → §3 → §1 → §2 (raw SQL, no agent
needed) first, then the agent-level round trips. If any probe fails, the
remedy named in `platform/adapter-library/capabilities/platform.json`'s database note applies
— drop the MySQL entry and restore a MariaDB-only engines map — never a
fallback or a widened bound around the failure (AGENTS.md rule 9).
