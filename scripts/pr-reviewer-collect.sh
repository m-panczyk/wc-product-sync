#!/bin/bash
# /root/.hermes/scripts/pr-reviewer-collect.sh
# Collects open PRs that haven't been reviewed yet.
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

# Load reviewed PRs
REVIEWED="[]"
if [ -f "$STATE_FILE" ]; then
  REVIEWED=$(python3 -c "import json; print(json.dumps(json.load(open('$STATE_FILE')).get('reviewed_prs',[])))")
fi

# Fetch open PRs
PRS_JSON=$(curl -s -H "Authorization: token $FORGEJO_TOKEN" "$API/pulls?state=open&limit=20")

# Find unreviewed PRs
UNREVIEWED=$(echo "$PRS_JSON" | python3 -c "
import json, sys
prs = json.load(sys.stdin)
reviewed = set(json.loads('$REVIEWED'))
unreviewed = [p for p in prs if p['number'] not in reviewed]
if not unreviewed:
    print('NO_NEW_PRS')
else:
    for p in unreviewed:
        print(json.dumps({
            'number': p['number'],
            'title': p['title'],
            'head': p['head']['ref'],
            'base': p['base']['ref'],
            'body': p.get('body', '')[:500]
        }))
")

if [ "$UNREVIEWED" = "NO_NEW_PRS" ]; then
  echo "NO_NEW_PRS"
  exit 0
fi

# Fetch latest from forgejo
git fetch forgejo 2>/dev/null || true

# For each unreviewed PR, output the diff
echo "$UNREVIEWED" | while IFS= read -r line; do
  [ "$line" = "NO_NEW_PRS" ] && continue
  PR_NUM=$(echo "$line" | python3 -c "import json,sys; print(json.loads(sys.stdin.read())['number'])")
  PR_BRANCH=$(echo "$line" | python3 -c "import json,sys; print(json.loads(sys.stdin.read())['head'])")
  BASE_BRANCH=$(echo "$line" | python3 -c "import json,sys; print(json.loads(sys.stdin.read())['base'])")

  # Generate diff
  DIFF=$(git diff "forgejo/$BASE_BRANCH...forgejo/$PR_BRANCH" 2>/dev/null | head -5000)

  echo "===PR_START==="
  echo "$line"
  echo "---DIFF---"
  echo "$DIFF"
  echo "===PR_END==="
done
