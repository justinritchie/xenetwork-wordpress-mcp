#!/usr/bin/env bash
# Tear down a WordPress MCP site's launchd job and remove its plist.
#
# Usage: ./uninstall-site.sh <site>     # site = root | ets
#
# The credentials file at ~/.mcp-credentials/wordpress-<site>.env is left in place.
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <site>     # site = root | ets" >&2
  exit 64
fi

SITE="$1"
USER_NAME="${USER:-user}"
LABEL="com.${USER_NAME}.wordpress-mcp-${SITE}"
PLIST_PATH="${HOME}/Library/LaunchAgents/${LABEL}.plist"
GUI_DOMAIN="gui/$(id -u)"

if launchctl print "${GUI_DOMAIN}/${LABEL}" >/dev/null 2>&1; then
  launchctl bootout "${GUI_DOMAIN}/${LABEL}" || true
  echo "[ok] launchd job booted out: ${LABEL}"
else
  echo "[noop] launchd job ${LABEL} was not loaded"
fi

if [[ -f "$PLIST_PATH" ]]; then
  rm "$PLIST_PATH"
  echo "[ok] removed $PLIST_PATH"
else
  echo "[noop] $PLIST_PATH did not exist"
fi

echo
echo "Note: ~/.mcp-credentials/wordpress-${SITE}.env was NOT touched."
