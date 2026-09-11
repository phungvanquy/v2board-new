#!/usr/bin/env bash
# abort() ratchet — blocks new ad-hoc abort( calls in app/.
#
# The clean-codebase change consolidates abort(500, …) sites into
# App\Exceptions\ApiException. Migrating all 400+ legacy call sites is a
# phased effort, so instead of a hard ban we freeze the current count:
# any PR that ADDS new abort() calls fails, while the remaining legacy
# sites shrink over time.
#
# Usage:  bash tools/abort-ratchet.sh check   # CI / pre-merge
#         bash tools/abort-ratchet.sh ratchet # lower the ceiling after migrating a file
set -euo pipefail

cd "$(dirname "$0")/.."
BASELINE_FILE="tools/abort-baseline.txt"
count=$(grep -rnoP "(?<![A-Za-z_])abort\(" app --include="*.php" | wc -l | tr -d ' ')

if [ "${1:-check}" = "ratchet" ]; then
    echo "$count" > "$BASELINE_FILE"
    echo "ratchet: ceiling lowered to $count"
    exit 0
fi

baseline=$(cat "$BASELINE_FILE" 2>/dev/null || echo 0)
if [ "$count" -gt "$baseline" ]; then
    echo "FAIL: new abort() calls detected (found $count, ceiling $baseline)."
    echo "Use App\Exceptions\ApiException::fail() / badRequest() / forbidden() instead."
    echo "After migrating a legacy file, run: bash tools/abort-ratchet.sh ratchet"
    exit 1
fi
echo "ok: $count abort() calls (ceiling $baseline)"
