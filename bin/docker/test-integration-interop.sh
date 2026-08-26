#!/usr/bin/env bash
# Runs the SC<->UT cross-plugin interoperability harness
# (tests/integration/Interop): a real, disposable WordPress install with
# BOTH plugins' real source loaded (universal-telegram from this checkout,
# universal-support-chat from the sibling checkout mounted by
# docker/docker-compose.interop.yml), proving real Contract v1 interop.
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"

wp_version="$(ut_parse_flag --wp-version "$@")"
wp_version="${wp_version:-6.9}"

php_version="$(ut_parse_flag --php-version "$@")"
php_version="${php_version:-8.3}"

ut_compose_run_interop "$php_version" bash -c "
    set -euo pipefail
    tests/bin/install-wp.sh '${wp_version}'
    source /tmp/ut-wp-env.sh
    tests/bin/install-support-chat.sh
    vendor/bin/phpunit -c phpunit-interop.xml.dist
"
