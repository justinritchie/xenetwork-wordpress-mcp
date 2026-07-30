#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "fastmcp>=3.2,<4",
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

import asyncio
import base64
import json
import os
import re
import sys
import time
from contextlib import asynccontextmanager
from dataclasses import dataclass
from typing import Annotated, Any

import httpx
from fastmcp import FastMCP
from pydantic import BeforeValidator


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
# Cross-site disambiguation: prepend a "[WP site: <label>]" tag to every
# tool's description so MCP clients can pick the right wordpress-* connector
# by semantic search. This server itself hosts MANY Jumbo client sites
# (opusadvisors, lcatt, jumbo.live, etc.) — internal switching happens via
# the switch_site tool. The label here only disambiguates the wordpress-jumbo
# MCP from wordpress-xenetwork and wordpress-energytransitionshow.
#
# Override via WP_SITE_LABEL env var; default is "Jumbo client sites".
# ---------------------------------------------------------------------------
SITE_LABEL = os.environ.get("WP_SITE_LABEL", "Jumbo client sites (opusadvisors, lcatt, jumbo.live, etc.)")

if SITE_LABEL:
    _label_prefix = f"[WP site: {SITE_LABEL}] "
    _original_mcp_tool = mcp.tool

    def _site_labeled_tool(*args, **kwargs):
        """Wrap mcp.tool so every registered tool gets `[WP site: …]`
        prepended to its description. Forwards everything else unchanged."""
        desc = kwargs.get("description")
        if desc and not desc.startswith(_label_prefix):
            kwargs["description"] = _label_prefix + desc
        return _original_mcp_tool(*args, **kwargs)

    mcp.tool = _site_labeled_tool  # type: ignore[method-assign]


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

# >>> css-options-tools (managed by claude) >>>
# =============================================================================
# v2.5.0 mu-plugin readers — Customizer CSS + wp_options
# =============================================================================
#
# Wraps /jumbo-qa/v1/{custom-css/*, options, options/<key>, options-search}
# from jumbo-qa-rest.php v2.5.0+. All read-only. All gated on manage_options.
#
# Design:
#   - get_custom_css_outline()  — orientation, ~2KB regardless of file size
#   - search_custom_css(...)    — the workhorse: find rules that might conflict
#   - get_custom_css_full()     — escape hatch when targeted tools miss it
#   - list_options(prefix)      — paginated key+length summary
#   - get_option(key)           — single full option
#   - search_options(pattern)   — grep across option_name + option_value


def _jq_url(path: str) -> str:
    """Build absolute URL for a /wp-json/jumbo-qa/v1/{path} endpoint."""
    site = _active()
    return f"{site.url}/wp-json/jumbo-qa/v1{path}"


async def _jq_request(method: str, path: str, params: dict | None = None) -> httpx.Response:
    """HTTP request against active site's jumbo-qa/v1 endpoint."""
    url = _jq_url(path)
    kwargs: dict[str, Any] = {"headers": _site_headers()}
    if params is not None:
        kwargs["params"] = params
    return await client.request(method, url, **kwargs)


# ---------------------------------------------------------------------------
# Customizer "Additional CSS" readers
# ---------------------------------------------------------------------------

@mcp.tool(
    description=(
        "Read the FULL Customizer 'Additional CSS' body for the active theme "
        "on the currently-active Jumbo site. The escape hatch when targeted "
        "tools (search/outline) don't catch what you need.\n"
        "\n"
        "Recommended pattern: call this, then immediately write the response's "
        "`css` field to a local file in your workspace, then grep locally with "
        "shell tools — this keeps the large blob out of your context window for "
        "subsequent calls.\n"
        "\n"
        "Returns: {active_theme, css, length, line_count, hash, fetched_at, _meta}. "
        "For a 10K-line CSS file expect a ~500KB-1MB response."
    ),
)
async def get_custom_css_full() -> dict | str:
    try:
        r = await _jq_request("GET", "/custom-css/full")
        r.raise_for_status()
    except Exception as e:
        return _err("get_custom_css_full", e)
    data = r.json()
    data["_meta"] = _meta(_active())
    return data


@mcp.tool(
    description=(
        "Read the STRUCTURE of the active theme's Customizer 'Additional CSS' "
        "without the body. Returns the parsed section-comment markers (any "
        "`/* === Section === */` or `/* --- Section --- */` style header), "
        "total length, line count, SHA-1 hash, and a 10-line preview.\n"
        "\n"
        "Always ~2KB response regardless of CSS size — call this first to orient "
        "before drilling in with search_custom_css or fetching the full blob.\n"
        "\n"
        "Returns: {active_theme, length, line_count, hash, sections: [{line, "
        "title, raw}], preview: [first 10 lines], _meta}."
    ),
)
async def get_custom_css_outline() -> dict | str:
    try:
        r = await _jq_request("GET", "/custom-css/outline")
        r.raise_for_status()
    except Exception as e:
        return _err("get_custom_css_outline", e)
    data = r.json()
    data["_meta"] = _meta(_active())
    return data


@mcp.tool(
    description=(
        "Regex or literal-substring search across the active theme's Customizer "
        "CSS. The workhorse for 'find any existing rules that might conflict "
        "with what I'm about to add.'\n"
        "\n"
        "Args:\n"
        "  pattern: required. Literal string by default; pass regex=True to "
        "interpret as PCRE regex.\n"
        "  context_lines: 0-20 (default 5). Lines of surrounding context per match.\n"
        "  max_matches: 1-500 (default 50). Hard cap on matches returned.\n"
        "  case_insensitive: default False.\n"
        "  regex: default True. If False, pattern is taken literally.\n"
        "\n"
        "Returns: {pattern, regex, case_insensitive, context_lines, "
        "total_match_count, returned_count, truncated, matches: "
        "[{line_number, line, context_before, context_after}], _meta}.\n"
        "\n"
        "Examples:\n"
        "  search_custom_css('!important')\n"
        "  search_custom_css('@media.*max-width.*768', regex=True)\n"
        "  search_custom_css('.gform_wrapper', regex=False)\n"
        "  search_custom_css('#003366|#003c66', regex=True, case_insensitive=True)"
    ),
)
async def search_custom_css(
    pattern: str,
    context_lines: int = 5,
    max_matches: int = 50,
    case_insensitive: bool = False,
    regex: bool = True,
) -> dict | str:
    params = {
        "pattern": pattern,
        "context": context_lines,
        "max_matches": max_matches,
        "case_insensitive": "true" if case_insensitive else "false",
        "regex": "true" if regex else "false",
    }
    try:
        r = await _jq_request("GET", "/custom-css/search", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("search_custom_css", e)
    data = r.json()
    data["_meta"] = _meta(_active())
    return data


# ---------------------------------------------------------------------------
# wp_options readers
# ---------------------------------------------------------------------------

@mcp.tool(
    description=(
        "Get a single wp_options value from the currently-active Jumbo site. "
        "WordPress auto-unserializes arrays/objects so callers see the "
        "structured form. Use this for things like:\n"
        "  - 'blogname' / 'blogdescription' / 'siteurl'\n"
        "  - 'theme_mods_<active-theme-slug>' (Customizer settings array)\n"
        "  - 'gforms_settings' / 'rg_form_field_settings'\n"
        "  - any plugin's settings option\n"
        "\n"
        "Args:\n"
        "  key: required. The exact option_name from wp_options.\n"
        "  max_chars: default 100000. Caps the JSON-encoded value size; "
        "truncated:true flag is set if exceeded.\n"
        "\n"
        "Returns: {key, value, type, length, truncated, max_chars, autoload, _meta}.\n"
        "404 if the option doesn't exist (disambiguated from 'value is false')."
    ),
)
async def get_option(key: str, max_chars: int = 100000) -> dict | str:
    try:
        r = await _jq_request("GET", f"/options/{key}", params={"max_chars": max_chars})
        if r.status_code == 404:
            return _err("get_option", ValueError(f"option '{key}' does not exist"))
        r.raise_for_status()
    except Exception as e:
        return _err("get_option", e)
    data = r.json()
    data["_meta"] = _meta(_active())
    return data


@mcp.tool(
    description=(
        "List wp_options matching a REQUIRED prefix. Prefix is required to "
        "prevent accidental full-table dump (wp_options can have 1000+ rows on "
        "a plugin-heavy site).\n"
        "\n"
        "Args:\n"
        "  prefix: required. option_name prefix (e.g. 'theme_mods_', "
        "'gforms_', 'widget_', 'cron').\n"
        "  autoload: optional 'yes' | 'no' filter.\n"
        "  max_value_chars: default 200. Values longer than this are truncated; "
        "use get_option() to fetch the full value if interesting.\n"
        "  limit: 1-500 (default 100).\n"
        "\n"
        "Returns: {prefix, autoload_filter, count, items: [{key, value, length, "
        "truncated, autoload, hash}], _meta}.\n"
        "\n"
        "Common prefixes worth knowing:\n"
        "  theme_mods_<slug>  — Customizer theme settings\n"
        "  gforms_            — Gravity Forms settings\n"
        "  widget_            — widget settings per widget area\n"
        "  jetpack_           — Jetpack module configs\n"
        "  cron               — wp-cron schedule"
    ),
)
async def list_options(
    prefix: str,
    autoload: str | None = None,
    max_value_chars: int = 200,
    limit: int = 100,
) -> dict | str:
    params: dict[str, Any] = {
        "prefix": prefix,
        "max_value_chars": max_value_chars,
        "limit": limit,
    }
    if autoload is not None:
        params["autoload"] = autoload
    try:
        r = await _jq_request("GET", "/options", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_options", e)
    data = r.json()
    data["_meta"] = _meta(_active())
    return data


@mcp.tool(
    description=(
        "Substring search across wp_options names AND values. Returns context "
        "snippets so callers can see what matched without fetching whole "
        "values. Useful for 'which option contains this URL/email/token?'.\n"
        "\n"
        "Args:\n"
        "  pattern: required. Plain substring (LIKE-style match — no regex).\n"
        "  prefix: optional option_name prefix to scope the search.\n"
        "  limit: 1-500 (default 100).\n"
        "  context_chars: 0-2000 (default 100). Surrounding chars around match.\n"
        "\n"
        "Returns: {pattern, prefix_filter, count, matches: [{key, autoload, "
        "value_length, match_in_name, match_in_value, context}], _meta}.\n"
        "\n"
        "Note: this is a SQL LIKE search, not regex. For regex, fetch via "
        "get_option(key) or list_options(prefix) and grep locally."
    ),
)
async def search_options(
    pattern: str,
    prefix: str | None = None,
    limit: int = 100,
    context_chars: int = 100,
) -> dict | str:
    params: dict[str, Any] = {
        "pattern": pattern,
        "limit": limit,
        "context_chars": context_chars,
    }
    if prefix is not None:
        params["prefix"] = prefix
    try:
        r = await _jq_request("GET", "/options-search", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("search_options", e)
    data = r.json()
    data["_meta"] = _meta(_active())
    return data
# <<< css-options-tools (managed by claude) <<<


# >>> user-meta-write-tools (managed by claude) >>>
# =============================================================================
# Scoped user-meta WRITE surface — allowlisted, site-explicit, dry-run default
# =============================================================================
#
# The ONLY write capability in this otherwise read-only server. Sets a single
# allowlisted user-meta key on known users, one EXPLICIT site at a time.
#
# Hard rules (see SECURITY.md):
#   - `site` is REQUIRED and explicit on every write tool; NEVER inferred from
#     the ambient active site (_active()). Prevents flipping meta on the wrong
#     event's registrants — an unrecoverable-without-DB-restore failure.
#   - Only keys in WRITABLE_META_KEYS may be written; anything else is refused
#     with zero HTTP calls.
#   - dry_run defaults to True; a caller must pass dry_run=False to mutate.
#     The dry run does the full resolution + old-value read, so the preview is
#     real, not hypothetical.
#   - Every write is post-verified: the value is read back from WP and only
#     reported `applied` if the readback matches, else `write_unconfirmed`.
#     WordPress silently 200s and DISCARDS meta whose key is NOT registered
#     with show_in_rest — the returned representation still shows the old value,
#     so the readback catches it. Ship templates/jumbo-mcp-meta.php per site.
#   - Bulk resolves ALL identifiers before writing any; aborts the whole batch
#     on any unresolved identifier unless allow_partial=True. Never half-applies.

WRITABLE_META_KEYS = set(
    k.strip()
    for k in os.environ.get("WP_WRITABLE_META_KEYS", "event_registration_status").split(",")
    if k.strip()
)


def _coerce_list(v):
    """Accept a real list, a JSON-encoded list string, or a comma-separated string.

    Why: MCP clients commonly serialize array arguments as a JSON *string*, and
    pydantic v2 strict mode rejects that with "Input should be a valid list"
    before the tool body ever runs (see tasks/lessons.md). Coercing here keeps
    the batch tools callable from any client without loosening the schema.
    """
    if isinstance(v, str):
        s = v.strip()
        if not s:
            return []
        try:
            parsed = json.loads(s)
        except Exception:
            return [x.strip() for x in s.split(",") if x.strip()]
        return parsed if isinstance(parsed, list) else [parsed]
    return v


# Batch identifier params — tolerant of JSON-string input from MCP clients.
EmailList = Annotated[list[str] | None, BeforeValidator(_coerce_list)]
UserIdList = Annotated[list[int] | None, BeforeValidator(_coerce_list)]

# Bounded concurrency + backoff so a 150-user batch never hammers WP Engine.
_WRITE_CONCURRENCY = max(1, int(os.environ.get("WP_WRITE_CONCURRENCY", "4")))
_RETRY_STATUSES = {429, 500, 502, 503, 504}


def _resolve_site(name: str) -> SiteConfig:
    """Explicit site resolution for WRITE paths. Raises ValueError on unknown.

    Deliberately does NOT fall back to _active() — write tools must name their
    target site so a batch can never land on the wrong event's registrants.
    """
    key = (name or "").strip().lower()
    if key not in SITES:
        raise ValueError(
            f"unknown site '{name}'. Configured: {', '.join(sorted(SITES))}. "
            f"Write tools require an explicit, known site and never fall back "
            f"to the active site."
        )
    return SITES[key]


def _site_auth(site: SiteConfig) -> dict[str, str]:
    """Auth header for an EXPLICIT site (not the active one)."""
    return {"Authorization": site.auth_header}


async def _get_site(site: SiteConfig, path: str, params: dict | None = None) -> httpx.Response:
    """GET against an EXPLICIT site (not _active())."""
    return await client.get(f"{site.base}{path}", params=params, headers=_site_auth(site))


async def _post_site(
    site: SiteConfig, path: str, json_body: dict, params: dict | None = None
) -> httpx.Response:
    """POST JSON against an EXPLICIT site, retrying 429/5xx with exponential backoff.

    httpx sets Content-Type: application/json from json=. Returns the final
    response; the caller inspects status (never half-swallows an error).
    """
    url = f"{site.base}{path}"
    headers = _site_auth(site)
    delay = 1.0
    resp: httpx.Response | None = None
    for attempt in range(4):
        resp = await client.post(url, json=json_body, params=params, headers=headers)
        if resp.status_code not in _RETRY_STATUSES:
            return resp
        if attempt < 3:
            await asyncio.sleep(delay)
            delay *= 2
    return resp  # retries exhausted; still a real Response for the caller


def _audit(site: SiteConfig, tool: str, user_id: Any, key: str, old: Any, new: Any, result: str) -> None:
    """One structured audit line to stderr per write attempt. No credentials."""
    print(
        f"[wp-jumbo-mcp][AUDIT] ts={time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())} "
        f"site={site.name} tool={tool} user_id={user_id} key={key} "
        f"old={old!r} new={new!r} result={result}",
        file=sys.stderr,
        flush=True,
    )


def _summarize(rows: list[dict]) -> dict:
    """Derive the batch summary purely from final row statuses."""
    def n(status: str) -> int:
        return sum(1 for r in rows if r["status"] == status)
    return {
        "total": len(rows),
        "resolved": sum(1 for r in rows if r["status"] != "unresolved"),
        "unresolved": n("unresolved"),
        "already_set": n("already_set"),
        "would_change": n("would_change"),
        "applied": n("applied"),
        "write_unconfirmed": n("write_unconfirmed"),
        "failed": n("failed"),
    }


def _exact_match(results: list[dict], email: str) -> tuple[int | None, str, dict]:
    """From a WP /users?search result, find the EXACTLY-one exact-email match.

    Returns (user_id, status, meta). status in {ok, not_found, ambiguous}.
    WP search is fuzzy (email/name/slug), so we filter to exact email here.
    """
    tgt = email.strip().lower()
    exact = [u for u in results if str(u.get("email", "")).strip().lower() == tgt]
    if len(exact) == 1:
        return int(exact[0]["id"]), "ok", (exact[0].get("meta") or {})
    if not exact:
        return None, "not_found", {}
    return None, "ambiguous", {}


async def _resolve_email(site: SiteConfig, email: str) -> tuple[int | None, str, dict]:
    """Resolve one email -> (user_id, status, meta) against an EXPLICIT site."""
    try:
        r = await _get_site(site, "/users", {"search": email, "context": "edit", "per_page": 10})
        r.raise_for_status()
    except Exception as e:  # noqa: BLE001 — surfaced as a row status
        return None, f"error:{type(e).__name__}", {}
    return _exact_match(r.json(), email)


@mcp.tool(
    description=(
        "Set a single allowlisted user-meta key on ONE user of an EXPLICIT "
        "Jumbo client site. Dry-run by default — you must pass dry_run=False "
        "to actually write.\n"
        "\n"
        "Primary use: flip `event_registration_status` (blank=pending, "
        "'confirmed-N'=confirmed in batch N) that audience segments key off.\n"
        "\n"
        "Args:\n"
        "  site: REQUIRED site name from list_sites (e.g. 'lcatt'). Never "
        "inferred from the active site.\n"
        "  key: meta key; must be in the server's WRITABLE_META_KEYS allowlist.\n"
        "  value: the string value to set.\n"
        "  user_id | email: provide EXACTLY ONE. email resolves to exactly one "
        "exact-match user or errors.\n"
        "  dry_run: default True. Reads the real current value and reports what "
        "WOULD change without writing.\n"
        "\n"
        "Returns {site, user_id, email, key, old_value, new_value, status, "
        "applied, dry_run}. status is one of would_change / already_set / "
        "applied / write_unconfirmed / not_found / ambiguous / an error string. "
        "A write is `applied` ONLY if the post-write readback matches; a WP "
        "silent-discard (unregistered meta key) surfaces as write_unconfirmed."
    ),
)
async def update_user_meta(
    site: str,
    key: str,
    value: str,
    user_id: int | None = None,
    email: str | None = None,
    dry_run: bool = True,
) -> dict:
    if key not in WRITABLE_META_KEYS:
        return {
            "error": f"key '{key}' is not writable. Allowlist: {sorted(WRITABLE_META_KEYS)}.",
            "key": key, "applied": False, "dry_run": dry_run,
        }
    try:
        s = _resolve_site(site)
    except ValueError as e:
        return {"error": str(e), "applied": False, "dry_run": dry_run}
    if (user_id is None) == (email is None):
        return {
            "error": "provide EXACTLY ONE of user_id or email.",
            "site": s.name, "applied": False, "dry_run": dry_run,
        }

    resolved_email = email
    if email is not None:
        uid, status, meta = await _resolve_email(s, email)
        if status != "ok":
            return {
                "site": s.name, "user_id": None, "email": email, "key": key,
                "old_value": None, "new_value": value, "status": status,
                "applied": False, "dry_run": dry_run,
            }
    else:
        uid = int(user_id)  # type: ignore[arg-type]
        try:
            r = await _get_site(s, f"/users/{uid}", {"context": "edit"})
            r.raise_for_status()
            meta = r.json().get("meta") or {}
        except Exception as e:  # noqa: BLE001
            return {
                "site": s.name, "user_id": uid, "email": None, "key": key,
                "new_value": value, "status": _err("update_user_meta.read", e),
                "applied": False, "dry_run": dry_run,
            }

    old_value = meta.get(key)

    if old_value == value:
        return {
            "site": s.name, "user_id": uid, "email": resolved_email, "key": key,
            "old_value": old_value, "new_value": value, "status": "already_set",
            "applied": False, "dry_run": dry_run,
        }

    if dry_run:
        return {
            "site": s.name, "user_id": uid, "email": resolved_email, "key": key,
            "old_value": old_value, "new_value": value, "status": "would_change",
            "applied": False, "dry_run": True,
        }

    try:
        pr = await _post_site(s, f"/users/{uid}", {"meta": {key: value}}, params={"context": "edit"})
        pr.raise_for_status()
        readback = (pr.json().get("meta") or {}).get(key)
    except Exception as e:  # noqa: BLE001
        _audit(s, "update_user_meta", uid, key, old_value, value, "failed")
        return {
            "site": s.name, "user_id": uid, "email": resolved_email, "key": key,
            "old_value": old_value, "new_value": value,
            "status": _err("update_user_meta.write", e), "applied": False, "dry_run": False,
        }

    confirmed = readback == value
    result = "applied" if confirmed else "write_unconfirmed"
    _audit(s, "update_user_meta", uid, key, old_value, value, result)
    return {
        "site": s.name, "user_id": uid, "email": resolved_email, "key": key,
        "old_value": old_value, "new_value": value, "readback": readback,
        "status": result, "applied": confirmed, "dry_run": False,
    }


@mcp.tool(
    description=(
        "Set a single allowlisted user-meta key+value on a BATCH of users of an "
        "EXPLICIT Jumbo client site. Dry-run by default. Resolves ALL users "
        "first and aborts the whole batch on any unresolved identifier unless "
        "allow_partial=True — never half-applies silently.\n"
        "\n"
        "Primary use: confirm a client-supplied list of registrant emails into "
        "batch N by setting event_registration_status='confirmed-N'.\n"
        "\n"
        "Args:\n"
        "  site: REQUIRED site name (e.g. 'lcatt').\n"
        "  key: meta key; must be in WRITABLE_META_KEYS.\n"
        "  value: single value applied to the whole batch.\n"
        "  emails | user_ids: provide EXACTLY ONE list. Empty list is a no-op "
        "(zeros summary, no HTTP calls).\n"
        "  dry_run: default True. Full resolve + old-value read, no writes.\n"
        "  allow_partial: default False. If False, ANY unresolved identifier "
        "aborts the batch before writing.\n"
        "  max_batch: default 250. A larger batch is REFUSED (not truncated).\n"
        "\n"
        "Returns {site, key, value, dry_run, summary{resolved, unresolved, "
        "already_set, would_change, applied, write_unconfirmed, failed}, rows:"
        "[{email, user_id, old_value, status, ...}]}. Each write is readback-"
        "verified; silent-discard writes surface as write_unconfirmed."
    ),
)
async def bulk_update_user_meta(
    site: str,
    key: str,
    value: str,
    emails: EmailList = None,
    user_ids: UserIdList = None,
    dry_run: bool = True,
    allow_partial: bool = False,
    max_batch: int = 250,
) -> dict:
    # Defence in depth: the Annotated BeforeValidator handles JSON-string args
    # coming through pydantic, but coerce again here so the tool is also correct
    # when called directly (tests, CLI, any path that skips validation).
    emails = _coerce_list(emails)
    user_ids = _coerce_list(user_ids)

    if key not in WRITABLE_META_KEYS:
        return {"error": f"key '{key}' is not writable. Allowlist: {sorted(WRITABLE_META_KEYS)}.",
                "key": key, "dry_run": dry_run}
    try:
        s = _resolve_site(site)
    except ValueError as e:
        return {"error": str(e), "dry_run": dry_run}

    have_emails = bool(emails)
    have_ids = bool(user_ids)
    if have_emails and have_ids:
        return {"error": "provide EXACTLY ONE of emails or user_ids, not both.",
                "site": s.name, "dry_run": dry_run}
    if not have_emails and not have_ids:
        # No-op on empty (explicit empty list or nothing) — zeros, no HTTP calls.
        return {"site": s.name, "key": key, "value": value, "dry_run": dry_run,
                "summary": _summarize([]), "rows": []}

    identifiers: list = list(emails) if have_emails else list(user_ids)  # type: ignore[arg-type]
    if len(identifiers) > max_batch:
        return {"error": f"batch of {len(identifiers)} exceeds max_batch={max_batch}. "
                         f"Refusing (never silently truncated).",
                "site": s.name, "dry_run": dry_run}

    sem = asyncio.Semaphore(_WRITE_CONCURRENCY)

    async def resolve_one(idx: int, ident) -> tuple[int, dict, int | None]:
        async with sem:
            if have_emails:
                uid, status, meta = await _resolve_email(s, ident)
                row: dict = {"email": ident, "user_id": uid}
            else:
                uid = int(ident)
                status = "ok"
                meta = {}
                try:
                    r = await _get_site(s, f"/users/{uid}", {"context": "edit"})
                    r.raise_for_status()
                    meta = r.json().get("meta") or {}
                except Exception as e:  # noqa: BLE001
                    status = f"error:{type(e).__name__}"
                row = {"email": None, "user_id": uid}
            if status != "ok":
                row.update({"old_value": None, "status": "unresolved", "detail": status})
                return idx, row, None
            old = meta.get(key)
            row["old_value"] = old
            row["status"] = "already_set" if old == value else "would_change"
            return idx, row, uid

    resolved = await asyncio.gather(*(resolve_one(i, ident) for i, ident in enumerate(identifiers)))
    resolved.sort(key=lambda t: t[0])
    rows = [r for _, r, _ in resolved]
    uid_by_idx = {i: uid for i, _, uid in resolved}

    unresolved = [r for r in rows if r["status"] == "unresolved"]
    if unresolved and not allow_partial:
        return {
            "site": s.name, "key": key, "value": value, "dry_run": dry_run, "aborted": True,
            "reason": (f"{len(unresolved)} of {len(rows)} identifiers did not resolve to exactly "
                       f"one user; batch aborted. Pass allow_partial=True to write the resolved rows."),
            "summary": _summarize(rows), "rows": rows,
        }

    if dry_run:
        return {"site": s.name, "key": key, "value": value, "dry_run": True,
                "summary": _summarize(rows), "rows": rows}

    async def write_one(i: int, row: dict) -> None:
        if row["status"] != "would_change":
            return
        uid = uid_by_idx[i]
        async with sem:
            try:
                pr = await _post_site(s, f"/users/{uid}", {"meta": {key: value}}, params={"context": "edit"})
                pr.raise_for_status()
                readback = (pr.json().get("meta") or {}).get(key)
            except Exception as e:  # noqa: BLE001
                row["status"] = "failed"
                row["detail"] = _err("bulk_update_user_meta.write", e)
                _audit(s, "bulk_update_user_meta", uid, key, row["old_value"], value, "failed")
                return
            if readback == value:
                row["status"] = "applied"
                row["new_value"] = value
                _audit(s, "bulk_update_user_meta", uid, key, row["old_value"], value, "applied")
            else:
                row["status"] = "write_unconfirmed"
                row["readback"] = readback
                _audit(s, "bulk_update_user_meta", uid, key, row["old_value"], value, "write_unconfirmed")

    await asyncio.gather(*(write_one(i, rows[i]) for i in range(len(rows))))

    return {"site": s.name, "key": key, "value": value, "dry_run": False,
            "summary": _summarize(rows), "rows": rows}
# <<< user-meta-write-tools (managed by claude) <<<


# >>> acp-transplant (managed by claude) >>>
# =============================================================================
# ACP column-set transplant — wraps POST /jumbo-qa/v1/acp/transplant (v2.6.0+)
# =============================================================================
#
# Admin Columns Pro's import ALWAYS creates a new named column set and never
# overwrites the untitled default set users actually see. So an exported column
# config cannot be applied to an existing site without hand-clicking every
# column or an SSH/WP-CLI database edit. This moves the payload ACP itself
# produced onto the default set.
#
# NOTE ON `site`: the ticket specified this should target the ambient active
# site (switch_site context). It takes an EXPLICIT site instead, matching the
# hard rule the other write tools in this server follow — a write must name its
# target so it can never land on the wrong client's site. Wrong-site here means
# silently replacing a live client's default column set; recoverable from the
# backup, but only if someone notices. Reads stay ambient; writes are named.

_ACP_TRANSPLANT_MIN_VERSION = (2, 6, 0)


def _parse_plugin_version(v: Any) -> tuple[int, ...] | None:
    """'2.6.0' -> (2, 6, 0). Returns None for anything unparseable."""
    if not isinstance(v, str):
        return None
    parts = v.strip().split(".")
    try:
        return tuple(int(p) for p in parts[:3])
    except ValueError:
        return None


@mcp.tool(
    description=(
        "Transplant an Admin Columns Pro column config from an imported column "
        "set onto an existing (default) set on ONE explicit Jumbo site. "
        "Dry-run by default — pass dry_run=False to actually write.\n"
        "\n"
        "WHY: ACP's import always creates a NEW named column set and never "
        "overwrites the untitled default set users actually see when they open "
        "a list table. So export/import cannot update an existing site's "
        "default columns. Workflow: (1) import the JSON in wp-admin so ACP "
        "builds and validates a real config, (2) call this to move that config "
        "onto the default set, (3) delete the leftover imported set in the UI.\n"
        "\n"
        "SAFE BECAUSE it never authors a column config — it copies a payload "
        "ACP itself produced, so key hashes, type strings and serialization are "
        "ACP's own work. Only the `columns` field moves; title, settings, "
        "list_key and list_id on the target are untouched, so the target keeps "
        "its identity and stays the default set.\n"
        "\n"
        "THIS IS A REPLACE, NOT A MERGE. Columns the target has and the source "
        "lacks are LOST. Always read `losses` in the dry run before writing.\n"
        "\n"
        "Args:\n"
        "  site: REQUIRED site name from list_sites. Never inferred from the "
        "active site — a write must name its target.\n"
        "  from_list_id: source set (the one ACP created on import).\n"
        "  to_list_id: target set (the default, usually untitled one). Use "
        "get_acp_list_screens / the acp/list-screens endpoint to find both.\n"
        "  dry_run: default True. Returns the full plan — gains, losses, "
        "warnings — without writing.\n"
        "\n"
        "READ `warnings` BEFORE WRITING. It flags absolute URLs pointing at "
        "another host, the cross-site import leftover that renders fine and "
        "links off-site, which a human reviewing the list table will not "
        "notice. It also restates any column losses.\n"
        "\n"
        "The target's previous columns are backed up to an option row before "
        "any write, and the column count is verified by re-reading the row "
        "afterward; the backup key is returned either way.\n"
        "\n"
        "Requires jumbo-qa-rest.php v2.6.0+ on the target site. Version is "
        "checked first so an older site reports a clear message instead of a "
        "bare 404."
    ),
)
async def acp_transplant_columns(
    site: str,
    from_list_id: str,
    to_list_id: str,
    dry_run: bool = True,
) -> dict:
    try:
        s = _resolve_site(site)
    except ValueError as e:
        return {"ok": False, "error": str(e), "dry_run": dry_run}

    if not from_list_id or not to_list_id:
        return {
            "ok": False,
            "error": "from_list_id and to_list_id are both required.",
            "site": s.name, "dry_run": dry_run,
        }
    if from_list_id == to_list_id:
        return {
            "ok": False,
            "error": "from_list_id and to_list_id are the same — nothing to do.",
            "site": s.name, "dry_run": dry_run,
        }

    # Version gate. Sites are on mixed versions during rollout, and a bare 404
    # from a 2.5.x site looks like a broken route rather than an old plugin.
    try:
        wr = await _get_site(s, "/wp-json/jumbo-qa/v1/whoami")
        wr.raise_for_status()
        installed_raw = (wr.json() or {}).get("version")
    except Exception as e:
        return {
            "ok": False,
            "site": s.name,
            "error": _err("acp_transplant_columns (version check)", e),
            "hint": "Could not reach /jumbo-qa/v1/whoami — is jumbo-qa-rest.php deployed on this site?",
            "dry_run": dry_run,
        }

    installed = _parse_plugin_version(installed_raw)
    if installed is None or installed < _ACP_TRANSPLANT_MIN_VERSION:
        want = ".".join(str(n) for n in _ACP_TRANSPLANT_MIN_VERSION)
        return {
            "ok": False,
            "site": s.name,
            "error": "plugin_too_old",
            "installed_version": installed_raw,
            "required_version": want,
            "message": (
                f"{s.name} is running jumbo-qa-rest.php {installed_raw!r}; the "
                f"transplant endpoint needs {want}+. Deploy the updated "
                f"mu-plugin to this site first."
            ),
            "dry_run": dry_run,
        }

    try:
        r = await _post_site(
            s,
            "/wp-json/jumbo-qa/v1/acp/transplant",
            {
                "from_list_id": from_list_id,
                "to_list_id": to_list_id,
                "dry_run": bool(dry_run),
            },
        )
    except Exception as e:
        return {
            "ok": False, "site": s.name, "dry_run": dry_run,
            "error": _err("acp_transplant_columns", e),
        }

    try:
        data = r.json()
    except Exception:
        return {
            "ok": False, "site": s.name, "dry_run": dry_run,
            "error": f"HTTP {r.status_code}, non-JSON body: {r.text[:300]}",
        }

    if not isinstance(data, dict):
        return {"ok": False, "site": s.name, "dry_run": dry_run, "raw": data}

    data["site"] = s.name
    data["plugin_version"] = installed_raw

    # Hoist warnings so they cannot be missed in a long result. The off-site
    # URL case is precisely the one a human scanning this would skip past.
    warnings = data.get("warnings") or []
    if warnings:
        data["ATTENTION"] = warnings

    _audit(
        s,
        "acp_transplant_columns",
        f"{from_list_id}->{to_list_id}",
        "acp.columns",
        data.get("to", {}).get("column_count_before"),
        data.get("to", {}).get("column_count_after"),
        ("dry_run" if dry_run else ("applied" if data.get("ok") else "failed")),
    )
    return data
# <<< acp-transplant (managed by claude) <<<


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
