#!/usr/bin/env bash
set -euo pipefail
VP_CAPSULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
capture_wprism_json_success VP_TARGET 'Visual Portfolio native target identities and media' wp_conf2 eval-file /siterepo/.tmp-vp-capture/roundtrip-native.php observe --use-include --user=admin
printf '%s\n' "$VP_TARGET" > "$CONF_REPO2/.tmp-vp-capture/roundtrip-target.json"
php -r 'require $argv[1]; VisualPortfolioRoundtripEvidence::compare(json_decode(file_get_contents($argv[2]),true,flags:JSON_THROW_ON_ERROR), json_decode(file_get_contents($argv[3]),true,flags:JSON_THROW_ON_ERROR));'   "$VP_CAPSULE/fixtures/native/roundtrip-evidence.php"   "$CONF_REPO1/.tmp-vp-capture/roundtrip-source.json" "$CONF_REPO2/.tmp-vp-capture/roundtrip-target.json"
pass 'Visual Portfolio native identities, selected settings, original media and gallery references round trip'
VP_HTTP=$(mktemp -d "$VP_CAPSULE/../../sandbox/tmp/vp-native-http.$CONF_PAIR.XXXXXX")
printf 'Visual Portfolio HTTP evidence: %s\n' "$VP_HTTP"
for side in 1 2; do
  port="$CONF1_PORT"; record="$CONF_REPO1/.tmp-vp-capture/roundtrip-source.json"
  if [ "$side" = 2 ]; then port="$CONF2_PORT"; record="$CONF_REPO2/.tmp-vp-capture/roundtrip-target.json"; fi
  cp "$record" "$VP_HTTP/$side-record.json"
  for kind in gallery archive; do
    slug=vp-author-gallery; [ "$kind" != archive ] || slug=vp-alternate-archive
    curl --fail --silent --show-error --max-time 30 "http://localhost:$port/$slug/" > "$VP_HTTP/$side-$kind.html"
    php -r 'require $argv[1]; $record=json_decode(file_get_contents($argv[3]),true,flags:JSON_THROW_ON_ERROR); foreach(VisualPortfolioRoundtripEvidence::rendered(file_get_contents($argv[2]),$record,$argv[4]) as $url) echo $url,"\n";' \
      "$VP_CAPSULE/fixtures/native/roundtrip-evidence.php" "$VP_HTTP/$side-$kind.html" "$record" "$kind" > "$VP_HTTP/$side-$kind-assets.txt"
    index=0
    while IFS= read -r url; do
      index=$((index + 1))
      image="$VP_HTTP/$side-$kind-$index.png"
      curl --fail --silent --show-error --max-time 30 "$url" > "$image"
      php -r '$info=getimagesize($argv[1]); if(!is_array($info) || $info[0] < 1 || $info[1] < 1 || $info[2] !== IMAGETYPE_PNG) throw new RuntimeException("VP rendered asset is not a nonempty PNG");' "$image"
    done < "$VP_HTTP/$side-$kind-assets.txt"
  done
done
pass 'Visual Portfolio complete HTTP galleries and archives render bound identities and downloadable images on both sites'
