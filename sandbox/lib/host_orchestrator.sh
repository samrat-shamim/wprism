#!/usr/bin/env bash
# Shared host boundary for pair-backed product tests. Phase-owning adapters
# must enter deploy through cli/wprism; invoking `wp wprism deploy` inside the
# target would let a plugin process impersonate the orchestrator.

wprism_host_registry_create() { # <file> <compose-file> <pair> [repo-path]
  local file="$1" compose_file="$2" pair="$3" repo_path="${4:-/siterepo}"
  jq -n \
    --arg compose_file "$compose_file" \
    --arg pair "$pair" \
    --arg repo_path "$repo_path" \
    '{envs: {
      (($pair + "1")): {
        transport: "docker", compose_file: $compose_file,
        service: "cli1", repo_path: $repo_path
      },
      (($pair + "2")): {
        transport: "docker", compose_file: $compose_file,
        service: "cli2", repo_path: $repo_path
      }
    }}' > "$file"
}

wprism_host_call() { # <cli> <registry> <project> <env> <verb> [args...]
  local cli="$1" registry="$2" project="$3" environment="$4" verb="$5"
  shift 5
  COMPOSE_PROJECT_NAME="$project" php "$cli" --envs-file="$registry" \
    "$verb" "$environment" "$@"
}
