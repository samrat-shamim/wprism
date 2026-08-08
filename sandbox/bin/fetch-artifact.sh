# fetch-artifact.sh — DUO-3223's version-boundary matrix / the owner ruling
# on artifact sourcing (issue comment 0ec1d2e3, DUO-3223). Meant to be
# SOURCED (". bin/fetch-artifact.sh"), not executed — it defines one
# function, fetch_artifact(), that reuses the calling script's own
# PAIR_COMPOSE array (every pair-based test already defines one) rather
# than re-encoding a whole docker-compose invocation as its own argument
# surface. This mirrors the existing convention of small in-script helper
# functions (install_plugins(), reset_env_state(), etc.) more than it
# mirrors sandbox/bin/pair.sh's own standalone-executable shape — a fetch
# always happens ON BEHALF OF some specific pair's specific cli service,
# never independently of one.
#
# Contract (this issue's own non-negotiable: "installs exact artifacts; it
# never pulls latest"):
#   - No entry in sandbox/conformance/artifacts.lock.json for the given
#     (slug, version) -> refuse loudly. No fallback to "just fetch it and
#     trust wp.org" for an unpinned version, ever.
#   - Entry present, no cached file yet -> download inside the CALLING
#     pair's own cli container (this host's own shell has no outbound
#     network access, confirmed empirically while researching this issue —
#     the containers do), verify sha256 against the lockfile's declared
#     digest, refuse loudly and delete the partial file on any mismatch.
#   - Entry present, cached file already exists -> re-verify sha256 every
#     time (cheap, local, no network) rather than trusting a prior verified-
#     ness a stale/tampered cache file might no longer deserve. A mismatch
#     here refuses loudly and does NOT silently re-fetch — an operator
#     asking "why did my pinned artifact's bytes change on disk" needs to
#     see that surfaced, not have it silently paper over itself.
#
# Requires: the caller has already layered pair.artifacts.yml onto its own
# PAIR_COMPOSE (-f pair.artifacts.yml, alongside pair.yml/pair.journal.yml/
# etc) — this mounts sandbox/conformance/artifacts-cache/ into cli1/cli2 at
# /artifacts-cache, shared across every pair and every run (deliberately
# NOT per-pair the way siterepo/ is — see pair.artifacts.yml's own comment
# for why that's what makes the cache actually save repeat downloads).
#
# fetch_artifact <slug> <version> <cli-service-name>
#   Prints the container-side path to the verified ZIP on stdout
#   (/artifacts-cache/<slug>-<version>.zip) — pass this straight to
#   `wp plugin install "$(fetch_artifact ...)" --activate`, never a slug or
#   a bare version number, so an install can never silently fall through to
#   wp-cli's own live wp.org fetch of "whatever is current."
fetch_artifact() {
  local slug="$1" version="$2" cli="$3"
  local lockfile="conformance/artifacts.lock.json"
  local entry url sha256 cache_path

  entry=$(jq -c --arg slug "$slug" --arg version "$version" \
    '.[$slug][$version] // empty' "$lockfile")
  if [ -z "$entry" ]; then
    echo "FAIL: fetch_artifact: no pin for $slug $version in $lockfile" \
      "— this issue's own non-negotiable is 'installs exact artifacts;" \
      "never pulls latest,' so an unpinned version is refused, not fetched" >&2
    return 1
  fi
  url=$(echo "$entry" | jq -r '.url')
  sha256=$(echo "$entry" | jq -r '.sha256')
  cache_path="/artifacts-cache/${slug}-${version}.zip"

  # The shared cache is a host bind mount and is intentionally not made
  # world-writable. Fetch/verification is infrastructure work, so perform
  # this one bounded command as root; ordinary wp-cli/plugin execution still
  # runs as the image's unprivileged user and only reads the verified ZIP.
  "${PAIR_COMPOSE[@]}" run --rm -T -u root "$cli" sh -c "
    set -e
    if [ -f '$cache_path' ]; then
      ACTUAL=\$(sha256sum '$cache_path' | cut -d' ' -f1)
      if [ \"\$ACTUAL\" != '$sha256' ]; then
        echo \"FAIL: fetch_artifact: cached $cache_path does not match its pinned digest (expected $sha256, got \$ACTUAL) — refusing, not silently re-fetching; delete the cache file first if a re-fetch is actually intended\" >&2
        exit 1
      fi
      exit 0
    fi
    TMP=\"$cache_path.tmp\"
    curl -fsSL -o \"\$TMP\" '$url'
    ACTUAL=\$(sha256sum \"\$TMP\" | cut -d' ' -f1)
    if [ \"\$ACTUAL\" != '$sha256' ]; then
      rm -f \"\$TMP\"
      echo \"FAIL: fetch_artifact: downloaded $slug $version does not match its pinned digest (expected $sha256, got \$ACTUAL) — refusing, deleting the partial file, not installing it\" >&2
      exit 1
    fi
    mv \"\$TMP\" '$cache_path'
  " >&2 || return 1

  echo "$cache_path"
}
