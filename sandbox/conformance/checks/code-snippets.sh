#!/usr/bin/env bash
# Production-oriented Code Snippets proof. The generic harness already proves
# clean deploy/apply and byte-identical recapture; this hook adds plugin/API/
# flat-file agreement, all reference aliases, safe mode, lifecycle residue,
# schema refusal, hostile conflicts, target-only rows, provider receipts, and
# idempotence. Source-side secret/deletion refusals follow after convergence.
set -euo pipefail

observe_code_snippets() {
  local side="$1" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Code Snippets observation side: $side" ;;
  esac
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
global $wpdb;
$table = Code_Snippets\code_snippets()->db->get_table_name(false);
$raw = $wpdb->get_results("SELECT id,name,description,code,tags,scope,condition_id,priority,active FROM `$table` ORDER BY id", ARRAY_A);
$api = Code_Snippets\get_snippets();
$byName = [];
$idsByName = [];
foreach ($api as $snippet) {
    $idsByName[$snippet->name][] = (int) $snippet->id;
    $byName[$snippet->name] = [
        'active' => (bool) $snippet->active,
        'code_hash' => hash('sha256', (string) $snippet->code),
        'description_length' => strlen((string) $snippet->desc),
        'id' => (int) $snippet->id,
        'priority' => (int) $snippet->priority,
        'scope' => (string) $snippet->scope,
    ];
}
foreach ($idsByName as &$ids) {
    sort($ids, SORT_NUMERIC);
}
unset($ids);
$content = $byName['Duo portable content 東京 🚀']['id'] ?? 0;
$page = get_page_by_path('code-snippets-reference-matrix', OBJECT, 'page');
$pageContent = $page ? (string) $page->post_content : '';
$hash = Code_Snippets\Snippet_Files::get_hashed_table_name($table);
$directory = Code_Snippets\Snippet_Files::get_base_dir($hash);
$tree = [];
if (is_dir($directory)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $entry) {
        if ($entry->isFile()) {
            $tree[substr($entry->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $entry->getPathname());
        }
    }
    ksort($tree, SORT_STRING);
}
$contentById = $content ? do_shortcode('[code_snippet id="' . $content . '"]') : '';
$contentByAlias = $content ? do_shortcode('[code_snippet snippet_id="' . $content . '"]') : '';
$sourceById = $content ? do_shortcode('[code_snippet_source id="' . $content . '"]') : '';
$sourceByAlias = $content ? do_shortcode('[code_snippet_source snippet_id="' . $content . '"]') : '';
echo wp_json_encode([
    'api_count' => count($api),
    'by_name' => $byName,
    'content_alias_hash' => hash('sha256', $contentByAlias),
    'content_hash' => hash('sha256', $contentById),
    'content_marker' => str_contains($contentById, 'duo-code-snippet-marker'),
    'content_unicode' => str_contains($contentById, '東京 🚀'),
    'flat_enabled' => Code_Snippets\Snippet_Files::is_active(),
    'flat_tree' => (object) $tree,
    'ids_by_name' => $idsByName,
    'neighbor' => get_option('code_snippets_target_neighbor', null),
    'page_content' => $pageContent,
    'raw_count' => count($raw),
    'raw_hash' => hash('sha256', wp_json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    'runtime_value' => apply_filters('duo_code_snippets_runtime', 'base'),
    'sample_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table` WHERE tags LIKE '%sample%'"),
    'source_alias_hash' => hash('sha256', $sourceByAlias),
    'source_hash' => hash('sha256', $sourceById),
    'source_marker' => str_contains($sourceById, 'duo-code-snippet-marker'),
    'target_only_value' => apply_filters('duo_target_only_snippet', 'absent'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF
  file="$repo_var/.tmp-code-snippets-observe.php"
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-code-snippets-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-code-snippets-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "Code Snippets $side runtime observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

save_runtime_profile() { # <conf1|conf2> <repository|target>
  local side="$1" profile="$2" repo_var file out
  case "$side" in
    conf1) repo_var="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo_var="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid Code Snippets save side: $side" ;;
  esac
  read -r -d '' SAVE_PHP <<PHPEOF || true
<?php
\$profile = '$profile';
\$snippet = null;
foreach (Code_Snippets\\get_snippets() as \$candidate) {
    if (\$candidate->name === 'Duo runtime filter') {
        \$snippet = \$candidate;
        break;
    }
}
if (!\$snippet) {
    throw new RuntimeException('mapped runtime snippet is absent');
}
  if (\$profile === 'repository') {
    \$snippet->desc = 'Repository branch update 東京 🚀';
    \$snippet->code = "add_filter('duo_code_snippets_runtime', static function (\\\$value) { return \\\$value . '|repository-runtime-v2'; });";
    \$snippet->priority = 30001;
} elseif (\$profile === 'complete-uninstall-recovery') {
    \$snippet->desc = 'Repository intent after complete target uninstall';
    \$snippet->code = "add_filter('duo_code_snippets_runtime', static function (\\\$value) { return \\\$value . '|complete-uninstall-recovery'; });";
    \$snippet->priority = 31001;
  } elseif (\$profile === 'target') {
    \$snippet->desc = 'Competing target branch update';
    \$snippet->code = "add_filter('duo_code_snippets_runtime', static function (\\\$value) { return \\\$value . '|target-runtime-v2'; });";
    \$snippet->priority = 29999;
} else {
    throw new RuntimeException('unknown Code Snippets runtime profile');
}
\$saved = Code_Snippets\\save_snippet(\$snippet);
if (!\$saved || !\$saved->active) {
    throw new RuntimeException('Code Snippets rejected the runtime profile');
}
echo (int) \$saved->id;
PHPEOF
  file="$repo_var/.tmp-code-snippets-save.php"
  printf '%s' "$SAVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-code-snippets-save.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-code-snippets-save.php)
  fi
  rm -f "$file"
  require_fixture_ids out
}

TARGET_INITIAL=$(observe_code_snippets conf2)
SOURCE_INITIAL=$(observe_code_snippets conf1)
printf '%s\n' "$TARGET_INITIAL" | jq -e '
  .raw_count == 3 and .api_count == 3 and .sample_count == 0 and
  .by_name["Duo portable content 東京 🚀"].active == true and
  .by_name["Duo portable content 東京 🚀"].description_length > 40000 and
  .by_name["Duo runtime filter"].active == true and
  .by_name["Duo runtime filter"].priority == 32767 and
  .by_name["Duo invalid inactive PHP"].active == false and
  .by_name["Duo invalid inactive PHP"].scope == "global" and
  .runtime_value == "base|repository-runtime" and
  .content_marker == true and .content_unicode == true and
  .content_hash == .content_alias_hash and
  .source_hash == .source_alias_hash and .source_marker == true and
  .flat_enabled == true and
  (.flat_tree | keys | sort) == ([
    "html/index.php",
    ("html/" + (.by_name["Duo portable content 東京 🚀"].id | tostring) + ".php"),
    "php/index.php",
    ("php/" + (.by_name["Duo runtime filter"].id | tostring) + ".php")
  ] | sort) and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Code Snippets target API/cache/flat-file behavior did not converge: $TARGET_INITIAL"
SOURCE_CONTENT_ID=$(jq -r '.by_name["Duo portable content 東京 🚀"].id' <<<"$SOURCE_INITIAL")
TARGET_CONTENT_ID=$(jq -r '.by_name["Duo portable content 東京 🚀"].id' <<<"$TARGET_INITIAL")
require_fixture_ids SOURCE_CONTENT_ID TARGET_CONTENT_ID
[ "$SOURCE_CONTENT_ID" != "$TARGET_CONTENT_ID" ] \
  || fail "Code Snippets source and target content IDs accidentally matched"
printf '%s\n' "$TARGET_INITIAL" | jq -e --arg source "$SOURCE_CONTENT_ID" --arg target "$TARGET_CONTENT_ID" '
  ([.page_content | scan("code_snippet(?:_source)? (?:id|snippet_id)=\\\"" + $target + "\\\"")] | length) == 4 and
  (.page_content | contains("\\\"" + $source + "\\\"") | not)
' >/dev/null || fail "Code Snippets did not rewrite all four shortcode aliases to the target-local ID: $TARGET_INITIAL"
pass "divergent snippet identities, all four shortcode aliases, API cache, native PHP/HTML behavior, invalid code, hostile LONGTEXT, and exact flat files converge"

# The MU fixture defines safe mode before ordinary plugins load. PHP snippets
# must stop executing without changing DB rows or the HTML shortcode surface.
: > "${CONF_REPO2:-siterepo/conf2}/.code-snippets-safe-mode"
SAFE=$(observe_code_snippets conf2)
printf '%s\n' "$SAFE" | jq -e '
  .runtime_value == "base" and .raw_count == 3 and
  .content_marker == true and .flat_enabled == true and
  .neighbor == "target-only-neighbor"
' >/dev/null || fail "Code Snippets safe mode did not isolate PHP execution while preserving content/state: $SAFE"
rm -f "${CONF_REPO2:-siterepo/conf2}/.code-snippets-safe-mode"
UNSAFE=$(observe_code_snippets conf2)
[ "$(jq -r '.runtime_value' <<<"$UNSAFE")" = 'base|repository-runtime' ] \
  || fail "Code Snippets PHP execution did not recover after safe mode ended: $UNSAFE"
pass "safe mode suppresses PHP only, leaves portable rows/content/flat state intact, and recovers on the next process"

wp_conf2 plugin deactivate code-snippets >/dev/null
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_snippets' --skip-column-names | tr -d '[:space:]')" = 3 ] \
  || fail "Code Snippets deactivation changed authored rows"
REDEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets deploy after deactivation" json "$REDEPLOY"
wp_conf2 plugin is-active code-snippets >/dev/null \
  || fail "Duo deploy did not reactivate exact Code Snippets code"
REACTIVATED=$(observe_code_snippets conf2)
[ "$(jq -r '.runtime_value' <<<"$REACTIVATED")" = 'base|repository-runtime' ] \
  || fail "Code Snippets deactivate/reactivate did not restore native execution: $REACTIVATED"
pass "deactivation preserves rows/files/settings and deploy reactivation restores plugin execution"

# Default uninstall is deliberately non-destructive: exact code disappears,
# while the table/settings/flat tree remain. Missing code must refuse, and an
# exact digest-bound reinstall must consume the retained state without samples.
RETAINED_BEFORE=$(jq -r '.raw_hash' <<<"$REACTIVATED")
wp_conf2 plugin uninstall code-snippets --deactivate >/dev/null
if wp_conf2 plugin is-installed code-snippets >/dev/null 2>&1; then
  fail "Code Snippets default uninstall left plugin code installed"
fi
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_snippets' --skip-column-names | tr -d '[:space:]')" = 3 ] \
  || fail "Code Snippets default uninstall removed the retained snippets table"
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered "Code Snippets deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing Code Snippets code did not refuse at the compatibility boundary: $MISSING_OUT"
CS_SHA=ab5822db426858b43d7c010481a87d0eaffb056cc483e93e057d96f6e6fdd4f4
CS_ARTIFACT="/artifacts-cache/plugin-code-snippets-3.9.6-${CS_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$CS_ARTIFACT');")" = "$CS_SHA" ] \
  || fail "Code Snippets cached reinstall artifact digest moved"
wp_conf2 plugin install "$CS_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get code-snippets --field=version)" = '3.9.6' ] \
  || fail "Code Snippets exact reinstall reported the wrong version"
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets deploy after retained-state reinstall" json "$REINSTALL_DEPLOY"
RETAINED=$(observe_code_snippets conf2)
[ "$(jq -r '.raw_hash' <<<"$RETAINED")" = "$RETAINED_BEFORE" ] \
  && [ "$(jq -r '.sample_count' <<<"$RETAINED")" = 0 ] \
  && [ "$(jq -r '.runtime_value' <<<"$RETAINED")" = 'base|repository-runtime' ] \
  || fail "Code Snippets exact reinstall did not reuse retained state byte-for-byte: $RETAINED"
pass "default uninstall residue, absent-code refusal, digest-bound reinstall, and reactivation retain authored/native state exactly"

# A missing authored column is outside the exact schema contract. Planning
# must refuse before state mutation; the plugin's own dbDelta path restores it.
SCHEMA_BEFORE=$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_snippets' --skip-column-names | tr -d '[:space:]')
wp_conf2 db query 'ALTER TABLE wp_snippets DROP COLUMN description' >/dev/null
SCHEMA_RC=0
SCHEMA_OUT=$(wp_conf2 duo plan --repo=/siterepo 2>&1) || SCHEMA_RC=$?
require_duo_answered "Code Snippets schema-drift plan" human "$SCHEMA_OUT"
[ "$SCHEMA_RC" -ne 0 ] && grep -Eq 'description|schema|column' <<<"$SCHEMA_OUT" \
  || fail "Code Snippets missing-column schema drift did not refuse loudly: $SCHEMA_OUT"
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_snippets' --skip-column-names | tr -d '[:space:]')" = "$SCHEMA_BEFORE" ] \
  || fail "Code Snippets schema refusal partially changed row count"
wp_conf2 eval 'Code_Snippets\code_snippets()->db->create_or_upgrade_tables();' >/dev/null
# dbDelta recreates the dropped column empty on every row. Restore all three
# repository descriptions from canonical JSON, using a file so the >40KB
# UTF-8/delimiter value never crosses shell or SQL quoting.
DESC_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-code-snippets-descriptions.json"
jq -s '
  if length != 3 then error("expected three canonical snippet rows")
  else map({key:.columns.name,value:.columns.description}) | from_entries
  end
' "$CONF_REPO2"/state/tables/snippets/*.json > "$DESC_FILE"
wp_conf2 eval '
  $descriptions=json_decode(file_get_contents("/siterepo/.tmp-code-snippets-descriptions.json"), true, 512, JSON_THROW_ON_ERROR);
  global $wpdb; $changed=0;
  foreach ($descriptions as $name => $description) {
    $result=$wpdb->update($wpdb->prefix . "snippets", ["description"=>$description], ["name"=>$name]);
    if (false === $result) { throw new RuntimeException($wpdb->last_error); }
    $changed += $result;
  }
  if (3 !== count($descriptions) || 3 !== $changed) { throw new RuntimeException("did not restore all snippet descriptions"); }
  Code_Snippets\clean_snippets_cache($wpdb->prefix . "snippets");
' >/dev/null
rm -f "$DESC_FILE"
SCHEMA_RECOVERED=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets plan after plugin schema repair" json "$SCHEMA_RECOVERED"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$SCHEMA_RECOVERED" >/dev/null \
  || fail "Code Snippets plugin schema repair did not return to the synced base: $SCHEMA_RECOVERED"
pass "missing-column drift refuses before partial mutation and the exact plugin schema repair restores a clean plan"

# Competing edits to one mapped snippet plus an unrelated same-name target row
# exercise conflict atomicity, explicit overwrite, target-only preservation,
# cache repair, the disabled-flat-files branch, and receipt secrecy.
save_runtime_profile conf1 repository
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: Code Snippets repository branch intent'
git -C "$CONF_REPO1" push -q origin main
save_runtime_profile conf2 target
TARGET_ONLY_ID=$(wp_conf2 eval '
  $s=Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    "name"=>"Duo runtime filter", "desc"=>"Unmanaged same-name target row",
    "code"=>"add_filter(\"duo_target_only_snippet\", static fn() => \"preserved\");",
    "scope"=>"global", "priority"=>123, "active"=>true,
  ])); echo (int)$s->id;
')
require_fixture_ids TARGET_ONLY_ID
# A populated mapped row without an identity must refuse. Model the supported
# target-local authoring workflow explicitly: capture once into disposable
# output to mint the row's local identity, without publishing target state or
# changing the repository branch that the conflict exercise compares.
TARGET_IDENTITY_CAPTURE=$(wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-code-snippets-target-identity 2>&1)
require_duo_answered "Code Snippets target-only identity capture" human "$TARGET_IDENTITY_CAPTURE"
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-code-snippets-target-identity"
wp_conf2 eval 'Code_Snippets\Settings\update_setting("general", "enable_flat_files", false); do_action("code_snippets/settings_updated", Code_Snippets\Settings\get_settings_values());' >/dev/null
wp_conf2 eval 'echo Code_Snippets\Snippet_Files::is_active() ? "on" : "off";' | grep -qx off \
  || fail "Code Snippets flat-file-disabled conflict premise did not land"
git -C "$CONF_REPO2" pull -q origin main

CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets competing-row plan" json "$CONFLICT_PLAN"
jq -e '.conflict | any(.type == "snippets")' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "competing Code Snippets row edits did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_BEFORE=$(observe_code_snippets conf2)
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered "Code Snippets unforced competing-row apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "Code Snippets competing row did not refuse before mutation: $CONFLICT_OUT"
CONFLICT_AFTER=$(observe_code_snippets conf2)
[ "$(jq -r '.raw_hash' <<<"$CONFLICT_AFTER")" = "$(jq -r '.raw_hash' <<<"$CONFLICT_BEFORE")" ] \
  || fail "unforced Code Snippets conflict partially mutated table/cache/file state"

FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets forced competing-row apply" json "$FORCED"
jq -e '
  .canary == "clean" and
  (.warnings | any(contains("FORCED conflict"))) and
  (.actions | length) == 1 and
  .actions[0].kind == "provider" and
  .actions[0].source == "provider:code-snippets-state/rebuild_snippet_state" and
  .actions[0].provider_version == "1.0.0" and .actions[0].verified == true and
  (.actions[0].after.row_count >= 3) and
  (.actions[0].after.database_hash | test("^[a-f0-9]{64}$")) and
  .actions[0].after.database_hash == .actions[0].after.api_hash and
  .actions[0].after.flat_files_enabled == false and
  .actions[0].after.flat_file_count == 0 and
  (.actions[0].after.flat_tree_hash | test("^[a-f0-9]{64}$"))
' <<<"$FORCED" >/dev/null || fail "Code Snippets forced apply lacked its verified count/hash-only provider receipt: $FORCED"
if grep -Fq 'target-runtime-v2' <<<"$FORCED" || grep -Fq 'repository-runtime-v2' <<<"$FORCED"; then
  fail "Code Snippets provider receipt exposed executable code"
fi
CONVERGED=$(observe_code_snippets conf2)
printf '%s\n' "$CONVERGED" | jq -e --argjson target_only "$TARGET_ONLY_ID" '
  .raw_count == 4 and .api_count == 4 and
  .runtime_value == "base|repository-runtime-v2" and .target_only_value == "preserved" and
  .flat_enabled == false and .flat_tree == {} and
  .neighbor == "target-only-neighbor" and
  (.ids_by_name["Duo runtime filter"] | length) == 2 and
  (.ids_by_name["Duo runtime filter"] | index($target_only)) != null
' >/dev/null || fail "Code Snippets forced conflict did not converge while preserving the target-only same-name row: $CONVERGED"

ZERO_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets zero-change plan" json "$ZERO_PLAN"
jq -e '
  ([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0
' <<<"$ZERO_PLAN" >/dev/null || fail "Code Snippets retry retained repository work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets zero-change apply" json "$ZERO_APPLY"
[ "$(jq -r '.canary' <<<"$ZERO_APPLY")" = clean ] && [ "$(jq '.actions | length' <<<"$ZERO_APPLY")" = 0 ] \
  || fail "Code Snippets zero-change retry fired a provider or dirtied the canary: $ZERO_APPLY"
pass "same-name target rows do not alias identity; conflicts refuse atomically, forced intent converges with a secret-safe provider receipt, disabled flat files purge, and retry is idempotent"

# Clean the explicitly target-only row through the plugin API so later
# lifecycle probes observe only repository identities.
wp_conf2 eval "Code_Snippets\\delete_snippet($TARGET_ONLY_ID);" >/dev/null

# A high-confidence token inside executable code must never enter canonical
# state or the public refusal. Delete it after the atomic source-side probe.
FAKE_TOKEN='ghp_1234567890abcdefghij'
SECRET_ID=$(wp_conf1 eval '
  $s=Code_Snippets\save_snippet(new Code_Snippets\Snippet([
    "name"=>"Duo secret guard probe", "desc"=>"Must refuse capture",
    "code"=>"\$token = \"ghp_1234567890abcdefghij\";", "scope"=>"global", "active"=>false,
  ])); echo (int)$s->id;
')
require_fixture_ids SECRET_ID
SECRET_STATUS_BEFORE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-code-snippets-secret 2>&1) || SECRET_RC=$?
require_duo_answered "Code Snippets credential-shaped code capture" human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_TOKEN" <<<"$SECRET_OUT" \
  || fail "Code Snippets secret guard did not refuse and redact credential-shaped code: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$SECRET_STATUS_BEFORE" ] \
  || fail "Code Snippets secret refusal partially published canonical state"
rm -rf "$CONF_REPO1/.tmp-code-snippets-secret"
wp_conf1 eval "Code_Snippets\\delete_snippet($SECRET_ID);" >/dev/null
pass "credential-shaped executable code refuses atomically and the public diagnostic redacts the token"

# The table selector is deliberately unsupported: even a row with no reverse
# reference cannot publish a tombstone, because the same capability would
# authorize deleting a shortcode-referenced row. Restore the exact DB row and
# identity after proving the refusal so later lifecycle work remains valid.
INVALID_ID=$(jq -r '.by_name["Duo invalid inactive PHP"].id' <<<"$(observe_code_snippets conf1)")
require_fixture_ids INVALID_ID
BACKUP_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-code-snippets-row.json"
wp_conf1 eval "global \$wpdb; echo wp_json_encode(\$wpdb->get_row(\$wpdb->prepare('SELECT * FROM ' . \$wpdb->prefix . 'snippets WHERE id=%d', $INVALID_ID), ARRAY_A));" > "$BACKUP_FILE"
wp_conf1 eval "Code_Snippets\\delete_snippet($INVALID_ID);" >/dev/null
DELETE_STATUS_BEFORE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
DELETE_RC=0
DELETE_OUT=$(wp_conf1 duo capture --repo=/siterepo --format=json) || DELETE_RC=$?
require_duo_answered "Code Snippets unsupported row deletion capture" json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "table:snippets")
' <<<"$DELETE_OUT" >/dev/null \
  || fail "Code Snippets deletion did not refuse at exact table:snippets: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$DELETE_STATUS_BEFORE" ] \
  || fail "failed Code Snippets deletion capture published a partial tombstone"
wp_conf1 eval '
  global $wpdb;
  $row=json_decode(file_get_contents("/siterepo/.tmp-code-snippets-row.json"), true, 512, JSON_THROW_ON_ERROR);
  if (false === $wpdb->insert($wpdb->prefix . "snippets", $row)) { throw new RuntimeException($wpdb->last_error); }
  Code_Snippets\clean_snippets_cache($wpdb->prefix . "snippets");
' >/dev/null
rm -f "$BACKUP_FILE"
pass "snippet deletion refuses at the exact unsupported selector and publishes neither state nor partial tombstone"

# The plugin's opt-in complete uninstall is the opposite lifecycle branch from
# the retained-state case above: it drops the table, settings, and entire flat
# tree. A subsequent exact reinstall starts with plugin samples and a primed
# empty cache; remove those product defaults, publish a new repository intent,
# and require one forced recovery to reconstruct all mapped identities through
# the verified provider.
wp_conf2 eval 'Code_Snippets\Settings\update_setting("general", "enable_flat_files", true);' >/dev/null
wp_conf2 eval 'do_action("code_snippets/settings_updated", Code_Snippets\Settings\get_settings_values()); Code_Snippets\Settings\update_setting("general", "complete_uninstall", true);' >/dev/null
COMPLETE_BEFORE=$(observe_code_snippets conf2)
printf '%s\n' "$COMPLETE_BEFORE" | jq -e '.flat_enabled == true and (.flat_tree | length) == 4' >/dev/null \
  || fail "Code Snippets complete-uninstall flat-tree premise did not land: $COMPLETE_BEFORE"
wp_conf2 plugin uninstall code-snippets --deactivate >/dev/null
[ "$(wp_conf2 db query "SHOW TABLES LIKE 'wp_snippets'" --skip-column-names | tr -d '[:space:]')" = '' ] \
  || fail "Code Snippets complete uninstall retained the snippets table"
[ "$(wp_conf2 eval 'echo false === get_option("code_snippets_settings", false) ? "absent" : "present";')" = absent ] \
  || fail "Code Snippets complete uninstall retained settings"
[ "$(wp_conf2 eval 'echo file_exists(WP_CONTENT_DIR . "/code-snippets") ? "present" : "absent";')" = absent ] \
  || fail "Code Snippets complete uninstall retained its flat-file root"

wp_conf2 plugin install "$CS_ARTIFACT" --force >/dev/null
COMPLETE_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets deploy after complete uninstall" json "$COMPLETE_DEPLOY"
wp_conf2 eval '
  foreach (Code_Snippets\get_snippets() as $snippet) {
    if (in_array("sample", (array) $snippet->tags, true)) {
      Code_Snippets\delete_snippet((int) $snippet->id);
    }
  }
' >/dev/null
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_snippets' --skip-column-names | tr -d '[:space:]')" = 0 ] \
  || fail "Code Snippets exact reinstall sample cleanup left target rows"

save_runtime_profile conf1 complete-uninstall-recovery
wp_conf1 duo capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm 'conformance: recover Code Snippets after complete uninstall'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
COMPLETE_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets plan after complete uninstall" json "$COMPLETE_PLAN"
jq -e '([.create,.update,.conflict,.collision] | map(length) | add) > 0' <<<"$COMPLETE_PLAN" >/dev/null \
  || fail "Code Snippets complete uninstall did not surface missing target state: $COMPLETE_PLAN"
COMPLETE_APPLY=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets forced recovery after complete uninstall" json "$COMPLETE_APPLY"
jq -e '
  .canary == "clean" and (.actions | length) == 1 and
  .actions[0].source == "provider:code-snippets-state/rebuild_snippet_state" and
  .actions[0].verified == true and .actions[0].after.row_count == 3 and
  .actions[0].after.database_hash == .actions[0].after.api_hash and
  .actions[0].after.flat_files_enabled == false and .actions[0].after.flat_file_count == 0
' <<<"$COMPLETE_APPLY" >/dev/null \
  || fail "Code Snippets complete-uninstall recovery lacked a verified default-settings provider receipt: $COMPLETE_APPLY"
COMPLETE_RECOVERED=$(observe_code_snippets conf2)
printf '%s\n' "$COMPLETE_RECOVERED" | jq -e '
  .raw_count == 3 and .api_count == 3 and .sample_count == 0 and
  .runtime_value == "base|complete-uninstall-recovery" and
  .content_marker == true and .flat_enabled == false and .flat_tree == {}
' >/dev/null || fail "Code Snippets complete-uninstall recovery did not restore exact native state: $COMPLETE_RECOVERED"
COMPLETE_RETRY=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "Code Snippets plan retry after complete-uninstall recovery" json "$COMPLETE_RETRY"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$COMPLETE_RETRY" >/dev/null \
  || fail "Code Snippets complete-uninstall recovery was not idempotent: $COMPLETE_RETRY"
pass "opt-in complete uninstall removes table/settings/files; exact reinstall, sample cleanup, forced identity recovery, provider verification, and retry restore the repository state"
