#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "fastmcp>=2.5.0",
#   "httpx>=0.27.0",
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
import sys
from contextlib import asynccontextmanager
from typing import Any

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
