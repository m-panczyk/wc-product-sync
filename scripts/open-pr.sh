#!/bin/bash
# Open PR via Forgejo API — parameters come from environment variables set by the caller.
FORGEJO_TOKEN="${FORGEJO_TOKEN:?FORGEJO_TOKEN not set}"
BASE_URL="http://192.168.66.118:3000/api/v1/repos/mpanczyk/wc-product-sync/pulls"
TITLE="${PR_TITLE:?PR_TITLE not set}"
BODY="${PR_BODY:-Closes #0\n\nImplemented by Hermes Agent.}"
HEAD="${PR_HEAD:?PR_HEAD not set}"
BASE="${PR_BASE:-main}"

RESPONSE=$(curl -s -X POST "$BASE_URL" \
  -H "Authorization: token ***" \
  -H "Content-Type: application/json" \
  -d "{
    \"title\": \"${TITLE}\",
    \"body\": \"${BODY}\",
    \"head\": \"${HEAD}\",
    \"base\": \"${BASE}\"
  }")

echo "$RESPONSE" | head -80
