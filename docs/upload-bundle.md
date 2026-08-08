# Upload and media rollback bundles

The upload provider is the filesystem/object-storage slice of verified SSH
rollback. It runs behind the adopted WordPress-independent recovery runtime.
Duo owns the immutable compile inventory, signed authorization, evidence
validation, bounded mutation journal, exact-absence rules, fresh verification,
and retention fence. The target provider owns local storage and any offload
API or credentials.

This capability does not make the existing in-place `wp duo apply` path
automatically recoverable. The automatic profile must route its upload writes
through `storage_apply`; DUO-3299 owns that end-to-end composition.

## Configuration and preflight

Add one absolute argv vector under `rollback_recovery`:

```json
{
  "upload_provider": ["/opt/duo/bin/upload-provider", "production"]
}
```

`recovery-probe` requires canonical `duo-upload-provider-response/v1`
evidence that the provider supports local storage, offload storage, or both;
encrypts before-images; leaves no durable plaintext; performs read-after-
restore verification; and emits no credentials. Missing support is a hard
pre-mutation block. Without this provider, status reports
`automatic_upload_rollback: false` and `upload_recovery: manual`.

## Compile inventory and preparation

The immutable compiled artifact and `duo plan` expose every attachment
original as well as its only permitted derivative directory/basename prefix.
This happens offline, before target contact. The declaration does not grant
wildcard deletion authority: target preparation resolves it into an exact
prior inventory.

After maintenance exclusion is held, the controller sends a signed
`duo-upload-bundle-request/v1` prepare request with that compile inventory.
The provider must reject symlinks, non-regular entries, traversal, duplicate
paths, unsupported backends, or paths outside the declared roots. It writes:

- `desired-inventory.json`, the exact compile declaration;
- `prior-inventory.json`, an exact present/absent record for every original
  plus every existing bounded derivative; and
- `before-images.enc`, authenticated encrypted bytes for local files and any
  offload object without a provider-native immutable version id.

Every present local path requires an encrypted before-image. Every present
offload object requires a native version id or exact encrypted bytes. An
absent row has neither and is only evidence that the exact path was absent.
Duo independently hashes and validates the artifacts. The complete metadata
hash becomes the signed receipt's existing `uploads_inventory_sha256`.

## Journaled mutation and derivative reporting

`storage_apply` runs only in `promoting` with an open signed `prepared`
operation whose input hash, receipt, generation, target, owner, artifact,
claimant, and epoch match. Its provider maintains a durable per-path journal:
every publish, replacement, or removal is recorded `prepared` before the
write and `completed` only after readback.

Attachment metadata generation must report every created, replaced, and
removed derivative. Duo accepts only the declared original or a path inside
that attachment's compiled derivative root. An undeclared derivative,
incomplete event, wrong original hash, changed path type, or concurrent writer
keeps the operation open and non-green. Provider reports and runtime errors
must not include signed URLs, authorization tokens, credentials, or secrets.

## Restore and fresh verification

`storage_restore` runs only in `rolling_back` under the same signed-operation
gate. Existing prior paths are restored atomically from verified before-images
or native object versions. A path proven absent may be deleted only when its
current hash still equals the completed mutation's after-hash; a replacement
by another writer is a loud refusal, never deletion authority.

After restore, Duo starts a second provider process for `verify-prior`. Every
original and derivative must match the frozen prior inventory, including exact
absence. Only the identical prior-inventory digest may complete the signed
operation. Disconnects before/after local writes, offload writes, derivative
generation, restores, and deletions remain retryable under the same journal.

## Retention

Encrypted before-images and inventory evidence remain immutable until the
signed generation is `committed` or `rolled_back` and its retention deadline
has elapsed. A signed delete request then invokes provider cleanup and leaves
a hash-only `duo-upload-bundle-tombstone/v1`. Early, active, foreign, or
changed-generation deletion is refused.
