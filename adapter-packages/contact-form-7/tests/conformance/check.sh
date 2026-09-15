#!/usr/bin/env bash
# Exact-artifact CF7 acceptance beyond deterministic capture/apply/recapture:
# native current+legacy storage, both shortcode identities, hostile target
# state, fail-closed boundaries, rollback/retry, concurrency, and lifecycle.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

observe_cf7() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid CF7 observation side: $side" ;;
  esac
  file="$repo/.tmp-cf7-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
$find = static function (string $slug): WP_Post {
    $post = get_page_by_path($slug, OBJECT, 'wpcf7_contact_form');
    if (!$post) {
        throw new RuntimeException("CF7 form $slug missing");
    }
    return $post;
};
$mainPost = $find('conformance-contact-form');
$legacyPost = $find('conformance-legacy-storage');
$deletePost = $find('conformance-delete-probe');
$main = WPCF7_ContactForm::get_instance($mainPost->ID);
$legacy = WPCF7_ContactForm::get_instance($legacyPost->ID);
if (!$main || !$legacy) {
    throw new RuntimeException('CF7 native form API could not load fixture');
}
$page = get_page_by_path('conformance-contact', OBJECT, 'page');
$legacyPage = get_page_by_path('conformance-contact-legacy', OBJECT, 'page');
$storagePage = get_page_by_path('conformance-contact-storage', OBJECT, 'page');
$multiplePage = get_page_by_path('conformance-contact-multiple', OBJECT, 'page');
if (!$page || !$legacyPage || !$storagePage || !$multiplePage) {
    throw new RuntimeException('CF7 fixture pages missing');
}
$option = get_option('wpcf7');
echo wp_json_encode([
    'delete_id' => (int) $deletePost->ID,
    'home' => home_url('/'),
    'legacy' => [
        'form' => (string) $legacy->prop('form'),
        'hash' => (string) get_post_meta($legacyPost->ID, '_hash', true),
        'id' => (int) $legacyPost->ID,
        'mail' => $legacy->prop('mail'),
        'old_id' => (string) get_post_meta($legacyPost->ID, '_old_cf7_unit_id', true),
        'storage' => array_map(static fn(string $name): array => [
            'current' => metadata_exists('post', $legacyPost->ID, '_' . $name),
            'legacy' => metadata_exists('post', $legacyPost->ID, $name),
        ], ['form', 'mail', 'mail_2', 'messages', 'additional_settings']),
    ],
    'main' => [
        'additional_settings' => (string) $main->prop('additional_settings'),
        'config_errors' => get_post_meta($mainPost->ID, '_config_errors', true),
        'config_validation' => get_post_meta($mainPost->ID, '_config_validation', true),
        'constant_contact' => get_post_meta($mainPost->ID, '_constant_contact', true),
        'flamingo' => get_post_meta($mainPost->ID, '_flamingo', true),
        'form' => (string) $main->prop('form'),
        'hash' => (string) get_post_meta($mainPost->ID, '_hash', true),
        'id' => (int) $mainPost->ID,
        'locale' => (string) $main->locale(),
        'mail' => $main->prop('mail'),
        'mail_2' => $main->prop('mail_2'),
        'messages' => $main->prop('messages'),
        'old_id' => (string) get_post_meta($mainPost->ID, '_old_cf7_unit_id', true),
        'sendinblue' => get_post_meta($mainPost->ID, '_sendinblue', true),
    ],
    'pages' => [
        'modern' => ['id' => (int) $page->ID, 'content' => (string) $page->post_content],
        'positional' => ['id' => (int) $legacyPage->ID, 'content' => (string) $legacyPage->post_content],
        'storage' => ['id' => (int) $storagePage->ID, 'content' => (string) $storagePage->post_content],
        'multiple' => ['id' => (int) $multiplePage->ID, 'content' => (string) $multiplePage->post_content],
    ],
    'option' => $option,
    'version' => defined('WPCF7_VERSION') ? WPCF7_VERSION : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-cf7-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-cf7-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "$side Contact Form 7 native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

commit_cf7_source() { # <message>
  wp_conf1 wprism capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

cf7_target_hash() {
  wp_conf2 eval '
    global $wpdb;
    $rows=[
      "posts"=>$wpdb->get_results("SELECT ID,post_title,post_name,post_status,post_content FROM {$wpdb->posts} WHERE post_type IN (\"wpcf7_contact_form\",\"page\") ORDER BY ID", ARRAY_A),
      "meta"=>$wpdb->get_results("SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type=\"wpcf7_contact_form\") ORDER BY meta_id", ARRAY_A),
    ];
    echo hash("sha256", wp_json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  '
}

SOURCE=$(observe_cf7 conf1)
TARGET=$(observe_cf7 conf2)
TARGET_PREMISE=$(cat "${CONF_REPO2:-siterepo/conf2}/.tmp-cf7-target.json")
printf '%s\n' "$TARGET" | jq -e '
  .home as $home |
  .version == "6.1.7" and
  .main.old_id == "3199001" and .legacy.old_id == "3199002" and
  (.main.hash | test("^[0-9a-f]{64}$")) and (.legacy.hash | test("^[0-9a-f]{64}$")) and
  (.main.form | contains("Name 東京 🚀")) and (.main.form | contains("wprism-unknown")) and
  (.main.mail.recipient == "main-recipient@example.test") and
  (.main.mail.subject | contains("main 東京 🚀")) and
  (.main.mail.body | length > 25000) and (.main.mail.body | contains($home)) and
  (.main.mail_2.active == true) and (.main.mail_2.body | contains($home)) and
  (.main.messages.validation_error | contains($home)) and
  .main.additional_settings == "demo_mode: on\nacceptance_as_validation: on" and
  .main.config_validation["target-runtime"] == true and
  .main.config_errors[0] == "target-obsolete-runtime" and
  .main.flamingo.channel == 8801 and
  .main.constant_contact.list == "target-environment-list" and
  .main.sendinblue.list == "target-environment-list" and
  .option.wprism_target_only == "target-option-preserved" and
  .option.turnstile == {"target-site-key":"target-secret-key"} and
  (.option | has("wprism_source_only") | not) and
  .legacy.storage == [
    {"current":false,"legacy":true}, {"current":false,"legacy":true},
    {"current":false,"legacy":true}, {"current":false,"legacy":true},
    {"current":false,"legacy":true}
  ] and
  (.legacy.form | contains("legacy-literal")) and (.legacy.mail.body | contains($home))
' >/dev/null || fail "CF7 native properties, long data, legacy storage, or target sovereignty did not converge: $TARGET"

# derived-post-body/v1: a form's post_content is CF7's own flattening of the
# properties this adapter carries as meta, so it is not canonical state
# (agent/src/Grammar/DerivedBodyGrammar.php). Each witness fails against a
# distinct defect:
#  - published state carries an EMPTY body for every form. Carrying it published
#    a second, undeclared copy of that state -- the copy whose source admin
#    address capture's clearance refused;
#  - the adopted target form keeps the body ITS OWN save() derived in
#    postdeploy.sh ("Hostile target main form"). Carrying the body replaces it
#    with the source dump (source-only recipient/sender); an apply that wrote the
#    empty canonical body blanks it.
# The failure text projects the body (length, one boolean) rather than printing
# it: the target's derivation holds the target admin address.
CF7_STATE_BODIES=$(wp_conf1 eval '
  $bodies = [];
  foreach (glob("/siterepo/state/posts/wpcf7_contact_form/*.md") ?: [] as $file) {
      [, $body] = \WPrism\Canon::parse_post_file((string) file_get_contents($file));
      $bodies[basename($file)] = strlen($body);
  }
  ksort($bodies);
  echo wp_json_encode($bodies), "\n";
' | awk 'NF { line=$0 } END { print line }')
require_observed_nonempty "CF7 published form bodies" "$CF7_STATE_BODIES"
jq -e '
  (keys | any(endswith("--conformance-contact-form.md"))) and
  (to_entries | all(.value == 0))
' <<<"$CF7_STATE_BODIES" >/dev/null \
  || fail "CF7 published state carries a non-empty derived form body: $CF7_STATE_BODIES"
CF7_TARGET_BODY=$(wp_conf2 eval '
  $post = get_page_by_path("conformance-contact-form", OBJECT, "wpcf7_contact_form");
  echo wp_json_encode(["content" => $post ? (string) $post->post_content : null]), "\n";
' | awk 'NF { line=$0 } END { print line }')
require_observed_nonempty "CF7 adopted target form body" "$CF7_TARGET_BODY"
jq -e '
  (.content | type == "string") and
  (.content | contains("Hostile target main form")) and
  (.content | contains("main-recipient@example.test") | not) and
  (.content | contains("main-sender@example.test") | not)
' <<<"$CF7_TARGET_BODY" >/dev/null \
  || fail "CF7 apply did not leave the adopted target form its own derived body: $(jq -c '{length: ((.content // "") | length), own_derivation: ((.content // "") | contains("Hostile target main form"))}' <<<"$CF7_TARGET_BODY")"
pass "derived form body: published state carries none, and apply leaves the adopted target form its own derivation"

SOURCE_MAIN=$(jq -r '.main.id' <<<"$SOURCE")
TARGET_MAIN=$(jq -r '.main.id' <<<"$TARGET")
SOURCE_LEGACY=$(jq -r '.legacy.id' <<<"$SOURCE")
TARGET_LEGACY=$(jq -r '.legacy.id' <<<"$TARGET")
require_fixture_ids SOURCE_MAIN TARGET_MAIN SOURCE_LEGACY TARGET_LEGACY
[ "$SOURCE_MAIN" != "$TARGET_MAIN" ] && [ "$SOURCE_LEGACY" != "$TARGET_LEGACY" ] \
  || fail "CF7 divergent identity premise failed: source=$SOURCE target=$TARGET"
jq -e --argjson observed "$TARGET" '
  .main == $observed.main.id and .legacy == $observed.legacy.id and
  .delete_probe == $observed.delete_id
' <<<"$TARGET_PREMISE" >/dev/null \
  || fail "CF7 apply replaced rather than adopted hostile native target rows: premise=$TARGET_PREMISE observed=$TARGET"

if rg -n '\[contact-form-7[^]]*id="[0-9a-f]{7}"' "$CONF_REPO1/state" >/dev/null 2>&1; then
  fail "CF7 canonical pages retained a raw modern hash prefix"
fi
rg -n '\[contact-form-7[^]]*id="\{\{post:[0-9a-f-]{36}\}\}"' "$CONF_REPO1/state" >/dev/null \
  || fail "CF7 canonical pages contain no modern post-token identity"
if rg -n '\[contact-form[[:space:]]+3199001([[:space:]]|\])' "$CONF_REPO1/state" >/dev/null 2>&1; then
  fail "CF7 canonical pages retained a raw legacy positional identity"
fi
pass "modern hash and legacy decimal identities canonicalize to post tokens across divergent native rows"

FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/conformance-contact/") \
  || fail "conf2 modern CF7 page did not return 200"
LEGACY_FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/conformance-contact-legacy/") \
  || fail "conf2 positional CF7 page did not return 200"
STORAGE_FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/conformance-contact-storage/") \
  || fail "conf2 legacy-storage CF7 page did not return 200"
MULTIPLE_FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/conformance-contact-multiple/") \
  || fail "conf2 multiple-forms CF7 page did not return 200"
for response in "$FRONT" "$LEGACY_FRONT" "$STORAGE_FRONT" "$MULTIPLE_FRONT"; do
  require_observed_nonempty "conf2 Contact Form 7 rendered response" "$response"
  if grep -q "localhost:${CONF1_PORT}" <<<"$response"; then
    fail "CF7 target render leaked the source host"
  fi
done
grep -q "_wpcf7\" value=\"$TARGET_MAIN\"" <<<"$FRONT" \
  && grep -q "_wpcf7\" value=\"$TARGET_MAIN\"" <<<"$LEGACY_FRONT" \
  || fail "modern or positional shortcode did not resolve the target main form $TARGET_MAIN"
grep -q "_wpcf7\" value=\"$TARGET_LEGACY\"" <<<"$STORAGE_FRONT" \
  || fail "modern shortcode did not resolve the target legacy-storage form $TARGET_LEGACY"
[ "$(grep -o '_wpcf7" value="[0-9]\+"' <<<"$MULTIPLE_FRONT" | wc -l | tr -d ' ')" = 2 ] \
  && grep -q "_wpcf7\" value=\"$TARGET_MAIN\"" <<<"$MULTIPLE_FRONT" \
  && grep -q "_wpcf7\" value=\"$TARGET_LEGACY\"" <<<"$MULTIPLE_FRONT" \
  || fail "multiple-form page did not render both target identities"
grep -Fq '[wprism-unknown raw="main-literal"]' <<<"$FRONT" \
  || fail "CF7 changed the verified literal behavior of an unknown form tag"
grep -Fq 'data-sitekey="target-site-key"' <<<"$FRONT" \
  || fail "CF7 target render did not retain its environment-owned Turnstile integration"
pass "native renders cover modern, positional, legacy-property, multiple-form, literal-tag, UTF-8, and target-host behavior"

# The target-owned Turnstile integration must remain behaviorally active: an
# anonymous request without a browser-generated token is spam. Then remove the
# integration for one local-only request so demo_mode can exercise the success
# path without an external verifier, and restore its exact native key map.
CONF1_POSTS_BEFORE=$(wp_conf1 post list --post_type=any --format=count)
UNIT_TAG=$(grep -o '_wpcf7_unit_tag" value="[^"]*"' <<<"$FRONT" | sed 's/.*value="//;s/"//')
require_observed_nonempty "conf1 post-count before CF7 submission" "$CONF1_POSTS_BEFORE"
require_observed_nonempty "conf2 CF7 unit tag" "$UNIT_TAG"
submit_cf7() {
  curl -fs -X POST "http://localhost:${CONF2_PORT}/wp-json/contact-form-7/v1/contact-forms/${TARGET_MAIN}/feedback" \
    -F "_wpcf7=$TARGET_MAIN" -F "_wpcf7_version=6.1.7" -F "_wpcf7_locale=en_US" \
    -F "_wpcf7_unit_tag=$UNIT_TAG" -F "_wpcf7_container_post=$(jq -r '.pages.modern.id' <<<"$TARGET")" \
    -F "your-name=Conformance Visitor" -F "your-email=visitor@example.test" \
    -F "your-subject=Conformance check" -F "your-message=Automated conformance submission 東京 🚀"
}
SPAM_SUBMISSION=$(submit_cf7) || fail "Turnstile-protected CF7 REST submission failed at HTTP transport"
printf '%s\n' "$SPAM_SUBMISSION" | jq -e '
  .status == "spam" and .demo_mode == true and .posted_data_hash == "" and (.invalid_fields | length) == 0
' >/dev/null || fail "CF7 target integration did not reject the missing Turnstile token: $SPAM_SUBMISSION"

wp_conf2 eval '$option=(array)get_option("wpcf7",[]); unset($option["turnstile"]); update_option("wpcf7",$option);' >/dev/null
SUBMISSION_RC=0
SUBMISSION=$(submit_cf7) || SUBMISSION_RC=$?
wp_conf2 eval '
  $option=(array)get_option("wpcf7",[]);
  $option["turnstile"]=["target-site-key"=>"target-secret-key"];
  update_option("wpcf7",$option);
' >/dev/null
[ "$SUBMISSION_RC" -eq 0 ] || fail "anonymous CF7 REST submission failed"
printf '%s\n' "$SUBMISSION" | jq -e '.status == "mail_sent" and (.posted_data_hash | type) == "string"' >/dev/null \
  || fail "CF7 native submission returned an unexpected result: $SUBMISSION"
[ "$(wp_conf2 eval 'echo wp_json_encode(WPCF7::get_option("turnstile"));')" = '{"target-site-key":"target-secret-key"}' ] \
  || fail "CF7 controlled submission did not restore the target Turnstile integration exactly"
[ "$(wp_conf1 post list --post_type=any --format=count)" = "$CONF1_POSTS_BEFORE" ] \
  || fail "conf1 changed after an anonymous conf2 CF7 submission"
pass "target Turnstile spam behavior and local demo-mode success both execute without crossing environment state"

ZERO_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 zero-change plan" json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "CF7 retry retained work: $ZERO_PLAN"
ZERO_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 zero-change apply" json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "CF7 no-op apply was not clean and idempotent: $ZERO_APPLY"
pass "CF7 zero-change plan/apply is idempotent"

# Real callback fallback forms are unsafe across tenants. Each source page is
# temporary; capture must refuse atomically before any canonical publication.
SOURCE_HASH=$(jq -r '.main.hash' <<<"$SOURCE")
for shape in \
  '[contact-form-7 title="Conformance Contact Form"]' \
  '[contact-form-7 id="123" title="Conformance Contact Form"]' \
  "[contact-form-7 id=\"${SOURCE_HASH:0:8}\" title=\"Conformance Contact Form\"]"; do
  TEMP_PAGE=$(wp_conf1 post create --post_type=page --post_status=publish --post_title='CF7 unsupported identity probe' --post_content="$shape" --porcelain)
  require_fixture_ids TEMP_PAGE
  BEFORE_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
  SHAPE_RC=0
  SHAPE_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || SHAPE_RC=$?
  [ "$SHAPE_RC" -ne 0 ] \
    && grep -Eq 'requires exactly one|must be exactly 7 lowercase hexadecimal' <<<"$SHAPE_OUT" \
    || fail "CF7 unsafe modern identity shape did not refuse: $shape => $SHAPE_OUT"
  [ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BEFORE_STATUS" ] \
    || fail "CF7 unsafe identity refusal partially published state"
  wp_conf1 post delete "$TEMP_PAGE" --force >/dev/null
done
pass "title-only, numeric, and non-native long modern shortcode identities refuse atomically"

# Schema and collision probes use exact live rows, restore their bytes, and
# prove every failed capture leaves the committed repository unchanged.
SCHEMA_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-cf7-schema-backup.json"
wp_conf1 eval '
  $main=get_page_by_path("conformance-contact-form", OBJECT, "wpcf7_contact_form");
  $legacy=get_page_by_path("conformance-legacy-storage", OBJECT, "wpcf7_contact_form");
  file_put_contents("/siterepo/.tmp-cf7-schema-backup.json", wp_json_encode([
    "mail"=>get_post_meta($main->ID,"_mail",true),
    "legacy_hash"=>get_post_meta($legacy->ID,"_hash",true),
  ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
' >/dev/null

FAKE_TOKEN='AKIAABCDEFGHIJKLMNOP'
wp_conf1 eval '
  $p=get_page_by_path("conformance-contact-form", OBJECT, "wpcf7_contact_form");
  $mail=get_post_meta($p->ID,"_mail",true); $mail["recipient"]="AKIAABCDEFGHIJKLMNOP";
  update_post_meta($p->ID,"_mail",$mail);
' >/dev/null
SECRET_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
SECRET_RC=0
SECRET_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || SECRET_RC=$?
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_TOKEN" <<<"$SECRET_OUT" \
  || fail "CF7 credential-shaped recipient did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$SECRET_STATUS" ] \
  || fail "CF7 secret refusal partially published canonical state"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-cf7-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_page_by_path("conformance-contact-form", OBJECT, "wpcf7_contact_form");
  update_post_meta($p->ID,"_mail",$b["mail"]);
' >/dev/null

wp_conf1 eval '
  $p=get_page_by_path("conformance-contact-form", OBJECT, "wpcf7_contact_form");
  $mail=get_post_meta($p->ID,"_mail",true); $mail["future_transport"]="unsafe";
  update_post_meta($p->ID,"_mail",$mail);
' >/dev/null
MAIL_RC=0
MAIL_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || MAIL_RC=$?
[ "$MAIL_RC" -ne 0 ] && grep -q "unknown field 'future_transport'" <<<"$MAIL_OUT" \
  || fail "CF7 unknown mail schema field did not refuse: $MAIL_OUT"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-cf7-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_page_by_path("conformance-contact-form", OBJECT, "wpcf7_contact_form");
  update_post_meta($p->ID,"_mail",$b["mail"]);
' >/dev/null

# Both property generations on one form is a repository-shape fault, refused by
# the compiler (runtime/interpreters/contact-form-7.php repository_diagnostics)
# rather than at classification: apply's locked target context legitimately
# meets both spellings once while converging a target to the other generation
# (tests/offline/regress_contact_form_7_storage_generation_apply.php). Capture
# compiles its staged candidate before publishing, so the refusal is still
# capture's, names the exact property, and must publish nothing.
wp_conf1 eval '
  $p=get_page_by_path("conformance-legacy-storage", OBJECT, "wpcf7_contact_form");
  update_post_meta($p->ID,"_form",get_post_meta($p->ID,"form",true));
' >/dev/null
DUAL_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
DUAL_RC=0
DUAL_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || DUAL_RC=$?
[ "$DUAL_RC" -ne 0 ] \
  && grep -q "conformance-legacy-storage.md:meta._form" <<<"$DUAL_OUT" \
  && grep -q "Contact Form 7 form has both current '_form' and legacy 'form' properties" <<<"$DUAL_OUT" \
  || fail "CF7 dual legacy/current storage did not refuse: $DUAL_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$DUAL_STATUS" ] \
  || fail "CF7 dual-storage refusal partially published canonical state"
wp_conf1 eval '$p=get_page_by_path("conformance-legacy-storage",OBJECT,"wpcf7_contact_form"); delete_post_meta($p->ID,"_form");' >/dev/null

wp_conf1 eval '
  $main=get_page_by_path("conformance-contact-form", OBJECT, "wpcf7_contact_form");
  $legacy=get_page_by_path("conformance-legacy-storage", OBJECT, "wpcf7_contact_form");
  update_post_meta($legacy->ID,"_hash",get_post_meta($main->ID,"_hash",true));
' >/dev/null
HASH_RC=0
HASH_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || HASH_RC=$?
[ "$HASH_RC" -ne 0 ] && grep -Eqi 'multiple matching forms|duplicates|ambiguous' <<<"$HASH_OUT" \
  || fail "CF7 duplicate native hash-prefix owners did not refuse: $HASH_OUT"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-cf7-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_page_by_path("conformance-legacy-storage", OBJECT, "wpcf7_contact_form");
  update_post_meta($p->ID,"_hash",$b["legacy_hash"]);
' >/dev/null
rm -f "$SCHEMA_BACKUP"
pass "secret recipients redact, unknown mail fields refuse, dual storage refuses, and duplicate native hash identities refuse"

# The form selector has no certified deletion semantics. Remove only wp_posts
# so the exact row can be restored after capture proves no tombstone published.
DELETE_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-cf7-delete-row.json"
DELETE_ID=$(wp_conf1 eval '
  global $wpdb;
  $p=get_page_by_path("conformance-delete-probe", OBJECT, "wpcf7_contact_form");
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$p->ID),ARRAY_A);
  file_put_contents("/siterepo/.tmp-cf7-delete-row.json",wp_json_encode($row));
  if (1 !== $wpdb->delete($wpdb->posts,["ID"=>$p->ID])) throw new RuntimeException($wpdb->last_error);
  clean_post_cache($p->ID); echo $p->ID;
')
require_fixture_ids DELETE_ID
DELETE_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
DELETE_RC=0
DELETE_OUT=$(wp_conf1 wprism capture --repo=/siterepo --format=json) || DELETE_RC=$?
require_wprism_answered "CF7 unsupported form deletion capture" json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "wprism-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "post:wpcf7_contact_form")
' <<<"$DELETE_OUT" >/dev/null \
  || fail "CF7 form deletion did not refuse at exact selector: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$DELETE_STATUS" ] \
  || fail "CF7 deletion refusal partially published a tombstone"
wp_conf1 eval '
  global $wpdb; $row=json_decode(file_get_contents("/siterepo/.tmp-cf7-delete-row.json"),true,512,JSON_THROW_ON_ERROR);
  if (false === $wpdb->insert($wpdb->posts,$row)) throw new RuntimeException($wpdb->last_error);
  clean_post_cache((int)$row["ID"]);
' >/dev/null
rm -f "$DELETE_BACKUP"
pass "unsupported CF7 form deletion refuses at post:wpcf7_contact_form and publishes no partial tombstone"

# Competing authored branches must refuse before changing the target, then an
# explicit repository choice converges while runtime/integration state survives.
wp_conf1 eval '
  $p=get_page_by_path("conformance-contact-form",OBJECT,"wpcf7_contact_form"); $f=WPCF7_ContactForm::get_instance($p->ID);
  $mail=$f->prop("mail"); $mail["subject"]="Repository competing subject 東京 🚀";
  $messages=$f->prop("messages"); $messages["validation_error"]="Repository competing validation " . home_url("/");
  $f->set_properties(["mail"=>$mail,"messages"=>$messages]); $f->save();
' >/dev/null
commit_cf7_source 'conformance: competing CF7 mail and message intent'
wp_conf2 eval '
  $p=get_page_by_path("conformance-contact-form",OBJECT,"wpcf7_contact_form"); $f=WPCF7_ContactForm::get_instance($p->ID);
  $mail=$f->prop("mail"); $mail["subject"]="Target competing subject";
  $messages=$f->prop("messages"); $messages["validation_error"]="Target competing validation";
  $f->set_properties(["mail"=>$mail,"messages"=>$messages]); $f->save();
' >/dev/null
CONFLICT_BEFORE=$(cf7_target_hash)
CONFLICT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 competing branch plan" json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "CF7 competing mail/messages did not produce typed conflicts: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered "CF7 unforced competing branch apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "CF7 competing branch did not refuse: $CONFLICT_OUT"
[ "$(cf7_target_hash)" = "$CONFLICT_BEFORE" ] \
  || fail "CF7 unforced conflict partially mutated target state"
FORCED=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 forced competing branch apply" json "$FORCED"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .plan.conflict == 1 and
  (.warnings | any(contains("FORCED conflict") and contains("repository intent authorized")))
' <<<"$FORCED" >/dev/null \
  || fail "CF7 forced repository intent did not converge cleanly: $FORCED"
CONVERGED=$(observe_cf7 conf2)
printf '%s\n' "$CONVERGED" | jq -e '
  . as $observed |
  (.main.mail.subject | contains("Repository competing subject 東京 🚀")) and
  (.main.messages.validation_error | contains($observed.home)) and
  .main.config_validation["target-runtime"] == true and
  .main.constant_contact.list == "target-environment-list" and
  .option.wprism_target_only == "target-option-preserved"
' >/dev/null || fail "CF7 forced conflict resolution lost repository or target-owned state: $CONVERGED"
pass "dirty mail/message conflicts refuse atomically; explicit force converges and preserves target runtime/integration state"

# A late metadata failure must roll back the post title and earlier mail write,
# retain retry authority, and converge after the exact database fault is gone.
wp_conf1 eval '
  $p=get_page_by_path("conformance-contact-form",OBJECT,"wpcf7_contact_form");
  wp_update_post(["ID"=>$p->ID,"post_title"=>"CF7 transaction title 東京 🚀"]);
  $f=WPCF7_ContactForm::get_instance($p->ID); $mail=$f->prop("mail");
  $mail["subject"]="CF7 transaction subject 東京 🚀"; $messages=$f->prop("messages");
  $messages["mail_sent_ok"]="CF7 transaction message " . home_url("/");
  $f->set_properties(["mail"=>$mail,"messages"=>$messages]); $f->save();
' >/dev/null
commit_cf7_source 'conformance: CF7 transactional recovery intent'
FAULT_BEFORE=$(cf7_target_hash)
wp_conf2 db query 'ALTER TABLE wp_postmeta DROP CONSTRAINT IF EXISTS wprism_cf7_fail_messages' >/dev/null
wp_conf2 db query '
  ALTER TABLE wp_postmeta ADD CONSTRAINT wprism_cf7_fail_messages
  CHECK (meta_key <> "_messages" OR meta_value NOT LIKE "%CF7 transaction message%")
' >/dev/null
FAULT_RC=0
FAULT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || FAULT_RC=$?
require_wprism_answered "CF7 injected transaction failure" human "$FAULT_OUT"
# Apply writes authored post meta through Db's checked mutation, whose refusal
# carries no driver text on purpose (agent/src/Kernel/DatabaseExceptions.php:14):
# the native error renders the statement, so it can echo meta payloads -- here,
# CF7 mail and message properties. The constraint name therefore never reaches
# the operator, and asserting it would be asserting a leak. Attribute through
# the product boundary and assert the redaction instead, the idiom
# the-events-calendar/tests/conformance/check.sh already uses for its own late
# postmeta constraint.
[ "$FAULT_RC" -ne 0 ] \
  && grep -Fq 'wprism: database mutation failed: apply reconcile authored post meta' <<<"$FAULT_OUT" \
  || fail "CF7 injected late database failure did not surface at the product boundary: $FAULT_OUT"
! grep -q 'wprism_cf7_fail_messages' <<<"$FAULT_OUT" \
  || fail "CF7 late-failure refusal leaked native driver text, which renders SQL values, into operator output: $FAULT_OUT"
[ "$(cf7_target_hash)" = "$FAULT_BEFORE" ] \
  || fail "CF7 failed transaction left partial post/meta writes"
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail "CF7 failed transaction did not retain retry authority"
wp_conf2 db query 'ALTER TABLE wp_postmeta DROP CONSTRAINT wprism_cf7_fail_messages' >/dev/null
RETRY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 retry after injected failure" json "$RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$RETRY" >/dev/null \
  || fail "CF7 retry did not consume durable intent: $RETRY"
RETRIED=$(observe_cf7 conf2)
printf '%s\n' "$RETRIED" | jq -e '
  . as $observed |
  (.main.mail.subject | contains("CF7 transaction subject 東京 🚀")) and
  (.main.messages.mail_sent_ok | contains($observed.home))
' >/dev/null || fail "CF7 retry did not converge through its native API: $RETRIED"
pass "late CF7 metadata failure rolls back every write, retains authority, and retries cleanly"

# Two real processes race one new repository intent. One may wait and observe
# no work or refuse at the named lock, but final state must be exact and clean.
wp_conf1 eval '
  $p=get_page_by_path("conformance-contact-form",OBJECT,"wpcf7_contact_form"); $f=WPCF7_ContactForm::get_instance($p->ID);
  $mail=$f->prop("mail"); $mail["subject"]="Concurrent CF7 intent 東京 🚀";
  $f->set_properties(["mail"=>$mail]); $f->save();
' >/dev/null
commit_cf7_source 'conformance: concurrent CF7 apply intent'
CONCURRENT_A="${CONF_REPO2:-siterepo/conf2}/.tmp-cf7-concurrent-a.log"
CONCURRENT_B="${CONF_REPO2:-siterepo/conf2}/.tmp-cf7-concurrent-b.log"
set +e
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 wprism apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing CF7 applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing CF7 apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing CF7 apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_cf7 conf2)
printf '%s\n' "$CONCURRENT" | jq -e '.main.mail.subject == "Concurrent CF7 intent 東京 🚀"' >/dev/null \
  || fail "competing CF7 applies lost repository intent: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 plan after competing applies" json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "CF7 competing applies left retained work: $CONCURRENT_PLAN"
pass "competing CF7 applies serialize and leave one exact idempotent result"

# Deactivation is reversible. CF7's native uninstall is destructive (all form
# posts and wpcf7 option); missing code must refuse, then exact digest-bound
# reinstall plus explicit repository authority must reconstruct native forms.
wp_conf2 option update wprism_cf7_neighbor 'target-neighbor-preserved' >/dev/null
wp_conf2 plugin deactivate contact-form-7 >/dev/null
if wp_conf2 plugin is-active contact-form-7 >/dev/null 2>&1; then
  fail "CF7 deactivation premise did not land"
fi
REACTIVATE=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 deploy after deactivation" json "$REACTIVATE"
wp_conf2 plugin is-active contact-form-7 >/dev/null || fail "WPrism deploy did not reactivate exact CF7 code"
# A combined `uninstall --deactivate` keeps WPCF7_VERSION defined in that
# request, and CF7's uninstall.php intentionally skips deletion in that shape.
# A second native request after deactivation is the plugin's destructive path.
wp_conf2 plugin deactivate contact-form-7 >/dev/null
wp_conf2 plugin uninstall contact-form-7 >/dev/null
if wp_conf2 plugin is-installed contact-form-7 >/dev/null 2>&1; then
  fail "CF7 uninstall left plugin code installed"
fi
[ "$(wp_conf2 post list --post_type=wpcf7_contact_form --format=count)" = 0 ] \
  || fail "CF7 native uninstall retained form rows"
if wp_conf2 option get wpcf7 >/dev/null 2>&1; then
  fail "CF7 native uninstall retained its environment option"
fi
[ "$(wp_conf2 option get wprism_cf7_neighbor)" = 'target-neighbor-preserved' ] \
  || fail "CF7 uninstall mutated an unrelated target option"
MISSING_RC=0
MISSING_OUT=$(wp_conf2 wprism deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_wprism_answered "CF7 deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing CF7 code did not refuse at compatibility: $MISSING_OUT"
CF7_SHA=aedc5cc878e1e62187882286e0711168491d744be547addc063311999ef1468d
CF7_ARTIFACT="/artifacts-cache/plugin-contact-form-7-6.1.7-${CF7_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$CF7_ARTIFACT');")" = "$CF7_SHA" ] \
  || fail "cached CF7 reinstall artifact digest moved"
wp_conf2 plugin install "$CF7_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get contact-form-7 --field=version)" = '6.1.7' ] \
  || fail "CF7 exact reinstall reported wrong version"
REINSTALL_DEPLOY=$(wp_conf2 wprism deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 deploy after exact reinstall" json "$REINSTALL_DEPLOY"
wp_conf2 eval '
  $option=(array)get_option("wpcf7",[]);
  $option["wprism_reinstall_target"]="reinstall-env-preserved";
  update_option("wpcf7",$option);
' >/dev/null
REINSTALL_BEFORE=$(cf7_target_hash)
REINSTALL_RC=0
REINSTALL_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || REINSTALL_RC=$?
require_wprism_answered "CF7 unforced apply after destructive uninstall" human "$REINSTALL_OUT"
[ "$REINSTALL_RC" -ne 0 ] && grep -Eq 'slug collisions need explicit resolution|collides with env id' <<<"$REINSTALL_OUT" \
  || fail "CF7 activation default did not require explicit slug adoption: $REINSTALL_OUT"
[ "$(cf7_target_hash)" = "$REINSTALL_BEFORE" ] \
  || fail "CF7 unforced reinstall collision partially mutated target state"
REINSTALL_APPLY=$(wp_conf2 wprism apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 recovery with explicit activation-default adoption" json "$REINSTALL_APPLY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .plan.adopt == 1 and
  (.warnings | any(contains("adopted env post")))
' <<<"$REINSTALL_APPLY" >/dev/null \
  || fail "CF7 exact reinstall did not recover canonical state: $REINSTALL_APPLY"
RECOVERED=$(observe_cf7 conf2)
printf '%s\n' "$RECOVERED" | jq -e '
  .main.mail.subject == "Concurrent CF7 intent 東京 🚀" and
  (.main.form | contains("Name 東京 🚀")) and
  (.legacy.form | contains("legacy-literal")) and
  .option.wprism_reinstall_target == "reinstall-env-preserved"
' >/dev/null || fail "CF7 native state did not recover after exact reinstall: $RECOVERED"
[ "$(wp_conf2 option get wprism_cf7_neighbor)" = 'target-neighbor-preserved' ] \
  || fail "CF7 recovery mutated the unrelated target option"
RECOVERY_FRONT=$(curl -fs "http://localhost:${CONF2_PORT}/conformance-contact/") \
  || fail "CF7 recovered modern page did not render"
RECOVERY_ID=$(jq -r '.main.id' <<<"$RECOVERED")
grep -q "_wpcf7\" value=\"$RECOVERY_ID\"" <<<"$RECOVERY_FRONT" \
  || fail "CF7 recovered modern shortcode did not resolve its reconstructed target form"
FINAL_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "CF7 final recovery plan" json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "CF7 recovery was not idempotent: $FINAL_PLAN"
wp_conf2 wprism capture --repo=/siterepo --out=/siterepo/.tmp-cf7-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-cf7-final" \
  || fail "CF7 final recovered state was not byte-identical"
rm -rf "$CONF_REPO2/.tmp-cf7-final"
pass "deactivate/reactivate, destructive uninstall, absent-code refusal, exact reinstall, explicit recovery, render, and retry are clean"
