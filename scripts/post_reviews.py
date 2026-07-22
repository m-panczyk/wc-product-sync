#!/usr/bin/env python3
"""Post PR review comments to Forgejo via the REST API."""

import json
import os
import subprocess
import urllib.request

# Load token: env var first, then fallback by sourcing .env
token = os.environ.get("FORGEJO_TOKEN_REVIEWER", "")
if not token:
    try:
        result = subprocess.run(
            ["bash", "-c", "set -a; source /root/.hermes/.env; echo \"${FORGEJO_TOKEN_REVIEWER}\""],
            capture_output=True, text=True, timeout=10
        )
        token = result.stdout.strip()
    except Exception:
        pass

if not token or len(token) < 10:
    print("ERROR: FORGEJO_TOKEN_REVIEWER not available")
    exit(1)

print(f"Token loaded (len={len(token)})")

FORGEJO_API = "http://192.168.66.118:3000/api/v1/repos/mpanczyk/wc-product-sync"
headers = {
    "Authorization": f"token {token}",
    "Content-Type": "application/json",
}

def post_comment(issue_num, body):
    url = f"{FORGEJO_API}/issues/{issue_num}/comments"
    data = json.dumps({"body": body}).encode("utf-8")
    req = urllib.request.Request(url, data=data, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req) as resp:
            result = json.loads(resp.read().decode())
            print(f"PR #{issue_num} comment posted: {result.get('html_url', 'N/A')}")
            return result
    except urllib.error.HTTPError as e:
        body_text = e.read().decode()
        print(f"PR #{issue_num} HTTP {e.code}: {body_text[:500]}")
        raise

# === PR #29 Review (FIRST_REVIEW) ===
pr29_body = """## Hermes PR Review (Round 1)

**Findings: 3**

### MEDIUM
- **`is_private_host()` — undefined variables `$c` and `$d`** (wc-product-sync.php, ~line 786): The function destructures the host into IPv4 octets via regex capture but only assigns `$a = (int) $m[1]` and `$b = (int) $m[2]`. Lines checking `0 === $c && 0 === $d` reference unassigned variables, which will emit PHP deprecation warnings in PHP 8.x+. Assign `$c = (int) $m[3]` and `$d = (int) $m[4]` before the comparison.

- **Dev artifacts committed** (.claude/settings.local.json, .hermes/agent-state.json): These are internal development tooling state files that should not be in the main repository. Remove them from this branch (they may also need to be added to `.gitignore`).

- **`add_settings_error()` with 'error' severity does not prevent saving** (wc-product-sync.php, ~line 799): The settings callback writes `$out['source_url'] = esc_url_raw(trim(...))` before calling `add_settings_error()`, so the insecure HTTP URL persists in the database even though an error message is displayed. Users will be confused — the setting appears blocked but actually saved. Either validate and refuse to save (return early from the callback), or document that the runtime check in `api_get()` is the actual enforcement.

### LOW
- **Hardcoded PR title in scripts/open-pr.sh** (line 10): The script's title field references a specific issue (#25) rather than being parameterized. If intended as a reusable template, make it generic; otherwise remove.
"""

# === PR #30 Review (RE_REVIEW) ===
pr30_body = """## Hermes PR Review (Round 2)

**No issues found** — clean review, LGTM.
"""

print("Posting PR #29 review...")
comment29 = post_comment(29, pr29_body)

print("\nPosting PR #30 review...")
comment30 = post_comment(30, pr30_body)

print("\nDone. Collecting urls for state update.")
with open("/tmp/pr_review_urls.json", "w") as f:
    json.dump({
        "29": comment29.get("html_url"),
        "30": comment30.get("html_url"),
    }, f)
