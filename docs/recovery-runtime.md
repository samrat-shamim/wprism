# SSH maintenance exclusion and recovery runtime

The recovery runtime under `<repo_path>/.duo/control/recovery-runtime/` is a
PHP CLI that does not load WordPress. A production host supplies its own
maintenance-exclusion provider, optional checkpoint, code-release, and upload
providers, and four recovery adapters. Duo invokes each as an argv array through `proc_open`; it never
constructs a shell command.

This is the fail-closed execution substrate. It does not make the current
in-place `duo promote` path automatically recoverable. That claim remains
blocked until the checkpoint, code/storage, verifier, and integrated
crash-matrix slices use this substrate end to end.

## Controller configuration

Put this in the gitignored `.duo-envs.json` SSH environment beside
`rollback_key_id` and `rollback_signing_key`:

```json
{
  "rollback_recovery": {
    "adapters": {
      "code_restore": ["/opt/duo/bin/code-restore"],
      "database_restore": ["/opt/duo/bin/database-restore"],
      "prior_verify": ["/opt/duo/bin/prior-verify"],
      "storage_restore": ["/opt/duo/bin/storage-restore"]
    },
    "checkpoint_provider": ["/opt/duo/bin/checkpoint-provider"],
    "code_release_provider": ["/opt/duo/bin/code-release-provider"],
    "upload_provider": ["/opt/duo/bin/upload-provider"],
    "exclusion_provider": ["/opt/duo/bin/exclude-site", "production"],
    "timeout_seconds": 30
  }
}
```

Executable paths must be absolute. Adoption writes only these paths and the
timeout to the protected target root, then probes every configured provider and all four
adapters. `checkpoint_provider` is optional for hosts that only install the
DUO-3294 executor substrate; it is required before encrypted database recovery
can be claimed. `code_release_provider` is optional; without it, code recovery
is explicitly manual and the in-place materializer cannot advertise automatic
rollback. Tokens, database credentials, and encryption key material must
not appear in configuration or argv; providers own any credentials they need.
`upload_provider` is optional; without it, upload recovery is explicitly
manual. When present it must prepare encrypted local/offload evidence before
the receipt can be claimed.

## Exclusion provider protocol

The provider reads one canonical JSON object plus a newline from stdin and
writes one canonical JSON object plus a newline to stdout. It implements
`probe`, `acquire`, `verify`, `keepalive`, `adopt`, and `release`.

Requests use `format: duo-exclusion-provider-request/v1`, carry the exact
target/receipt/generation/owner/artifact/claimant identity (nullable only for
`probe`), and require all scopes:

```json
{
  "background_jobs": true,
  "filesystem_writers": true,
  "package_updates": true,
  "public_traffic": true
}
```

Responses contain exactly `available`, `disconnect_behavior`, `format`,
`provider_id`, `provider_version`, `scopes`, `state`, `target_id`, and `token`.
They use `format: duo-exclusion-provider-response/v1`, attest
`disconnect_behavior: remain_excluded`, and repeat all four true scopes.
`probe` returns `state: ready` and a null token; acquire/verify/keepalive/adopt
return `held`; release returns `released`.

A disconnect or failed keepalive must leave all scopes excluded indefinitely.
The provider must never use lease expiry to reopen the site. `adopt` transfers
control to the exact next signed claimant epoch without changing the token.

Duo stores the opaque token only in mode-`0600` `exclusion.json`. Its SHA-256
digest is bound into the immutable receipt. A reservation acquired before
receipt publication can only be retried for its exact receipt, generation,
owner, artifact, and claimant. Any mismatch is non-green and stays excluded.
Release is accepted only while the authority proves `committed` or
`rolled_back`.

## Recovery adapter protocol

Each adapter implements `probe` and `execute` over canonical JSON stdin/stdout.
Requests use `duo-recovery-adapter-request/v1`. An execute request names the
adapter/operation id, attempt, claimant epoch, receipt, input path, and SHA-256.
The runtime runs it only when the signed event chain has an open `prepared`
operation with the same adapter, attempt, and input digest.

Responses contain exactly `adapter`, `adapter_version`, `available`, `format`,
`input_sha256`, `loads_site_code`, `result_sha256`, and `status`, with format
`duo-recovery-adapter-response/v1`. A probe returns `status: ready`, null
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

When `checkpoint_provider` is configured, Duo routes `database_restore` and
`prior_verify` through the stricter checkpoint protocol instead of the generic
adapter contract. See [checkpoint-bundle.md](checkpoint-bundle.md). When
`code_release_provider` is configured, Duo adds `code_select` in `promoting`
and routes both it and `code_restore` through the immutable atomic-release
protocol. See [code-release-runtime.md](code-release-runtime.md). Storage
uses the generic adapter only without `upload_provider`. With the provider,
Duo adds `storage_apply` in `promoting` and routes it and `storage_restore`
through the bounded upload journal. See [upload-bundle.md](upload-bundle.md).

## Operator probes

These commands run through raw SSH even when `wp-config.php`, plugins, themes,
or user MU plugins are broken:

```sh
php <repo_path>/.duo/control/recovery-runtime/rollback-control.php \
  recovery-probe --root=<repo_path>/.duo/control

php <repo_path>/.duo/control/recovery-runtime/rollback-control.php \
  status --root=<repo_path>/.duo/control
```

Do not edit `target.json`, `exclusion.json`, receipts, or events. A new
controller takes over through a signed authority event and signed exclusion
`adopt`; manual edits are treated as corruption.
