#!/usr/bin/env sh

# Find where this script is running from.
SCRIPT_DIR="$( cd "$(dirname "$0")" >/dev/null 2>&1 || exit ; pwd -P )"

# Start PHP interactive mode, loading our bootstrap file first.
php -a \
  -d "auto_prepend_file=${SCRIPT_DIR}/bootstrap.php" \
  -d "cli.prompt=\e[032mphp \>\e[0m "

