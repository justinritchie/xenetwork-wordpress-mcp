# Setting up xenetwork-wordpress-mcp on a new machine

## 1. Prerequisites

```bash
# Homebrew (skip if installed)
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# uv — Python script runner used by both servers
brew install uv

# mcp-remote — bridges Claude Desktop to the local HTTP MCPs
npm install -g mcp-remote
```

## 2. Get the code

```bash
mkdir -p ~/justinritchie-mcp-servers
cd ~/justinritchie-mcp-servers
git clone https://github.com/justinritchie/xenetwork-wordpress-mcp.git
```

## 3. Place credentials

For each site you're wiring up, create `~/.mcp-credentials/wordpress-<site>.env`:

```bash
mkdir -p ~/.mcp-credentials
chmod 700 ~/.mcp-credentials

cat > ~/.mcp-credentials/wordpress-root.env <<'EOF'
WP_BASE_URL="https://xenetwork.org"
WP_USERNAME="your-wp-username"
WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
WP_MCP_PORT=8001
EOF
chmod 600 ~/.mcp-credentials/wordpress-root.env

cat > ~/.mcp-credentials/wordpress-ets.env <<'EOF'
WP_BASE_URL="https://xenetwork.org/ets"
WP_USERNAME="your-wp-username"
WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
WP_MCP_PORT=8002
EOF
chmod 600 ~/.mcp-credentials/wordpress-ets.env
```

If you keep credentials in a private repo, just clone it to that location instead — the env files are picked up automatically.

**Application Passwords**: WP admin → Users → your user → Application Passwords. The 24-character value with spaces is correct — paste it literally including the spaces. Application Passwords are network-wide on a multisite, so the same value works for both root and ets.

## 4. Deploy the mu-plugins (root site only)

The `root` server depends on two custom mu-plugins to expose s2Member metadata and Formidable Forms data. Copy them to your WordPress server:

```bash
# from your local clone:
scp sites/root/mu-plugins/*.php your-server:/path/to/wordpress/wp-content/mu-plugins/
```

Or via SFTP / WP-Engine portal / however you normally deploy. Files needed:

- `wp-content/mu-plugins/xen-s2member-rest.php`
- `wp-content/mu-plugins/xen-formidable-rest.php`

mu-plugins are auto-loaded on every page hit; no activation needed.

If you skip this step, the `root` server still boots and the standard `/wp/v2/users` tools work, but s2Member metadata and root Formidable tools will return 404s.

## 5. Install each site as a launchd service

```bash
cd ~/justinritchie-mcp-servers/xenetwork-wordpress-mcp
./setup-site.sh root
./setup-site.sh ets
```

Each invocation validates the env file, generates `~/Library/LaunchAgents/com.<user>.wordpress-mcp-<site>.plist` from the template, loads the launchd job, and verifies the server is listening. Idempotent.

## 6. Wire Claude Desktop

Edit `~/Library/Application Support/Claude/claude_desktop_config.json` and add:

```json
"mcpServers": {
  "wordpress-root": { "command": "/opt/homebrew/bin/mcp-remote", "args": ["http://localhost:8001/mcp"] },
  "wordpress-ets":  { "command": "/opt/homebrew/bin/mcp-remote", "args": ["http://localhost:8002/mcp"] }
}
```

⌘Q + relaunch Claude Desktop. Both connectors should appear as healthy.

## 7. Verify

```bash
# Quick health check on both ports (HTTP 406 means the server is up but expects MCP-formatted requests)
curl -sI http://localhost:8001/mcp | head -1
curl -sI http://localhost:8002/mcp | head -1

# Check launchd jobs
launchctl list | grep wordpress-mcp

# Tail logs
tail -50 /tmp/wordpress-mcp-root.err.log
tail -50 /tmp/wordpress-mcp-ets.err.log
```

In Claude Desktop, ask the `wordpress-root` connector to call `whoami` — should return your WP user record.

## Updating

```bash
cd ~/justinritchie-mcp-servers/xenetwork-wordpress-mcp
git pull

# Reload both — picks up updated server.py
./setup-site.sh root
./setup-site.sh ets
```

If a `git pull` updates the mu-plugins, deploy the new versions to your WordPress server (step 4).

## Troubleshooting

**`401 Unauthorized` from WP** — `WP_USERNAME` or `WP_APP_PASSWORD` is wrong. Re-check the App Password (it's `xxxx xxxx xxxx xxxx xxxx xxxx` with spaces). Application Passwords don't work on user accounts that have 2FA without the right plugin.

**`404` on `/xen/v1/...` routes (s2Member, root Formidable)** — the mu-plugins aren't deployed on the WordPress server. See step 4.

**Port already in use** — change `WP_MCP_PORT` in the env file and re-run `setup-site.sh`.

**Server didn't come up within 15s** — almost always uv installing dependencies on first run. Run `uv run --script ~/justinritchie-mcp-servers/xenetwork-wordpress-mcp/_warmup-deps.py` once, then re-run `setup-site.sh`.
