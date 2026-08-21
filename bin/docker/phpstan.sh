#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/_lib.sh"
# --memory-limit=1G: the WooCommerce stub file added for M03
# (vendor/php-stubs/woocommerce-stubs) is large enough that PHPStan's
# default 512M worker limit is no longer sufficient.
ut_compose_run "8.1" vendor/bin/phpstan analyse --memory-limit=1G "$@"
