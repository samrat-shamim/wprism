# Encrypted checkpoint bundles

The checkpoint bundle is the database before-image slice of verified SSH
rollback. It runs in the adopted PHP recovery runtime without loading
WordPress. A target-owned provider performs database, disposable-instance,
and KMS operations; Duo owns authorization, immutable metadata, artifact
verification, receipt binding, and retention fencing.

This feature does not restore code, uploads, or external systems and does not
by itself make `duo promote` automatically recoverable. The remaining resource
and integrated crash-matrix issues must close before that broader claim is
true.

## Configuration and trust boundary

Add one absolute argv array to `rollback_recovery`:

```json
{
  "checkpoint_provider": ["/opt/duo/bin/checkpoint-provider", "production"]
}
```

The registry and adopted configuration contain no database password, KMS
credential, or encryption key. The provider owns those values. It reads one
canonical JSON request from stdin and writes one canonical JSON response to
stdout. Duo invokes the argv directly, applies the configured recovery
timeout, and redacts provider output on failure.

`recovery-probe` requires the provider to attest:

- `format: duo-checkpoint-provider-response/v1`;
- `state: ready` and `available: true`;
- authenticated streaming encryption;
- no durable plaintext export;
- cleanup of any temporary plaintext.

Missing or malformed evidence makes adoption/recovery preflight non-green.

## Preparation before mutation

The controller first acquires the exact next-generation exclusion reservation.
It then sends a signed `duo-checkpoint-request/v1` `prepare` request containing
the target, generation, receipt, owner, artifact, claimant/epoch, external key
id, retention deadline, and canonical UTC timestamp. The runtime accepts it
only when every identity field matches the verified held reservation.

The provider receives fixed output paths under
`<repo_path>/.duo/rollback/<receipt-id>/artifacts/`. It must stream the database
export through authenticated encryption before durable storage and write:

- `checkpoint.enc`, never a durable plaintext database dump;
- canonical `prior-verifier-inputs.json` with format
  `duo-prior-verifier-inputs/v1` and frozen SHA-256 values for the canonical
  tree, policy, manifest inputs, database schema, map state, state/code
  revisions, lifecycle receipts, runtime fingerprints, and ledger session.

Before returning success, the provider imports the decrypted stream into a
disposable database and proves it readable. Its response identifies the
database, encryption algorithm/key id, cipher hash/size, export and disposable
import evidence, verifier-input hash, runtime/ledger hashes, provider
id/version, cleanup result, and honest physical-erasure capability.

Duo independently reads and hashes both artifacts, validates the exact frozen
input schema, cross-checks runtime/ledger hashes, sets mode `0600`, and publishes
canonical immutable metadata. The metadata's SHA-256 becomes the receipt's
`checkpoint_sha256`; the receipt contains ciphertext metadata and the external
key id, never plaintext or key material. A substituted metadata hash, target,
generation, database, key, provider, or artifact is refused.

Checkpoint preparation is serialized by a protected external lock. An exact
retry verifies and returns the existing immutable result.

## Authorized restore

`database_restore` executes only for the signed open prepared operation with
the exact input digest, claimant epoch, receipt, and held exclusion token. Duo
rechecks the ciphertext hash/size, verifier-input hash/schema, metadata hash,
database identity, and provider identity before accepting success.

The provider must perform and attest this boundary in order:

1. abort any old promotion lease;
2. begin the original owner/artifact session;
3. decrypt and import the checkpoint into the exact database;
4. attempt the final abort in all cases, including decrypt or import failure.

The response must name the original owner/artifact and database identity and
prove both aborts, the final-abort attempt, import success, temporary plaintext
cleanup, and survival of the external authority. Duo writes immutable
hash-only attempt evidence before returning a failed restore. Wrong keys,
corrupt/truncated ciphertext, a changed database, import failure, or missing
final abort stays non-green and leaves exclusion held.

## Fresh prior-world verification

`prior_verify` has the same signed-operation and artifact gates. The provider
must use fresh processes, prove no live promotion session remains, and return
two independent canonical recaptures. They must be byte-identical by SHA-256.

Duo compares the response to every frozen verifier input: canonical tree,
policy, manifest inputs, schema, map state, state/code revisions, lifecycle
receipts, runtime fingerprints, and ledger session. It also checks the same
database and provider identities. Any mismatch stays non-green, so a database
import alone can never establish a successful rollback.

Terminal rollback admission is proof-bearing: `verifying_prior → rolled_back`
requires a completed `prior_verify` from the current claim epoch. A claimant
takeover therefore invalidates an earlier claimant's verification evidence and
the successor must verify again. If `database_restore` was declared by a
prepared operation, its completed event must also remain in the signed chain;
the absence of open operations is never treated as proof that restoration ran.

## Retention and deletion

Checkpoint material cannot be deleted for an inactive or nonterminal
generation, and a signed terminal `committed` or `rolled_back` generation is
still retained until its canonical UTC deadline has elapsed. Deletion is a
signed exact-identity request and is serialized with preparation.

After the provider proves ciphertext absence and temporary cleanup, Duo writes
an immutable `duo-checkpoint-tombstone/v1`. It then deletes the frozen verifier
inputs and checkpoint metadata and fsyncs the receipt directory. The remaining
tombstone contains only hashes, receipt/target/generation, external key id,
provider id, retention deadline, deletion time, and the provider's honest physical-erasure
statement. Storage snapshots or copy-on-write media may justify only a logical
deletion claim; Duo does not upgrade that claim. An exact retry verifies and
returns the same tombstone.
