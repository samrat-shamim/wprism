#!/usr/bin/env bash
# Spike B — merge (the required v0 exit criterion):
#   divergent edits in two environments merge via plain `git merge` on
#   canonical files; a genuine conflict surfaces as a git conflict; uncaptured
#   env drift shows in the plan and is preserved; both environments converge.
set -euo pipefail
cd "$(dirname "$0")/../.."
COMPOSE="docker compose -f docker-compose.yml"
wp_a() { $COMPOSE run --rm -T cli-a wp "$@"; }
wp_b() { $COMPOSE run --rm -T cli-b wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
GIT_A="git -C siterepo/a -c user.name=wprism-a -c user.email=a@example.test"
GIT_B="git -C siterepo/b -c user.name=wprism-b -c user.email=b@example.test"

[ -d siterepo/b/.git ] || fail "run spike A first (make spike-a)"

ABOUT_A=$(wp_a post list --post_type=page --name=about --field=ID)
ABOUT_B=$(wp_b post list --post_type=page --name=about --field=ID)
HELLO_B=$(wp_b post list --post_type=post --name=hello-wprism --field=ID)
TEAM_B=$(wp_b post list --post_type=page --name=team --field=ID)

say "branch edit-a: env A edits About"
$GIT_A checkout -qb edit-a main
wp_a post update "$ABOUT_A" --post_title='About (A-edit)' >/dev/null
wp_a wprism capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "A: retitle About" && $GIT_A push -q origin edit-a

say "branch edit-b: env B edits Hello WPrism AND About (conflicting)"
$GIT_B fetch -q origin && $GIT_B checkout -qb edit-b origin/main
wp_b post update "$HELLO_B" --post_title='Hello WPrism (B-edit)' >/dev/null
wp_b post update "$ABOUT_B" --post_title='About (B-edit)' >/dev/null
wp_b wprism capture --repo=/siterepo >/dev/null
$GIT_B add -A && $GIT_B commit -qm "B: retitle Hello WPrism + About" && $GIT_B push -q origin edit-b

say "env B makes an UNCAPTURED edit (drift)"
wp_b post update "$TEAM_B" --post_title='Team (B-local-drift)' >/dev/null

say "merge both branches in git"
$GIT_A checkout -q main
$GIT_A merge -q edit-a >/dev/null
set +e
$GIT_A fetch -q origin edit-b
$GIT_A merge origin/edit-b >/dev/null 2>&1
MERGE_RC=$?
set -e
[ "$MERGE_RC" -ne 0 ] || fail "expected a merge conflict on About, merge succeeded"
CONFLICTS=$($GIT_A status --porcelain | grep '^UU' || true)
echo "$CONFLICTS"
grep -q -- '--about.md' <<<"$CONFLICTS" || fail "conflict is not on the About entity file"
[ "$(echo "$CONFLICTS" | wc -l | tr -d ' ')" = "1" ] || fail "expected exactly one conflicted entity"
grep -q '<<<<<<<' siterepo/a/state/posts/page/*--about.md || fail "no conflict markers in About file"
pass "conflict surfaced as a plain git conflict, scoped to the About entity; Hello WPrism merged clean"

say "resolve the conflict (editorial decision: merged title)"
ABOUT_FILE=$(ls siterepo/a/state/posts/page/*--about.md)
git -C siterepo/a checkout --theirs -- "state/posts/page/$(basename "$ABOUT_FILE")"
sed -i.bak 's/About (B-edit)/About (merged)/' "$ABOUT_FILE" && rm -f "$ABOUT_FILE.bak"
$GIT_A add -A && $GIT_A commit -qm "merge edit-b (About resolved: merged title)" && $GIT_A push -q origin main

say "apply merged main to env A"
$GIT_A pull -q origin main
wp_a wprism apply --repo=/siterepo --default-author=admin >/dev/null
[ "$(wp_a post get "$ABOUT_A" --field=post_title)" = "About (merged)" ] || fail "A: About title not merged"
HELLO_A=$(wp_a post list --post_type=post --name=hello-wprism --field=ID)
[ "$(wp_a post get "$HELLO_A" --field=post_title)" = "Hello WPrism (B-edit)" ] || fail "A: B's Hello edit did not arrive"
pass "env A converged to merged state"

say "apply merged main to env B — drift must surface and be preserved"
$GIT_B checkout -q main 2>/dev/null || $GIT_B checkout -qb main origin/main
$GIT_B pull -q origin main
PLAN=$(wp_b wprism plan --repo=/siterepo --json | tail -1)
echo "$PLAN" | jq -e '.drift | length == 1' >/dev/null || fail "expected exactly one drift entity in B's plan"
echo "$PLAN" | jq -r '.drift[0].path' | grep -q -- '--team.md' || fail "drift is not the Team entity"
set +e
APPLY_OUTPUT=$(wp_b wprism apply --repo=/siterepo --default-author=admin 2>&1)
APPLY_RC=$?
set -e
echo "$APPLY_OUTPUT"
[ "$APPLY_RC" -ne 0 ] || fail "apply unexpectedly promoted metadata despite drift"
grep -qi 'post-apply convergence verification failed' <<<"$APPLY_OUTPUT" || fail "apply did not fail closed on drift"
grep -qi 'canonical hash mismatch' <<<"$APPLY_OUTPUT" || fail "apply did not identify the drift mismatch"
[ "$(wp_b post get "$ABOUT_B" --field=post_title)" = "About (merged)" ] || fail "B: About title not merged"
[ "$(wp_b post get "$TEAM_B" --field=post_title)" = "Team (B-local-drift)" ] || fail "B: local drift was clobbered"
pass "B converged on merged entities; uncaptured local edit surfaced as drift and was preserved"

say "capture-first: fold B's drift into the repo, propagate to A"
wp_b wprism capture --repo=/siterepo >/dev/null
$GIT_B add -A && $GIT_B commit -qm "B: capture local Team edit" && $GIT_B push -q origin main
$GIT_A pull -q origin main
wp_a wprism apply --repo=/siterepo --default-author=admin >/dev/null
TEAM_A=$(wp_a post list --post_type=page --name=team --field=ID)
[ "$(wp_a post get "$TEAM_A" --field=post_title)" = "Team (B-local-drift)" ] || fail "A: Team drift did not propagate after capture"

say "final convergence: canonical(A) == canonical(B)"
wp_a wprism capture --repo=/siterepo --out=/siterepo/.tmp-final >/dev/null
wp_b wprism capture --repo=/siterepo --out=/siterepo/.tmp-final >/dev/null
diff -r siterepo/a/.tmp-final siterepo/b/.tmp-final || fail "environments did not converge"
rm -rf siterepo/a/.tmp-final siterepo/b/.tmp-final
pass "environments byte-identical"

printf '\n\033[1;32m✔ SPIKE B PASSED\033[0m\n'
