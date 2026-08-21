#!/usr/bin/env bash
# Runs the dependency-free JS behavioural test suite (M04 plan §7) via
# Node's own built-in test runner, inside a throwaway official Node
# container — no npm dependency, no package.json, no assumption that Node
# is preinstalled on any CI runner.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"

node_version="$(ut_parse_flag --node-version "$@")"
node_version="${node_version:-20}"

docker run --rm \
    -v "${UT_REPO_ROOT}:/app" \
    -w /app \
    "node:${node_version}-alpine" \
    node --test tests/js/
