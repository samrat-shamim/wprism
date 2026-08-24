#!/usr/bin/env bash
# Live regression for `duo env provider-check` against the reference provider.
#
# WHY THIS SUITE EXISTS
# ---------------------
# docs/branch-environment-provider.md and the `duo env provider-check` harness
# are both projections of cli/src/Environment/EnvironmentProviderProtocol.php,
# and the offline suite
# (sandbox/tests/offline/environment/regress_env_provider_conformance.php)
# proves they agree with CommandEnvironmentProvider using stub providers this
# repository writes itself. What no offline suite can prove is that the
# harness's SYNTHETIC cycle is a cycle a REAL provider can serve: that its
# inputs are ones an independent implementation accepts, that its teardown
# actually unfreezes a source and releases a target, and that a withheld
# capability produces the verdict the operator needs before touching prod.
# That is this suite, against tools/reference-env-provider.php on a real pair.
#
# It owns its pair end to end: `pair.sh up` here, `pair.sh destroy` in the
# trap, and the final `pair.sh list` is the evidence it left nothing running.
# Not wired into any offline target — it needs docker.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
# Absolute self-path, captured BEFORE the cd below: a relative $0 (the normal
# way this suite is launched, `cd sandbox && bash tests/live/...`) dangles the
# moment the working directory moves to $ROOT, and the preflight's
# `bash -n "$SELF"` would report "No such file or directory" as a parse
# failure — which is exactly how this suite's first run died.
SELF="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
cd "$ROOT"

PAIR=envprovcheck
PORT1=9200
PORT2=9201
SOURCE_ENV="${PAIR}1"
TARGET_ENV="${PAIR}2"
SANDBOX="$ROOT/sandbox"
DUO="$ROOT/cli/duo"
PROVIDER="$ROOT/tools/reference-env-provider.php"
PHP_BIN="$(command -v php)"
SITE1="$SANDBOX/siterepo/${PAIR}1"
SITE2="$SANDBOX/siterepo/${PAIR}2"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/duo-env-provider-check-live.XXXXXX")"
ENVS="$TMP/envs.json"
PROVIDER_CONFIG="$TMP/provider.json"
PROVIDER_STATE="$TMP/provider-state"
ORIGIN="$TMP/origin.git"
CONTROLLER="$TMP/controller"
BRANCH=duo/provider-check-live
PAIR_OWNED=0

say() { printf '\n== %s ==\n' "$*"; }
pass() { printf 'ok: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

# `pair.sh list` reports bare pair names (`  - foo`), never Docker project
# names. Keep the parser strict so `duo-envprovcheck` or `envprovcheck0`
# cannot be mistaken for this pair -- the same boundary
# regress_environment_materializer_live.sh:47-58 defends.
pair_list_has_exact() {
  local pair=$1
  grep -Eq "^[[:space:]]*-[[:space:]]*${pair}[[:space:]]*$"
}

cleanup() {
  local status=$?
  trap - EXIT INT TERM
  set +e
  if [ "$PAIR_OWNED" -eq 1 ]; then
    bash "$SANDBOX/bin/pair.sh" destroy "$PAIR" >/dev/null 2>&1 || status=1
    rm -rf -- "$SITE1" "$SITE2" || status=1
  fi
  # The provider publishes immutable snapshot evidence read-only; restore the
  # owner write bit before removing this script's own mktemp allocation.
  if [ -d "$TMP" ]; then chmod -R u+w "$TMP" >/dev/null 2>&1 || true; fi
  rm -rf -- "$TMP" || status=1
  local list
  list="$(bash "$SANDBOX/bin/pair.sh" list 2>&1)" || status=1
  if pair_list_has_exact "$PAIR" <<<"$list"; then
    printf 'FAIL: cleanup left pair %s running:\n%s\n' "$PAIR" "$list" >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

run_check() { # run_check <out-file> <duo env provider-check args...>
  local out=$1
  shift
  local status=0
  (cd "$CONTROLLER" && "$DUO" --envs-file="$ENVS" env provider-check "$@") >"$out" 2>&1 || status=$?
  printf '%s' "$status"
}

# The harness prints one JSON object under --format=json and nothing else, but
# a provider or transport may still write to the same stream, so take the last
# complete object exactly as regress_environment_materializer_live.sh:129-138
# does.
extract_final_json() {
  "$PHP_BIN" -r '
    $raw = file_get_contents($argv[1]);
    $start = strrpos($raw, "\n{");
    if ($start === false) $start = ($raw !== "" && $raw[0] === "{") ? -1 : false;
    if ($start === false) { fwrite(STDERR, "no final JSON object\n"); exit(1); }
    $value = json_decode(substr($raw, $start + 1), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($value)) { fwrite(STDERR, "final JSON is not an object\n"); exit(1); }
    file_put_contents($argv[2], json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
  ' "$1" "$2" || fail "could not extract the provider-check envelope from $1"
}

write_provider_config() { # write_provider_config <withheld,ids>
  "$PHP_BIN" -r '
    $withheld = $argv[10] === "" ? [] : explode(",", $argv[10]);
    $config = [
      "format" => "duo-reference-env-provider-config/v1",
      "pair" => $argv[2], "pair_script" => $argv[3] . "/bin/pair.sh",
      "compose_dir" => $argv[3],
      "compose_files" => [$argv[3] . "/pair.yml", $argv[3] . "/pair.http.yml"],
      "controller_repo" => $argv[4], "db_container" => "duo-shared-db",
      "state_root" => $argv[5], "source_environment" => $argv[6],
      "destroy_scope" => "side", "withheld_capabilities" => array_values($withheld),
      "environments" => [
        $argv[6] => [
          "role" => "source", "side" => 1, "port" => (int) $argv[8],
          "container" => "duo-" . $argv[2] . "-wp1-1", "service" => "cli1",
          "database" => "wp_" . $argv[2] . "1", "repo" => $argv[3] . "/siterepo/" . $argv[6],
        ],
        $argv[7] => [
          "role" => "target", "side" => 2, "port" => (int) $argv[9],
          "container" => "duo-" . $argv[2] . "-wp2-1", "service" => "cli2",
          "database" => "wp_" . $argv[2] . "2", "repo" => $argv[3] . "/siterepo/" . $argv[7],
        ],
      ],
    ];
    file_put_contents($argv[1], json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
  ' "$PROVIDER_CONFIG" "$PAIR" "$SANDBOX" "$ORIGIN" "$PROVIDER_STATE" \
    "$SOURCE_ENV" "$TARGET_ENV" "$PORT1" "$PORT2" "$1"
}

say "preflight — the harness script parses and the pair budget allows one pair"
bash -n "$SELF" || fail "this suite does not parse"
[ -x "$DUO" ] || fail "cli/duo is not executable"
[ -f "$PROVIDER" ] || fail "tools/reference-env-provider.php is missing"
command -v docker >/dev/null || fail "docker is required for this live suite"
if bash "$SANDBOX/bin/pair.sh" list 2>&1 | pair_list_has_exact "$PAIR"; then
  fail "pair $PAIR already exists; another run owns it"
fi
pass "preflight clean"

say "pair — up $PAIR on $PORT1/$PORT2"
bash "$SANDBOX/bin/pair.sh" up "$PAIR" "$PORT1" "$PORT2" --http || fail "pair.sh up failed"
PAIR_OWNED=1
pass "pair $PAIR is live"

say "controller — an origin the provider can clone and a branch to materialize"
git init -q --bare -b main "$ORIGIN" || fail "could not create the controller origin"
git clone -q "$ORIGIN" "$CONTROLLER" || fail "could not clone the controller origin"
(
  cd "$CONTROLLER"
  git config user.email provider-check@example.invalid
  git config user.name 'Provider Check Live'
  mkdir -p state
  printf 'provider-check live\n' > state/README.md
  git add state/README.md
  git commit -q -m 'provider-check live baseline'
  git push -q origin HEAD:refs/heads/main
  git checkout -q -b "$BRANCH"
  printf 'branch delta\n' >> state/README.md
  git commit -q -am 'provider-check live branch delta'
  git push -q origin "HEAD:refs/heads/$BRANCH"
) || fail "could not seed the controller repository"
mkdir -p "$PROVIDER_STATE"
pass "controller repository seeded on $BRANCH"

say "registry — both pair sides carrying the machine-local provider block"
write_provider_config ""
"$PHP_BIN" -r '
  $envs = ["envs" => []];
  foreach ([[$argv[2], "cli1", 1], [$argv[3], "cli2", 2]] as [$name, $service, $side]) {
    $envs["envs"][$name] = [
      "transport" => "docker",
      "compose_file" => $argv[4] . "/pair.yml",
      "service" => $service,
      "repo_path" => "/site",
      "wp_path" => "/var/www/html",
      "environment_provider" => [
        "command" => [$argv[5], $argv[6], $argv[7]],
        "timeout_seconds" => 60,
      ],
    ];
  }
  file_put_contents($argv[1], json_encode($envs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
' "$ENVS" "$SOURCE_ENV" "$TARGET_ENV" "$SANDBOX" "$PHP_BIN" "$PROVIDER" "$PROVIDER_CONFIG"
pass "registry written for $SOURCE_ENV and $TARGET_ENV"

say "negotiate — the non-mutating tier is READY and touches no source"
status="$(run_check "$TMP/negotiate.out" "$TARGET_ENV" --format=json)"
[ "$status" = 0 ] || { cat "$TMP/negotiate.out" >&2; fail "negotiate tier exited $status against a conformant provider"; }
extract_final_json "$TMP/negotiate.out" "$TMP/negotiate.json"
"$PHP_BIN" -r '
  $body = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($body["verdict"] ?? null) !== "READY") { fwrite(STDERR, "verdict is not READY\n"); exit(1); }
  if (($body["tier"] ?? null) !== "negotiate") { fwrite(STDERR, "wrong tier\n"); exit(1); }
  if (!preg_match("/^sha256:[a-f0-9]{64}$/D", (string) ($body["pin"]["capabilities_sha256"] ?? ""))) {
    fwrite(STDERR, "no capability pin\n"); exit(1);
  }
  $actions = [];
  foreach ($body["checks"] ?? [] as $check) {
    if (str_starts_with((string) $check["check"], "action ")) $actions[] = substr((string) $check["check"], 7);
  }
  if ($actions !== ["inspect"]) { fwrite(STDERR, "the non-mutating tier ran: " . implode(",", $actions) . "\n"); exit(1); }
' "$TMP/negotiate.json" || fail "the negotiate envelope is not the non-mutating READY report"
[ ! -d "$PROVIDER_STATE/prepared" ] || fail "the negotiate tier prepared a snapshot session"
pass "negotiate tier READY with exactly one action (inspect) and no prepared session"

say "negotiate — a withheld capability BLOCKS and names that exact id"
write_provider_config "environment.create,environment.destroy"
status="$(run_check "$TMP/withheld.out" "$TARGET_ENV" --format=json)"
[ "$status" = 1 ] || fail "a withheld capability did not exit 1 (exit $status)"
extract_final_json "$TMP/withheld.out" "$TMP/withheld.json"
"$PHP_BIN" -r '
  $body = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($body["verdict"] ?? null) !== "BLOCKED") { fwrite(STDERR, "verdict is not BLOCKED\n"); exit(1); }
  $detail = null;
  foreach ($body["checks"] ?? [] as $check) {
    if ($check["check"] === "capability set materialize-target-create") $detail = (string) $check["detail"];
  }
  if ($detail === null || !str_contains($detail, "missing environment.create, environment.destroy")) {
    fwrite(STDERR, "the withheld ids were not named: " . var_export($detail, true) . "\n"); exit(1);
  }
  if (($body["profiles"]["materialize-target-attach"] ?? null) !== true) {
    fwrite(STDERR, "the still-complete attach profile was not reported\n"); exit(1);
  }
' "$TMP/withheld.json" || fail "the withheld-capability verdict does not name the id an operator must add"
pass "withheld environment.create/destroy BLOCKS, naming both ids, attach still reported complete"

say "cycle — all 18 actions, snapshot aborted, target released"
write_provider_config ""
status="$(run_check "$TMP/cycle.out" "$TARGET_ENV" --cycle --from "$SOURCE_ENV" \
  --confirm-disposable --branch "$BRANCH" --format=json)"
[ "$status" = 0 ] || { cat "$TMP/cycle.out" >&2; fail "the synthetic cycle exited $status"; }
extract_final_json "$TMP/cycle.out" "$TMP/cycle.json"
"$PHP_BIN" -r '
  $body = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($body["verdict"] ?? null) !== "READY") { fwrite(STDERR, "cycle verdict is not READY\n"); exit(1); }
  if (($body["findings"] ?? []) !== []) { fwrite(STDERR, "a conformant provider produced findings\n"); exit(1); }
  $seen = [];
  foreach ($body["actions"] ?? [] as $name) {
    $seen[preg_replace("/^(?:teardown )?action /", "", (string) $name)] = true;
  }
  $required = [
    "inspect", "attach", "snapshot-prepare", "snapshot-create", "snapshot-read", "snapshot-abort",
    "snapshot-restore", "repository-materialize", "url-set", "mutation-acquire", "mutation-read",
    "mutation-release", "ttl-set", "ttl-read", "detach",
  ];
  $missing = array_values(array_diff($required, array_keys($seen)));
  if ($missing !== []) { fwrite(STDERR, "cycle never ran: " . implode(",", $missing) . "\n"); exit(1); }
' "$TMP/cycle.json" || fail "the synthetic cycle did not drive the attach-mode action set"
pass "the synthetic cycle completed READY over the attach-mode action set"

say "cycle — nothing retained: no live session, no immutable set, pair still up"
"$PHP_BIN" -r '
  $path = $argv[1] . "/state.json";
  if (!is_file($path)) exit(0);
  $state = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
  foreach (($state["sessions"] ?? []) as $key => $session) {
    if (($session["state"] ?? null) !== "aborted") {
      fwrite(STDERR, "session $key was left in state " . var_export($session["state"] ?? null, true) . "\n");
      exit(1);
    }
  }
  if (($state["snapshots"] ?? []) !== []) { fwrite(STDERR, "an immutable snapshot set was retained\n"); exit(1); }
' "$PROVIDER_STATE" || fail "the cycle retained provider state it promised to abort"
# Capture the listing rather than piping it straight into the matcher: the
# first execution of this suite failed here, and the evidence the check had
# just judged was gone with the pipe.
PAIR_LIST_AFTER_CYCLE="$(bash "$SANDBOX/bin/pair.sh" list 2>&1)"
printf '%s\n' "$PAIR_LIST_AFTER_CYCLE" | pair_list_has_exact "$PAIR" \
  || { printf '%s\n' "$PAIR_LIST_AFTER_CYCLE" >&2; fail "the cycle destroyed the pair it only attached to"; }
docker inspect "duo-${PAIR}-wp2-1" >/dev/null 2>&1 \
  || fail "the cycle removed the target container an attach/detach must leave alone"
pass "snapshot aborted, no set retained, and the attached pair side survives its own detach"

say "cycle — the safety gate is not optional"
if (cd "$CONTROLLER" && "$DUO" --envs-file="$ENVS" env provider-check "$TARGET_ENV" \
    --cycle --from "$SOURCE_ENV" --format=json) >"$TMP/unconfirmed.out" 2>&1; then
  fail "--cycle ran without --confirm-disposable"
fi
grep -q 'requires --from <disposable-source-env> and --confirm-disposable' "$TMP/unconfirmed.out" \
  || { cat "$TMP/unconfirmed.out" >&2; fail "--cycle without --confirm-disposable refused for the wrong reason"; }
pass "--cycle refuses without --confirm-disposable, because snapshot-prepare freezes the named source"

say "DONE"
pass "duo env provider-check conforms against tools/reference-env-provider.php on a real pair"
