#!/usr/bin/env bash
# Wire up one WordPress site as a launchd-managed MCP service.
#
# Usage: ./setup-site.sh <site>     # site = root | ets
#
# Prerequisites:
#   1. Homebrew + uv: `brew install uv`
#   2. ~/.mcp-credentials/wordpress-<site>.env with:
#        WP_BASE_URL="https://xenetwork.org"  (or .../ets)
#        WP_USERNAME="..."
#        WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
#        WP_MCP_PORT=8001  (root) or 8002 (ets) — pick distinct ports
#
# Idempotent. Safe on a new machine after `git clone`.
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <site>     # site = root | ets" >&2
  exit 64
fi

SITE="$1"
HERE="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="${HOME}/.mcp-credentials/wordpress-${SITE}.env"
TEMPLATE="$HERE/templates/launchd.plist.template"
SERVER_FILE="$HERE/sites/${SITE}/server.py"

USER_NAME="${USER:-user}"
LABEL="com.${USER_NAME}.wordpress-mcp-${SITE}"
PLIST_DEST="${HOME}/Library/LaunchAgents/${LABEL}.plist"
GUI_DOMAIN="gui/$(id -u)"

# --- Pre-flight -------------------------------------------------------------

if [[ ! -f "$SERVER_FILE" ]]; then
  echo "ERROR: unknown site '$SITE' — no server at $SERVER_FILE" >&2
  echo "       supported: root, ets" >&2
  exit 64
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: credentials not found at $ENV_FILE" >&2
  echo
  echo "Create it with the credentials for the '$SITE' site:" >&2
  echo "  WP_BASE_URL=\"https://xenetwork.org$([[ "$SITE" == "ets" ]] && echo "/ets" || echo "")\"" >&2
  echo "  WP_USERNAME=\"...\"" >&2
  echo "  WP_APP_PASSWORD=\"xxxx xxxx xxxx xxxx xxxx xxxx\"" >&2
  echo "  WP_MCP_PORT=$([[ "$SITE" == "ets" ]] && echo "8002" || echo "8001")" >&2
  exit 65
fi

if [[ ! -f "$TEMPLATE" ]]; then
  echo "ERROR: launchd template not found at $TEMPLATE" >&2
  exit 1
fi

if ! command -v uv >/dev/null 2>&1; then
  echo "ERROR: 'uv' not found in PATH. Install with: brew install uv" >&2
  exit 1
fi

# Validate required vars in env file
# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a
: "${WP_BASE_URL:?must be set in $ENV_FILE}"
: "${WP_USERNAME:?must be set in $ENV_FILE}"
: "${WP_APP_PASSWORD:?must be set in $ENV_FILE}"
: "${WP_MCP_PORT:?must be set in $ENV_FILE}"

chmod +x "$HERE/start.sh" "$HERE/stop.sh"

# --- Generate plist from template -------------------------------------------

mkdir -p "${HOME}/Library/LaunchAgents"

TMP_PLIST="$(mktemp)"
trap 'rm -f "$TMP_PLIST"' EXIT

sed -e "s|__LABEL__|${LABEL}|g" \
    -e "s|__REPO_ROOT__|${HERE}|g" \
    -e "s|__SITE__|${SITE}|g" \
    "$TEMPLATE" > "$TMP_PLIST"

mv "$TMP_PLIST" "$PLIST_DEST"
trap - EXIT

echo "[ok] wrote $PLIST_DEST"

# --- Load (or reload) the launchd job ---------------------------------------

launchctl bootout "${GUI_DOMAIN}/${LABEL}" 2>/dev/null || true
launchctl bootstrap "${GUI_DOMAIN}" "$PLIST_DEST"

echo "[ok] launchd job loaded: ${LABEL}"

# --- Wait for port -----------------------------------------------------------

echo -n "Waiting for wordpress-${SITE} to start on port ${WP_MCP_PORT}"
for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  if lsof -i ":${WP_MCP_PORT}" -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo
    echo "[ok] wordpress-${SITE} is listening on http://localhost:${WP_MCP_PORT}/mcp"
    echo
    echo "Logs:"
    echo "  tail -f /tmp/wordpress-mcp-${SITE}.out.log"
    echo "  tail -f /tmp/wordpress-mcp-${SITE}.err.log"
    echo
    echo "Wire into Claude Desktop by adding to claude_desktop_config.json:"
    echo "  \"wordpress-${SITE}\": {"
    echo "    \"command\": \"/opt/homebrew/bin/mcp-remote\","
    echo "    \"args\": [\"http://localhost:${WP_MCP_PORT}/mcp\"]"
    echo "  }"
    exit 0
  fi
  echo -n "."
  sleep 1
done

echo
echo "WARNING: server didn't come up within 15s. Check logs:"
echo "  tail -100 /tmp/wordpress-mcp-${SITE}.err.log"
exit 2
