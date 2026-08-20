#!/usr/bin/env bash
# Shared helpers for the bin/docker/*.sh wrapper scripts. Not invoked directly.
set -euo pipefail

UT_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
UT_COMPOSE_FILE="${UT_REPO_ROOT}/docker/docker-compose.yml"

ut_parse_flag() {
    # ut_parse_flag --wp-version "$@"  -> echoes the value, or empty string
    local flag="$1"
    shift
    for arg in "$@"; do
        if [[ "$arg" == "${flag}="* ]]; then
            echo "${arg#${flag}=}"
            return 0
        fi
    done
    echo ""
}

ut_compose_run() {
    local php_version="${1:-8.1}"
    shift
    PHP_VERSION="$php_version" docker compose -f "$UT_COMPOSE_FILE" run --rm php "$@"
}

ut_compose_down() {
    docker compose -f "$UT_COMPOSE_FILE" down -v --remove-orphans >/dev/null 2>&1 || true
}
