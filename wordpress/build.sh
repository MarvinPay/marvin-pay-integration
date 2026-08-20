#!/usr/bin/env bash
# Marvin Pay WordPress plugin build: lint every PHP file, then zip.
# Usage: bash build.sh        → lint + dist/marvinpay-wordpress-<version>.zip
#        bash build.sh lint   → lint only
set -euo pipefail
cd "$(dirname "$0")"

HOST_DIR="$(pwd -W 2>/dev/null || pwd)"

echo "── php -l over every plugin file ──"
# MSYS_NO_PATHCONV stops Git Bash rewriting /app into a Windows path.
MSYS_NO_PATHCONV=1 docker run --rm -v "${HOST_DIR}:/app" -w /app php:8.2-cli bash -c \
  'set -e; find marvinpay -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null && echo "lint OK"'

if [ "${1:-}" = "lint" ]; then
  exit 0
fi

VERSION="$(sed -n 's/^ \* Version: *\([0-9.]*\).*$/\1/p' marvinpay/marvinpay.php | head -1)"
if [ -z "$VERSION" ]; then
  echo "Could not read version from marvinpay/marvinpay.php" >&2
  exit 1
fi
sed -i "s/^Stable tag: .*/Stable tag: ${VERSION}/" marvinpay/readme.txt

mkdir -p dist
OUT="dist/marvinpay-wordpress-${VERSION}.zip"
rm -f "$OUT"

if command -v zip > /dev/null 2>&1; then
  zip -rq "$OUT" marvinpay
else
  # No native zip (typical Git Bash): build inside Linux so entry names use
  # forward slashes. PowerShell's Compress-Archive writes backslash entry
  # names, which extract as literal 'marvinpay\foo' FILES on Linux WordPress
  # hosts — the plugin would fail to install.
  MSYS_NO_PATHCONV=1 docker run --rm -v "${HOST_DIR}:/app" -w /app python:3-alpine \
    python -m zipfile -c "$OUT" marvinpay
fi

echo "built ${OUT}"
