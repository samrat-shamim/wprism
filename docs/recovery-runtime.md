# Maintenance exclusion and recovery runtime

The recovery runtime under `<repo_path>/.wprism/control/recovery-runtime/` is a
PHP CLI that does not load WordPress. A production host supplies its own
maintenance-exclusion provider, optional checkpoint, code-release, upload, and effect
providers, and four recovery adapters. WPrism invokes each as an argv array through `proc_open`; it never
constructs a shell command.

This is the fail-closed execution substrate. issue #3299's production-form SSH
harness composes its checkpoint, code/storage, effect, fresh-verifier, and
external-authority paths end to end and certifies the closed crash matrix.
`wprism promote` selects that automatic profile only when this complete provider
set and the controller's explicit `verified_rollback` policy pass preflight;
otherwise it warns and retains the operator-directed recovery contract.
The code provider must additionally attest plan-bound compiled-code inventory;
automatic preparation sends that inventory through the signed v2 request and
refuses a generation-specific descriptor whose roots or file hashes diverge.

## The runtime is transport-independent by construction

Nothing above is SSH. The runtime is WordPress-free PHP, every provider is an
absolute-path argv vector invoked target-side, and the maintenance exclusion is
reserved and released by the authority on the target itself — the controller
holds no lock. A transport supplies exactly two things:

1. process invocation — `php <control-root>/recovery-runtime/rollback-control.php
   <action> --root=<control-root>`, which is the base transport's `captureRaw()`;
2. one mode-`0600` canonical-JSON handoff placed where the runtime can read it,
   and removed on every observed exit.

Those two members, plus the environment's declared capability predicates, are
the `RecoveryTransport` interface in `cli/src/Transport/RecoveryTransport.php`.
SSH and `local` implement it today; `docker` does not, because
`docker compose run --rm` gives every call a fresh container, so a handoff
written in one call is gone before the next and the handoff must travel through
the bind mount instead.

### A `local` target's signing key: what it buys and what it does not

On SSH the stated property is that the Ed25519 secret never leaves the
controller: the target sees only signed receipts, signed events, and the public
key adoption provisioned. On a `local` target the controller **is** the target,
so the secret and the control root share a machine. The signature still gives
tamper-evidence against a compromised recovery runtime or a corrupted journal —
a forged receipt or a rewritten event chain still fails verification — but it
gives **no** evidence against a compromised controller, which holds the secret.
That is a strictly weaker property than the SSH case.

Because it is weaker, arming it is privileged: `local` accepts
`rollback_key_id`, `rollback_signing_key`, `rollback_recovery` and
`verified_rollback` only from an environment carrying machine-local provenance,
which only an untracked `.wprism-envs.json` can supply. Without it the environment
refuses at load with `local rollback authority is privileged and has no
machine-local authorization`. An environment that never configures those keys is
unchanged in every respect, including its `wprism envs`, `wprism status` and
`wprism promote` output.

The `local` path is **not certified**. `docs/ssh-rollback-certification.md`
records what the SSH harness certified; its crash classes include SSH loss and
remote-command kill, which have no local analogue, so a local certificate is a
different document with a different matrix — not a re-badge of that one.

A separate SSH-only checkpoint profile serves
`wprism promote <env> --scope-contract=<path>`. It accepts only the bounded
DB-contained scoped-plan surfaces documented in `spec/repo-format.md`, invokes
no ordinary code/upload/lifecycle/effect recovery provider, and uses only the
encrypted database checkpoint plus prior verifier before its durable
`scoped_fresh_verification` seal. A failure before that seal restores and
verifies the checkpoint. The seal is forward-only: an exact retry first
finishes signed commit, then target-session cleanup and exclusion release, and
no later public scoped rollback exists.

The authority's deterministic crash hooks are inert on adopted production
roots even if `WPRISM_ROLLBACK_CRASH_AT` reaches the non-interactive SSH
environment. A hook is enabled only for a disposable test root containing the
exact mode-0600 `.certification-crash-mode` marker; adoption never creates that
marker. The regression harness creates it only around each injected request.

## Controller configuration

Put this in the gitignored `.wprism-envs.json` environment beside
`rollback_key_id` and `rollback_signing_key`. It is accepted on an `ssh`
environment, and — with the machine-local privilege described above — on a
`local` one:

```json
{
  "verified_rollback": {
    "claim_ttl_seconds": 300,
    "encryption_key_id": "production-kms-2026",
    "retention_seconds": 86400
  },
  "rollback_recovery": {
    "adapters": {
      "code_restore": ["/opt/wprism/bin/code-restore"],
      "database_restore": ["/opt/wprism/bin/database-restore"],
      "prior_verify": ["/opt/wprism/bin/prior-verify"],
      "storage_restore": ["/opt/wprism/bin/storage-restore"]
    },
    "checkpoint_provider": ["/opt/wprism/bin/checkpoint-provider"],
    "code_release_provider": ["/opt/wprism/bin/code-release-provider"],
    "upload_provider": ["/opt/wprism/bin/upload-provider"],
    "effect_provider": ["/opt/wprism/bin/effect-provider"],
    "exclusion_provider": ["/opt/wprism/bin/exclude-site", "production"],
    "timeout_seconds": 30
  }
}
```

Executable paths must be absolute. Adoption writes only these paths and the
timeout to the protected target root, then probes every configured provider and all four
adapters. `checkpoint_provider` is optional for hosts that only install the
issue #3294 executor substrate; it is required before encrypted database recovery
can be claimed. `code_release_provider` is optional; without it, code recovery
is explicitly manual and the in-place materializer cannot advertise automatic
rollback. Tokens, database credentials, and encryption key material must
not appear in configuration or argv; providers own any credentials they need.
`upload_provider` is optional; without it, upload recovery is explicitly
manual. When present it must prepare encrypted local/offload evidence before
the receipt can be claimed.
`effect_provider` is optional; without it, lifecycle/rebuild-action effect recovery
is explicitly manual. When present it prepares the compiled bounded inventory,
prior inverse inputs, and receipt outboxes before receipt publication. See
[effect-bundle.md](effect-bundle.md).

## Exclusion provider protocol

The provider reads one canonical JSON object plus a newline from stdin and
writes one canonical JSON object plus a newline to stdout. It implements
`probe`, `acquire`, `verify`, `keepalive`, `adopt`, and `release`.

Requests use `format: wprism-exclusion-provider-request/v2`, carry the exact
target/receipt/generation/owner/artifact/claimant identity (nullable only for
`probe`), and require all scopes:

```json
{
  "background_jobs": true,
  "database_writers": true,
  "filesystem_writers": true,
  "package_updates": true,
  "public_traffic": true
}
```

Responses contain exactly `available`, `disconnect_behavior`, `format`,
`provider_id`, `provider_version`, `scopes`, `state`, `target_id`, and `token`.
They use `format: wprism-exclusion-provider-response/v2`, attest
`disconnect_behavior: remain_excluded`, and repeat all five true scopes.
`probe` returns `state: ready` and a null token; acquire/verify/keepalive/adopt
return `held`; release returns `released`.

`database_writers` is a separate, whole-target attestation: it means the
provider has excluded every non-WPrism path that can write the target database,
including direct database clients, application and integration writers,
cron/queue and CLI workers, and deployment or migration tooling. It is not
satisfied merely by stopping public HTTP traffic or the WordPress scheduler.
The v2 provider protocol deliberately replaces v1: a v1 request or response
is non-green, so operators must upgrade the provider before a new recovery
generation can be probed or claimed.

The scoped-promotion target gate does not trust the compact scope request or a
host-supplied receipt hash. Adoption writes a mode-`0600`
`<mu-plugin-dir>/wprism/scoped-promotion-control.json` containing only the fixed
absolute control root and format. Before target begin, immediately before
authored apply, and before target completion, the installed agent invokes the
fixed raw runtime's internal `scoped-promotion-witness` action. That action
verifies the complete signed active chain, reads the exact exclusion record,
and invokes only the configured exclusion provider's `verify` operation. Its
closed output binds receipt format/payload hash, owner, artifact, scope,
generation, target, signing key, signed delete capability, authority state,
and held exclusion without
publishing the opaque token, filesystem paths, provider output, or target
values. `promoting` authorizes mutation; `committed` authorizes only exact
terminal replay/completion while exclusion is still held.

The target random `ps-*` session persists the same closed profile/generation/
receipt/scope/target/key/delete-capability tuple in its first durable write.
The scoped mutation authority/archive separately seals a hash-only copy of
that external generation. An old terminal for the same scope/artifact cannot
be replayed by a fresh receipt, and a no-delete receipt cannot be widened by a
target-local `--with-deletes`. An ordinary
promotion session cannot be reinterpreted as scoped, omitting the receipt
cannot fall through to generic continuation, and completion cannot retire the
handoff before the signed authority is committed. A lost completion response
remains retryable after the controller claim TTL because terminal signed state
and the held fail-closed exclusion—not wall-clock freshness—authorize cleanup.

A disconnect or failed keepalive must leave all scopes excluded indefinitely.
The provider must never use lease expiry to reopen the site. `adopt` transfers
control to the exact next signed claimant epoch without changing the token.

WPrism stores the opaque token only in mode-`0600` `exclusion.json`. Its SHA-256
digest is bound into the immutable receipt. A reservation acquired before
receipt publication can only be retried for its exact receipt, generation,
owner, artifact, and claimant. Any mismatch is non-green and stays excluded.
Release is accepted only while the authority proves `committed` or
`rolled_back`.

## Recovery adapter protocol

Each adapter implements `probe` and `execute` over canonical JSON stdin/stdout.
Requests use `wprism-recovery-adapter-request/v1`. An execute request names the
adapter/operation id, attempt, claimant epoch, receipt, input path, and SHA-256.
The runtime runs it only when the signed event chain has an open `prepared`
operation with the same adapter, attempt, and input digest.

Responses contain exactly `adapter`, `adapter_version`, `available`, `format`,
`input_sha256`, `loads_site_code`, `result_sha256`, and `status`, with format
`wprism-recovery-adapter-response/v1`. A probe returns `status: ready`, null
input/result hashes, and `loads_site_code: false`. Execution returns the exact
input hash and a result SHA-256. The controller must append that digest in the
signed completion event; the adapter cannot complete or broaden its own
authority.

The base adapter names are:

- `database_restore`: import the prepared database checkpoint;
- `code_restore`: restore the prepared prior code descriptor;
- `storage_restore`: restore prepared uploads or declared storage;
- `prior_verify`: run the prepared prior-world verifier inputs.

Provider- or layout-specific behavior belongs behind these commands. Missing
executables, timeouts, malformed/noncanonical evidence, incomplete scopes,
token mismatch, stale claimants, and unprepared inputs fail before execution
or release.

When `checkpoint_provider` is configured, WPrism routes `database_restore` and
`prior_verify` through the stricter checkpoint protocol instead of the generic
adapter contract. See [checkpoint-bundle.md](checkpoint-bundle.md). When
`code_release_provider` is configured, WPrism adds `code_select` in `promoting`
and routes both it and `code_restore` through the immutable atomic-release
protocol. See [code-release-runtime.md](code-release-runtime.md). Storage
uses the generic adapter only without `upload_provider`. With the provider,
WPrism adds `storage_apply` in `promoting` and routes it and `storage_restore`
through the bounded upload journal. See [upload-bundle.md](upload-bundle.md).
With `effect_provider`, WPrism adds `effects_inverse` in `rolling_back` and routes
it through immutable lifecycle/rebuild-action evidence plus a fresh provider-process
readback. It is intentionally not one of the four generic adapters.

## Operator probes

These commands run through raw SSH even when `wp-config.php`, plugins, themes,
or user MU plugins are broken:

```sh
php <repo_path>/.wprism/control/recovery-runtime/rollback-control.php \
  recovery-probe --root=<repo_path>/.wprism/control

php <repo_path>/.wprism/control/recovery-runtime/rollback-control.php \
  status --root=<repo_path>/.wprism/control

php <repo_path>/.wprism/control/recovery-runtime/rollback-control.php \
  audit --root=<repo_path>/.wprism/control
```

`audit` returns hash-only evidence for the verified active receipt and its
complete event chain. It does not expose provider tokens, checkpoint bytes,
or key material.

Do not edit `target.json`, `exclusion.json`, receipts, or events. A new
controller takes over through a signed authority event and signed exclusion
`adopt`; manual edits are treated as corruption.
