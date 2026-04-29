#!/usr/bin/env bash
# Stop a WordPress MCP wrapper for one site.
#
# Usage: ./stop.sh <site>     # site = root | ets
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <site>     # site = root | ets" >&2
  exit 64
fi

SITE="$1"
ENV_FILE="${HOME}/.mcp-credentials/wordpress-${SITE}.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: credentials not found at $ENV_FILE" >&2
  exit 65
fi

# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a
: "${WP_MCP_PORT:?must be set in $ENV_FILE}"

PIDS=$(lsof -i ":$WP_MCP_PORT" -sTCP:LISTEN -t 2>/dev/null || true)
if [[ -z "$PIDS" ]]; then
  echo "Nothing listening on port $WP_MCP_PORT for wordpress-${SITE}."
  exit 0
fi

echo "Stopping wordpress-${SITE} (PIDs: $PIDS)"
# shellcheck disable=SC2086
kill $PIDS
sleep 1

if lsof -i ":$WP_MCP_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
  echo "Still bound. Sending SIGKILL."
  # shellcheck disable=SC2086
  kill -9 $PIDS || true
fi

echo "Done. (If launchd has the job loaded with KeepAlive, it'll respawn — use uninstall-site.sh to fully unload.)"
