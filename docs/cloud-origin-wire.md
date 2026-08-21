# Duo Cloud origin wire

Status: implementation contract for Universal Duo Cloud Preview. These are
closed endpoint schemas, not an extensible remote-action protocol. Unknown
fields, formats, states, or identities refuse.

## Envelope and transport

Origin traffic is outbound HTTPS POST to a shipped credential-free base URL.
Redirects, query parameters, fragments, Basic/Bearer authentication, and
caller-selected callback URLs are forbidden. Offline tests may substitute an
HTTP loopback endpoint only while `DUO_TEST_MODE` is set.

Requests and responses use exactly:

```json
{"format":"duo-cloud-origin-signed-envelope/v1","key_id":"...","payload":{},"signature":"..."}
```

Objects are recursively byte-key-sorted compact JSON, arrays preserve order,
floats are forbidden, and the envelope has one trailing LF. The Ed25519
signature covers the canonical payload bytes without that LF. Base64 must
round-trip canonically. Every response is signed by the pre-paired service key
and includes `request_sha256`, the SHA-256 of its exact request payload bytes.
The service key echoed during pairing cannot replace that trust anchor.

The origin key id is SHA-256 of
`"duo-cloud-origin-key/v1\0" || raw_public_key`. Other request ids are SHA-256
of the named NUL-delimited domain and fields below; integers use decimal text.
The client durably records each sequence/attempt before sending it. A service
stores `(key_id, request_id, request_sha256)` before effects: exact retries
return the cached signed response, while the same id with changed bytes
refuses.

No endpoint below contains `action`, `script`, `argv`, SQL, an executable
selector, or a remotely supplied filesystem path.

## Pair

`POST /v1/origin/pair/begin`

- request format `duo-cloud-origin-pair-begin-request/v1`;
- exact fields `connector`, `device_code`, `format`, `origin_key`,
  `pair_attempt_id`, `request_id`;
- `connector` is exactly `agent_version`, `home_url_sha256`, `installation_id`,
  `multisite`, `php_version`, `site_url_sha256`, `wordpress_version`;
- `origin_key` is exactly `algorithm: Ed25519`, `key_id`, `public_key`;
- `pair_attempt_id` is 16 random bytes as lowercase hex;
- request id domain is `duo-cloud-origin-pair-begin/v1`, followed by attempt
  and origin-key ids.

The signed response format is `duo-cloud-origin-pair-begin-response/v1`, with
exact fields `expires_at`, `format`, `origin_key_id`, `pair_attempt_id`,
`pairing_id`, `poll_after_seconds`, `request_sha256`, `state`; state is
`pending`.

The user-facing device code is 20 RFC 4648 base32 symbols rendered as four
groups of five, expires within ten minutes, and is consumed exactly once by
the authenticated service. The connector never persists or logs it.

`POST /v1/origin/pair/poll`

- request format `duo-cloud-origin-pair-poll-request/v1`;
- exact fields `format`, `origin_key_id`, `pair_attempt_id`, `pairing_id`,
  `poll_sequence`, `request_id`;
- request id binds attempt, pairing, and sequence;
- the sequence advances only after a durable response.

The response format is `duo-cloud-origin-pair-poll-response/v1`, with exact
fields `expires_at`, `format`, `origin_key_id`, `pair_attempt_id`, `pairing`,
`pairing_id`, `poll_after_seconds`, `poll_sequence`, `request_sha256`, `state`.
State is `pending`, `paired`, `denied`, or `expired`. `pairing` is null unless
paired, then is exactly `demand_generation`, `origin_generation`,
`service_key_id`, `service_public_key_sha256`, `site_id`, `tenant_id`.

The connector publishes the tenant/site binding only after it has verified the
signed paired response, service-key pin, and a non-decreasing site-wide demand
generation. Re-pairing rotates the origin generation but never resets the
demand generation.

## Demand

`POST /v1/origin/demand/poll` uses request format
`duo-cloud-origin-demand-poll-request/v1` with exact fields
`after_demand_generation`, `format`, `origin_generation`, `origin_key_id`,
`poll_sequence`, `request_id`, `site_id`, `tenant_id`.

The response format is `duo-cloud-origin-demand-poll-response/v1` with exact
fields `after_demand_generation`, `demand`, `format`, `origin_generation`,
`origin_key_id`, `poll_after_seconds`, `poll_sequence`, `request_sha256`,
`site_id`, `state`, `tenant_id`. State is `idle`, `demanded`, or `revoked`.

A demanded job is exactly:

```text
chunk_size, demand_generation, demand_id, expires_at,
expected_production_commit, format, nonce, retention_deadline, snapshot_mode
```

Its format is `duo-cloud-origin-export-demand/v1`, mode is
`portable-refresh`, chunk size is 1,048,576, nonce is 32 canonical-base64
bytes, and the expected commit is a lowercase 40- or 64-hex Git oid. The demand
id binds tenant, site, origin generation, demand generation, nonce, and commit.
Only `last_terminal_generation + 1` is accepted. The connector persists an
accepted demand before reading WordPress.

## Sealed export

The local session id binds tenant, site, origin generation, demand generation,
demand id, and expected commit under domain `duo-cloud-origin-session/v1`.
Database observation is one read-only consistent-snapshot process. A crash
before local publication starts a new observation; once published, all retries
read only the sealed artifact.

The upload manifest format is `duo-cloud-origin-export-manifest/v1`, with exact
fields:

```text
artifact_hash, chunks, code_revision, expected_production_commit,
export_sha256, export_size, format, generation, manifest_sha256,
repository_revision_hash, snapshot_hash
```

Chunks are ordered exact objects `index`, `offset`, `sha256`, `size`; indices
start at zero, offsets are contiguous, every non-final chunk is 1 MiB, the
final chunk is non-empty, and concatenation verifies the total size/hash.
`manifest_sha256` is the SHA-256 of agent `Canon::encode()` over the manifest
without that field. Repository/artifact/code identities are copied from the
verified `duo-refresh-production/v1` export.

## Upload

`POST /v1/origin/export/announce` request format is
`duo-cloud-origin-export-announce-request/v1`, exact fields
`demand_generation`, `demand_id`, `format`, `manifest`, `origin_generation`,
`origin_key_id`, `request_id`, `site_id`, `tenant_id`. Its response format is
`duo-cloud-origin-export-announce-response/v1`, exact fields
`demand_generation`, `demand_id`, `export_id`, `format`, `manifest_sha256`,
`origin_generation`, `origin_key_id`, `request_sha256`, `site_id`, `state`,
`tenant_id`; state is `announced`.

`POST /v1/origin/export/missing` uses request/response formats
`duo-cloud-origin-export-missing-{request,response}/v1`. The request contains
the export identities plus `query_sequence` and `request_id`. The response
echoes them plus `request_sha256` and `missing_chunk_sha256`, which is the
sorted, unique, exact set of currently absent manifest digests. Presence is
tenant/site scoped so it cannot become a cross-tenant deduplication oracle.

`POST /v1/origin/export/chunk` request format is
`duo-cloud-origin-export-chunk-request/v1`, with exact fields `chunk_base64`,
`chunk_sha256`, `chunk_size`, the export/demand/origin/site/tenant identities,
and `request_id`. The response format is the corresponding `response/v1` with
the same identities, `request_sha256`, and state `stored`. The service verifies
canonical base64, size, digest, manifest membership, write, and readback before
publication. Receipts never store the body.

`POST /v1/origin/export/commit` request format is
`duo-cloud-origin-export-commit-request/v1`, containing only the export,
demand, origin, site, tenant, manifest, and request identities. The service
requires no missing chunks, rechecks every descriptor and the assembled
canonical export, then atomically publishes the immutable snapshot. The signed
response format is `duo-cloud-origin-export-commit-response/v1`, exact fields
`commit_receipt_sha256`, all request identities, `request_sha256`,
`retention_deadline`, `snapshot_hash`, and state `committed`.

## Rotation and revocation

The public controller surface is closed to `duo origin rotate <env>` and
`duo origin uninstall <env>`. They map only to protected
`wp duo origin-rotate` and `wp duo origin-uninstall` connector commands through
the isolated control-plane bootstrap; the target always returns the exact
`duo-cloud-origin-command/v1` document and the controller republishes no
unvalidated target field or diagnostic.

`POST /v1/origin/key/rotate` is allowed only while idle. The old key signs a
`duo-cloud-origin-key-rotation-request/v1` request; a nested signature by the
new key proves possession of the exact tenant/site, generations, rotation id,
and new key id. The service atomically activates generation `current + 1` and
tombstones the old key. Only an exact cached rotation response remains
available to the old key. The connector holds its upload-journal lock from the
local idle proof through the durable pairing-generation rebind. A polling,
accepted, sealed, uploading, committing, or cleaning journal refuses before a
rotation request is sent. One pairing state admits one deterministic rollover;
later calls replay its cached receipt. A later planned rollover requires revoke
and a fresh pairing, which creates the next distinct rotation intent.

`POST /v1/origin/revoke` accepts reason `administrator_requested` or
`uninstall`, atomically revokes the binding and active export authority, and
returns a signed `duo-cloud-origin-revoke-response/v1` receipt. The old key can
retrieve only that exact cached receipt. `duo origin uninstall` first journals
cleanup intent and crash-safely removes any recoverable immutable spool, then
sends the fixed `uninstall` reason while still holding the upload lock. Exact
retries resume cleanup and replay the same signed revocation request; ordinary
`duo origin revoke` retains the distinct `administrator_requested` reason and
request identity. The upload journal binds that reason before it abandons even
an in-flight demand poll, so a crash before the pairing store records its own
intent cannot reopen export, rotation, pairing, or the conflicting revocation.

Authorized state refusals use HTTP 200 and signed
`duo-cloud-origin-refusal/v1` payloads with exact fields `format`,
`reason_code`, `request_format`, `request_sha256`, `retryable`. The closed
codes are `origin_revoked`, `pairing_code_rejected`, `stale_origin_generation`,
`stale_demand_generation`, `demand_expired`, `manifest_conflict`,
`chunk_not_announced`, `commit_incomplete`, `recovery_required`, and
`rate_limited`. Malformed, oversized, unknown-key, or invalid-signature input
gets a generic unsigned 4xx response whose body is never exposed locally.
