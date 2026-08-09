# SSH rollback certification

DUO-3299 supplies the production-form local merge gate for the verified SSH
rollback design. DUO-3310 shares the product plan-to-claim builder with this
harness, so its crash generations consume the same code/upload/effect inventory
fields that `duo promote` signs. It remains a local certification harness, not
a hosted-CI shortcut.

Run it from the repository root:

```sh
make certify-ssh-rollback
```

The harness owns four uniquely named disposable containers: two standalone
SSH WordPress hosts and two independent MariaDB servers. After reachability,
authority and recovery requests use `SshTransport`, `RollbackAuthority`, and
the adopted WordPress-independent target runtime. The source and target do
not share a checkout, WordPress volume, database, or host identity.

## Closed matrix

The verifier compiles the required matrix into
`sandbox/bin/ssh-rollback-certification.php`. A spec cannot describe a smaller
run. The current contract contains 33 durable boundaries crossed with:

- controller process kill;
- remote-command kill; and
- database-connection loss;

at both the `before` and `after` edge, for 198 required case ids. The
boundaries cover preparation and signed publication, code upload/selection,
lifecycle and rebuild effects, local/offload uploads, authored database
commit, new verification, rollback publication, every code/upload/effect and
database restore boundary, two canonical prior recaptures, takeover, SSH
loss, and retained-artifact deletion.

Every case binds a hash-only fail-closed observation and a complete signed
receipt/event-chain audit. The intermediate result must keep traffic and all
writer scopes excluded and cannot be green. The final result must be a
fresh-process verified `committed` or `rolled_back` state.

The same bundle also requires mutation-free refusals for changed target,
stale generation, wrong owner/artifact, checkpoint substitution/corruption,
unavailable key/adapter, changed file, concurrent claimant/writer, and failed
maintenance keepalive.

## Signed evidence

Successful runs write a canonical bundle and its public verification key to
`sandbox/tmp/ssh-rollback-certification/` (or
`SSH_ROLLBACK_EVIDENCE_DIR`). The filename is the bundle's SHA-256 digest.
Verify it independently with:

```sh
php sandbox/bin/ssh-rollback-certification.php verify \
  sandbox/tmp/ssh-rollback-certification/<digest>.json \
  sandbox/tmp/ssh-rollback-certification/<digest>.pub
```

The Ed25519-signed payload binds case ids, injection evidence, receipt and
event-chain hashes, exact resource fingerprints, new/prior verifier reports,
terminal outcomes, and target/provider versions. Verification also requires:

- two distinct fresh verifier processes and byte-identical canonical
  recaptures;
- immutable new/prior artifact identity;
- runtime, ledger/session, code, upload, schema, and adapter-invariant hashes;
- independent source/target host and database fingerprints; and
- cleanup proofs for owned SSH fixtures, plaintext checkpoints, nonterminal
  receipts, and maintenance locks.

`make regress-ssh-rollback-certification` deliberately removes or corrupts
every required verifier field, matrix dimension, negative refusal, cleanup
proof, and signature. Each defect must make the verifier fail.
