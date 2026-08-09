#!/usr/bin/env bash
# Guide command checker (DUO-3323) — proves docs/guides/*.md never cites a
# command that does not exist, and that anything it cites which DOESN'T exist
# is labeled as unshipped at the exact line that mentions it.
#
# Why this exists: the guides are narrative prose written against a moving
# tree. A reference page that drifts is annoying; a guide that tells an
# operator to run a verb the dispatcher has never heard of is worse than no
# guide, because it burns the trust that makes the honest-boundary posture
# worth anything. So the honesty contract in docs/guides/README.md is
# mechanically checked rather than merely asserted:
#
#   1. every `duo <verb>` token must be a real host verb (cli/duo's dispatch
#      list plus the verbs main() compares before it), and
#   2. every `wp duo <command>` token must be a real agent registration
#      (agent/src/Cli.php's public methods, with @subcommand overrides), and
#   3. a token that is neither is a FAILURE naming the guide and line —
#      UNLESS that same line carries the literal `**Planned (DUO-NNNN)**`
#      label, which is precisely how the guides are required to mark
#      unshipped behavior. A planned command may be named in prose; it may
#      never be named without its label.
#
# Scope, stated so nobody mistakes silence for a passing grade: only tokens
# inside fenced code blocks and inline `code spans` are examined, because that
# is where the guides cite commands. FLAGS ARE NOT CHECKED — only verbs and
# subcommands. This is a cheap, hand-run truth check, not a parser.
#
# Deliberately NOT named regress_* and deliberately has no Makefile target:
# same precedent as cli_status_truth.sh. Run it by hand when the guides or the
# dispatch lists change.
#
# Usage:
#   bash sandbox/tests/check_guide_commands.sh              # check the tree
#   bash sandbox/tests/check_guide_commands.sh --self-test  # prove it fails
#
# Bash only. No docker, no network, no live environment.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
HOST_CLI="$ROOT/cli/duo"
AGENT_CLI="$ROOT/agent/src/Cli.php"
GUIDE_DIR="$ROOT/docs/guides"

TMPDIR_SELFTEST=""
cleanup() { [ -n "$TMPDIR_SELFTEST" ] && rm -rf "$TMPDIR_SELFTEST"; return 0; }
trap cleanup EXIT

for required in "$HOST_CLI" "$AGENT_CLI"; do
    if [ ! -f "$required" ]; then
        echo "check_guide_commands: missing source of truth: $required" >&2
        exit 1
    fi
done

# --- the two allowlists, read out of the source, never hardcoded -------------

# cli/duo dispatch: the $verbsNeedingEnv array plus every verb main() compares
# directly (that is where `envs` and `driver-capabilities` are handled).
host_verbs() {
    {
        sed -n 's/.*verbsNeedingEnv = \[\(.*\)\];.*/\1/p' "$HOST_CLI"
        grep -oE "verb === '[a-z][a-z0-9-]*'" "$HOST_CLI"
    } | grep -oE "'[a-z][a-z0-9-]*'" | tr -d "'" | sort -u
}

# agent/src/Cli.php registers the whole class as `wp duo`, so every public
# method is a subcommand: underscores become hyphens unless an explicit
# @subcommand annotation names it.
agent_commands() {
    {
        grep -oE '^[[:space:]]*public function [a-z_]+' "$AGENT_CLI" \
            | sed 's/.*public function //' | tr '_' '-'
        grep -oE '@subcommand [a-z][a-z0-9-]*' "$AGENT_CLI" \
            | sed 's/@subcommand //'
    } | sort -u
}

# --- token extraction --------------------------------------------------------

# Emits one TAB-separated row per citation: file, line, planned-flag, kind,
# token. `wp duo <cmd>` is consumed first so its `duo` never also reads as a
# host verb. Inline code spans are joined with " | " so two adjacent spans
# can never form a phantom `duo <token>` pair across the boundary.
AWK_EXTRACT='
BEGIN { fence = 0 }
{
    line = $0
    if (line ~ /^[[:space:]]*```/) { fence = 1 - fence; next }
    code = ""
    if (fence) {
        code = line
    } else {
        rest = line
        while (match(rest, /`[^`]+`/)) {
            code = code " | " substr(rest, RSTART + 1, RLENGTH - 2)
            rest = substr(rest, RSTART + RLENGTH)
        }
    }
    if (code == "") { next }
    planned = (line ~ /\*\*Planned \(DUO-[0-9]+\)\*\*/) ? 1 : 0

    work = code
    kept = ""
    while (match(work, /wp[[:space:]]+duo[[:space:]]+[a-z][a-z0-9-]*/)) {
        seg = substr(work, RSTART, RLENGTH)
        sub(/^.*duo[[:space:]]+/, "", seg)
        printf "%s\t%d\t%d\tagent\t%s\n", FILENAME, FNR, planned, seg
        kept = kept " | " substr(work, 1, RSTART - 1)
        work = substr(work, RSTART + RLENGTH)
    }
    work = kept " | " work

    while (match(work, /(^|[^a-zA-Z0-9_.\/-])(cli\/)?duo[[:space:]]+[a-z][a-z0-9-]*/)) {
        seg = substr(work, RSTART, RLENGTH)
        sub(/^.*duo[[:space:]]+/, "", seg)
        printf "%s\t%d\t%d\thost\t%s\n", FILENAME, FNR, planned, seg
        work = substr(work, RSTART + RLENGTH)
    }
}
'

# --- the check ---------------------------------------------------------------

# scan_guides <dir> ; prints findings, returns non-zero on any unknown token.
scan_guides() {
    local dir="$1"
    local verbs commands
    verbs="$(host_verbs)"
    commands="$(agent_commands)"

    local status=0 checked=0 planned_count=0 files=0
    local f file lineno planned kind token allowed label

    for f in "$dir"/*.md; do
        [ -e "$f" ] || continue
        files=$((files + 1))
        while IFS=$'\t' read -r file lineno planned kind token; do
            [ -n "${token:-}" ] || continue
            checked=$((checked + 1))
            if [ "$kind" = 'agent' ]; then
                allowed="$commands"
                label="wp duo $token"
            else
                allowed="$verbs"
                label="duo $token"
            fi
            if printf '%s\n' "$allowed" | grep -qxF -- "$token"; then
                continue
            fi
            if [ "$planned" = '1' ]; then
                planned_count=$((planned_count + 1))
                echo "  planned: $(basename "$file"):$lineno cites '$label' and labels it unshipped"
                continue
            fi
            echo "FAIL: $(basename "$file"):$lineno cites '$label', which is not a"\
                 "$([ "$kind" = 'agent' ] && echo 'wp duo subcommand in agent/src/Cli.php' || echo 'verb in cli/duo')" >&2
            status=1
        done < <(awk "$AWK_EXTRACT" "$f")
    done

    if [ "$files" -eq 0 ]; then
        echo "FAIL: no guide files found in $dir" >&2
        return 1
    fi
    echo "  checked $checked command citation(s) across $files file(s); $planned_count labeled planned"
    return "$status"
}

# --- self-test ---------------------------------------------------------------
#
# Proves the checker actually catches a bad citation, and that the ONLY thing
# that lets an unknown command through is the honesty label itself.
self_test() {
    TMPDIR_SELFTEST="$(mktemp -d)"
    local bad="$TMPDIR_SELFTEST/unlabeled" good="$TMPDIR_SELFTEST/labeled"
    mkdir -p "$bad" "$good"
    cp "$GUIDE_DIR"/*.md "$bad/"
    cp "$GUIDE_DIR"/*.md "$good/"

    printf '\nInjected by --self-test: run `duo not-a-real-verb` and `wp duo not-a-real-command`.\n' \
        >> "$bad/quickstart.md"
    printf '\nInjected by --self-test: `duo not-a-real-verb` is **Planned (DUO-9999)** — not yet shipped.\n' \
        >> "$good/quickstart.md"

    local out rc

    echo "self-test case A: an unlabeled bogus citation must FAIL"
    set +e
    out="$(scan_guides "$bad" 2>&1)"
    rc=$?
    set -e
    if [ "$rc" -eq 0 ]; then
        echo "SELF-TEST FAILED: checker accepted a nonexistent verb" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    if ! printf '%s\n' "$out" | grep -q 'quickstart.md:'; then
        echo "SELF-TEST FAILED: failure did not name the guide file and line" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    if ! printf '%s\n' "$out" | grep -q "duo not-a-real-verb"; then
        echo "SELF-TEST FAILED: failure did not name the bogus host verb" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    if ! printf '%s\n' "$out" | grep -q "wp duo not-a-real-command"; then
        echo "SELF-TEST FAILED: failure did not name the bogus agent command" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    printf '%s\n' "$out" | sed 's/^/    /'
    echo "  ok: rejected, naming the file, the line, and both bogus tokens"

    echo "self-test case B: the same bogus verb WITH its planned label must pass"
    set +e
    out="$(scan_guides "$good" 2>&1)"
    rc=$?
    set -e
    if [ "$rc" -ne 0 ]; then
        echo "SELF-TEST FAILED: a labeled planned command was rejected" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    echo "  ok: accepted, because the line carries **Planned (DUO-9999)**"
    return 0
}

# --- main --------------------------------------------------------------------

case "${1:-}" in
    --self-test)
        echo "== check_guide_commands --self-test =="
        if self_test; then
            echo "✔ CHECK_GUIDE_COMMANDS SELF-TEST PASSED"
            exit 0
        fi
        echo "✘ CHECK_GUIDE_COMMANDS SELF-TEST FAILED" >&2
        exit 1
        ;;
    '')
        echo "== check_guide_commands: docs/guides =="
        echo "host verbs:     $(host_verbs | tr '\n' ' ')"
        echo "agent commands: $(agent_commands | tr '\n' ' ')"
        if scan_guides "$GUIDE_DIR"; then
            echo "✔ CHECK_GUIDE_COMMANDS PASSED"
            exit 0
        fi
        echo "✘ CHECK_GUIDE_COMMANDS FAILED" >&2
        exit 1
        ;;
    *)
        echo "usage: $0 [--self-test]" >&2
        exit 2
        ;;
esac
