#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"

wp_version="$(ut_parse_flag --wp-version "$@")"
wc_version="$(ut_parse_flag --wc-version "$@")"
if [ -z "$wp_version" ] || [ -z "$wc_version" ]; then
    echo "Usage: $0 --wp-version=7.1 --wc-version=11.0.1 [--php-version=8.3]" >&2
    exit 1
fi

php_version="$(ut_parse_flag --php-version "$@")"
php_version="${php_version:-8.3}"

ut_compose_run "$php_version" bash -c "
    set -euo pipefail
    tests/bin/install-wp.sh '${wp_version}' '${wc_version}'
    source /tmp/ut-wp-env.sh
    vendor/bin/phpunit -c phpunit-integration.xml.dist
"
