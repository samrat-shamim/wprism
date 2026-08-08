#!/usr/bin/env bash
# Yoast SEO render/API-level acceptance (DUO-3223): byte-identical
# canonical state proves the raw postmeta/option bytes round-tripped, but
# every ref-typed value conformance/seeds/yoast.sh authors
# (_yoast_wpseo_primary_category, wpseo_taxonomy_meta's per-term
# opengraph-image-id, wpseo_titles/wpseo_social's company/person/default
# logo ids) is stored as conf1's own local id — this check proves Yoast's
# OWN runtime (WPSEO_Primary_Term, WPSEO_Taxonomy_Meta, WPSEO_Options —
# the same APIs the frontend/admin actually call, never a raw meta read)
# resolves each of them correctly on a FRESH conf2 process using conf2's
# own ids. Also curls the seeded post's live front-end rendering (matching
# checks/elementor.sh's methodology) to prove the generated <title>/meta
# description tag — the actual thing a search engine or social share sees —
# reflects the authored SEO title/description on conf2, not a stale or
# corrupted value.
#
# Buffer the curl body into a variable before grepping it: under
# `pipefail`, `curl | grep -q` dies with curl's EPIPE (rc 23) whenever grep
# matches before curl finishes writing — see checks/elementor.sh's
# identical comment.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; $COMPOSE/fail/pass are exported by run.sh itself, CONF2_PORT
# is exported by run.sh (default below matches the legacy conf1/conf2 port
# for any standalone invocation).
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

API_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$post = get_page_by_path("conformance-yoast-post", OBJECT, "post");
if (!$post) { echo "NO_POST"; exit; }
$id = $post->ID;

// Primary category (WPSEO_Primary_Term, the same class Yoast'"'"'s own
// breadcrumbs/schema output calls) -- must resolve to "Conformance
// Primary" BY NAME, since conf2 assigned that category a different
// term_id than conf1 did.
$primary_term_obj = new WPSEO_Primary_Term("category", $id);
$primary_term_id = $primary_term_obj->get_primary_term();
$primary_term = $primary_term_id ? get_term($primary_term_id, "category") : null;
$primary_name = $primary_term && !is_wp_error($primary_term) ? $primary_term->name : "";

// Per-term SEO meta (WPSEO_Taxonomy_Meta::get_term_meta(), the taxonomy
// json_refs sub-key mechanism task #11 wave 2 built) -- the stored
// opengraph-image-id must resolve to a real, live attachment on conf2.
$cat = get_term_by("slug", "conformance-primary", "category");
$tax_meta = $cat ? WPSEO_Taxonomy_Meta::get_term_meta($cat->term_id, "category") : null;
$og_image_id = $tax_meta["wpseo_opengraph-image-id"] ?? null;
$og_image_ok = $og_image_id && get_post((int) $og_image_id) ? "yes" : "no";

// wpseo_titles/wpseo_social logo+default-image sub-keys (task #31) --
// WPSEO_Options::get() is Yoast'"'"'s own public reader.
$company_logo_id = WPSEO_Options::get("company_logo_id");
$person_logo_id = WPSEO_Options::get("person_logo_id");
$og_default_id = WPSEO_Options::get("og_default_image_id");
$company_ok = $company_logo_id && get_post((int) $company_logo_id) ? "yes" : "no";
$person_ok = $person_logo_id && get_post((int) $person_logo_id) ? "yes" : "no";
$og_default_ok = $og_default_id && get_post((int) $og_default_id) ? "yes" : "no";

echo implode("|", [$primary_name, $og_image_ok, $company_ok, $person_ok, $og_default_ok]);
' 2>&1 | tail -1)
echo "conf2 Yoast API resolution: $API_OUT"

[ "$API_OUT" != "NO_POST" ] || fail "conf2 has no 'conformance-yoast-post' post — seed content did not round-trip"

IFS='|' read -r PRIMARY_NAME OG_IMAGE_OK COMPANY_OK PERSON_OK OG_DEFAULT_OK <<< "$API_OUT"

[ "$PRIMARY_NAME" = "Conformance Primary" ] \
  || fail "conf2's WPSEO_Primary_Term did not resolve _yoast_wpseo_primary_category to 'Conformance Primary' (got: '$PRIMARY_NAME') -- ref not rebound to conf2's own term id"
[ "$OG_IMAGE_OK" = "yes" ] \
  || fail "conf2's WPSEO_Taxonomy_Meta per-term wpseo_opengraph-image-id did not resolve to a live attachment (got: $API_OUT)"
[ "$COMPANY_OK" = "yes" ] || fail "conf2's WPSEO_Options company_logo_id did not resolve to a live attachment (got: $API_OUT)"
[ "$PERSON_OK" = "yes" ] || fail "conf2's WPSEO_Options person_logo_id did not resolve to a live attachment (got: $API_OUT)"
[ "$OG_DEFAULT_OK" = "yes" ] || fail "conf2's WPSEO_Options og_default_image_id did not resolve to a live attachment (got: $API_OUT)"
pass "conf2 resolves primary-category, per-term OG image, and site-wide logo/default-image refs via Yoast's own runtime APIs, all using conf2's own local ids"

echo "conf2 front-end rendering check: the actual <title>/meta description tag a search engine or social share sees"
FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/conformance-yoast-post/") \
  || fail "conf2 conformance-yoast-post did not return 200"
[ "${#FRONT}" -ge 1000 ] \
  || fail "conf2 conformance-yoast-post response was suspiciously short (${#FRONT} bytes)"
if grep -qiE 'fatal error|uncaught' <<<"$FRONT"; then
  fail "conf2 conformance-yoast-post contains a PHP fatal error marker"
fi
grep -q 'Conformance Yoast Post' <<<"$FRONT" \
  || fail "conf2's rendered page title does not include the authored Yoast SEO title (_yoast_wpseo_title)"
grep -qi 'A meta description written for the Yoast conformance seed' <<<"$FRONT" \
  || fail "conf2's rendered <meta name=\"description\"> does not carry the authored _yoast_wpseo_metadesc"
if grep -Fq "http://localhost:${CONF1_PORT}" <<<"$FRONT"; then
  fail "conf2's rendered Yoast page leaks the conf1 host"
fi
pass "conf2's live front-end rendering carries the authored Yoast SEO title/description with no fatal marker or conf1 host leak"
