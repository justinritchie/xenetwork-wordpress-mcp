#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "fastmcp>=3.2,<4",
#   "httpx>=0.27.0,<1.0",
# ]
# ///
"""
WordPress (xenetwork.org/energytransitionshow subsite) MCP — content only,
read-only.

Sibling to wordpress-xenetwork-mcp (which targets the network root for users
and subscriptions). This MCP targets the ETS SUBSITE where the actual
published content lives — episodes, show notes, pages, categories, tags.

Six tools, all read-only:
  - get_post(id)       — single post (full body)
  - list_posts(...)    — paginated post search
  - get_page(id)       — single page (full body)
  - list_pages(...)    — paginated page search
  - list_categories    — taxonomy
  - list_tags          — taxonomy

Reads three env vars (set by start-wordpress-mcp.sh):
  WP_BASE_URL     — e.g. https://xenetwork.org/energytransitionshow
  WP_USERNAME     — WordPress login username (slug, not email)
  WP_APP_PASSWORD — Application Password (network-wide, same as users MCP)

Run with:
  uv run --script server.py
"""

from __future__ import annotations

import base64
import os
import re
import sys
from contextlib import asynccontextmanager
from typing import Any

import datetime as _dt
import httpx
from fastmcp import FastMCP


WP_BASE = os.environ.get("WP_BASE_URL", "").rstrip("/")
WP_USER = os.environ.get("WP_USERNAME", "")
WP_PASS = os.environ.get("WP_APP_PASSWORD", "")
PORT = int(os.environ.get("WP_MCP_PORT", "8007"))
SERVER_NAME = os.environ.get("WP_MCP_SERVER_NAME", "wordpress-energytransitionshow")

if not WP_BASE:
    sys.exit("ERROR: WP_BASE_URL is not set")
if not WP_USER:
    sys.exit("ERROR: WP_USERNAME is not set")
if not WP_PASS:
    sys.exit("ERROR: WP_APP_PASSWORD is not set")


_basic_token = base64.b64encode(f"{WP_USER}:{WP_PASS}".encode()).decode()
client = httpx.AsyncClient(
    base_url=f"{WP_BASE}/wp-json/wp/v2",
    timeout=httpx.Timeout(30.0, connect=10.0),
    follow_redirects=True,
    headers={
        "Authorization": f"Basic {_basic_token}",
        "Accept": "application/json",
        "User-Agent": "wordpress-energytransitionshow-mcp/1.0",
    },
)


@asynccontextmanager
async def lifespan(app):
    """Pre-fetch /episodes (limit 1) at boot to warm the connection pool
    and confirm the subsite REST API is reachable. We use /episodes (the
    custom post type) instead of /posts because /posts on this subsite
    returns []; all the actual content lives in xen_episodes."""
    try:
        r = await client.get("/episodes", params={"per_page": 1, "status": "publish"})
        elapsed_ms = r.elapsed.total_seconds() * 1000
        if r.status_code == 200:
            count = r.headers.get("X-WP-Total", "?")
            print(
                f"[wp-mcp] warmup: GET /episodes?per_page=1 -> 200 "
                f"({elapsed_ms:.0f}ms, total_episodes={count})",
                flush=True,
            )
        else:
            print(
                f"[wp-mcp] warmup: GET /episodes -> {r.status_code} "
                f"(check WP_BASE_URL — should be https://xenetwork.org/ets)",
                flush=True,
            )
    except Exception as e:
        print(f"[wp-mcp] warmup failed (non-fatal): {e}", flush=True)
    yield
    try:
        await client.aclose()
    except Exception:
        pass


mcp = FastMCP(name=SERVER_NAME, lifespan=lifespan)


# ---------------------------------------------------------------------------
# Cross-site disambiguation: prepend a "[WP site: <label>]" tag to every
# tool's description so MCP clients (and the LLMs driving them) can pick the
# right wordpress-* connector by semantic search. Otherwise tools like
# get_form / list_users / get_episode look identical across wordpress-jumbo,
# wordpress-xenetwork, and wordpress-energytransitionshow.
#
# WP_SITE_LABEL overrides the auto-derivation. Default derives from SERVER_NAME.
# ---------------------------------------------------------------------------
_SITE_LABELS = {
    "wordpress-xenetwork": "xenetwork.org (network root)",
    "wordpress-jumbo": "Jumbo client sites",
    "wordpress-energytransitionshow": "energytransitionshow.com (ETS subsite)",
    "wordpress-ets": "energytransitionshow.com (ETS subsite)",
}
SITE_LABEL = os.environ.get("WP_SITE_LABEL", "") or _SITE_LABELS.get(
    SERVER_NAME, SERVER_NAME
)

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


def _trim_post(p: dict) -> dict:
    """Compress a post/page payload to readable essentials. No body."""
    return {
        "id": p.get("id"),
        "type": p.get("type"),
        "status": p.get("status"),
        "date": p.get("date"),
        "modified": p.get("modified"),
        "slug": p.get("slug"),
        "link": p.get("link"),
        "title": (p.get("title") or {}).get("rendered"),
        "excerpt": (p.get("excerpt") or {}).get("rendered"),
        "author": p.get("author"),
        "categories": p.get("categories"),
        "tags": p.get("tags"),
        "parent": p.get("parent"),
    }


def _trim_post_full(p: dict) -> dict:
    """Like _trim_post but also keeps the full content body — for get_post."""
    short = _trim_post(p)
    short["content"] = (p.get("content") or {}).get("rendered")
    return short


def _trim_term(t: dict) -> dict:
    return {
        "id": t.get("id"),
        "name": t.get("name"),
        "slug": t.get("slug"),
        "count": t.get("count"),
        "description": t.get("description") or None,
        "link": t.get("link"),
        "taxonomy": t.get("taxonomy"),
    }


# ---------------------------------------------------------------------------
# Tools — content only, all read-only
# ---------------------------------------------------------------------------

@mcp.tool(
    description=(
        "Get a single Energy Transition Show episode by its WordPress post "
        "ID. Returns trimmed metadata plus the full rendered HTML content "
        "body (show notes). Use list_episodes to find episode IDs first."
    ),
)
async def get_episode(id: int) -> dict | str:
    """Get a single ETS episode by its WordPress post ID.

    Returns the full content body (HTML), title, date, slug, link, and
    taxonomy associations. Use list_episodes to find episode IDs first.
    """
    try:
        r = await client.get(f"/episodes/{id}", params={"context": "view"})
        r.raise_for_status()
    except Exception as e:
        return _err("get_episode", e)
    return _trim_post_full(r.json())


@mcp.tool(
    description=(
        "List or search Energy Transition Show episodes. Read-only.\n"
        "\n"
        "ETS uses a custom post type (`xen_episodes`) for episodes, so "
        "this tool hits /wp-json/wp/v2/episodes — NOT the standard /posts "
        "endpoint (which is empty on this site). There are 284+ episodes "
        "in the catalog.\n"
        "\n"
        "Args:\n"
        "  search: substring search across episode title/content (e.g. an "
        "episode number, guest name, or topic — try 'electricity review', "
        "'episode 274', or 'solar batteries').\n"
        "  status: filter (default 'publish'; can be 'draft', 'pending', "
        "'private', 'any').\n"
        "  after: ISO date — only episodes published after this date.\n"
        "  before: ISO date — only episodes published before this date.\n"
        "  page: 1-indexed page number (default 1).\n"
        "  per_page: results per page, max 100 (default 10).\n"
        "\n"
        "Returns trimmed episode metadata without the full body — use "
        "get_episode for a specific episode's show notes — plus pagination."
    ),
)
async def list_episodes(
    search: str | None = None,
    status: str = "publish",
    after: str | None = None,
    before: str | None = None,
    page: int = 1,
    per_page: int = 10,
) -> dict | str:
    params: dict[str, Any] = {
        "context": "view",
        "status": status,
        "page": page,
        "per_page": min(per_page, 100),
    }
    if search:
        params["search"] = search
    if after:
        params["after"] = after
    if before:
        params["before"] = before
    try:
        r = await client.get("/episodes", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_episodes", e)
    return {
        "episodes": [_trim_post(p) for p in r.json()],
        "total": int(r.headers.get("X-WP-Total", 0)),
        "total_pages": int(r.headers.get("X-WP-TotalPages", 0)),
        "page": page,
    }


@mcp.tool(
    description=(
        "Get a single Energy Transition Show page by ID. Returns trimmed "
        "metadata plus the full rendered HTML content body."
    ),
)
async def get_page(id: int) -> dict | str:
    try:
        r = await client.get(f"/pages/{id}", params={"context": "view"})
        r.raise_for_status()
    except Exception as e:
        return _err("get_page", e)
    return _trim_post_full(r.json())


@mcp.tool(
    description=(
        "List or search Energy Transition Show pages. Read-only.\n"
        "\n"
        "Args:\n"
        "  search: substring search across page title/content.\n"
        "  parent: filter to children of a specific parent page ID (0 = "
        "top-level only).\n"
        "  status: filter (default 'publish').\n"
        "  page: 1-indexed page number (default 1).\n"
        "  per_page: results per page, max 100 (default 10).\n"
        "\n"
        "Returns trimmed page metadata and pagination info."
    ),
)
async def list_pages(
    search: str | None = None,
    parent: int | None = None,
    status: str = "publish",
    page: int = 1,
    per_page: int = 10,
) -> dict | str:
    params: dict[str, Any] = {
        "context": "view",
        "status": status,
        "page": page,
        "per_page": min(per_page, 100),
    }
    if search:
        params["search"] = search
    if parent is not None:
        params["parent"] = parent
    try:
        r = await client.get("/pages", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_pages", e)
    return {
        "pages": [_trim_post(p) for p in r.json()],
        "total": int(r.headers.get("X-WP-Total", 0)),
        "total_pages": int(r.headers.get("X-WP-TotalPages", 0)),
        "page": page,
    }


@mcp.tool(
    description=(
        "List Energy Transition Show categories. Read-only.\n"
        "\n"
        "Args:\n"
        "  search: substring search by category name.\n"
        "  post: filter to categories assigned to a specific post ID.\n"
        "  per_page: max 100 (default 25).\n"
        "\n"
        "Returns id, name, slug, count (post count), description for each."
    ),
)
async def list_categories(
    search: str | None = None,
    post: int | None = None,
    per_page: int = 25,
) -> list[dict] | str:
    params: dict[str, Any] = {"per_page": min(per_page, 100)}
    if search:
        params["search"] = search
    if post is not None:
        params["post"] = post
    try:
        r = await client.get("/categories", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_categories", e)
    return [_trim_term(t) for t in r.json()]


@mcp.tool(
    description=(
        "List Energy Transition Show tags. Read-only.\n"
        "\n"
        "Args:\n"
        "  search: substring search by tag name.\n"
        "  post: filter to tags assigned to a specific post ID.\n"
        "  per_page: max 100 (default 25).\n"
        "\n"
        "Returns id, name, slug, count (post count), description for each."
    ),
)
async def list_tags(
    search: str | None = None,
    post: int | None = None,
    per_page: int = 25,
) -> list[dict] | str:
    params: dict[str, Any] = {"per_page": min(per_page, 100)}
    if search:
        params["search"] = search
    if post is not None:
        params["post"] = post
    try:
        r = await client.get("/tags", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_tags", e)
    return [_trim_term(t) for t in r.json()]


# ---------------------------------------------------------------------------
# URL Shortify (network-wide URL shortener plugin)
# ---------------------------------------------------------------------------
#
# URL Shortify exposes /wp-json/url-shortify/v1/links — its own table of
# slug→destination redirects. The plugin is network-active so we can hit
# it via either xenetwork.org/wp-json or xenetwork.org/ets/wp-json. Both
# paths see the same data. We use the ETS subsite path for consistency.
#
# Short URL format: https://xenetwork.org/<slug> redirects to the
# destination (verified — xenetwork.org/233 → episode 233's full URL).
#
# These tools cover the support workflow: "what's the short link for
# episode X" → find_short_link_for_url(<episode URL>) → returns short_url.
# Plus full CRUD for managing short links from Claude.

SHORTIFY_BASE = f"{WP_BASE}/wp-json/url-shortify/v1"
SHORT_URL_HOST = "https://xenetwork.org"


def _short_url_for_slug(slug: str | None) -> str | None:
    """URL Shortify's default redirect URL is <site>/<slug>."""
    if not slug:
        return None
    return f"{SHORT_URL_HOST}/{slug}"


def _trim_short_link(link: dict) -> dict:
    """Strip URL Shortify response down to readable essentials. Drops the
    massive `rules` serialized PHP blob and a handful of always-null fields
    that just inflate token count for no value."""
    slug = link.get("slug")
    return {
        "id": int(link["id"]) if link.get("id") not in (None, "") else None,
        "slug": slug,
        "short_url": _short_url_for_slug(slug),
        "destination_url": link.get("url"),
        "name": link.get("name"),
        "description": link.get("description") or None,
        "status": "active" if str(link.get("status")) == "1" else "inactive",
        "redirect_type": link.get("redirect_type"),
        "type": link.get("type"),
        "total_clicks": link.get("total_clicks"),
        "unique_clicks": link.get("unique_clicks"),
        "expires_at": link.get("expires_at"),
        "created_at": link.get("created_at"),
        "updated_at": link.get("updated_at"),
    }


@mcp.tool(
    description=(
        "List URL Shortify short links. URL Shortify is the network-wide "
        "shortener plugin — its data lives in its own table, NOT as "
        "postmeta on episodes/pages. Returns trimmed records (drops the "
        "huge `rules` serialized PHP blob).\n"
        "\n"
        "Args:\n"
        "  page: 1-indexed page (default 1).\n"
        "  per_page: max 100 (default 25). Plugin returns {success, data} "
        "envelope; we unwrap.\n"
        "\n"
        "Each link record includes a computed `short_url` "
        "('https://xenetwork.org/<slug>') for direct usability."
    ),
)
async def list_short_links(
    page: int = 1,
    per_page: int = 25,
) -> dict | str:
    params: dict[str, Any] = {
        "page": page,
        "per_page": min(per_page, 100),
    }
    try:
        r = await client.get(f"{SHORTIFY_BASE}/links", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_short_links", e)
    body = r.json()
    # URL Shortify wraps its response in {success: bool, data: [...]}.
    items = body.get("data", []) if isinstance(body, dict) else body
    return {
        "links": [_trim_short_link(x) for x in items],
        "page": page,
    }


@mcp.tool(
    description=(
        "Get a single short link by its URL Shortify ID. Returns trimmed "
        "record including the computed `short_url` (the full "
        "https://xenetwork.org/<slug> URL)."
    ),
)
async def get_short_link(id: int) -> dict | str:
    try:
        r = await client.get(f"{SHORTIFY_BASE}/links/{id}")
        r.raise_for_status()
    except Exception as e:
        return _err("get_short_link", e)
    body = r.json()
    link = body.get("data", body) if isinstance(body, dict) else body
    if isinstance(link, list) and link:
        link = link[0]
    return _trim_short_link(link)


@mcp.tool(
    description=(
        "Find a URL Shortify short link by destination URL. The primary "
        "support workflow: 'what's the short link for episode 22' →\n"
        "  1. list_episodes(search='episode 22') to find the episode URL\n"
        "  2. find_short_link_for_url(<that URL>) returns the short link\n"
        "\n"
        "Args:\n"
        "  destination_url_substring: any substring of the destination "
        "URL. Match is case-sensitive substring on the `url` field. "
        "Examples: 'episode-233', 'episode-22-', "
        "'/become-a-member-ets/institutions/sabuqcf'.\n"
        "  fetch_limit: maximum number of links to scan (default 500). "
        "If you have more total short links than this, raise this limit "
        "or paginate via list_short_links.\n"
        "\n"
        "Returns the matching short link record (or null if no match), "
        "or a list of matches if there are multiple. Each includes the "
        "computed short_url ready to share."
    ),
)
async def find_short_link_for_url(
    destination_url_substring: str,
    fetch_limit: int = 500,
) -> dict | str:
    if not destination_url_substring:
        return "ERROR: destination_url_substring is required"

    try:
        r = await client.get(
            f"{SHORTIFY_BASE}/links",
            params={"page": 1, "per_page": min(fetch_limit, 500)},
        )
        r.raise_for_status()
    except Exception as e:
        return _err("find_short_link_for_url", e)
    body = r.json()
    items = body.get("data", []) if isinstance(body, dict) else body

    matches = [
        _trim_short_link(x)
        for x in items
        if destination_url_substring in (x.get("url") or "")
    ]
    if not matches:
        return {
            "matches": [],
            "note": (
                f"No short link found whose destination URL contains "
                f"{destination_url_substring!r}. Total links scanned: "
                f"{len(items)}. To create one, call create_short_link."
            ),
        }
    if len(matches) == 1:
        return {"matches": matches, "match": matches[0]}
    return {
        "matches": matches,
        "note": f"{len(matches)} short links match — pick one or refine the search substring.",
    }


@mcp.tool(
    description=(
        "Create a new URL Shortify short link. Only `url` is required — "
        "the plugin auto-generates a slug if you don't pass one.\n"
        "\n"
        "Args:\n"
        "  url: destination URL (REQUIRED). Where the short link "
        "redirects. e.g. 'https://xenetwork.org/ets/episodes/episode-22-...'\n"
        "  slug: optional desired slug (the part after xenetwork.org/). "
        "If omitted, URL Shortify auto-generates a random one. For "
        "episode-numbered slugs like '22' or '274' pass them explicitly.\n"
        "  name: optional human-readable label. By convention used for "
        "episodes: '[Episode #N] - <Episode Title>'.\n"
        "  description: optional description.\n"
        "  redirect_type: '301', '302', or '307'. Default '307' "
        "(temporary, preserves request method — the URL Shortify default).\n"
        "  nofollow: bool, default true (rel=nofollow on outbound links).\n"
        "  track_me: bool, default true (count clicks).\n"
        "\n"
        "Returns the new short link with computed short_url."
    ),
    annotations={"destructiveHint": False, "idempotentHint": False},
)
async def create_short_link(
    url: str,
    slug: str | None = None,
    name: str | None = None,
    description: str | None = None,
    redirect_type: str = "307",
    nofollow: bool = True,
    track_me: bool = True,
) -> dict | str:
    payload: dict[str, Any] = {
        "url": url,
        "redirect_type": redirect_type,
        "nofollow": "1" if nofollow else "0",
        "track_me": "1" if track_me else "0",
        "status": "1",
    }
    if slug:
        payload["slug"] = slug
    if name:
        payload["name"] = name
    if description:
        payload["description"] = description
    try:
        r = await client.post(f"{SHORTIFY_BASE}/links", json=payload)
        r.raise_for_status()
    except Exception as e:
        return _err("create_short_link", e)
    body = r.json()
    link = body.get("data", body) if isinstance(body, dict) else body
    if isinstance(link, list) and link:
        link = link[0]
    if not isinstance(link, dict):
        return f"ERROR: unexpected create response: {body!r}"
    return _trim_short_link(link)


@mcp.tool(
    description=(
        "Update an existing URL Shortify short link by ID. Only "
        "specified fields are changed. Useful for renaming labels, "
        "swapping destinations, or toggling status.\n"
        "\n"
        "Args:\n"
        "  id: the short link's ID.\n"
        "  url: optional new destination URL.\n"
        "  slug: optional new slug.\n"
        "  name: optional new name/label.\n"
        "  description: optional new description.\n"
        "  status: optional 'active' or 'inactive' (mapped to '1'/'0').\n"
        "  redirect_type: optional '301'/'302'/'307'.\n"
        "  expires_at: optional ISO datetime, or empty string to clear."
    ),
    annotations={"destructiveHint": True, "idempotentHint": True},
)
async def update_short_link(
    id: int,
    url: str | None = None,
    slug: str | None = None,
    name: str | None = None,
    description: str | None = None,
    status: str | None = None,
    redirect_type: str | None = None,
    expires_at: str | None = None,
) -> dict | str:
    payload: dict[str, Any] = {}
    if url is not None:
        payload["url"] = url
    if slug is not None:
        payload["slug"] = slug
    if name is not None:
        payload["name"] = name
    if description is not None:
        payload["description"] = description
    if status is not None:
        if status not in ("active", "inactive"):
            return f"ERROR: status must be 'active' or 'inactive', got {status!r}"
        payload["status"] = "1" if status == "active" else "0"
    if redirect_type is not None:
        payload["redirect_type"] = redirect_type
    if expires_at is not None:
        payload["expires_at"] = expires_at

    if not payload:
        return "ERROR: at least one field required to update"

    try:
        r = await client.post(f"{SHORTIFY_BASE}/links/{id}", json=payload)
        r.raise_for_status()
    except Exception as e:
        return _err("update_short_link", e)
    body = r.json()
    link = body.get("data", body) if isinstance(body, dict) else body
    if isinstance(link, list) and link:
        link = link[0]
    return _trim_short_link(link) if isinstance(link, dict) else body


@mcp.tool(
    description=(
        "Delete a URL Shortify short link by ID. Permanent — once "
        "deleted, the short URL stops redirecting. Use with care."
    ),
    annotations={"destructiveHint": True, "idempotentHint": True},
)
async def delete_short_link(id: int) -> dict | str:
    try:
        r = await client.delete(f"{SHORTIFY_BASE}/links/{id}")
        r.raise_for_status()
    except Exception as e:
        return _err("delete_short_link", e)
    return {"ok": True, "deleted_id": id, "response": r.json()}


# ---------------------------------------------------------------------------
# Formidable Forms (read-only)
# ---------------------------------------------------------------------------
#
# Formidable's data REST API lives at /wp-json/frm/v2 on the ETS subsite
# only (the network root has /frm-admin/v1 which is just install
# scaffolding, not data). Routes:
#   GET /forms                        — list forms (returns dict keyed by slug)
#   GET /forms/<id>                   — single form
#   GET /forms/<id>/fields            — field schema for a form
#   GET /forms/<id>/entries           — entries for a form
#   GET /entries                      — all entries across forms
#   GET /entries/<id>                 — single entry
#
# All read-only by design — Justin explicitly said "read it not edit" so
# we expose no write tools. POST/PUT/DELETE are NOT wrapped here.

FRM_BASE = f"{WP_BASE}/wp-json/frm/v2"


def _trim_form(f: dict) -> dict:
    """Compress a Formidable form record to readable essentials."""
    return {
        "id": f.get("id"),
        "name": f.get("name"),
        "slug": f.get("form_key") or f.get("slug"),
        "description": f.get("description") or None,
        "status": f.get("status"),
        "is_template": f.get("is_template"),
        "default_template": f.get("default_template"),
        "created_at": f.get("created_at"),
        "parent_form_id": f.get("parent_form_id"),
    }


def _trim_form_entry(e: dict) -> dict:
    """Compress a Formidable entry. Preserves the submitted field data
    (the meta/metas dict — what the user actually submitted) since that's
    the whole point of reading entries."""
    return {
        "id": e.get("id"),
        "form_id": e.get("form_id"),
        "item_key": e.get("item_key"),
        "name": e.get("name"),
        "user_id": e.get("user_id"),
        "ip": e.get("ip"),
        "created_at": e.get("created_at"),
        "updated_at": e.get("updated_at"),
        # Submitted field values: Formidable returns these as `metas` (dict
        # keyed by field_id) and/or `meta` (legacy). Keep both if present.
        "metas": e.get("metas"),
    }


def _trim_form_field(field: dict) -> dict:
    """Compress a Formidable form field definition."""
    return {
        "id": field.get("id"),
        "field_key": field.get("field_key"),
        "name": field.get("name"),
        "description": field.get("description") or None,
        "type": field.get("type"),
        "default_value": field.get("default_value") or None,
        "options": field.get("options"),
        "required": field.get("required"),
        "field_order": field.get("field_order"),
    }


@mcp.tool(
    description=(
        "List all Formidable Forms on the ETS subsite. Read-only.\n"
        "\n"
        "Returns a list of trimmed form records (id, name, slug, status, "
        "description, created_at). The Formidable native response is "
        "actually a dict keyed by slug — we flatten it to a list for "
        "easier processing.\n"
        "\n"
        "8 forms exist on the ETS subsite as of inspection: contact2, "
        "nxgbi, nxgbi2, submitanepisodeidea, studentdiscountform, "
        "submitjobpost, replytojobpost, jobboardfeedbacksurvey."
    ),
)
async def list_forms() -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/forms")
        r.raise_for_status()
    except Exception as e:
        return _err("list_forms", e)
    body = r.json()
    # Native response is a dict keyed by slug; flatten to list
    if isinstance(body, dict):
        forms = list(body.values())
    elif isinstance(body, list):
        forms = body
    else:
        return f"ERROR: unexpected list_forms response: {body!r}"
    return {"forms": [_trim_form(f) for f in forms], "total": len(forms)}


@mcp.tool(
    description=(
        "Get a single Formidable Form's definition by ID or slug. "
        "Returns the trimmed form record. Pair with list_form_fields "
        "to understand the schema of submitted entries."
    ),
)
async def get_form(form_id: str) -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/forms/{form_id}")
        r.raise_for_status()
    except Exception as e:
        return _err("get_form", e)
    return _trim_form(r.json())


@mcp.tool(
    description=(
        "Get the field schema for a Formidable Form by ID or slug. "
        "Returns the list of fields (id, key, name, type, options, "
        "required) — this is what you need to interpret entry meta "
        "values, since entry `metas` is keyed by field_id."
    ),
)
async def list_form_fields(form_id: str) -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/forms/{form_id}/fields")
        r.raise_for_status()
    except Exception as e:
        return _err("list_form_fields", e)
    body = r.json()
    if isinstance(body, dict):
        fields = list(body.values())
    elif isinstance(body, list):
        fields = body
    else:
        return f"ERROR: unexpected list_form_fields response: {body!r}"
    return {"fields": [_trim_form_field(f) for f in fields], "total": len(fields)}


@mcp.tool(
    description=(
        "List entries (submissions) for a specific Formidable Form. "
        "Read-only.\n"
        "\n"
        "Args:\n"
        "  form_id: the form's numeric ID or slug "
        "(e.g. 'studentdiscountform').\n"
        "  page: 1-indexed page number (default 1).\n"
        "  per_page: results per page, max 100 (default 25).\n"
        "  search: optional substring search across entry data.\n"
        "\n"
        "Returns trimmed entries with their submitted `metas` "
        "(field_id → submitted value). Use list_form_fields first to "
        "map field_ids to human-readable field names if you need to "
        "interpret the submission contents."
    ),
)
async def list_form_entries(
    form_id: str,
    page: int = 1,
    per_page: int = 25,
    search: str | None = None,
) -> dict | str:
    params: dict[str, Any] = {
        "page": page,
        "per_page": min(per_page, 100),
    }
    if search:
        params["search"] = search
    try:
        r = await client.get(
            f"{FRM_BASE}/forms/{form_id}/entries", params=params
        )
        r.raise_for_status()
    except Exception as e:
        return _err("list_form_entries", e)
    body = r.json()
    if isinstance(body, dict):
        entries = list(body.values())
    elif isinstance(body, list):
        entries = body
    else:
        return f"ERROR: unexpected list_form_entries response: {body!r}"
    return {
        "entries": [_trim_form_entry(e) for e in entries],
        "total_returned": len(entries),
        "page": page,
    }


@mcp.tool(
    description=(
        "Get a single Formidable Forms entry by its ID. Returns the "
        "trimmed entry record with its `metas` dict (field_id → "
        "submitted value). Use list_form_fields on the entry's form_id "
        "to interpret the field_ids."
    ),
)
async def get_form_entry(entry_id: str) -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/entries/{entry_id}")
        r.raise_for_status()
    except Exception as e:
        return _err("get_form_entry", e)
    return _trim_form_entry(r.json())


# ---------------------------------------------------------------------------
# Editorial state: revisions, autosaves, edit locks
#
# All read-only. Nothing below can modify a post: every call is a GET, and the
# edit-lock/enclosure data comes from a computed REST field that has no
# update_callback, so there is no write path to misuse.
#
# WHAT THE REST API ACTUALLY SUPPORTS HERE (verified 2026-07-28 against this
# install, not assumed):
#   * xen_episodes declares supports: revisions AND autosave, and
#     /episodes/<id>/revisions returns 200 with real revisions. The concern
#     that a custom post type would 404 on /revisions does not apply here.
#   * /episodes/<id>/autosaves returns 200. It is EMPTY unless an editor has
#     made a change in the block editor — WordPress keeps at most one autosave
#     per user per post, and only once something has actually been typed.
#   * `_edit_lock` and `_edit_last` are protected meta and are NOT returned by
#     core REST. GET /episodes/4425?context=edit returned meta keys
#     ['_acf_changed', 'footnotes'] and nothing else. Reading them requires the
#     ets-mcp-editorial.php mu-plugin (see templates/), which exposes them as a
#     computed `mcp_editorial` field. The two tools that need it say so
#     explicitly when it is missing rather than returning a confusing empty
#     result.
# ---------------------------------------------------------------------------

_USER_CACHE: dict[int, dict] = {}


async def _resolve_user(user_id: int | None) -> dict | None:
    """Resolve a WP user id to a readable identity, cached for the process.

    Revision lists repeat the same author on every entry; without a cache a
    10-revision list would issue 10 identical /users lookups.
    """
    if not user_id:
        return None
    uid = int(user_id)
    if uid in _USER_CACHE:
        return _USER_CACHE[uid]
    try:
        r = await client.get(f"/users/{uid}", params={"context": "edit"})
        r.raise_for_status()
        u = r.json()
        out = {
            "user_id": uid,
            "name": u.get("name"),
            "slug": u.get("slug"),
            "email": u.get("email"),
        }
    except Exception:
        # A missing or unreadable user must not fail the whole call — the
        # revision itself is still real and still worth returning.
        out = {"user_id": uid, "name": None, "unresolved": True}
    _USER_CACHE[uid] = out
    return out


def _excerpt(html: str | None, limit: int = 400) -> str | None:
    if not html:
        return None
    text = re.sub(r"<[^>]+>", " ", html)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:limit] + ("…" if len(text) > limit else "")


@mcp.tool(
    description=(
        "List saved revisions for an ETS episode, newest first. Read-only.\n"
        "\n"
        "Each entry gives the revision id, resolved author, date/modified "
        "timestamps, title, and a plain-text excerpt of the body. Use "
        "get_episode_revision for the full content of one revision.\n"
        "\n"
        "Revisions are SAVED history. For changes typed but not yet saved, "
        "use get_episode_autosave instead."
    ),
)
async def list_episode_revisions(id: int, limit: int = 20) -> dict | str:
    try:
        r = await client.get(
            f"/episodes/{id}/revisions",
            params={"per_page": max(1, min(limit, 100)), "context": "edit"},
        )
        r.raise_for_status()
    except Exception as e:
        return _err("list_episode_revisions", e)

    items = r.json()
    if not isinstance(items, list):
        return {"ok": False, "error": "unexpected response shape", "raw": items}

    out = []
    for rev in items:
        out.append({
            "revision_id": rev.get("id"),
            "parent": rev.get("parent"),
            "author": await _resolve_user(rev.get("author")),
            "date_gmt": rev.get("date_gmt"),
            "modified_gmt": rev.get("modified_gmt"),
            "title": (rev.get("title") or {}).get("rendered"),
            "excerpt": _excerpt((rev.get("content") or {}).get("rendered")),
        })
    out.sort(key=lambda x: x.get("modified_gmt") or "", reverse=True)
    return {"ok": True, "episode_id": id, "count": len(out), "revisions": out}


@mcp.tool(
    description=(
        "Get one saved revision of an ETS episode in full — raw and rendered "
        "content, title, excerpt, resolved author, and timestamps. Read-only. "
        "Get revision ids from list_episode_revisions."
    ),
)
async def get_episode_revision(id: int, revision_id: int) -> dict | str:
    try:
        r = await client.get(
            f"/episodes/{id}/revisions/{revision_id}",
            params={"context": "edit"},
        )
        r.raise_for_status()
    except Exception as e:
        return _err("get_episode_revision", e)

    rev = r.json()
    content = rev.get("content") or {}
    title = rev.get("title") or {}
    return {
        "ok": True,
        "episode_id": id,
        "revision_id": rev.get("id"),
        "author": await _resolve_user(rev.get("author")),
        "date_gmt": rev.get("date_gmt"),
        "modified_gmt": rev.get("modified_gmt"),
        "title_raw": title.get("raw"),
        "title_rendered": title.get("rendered"),
        "content_raw": content.get("raw"),
        "content_rendered": content.get("rendered"),
        "excerpt": (rev.get("excerpt") or {}).get("rendered"),
    }


@mcp.tool(
    description=(
        "Get the PENDING AUTOSAVE for an ETS episode — content typed into the "
        "block editor but not yet saved or published. Read-only.\n"
        "\n"
        "This is the earliest available signal that someone is drafting a "
        "change, well before the post's `modified` timestamp moves.\n"
        "\n"
        "An empty result is a normal, meaningful answer: WordPress stores at "
        "most one autosave per user per post and creates it only once the "
        "editor has actually changed something. An open editor with no edits "
        "produces nothing. Empty means 'nothing pending', NOT an error."
    ),
)
async def get_episode_autosave(id: int) -> dict | str:
    try:
        r = await client.get(
            f"/episodes/{id}/autosaves", params={"context": "edit"}
        )
        r.raise_for_status()
    except Exception as e:
        return _err("get_episode_autosave", e)

    items = r.json()
    lst = items if isinstance(items, list) else [items]
    if not lst:
        return {
            "ok": True,
            "episode_id": id,
            "autosave_present": False,
            "note": "No pending autosave. Either nobody is editing, or the "
                    "editor is open but nothing has been changed yet.",
        }

    out = []
    for a in lst:
        content = a.get("content") or {}
        title = a.get("title") or {}
        out.append({
            "autosave_id": a.get("id"),
            "author": await _resolve_user(a.get("author")),
            "modified_gmt": a.get("modified_gmt"),
            "date_gmt": a.get("date_gmt"),
            "title_raw": title.get("raw") or title.get("rendered"),
            "content_raw": content.get("raw") or content.get("rendered"),
            "excerpt": _excerpt(content.get("rendered") or content.get("raw")),
        })
    return {
        "ok": True,
        "episode_id": id,
        "autosave_present": True,
        "count": len(out),
        "autosaves": out,
    }


_MU_PLUGIN_HINT = (
    "`mcp_editorial` is not present on the episode payload. That field comes "
    "from the ets-mcp-editorial.php mu-plugin, which has not been deployed to "
    "this site yet. `_edit_lock`/`_edit_last` and the PowerPress enclosure keys "
    "are protected meta and are NOT readable through core REST without it — "
    "this is a deployment gap, not a permissions or code failure. The plugin "
    "file is in the MCP repo under sites/ets/templates/; drop it into "
    "wp-content/mu-plugins/ on the ETS subsite."
)


async def _editorial_field(id: int) -> tuple[dict | None, str | None]:
    """Fetch the computed mcp_editorial field, or explain why it isn't there."""
    try:
        r = await client.get(f"/episodes/{id}", params={"context": "edit"})
        r.raise_for_status()
    except Exception as e:
        return None, _err("get_episode(edit context)", e)
    d = r.json()
    if "mcp_editorial" not in d:
        return None, _MU_PLUGIN_HINT
    return d.get("mcp_editorial"), None


@mcp.tool(
    description=(
        "Show who currently has an ETS episode open in the block editor, and "
        "who last saved it. Read-only. This is the programmatic form of the "
        "'X is currently editing this post' notice in wp-admin.\n"
        "\n"
        "Returns the lock timestamp as ISO 8601 plus its age in seconds. "
        "WordPress treats a lock older than ~150 seconds as stale, so age "
        "matters more than presence — a lock can outlive the editing session."
    ),
)
async def get_episode_lock(id: int) -> dict | str:
    field, problem = await _editorial_field(id)
    if problem:
        return {"ok": False, "episode_id": id, "reason": problem}
    if not field:
        return {
            "ok": False,
            "episode_id": id,
            "reason": "mcp_editorial returned null — the Application Password "
                      "user lacks edit_post on this episode, so the field is "
                      "withheld by design.",
        }
    lock = field.get("edit_lock")
    return {
        "ok": True,
        "episode_id": id,
        "locked": bool(lock),
        "lock": lock,
        "last_editor": field.get("edit_last"),
        "server_time": field.get("server_time"),
        "note": None if lock else "No edit lock set — nobody has the post open, "
                                  "or the lock has already been cleared.",
    }


@mcp.tool(
    description=(
        "Read the podcast enclosure meta for an ETS episode (PowerPress) so "
        "the audio URL comes from post meta rather than being scraped out of "
        "rendered HTML. Read-only. Returns whichever enclosure keys are "
        "actually populated on this install, with the key name each value "
        "came from."
    ),
)
async def get_episode_media_meta(id: int) -> dict | str:
    field, problem = await _editorial_field(id)
    if problem:
        return {"ok": False, "episode_id": id, "reason": problem}
    if not field:
        return {
            "ok": False,
            "episode_id": id,
            "reason": "mcp_editorial returned null — insufficient capability "
                      "on this episode.",
        }
    enc = field.get("enclosure")
    return {
        "ok": True,
        "episode_id": id,
        "enclosure_present": bool(enc),
        "enclosure": enc,
        "note": None if enc else "No enclosure/powerpress meta found on this "
                                 "episode.",
    }


# ---------------------------------------------------------------------------
# Content fingerprint — the cheap tripwire
#
# Deep revision tooling per post type is the wrong shape for "did anything
# change". This is one call that counts every content type and reports the
# newest modification in each, plus a single fingerprint string to diff against
# the previous run.
#
# WHY THIS BEATS ENUMERATING TYPES WE CARE ABOUT
#   It walks whatever /types returns, so a post type added next month is covered
#   without anyone remembering to add it — the same reasoning that made the WSAL
#   filtering exclusion-based. The measured content on this site is episodes
#   (290), job board (225), pages (66) and testimonials (27); `post`,
#   `xen_newsletters`, `xen_videos`, `xen_extras` and `mailpoet_email` are all
#   empty. Hardcoding today's list would go stale silently.
#
# COST
#   One request per type — about nine — each returning a single item, with the
#   total read from the X-WP-Total header rather than by fetching rows. Cheap
#   enough to run hourly.
# ---------------------------------------------------------------------------

# Infrastructure types that churn for reasons unrelated to editorial activity
# (template parts re-save on theme updates, attachments on any upload) would
# produce constant false positives.
_FINGERPRINT_SKIP = {
    "attachment", "nav_menu_item", "wp_block", "wp_template",
    "wp_template_part", "wp_global_styles", "wp_navigation",
    "wp_font_family", "wp_font_face",
}

_ALL_STATUSES = "publish,draft,pending,private,future,auto-draft,inherit"


@mcp.tool(
    description=(
        "Cheap change-detection tripwire across ALL content types. Read-only.\n"
        "\n"
        "Returns, per post type: total item count, the newest modified "
        "timestamp, and the id/title of the most recently touched item — plus "
        "a single `fingerprint` string for the whole site.\n"
        "\n"
        "Intended use: store `fingerprint` and compare next run. If it is "
        "unchanged, nothing anywhere was added, edited or deleted and you can "
        "stop. If it changed, the per-type rows show you where to look. This "
        "covers post types nobody thought to monitor, including ones added "
        "later, which a hardcoded list would miss.\n"
        "\n"
        "Counts include drafts and pending items by default, because an "
        "unpublished draft is exactly the early signal worth catching. Set "
        "published_only=True to count only live content."
    ),
)
async def get_content_fingerprint(
    published_only: bool = False,
    include_empty: bool = False,
) -> dict | str:
    import hashlib

    try:
        r = await client.get("/types", params={"context": "edit"})
        r.raise_for_status()
        types = r.json()
    except Exception as e:
        return _err("get_content_fingerprint(/types)", e)

    rows: list[dict] = []
    errors: list[str] = []

    for tname, meta in types.items():
        if tname in _FINGERPRINT_SKIP:
            continue
        rest_base = (meta or {}).get("rest_base")
        if not rest_base:
            continue

        params: dict[str, Any] = {
            "per_page": 1,
            "context": "edit",
            "orderby": "modified",
            "order": "desc",
        }
        if not published_only:
            params["status"] = _ALL_STATUSES

        try:
            rr = await client.get(f"/{rest_base}", params=params)
            if rr.status_code >= 400:
                errors.append(f"{tname}: HTTP {rr.status_code}")
                continue
        except Exception as e:  # noqa: BLE001
            errors.append(f"{tname}: {type(e).__name__}")
            continue

        # Total comes from the header — no need to pull the rows themselves.
        total = int(rr.headers.get("X-WP-Total", "0") or 0)
        items = rr.json()
        newest = items[0] if isinstance(items, list) and items else None

        if total == 0 and not include_empty:
            continue

        rows.append({
            "type": tname,
            "rest_base": rest_base,
            "count": total,
            "newest_modified_gmt": (newest or {}).get("modified_gmt"),
            "newest_id": (newest or {}).get("id"),
            "newest_status": (newest or {}).get("status"),
            "newest_title": ((newest or {}).get("title") or {}).get("raw")
                            or ((newest or {}).get("title") or {}).get("rendered"),
        })

    rows.sort(key=lambda x: x["type"])

    # Fingerprint deliberately combines count AND newest-modified per type: a
    # count alone misses an edit to an existing item, and a timestamp alone
    # misses a deletion (which lowers the count while the newest item is
    # untouched). Together they catch add, edit and delete.
    basis = "|".join(
        f"{r['type']}:{r['count']}:{r['newest_modified_gmt']}" for r in rows
    )
    fingerprint = hashlib.sha256(basis.encode()).hexdigest()[:16]

    latest = max(
        (r["newest_modified_gmt"] for r in rows if r["newest_modified_gmt"]),
        default=None,
    )

    return {
        "ok": True,
        "fingerprint": fingerprint,
        "site_newest_modified_gmt": latest,
        "types_checked": len(rows),
        "total_items": sum(r["count"] for r in rows),
        "published_only": published_only,
        "types": rows,
        "errors": errors or None,
        "usage": "Store `fingerprint`. Unchanged next run means nothing was "
                 "added, edited or deleted anywhere. Changed means compare the "
                 "per-type rows to find which type moved, then use "
                 "list_episode_revisions / get_episode_autosave on that item.",
    }


# ---------------------------------------------------------------------------
# WP Activity Log (WSAL) — read-only
#
# WSAL free has no notification rules (Premium only), so the log has to be
# polled rather than pushed. It is the only record of site actions that never
# move a post's `modified` timestamp, which makes it invisible to every other
# monitor.
#
# These call custom routes added by the ets-mcp-editorial.php mu-plugin, not
# core REST — WSAL keeps occurrences in its own tables, so there is no core
# endpoint to use. Every query behind them is a prepared SELECT; there is no
# write path.
# ---------------------------------------------------------------------------

ETS_MCP_ROUTE = f"{WP_BASE}/wp-json/ets-mcp/v1"

_WSAL_MISSING_HINT = (
    "The ets-mcp/v1 routes are not registered on this site (404). They come "
    "from the ets-mcp-editorial.php mu-plugin, which has not been deployed "
    "yet — drop it into wp-content/mu-plugins/ on the ETS subsite. This is a "
    "deployment gap, not a permissions or code failure."
)


def _int_list(v: Any) -> list[int] | None:
    """Accept a real list, a JSON-encoded list string, or a comma-separated string.

    MCP clients routinely serialise array arguments as a JSON *string*. Without
    this, passing exclude_event_ids=[] to deliberately INCLUDE failed logins
    fails in a way that looks like the filter is stuck on.
    """
    if v is None:
        return None
    if isinstance(v, list):
        return [int(x) for x in v]
    if isinstance(v, str):
        s = v.strip()
        if not s:
            return []
        try:
            import json as _json
            parsed = _json.loads(s)
            if isinstance(parsed, list):
                return [int(x) for x in parsed]
            return [int(parsed)]
        except Exception:
            return [int(x.strip()) for x in s.split(",") if x.strip()]
    return [int(v)]


# Network-scope events do not live on the ETS subsite. 4008/4009 (super admin
# granted/revoked), 4010 (user added to a site), 6060 (an event enabled or
# disabled) and 7001-7005 (subsite lifecycle) are written against blog 1, so a
# subsite-scoped query returns count:0 — which reads exactly like "it never
# happened". That is not hypothetical: the 6060 events from the 2026-08-07 WSAL
# configuration change were visible in Network Admin and invisible here.
_NETWORK_SCOPE_EVENTS = (4008, 4009, 4010, 6060, 7001, 7002, 7003, 7004, 7005)


def _scope_param(site_id):
    """Normalise site_id for the PHP route. None means 'leave it alone'."""
    if site_id is None or site_id == "":
        return None
    if isinstance(site_id, str) and site_id.strip().lower() == "all":
        return "all"
    return int(site_id)


def _is_network_wide(site_id) -> bool:
    v = _scope_param(site_id)
    return v == "all" or v == 0


# A network-scoped query with a username filter over a wide window drops the
# site_id predicate — the one selective index the query had — and scans a
# 4.85M-row table. MEASURED 2026-08-08: username=chris + site_id=all +
# since=2024-08-06 completes in 31.7s. The client default is 30s, so it failed
# by a two-second margin and surfaced as a bare ReadTimeout.
#
# That is worth stating plainly, because the obvious reading of the symptom is
# "needs an index on a 4.85M-row shared production table" — a planned schema
# change on a live multisite. It does not. The query works; the caller hung up.
_ETS_NETWORK_TIMEOUT = httpx.Timeout(90.0, connect=10.0)


# ---------------------------------------------------------------------------
# WINDOWING — why wide activity-log queries are split rather than waited on
#
# MEASURED 2026-08-08, username=chris, network scope:
#     7 days   ->   2.8s,   305 events
#     2 years  ->  30-40s,  HTTP 502 from the WP Engine gateway
#
# The table is ~4.85M rows and `username` is not selectively indexed, so a
# username filter scans in proportion to the window. Two years of scanning to
# return 4,889 events crosses a server-side limit this connector cannot raise.
#
# Three fixes were considered:
#   raise the client timeout  - treats a server limit as a client one; the 502
#                               proved it does not work
#   add a database index      - a schema change on a shared production table
#                               holding evidence in a live matter
#   split the window          - each slice is cheap and always returns
#
# The third needs no schema change and no elevated privilege, so that is what
# this does. A wide request becomes N bounded requests, merged.
_ETS_MAX_WINDOW_DAYS = 45


def _iso_day(ts: float) -> str:
    return _dt.datetime.utcfromtimestamp(ts).strftime("%Y-%m-%d")


def _parse_since(since) -> float | None:
    """Best-effort ISO date / datetime / unix -> epoch seconds."""
    if since in (None, ""):
        return None
    if isinstance(since, (int, float)):
        return float(since)
    txt = str(since).strip()
    if txt.replace(".", "", 1).isdigit():
        return float(txt)
    for fmt in ("%Y-%m-%d", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d %H:%M:%S"):
        try:
            return _dt.datetime.strptime(txt, fmt).replace(
                tzinfo=_dt.timezone.utc).timestamp()
        except ValueError:
            continue
    return None


def _windows(since_ts: float, until_ts: float, days: int = _ETS_MAX_WINDOW_DAYS):
    """Yield (since_iso, until_iso) slices, oldest first."""
    step = days * 86400
    cur = since_ts
    while cur < until_ts:
        end = min(cur + step, until_ts)
        yield _iso_day(cur), _iso_day(end)
        cur = end


async def _ets_route(path: str, params: dict) -> dict | str:
    # The longer budget applies to EVERY activity-log call, not just network
    # scope. First attempt scoped it to network only, and the acceptance run
    # immediately showed why that was wrong: username=chris + since=2024-08-06
    # at SUBSITE scope also exceeds 30s. The cost is the username filter over a
    # wide window against a multi-million-row table; site_id is not what makes
    # it slow. A generous timeout costs nothing when a query is fast.
    network = str(params.get("site_id", "")).lower() in ("all", "0")

    try:
        r = await client.get(f"{ETS_MCP_ROUTE}{path}", params=params,
                             timeout=_ETS_NETWORK_TIMEOUT)
        if r.status_code == 404:
            return {"ok": False, "reason": _WSAL_MISSING_HINT}
        r.raise_for_status()
    except httpx.ReadTimeout:
        # Never surface a bare ReadTimeout. An agent that sees one has no way to
        # know the query is recoverable by narrowing.
        return {
            "ok": False,
            "error": "query_timeout",
            "timed_out_after_seconds": 90,
            "scope": "network" if network else "subsite",
            "reason": (
                "The query did not return in time. Network scope drops the site_id "
                "predicate and scans ~4.85M rows; combining it with a username "
                "filter over a wide window is the slowest shape this connector "
                "can produce."
            ),
            "hint": (
                "This is recoverable — narrow it rather than concluding there is no "
                "data. Try a shorter `since`, add `event_ids`, or drop `username` "
                "(the unfiltered network summary is fast). A measured reference: "
                "username + site_id=all + since=2024-08-06 took 31.7s."
            ),
        }
    except Exception as e:
        return _err(f"ets-mcp{path}", e)
    return r.json()


@mcp.tool(
    description=(
        "Read WP Activity Log (WSAL) events for the ETS site, newest first. "
        "Read-only.\n"
        "\n"
        "This is the only record of actions that never move a post's "
        "`modified` timestamp — logins, role changes, plugin and settings "
        "changes, deletions.\n"
        "\n"
        "Filtering is EXCLUSION-based on purpose. exclude_event_ids defaults "
        "to [1002, 1003] (failed logins), which are high-volume noise from an "
        "active credential-stuffing botnet. Pass exclude_event_ids=[] to see "
        "them deliberately. There is intentionally no allowlist of "
        "'interesting' events, because the event that matters is usually the "
        "one nobody thought to anticipate.\n"
        "\n"
        "since/until accept ISO dates ('2026-07-27'), datetimes, or unix "
        "timestamps.\n"
        "\n"
        "SCOPE — the subsite view is NOT complete. Defaults to the ETS subsite "
        "(blog 2). Network-scope events are written against blog 1 and are "
        "INVISIBLE by default: 4008/4009 super admin granted or revoked, 4010 "
        "user added to a site, 6060 an event enabled or disabled, 7001-7005 "
        "subsite lifecycle. A subsite query returns count:0 for those, which "
        "reads exactly like they never happened — that assumption is what "
        "produced this parameter. Pass site_id='all' for network-wide, or an "
        "integer for a specific blog. Every response reports what it used in "
        "`query_scope`; read it before concluding anything from an empty result.\n"
        "\n"
        "WATCH THE TIME BOUND when hunting a specific event. `since` is "
        "inclusive of the date but resolves to a moment — an event late on the "
        "same UTC day can fall outside `since=<that date>`. If you expect rows "
        "and get none, widen the window before concluding the event is absent."
    ),
)
async def get_activity_log(
    username: str | None = None,
    since: str | None = None,
    until: str | None = None,
    exclude_event_ids: Any = None,
    event_ids: Any = None,
    limit: int = 100,
    all_users: bool = False,
    site_id: int | str | None = None,
) -> dict | str:
    # SCOPE DISCIPLINE. This log holds ~2,000,000 events across ~2 years, and a
    # typical week is ~87,000 of which roughly 69% are botnet failed logins and
    # most of the remainder are `System` rows. An unscoped pull is therefore
    # both useless (signal buried) and disproportionate (it sweeps up unrelated
    # activity by everyone). Scoping to one account is the normal case, so it
    # is required rather than merely encouraged; a site-wide sweep stays
    # possible but has to be asked for deliberately via all_users=True.
    if not username and not all_users:
        return {
            "ok": False,
            "reason": "username is required. This log holds ~2M events and a "
                      "typical week is ~87k, mostly botnet failed logins and "
                      "System rows — an unscoped pull buries the signal and "
                      "sweeps in unrelated activity. Pass username='...' to "
                      "scope it, or all_users=True if you genuinely need a "
                      "site-wide sweep.",
            "hint": "Use get_activity_log_summary(all_users=True) first if you "
                    "want volume by event code before narrowing.",
        }

    excl = _int_list(exclude_event_ids)
    if excl is None:                    # not supplied at all -> default
        excl = [1002, 1003]
    only = _int_list(event_ids)

    # A network-wide sweep is BROADER than the case the rule above was written
    # for — it adds every other blog on the multisite to an already-2M-row table.
    # all_users + site_id=all + no time bound is the widest query this connector
    # can express, so it has to be asked for precisely rather than stumbled into.
    # Narrowing by event_ids is enough; this is about accidental scope, not
    # forbidding the query.
    if _is_network_wide(site_id) and all_users and not since and not only:
        return {
            "ok": False,
            "reason": "site_id='all' with all_users=True and no `since` would sweep the "
                      "ENTIRE network log — roughly 2,000,000 rows across every blog, "
                      "about 69% of it botnet failed logins.",
            "hint": "Add `since`, or narrow with `event_ids`. For the network-scope "
                    f"events specifically, event_ids={list(_NETWORK_SCOPE_EVENTS)} is "
                    "usually what you want.",
            "query_scope": {"requested": "network", "refused": True},
        }

    params: dict[str, Any] = {"limit": max(1, min(int(limit), 500))}
    scope = _scope_param(site_id)
    if scope is not None:
        params["site_id"] = scope
    if username:
        params["username"] = username
    if since:
        params["since"] = since
    if until:
        params["until"] = until
    if excl:
        params["exclude_event_ids"] = ",".join(str(x) for x in excl)
    if only:
        params["event_ids"] = ",".join(str(x) for x in only)

    out = await _ets_route("/activity-log", params)
    if isinstance(out, dict) and out.get("ok") and not out.get("events"):
        out["note"] = (
            "No matching events. Note the default excludes failed logins "
            "(1002/1003) — pass exclude_event_ids=[] if you expected those."
        )
    return out


@mcp.tool(
    description=(
        "Count WSAL events grouped by event code over a window, with the "
        "human label for each. Read-only.\n"
        "\n"
        "Use this before get_activity_log when the log is noisy: a "
        "credential-stuffing botnet becomes a single row with a count of "
        "several hundred instead of several hundred rows, and an unusual "
        "burst of any other event type is immediately visible. Includes ALL "
        "event codes — nothing is excluded here, because the whole point is "
        "seeing relative volume."
        "\n"
        "SCOPE — the subsite view is NOT complete. Defaults to the ETS "
        "subsite (blog 2). Network-scope events are written against blog 1 "
        "and are INVISIBLE by default: 4008/4009 super admin granted or "
        "revoked, 4010 user added to a site, 6060 an event enabled or "
        "disabled, 7001-7005 subsite lifecycle. A subsite query returns "
        "count:0 for those, which reads exactly like they never happened. "
        "Pass site_id='all' to see them, or an integer for another blog. "
        "Every response reports the scope it used in `query_scope` — read "
        "it before drawing a conclusion from an empty result.\n"
    ),
)
async def get_activity_log_summary(
    since: str | None = None,
    username: str | None = None,
    all_users: bool = False,
    site_id: int | str | None = None,
) -> dict | str:
    # Summary is the one place a site-wide view is genuinely useful — it is
    # counts, not content, so it reveals volume without sweeping up anyone's
    # activity in detail. It still has to be asked for, so the scoped case
    # stays the default and nobody reaches for a broad pull out of habit.
    if not username and not all_users:
        return {
            "ok": False,
            "reason": "username is required, or pass all_users=True. A "
                      "site-wide summary is counts only (no event detail), so "
                      "it is a reasonable first look when you want to see "
                      "volume by event code — but it should be a deliberate "
                      "choice, not the default.",
        }
    params: dict[str, Any] = {}
    scope = _scope_param(site_id)
    if scope is not None:
        params["site_id"] = scope
    if since:
        params["since"] = since
    if username:
        params["username"] = username
    # A username filter over a wide window is the shape that 502s. Split it into
    # bounded slices and merge, rather than issuing one query that dies at the
    # gateway. Each slice is a few seconds; the caller gets the same answer.
    since_ts = _parse_since(since)
    now = _dt.datetime.now(_dt.timezone.utc).timestamp()
    span_days = ((now - since_ts) / 86400) if since_ts else 0

    if username and since_ts and span_days > _ETS_MAX_WINDOW_DAYS:
        merged: dict[int, dict] = {}
        slices = 0
        for w_since, w_until in _windows(since_ts, now):
            wp = dict(params, since=w_since, until=w_until)
            part = await _ets_route("/activity-log/summary", wp)
            if not isinstance(part, dict) or part.get("ok") is False:
                return {
                    "ok": False,
                    "error": "window_slice_failed",
                    "failed_window": {"since": w_since, "until": w_until},
                    "completed_slices": slices,
                    "detail": part,
                    "hint": "One slice of a windowed query failed. Retry with a "
                            "narrower `since`, or query the failing window alone.",
                }
            slices += 1
            for g in part.get("groups") or []:
                code = g.get("alert_id") or g.get("event_id")
                if code is None:
                    continue
                row = merged.setdefault(code, dict(g))
                if row is not g:
                    row["count"] = (row.get("count") or 0) + (g.get("count") or 0)

        groups = sorted(merged.values(), key=lambda g: -(g.get("count") or 0))
        return {
            "ok": True,
            "groups": groups,
            "total_events": sum(g.get("count") or 0 for g in groups),
            "query_scope": {"scope": "network" if _is_network_wide(site_id) else "subsite",
                            "site_id_param": _scope_param(site_id)},
            "windowed": {
                "reason": "A username filter over a wide window exceeds the server's "
                          "request limit — measured: 7 days 2.8s, 2 years HTTP 502. "
                          "Split rather than waited on.",
                "slices": slices,
                "days_per_slice": _ETS_MAX_WINDOW_DAYS,
                "span_days": round(span_days, 1),
            },
        }

    return await _ets_route("/activity-log/summary", params)


@mcp.tool(
    description=(
        "Report WSAL's pruning configuration and how much history actually "
        "exists — retention period, max-event cap, oldest and newest event, "
        "and total count. Read-only.\n"
        "\n"
        "Check this FIRST when the log matters as evidence. WSAL free prunes "
        "on a rolling window by default, and anything already pruned is "
        "unrecoverable — no tooling built on top can bring it back."
        "\n"
        "SCOPE — the subsite view is NOT complete. Defaults to the ETS "
        "subsite (blog 2). Network-scope events are written against blog 1 "
        "and are INVISIBLE by default: 4008/4009 super admin granted or "
        "revoked, 4010 user added to a site, 6060 an event enabled or "
        "disabled, 7001-7005 subsite lifecycle. A subsite query returns "
        "count:0 for those, which reads exactly like they never happened. "
        "Pass site_id='all' to see them, or an integer for another blog. "
        "Every response reports the scope it used in `query_scope` — read "
        "it before drawing a conclusion from an empty result.\n"
    ),
)
async def get_activity_log_retention(site_id: int | str | None = None) -> dict | str:
    params: dict[str, Any] = {}
    scope = _scope_param(site_id)
    if scope is not None:
        params["site_id"] = scope
    return await _ets_route("/activity-log/retention", params)



# --- ACF options pages -------------------------------------------------------
# This connector is already scoped to one blog (WP_BASE), so these tools need no
# `site` parameter — the URL selects the blog. The identical block lives in the
# root and ets servers; each talks to its own blog's options table. Root is
# blog_id 1, /ets is blog_id 2, and both expose a `site-general-settings` page
# with the SAME slug and DIFFERENT values. Do not assume a value read on one
# applies to the other.


@mcp.tool(
    description=(
        "Read an ACF options page on this blog — site-wide settings that live in "
        "ACF rather than in a plugin's own tables. Read-only.\n"
        "\n"
        "Call with no `page` to list the options pages registered on this blog. "
        "Do that first: slugs and field names are per-blog and are NOT guessable "
        "from the admin labels.\n"
        "\n"
        "Field NAMES come from ACF definitions, not from the admin label and not "
        "from options_* row names. On the ETS blog the Announcement Bar's "
        "'Content' field is `bar_content`, and 'Visibility Mode' is "
        "`bar_visibility_mode`. Always read before writing.\n"
        "\n"
        "Fields that can silently change what the public site renders are marked "
        "`guarded: true` — writing them needs an explicit opt-in. On ETS that is "
        "bar_visibility_mode (setting it to draft/admin-only removes the banner "
        "from the live site) and show_full_episode_feeds_to_non-members (which "
        "has revenue consequences).\n"
        "\n"
        "Link fields return an object {url, title, target}, not a string. Choice "
        "fields list their accepted `choices` — send the VALUE, not the label. "
        "Image fields resolve to value_url plus value_meta width/height.\n"
        "\n"
        "AUDIT NOTE: this reads INTENT, not what a visitor receives. Page cache "
        "can serve old markup long after ACF is correct — measured on this exact "
        "network, where bar_content held the corrected copy while the rendered "
        "banner still showed the previous wording. To confirm reality, fetch the "
        "page with a cache-busting query string and compare.\n"
        "\n"
        "Args:\n"
        "  page: options page slug, with or without the 'acf-options-' prefix.\n"
        "  field: single field name."
    ),
)
async def get_acf_options(page: str | None = None, field: str | None = None) -> dict:
    params: dict = {}
    if page:
        params["page"] = page
    if field:
        params["field"] = field
    try:
        r = await client.get(f"{WP_BASE}/wp-json/xen/v1/acf-options", params=params or None)
        r.raise_for_status()
        return r.json()
    except Exception as e:
        return {"ok": False, "error": _err("get_acf_options", e),
                "hint": "Is xen-s2member-rest.php 1.1.0+ deployed, and is ACF active on this blog?"}


@mcp.tool(
    description=(
        "Write fields on an ACF options page on this blog. Merge semantics — only "
        "the named fields change, everything else is untouched.\n"
        "\n"
        "CALL get_acf_options FIRST. An unrecognised field name is REFUSED rather "
        "than written: writing it blind would create an orphan options_* row with "
        "no field-key partner, invisible in wp-admin but present in the database.\n"
        "\n"
        "Writes go through ACF by field KEY, which maintains the `_options_<name>` "
        "shadow row binding value to definition. Writing the value row alone "
        "leaves wp-admin rendering an EMPTY FIELD over a populated database — and "
        "that state reads back fine over this API, so the response cannot tell "
        "you. The returned `integrity` block re-reads every field and confirms "
        "the shadow row exists; check it rather than trusting `ok`.\n"
        "\n"
        "GUARDED FIELDS are refused unless named in allow_guarded. These change "
        "public rendering with no other symptom — bar_visibility_mode removes the "
        "announcement banner from the live site, and "
        "show_full_episode_feeds_to_non-members affects paid content access. Read "
        "the current value before overriding the guard.\n"
        "\n"
        "KNOWN TRAP on the ETS blog: two distinct fields are both named "
        "`bar_text_color` (bar text and button text, different keys, identical "
        "labels). A write by that name is ambiguous and ACF will resolve only "
        "one. Fix the duplicate in ACF rather than working around it here.\n"
        "\n"
        "dry_run genuinely does not write — it returns before/after and stops.\n"
        "\n"
        "CACHE: an ACF write does not flush page cache. The rendered page can "
        "keep serving the old value. Verify with a cache-busting query string, "
        "and purge if the change needs to be live now.\n"
        "\n"
        "Args:\n"
        "  page: options page slug.\n"
        "  fields: {field_name: value}. Only these change.\n"
        "  dry_run: return the diff and write nothing.\n"
        "  allow_guarded: field names you explicitly accept writing despite the guard."
    ),
)
async def set_acf_options(
    page: str,
    fields: dict,
    dry_run: bool = False,
    allow_guarded: list | None = None,
) -> dict:
    body: dict = {"page": page, "fields": fields, "dry_run": bool(dry_run)}
    if allow_guarded:
        body["allow_guarded"] = allow_guarded
    try:
        r = await client.post(f"{WP_BASE}/wp-json/xen/v1/acf-options", json=body)
        data = r.json()
    except Exception as e:
        return {"ok": False, "error": _err("set_acf_options", e)}

    # Surface integrity problems at the top level. A caller skimming for `ok`
    # should not have to dig into a nested block to learn the write did not land.
    if isinstance(data, dict):
        ig = data.get("integrity") or {}
        if ig.get("mismatched_fields"):
            data["ATTENTION"] = (
                "Re-read does not match what was sent for: "
                + ", ".join(ig["mismatched_fields"]) + ". Do not assume this landed."
            )
        elif False in (ig.get("field_key_row_present") or {}).values():
            data["ATTENTION"] = (
                "A _options_<name> field-key row is MISSING — wp-admin may render this "
                "field EMPTY over a populated database. Check wp-admin, not just the API."
            )
    return data


# --- episode write tools -----------------------------------------------------
# Episodes are xen_episodes at /wp/v2/episodes. Their distinguishing content —
# dates, guest, geek rating, audio enclosures, paywall flag — is NOT in
# post_content and NOT in core REST meta. It lives in postmeta exposed by the
# ets-mcp-episode-fields mu-plugin. Body and taxonomy go through core REST;
# fields go through that plugin's guarded route.

# Counters and send-state that belong to the SOURCE episode. Copying these onto
# a new post inherits another episode's view count and marks its notification
# email as already sent, which would suppress the real one.
_EPISODE_DO_NOT_COPY = ("iawp_total_views", "new_episode_email_sent", "_dp_original")


async def _episode_set_fields(post_id: int, fields: dict, dry_run: bool = False) -> dict:
    try:
        r = await client.post(
            f"{WP_BASE}/wp-json/xen-ets/v1/episodes/{post_id}/fields",
            json={"fields": fields, "dry_run": bool(dry_run)},
        )
        return r.json()
    except Exception as e:
        return {"ok": False, "error": _err("set_episode_fields", e),
                "hint": "Is ets-mcp-episode-fields.php deployed on the ETS subsite?"}


@mcp.tool(
    description=(
        "Read every stored field on an episode — the data that does NOT appear in "
        "core REST. Read-only.\n"
        "\n"
        "core REST returns acf:[] and meta ['_acf_changed','footnotes'] for an "
        "episode, while the rendered page carries recording date, air date, guest, "
        "geek rating, the audio enclosures and the paywall flag. This returns what "
        "the post actually holds — no guessed schema.\n"
        "\n"
        "Known keys on a typical episode (verified on 4338 / #266):\n"
        "  air_date, recording_date   YYYYMMDD strings\n"
        "  geek_rating                '1'-'10'\n"
        "  guest_link                 guest post ID\n"
        "  episode_links, episode_news, episode_chapter_data   HTML/JSON blocks\n"
        "  gumroad_url\n"
        "  allow_full_episode_for_non_members   'Yes'/'No' — THE PAYWALL FLAG\n"
        "  enclosure, _member:enclosure, _member-monthly:enclosure   PowerPress, 3 tiers\n"
        "  _dp_original               the ORIGINAL post id, for a re-release\n"
        "\n"
        "Every ACF field also has an underscore twin (_air_date) holding its field "
        "KEY. Both are returned. Do not treat the twin as a value.\n"
        "\n"
        "Args:\n"
        "  post_id: the episode."
    ),
)
async def get_episode_fields(post_id: int) -> dict:
    try:
        r = await client.get(f"{WP_BASE}/wp-json/xen-ets/v1/episodes/{post_id}/fields")
        return r.json()
    except Exception as e:
        return {"ok": False, "error": _err("get_episode_fields", e),
                "hint": "Is ets-mcp-episode-fields.php deployed on the ETS subsite?"}


@mcp.tool(
    description=(
        "Write stored fields on an episode. Merge semantics — only the named keys "
        "change.\n"
        "\n"
        "CALL get_episode_fields FIRST on a comparable episode to see real key names "
        "and value formats. Dates are YYYYMMDD strings, not ISO.\n"
        "\n"
        "ACF PAIRS ARE HANDLED FOR YOU: each ACF field is stored as two rows — "
        "air_date holds the value, _air_date holds the field key. Writing the value "
        "alone leaves wp-admin rendering an EMPTY field over a populated database. "
        "This carries the companion key across automatically and reports it in "
        "integrity.acf_keys_auto_bound; anything it could not bind is raised as "
        "ATTENTION. Send plain names (air_date), not the underscore twins.\n"
        "\n"
        "PROTECTED ORIGINALS are refused server-side, not just here — the mu-plugin "
        "blocks both this route and core PATCH for those IDs.\n"
        "\n"
        "dry_run returns before/after and writes nothing.\n"
        "\n"
        "Args:\n"
        "  post_id: the episode.\n"
        "  fields: {meta_key: value}. Merge semantics.\n"
        "  dry_run: preview without writing."
    ),
)
async def set_episode_fields(post_id: int, fields: dict, dry_run: bool = False) -> dict:
    if not fields:
        return {"ok": False, "error": "fields is required and must be non-empty."}
    return await _episode_set_fields(post_id, dict(fields), dry_run=dry_run)


@mcp.tool(
    description=(
        "Create an episode. Defaults to DRAFT — publishing is always an explicit "
        "choice.\n"
        "\n"
        "Creates the post through core REST, then writes `fields` through the "
        "guarded meta route. Both halves are reported separately, so a post that "
        "was created but whose fields failed is visible rather than looking like a "
        "clean success.\n"
        "\n"
        "WITHOUT `fields` the episode has a body and NO recording date, air date, "
        "guest, geek rating or audio player — it will look broken on the front end. "
        "Read a comparable episode with get_episode_fields and pass the equivalents.\n"
        "\n"
        "Do NOT copy these across from another episode: iawp_total_views (that "
        "episode's view count), new_episode_email_sent (marks the notification as "
        "already sent, suppressing the real one), _dp_original (re-release lineage — "
        "set it deliberately or not at all). They are stripped automatically and "
        "reported in `fields_stripped`.\n"
        "\n"
        "Args:\n"
        "  title, content (HTML body), excerpt, slug\n"
        "  status: draft (default), publish, pending, future\n"
        "  date: ISO8601 for scheduling; requires status='future'\n"
        "  categories / tags: integer lists\n"
        "  fields: the stored-field dict — see get_episode_fields"
    ),
)
async def create_episode(
    title: str,
    content: str = "",
    excerpt: str = "",
    slug: str | None = None,
    status: str = "draft",
    date: str | None = None,
    categories: list | None = None,
    tags: list | None = None,
    featured_media: int | None = None,
    fields: dict | None = None,
) -> dict:
    payload: dict = {"title": title, "content": content,
                     "excerpt": excerpt, "status": status}
    if featured_media is not None:
        # Set through the post route, never via _thumbnail_id meta — two writers
        # for one value is how they drift.
        payload["featured_media"] = int(featured_media)
    if slug:
        payload["slug"] = slug
    if date:
        payload["date"] = date
    if categories:
        payload["categories"] = [int(c) for c in categories]
    if tags:
        payload["tags"] = [int(t) for t in tags]

    try:
        r = await client.post(f"{WP_BASE}/wp-json/wp/v2/episodes", json=payload)
        post = r.json()
    except Exception as e:
        return {"ok": False, "error": _err("create_episode", e)}

    if not isinstance(post, dict) or not post.get("id"):
        return {"ok": False, "error": "create failed", "response": str(post)[:400]}

    new_id = int(post["id"])
    out: dict = {
        "ok": True,
        "id": new_id,
        "status": post.get("status"),
        "slug": post.get("slug"),
        "link": post.get("link"),
        "edit_url": f"{WP_BASE}/wp-admin/post.php?post={new_id}&action=edit",
        "preview_url": f"{WP_BASE}/?p={new_id}&preview=true",
    }

    if fields:
        clean = {k: v for k, v in dict(fields).items() if k not in _EPISODE_DO_NOT_COPY}
        stripped = [k for k in dict(fields) if k in _EPISODE_DO_NOT_COPY]
        fr = await _episode_set_fields(new_id, clean)
        out["fields_result"] = fr
        out["fields_stripped"] = stripped
        if not fr.get("ok"):
            out["ok"] = False
            out["ATTENTION"] = (
                f"Post {new_id} WAS created but its fields did not write. It will render "
                "without dates, guest or player. Fix with set_episode_fields before publishing."
            )
    else:
        out["ATTENTION"] = (
            "No fields were set. This episode has a body and no recording date, air date, "
            "guest, geek rating or audio. Read a comparable episode with get_episode_fields "
            "and set the equivalents before publishing."
        )
    return out


@mcp.tool(
    description=(
        "Update an episode. Partial semantics — anything not passed is left alone.\n"
        "\n"
        "PROTECTED ORIGINALS: the mu-plugin refuses these server-side on both the "
        "core route and the fields route, so the house rule holds even for callers "
        "that are not this tool. A refusal returns protected_post with the ID list. "
        "The override is a deliberate header and is written to the site error log.\n"
        "\n"
        "Pass `fields` to update stored meta in the same call; ACF companion keys "
        "are handled automatically. Status changes are explicit — this never "
        "publishes something that was a draft unless you pass status='publish'.\n"
        "\n"
        "Args:\n"
        "  id: REQUIRED.\n"
        "  title, content, excerpt, slug, status, date, categories, tags: any subset.\n"
        "  fields: stored-field dict.\n"
        "  confirm_protected: override the protected-original guard. Logged loudly."
    ),
)
async def update_episode(
    id: int,
    title: str | None = None,
    content: str | None = None,
    excerpt: str | None = None,
    slug: str | None = None,
    status: str | None = None,
    date: str | None = None,
    categories: list | None = None,
    tags: list | None = None,
    featured_media: int | None = None,
    fields: dict | None = None,
    confirm_protected: bool = False,
) -> dict:
    payload: dict = {}
    for k, v in (("title", title), ("content", content), ("excerpt", excerpt),
                 ("slug", slug), ("status", status), ("date", date)):
        if v is not None:
            payload[k] = v
    if categories is not None:
        payload["categories"] = [int(c) for c in categories]
    if tags is not None:
        payload["tags"] = [int(t) for t in tags]
    if featured_media is not None:
        payload["featured_media"] = int(featured_media)

    out: dict = {"ok": True, "id": id}
    headers = {"X-ETS-Confirm-Protected": "yes"} if confirm_protected else None

    if payload:
        try:
            r = await client.post(f"{WP_BASE}/wp-json/wp/v2/episodes/{id}",
                                  json=payload, headers=headers)
            post = r.json()
        except Exception as e:
            return {"ok": False, "id": id, "error": _err("update_episode", e)}

        if isinstance(post, dict) and post.get("code"):
            return {"ok": False, "id": id, "error": post.get("code"),
                    "message": post.get("message"),
                    "data": post.get("data"),
                    "hint": "Protected originals are refused server-side. "
                            "Pass confirm_protected=True only if you truly mean it."}
        out.update({"status": post.get("status"), "slug": post.get("slug"),
                    "link": post.get("link"), "updated": sorted(payload.keys())})

    if fields:
        fr = await _episode_set_fields(id, dict(fields))
        out["fields_result"] = fr
        if not fr.get("ok"):
            out["ok"] = False
            out["ATTENTION"] = "Field write did not verify — see fields_result.integrity."

    if not payload and not fields:
        return {"ok": False, "id": id, "error": "nothing to update — pass at least one field."}
    return out


# PowerPress stores an enclosure as four newline-delimited parts:
#   <url>\n<byte_length>\n<mime_type>\n<serialized PHP settings array>
# A bare URL produces no player. A copied enclosure with a swapped URL keeps the
# WRONG byte length, which half-works — podcast clients use it for seek and
# download progress, so it looks fine in wp-admin and misbehaves in the app.
_ENCLOSURE_KEYS = {
    "public": "enclosure",
    "member": "_member:enclosure",
    "member-monthly": "_member-monthly:enclosure",
}


@mcp.tool(
    description=(
        "Set one audio enclosure on an episode, building the PowerPress structure "
        "and VERIFYING the byte length against the real file.\n"
        "\n"
        "USE THIS rather than writing `enclosure` through set_episode_fields. "
        "PowerPress does not store a URL — it stores four newline-delimited parts: "
        "url, byte length, MIME type, and a serialized PHP settings array. Writing "
        "a bare URL yields no player at all.\n"
        "\n"
        "The failure this exists to prevent: copying another episode's enclosure "
        "and swapping the URL leaves the previous episode's byte length in place. "
        "wp-admin looks correct and podcast apps mis-seek. This HEAD-requests the "
        "file and refuses the write if the length disagrees, unless you override.\n"
        "\n"
        "The serialized settings tail is carried over from the tier's existing "
        "value, or from `copy_settings_from` — never invented, because it encodes "
        "per-episode podcast settings that cannot be reconstructed.\n"
        "\n"
        "TIERS — an ETS episode has three:\n"
        "  public          `enclosure`                  the free/teaser file\n"
        "  member          `_member:enclosure`          full episode, secure-members path\n"
        "  member-monthly  `_member-monthly:enclosure`  usually identical to member\n"
        "Set each one you need; this writes a single tier per call so a mistake "
        "cannot take out all three.\n"
        "\n"
        "Args:\n"
        "  post_id: the episode.\n"
        "  tier: public | member | member-monthly\n"
        "  url: the mp3 URL.\n"
        "  byte_length: omit to use the length reported by the server.\n"
        "  mime_type: defaults to audio/mpeg.\n"
        "  copy_settings_from: episode ID to borrow the serialized settings tail "
        "from, when this tier has none yet.\n"
        "  skip_verify: write even if the byte length disagrees with the file. "
        "Only for a file not yet uploaded.\n"
        "  dry_run: show the assembled value, write nothing."
    ),
)
async def set_episode_enclosure(
    post_id: int,
    tier: str,
    url: str,
    byte_length: int | None = None,
    mime_type: str = "audio/mpeg",
    copy_settings_from: int | None = None,
    skip_verify: bool = False,
    dry_run: bool = False,
) -> dict:
    key = _ENCLOSURE_KEYS.get(tier)
    if not key:
        return {"ok": False, "error": f"tier must be one of {sorted(_ENCLOSURE_KEYS)}"}

    # Ask the server what the file actually is. This is the whole safety story.
    actual_len: int | None = None
    head_note = None
    try:
        hr = await client.head(url, follow_redirects=True, timeout=30.0)
        if hr.status_code == 200:
            cl = hr.headers.get("content-length")
            actual_len = int(cl) if cl and cl.isdigit() else None
            if actual_len is None:
                head_note = "Server returned no Content-Length; byte length not verified."
        else:
            head_note = f"HEAD returned HTTP {hr.status_code} — file may not be uploaded yet."
    except Exception as e:
        head_note = f"Could not reach the file ({type(e).__name__}); byte length not verified."

    if byte_length is None:
        if actual_len is None:
            return {"ok": False, "error": "no_byte_length",
                    "message": "byte_length not supplied and the file could not be measured.",
                    "head_note": head_note,
                    "hint": "Upload the file first, or pass byte_length explicitly with skip_verify=True."}
        byte_length = actual_len

    if actual_len is not None and int(byte_length) != actual_len and not skip_verify:
        return {
            "ok": False, "error": "byte_length_mismatch",
            "supplied": int(byte_length), "actual": actual_len, "url": url,
            "message": "The byte length does not match the file. This is what a copied "
                       "enclosure with a swapped URL looks like — it renders fine in "
                       "wp-admin and mis-seeks in podcast apps.",
            "hint": f"Use byte_length={actual_len}, or skip_verify=True if you mean it.",
        }

    # Carry the serialized settings tail. Never invented — it encodes per-episode
    # podcast settings that cannot be reconstructed from anything visible here.
    existing = await get_episode_fields(post_id)
    tail = ""
    cur = ((existing.get("fields") or {}).get(key) or "") if isinstance(existing, dict) else ""
    parts = str(cur).split("\n")
    if len(parts) >= 4 and parts[3].strip():
        tail = parts[3]
    elif copy_settings_from:
        donor = await get_episode_fields(int(copy_settings_from))
        dparts = str(((donor.get("fields") or {}).get(key) or "")).split("\n")
        if len(dparts) >= 4:
            tail = dparts[3]

    value = f"{url}\n{int(byte_length)}\n{mime_type}\n{tail}"

    out: dict = {
        "post_id": post_id, "tier": tier, "meta_key": key,
        "url": url, "byte_length": int(byte_length), "mime_type": mime_type,
        "byte_length_verified": (actual_len is not None and int(byte_length) == actual_len),
        "settings_tail": "carried from existing" if (len(parts) >= 4 and parts[3].strip())
                         else ("carried from donor" if tail else "EMPTY"),
    }
    if head_note:
        out["head_note"] = head_note
    if not tail:
        out["ATTENTION"] = (
            "No serialized settings tail was available for this tier. PowerPress may "
            "not render the player. Pass copy_settings_from=<an episode that has this "
            "tier> to carry it across."
        )

    if dry_run:
        out.update({"ok": True, "dry_run": True, "persisted": False,
                    "would_write": value[:160] + ("…" if len(value) > 160 else "")})
        return out

    res = await _episode_set_fields(post_id, {key: value})
    out["ok"] = bool(res.get("ok"))
    out["dry_run"] = False
    out["persisted"] = True
    out["write_result"] = res.get("integrity")
    return out


@mcp.tool(
    description=(
        "Snapshot the PAYWALL FLAG and all three audio enclosures for every "
        "episode, in one call. Read-only. This is the monitoring baseline.\n"
        "\n"
        "WHY IT EXISTS: feedwatch hashes the two podcast feeds, which only sees "
        "what reaches a feed. Two things it cannot see:\n"
        "\n"
        "  allow_full_episode_for_non_members — 'Yes' publishes a PAID episode "
        "to non-members. Nothing monitored it. It is arguably a worse outcome "
        "than an audio swap and materially easier to do by accident.\n"
        "\n"
        "  the member tiers — _member:enclosure and _member-monthly:enclosure "
        "never appear in the public feed at all, so a swap there is invisible "
        "to a feed hash.\n"
        "\n"
        "Both live in postmeta, and postmeta writes do not reliably bump "
        "post_modified — so get_content_fingerprint is NOT a dependable "
        "tripwire for either. WSAL event 2054 now records such a change, but "
        "that is detection after the fact. This is the baseline you diff.\n"
        "\n"
        "Returns per episode: post_id, title, status, modified_gmt, the three "
        "enclosures as url + byte_length + mime_type, and the paywall flag. "
        "Plus a summary listing every episode currently open to non-members.\n"
        "\n"
        "The serialized PowerPress settings tail is deliberately omitted — it "
        "is bulky and irrelevant to a diff — but its presence is reported, "
        "because an enclosure without one may not render a player.\n"
        "\n"
        "Includes drafts and scheduled posts by default. A scheduled episode is "
        "exactly the interesting case: the first run of this found post 4429 "
        "(#281, scheduled) carrying Episode #279's audio on its member tier.\n"
        "\n"
        "Ordered by post_id and free of volatile fields so two runs diff "
        "cleanly. Roughly 295 rows.\n"
        "\n"
        "Args:\n"
        "  status: comma-separated post statuses. Default covers publish, "
        "draft, future, pending and private — narrow it only if you know why."
    ),
)
async def list_episode_enclosures(status: str | None = None) -> dict | str:
    # NOTE the namespace. The activity-log tools go through _ets_route against
    # ETS_MCP_ROUTE (ets-mcp/v1); this route is registered under xen-ets/v1 by
    # ets-mcp-episode-fields.php. They are different plugins and different
    # namespaces — reusing the wrong helper yields a 404 that looks like a
    # missing feature rather than a wrong prefix.
    params: dict[str, Any] = {}
    if status:
        params["status"] = status
    try:
        r = await client.get(
            f"{WP_BASE}/wp-json/xen-ets/v1/episodes/enclosures",
            params=params or None,
        )
        r.raise_for_status()
        return r.json()
    except Exception as e:
        return {
            "ok": False,
            "error": _err("list_episode_enclosures", e),
            "hint": "Is ets-mcp-episode-fields.php 1.3.0+ deployed to "
                    "wp-content/mu-plugins on the ETS subsite?",
        }


@mcp.tool(
    description=(
        "CHEAP TRIPWIRE for the enclosure + paywall state. Call this every run; "
        "call list_episode_enclosures only when it changes. Read-only, ~1 KB.\n"
        "\n"
        "Store `fingerprint` and compare next run. Unchanged means no enclosure "
        "URL, no byte length and no paywall flag moved on ANY episode — stop "
        "there, nothing needs investigating. Same idiom as "
        "get_content_fingerprint, applied to postmeta.\n"
        "\n"
        "WHY: list_episode_enclosures is ~213 KB for 295 episodes. Correct for a "
        "human investigation, wrong for a task running 15 times a day — every "
        "run would exceed the tool-output limit and spill to a temp file, "
        "burning context on the fourteen runs where nothing happened.\n"
        "\n"
        "The hash covers, per episode: post_id, all three enclosure URLs, all "
        "three byte lengths, and allow_full_episode_for_non_members. Nothing "
        "volatile, so two unchanged runs are byte-identical.\n"
        "\n"
        "`newest_modified_gmt` is reported but deliberately NOT hashed. Postmeta "
        "writes do not reliably bump post_modified — that is the whole reason "
        "this tool exists rather than relying on the content fingerprint — so "
        "including it would make the hash both noisy and untrustworthy at once. "
        "Do not treat an unchanged modified_gmt as evidence nothing happened.\n"
        "\n"
        "`open_to_non_members` is returned in full because it is small and it is "
        "the thing most worth eyeballing: any episode listed there is readable "
        "by non-members right now.\n"
        "\n"
        "Args:\n"
        "  status: same default as list_episode_enclosures (publish, draft, "
        "future, pending, private). Keep it consistent between runs or the "
        "fingerprint will change for the wrong reason."
    ),
)
async def get_enclosure_fingerprint(status: str | None = None) -> dict | str:
    params: dict[str, Any] = {}
    if status:
        params["status"] = status
    try:
        r = await client.get(
            f"{WP_BASE}/wp-json/xen-ets/v1/episodes/enclosures/fingerprint",
            params=params or None,
        )
        r.raise_for_status()
        return r.json()
    except Exception as e:
        return {
            "ok": False,
            "error": _err("get_enclosure_fingerprint", e),
            "hint": "Is ets-mcp-episode-fields.php 1.4.0+ deployed to "
                    "wp-content/mu-plugins on the ETS subsite?",
        }

if __name__ == "__main__":
    print(
        f"[wp-mcp] starting {SERVER_NAME} on http://localhost:{PORT}/mcp",
        flush=True,
    )
    print(f"[wp-mcp] base URL: {WP_BASE}/wp-json/wp/v2", flush=True)
    print(f"[wp-mcp] short URL base: {SHORTIFY_BASE}", flush=True)
    print(f"[wp-mcp] frm base: {FRM_BASE}", flush=True)
    print(f"[wp-mcp] user:     {WP_USER}", flush=True)
    mcp.run(transport="http", host="0.0.0.0", port=PORT)
