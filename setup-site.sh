#!/usr/bin/env bash
# Wire up one WordPress site as a launchd-managed MCP service.
#
# Usage: ./setup-site.sh <site>     # site = root | ets | jumbo
#
# Prerequisites:
#   1. Homebrew + uv: `brew install uv`
#   2. ~/.mcp-credentials/wordpress-<site>.env. Shape depends on site:
#
#      Single-site (root, ets):
#        WP_BASE_URL="https://xenetwork.org"   (or .../ets)
#        WP_USERNAME="..."
#        WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
#        WP_MCP_PORT=8001                      (root) or 8002 (ets)
#
#      Multi-site (jumbo):
#        WP_MCP_PORT=8003
#        WP_DEFAULT_SITE="opusadvisors"
#        WP_SITE_OPUSADVISORS_URL="https://opusadvisors.events"
#        WP_SITE_OPUSADVISORS_USERNAME="..."
#        WP_SITE_OPUSADVISORS_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
#        # ...add more WP_SITE_<NAME>_* groups for each client site
#
# Idempotent. Safe on a new machine after `git clone`.
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <site>     # site = root | ets | jumbo" >&2
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

# Sites that use the multi-site env shape (no WP_BASE_URL/USERNAME/APP_PASSWORD;
# instead WP_SITE_<NAME>_* groups + WP_DEFAULT_SITE). Add new multi-site
# servers here as they're added.
MULTISITE_SITES=("jumbo")

is_multisite() {
  local s
  for s in "${MULTISITE_SITES[@]}"; do
    [[ "$s" == "$SITE" ]] && return 0
  done
  return 1
}

# --- Pre-flight -------------------------------------------------------------

if [[ ! -f "$SERVER_FILE" ]]; then
  echo "ERROR: unknown site '$SITE' — no server at $SERVER_FILE" >&2
  echo "       supported: root, ets, jumbo" >&2
  exit 64
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: credentials not found at $ENV_FILE" >&2
  echo
  if is_multisite; then
    echo "Create it with the multi-site env shape:" >&2
    echo "  WP_MCP_PORT=8003" >&2
    echo "  WP_DEFAULT_SITE=\"opusadvisors\"" >&2
    echo "  WP_SITE_OPUSADVISORS_URL=\"https://opusadvisors.events\"" >&2
    echo "  WP_SITE_OPUSADVISORS_USERNAME=\"your-wp-login\"" >&2
    echo "  WP_SITE_OPUSADVISORS_PASSWORD=\"xxxx xxxx xxxx xxxx xxxx xxxx\"" >&2
    echo "  # Repeat WP_SITE_<NAME>_* for each additional client site." >&2
    echo "See wordpress-jumbo.env.example for the full template." >&2
  else
    echo "Create it with the credentials for the '$SITE' site:" >&2
    echo "  WP_BASE_URL=\"https://xenetwork.org$([[ "$SITE" == "ets" ]] && echo "/ets" || echo "")\"" >&2
    echo "  WP_USERNAME=\"...\"" >&2
    echo "  WP_APP_PASSWORD=\"xxxx xxxx xxxx xxxx xxxx xxxx\"" >&2
    echo "  WP_MCP_PORT=$([[ "$SITE" == "ets" ]] && echo "8002" || echo "8001")" >&2
  fi
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
: "${WP_MCP_PORT:?must be set in $ENV_FILE}"
if is_multisite; then
  # Multi-site shape — at least one WP_SITE_<NAME>_URL must be set.
  if ! compgen -v | grep -qE '^WP_SITE_[A-Z0-9_]+_URL$'; then
    echo "ERROR: no WP_SITE_<NAME>_URL set in $ENV_FILE" >&2
    echo "       multi-site config requires at least one site group:" >&2
    echo "         WP_SITE_<NAME>_URL=https://example.com" >&2
    echo "         WP_SITE_<NAME>_USERNAME=your-login" >&2
    echo "         WP_SITE_<NAME>_PASSWORD=\"xxxx xxxx xxxx xxxx xxxx xxxx\"" >&2
    exit 65
  fi
else
  # Single-site shape — the original validation.
  : "${WP_BASE_URL:?must be set in $ENV_FILE}"
  : "${WP_USERNAME:?must be set in $ENV_FILE}"
  : "${WP_APP_PASSWORD:?must be set in $ENV_FILE}"
fi

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
