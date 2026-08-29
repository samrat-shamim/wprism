#!/usr/bin/env bash
# Source-only helper for central live evidence that needs a private adapter
# library. The caller provides COMPOSE as an array; no process-global selector
# or WP-CLI path flag is exposed.

wprism_with_adapter_library() { # <service> <container-library-root> <command> [--flag[=value] ...]
  local service="${1:-}" library_root="${2:-}" subcommand="${3:-}" assoc='{}'
  local arg key value assoc_b64
  shift 3 || return 64
  case "$subcommand" in
    capture|plan|apply|deploy|lint) ;;
    *) printf 'explicit adapter library refuses unsupported command: %s\n' "$subcommand" >&2; return 64 ;;
  esac
  for arg in "$@"; do
    case "$arg" in
      --*=*)
        key="${arg%%=*}"; key="${key#--}"; value="${arg#*=}"
        assoc=$(jq -cn --argjson obj "$assoc" --arg key "$key" --arg value "$value" '$obj + {($key): $value}') \
          || return 64
        ;;
      --*)
        key="${arg#--}"
        assoc=$(jq -cn --argjson obj "$assoc" --arg key "$key" '$obj + {($key): true}') \
          || return 64
        ;;
      *) printf 'explicit adapter library refuses positional argument: %s\n' "$arg" >&2; return 64 ;;
    esac
  done
  assoc_b64=$(printf '%s' "$assoc" | base64 | tr -d '\n')
  "${COMPOSE[@]}" run --rm -T \
    -e WPRISM_TEST_ADAPTER_LIBRARY="$library_root" \
    -e WPRISM_TEST_ASSOC_B64="$assoc_b64" \
    -e WPRISM_TEST_SUBCOMMAND="$subcommand" "$service" wp eval '
      $subcommand = (string) getenv("WPRISM_TEST_SUBCOMMAND");
      $assoc = json_decode(base64_decode((string) getenv("WPRISM_TEST_ASSOC_B64"), true), true, 512, JSON_THROW_ON_ERROR);
      $assoc["adapter_library"] = \WPrism\AdapterLibrary::fromSourceTree((string) getenv("WPRISM_TEST_ADAPTER_LIBRARY"));
      (new \WPrism\Cli())->{$subcommand}([], $assoc);
    '
}
