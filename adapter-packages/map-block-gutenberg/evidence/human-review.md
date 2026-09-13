# Authorized promotion review

Decision: **approve the bounded promotion proposal**, subject to the remaining
warning-free native, exact-artifact and certified host-path gates below.

Reviewer: Codex (AI), 2026-09-14 (Asia/Dhaka). Reviewed runtime source:
`8183b5d78b667e124a99c394af5ab52921720dbb`, whose Map capsule and shared
block-content runtime are unchanged from the merged qualification checkpoint.
Banach (AI) independently reviewed the retained native evidence and compatibility
claims. This is not an independent human code review.

Authority: the task owner explicitly requested "you review it for me", then
"you're authorized to approved" in this task's conversation. That delegation
replaces this adapter's earlier separate-human-signoff blocker. It does not
change the repository-wide review policy or assert that the user personally
inspected the code. The decision and reasons below are the authorized agent's.

The exact proposed boundary remains plugin 1.35 only (`>=1.35 <1.35.1`),
static leaf maps with zoom, height, address and API key. Unknown schema,
inconsistent/custom HTML, map children and unsupported versions refuse.
Ordinary parent blocks may contain maps. No entity, table, repair action or
plugin-owned deletion grant is proposed. Exact artifacts and the native/offline
evidence are in [artifacts.lock.json](artifacts.lock.json) and
[qualification.md](qualification.md).

## Decisions and reasons

- **Approve the shared `block-content-codecs/v1` use.** Reviewed
  `agent/src/Kernel/BlockContentGrammar.php`, the whole-block path in
  `agent/src/Grammar/Blocks.php` and
  `agent/src/Apply/BlockEnvironmentOptions.php`. The manifest validator/projector
  rejects foreign ownership and site overrides; `Blocks` checks the input leaf,
  closed output keys, declared attribute paths, 1 MiB HTML bound and absence of
  new block delimiters before serializing. Binding reads use sorted exact option
  locks inside the authored transaction, compare encoded physical bytes to
  owner-only target intent, and clear credential bindings in `finally`.
  The shared evidence is
  `sandbox/tests/offline/grammar/regress_block_content_codecs.php`.
- **Approve the exact `address` public-text clearance.** The native attribute
  specifies an authored map destination, not a private customer/address record.
  `BlockAttributeReader::clearance_value` keeps the original body and decoded
  scalar bytes, removing only this declared field's semantic key role. It grants
  no container or unrelated-block clearance. Both capture publication and
  immutable repository authorization still call the secret and PII scanners.
  The additional Map regressions first establish a valid map schema, then prove
  that public destination text passes while email, phone, labelled credentials
  and hard token signatures refuse at both gates. This approval does not infer
  that an arbitrary destination is public or exempt values from those scanners.
- **Approve target-local credential rebinding.** Every map, including one saved with an explicit or
  historical source key, intentionally binds the target's provisioned
  `gmw-map-block-key`. Both canonical locations contain only `@env`; missing or
  drifted target intent refuses. Per-map source keys and the plugin's bundled
  fallback are not transported or silently restored.
- **Approve only the bounded static-transport disposition.** The native editor's
  four upstream deprecation/enqueue warnings remain a compatibility limitation,
  not a clean editor-compatibility claim. The stored blocks passed native
  parse/serialize/parse, not a subsequent editor save. Google Maps credential
  validity, billing, restrictions and service availability are outside the
  static transport claim. The adapter-specific native profile is WordPress
  7.1/PHP 8.3/MariaDB 11, not a newly executed full platform matrix.

## Finding and remaining release gates

The independent review found one P2 evidence defect: the same-version reinstall
used redundant `--activate`, emitted WP-CLI's "already active" warning, and still
reached PASS in the retained `3aa5865b` and `74fc75a8` logs. Those terminal results
are not warning-free green runs. `check.sh` now omits redundant activation,
requires complete installer success and rejects warning output on either stream;
the following native active/version assertion is retained. Seven shell probes
exercise the actual command and acceptance, including the failing old command.
The live warning-free rerun remains required, not presumed from the shell test.

Approval removes the review blocker, not the remaining technical work. The
production disposition and conformance entry still need to be updated and
tested through the certified host deployment path. The capsule also needs its
exact-artifact version-matrix workflow, with real 1.35 admission and real 1.34
refusal; the previous native 1.34 header fault was not that artifact test. Any
`package/disposition.json` change moves adapter identity: normal recompilation
and re-pinning are required, followed by the capsule's live run and complete
local merge/release gates and independent review of the final candidate.
The review checkpoint kept the disposition experimental. A following candidate
may declare the reviewed certified boundary to exercise that real host path;
it must not be merged or reported production-ready until those gates pass.
The digest at the review checkpoint was
`e814fa810757d428dfabc5479e217c5313eb0d8e7ca4ca25da59403ebaba1c8c`.

The subsequent exact-source matrix at
`39640c46de8141e5c32b29f9e40a5dfd3e42dd9b` passed the certified host path,
warning-free reinstall and real 1.34 refusal, then destroyed its disposable
pair. All 390 offline suites, Composer and release checks passed at that
source. Independent review found no actionable issue in the promotion delta
or matrix implementation. See [qualification.md](qualification.md) for the
retained proof, digest migration and final-revision gate discipline.
