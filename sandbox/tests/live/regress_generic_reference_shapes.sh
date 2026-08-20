#!/usr/bin/env bash
# Live product-path regression for DUO-3316/DUO-3364. Two neutral fixture
# plugins prove taxonomy object keyspaces and structured EAV sidecars
# independently of every shipped adapter. The attached sidecar combines
# json_refs, key_refs, and order_preserving in one nested value, then proves
# capture, lint, apply, recapture, and no-op convergence. Uses one disposable
# pair and destroys it on exit.
set -euo pipefail
cd "$(dirname "$0")/../.."

say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

command -v jq >/dev/null || fail "jq required"

PAIR="${GENERIC_REFS_PAIR:-codexsma3316}"
PORT1="${GENERIC_REFS_PORT1:-9340}"
PORT2="${GENERIC_REFS_PORT2:-9341}"
export DUO_PAIR="$PAIR" DUO_PORT1="$PORT1" DUO_PORT2="$PORT2" DUO_CODEBIND_PLUGIN=""
COMPOSE=(docker compose -p "duo-$PAIR" -f pair.yml)
SITE1="siterepo/${PAIR}1"
SITE2="siterepo/${PAIR}2"
ORIGIN="siterepo/origin-$PAIR.git"
MANIFEST_DIR="/siterepo/.duo-test-manifests"

wp1_raw() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2_raw() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
wp1() { "${COMPOSE[@]}" run --rm -T cli1 env "DUO_MANIFESTS_DIR=$MANIFEST_DIR" wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 env "DUO_MANIFESTS_DIR=$MANIFEST_DIR" wp "$@"; }

normalize_repo_permissions() {
  # Capture writes as container uid 33. Normalize only this disposable
  # pair's bind roots so a failed run remains safely rerunnable from the host.
  "${COMPOSE[@]}" run --rm -T -u root cli1 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
  "${COMPOSE[@]}" run --rm -T -u root cli2 sh -c 'chmod -R ugo+rwX /siterepo' >/dev/null 2>&1 || true
}

clear_site_root() {
  local root="$1"
  find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
}

cleanup() {
  local status=$?
  trap - EXIT INT TERM
  set +e
  normalize_repo_permissions
  bash bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf -- "$ORIGIN" "$SITE1" "$SITE2"
  if [ -e "$ORIGIN" ] || [ -e "$SITE1" ] || [ -e "$SITE2" ]; then
    printf 'FAIL: generic-reference cleanup left pair-owned repository paths behind\n' >&2
    status=1
  fi
  exit "$status"
}
trap cleanup EXIT INT TERM

say "bring up isolated pair $PAIR ($PORT1/$PORT2)"
bash bin/pair.sh up "$PAIR" "$PORT1" "$PORT2"
pass "pair ready"

say "install two independent fixture plugins"
for side in 1 2; do
  service="wp${side}"
  "${COMPOSE[@]}" exec -T "$service" mkdir -p \
    /var/www/html/wp-content/plugins/duo-taxonomy-keyspace \
    /var/www/html/wp-content/plugins/duo-sidecar-refs
  "${COMPOSE[@]}" cp tests/fixtures/duo-taxonomy-keyspace/duo-taxonomy-keyspace.php \
    "$service:/var/www/html/wp-content/plugins/duo-taxonomy-keyspace/duo-taxonomy-keyspace.php"
  "${COMPOSE[@]}" cp tests/fixtures/duo-sidecar-refs/duo-sidecar-refs.php \
    "$service:/var/www/html/wp-content/plugins/duo-sidecar-refs/duo-sidecar-refs.php"
done
wp1_raw plugin activate duo-taxonomy-keyspace duo-sidecar-refs >/dev/null
wp2_raw plugin activate duo-taxonomy-keyspace duo-sidecar-refs >/dev/null
pass "fixtures active and sidecar schemas created on both environments"

say "initialize a fixture-only site repository and manifest directory"
normalize_repo_permissions
rm -rf -- "$ORIGIN"
clear_site_root "$SITE1"
clear_site_root "$SITE2"
mkdir -p "$SITE1/.duo-test-manifests"
cp ../manifests/core.json "$SITE1/.duo-test-manifests/core.json"
cp tests/fixtures/duo-taxonomy-keyspace/manifest.json \
  "$SITE1/.duo-test-manifests/duo-taxonomy-keyspace-fixture.json"
cp tests/fixtures/duo-sidecar-refs/manifest.json \
  "$SITE1/.duo-test-manifests/duo-sidecar-refs-fixture.json"
cp site-repo.gitignore.template "$SITE1/.gitignore"
printf '\n.duo-test-manifests/\n.tmp-duo3316-*.php\n' >> "$SITE1/.gitignore"

apply_patch_site_config() {
  local target="$1"
  cat > "$target/site.duo.json" <<'JSON'
{
  "manifests": ["core", "duo-taxonomy-keyspace-fixture", "duo-sidecar-refs-fixture"],
  "policy": {
    "options": {
      "default_category": {"class": "env", "required": false},
      "page_for_posts": {"class": "env", "required": false},
      "page_on_front": {"class": "env", "required": false},
      "sticky_posts": {"class": "env", "required": false},
      "wp_page_for_privacy_policy": {"class": "env", "required": false}
    },
    "post_meta": {},
    "post_types": ["dks_article"],
    "scope": {
      "post_type": {
        "page": {"class": "runtime"},
        "post": {"class": "runtime"}
      },
      "taxonomy": {
        "category": {"class": "runtime"}
      }
    },
    "taxonomies": ["dks_post_rel", "dks_term_rel"],
    "term_meta": {}
  },
  "spec_version": 2
}
JSON
}
apply_patch_site_config "$SITE1"
git init --bare -b main "$ORIGIN" >/dev/null
git -C "$SITE1" init -q -b main
git -C "$SITE1" -c user.name="duo-$PAIR" -c user.email="$PAIR@example.test" add -A
git -C "$SITE1" -c user.name="duo-$PAIR" -c user.email="$PAIR@example.test" commit -qm "fixture policy"
git -C "$SITE1" remote add origin "../origin-$PAIR.git"
git -C "$SITE1" push -qu origin main
pass "repository initialized"

say "seed nested descriptions, post/term relationships, and PHP/JSON sidecar values"
POST_ID=$(wp1 post create --post_type=dks_article --post_status=publish \
  --post_title='DUO 3316 Article' --post_content='portable body' --porcelain | tr -d '\r')
POST_TERM_ID=$(wp1 term create dks_post_rel post-owner --porcelain | tr -d '\r')
TERM_A_ID=$(wp1 term create dks_term_rel owner-a --porcelain | tr -d '\r')
TERM_B_ID=$(wp1 term create dks_term_rel owner-b --porcelain | tr -d '\r')
wp1 post term add "$POST_ID" dks_post_rel post-owner --by=slug >/dev/null

cat > "$SITE1/.tmp-duo3316-seed.php" <<'PHP'
<?php
global $wpdb;
$post = get_page_by_title('DUO 3316 Article', OBJECT, 'dks_article');
$a = get_term_by('slug', 'owner-a', 'dks_term_rel');
$b = get_term_by('slug', 'owner-b', 'dks_term_rel');
if (!$post || !$a || !$b) {
    throw new RuntimeException('fixture entities missing');
}
$payload = [
    'links' => ['primary' => ['post_id' => (int) $post->ID, 'term_id' => (int) $b->term_id]],
    'by_term' => [(int) $b->term_id => ['label' => 'target']],
];
$empty = ['links' => [], 'by_term' => []];
$wpdb->update($wpdb->term_taxonomy, ['description' => serialize($payload)], [
    'term_id' => (int) $a->term_id, 'taxonomy' => 'dks_term_rel',
]);
$wpdb->update($wpdb->term_taxonomy, ['description' => serialize($empty)], [
    'term_id' => (int) $b->term_id, 'taxonomy' => 'dks_term_rel',
]);
$targetTt = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='dks_term_rel'",
    (int) $b->term_id
));
$wpdb->insert($wpdb->term_relationships, [
    'object_id' => (int) $a->term_id, 'term_taxonomy_id' => $targetTt, 'term_order' => 0,
]);
$wpdb->insert($wpdb->prefix . 'dks_entries', ['label' => 'portable-entry']);
$entry = (int) $wpdb->insert_id;
$sidecar = [
    'post_id' => (int) $post->ID,
    'nested' => [['term_id' => (int) $b->term_id]],
    'by_term' => [(int) $b->term_id => ['label' => 'sidecar-target']],
];
$ordered = [
    // The insertion order is semantic fixture data, not alphabetical order.
    // zulu precedes alpha, and the term-keyed map deliberately places B
    // before A. Canon must retain both orders while rewriting the IDs.
    'zulu' => [
        'post_id' => (int) $post->ID,
        'term_id' => (int) $b->term_id,
    ],
    'alpha' => [
        'by_term' => [
            (int) $b->term_id => ['label' => 'ordered-b'],
            (int) $a->term_id => ['label' => 'ordered-a'],
        ],
    ],
];
$rows = [
    'opaque' => serialize(['z' => 'keep', 'a' => 'same']),
    'payload' => serialize($sidecar),
    'ordered_payload' => serialize($ordered),
    'payload_json' => json_encode(['deep' => ['post_id' => (int) $post->ID]]),
    'scalar_post' => (string) $post->ID,
];
foreach ($rows as $key => $value) {
    $wpdb->insert($wpdb->prefix . 'dks_entry_meta', [
        'entry_id' => $entry, 'meta_key' => $key, 'meta_value' => $value,
    ]);
}
PHP
wp1 eval-file /siterepo/.tmp-duo3316-seed.php >/dev/null
rm -f "$SITE1/.tmp-duo3316-seed.php"
pass "source IDs: post=$POST_ID post-term=$POST_TERM_ID term-a=$TERM_A_ID term-b=$TERM_B_ID"

say "capture and prove every declared path is portable"
wp1 duo capture --repo=/siterepo >/dev/null
wp1 duo lint --repo=/siterepo >/dev/null
TERM_FILE=$(find "$SITE1/state/terms/dks_term_rel" -name '*--owner-a.json' -print -quit)
TERM_B_FILE=$(find "$SITE1/state/terms/dks_term_rel" -name '*--owner-b.json' -print -quit)
TABLE_FILE=$(find "$SITE1/state/tables/dks_entries" -name '*.json' -print -quit)
[ -n "$TERM_FILE" ] && [ -n "$TERM_B_FILE" ] && [ -n "$TABLE_FILE" ] || fail "fixture canonical files missing"
TERM_A_UUID=$(jq -r '.uuid' "$TERM_FILE")
TERM_B_UUID=$(jq -r '.uuid' "$TERM_B_FILE")
[ "$TERM_A_UUID" != "null" ] && [ "$TERM_B_UUID" != "null" ] \
  || fail "captured term UUIDs are missing"
jq -e '.description.links.primary.post_id | startswith("{{post:")' "$TERM_FILE" >/dev/null \
  || fail "nested description post ref was not tokenized"
jq -e '.description.links.primary.term_id | startswith("{{term:")' "$TERM_FILE" >/dev/null \
  || fail "nested description term ref was not tokenized"
jq -e '.description.by_term | keys[0] | startswith("{{term:")' "$TERM_FILE" >/dev/null \
  || fail "description key ref was not tokenized"
jq -e '.meta.payload.post_id | startswith("{{post:")' "$TABLE_FILE" >/dev/null \
  || fail "PHP sidecar post ref was not tokenized"
jq -e '.meta.payload.nested[0].term_id | startswith("{{term:")' "$TABLE_FILE" >/dev/null \
  || fail "PHP sidecar term ref was not tokenized"
jq -e '.meta.payload.by_term | keys[0] | startswith("{{term:")' "$TABLE_FILE" >/dev/null \
  || fail "PHP sidecar key ref was not tokenized"
jq -e '.meta.ordered_payload | keys_unsorted == ["zulu", "alpha"]' "$TABLE_FILE" >/dev/null \
  || fail "order-preserving sidecar root map was alphabetically reordered"
jq -e '.meta.ordered_payload.zulu.post_id | startswith("{{post:")' "$TABLE_FILE" >/dev/null \
  || fail "order-preserving sidecar post value ref was not tokenized"
jq -e '.meta.ordered_payload.zulu.term_id | startswith("{{term:")' "$TABLE_FILE" >/dev/null \
  || fail "order-preserving sidecar term value ref was not tokenized"
jq -e --arg b "{{term:$TERM_B_UUID}}" --arg a "{{term:$TERM_A_UUID}}" \
  '.meta.ordered_payload.alpha.by_term | keys_unsorted == [$b, $a]' "$TABLE_FILE" >/dev/null \
  || fail "order-preserving sidecar term key refs lost the declared B-to-A insertion order"
jq -e '.meta.payload_json.deep.post_id | startswith("{{post:")' "$TABLE_FILE" >/dev/null \
  || fail "JSON sidecar ref was not tokenized"
jq -e '.meta.opaque == "a:2:{s:1:\"z\";s:4:\"keep\";s:1:\"a\";s:4:\"same\";}"' "$TABLE_FILE" >/dev/null \
  || fail "undeclared opaque sidecar bytes changed"
pass "capture/lint use the same nested value and map-key declarations"

git -C "$SITE1" -c user.name="duo-$PAIR" -c user.email="$PAIR@example.test" add state
git -C "$SITE1" -c user.name="duo-$PAIR" -c user.email="$PAIR@example.test" commit -qm "capture portable fixture"
git -C "$SITE1" push -q origin main

say "clone target, advance every local id counter, then apply"
git clone -q "siterepo/origin-$PAIR.git" "$SITE2"
# The host Git clone creates mode-0644 files owned by the host uid. Capture
# publishes as container uid 33 and must be able to retire its backup tree
# without emitting permission warnings on an otherwise successful recapture.
chmod -R a+rwX "$SITE2"
mkdir -p "$SITE2/.duo-test-manifests"
cp "$SITE1/.duo-test-manifests/"*.json "$SITE2/.duo-test-manifests/"
for i in 1 2 3; do
  filler=$(wp2 post create --post_type=dks_article --post_status=publish --post_title="filler-$i" --porcelain | tr -d '\r')
  wp2 post delete "$filler" --force >/dev/null
done
for taxonomy in dks_post_rel dks_term_rel; do
  filler=$(wp2 term create "$taxonomy" "filler-$taxonomy" --porcelain | tr -d '\r')
  wp2 term delete "$taxonomy" "$filler" >/dev/null
done
wp2 db query "INSERT INTO wp_dks_entries (label) VALUES ('filler'); DELETE FROM wp_dks_entries WHERE label='filler';" >/dev/null

REV=$(git -C "$SITE2" rev-parse HEAD)
APPLY=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV" --format=json | tail -1)
echo "$APPLY"
echo "$APPLY" | jq -e '.canary == "clean" and .applied > 0' >/dev/null \
  || fail "apply did not complete with a clean canary"
wp2 duo lint --repo=/siterepo >/dev/null

TARGET_POST_ID=$(wp2 eval '$post = get_page_by_title("DUO 3316 Article", OBJECT, "dks_article"); if (!$post) { throw new RuntimeException("target post missing"); } echo (int) $post->ID;' | tr -d '\r')
TARGET_TERM_A_ID=$(wp2 term get dks_term_rel owner-a --by=slug --field=term_id | tr -d '\r')
TARGET_TERM_B_ID=$(wp2 term get dks_term_rel owner-b --by=slug --field=term_id | tr -d '\r')
[ "$TARGET_POST_ID" != "$POST_ID" ] || fail "post IDs did not diverge"
[ "$TARGET_TERM_A_ID" != "$TERM_A_ID" ] || fail "term A IDs did not diverge"
[ "$TARGET_TERM_B_ID" != "$TERM_B_ID" ] || fail "term IDs did not diverge"

cat > "$SITE2/.tmp-duo3316-check.php" <<'PHP'
<?php
global $wpdb;
$post = get_page_by_title('DUO 3316 Article', OBJECT, 'dks_article');
$a = get_term_by('slug', 'owner-a', 'dks_term_rel');
$b = get_term_by('slug', 'owner-b', 'dks_term_rel');
$descriptionRaw = $wpdb->get_var($wpdb->prepare(
    "SELECT description FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='dks_term_rel'",
    (int) $a->term_id
));
$description = unserialize($descriptionRaw, ['allowed_classes' => false]);
$relationship = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} tt "
    . "ON tt.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=%d AND tt.term_id=%d "
    . "AND tt.taxonomy='dks_term_rel'",
    (int) $a->term_id,
    (int) $b->term_id
));
$entry = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}dks_entries WHERE label='portable-entry'");
$raw = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_key,meta_value FROM {$wpdb->prefix}dks_entry_meta WHERE entry_id=%d",
    $entry
), OBJECT_K);
$payload = unserialize($raw['payload']->meta_value, ['allowed_classes' => false]);
$ordered = unserialize($raw['ordered_payload']->meta_value, ['allowed_classes' => false]);
$json = json_decode($raw['payload_json']->meta_value, true);
$opaque = 'a:2:{s:1:"z";s:4:"keep";s:1:"a";s:4:"same";}';
$ok = (int) $description['links']['primary']['post_id'] === (int) $post->ID
    && (int) $description['links']['primary']['term_id'] === (int) $b->term_id
    && array_key_exists((int) $b->term_id, $description['by_term'])
    && $relationship === 1
    && (int) $payload['post_id'] === (int) $post->ID
    && (int) $payload['nested'][0]['term_id'] === (int) $b->term_id
    && array_key_exists((int) $b->term_id, $payload['by_term'])
    && array_keys($ordered) === ['zulu', 'alpha']
    && (int) $ordered['zulu']['post_id'] === (int) $post->ID
    && (int) $ordered['zulu']['term_id'] === (int) $b->term_id
    && array_keys($ordered['alpha']['by_term']) === [(int) $b->term_id, (int) $a->term_id]
    && $ordered['alpha']['by_term'][(int) $b->term_id]['label'] === 'ordered-b'
    && $ordered['alpha']['by_term'][(int) $a->term_id]['label'] === 'ordered-a'
    && (int) $json['deep']['post_id'] === (int) $post->ID
    && $raw['opaque']->meta_value === $opaque
    && (int) $raw['scalar_post']->meta_value === (int) $post->ID;
echo wp_json_encode(['ok' => $ok, 'post' => (int) $post->ID, 'term_a' => (int) $a->term_id, 'term_b' => (int) $b->term_id]);
PHP
CHECK=$(wp2 eval-file /siterepo/.tmp-duo3316-check.php | tail -1)
rm -f "$SITE2/.tmp-duo3316-check.php"
echo "$CHECK" | jq -e '.ok == true' >/dev/null || fail "target wire values do not resolve to target-local IDs: $CHECK"
pass "target relationships and PHP/JSON wire values use target-local IDs"

say "recapture is byte-identical and a second apply is a no-op"
wp2 duo capture --repo=/siterepo >/dev/null
diff -ru "$SITE1/state" "$SITE2/state" >/dev/null || fail "source/target canonical state differs after recapture"
REV2=$(git -C "$SITE2" rev-parse HEAD)
NOOP=$(wp2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --format=json | tail -1)
echo "$NOOP" | jq -e '.applied == 0 and .plan.create == 0 and .plan.update == 0' >/dev/null \
  || fail "second apply was not a no-op: $NOOP"
pass "byte identity and no-op convergence proven"

printf '\n\033[1;32m✔ REGRESS_GENERIC_REFERENCE_SHAPES PASSED\033[0m\n'
