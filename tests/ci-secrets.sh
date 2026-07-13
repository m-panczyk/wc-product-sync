#!/usr/bin/env bash
# Upload the rig-test secrets to Forgejo Actions from your local tests/perf.env.
#
# Values are read from perf.env and PUT straight to the API — they are never printed, so
# nothing lands in your scrollback or shell history.
#
#   FORGEJO_TOKEN=... tests/ci-secrets.sh
#
# Env:
#   FORGEJO_TOKEN  (required)  token with repo write access
#   FORGEJO_URL    default https://git.panczyk.cc
#   FORGEJO_REPO   default mpanczyk/wc-product-sync
#   SSH_KEY_FILE   private key the runner uses to reach the rig (default ~/.ssh/wps-ci)
#   RELEASE_TOKEN  optional; also uploaded, used by release.yaml
#
# NOTE ON THE KEY: whatever SSH_KEY_FILE points at is handed to every CI job, and any branch
# anyone pushes can read it. It must be a DEDICATED key — never a personal one:
#   ssh-keygen -t ed25519 -f ~/.ssh/wps-ci -N '' -C 'wps-ci@forgejo-actions'
#   ssh-copy-id -i ~/.ssh/wps-ci.pub <QNAP_SSH>
#   ssh-copy-id -i ~/.ssh/wps-ci.pub <SRC_SSH>
#
# NOTE ON RELEASE_TOKEN: a dedicated Forgejo token, not your personal one. release.yaml falls
# back to the automatic per-run token if this is unset.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"

: "${FORGEJO_TOKEN:?FORGEJO_TOKEN is required}"
FORGEJO_URL="${FORGEJO_URL:-https://git.panczyk.cc}"
FORGEJO_REPO="${FORGEJO_REPO:-mpanczyk/wc-product-sync}"
SSH_KEY_FILE="${SSH_KEY_FILE:-$HOME/.ssh/wps-ci}"
ENV_FILE="$DIR/perf.env"

[ -f "$ENV_FILE" ]     || { echo "ERROR: $ENV_FILE not found (copy from perf.env.example)" >&2; exit 1; }
[ -f "$SSH_KEY_FILE" ] || { echo "ERROR: $SSH_KEY_FILE not found" >&2; exit 1; }

# shellcheck disable=SC1090
source "$ENV_FILE"

API="$FORGEJO_URL/api/v1/repos/$FORGEJO_REPO/actions/secrets"

put_secret() { # put_secret NAME VALUE
	local name="$1" value="$2" code
	[ -n "$value" ] || { echo "  skip $name (empty)"; return 0; }
	code="$(python3 -c 'import json,sys; print(json.dumps({"data": sys.argv[1]}))' "$value" \
		| curl -sS -o /dev/null -w '%{http_code}' -X PUT "$API/$name" \
			-H "Authorization: token $FORGEJO_TOKEN" \
			-H 'Content-Type: application/json' --data-binary @-)"
	case "$code" in
		201|204) echo "  set $name" ;;
		*)       echo "  FAILED $name (HTTP $code)" >&2; return 1 ;;
	esac
}

echo "==> Uploading rig secrets to $FORGEJO_REPO"
for v in QNAP_SSH QNAP_DOCKER QNAP_PROJECT GRAFANA GRAFANA_TOKEN \
         INFLUX INFLUX_ORG INFLUX_TOKEN SRC_SSH SRC_CONTAINER SRC_PROJECT; do
	put_secret "$v" "${!v:-}"
done

echo "==> Uploading the runner's SSH key ($SSH_KEY_FILE)"
put_secret RIG_SSH_KEY "$(cat "$SSH_KEY_FILE")"

# Without known_hosts the scripts' `ssh -o BatchMode=yes` fails on host-key verification —
# there is no prompt to accept it in CI.
echo "==> Scanning host keys for known_hosts"
hosts=""
for target in "${QNAP_SSH:-}" "${SRC_SSH:-}"; do
	[ -n "$target" ] || continue
	host="${target#*@}"
	echo "  keyscan $host"
	hosts="$hosts$(ssh-keyscan -T 5 "$host" 2>/dev/null)"$'\n'
done
put_secret RIG_KNOWN_HOSTS "$hosts"

if [ -n "${RELEASE_TOKEN:-}" ]; then
	echo "==> Uploading RELEASE_TOKEN"
	put_secret RELEASE_TOKEN "$RELEASE_TOKEN"
fi

echo
echo "Done. Re-run the 'Rig tests' workflow."
