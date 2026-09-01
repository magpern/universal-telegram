#!/usr/bin/env bash
# Wrapper: run the repository-local release packager inside the Docker php
# service so no host PHP/Composer install is required. Forwards an optional
# version argument to scripts/build-release-package.sh.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"
ut_compose_run "8.1" bash scripts/build-release-package.sh "$@"
