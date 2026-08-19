#!/usr/bin/env bash
# Runs the plugin unit tests in Docker (no local PHP needed).
set -euo pipefail
cd "$(dirname "$0")/.."

mkdir -p tools
if [ ! -f tools/phpunit.phar ]; then
  echo "Downloading PHPUnit 10…"
  curl -fsSL -o tools/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
fi

HOST_DIR="$(pwd -W 2>/dev/null || pwd)"
# MSYS_NO_PATHCONV stops Git Bash rewriting /app into a Windows path.
exec env MSYS_NO_PATHCONV=1 docker run --rm -v "${HOST_DIR}:/app" -w /app php:8.2-cli \
  php tools/phpunit.phar -c tests/phpunit.xml "$@"
