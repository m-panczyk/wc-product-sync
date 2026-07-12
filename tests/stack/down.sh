#!/usr/bin/env bash
# Destroy the ephemeral rig, volumes included. Nothing here is precious.
set -euo pipefail
cd "$(dirname "$0")"
docker compose down -v --remove-orphans
rm -f .env
echo "Stack down."
