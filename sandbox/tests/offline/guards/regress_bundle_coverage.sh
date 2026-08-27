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
# WHAT DERIVATION CHANGED HERE
# ----------------------------
# The corpus's prerequisite list and both status counts are no longer written
# by hand: tools/offline-corpus.php derives them from the suite files on disk
# and emits tools/offline-corpus.mk, which the Makefile pulls in with
# `include`. Two consequences for this file, and both are load-bearing:
#
#   - every scan below reads the Makefile WITH its includes folded in, or it
#     would see a corpus with no prerequisites and report all ~294 wired
#     suites as gaps;
#   - the survey above answers "is every suite wired", which derivation makes
#     a strictly weaker question than "can a suite be left out at all". The
#     third self-test is that stronger one: it deletes a derived suite from a
#     synthetic copy of the generated include and requires the generator to
#     refuse it BY NAME. There is no exclusion input to delete it from, which
#     is the whole property.
#
# Source-text/Makefile scan plus one run of the derivation generator against a
# synthetic tree; no docker, no WordPress bootstrap, no suite is executed.
set -euo pipefail
cd "$(dirname "$0")/../../../.."   # -> repo root (this check reads the top-level Makefile)

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

[ -f Makefile ] && [ -d sandbox/tests ] || fail "expected ./Makefile and ./sandbox/tests from the repo root"

# check_coverage <tests-dir> <makefile> <include-root> -- prints any gaps to
# stderr, returns 0 (no gaps) or 1 (gaps found). Never fail()s itself so the
# self-test below can assert on its exit code either way. <include-root> is
# the directory the makefile's `include` paths resolve against; it is a third
# argument rather than dirname(makefile) because the self-tests below hand
# this a mutated copy of the Makefile while the tree it describes stays where
# it was.
check_coverage() {
  python3 - "$1" "$2" "$3" <<'PYEOF'
import re, glob, os, sys

tests_dir, makefile, include_root = sys.argv[1], sys.argv[2], sys.argv[3]


def makefile_text(path, depth=0):
    """The makefile's text with `include`d fragments folded in, in make's order.

    regress-offline-corpus's prerequisite list and both status counts live in
    the generated tools/offline-corpus.mk. A scan that read ./Makefile alone
    would find a corpus with no prerequisites and call every wired suite a
    gap. Variables and globs in an include path are skipped rather than
    guessed at -- tools/offline-corpus.php refuses to generate against one for
    the same reason.
    """
    with open(path, encoding='utf-8') as fh:
        text = fh.read()
    if depth > 4:
        return text
    out = []
    for line in text.split("\n"):
        out.append(line)
        m = re.match(r'^(-?)include\s+(.+)$', line)
        if not m:
            continue
        for included in m.group(2).split():
            if not re.match(r'^[A-Za-z0-9_./-]+$', included):
                continue
            resolved = os.path.join(include_root, included)
            if os.path.isfile(resolved):
                out.append(makefile_text(resolved, depth + 1))
    return "\n".join(out)

# RECURSIVE. The suite estate is moving into
# sandbox/tests/{offline/<domain>,live,grind,certify,spike}/, and a
# non-recursive glob fails OPEN on every file below the top level: a nested
# suite is simply not enumerated, so it is not compared against the Makefile
# and this check stays green while the suite runs nowhere. That is the exact
# failure this file exists to make impossible, one directory deeper, so the
# recursion landed before any file moved rather than with them. `**/` matches
# zero or more directories, so top-level files are still included.
files = sorted(glob.glob(os.path.join(tests_dir, "**", "regress_*.sh"), recursive=True) +
                glob.glob(os.path.join(tests_dir, "**", "regress_*.php"), recursive=True))

# Basename-keyed, because a target name is derived from the basename alone
# (`regress_foo_bar.sh` <-> `regress-foo-bar`) and a directory prefix
# contributes nothing to it. Two files sharing a basename therefore claim ONE
# target between them, and whichever loses the tie is unrunnable while looking
# wired -- a gap this check would otherwise report as covered. Nesting is what
# makes the collision possible at all (a directory cannot hold the same name
# twice), so it is refused here rather than resolved by a tie-break rule.
paths = {}   # basename -> path relative to tests_dir
collisions = []
for f in files:
    basename = os.path.basename(f)
    relative = os.path.relpath(f, tests_dir)
    if basename in paths:
        collisions.append((paths[basename], relative))
        continue
    paths[basename] = relative
if collisions:
    for first, second in collisions:
        print(f"  {os.path.join(tests_dir, first)} and {os.path.join(tests_dir, second)} share the "
              f"basename '{os.path.basename(first)}' -- target names come from the basename alone, "
              f"so only one of them can ever be wired and the other is unrunnable", file=sys.stderr)
    sys.exit(1)
basenames = list(paths)

def code_lines(path):
    # Strip full-line comments so a doc-comment mention ("see regress_x.sh's
    # own header") can't be mistaken for an actual invocation -- the one
    # false-positive shape found authoring this check. Inline trailing
    # comments are left alone: under-stripping only risks a false
    # "covered", never a false gap, which is the safe direction to err.
    with open(path, encoding='utf-8', errors='replace') as fh:
        return ''.join(l for l in fh if not re.match(r'^\s*#', l))

contents = {b: code_lines(os.path.join(tests_dir, rel)) for b, rel in paths.items()}

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

# target name -> source file RELATIVE TO tests_dir, for every file that is its
# own suite. The relative path (not the basename) is what the gap report
# prints, so a nested suite is named where it actually lives.
primary = {}
for b in basenames:
    if invoked_elsewhere(b):
        continue
    stem = re.sub(r'\.(sh|php)$', '', b)
    target = stem.replace('regress_', 'regress-').replace('_', '-')
    primary[target] = paths[b]

mk_text = makefile_text(makefile)
mk_lines = mk_text.split("\n")

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
    mk_text,
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

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
cp Makefile "$TMP/Makefile"
# The generated include travels with the Makefile copy: it carries the corpus's
# prerequisite list and both status counts, so a copy without it describes an
# empty corpus and every self-test below would pass for the wrong reason.
mkdir -p "$TMP/tools" "$TMP/tests"
cp tools/offline-corpus.mk "$TMP/tools/offline-corpus.mk"
# RECURSIVE copy, preserving each file's directory. A flat `cp
# sandbox/tests/regress_*.{sh,php}` would build a synthetic tree that is flat
# no matter what the real one looks like, so every self-test below would keep
# passing against a check that had silently lost its recursion -- the harness
# would stop exercising what the real check does. `find -print0` + a read loop
# rather than cpio/rsync: both of those have BSD/GNU flag differences, and this
# runs on stock macOS as well as Linux.
while IFS= read -r -d '' file; do
  relative="${file#sandbox/tests/}"
  mkdir -p "$TMP/tests/$(dirname "$relative")"
  cp "$file" "$TMP/tests/$relative"
done < <(find sandbox/tests \( -name 'regress_*.sh' -o -name 'regress_*.php' \) -type f -print0)
# The copy is a premise of every self-test below, and a silently incomplete one
# would make them all pass against a tree that is not the tree. This is what
# fails if the loop above is ever flattened back: a flat copy drops every
# nested suite and the counts diverge by exactly those files.
REAL_SUITE_COUNT=$(find sandbox/tests \( -name 'regress_*.sh' -o -name 'regress_*.php' \) -type f | wc -l | tr -d ' ')
COPIED_SUITE_COUNT=$(find "$TMP/tests" \( -name 'regress_*.sh' -o -name 'regress_*.php' \) -type f | wc -l | tr -d ' ')
[ "$REAL_SUITE_COUNT" = "$COPIED_SUITE_COUNT" ] \
  || fail "self-test setup failed: the synthetic tree holds $COPIED_SUITE_COUNT suite files but sandbox/tests holds $REAL_SUITE_COUNT -- the copy is not reproducing the real tree's shape, so nothing below is testing what the real check does"
# Precondition, not a self-test: every self-test below plants a defect in this
# copy and asserts on the refusal it produces, so a copy that is ALREADY
# refusing reports those defects under whatever complaint came first. The
# derived form makes that reachable in one specific way -- a hand-edited or
# unregenerated tools/offline-corpus.mk -- so the remedy is named here rather
# than left to be inferred from a self-test failure about something else.
say "precondition: the unmodified tree must already agree with its generated corpus include"
if ! check_coverage "$TMP/tests" "$TMP/Makefile" "$TMP" 2>"$TMP/coverage-precondition.log"; then
  cat "$TMP/coverage-precondition.log" >&2
  fail "the repository's own suite tree and Makefile do not agree BEFORE any self-test planted anything -- see above. If the complaint is a count or a missing target, tools/offline-corpus.mk is stale: run 'php tools/offline-corpus.php'. Nothing below this line would be meaningful until it is fixed."
fi
pass "precondition: the unmodified tree and its generated corpus include agree"

say "self-test: a synthetic unwired suite file must be flagged as a gap"
cat > "$TMP/tests/regress_synthetic_unwired_probe.sh" <<'EOF'
#!/usr/bin/env bash
# Synthetic fixture for regress_bundle_coverage.sh's own self-test only --
# deliberately never wired into regress-offline-all or regress-live-list.
EOF
if check_coverage "$TMP/tests" "$TMP/Makefile" "$TMP" 2>"$TMP/coverage-selftest.log"; then
  fail "self-test failed: a synthetic suite with no bundle/live-list entry was NOT flagged -- this check's own detection logic is broken, do not trust the real-repo result below"
fi
grep -q "regress_synthetic_unwired_probe.sh" "$TMP/coverage-selftest.log" \
  || fail "self-test failed: check_coverage exited non-zero but did not name the synthetic file it should have flagged"
pass "self-test: synthetic unwired suite correctly flagged as a gap"

say "self-test: a stale offline-suite count must be rejected"
# The count lives in the GENERATED include now, so the mutation happens there
# and the copied Makefile is left alone. That is also the shape of the only
# way this count can still go stale in the real repo: someone hand-edits
# tools/offline-corpus.mk instead of regenerating it.
mkdir -p "$TMP/badcount/tools"
cp "$TMP/Makefile" "$TMP/badcount/Makefile"
read -r BAD_COUNT ACTUAL_COUNT < <(python3 - "$TMP/tools/offline-corpus.mk" "$TMP/badcount/tools/offline-corpus.mk" <<'PYEOF'
import re, sys

source, destination = sys.argv[1], sys.argv[2]
text = open(source, encoding='utf-8').read()
match = re.search(r'(regress-offline-all:\s+)(\d+)(\s+offline suites green)', text)
if not match:
    raise SystemExit('could not locate the generated offline-suite status line')
declared = int(match.group(2))
bad = declared - 1 if declared > 0 else declared + 1
text = text[:match.start(2)] + str(bad) + text[match.end(2):]
open(destination, 'w', encoding='utf-8').write(text)
print(bad, declared)
PYEOF
)
if check_coverage "$TMP/tests" "$TMP/badcount/Makefile" "$TMP/badcount" 2>"$TMP/coverage-selftest-bad-count.log"; then
  fail "self-test failed: stale regress-offline-all count was accepted"
fi
grep -q "reports ${BAD_COUNT} suites.*contains ${ACTUAL_COUNT} unique offline suites" "$TMP/coverage-selftest-bad-count.log" \
  || fail "self-test failed: stale-count refusal did not report the declared and actual counts"
pass "self-test: stale offline-suite count correctly rejected"

say "self-test: the same synthetic tree WITHOUT the extra file must be clean (no false positives from the harness itself)"
rm -f "$TMP/tests/regress_synthetic_unwired_probe.sh"
if ! check_coverage "$TMP/tests" "$TMP/Makefile" "$TMP" 2>"$TMP/coverage-selftest-clean.log"; then
  fail "self-test failed: the unmodified regress-suite tree (minus the synthetic probe) reported a gap that shouldn't exist -- see $TMP/coverage-selftest-clean.log. This means either a REAL gap exists in the current repo (in which case the real check below will also correctly fail, which is fine) or this check's own logic has a false-positive bug (needs investigation either way, but don't blame the self-test)."
fi
pass "self-test: an unmodified suite tree with a copied Makefile reports no gaps via this check's own logic"

# ---------------------------------------------------------------- nesting
# The three self-tests below are the reason the recursion landed as its own
# change, ahead of any file moving. Before it, a suite in a subdirectory was
# not enumerated at all, so an unwired one produced NO gap and NO failure --
# the check reported "every suite is wired" about a tree it had not read. A
# silent fail-open cannot be caught by the thing that is failing open, so it
# is caught here, against a synthetic tree that is nested by construction.
say "self-test: a WIRED suite in a subdirectory must be accepted"
NESTED_ROOT="$TMP/nested"
NESTED_MAKEFILE="$NESTED_ROOT/Makefile"
mkdir -p "$NESTED_ROOT/tools"
cp "$TMP/Makefile" "$NESTED_MAKEFILE"
python3 - "$TMP/tools/offline-corpus.mk" "$NESTED_ROOT/tools/offline-corpus.mk" regress-synthetic-nested-wired-probe <<'PYEOF'
import re, sys

source, destination, target = sys.argv[1], sys.argv[2], sys.argv[3]
text = open(source, encoding='utf-8').read()
# The synthetic target joins regress-offline-corpus's prerequisites AND both
# declared counts move with it. They have to move together: the count check
# runs before the gap report, so a mismatched count would abort with a
# different refusal and this self-test would pass for the wrong reason.
text, replaced = re.subn(r'(?m)^(regress-offline-corpus:)', r'\1 ' + target, text, count=1)
if replaced != 1:
    raise SystemExit('could not locate the regress-offline-corpus prerequisite line')
text, replaced = re.subn(
    r'((?:regress-offline-all|regress-offline-corpus): )(\d+)( offline suites green)',
    lambda m: m.group(1) + str(int(m.group(2)) + 1) + m.group(3),
    text,
)
if replaced != 2:
    raise SystemExit('expected both generated status lines, found %d' % replaced)
open(destination, 'w', encoding='utf-8').write(text)
PYEOF
mkdir -p "$TMP/tests/offline/guards"
cat > "$TMP/tests/offline/guards/regress_synthetic_nested_wired_probe.sh" <<'EOF'
#!/usr/bin/env bash
# Synthetic fixture for regress_bundle_coverage.sh's own self-test only --
# a suite in a subdirectory that IS wired into the offline corpus.
EOF
if ! check_coverage "$TMP/tests" "$NESTED_MAKEFILE" "$NESTED_ROOT" 2>"$TMP/coverage-selftest-nested-wired.log"; then
  cat "$TMP/coverage-selftest-nested-wired.log" >&2
  fail "self-test failed: a suite in a subdirectory WITH a regress-offline-corpus entry was reported as a gap -- the enumeration finds nested files but the target-name derivation does not agree with them"
fi
pass "self-test: a wired suite in a subdirectory is correctly accepted"

say "self-test: an UNWIRED suite in a subdirectory must be flagged as a gap"
cat > "$TMP/tests/offline/guards/regress_synthetic_nested_unwired_probe.sh" <<'EOF'
#!/usr/bin/env bash
# Synthetic fixture for regress_bundle_coverage.sh's own self-test only --
# a suite in a subdirectory, deliberately never wired anywhere.
EOF
if check_coverage "$TMP/tests" "$NESTED_MAKEFILE" "$NESTED_ROOT" 2>"$TMP/coverage-selftest-nested-unwired.log"; then
  fail "self-test failed: a suite in a SUBDIRECTORY with no bundle/live-list entry was NOT flagged. This is the exact fail-open this recursion exists to close: the file enumeration is no longer reaching below sandbox/tests, so every nested suite is invisible to this check and the real-repo result below means nothing"
fi
grep -q "offline/guards/regress_synthetic_nested_unwired_probe.sh" "$TMP/coverage-selftest-nested-unwired.log" \
  || fail "self-test failed: the nested gap was detected but not reported at its real path -- a gap report naming only a basename cannot be acted on once suites live in subdirectories"
rm -f "$TMP/tests/offline/guards/regress_synthetic_nested_unwired_probe.sh"
pass "self-test: nested unwired suite correctly flagged as a gap, named at its nested path"

say "self-test: two suite files sharing a basename must be refused"
# The half-finished-move shape: the same suite present at both its old and its
# new path. Target names come from the basename alone, so the two claim one
# target and only one of them can ever run.
cp "$TMP/tests/offline/guards/regress_synthetic_nested_wired_probe.sh" \
   "$TMP/tests/regress_synthetic_nested_wired_probe.sh"
if check_coverage "$TMP/tests" "$NESTED_MAKEFILE" "$NESTED_ROOT" 2>"$TMP/coverage-selftest-duplicate.log"; then
  fail "self-test failed: the same basename at two paths was accepted -- one of the two is unrunnable while this check calls it covered"
fi
grep -q "share the basename 'regress_synthetic_nested_wired_probe.sh'" "$TMP/coverage-selftest-duplicate.log" \
  || fail "self-test failed: the duplicate-basename refusal did not name the colliding basename"
rm -f "$TMP/tests/regress_synthetic_nested_wired_probe.sh" \
      "$TMP/tests/offline/guards/regress_synthetic_nested_wired_probe.sh"
pass "self-test: a basename claimed by two files is correctly refused"

# ------------------------------------------------------------ cannot exclude
# The property derivation adds, and the one this file could not previously
# state: a suite on disk cannot be left OUT of the corpus, because there is no
# exclusion input to leave it out of. Everything above answers "was it
# wired?", which is an after-the-fact question about a hand-written list.
# This runs the generator itself against a faithful copy of the tree, deletes
# one derived suite from the copied include -- the only way an exclusion can
# even be attempted -- and requires the refusal to name it.
say "self-test: the derivation refuses a suite that has been deleted from the generated include"
DERIVE_ROOT="$TMP/derive"
mkdir -p "$DERIVE_ROOT/tools" "$DERIVE_ROOT/sandbox/tests"
cp Makefile "$DERIVE_ROOT/Makefile"
cp tools/offline-corpus.mk "$DERIVE_ROOT/tools/offline-corpus.mk"
cp sandbox/tests/offline_diagnostics_guard.sh "$DERIVE_ROOT/sandbox/tests/offline_diagnostics_guard.sh"
while IFS= read -r -d '' file; do
  mkdir -p "$DERIVE_ROOT/$(dirname "$file")"
  cp "$file" "$DERIVE_ROOT/$file"
done < <(find sandbox/tests \( -name 'regress_*.sh' -o -name 'regress_*.php' \) -type f -print0)
# The copy has to derive clean first, or the refusal below proves nothing
# about exclusion -- it would just be re-reporting an incomplete fixture.
if ! php tools/offline-corpus.php --check --root="$DERIVE_ROOT" >"$TMP/derive-clean.log" 2>&1; then
  cat "$TMP/derive-clean.log" >&2
  fail "self-test failed: the copied tree does not derive to the committed include, so the exclusion refusal below would not be testing exclusion"
fi
EXCLUDED=$(python3 - "$DERIVE_ROOT/tools/offline-corpus.mk" <<'PYEOF'
import re, sys

path = sys.argv[1]
lines = open(path, encoding='utf-8').read().split("\n")
for i, line in enumerate(lines):
    m = re.match(r'^\t([a-z0-9-]+) \\$', line)
    if m and m.group(1) != 'code-half-unit':
        del lines[i]
        open(path, 'w', encoding='utf-8').write("\n".join(lines))
        print(m.group(1))
        break
else:
    raise SystemExit('could not find a derived suite line to delete')
PYEOF
)
if php tools/offline-corpus.php --check --root="$DERIVE_ROOT" >"$TMP/derive-excluded.log" 2>&1; then
  fail "self-test failed: a suite on disk was silently absent from the generated corpus include and the derivation accepted it -- exclusion is supposed to be impossible, not merely discouraged"
fi
grep -q "missing from tools/offline-corpus.mk: ${EXCLUDED}" "$TMP/derive-excluded.log" \
  || { cat "$TMP/derive-excluded.log" >&2; fail "self-test failed: the derivation refused but did not name the excluded suite '$EXCLUDED'"; }
pass "self-test: an attempted exclusion of '$EXCLUDED' was refused by name"

say "real check: every sandbox/tests/regress_*.{sh,php} file vs. Makefile's regress-offline-all / regress-live-list"
if check_coverage sandbox/tests Makefile . 2>/tmp/coverage_real.log; then
  pass "every regress-* suite file has a regress-offline-all or regress-live-list entry"
else
  cat /tmp/coverage_real.log >&2
  fail "one or more regress_*.{sh,php} files exist with no regress-offline-all or regress-live-list entry -- see above. Add the missing target to whichever bundle matches its docker/pair.sh dependency (see regress-offline-all's own comment for the classification rule), or if it's a genuine helper invoked by another suite (php/bash <this-file> appearing in another regress_*.{sh,php} file's own code), this check already excludes it -- so a flag here means neither is currently true."
fi

printf '\n\033[1;32m✔ REGRESS_BUNDLE_COVERAGE PASSED\033[0m\n'
