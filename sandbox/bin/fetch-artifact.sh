# fetch-artifact.sh — typed, digest-pinned shared artifact resolution.
# Source this file after defining PAIR_COMPOSE as a docker-compose argv array.
#
# validate_artifact_lock [path]
#
# Refuse any registry shape that the resolver or bundle reducer does not
# understand. In particular, an unknown role must never be executable while
# disappearing from the bundle's certified/refusal artifact inventory.
validate_artifact_lock() {
  local lockfile="${1:-${DUO_ARTIFACT_LOCKFILE:-conformance/artifacts.lock.json}}"
  jq -e '
    def safe_slug:
      type == "string" and test("^[a-z0-9][a-z0-9._-]*[a-z0-9]$");
    def safe_version:
      type == "string" and test("^[0-9A-Za-z][0-9A-Za-z._-]*$");
    def safe_entry:
      type == "object" and
      (keys == ["role","sha256","url"] or
       keys == ["archive_root","role","sha256","url"]) and
      (.role == "certified-boundary" or
       .role == "refusal-fixture" or
       .role == "exercise-fixture") and
      (.sha256 | type == "string" and test("^[0-9a-f]{64}$")) and
      (.url | type == "string" and test("^https://[^[:space:]'\''\"]+$")) and
      ((has("archive_root") | not) or (.archive_root | safe_slug));
    def safe_namespace:
      type == "object" and
      all(to_entries[];
        (.key | safe_slug) and
        (.value | type == "object" and length > 0) and
        all(.value | to_entries[];
          (.key | safe_version) and (.value | safe_entry)));
    type == "object" and keys == ["plugins","themes"] and
    (.plugins | safe_namespace) and (.themes | safe_namespace)
  ' "$lockfile" >/dev/null 2>&1 || {
    echo "FAIL: artifact lock is malformed or contains an unsupported namespace, key, role, URL, digest, or archive root" >&2
    return 1
  }
}

# fetch_artifact <slug> <version> <cli-service-name> [plugin|theme]
# prints only the verified container-side ZIP path on stdout. Resolution is
# authorized by conformance/artifacts.lock.json; a miss never falls through to
# a bare WP-CLI catalog install. The mounted runner owns the cross-process lock,
# re-verification, bounded download, and atomic publication.
fetch_artifact() {
  local slug="$1" version="$2" cli="$3" kind="${4:-plugin}"
  local lockfile="${DUO_ARTIFACT_LOCKFILE:-conformance/artifacts.lock.json}"
  local runner="/duo-harness/artifact-cache-fetch.sh"
  local entry url sha256 cache_path source record offline="${DUO_ARTIFACT_OFFLINE:-0}"

  [[ "$slug" =~ ^[a-z0-9][a-z0-9._-]*[a-z0-9]$ ]] \
    || { echo "FAIL: fetch_artifact: invalid artifact slug" >&2; return 1; }
  [[ "$version" =~ ^[0-9A-Za-z][0-9A-Za-z._-]*$ ]] \
    || { echo "FAIL: fetch_artifact: invalid artifact version" >&2; return 1; }
  case "$kind" in
    plugin|theme) ;;
    *) echo "FAIL: fetch_artifact: kind must be plugin or theme" >&2; return 1 ;;
  esac
  case "$offline" in
    0|1) ;;
    *) echo "FAIL: fetch_artifact: DUO_ARTIFACT_OFFLINE must be 0 or 1" >&2; return 1 ;;
  esac
  validate_artifact_lock "$lockfile" || return 1
  entry=$(jq -c --arg namespace "${kind}s" --arg slug "$slug" --arg version "$version" \
    '.[$namespace][$slug][$version] // empty' "$lockfile")
  if [ -z "$entry" ]; then
    echo "FAIL: fetch_artifact: no $kind pin for $slug $version in $lockfile" \
      "— exact artifact installs are mandatory, so an unpinned version is refused, not fetched" >&2
    return 1
  fi
  url=$(echo "$entry" | jq -r '.url')
  sha256=$(echo "$entry" | jq -r '.sha256')
  [[ "$sha256" =~ ^[0-9a-f]{64}$ ]] \
    || { echo "FAIL: fetch_artifact: malformed digest pin for $slug $version" >&2; return 1; }
  if [[ "$url" != https://* || "$url" == *"'"* || "$url" == *" "* \
    || "$url" == *$'\t'* || "$url" == *$'\r'* || "$url" == *$'\n'* ]]; then
    echo "FAIL: fetch_artifact: malformed HTTPS URL pin for $slug $version" >&2
    return 1
  fi
  cache_path="/artifacts-cache/${kind}-${slug}-${version}-${sha256}.zip"

  # Cache writes are infrastructure work and run as root inside the fixed CLI
  # image. Ordinary WP-CLI execution remains uid 33 and only reads the ZIP.
  if ! source=$("${PAIR_COMPOSE[@]}" run --rm -T -u root \
    -e "DUO_ARTIFACT_FORCE_PHP_LOCK=${DUO_ARTIFACT_FORCE_PHP_LOCK:-0}" \
    "$cli" sh "$runner" "$url" "$sha256" "$cache_path" "$offline" "$slug" "$version" "$kind"); then
    return 1
  fi
  case "$source" in
    cache-hit|network-fetch) ;;
    *) echo "FAIL: fetch_artifact: fetch runner returned an invalid source record" >&2; return 1 ;;
  esac

  printf 'artifact-cache: kind=%s slug=%s version=%s sha256=%s source=%s path=%s\n' \
    "$kind" "$slug" "$version" "$sha256" "$source" "$cache_path" >&2
  if [ -n "${DUO_ARTIFACT_USAGE_LOG:-}" ]; then
    record=$(jq -cn --arg kind "$kind" --arg slug "$slug" --arg version "$version" \
      --arg sha256 "$sha256" --arg source "$source" --arg path "$cache_path" \
      '{kind:$kind,slug:$slug,version:$version,sha256:$sha256,source:$source,path:$path}') \
      || return 1
    # One bounded printf opens with O_APPEND and performs one record write.
    printf '%s\n' "$record" >> "$DUO_ARTIFACT_USAGE_LOG" || return 1
  fi
  echo "$cache_path"
}
