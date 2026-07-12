# Forgejo Action Runner Installation Guide

This guide walks through installing and configuring the Forgejo Actions runner on this machine (Proxmox host, Debian-based Linux).

## Prerequisites

- Docker or Podman installed (required for container-based workflow jobs)  
- Access to 192.168.66.118:3000 (Forgejo instance)
- Admin credentials for the `mpanczyk` user on Forgejo

## Step 1: Create Runner User

```bash
sudo adduser --disabled-password --gecos "" actions-runner
```

## Step 2: Download and Install the Runner

### For Linux AMD64 (recommended for this machine):

```bash
# Create installation directory  
sudo mkdir -p /opt/forgejo-runner
sudo chown actions-runner:actions-runner /opt/forgejo-runner

# Download latest release from code.forgejo.org
cd /tmp
curl -L -o runner.tar.gz \
  "https://code.forgejo.org/forgejo/runner/releases/latest/download/runner-linux-amd64.tar.gz"

# Extract to installation dir
sudo tar xzf runner.tar.gz -C /opt/forgejo-runner/
sudo chown -R actions-runner:actions-runner /opt/forgejo-runner/

# Verify  
/opt/forgejo-runner/forgejo-runner --version
```

### For ARM64 (if on Raspberry Pi or similar):

Replace `linux-amd64` with `linux-arm64` in the URL above.

## Step 3: Configure the Runner

The runner needs to connect to your Forgejo instance at `https://git.panczyk.cc/` (or `http://192.168.66.118:3000/`).

### Generate Registration Token

1. Log in to Forgejo at http://192.168.66.118:3000
2. Navigate to **mpanczyk/wc-product-sync** → Settings → Actions
3. Click **"Add Runner"** or **"Register New Runner"**  
4. Copy the token (it expires after use)

### Configure Runner

```bash
cd /opt/forgejo-runner

# Run configuration wizard as the actions-runner user
sudo su - actions-runner -s /bin/bash
./forgejo-runner config \
  --url "http://192.168.66.118:3000/mpanczyk/wc-product-sync" \
  --token "<YOUR-GENERATED-TOKEN>"

# Set runner labels (used in workflow files' runs-on field)
# Edit .forgejo-runner config and set:
#   labels: ["self-hosted", "wc-sync-ci"]

exit  # Exit the sudo su session
```

### Configuration File Structure

Create/edit `~/.forgejo-runner/config.yml`:

```yaml
forgejo:
  url: "http://192.168.66.118:3000"
  repo: "wc-product-sync"
  
# Runner labels for job targeting
labels:
  - "self-hosted"
  - "linux"
  - "wc-sync-ci"

# Container image cache (improves performance on repeated runs)
cache:
  enabled: true
  
# Network mode for containerized jobs
network: "host"

# Check interval for new jobs (seconds)  
fetch_interval: 10s
```

## Step 4: Install Systemd Service

Create the service file at `/etc/systemd/user/forgejo-runner.service`:

```ini
[Unit]
Description=Forgejo Actions Runner Service
After=docker.service network.target

[Service]
Type=simple
User=actions-runner
WorkingDirectory=/opt/forgejo-runner
ExecStart=/opt/forgejo-runner/forgejo-runner run --config /home/actions-runner/.forgejo-runner/config.yml
Restart=on-failure
RestartSec=10s
TimeoutStopSec=30s

[Install]
WantedBy=default.target
```

Enable and start:

```bash
sudo systemctl --user daemon-reload
sudo systemctl --user enable forgejo-runner.service  
sudo systemctl --user start forgejo-runner.service
sudo systemctl --user status forgejo-runner.service
```

## Step 5: Verify Setup

### Check runner registration on Forgejo UI

1. Go to https://git.panczyk.cc/mpanczyk/wc-product-sync/settings/actions
2. Confirm the runner appears under "Available Runners"

### Trigger a test workflow

```bash
cd /home/seth/Projekty/wpwc-prod-sync  
# Push a dummy commit to trigger CI  
git add .gitea/workflows/*
git commit -m "ci: add smoke tests workflow"
git push forgejo main
```

## Troubleshooting

### Runner not appearing in Forgejo UI
- Verify network connectivity between runner machine and Forgejo instance
- Check firewall rules (port 3000 must be accessible)
- Run `journalctl -u forgejo-runner` to see startup logs

### Container jobs failing  
- Ensure Docker/Podman is running: `sudo systemctl status docker`
- Test network mode: add `network: "host"` to config.yml
- Verify container images are pullable from the runner machine

### Permissions issues
- Ensure actions-runner user has read access to repo files
- Check SSH keys are configured for rig connectivity if tests need remote servers

## Alternative: Lightweight CI with Shell Scripts

If the full Actions runner is too complex, you can use a simpler approach:

1. Create a cron job that pulls latest and runs tests
2. Push results back via webhook to Forgejo API
3. Use `git log --oneline` to detect new commits before running
