#!/bin/bash

# Check job stats - requires you to be logged in
# Usage: ./check-job-stats.sh [session_id]
#
# To get your session ID:
#   1. Open your browser and log into VibeReader
#   2. Open DevTools (F12 or Cmd+Option+I)
#   3. Go to Application/Storage tab > Cookies > http://localhost:9999
#   4. Find the "PHPSESSID" cookie and copy its value
#   5. Run: ./check-job-stats.sh YOUR_SESSION_ID
#
# Or use curl to login and capture the cookie:
#   curl -c cookies.txt -b cookies.txt -X POST http://localhost:9999/login \
#     -d "username=YOUR_USERNAME&password=YOUR_PASSWORD&_token=CSRF_TOKEN"
#   Then use: curl -b cookies.txt http://localhost:9999/api/jobs/stats

echo "Checking job queue statistics..."
echo ""

SESSION_ID="${1:-}"

if [ -z "$SESSION_ID" ]; then
    echo "⚠️  No session ID provided. Attempting without authentication..."
    echo "   (This will likely fail - you need to be logged in)"
    echo ""
    echo "To get your session ID:"
    echo "  1. Log into VibeReader in your browser"
    echo "  2. Open DevTools (F12) > Application > Cookies"
    echo "  3. Copy the PHPSESSID value"
    echo "  4. Run: ./check-job-stats.sh YOUR_SESSION_ID"
    echo ""
    curl -s http://localhost:9999/api/jobs/stats | python3 -m json.tool
else
    echo "Using session ID: ${SESSION_ID:0:20}..."
    echo ""
    curl -s -H "Cookie: PHPSESSID=$SESSION_ID" http://localhost:9999/api/jobs/stats | python3 -m json.tool
fi
