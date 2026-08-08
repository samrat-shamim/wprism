# Verified rollback for production SSH promotion

> **Design ruling (DUO-3291, 2026-08-08; authority foundation DUO-3293).**
> The protected target identity, never-reused generation fence, immutable
> signed receipt, signed event hash chain, claimant takeover, status evidence,
> and SSH adoption wiring described in section 3 are implemented. The
> resource-specific executors in later sections are not. Current promotion
> therefore remains operator-directed as specified in `spec/repo-format.md`;
> a successful database import is not a verified rollback.

## 1. Claim and scope

A production promotion may advertise automatic rollback only when, before its
first semantic mutation, Duo has proved that every resource it can touch is
one of:

1. **restorable** from an exact, integrity-checked before-image;
2. **reversible** through a version-pinned adapter whose inverse and verifier
   are both available in the isolated recovery control plane; or
3. **prevented** for the whole promotion and rollback window.

An unclassified, non-reversible, or merely observable effect blocks the
promotion before mutation. This is intentionally stricter than the current
lifecycle observer, which reports mail and HTTP attempts but does not prevent
them. Duo must never turn a best-effort compensation into a rollback claim.

The smallest truthful success set is binary:

- `committed`: the new code and state passed all new-revision verifiers; or
- `rolled_back`: the prior code and state passed all prior-revision verifiers.

Every other durable state is non-green and keeps traffic excluded. A process
exit, SSH disconnect, or temporarily unavailable dependency may leave work
pending, but may not relabel a mixed target as either outcome.

This design is limited to a production-form SSH target with the adopted,
protected Duo control agent and standard `wp-content/mu-plugins` layout. It
does not make Duo the owner of load balancers, backup products, key management,
or storage snapshots. Those remain explicit target adapters with narrow
prepare/verify/release contracts.

## 2. Why the current receipts are not enough

The current path has several useful but narrower guarantees:

- the target database lease binds an owner and compiled artifact;
- the checkpoint contains the database, including the ledger and temporary
  promotion row;
- code stage records desired descriptors and created paths in the database;
- lifecycle records pre/post canonical option hashes;
- apply keeps `apply_in_progress` until rebuild and fresh-process canonical
  verification pass; and
- the protected control bootstrap can run with plugins, themes, and user MU
  plugins skipped when ordinary WordPress bootstrap is broken.

Those facts fail to establish automatic rollback. Importing the checkpoint
replaces the lease and all database-local progress. The current code
materializer writes and prunes live paths in place and retains descriptors,
not complete prior bytes. Attachment apply writes upload bytes outside the
database transaction, and metadata generation may write additional files.
Lifecycle hooks can mutate arbitrary files, schedules, queues, or remote
systems. Rebuilders and object-cache flushes happen after the authored
transaction. Therefore the durable rollback authority and its progress journal
must live outside every resource the rollback can replace.

## 3. Target-side authority and rollback bundle

### 3.1 Control root and generation fence

Adoption provisions a protected control root outside the managed code payload:

```text
<repo_path>/.duo/control/
  target.json
  target.lock
  public-keys/
  recovery-runtime/
<repo_path>/.duo/rollback/<receipt-id>/
  receipt.json
  events/<sequence>-<event-hash>.json
  artifacts/
  reports/
```

`target.json` is an atomically published, hash-checked record containing a
stable target identity, a monotonically increasing promotion generation, the
active receipt id, and its state. Every update is serialized with an OS-level
lock on `target.lock`. Neither file is stored in the WordPress database, live
code release, or uploads tree.

A new promotion increments the generation with compare-and-swap and records
its receipt before it may mutate WordPress. It refuses while the previous
generation is non-terminal. Every mutating or restoring command must match all
of:

- stable target identity;
- active receipt id and generation;
- original promotion owner;
- exact new artifact hash;
- exact prior checkpoint and code-resource hashes; and
- the current recovery claim epoch.

A receipt from an older generation can consequently never overwrite a newer
promotion. Deleting or editing the database lease cannot weaken this fence.

### 3.2 Signed append-only receipt

`receipt.json` is canonical JSON signed by an authorized controller key. It is
immutable after `prepared` and contains:

- receipt format, target identity, generation, creation time, and expiry;
- original owner and exact new compiled artifact hash;
- previous applied revision and the hashes of frozen prior-verifier inputs;
- encrypted database checkpoint hash, database identity, and export evidence;
- prior and new code descriptors plus the exact release or file before-image
  inventory needed to restore the prior bytes;
- upload/media before-images and created-path deletion receipts;
- lifecycle, derived-state, cache, schedule, and external-effect adapter ids,
  versions, declarations, inverse inputs, and verifier inputs;
- protected runtime fingerprints and ledger/session identity hashes;
- maintenance/exclusion provider identity and durable token hash;
- encryption algorithm and key id, retention deadline, and audit policy; and
- an ordered restore plan with a hash for every input.

Secrets, private keys, bearer credentials, and plaintext database contents are
never embedded in the receipt. The controller signs the receipt; the target
stores only verification keys. Encryption keys are obtained by key id from an
external KMS or operator-approved secret source at use time.

Mutable progress is an append-only, signed hash chain under `events/`. Each
event contains the previous event hash, monotonically increasing sequence,
state, operation id, attempt, recovery claimant, input hashes, result hashes,
and timestamp. `target.json` points at the current event hash. Atomic publish
is temp-file + fsync + rename + parent-directory fsync. No event is rewritten.

The split is deliberate: the immutable receipt says *what is authorized*;
events say *how far that exact authorization progressed*. A copy of the
receipt and event chain is also retained by the controller or audit sink, but
the target-side copy is authoritative for target mutation.

### 3.3 Prepared means recoverable

The receipt may enter `prepared` only after all of these pass:

1. maintenance/traffic and non-Duo writer exclusion is acquired in a mode that
   remains closed if the initiating SSH session disappears;
2. the external generation fence is claimed;
3. the exact new repository artifact is compiled and verified;
4. the prior canonical state is captured with frozen prior policy/verifier
   inputs, and its hash agrees with the current applied ledger;
5. the encrypted database export finishes, can be decrypted by the recovery
   executor, and passes an import/readability check in a disposable database;
6. prior code bytes are present in an immutable release, or a complete
   before-image journal has been encrypted and verified;
7. every possible upload/filesystem target has a before-image or a proven
   absent-path receipt;
8. every lifecycle, derived, cache, schedule, and external effect has a loaded
   compatible adapter classified as restorable, reversible, or prevented;
9. the isolated recovery runtime can reach the database, code switch, storage
   adapters, maintenance provider, and verification commands without loading
   ordinary plugins, themes, or user MU plugins; and
10. the complete receipt and first event are durably published and read back.

Failure anywhere in preparation appends an audited `preparation_aborted`
event, clears that generation's active receipt, and releases exclusion without
touching WordPress semantic resources. The generation is never decremented or
reused. A database export file alone is not `prepared`.

## 4. Resource inventory by phase

| Phase | Mutable resource | Required rollback material or prevention | Restore verification |
|---|---|---|---|
| prepare | external receipt/generation, encrypted artifacts | append-only receipt; generation CAS; verified encrypted bundle | signature, hash chain, target/generation match |
| code stage | plugin/theme/MU-plugin files and directories | immutable prior release with atomic pointer, or complete per-path before-images and absent-path receipts | prior descriptor, every file hash, no unrecorded owned path |
| lifecycle retire/activate | active-plugin/theme options, plugin tables, ledger lifecycle receipts, files, schedules, queues, remote calls | database checkpoint plus declared filesystem/schedule inverse; mail/HTTP/queue effects prevented or explicitly reversible | prior canonical lifecycle records, adapter invariants, no unaccounted effect |
| code finalize | old owned paths, completed/staged code ledger | same code release/journal; checkpoint restores ledger | prior code descriptor/revision and exact path inventory |
| apply transaction | authored DB rows, identity map/state ledger, upload originals | database checkpoint; upload before-images/created-path receipts | prior canonical recapture; ledger identity hash; upload hashes |
| rebuild | derived DB tables, attachment derivatives, caches, future-post schedules, adapter commands | DB checkpoint where contained; otherwise adapter inverse or deterministic prior rebuild; external caches remain traffic-isolated | declared table/query probes, derivative inventory, schedule set, cache generation |
| verify new | verifier reports only; plugin shutdown code remains a possible writer | lifecycle/external isolation remains active; reports append-only | report bound to new artifact and fresh process |
| commit | applied/base ledger and external generation state | database checkpoint remains retained through rollback window | new revision report, ledger hash, receipt terminal event |

Some consequences are easy to miss:

- `Code::stage()` and `Code::finalize()` currently mutate live paths in place.
  Descriptor history is deletion authority, not a byte-complete inverse.
  Automatic production rollback therefore requires an atomic SSH release
  adapter, or a separately certified full before-image adapter. The existing
  in-place materializer remains fail-closed/manual-recovery only.
- `Apply::place_attachment()` may publish a repository media blob before the
  database transaction commits. The rollback inventory must retain the old
  bytes when the path existed and an exact deletion receipt when it did not.
  Attachment metadata generators may create thumbnails and must report every
  path they create, replace, or remove. An offload provider needs the same
  versioned-object/inverse contract; local assumptions are insufficient.
- Database restore covers DB-resident cron rows and derived tables, but not an
  external cron service, queue, search index, object cache, CDN, or filesystem
  derivative. Each needs a bounded inverse and verifier, or must be prevented
  and rebuilt before traffic resumes.
- The current lifecycle mail/HTTP observer is evidence, not isolation. A
  production automatic-rollback profile must short-circuit such calls into a
  receipt-bound outbox, or use an adapter with a real idempotent inverse. A
  declaration of `irreversible` blocks before code stage.
- Database migrations are supported only when restoring the checkpoint plus
  prior code has been certified against the prior version. An adapter that
  can issue only a forward migration is non-reversible and blocks the automatic
  profile before mutation.

## 5. State machine

The external receipt uses these states:

```text
prepared
  -> promoting
  -> verifying_new
  -> committed

promoting | verifying_new
  -> rollback_pending
  -> rolling_back
  -> verifying_prior
  -> rolled_back
```

`committed` and `rolled_back` are the only green terminal states. A failed
preparation never enters this graph. There is no `rollback_succeeded` shortcut
after database import and no terminal `best_effort` state.

Every resource operation follows the same journal protocol:

1. append `operation_prepared` with exact input hashes and idempotency key;
2. perform or resume the bounded operation;
3. independently read and verify the resource; and
4. append `operation_completed` with result evidence.

A crash before step 1 means the operation was unauthorized. A crash between
steps 1 and 4 is ambiguous, so recovery reads the resource, accepts it only if
the exact postcondition already holds, otherwise safely repeats the same
idempotent operation. The next top-level state is appended only after all
operations in the current state have completion evidence.

### 5.1 Normal promotion

`prepared -> promoting` authorizes the existing ordered promotion phases.
After every phase, its resource delta and adapter receipts are compared with
the prepared inventory; an undeclared path, effect, or resource fails
immediately into `rollback_pending`. `promoting -> verifying_new` occurs only
after code finalize, state apply, rebuilds, and all adapter postconditions have
finished.

New-revision verification runs in fresh processes and checks canonical state,
code, uploads, runtime protection, ledger identity, and every adapter invariant.
Only the complete report permits `verifying_new -> committed`. The generation
record is then marked terminal, but rollback material remains encrypted and
retained until policy allows deletion.

### 5.2 Verification failure and rollback order

Any mutation/rebuild/new-verification failure appends `rollback_pending`
before cleanup of the database lease. Rollback proceeds under the external
generation fence and maintenance exclusion in this order:

1. claim recovery and revalidate receipt signatures, generation, target
   identity, artifact/checkpoint hashes, adapter availability, and exclusion;
2. make the prior code release available and verify it without exposing it to
   traffic; for in-place adapters, restore protected prior bytes while using
   only the isolated recovery runtime;
3. restore upload/media originals and remove only exact paths proven absent
   before promotion;
4. restore the exact database checkpoint through the isolated control plane
   using the existing row-repair order: abort the old owner/artifact, begin
   that owner/artifact, import, then perform the final abort even if import
   fails;
5. atomically select the already-verified prior code release (or verify the
   completed in-place restore);
6. run declared inverses/restorers for non-DB derived state, schedules, caches,
   queues, and reversible external resources;
7. prove no database promotion row remains—the external generation authority
   remains the stronger fence throughout; and
8. enter `verifying_prior` and run the complete prior verification suite.

Steps 2–5 happen with public traffic and background writers excluded, so no
ordinary request observes the intentionally temporary code/database mismatch.
The isolated executor must not load either revision's ordinary plugin/theme/MU
runtime while performing those steps.

### 5.3 Retry and operator takeover

A retry must present the same receipt, generation, target identity, and
original owner/artifact. It continues the first incomplete operation; it does
not start another promotion. The current recovery claimant holds a bounded
external claim lease recorded in the event chain.

After that claim expires, an authorized operator or controller may append an
`operator_takeover` event with a new claim epoch. Takeover does not change the
original promotion owner, artifact, checkpoint, generation, or restore plan.
The taker re-verifies the full chain and every completed operation before
continuing. Compare-and-swap on the claim epoch prevents the former controller
from resuming. A forced takeover that changes inputs is a new, separately
audited manual procedure and receives no automatic rollback claim.

An SSH disconnect never releases maintenance or advances state. The target
defaults to closed traffic when the maintenance controller lease loses its
keepalive, and a second authorized controller can reconnect through raw SSH to
the protected recovery runtime. If the target itself is unreachable, the
receipt remains non-green until reachability returns; availability loss is not
evidence of rollback.

## 6. Prior-state verification

`verifying_prior` must start new processes after database import and code
selection. It compares observed facts with frozen, receipt-bound before-state
evidence:

1. canonical recapture under the prior policy and manifest snapshot matches
   every prior entity hash and absence assertion;
2. protected runtime fingerprints match for runtime rows/columns the promotion
   was required to preserve;
3. `duo_map`, `duo_state`, `applied_revision`, code ledger, and lifecycle
   session hashes match the checkpoint inventory, with no live promotion row;
4. the selected code descriptor/revision, every managed file hash, and absence
   of unrecorded owned paths match the prior code receipt;
5. upload originals and derivative inventories match their prior hashes;
6. database schema/version probes and every version-pinned adapter invariant
   pass in fresh isolated processes;
7. schedules, queues, caches, remote resources, and traffic-exclusion state
   match their declared prior postconditions; and
8. a second canonical recapture is byte-identical, proving the verification
   bootstrap and shutdown path did not mutate the restored state.

Only then may `verifying_prior -> rolled_back` be appended and traffic
exclusion released. A failed verifier stays non-green, keeps maintenance, and
is retryable from its operation receipt.

## 7. Crash-injection certification matrix

Certification kills the orchestrator, the remote command, and—where
meaningful—the database connection immediately before and after each durable
boundary. Every case must recover to a fully verified new or prior revision;
the intermediate expectation is deliberately non-green.

| Injection boundary | Durable observation after restart | Required recovery/result |
|---|---|---|
| before generation claim | no active receipt; no semantic mutation | ordinary fresh promotion is safe |
| after generation claim, before `prepared` | incomplete preparation; no semantic mutation | verify/delete partial encrypted artifacts, release exclusion |
| after checkpoint export, before export verification | no `prepared` event | discard; never import the unchecked file |
| after `prepared`, before first mutation | exact receipt active | resume promotion or roll back to verified prior without writes |
| before/after each code-stage file publish | operation prepared but maybe incomplete | hash target; finish exact stage or restore from prior release/journal |
| before/after lifecycle retire and activate | pre-hook/adapter receipt identifies ambiguity | go directly to rollback; never ordinary retry |
| before/after code prune or pointer switch | exact code operation receipt | reconcile exact descriptor/pointer, then continue or roll back |
| before/after each upload publish | prior hash/absence receipt exists | restore old bytes or delete only exact newly-created path |
| before/after authored DB commit | checkpoint + `apply_in_progress` evidence | roll back transaction if live; otherwise restore checkpoint |
| before/after each rebuild/inverse | adapter operation receipt | verify postcondition, idempotently repeat or restore |
| during fresh new verification | no terminal event | repeat verifier; failure enters `rollback_pending` |
| after new verification, before `committed` | signed pass report but non-terminal state | validate report and append commit, or roll back; never open traffic first |
| before/after `rollback_pending` append | failure event and operation evidence | reconstruct only from signed chain, then append/continue rollback |
| during code/upload restoration | exact restore operation pending | verify and resume that operation |
| immediately before/during/after DB import | external fence survives DB replacement | repeat exact import if its checkpoint postcondition is not proven |
| after import, before final database abort | restored old lease row may exist | perform exact final abort before verification |
| during prior verification | non-terminal `verifying_prior` | repeat complete fresh-process verification |
| after prior pass, before `rolled_back` | signed pass report, traffic still closed | validate report, append terminal event, then release traffic |
| during operator takeover | claim epoch CAS decides one owner | loser stops; winner revalidates and resumes exact operation |
| SSH loss at any non-terminal state | maintenance defaults closed; receipt unchanged | reconnect from any authorized controller and resume |
| during artifact retention/deletion | terminal audit record names intended deletion set | retry deletion; never delete active/non-terminal material |

The harness must also inject changed target identity, stale generation, wrong
owner/artifact, substituted checkpoint, unavailable decryption key, missing
adapter, changed file, and failed maintenance keepalive. Each is a loud refusal
without unauthorized mutation. The certification artifact is a signed,
machine-readable bundle of every case, terminal outcome, verifier report, and
resource fingerprint—not a log-grep claim.

## 8. Encryption, retention, deletion, and audit

- Database dumps, file/upload before-images, adapter secrets, and any report
  containing sensitive values are encrypted before durable storage. Use an
  authenticated streaming format; record ciphertext size/hash, algorithm,
  recipient/key id, and creation time in the receipt.
- The decryption key is not stored beside the bundle, in Git, in Linear, in
  command-line arguments, or in logs. Recovery obtains a short-lived key
  handle from the configured secret provider and scrubs temporary plaintext.
- Temporary plaintext uses mode `0600` in a dedicated directory, is never
  placed under a web-served root, and is removed on success and failure.
- Retention has both a time window and a count/storage ceiling, but active or
  non-terminal generations are never garbage-collected. A committed bundle is
  retained through the declared rollback window; a rolled-back bundle is
  retained through its audit window.
- Deletion first appends a terminal `deletion_authorized` event naming exact
  hashes, then removes ciphertext and verifies absence. An immutable audit
  tombstone retains receipt id, target/generation, terminal result, report
  hashes, key id, retention decision, and deletion evidence without retaining
  secrets.
- Storage snapshots and copy-on-write media may make physical erasure
  unverifiable. Policy must describe provider retention honestly; Duo claims
  logical deletion only unless the provider supplies stronger evidence.
- Logs redact credentials, connection strings, salts, tokens, user data, and
  plaintext SQL. Audit evidence carries hashes and bounded summaries instead.

## 9. Required implementation split

DUO-3291 ends with this design. Implementation should be split into issues
whose acceptance can be certified independently:

1. **DUO-3293 — External rollback receipt and generation fence** — canonical signed
   receipt, append-only event chain, target identity/generation CAS, recovery
   claim/takeover, status, and refusal of stale authority.
2. **DUO-3294 — Production SSH exclusion and fatal-safe recovery executor** — durable
   maintenance/non-Duo-writer adapter, raw-SSH recovery commands, disconnect
   behavior, and protected runtime installation/update. Implemented by the
   target-owned argv protocol in
   [`../recovery-runtime.md`](../recovery-runtime.md); it remains a substrate,
   not an automatic-rollback claim, until the dependent integration slices
   close.
3. **DUO-3296 — Atomic SSH code releases and restore verification** — immutable releases,
   atomic pointer, prior descriptor retention, exact file/path verification,
   and fail-closed fallback for unsupported in-place targets.
4. **DUO-3295 — Encrypted checkpoint bundle and verified database restore** — prior
   canonical verifier inputs, export validation, KMS integration, exact import
   row repair, ledger/session verification, retention, and audit tombstones.
   Implemented by the target-owned checkpoint protocol in
   [`../checkpoint-bundle.md`](../checkpoint-bundle.md); it supplies only the
   database slice and does not broaden the integrated automatic-rollback claim.
5. **DUO-3297 — Upload/filesystem mutation journal** — local and offload before-images,
   created-path receipts, attachment derivative inventory, safe restore, and
   undeclared-path refusal.
6. **DUO-3298 — Reversibility grammar and adapter preflight** — lifecycle, migrations,
   rebuilders, derived stores, schedules, queues, caches, mail/HTTP, and other
   external effects classified as restorable/reversible/prevented/irreversible;
   unsupported effects block before code stage.
7. **DUO-3299 — Crash-injection certification and signed evidence** — boundary injection,
   operator takeover, SSH loss, tamper cases, new/prior fresh-process verifiers,
   and a machine-readable certification bundle.

Dependencies are intentional: the receipt/fence and recovery executor are the
foundation; resource adapters feed the receipt; the crash harness certifies
the integrated path. No slice may advertise automatic rollback until the full
resource inventory is closed and the integrated matrix is green.
