#!/usr/bin/env bash
# Unit tests for scripts/changelog-section.sh (PR #46).
# Tests the two-pass lookup: exact version match first, then base version fallback.
#
# Usage: ./tests/changelog-section.test.sh
# Uses CHANGELOG.md from this branch (per-rc sections) as the primary test fixture.

set -euo pipefail

cd "$(dirname "$0")/.."

SCRIPT="scripts/changelog-section.sh"
CL="CHANGELOG.md"
PASSED=0; FAILED=0

tp() { # test-pass "description" "condition"
	if eval "$2" >/dev/null 2>&1; then PASSED=$((PASSED+1)); echo "  PASS: $1"; else FAILED=$((FAILED+1)); echo "  FAIL: $1"; fi
}

echo "===> changelog-section.sh unit tests (two-pass lookup: exact → fallback)"
echo "     Script: ${SCRIPT}"
echo "     Changelog: ${CL}"

# ---- Exact match tests using this branch's CHANGELOG (has per-rc sections) ----
echo ""
echo "--- Exact match (per-rc sections present) ---"

OUT="$(bash "$SCRIPT" "0.9.27-rc9" "$CL")"
tp "0.9.27-rc9 matches rc9 section" 'echo "$OUT" | grep -q "^### 0.9.27-rc9 "'

OUT="$(bash "$SCRIPT" "0.9.27-rc8" "$CL")"
tp "0.9.27-rc8 matches rc8 section" 'echo "$OUT" | grep -q "^### 0.9.27-rc8 "'

OUT_RC9="$(bash "$SCRIPT" "0.9.27-rc9" "$CL")"
OUT_RC8="$(bash "$SCRIPT" "0.9.27-rc8" "$CL")"
tp "rc9 and rc8 return different sections (not identical)" '[[ "$OUT_RC9" != "$OUT_RC8" ]]'

OUT="$(bash "$SCRIPT" "0.9.27-rc6" "$CL")"
tp "0.9.27-rc6 matches rc6 section" 'echo "$OUT" | grep -q "^### 0.9.27-rc6 "'

OUT="$(bash "$SCRIPT" "0.9.27" "$CL")"
tp "0.9.27 (stable) matches its own section" 'echo "$OUT" | grep -q "^### 0.9.27 —"'

# ---- Fallback tests (simulated changelog without per-rc sections) ----
TEST_CL=$(mktemp /tmp/changelog-test.XXXXXX.md)
trap 'rm -f "$TEST_CL"' EXIT

cat > "$TEST_CL" <<'EOL'
## Changelog

### 0.9.27 — stable release notes

- Stable version content only.
- Shared with rc/beta when no per-prerelease section exists.
EOL

echo ""
echo "--- Fallback to base version (no per-rc sections) ---"

# When exact match fails, should fall back to bare base version
OUT="$(bash "$SCRIPT" "0.9.27-rc9" "$TEST_CL")"
tp "rc9 falls back to 0.9.27 when no rc9 section exists" 'echo "$OUT" | grep -q "^### 0.9.27 —"'

OUT="$(bash "$SCRIPT" "0.9.27-beta1" "$TEST_CL")"
tp "beta falls back to 0.9.27 via base version stripping" 'echo "$OUT" | grep -q "^### 0.9.27 —"'

# Stable version matches directly (no fallback needed)
OUT="$(bash "$SCRIPT" "0.9.27" "$TEST_CL")"
tp "0.9.27 independent match (no fallback)" 'echo "$OUT" | grep -q "^### 0.9.27 —"'

# Nonexistent version returns empty gracefully (exit 0)
OUT="$(bash "$SCRIPT" "0.9.28-beta1" "$TEST_CL")"
tp "nonexistent version returns empty string, no error" '[ "${#OUT}" -eq 0 ]'

echo ""
echo "Results: $PASSED passed, $FAILED failed"
exit "${FAILED:-0}"
