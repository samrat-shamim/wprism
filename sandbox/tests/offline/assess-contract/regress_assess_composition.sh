#!/usr/bin/env bash
# Regression — round-3 MUP §2.1, §4.1, §4.6: `wprism assess <env>` composes the
# whole read-only assessment in one fixed order, refuses (rather than
# partially succeeding) when the target is unreachable or unsupported, and
# returns the distinct readiness exit 3 when a complete report is red.
#
# This drives the real `php cli/wprism` over a `local` transport with a fake
# `wp` on PATH, not the command class in isolation. Three of the things
# under test live outside `AssessCommand` — the verb reaching the dispatch
# match arm, `EnvironmentCommandPreflight::ENVIRONMENT_VERBS` admitting it,
# and `DriverCapabilityReport::requirements()` knowing the operation — and a
# suite that constructed the command by hand would pass with all three
# broken. That is exactly how `wprism scope` shipped broken (issue #3344).
#
# Also carries the engine-adapter grep gate for the host side: no plugin
# slug may appear in cli/src/Assess, cli/src/Contract or agent/src/Assess.
#
# Offline: no docker, no WordPress, no network, no target.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-assess-composition.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
check() { if [ "$1" = 0 ]; then pass "$2"; else fail "$2"; fi; }

# assert_contains <haystack-file> <needle> <message>
assert_contains() {
  if grep -Fq -- "$2" "$1"; then pass "$3"; else
    fail "$3 (missing: $2)"
    sed -n '1,20p' "$1" >&2
  fi
}

assert_absent() {
  if grep -Fq -- "$2" "$1"; then fail "$3 (unexpectedly present: $2)"; else pass "$3"; fi
}

php "$ROOT/sandbox/tests/fixtures/assess/make-fixture.php" "$TMP/site" >/dev/null \
  || { echo "FAIL: could not build the assess fixture" >&2; exit 1; }

export WPRISM_FIXTURES="$TMP/site/fixtures"
export WPRISM_SITE_REPO="$TMP/site/repo"
PATH="$TMP/site/bin:$PATH"
export PATH

# run_assess <calls-file> <stdout-file> <stderr-file> [args...] -> exit code
run_assess() {
  local calls="$1" out="$2" err="$3"; shift 3
  : > "$calls"
  WPRISM_CALLS="$calls" \
    php "$ROOT/cli/wprism" --envs-file="$TMP/site/envs.json" "$@" \
    > "$out" 2> "$err"
}

say() { printf '\n== %s ==\n' "$*"; }

# ---------------------------------------------------------------- composition
say 'composition order'
( cd "$TMP/site/repo" && run_assess "$TMP/calls.txt" "$TMP/out.json" "$TMP/err.txt" \
    assess fixture --format=json )
STATUS=$?
check "$([ "$STATUS" = 3 ] && echo 0 || echo 1)" "a complete red assessment exits 3 (got $STATUS)"
[ -s "$TMP/err.txt" ] && { fail "assess wrote to stderr on success"; cat "$TMP/err.txt" >&2; } \
  || pass 'a successful assessment writes nothing to stderr'

# MUP §2.1's order, read off the calls the fake `wp` recorded. Doctor's
# checks come first (they gate everything), then the read-only init probe,
# then the inventory, then one capabilities call per DISTINCT registry
# operation — four for all six product operations, never six.
# Doctor is TWO wp calls here, not four (issue #3511): `core is-installed`, then
# one composed eval carrying agent presence, DISALLOW_FILE_MODS and the
# PHP/database/WordPress facts. The mapping below names that composed snippet
# by all three facts, so a re-split stops mapping to `doctor` and the order
# assertion fails instead of quietly absorbing the extra round trips.
# The bootstrap rule runs FIRST and on the raw line: the eligibility probe
# wraps its own `core is-installed` in an isolated control-plane --exec, so a
# doctor rule applied first would swallow it.
ORDER=$(sed -e 's/.*bootstrap eligibility.*/bootstrap/' "$TMP/calls.txt" \
  | sed -e 's/.*--path=[^ ]* //' \
  | sed -e 's/^\(core is-installed\).*/doctor/' \
        -e 's/^eval.*class_exists.*DISALLOW_FILE_MODS.*db_server_info.*/doctor/' \
        -e 's/^wprism init .*/init-probe/' \
        -e 's/^wprism assess-inventory .*/assess-inventory/' \
        -e 's/^wprism capabilities .*--operation=\([a-z]*\).*/capabilities:\1/' \
  | uniq)
EXPECTED=$'doctor\nbootstrap\ninit-probe\nassess-inventory\ncapabilities:capture\ncapabilities:delete\ncapabilities:plan\ncapabilities:promote'
if [ "$ORDER" = "$EXPECTED" ]; then
  pass 'assess composes doctor -> bootstrap probe -> init probe -> inventory -> one capabilities call per registry operation'
else
  fail "composition order changed; got:"; printf '%s\n' "$ORDER" >&2
fi

CAP_PREVIEW=$(grep -c 'wprism capabilities .*--adoption-preview' "$TMP/calls.txt")
CAP_CALLS=$(grep -c 'wprism capabilities ' "$TMP/calls.txt")
check "$([ "$CAP_PREVIEW" = "$CAP_CALLS" ] && echo 0 || echo 1)" \
  'every capabilities read carries --adoption-preview, so a seed is answered against the same policy the inventory was projected against'
check "$([ "$CAP_CALLS" = 4 ] && echo 0 || echo 1)" \
  "six product operations collapse to four registry operations (got $CAP_CALLS calls)"

assert_contains "$TMP/calls.txt" 'wprism assess-inventory --repo=' \
  'the inventory is read with the TARGET repo path, not the local one'
assert_absent "$TMP/calls.txt" 'wprism coverage' \
  'coverage is not a separate call: the agent composes it into the inventory'
assert_absent "$TMP/calls.txt" 'wprism pending' \
  'pending is not a separate call: the agent composes it into the inventory'

# ------------------------------------------------------------------- document
say 'the assess report document'
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$fail = static function (string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); };
if (!is_array($d)) { $fail("assess --format=json did not emit a JSON object"); }
$keys = array_keys($d); sort($keys);
$want = ["assess_digest","authority","dispositions","env","evidence","format","generated_at","surfaces","target","unknown"];
if ($keys !== $want) { $fail("top-level key set moved: " . implode(",", $keys)); }
if ($d["format"] !== "wprism-assess-report/v1") { $fail("wrong format key"); }
if ($d["env"] !== "fixture") { $fail("the report does not name its environment"); }
if (($d["target"]["site_mode"] ?? null) !== "single-site") { $fail("target block is not the inventory verbatim"); }
if (($d["authority"]["access"] ?? null) !== "read-only for this command") { $fail("authority does not state its access"); }
if (($d["authority"]["init_probe"]["reason_code"] ?? null) !== "repository_owned") {
    $fail("the read-only init probe refusal was not recorded as a fact");
}
if (($d["authority"]["bootstrap"]["probed"] ?? null) !== true) { $fail("the bootstrap probe did not run"); }
$rows = [];
foreach ($d["surfaces"] as $row) { $rows[$row["id"]] = $row; }
$expect = [
  // id => [state_class, handling, release readiness, containment, recovery, next action]
  "post_type:page"                => ["authored","manage","Ready","prevented","provider-state restorable"],
  "table:sample_ledger"           => ["runtime","preserve local","Unsupported","prevented","not applicable"],
  "table:sample_lookup"           => ["derived","rebuild","Unsupported","unknown","not applicable"],
  "option_group:sample-adapter:env" => ["environment-bound","rebind","Ready with conditions","unknown","not applicable"],
  "option_group:core:managed"     => ["authored","block","Ready","unknown","unknown"],
  "table:sample_log"              => ["unclassified","block","Not qualified","unknown","unknown"],
  // T6 SS3.6 new row kind. An ACTIVE plugin no pinned manifest declares
  // projects with no claim at all, which is the honest reading of: WPrism has no
  // authority over any state this plugin owns.
  // (No apostrophes in this block -- it lives inside a shell single-quoted
  // php -r script, where one would close the quote.)
  "plugin:unmanaged-widget"       => ["unclassified","block","Not qualified","unknown","unknown"],
];
foreach ($expect as $id => [$class, $handling, $readiness, $containment, $recovery]) {
    if (!isset($rows[$id])) { $fail("surface row $id is missing from the catalog"); }
    $row = $rows[$id]; $p = $row["operations"]["release"];
    if ($row["state_class"] !== $class) { $fail("$id state_class is {$row["state_class"]}, expected $class"); }
    if ($row["handling"] !== $handling) { $fail("$id handling is {$row["handling"]}, expected $handling"); }
    if ($p["readiness"] !== $readiness) { $fail("$id release readiness is {$p["readiness"]}, expected $readiness"); }
    if ($p["effect_containment"] !== $containment) { $fail("$id containment is {$p["effect_containment"]}"); }
    if ($p["effect_recovery_semantics"] !== $recovery) { $fail("$id recovery is {$p["effect_recovery_semantics"]}"); }
}
// §1.5: the only structurally provable containment carries its literal basis.
foreach ($d["surfaces"] as $row) {
    foreach ($row["operations"] as $operation => $p) {
        $basis = $p["effect_containment_basis"];
        $want = $p["effect_containment"] === "prevented"
            ? "no WordPress hooks fire in the apply window"
            : "unknown — not enforced in this profile";
        if ($basis !== $want) { $fail("{$row["id"]}/$operation carries the wrong containment basis"); }
        // T6 §3.2 removed `Site-certified` from this list: it is EARNED now,
        // by a verified certificate. The two that remain are the ones this
        // profile still structurally cannot prove — there is no egress
        // control behind `sandboxed`, and no declared compensation action
        // behind `compensatable`.
        foreach (["sandboxed","compensatable"] as $never) {
            if (in_array($never, [$p["certification_provenance"], $p["effect_containment"],
                                  $p["effect_recovery_semantics"]], true)) {
                $fail("$never was emitted, and this profile can never earn it");
            }
        }
        // This fixture installs no certificate, so nothing in it may read
        // Site-certified either — the word must come from evidence, never
        // from an adapter merely being site-sourced.
        if ($p["certification_provenance"] === "Site-certified") {
            $fail("{$row["id"]}/$operation reads Site-certified with no certificate installed");
        }
    }
}
// §1.6 consequence: an undeclared code lifecycle window blocks a release and
// its next action is a contract declaration, not a release action.
$managed = $rows["option_group:core:managed"];
if ($managed["operations"]["release"]["handling"] !== "block") { $fail("the lifecycle window does not block a release"); }
if ($managed["operations"]["capture"]["handling"] === "block") { $fail("the lifecycle window blocks capture, which reaches nothing live"); }
if ($managed["next_action"] !== "declare in contract") { $fail("the lifecycle window next action is {$managed["next_action"]}"); }
if ($managed["decided_by"] !== "platform-default") { $fail("a classified surface is not platform-default"); }
if ($rows["table:sample_log"]["decided_by"] !== "unresolved") { $fail("an unclassified surface must be unresolved"); }
// The unknown block: names and counts, never values, and bounded.
if ($d["unknown"]["invisible_names_count"] !== 41) { $fail("invisible option count is not the true total"); }
if ($d["unknown"]["pending_count"] !== 3) { $fail("pending count is not the true total"); }
// T6 §3.7 item 2: the third finding MUP §2.1 item 3 always named. The names
// were already in names_sample; without the COUNT the human `unknown:` block
// had no table line and the next-actions roll-up had nothing to add.
if (!is_int($d["unknown"]["undeclared_tables_count"] ?? null)) {
    $fail("the undeclared-table count is not published");
}
if (count($d["unknown"]["names_sample"]) > 200) { $fail("the names sample is unbounded"); }
// T6 §3.6: an unclassified surface with no probable owning plugin is a
// classification rule away. `qualify in rehearsal` is retired from the
// emitted set entirely, so nothing in the document may carry it.
if ($rows["table:sample_log"]["next_action"] !== "classify") {
    $fail("an unowned unclassified table names {$rows["table:sample_log"]["next_action"]}, expected classify");
}
// The plugin IS its own probable owner, so the answer is the adapter, never
// classification -- there is nothing to classify about a plugin.
if ($rows["plugin:unmanaged-widget"]["next_action"] !== "install adapter") {
    $fail("an unmanaged plugin names {$rows["plugin:unmanaged-widget"]["next_action"]}, expected install adapter");
}
if ($rows["plugin:unmanaged-widget"]["kind"] !== "plugin") {
    $fail("the unmanaged-plugin row does not carry kind plugin");
}
if ($rows["plugin:unmanaged-widget"]["operations"]["release"]["certification_provenance"] !== "Uncertified") {
    $fail("an unmanaged plugin must be Uncertified");
}
foreach ($d["surfaces"] as $row) {
    if (($row["next_action"] ?? null) === "qualify in rehearsal") {
        $fail("{$row["id"]} emits the retired `qualify in rehearsal`");
    }
    foreach ($row["operations"] as $operation => $p) {
        if (($p["gap_action"] ?? null) === "qualify in rehearsal") {
            $fail("{$row["id"]}/$operation emits the retired `qualify in rehearsal`");
        }
        foreach (["certification_principal","certification_trust_root","blockers","probable_owner"] as $k) {
            if (!array_key_exists($k, $p)) { $fail("{$row["id"]}/$operation is missing $k"); }
        }
    }
}
// The evidence pins, and the digest binding the whole document. Two facts and
// no third: the target reports the content address of the reviewed
// dispositions its verdict came from, and the host records its own copy of
// that file. There is no per-subject bundle list, so a count of pinned
// certification subjects would be a count of nothing.
$pinKeys = array_keys($d["evidence"]); sort($pinKeys);
if ($pinKeys !== ["generated_from","registry_sha256"]) {
    $fail("evidence pins " . implode(",", $pinKeys) . ", expected registry_sha256 and generated_from");
}
if (!is_string($d["evidence"]["registry_sha256"] ?? null) || $d["evidence"]["registry_sha256"] === "") {
    $fail("the target reported no reviewed-dispositions hash");
}
if (array_keys($d["evidence"]["generated_from"]) !== ["dispositions_sha256"]) {
    $fail("evidence provenance names " . implode(",", array_keys($d["evidence"]["generated_from"])) . ", expected dispositions_sha256 alone");
}
if (!is_string($d["evidence"]["generated_from"]["dispositions_sha256"] ?? null)) {
    $fail("evidence provenance dispositions_sha256 is missing");
}
// issue #3484: the two numbers assess holds from two machines, compared. The
// evidence pins keep exactly two keys above -- the block is copied verbatim
// into contract.evidence_pins, so the comparison had to live somewhere that
// is not a contract wire change.
$disp = $d["dispositions"] ?? null;
if (!is_array($disp)) { $fail("the report publishes no host/target dispositions comparison"); }
$dispKeys = array_keys($disp); sort($dispKeys);
if ($dispKeys !== ["agree","host_registry_sha256","meaning","target_registry_sha256"]) {
    $fail("the dispositions block carries " . implode(",", $dispKeys));
}
if ($disp["target_registry_sha256"] !== $d["evidence"]["registry_sha256"]) {
    $fail("the block restates a target hash the evidence pins do not");
}
// The fixture computes the target hash from THIS checkout package dispositions,
// exactly as ManifestDispositions::sha256() would on an adopted site, so the
// agreeing case is the real number and not a fixture convention.
if ($disp["agree"] !== true) { $fail("an unskewed fixture must report the two libraries agreeing"); }
if ($disp["host_registry_sha256"] !== $disp["target_registry_sha256"]) {
    $fail("agree is true beside two different hashes");
}
require_once $argv[2] . "/agent/src/Kernel/Canon.php";
require_once $argv[2] . "/agent/src/Policy/AdapterLibrary.php";
$library = \WPrism\AdapterLibrary::fromSourceTree($argv[2]);
$registry = ["format" => "wprism-manifest-dispositions/v1", "manifests" => [], "profiles" => []];
foreach ($library->packages() as $package) {
    $registry["manifests"][$package->name()] = json_decode(
        (string) file_get_contents($package->dispositionPath()),
        true
    );
}
$registry["profiles"] = json_decode((string) file_get_contents($library->profilesPath()), true);
ksort($registry["manifests"], SORT_STRING);
$onDisk = hash("sha256", \WPrism\Canon::encode($registry));
if ($disp["host_registry_sha256"] !== $onDisk) {
    $fail("the host half is not the content address of this checkout reviewed dispositions");
}
$stated = $d["assess_digest"]; unset($d["assess_digest"]);
require_once $argv[2] . "/agent/src/Kernel/Canon.php";
if ($stated !== "sha256:" . hash("sha256", \WPrism\Canon::encode($d))) { $fail("assess_digest does not bind its own document"); }
echo "ok: the report document validates, and every §1 projection matches\n";
' "$TMP/out.json" "$ROOT" || fail 'the assess report document is wrong'

# --------------------------------------------------------------- the proposal
say 'the proposed contract'
if [ -f "$TMP/site/repo/.wprism/contract/fixture/proposed.json" ]; then
  pass 'assess writes .wprism/contract/fixture/proposed.json into the LOCAL site repository'
else
  fail 'assess did not write the proposed contract'
fi
php -r '
$p = json_decode(file_get_contents($argv[1]), true);
$fail = static function (string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); };
if (($p["format"] ?? null) !== "wprism-application-contract-proposal/v1") { $fail("wrong proposal format"); }
if (($p["contract"]["attestation"]["state"] ?? null) !== "unsigned") { $fail("this profile writes only unsigned attestations"); }
$effects = $p["contract"]["declarations"]["external_effects"];
if (count($effects) !== 1 || $effects[0]["decided_by"] !== "unresolved") {
    $fail("the lifecycle effect must be proposed UNREVIEWED so accepting it unread is refused by the schema");
}
if (($p["review_required_count"] ?? 0) < 1) { $fail("a generated proposal with an unreviewed effect must require review"); }
if (!isset($p["contract"]["declarations"]["surface_labels"])) { $fail("the surface_labels map is missing"); }
echo "ok: the proposal is a proposal — unsigned, unreviewed where it must be, and never authority\n";
' "$TMP/site/repo/.wprism/contract/fixture/proposed.json" || fail 'the generated proposal is wrong'

# ---------------------------------------------------------------- human view
# The human view is a PROJECTION of the document above, never a second
# computation — so these are the exact lines an operator (and the T6 adapter
# walk) reads, asserted against the same run.
say 'the human view'
( cd "$TMP/site/repo" && run_assess "$TMP/callsh.txt" "$TMP/outh.txt" "$TMP/errh.txt" \
    assess fixture )
STATUS=$?
check "$([ "$STATUS" = 3 ] && echo 0 || echo 1)" "the red human view exits 3 (got $STATUS)"

# T6 §3.7 item 2: the `unknown:` block's third line. Its absence was the
# difference between an operator seeing "WPrism cannot see this part of your
# database" and seeing nothing at all.
assert_contains "$TMP/outh.txt" 'undeclared table(s) (no installed adapter declares them)' \
  'the unknown block counts undeclared tables'
assert_contains "$TMP/outh.txt" 'option name(s) invisible to every installed adapter' \
  'beside the invisible options it always counted'

# T6 §3.6: every action in the closed set prints with its count INCLUDING the
# zeroes, so the presence of a line is never the signal. `certify adapter` is
# the new one and it must print at zero here — this fixture installs no
# uncertified adapter.
assert_contains "$TMP/outh.txt" '  certify adapter' \
  'the new closed-set action prints in the roll-up, at its true count'
assert_contains "$TMP/outh.txt" '  install adapter' \
  'and so does install adapter'
assert_contains "$TMP/outh.txt" '  qualify in rehearsal' \
  'the retired word still PRINTS its count — the closed set is the same size for a reader'
# The ninth word. `attest contract` is in the closed set and emitted by nothing:
# the contract attestation signer ships, and the trust root
# (.wprism/contract/authorities.json) is absent on every site, so attesting is an
# organizational decision rather than a next action. It still prints its zero
# for the same reason `qualify in rehearsal` does two lines above — the count is
# the signal, so a line that appears only when non-zero would teach a reader to
# read presence instead of the number.
assert_contains "$TMP/outh.txt" '  attest contract' \
  'the unemitted attestation action prints in the roll-up, at its true count of zero'
NEXT_ACTION_LINES=$(sed -n '/^next actions:/,/^evidence:/p' "$TMP/outh.txt" | grep -cE '^ +[0-9]+  ')
check "$([ "$NEXT_ACTION_LINES" = 9 ] && echo 0 || echo 1)" \
  "the roll-up prints all nine closed-set actions (got $NEXT_ACTION_LINES)"

# The roll-up counts every finding EXACTLY once. An undeclared table is a
# surface row AND an unknown-section finding, so counting the coverage total on
# top of the rows would report one table twice — in the one section whose whole
# doctrine is that the count is the signal. The sum below is the arithmetic
# statement of that: surface rows + invisible options + pending, and nothing
# else, because every undeclared table here became a row.
ROLLUP_TOTAL=$(sed -n '/^next actions:/,/^evidence:/p' "$TMP/outh.txt" \
  | grep -oE '^ +[0-9]+' | tr -d ' ' | paste -sd+ - | bc)
EXPECTED_TOTAL=$(php -r '
$d = json_decode(file_get_contents($argv[1]), true);
echo count($d["surfaces"]) + $d["unknown"]["invisible_names_count"] + $d["unknown"]["pending_count"];
' "$TMP/out.json")
check "$([ "$ROLLUP_TOTAL" = "$EXPECTED_TOTAL" ] && echo 0 || echo 1)" \
  "the roll-up counts each finding exactly once (sum $ROLLUP_TOTAL, expected $EXPECTED_TOTAL)"

# Invisible names are absent from the pending/classify queue by definition.
# They must contribute to the adapter path, while only actual pending items
# and classified surface rows contribute to `classify`.
INVISIBLE_COUNT=$(php -r '$d=json_decode(file_get_contents($argv[1]), true); echo $d["unknown"]["invisible_names_count"];' "$TMP/out.json")
INSTALL_COUNT=$(sed -n '/^next actions:/,/^evidence:/p' "$TMP/outh.txt" | awk '/  install adapter$/ { print $1 }')
CLASSIFY_COUNT=$(sed -n '/^next actions:/,/^evidence:/p' "$TMP/outh.txt" | awk '/  classify$/ { print $1 }')
check "$([ "$INSTALL_COUNT" -ge "$INVISIBLE_COUNT" ] && echo 0 || echo 1)" \
  "invisible options contribute to install adapter ($INSTALL_COUNT for $INVISIBLE_COUNT invisible names)"
check "$([ "$CLASSIFY_COUNT" -lt "$INVISIBLE_COUNT" ] && echo 0 || echo 1)" \
  "invisible options do not inflate the dead-end classify action ($CLASSIFY_COUNT classify)"

# The retired word may print as a COUNTED zero and must never appear as a row's
# own next action, which is a different thing and the one that would be a lie.
assert_absent "$TMP/outh.txt" 'next action: qualify in rehearsal' \
  'no surface row names the retired action'

# T6 §3.6's row, as the operator reads it. The whole line is asserted because
# the walk greps for the id and because `plugin:<slug>` is the one surface id
# whose shape an operator has to recognise without being told.
assert_contains "$TMP/outh.txt" 'plugin:unmanaged-widget' \
  'an active plugin with no adapter appears as a surface row'
assert_contains "$TMP/outh.txt" 'next action: install adapter (capture, merge, release, verify, delete, recover)' \
  'and its next action is the adapter, for every operation'

# Nothing here is certified by a site key, so the evidence block says nothing
# about a principal. The positive case is regress_adapter_certify.php's.
assert_absent "$TMP/outh.txt" 'contract attestation unsigned' \
  'the site-certified line is absent when no certificate is installed'

# ------------------------------------------------ the mid-upgrade skew window
# issue #3484. An operator who has pulled a revision that edited
# manifests/dispositions.json is ahead of every site they have not re-adopted
# yet — docs/adoption.md's upgrade runbook runs for as long as that takes, and
# `wprism assess` is how they see the site during it. So the mismatch is LOUD and
# assess still completes; what it withholds is the one artifact that would
# bake the skew in. Minting is gated in AssessCommand::writeLocalArtifacts(),
# which is the only writeProposal() call in the tree.
say 'a host/target library mismatch is loud, and assess still answers'
PROPOSAL_BEFORE=$(cat "$TMP/site/repo/.wprism/contract/fixture/proposed.json")
( cd "$TMP/site/repo" && WPRISM_LIBRARY_SKEW=1 run_assess "$TMP/calls-skew.txt" "$TMP/skew.json" "$TMP/skew.err" \
    assess fixture --format=json )
STATUS=$?
check "$([ "$STATUS" = 3 ] && echo 0 || echo 1)" \
  "assessment under a library mismatch is complete but red, exit 3 (got $STATUS)"
[ -s "$TMP/skew.err" ] && { fail 'a mismatched assessment wrote to stderr'; cat "$TMP/skew.err" >&2; } \
  || pass 'the mismatch is a report section, not an error stream'
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$fail = static function (string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); };
$disp = $d["dispositions"] ?? null;
if (!is_array($disp)) { $fail("no dispositions block under skew"); }
if ($disp["agree"] !== false) { $fail("two different libraries reported as agreeing"); }
if ($disp["host_registry_sha256"] === $disp["target_registry_sha256"]) { $fail("the skew fixture did not skew"); }
if ($disp["target_registry_sha256"] !== $d["evidence"]["registry_sha256"]) {
    $fail("the block and the evidence pins name different target libraries");
}
if (strpos($disp["meaning"], "different reviewed library") === false) {
    $fail("the machine document does not say what the mismatch means");
}
// Both hashes are FULL in the machine view. The human bound is a rule about
// terminals, and a truncated hash in JSON would make the document unusable
// for the one thing it is for: comparing two libraries.
foreach (["host_registry_sha256", "target_registry_sha256"] as $k) {
    if (preg_match("/^[a-f0-9]{64}$/D", (string) $disp[$k]) !== 1) { $fail("$k is not a full sha256 in the machine view"); }
}
echo "ok: the machine document states both hashes, the verdict and its meaning\n";
' "$TMP/skew.json" || fail 'the mismatch is not a first-class block in the machine document'

if [ "$PROPOSAL_BEFORE" = "$(cat "$TMP/site/repo/.wprism/contract/fixture/proposed.json")" ]; then
  pass 'assess minted no proposal from the mismatched assessment — the earlier one is untouched, not overwritten'
else
  fail 'a mismatched assessment rewrote .wprism/contract/fixture/proposed.json'
fi

( cd "$TMP/site/repo" && WPRISM_LIBRARY_SKEW=1 run_assess "$TMP/calls-skewh.txt" "$TMP/skew.txt" "$TMP/skewh.err" \
    assess fixture )
assert_contains "$TMP/skew.txt" 'MISMATCH: this checkout ships ' \
  'the human view names the mismatch in the evidence block'
assert_contains "$TMP/skew.txt" 'the target answered from a different reviewed library than this checkout ships' \
  'and prints the document own meaning sentence rather than a second wording of it'
assert_contains "$TMP/skew.txt" 'no proposed contract written' \
  'the human view says the proposal was withheld, where it would have claimed one was written'
assert_absent "$TMP/skew.txt" 'proposed contract written: .wprism/contract/fixture/proposed.json (accept' \
  'and never claims a proposal an operator could accept'
# MUP §5.2 again: the mismatch lines are the newest place a 64-hex digest
# could reach a terminal, and they print twelve.
if grep -qE '[0-9a-f]{32,}' "$TMP/skew.txt"; then
  fail 'the mismatch lines leak a full hash into the human view'
  grep -oE '[0-9a-f]{32,}' "$TMP/skew.txt" | head -3 >&2
else
  pass 'both hashes print as twelve-hex prefixes; the full ones stay in --format=json'
fi

# ------------------------------------------------------------------- refusals
say 'structured refusals'
( cd "$TMP/site/repo" && WPRISM_DOCTOR_FAIL=1 run_assess "$TMP/calls2.txt" "$TMP/out2.json" "$TMP/err2.txt" \
    assess fixture --format=json )
STATUS=$?
check "$([ "$STATUS" = 1 ] && echo 0 || echo 1)" "an unreachable target is a refusal, exit 1 (got $STATUS)"
assert_contains "$TMP/out2.json" '"format":"wprism-command-refusal/v1"' \
  'the refusal is the common machine envelope on stdout'
assert_contains "$TMP/out2.json" '"reason_code":"assess_target_unreachable"' \
  'the refusal names why the assessment could not run'
DOCTOR_CAPS=$(grep -c 'wprism capabilities ' "$TMP/calls2.txt" || true)
check "$([ "$DOCTOR_CAPS" = 0 ] && echo 0 || echo 1)" \
  'a failed doctor gate stops the composition rather than partially succeeding'

( cd "$TMP/site/repo" && WPRISM_MULTISITE=1 run_assess "$TMP/calls3.txt" "$TMP/out3.json" "$TMP/err3.txt" \
    assess fixture --format=json )
STATUS=$?
check "$([ "$STATUS" = 1 ] && echo 0 || echo 1)" "an unsupported topology is a refusal, exit 1 (got $STATUS)"
assert_contains "$TMP/out3.json" '"reason_code":"assess_topology_unsupported"' \
  'multisite refuses by name instead of producing a page of identical blockers'

# ------------------------------------------------------ the adoption preview
# T7 grind A3: on an adoption seed the agent projects the inventory against
# the init proposal and says so in an `adoption` block. The host carries it
# on `authority.adoption` (the block assess owns, deliberately not
# key-closed) and the human view says so on its own line, before any surface
# row — a reader must be able to tell a preview of adoption from a
# repository in force.
say 'the adoption preview travels through the report and the human view'
( cd "$TMP/site/repo" && WPRISM_ADOPTION_SEED=1 run_assess "$TMP/calls5.txt" "$TMP/out5.json" "$TMP/err5.txt" \
    assess fixture --format=json )
STATUS=$?
check "$([ "$STATUS" = 3 ] && echo 0 || echo 1)" "an adoption-seed assessment is complete but red (got $STATUS)"
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$a = $d["authority"]["adoption"] ?? null;
if (!is_array($a)) { fwrite(STDERR, "authority.adoption is absent\n"); exit(1); }
if (($a["preview"] ?? null) !== "init-proposal" || ($a["adapters"] ?? null) !== ["core", "fixture-shop"]) { fwrite(STDERR, "authority.adoption does not carry the agent block verbatim\n"); exit(1); }
if (($a["scope"]["left_local"] ?? null) !== ["post_type:fixture_log"]) { fwrite(STDERR, "left_local not carried\n"); exit(1); }
' "$TMP/out5.json"
check $? 'authority.adoption carries the agent block verbatim (preview, adapters, left-local types)'
( cd "$TMP/site/repo" && run_assess "$TMP/calls5b.txt" "$TMP/out5b.json" "$TMP/err5b.txt" assess fixture --format=json )
php -r '$d = json_decode(file_get_contents($argv[1]), true); exit(array_key_exists("adoption", $d["authority"] ?? []) && $d["authority"]["adoption"] === null ? 0 : 1);' "$TMP/out5b.json"
check $? 'an init-owned repository reports authority.adoption null'
( cd "$TMP/site/repo" && WPRISM_ADOPTION_SEED=1 run_assess "$TMP/calls6.txt" "$TMP/out6.txt" "$TMP/err6.txt" \
    assess fixture )
assert_contains "$TMP/out6.txt" 'adoption: this repository is an adoption seed — assessed as wprism init would propose it: adapters core, fixture-shop · left local: post_type:fixture_log · init advisories: 1 · init would refuse: 0 · init is ready' \
  'the human view names the preview, the adapters, the left-local types and the counts on one line'
LINE_ADOPTION=$(grep -n '^adoption:' "$TMP/out6.txt" | head -1 | cut -d: -f1)
LINE_FIRST_SURFACE=$(grep -nE '^(surface|[a-z_]+:[a-z_]+ )' "$TMP/out6.txt" | head -1 | cut -d: -f1)
check "$([ -n "$LINE_ADOPTION" ] && [ -n "$LINE_FIRST_SURFACE" ] && [ "$LINE_ADOPTION" -lt "$LINE_FIRST_SURFACE" ] && echo 0 || echo 1)" \
  'the adoption line comes before the first surface row'
( cd "$TMP/site/repo" && run_assess "$TMP/calls6b.txt" "$TMP/out6b.txt" "$TMP/err6b.txt" assess fixture )
if grep -q '^adoption:' "$TMP/out6b.txt"; then fail 'an init-owned repository prints an adoption line'; else pass 'an init-owned repository prints no adoption line'; fi

# A host-side preflight failure must also produce the envelope, or the first
# unresolvable environment becomes an unparseable line in a pipeline.
( cd "$TMP/site/repo" && run_assess "$TMP/calls4.txt" "$TMP/out4.json" "$TMP/err4.txt" \
    assess no-such-env --format=json )
STATUS=$?
check "$([ "$STATUS" = 1 ] && echo 0 || echo 1)" "an unknown environment refuses, exit 1 (got $STATUS)"
assert_contains "$TMP/out4.json" '"reason_code":"host_preflight_failed"' \
  'a host preflight failure emits the same refusal envelope'

# ------------------------------------------------------- blocked but bounded
say 'blocked surfaces produce a complete red assessment'
BLOCKED=$(php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$n = 0;
foreach ($d["surfaces"] as $row) { if ($row["handling"] === "block") { $n++; } }
echo $n;
' "$TMP/out.json")
check "$([ "$BLOCKED" -ge 2 ] && echo 0 || echo 1)" \
  "the complete run reported $BLOCKED blocked surface(s) and exited 3"

# ------------------------------------------------------- flag grammar closure
say 'flag grammar'
( cd "$TMP/site/repo" && run_assess "$TMP/calls5.txt" "$TMP/out5.json" "$TMP/err5.txt" \
    assess fixture --operation=release --format=json )
STATUS=$?
check "$([ "$STATUS" = 3 ] && echo 0 || echo 1)" "--operation narrows the red projection (exit $STATUS)"
NARROW_CAPS=$(grep -c 'wprism capabilities ' "$TMP/calls5.txt")
check "$([ "$NARROW_CAPS" = 1 ] && echo 0 || echo 1)" \
  "one product operation costs one registry call (got $NARROW_CAPS)"

( cd "$TMP/site/repo" && run_assess "$TMP/calls6.txt" "$TMP/out6.json" "$TMP/err6.txt" \
    assess fixture --operation=teleport --format=json )
STATUS=$?
check "$([ "$STATUS" = 1 ] && echo 0 || echo 1)" "an unknown operation refuses (exit $STATUS)"
assert_contains "$TMP/out6.json" '"reason_code":"invalid_arguments"' \
  'an operation outside the closed set is a typed refusal, never a silent default'

( cd "$TMP/site/repo" && run_assess "$TMP/calls7.txt" "$TMP/out7.json" "$TMP/err7.txt" \
    assess fixture --not-a-flag --format=json )
STATUS=$?
check "$([ "$STATUS" = 1 ] && echo 0 || echo 1)" "an undefined flag refuses (exit $STATUS)"

# ------------------------------------------------------------ the grep gate
say 'engine-adapter boundary'
# The forbidden set is DERIVED from the shipped manifest library, so a new
# adapter joins it automatically and this gate cannot rot into a hand list.
TOKENS=$(php -r '
$out = [];
require_once $argv[1] . "/agent/src/Policy/AdapterLibrary.php";
foreach (\WPrism\AdapterLibrary::fromSourceTree($argv[1])->packages() as $package) {
    $name = $package->name();
    if ($name === "core") { continue; }
    $manifest = json_decode((string) file_get_contents($package->manifestPath()), true);
    if (!is_array($manifest)) { continue; }
    $out[] = $name;
    foreach (["plugin", "theme"] as $key) {
        if (is_string($manifest[$key] ?? null) && $manifest[$key] !== "") { $out[] = $manifest[$key]; }
    }
}
$out = array_values(array_unique(array_filter($out, static fn (string $v): bool => strlen($v) > 3)));
sort($out);
echo implode("\n", $out);
' "$ROOT")
SCANNED=0
BOUNDARY_OK=1
for dir in cli/src/Assess cli/src/Contract agent/src/Assess; do
  [ -d "$ROOT/$dir" ] || continue
  while IFS= read -r file; do
    SCANNED=$((SCANNED + 1))
    while IFS= read -r token; do
      [ -n "$token" ] || continue
      if grep -Fqi -- "$token" "$file"; then
        fail "engine-adapter boundary: '$token' appears in $file"
        BOUNDARY_OK=0
      fi
    done <<< "$TOKENS"
  done < <(find "$ROOT/$dir" -name '*.php' -type f)
done
if [ "$SCANNED" -eq 0 ]; then
  fail 'the boundary gate scanned zero files, so it proved nothing'
elif [ "$BOUNDARY_OK" = 1 ]; then
  pass "no plugin slug appears in any of the $SCANNED engine-side assess/contract files"
fi

# The verb must also be reachable and documented through cli/wprism itself.
say 'cli/wprism wiring'
grep -Fq "'assess' => cmd_assess(\$transport, \$extra)" "$ROOT/cli/wprism" \
  && pass 'assess is registered in the dispatch match' \
  || fail 'assess is not registered in cli/wprism dispatch'
grep -Fq 'wprism assess <env>' "$ROOT/cli/wprism" \
  && pass 'assess appears in the public usage text' \
  || fail 'assess is missing from wprism_usage()'
php -r '
require $argv[1] . "/cli/src/Command/EnvironmentCommandPreflight.php";
require $argv[1] . "/cli/src/Transport/EnvironmentDriver.php";
$verbs = \WPrism\Orchestrator\EnvironmentCommandPreflight::environmentVerbs();
foreach (["assess", "contract"] as $verb) {
    if (!in_array($verb, $verbs, true)) { fwrite(STDERR, "FAIL: $verb is not an environment verb\n"); exit(1); }
    $method = new ReflectionMethod(\WPrism\Orchestrator\DriverCapabilityReport::class, "requirements");
    $method->invoke(null, $verb);
}
$method = new ReflectionMethod(\WPrism\Orchestrator\DriverCapabilityReport::class, "requirements");
if ($method->invoke(null, "assess") !== $method->invoke(null, "coverage")) {
    fwrite(STDERR, "FAIL: assess must demand exactly what the other read-only passthroughs demand\n"); exit(1);
}
if ($method->invoke(null, "contract") !== $method->invoke(null, "coverage")) {
    fwrite(STDERR, "FAIL: contract must demand exactly what the other read-only passthroughs demand\n"); exit(1);
}
echo "ok: both verbs are environment-bound and resolve through requirements()\n";
' "$ROOT" || fail 'the new verbs are not wired through the preflight and driver tables'

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_ASSESS_COMPOSITION FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_ASSESS_COMPOSITION PASSED\n'
