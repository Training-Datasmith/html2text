#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
IMAGE_TAG='html2text-system-test:remote-test-2026-11-09'
COMPOSER_URL='https://getcomposer.org/download/2.8.10/composer.phar'
COMPOSER_SHA256='28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573'

if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
  DOCKER=(sudo docker)
else
  DOCKER=(docker)
fi

"${DOCKER[@]}" build -f "${ROOT}/docker/system-test/Dockerfile" -t "${IMAGE_TAG}" "${ROOT}/docker"

WORKTREE="${ROOT}/.baseline-master-worktree"
sudo rm -rf "${WORKTREE}"
git worktree add --detach "${WORKTREE}" master

read -r -d '' INNER <<'EOS' || true
set -euo pipefail
export COMPOSER_ALLOW_SUPERUSER=1
curl -fsSL -o /tmp/composer.phar "COMPOSER_URL_PLACEHOLDER"
echo "COMPOSER_SHA256_PLACEHOLDER  /tmp/composer.phar" | sha256sum -c -
cd /workspace
cat >composer.json <<'JSON'
{
  "name": "soundasleep/html2text-baseline",
  "require": { "php": "^7.3|^8.0", "ext-dom": "*", "ext-libxml": "*" },
  "require-dev": { "phpunit/phpunit": "8.5.56" },
  "autoload": { "psr-4": { "Soundasleep\\": "src" } },
  "config": { "lock": false }
}
JSON
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress --no-ansi
vendor/bin/phpunit --colors=never --do-not-cache-result 2>&1 | tee /tmp/phpunit.out
grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions\)$' /tmp/phpunit.out | tail -n 1
EOS

INNER="${INNER//COMPOSER_URL_PLACEHOLDER/${COMPOSER_URL}}"
INNER="${INNER//COMPOSER_SHA256_PLACEHOLDER/${COMPOSER_SHA256}}"

OK_LINE="$("${DOCKER[@]}" run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "${WORKTREE}:/workspace" -w /workspace "${IMAGE_TAG}" bash -lc "$INNER")"
OK_LINE="$(printf '%s\n' "${OK_LINE}" | grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions\)$' | tail -n 1)"

sudo rm -rf "${WORKTREE}"
git worktree prune 2>/dev/null || true

if [[ -z "${OK_LINE}" ]]; then
  echo "Failed to capture PHPUnit OK line from master tree" >&2
  exit 1
fi

python3 - <<PY
from pathlib import Path
path = Path("${ROOT}/scripts/upstream-baseline.php")
line = """${OK_LINE}"""
contents = f"""<?php

declare(strict_types=1);

return [
    'phpunit_ok_line' => {line!r},
];
"""
path.write_text(contents)
PY

echo "Recorded baseline: ${OK_LINE}"
