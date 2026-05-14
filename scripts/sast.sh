#!/bin/bash
# SAST Security Scan using Semgrep
set -e

echo "=== SAST Security Scan ==="

# Check if semgrep is installed
if command -v semgrep &> /dev/null; then
    echo "Using Semgrep..."
    semgrep --config=semgrep-rules/ --error --strict .
elif command -v docker &> /dev/null; then
    echo "Using Semgrep via Docker..."
    docker run --rm -v "${PWD}:/src" returntocorp/semgrep:latest \
        semgrep --config=/src/semgrep-rules/ --error --strict /src
else
    echo "WARNING: Semgrep not available - skipping SAST scan"
    echo "Install: pip install semgrep or brew install semgrep"
    exit 0
fi

echo "=== SAST Scan Complete ==="
