#!/usr/bin/env bash
#
# tools/doctor.sh -- fresh-checkout diagnosis for the duo-wp developer loop.
#
# WHY THIS EXISTS
# ---------------
# Three of this repo's failure modes are environmental, produce error text that
# names the wrong culprit, and cost hours the first time each is met:
#
#   1. A SHALLOW clone. `git clone --depth 1` is the default in most CI images
#      and in several agent bootstrap habits. It leaves the working tree
#      byte-identical to a full clone, so nothing looks wrong -- until
#      `make release-gate` dies with
#        duo: certification Git revision is not a commit: c30c1976...
#      (agent/src/ScopedCertificationBundle.php:433) and several offline suites
#      that exercise the same bundle path die with it too. The message names a
#      certification revision, so it reads like an evidence problem. It is not:
#      the object is simply not in the local object database.
#
#   2. MISSING CERTIFICATION COMMITS. Every record in
#      manifests/capabilities/evidence.json carries `bundle.git_revision`, and
#      assertGitRevisionInputs() proves the recorded closure bytes existed at
#      that exact commit by running `git cat-file -t <sha>` and
#      `git ls-tree -r <sha>` locally. Those commits are the PR heads that
#      certified each subject, and this project merges with `gh pr merge
#      --squash`, which DISCARDS them from every branch: no ref points at them,
#      so a plain `git fetch` (which negotiates by ref) never brings them down,
#      even into a full clone. GitHub still serves them by SHA, which is why
#      the remedy is an explicit `git fetch origin <sha>` pinned by a local
#      ref under refs/duo-cert/.
#
#   3. UNTRACKED OR IGNORED FILES UNDER agent/, cli/, sandbox/bin/. The
#      certification closure is built by walking those three trees on the
#      FILESYSTEM (ScopedCertificationBundle::subjectInputPaths()'s $addTree),
#      with no gitignore awareness whatsoever. A stray scratch file, an editor
#      swap file, or a .DS_Store inside them is a closure input: it changes
#      every subject's closure digest, expires all nine certifications, and a
#      deployed agent then refuses every command out of
#      assertRuntimeInputsCurrent() during Policy::load().
#
# Everything else here is ordinary prerequisite checking, but it is checked in
# the same run because the point of one command is that a newcomer types one
# command.
#
# WHAT --fix IS ALLOWED TO DO
# ---------------------------
# Only three remedies are safe to apply unattended, and only these are applied:
#   - `git fetch origin <sha> && git update-ref refs/duo-cert/<sha> <sha>` for a
#     missing certification commit (additive; creates no branch, moves nothing).
#   - `composer install` (writes only vendor/, which is gitignored).
#   - `mkdir -p sandbox/tmp` (gitignored scratch root).
# Everything else -- unshallowing, deleting files from the walked trees,
# installing host packages -- is printed as an exact command and left to a
# human, because each one either transfers a lot of data, destroys data, or
# needs a package manager this script has no business choosing.
#
# CONSTRAINTS
# -----------
# - bash + `set -euo pipefail`; cwd-independent (resolves its own repo root).
# - Reads only. It writes nothing outside vendor/, sandbox/tmp/ and
#   refs/duo-cert/, and only then under --fix.
# - Never creates a file under agent/, cli/, or sandbox/bin/ -- see hazard 3.
# - Exit status: 0 when no FAIL line was printed, 1 otherwise. WARN never
#   fails the run: docker, gh and a non-8.3 PHP are all legitimately absent
#   from a machine that can still run the whole offline gate.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

FIX=0
COLOR=auto
RUN_PAIRS=1

usage() {
    cat <<'TXT'
usage: bash tools/doctor.sh [--fix] [--no-pairs] [--no-color]

Diagnoses a duo-wp checkout for the developer loop and prints one ok/WARN/FAIL
line per check, each failure carrying the exact remedy command.

  --fix        apply the three safe remedies: fetch missing certification
               commits into refs/duo-cert/<sha>, `composer install`, and
               `mkdir -p sandbox/tmp`. Nothing else is ever applied.
  --no-pairs   skip every check that dials a daemon or the network: the
               sandbox pair-budget probe (it briefly takes the host-wide
               pair lock via sandbox/bin/pair.sh list), and the host-tooling
               docker (compose/info) and `gh auth status` probes.
  --no-color   plain output. NO_COLOR in the environment does the same.
  -h, --help   this text.

Exit status is 1 if any FAIL line was printed, 0 otherwise.
TXT
}

while [ $# -gt 0 ]; do
    case "$1" in
        --fix) FIX=1 ;;
        --no-pairs) RUN_PAIRS=0 ;;
        --no-color) COLOR=never ;;
        -h|--help) usage; exit 0 ;;
        *) echo "doctor: unknown option '$1'" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

if [ "$COLOR" != "never" ] && [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    C_OK=$'\033[1;32m'; C_WARN=$'\033[1;33m'; C_FAIL=$'\033[1;31m'
    C_HEAD=$'\033[1m'; C_DIM=$'\033[2m'; C_OFF=$'\033[0m'
else
    C_OK=''; C_WARN=''; C_FAIL=''; C_HEAD=''; C_DIM=''; C_OFF=''
fi

N_OK=0
N_WARN=0
N_FAIL=0

section() { printf '\n%s== %s ==%s\n' "$C_HEAD" "$*" "$C_OFF"; }
ok()      { N_OK=$((N_OK + 1));   printf '%sok%s    %s\n' "$C_OK" "$C_OFF" "$*"; }
warn()    { N_WARN=$((N_WARN + 1)); printf '%sWARN%s  %s\n' "$C_WARN" "$C_OFF" "$*"; }
fail()    { N_FAIL=$((N_FAIL + 1)); printf '%sFAIL%s  %s\n' "$C_FAIL" "$C_OFF" "$*"; }
why()     { printf '      %s%s%s\n' "$C_DIM" "$*" "$C_OFF"; }
remedy()  { printf '      %sremedy:%s %s\n' "$C_HEAD" "$C_OFF" "$*"; }
fixing()  { printf '      %s--fix:%s %s\n' "$C_HEAD" "$C_OFF" "$*"; }

have() { command -v "$1" >/dev/null 2>&1; }

# Nested tools (pair.sh) colour their own output unconditionally. When doctor
# is running without colour -- piped into a log, or under NO_COLOR -- their
# escapes would survive and make the transcript unreadable, so strip them.
indent() {
    if [ -n "$C_OFF" ]; then
        sed 's/^/        /'
    else
        sed $'s/\033\\[[0-9;]*m//g; s/^/        /'
    fi
}

# `timeout` is GNU coreutils and is absent from a stock macOS. Every probe that
# can block on a lock or a daemon goes through this, so doctor can never be the
# thing that hangs a terminal.
#
# When neither `timeout` nor Homebrew's `gtimeout` is on PATH -- i.e. exactly
# the stock-macOS case this comment names -- the fallback used to be a bare
# "$@" with NO bound at all, which silently broke the "can never hang" promise
# on the one platform it was written for. This fallback makes the bound real
# without coreutils: run the command in the background, poll for it to exit,
# and kill it (SIGTERM, then SIGKILL if it ignores that) once the deadline
# passes.
guard() {
    local secs="$1"; shift
    if have timeout; then
        timeout "$secs" "$@"
        return $?
    elif have gtimeout; then
        gtimeout "$secs" "$@"
        return $?
    fi
    "$@" &
    local guard_pid=$!
    local waited=0
    while kill -0 "$guard_pid" 2>/dev/null; do
        if [ "$waited" -ge "$secs" ]; then
            kill -TERM "$guard_pid" 2>/dev/null || true
            sleep 1
            kill -KILL "$guard_pid" 2>/dev/null || true
            wait "$guard_pid" 2>/dev/null || true
            return 124
        fi
        sleep 1
        waited=$((waited + 1))
    done
    wait "$guard_pid"
}

# ---------------------------------------------------------------- clone shape

section "clone integrity"

git_ok=1
if ! have git; then
    fail "git is not on PATH -- every check below depends on it"
    remedy "install git (xcode-select --install on macOS, apt-get install git on Debian)"
    git_ok=0
elif ! git rev-parse --git-dir >/dev/null 2>&1; then
    fail "$REPO_ROOT is not a Git working tree"
    remedy "git clone https://github.com/duotronic-ai/duo-wp && bash duo-wp/tools/doctor.sh"
    git_ok=0
fi

if [ "$git_ok" = 1 ]; then
    shallow="$(git rev-parse --is-shallow-repository 2>/dev/null || echo unknown)"
    if [ "$shallow" = "false" ]; then
        ok "full clone (git rev-parse --is-shallow-repository = false)"
    else
        fail "shallow clone (is-shallow-repository = $shallow)"
        why "measured on a --depth 1 clone of this repo: make release-gate,"
        why "regress-capability-registry and regress-manifest-dispositions all die with"
        why "\"duo: certification Git revision is not a commit\" -- every path that"
        why "replays the certification bundle does. The certification commits are simply"
        why "absent from the local object database; the message names the wrong culprit."
        remedy "git fetch --unshallow"
        why "not applied by --fix: unshallowing can transfer the entire history."
    fi
fi

# ------------------------------------------------- certification commits (b)

# Read every distinct bundle.git_revision out of the evidence file. jq is a
# hard prerequisite of the sandbox scripts and python3 of the shell helpers, so
# either is fine; preferring jq keeps the dependency the same one every other
# script in the repo already has.
cert_revisions() {
    if have jq; then
        jq -r '.records[].bundle.git_revision' manifests/capabilities/evidence.json | sort -u
    elif have python3; then
        python3 - <<'PY'
import json
with open('manifests/capabilities/evidence.json') as handle:
    doc = json.load(handle)
seen = set()
for record in doc.get('records', {}).values():
    revision = record.get('bundle', {}).get('git_revision')
    if isinstance(revision, str) and revision:
        seen.add(revision)
for revision in sorted(seen):
    print(revision)
PY
    else
        return 1
    fi
}

if [ "$git_ok" = 1 ]; then
    if [ ! -f manifests/capabilities/evidence.json ]; then
        fail "manifests/capabilities/evidence.json is missing -- this is not a duo-wp checkout"
        remedy "re-clone the repository"
    else
        revs=''
        if ! revs="$(cert_revisions 2>/dev/null)"; then
            warn "cannot read certification revisions (needs jq or python3)"
            remedy "brew install jq   # or: apt-get install jq"
            revs=''
        fi
        if [ -n "$revs" ]; then
            missing=''
            for rev in $revs; do
                if [ "$(git cat-file -t "$rev" 2>/dev/null || true)" != "commit" ]; then
                    missing="$missing $rev"
                fi
            done
            if [ -n "$missing" ] && [ "$FIX" = 1 ]; then
                for rev in $missing; do
                    fixing "git fetch origin $rev"
                    if git fetch --quiet origin "$rev" 2>/dev/null; then
                        # This is the last command of the then-branch: under
                        # `set -e` a stale ref lock or D/F conflict here would
                        # otherwise abort the whole diagnosis rather than just
                        # leaving this one revision unpinned.
                        git update-ref "refs/duo-cert/$rev" "$rev" \
                            || why "could not pin refs/duo-cert/$rev (stale lock or ref conflict?)"
                    fi
                done
                missing=''
                for rev in $revs; do
                    if [ "$(git cat-file -t "$rev" 2>/dev/null || true)" != "commit" ]; then
                        missing="$missing $rev"
                    fi
                done
            fi
            if [ -z "$missing" ]; then
                ok "every certification commit resolves ($(printf '%s\n' $revs | wc -l | tr -d ' ') distinct revision(s) over $( { jq -r '.records | keys[]' manifests/capabilities/evidence.json 2>/dev/null || true; } | wc -l | tr -d ' ') subject record(s))"
                why "release-gate needs them because ScopedCertificationBundle::assertGitRevisionInputs()"
                why "replays 'git cat-file -t <sha>' and 'git ls-tree -r <sha>' locally to prove each"
                why "recorded closure byte existed at that commit."
            else
                for rev in $missing; do
                    fail "certification commit not in this object database: $rev"
                done
                why "these are squash-discarded PR heads: no branch points at them, so a plain"
                why "'git fetch' never retrieves them. GitHub still serves them by SHA."
                for rev in $missing; do
                    remedy "git fetch origin $rev && git update-ref refs/duo-cert/$rev $rev"
                done
                [ "$FIX" = 1 ] && why "--fix attempted the fetch above and it did not succeed (offline? no 'origin'?)."
            fi
        fi
    fi
fi

# ------------------------------------------------------------- release gate

section "release gate"

if have php; then
    gate_rc=0
    gate_out="$(php scripts/capability-registry.php check 2>&1)" || gate_rc=$?
    if [ "$gate_rc" = 0 ]; then
        ok "make release-gate: $(printf '%s' "$gate_out" | head -1)"
    else
        fail "make release-gate (exit $gate_rc)"
        printf '%s\n' "$gate_out" | sed 's/^/      /'
        remedy "read the message above; a closure-digest mismatch means a certification round is due (docs/dev-setup.md)"
    fi
else
    fail "php is not on PATH -- release-gate and every offline suite need it"
    remedy "install PHP 8.3 (brew install php@8.3 / apt-get install php8.3-cli)"
fi

# ------------------------------------------------------------- php runtime

section "php runtime"

if have php; then
    # Guarded: a `php` that exists but exits non-zero at startup (broken
    # auto_prepend_file, a fatal in an ini-loaded extension) would otherwise
    # abort doctor here under `set -e`, skipping every later section and the
    # final tally -- the opposite of what a tool for diagnosing broken
    # environments should do on the one input it cannot assume is healthy.
    php_version="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo unknown)"
    if ! php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
        fail "PHP $php_version is below the 8.3.0 floor"
        remedy "install PHP 8.3 (brew install php@8.3 / apt-get install php8.3-cli)"
    elif php -r 'exit(version_compare(PHP_VERSION, "8.4.0", "<") ? 0 : 1);'; then
        ok "PHP $php_version (inside the certified 8.3.0-<8.4 range)"
    else
        warn "PHP $php_version is outside the certified range"
        why "certified floor is 8.3.0-<8.4 per docs/compatibility-baseline.json; local 8.4/8.5"
        why "is forward coverage, not the pinned target, and the diagnostics guard fails on"
        why "any Deprecated line a newer engine emits."
        remedy "brew install php@8.3 && export PATH=\"\$(brew --prefix php@8.3)/bin:\$PATH\"   # to reproduce the certified engine"
    fi

    # Required extensions. Each is required because first-party code calls it,
    # not because it is conventionally present:
    #   sodium   -- sodium_crypto_sign_detached / _verify_detached / _publickey_
    #               from_secretkey / sodium_memzero in agent/src/
    #               AdapterCertification.php, cli/src/RollbackAuthority.php and
    #               recovery/rollback-control.php (the whole rollback authority
    #               chain is Ed25519).
    #   json     -- canonical JSON is the wire format (recovery/CanonicalJson.php
    #               and every manifest reader).
    #   mbstring -- mb_strtolower() in agent/src/AdapterSources.php.
    for ext in json mbstring sodium; do
        if php -r "exit(extension_loaded('$ext') ? 0 : 1);"; then
            ok "php extension: $ext"
        else
            fail "php extension missing: $ext"
            remedy "install the php-$ext package for your PHP build and re-run"
        fi
    done
    # openssl is deliberately a WARN, not a FAIL: `grep -rE 'openssl_[a-z_]+\('
    # agent cli recovery scripts` is EMPTY -- no first-party code path uses it.
    # Composer's own downloads and any TLS-bearing tooling do, so a missing
    # openssl breaks setup but not the product's offline gate.
    if php -r "exit(extension_loaded('openssl') ? 0 : 1);"; then
        ok "php extension: openssl (used by composer/TLS; no first-party call site)"
    else
        warn "php extension missing: openssl"
        why "no agent/cli/recovery code calls openssl_*; composer's downloads do."
        remedy "install the php-openssl package for your PHP build"
    fi
fi

# ------------------------------------------------------------- host tooling

section "host tooling"

for tool in git jq python3 make; do
    if have "$tool"; then
        case "$tool" in
            git)     ver="$(git --version)" ;;
            jq)      ver="jq $(jq --version 2>&1 | sed 's/^jq-//')" ;;
            python3) ver="$(python3 --version 2>&1)" ;;
            # `|| true`: under `set -o pipefail`, `head -1` closing the pipe
            # early can SIGPIPE the left-hand command once its output exceeds
            # one line (GNU make's --version banner does), which would
            # otherwise abort the whole diagnosis under `set -e`.
            make)    ver="$(make --version 2>&1 | head -1 || true)" ;;
        esac
        ok "$tool: $ver"
        if [ "$tool" = make ]; then
            case "$ver" in
                *"3.8"*)
                    why "GNU Make 3.81 (the stock macOS build) has no --output-sync, so a raw"
                    why "'make -j' interleaves suite output into one unreadable stream. Use"
                    why "'php tools/offline.php -j8', which gives every suite its own log."
                    ;;
            esac
        fi
    else
        fail "$tool is not on PATH"
        case "$tool" in
            jq)      remedy "brew install jq          # every sandbox script parses JSON with it" ;;
            python3) remedy "install python3 3.9+     # shell-embedded helpers in sandbox/tests" ;;
            make)    remedy "install GNU make         # the offline corpus is a make target set" ;;
            git)     remedy "install git" ;;
        esac
    fi
done

# Both probes below are gated behind $RUN_PAIRS (--no-pairs clears it) rather
# than a separate flag: `docker info`/`docker compose version` dial the
# docker daemon socket and `gh auth status` makes a network call, so
# --no-pairs meaning "no host-daemon/network contact from doctor" covers both
# with the flag this script already has, and matches the sandbox pair-budget
# probe further down (which is silent, not a WARN, when skipped this way --
# the same convention is followed here). Each is still individually
# guard()-bounded so a slow/unreachable daemon cannot hang the run even when
# probed.
if [ "$RUN_PAIRS" = 1 ]; then
    if have docker; then
        if guard 20 docker compose version >/dev/null 2>&1; then
            ok "docker: $(docker --version 2>&1 | head -1); $(guard 20 docker compose version 2>&1 | head -1)"
        else
            warn "docker is present but 'docker compose version' failed (compose v2 plugin missing)"
            remedy "install the docker compose v2 plugin; the offline loop does not need it"
        fi
        if guard 20 docker info >/dev/null 2>&1; then
            ok "docker daemon reachable"
        else
            warn "docker daemon not reachable -- live sandbox pairs are unavailable"
            why "the entire offline gate (make regress-offline-all) runs without docker."
            remedy "start Docker Desktop / OrbStack, or work offline-only"
        fi
    else
        warn "docker not found -- live sandbox pairs and conformance sweeps are unavailable"
        why "the entire offline gate (make regress-offline-all) runs without docker."
        remedy "install Docker Desktop or OrbStack (only needed for live evidence)"
    fi

    if have gh; then
        if guard 15 gh auth status >/dev/null 2>&1; then
            ok "gh authenticated (PR close gate available)"
        else
            warn "gh is installed but not authenticated"
            remedy "gh auth login"
        fi
    else
        warn "gh not found -- needed only for the PR/close gate, not for local verification"
        remedy "brew install gh"
    fi
fi

# ------------------------------------------------- certification closure (f)

section "certification closure hygiene"

if [ "$git_ok" = 1 ]; then
    # Only "??" (untracked) and "!!" (ignored) status codes are the hazard
    # this check exists for -- a stray scratch/editor/.DS_Store file the
    # filesystem walk in subjectInputPaths() picks up with no gitignore
    # awareness. An ordinary tracked modification (" M", "D ", ...) is a
    # DIFFERENT and expected situation (see the tracked/WARN block below,
    # which is the normal case whenever this branch touches agent/ or cli/);
    # without this filter it was reported here too, as a FAIL telling the
    # developer to delete their own in-progress edit.
    raw="$(git status --porcelain --ignored --untracked-files=all -- agent cli sandbox/bin || true)"
    stray="$(printf '%s\n' "$raw" | grep -E '^(\?\?|!!) ' || true)"
    if [ -z "$stray" ]; then
        ok "agent/, cli/, sandbox/bin/ carry no untracked or ignored files"
    else
        fail "untracked/ignored files inside the certification closure trees:"
        printf '%s\n' "$stray" | sed 's/^/        /'
        why "the certification closure walks the filesystem: untracked/ignored files here"
        why "expire all 9 certifications and make deployed agents refuse commands"
        why "(ScopedCertificationBundle::assertRuntimeInputsCurrent during Policy::load)."
        remedy "remove or relocate each file above (scratch belongs in sandbox/tmp/), then re-run"
        why "not applied by --fix: deleting files is never safe to do unattended."
    fi

    tracked="$(git status --porcelain -- agent cli sandbox/bin Makefile || true)"
    if [ -z "$tracked" ]; then
        ok "agent/, cli/, sandbox/bin/, Makefile are byte-clean against HEAD"
    else
        warn "modified closure files (a certification round is due before merge):"
        printf '%s\n' "$tracked" | sed 's/^/        /'
        remedy "php tools/cert-impact.php    # names exactly which subjects expire"
    fi
fi

# ------------------------------------------------------------ dev toolchain

section "dev toolchain"

if [ ! -f vendor/autoload.php ] && [ "$FIX" = 1 ] && have composer; then
    fixing "composer install"
    composer install --no-interaction || true
fi

if [ -f vendor/autoload.php ]; then
    ok "vendor/autoload.php present"
    for bin in phpstan phpunit php-cs-fixer; do
        if [ -x "vendor/bin/$bin" ]; then
            ok "vendor/bin/$bin present"
        else
            warn "vendor/bin/$bin missing"
            remedy "composer install"
        fi
    done
else
    warn "vendor/ is not installed -- composer check, phpstan, phpunit unavailable"
    why "nothing under vendor/ ever ships: cli/src/Adopt.php tars exactly"
    why "'agent manifests recovery', so the dev toolchain cannot reach a managed site."
    remedy "composer install"
fi

# ------------------------------------------------------- scratch + sandbox

section "scratch and sandbox"

if [ -d sandbox/tmp ]; then
    ok "sandbox/tmp/ exists (gitignored scratch root for every tool here)"
else
    if [ "$FIX" = 1 ]; then
        fixing "mkdir -p sandbox/tmp"
        mkdir -p sandbox/tmp
        ok "sandbox/tmp/ created"
    else
        warn "sandbox/tmp/ is missing -- tools/offline.php and tools/affected.php cache there"
        remedy "mkdir -p sandbox/tmp"
    fi
fi

if [ "$RUN_PAIRS" = 1 ] && have docker && guard 20 docker info >/dev/null 2>&1; then
    pair_rc=0
    pair_out="$(guard 120 bash sandbox/bin/pair.sh list 2>&1)" || pair_rc=$?
    if [ "$pair_rc" = 0 ]; then
        ok "sandbox pair inventory (respect the budget: see docs/agents/linear-loop.md)"
        printf '%s\n' "$pair_out" | indent
    else
        warn "sandbox/bin/pair.sh list failed (exit $pair_rc) -- live pairs may be unusable"
        printf '%s\n' "$pair_out" | indent
        remedy "bash scripts/agent-bootstrap.sh   # verifies the full live prerequisite set"
    fi
elif [ "$RUN_PAIRS" = 1 ]; then
    warn "pair budget not probed (docker unavailable) -- offline work is unaffected"
    remedy "start docker, then: bash sandbox/bin/pair.sh list"
fi

# ------------------------------------------------------------- cheat sheet

section "the loop"

cat <<'TXT'
  composer check                    lint + phpstan + php-cs-fixer + phpunit (the dev gate)
  php tools/offline.php -j8         the whole offline corpus in parallel, one log per suite
  php tools/offline.php --changed   only the suites your diff can affect (iteration only)
  php tools/affected.php --explain  why each suite was selected
  php tools/cert-impact.php         which of the 9 certifications your diff expires
  make regress-offline-all          THE canonical merge gate -- unconditional (DUO-3285)
  make release-gate                 evidence, generated registry and product prose agree

  New here? docs/dev-setup.md, then docs/agents/linear-loop.md.
TXT

printf '\n%s--%s %sok %d%s  %sWARN %d%s  %sFAIL %d%s\n' \
    "$C_DIM" "$C_OFF" \
    "$C_OK" "$N_OK" "$C_OFF" \
    "$C_WARN" "$N_WARN" "$C_OFF" \
    "$C_FAIL" "$N_FAIL" "$C_OFF"

if [ "$N_FAIL" -gt 0 ]; then
    printf '%sdoctor: %d check(s) failed -- apply the remedies above%s\n' "$C_FAIL" "$N_FAIL" "$C_OFF" >&2
    exit 1
fi
exit 0
