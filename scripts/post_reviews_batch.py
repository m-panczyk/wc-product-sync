#!/usr/bin/env python3
"""Post all PR review comments and update state file."""
import json, os, subprocess, sys, urllib.request, urllib.error
from datetime import datetime, timezone

token = os.environ.get("FORGEJO_TOKEN_REVIEWER", "")
if not token:
    try:
        r = subprocess.run(["bash","-c","set -a; source /root/.hermes/.env; echo \"${FORGEJO_TOKEN_REVIEWER}\""],
                           capture_output=True, text=True, timeout=10)
        token = r.stdout.strip()
    except Exception:
        pass

if not token or len(token) < 10:
    print("ERROR: FORGEJO_TOKEN_REVIEWER not available", file=sys.stderr)
    sys.exit(1)

API = "http://192.168.66.118:3000/api/v1/repos/mpanczyk/wc-product-sync"
now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

def post_comment(issue_num, body):
    url = f"{API}/issues/{issue_num}/comments"
    data = json.dumps({"body": body}).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers={
        "Authorization": f"token {token}",
        "Content-Type": "application/json"
    }, method="POST")
    resp = urllib.request.urlopen(req)
    result = json.loads(resp.read().decode())
    print(f"PR #{issue_num}: comment posted — {result.get('html_url', 'N/A')}")
    return result

# ── Review 1: PR #31 (FIRST_REVIEW) – CI PHPCS step ───────────────────
pr31_review = """## Hermes PR Review (Round 1)

**No issues found** — LGTM.

CI: all green OK"""

# ── Review 2: PR #30 (FIRST_REVIEW) – i18n strings + dev artifacts ────
pr30_review = """## Hermes PR Review (Round 1)

**Findings: 2**

### MEDIUM
- **Dev artifacts committed to the repository** (.claude/settings.local.json, .hermes/agent-state.json): These are internal development tooling state files that should not be in the repository. Either remove them from this branch or ensure they are in `.gitignore` before merge.

- **CI: E2E / e2e test failure.** The end-to-end tests are failing on this commit (see CI checks). While no E2E-related code changes appear in this diff, the CI gate is red — this must be resolved before merge. Please investigate whether the failure is caused by these i18n changes or is pre-existing/unrelated.

### LOW
- **Hardcoded PR title in scripts/open-pr.sh** (line 10): The script contains a hardcoded PR title referencing issue #25. If intended as a reusable template, parameterize it; otherwise remove the specific issue reference.

CI: E2E / e2e=failure — please investigate and fix."""

# ── Review 3: PR #29 (FIRST_REVIEW) – HTTPS source URL security fix ───
pr29_review = """## Hermes PR Review (Round 1)

**Findings: 3**

### MEDIUM
- **`is_private_host()` — `127.0.0.1` is not detected as private** (wc-product-sync.php, ~line 758): The function handles `localhost` and `::1` strings but does not recognize the standard IPv4 loopback address `127.0.0.1`. The old implementation had this in its exclusion list. As a result, HTTP connections to `http://127.0.0.1:8080` will be blocked by `source_url_is_insecure()` and `api_get()`, breaking local development setups that use 127.0.0.1 instead of localhost. Add `127.0.0.1` (and optionally the entire 127.0.0.0/8 range) to the private/host exclusion check — e.g. after the string checks, add:

        if (0 === strncmp($host, '127.', 4)) return true; // 127.0.0.0/8 loopback

- **`is_private_host()` does not handle link-local range 169.254.0.0/16**: The old implementation included `169.254.` in its host exclusion list, but the new function does not. This is a minor regression — users on link-local addresses (usually auto-assigned via mDNS/Zeroconf) will have their HTTP connections incorrectly blocked. Consider adding:

        if (169 === $a && 254 === $b) return true; // 169.254.0.0/16 link-local

- **`sanitize_options()` may save insecure URLs despite the error**: When `source_url_is_insecure($parsed)` returns true, `add_settings_error()` is called with severity 'error', but `$out['source_url']` is not reassigned to block the save. The old value persists because `wp_update_option()` will still store `$out` from the callback. Depending on WordPress's settings validation behavior, the error message may display but the form may still appear to "save" — users could be confused why their change appears saved while the insecure URL remains. Consider adding explicit validation that prevents saving when insecure, or document that `api_get()` is the actual enforcement mechanism at runtime.

Note: The `$c` / `$d` undefined variable issue from PR #29 (Round 1 review) has been fixed in this commit — variables are now properly assigned before use. Thank you for addressing it.

CI: CI status not available from script output."""

# Post all three comments
reviews = [pr31_review, pr30_review, pr29_review]
urls = {}
for i, review_body in enumerate(reviews):
    pr_num = 31 - i
    try:
        result = post_comment(pr_num, review_body)
        urls[pr_num] = result.get("html_url", "N/A")
    except urllib.error.HTTPError as e:
        print(f"PR #{pr_num} API error {e.code}: {e.read().decode()[:500]}", file=sys.stderr)
        sys.exit(1)

# ── Update state file ──────────────────────────────────────────────────
state_path = "/opt/data/workspaces/wpwc-prod-sync/.hermes/agent-state.json"
with open(state_path, "r") as f:
    state = json.load(f)

state.setdefault("reviewed_prs", [])
state.setdefault("pr_status", {})

# PR #31 — clean
state["pr_status"][31] = {
    "branch": "fix/issue-22",
    "base": "main",
    "review_round": 1,
    "last_reviewed_sha": "8dbf9dfcf897a79147052ef1f318927127f7cd7a",
    "last_review_at": now,
    "open_findings": 0,
    "consecutive_clean_reviews": 1,
    "last_review_was_clean": True,
    "ready_to_merge": False,
}
state["reviewed_prs"].append(31)

# PR #30 — 2 medium findings (CI failure is a HIGH finding for merge gate)
state["pr_status"][30] = {
    "branch": "fix/issue-23",
    "base": "main",
    "review_round": 1,
    "last_reviewed_sha": "3d07f8940bb38e39924860f823eb8634b98eb09f",
    "last_review_at": now,
    "open_findings": 3,  # 2 medium + 1 CI failure (HIGH for merge gate)
    "consecutive_clean_reviews": 0,
    "last_review_was_clean": False,
    "ready_to_merge": False,
}
state["reviewed_prs"].append(30)

# PR #29 — 3 medium findings
state["pr_status"][29] = {
    "branch": "fix/issue-24",
    "base": "main",
    "review_round": 1,
    "last_reviewed_sha": "ede0706e310154050512682b47f217b77b8f85b6",
    "last_review_at": now,
    "open_findings": 3,
    "consecutive_clean_reviews": 0,
    "last_review_was_clean": False,
    "ready_to_merge": False,
}
state["reviewed_prs"].append(29)

with open(state_path, "w") as f:
    json.dump(state, f, indent=2)
    f.write("\n")

print("\nState file updated.")

# ── Matrix output ──────────────────────────────────────────────────────
lines = []
for pr_num in [31, 30, 29]:
    findings = state["pr_status"][pr_num]
    nf = findings["open_findings"]
    url = urls[pr_num]
    if nf == 0:
        lines.append(f"Review PR #{pr_num} — LGTM — {url}")
    else:
        # Count severities from our review text
        if pr_num == 30:
            verdict = f"2M + CI failure"
        elif pr_num == 29:
            verdict = f"3M (loopback bug, link-local gap, sanitize behavior)"
        else:
            verdict = f"{nf} findings"
        lines.append(f"Review PR #{pr_num} — {verdict} — {url}")

for line in lines:
    print(line)
