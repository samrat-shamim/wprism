# The branch-environment provider protocol

**Generated — never hand-edited.** Written by `tools/provider-protocol-doc.php` from
`cli/src/Environment/EnvironmentProviderProtocol.php`, `EnvironmentProviderCapability`
and `CommandEnvironmentProvider`; `make release-gate` byte-compares it. Edit the code,
then run `php tools/provider-protocol-doc.php generate`.

Duo orchestrates providers; it does not supply hosting. A **branch-environment
provider** is a program on the machine that runs `duo`, which can snapshot a source
environment, acquire a disposable target, materialize a repository into it, and reap
it again. `duo env materialize`, `duo env reap` and `duo rehearse` refuse without one.
This document is the whole contract; `tools/reference-env-provider.php` is a worked
example of it, not a second specification.

Before pointing `duo env materialize` at production, run the harness:

```
duo env provider-check <env>                      # non-mutating: config, capabilities, one inspect
duo env provider-check <env> --cycle --from <disposable-src> --confirm-disposable
```

The harness drives your provider through the same `CommandEnvironmentProvider` the
orchestrator uses, so it cannot send a request the product would not send.
`--cycle` FREEZES the named source with `snapshot-prepare`, which is why
`--confirm-disposable` is mandatory and never inferred.

## 1. Where a provider is configured

The `environment_provider` block is privileged host configuration. It is accepted
**only** from the machine-local `.duo-envs.json`, never from the shared, committed
`site.duo.json`:

```json
{
  "envs": {
    "<env>": {
      "transport": "local",
      "wp_path": "/absolute/path/to/wordpress",
      "repo_path": "/absolute/path/to/site-repo",
      "environment_provider": {
        "command": ["/absolute/path/to/provider", "/absolute/path/to/config.json"],
        "timeout_seconds": 30
      }
    }
  }
}
```

This is a complete one-environment local registry shape; use the required transport
keys for SSH or Docker instead when that is how the environment is reached.

* the object's key set is exactly `{command, timeout_seconds}`;
* `command` is a non-empty argv **list** — it is executed with `bypass_shell`, so
  there is no shell, no word splitting and no PATH lookup;
* `command[0]` must be absolute;
* `timeout_seconds` is an integer in **1..60**, applied per action.

## 2. The wire

One request object plus a newline on stdin; one response object plus a newline on
stdout; exit 0. Both are **canonical JSON**: object keys sorted bytewise, no
insignificant whitespace, `/` unescaped. The orchestrator re-encodes the response it
parsed and compares bytes, so any other spacing or key order is refused as
noncanonical evidence. stdout plus stderr may not exceed 1,048,576 bytes, and
provider output is redacted on every failure — it may carry host or production-data
diagnostics, so it never reaches an operator's terminal or the journal.

**Request** — `duo-branch-environment-provider-request/v1`, key set exactly:

| field | value |
|---|---|
| `action` | one of the 18 names in §5 |
| `environment` | the registry name of the environment being acted on |
| `format` | `duo-branch-environment-provider-request/v1` |
| `input` | the per-action object in §5 — the empty JSON list `[]` for `capabilities` |
| `operation_id` | one opaque `[A-Za-z0-9._:@+-]{8,256}` id, stable for a whole operation |

**Response** — `duo-branch-environment-provider-response/v1`, key set exactly:

| field | value |
|---|---|
| `action` | echoed verbatim |
| `environment` | echoed verbatim |
| `format` | `duo-branch-environment-provider-response/v1` |
| `operation_id` | echoed verbatim |
| `provider` | exactly `{id, protocol}`; `id` matches `[A-Za-z0-9._:@+-]{1,128}`, `protocol` is the integer `1` |
| `result` | the per-action **closed** object in §5 |
| `status` | `ok` — a provider reports failure by exiting non-zero, never by a status value |

`provider` must not change for the life of an `operation_id`: a same-named
environment whose provider identity moved is not a continuation of the journaled
operation. `result` key sets are **closed** — a missing field and an extra field are
the same refusal.

`input` is deliberately **not** closed. Refuse when a key you need is absent; never
refuse merely because a key you ignore is present.

## 3. Capabilities

`capabilities` is the first action of every operation. Advertise only what you
implement: serving `create` with an attach, or `destroy` with a detach, converts a
missing capability into a silent data-loss class. Refuse any action whose id you did
not advertise, and name that id.

The 19 ids:

* `environment.attach`
* `environment.create`
* `environment.destroy`
* `environment.detach`
* `environment.inspect`
* `environment.mutation.acquire`
* `environment.mutation.read`
* `environment.mutation.release`
* `environment.ttl`
* `environment.ttl.read`
* `environment.url.discover`
* `environment.url.set`
* `operation.receipts`
* `repository.materialize`
* `snapshot.set.abort`
* `snapshot.set.create`
* `snapshot.set.prepare`
* `snapshot.set.read`
* `snapshot.set.restore`

## 4. What each operation requires

Each row is a set the orchestrator checks before it acts. The operation phrase is
the text an operator sees after `cannot ` in
`environment provider '<id>' cannot <operation>; missing <ids>`.

| side | operation | required ids | also required when |
|---|---|---|---|
| target | materialize a create branch environment | `environment.create`, `environment.destroy`, `environment.inspect`, `environment.mutation.acquire`, `environment.mutation.read`, `environment.mutation.release`, `environment.url.discover`, `environment.url.set`, `operation.receipts`, `repository.materialize`, `snapshot.set.restore` | `environment.ttl`, `environment.ttl.read` when `duo env materialize --ttl <seconds>` is given |
| target | materialize a attach branch environment | `environment.attach`, `environment.detach`, `environment.inspect`, `environment.mutation.acquire`, `environment.mutation.read`, `environment.mutation.release`, `environment.url.discover`, `environment.url.set`, `operation.receipts`, `repository.materialize`, `snapshot.set.restore` | `environment.ttl`, `environment.ttl.read` when `duo env materialize --ttl <seconds>` is given |
| source | materialize a coherent production snapshot | `environment.inspect`, `operation.receipts`, `snapshot.set.abort`, `snapshot.set.create`, `snapshot.set.prepare`, `snapshot.set.read` | — |
| target | reap a create branch environment | `environment.destroy`, `environment.inspect`, `environment.mutation.acquire`, `environment.mutation.read`, `environment.mutation.release`, `operation.receipts` | `environment.ttl.read` when the materialization journaled a `ttl-set` phase |
| target | reap a attach branch environment | `environment.detach`, `environment.inspect`, `environment.mutation.acquire`, `environment.mutation.read`, `environment.mutation.release`, `operation.receipts` | `environment.ttl.read` when the materialization journaled a `ttl-set` phase |
| source | reap an unfinished source snapshot session | `operation.receipts`, `snapshot.set.abort`, `snapshot.set.prepare` | — |
| target | recover a lost target create response | `environment.create`, `operation.receipts` | — |
| target | recover a lost target attach response | `environment.attach`, `operation.receipts` | — |

## 5. The 18 actions

### `capabilities`

Gated by **nothing** — negotiation cannot be gated on a negotiated capability.

The negotiation probe is the one request whose `input` is the empty JSON LIST `[]` rather than an object.

**Request `input`:** none.

**Response `result`** (closed set):

| field | value |
|---|---|
| `capabilities` | a JSON list of capability id strings |

### `inspect`

Gated by `environment.inspect`.

The identity expectations are sent only once the orchestrator already holds a target identity (EnvironmentLifecycle.php:1319, :1588); the very first source inspect carries `role` alone (:1005).

**Request `input`:**

| field | value |
|---|---|
| `role` | one of "source", "target" |

Additionally, in the phases named above:

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `presence` | one of "present", "absent" |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `attach`

Gated by `environment.attach`.

EnvironmentLifecycle.php:1139-1142. The same object is replayed verbatim on recovery, so acquisition must be idempotent under `intent_sha256`.

**Request `input`:**

| field | value |
|---|---|
| `intent_sha256` | a lowercase 64-character hex SHA-256 |
| `mode` | exactly "attach" |
| `target_environment` | a registry environment name |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `presence` | exactly "present" |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `create`

Gated by `environment.create`.

EnvironmentLifecycle.php:1139-1142. The same object is replayed verbatim on recovery, so acquisition must be idempotent under `intent_sha256`.

**Request `input`:**

| field | value |
|---|---|
| `intent_sha256` | a lowercase 64-character hex SHA-256 |
| `mode` | exactly "create" |
| `target_environment` | a registry environment name |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `presence` | exactly "present" |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `snapshot-prepare`

Gated by `snapshot.set.prepare`.

EnvironmentLifecycle.php:1017. This is the action that FREEZES the named source; the session id is deterministic per operation so a lost response is retryable and abortable.

**Request `input`:**

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `source_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

### `snapshot-create`

Gated by `snapshot.set.create`.

EnvironmentLifecycle.php:1074-1082. `semantic_snapshot_sha256` in the result must echo `expected_semantic_snapshot_sha256` exactly.

**Request `input`:**

| field | value |
|---|---|
| `expected_semantic_snapshot_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_lease_generation` | a JSON integer >= 1 |
| `expected_source_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_lease_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `production_commit` | a 40- or 64-character lowercase hex Git object id |

**Response `result`** (closed set):

| field | value |
|---|---|
| `database_sha256` | a lowercase 64-character hex SHA-256 |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `media_sha256` | a lowercase 64-character hex SHA-256 |
| `retention_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `semantic_snapshot_sha256` | a lowercase 64-character hex SHA-256 |
| `snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `snapshot_set_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `snapshot_set_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `source_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

### `snapshot-abort`

Gated by `snapshot.set.abort`.

EnvironmentLifecycle.php:2141-2145. Aborting must release the source freeze and discard any immutable set the same session created.

**Request `input`:**

| field | value |
|---|---|
| `expected_snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_lease_generation` | a JSON integer >= 1 |
| `expected_source_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_lease_receipt_sha256` | a lowercase 64-character hex SHA-256 |

**Response `result`** (closed set):

| field | value |
|---|---|
| `disposition` | exactly "aborted" |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `source_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

### `snapshot-read`

Gated by `snapshot.set.read`.

EnvironmentLifecycle.php:1113-1121. `immutable: true` is a claim that the set was re-read from storage, not remembered.

**Request `input`:**

| field | value |
|---|---|
| `expected_snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_snapshot_set_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_snapshot_set_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_source_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_lease_generation` | a JSON integer >= 1 |
| `expected_source_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_source_lease_receipt_sha256` | a lowercase 64-character hex SHA-256 |

**Response `result`** (closed set):

| field | value |
|---|---|
| `database_sha256` | a lowercase 64-character hex SHA-256 |
| `immutable` | JSON true |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `media_sha256` | a lowercase 64-character hex SHA-256 |
| `retention_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `semantic_snapshot_sha256` | a lowercase 64-character hex SHA-256 |
| `snapshot_session_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `snapshot_set_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `snapshot_set_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `source_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

### `snapshot-restore`

Gated by `snapshot.set.restore`.

EnvironmentLifecycle.php:1210-1214. Restoring another set than `snapshot_set_id` is refused by the orchestrator on readback (:1220).

**Request `input`:**

| field | value |
|---|---|
| `database_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `media_sha256` | a lowercase 64-character hex SHA-256 |
| `snapshot_set_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `snapshot_set_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `repository-materialize`

Gated by `repository.materialize`.

EnvironmentLifecycle.php:1223-1228. The result `branch_commit` must equal the requested one (:1232).

**Request `input`:**

| field | value |
|---|---|
| `branch_commit` | a 40- or 64-character lowercase hex Git object id |
| `branch_ref` | a Git ref name |
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `repo_path` | an absolute filesystem path on the orchestrating host |

**Response `result`** (closed set):

| field | value |
|---|---|
| `branch_commit` | a 40- or 64-character lowercase hex Git object id |
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `repository_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `url-set`

Gated by `environment.url.set`.

EnvironmentLifecycle.php:1234-1238. The URL is the provider-owned one it discovered itself; the readback must match it (:1241).

**Request `input`:**

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `mutation-acquire`

Gated by `environment.mutation.acquire`.

EnvironmentLifecycle.php:1148. Acquisition is idempotent per `mutation_owner`: a re-acquire by the SAME owner returns the same fence, a different owner must refuse.

**Request `input`:**

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_generation` | a JSON integer >= 1 |
| `mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `state` | exactly "held" |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `mutation-read`

Gated by `environment.mutation.read`.

EnvironmentLifecycle.php:1195. A readback may never mint a new `mutation_receipt_sha256` (:2027-2033).

**Request `input`:**

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_generation` | a JSON integer >= 1 |
| `mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `state` | one of "held", "released" |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `mutation-release`

Gated by `environment.mutation.release`.

EnvironmentLifecycle.php:1347. The held -> released acknowledgement is the ONE transition allowed to mint a new receipt for the same lineage.

**Request `input`:**

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_generation` | a JSON integer >= 1 |
| `mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `state` | exactly "released" |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `ttl-set`

Gated by `environment.ttl`.

EnvironmentLifecycle.php:1327. A TTL is observable expiry metadata only; expiry never implies the resource may be reused or reaped without the explicit fenced reap action.

**Request `input`:**

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ttl_seconds` | a JSON integer >= 1 |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expires_at` | canonical UTC seconds, YYYY-MM-DDTHH:MM:SSZ |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ttl_generation` | a JSON integer >= 1 |
| `ttl_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ttl_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `ttl_state` | exactly "active" |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `ttl-read`

Gated by `environment.ttl.read`.

The mutation expectations are present while the materialization fence is still held (EnvironmentLifecycle.php:1335) and absent on the reap readback (:1593).

**Request `input`:**

| field | value |
|---|---|
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_expires_at` | canonical UTC seconds, YYYY-MM-DDTHH:MM:SSZ |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_ttl_generation` | a JSON integer >= 1 |
| `expected_ttl_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_ttl_receipt_sha256` | a lowercase 64-character hex SHA-256 |

Additionally, in the phases named above:

| field | value |
|---|---|
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |

**Response `result`** (closed set):

| field | value |
|---|---|
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expires_at` | canonical UTC seconds, YYYY-MM-DDTHH:MM:SSZ |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ttl_generation` | a JSON integer >= 1 |
| `ttl_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ttl_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `ttl_state` | exactly "active" |
| `url` | a credential-free http(s) base URL under 2048 bytes with no query or fragment |

### `destroy`

Gated by `environment.destroy`.

EnvironmentLifecycle.php:1735. Note the result carries NO `url`: after a reap there is no environment to address.

**Request `input`:**

| field | value |
|---|---|
| `compare_and_reap` | JSON true |
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `absence_proof_sha256` | a lowercase 64-character hex SHA-256 |
| `disposition` | exactly "destroyed" |
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

### `detach`

Gated by `environment.detach`.

EnvironmentLifecycle.php:1735. Serving `detach` where `destroy` was asked (or the reverse) converts a missing capability into a silent data-loss class; the disposition is what proves which one ran.

**Request `input`:**

| field | value |
|---|---|
| `compare_and_reap` | JSON true |
| `expected_environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_lease_generation` | a JSON integer >= 1 |
| `expected_lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_generation` | a JSON integer >= 1 |
| `expected_mutation_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_owner` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `expected_mutation_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `expected_resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

**Response `result`** (closed set):

| field | value |
|---|---|
| `absence_proof_sha256` | a lowercase 64-character hex SHA-256 |
| `disposition` | exactly "detached" |
| `environment_identity` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `lease_generation` | a JSON integer >= 1 |
| `lease_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |
| `ownership_receipt_sha256` | a lowercase 64-character hex SHA-256 |
| `resource_id` | an opaque identifier matching [A-Za-z0-9._:@+-]{8,256} |

## 6. Every refusal, and what earns it

`<…>` stands for an interpolated value. These are the operator-visible bytes;
provider stdout/stderr is never among them.

| refusal | cause |
|---|---|
| `env '<env>': branch materialization requires machine-local environment_provider configuration` | the environment has no `environment_provider` object at all |
| `env '<env>': environment_provider is privileged host configuration and is allowed only in .duo-envs.json` | the block was found in the shared, committed `site.duo.json` |
| `env '<env>': environment_provider has missing or unknown fields` | the block is not exactly `{command, timeout_seconds}` |
| `env '<env>': environment_provider.command must be a non-empty argv array` | `command` is a string, an object, or empty |
| `env '<env>': environment_provider.command[<i>] is invalid` | an argv element is not a non-empty NUL-free string |
| `env '<env>': environment_provider executable must be absolute` | argv[0] does not begin with `/` — no PATH lookup happens here |
| `env '<env>': environment_provider.timeout_seconds must be 1..60` | `timeout_seconds` is not an integer in 1..60 |
| `could not start environment provider` | argv could not be executed |
| `environment provider failed; provider output is redacted` | the process exited non-zero |
| `environment provider timed out; provider output is redacted` | no complete response within `timeout_seconds` |
| `environment provider output exceeded the redacted evidence limit` | stdout+stderr exceeded 1,048,576 bytes |
| `environment provider returned malformed JSON` | stdout did not parse as JSON |
| `environment provider returned noncanonical evidence` | re-encoding the parsed response canonically did not reproduce stdout byte for byte |
| `environment provider response has missing or unknown fields` | the response object is not exactly the seven top-level keys |
| `environment provider response is not bound to the request` | `format`, `action`, `environment`, `operation_id` or `status` does not match what was sent |
| `environment provider identity is malformed` | `provider` is not a JSON object |
| `environment provider identity has missing or unknown fields` | `provider` is not exactly `{id, protocol}` |
| `environment provider id is invalid` | `provider.id` is not `[A-Za-z0-9._:@+-]{1,128}` |
| `environment provider protocol must be 1` | `provider.protocol` is not the integer 1 |
| `environment provider result must be an object` | `result` is a non-empty JSON list or a scalar |
| `environment provider capabilities must be a list` | the `capabilities` result field is not a JSON list |
| `environment provider capability IDs must be strings` | a capability id is not a string |
| `environment provider '<id>' declared unknown capability '<cap>'` | an advertised id is outside the vocabulary below |
| `environment provider '<id>' repeated a capability` | the advertised list has a duplicate |
| `environment provider '<id>' cannot <operation>; missing <ids>` | the advertised set does not cover a requirement set below |
| `environment provider identity changed during capability negotiation` | `provider.id`/`provider.protocol` moved between two `capabilities` calls |
| `environment provider identity changed after capability negotiation` | `provider` moved between negotiation and an action |
| `environment provider capabilities must be negotiated before operations` | an action was attempted before `capabilities` (an orchestrator-side invariant, listed so a provider author can rely on the ordering) |
| `<action> result has missing or unknown fields` | the result key set is not exactly the action's closed set below |
| `environment provider <label> is invalid` | an identifier field is not `[A-Za-z0-9._:@+-]{8,256}` |
| `environment provider <label> must be a SHA-256 digest` | a digest field is not 64 lowercase hex characters |
| `environment provider <label> must be a positive integer` | a generation field is not a JSON integer >= 1 |
| `environment provider branch commit is invalid` | `branch_commit` is not a 40- or 64-character lowercase hex oid |
| `environment provider expiry must be canonical UTC seconds` | `expires_at` is not `YYYY-MM-DDTHH:MM:SSZ` |
| `environment provider URL must be a credential-free HTTP(S) base URL without query or fragment` | `url` carries userinfo, a query, a fragment, a non-HTTP scheme, or exceeds 2048 bytes |
| `environment provider <action> returned invalid presence` | `presence` is not `present`/`absent`, or `attach`/`create` returned `absent` |
| `environment provider snapshot-read did not prove immutable readback` | `immutable` is not JSON `true` |
| `environment provider snapshot-abort returned wrong disposition` | `disposition` is not `aborted` |
| `environment provider <action> returned an invalid mutation fence state` | `state` does not match the action (`acquire` -> `held`, `release` -> `released`) |
| `environment provider <action> did not return an active TTL lease` | `ttl_state` is not `active` |
| `environment provider <action> returned wrong disposition` | `destroy` did not answer `destroyed`, or `detach` did not answer `detached` |
| `unknown environment provider action '<action>'` | an action outside the 18 below |

## 7. The worked example

`tools/reference-env-provider.php` implements all 18 actions against Duo's own
sandbox pair (`sandbox/bin/pair.sh` over the shared MariaDB). It is **DEV-ONLY** —
`tools/` never ships, and `cli/src/Onboarding/Adopt.php` tars only `agent`,
`manifests` and `recovery` — so read it as a demonstration of this document, not as
an artifact you can deploy. Its `--print-plan` flag runs the same negotiation and
argument validation and prints the command boundary an action would use, executing
nothing.

Two habits it demonstrates that this contract does not spell out but every operation
depends on:

* **idempotency per `operation_id`.** Any response may be lost after the work
  happened. Every action is replayed with a byte-identical `input`, and must return
  the same evidence rather than doing the work twice.
* **never emulate.** Refuse an action you cannot perform exactly, naming the
  capability id. A near-miss is worse than a refusal at every one of these actions.
