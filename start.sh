#!/usr/bin/env bash
# Start a WordPress MCP wrapper for one site (root or ets).
#
# Usage: ./start.sh <site>
#   site = "root" → xenetwork.org network root (users + s2Member + Formidable + Institutional)
#   site = "ets"  → xenetwork.org/ets subsite (episodes + URL Shortify + Formidable v2)
#
# Reads credentials from ~/.mcp-credentials/wordpress-<site>.env which must define:
#   WP_BASE_URL      — e.g. https://xenetwork.org or https://xenetwork.org/ets
#   WP_USERNAME      — WordPress login username (slug, not email)
#   WP_APP_PASSWORD  — Application Password (the 24-char w/ spaces format from WP admin)
#   WP_MCP_PORT      — local port (e.g. 8001 for root, 8002 for ets)
#
# This script is what the launchd plist invokes; also fine to run in foreground for debugging.
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <site>     # site = root | ets" >&2
  exit 64
fi

SITE="$1"
HERE="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${HOME}/.mcp-credentials/wordpress-${SITE}.env"
SERVER_FILE="$HERE/sites/${SITE}/server.py"

if [[ ! -f "$SERVER_FILE" ]]; then
  echo "ERROR: unknown site '$SITE' — no server at $SERVER_FILE" >&2
  echo "       supported: root, ets" >&2
  exit 64
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: credentials not found at $ENV_FILE" >&2
  echo "       create it with WP_BASE_URL, WP_USERNAME, WP_APP_PASSWORD, WP_MCP_PORT" >&2
  exit 65
fi

# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a

: "${WP_BASE_URL:?must be set in $ENV_FILE}"
: "${WP_USERNAME:?must be set in $ENV_FILE}"
: "${WP_APP_PASSWORD:?must be set in $ENV_FILE}"
: "${WP_MCP_PORT:?must be set in $ENV_FILE}"
export WP_MCP_SERVER_NAME="${WP_MCP_SERVER_NAME:-wordpress-${SITE}}"

# Refuse to start if port is already bound.
if lsof -i ":$WP_MCP_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
  echo "Port $WP_MCP_PORT is already in use. PID(s):"
  lsof -i ":$WP_MCP_PORT" -sTCP:LISTEN
  echo
  echo "Stop the older instance with: ./stop.sh $SITE"
  exit 1
fi

echo "Starting wordpress-${SITE} MCP (FastMCP/streamable-http) on http://localhost:${WP_MCP_PORT}/mcp"
echo "WP base URL: $WP_BASE_URL"
echo "WP user:     $WP_USERNAME"
echo

# uv run --script handles dep install + venv on first execution.
exec uv run --script "$SERVER_FILE"
