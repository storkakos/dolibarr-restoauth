#!/usr/bin/env bash
# Run `php -l` over every PHP file of the module and exit non-zero if any file fails.
# Uses a local `php` if there is one, otherwise a throwaway php:8.3-cli Docker container.
#
#   bash scripts/lint-php.sh
#
# Environment:
#   PHP_IMAGE        Docker image to use without local php (default php:8.3-cli)
#   LINT_CONTAINER   name of that container (default restoauth-lint-$$)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

files=()
while IFS= read -r -d '' f; do
  files+=("$f")
done < <(find . -type f -name '*.php' -not -path './.git/*' -not -path './.scratch/*' -print0 | sort -z)

if [ "${#files[@]}" -eq 0 ]; then
  echo "lint-php: no PHP files found" >&2
  exit 1
fi

# One php -l per file; print only failures, then a summary.
lint_cmd='fail=0; n=0; for f in "$@"; do n=$((n+1)); out=$(php -l "$f" 2>&1) || { echo "$out"; fail=$((fail+1)); }; done; echo "lint-php: $n files checked, $fail failed"; [ "$fail" -eq 0 ]'

if command -v php >/dev/null 2>&1; then
  bash -c "$lint_cmd" lint "${files[@]}"
else
  if ! command -v docker >/dev/null 2>&1; then
    echo "lint-php: neither php nor docker found (run it on the test VM, see docs/agents/test-vm.md)" >&2
    exit 2
  fi
  docker run --rm --name "${LINT_CONTAINER:-restoauth-lint-$$}" \
    -v "$ROOT":/src:ro -w /src "${PHP_IMAGE:-php:8.3-cli}" \
    bash -c "$lint_cmd" lint "${files[@]}"
fi
