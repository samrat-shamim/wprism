# Universal Duo Cloud Preview

Status: acceptance candidate. The signed controller transport, outbound origin
export, portable materializer, generation-bound cloud provider, isolated worker,
public create/sleep/wake/reap commands, and deterministic product path are implemented.
Production availability remains gated on the live host-containment, deployment
preflight, full offline, certification, release, and merge evidence below. This
document does not widen the certified WordPress or adapter matrix.

## Product promise

After one supported WordPress site is paired, an operator can request one
on-demand branch preview URL without provisioning a staging account or server.
The customer supplies no additional compute. Duo supplies and accounts for the
preview compute, storage, routing, and retention.

This is not a promise that preview consumes no resources. Every running preview
has CPU, memory, database, filesystem, routing, and storage cost. The default
commercial unit is one reusable concurrent slot per adopted site. A new branch
waits for, replaces, or explicitly reaps the current occupant.

Universal means a supported origin can export over outbound HTTPS. It does not
mean every WordPress topology or plugin is supported, nor that a cloud preview
reproduces host-specific NGINX/LiteSpeed rules, premium-license servers, Redis,
search, or arbitrary external services. The preview receipt carries a fidelity
report; absent facts never authorize production.

## Trust and isolation boundaries

There are four separate principals:

1. The WordPress connector owns an origin key and may export only the paired
   site's explicitly classified snapshot.
2. The controller owns Git intent, human authorization, and a controller key.
   Its private key never reaches WordPress or a preview workload.
3. The cloud control plane owns tenant/site/resource mappings, generation
   counters, request replay receipts, TTL, and routing.
4. The worker owns exactly one isolated workload generation at a time. Customer
   PHP never runs in the control plane and never shares a container, process
   namespace, filesystem, database credential, or network identity with another
   tenant.

One physical node may bin-pack many workloads. One security container may not.
Sequential reuse of the same site slot is allowed only after exact reap and a
rotated lease generation.

## Controller-to-cloud control protocol

The machine-local `cloud_preview` configuration names a credential-free HTTPS
control endpoint, tenant/site ids, an Ed25519 request key, and a pinned Ed25519
response key. HTTP is accepted only on the loopback interface for deterministic
offline tests.

Every command uses `duo-cloud-preview-signed-envelope/v1`. The signed request
payload contains:

- action (`raw` or `wp`) and closed input;
- a journal-owned command phase and zero-based phase-local index;
- tenant, site, logical environment, operation, and deterministic request id;
- the provider's exact `resource_id`, environment identity, lease id and
  generation, and ownership receipt;
- the exact held mutation id, owner, generation, and receipt.

The request id is deterministic over tenant, site, operation, command phase,
and command index. The service authorizes the request key for the signed
tenant/site pair, compares
the entire resource and mutation tuple against its current authority record,
and stores `(key_id, request_id, request_sha256)` before execution. An exact
retry returns the cached signed response; a reused request id with different
bytes refuses. A released, reaped, stale-generation, foreign-site, or foreign-
tenant tuple executes no workload command.

The response is independently signed by the pinned service key and binds the
request hash, tenant/site, operation, environment, and full target tuple. The
controller accepts canonical JSON, HTTP 200, the pinned response key, a valid
signature, and an exact request comparison or nothing.

Dynamic target commands remain disabled until the environment provider has
returned both a present resource lease and a held mutation fence. Rehearsal
observation runs after convergence but before fence release. Its canonical
result is journaled as `target-observed`; recovery replays those bytes without
using a stale fence or contacting the target again. After a confirmed release,
the controller driver revokes its local binding; the service independently
re-reads the live fence for every request, so retaining or replaying an old
signed envelope cannot recover authority.

## Origin pairing and export

The exact endpoint schemas, request-id derivations, generation transitions,
and upload invariants are fixed by
[cloud-origin-wire.md](cloud-origin-wire.md). This section describes their
product boundary.

Pairing is initiated by a WordPress administrator with a short-lived,
single-use cloud code. The connector generates its Ed25519 keypair locally,
encrypts the private key with a key derived from WordPress secret salts, sends
only the public key and reviewed site metadata, and pins the cloud response
key. Pairing codes, controller keys, and cloud service keys are distinct.

The connector polls outbound HTTPS for signed jobs. It exposes no generic
remote shell or unsigned public export URL. A job is accepted only when its
tenant/site, monotonic job generation, expiry, nonce, requested snapshot mode,
and service signature all match the local pairing record. Live observation is
executed only by Duo's isolated WP-CLI control bootstrap (`--skip-plugins`,
`--skip-themes`, user-MU shadow, cron disabled). A normal REST, admin, or
WP-Cron request is not a capture boundary: ordinary site code has already
booted there and may have mutated production before a read-only snapshot opens.

Before live capture, Policy enumerates static `allow_pii`/`allow_secret` grants
plus every interpreter's closed egress-sensitivity capability; shipped ACF
declares an empty set, while an undeclared or malformed interpreter refuses.

The same public controller exposes only the closed maintenance operations
`duo origin rotate <env>` and `duo origin uninstall <env>`. Both cross the
existing protected WP-CLI connector boundary and accept only its exact redacted
command document. Rotation is serialized with export and requires an idle
upload journal; uninstall durably removes any local spool before it signs the
distinct `uninstall` revocation reason. Their deterministic pairing requests
make a lost response an exact retry rather than fresh authority. One pairing
admits one rollover; a later planned rollover requires revoke and fresh pairing
so a repeated command cannot be mistaken for new key authority.

Export upload is resumable and content-addressed. The database observation
itself is one server-enforced read-only transaction and is never resumed across
processes: a crash before sealing discards the partial build and starts a new
snapshot. Once canonical bytes are sealed, retries perform no WordPress, Git,
database, or media reads:

- the semantic site repository commit is the branch/source authority;
- one canonical `duo-refresh-production/v1` portable authored-state document is
  split into fixed ordered chunks, hashed, and uploaded over authenticated TLS
  with exact offsets and idempotency keys;
- `duo-cloud-origin-export-manifest/v1` binds the complete export hash and size,
  every chunk, repository/artifact/code identities, semantic snapshot hash,
  demand generation, and expected production commit; the signed demand and
  terminal commit receipt bind expiry and retention;
- the controller reconstructs and verifies those exact bytes before a portable
  slot may consume them. It does not invent separate physical database or media
  snapshot objects.

The portable mode exports only state Duo classifies and the runtime seed needed
to boot an honest preview. Runtime orders, sessions, form submissions, secrets,
and external credentials are excluded or replaced according to the accepted
application contract. A host-native coherent snapshot may be used by a later
provider-specific fidelity mode, but it is a different claim.

## Workload containment

Before WordPress starts, the worker must prove:

- a per-generation filesystem and database identity;
- non-root execution, resource limits, and no host/container control socket;
- a read-only base image plus generation-owned writable volumes;
- preview-only salts and database credentials;
- production credentials removed or rebound to explicit test values;
- cron and queues stopped until policy is installed;
- outbound network denied by default, with separately declared allowlisted
  destinations or local mocks for HTTP, mail, payments, webhooks, and queues;
- a credential-free routed URL and TLS identity bound to the current generation.

Containment failures are promotion-blocking. A canary is evidence that a known
path did not fire during one transaction; it is not a substitute for a network
policy during interactive preview traffic.

## Portable preview execution

Universal preview is a separate, explicitly lower-fidelity mode from
production rehearsal. It never labels a portable semantic export as a coherent
raw production database/media snapshot and never feeds invented physical
snapshot receipts into `EnvironmentMaterializer`.

The controller verifies a committed origin export, rebases the checked-out Git
branch against that immutable production observation, and freezes a candidate
repository commit. The cloud slot starts from a reviewed WordPress/platform
base with fresh preview salts and an empty runtime. It materializes that exact
candidate repository, installs the protected Duo control plane, and applies
the candidate's portable authored state. Runtime-only production orders,
sessions, submissions, queues, credentials, and host caches are not cloned;
adapters must supply an explicit preview seed or the fidelity report records
their absence.

This ordering requires a dedicated portable-preview materializer. The existing
rehearsal path remains the production-fidelity path and continues to require a
host/provider coherent DB and media snapshot. Neither receipt can substitute
for the other when authorizing production.

## Slot lifecycle

The cloud provider implements the existing environment-provider action set.
Its authority store, not a logical environment name, owns the stable slot id
and monotonically increasing generation. `create` allocates and clears only a
new generation, then returns its credential-free URL. Explicit `duo preview
sleep <env>` and `duo preview wake <env>` actions retain and resume that same
generation under a lineage-bound sleep fence: sleep removes routing before
execution, while wake proves execution before restoring routing. Candidate
sync/materialization, portable authored-state application, URL update, TTL,
command execution, sleep/wake, and reap all compare the exact lease and held
mutation fence.

`destroy` first persists a reaping intent, removes routing, stops execution,
revokes credentials, deletes database/filesystem state, verifies absence, and
then publishes the terminal receipt. Exact lost-response retries return the
same receipt. Old-generation work cannot reach a newer occupant.

## Acceptance gates

The feature is not described as working until offline product-path evidence
proves all of the following:

1. `duo env materialize ... --create` acquires a slot and held fence before the
   first cloud command; every raw/WP command carries a valid signed exact tuple.
2. Request or response body tampering, a foreign tenant/site/key, replay with
   changed bytes, and stale/missing/released fences execute zero commands.
3. Lost provider/control responses, controller death at every durable phase,
   concurrent acquisition, partial reap, TTL expiry, and generation reuse
   converge or refuse without cross-generation mutation.
4. Rehearsal observation is captured under the held fence and replayed from its
   journal after release without another target request.
5. Pairing, job polling, chunk upload, immutable readback, revocation, key
   rotation, and uninstall are tested through the WordPress connector surface.
6. Workload tests prove tenant filesystem/database/process/network separation,
   default-deny effects, credential rebinding, URL/TLS routing, sleep/wake, and
   exact reap.
7. The fidelity report distinguishes portable cloud evidence from exact-host
   rehearsal and cannot widen capability certification.

The repository's ordinary lint, static, unconditional offline merge, and
release gates remain mandatory after these product-path tests.
