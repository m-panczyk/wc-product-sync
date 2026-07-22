#!/usr/bin/env python3
import json, os, sys, urllib.request

token = os.environ.get('FORGEJO_TOKEN_WORKER', '')
base = 'http://192.168.66.118:3000/api/v1/repos/mpanczyk/wc-product-sync'

def api(path_str):
    req = urllib.request.Request(
        f'{base}/{path_str}',
        headers={'Authorization': f'token {token}', 'Content-Type': 'application/json'}
    )
    try:
        with urllib.request.urlopen(req) as r:
            return json.loads(r.read())
    except Exception as e:
        print(f"Error fetching {path_str}: {e}", file=sys.stderr)
        return None

# PR #30 info
pr = api(f'pulls/30')
if pr:
    print("=== PR INFO ===")
    print(json.dumps({k: pr[k] for k in ['title','body','state','base','head','html_url','number']}, indent=2))

# PR comments
comments = api('pulls/30/comments')
if comments:
    print("\n=== PR COMMENTS ===")
    for c in comments:
        print(json.dumps({'id': c['id'], 'body': c['body'][:500], 'created_at': c['created_at']}, indent=2))

# CI commits check
commits = api('pulls/30/commits')
if commits:
    print("\n=== COMMITS ===")
    for c in commits:
        print(json.dumps({'sha': c['sha'][:8], 'message': c['commit']['message'].split('\n')[0]}, indent=2))
