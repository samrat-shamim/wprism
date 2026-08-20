# Universal Duo Cloud Preview service

`cloud/` is the dependency-free control plane and disposable WordPress runtime
for Universal Duo Cloud Preview. It is installed on a dedicated Linux container
host operated by Duo or a hosting provider. It is not installed inside a
customer's Hostinger, Pressable, or other managed WordPress account.

The origin-side Duo extension pairs the site, answers a signed export demand,
and uploads the requested production snapshot. The controller pushes one exact
candidate commit to the site's canonical Git remote. This service fetches only
that deterministic operation ref, creates an isolated WordPress generation,
restores the demanded database/media, materializes the commit, rebinds the URL,
and publishes a temporary HTTPS route. The origin therefore needs no Docker,
root access, extra database, or preview compute.

One `ProductionService` configuration and state root intentionally bind one
tenant/site and one approved Git source. Run another worker configuration for
another site. Host-side Duo workers, Docker access, the route authority, and
Caddy admin are one mutually trusted operator control-plane boundary. They are
not isolation boundaries from one another; compromise of a host control process
is host-global. Tenant PHP and raw preview commands run only in fenced workload
containers and are the hostile side of the boundary.

One physical host admits at most 32 registered workers and 32 simultaneous
generation bindings. The firewall, storage journal, and complete kernel
readbacks enforce the same closed bound; add another encrypted host rather than
silently exceeding it.

## Shipped boundaries

- `runtime/ProductionConfig.php` accepts only canonical
  `duo-cloud-production-config/v1` JSON pinned by an out-of-band SHA-256. It
  reopens executable, contract, profile, key, and secret files with inode,
  ownership, mode, size, and digest checks.
- `runtime/ProductionService.php` assembles the signed control, lifecycle,
  origin, repository-sync, container-command, and HTTP authorities. Controller
  key installation happens during active preflight, never while assembling an
  unauthenticated HTTP request.
- `bin/duo-cloud-http` is the extensionless FastCGI source front controller. It
  deliberately begins with `<?php`, not an executable shebang: PHP-FPM would
  otherwise copy shebang bytes into every public response. It accepts
  only exact POST targets, canonical `Content-Length` no greater than 2 MiB,
  and `application/json`. Public failures stay constant; a protected log gets
  only a correlation id plus class/reason hashes, never request or secret values.
- `bin/duo-cloud-preflight-fleet` reconciles the shared nftables, Caddy, and
  encrypted-storage registries once, then publishes one atomic 60-second host
  lease. `bin/duo-cloud-preflight` independently proves one worker's quota,
  firewall membership, route health, Docker/security policy, controller keys,
  and Git credential provider, then publishes its 60-second local lease. HTTP
  requires both current leases and refuses with 503 when either is absent,
  stale, failed, or bound to different bytes.
- `bin/duo-cloud-reap-expired-fleet` serially visits the closed fleet registry
  and revokes route, execution, then state for each expired single-site slot.
  One host-wide timer prevents 32 workers from racing the same fail-fast host
  authorities. `bin/duo-cloud-reap-origin` independently performs bounded,
  crash-resumable per-worker origin-object retention.
- `bin/duo-cloud-firewall-client` crosses a pinned, closed `sudo -n` boundary to
  a root authority. The authority binds nftables INPUT and FORWARD drops to the
  exact Docker bridge interface, permits only established host-initiated route
  replies, validates the Docker network id/IPAM/link, and atomically journals
  multi-site rules. Startup reconcile republishes the exact table after reboot.
- `bin/duo-cloud-storage-client` uses the same privilege bridge. Its root
  authority proves the actual LUKS2 mapping and XFS `prjquota` mount, assigns
  generation-owned DB/filesystem volumes to unique projects, applies byte and
  inode hard limits, and reads back root inheritance, every descendant's project
  membership, and quota reports.
- `bin/duo-cloud-route-authority` owns one host-global Caddy route document. It
  journals bind/unbind and atomically reconciles the complete config in the host
  phase. A principal-scoped worker phase snapshots only that worker's routes
  under the authority lock, then performs Docker and exact HTTPS health probes
  after releasing it, so one unhealthy site cannot revoke fleet health.
- `runtime/image/Dockerfile` builds the reviewed WordPress/MariaDB image as
  UID/GID 10001. The runtime has a read-only root, no capabilities, no privilege
  escalation, fixed tmpfs/resource bounds, no published port, an internal
  non-masqueraded IPv4 bridge, and only quota-backed DB/filesystem volumes.

Host/control processes pass child commands as argv arrays and never evaluate a
shell string. The intentionally contained `raw` preview action does invoke
`/bin/sh -seu` inside the hostile workload. After every raw or WP command the
command runner stops the entire container, proves the old process population is
gone, restarts it, and proves reviewed readiness before returning.

## Required production host

This service deliberately refuses a generic shared-hosting node or Docker
Desktop VM. The production boundary is a dedicated Linux host with:

- PHP 8.3 CLI and FPM with Sodium, POSIX, PCNTL, Phar, and JSON;
- a rootful Docker-compatible engine, Git, Caddy 2, curl, sudo, nftables,
  iproute2, cryptsetup/dmsetup, findmnt, xfsprogs, and an effective AppArmor LSM;
- Docker Engine 26.0.0 or newer. Every workload additionally pins the unrouted
  resolver `192.0.2.53`; this keeps DNS attempts inside the fenced workload
  namespace rather than relying on a daemon DNS proxy for internal networks;
- service user/group `duo-cloud` at numeric `10001:10001`, and a separate
  unprivileged `duo-cloud-edge` account for the loopback control Caddy. Add the
  edge account to the trusted `duo-cloud` supplementary group so it can open
  only the worker sockets owned `duo-cloud:duo-cloud` mode 0660;
- Docker reporting both `name=apparmor` and `name=seccomp`, with workload
  inspection proving `docker-default`, the pinned masked/readonly proc paths,
  cgroup namespace isolation, `Seccomp: 2`, memory+swap, CPU, PID, shm, and
  nofile limits;
- one LUKS2 dm-crypt mapper containing an XFS filesystem mounted with project
  quotas at `/var/lib/duo-cloud`; Docker `DockerRootDir` must be
  `/var/lib/duo-cloud/docker`, and all authority state, repositories, snapshots,
  configuration, keys, blobs, and both Caddy data roots must resolve beneath the
  same attested encrypted mount;
- the shipped seccomp profile. It default-denies ioctl and permits only the
  reviewed TCGETS, FIONREAD, and FIEMAP requests, so capless UID 10001 cannot
  clear XFS project inheritance/project ids. It also denies AF_ALG, AF_VSOCK,
  and the legacy x86 `socketcall` path;
- wildcard preview DNS and public 80/443 routed to the dedicated route Caddy.
  The separate public control TLS proxy forwards only the exact API paths to
  loopback `127.0.0.1:8081`. It must not share or overwrite Caddy admin
  `127.0.0.1:2019`, which belongs exclusively to the preview-route Caddy.

The service user is trusted and can invoke Docker. Tenant code never receives
the Docker socket, host filesystem, sudo, Caddy admin, or a host process. Do not
install this design as mutually hostile site workers sharing those capabilities.

## Immutable configuration layout

The files under `deploy/*.example` are visibly non-runnable templates. Every
`REPLACE_...` value must be supplied from measured host/image bytes. Render each
JSON document to canonical sorted JSON with exactly one trailing LF, place the
SHA-256 of those exact bytes in its `.sha256` pin file, and only then install it.
Never put tokens, private keys, signed requests, or candidate source in argv,
URLs, environment variables, labels, or logs.

The one encrypted layout is:

```text
/opt/duo-cloud/                         root-owned, non-writable program tree
  bin/                                  non-root entrypoints
  libexec/                              absolute-interpreter root authorities
  src/ runtime/ deploy/                 pinned executable closure and artifacts
/var/lib/duo-cloud/                     LUKS2 + XFS prjquota mount
  config/                               authority/client configs, pins, keys
  workers/<worker>/                     service state, repo, snapshots
  firewall/ storage/ routes/            host-global durable journals
  docker/                               exact DockerRootDir
  caddy/{route,control}-{config,data}/   separate Caddy state roots
```

Install these canonical root-owned, non-group/world-writable public config and
pin pairs beneath `/var/lib/duo-cloud/config`:

```text
firewall-authority.json  firewall-authority.sha256
firewall-client.json     firewall-client.sha256
storage-authority.json   storage-authority.sha256
storage-client.json      storage-client.sha256
route-authority.json     route-authority.sha256
```

The client configs pin `/usr/bin/sudo`, the absolute PHP CLI interpreter, the
root authority, and its complete root-owned PHP closure. Install the root
authorities at the exact `/opt/duo-cloud/libexec/...` paths with the absolute
interpreter shebang shown in the templates. Validate and install
`deploy/duo-cloud.sudoers.example` as root with `visudo -cf`; do not grant
arbitrary sudo or allow environment preservation. The trusted service units
must not set `NoNewPrivileges=true`, because lifecycle/preflight deliberately
cross these two pinned sudo boundaries.

Private Git credentials use fixed files:

```text
/var/lib/duo-cloud/config/repository-credential.json
/var/lib/duo-cloud/config/repository-credential.sha256
/var/lib/duo-cloud/config/repository-token
```

The descriptor is canonical `duo-cloud-git-credential-provider/v1` with exactly
`format,password_file,password_sha256,remote_url_sha256,username`. The token
file contains one high-entropy token plus LF and is service-owned mode 0600.
`remote_url_sha256` is
`sha256("duo-cloud-repository-remote-url/v1\0" + canonical_https_url)`.
`duo-cloud-git-credential verify` emits only a credential-free receipt.

The production config has exactly these top-level fields:

```text
controller_keys, format, host_durable_paths, host_preflight_root, runtime,
service, state_root
```

The canonical fleet config has exactly `format, workers` at the top level. Each
newly rendered worker entry has exactly:

```text
configuration_file, configuration_sha256, retiring_configuration, worker_id
```

`retiring_configuration` is either `null` or an exact
`{configuration_file,configuration_sha256}` object for that same worker. During
one worker-config rotation, retain the exact old config path and pinned bytes in
that field, together with their shared-authority admissions, until every active
or expired slot created under those bytes has drained or been reaped. Only then
set the field to `null` and remove the old config. Never introduce a second
retiring config before completing that drain: one current plus one retiring
configuration is the closed rotation bound. Retain every cleanup-required
immutable artifact named by the retiring config until its last generation is
terminal: the old image must remain resolvable, and its seccomp profile,
process launcher, container engine, route/firewall/storage clients, Git
executable, and repository source must remain at the exact pinned paths and
bytes. If an upgrade would overwrite one of those fixed paths, drain and reap
the old generation before replacement, or install the new artifact at a
versioned path first.

`host_durable_paths` has exactly:

```text
authority_config_root, control_proxy_config_root, control_proxy_data_root,
firewall_authority_state_root, route_authority_state_root,
route_proxy_config_root, route_proxy_data_root, storage_authority_state_root
```

`runtime` has exactly:

```text
container_engine, firewall_authority, git, image, memory_bytes, nano_cpus,
pids_limit, platform_fingerprint_sha256, preview_domain, process_launcher,
process_timeout_seconds, repository_remote, repository_source,
review_receipt_sha256, route_authority, runtime_contract_file,
runtime_contract_sha256, seccomp_profile_file, seccomp_profile_sha256,
snapshot_object_root, storage_authority, workload_repository_path
```

All executable fields including `process_launcher` are `{path,sha256}`. The
launcher must be the absolute CLI PHP binary; FPM's `PHP_BINARY` is not a CLI
launcher. The image must be `registry/name@sha256:<64 lowercase hex>`.
`review_receipt_sha256` is exactly the `proof_receipt_sha256` from one canonical
`duo-cloud-runtime-image-proof/v1` document for that same immutable image and
seccomp digest after exact cleanup; it is not free-form review metadata. The
service re-reads that private proof during preflight and HTTP readiness.
`repository_remote` is exactly
`allowed_ref_prefix,credential_helper,credential_helper_sha256,name,url,url_sha256`.
Its URL is credential-free canonical HTTPS and the ref prefix is a dedicated
`refs/heads/.../` namespace. The local repository must already have that pinned
remote name and URL.

The example timeout chain is intentional: the production outer runner is 90s,
the sudo clients are 60s, and root authority child calls are at most 5s/10s.
Do not invert that ordering. FPM has `request_terminate_timeout=0`; killing a
worker asynchronously can strand a multi-journal transition. Every lock is
fail-fast and every child operation instead owns a bounded monotonic deadline.

## Deployment order

1. Provision the LUKS2 mapper/XFS `prjquota` mount and create the complete
   `/var/lib/duo-cloud` layout, including the private durable
   `runtime-image-verifier` authority directory, before starting Docker. Configure Docker's root
   there, restart Docker, and record the exact LUKS UUID, mapper geometry,
   cipher/key size, XFS UUID, binaries, and digests in the storage config.
2. Install the immutable program tree, runtime-image contract, seccomp profile,
   absolute root authorities, private keys, Git
   credential files, and the validated sudoers rule. Create worker repository,
   snapshot, and state directories as service-owned mode 0700. Do not render
   the final worker configs until the immutable runtime-image receipt in step 3
   is known.
3. Build and behavior-test `runtime/image/Dockerfile`, push it, resolve the
   registry digest, and rerun the verifier against that immutable reference.
   Put only that reference and its exact proof receipt in production config.
   Render `fleet-config.json.example` as `config/fleet.json`, pin its bytes,
   and install `fleet.env`. Admit every worker in the firewall, route, and
   storage registries, then use `duo-cloud-install-runtime-image-proof` to
   validate and atomically publish the staged proof as the service identity.
4. Install the normative `php-fpm.ini`, `php-fpm-pool.conf.example` (after
   replacing the worker config path/pin), Caddy artifacts, and systemd units.
   The control Caddy has `admin off` and listens only on 127.0.0.1:8081; the
   route Caddy starts from `route-caddy-initial.json` and owns admin 2019.
5. Start Docker, then `duo-cloud-route-caddy.service`, one templated FPM unit
   per configured worker such as `duo-cloud-php-fpm@site-a.service`, and
   `duo-cloud-control-caddy.service`. Each public control hostname must map to
   only its worker's distinct `/run/duo-cloud/<worker>/php-fpm.sock`; the final
   edge handler returns 404 and never chooses a fallback worker. Run the
   portable `verify-fpm-ingress.php` proof, enable the installed-FPM proof
   timer, and enable one strict Linux host-proof timer per worker before
   exposing public control traffic.
6. Start `duo-cloud-preflight-fleet.timer`, then one
   `duo-cloud-preflight@<worker>.timer` per worker. Both refresh every 20 seconds,
   comfortably inside their independent 60-second leases. The fleet phase runs
   shared authority reconciliation once; worker phases contain failures to the
   affected site. A detected host failure invalidates all workers immediately,
   while a local failure invalidates only that worker. A crashed refresher stops
   renewing its lease, so HTTP fails closed after at most 60 seconds.
   Fleet preflight and fleet preview reap share one fail-fast maintenance lock.
   A real route or firewall authority collision also returns the closed
   temporary-failure signal: a still-current exact host or worker lease remains
   readable but is never renewed. A stale or mismatched lease, malformed signal,
   or genuine authority failure is invalidated instead.
7. Enable the singleton `duo-cloud-reap-expired-fleet.timer` and one
   `duo-cloud-reap-origin@<worker>.timer` per worker. The fleet reaper walks all
   workers in canonical order, continues after a value-screened local refusal,
   and never overlaps itself. On upgrade, disable and remove any previously
   installed `duo-cloud-reap-expired@*.timer` units before enabling the fleet
   timer, following the drain sequence below. Both janitor service types set
   `TimeoutStartSec=0`, so systemd imposes no start deadline. Their durable
   journals recover an unexpected process loss on the next invocation, but an
   operator must not stop or kill a janitor while it is active.

For an upgrade from the former templated preview-reap schedule, first stop and
disable every legacy timer. Only after all timers are stopped, wait for every
corresponding `duo-cloud-reap-expired@<worker>.service` that is already active
to finish naturally. Do not run `systemctl stop`, `systemctl kill`, or
`systemctl restart` against an active legacy service.
Never stop, kill, or restart an active legacy service.
Once every legacy service is inactive, remove the obsolete template files from
the directory where this deployment installed them, reload systemd, and only
then start the singleton. List every registered worker in `legacy_workers`
before running this sequence:

```bash
set -euo pipefail

legacy_workers=(site-a site-b)

for worker in "${legacy_workers[@]}"; do
  systemctl disable --now "duo-cloud-reap-expired@${worker}.timer"
  timer_state=$(systemctl show --property=ActiveState --value \
    "duo-cloud-reap-expired@${worker}.timer")
  if [ "$timer_state" != inactive ]; then
    echo "legacy reaper timer ${worker} stayed ${timer_state}" >&2
    exit 1
  fi
  if systemctl is-enabled --quiet "duo-cloud-reap-expired@${worker}.timer"; then
    echo "legacy reaper timer ${worker} stayed enabled" >&2
    exit 1
  fi
done

for worker in "${legacy_workers[@]}"; do
  while :; do
    state=$(systemctl show --property=ActiveState --value \
      "duo-cloud-reap-expired@${worker}.service")
    case "$state" in
      inactive) break ;;
      active|activating|deactivating|reloading) sleep 1 ;;
      *) echo "legacy reaper ${worker} stopped in unexpected state: ${state}" >&2; exit 1 ;;
    esac
  done
done

rm -f /etc/systemd/system/duo-cloud-reap-expired@.timer \
  /etc/systemd/system/duo-cloud-reap-expired@.service
systemctl daemon-reload
systemctl enable --now duo-cloud-reap-expired-fleet.timer
```

The singleton host services use a root-owned
`/var/lib/duo-cloud/config/fleet.env` containing only:

```text
DUO_CLOUD_FLEET_CONFIG_FILE=/var/lib/duo-cloud/config/fleet.json
DUO_CLOUD_FLEET_CONFIG_SHA256=<sha256 of exact canonical bytes>
```

Each worker uses a root-owned
`/var/lib/duo-cloud/config/workers/<worker>.env` containing only:

```text
DUO_CLOUD_CONFIG_FILE=/var/lib/duo-cloud/config/workers/<worker>.json
DUO_CLOUD_CONFIG_SHA256=<sha256 of exact canonical bytes>
```

## Pairing and preview flow

1. After a green active preflight, issue a short-lived one-use code on the
   protected host with `duo-cloud-origin-admin issue-device-code --ttl 600`.
   This local admin action is intentionally absent from the public router.
2. The site administrator installs Duo and runs `duo origin pair` against the
   provider's control URL. The service retains only the keyed digest of the
   plaintext code.
3. The controller requests an export for one expected production commit. The
   paired origin uploads only missing content-addressed chunks and commits the
   immutable demanded export.
4. The controller pushes the candidate commit to its deterministic operation
   ref. Signed `repository-sync` fetches only that ref and proves the exact
   remote/commit receipt before any materialization.
5. Lifecycle creates and holds a generation, restores database/media, applies
   the synced commit, rebinds URLs, and asks the global route authority for the
   exact HTTPS hostname. TTL expiry is fenced and reaped in route, execution,
   then state order.

The public TLS proxy must exact-match only the paths listed in
`deploy/control-edge.Caddyfile.example`, preserve the raw target/body/content
headers, and enforce its 2 MiB body cap before FastCGI. Do not expose the local
admin binary, Docker, sudo helpers, authority configs, Caddy admin, or FPM
socket.

## Evidence and refusal

Build and exercise the real image:

```bash
cloud/deploy/verify-runtime-image.php --build --image duo-cloud-preview:local \
  --state-root /var/lib/duo-cloud/runtime-image-verifier
# After push/digest resolution, this run is the production evidence:
cloud/deploy/verify-runtime-image.php \
  --image registry.example.invalid/duo/wordpress@sha256:<digest> \
  --state-root /var/lib/duo-cloud/runtime-image-verifier \
  > /var/lib/duo-cloud/workers/site-a/state/runtime-image-proof.candidate.json
```

The verifier requires the explicit durable private
`--state-root .../runtime-image-verifier`; its parent must already be a
canonical service-owned mode-0700 directory. One fixed lock and atomic journal
record every deterministic container, volume, private directory, and ephemeral
registry tag before effects. Startup reconciles that journal, while missing
journal plus deterministic residue refuses. This keeps Docker-persistent state
recoverable across verifier death and host reboot; `/tmp`, `/run`, and
`/var/tmp` are rejected as authority roots. The verifier then uses an ephemeral
loopback registry port, applies the exact seccomp profile, inspects all
resource/security fields, starts MariaDB and WordPress, checks the immutable
health endpoint, and invokes the real `ContainerCommandRunner` orphan-process
boundary. A successful tag is still not production authority; push it, pin the
registry digest, and rerun against that exact reference. Read
`proof_receipt_sha256` from the canonical output,
render it as `runtime.review_receipt_sha256`, then install the proof through the
checked path (the staged source must be service-owned and mode 0600):

```bash
export DUO_CLOUD_CONFIG_FILE=/var/lib/duo-cloud/config/workers/site-a.json
export DUO_CLOUD_CONFIG_SHA256=<sha256-of-worker-config>
export DUO_CLOUD_RUNTIME_IMAGE_PROOF_FILE=/var/lib/duo-cloud/workers/site-a/state/runtime-image-proof.candidate.json
export DUO_CLOUD_RUNTIME_IMAGE_PROOF_SHA256=<sha256-of-proof-file>
/opt/duo-cloud/bin/duo-cloud-install-runtime-image-proof
```

The installer publishes
`host_preflight_root/runtime-image-proof.<review_receipt_sha256>.json` with a
fixed runtime-proof family lock, one deterministic crash-recovery temp, atomic rename, and
directory sync. Never copy or redirect bytes directly into that destination.

Exercise the checked two-worker FastCGI/control-edge boundary with the installed
PHP-FPM, Caddy, and curl binaries:

```bash
cloud/deploy/verify-fpm-ingress.php
```

The proof starts two independent FPM masters and sockets, binds two exact
control hosts through Caddy, proves A cannot reach B (or vice versa), verifies
unknown-host and unreviewed-path 404 responses, checks socket ownership/modes,
and removes every disposable process and file before emitting its receipt.

That portable proof deliberately uses disposable configs and the invoking
identity. Before exposure, separately prove the installed systemd boundary.
Render `deploy/installed-fpm-ingress.json.example` for every fleet worker in
worker-id order. Its `host_preflight_root` must equal every admitted worker
config. Its pins are the SHA-256s of the installed
`control-edge.Caddyfile`, `php-fpm.ini`, each
`/var/lib/duo-cloud/config/workers/<worker>-fpm.conf`, and each pinned
`/var/lib/duo-cloud/config/workers/<worker>.json`. Publish the canonical,
root-owned descriptor as
`/var/lib/duo-cloud/config/installed-fpm-ingress.json`; publish a root-owned
`/var/lib/duo-cloud/config/installed-fpm-ingress.env` from
`installed-fpm-ingress.env.example` with that descriptor's SHA-256. Install the
exact `duo-cloud-verify-installed-fpm-ingress.service`, reload systemd, and run:

```bash
systemctl enable --now duo-cloud-verify-installed-fpm-ingress.timer
```

The oneshot refuses unless the active no-drop-in unit bytes, process identities
and executables, rendered pool and Caddy bytes, worker config pins, socket
ownership, live Host-to-socket routes, and empty 404 fallback all match. The
portable and installed proofs establish different premises; both are required.
macOS can run only the portable proof and cannot emit installed readiness.
The timer refreshes every 30 seconds. Its boot-bound receipt expires after 90
seconds; explicit refusal invalidates immediately, while SIGKILL is bounded by
that short expiry. Verification and publication share one 45-second wall bound;
the systemd supervisor allows 60 seconds so it cannot kill a valid maximum-fleet
publication at the verifier boundary. One fleet-global control receipt commits
content-addressed per-worker receipts only after every successful worker inode
is durable. A worker-local pool, process, socket, or live-route failure removes
only that worker's active versions; healthy workers remain current. A shared
Caddy, descriptor, static artifact, or publication failure invalidates the
global control receipt and therefore every worker.

Before service exposure, run the checked Linux host verifier (when installed)
as the trusted service user through
`duo-cloud-verify-linux-host-boundaries@<worker>.service`. Render one private
`workers/<worker>-host-proof.env` from `worker-host-proof.env.example` and
enable the corresponding timer for every 1..32 fleet worker. Its environment
contains only the canonical worker config path and SHA-256, the shared
host-preflight root, plus a synthetic firewall-rotation
digest computed as
`sha256("duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1\0" +
configuration_sha256)`. The verifier derives the engine, immutable image
reference, resolved image ID, seccomp profile, and worker storage identity from
those pinned config bytes; the synthetic digest is admitted only in that same
worker's firewall rotation set.

Strict receipts use
`linux-host-boundaries-proof.<configuration_sha256>.json`; workers and rotating
configs therefore cannot overwrite or cross-admit each other. Each receipt
binds the exact runtime-image receipt and resolved image ID, current boot,
verifier/runtime artifact hashes, full proof, and a seven-day expiry. The
per-worker timer renews every 12 hours and the shared proof lock serializes all
instances. A lock or exact host-authority busy response exits 75 and the unit
retries after 30 seconds without invalidating current evidence. A durable
15-minute renewal intent keeps the previous exact receipt usable during that
bounded attempt; retries preserve its original deadline. An expired, failed,
or prior-boot intent revokes the old receipt before a fresh attempt begins, and
an explicit verifier failure invalidates immediately. Docker networks,
containers, volumes, firewall bindings, storage proof assignments, and runtime
scratch names are deterministic per configuration. Each retry first reconciles
the exact labeled prior-attempt set, so SIGKILL cannot strand undiscoverable
proof resources and later claim exact cleanup. Remove the obsolete singleton
strict unit before installing this template; it is not a supported second
renewal path.

The strict proof must establish the actual non-root client-to-sudo path,
nftables workload-to-host and workload-forward denial, positive host-to-health
reachability, multi-site rule preservation, LUKS2/XFS quota bind and hard-limit
enforcement, seccomp mutation denial, and exact cleanup. macOS, Docker Desktop,
a host without AppArmor, or a Docker root outside the attested mount must refuse
rather than produce a readiness record. On a dedicated nonproduction Linux host
whose Docker lacks AppArmor, the manual, non-installable
`duo-cloud-diagnose-linux-host-boundaries-nonproduction.service`
may be run for partial diagnostics. It always records `production_ready:false`
with nonproduction scope and exits 78, including on an AppArmor-capable host; it
is never readiness evidence and must never replace the strict unit.

Strict evidence snapshots the exact worker configuration, runtime executables,
seccomp profile, and the verifier's closed PHP source dependency set before
effects. After exact cleanup it rechecks inode, ownership/mode, timestamps,
size, and digest before publication. The receipt binds the canonical source-set
digest as `artifact_sha256s.php_closure_sha256`; readiness also re-hashes the
live configured artifacts, so an atomic deployment rename cannot reuse an old
proof.

Worker preflight and every public HTTP readiness decision require the complete
joined evidence set: the per-review runtime image proof, that configuration's
strict host receipt, the fleet-global installed-FPM control receipt, and its
content-addressed per-worker FPM/socket receipt. The 60-second worker lease
binds their stable proof identity, so a refreshed proof with changed evidence
requires a new worker preflight. Missing, tampered, expired, cross-boot, or
cross-worker evidence is a service-unavailable refusal, never synthetic
readiness.

Focused deterministic development evidence:

```bash
find cloud -type f \( -name '*.php' -o -path 'cloud/bin/*' \) -exec php -l {} \;
vendor/bin/phpunit tests/Cloud
vendor/bin/phpstan analyse --memory-limit=1G --no-progress cloud tests/Cloud
```

Repository merge evidence remains `composer check`, `make regress-offline-all`,
and `make release-gate` from the root `AGENTS.md`.
