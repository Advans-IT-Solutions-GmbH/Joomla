#!/bin/bash
#
# Download latest test logs from GitHub Actions
#

set -e

REPO="Advans-IT-Solutions-GmbH/Joomla"
OUTPUT_DIR="./downloaded-logs"

echo "=== Downloading Latest Test Logs ==="
echo ""

# Get latest workflow run
echo "Fetching latest workflow run..."
RUN_ID=$(gh run list --repo "$REPO" --workflow "j2commerce-import-export.yml" --limit 1 --json databaseId --jq '.[0].databaseId')

if [ -z "$RUN_ID" ]; then
    echo "❌ No workflow runs found"
    exit 1
fi

echo "Latest run ID: $RUN_ID"
echo ""

# Download artifacts
echo "Downloading artifacts..."
mkdir -p "$OUTPUT_DIR"
gh run download "$RUN_ID" --repo "$REPO" --dir "$OUTPUT_DIR"

echo ""
echo "✅ Logs downloaded to: $OUTPUT_DIR"
echo ""

# List downloaded files
echo "Downloaded files:"
find "$OUTPUT_DIR" -type f -exec ls -lh {} \;

echo ""
echo "=== Quick View ==="
echo ""

# Show docker-compose logs if available
if [ -f "$OUTPUT_DIR/test-results-and-logs/logs/docker-compose.log" ]; then
    echo "--- Docker Compose Logs (last 50 lines) ---"
    tail -50 "$OUTPUT_DIR/test-results-and-logs/logs/docker-compose.log"
fi

# Show joomla logs if available
if [ -f "$OUTPUT_DIR/test-results-and-logs/logs/joomla.log" ]; then
    echo ""
    echo "--- Joomla Logs (last 50 lines) ---"
    tail -50 "$OUTPUT_DIR/test-results-and-logs/logs/joomla.log"
fi
