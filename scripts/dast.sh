#!/bin/bash
# DAST security scan - starts dev server and runs tests
set -e

echo "=== Starting DAST Security Scan ==="

# Start dev server in background
php -S 127.0.0.1:8089 -t public/ > /dev/null 2>&1 &
SERVER_PID=$!
sleep 2

# Check server is running
if ! curl -s http://127.0.0.1:8089/health > /dev/null 2>&1; then
    echo "ERROR: Dev server failed to start"
    kill $SERVER_PID 2>/dev/null
    exit 1
fi

echo "Dev server started (PID: $SERVER_PID)"

# Run the DAST security test suite
php vendor/bin/phpunit --no-coverage tests/dast/
EXIT_CODE=$?

# Cleanup
kill $SERVER_PID 2>/dev/null
wait $SERVER_PID 2>/dev/null

echo "=== DAST Scan Complete (exit code: $EXIT_CODE) ==="
exit $EXIT_CODE
