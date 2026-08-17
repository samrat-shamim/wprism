#!/usr/bin/env bash
# Regression — DUO-3285 fast-follow: the durable fix for the drift class
# that let regress-coverage-offline (DUO-3290, asub's PR #82) land with a
# real Makefile target and no bundle entry, invisible to CI for as long as
# nobody happened to notice by inspection. Re-running the DUO-3285 survey
# by hand the same day found two more of the same shape it had NOT been
# re-run against since (regress-coverage, regress-woo-attribute-deletion) --
# so "wire the one flagged suite in" fixes an instance, not the class. This
# suite fixes the class: it runs that survey itself, every time, and fails
# loud the moment a regress_*.{sh,php} file exists with neither a
# regress-offline-all prerequisite nor a regress-live-list entry. A future
# suite must declare itself at birth (either place) or this goes red.
#
# Pure source-text/Makefile scan, no docker, no WordPress bootstrap, no PHP
# execution -- reads sandbox/tests/*.{sh,php} filenames and the Makefile's
# own text, nothing else.
set -euo pipefail
cd "$(dirname "$0")/../.."   # -> repo root (this check reads the top-level Makefile)

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

[ -f Makefile ] && [ -d sandbox/tests ] || fail "expected ./Makefile and ./sandbox/tests from the repo root"

# check_coverage <tests-dir> <makefile> -- prints any gaps to stderr,
# returns 0 (no gaps) or 1 (gaps found). Never fail()s itself so the
# self-test below can assert on its exit code either way.
check_coverage() {
  python3 - "$1" "$2" <<'PYEOF'
import re, glob, os, sys

tests_dir, makefile = sys.argv[1], sys.argv[2]

files = sorted(glob.glob(os.path.join(tests_dir, "regress_*.sh")) +
                glob.glob(os.path.join(tests_dir, "regress_*.php")))
basenames = [os.path.basename(f) for f in files]

def code_lines(path):
    # Strip full-line comments so a doc-comment mention ("see regress_x.sh's
    # own header") can't be mistaken for an actual invocation -- the one
    # false-positive shape found authoring this check. Inline trailing
    # comments are left alone: under-stripping only risks a false
    # "covered", never a false gap, which is the safe direction to err.
    with open(path, encoding='utf-8', errors='replace') as fh:
        return ''.join(l for l in fh if not re.match(r'^\s*#', l))

contents = {b: code_lines(f) for b, f in zip(basenames, files)}

# A file is a helper (not its own primary suite) if some OTHER file's CODE
# actually runs it: `php <name>` or `bash <name>`, optionally with a path
# prefix -- the only two invocation shapes this codebase uses. A bare
# textual mention (a comment, a filename in prose) doesn't count -- which is
# exactly why code_lines() above strips full-line comments before any of
# this runs.
#
# One regex pass per file (not one pass per basename) replaces what was an
# O(n^2) survey: originally, for EACH of the ~n basenames, a fresh regex was
# compiled (via re.escape(basename)) and searched against EVERY other file's
# text again from scratch. To stay a single pass while still accepting
# exactly the same basenames as that per-basename check -- not a guessed
# shape like `regress_[a-z0-9_]+\.(?:sh|php)`, which would silently narrow
# acceptance (rejecting a real but unusually-shaped basename, e.g. one with
# a dash or an embedded dot, is a false "needs its own Makefile entry", not
# a hidden gap -- but still wrong either way) -- the alternation below is
# built FROM basenames itself: one regex whose acceptable-basename set is
# exactly the on-disk `regress_*.{sh,php}` glob result, nothing guessed.
# Longest-first ordering (key=len, reverse=True) resolves the one ambiguity
# a combined alternation can introduce that independent per-basename
# searches never had: when one on-disk basename is a strict string prefix
# of another (e.g. `regress_a.sh`, matching the `regress_*.sh` glob, is a
# literal prefix of `regress_a.sh.php`, which independently matches
# `regress_*.php`), trying the longer alternative first at a given position
# stops the match from being reported under the shorter name instead.
# invoked_by maps a basename to the set of files
# whose code invokes it. Self-invocation is still excluded (a file naming
# itself doesn't count -- checked via `inv != basename` below, matching the
# old `other != basename` guard), and comment lines are still stripped
# first via code_lines() before this pass ever sees the text.
#
# Residual, inherited from the original per-basename check and NOT fixed
# here: `\b` is a word/non-word boundary, not an end-of-name anchor, so a
# stray `regress_x.sh.bak`-named file (never itself a match target, since
# it doesn't fit the `regress_*.{sh,php}` glob) can still make a genuine
# `regress_x.sh` basename register as "invoked" if some file's code
# happens to write `bash regress_x.sh.bak` -- `\b` is satisfied right
# after `.sh`, same as it always was. This is unchanged behaviour, not a
# regression, and is a false "covered" (safe direction) rather than a
# hidden gap.
if basenames:
    alternation = '|'.join(sorted((re.escape(b) for b in basenames), key=len, reverse=True))
    GENERIC_INVOCATION_RX = re.compile(
        r'(?:php|bash)\s+(?:\S*/)?(' + alternation + r')\b'
    )
else:
    GENERIC_INVOCATION_RX = re.compile(r'(?!)')  # no basenames on disk -> never matches
invoked_by = {}  # basename -> set of files whose code invokes it
for owner, text in contents.items():
    for m in GENERIC_INVOCATION_RX.finditer(text):
        invoked_by.setdefault(m.group(1), set()).add(owner)

def invoked_elsewhere(basename):
    return any(inv != basename for inv in invoked_by.get(basename, ()))

primary = {}  # target name -> source file, for every file that is its own suite
for b in basenames:
    if invoked_elsewhere(b):
        continue
    stem = re.sub(r'\.(sh|php)$', '', b)
    target = stem.replace('regress_', 'regress-').replace('_', '-')
    primary[target] = b

mk_lines = open(makefile, encoding='utf-8').read().split("\n")

def target_prereq_line(name):
    # Follow \-continuations for a target's PREREQUISITE line only (this
    # Makefile writes multi-line prerequisite lists with a trailing `\`,
    # indented with a tab for readability -- indistinguishable from a
    # recipe line by indentation alone, so this follows continuations
    # explicitly rather than stopping at the first tab-indented line).
    for i, line in enumerate(mk_lines):
        if line.startswith(name + ":"):
            full = line[len(name) + 1:]
            j = i
            while full.rstrip().endswith("\\"):
                j += 1
                full = full.rstrip()[:-1] + " " + mk_lines[j]
            return full
    return None

offline_all_line = target_prereq_line("regress-offline-all")
if offline_all_line is None:
    sys.exit("regress-offline-all target not found in Makefile")
offline_all_direct = [t for t in re.split(r'\s+', offline_all_line.strip()) if t]
# regress-offline-all is a guarded wrapper: its recipe invokes the corpus
# target under offline_diagnostics_guard.sh, so running the corpus as a
# prerequisite would bypass the very diagnostic gate this issue adds.
if not offline_all_direct:
    if target_prereq_line("regress-offline-corpus") is None:
        sys.exit("regress-offline-all has no prerequisites and no corpus target")
    offline_all_direct = ["regress-offline-corpus"]
code_half_line = target_prereq_line("code-half-unit") or ""
code_half_direct = [t for t in re.split(r'\s+', code_half_line.strip()) if t]

def expand_offline_targets(initial):
    expanded = set()
    pending = list(initial)
    while pending:
        target = pending.pop()
        if target in expanded:
            continue
        expanded.add(target)
        if target in {"code-half-unit", "regress-offline-corpus"}:
            prereqs = target_prereq_line(target) or ""
            pending.extend(t for t in re.split(r'\s+', prereqs.strip()) if t)
    return expanded

offline_all_transitive = (
    expand_offline_targets(offline_all_direct) | expand_offline_targets(code_half_direct)
) - {"code-half-unit", "regress-offline-corpus"}

# Keep the human-facing close-gate status truthful.  The guarded wrapper runs
# regress-offline-corpus, with code-half-unit's prerequisites folded in once;
# the exact same set is what the wrapper executes. A stale echo is
# operationally misleading even when every recipe still runs, so treat it as
# a coverage failure and exercise that failure in the self-test below.
count_matches = re.findall(
    r'regress-offline-all:\s+(\d+)\s+offline suites green',
    open(makefile, encoding='utf-8').read(),
)
if len(count_matches) != 1:
    sys.exit("Makefile must contain exactly one numeric regress-offline-all status count")
declared_count = int(count_matches[0])
actual_count = len(offline_all_transitive)
if declared_count != actual_count:
    sys.exit(
        f"regress-offline-all reports {declared_count} suites but its prerequisite graph contains "
        f"{actual_count} unique offline suites"
    )

live_names = set()
try:
    start = next(i for i, l in enumerate(mk_lines) if l.startswith("regress-live-list:"))
except StopIteration:
    sys.exit("regress-live-list target not found in Makefile")
for l in mk_lines[start + 1:]:
    if l and not l.startswith("\t"):
        break
    live_names.update(re.findall(r'regress-[a-z0-9-]+', l))

gaps = [(name, path) for name, path in sorted(primary.items())
        if name not in offline_all_transitive and name not in live_names]

if gaps:
    for name, path in gaps:
        print(f"  {os.path.join(tests_dir, path)} -- target '{name}' is in neither "
              f"regress-offline-all nor regress-live-list", file=sys.stderr)
    sys.exit(1)
PYEOF
}

say "self-test: a synthetic unwired suite file must be flagged as a gap"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
cp Makefile "$TMP/Makefile"
mkdir -p "$TMP/tests"
cp sandbox/tests/regress_*.sh sandbox/tests/regress_*.php "$TMP/tests/" 2>/dev/null || true
cat > "$TMP/tests/regress_synthetic_unwired_probe.sh" <<'EOF'
#!/usr/bin/env bash
# Synthetic fixture for regress_bundle_coverage.sh's own self-test only --
# deliberately never wired into regress-offline-all or regress-live-list.
EOF
if check_coverage "$TMP/tests" "$TMP/Makefile" 2>"$TMP/coverage-selftest.log"; then
  fail "self-test failed: a synthetic suite with no bundle/live-list entry was NOT flagged -- this check's own detection logic is broken, do not trust the real-repo result below"
fi
grep -q "regress_synthetic_unwired_probe.sh" "$TMP/coverage-selftest.log" \
  || fail "self-test failed: check_coverage exited non-zero but did not name the synthetic file it should have flagged"
pass "self-test: synthetic unwired suite correctly flagged as a gap"

say "self-test: a stale offline-suite count must be rejected"
BAD_COUNT_MAKEFILE="$TMP/Makefile.bad-count"
read -r BAD_COUNT ACTUAL_COUNT < <(python3 - "$TMP/Makefile" "$BAD_COUNT_MAKEFILE" <<'PYEOF'
import re, sys

source, destination = sys.argv[1], sys.argv[2]
text = open(source, encoding='utf-8').read()
match = re.search(r'(regress-offline-all:\s+)(\d+)(\s+offline suites green)', text)
if not match:
    raise SystemExit('could not locate the Makefile offline-suite status line')
declared = int(match.group(2))
bad = declared - 1 if declared > 0 else declared + 1
text = text[:match.start(2)] + str(bad) + text[match.end(2):]
open(destination, 'w', encoding='utf-8').write(text)
print(bad, declared)
PYEOF
)
if check_coverage "$TMP/tests" "$BAD_COUNT_MAKEFILE" 2>"$TMP/coverage-selftest-bad-count.log"; then
  fail "self-test failed: stale regress-offline-all count was accepted"
fi
grep -q "reports ${BAD_COUNT} suites.*contains ${ACTUAL_COUNT} unique offline suites" "$TMP/coverage-selftest-bad-count.log" \
  || fail "self-test failed: stale-count refusal did not report the declared and actual counts"
pass "self-test: stale offline-suite count correctly rejected"

say "self-test: the same synthetic tree WITHOUT the extra file must be clean (no false positives from the harness itself)"
rm -f "$TMP/tests/regress_synthetic_unwired_probe.sh"
if ! check_coverage "$TMP/tests" "$TMP/Makefile" 2>"$TMP/coverage-selftest-clean.log"; then
  fail "self-test failed: the unmodified regress-suite tree (minus the synthetic probe) reported a gap that shouldn't exist -- see $TMP/coverage-selftest-clean.log. This means either a REAL gap exists in the current repo (in which case the real check below will also correctly fail, which is fine) or this check's own logic has a false-positive bug (needs investigation either way, but don't blame the self-test)."
fi
pass "self-test: an unmodified suite tree with a copied Makefile reports no gaps via this check's own logic"

say "real check: every sandbox/tests/regress_*.{sh,php} file vs. Makefile's regress-offline-all / regress-live-list"
if check_coverage sandbox/tests Makefile 2>/tmp/coverage_real.log; then
  pass "every regress-* suite file has a regress-offline-all or regress-live-list entry"
else
  cat /tmp/coverage_real.log >&2
  fail "one or more regress_*.{sh,php} files exist with no regress-offline-all or regress-live-list entry -- see above. Add the missing target to whichever bundle matches its docker/pair.sh dependency (see regress-offline-all's own comment for the classification rule), or if it's a genuine helper invoked by another suite (php/bash <this-file> appearing in another regress_*.{sh,php} file's own code), this check already excludes it -- so a flag here means neither is currently true."
fi

printf '\n\033[1;32m✔ REGRESS_BUNDLE_COVERAGE PASSED\033[0m\n'
