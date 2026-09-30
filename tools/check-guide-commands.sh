#!/usr/bin/env bash
# Guide command checker — current guides, detailed references and public
# entry points must never cite a
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
#   1. every `wprism <verb>` token must be a real host verb (cli/wprism's dispatch
#      list plus the verbs main() compares before it), and
#   2. every `wp wprism <command>` token must be a real agent registration
#      (agent/src/Command/Cli.php's public methods, with @subcommand overrides), and
#   3. a token that is neither is a FAILURE naming the guide and line —
#      UNLESS that same line carries the literal `**Planned** — not yet shipped.`
#      label, which is precisely how the guides are required to mark
#      unshipped behavior. A planned command may be named in prose; it may
#      never be named without its label.
#
# Scope, stated so nobody mistakes silence for a passing grade. Three
# limitations, all deliberate — this is a focused local truth check, not a
# parser:
#
#   - Only tokens inside fenced code blocks and inline `code spans` are
#     examined, because that is where the guides cite commands. A command
#     named in bare prose is invisible here.
#   - FLAGS ARE NOT CHECKED. Only verbs and subcommands are resolved, so
#     `wprism status --nonsense` passes.
#   - Only the bare `wprism …`, `cli/wprism …`, and `wp wprism …` spellings are
#     recognized. A path-prefixed invocation (`./cli/wprism status`,
#     `bin/wprism status`) does not match the extractor and is silently
#     unchecked — cite commands in one of the three recognized forms.
#
# Developer entry point, run by tools/check-docs.sh and offline --extras.
# It reads dispatch vocabularies without contacting a target.
#
# Usage:
#   bash tools/check-guide-commands.sh              # check the tree
#   bash tools/check-guide-commands.sh --self-test  # prove it fails
#
# Bash only. No docker, no network, no live environment.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
HOST_CLI="$ROOT/cli/wprism"
HOST_PREFLIGHT="$ROOT/cli/src/Command/EnvironmentCommandPreflight.php"
AGENT_CLI="$ROOT/agent/src/Command/Cli.php"
GUIDE_DIR="$ROOT/docs/guides"
REFERENCE_DIR="$ROOT/docs/reference"

TMPDIR_SELFTEST=""
cleanup() { [ -n "$TMPDIR_SELFTEST" ] && rm -rf "$TMPDIR_SELFTEST"; return 0; }
trap cleanup EXIT

for required in "$HOST_CLI" "$HOST_PREFLIGHT" "$AGENT_CLI"; do
    if [ ! -f "$required" ]; then
        echo "check_guide_commands: missing source of truth: $required" >&2
        exit 1
    fi
done

# --- the two allowlists, read out of the source, never hardcoded -------------

# cli/wprism dispatch: the environment preflight vocabulary plus every verb main()
# compares directly (that is where offline commands such as `envs` and
# `driver-capabilities` are handled). The vocabulary is read from the runtime
# collaborator rather than copied from the host entrypoint.
host_verbs() {
    {
        php -r '
            require $argv[1];
            foreach (WPrism\Orchestrator\EnvironmentCommandPreflight::environmentVerbs() as $verb) {
                echo $verb, "\n";
            }
        ' "$HOST_PREFLIGHT"
        grep -oE "verb === '[a-z][a-z0-9-]*'" "$HOST_CLI"
    } | sed -nE \
        -e "s/.*'([a-z][a-z0-9-]*)'.*/\\1/p" \
        -e "/^[a-z][a-z0-9-]*$/p" | sort -u
}

# agent/src/Command/Cli.php registers the whole class as `wp wprism`, so every public
# method is a subcommand, named exactly as WP-CLI names it: the `@subcommand`
# annotation when one is present, otherwise the RAW method name. WP-CLI never
# hyphenates a method name itself (CommandFactory::create_subcommand takes the
# tag or `$reflection->name`), which is why `code_inventory` shipped unreachable
# as `wp wprism code-inventory` while this checker -- which used to hyphenate --
# validated the guide citation green (issue #3517). Emitting the raw name means a
# guide that cites a hyphenated form of an untagged method now fails here.
agent_commands() {
    # One name per handler, exactly as WP-CLI registers it: the @subcommand tag
    # seen in the docblock above the method, else the raw method name. A tagged
    # method is NOT also reachable under its raw name, so neither is listed here.
    awk '
        /@subcommand [a-z][a-z0-9-]*/ {
            match($0, /@subcommand [a-z][a-z0-9-]*/)
            tag = substr($0, RSTART + 12, RLENGTH - 12)
            next
        }
        /^[[:space:]]*public function [a-z_]+/ {
            match($0, /public function [a-z_]+/)
            name = substr($0, RSTART + 16, RLENGTH - 16)
            print (tag != "" ? tag : name)
            tag = ""
        }
    ' "$AGENT_CLI" | sort -u
}

# --- token extraction --------------------------------------------------------

# Emits one TAB-separated row per citation: file, line, planned-flag, kind,
# token. `wp wprism <cmd>` is consumed first so its `wprism` never also reads as a
# host verb. Inline code spans are joined with " | " so two adjacent spans
# can never form a phantom `wprism <token>` pair across the boundary.
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
    planned = (line ~ /\*\*Planned\*\*[[:space:]]*—[[:space:]]*not yet shipped\./) ? 1 : 0

    work = code
    kept = ""
    while (match(work, /wp[[:space:]]+wprism[[:space:]]+[a-z][a-z0-9-]*/)) {
        seg = substr(work, RSTART, RLENGTH)
        sub(/^.*wprism[[:space:]]+/, "", seg)
        printf "%s\t%d\t%d\tagent\t%s\n", FILENAME, FNR, planned, seg
        kept = kept " | " substr(work, 1, RSTART - 1)
        work = substr(work, RSTART + RLENGTH)
    }
    work = kept " | " work

    while (match(work, /(^|[^a-zA-Z0-9_.\/-])(cli\/)?wprism[[:space:]]+[a-z][a-z0-9-]*/)) {
        seg = substr(work, RSTART, RLENGTH)
        sub(/^.*wprism[[:space:]]+/, "", seg)
        printf "%s\t%d\t%d\thost\t%s\n", FILENAME, FNR, planned, seg
        work = substr(work, RSTART + RLENGTH)
    }
}
'

# --- the check ---------------------------------------------------------------

# scan_files <file>... ; returns non-zero on any unknown command citation.
scan_files() {
    local verbs commands
    verbs="$(host_verbs)"
    commands="$(agent_commands)"

    local status=0 checked=0 planned_count=0 files=0
    local f file lineno planned kind token allowed label

    for f in "$@"; do
        [ -e "$f" ] || continue
        files=$((files + 1))
        while IFS=$'\t' read -r file lineno planned kind token; do
            [ -n "${token:-}" ] || continue
            checked=$((checked + 1))
            if [ "$kind" = 'agent' ]; then
                allowed="$commands"
                label="wp wprism $token"
            else
                allowed="$verbs"
                label="wprism $token"
            fi
            # A here-string, not `printf | grep -q`: grep -q exits on the
            # first match, and under `pipefail` a printf that was still
            # writing then fails the pipeline with SIGPIPE -- a VALID token
            # (the alphabetically first verb, `adapter`, most of all) reported
            # as unknown whenever the host is busy. Observed as an intermittent
            # "cites 'wprism adapter', which is not a verb" while the offline
            # corpus ran beside this check.
            if grep -qxF -- "$token" <<<"$allowed"; then
                continue
            fi
            if [ "$planned" = '1' ]; then
                planned_count=$((planned_count + 1))
                echo "  planned: $(basename "$file"):$lineno cites '$label' and labels it unshipped"
                continue
            fi
            echo "FAIL: $(basename "$file"):$lineno cites '$label', which is not a"\
                 "$([ "$kind" = 'agent' ] && echo 'wp wprism subcommand in agent/src/Command/Cli.php' || echo 'verb in cli/wprism')" >&2
            status=1
        done < <(awk "$AWK_EXTRACT" "$f")
    done

    if [ "$files" -eq 0 ]; then
        echo "FAIL: no documentation files supplied" >&2
        return 1
    fi
    echo "  checked $checked command citation(s) across $files file(s); $planned_count labeled planned"
    return "$status"
}

scan_current() {
    local root="$1"
    scan_files "$root/README.md" "$root/CONTRIBUTING.md" "$root/cli/README.md" \
        "$root/docs/guides"/*.md "$root/docs/reference"/*.md
}

# --- self-test ---------------------------------------------------------------
#
# Proves the checker actually catches a bad citation, and that the ONLY thing
# that lets an unknown command through is the honesty label itself.
self_test() {
    TMPDIR_SELFTEST="$(mktemp -d)"
    local bad="$TMPDIR_SELFTEST/unlabeled" good="$TMPDIR_SELFTEST/labeled"
    local fixture
    for fixture in "$bad" "$good"; do
        mkdir -p "$fixture/cli" "$fixture/docs/guides" "$fixture/docs/reference"
        cp "$ROOT/README.md" "$ROOT/CONTRIBUTING.md" "$fixture/"
        cp "$ROOT/cli/README.md" "$fixture/cli/"
        cp "$GUIDE_DIR"/*.md "$fixture/docs/guides/"
        cp "$REFERENCE_DIR"/*.md "$fixture/docs/reference/"
    done

    printf '\nInjected by --self-test: run `wprism not-a-real-verb` and `wp wprism not-a-real-command`.\n' \
        >> "$bad/docs/guides/quickstart.md"
    printf '\nInjected by --self-test: run `wprism not-a-real-reference-verb`.\n' \
        >> "$bad/docs/reference/cli-commands.md"
    printf '\nInjected by --self-test: `wprism not-a-real-verb` is **Planned** — not yet shipped.\n' \
        >> "$good/docs/guides/quickstart.md"
    printf '\nInjected by --self-test: `wprism not-a-real-reference-verb` is **Planned** — not yet shipped.\n' \
        >> "$good/docs/reference/cli-commands.md"

    local out rc

    echo "self-test case A: an unlabeled bogus citation must FAIL"
    set +e
    out="$(scan_current "$bad" 2>&1)"
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
    if ! printf '%s\n' "$out" | grep -q "wprism not-a-real-verb"; then
        echo "SELF-TEST FAILED: failure did not name the bogus host verb" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    if ! printf '%s\n' "$out" | grep -q "wp wprism not-a-real-command"; then
        echo "SELF-TEST FAILED: failure did not name the bogus agent command" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    if ! grep -q 'cli-commands.md:.*not-a-real-reference-verb' <<<"$out"; then
        echo "SELF-TEST FAILED: checker did not inspect the moved reference" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    printf '%s\n' "$out" | sed 's/^/    /'
    echo "  ok: rejected guide and reference citations, naming files, lines, and tokens"

    echo "self-test case B: the same bogus verb WITH its planned label must pass"
    set +e
    out="$(scan_current "$good" 2>&1)"
    rc=$?
    set -e
    if [ "$rc" -ne 0 ]; then
        echo "SELF-TEST FAILED: a labeled planned command was rejected" >&2
        printf '%s\n' "$out" >&2
        return 1
    fi
    echo "  ok: accepted, because the line carries **Planned** — not yet shipped."
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
        echo "== check_guide_commands: guides, references, and public entry points =="
        echo "host verbs:     $(host_verbs | tr '\n' ' ')"
        echo "agent commands: $(agent_commands | tr '\n' ' ')"
        if scan_current "$ROOT"; then
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
