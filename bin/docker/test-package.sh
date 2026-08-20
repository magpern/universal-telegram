#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"

wp_version="$(ut_parse_flag --wp-version "$@")"
php_version="$(ut_parse_flag --php-version "$@")"
wc_version="$(ut_parse_flag --woocommerce "$@")"

if [ -z "$wp_version" ] || [ -z "$php_version" ]; then
    echo "Usage: $0 --wp-version=7.1 --php-version=8.3 [--woocommerce=11.0.1]" >&2
    exit 1
fi

ut_compose_run "$php_version" bash tests/package/run.sh "$wp_version" "$wc_version"
