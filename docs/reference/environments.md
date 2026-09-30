# Environment registry and transports

Use [quickstart](../guides/quickstart.md) to create a connection. This reference covers registry provenance, transport configuration, and provider bindings.

## The environment registry

An environment registry is a JSON object under an `"envs"` key, keyed by
environment name:

```json
{
  "envs": {
    "local": {
      "transport": "docker",
      "compose_file": "/home/me/my-site/compose.yml",
      "service": "wpcli",
      "repo_path": "/siterepo"
    },
    "stage": {
      "transport": "ssh",
      "host": "deploy@stage.example.com",
      "wp_path": "/var/www/html",
      "repo_path": "/srv/site"
    },
    "dev": {
      "transport": "local",
      "wp_path": "/var/www/html",
      "repo_path": "/home/me/site",
      "bootstrap": {
        "format": "wprism-local-control-plane/v1"
      }
    }
  }
}
```

`repo_path` is required for every environment, on every transport — it's
the site-repo path **as seen from inside that environment** (a container
path, a remote path, or a local path), passed straight through as
`wp wprism <verb> --repo=<repo_path>`.

Per-transport required keys:

| Transport | Required keys | Optional keys |
|---|---|---|
| `local` | `wp_path`, `repo_path` | machine-local-only exact `bootstrap: {"format":"wprism-local-control-plane/v1"}` |
| `docker` | attachment: `compose_file`, `service`, `repo_path`; existing-service bootstrap adds `wordpress_service`, `wp_path`; managed bootstrap uses `compose_file`, `wordpress_service`, `tooling: "managed"` and generates the helper fields | `compose_env_file`, `profile`, `mode` (`"run"` default, or `"exec"` for an existing service) |
| `ssh` | `host`, `wp_path`, `repo_path` | `ssh_config`, paired `rollback_key_id` + `rollback_signing_key`, `rollback_recovery`, `verified_rollback` |

A missing required key is a loud, specific error naming the environment,
the key, and the transport — never a guess.

`compose_env_file` is an optional machine-local path passed to Compose as
`--env-file` before `-f`. It is useful for parameterized Compose definitions
whose project name, mounts, or ports must remain fixed across fresh `run --rm`
calls; the path must exist when the registry entry is loaded.

The local/Docker `bootstrap` member grants only the delivery mechanism. It is accepted
only from an untracked `.wprism-envs.json` entry whose provenance is assigned by
the registry loader; the same bytes in checked-in `site.wprism.json` remain
unsupported. A present bootstrap object is closed and exact—`null`, another
format, or any extra key is a configuration error. `wprism driver-capabilities`
does not contact WordPress. The later `wprism adopt` command emits and binds a
`wprism-bootstrap-eligibility/v1` report for the exact target before installation.
For Docker, use `connect --tooling=managed` when the application Compose file
has no WP-CLI service. WPrism builds its pinned controller-owned helper image,
writes a private generated overlay without copying resolved environment values,
and gives it a separate durable repository volume. The original Compose file is
not edited, application services are never started or stopped, and helper runs
use `--no-deps`. The running official WordPress service must have a writable
persistent `/var/www/html`; custom images and mounts that shadow the MU control
path refuse. Advanced users may name an existing WP-CLI/Git-capable service;
the same local-daemon, running-web, storage, and initial-authority checks apply.

### Optional branch-environment provider

Physical production snapshots, resource creation/cleanup, URLs, and host TTLs
are privileged host operations, not transport primitives. An environment may
therefore add this exact block only in the machine-local `.wprism-envs.json`
entry (checked-in `site.wprism.json` is rejected even if it tries to forge loader
provenance):

```json
{
  "environment_provider": {
    "command": ["/absolute/path/to/provider", "--site=example"],
    "timeout_seconds": 30
  }
}
```

The command receives one canonical
`wprism-branch-environment-provider-request/v2` object on stdin and must return
one canonical `wprism-branch-environment-provider-response/v2` object on stdout.
Protocol 2 has a closed capability vocabulary. It deliberately replaces v1:
`repository-materialize` now requires the provider to echo the distinct named
`target_branch` it checked out. Because `capabilities` is always the first,
non-mutating action, a v1 provider is rejected before snapshot, resource, or
repository mutation. Upgrade both wire format strings and `provider.protocol`
before running materialization.

```
snapshot.set.prepare      snapshot.set.create      snapshot.set.read
snapshot.set.abort        snapshot.set.restore
environment.inspect       environment.attach       environment.create
environment.destroy       environment.detach       environment.ttl
environment.ttl.read      environment.mutation.acquire
environment.mutation.read environment.mutation.release
environment.url.discover  environment.url.set      repository.materialize
operation.receipts
```

Operation responses contain only opaque IDs/digests and redacted operational
evidence—never database/media bytes, credentials, signed URLs, or production
PII. `snapshot-prepare` acquires a provider-owned source freeze and returns one
operation-bound session/lease receipt. While that freeze is held, WPrism exports
semantic production truth; `snapshot-create` consumes the same session and
returns one `snapshot_set_id` binding database and media hashes, retention
evidence, source identity, and that semantic snapshot hash. `snapshot-read`
must return the immutable set before restore; `snapshot-abort` idempotently
releases an unfinished session. There is no raw or independently timed DB/media
fallback.

`mutation-acquire` obtains an exclusive operation-scoped target fence before
the first target mutation. `mutation-read` reconciles it after an interruption,
and `mutation-release` publishes an idempotent release receipt only after
promotion convergence and TTL readback. Only that held-to-released transition
may mint a new mutation receipt; every held or released `mutation-read` must
return the exact receipt already journaled for its current state. Every
mutating target request carries
`expected_environment_identity`, `expected_resource_id`, `expected_lease_id`,
`expected_lease_generation`, and `expected_ownership_receipt_sha256`; the
provider must also enforce the held mutation fence tuple. `ttl-set`/`ttl-read`
publish and verify expiry metadata only; they must not schedule or perform
automatic destruction. Reap compares both leases before its explicit destroy
or detach. The host journal lives under Git's common directory at
`wprism-environments/` with mode-0600 immutable run/event records; it is
operational recovery state and never canonical branch state.

`ssh_config`, when present, is passed to both `ssh -F` and `scp -F` and may be
relative to the registry file that defined the environment. This is the
single place to configure a non-default port, identity, proxy jump, and
host-key policy without embedding shell options in `host`.

`rollback_key_id` and `rollback_signing_key` are optional as a pair. The key
path resolves relative to the registry file, must be a regular mode-`0600`
file, and contains canonical base64 Ed25519 secret-key bytes. Keep it in the
gitignored machine-local overlay. `wprism adopt` derives and installs only its
public key under `<repo_path>/.wprism/control/public-keys/`; a key id is immutable,
so rotation uses a new id. The controller secret is never copied to the host.

SSH adoption also installs the database-independent recovery runtime under
`<repo_path>/.wprism/control/recovery-runtime/` and creates one stable external
target identity. `wprism status` verifies the active signed receipt and complete
event hash chain. An invalid chain or any active nonterminal state is
non-green; only `committed` and `rolled_back` active generations are green.
`wprism deploy` and `wprism promote` refuse target mutation while that external
authority is invalid or nonterminal. A host adopted before this runtime emits
a manual-recovery warning and retains the operator-directed behavior.

When both the rollback verification key and recovery configuration are
installed, adoption also writes a mode-`0600`
`<mu-plugin-dir>/wprism/scoped-promotion-control.json`. It contains only the
absolute adopted control root and a closed format tag. Receipt-bearing target
commands cannot choose another root: they run the installed raw recovery
runtime, verify the signed active chain, and re-prove the live v2 exclusion
before begin/apply/complete. Runtime/provider stderr and opaque exclusion
tokens never enter public JSON.

`rollback_recovery` configures a target-owned exclusion provider plus exact
`code_restore`, `database_restore`, `prior_verify`, and `storage_restore` argv
adapters. Its optional `checkpoint_provider` argv enables encrypted database
checkpoint preparation and the stricter database/prior-verifier execution
path. Its optional `code_release_provider` argv enables off-target-built,
immutable descriptor-bound releases plus signed-journal `code_select` and
atomic `code_restore`; without it, code recovery is explicitly manual.
Its optional `upload_provider` prepares encrypted local/offload before-images,
adds signed-journal `storage_apply`, and makes `storage_restore` enforce exact
absence plus fresh prior-inventory verification; without it, upload recovery
is explicitly manual.
Its optional `effect_provider` prepares the compiled lifecycle/rebuild effect
inventory, receipt outboxes, and pinned inverse inputs, then enables
`effects_inverse` with fresh prior readback; without it, effect recovery is
explicitly manual.
`verified_rollback` is controller-only policy and contains exactly
`claim_ttl_seconds` (30..3600), `encryption_key_id` (the external KMS/provider
label, never key material), and `retention_seconds` (60..31536000). It is not
copied into the adopted recovery runtime. When this policy and all four
checkpoint/code/upload/effect providers are configured and pass runtime
preflight, `wprism promote` selects the automatic profile by those capabilities,
binds the compiled plan's exact code/upload/effect inventories into the
provider preparation and receipt v2,
and uses the signed operation journal. Any missing capability produces a loud
WARN and retains the operator-directed checkpoint path; a configured but
failed preflight refuses instead of degrading.

The SSH scoped-promotion profile uses the same policy, exclusion provider, and
checkpoint provider but prepares no code/upload/effect release evidence. Its
exclusion provider must attest the v2 `database_writers: true` scope in
addition to the other four scopes. That is a whole-target promise covering
direct database clients, workers, integrations, and migration tooling—not
just public HTTP or WordPress cron.

Adoption probes all configured capabilities. Provider tokens and key
material never enter the registry or command line; only hashes and the
external key id are bound into a signed receipt. See
[docs/recovery-runtime.md](../recovery-runtime.md) for the protocol and
automatic-profile boundary, and
[docs/checkpoint-bundle.md](../checkpoint-bundle.md) for the checkpoint
contract, and [docs/code-release-runtime.md](../code-release-runtime.md)
for the atomic code-release contract.
See [docs/upload-bundle.md](../upload-bundle.md) for the upload/media
journal and provider contract.
See [docs/effect-bundle.md](../effect-bundle.md) for the manifest grammar,
preflight isolation, runtime reconciliation, and inverse contract.

### Where the registry comes from

`wprism` merges **two** optional files. It searches upward for `site.wprism.json` so
commands work from any subdirectory, but inside Git accepts that file only at
the current worktree root. It then accepts an automatically discovered
machine-local overlay only beside that site file. When no site file exists,
the overlay must be at the current Git worktree root. A nearer nested registry
or overlay is refused instead of being allowed to shadow the trusted
environment names; use the explicit overlay override only when you
intentionally trust another path:

1. **`site.wprism.json`** — the site repo's own policy file (see
   [spec/repo-format.md](../../spec/repo-format.md)). Committable: the `envs`
   entries here should contain nothing secret (no passwords, no bare API
   tokens) since this file is meant to be shared with the whole team via
   git. This is the natural home for the list of environments that *exist*
   for this site (e.g. `dev`, `stage`, `prod`) and whatever about them is
   true for everyone.
2. **`.wprism-envs.json`** *(gitignored)* — a machine-local overlay, same
   shape (`{"envs": {...}}`), for anything that's true only on this
   machine or shouldn't be committed: a local docker-compose file path, an
   ssh alias only you have configured, a sandbox-only environment nobody
   else needs.

**The overlay wins whole-entry, per environment name** — if `stage` exists
in both files, `.wprism-envs.json`'s `stage` entirely replaces
`site.wprism.json`'s (no per-key deep merge). This keeps the merge rule simple
and predictable: for any given environment, exactly one file is "the"
source of truth, and it's always the more machine-specific one when both
define it.

Relative filesystem paths inside an environment entry (currently just
`compose_file`) resolve against the directory of **whichever file defined
that entry** — not the current working directory — so a registry file
keeps working no matter where you invoke `wprism` from.

Pass `--envs-file=<path>` to explicitly trust and load the overlay at that
path instead of searching for `.wprism-envs.json`. Auto-discovery refuses a
Git-tracked `.wprism-envs.json`, because repository content cannot authorize a
privileged host provider; the explicit flag is an operator trust decision and
must never be populated from an untrusted repository or script.
`site.wprism.json` discovery is unaffected by this flag — it's specifically an
override for the machine-local half of the registry.

### Suggested `.gitignore` line

```
/.wprism-envs.json
/.wprism/*
!/.wprism/authority/
/.wprism/authority/*
!/.wprism/authority/authorities.json
/.wprism-env-values.json
```

(Already added to this repo's `.gitignore` for the sandbox.)

## Transports

All three transports build a fully `escapeshellarg()`-escaped command
string and run it either streamed (`passthru`, exit code propagated — used
by `capture`/`plan`/`apply`) or captured with stdout and stderr collected
on **separate** pipes (`proc_open`, used by `doctor`/`status`, which parse
output — `docker compose run`'s own container-lifecycle chatter lands on
stderr, and merging the streams would corrupt the JSON `wprism status` parses).

- **`local`**: `wp --path=<wp_path> wprism <verb> --repo=<repo_path> …`
- **`docker`**: `docker compose -f <compose_file> [--profile <profile>] run --rm -T <service> wp wprism <verb> --repo=<repo_path> …`
  (default, or explicit `"mode": "run"`)
- **`ssh`**: `ssh -T <host> 'cd <wp_path> && wp wprism <verb> --repo=<repo_path> …'`
  (the remote command is assembled with each part escaped, then the whole
  thing is escaped again as the single argument to `ssh`; `-T` defeats a user
  `RequestTTY=force` setting)

Raw (non-`wp`) commands, used only by `doctor`'s reachability, repo-path,
and `.wprism-env-values.json` git-tracked checks, follow the same shape but run through `bash -c '<script>'` for
`docker` (so shell operators like `&&`/`[ -d … ]` work — `docker compose
run`'s trailing arguments are otherwise passed as the container's argv
directly, not interpreted by a shell) and directly for `local`/`ssh` (PHP's
`proc_open`/`passthru` already invoke `/bin/sh -c` for string commands, and
`ssh` already hands its command argument to the remote login shell).

### `docker` transport mode (issue #3513)

`"mode": "exec"` is an opt-in per-environment key that switches every
`docker` command from `run --rm` to `exec` against an **already-running**
service:

```
docker compose -f <compose_file> [--profile <profile>] exec -T <service> wp wprism <verb> --repo=<repo_path> …
```

and the raw-command shape follows the same substitution (`exec -T <service>
bash -c '<script>'` in place of `run --rm -T <service> bash -c '<script>'`).
Any `mode` value other than `"run"` or `"exec"` is a loud, specific error
naming the environment, the key, and the offending value — never a silent
fallback to `run`. Leaving `mode` unset, or setting it to `"run"`, produces
byte-identical command strings to every environment defined before issue #3513.

**Why opt in.** `run --rm` pays container create plus (on the legacy
`sandbox/docker-compose.yml` estate, which uses `depends_on`) dependency
resolution plus wp-cli's own startup, on every single call — measured on one
host at roughly 0.40s + 0.51s + 0.33s. `exec -T` against a container that is
already up pays only the wp-cli startup floor, measured at roughly 0.14s —
about 0.77s saved per call on that estate, or about 0.26s per call on a
`sandbox/pair.sh` pair (which has no `depends_on` to begin with — see
`docs/sandbox.md`).

**What it costs.** Unlike `run --rm`, `exec` does not create a fresh
container per call:

- wp-cli's own cache/tmp state persists across calls instead of starting
  clean every time.
- Any edit to the service's environment variables or mounts goes stale until
  the operator recreates it (`docker compose up -d --force-recreate
  <service>`, or an equivalent).
- The service must already be running. `wordpress:cli`'s default CMD (`wp
  shell`) exits immediately without a TTY, so a service meant to stay
  resident for `exec` needs its own long-running `command:`, e.g. `command:
  ["tail", "-f", "/dev/null"]`.

**The not-running precondition.** Before building an `exec` command,
`DockerTransport` checks once per `wprism` process whether the target service
is running (`docker compose … ps --status=running --services`); the result
is cached for the rest of that invocation, so a single `wprism` call never pays
that probe twice. If the service is not running, every command — `wp`, raw,
and the env-set stdin handoff alike — fails with the exact remedy instead of
reaching docker at all:

```
env '<name>': transport mode "exec" requires service '<svc>' to be running. remedy: docker compose -f <compose_file> [--profile <profile>] up -d <svc>
```

so `wprism doctor <env>` renders `[FAIL] transport reachable — …` with that
remedy as its first check, the same way any other unreachable transport
does. It never falls back to `run`.

Container user stays whatever the service's own `user:` is (`33:33` for the
sandbox's `cli-*` services); `exec` does not change that. The `--exec`
control-plane bootstrap (`CodeDeploy::controlArgs`, used by `compile`,
`promotion-*`, `code-stage`, `code-preflight`, `code-finalize`, `deploy`,
`scope`) is per-process wp-cli state and is unaffected by container
residency. `env-set`'s stdin piping (`PassthroughCommand::streamWpInput`)
works the same way over `exec` as over `run`: `-T` is passed explicitly on
both, so stdin is a plain pipe rather than a TTY even against an older
compose that would otherwise allocate one.
