#!/bin/bash
# CI progress monitor: runs alongside PHPUnit, writes heartbeats every 30s
# Shows elapsed time and last test from --testdox output
set -euo pipefail

PROGRESS_FILE="${CI_PROGRESS_FILE:-/phpunit-progress.txt}"
OUTPUT_FILE="${1:-phpunit-output.txt}"
INTERVAL=30

echo "=== CI PROGRESS MONITOR ===" > "$PROGRESS_FILE"
echo "Started: $(date -u)" >> "$PROGRESS_FILE"
START_TIME=$(date +%s)

while true; do
    sleep "$INTERVAL"
    NOW=$(date +%s)
    ELAPSED=$((NOW - START_TIME))
    MINUTES=$((ELAPSED / 60))
    
    # Get last non-empty line from testdox output (shows current test)
    LAST_TEST=""
    if [ -f "$OUTPUT_FILE" ]; then
        LAST_TEST=$(grep -E "^  [A-Z]|^  ✓|^  ✗|^  S|^  R" "$OUTPUT_FILE" 2>/dev/null | tail -1 || true)
        if [ -z "$LAST_TEST" ]; then
            LAST_TEST=$(grep -v "^$" "$OUTPUT_FILE" 2>/dev/null | tail -1 || true)
        fi
    fi
    
    # Count tests completed so far
    TEST_COUNT=0
    if [ -f "$OUTPUT_FILE" ]; then
        TEST_COUNT=$(grep -cE "^  [A-Z]" "$OUTPUT_FILE" 2>/dev/null || echo "0")
    fi
    
    echo "[${MINUTES}m ${ELAPSED}s] tests=${TEST_COUNT} last=${LAST_TEST}" >> "$PROGRESS_FILE"
    
    # Safety: kill after 50 minutes
    if [ "$ELAPSED" -ge 3000 ]; then
        echo "=== WATCHDOG TIMEOUT at ${ELAPSED}s ===" >> "$PROGRESS_FILE"
        break
    fi
done
