#!/bin/bash

# Check job stats - requires you to be logged in
# Usage: ./check-job-stats.sh

# Replace with your actual session cookie if needed
# You can get this from browser DevTools > Application > Cookies

echo "Checking job queue statistics..."
echo ""

# If running locally (not in Docker)
curl -s http://localhost:9999/api/jobs/stats | python3 -m json.tool

# Or if you have a session cookie, use:
# curl -s -H "Cookie: PHPSESSID=your_session_id" http://localhost:9999/api/jobs/stats | python3 -m json.tool
