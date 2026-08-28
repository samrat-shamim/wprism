#!/usr/bin/env bash
# Regression — DUO-3423: a live target read must establish that its observation
# answered before it can accuse Duo. Adapter-specific premise contracts belong
# to the adapter package that owns them; this guard discovers those contracts
# instead of carrying a central adapter/path/count registry.
#
# Package contract format: adapter-packages/<slug>/evidence/
# target-observation-premises.tsv, a format/count ratchet followed by one
# literal assertion per line:
#
#   # format duo-target-observation-premises/v1
#   # expected observations=N fixtures=N
#   observation<TAB>tests/conformance/check.sh<TAB>literal assertion prefix
#   fixture<TAB>tests/certify/version-matrix.sh<TAB>literal assertion prefix
#
# A package-relative tests/*.sh path stays inside its package. Cross-adapter
# certification scenarios may use @repo/sandbox/tests/certify/*.sh; ownership
# of the premise contract still stays with the adapter. Blank lines and lines
# beginning with # are ignored. This is an offline source contract: it never
# runs Docker or a pair.
set -euo pipefail
cd "$(dirname "$0")/../../.." # -> sandbox/

pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

REPO_ROOT="$(cd .. && pwd -P)"
ADAPTER_ROOT="$REPO_ROOT/adapter-packages"
CONTRACT_NAME='target-observation-premises.tsv'
ACTIVE_SHELL_HELPER="$REPO_ROOT/tools/active-shell-source.php"

ACTIVE_SHELL_SOURCE=''
ACTIVE_SHELL_FILES=()
ACTIVE_SHELL_BYTES=()

clear_active_shell_cache() {
  ACTIVE_SHELL_SOURCE=''
  ACTIVE_SHELL_FILES=()
  ACTIVE_SHELL_BYTES=()
}

load_active_shell_source() { # <file>
  local file="$1" index=0
  for ((index = 0; index < ${#ACTIVE_SHELL_FILES[@]}; index++)); do
    if [ "${ACTIVE_SHELL_FILES[index]}" = "$file" ]; then
      ACTIVE_SHELL_SOURCE="${ACTIVE_SHELL_BYTES[index]}"
      return
    fi
  done
  ACTIVE_SHELL_SOURCE="$(php "$ACTIVE_SHELL_HELPER" "$file")" \
    || return 1
  ACTIVE_SHELL_FILES+=("$file")
  ACTIVE_SHELL_BYTES+=("$ACTIVE_SHELL_SOURCE")
}

source_has_active_literal() { # <file> <literal>
  php "$ACTIVE_SHELL_HELPER" --statement "$1" "$2" >/dev/null
}

active_statement_comment() { # <file> <literal>
  php "$ACTIVE_SHELL_HELPER" --statement "$1" "$2"
}

source_declares_manifest_participant() { # <file> <adapter-slug>
  php "$ACTIVE_SHELL_HELPER" --participant "$1" "$2"
}

source_uses_premise_helpers() { # <file>
  load_active_shell_source "$1" || return 1
  grep -Eq '^[[:space:]]*require_(observed_nonempty|duo_answered|fixture_ids|fixture_values)([[:space:];&|]|$)' \
    <<< "$ACTIVE_SHELL_SOURCE"
}

guard() {
  local file="$1" needle="$2"
  [ -f "$file" ] || fail "observation inventory names missing file: $file"
  source_has_active_literal "$file" "$needle" \
    || fail "$file is missing the target-observation premise: $needle"
}

contract_error() {
  printf '%s:%s: %s\n' "$1" "$2" "$3"
  return 1
}

# Failure-returning validation lets the mutation checks prove that stale
# assertions and path escapes are rejected instead of terminating early.
validate_contract_file() { # <contract> <package-root> [repository-root]
  local contract="$1" package="$2" repo_root="${3:-$REPO_ROOT}"
  local line='' kind='' rest='' relative='' needle='' logical='' source='' comment='' slug=''
  local line_no=0 observations=0 fixtures=0 prior=''
  local expected_observations='' expected_fixtures=''
  local tab=$'\t'
  local -a seen=()

  [ -f "$contract" ] || { contract_error "$contract" 0 'contract is not a regular file'; return 1; }
  [ ! -L "$contract" ] || { contract_error "$contract" 0 'contract must not be a symlink'; return 1; }

  while IFS= read -r line || [ -n "$line" ]; do
    line_no=$((line_no + 1))
    if [ "$line_no" -eq 1 ]; then
      [ "$line" = '# format duo-target-observation-premises/v1' ] \
        || { contract_error "$contract" "$line_no" 'missing format duo-target-observation-premises/v1'; return 1; }
      continue
    fi
    if [ "$line_no" -eq 2 ]; then
      if [[ "$line" =~ ^#\ expected\ observations=(0|[1-9][0-9]*)\ fixtures=(0|[1-9][0-9]*)$ ]]; then
        expected_observations="${BASH_REMATCH[1]}"
        expected_fixtures="${BASH_REMATCH[2]}"
      else
        contract_error "$contract" "$line_no" 'missing canonical expected-count ratchet'
        return 1
      fi
      continue
    fi
    [ -z "$line" ] && continue
    case "$line" in \#*) continue ;; esac
    case "$line" in *$'\r'*) contract_error "$contract" "$line_no" 'CR bytes are not canonical'; return 1 ;; esac

    kind="${line%%"$tab"*}"
    rest="${line#*"$tab"}"
    [ "$rest" != "$line" ] \
      || { contract_error "$contract" "$line_no" 'expected three tab-separated fields'; return 1; }
    relative="${rest%%"$tab"*}"
    needle="${rest#*"$tab"}"
    [ "$needle" != "$rest" ] && [[ "$needle" != *"$tab"* ]] \
      || { contract_error "$contract" "$line_no" 'expected exactly three tab-separated fields'; return 1; }
    [ -n "$relative" ] && [ -n "$needle" ] \
      || { contract_error "$contract" "$line_no" 'path and assertion must be non-empty'; return 1; }

    case "$kind" in
      observation) observations=$((observations + 1)) ;;
      fixture) fixtures=$((fixtures + 1)) ;;
      *) contract_error "$contract" "$line_no" "unknown premise kind: $kind"; return 1 ;;
    esac

    logical="$relative"
    case "$relative" in
      tests/*.sh) source="$package/$relative" ;;
      @repo/sandbox/tests/certify/*.sh)
        logical="${relative#@repo/}"
        source="$repo_root/$logical"
        ;;
      *)
        contract_error "$contract" "$line_no" \
          'path must be package-relative tests/*.sh or @repo/sandbox/tests/certify/*.sh'
        return 1
        ;;
    esac
    case "/$logical/" in
      *'//'*) contract_error "$contract" "$line_no" 'path is not canonical'; return 1 ;;
      *'/./'*|*'/../'*) contract_error "$contract" "$line_no" 'path traversal is forbidden'; return 1 ;;
    esac
    case "$logical" in
      *[!A-Za-z0-9_./@-]*) contract_error "$contract" "$line_no" 'path contains unsafe bytes'; return 1 ;;
    esac

    if [ "${#seen[@]}" -gt 0 ]; then
      for prior in "${seen[@]}"; do
        [ "$prior" != "$line" ] \
          || { contract_error "$contract" "$line_no" 'duplicate premise contract row'; return 1; }
      done
    fi
    seen+=("$line")

    [ -f "$source" ] && [ ! -L "$source" ] \
      || { contract_error "$contract" "$line_no" "premise source is missing or symlinked: $relative"; return 1; }
    comment="$(active_statement_comment "$source" "$needle")" \
      || { contract_error "$contract" "$line_no" "source is missing active premise: $needle"; return 1; }
    if [[ "$relative" == @repo/* ]]; then
      slug="$(basename "$package")"
      source_declares_manifest_participant "$source" "$slug" || {
        contract_error "$contract" "$line_no" \
          "@repo premise has no active canonical manifest participant declaration for $slug"
        return 1
      }
      [ "$comment" = "duo-premise-owner: $slug" ] || {
        contract_error "$contract" "$line_no" \
          "@repo premise requires exact # duo-premise-owner: $slug on its active assertion"
        return 1
      }
    fi
  done < "$contract"

  [ $((observations + fixtures)) -gt 0 ] \
    || { contract_error "$contract" 0 'contract contains no premise rows'; return 1; }
  [ "$expected_observations" = "$observations" ] && [ "$expected_fixtures" = "$fixtures" ] \
    || {
      contract_error "$contract" 0 \
        "expected-count mismatch ($expected_observations/$expected_fixtures declared, $observations/$fixtures found)"
      return 1
    }
  printf '%s %s\n' "$observations" "$fixtures"
}

validate_version_matrix_premises() { # <package version-matrix.sh>
  local file="$1" variable='' assignments=0 premises=0
  [ -f "$file" ] && [ ! -L "$file" ] || {
    printf '%s: package version matrix is missing or symlinked\n' "$file"
    return 1
  }
  load_active_shell_source "$file" || return 1
  for variable in INSTALLED_2 TEC_INSTALLED_2 NEGATIVE_INSTALLED; do
    assignments="$(grep -Ec "^[[:space:]]*${variable}=" <<< "$ACTIVE_SHELL_SOURCE" || true)"
    premises="$(grep -Ec "^[[:space:]]*require_fixture_values[[:space:]]+${variable}([[:space:]]|$)" <<< "$ACTIVE_SHELL_SOURCE" || true)"
    [ "$assignments" -eq "$premises" ] || {
      printf '%s: %s assignment/premise mismatch (%s/%s)\n' \
        "$file" "$variable" "$assignments" "$premises"
      return 1
    }
  done
}

run_contract_mutation_checks() {
  local scratch='' package='' contract='' source='' result=''
  scratch="$(mktemp -d "${TMPDIR:-/tmp}/duo-premise-contract.XXXXXX")"
  package="$scratch/package"
  contract="$package/evidence/$CONTRACT_NAME"
  source="$package/tests/conformance/check.sh"
  mkdir -p "$(dirname "$source")" "$(dirname "$contract")"
  printf '%s\n' 'require_observed_nonempty "probe answered" "$out"' > "$source"
  printf '%s\n' \
    '# format duo-target-observation-premises/v1' \
    '# expected observations=1 fixtures=0' \
    $'observation\ttests/conformance/check.sh\trequire_observed_nonempty "probe answered"' > "$contract"
  result="$(validate_contract_file "$contract" "$package")" \
    || { rm -rf "$scratch"; fail "valid package premise contract refused: $result"; }
  [ "$result" = '1 0' ] \
    || { rm -rf "$scratch"; fail "valid package premise contract returned wrong counts: $result"; }
  source_uses_premise_helpers "$source" \
    || { rm -rf "$scratch"; fail 'active premise helper was not discovered'; }

  printf '%s\n' ': # require_observed_nonempty "probe answered" "$out"' > "$source"
  clear_active_shell_cache
  if source_uses_premise_helpers "$source" || validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a commented-out assertion mutation'
  fi
  printf '%s\n' \
    "cat <<'INERT_PREMISE' >/dev/null" \
    'require_observed_nonempty "probe answered" "$out"' \
    'INERT_PREMISE' > "$source"
  clear_active_shell_cache
  if source_uses_premise_helpers "$source" || validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a heredoc-only assertion mutation'
  fi
  printf '%s\n' \
    'cat <<\INERT_ESCAPED_PREMISE >/dev/null' \
    'require_observed_nonempty "probe answered" "$out"' \
    'INERT_ESCAPED_PREMISE' > "$source"
  clear_active_shell_cache
  if source_uses_premise_helpers "$source" 2>/dev/null \
    || validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted an escaped-heredoc assertion mutation'
  fi
  printf '%s\n' \
    "printf '%s' \\" \
    'require_observed_nonempty "probe answered" "$out"' > "$source"
  clear_active_shell_cache
  if source_uses_premise_helpers "$source" || validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a continued argument as an active assertion'
  fi
  printf '%s\n' \
    "jq -e '" \
    'require_observed_nonempty "probe answered" "$out"' \
    "' >/dev/null" > "$source"
  clear_active_shell_cache
  if source_uses_premise_helpers "$source" || validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a multiline-quoted assertion mutation'
  fi
  printf '%s\n' \
    'captured="$(' \
    'require_observed_nonempty "probe answered" "$out"' \
    ')"' > "$source"
  clear_active_shell_cache
  validate_contract_file "$contract" "$package" >/dev/null \
    || { rm -rf "$scratch"; fail 'package premise contract hid an executed command-substitution premise'; }
  printf '%s\n' \
    'captured="`' \
    'require_observed_nonempty "probe answered" "$out"' \
    '`"' > "$source"
  clear_active_shell_cache
  if source_uses_premise_helpers "$source" 2>/dev/null \
    || validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted unsupported legacy backtick substitution'
  fi
  printf '%s\n' 'echo '\''require_observed_nonempty "probe answered" "$out"'\''' > "$source"
  clear_active_shell_cache
  if source_uses_premise_helpers "$source" || validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted an echoed assertion mutation'
  fi
  printf '%s\n' \
    'never_runs() {' \
    'require_observed_nonempty "probe answered" "$out"' \
    '}' > "$source"
  clear_active_shell_cache
  if validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted an assertion trapped in an uncalled function'
  fi
  printf '%s\n' 'require_observed_nonempty "probe answered" "$out"' > "$source"
  clear_active_shell_cache

  printf '%s\n' \
    'SHIFT=1' \
    'flags=$((1 << SHIFT))' \
    'require_observed_nonempty "probe answered" "$out"' > "$source"
  clear_active_shell_cache
  validate_contract_file "$contract" "$package" >/dev/null \
    || { rm -rf "$scratch"; fail 'package premise contract mistook an arithmetic shift for a heredoc'; }
  printf '%s\n' 'require_observed_nonempty "probe answered" "$out"' > "$source"
  clear_active_shell_cache

  printf '%s\n' \
    '# format duo-target-observation-premises/v1' \
    '# expected observations=1 fixtures=0' \
    $'observation\ttests/conformance/check.sh\trequire_observed_nonempty "stale"' > "$contract"
  if validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a stale assertion mutation'
  fi
  printf '%s\n' \
    '# format duo-target-observation-premises/v1' \
    '# expected observations=1 fixtures=0' \
    $'observation\t../outside.sh\trequire_observed_nonempty "probe answered"' > "$contract"
  if validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a path-escape mutation'
  fi

  printf '%s\n' \
    '# format duo-target-observation-premises/v1' \
    '# expected observations=2 fixtures=0' \
    $'observation\ttests/conformance/check.sh\trequire_observed_nonempty "probe answered"' > "$contract"
  if validate_contract_file "$contract" "$package" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a deleted-row mutation'
  fi

  printf '%s\n' \
    'INSTALLED_2="$(wp2 plugin get x --field=version)"' \
    ': # require_fixture_values INSTALLED_2' > "$source"
  clear_active_shell_cache
  if validate_version_matrix_premises "$source" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'version-matrix validator accepted a commented-out plugin-version premise'
  fi
  printf '%s\n' \
    'INSTALLED_2="$(wp2 plugin get x --field=version)"' \
    "cat <<'INERT_VMATRIX_PREMISE' >/dev/null" \
    'require_fixture_values INSTALLED_2' \
    'INERT_VMATRIX_PREMISE' > "$source"
  clear_active_shell_cache
  if validate_version_matrix_premises "$source" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'version-matrix validator accepted a heredoc-only plugin-version premise'
  fi
  printf '%s\n' \
    'INSTALLED_2="$(wp2 plugin get x --field=version)"' \
    'require_fixture_values INSTALLED_2' > "$source"
  clear_active_shell_cache
  validate_version_matrix_premises "$source" \
    || { rm -rf "$scratch"; fail 'version-matrix validator refused matching assignment/premise rows'; }

  local repo_fixture="$scratch/repository" owner_package="$scratch/repository/adapter-packages/acf"
  local owner_source="$repo_fixture/sandbox/tests/certify/certify_owner.sh"
  local owner_contract="$owner_package/evidence/$CONTRACT_NAME"
  mkdir -p "$(dirname "$owner_source")" "$(dirname "$owner_contract")"
  printf '%s\n' \
    'DUO_CERTIFICATION_MANIFESTS_JSON='\''["acf"]'\''' \
    'require_observed_nonempty "owned assertion" "$out" # duo-premise-owner: acf' > "$owner_source"
  printf '%s\n' \
    '# format duo-target-observation-premises/v1' \
    '# expected observations=1 fixtures=0' \
    $'observation\t@repo/sandbox/tests/certify/certify_owner.sh\trequire_observed_nonempty "owned assertion"' \
    > "$owner_contract"
  validate_contract_file "$owner_contract" "$owner_package" "$repo_fixture" >/dev/null \
    || { rm -rf "$scratch"; fail 'package premise contract refused its exact @repo owner'; }
  printf '%s\n' \
    ': # DUO_CERTIFICATION_MANIFESTS_JSON='\''["acf"]'\''' \
    'require_observed_nonempty "owned assertion" "$out" # duo-premise-owner: acf' > "$owner_source"
  if validate_contract_file "$owner_contract" "$owner_package" "$repo_fixture" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted an inline-comment-only @repo participant'
  fi
  printf '%s\n' \
    "cat <<'INERT_PARTICIPANT' >/dev/null" \
    'DUO_CERTIFICATION_MANIFESTS_JSON='\''["acf"]'\''' \
    'INERT_PARTICIPANT' \
    'require_observed_nonempty "owned assertion" "$out" # duo-premise-owner: acf' > "$owner_source"
  if validate_contract_file "$owner_contract" "$owner_package" "$repo_fixture" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a heredoc-only @repo participant'
  fi
  printf '%s\n' \
    'never_runs() {' \
    'DUO_CERTIFICATION_MANIFESTS_JSON='\''["acf"]'\''' \
    'require_observed_nonempty "owned assertion" "$out" # duo-premise-owner: acf' \
    '}' > "$owner_source"
  if validate_contract_file "$owner_contract" "$owner_package" "$repo_fixture" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted an @repo participant trapped in an uncalled function'
  fi
  printf '%s\n' \
    'require_observed_nonempty "owned assertion" "$out" # duo-premise-owner: acf' > "$owner_source"
  if validate_contract_file "$owner_contract" "$owner_package" "$repo_fixture" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted a missing @repo participant'
  fi
  printf '%s\n' \
    'DUO_CERTIFICATION_MANIFESTS_JSON='\''["acf"]'\''' \
    'require_observed_nonempty "owned assertion" "$out" # duo-premise-owner: acf' > "$owner_source"
  sed 's/duo-premise-owner: acf/duo-premise-owner: woocommerce/' "$owner_source" > "$owner_source.mutated"
  mv "$owner_source.mutated" "$owner_source"
  if validate_contract_file "$owner_contract" "$owner_package" "$repo_fixture" >/dev/null 2>&1; then
    rm -rf "$scratch"
    fail 'package premise contract accepted an @repo assertion owned by another adapter'
  fi
  rm -rf "$scratch"
}

run_contract_mutation_checks
pass 'package premise contract schema rejects deleted rows, stale assertions, path escapes, and unguarded version reads'

# Deleting one package's sidecar must not silently reduce the discovered set.
# Derive the obligation from the package's own helper calls, not from a central
# adapter list: any package with premise-bearing shell tests owns a contract.
while IFS= read -r package; do
  needs_contract=0
  if [ -d "$package/tests" ]; then
    while IFS= read -r source; do
      if source_uses_premise_helpers "$source"; then
        needs_contract=1
        break
      fi
    done < <(find "$package/tests" -type f -name '*.sh' -print | LC_ALL=C sort)
  fi
  if [ "$needs_contract" -eq 1 ]; then
    [ -f "$package/evidence/$CONTRACT_NAME" ] && [ ! -L "$package/evidence/$CONTRACT_NAME" ] \
      || fail "adapter package $(basename "$package") uses premise helpers but owns no $CONTRACT_NAME"
  fi
done < <(find "$ADAPTER_ROOT" -mindepth 1 -maxdepth 1 -type d -print | LC_ALL=C sort)

contract_count=0
observation_count=0
fixture_count=0
while IFS= read -r contract; do
  package="$(dirname "$(dirname "$contract")")"
  slug="$(basename "$package")"
  [[ "$slug" =~ ^[a-z][a-z0-9]*(-[a-z0-9]+)*$ ]] \
    || fail "package premise contract has invalid adapter slug: $contract"
  [ -f "$package/package/manifest.json" ] && [ ! -L "$package" ] \
    || fail "package premise contract is outside a canonical adapter package: $contract"
  if ! counts="$(validate_contract_file "$contract" "$package" 2>&1)"; then
    fail "$counts"
  fi
  observations="${counts%% *}"
  fixtures="${counts#* }"
  observation_count=$((observation_count + observations))
  fixture_count=$((fixture_count + fixtures))
  contract_count=$((contract_count + 1))
done < <(find "$ADAPTER_ROOT" -mindepth 3 -maxdepth 3 -path "*/evidence/$CONTRACT_NAME" -print | LC_ALL=C sort)
[ "$contract_count" -gt 0 ] || fail "no package-owned $CONTRACT_NAME files were discovered"
pass "$observation_count adapter observation and $fixture_count fixture premises validated from $contract_count package contracts"

# Version-matrix cases are package-owned and can grow independently. Match
# every observed plugin-version assignment to its local fixture premise; no
# adapter list or expected total belongs in this central guard.
version_matrix_count=0
while IFS= read -r version_matrix; do
  if ! mismatch="$(validate_version_matrix_premises "$version_matrix" 2>&1)"; then
    fail "$mismatch"
  fi
  version_matrix_count=$((version_matrix_count + 1))
done < <(find "$ADAPTER_ROOT" -mindepth 4 -maxdepth 4 -path '*/tests/certify/version-matrix.sh' -print | LC_ALL=C sort)
[ "$version_matrix_count" -gt 0 ] || fail 'no package-owned version-matrix cases were discovered'
pass "package-owned version-matrix plugin observations have matching fixture premises"

# Global engine/core premise contracts remain central because their source and
# consumer are not owned by a single adapter.
OBSERVATIONS=(
  'conformance/checks/fse.sh|require_duo_answered "conf2 duo plan after active-theme mismatch" json'
  'conformance/checks/fse.sh|require_duo_answered "conf2 duo plan after restoring active theme" json'
  'conformance/checks/core.sh|require_observed_nonempty "conf2 custom_logo post type"'
  'conformance/checks/core.sh|require_observed_nonempty "conf2 custom_css post type"'
  'conformance/checks/core.sh|require_observed_nonempty "conf2 custom_css post content"'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo pending after apply" json'
  'conformance/checks/core.sh|require_duo_answered "conf1 duo pending unknown-widget probe" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo apply --force-theirs conflict override" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan unforced conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan unforced conflict human view" human'
  'conformance/checks/core.sh|require_duo_answered "conf1 duo capture page deletion" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan referential page deletion" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo apply forced page deletion" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan page deletion retry" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan guard-blocked deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan guard-blocked deletion human view" human'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan local deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan local deletion conflict human view" human'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo apply forced local deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan branch deletion conflict" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan missing guard table" json'
  'conformance/checks/core.sh|require_duo_answered "conf2 duo plan fresh target deletion interpretation" json'
  'tests/certify/certify_merge.sh|require_observed_nonempty "A About post title after merge"'
  'tests/certify/certify_merge.sh|require_observed_nonempty "A Hello post title after merge"'
  'tests/certify/certify_merge.sh|require_observed_nonempty "B About post title after apply"'
  'tests/certify/certify_merge.sh|require_observed_nonempty "B Team post title after apply"'
  'tests/certify/certify_merge.sh|require_observed_nonempty "A Team post title after recapture"'
  'tests/certify/certify_merge.sh|require_duo_answered "env B drift plan" json'
  'tests/certify/certify_merge.sh|require_duo_answered "env B apply with preserved local drift" human'
  'tests/certify/certify_merge.sh|require_duo_answered "env B retry plan after preserved drift" json'
  'tests/certify/certify_merge.sh|require_duo_answered "env A unresolved-conflict plan" json'
  'tests/certify/certify_merge.sh|require_duo_answered "env A final lint" json'
  'tests/certify/certify_merge.sh|require_duo_answered "env B final lint" json'
  'tests/certify/certify_adversarial_matrix.sh|require_observed_nonempty "A ledger local id after refused duplicate plan"'
  'tests/certify/certify_adversarial_matrix.sh|require_observed_nonempty "B ledger local id after refused duplicate plan"'
  'tests/certify/certify_adversarial_matrix.sh|require_fixture_ids LOC_LOCAL'
  'tests/certify/certify_adversarial_matrix.sh|require_observed_nonempty "restored ledger local id after identity import"'
  'tests/certify/certify_merge.sh|require_duo_answered "B retry apply after capture" json'
  'tests/certify/certify_merge.sh|require_duo_answered "B clean plan after retry" json'
  'tests/certify/certify_ssh_adoption_roundtrip.sh|require_observed_nonempty "target runtime checksum before apply"'
  'tests/certify/certify_ssh_adoption_roundtrip.sh|require_observed_nonempty "target runtime checksum after apply"'
  'tests/certify/certify_ssh_adoption_roundtrip.sh|require_observed_nonempty "target authored banner after apply"'
  'tests/certify/certify_ssh_rollback.sh|require_observed_nonempty "target SSH hostname"'
  'tests/certify/certify_ssh_rollback.sh|require_observed_nonempty "target database identity"'
  'tests/certify/certify_ssh_rollback.sh|require_observed_nonempty "target rollback status"'
  'tests/certify/certify_ssh_rollback.sh|require_observed_nonempty "target plaintext checkpoint count"'
  'tests/certify/certify_ssh_rollback.sh|require_observed_nonempty "target maintenance exclusion state"'
)
for item in "${OBSERVATIONS[@]}"; do
  guard "${item%%|*}" "${item#*|}"
done
pass "all ${#OBSERVATIONS[@]} global target-reading postconditions retain explicit premise guards"

FIXTURES=(
  'conformance/checks/core.sh|require_fixture_values HOME_FILE'
  'conformance/checks/core.sh|require_fixture_values HELLO_FILE'
  'conformance/checks/core.sh|require_fixture_values ATT_FILE'
  'conformance/checks/core.sh|require_fixture_values CHILD_FILE'
  'conformance/checks/core.sh|require_fixture_ids ATT1'
  'conformance/checks/core.sh|require_fixture_ids CHILD1'
  'conformance/postdeploy/core.sh|require_fixture_ids B'
  'conformance/postdeploy/core.sh|require_fixture_ids A'
  'conformance/postdeploy/core.sh|require_fixture_ids B_CHILD A_CHILD'
  'conformance/postdeploy/core.sh|require_fixture_ids DUP'
)
for item in "${FIXTURES[@]}"; do
  guard "${item%%|*}" "${item#*|}"
done
pass "all ${#FIXTURES[@]} global target fixture reads retain explicit fixture premises"

# Explicit inventory of intentionally empty core observations. These assert
# absence/cleanliness; requiring non-empty output would invert their meaning.
grep -Fq '[ -z "$(wp_conf2 post list --post_type=page --name=home --field=ID)" ] || fail "Home page survived exact deletion"' conformance/checks/core.sh \
  || fail "the expected-empty Home deletion predicate disappeared from the explicit exemption inventory"
grep -Fq 'git -C "$CONF_REPO2" status --porcelain --untracked-files=all' conformance/checks/core.sh \
  || fail "the clean-repository observation exemption lost its direct git status evidence"
grep -Fq '[ -z "$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID)" ]' conformance/checks/core.sh \
  || fail "the expected-empty Hello deletion predicate disappeared from the explicit exemption inventory"
grep -Fq '[ -z "$(wp_conf2 post list --post_type=attachment --name=conformance-logo --field=ID)" ]' conformance/checks/core.sh \
  || fail "the expected-empty attachment deletion predicate disappeared from the explicit exemption inventory"
grep -Fq 'rollback-alpha --field=ID)' conformance/checks/core.sh \
  || fail "the expected-empty rollback deletion predicates disappeared from the explicit exemption inventory"
grep -Fq 'SELECT uuid FROM wp_duo_map WHERE uuid' tests/certify/certify_adversarial_matrix.sh \
  || fail "the expected-empty identity-map setup predicate disappeared from the explicit exemption inventory"
grep -Fq 'git -C siterepo/certmatrix1 status --porcelain -- state' tests/certify/certify_adversarial_matrix.sh \
  || fail "the adversarial clean-repository observation exemption lost its direct git status evidence"
pass "expected-empty absence/clean-repository predicates remain explicitly inventoried rather than falsely premise-guarded"

echo "REGRESS_TARGET_OBSERVATION_PREMISES PASSED"
