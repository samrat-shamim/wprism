# Contributing to WPrism

Thank you for helping make WordPress changes reviewable and repeatable.
Useful contributions include a clear bug reproduction, an improved guide, a
host-eligibility report, a regression test, or a bounded adapter improvement.

You can participate entirely through GitHub. No Linear account, private project
history, or coding-agent setup is required. Our [code of conduct](CODE_OF_CONDUCT.md)
applies to project spaces; [governance](GOVERNANCE.md) explains who reviews and
decides changes.

## Find a starting point

- Ask usage questions and share examples in
  [Discussions](https://github.com/duotronic-ai/wprism/discussions).
- Report reproducible bugs or propose work in
  [Issues](https://github.com/duotronic-ai/wprism/issues).
- Small fixes can start as pull requests. Discuss substantial architecture,
  public-contract, or adapter-scope changes in an issue first.
- For plugin work, start with [your first adapter contribution](docs/guides/first-adapter.md).
- Report vulnerabilities privately through [SECURITY.md](SECURITY.md).

A bug report should include the exact WPrism commit, relevant runtime/plugin
versions, the command and refusal code, expected behavior, and the smallest
reproduction. Use synthetic data and remove credentials, customer information,
and identifying site details from attachments.

## Make your first change

Fork the repository, make a full clone of your fork, and branch from current
upstream main. Avoid shallow clones: some local regression checks examine
commit ancestry.

```sh
git clone https://github.com/YOUR-NAME/wprism.git
cd wprism
git remote add upstream https://github.com/duotronic-ai/wprism.git
git fetch upstream
git switch -c fix/describe-the-change upstream/main
```

Use PHP 8.3 and Composer for the development toolchain. Docker is needed for
the demo and live evidence; most regression suites run offline.
Read [AGENTS.md](AGENTS.md) before editing: its architecture and evidence rules
apply to every contributor, whether using an editor or an agent.

```sh
composer install
bash tools/doctor.sh
composer check
php tools/offline.php --changed
```

The doctor prints concrete remedies. PHP 8.3 keeps this contributor setup
inside the certified runtime boundary. [Dev setup](docs/dev-setup.md) explains
other supported runtimes, platform requirements, and troubleshooting.

Keep changes focused. Preserve dependency-free shipped code, canonical output,
refusal contracts, and lock ordering. Do not mass-format the repository.
An adapter's identity-bearing package bytes are deployed contract inputs;
changing them requires deliberate compatibility and migration review.
Scratch belongs in `sandbox/tmp/` or a temporary directory.

## Add evidence with the change

A bug fix needs regression coverage that fails against the previous behavior
through the product path. Put tests with their owner:

| Change | Test location |
|---|---|
| Plugin adapter | `adapter-packages/<slug>/tests/<execution-class>/` |
| Shared engine or CLI behavior | `sandbox/tests/offline/<domain>/` |
| Developer tooling | `tests/` |
| Cross-adapter behavior | A participant-declared `integration-scenarios/<name>/` |

Use [the shared test helpers](sandbox/tests/lib/README.md) for shared suites.
A new shared offline suite needs a Makefile leaf and regeneration with
`php tools/offline-corpus.php`. Package-local offline tests are discovered
automatically; they need no new aggregate row.

Start offline. Run only the live evidence needed to exercise the changed
boundary, following the evidence and pair discipline in
[the sandbox guide](docs/sandbox.md). The internal
[agent dispatch document](docs/agents/linear-loop.md#evidence-scoping) also
explains evidence scoping; its tracker claim/close workflow applies only to
explicit agent dispatches.

## Open a pull request

Run syntax checks on every touched PHP or shell file, then the required gates:

```sh
composer check
make regress-offline-all
make release-gate
```

`php tools/offline.php -j8` provides the same offline suite work with separate
logs for diagnosis. Changed-suite runs are for iteration; the full aggregate
remains the merge gate. Warnings do not count as a passing result.

The pull request should explain the problem, resulting behavior, regression
evidence, and any compatibility or re-pinning impact. Include the exact commit,
runtime versions, literal `make regress-offline-all` result, release-gate
result, and the scoped live result when needed. State incomplete checks plainly.

CI is disabled by owner decision; local evidence is the gate. Nothing about CI
belongs in a PR description.

Maintainers review the code and evidence, may request changes, and normally
squash-merge a focused contribution. Report unrelated discoveries separately.
Keep capability claims derived from package-owned sources; do not copy a
plugin inventory into a guide.

## License and sign-off

WPrism is [GPL-2.0-or-later](LICENSE), and contributions use the same terms.
Sign off each commit to certify your right to submit the contribution under the
[Developer Certificate of Origin](https://developercertificate.org/):

```sh
git commit -s
```

The sign-off is a provenance statement, not a copyright assignment. Review and
understand all code you submit, including code produced with an assistant.
