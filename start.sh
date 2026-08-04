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

HERE="$(cd "$(dirname "$0")" && pwd)"

# Enumerate sites from disk rather than hardcoding. The old hardcoded
# "supported: root, ets" went stale the moment jumbo was added, and a wrong
# list in an error message sends the reader somewhere that does not exist.
list_sites() {
  local d
  for d in "$HERE"/sites/*/server.py; do
    [[ -f "$d" ]] || continue
    basename "$(dirname "$d")"
  done | sort | tr '\n' ' '
}

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <site>" >&2
  echo "       available: $(list_sites)" >&2
  exit 64
fi

SITE="$1"
ENV_FILE="${HOME}/.mcp-credentials/wordpress-${SITE}.env"
SERVER_FILE="$HERE/sites/${SITE}/server.py"

if [[ ! -f "$SERVER_FILE" ]]; then
  echo "ERROR: unknown site '$SITE' — no server at $SERVER_FILE" >&2
  echo "       available: $(list_sites)" >&2
  exit 64
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: credentials not found at $ENV_FILE" >&2
  echo "       create it with WP_BASE_URL, WP_USERNAME, WP_APP_PASSWORD, WP_MCP_PORT" >&2
  exit 65
fi

# --- Pre-flight lint of the env file -----------------------------------------
#
# This MUST run before `source`. A malformed line blows up DURING sourcing, and
# `set -e` then kills the script instantly — so any validation placed after the
# source line (see WP_MCP_PORT etc. below) never executes.
#
# The failure this catches, which cost a full QA session on Aug 3 2026:
# a WP Application Password is 24 chars WITH SPACES ("aaaa bbbb cccc ..."). If
# it is not quoted, bash reads
#
#     WP_SITE_FOO_PASSWORD=aaaa bbbb cccc
#
# as a one-shot assignment prefix and tries to execute `bbbb` as a command. The
# result is a bare
#
#     wordpress-jumbo.env: line 48: bbbb: command not found
#
# and exit 127. launchd reports only the exit code, so the service crash-loops
# with no usable explanation and the MCP connector just times out.
#
# Uses \042 (") and \047 (') so the awk program needs no nested quoting.
BAD_LINES="$(awk '
  /^[[:space:]]*#/  { next }                        # comment
  !/=/              { next }                        # not an assignment
  {
    key = substr($0, 1, index($0, "=") - 1)
    sub(/^[[:space:]]*(export[[:space:]]+)?/, "", key)
    sub(/[[:space:]]+$/, "", key)
    if (key !~ /^[A-Za-z_][A-Za-z0-9_]*$/) next     # not a valid var name
    val = substr($0, index($0, "=") + 1)
    if (val ~ /^[\042\047]/)                 next   # already quoted — fine
    if (val ~ /[[:space:]]/) printf "  line %d: %s\n", FNR, key
  }
' "$ENV_FILE")"

if [[ -n "$BAD_LINES" ]]; then
  echo "ERROR: $ENV_FILE has unquoted value(s) containing spaces:" >&2
  echo "$BAD_LINES" >&2
  echo >&2
  echo "       Sourcing this file would fail with 'command not found' and exit 127." >&2
  echo "       WP Application Passwords contain spaces and MUST be quoted:" >&2
  echo >&2
  echo '           WP_SITE_FOO_PASSWORD="aaaa bbbb cccc dddd eeee ffff"' >&2
  echo >&2
  echo "       Keep the spaces — they are part of the password. Just add the quotes." >&2
  exit 66
fi

# shellcheck disable=SC1090
set -a; source "$ENV_FILE"; set +a

# Detect multi-site mode by checking for any WP_SITE_*_URL vars (after env load).
# Multi-site env files use the WP_SITE_<NAME>_* triple pattern + WP_DEFAULT_SITE.
# Single-site env files (wordpress-energytransitionshow, wordpress-xenetwork)
# use WP_BASE_URL/WP_USERNAME/WP_APP_PASSWORD directly.
MULTI_SITE_MODE=0
if compgen -v 2>/dev/null | grep -qE '^WP_SITE_[A-Z0-9_]+_URL$'; then
  MULTI_SITE_MODE=1
fi

if [[ "$MULTI_SITE_MODE" -eq 1 ]]; then
  # Multi-site: server.py validates WP_SITE_<NAME>_* triples itself.
  # Only require WP_MCP_PORT here; the rest is server.py's concern.
  : "${WP_MCP_PORT:?must be set in $ENV_FILE}"
else
  # Single-site: keep the original strict validation.
  : "${WP_BASE_URL:?must be set in $ENV_FILE}"
  : "${WP_USERNAME:?must be set in $ENV_FILE}"
  : "${WP_APP_PASSWORD:?must be set in $ENV_FILE}"
  : "${WP_MCP_PORT:?must be set in $ENV_FILE}"
fi
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
if [[ "$MULTI_SITE_MODE" -eq 1 ]]; then
  echo "Mode:        multi-site (WP_SITE_<NAME>_* triples)"
  echo "Default:     ${WP_DEFAULT_SITE:-<unset>}"
else
  echo "WP base URL: $WP_BASE_URL"
  echo "WP user:     $WP_USERNAME"
fi
echo

# uv run --script handles dep install + venv on first execution.
exec uv run --script "$SERVER_FILE"
