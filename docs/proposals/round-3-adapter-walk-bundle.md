# T6 — the site-adapter certification bundle, exactly

*Companion to [round-3-adapter-walk.md](round-3-adapter-walk.md) §3.1–§3.5.
This is the wire contract `duo adapter certify` (cli, host-side) must produce
and `\Duo\AdapterCertification` (agent) verifies. Every rule below is enforced
by the agent; a builder that guesses will be refused by name.*

Authority: `agent/src/Adapter/AdapterCertification.php`. Proof:
`sandbox/tests/regress_site_adapter_certification.php` § "T6 §3.1/§3.2".

## 1. The site trust root — `adapters/authorities.json`

Format `duo-adapter-authorities/v1`, canonical Duo JSON bytes, exactly the
grammar of the shipped `manifests/capabilities/adapter-authorities.json`:

```json
{
    "format": "duo-adapter-authorities/v1",
    "keys": {
        "acme-ops": {
            "adapter_names": ["acme-catalog"],
            "algorithm": "ed25519",
            "public_key": "<base64 32-byte Ed25519 public key>",
            "scope": "site_adapter_certification",
            "status": "trusted",
            "trust_tiers": ["declarative_manifest"]
        }
    }
}
```

- Each key record carries **exactly** those six keys. The allowlist the
  contract calls `adapters` is spelled **`adapter_names`** — the shipped
  grammar's own name, kept identical so one validator serves both roots.
- `status` is `trusted` or `revoked`; `trust_tiers` is a non-empty subset of
  `declarative_manifest`, `native_action`, `plugin_provider`, and must contain
  the tier the manifest actually reaches (`AdapterSources::trust_tier()`).
- `adapter_names` must contain the adapter being certified.
- **Shipped wins on a key-id clash.** A key id the agent-owned file declares
  resolves to the shipped record, and a certificate that claimed `trust_root:
  "site"` for it is refused. Choose an id the shipped file does not use.
- The file is **reserved inside `adapters/`**: it is never globbed as an
  adapter, and whenever it exists it is validated whole. A malformed one is a
  whole-source refusal (`certification_source`), not one adapter's problem.
- Both roots may be absent. Private keys never live in the repository.

## 2. The bundle — built by the agent, not by the host

**`duo adapter certify` does not build a bundle.** It calls one entry point:

```php
\Duo\AdapterCertification::sign_site(
    string $manifestDir,   // must resolve to the library this process loads
    string $repo,          // site repository root
    string $name,          // adapter name; adapters/<name>.json must exist
    string $authorityId,   // key id, resolved against BOTH trust roots
    string $secretKey,     // base64 or hex Ed25519 secret key bytes
    string $reason         // the operator's stated basis; signed and reported
): string                  // canonical duo-adapter-certification/v1 bytes
```

Write the returned bytes to `adapters/certifications/<name>.json`, then call
`verifyFile()` to confirm and `certificateSummary()` to print. The offline tool
does exactly that under `php scripts/adapter-certification.php sign-site
--manifest-dir=… --repo=… --name=… --authority=… --secret-key-file=…
--reason=…`, which is the reference implementation.

`sign_site()` derives the ratification from the manifest, builds the
unexercised bundle in memory, **runs the real loader** for the `grammar`
verdict (a manifest that does not load is refused, with the loader's own
message), verifies its own output through the same validator that will
re-verify it at every load, and signs. Nothing is written to disk: an
unexercised bundle's only assets are `environment.json` and `ratification.json`
and both are already inside the signed statement, so there is no directory to
keep and none to tamper with. There is no `--bundle` and no `--evidence-repo`.

It refuses an agent-owned key by name: `exercised: false` follows the trust
root, so a platform key certifies a reviewed exercise through `sign()` or
nothing.

The grammar below is therefore what the agent PRODUCES and verifies — read it
to know what a certificate asserts, not to build one.

## 2a. The bundle grammar — `duo-site-adapter-certification-bundle/v1`

A directory holding `bundle.json` plus its assets. `bundle.json` and the
`results/*.json` assets use the **four-space pretty** canonical encoding
(`json_encode(..., JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|
JSON_UNESCAPED_UNICODE)` over canonically-normalised data, plus a trailing
`"\n"`); `environment.json`, `ratification.json` and `diffs/*.json` use
repository Canon (compact canonical, trailing newline). That split is the
existing producer's, unchanged.

`bundle.json` carries **exactly** these sixteen keys:

| key | value for `duo adapter certify` |
|---|---|
| `schema_version` | `"duo-site-adapter-certification-bundle/v1"` |
| `subject` | `{"kind": "site_adapter", "name": "<n>"}` |
| `verdict` | `"pass"` |
| `evidence` | **NEW** — `{"exercised": false, "grammar": "ok", "reason": "<text>"}` |
| `bound_inputs` | non-empty list of asset descriptors; **must include exactly one** for `adapters/<n>.json` with the raw file's `sha256`/`size` |
| `artifacts` | `[]` when `exercised` is false |
| `tests` | `[]` when `exercised` is false |
| `force_hatches` | `[]` — always; a certificate is never a vehicle for a force flag |
| `environment` | descriptor for `environment.json` |
| `environment_summary` | the same object the asset holds, byte-for-byte |
| `ratification` | descriptor for `ratification.json` |
| `ratification_summary` | `{"certified_claims": ["manifests.<n>"], "manifest_count": 1, "profile_count": 0}` |
| `harness` | `{"name": "<non-empty>", "version": <int >= 1>}` |
| `git_revision` | 40 lowercase hex. A repository with no commit to bind uses the nil SHA (`"0" * 40`) — honest, not fabricated |
| `created_at` | any `strtotime()`-parseable timestamp; this becomes the claim's **`signed_at`** |
| `bundle_digest` | sha256 of the COMPACT canonical encoding of the bundle **without** `bundle_digest`, plus a trailing `"\n"` |

An asset descriptor is exactly `{"path": <relative>, "sha256": <64 hex>,
"size": <int >= 0>}`. `path` is slash-separated, never absolute, never
containing `.` or `..`.

### `evidence`, and the one relaxation

`evidence` is required on **every** bundle and carries exactly
`exercised`, `grammar`, `reason`:

- `grammar` must be `"ok"` — the `manifest-validate` verdict. Certifying a
  manifest the loader itself refuses would certify bytes no command can use.
- `reason` is a non-empty string; it is signed and reportable.
- `exercised: true` is the pre-T6 shape, unchanged, and the **only** shape a
  certificate under an agent-owned key may take. It requires named passing
  tests with `results/`, `diffs/` and `logs/` assets.
- `exercised: false` is admissible **only under a site trust root**. It
  requires `tests: []` **and** `artifacts: []`; declaring `false` while naming
  either is refused (`declares evidence.exercised false but names tests or
  artifacts`). A platform-rooted bundle with `exercised: false` is refused
  with `only a site trust root may certify`.

`exercised` rides onto the resulting claim (`claim.evidence.exercised`), so
`status: certified` can never be read as "somebody ran it".

### Assets at signing time

`sign` opens every declared asset and every `bound_inputs` entry (resolved
inside `--evidence-repo`) and re-hashes them before the private key is used.
For an unexercised bundle that means exactly `environment.json` and
`ratification.json` must exist; `results/`, `diffs/`, `logs/` are absent.

## 3. The ratification — derived, never authored

`sign_site()` derives it from the manifest. Every field is a restatement of
something the manifest already declares, or of something a grammar check
provably did not review:

- `entity_sections` / `field_sections` — exactly the state surfaces the
  manifest declares, partitioned by the shipped vocabulary. A manifest key in
  neither list and not a known non-surface key **stops the signer by name**
  rather than minting a certificate that covers less than the adapter does.
- `operations` — `apply, capture, compile, deploy, plan, recapture`. No
  `delete` (deletion semantics are what a validator run cannot review), no
  `render-api` or `test-only` (reviewed runtime behaviours).
- `deletion_semantics` — `supported: []`, and an `unsupported[]` row for
  `deletions.*`.
- `lifecycle_phases` — `[]`, for the same reason.
- every `class: authored_typed_snapshot_post_v1` table gets a `tables.<t>`
  `unsupported[]` row (ManifestDispositions requires it).
- every `default_class: authored` table is recorded in
  `default_authored_keyspaces` with status **`unsupported`**, never
  `justified`: justification is a review judgement about a plugin-upgrade
  tripwire, and no exercise made one.
- `supported_versions` — `{plugin, range}` restating the manifest when it
  declares a plugin (`validate_entry()` compares them), else
  `{"source": "site-operator"}`.

The resulting document, for reference:

```json
{
    "format": "duo-manifest-dispositions/v1",
    "manifests": {
        "<n>": {
            "capabilities": {
                "deletion_semantics": {"supported": [], "unsupported": ["all"]},
                "entity_sections": ["options"],
                "field_sections": [],
                "lifecycle_phases": [],
                "operations": ["apply", "capture", "deploy"]
            },
            "default_authored_keyspaces": [],
            "evidence": {
                "bundle_schema": "duo-site-adapter-certification-bundle/v1",
                "tests": []
            },
            "reason": "<non-empty text>",
            "status": "certified",
            "supported_versions": {"<non-empty object>": "…"},
            "unsupported": [{"operation": "…", "reason": "…", "surface": "…"}]
        }
    },
    "profiles": []
}
```

Enforced, and each has bitten a fixture:

- exactly the seven disposition keys above; `capabilities` exactly the five
  shown; `deletion_semantics` exactly `supported`/`unsupported`.
- `status` must be `certified`.
- `supported_versions` must be a **non-empty** object. When the manifest
  declares `plugin`, it must be `{"plugin": <basename>, "range": <the
  manifest's own version_range>}`.
- `unsupported` must be a **non-empty** list of `{operation, reason, surface}`.
  A certified claim has to state a boundary.
- `evidence.tests` must be `[]` when the bundle declares `exercised: false`,
  and every cited test must exist as a passing bundle test otherwise.
- The `ratification.json` asset descriptor must bind these exact bytes.

## 4. Signing a REVIEWED-exercise certificate

This is the pre-existing path, unchanged, for an agent-owned key and a real
conformance bundle on disk. A site certificate does not use it.

```
php scripts/adapter-certification.php sign \
  --manifest-dir=<agent manifests> --repo=<site repo> --name=<n> \
  --bundle=<bundle dir> --evidence-repo=<site repo> \
  --authority=<key id> --secret-key-file=<file>
```

The secret key file must be a regular non-symlink file with no group/world
permission bits. It is resolved against the **site** root when the key id is
not in the agent-owned file, so a site-rooted signature needs no new flag.

Output on stdout is canonical JSON:

```json
{
    "authority": {"fingerprint": "…", "key_id": "acme-ops", "record_sha256": "…"},
    "bundle_digest": "…",
    "certificate_path": "adapters/certifications/<n>.json",
    "exercised": false,
    "name": "<n>",
    "principal": "acme-ops",
    "status": "certified",
    "trust_root": "site",
    "trust_tier": "declarative_manifest"
}
```

`verify` and `verify-frozen` print the same summary without
`certificate_path`. `sign-site` prints the identical summary — a site
certificate's summary carries `trust_root: "site"`, the `principal`, and
`exercised: false`.

## 5. What the agent then emits

### Catalog / diagnostic rows

Every row of `AdapterSources::survey()['adapters']` and of
`AdapterSources::diagnostics()` carries `trust_root` and `principal`:

| row | `certification` | `trust_root` | `principal` |
|---|---|---|---|
| shipped | `registry` | `platform` | `null` |
| out-of-tree, no certificate | `uncertified` | `null` | `null` |
| valid signature, pin not exact | `signed_unpinned` | `site` \| `platform` | key id |
| valid signature under an agent-owned key + exact pin | `third_party_signed` | `platform` | key id |
| valid signature under a **site** key + exact pin | **`site_signed`** | `site` | key id |

A shipped adapter displaced by an explicit `{name, source:"site"}` pin is not
a catalog row at all — it moves to `not_installed` with
`reason_code: "shadowed_by_site"` and a `winner` naming the site copy.

### The capability claim

```json
"certification": {
    "principal": "acme-ops",
    "signed_at": "<the bundle's created_at>",
    "source": "site",
    "trust_root": "site"
}
```

with `status: "certified"` and `evidence.status: "current"` (plus
`evidence.exercised`), which is what `duo promote`'s existing gate reads. No
host change is needed for promotion to admit a site-certified adapter.

## 6. `wp duo init --allow-unmanaged-plugins`

The host must forward the flag to **both** target invocations:

```
wp duo init --repo=<path> --format=json [--allow-unmanaged-plugins]
wp duo init --repo=<path> --confirm=<digest> --format=json [--allow-unmanaged-plugins]
```

It is inside the proposal digest, so a confirmation that omits it recomputes a
different proposal and is refused. Under the flag each unmanaged plugin moves
from `unsupported` to `advisories` with code `active_plugin_without_adapter`,
`kind: "plugin"` and `extension: "<dir>/<file>.php"`; nothing about it is
selected or written.
