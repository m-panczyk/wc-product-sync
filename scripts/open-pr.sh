#!/bin/bash
# Open PR for issue #25 via Forgejo API
FORGEJO_TOKEN="${FORGEJO_TOKEN:?FORGEJO_TOKEN not set}"
BASE_URL="http://192.168.66.118:3000/api/v1/repos/mpanczyk/wc-product-sync/pulls"

RESPONSE=$(curl -s -X POST "$BASE_URL" \
  -H "Authorization: token $FORGEJO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "fix: Name-fallback matching uses sync_statuses not just publish (#25)",
    "body": "Closes #25\n\nImplemented by Hermes Agent.",
    "head": "fix/issue-25",
    "base": "main"
  }')

echo "$RESPONSE" | head -80
