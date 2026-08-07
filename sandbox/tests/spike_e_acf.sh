#!/usr/bin/env bash
# Spike E — ACF interpreter manifest round-trip:
#   schema-driven meta classification (design finding #12 — which meta values
#   are id refs is determined by ACF's field-group definitions in the DB, not
#   a static key list) plus the verbatim-body path (finding #13) for the
#   field/group definitions that make the schema readable in the first place.
#   Own dedicated env pair (e1 :8804 / e2 :8805, profile "spikee"); this
#   script boots, installs, and seeds them itself — it never touches envs
#   a/b/c or their site repos.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yml --profile spikee"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

wp_env() { # wp_env <e1|e2> <wp args...>
  local env="$1"; shift
  $COMPOSE run --rm -T "cli-$env" wp "$@"
}
wp_e1() { wp_env e1 "$@"; }
wp_e2() { wp_env e2 "$@"; }

wait_for() { # wait_for <e1|e2>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    wp_env "$env" core version >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo "env $env never became ready" >&2
  exit 1
}

# wp-cli can't write .htaccess without extra config; apache needs it for pretty
# permalinks — same two steps sandbox/setup.sh performs for envs A/B on this
# identical docker image.
write_htaccess() { # write_htaccess <e1|e2>
  $COMPOSE exec -T -u www-data "wp-$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF
}

install_env() { # install_env <e1|e2> <port> <title>
  local env="$1" port="$2" title="$3"
  wait_for "$env"
  if ! wp_env "$env" core is-installed >/dev/null 2>&1; then
    wp_env "$env" core install \
      --url="http://localhost:$port" --title="$title" \
      --admin_user=admin --admin_password=admin \
      --admin_email=admin@example.test --skip-email
    wp_env "$env" theme install twentytwentyone --activate
    wp_env "$env" option update permalink_structure '/%postname%/'
    wp_env "$env" rewrite flush
    write_htaccess "$env"
    wp_env "$env" site empty --yes
    echo "env $env installed"
  else
    echo "env $env already installed"
  fi
  wp_env "$env" plugin is-installed advanced-custom-fields >/dev/null 2>&1 \
    || wp_env "$env" plugin install advanced-custom-fields --activate
}

say "boot env pair e1 (:8804) / e2 (:8805)"
mkdir -p siterepo/e1 siterepo/e2
$COMPOSE up -d db-e1 wp-e1 db-e2 wp-e2
install_env e1 8804 "Duo E1"
install_env e2 8805 "Duo E2"
pass "both envs installed with ACF (free) active"

say "init the site repo (own origin, own clones — never touches siterepo/a|b|c)"
if [ ! -d siterepo/origin-e.git ]; then
  git init --bare -b main siterepo/origin-e.git >/dev/null
fi
if [ ! -d siterepo/e1/.git ]; then
  cat > siterepo/e1/site.duo.json <<'EOF'
{
  "manifests": ["core", "acf"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "acf-field-group", "acf-field"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 1
}
EOF
  cp site-repo.gitignore.template siterepo/e1/.gitignore
  git -C siterepo/e1 init -q -b main
  git -C siterepo/e1 remote add origin ../origin-e.git
fi

say "seed ACF schema + content on e1: field group, image field, relationship field"
# EXPLICIT keys throughout (group_duo_demo / field_duo_hero / field_duo_related)
# so the DB-backed field/group posts are deterministic across runs. The field
# group's DB post ID (not its key) is what acf_update_field()'s 'parent' wants.
cat > siterepo/e1/.tmp-seed-acf.php <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) {
    fwrite(STDERR, "ACF functions not available\n");
    exit(1);
}

acf_update_field_group([
    'key' => 'group_duo_demo',
    'title' => 'Duo Demo',
    'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'menu_order' => 0,
    'position' => 'normal',
    'style' => 'default',
    'label_placement' => 'top',
    'instruction_placement' => 'label',
    'active' => true,
]);
$group_posts = get_posts([
    'post_type' => 'acf-field-group', 'name' => 'group_duo_demo',
    'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any',
]);
$group_id = $group_posts ? (int) $group_posts[0] : 0;
if (!$group_id) {
    fwrite(STDERR, "field group not created\n");
    exit(1);
}

acf_update_field([
    'key' => 'field_duo_hero',
    'label' => 'Hero Image',
    'name' => 'duo_hero',
    'type' => 'image',
    'parent' => $group_id,
    'return_format' => 'id',
]);
acf_update_field([
    'key' => 'field_duo_related',
    'label' => 'Related',
    'name' => 'duo_related',
    'type' => 'relationship',
    'parent' => $group_id,
    'post_type' => ['post'],
    'return_format' => 'id',
]);

// an image attachment (GD-generated, like spike A's)
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$upload_dir = wp_upload_dir();
$filename = trailingslashit($upload_dir['path']) . 'duo-acf-logo.png';
$im = imagecreatetruecolor(96, 64);
imagefilledrectangle($im, 0, 0, 95, 63, imagecolorallocate($im, 200, 90, 30));
imagepng($im, $filename);
imagedestroy($im);

$filetype = wp_check_filetype(basename($filename), null);
$att_id = wp_insert_attachment([
    'post_mime_type' => $filetype['type'],
    'post_title' => 'Duo ACF Logo',
    'post_content' => '',
    'post_status' => 'inherit',
], $filename);
if (is_wp_error($att_id) || !$att_id) {
    fwrite(STDERR, "attachment insert failed\n");
    exit(1);
}
update_post_meta($att_id, '_wp_attachment_image_alt', 'Duo ACF logo');
$meta = wp_generate_attachment_metadata($att_id, $filename);
wp_update_attachment_metadata($att_id, $meta);

// two relationship targets + the content post carrying both field values
$target1 = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Duo Related Target One', 'post_name' => 'duo-related-target-one',
    'post_content' => "<!-- wp:paragraph -->\n<p>Relationship target one.</p>\n<!-- /wp:paragraph -->",
], true);
$target2 = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Duo Related Target Two', 'post_name' => 'duo-related-target-two',
    'post_content' => "<!-- wp:paragraph -->\n<p>Relationship target two.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($target1) || is_wp_error($target2)) {
    fwrite(STDERR, "target post insert failed\n");
    exit(1);
}

$content_id = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Duo ACF Content', 'post_name' => 'duo-acf-content',
    'post_content' => "<!-- wp:paragraph -->\n<p>Carries ACF fields.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($content_id)) {
    fwrite(STDERR, "content post insert failed\n");
    exit(1);
}

update_field('duo_hero', $att_id, $content_id);
update_field('duo_related', [$target1, $target2], $content_id);

echo json_encode([
    'group' => $group_id, 'attachment' => $att_id,
    'target1' => $target1, 'target2' => $target2, 'content' => $content_id,
]) . "\n";
PHPEOF
SEED_JSON=$(wp_e1 eval-file /siterepo/.tmp-seed-acf.php)
rm -f siterepo/e1/.tmp-seed-acf.php
echo "$SEED_JSON" | jq .
pass "seeded group_duo_demo (image field_duo_hero, relationship field_duo_related) + content"

say "capture e1 into the site repo (schema-driven meta classification runs here)"
wp_e1 duo capture --repo=/siterepo
git -C siterepo/e1 add -A
git -C siterepo/e1 -c user.name=duo -c user.email=duo@example.test commit -qm "capture: seeded ACF content on e1"
git -C siterepo/e1 push -qu origin main

say "acceptance: capture is deterministic (capture twice, zero diff)"
wp_e1 duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/e1/state siterepo/e1/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/e1/.tmp-state2
pass "capture-twice diff is empty"

say "clone the repo for env e2"
if [ -d siterepo/e2/.git ]; then
  git -C siterepo/e2 checkout -q main && git -C siterepo/e2 pull -q origin main
else
  rm -rf siterepo/e2 && git clone -q siterepo/origin-e.git siterepo/e2
fi

say "apply e1's captured state onto e2"
REV=$(git -C siterepo/e2 rev-parse HEAD)
APPLY_JSON=$(wp_e2 duo apply --repo=/siterepo --adopt-by-slug=terms --default-author=admin --revision="$REV" --json | tail -1)
echo "$APPLY_JSON" | jq .
[ "$(echo "$APPLY_JSON" | jq -r '.canary')" = "clean" ] || fail "side-effect canary was not clean during apply"
pass "apply succeeded, side-effect canary clean"

say "acceptance: canonical(e2) == canonical(e1), byte for byte"
wp_e2 duo capture --repo=/siterepo --out=/siterepo/.tmp-e2state >/dev/null
diff -r siterepo/e1/state siterepo/e2/.tmp-e2state || fail "round-trip mismatch between e1 and e2"
rm -rf siterepo/e2/.tmp-e2state
pass "canonical state identical: interpreter-typed refs remapped, string-cast serialized arrays intact, verbatim field bodies byte-preserved"

say "acceptance: get_field('duo_hero') on e2 resolves to a byte-identical attachment"
CONTENT_E2=$(wp_e2 post list --post_type=post --name=duo-acf-content --field=ID)
HERO_ATT_E2=$(wp_e2 eval "echo get_field('duo_hero', $CONTENT_E2);")
[ -n "$HERO_ATT_E2" ] || fail "get_field(duo_hero) returned nothing on e2"
HERO_FILE_REL=$(wp_e2 post meta get "$HERO_ATT_E2" _wp_attached_file)
E1_MEDIA=$(grep -h '"media"' siterepo/e1/state/posts/attachment/*.md | sed 's/.*"media": "\([^"]*\)".*/\1/')
E1_SHA=${E1_MEDIA%.*}
E2_SHA=$($COMPOSE run --rm -T cli-e2 bash -c "sha256sum /var/www/html/wp-content/uploads/$HERO_FILE_REL | cut -d' ' -f1")
[ "$E1_SHA" = "$E2_SHA" ] || fail "e2's duo_hero attachment content does not match e1's (got $E2_SHA, want $E1_SHA)"
pass "get_field(duo_hero) on e2 -> attachment #$HERO_ATT_E2, byte-identical to e1's upload"

say "acceptance: get_field('duo_related') on e2 resolves to the correct e2-local targets"
RELATED_SLUGS=$(wp_e2 eval "
\$ids = get_field('duo_related', $CONTENT_E2);
echo implode(',', array_map(fn(\$id) => get_post(\$id)->post_name, (array) \$ids));
")
[ "$RELATED_SLUGS" = "duo-related-target-one,duo-related-target-two" ] \
  || fail "get_field(duo_related) slugs on e2 are '$RELATED_SLUGS', expected the two seeded targets"
pass "get_field(duo_related) on e2 -> slugs match by identity ($RELATED_SLUGS)"

say "acceptance: verbatim acf-field post_content still unserializes correctly on e2"
UNSER_OK=$(wp_e2 eval "
\$rows = get_posts(['post_type'=>'acf-field','name'=>'field_duo_hero','posts_per_page'=>1,'post_status'=>'any']);
\$def = maybe_unserialize(\$rows[0]->post_content ?? '');
echo (is_array(\$def) && (\$def['type'] ?? null) === 'image') ? 'ok' : 'fail';
")
[ "$UNSER_OK" = "ok" ] || fail "field_duo_hero's verbatim post_content does not unserialize to a valid field def on e2"
pass "field_duo_hero's post_content survived the verbatim body path and still unserializes to type=image"

printf '\n\033[1;32m✔ SPIKE E PASSED\033[0m\n'
