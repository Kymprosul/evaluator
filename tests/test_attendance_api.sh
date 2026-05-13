#!/usr/bin/env bash
# Test script for the Attendance API
# Runs against a local PHP built-in server.
#
# Usage: ./tests/test_attendance_api.sh [BASE_URL]
# Default BASE_URL: http://localhost:8080

set -euo pipefail

BASE_URL="${1:-http://localhost:8080}"
PASS=0
FAIL=0

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Helper: check HTTP status code
check_status() {
    local test_name="$1"
    local expected="$2"
    local actual="$3"
    local body="$4"

    if [ "$actual" = "$expected" ]; then
        echo -e "${GREEN}PASS${NC} - ${test_name} (HTTP ${actual})"
        PASS=$((PASS + 1))
    else
        echo -e "${RED}FAIL${NC} - ${test_name} (expected HTTP ${expected}, got HTTP ${actual})"
        echo "  Body: ${body:0:200}"
        FAIL=$((FAIL + 1))
    fi
}

# Helper: check JSON field in body
check_json_field() {
    local test_name="$1"
    local expected="$2"
    local body="$3"

    if echo "$body" | grep -q "$expected"; then
        echo -e "${GREEN}PASS${NC} - ${test_name}"
        PASS=$((PASS + 1))
    else
        echo -e "${RED}FAIL${NC} - ${test_name} (expected '${expected}' in response)"
        echo "  Body: ${body:0:200}"
        FAIL=$((FAIL + 1))
    fi
}

echo "============================================"
echo " Attendance API Test Suite"
echo " Target: ${BASE_URL}"
echo "============================================"
echo ""

# First, get a CSRF token by visiting the login page (or any page that sets the session/token)
# For this test, we need to login first to get a session, then get a CSRF token.
# We'll use the login flow:
# 1. GET login page to get CSRF token
# 2. POST login to get session
# 3. Use session + CSRF for attendance requests

# Step 1: Get initial page with CSRF token
LOGIN_PAGE=$(curl -s -c /tmp/eval_test_cookies.txt -w "\n%{http_code}" "${BASE_URL}/login.php" 2>/dev/null || true)
LOGIN_BODY=$(echo "$LOGIN_PAGE" | head -n -1)
LOGIN_CODE=$(echo "$LOGIN_PAGE" | tail -n 1)

# Extract CSRF token from meta tag
CSRF_TOKEN=$(echo "$LOGIN_BODY" | grep -oP 'name="csrf-token" content="\K[^"]+' || echo "")

if [ -z "$CSRF_TOKEN" ]; then
    echo "WARNING: Could not extract CSRF token from login page. Tests requiring CSRF may fail."
    echo "  (This is expected if the server is not running with proper PHP session support)"
fi

# Step 2: Login as testprof
echo "Logging in as testprof..."
LOGIN_RESULT=$(curl -s -c /tmp/eval_test_cookies.txt -b /tmp/eval_test_cookies.txt \
    -X POST "${BASE_URL}/login.php" \
    -d "username=testprof&password=test1234" \
    -w "\n%{http_code}" 2>/dev/null || echo "LOGIN_FAILED")

LOGIN_RESULT_BODY=$(echo "$LOGIN_RESULT" | head -n -1)
LOGIN_RESULT_CODE=$(echo "$LOGIN_RESULT" | tail -n 1)

# After login, get a fresh CSRF token from the dashboard
DASHBOARD=$(curl -s -c /tmp/eval_test_cookies.txt -b /tmp/eval_test_cookies.txt \
    -w "\n%{http_code}" "${BASE_URL}/dashboard.php" 2>/dev/null || true)
DASHBOARD_BODY=$(echo "$DASHBOARD" | head -n -1)
CSRF_TOKEN=$(echo "$DASHBOARD_BODY" | grep -oP 'name="csrf-token" content="\K[^"]+' || echo "")

if [ -z "$CSRF_TOKEN" ]; then
    # Try alternative meta tag format
    CSRF_TOKEN=$(echo "$DASHBOARD_BODY" | grep -oP "csrf-token.*?content=\"\K[^\"]+" || echo "")
fi

echo "CSRF Token: ${CSRF_TOKEN:0:20}..."
echo ""

# ---- Test 1: POST attendance with 5 student IDs ----
echo "--- Test 1: POST attendance with 5 students ---"
RESPONSE=$(curl -s -b /tmp/eval_test_cookies.txt \
    -w "\n%{http_code}" \
    -X POST "${BASE_URL}/api/attendance.php" \
    -H "X-CSRF-Token: ${CSRF_TOKEN}" \
    -d "class_id=1&attendance_date=$(date +%Y-%m-%d)&present_student_ids[]=1&present_student_ids[]=2&present_student_ids[]=3&present_student_ids[]=4&present_student_ids[]=5" 2>/dev/null || echo -e "\n000")
BODY=$(echo "$RESPONSE" | head -n -1)
HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

check_status "POST attendance with 5 students" "200" "$HTTP_CODE" "$BODY"
check_json_field "Response contains ok:true" '"ok":true' "$BODY"

# ---- Test 2: POST same attendance again (idempotency) ----
echo "--- Test 2: POST same attendance again (idempotency) ---"
RESPONSE=$(curl -s -b /tmp/eval_test_cookies.txt \
    -w "\n%{http_code}" \
    -X POST "${BASE_URL}/api/attendance.php" \
    -H "X-CSRF-Token: ${CSRF_TOKEN}" \
    -d "class_id=1&attendance_date=$(date +%Y-%m-%d)&present_student_ids[]=1&present_student_ids[]=2&present_student_ids[]=3&present_student_ids[]=4&present_student_ids[]=5" 2>/dev/null || echo -e "\n000")
BODY=$(echo "$RESPONSE" | head -n -1)
HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

check_status "POST same attendance (idempotent)" "200" "$HTTP_CODE" "$BODY"
check_json_field "Response contains ok:true" '"ok":true' "$BODY"

# ---- Test 3: POST with empty array ----
echo "--- Test 3: POST with empty attendance ---"
RESPONSE=$(curl -s -b /tmp/eval_test_cookies.txt \
    -w "\n%{http_code}" \
    -X POST "${BASE_URL}/api/attendance.php" \
    -H "X-CSRF-Token: ${CSRF_TOKEN}" \
    -d "class_id=1&attendance_date=$(date +%Y-%m-%d)" 2>/dev/null || echo -e "\n000")
BODY=$(echo "$RESPONSE" | head -n -1)
HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

check_status "POST empty attendance" "200" "$HTTP_CODE" "$BODY"
check_json_field "Response contains ok:true" '"ok":true' "$BODY"

# ---- Test 4: POST without CSRF token ----
echo "--- Test 4: POST without CSRF token (should fail) ---"
RESPONSE=$(curl -s -b /tmp/eval_test_cookies.txt \
    -w "\n%{http_code}" \
    -X POST "${BASE_URL}/api/attendance.php" \
    -d "class_id=1&attendance_date=$(date +%Y-%m-%d)&present_student_ids[]=1" 2>/dev/null || echo -e "\n000")
BODY=$(echo "$RESPONSE" | head -n -1)
HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)

if [ "$HTTP_CODE" = "403" ] || [ "$HTTP_CODE" = "400" ] || echo "$BODY" | grep -q '"ok":false'; then
    echo -e "${GREEN}PASS${NC} - POST without CSRF token rejected (HTTP ${HTTP_CODE})"
    PASS=$((PASS + 1))
else
    echo -e "${RED}FAIL${NC} - POST without CSRF token should have been rejected (got HTTP ${HTTP_CODE})"
    echo "  Body: ${BODY:0:200}"
    FAIL=$((FAIL + 1))
fi

# Cleanup
rm -f /tmp/eval_test_cookies.txt

echo ""
echo "============================================"
echo " Results: ${PASS} passed, ${FAIL} failed"
echo "============================================"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi
exit 0
