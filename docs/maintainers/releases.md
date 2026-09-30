# Publication and releases

This is the maintainer procedure for a public alpha. Source availability is
separate from production qualification; the [product specification](../product-spec.md)
continues to govern site-certified production claims.

## Prepare an exact candidate

Work in an isolated full clone or worktree. Preserve other contributors'
uncommitted changes. Select and commit the reviewed candidate before live
evidence so the sandbox can verify its exact source SHA.

Use the supported development runtime from [dev setup](../dev-setup.md).
Record the candidate commit, runtime/tool versions, commands, exit statuses,
and complete local logs under ignored `sandbox/tmp/`.

Required validation:

```sh
composer check
make regress-offline-all
make release-gate
bash sandbox/tests/spike/check_guide_commands.sh
```

Lint each touched PHP file and syntax-check each touched shell script.
Warnings are not green. Changed-suite runs are useful during iteration; they
do not replace the aggregate gate.

For the initial alpha, exercise the [documented demo](../guides/try-wprism.md)
from the exact clean candidate. Check the visible page change, preserved
target comment, trusted-target refusal, and cleanup. Use
`WPRISM_SOURCE_ROOT` and `WPRISM_EXPECTED_SOURCE_SHA` when validating a linked
worktree, as required by [sandbox source discipline](../sandbox.md#the-exact-source-gate-wprism_expected_source_sha).
Scope any additional live tests to the changed behavior.

## Review what publication exposes

Before making a private repository public:

- Scan the candidate and the history reachable from every ref that will be
  published. Review historical findings, not just the current files.
- Review hosted pull-request discussions, release artifacts, and historical
  logs for credentials, private host details, or customer information.
- Check ownership and redistribution rights for code, fixtures, and media.
  Keep third-party notices and identify any copied upstream test sources.
- Confirm private vulnerability reporting is enabled and notifications reach
  a maintainer. Verify the route in [SECURITY.md](../../SECURITY.md).
- Enable Issues and Discussions and confirm the contribution templates work.
- Read the README from a fresh-user perspective and verify every local link.

An example local secret scan uses Gitleaks (development tooling only):

```sh
mkdir -p sandbox/tmp/public-release
gitleaks git --redact=100 --no-banner --log-opts=HEAD \
  --report-format=json \
  --report-path=sandbox/tmp/public-release/history-secrets.json .
```

Scan other published refs too. `.gitleaksignore` contains exact historical
fingerprints reviewed as synthetic canaries, source hashes, or test assertions.
It does not exclude directories, rules, or secret prefixes. A new finding
requires review. Do not add a broad exclusion merely because a value appears
in a test. A scan with reviewed exceptions is not a proof that no sensitive
information exists.

Keep reports private and redacted. If a real credential is found, arrange
revocation/rotation before publication; deleting its current file is insufficient.
Coordinate any necessary history rewrite explicitly with the repository owner.

## Package a reviewable source candidate

The current installation path is a full source checkout. Adoption assembles
the package/platform library and ships exactly `agent recovery` to the target.
A single copied `cli/wprism` file is not a complete installation.

A Git bundle preserves the source and ancestry needed by the evaluation
workflow without packaging ignored scratch, local credentials, or vendor files.
From the clean committed candidate:

```sh
release_commit=$(git rev-parse HEAD)
release_name=wprism-alpha-$(git rev-parse --short=12 HEAD)
release_dir="$PWD/sandbox/tmp/public-release/$release_name"
mkdir -p "$release_dir"
git bundle create "$release_dir/$release_name.bundle" HEAD
git bundle verify "$release_dir/$release_name.bundle"
(
  cd "$release_dir"
  shasum -a 256 "$release_name.bundle" > SHA256SUMS
)
git clone "$release_dir/$release_name.bundle" "$release_dir/verification"
git -C "$release_dir/verification" rev-parse HEAD
```

The clone's HEAD must equal `release_commit`. Run its CLI help and release gate
and, for the first alpha, the documented demo. Verify that its working tree is
clean. The bundle includes history, so the publication review applies to it too.

GitHub's automatically generated source ZIP/tar downloads omit Git ancestry.
Do not advertise them as interchangeable with the documented full-clone demo
or contributor workflow.

## Release notes and publication

Prepare release notes with:

- the exact source commit and candidate asset checksum;
- the user-visible changes and known evaluation limits;
- required runtime/host capabilities, linked to the generated source projection;
- local verification results and the scoped live evidence;
- changes to adapter digests, required recompile/re-pin steps, and reassessment;
- known unresolved issues and the supported upgrade path.

Keep package capability rows derived from their owners. Do not manually copy a
plugin matrix into release notes or maintain a separate certification inventory.
Publish source changes, release notes, and the candidate assets for review
before changing repository visibility or publishing a release.

When publication is authorized, tag that exact reviewed commit and publish an
alpha prerelease with its notes and checksummed bundle. Check public access,
the clone instructions, Issues, Discussions, and private vulnerability reporting
from an outside view. Never infer production qualification from the alpha tag.

## Support and upgrade policy

Only the latest published alpha is supported; older alphas have no backport
promise. Before the first alpha, `main` is the evaluation candidate. Fixes land
on `main` and ship in a replacement alpha.

Users pin a release or exact commit and review its upgrade notes before
updating the control plane. Adapter identity changes can invalidate compiled
artifacts and site pins; the remedy is explicit recompilation and re-pinning,
never bypassing the mismatch.

A production-readiness announcement additionally needs the independent field
evidence and frozen launch thresholds described by the product specification.
The [community roadmap](../community-roadmap.md) tracks that work.
