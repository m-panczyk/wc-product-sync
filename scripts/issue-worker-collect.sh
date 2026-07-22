#!/bin/bash
# /root/.hermes/scripts/issue-worker-collect.sh
# Collects open issues that haven't been worked on yet.
# stdout is injected into the agent's prompt as context.

set -euo pipefail

FORGEJO="http://192.168.66.118:3000"
REPO="mpanczyk/wc-product-sync"
API="$FORGEJO/api/v1/repos/$REPO"
WORKSPACE="/opt/data/workspaces/wpwc-prod-sync"
STATE_FILE="$WORKSPACE/.hermes/agent-state.json"

cd "$WORKSPACE"

# Source FORGEJO_TOKEN from env file
if [ -z "${FORGEJO_TOKEN:-}" ] && [ -f /root/.hermes/.env ]; then
  set -a
  source /root/.hermes/.env
  set +a
fi

# Load worked issues
WORKED="[]"
if [ -f "$STATE_FILE" ]; then
  WORKED=$(python3 -c "import json; print(json.dumps(json.load(open('$STATE_FILE')).get('worked_issues',[])))")
fi

# Fetch open issues (type=issues excludes PRs)
ISSUES_JSON=$(curl -s -H "Authorization: token $FORGEJO_TOKEN" \
  "$API/issues?state=open&type=issues&limit=20")

# Find unworked issues, skip wip/blocked labels, skip issues closed by open PRs, max 1
RESULT=$(echo "$ISSUES_JSON" | python3 -c "
import json, sys
issues = json.load(sys.stdin)
worked = set(json.loads('$WORKED'))

# Get open PRs to check which issues they close
import urllib.request
prs_url = '$API/pulls?state=open&limit=50'
req = urllib.request.Request(prs_url, headers={'Authorization': 'token $FORGEJO_TOKEN'})
prs = json.loads(urllib.request.urlopen(req).read())
issues_closed_by_prs = set()
for p in prs:
    body = p.get('body', '')
    for n in body.split():
        if n.startswith('#') and n[1:].isdigit():
            issues_closed_by_prs.add(int(n[1:]))
        elif 'closes' in body.lower() or 'fixes' in body.lower() or 'resolves' in body.lower():
            for part in body.replace(',', ' ').split():
                if part.startswith('#') and part[1:].isdigit():
                    issues_closed_by_prs.add(int(part[1:]))

for i in issues:
    labels = [l['name'].lower() for l in i.get('labels', [])]
    if 'wip' in labels or 'blocked' in labels:
        continue
    if i['number'] in worked:
        continue
    if i['number'] in issues_closed_by_prs:
        continue
    print(json.dumps({
        'number': i['number'],
        'title': i['title'],
        'body': i.get('body', '')[:2000],
        'labels': labels
    }))
    break
")

if [ -z "$RESULT" ]; then
  echo "NO_NEW_ISSUES"
  exit 0
fi

# Determine branch prefix
ISSUE_NUM=$(echo "$RESULT" | python3 -c "import json,sys; print(json.loads(sys.stdin.read())['number'])")
ISSUE_TITLE=$(echo "$RESULT" | python3 -c "import json,sys; print(json.loads(sys.stdin.read())['title'])")

if echo "$ISSUE_TITLE" | grep -qiE '^(feat|feature)'; then
  BRANCH_PREFIX="feat"
elif echo "$ISSUE_TITLE" | grep -qiE '^(fix|bug)'; then
  BRANCH_PREFIX="fix"
elif echo "$ISSUE_TITLE" | grep -qiE '^(docs|doc)'; then
  BRANCH_PREFIX="docs"
else
  BRANCH_PREFIX="fix"
fi

BRANCH_NAME="${BRANCH_PREFIX}/issue-${ISSUE_NUM}"

# Create or reset branch from main
git fetch forgejo 2>/dev/null || true
# Stash any local changes first
git stash 2>/dev/null || true
# Switch to or create the branch from forgejo/main
git checkout "$BRANCH_NAME" 2>/dev/null || git checkout -b "$BRANCH_NAME" forgejo/main 2>/dev/null
# Reset to forgejo/main to ensure clean state
git reset --hard forgejo/main 2>/dev/null || true

# Output context for the agent
echo "ISSUE_TO_WORK:"
echo "$RESULT"
echo "---"
echo "BRANCH_NAME: $BRANCH_NAME"
echo "WORKSPACE: $WORKSPACE"
echo "CURRENT_BRANCH: $(git branch --show-current)"
echo "FILES:"
ls -la "$WORKSPACE"/*.php 2>/dev/null | head -10
echo "INCLUDES:"
ls -la "$WORKSPACE"/includes/*.php 2>/dev/null | head -20
