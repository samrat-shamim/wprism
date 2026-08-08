# Immutable SSH code releases

The code-release provider is the code before-image and atomic-selection slice
of verified SSH rollback. It runs behind the adopted, WordPress-independent
recovery runtime. Duo owns signed authorization, immutable descriptor
validation, receipt/generation binding, retry semantics, and exact evidence;
the target provider owns immutable release storage and the live pointer.

This capability does not make the existing in-place `wp duo code-stage` /
`code-finalize` materializer automatically recoverable. That path remains an
explicit manual-recovery profile. The automatic profile requires
`code_release_provider` and the remaining storage/lifecycle/integration slices.

## Configuration and preflight

Add an absolute argv vector under `rollback_recovery`:

```json
{
  "code_release_provider": ["/opt/duo/bin/code-release-provider", "production"]
}
```

The provider reads and writes canonical newline-terminated JSON. Its `probe`
must attest immutable releases, complete verified descriptors, an atomic
pointer, target-generation fencing, off-target build and dependency
resolution, no mutable resolution, and no target Git history or registry
credentials. A missing provider leaves `automatic_code_rollback: false` and
`code_recovery: manual`; Duo never infers support from a filesystem layout.

## Prepared releases and receipt binding

After fail-closed exclusion is reserved and before the signed receipt is
published, the controller sends a signed `duo-code-release-request/v1`
`prepare` request. It binds the exact target, next generation, receipt, owner,
artifact, claimant epoch, desired code revision, complete desired-descriptor
hash, and retention deadline.

The provider must already have an immutable desired release built and resolved
off-target and an exact immutable prior release selected. It writes canonical
desired and prior descriptors at the runtime-supplied paths. Each
`duo-code-release-descriptor/v1` records:

- the outer compiled artifact and separate code revision;
- release id, role, and target generation;
- all owned roots; and
- every owned directory/file path, type, and SHA-256.

Symlinks, absolute/traversing paths, duplicate paths, files outside owned
roots, missing prior releases, changed bytes, or an unrecorded owned path are
refused. Duo hashes and parses both descriptors independently and publishes
immutable code-release metadata. The exact prior descriptor hash becomes the
receipt's `prior_code_descriptor_sha256`, and the complete metadata hash becomes
`code_release_metadata_sha256` in `duo-rollback-receipt/v2`; the outer artifact
binds the desired descriptor. The authority still reads existing v1 receipts,
but they cannot claim this newer automatic code-release profile. Preparation
retries either verify the exact existing metadata or
clear only interrupted descriptor outputs and repeat.

## Atomic selection and restore

`code_select` is available only with the certified provider and only in
`promoting`. `code_restore` is routed through it only in `rolling_back`.
Both require an open signed `prepared` event whose operation id, attempt, input
path/hash, target, generation, receipt, owner, artifact, claimant, and epoch
match exactly. The canonical `duo-code-release-operation/v1` input also binds
the immutable metadata hash and expected current pointer.

For either direction the provider may observe only the exact from-pointer or
the already-selected exact to-pointer. Any third value is a concurrent-writer
refusal. It verifies every descriptor path/type/hash and absence of unrecorded
owned paths, atomically swaps the pointer when needed, then returns evidence.
Duo makes a second provider call to verify the selected release and requires
identical pointer/result evidence. Only then may the controller append the
signed `completed` event.

A disconnect before the prepared event authorizes nothing. A disconnect after
that event but before/after the pointer swap leaves the operation open and the
site excluded. Retrying the same operation verifies the current pointer,
repeats the swap only when safe, and converges idempotently. A stale target,
generation, descriptor, pointer, claimant, or operation remains non-green.

## Retention

The prior release and pointer receipt remain immutable through the signed
receipt's rollback window. A signed delete request is accepted only for an
active terminal generation after the deadline. The provider must refuse to
delete a currently selected prior release and prove absence before Duo writes
a hash-only `duo-code-release-tombstone/v1`. Failed or disconnected deletion
is retryable and cannot broaden authority.
