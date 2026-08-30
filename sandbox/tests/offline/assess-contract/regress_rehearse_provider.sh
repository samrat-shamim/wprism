#!/usr/bin/env bash
# Regression — round-3 MUP §2.2 / §4.4 / §6.2: `wprism rehearse`'s provider
# contract and its containment disclosure.
#
# Five properties, none of which needs a pair, docker, WordPress or a network:
#
#   1. CAPABILITY NEGOTIATION. A provider advertising a subset of what a
#      branch materialization needs makes the command refuse, naming the
#      missing capability id, before any source or target mutation. Driven
#      through `EnvironmentCommand::run()` — the verb boundary `RehearseCommand`
#      composes — with a fake direct-argv provider, the way
#      sandbox/tests/offline/environment/regress_environment_materializer.php drives the
#      materializer. NEVER EMULATION: the only provider action either side
#      sees is `capabilities`.
#
#   2. `--reap` IDEMPOTENCE AT THE PROVIDER CONTRACT LEVEL. A second reap of a
#      reaped identity returns the SAME receipt from absence evidence with no
#      second destructive provider call, and a reap whose provider identity
#      has changed refuses instead of reaping a reused resource — the exact
#      compare `EnvironmentMaterializer::reap()` enforces.
#
#   3. THE REFERENCE PROVIDER (tools/reference-env-provider.php). Its
#      capability negotiation runs through the REAL orchestrator client
#      (`CommandEnvironmentProvider`), which re-encodes and byte-compares
#      every response — so the byte-compatibility with the proven
#      sandbox/tests/fixtures/environment-materializer-live-provider.php shape is a gate, not a
#      claim. Its argument-validation paths run through the documented
#      `--print-plan` (alias `--dry-run`) mode, which names the external-command
#      boundary and executes none of it. The suite's plan section deliberately
#      never runs the provider against docker: this is an offline suite.
#
#   4. REUSABLE-SLOT SAFETY. The real provider entry point reuses one physical
#      target through generation-bound leases. Lost responses are retryable,
#      while stale prior-generation cleanup cannot clear the new occupant.
#      Its external-command boundary answers with the CHILD's exit status and
#      stderr, so a `docker exec -i` that refuses the dump on stdin is diagnosed
#      by what docker said, never by this provider's own broken pipe (issue #3492).
#
#   5. CONTAINMENT DISCLOSURE. `RehearsalDisclosure` refuses malformed provider
#      proof and emits a target-bound verified block. Its standalone legacy
#      preview renderer retains MUP §2.2's literal unknown banner, once, plus
#      the bounded preview of what a release would touch.
#
# Offline: no docker, no WordPress, no network, no target.
# Dependencies: sandbox/tests/fixtures/rehearse/make-provider-config.php
# Dependencies: sandbox/tests/fixtures/rehearse/provider-negotiation-checks.php
# sandbox/tests/fixtures/rehearse/provider-stdin-checks.php
# sandbox/tests/fixtures/rehearse/contained-provider-checks.php
# sandbox/tests/fixtures/rehearse/fake-contained-docker.php
# sandbox/tests/fixtures/rehearse/reference-provider-command-checks.php
# sandbox/tests/fixtures/rehearse/slot-reuse-checks.php
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/wprism-rehearse-provider.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT INT TERM

FAILURES=0
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; FAILURES=$((FAILURES + 1)); }
say()  { printf '\n== %s ==\n' "$*"; }

FIX="$ROOT/sandbox/tests/fixtures/rehearse"
PROVIDER="$ROOT/tools/reference-env-provider.php"
OP='20260817-090000-0000000000000000abcdefab'

# request <action> <environment> <input-json> -> one canonical provider request
request() {
  printf '{"action":"%s","environment":"%s","format":"wprism-branch-environment-provider-request/v1","input":%s,"operation_id":"%s"}\n' \
    "$1" "$2" "$3" "$OP"
}

# ------------------------------------------------ 1 + 2: the shipped lifecycle
say 'capability negotiation and --reap idempotence through EnvironmentCommand'
mkdir -p "$TMP/env"
if php "$FIX/env-command-checks.php" "$TMP/env" > "$TMP/env.out" 2> "$TMP/env.err"; then
  pass 'the EnvironmentCommand provider-contract checks pass'
else
  fail 'the EnvironmentCommand provider-contract checks failed'
fi
sed -n 's/^ok: /ok: /p' "$TMP/env.out"
if grep -q '^FAIL' "$TMP/env.err"; then
  sed -n 's/^FAIL/FAIL/p' "$TMP/env.err" >&2
fi

# The refusal text itself is written to STDERR by the command boundary. It has
# to NAME the capability, not merely fail: an operator who is told "provider
# cannot materialize" has nothing to fix.
for id in repository.materialize snapshot.set.read; do
  if grep -Fq "missing $id" "$TMP/env.err"; then
    pass "the negotiation refusal names the missing capability id '$id'"
  else
    fail "the negotiation refusal did not name '$id'"
  fi
done
if grep -Fq 'refusing stale or foreign resource mutation' "$TMP/env.err"; then
  pass 'a reap against a changed provider identity refuses with the exact-compare diagnostic'
else
  fail 'the stale-identity reap did not produce the exact-compare refusal'
fi

# --------------------------------------- 3a: the reference provider, negotiated
say 'the reference provider negotiates through the real orchestrator client'
mkdir -p "$TMP/provider"
if php "$FIX/provider-negotiation-checks.php" "$TMP/provider" > "$TMP/provider.out" 2> "$TMP/provider.err"; then
  pass 'the reference provider capability-negotiation checks pass'
else
  fail 'the reference provider capability-negotiation checks failed'
  cat "$TMP/provider.err" >&2
fi
sed -n 's/^ok: /ok: /p' "$TMP/provider.out"

say 'the reference provider contained-preview mode'
mkdir -p "$TMP/contained-provider"
if php "$FIX/contained-provider-checks.php" "$TMP/contained-provider" > "$TMP/contained-provider.out" 2> "$TMP/contained-provider.err"; then
  pass 'the contained-preview provider checks pass'
else
  fail 'the contained-preview provider checks failed'
  cat "$TMP/contained-provider.err" >&2
fi
sed -n 's/^ok: /ok: /p' "$TMP/contained-provider.out"

# --------------------------------------- 3b: the reference provider, --print-plan
say 'the reference provider --print-plan mode'
php "$FIX/make-provider-config.php" "$TMP/config.json" \
  || { echo "FAIL: could not write the reference provider config" >&2; exit 1; }
php "$FIX/make-provider-config.php" "$TMP/config-subset.json" 'environment.create,environment.destroy' \
  || { echo "FAIL: could not write the subset provider config" >&2; exit 1; }
mkdir -p "$TMP/plan-state" "$TMP/plan-state-subset"
php -r '
foreach ([[$argv[1], $argv[2]], [$argv[3], $argv[4]]] as [$path, $state]) {
    $config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $config["state_root"] = $state;
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
' "$TMP/config.json" "$TMP/plan-state" "$TMP/config-subset.json" "$TMP/plan-state-subset" \
  || { echo "FAIL: could not isolate the reference provider plan state" >&2; exit 1; }

# plan <config> <action> <environment> <input> -> exit code; stdout/stderr saved
plan() {
  request "$2" "$3" "$4" | php "$PROVIDER" --print-plan "$1" > "$TMP/plan.json" 2> "$TMP/plan.err"
}

plan "$TMP/config.json" attach mup2 '{"mode":"attach"}'
STATUS=$?
if [ "$STATUS" = 0 ]; then
  pass '--print-plan accepts a well-formed attach request'
else
  fail "--print-plan rejected a well-formed attach request (exit $STATUS): $(cat "$TMP/plan.err")"
fi

php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$fail = static function (string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); };
if (($d["format"] ?? null) !== "wprism-reference-env-provider-plan/v1") $fail("the dry run is not a plan document");
if (($d["executed"] ?? null) !== false) $fail("a dry run must state that it executed nothing");
if (($d["url_source"] ?? null) !== "config") $fail("a dry run must say its URL came from the config, not the port map");
if (($d["provider"]["protocol"] ?? null) !== 1) $fail("the plan does not pin protocol 1");
$argv0 = $d["commands"][0]["argv"] ?? [];
if ($argv0 !== ["docker", "port", "wprism-mup-wp2-1", "80/tcp"] || count($d["commands"] ?? []) !== 1) {
    $fail("attach does not limit itself to a physical-presence proof: " . json_encode($d["commands"] ?? []));
}
if (($d["capabilities_required"] ?? []) !== ["environment.attach", "environment.url.discover", "operation.receipts"]) {
    $fail("attach does not require exactly its own capability plus url discovery and receipts");
}
echo "ok: attach plans only `docker port` and requires environment.attach + environment.url.discover + operation.receipts\n";
' "$TMP/plan.json" || fail 'the attach plan document is wrong'

# `capabilities` is the one action that needs neither pair nor state.
plan "$TMP/config.json" capabilities mup2 '[]'
php -r '
$d = json_decode(file_get_contents($argv[1]), true);
$fail = static function (string $m): void { fwrite(STDERR, "FAIL: $m\n"); exit(1); };
if (($d["state_dependent"] ?? null) !== false) $fail("the capabilities probe must not be state dependent");
if (($d["identity_authoritative"] ?? null) !== false) $fail("a state-free target capabilities plan must label its displayed identity non-authoritative");
if (count($d["capabilities_advertised"] ?? []) !== 19) $fail("the provider does not advertise the whole protocol");
foreach (["snapshot.set.prepare","snapshot.set.create","snapshot.set.read","snapshot.set.abort","snapshot.set.restore",
          "environment.attach","environment.create","repository.materialize","environment.url.discover","operation.receipts"] as $id) {
    if (!in_array($id, $d["capabilities_advertised"], true)) $fail("MUP §2.2 requires '\''$id'\'' and the reference provider does not advertise it");
}
echo "ok: the reference provider advertises every capability MUP §2.2 names for a rehearsal\n";
' "$TMP/plan.json" || fail 'the capabilities plan document is wrong'

# Never emulation, at the provider's own boundary.
plan "$TMP/config-subset.json" create mup2 '{"mode":"create"}'
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq 'environment.create' "$TMP/plan.err" \
   && grep -Fq 'refusing rather than emulating it' "$TMP/plan.err"; then
  pass 'a withheld environment.create refuses by id rather than being served as an attach'
else
  fail "a withheld environment.create did not refuse by id (exit $STATUS): $(cat "$TMP/plan.err")"
fi

# Argument validation, one case per shape the provider must not accept.
validate() {
  local label="$1" action="$2" env="$3" input="$4" needle="$5"
  plan "$TMP/config.json" "$action" "$env" "$input"
  local status=$?
  if [ "$status" = 1 ] && grep -Fq "$needle" "$TMP/plan.err"; then
    pass "$label"
  else
    fail "$label (exit $status): $(cat "$TMP/plan.err")"
  fi
}
validate 'an acquisition whose mode contradicts its action refuses' \
  attach mup2 '{"mode":"create"}' 'target acquisition mode is malformed'
validate 'a repository materialization without a 40-hex commit refuses' \
  repository-materialize mup2 '{"branch_commit":"HEAD"}' 'repository materialization commit is invalid'
validate 'a reap without compare-and-reap intent refuses' \
  detach mup2 '{}' 'reap lacks compare-and-reap intent'
validate 'a snapshot prepared against a target rather than the source refuses' \
  snapshot-prepare mup2 '{"snapshot_session_id":"session-0001"}' "requires the configured source role"
validate 'an unknown environment refuses' \
  attach mup9 '{"mode":"attach"}' "unknown reference environment 'mup9'"
validate 'an unknown action refuses' \
  not-an-action mup2 '{}' "unsupported reference provider action 'not-an-action'"
validate 'a TTL below the protocol floor refuses' \
  ttl-set mup2 '{"ttl_seconds":5}' 'TTL set is invalid'

# A malformed protocol envelope is refused with the same boundary
# CommandEnvironmentProvider enforces.
printf '{"action":"attach","environment":"mup2","format":"wprism-branch-environment-provider-request/v0","input":{"mode":"attach"},"operation_id":"%s"}\n' "$OP" \
  | php "$PROVIDER" --print-plan "$TMP/config.json" > /dev/null 2> "$TMP/plan.err"
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq 'invalid protocol shape' "$TMP/plan.err"; then
  pass 'a request naming another protocol version refuses'
else
  fail "a foreign protocol version was accepted (exit $STATUS)"
fi

printf '{"action":"attach","environment":"mup2","format":"wprism-branch-environment-provider-request/v1","input":["mode"],"operation_id":"%s"}\n' "$OP" \
  | php "$PROVIDER" --print-plan "$TMP/config.json" > /dev/null 2> "$TMP/plan.err"
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq 'invalid protocol shape' "$TMP/plan.err"; then
  pass 'a non-empty list input refuses, exactly as CommandEnvironmentProvider does'
else
  fail "a list-shaped input was accepted (exit $STATUS)"
fi

# A plan without provider state must not fabricate a generation-bound target
# identity. The reusable-slot check below acquires a real lease and proves that
# the same plan validates it.
plan "$TMP/config.json" capabilities mup2 '[]'
IDENTITY="$(php -r '
$i = json_decode(file_get_contents($argv[1]), true)["identity"];
echo json_encode([
  "expected_environment_identity" => $i["environment_identity"],
  "expected_lease_generation" => $i["lease_generation"],
  "expected_lease_id" => $i["lease_id"],
  "expected_ownership_receipt_sha256" => $i["ownership_receipt_sha256"],
  "expected_resource_id" => $i["resource_id"],
  "url" => $i["url"],
], JSON_UNESCAPED_SLASHES);
' "$TMP/plan.json")"
plan "$TMP/config.json" url-set mup2 "$IDENTITY"
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq 'cannot validate a fenced target plan without an active provider lease' "$TMP/plan.err"; then
  pass 'a fenced target plan without active provider state refuses instead of inventing an identity'
else
  fail "a state-free fenced plan did not refuse (exit $STATUS): $(cat "$TMP/plan.err")"
fi

# --dry-run is a documented alias, not a second grammar.
request capabilities mup2 '[]' | php "$PROVIDER" --dry-run "$TMP/config.json" > "$TMP/alias.json" 2>/dev/null
if cmp -s "$TMP/alias.json" <(request capabilities mup2 '[]' | php "$PROVIDER" --print-plan "$TMP/config.json" 2>/dev/null); then
  pass '--dry-run is a byte-identical alias for --print-plan'
else
  fail '--dry-run and --print-plan disagree'
fi

if [ ! -e "$TMP/plan-state/provider-errors.log" ] && [ ! -e "$TMP/plan-state-subset/provider-errors.log" ]; then
  pass '--print-plan refusals leave no provider-state or diagnostic-log writes'
else
  fail '--print-plan wrote provider diagnostics despite its no-write contract'
fi

# The two-token argv CommandEnvironmentProvider is configured with is unchanged.
request capabilities mup2 '[]' | php "$PROVIDER" > /dev/null 2>"$TMP/plain.err"
STATUS=$?
if [ "$STATUS" = 1 ] && grep -Fq 'usage: reference-env-provider.php' "$TMP/plain.err"; then
  pass 'the provider still refuses any argv other than [--print-plan|--dry-run] <config.json>'
else
  fail "the provider accepted a config-less invocation (exit $STATUS)"
fi

# The freeze witness ignores WordPress core's cron lock and nothing else.
# `spawn_cron()` rewrites the `doing_cron` transient's timestamp on any
# bootstrap that finds cron due — live traffic does, and so does the
# orchestrator's own refresh-export between snapshot-prepare and
# snapshot-create — so a witness that counted it refused every live source
# (grind_mup.sh step 5). A real write between the two dumps must still refuse.
php -r '
$code = (string) file_get_contents($argv[1]);
if (!preg_match("/function ref_freeze_witness\\(string \\\$dump\\): string \\{.*?\\n\\}/s", $code, $m)) { fwrite(STDERR, "ref_freeze_witness not found\n"); exit(1); }
eval(str_replace("function ref_freeze_witness", "function witness", $m[0]));
$q = chr(39);
$a = "INSERT INTO `wp_options` VALUES (1,{$q}siteurl{$q},{$q}http://x{$q},{$q}on{$q}),(125,{$q}_transient_doing_cron{$q},{$q}1786978834.28991{$q},{$q}on{$q}),(126,{$q}x{$q},{$q}y{$q},{$q}on{$q});\n";
$cronBumped = str_replace("1786978834.28991", "1786978920.39308", $a);
$realWrite = str_replace("(126,{$q}x{$q},{$q}y{$q},{$q}on{$q})", "(126,{$q}x{$q},{$q}z{$q},{$q}on{$q})", $a);
$ok = witness($a) === witness($cronBumped) && witness($a) !== witness($realWrite) && witness($a) !== hash("sha256", $a);
if (!$ok) { fwrite(STDERR, "witness semantics wrong\n"); exit(1); }
echo "ok\n";
' "$PROVIDER" > /dev/null 2> "$TMP/witness.err" \
  && pass 'the freeze witness ignores the doing_cron lock timestamp and still refuses a real write' \
  || { fail "the freeze witness semantics are wrong: $(cat "$TMP/witness.err")"; }

# The provider's external-command boundary on the write side. A child that
# stops reading its stdin is diagnosed by its own exit status, not by the EPIPE
# this provider sees — issue #3492 read that pipe as the provider's own failure and
# turned every `docker exec -i … mariadb` call into a scheduling coin flip.
if php "$FIX/provider-stdin-checks.php" > "$TMP/stdin.out" 2> "$TMP/stdin.err"; then
  pass 'the provider command boundary answers with the child, not with its own pipe'
else
  fail 'the provider command-boundary stdin checks failed'
  cat "$TMP/stdin.err" >&2
fi
sed -n 's/^ok: /ok: /p' "$TMP/stdin.out"

# ------------------------------------------ 4: one reusable physical preview slot
say 'reusable preview-slot generation and stale-reap safety'
mkdir -p "$TMP/slot"
if php "$FIX/slot-reuse-checks.php" "$TMP/slot" > "$TMP/slot.out" 2> "$TMP/slot.err"; then
  pass 'the reusable preview-slot lifecycle checks pass'
else
  fail 'the reusable preview-slot lifecycle checks failed'
  cat "$TMP/slot.err" >&2
fi
sed -n 's/^ok: /ok: /p' "$TMP/slot.out"

# The same provider must compose through the shipped command/materializer
# journal, not only through direct protocol calls.
mkdir -p "$TMP/reference-command"
if php "$FIX/reference-provider-command-checks.php" "$TMP/reference-command" > "$TMP/reference-command.out" 2> "$TMP/reference-command.err"; then
  pass 'the reusable provider completes the real materialize/reap command path'
else
  fail 'the reusable provider failed the real materialize/reap command path'
  cat "$TMP/reference-command.err" >&2
fi
sed -n 's/^ok: /ok: /p' "$TMP/reference-command.out"

# ------------------------------------------------------ 5: the disclosure
say 'the containment disclosure and the rehearsal preview'
mkdir -p "$TMP/fixture"
php "$FIX/make-fixture.php" "$TMP/fixture" > /dev/null \
  || { echo "FAIL: could not build the rehearse fixture" >&2; exit 1; }
if php "$FIX/preview-checks.php" "$TMP/fixture" > "$TMP/preview.out" 2> "$TMP/preview.err"; then
  pass 'the preview and disclosure checks pass'
else
  fail 'the preview and disclosure checks failed'
  cat "$TMP/preview.err" >&2
fi
sed -n 's/^ok: /ok: /p' "$TMP/preview.out"

# The banner is a literal in the proposal, so assert it as a literal here too:
# a reworded disclosure is a product change, not a refactor.
BANNER='containment: unknown — not enforced in this profile; do not point this environment at live payment or mail credentials.'
if [ "$(head -n 1 "$TMP/fixture/render.txt")" = "$BANNER" ]; then
  pass 'the containment banner is the first line of the rehearsal report, byte for byte'
else
  fail 'the containment banner is missing, reworded, or not printed first'
fi
if [ "$(grep -cF "$BANNER" "$TMP/fixture/render.txt")" = 1 ]; then
  pass 'the banner is printed once, not once per section'
else
  fail 'the containment banner is printed more than once'
fi
if grep -Fq 'cannot authorize an Experimental or Uncertified capability' "$TMP/fixture/render.txt"; then
  pass 'the consequence sentence follows the banner in the operator report'
else
  fail 'the report does not say a rehearsal cannot authorize Experimental/Uncertified capabilities'
fi
if grep -Fq '"containment": "unknown"' "$TMP/fixture/preview.json" \
   && grep -Fq '"enforced": false' "$TMP/fixture/preview.json"; then
  pass 'the JSON disclosure block reports containment unknown and enforced false'
else
  fail 'the JSON disclosure block is missing or mis-stated'
fi
if grep -Fq 'sandboxed' "$TMP/fixture/preview.json"; then
  fail 'the standalone preview claims sandboxed without a provider proof'
else
  pass 'the standalone preview never claims sandboxed without a provider proof'
fi

printf '\n'
if [ "$FAILURES" -ne 0 ]; then
  printf 'REGRESS_REHEARSE_PROVIDER FAILED (%d)\n' "$FAILURES" >&2
  exit 1
fi
printf 'REGRESS_REHEARSE_PROVIDER PASSED\n'
