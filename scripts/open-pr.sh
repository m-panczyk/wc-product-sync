#!/bin/bash
# Open a PR via Forgejo API (template — override vars below as needed)
FORGEJO_TOKEN="${FORGEJO_TOKEN:?FORGEJO_TOKEN not set}"
BASE_URL="http://192.168.66.118:3000/api/v1/repos/mpanczyk/wc-product-sync/pulls"

# === Customize these variables ===
PR_TITLE="fix: Name-fallback matching uses sync_statuses not just publish (#25)"
PR_BODY="Closes #25

Implemented by Hermes Agent."
HEAD_BRANCH="fix/issue-25"
BASE_BRANCH="main"
# ================================

RESPONSE=$(curl -s -X POST "$BASE_URL" \
  -H "Authorization: token $FORGEJO_TOKEN" \
  -H "Content-Type: application/json" \
  -d "$(jq -n \
    --arg title "$PR_TITLE" \
    --arg body "$PR_BODY" \
    --arg head "$HEAD_BRANCH" \
    --arg base "$BASE_BRANCH" \
    '{title:$title,body:$body,head:$head,base:$base}')"

echo "$RESPONSE" | head -80
