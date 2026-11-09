#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
IMAGE_TAG='html2text-system-test:remote-test-2026-11-09'
COMPOSER_URL='https://getcomposer.org/download/2.8.10/composer.phar'
COMPOSER_SHA256='28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573'
LOG_DIR="${ROOT}/.system-test"
LOG_FILE="${LOG_DIR}/system-test.log"

if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
  DOCKER=(sudo docker)
else
  DOCKER=(docker)
fi

mkdir -p "${LOG_DIR}"

strip_ansi() {
  sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g'
}

output_has_blocked_messages() {
  local stripped
  stripped="$(printf '%s\n' "$1" | strip_ansi)"
  if printf '%s\n' "${stripped}" | grep -Eq '^(PHP )?(Warning|Deprecated|Notice):'; then
    return 0
  fi
  return 1
}

log() {
  printf '%s\n' "$*" | tee -a "${LOG_FILE}"
}

BASELINE_LINE="$(python3 <<PY
import pathlib, re
text = pathlib.Path("${ROOT}/scripts/upstream-baseline.php").read_text()
m = re.search(r"'phpunit_ok_line'\\s*=>\\s*'([^']+)'", text)
print(m.group(1) if m else "")
PY
)"

if [[ -z "${BASELINE_LINE}" ]]; then
  log "FAIL: scripts/upstream-baseline.php has no phpunit_ok_line; run scripts/record-upstream-baseline.sh first"
  exit 2
fi

log "Building ${IMAGE_TAG}..."
"${DOCKER[@]}" build -f "${ROOT}/docker/system-test/Dockerfile" -t "${IMAGE_TAG}" "${ROOT}/docker" 2>&1 | tee -a "${LOG_FILE}"

read -r -d '' INNER <<EOS || true
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1

php -r 'if (version_compare(PHP_VERSION, "7.3.0", "<")) { exit(1);} echo "php-floor: OK (".PHP_VERSION.")\n";'
for ext in dom libxml mbstring xml; do
  if ! php -m | grep -qi "^\${ext}\$"; then
    echo "Missing extension \${ext}" >&2
    exit 1
  fi
done
echo 'php-extensions: OK'

curl -fsSL -o /tmp/composer.phar "${COMPOSER_URL}"
echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c -
cd /workspace
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress --no-ansi
php /tmp/composer.phar audit --no-interaction
php scripts/check-cluster-pins.php
php scripts/check-rename.php
vendor/bin/phpunit --colors=never --do-not-cache-result
EOS

set +e
OUTPUT="$("${DOCKER[@]}" run --rm -v "${ROOT}:/workspace" -w /workspace "${IMAGE_TAG}" bash -lc "$INNER" 2>&1)"
RUN_STATUS=$?
set -e

printf '%s\n' "${OUTPUT}" | tee "${LOG_FILE}"

if [[ "${RUN_STATUS}" -ne 0 ]]; then
  log "FAIL: container exit code ${RUN_STATUS}"
  exit "${RUN_STATUS}"
fi

if output_has_blocked_messages "${OUTPUT}"; then
  log 'FAIL: output contains Warning, Deprecated, or Notice'
  exit 1
fi

STRIPPED="$(printf '%s\n' "${OUTPUT}" | strip_ansi)"

strip_ansi <<<"${OUTPUT}" | grep -Eq '^php-floor: OK \(7\.3\.[0-9]+\)$' || { log 'FAIL php-floor'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^php-extensions: OK$' || { log 'FAIL php-extensions'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^/tmp/composer\.phar: OK$' || { log 'FAIL composer checksum'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^Generating autoload files$' || { log 'FAIL composer update'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^No security vulnerability advisories found\.$' || { log 'FAIL composer audit'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^check-cluster-pins: wrong-SHA installed\.php failed on installed\.php only$' || { log 'FAIL pin demo php'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^check-cluster-pins: wrong-SHA installed\.json failed on installed\.json only$' || { log 'FAIL pin demo json'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^check-cluster-pins: OK \([0-9]+ packages; installed\.php reference and installed\.json source\.reference\)$' || { log 'FAIL pins'; exit 1; }
strip_ansi <<<"${OUTPUT}" | grep -Eq '^check-rename: OK \(no old or camelCase repo-owned names\)$' || { log 'FAIL rename'; exit 1; }

PHPUNIT_OK="$(strip_ansi <<<"${OUTPUT}" | grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions\)$' | tail -n 1)"
if [[ "${PHPUNIT_OK}" != "${BASELINE_LINE}" ]]; then
  log "FAIL: phpunit line got '${PHPUNIT_OK}' expected '${BASELINE_LINE}'"
  exit 1
fi

log '=== Gate lines ==='
printf '%s\n' "${STRIPPED}" | grep -E '^php-floor: OK|^php-extensions: OK|^/tmp/composer\.phar: OK|^Generating autoload files$|^No security vulnerability advisories found\.$|^check-cluster-pins:|^check-rename: OK|^OK \(' || true
log "System test passed (${PHPUNIT_OK})."
