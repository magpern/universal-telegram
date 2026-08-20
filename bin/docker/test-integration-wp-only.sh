#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"

wp_version="$(ut_parse_flag --wp-version "$@")"
if [ -z "$wp_version" ]; then
    echo "Usage: $0 --wp-version=6.9 [--php-version=8.1]" >&2
    exit 1
fi

php_version="$(ut_parse_flag --php-version "$@")"
if [ -z "$php_version" ]; then
    if [ "$wp_version" = "6.9" ]; then
        php_version="8.1"
    else
        php_version="8.3"
    fi
fi

ut_compose_run "$php_version" bash -c "
    set -euo pipefail
    tests/bin/install-wp.sh '${wp_version}'
    source /tmp/ut-wp-env.sh
    vendor/bin/phpunit -c phpunit-integration.xml.dist
"
