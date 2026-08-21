# Universal cloud previews

Use this guide after a site has passed adoption and assessment and you want an
on-demand branch URL without buying or provisioning another WordPress host.
Duo Cloud supplies the preview compute. The origin may remain on Hostinger,
Pressable, or another provider, but it must still satisfy Duo's supported-site
and isolated WP-CLI requirements.

A cloud preview is a portable, lower-fidelity environment. It proves the
branch against Duo-managed authored state; it does not reproduce host-specific
NGINX/LiteSpeed behavior, Redis, search, production credentials, live orders,
sessions, submissions, queues, or external effects. Use `duo rehearse` with a
qualified host provider when exact-host evidence is required for a production
decision.

## What must already exist

- The production site is adopted, initialized, classified, contract-reviewed,
  and reachable through a Duo environment driver with isolated WP-CLI control.
- The production target has Git available to the protected PHP/WP-CLI process,
  and its `repo_path` is a clean Git worktree at the exact production commit.
  `duo adopt` deliberately works without Git, so adoption alone does not prove
  this Cloud-origin prerequisite; publish and initialize the target repository
  first, then keep its HEAD aligned with the local production ref.
- The origin's CLI PHP enables HTTPS stream access (`allow_url_fopen`), Sodium,
  and `proc_open`, and can validate the Cloud service certificate. Sodium owns
  the Ed25519/XChaCha authority and `proc_open` owns the protected target-side
  Git boundary; disabled functions, a missing extension, or a missing Git
  executable refuse rather than falling back to an unsigned, web-request, or
  shell-emulated transport.
- The local site-repository checkout is clean and contains both the production
  ref and the branch to preview.
- That checkout has exactly one canonical, credential-free HTTPS push URL
  matching the repository authority signed by the Cloud service. Its
  machine-local credential helper must noninteractively publish and delete the
  deterministic operation refs under the service-signed prefix (normally
  `refs/heads/duo-preview/`). This controller credential is distinct from the
  worker's separately pinned read credential; the Cloud operator must
  provision both against the same repository.
- A Duo Cloud worker has been provisioned for this tenant/site. Its operator
  gives you the tenant/site ids, controller key ids, controller signing key,
  service response key, origin endpoint pin, and a short-lived pairing code.
- The production `wp-config.php` pins the credential-free origin endpoint and
  service verification key as `DUO_CLOUD_ORIGIN_ENDPOINT`,
  `DUO_CLOUD_ORIGIN_SERVICE_KEY_ID`, and
  `DUO_CLOUD_ORIGIN_SERVICE_PUBLIC_KEY`. These are control-plane trust anchors,
  not WordPress options.
- Host and WordPress auto-updaters remain fenced from Duo-managed code. Cloud
  preview does not make an unsupported or drifting origin supported.

The cloud service itself is an operator deployment. Its exact worker, Git,
storage-encryption, firewall, TLS, routing, and janitor requirements are in
[../../cloud/README.md](../../cloud/README.md).

## Add the machine-local preview environment

Put cloud authority only in the untracked `.duo-envs.json` beside
`site.duo.json`. A cloud-preview entry is rejected if it comes from the
committed registry. The overlay replaces a same-named committed environment
as one whole object.

```json
{
  "envs": {
    "preview": {
      "transport": "cloud-preview",
      "repo_path": "/srv/duo",
      "cloud_preview": {
        "control_endpoint": "https://preview.example.test/v1/preview/control",
        "lifecycle_endpoint": "https://preview.example.test/v1/preview/lifecycle",
        "request_key_id": "controller-key-v1",
        "request_signing_key": ".duo-cloud/controller-secret.key",
        "response_key_id": "service-key-v1",
        "response_public_key": ".duo-cloud/service-public.key",
        "site_id": "site-a",
        "tenant_id": "tenant-a",
        "timeout_seconds": 60
      }
    }
  }
}
```

`repo_path` is the fixed repository path inside the reviewed workload, not a
path on the controller or production host. Both endpoints must be exact HTTPS
URLs on the same authority. The request file contains canonical-base64
Ed25519 secret-key bytes and must be controller-owned mode `0600`; the public
response-key file must be controller-owned and not group/world writable.
Relative paths resolve beside the overlay. Duo rejects symlinks, changed
inodes, weak file permissions, credential-bearing URLs, and tracked cloud
configuration.

Check the two environments before pairing:

```bash
duo driver-capabilities production --operation=status --format=json
duo driver-capabilities preview --operation=materialize --format=json
```

That driver check establishes transport reachability, not the origin PHP
runtime. Before issuing a device code, the host operator must separately verify
that the same protected WP-CLI PHP binary has Sodium, `allow_url_fopen`, and an
enabled `proc_open`, and that its target worktree Git command can read the
expected production HEAD. Pairing and export still recheck the prerequisites
they use and refuse when one is absent or changes.

## Pair the production origin

Pass the one-use device code over inherited stdin. Start the command, paste the
code as its single input line, and press Return. Do not place the code in a
shell command, variable, or pipeline: those forms can expose it through shell
history or another process's argv. Duo's durable state retains only its digest.

```bash
duo origin pair production --format=json
duo origin pair production --poll --format=json
duo origin status production --format=json
```

If the begin response is lost, repeat the first command with the same code;
the stored attempt and code digest reproduce the exact signed request. Use
`--poll` only after begin has returned a signed pending receipt. Pairing and
every export run through Duo's protected, plugin/theme-skipping control
bootstrap; Duo does not add a public WordPress REST endpoint or remote shell.

Rotate the origin signing key during a planned idle window:

```bash
duo origin rotate production --format=json
```

Rotation holds the same private upload-journal lock as export and refuses while
that journal is polling, sealed, uploading, committing, or cleaning. The old
key signs the generation transition and the new key proves possession; the
local generation is rebound before the journal lock is released. If the signed
response is lost, repeat the same command to recover the exact cached receipt.
One pairing admits one deterministic rollover: repeating a completed rotation
replays that receipt and does not mint another key. For a later planned
rollover, revoke the current pairing, issue a fresh pairing code, pair again,
then run `duo origin rotate` under that new authority.

## Create one branch preview

Run from a clean checkout of the existing feature branch you want to preview.
`--production-ref` names the local production commit authority;
`--new-branch` names a new, unused local branch that Duo will create for the
immutable rebased candidate. Duo refuses to replace an existing ref.

```bash
git switch feature/checkout-copy
duo preview create preview \
  --from=production \
  --production-ref=main \
  --new-branch=duo-preview/checkout-copy \
  --ttl=86400 \
  --format=json
```

The command performs one closed workflow:

1. The cloud service issues a generation-bound export demand for the exact
   production commit.
2. The controller invokes the adopted origin through its existing driver. The
   origin observes one read-only snapshot, seals immutable chunks, uploads
   them over signed outbound HTTPS, and deletes its local spool after the
   cloud commit receipt is durable.
3. The controller rebases the requested branch against that verified semantic
   production observation and freezes the candidate commit.
4. Under the preview's held mutation fence, the controller publishes only the
   deterministic operation ref. The cloud worker fetches that ref through its
   pinned credential helper, reads back the exact commit, and the controller
   deletes the temporary ref after the compare-and-sync receipt.
5. The worker materializes the candidate, applies portable state, rebinds the
   URL and preview credentials, proves convergence, publishes HTTPS routing,
   records the final TTL, and releases mutation authority.

Retries use the private local preview journal. A completed retry returns its
recorded receipt without another export, target command, or provider action.
A different branch/commit/TTL cannot reuse the same in-progress intent; reap
the exact slot first.

The receipt says `production_fidelity: false` and lists the omitted production
facts. Treat that list as part of the result, not as a warning to ignore.

## Sleep and wake the retained generation

```bash
duo preview sleep preview --format=json
duo preview wake preview --format=json
```

Sleep acquires a lifecycle-lineage-bound management fence, removes the public
route, then removes execution. It retains the same lease generation, URL,
preview credentials, network, database/filesystem volumes, and encrypted quota
binding. Wake reuses that exact held fence, restores and verifies execution,
then restores routing and releases the fence. A lost response is recovered by
repeating the same command; another target or generation cannot consume the
journaled transition. Workload commands are unavailable while the slot is
asleep or transitioning. Explicit reap and the TTL janitor can both destroy an
asleep or interrupted transition without waking it first.

## Reap the exact slot

```bash
duo preview reap preview --format=json
```

Reap compares the journaled resource id, lease generation, ownership receipt,
and mutation fence before it removes routing, execution, database/filesystem
state, and credentials. Repeating the same reap is idempotent. A stale local
journal cannot delete a newer occupant. If create stopped after publishing its
temporary Git operation ref, reap first compares and deletes only that exact
commit with a force-with-lease, proves remote absence, and then makes the slot
terminal; a changed ref refuses before provider mutation.

The service also assigns a provisional one-hour deadline as soon as allocation
becomes durable, then replaces it with the requested TTL after materialization.
Service janitors recover and reap abandoned create/fence phases even if the
controller and its journal are lost. Janitors are a service requirement, not a
replacement for explicit reap after review.

## Hosting-provider boundary

No Hostinger or Pressable compute is created for the preview. Those providers
hold only the origin. The current direct workflow still needs their SSH/WP-CLI
surface (or another already-qualified Duo driver), target-side Git callable by
that protected process, Sodium, HTTPS streams, enabled `proc_open`, and a clean
target `repo_path` at the requested production commit. SFTP-only,
wp-admin-only, Git-less, disabled-function, or seed-only adopted accounts are
not a working origin path.

The preview runs on Duo Cloud's isolated worker with fresh database,
filesystem, salts, credentials, URL, and generation. Customer plugin/theme PHP
never shares a workload container with another tenant. The worker denies
workload egress—including the container host gateway—by a pinned host firewall
authority; Caddy may initiate the reviewed HTTPS route into the workload, but
the workload cannot initiate a connection back to host or Internet services.

Revoke pairing administratively when the origin must stop exporting:

```bash
duo origin revoke production --format=json
```

Revocation first completes crash-safe deletion of any active local export
spool, then revokes the origin key remotely with reason
`administrator_requested`.

Before removing the connector from WordPress, use the distinct uninstall
authority:

```bash
duo origin uninstall production --format=json
```

Uninstall uses the same locked, crash-safe spool cleanup, then signs the
revocation with reason `uninstall`; a lost cleanup or Cloud response is resumed
by repeating that command. Neither command removes an existing preview or the
local Duo installation. Use `duo preview reap` for the separate preview
resource authority, and remove the connector only after uninstall is terminal.
