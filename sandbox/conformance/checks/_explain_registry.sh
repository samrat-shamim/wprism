#!/usr/bin/env bash
# issue #3409: concurrency-safe allocation of the host `wprism explain` envs registry
# used by conformance/checks/core.sh. Extracted into a sourceable helper (the
# same convention as _retry_helper.sh) so the allocation idiom is provable
# offline — sandbox/tests/offline/guards/regress_explain_registry.sh — on BOTH GNU and
# BSD/macOS mktemp, with no docker and no pair.
#
# The prior inline form
#     mktemp "${TMPDIR:-/tmp}/wprism-explain-envs.XXXXXX.json"
# is a portability bug. GNU mktemp substitutes a run of X's even when a suffix
# follows, but BSD/macOS mktemp only substitutes an X-run that TERMINATES the
# template. With the `.json` suffix after the X-run, BSD/macOS mktemp takes the
# whole template literally, so every caller races for the one fixed path
# `wprism-explain-envs.XXXXXX.json` — observed live as
#     mktemp: mkstemp failed on .../wprism-explain-envs.XXXXXX.json: File exists
# when a concurrent core sweep already owned that name (the issue #3344 exact-source
# sweep, PR #184).
#
# `mktemp -d` with a template whose X-run is FINAL is honored identically by GNU
# and BSD, so a per-run private directory is collision-free on both. The JSON
# registry then lives at a fixed name INSIDE that unique directory, preserving
# the `.json` filename the caller passes to `wprism --envs-file=`.

# Create and echo a fresh, uniquely-named, caller-owned directory for the explain
# registry. The caller writes its JSON to "$dir/envs.json" and owns cleanup —
# install a trap BEFORE the first write so an interrupt cannot leak the dir.
alloc_explain_registry_dir() {
  mktemp -d "${TMPDIR:-/tmp}/wprism-explain-envs.XXXXXX"
}
