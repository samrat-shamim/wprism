# DUO-3340: live adapter-authoring exercise

`regress-adapter-authoring-live` is a disposable, candidate-bound proof of the
public adapter-authoring loop. It owns its pair name, ports, site repositories,
and origin. It does not alter the offline suite count and is discoverable from
`make regress-live-list`.

Run it only from a checkout whose canonical agent/adapter-packages/platform
source is the candidate commit:

```sh
DUO_EXPECTED_SOURCE_SHA=$(git rev-parse HEAD) \
ADAPTER_AUTHORING_PAIR=duo3340author \
ADAPTER_AUTHORING_PORT1=8930 ADAPTER_AUTHORING_PORT2=8931 \
make regress-adapter-authoring-live
```

`ADAPTER_AUTHORING_PAIR` must be lowercase alphanumeric and begin with a
letter. `ADAPTER_AUTHORING_PORT1` is even, from 8900 through 65534;
`ADAPTER_AUTHORING_PORT2` must be exactly `PORT1 + 1`. The exact source SHA is
mandatory: `pair.sh` refuses before database/container/repository mutation if
its canonical bind-mounted source is not that SHA. A linked worktree whose
primary checkout is another revision will therefore refuse safely; use the
candidate primary checkout or a standalone clone.

The exercise routes the observation verb through a generated, scratch Docker
Compose config and environment registry so the host transport path (including
journal/codebind overlays) is exercised too:

> Host `duo adapter-observe <env>
> --out=<scratch-file>` derives the configured target repository from the
> generated environment registry, then invokes `wp duo adapter-observe
> --repo=/siterepo --format=json`. The
> host `--out` form is mutually exclusive with host `--format=json` and is
> create-only; the target response is the canonical JSON written to scratch.
> The live script validates the exact `duo-adapter-observation/v1` envelope,
> `authority=false`, `redaction=values_omitted`, its canonical `observation_hash`,
> named deferred limitations, and secret/path/title/value redaction. It also
> hashes `duo_journal`, `duo_map`, `duo_state`, and `duo_kv` before/after, and
> fails clearly if the command is absent or non-read-only.

## What the proof covers

The controlled `duo-agency-cpt` fixture is copied into the source repository's
`code/` tree before `pair.sh up --journal --codebind ... --http` creates the
pair. The initial site pin is `core` only, so the unclassified plugin writes
really block capture. Side one activates the fixture; side two starts inactive
and can only receive the plugin through the public `duo deploy` path. After
review, the exercise adds the shipped `duo-agency-cpt` pin and removes only the
duplicate inline facts.

The source flow performs an authenticated admin REST write of the authored
project note and a hard-secret-shaped option, then five anonymous project-page
requests that produce runtime post meta. Capture is expected to block. The
journal report and pending JSON must distinguish the REST note (`authored`),
anonymous views (`runtime`), and the hard secret. The safe, generated-looking
note payload is shape-only evidence: it must not become a secret or leak through
the observer/draft.

Classification is explicit and reviewed. Authored classification of the hard
secret must refuse without `--allow-secret`; the accepted decisions are one
semicolon-separated `--set` call, followed by the optional env rule. Capture
then excludes runtime and secret bytes while retaining the authored note.

The host `duo adapter-draft` report is checked as three separate trust layers:

- facts reproduce the reviewed policy;
- `_draft.proposals` and unanswered questions are inert and display-only;
- generated-looking surfaces are `unsupported`, shape-only, redacted, and have
  no PHP/actions/providers/notes authority.

`--check-proposals` is a throwaway grammar check and must not change
`site.duo.json`. The strict observer artifact is also passed to
`adapter-draft --evidence`; only its bounded seam note may differ from the
no-evidence draft. Facts, proposals, questions, and authority remain identical
and inert. The shipped `manifest-validate --site --pins` run, host
catalog/inspect, and target capability report must all identify the same
`duo-agency-cpt` adapter. Graduation removes only duplicate inline policy; it
hash-checks that the shipped manifest's hand-authored `actions`, `providers`,
and `notes` did not change and that captured state stayed byte-identical.

Finally, the target receives the source commit, deploys the existing plugin,
plans without deletion authority, and applies. The report must contain a
verified plugin provider receipt and a verified native transient-delete
receipt. A fresh target capture must match the source state byte-for-byte.
After a source revision, deactivating the target plugin must produce an
identity/remediation-bearing refusal before target mutation. Deploying again
and retrying must converge and recapture identically.

The exercise passes no deletion flag. Verified rollback is reported as deferred
unless an existing driver profile independently advertises it;
generic/scoped rollback and plugin/theme upgrade, downgrade, or removal are
also explicitly deferred. A failed run leaves the pair and repositories for
inspection. Only a green run destroys them, so reruns require deliberate
inspection/removal of the previous evidence boundary.
