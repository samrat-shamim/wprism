# `sandbox/conformance/boundary/` — recorded inputs for `duo adapter boundary`

Two kinds of file, one rule each.

**`<slug>.releases.json`** — a `duo-adapter-release-list/v1` document: every
candidate release with its exact download URL and sha256. This is the bisector's
pin source, and the reason nothing on the search path ever reaches the network.
`fetch_artifact()` resolves against `../artifacts.lock.json` and refuses a miss
rather than falling through to a bare catalog install
(`../../bin/fetch-artifact.sh:44-47`); a bisection probes versions that are by
definition not in that lock yet — finding the ones that belong there is the job
— so it carries its own reviewed pin document under the same discipline. Every
entry must carry a digest: the command refuses a half-recorded list rather than
choose between fabricating a lock row and silently dropping one.

Recording one is a deliberate human act, not something a suite does. Take the
release list from the plugin's own release history, fetch each ZIP once,
`sha256sum` it, and write `source` and `recorded_at` so the document says where
it came from. Committed lists are the durable half of a live run: the pair is
only the recorder (`docs/agents/live-pair-budget.md` §Recording over
repetition).

**`<manifest>.site.duo.json`** — the site policy a probe runs under. `--site-policy`
is required rather than derived because "green" is a claim about a policy: the
same plugin under a wider `post_types` set is a different round-trip. These are
byte-equal to the policy `../../tests/certify/matrix.d/`'s subject writes
inline in `certify_version_matrix.sh`, so a bisection and a certification are
claiming the same thing.

**`<slug>.outcomes.json`** — a `duo-adapter-boundary-outcomes/v1` record, written
by `../../bin/adapter-boundary.sh` as probes complete. It accumulates; re-running
the loop replays what is already recorded and only probes what is missing.

**No file here may carry `last_verified`, `stale`, `releases_behind` or
`freshness`.** Those are DERIVED by `duo adapter proposals`, which reads this
whole directory and projects each adapter's newest green probe; a recorded input
allowed to state its own freshness would let the adapter nobody has probed
declare itself current. The command refuses such a document by name.

Nothing here is a manifest input. The range in `manifests/<name>.json` and its
Canon-byte-equal restatement in `manifests/dispositions/<name>.json` stay one reviewed
human edit (`agent/src/Policy/ManifestDispositions.php:632-637`).

## The second reader (WP-2.8)

An outcomes record is no longer only a reviewer's sentence. `site.duo.json` may
carry the same rows under `adapter_version_evidence`, keyed by plugin basename,
and `LifecyclePlanner::code_mismatch()` reads them to mint the graduated
`version_range_graduated` verdict for an installed release that probed `green`
— see `docs/guides/code-updates.md` §"The third state" and
`agent/src/Policy/VersionEvidenceGrammar.php`.

Two consequences for whoever records one here. The `signature` field stops
being prose a human skims: it is quoted verbatim in an operator-facing verdict,
so write what the probe actually observed. And `artifact-unresolved` keeps its
exact meaning on both readers — it blocks a search and it blocks a graduation,
because a 404 is a fact about a download and never about a plugin. The site's
copy is still a reviewed human edit; nothing copies these files onto a target.
