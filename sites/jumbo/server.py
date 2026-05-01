#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "fastmcp>=2.5.0",
#   "httpx>=0.27.0",
# ]
# ///
"""
WordPress (Jumbo client sites) MCP — multi-site, read-only.

Why this exists: Justin's Jumbo platform hosts many client WordPress
installs (opusadvisors.events, lcatt.opusadvisors.events, jumbo.live,
and more added over time). For automated QA — confirming a test user
from a Playwright registration run actually landed in WP with the
expected profile data — we need a thin read-only client. This server
holds N site configs, picks one as the active context at boot, and
exposes a switch_site tool to flip between them at runtime.

Unlike the sister sites/root and sites/ets servers (which target the
XE Network multisite and lean on custom mu-plugins for s2Member /
Formidable / Institutional CPT data), this server only touches CORE
WordPress REST endpoints (/wp/v2/users) so the same surface works
against any vanilla WP install. Custom-CPT tools can be added later
per the "add other stuff later" principle.

Read-only by design. No POST/PUT/DELETE tools. Cross-site write
risk is zero by construction.

Reads env vars (sourced from ~/.mcp-credentials/wordpress-jumbo.env
via start.sh):
  WP_DEFAULT_SITE       — name of the site selected at boot (e.g. "opusadvisors")
  WP_MCP_PORT           — local port to listen on (default 8003)
  WP_MCP_SERVER_NAME    — MCP server name (default "wordpress-jumbo")
  WP_SITE_<NAME>_URL    — base URL for site <NAME> (e.g. https://opusadvisors.events)
  WP_SITE_<NAME>_USERNAME    — WP login username for site <NAME>
  WP_SITE_<NAME>_PASSWORD    — Application Password for site <NAME>
                              (24 chars w/ spaces)

Adding a client site = three env vars (URL, USERNAME, PASSWORD) +
optionally promoting it via WP_DEFAULT_SITE. No code changes.

Run with:
  uv run --script server.py
"""

from __future__ import annotations

import base64
import os
import re
import sys
from contextlib import asynccontextmanager
from dataclasses import dataclass
from typing import Any

import httpx
from fastmcp import FastMCP


PORT = int(os.environ.get("WP_MCP_PORT", "8003"))
SERVER_NAME = os.environ.get("WP_MCP_SERVER_NAME", "wordpress-jumbo")


# ---------------------------------------------------------------------------
# Site config — read all WP_SITE_<NAME>_* env vars at boot
# ---------------------------------------------------------------------------

@dataclass
class SiteConfig:
    name: str           # canonical lowercase, e.g. "opusadvisors"
    url: str            # base URL with no trailing slash
    username: str
    password: str

    @property
    def base(self) -> str:
        return f"{self.url}/wp-json/wp/v2"

    @property
    def auth_header(self) -> str:
        token = base64.b64encode(f"{self.username}:{self.password}".encode()).decode()
        return f"Basic {token}"


def _load_sites_from_env() -> dict[str, SiteConfig]:
    """Scan os.environ for WP_SITE_<NAME>_URL/USERNAME/PASSWORD triples.

    Site names are normalized to lowercase. Sites missing any of the
    three required keys are dropped with a warning to stderr.
    """
    pattern = re.compile(r"^WP_SITE_([A-Z0-9_]+)_URL$")
    candidates: dict[str, dict[str, str]] = {}
    for k, v in os.environ.items():
        m = pattern.match(k)
        if m:
            site = m.group(1).lower()
            candidates.setdefault(site, {})["url"] = v.rstrip("/")

    for k, v in os.environ.items():
        if k.startswith("WP_SITE_") and k.endswith("_USERNAME"):
            site = k[len("WP_SITE_"):-len("_USERNAME")].lower()
            candidates.setdefault(site, {})["username"] = v
        elif k.startswith("WP_SITE_") and k.endswith("_PASSWORD"):
            site = k[len("WP_SITE_"):-len("_PASSWORD")].lower()
            candidates.setdefault(site, {})["password"] = v

    sites: dict[str, SiteConfig] = {}
    for name, parts in candidates.items():
        if not all(k in parts for k in ("url", "username", "password")):
            missing = [k for k in ("url", "username", "password") if k not in parts]
            print(
                f"[wp-jumbo-mcp] skipping site '{name}': missing "
                f"{', '.join(f'WP_SITE_{name.upper()}_{m.upper()}' for m in missing)}",
                file=sys.stderr,
            )
            continue
        sites[name] = SiteConfig(
            name=name,
            url=parts["url"],
            username=parts["username"],
            password=parts["password"],
        )
    return sites


SITES = _load_sites_from_env()
if not SITES:
    sys.exit(
        "ERROR: no sites configured. Set at least one site via env vars:\n"
        "  WP_SITE_<NAME>_URL=https://example.com\n"
        "  WP_SITE_<NAME>_USERNAME=your-login\n"
        "  WP_SITE_<NAME>_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'\n"
        "See wordpress-jumbo.env.example."
    )

_default_site_name = os.environ.get("WP_DEFAULT_SITE", "").lower()
if _default_site_name and _default_site_name not in SITES:
    print(
        f"[wp-jumbo-mcp] WARNING: WP_DEFAULT_SITE='{_default_site_name}' not configured. "
        f"Falling back to first available: {sorted(SITES)[0]}",
        file=sys.stderr,
    )
    _default_site_name = sorted(SITES)[0]
elif not _default_site_name:
    _default_site_name = sorted(SITES)[0]


# ---------------------------------------------------------------------------
# Active-site state
# ---------------------------------------------------------------------------

_state = {"active": _default_site_name}


def _active() -> SiteConfig:
    return SITES[_state["active"]]


# Shared httpx client — base URL gets swapped per call rather than building
# a client per site. Keeps connection pool simple.
client = httpx.AsyncClient(
    timeout=httpx.Timeout(30.0, connect=10.0),
    follow_redirects=True,
    headers={
        "Accept": "application/json",
        "User-Agent": f"{SERVER_NAME}/1.0",
    },
)


def _site_headers() -> dict[str, str]:
    """Auth header for the currently-active site."""
    return {"Authorization": _active().auth_header}


async def _get(path: str, params: dict | None = None) -> httpx.Response:
    """GET <active-site-base>/<path> with the active site's auth."""
    site = _active()
    url = f"{site.base}{path}"
    return await client.get(url, params=params, headers=_site_headers())


# ---------------------------------------------------------------------------
# Lifespan — warm the active site at boot and confirm creds
# ---------------------------------------------------------------------------

@asynccontextmanager
async def lifespan(app):
    site = _active()
    try:
        r = await _get("/users/me")
        elapsed_ms = r.elapsed.total_seconds() * 1000
        if r.status_code == 200:
            data = r.json()
            print(
                f"[wp-jumbo-mcp] warmup: GET {site.url}/wp-json/wp/v2/users/me -> 200 "
                f"({elapsed_ms:.0f}ms, user={data.get('slug')!r}, id={data.get('id')})",
                flush=True,
            )
        else:
            print(
                f"[wp-jumbo-mcp] warmup: GET {site.url}/wp-json/wp/v2/users/me -> "
                f"{r.status_code} (check WP_SITE_{site.name.upper()}_PASSWORD)",
                flush=True,
            )
    except Exception as e:
        print(f"[wp-jumbo-mcp] warmup failed (non-fatal): {e}", flush=True)
    yield
    try:
        await client.aclose()
    except Exception:
        pass


mcp = FastMCP(name=SERVER_NAME, lifespan=lifespan)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _err(stage: str, exc: Exception) -> str:
    if isinstance(exc, httpx.HTTPStatusError):
        return (
            f"ERROR ({stage}): HTTP {exc.response.status_code} from "
            f"{exc.request.url} — {exc.response.text[:300]}"
        )
    return f"ERROR ({stage}): {type(exc).__name__}: {exc}"


def _trim_user(u: dict) -> dict:
    """Compress user payload — drop gravatar, _links, capabilities map.

    Keeps the fields needed for QA verification (id, name, email,
    registered_date, roles) plus any custom meta/acf the site exposes.
    """
    return {
        "id": u.get("id"),
        "username": u.get("username"),
        "email": u.get("email"),
        "name": u.get("name"),
        "first_name": u.get("first_name"),
        "last_name": u.get("last_name"),
        "slug": u.get("slug"),
        "url": u.get("url") or None,
        "link": u.get("link"),
        "registered_date": u.get("registered_date"),
        "roles": u.get("roles"),
        "meta": u.get("meta") or None,
        "acf": u.get("acf") or None,
    }


def _meta(site: SiteConfig) -> dict:
    """Per-tool metadata so callers always know which site a result came from."""
    return {"site": site.name, "url": site.url}


# ---------------------------------------------------------------------------
# Site-management tools
# ---------------------------------------------------------------------------

@mcp.tool(
    description=(
        "List all configured Jumbo client sites and indicate which is "
        "currently active. Each site is one of the WordPress installs "
        "this MCP can target (opusadvisors.events, lcatt.opusadvisors.events, "
        "jumbo.live, etc.) — configured via WP_SITE_<NAME>_URL/USERNAME/PASSWORD "
        "env vars in ~/.mcp-credentials/wordpress-jumbo.env.\n"
        "\n"
        "Returns a list of {name, url, active} entries plus the active "
        "site name. Use switch_site(name) to change the active context."
    ),
)
async def list_sites() -> dict:
    return {
        "active": _state["active"],
        "sites": [
            {
                "name": s.name,
                "url": s.url,
                "active": s.name == _state["active"],
            }
            for s in sorted(SITES.values(), key=lambda s: s.name)
        ],
    }


@mcp.tool(
    description=(
        "Return the currently-active Jumbo client site. All other tools "
        "(find_user_by_email, get_user, list_users, whoami) operate "
        "against this site. Call this anytime you need to confirm "
        "which site you're hitting."
    ),
)
async def current_site() -> dict:
    s = _active()
    return {"name": s.name, "url": s.url}


@mcp.tool(
    description=(
        "Switch the active Jumbo client site. All subsequent tool calls "
        "will target the new site until switch_site is called again or "
        "the MCP server restarts (default-on-restart is the WP_DEFAULT_SITE "
        "env var).\n"
        "\n"
        "Args:\n"
        "  name: site name as it appears in list_sites (lowercase, e.g. "
        "'opusadvisors', 'lcatt', 'jumbo'). Case-insensitive.\n"
        "\n"
        "Returns the new active site config plus the previous one for "
        "audit trail.\n"
        "\n"
        "If the name doesn't match a configured site, returns an error "
        "and leaves the active site unchanged."
    ),
)
async def switch_site(name: str) -> dict | str:
    target = name.strip().lower()
    if target not in SITES:
        available = ", ".join(sorted(SITES))
        return (
            f"ERROR: unknown site '{name}'. Configured sites: {available}. "
            f"Add a new one by setting WP_SITE_{target.upper()}_URL, "
            f"WP_SITE_{target.upper()}_USERNAME, and WP_SITE_{target.upper()}_PASSWORD "
            f"in ~/.mcp-credentials/wordpress-jumbo.env, then restart the MCP."
        )
    previous = _state["active"]
    _state["active"] = target
    s = _active()
    return {
        "active": s.name,
        "url": s.url,
        "previous": previous,
    }


# ---------------------------------------------------------------------------
# Read-only WP core tools — operate on the active site
# ---------------------------------------------------------------------------

@mcp.tool(
    description=(
        "Health check on the currently-active Jumbo client site. Round-trips "
        "to /wp/v2/users/me. Returns the authenticated WP user's id, name, "
        "and slug, plus _meta.{site, url} so it's clear which site answered. "
        "Use to confirm credentials work for the active site."
    ),
)
async def whoami() -> dict | str:
    try:
        r = await _get("/users/me", params={"context": "edit"})
        r.raise_for_status()
    except Exception as e:
        return _err("whoami", e)
    return {**_trim_user(r.json()), "_meta": _meta(_active())}


@mcp.tool(
    description=(
        "Find a WordPress user by email address on the currently-active "
        "Jumbo client site. WP REST search matches across email/name/slug — "
        "for an exact email match the result list is typically 1 item.\n"
        "\n"
        "Returns a list of trimmed user records. Result includes "
        "_meta.{site, url} so the source site is unambiguous.\n"
        "\n"
        "Primary QA use: after a Playwright registration test creates a "
        "test user (e.g. guest40-x7k2m@jumbo.live), call this to confirm "
        "the user actually landed in WP with the right profile data."
    ),
)
async def find_user_by_email(email: str) -> dict | str:
    try:
        r = await _get(
            "/users",
            params={"search": email, "context": "edit", "per_page": 10},
        )
        r.raise_for_status()
    except Exception as e:
        return _err("find_user_by_email", e)
    return {
        "users": [_trim_user(u) for u in r.json()],
        "_meta": _meta(_active()),
    }


@mcp.tool(
    description=(
        "Get a full WordPress user record by numeric ID on the "
        "currently-active Jumbo client site. Use after find_user_by_email "
        "when you need the full record including meta and ACF fields.\n"
        "\n"
        "Returns the trimmed user record plus _meta.{site, url}."
    ),
)
async def get_user(id: int) -> dict | str:
    try:
        r = await _get(f"/users/{id}", params={"context": "edit"})
        r.raise_for_status()
    except Exception as e:
        return _err("get_user", e)
    return {**_trim_user(r.json()), "_meta": _meta(_active())}


@mcp.tool(
    description=(
        "List WordPress users on the currently-active Jumbo client site, "
        "paginated. Useful for verifying the most recent registrations "
        "(e.g. after a Playwright matrix run).\n"
        "\n"
        "Args:\n"
        "  page: 1-indexed page number (default 1).\n"
        "  per_page: results per page, max 100 (default 25).\n"
        "  role: optional WP role filter ('subscriber', 'editor', "
        "'administrator', etc.).\n"
        "  search: optional substring search across name/email/slug. "
        "For finding all test users from a recent matrix, search for "
        "the test email prefix (e.g. 'guest40-').\n"
        "  orderby: optional sort key — 'registered_date' is most useful "
        "for QA verification ('most recent users first'). Default 'name'.\n"
        "  order: 'asc' or 'desc' (default 'asc').\n"
        "\n"
        "Returns trimmed user records, total/total_pages from "
        "X-WP-Total/X-WP-TotalPages headers, and _meta.{site, url}."
    ),
)
async def list_users(
    page: int = 1,
    per_page: int = 25,
    role: str | None = None,
    search: str | None = None,
    orderby: str = "name",
    order: str = "asc",
) -> dict | str:
    params: dict[str, Any] = {
        "context": "edit",
        "page": page,
        "per_page": min(per_page, 100),
        "orderby": orderby,
        "order": order,
    }
    if role:
        params["roles"] = role
    if search:
        params["search"] = search
    try:
        r = await _get("/users", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_users", e)
    return {
        "users": [_trim_user(u) for u in r.json()],
        "total": int(r.headers.get("X-WP-Total", 0)),
        "total_pages": int(r.headers.get("X-WP-TotalPages", 0)),
        "page": page,
        "_meta": _meta(_active()),
    }


# ---------------------------------------------------------------------------
# Boot
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    print(
        f"[wp-jumbo-mcp] starting {SERVER_NAME} on http://localhost:{PORT}/mcp",
        flush=True,
    )
    print(
        f"[wp-jumbo-mcp] {len(SITES)} site(s) configured: "
        f"{', '.join(sorted(SITES))}",
        flush=True,
    )
    print(
        f"[wp-jumbo-mcp] active site at boot: {_state['active']} ({_active().url})",
        flush=True,
    )
    mcp.run(transport="http", host="0.0.0.0", port=PORT)
