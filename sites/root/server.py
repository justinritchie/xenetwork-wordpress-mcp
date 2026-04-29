#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "fastmcp>=2.5.0",
#   "httpx>=0.27.0",
# ]
# ///
"""
WordPress (xenetwork.org network root) MCP — users only, read-only.

Why this exists: docdyhr/mcp-wordpress is heavyweight (59 tools, hangs ~80s
on stdio MCP init). For Justin's support workflow we only need 4 read-only
user-lookup tools against the WordPress REST API. This wrapper boots in
<1s, exposes only those 4 tools, and uses the same launchd-managed
streamable-http pattern as the Craft MCPs so it survives Claude Desktop
restarts.

This MCP targets the NETWORK ROOT (https://xenetwork.org) where users and
subscriptions live. Posts/pages/taxonomies live on a subsite — see the
sibling wordpress-energytransitionshow-mcp folder for that.

Reads three env vars (set by start-wordpress-mcp.sh):
  WP_BASE_URL     — e.g. https://xenetwork.org (no trailing /wp-json)
  WP_USERNAME     — WordPress login username (slug, not email)
  WP_APP_PASSWORD — Application Password from WP admin (24 chars w/ spaces)

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
PORT = int(os.environ.get("WP_MCP_PORT", "8006"))
SERVER_NAME = os.environ.get("WP_MCP_SERVER_NAME", "wordpress-xenetwork")

if not WP_BASE:
    sys.exit("ERROR: WP_BASE_URL is not set")
if not WP_USER:
    sys.exit("ERROR: WP_USERNAME is not set")
if not WP_PASS:
    sys.exit("ERROR: WP_APP_PASSWORD is not set")


# WordPress REST uses HTTP Basic auth with the application password as the
# password. Build the header once and reuse for the life of the server.
_basic_token = base64.b64encode(f"{WP_USER}:{WP_PASS}".encode()).decode()
client = httpx.AsyncClient(
    base_url=f"{WP_BASE}/wp-json/wp/v2",
    timeout=httpx.Timeout(30.0, connect=10.0),
    follow_redirects=True,
    headers={
        "Authorization": f"Basic {_basic_token}",
        "Accept": "application/json",
        "User-Agent": "wordpress-xenetwork-mcp/1.0",
    },
)


@asynccontextmanager
async def lifespan(app):
    """Pre-fetch /users/me at server boot to warm the connection pool and
    confirm credentials. If auth is wrong we'll see it in the log
    immediately rather than at first user-facing call."""
    try:
        r = await client.get("/users/me")
        elapsed_ms = r.elapsed.total_seconds() * 1000
        if r.status_code == 200:
            data = r.json()
            print(
                f"[wp-mcp] warmup: GET /users/me -> 200 "
                f"({elapsed_ms:.0f}ms, user={data.get('slug')!r}, id={data.get('id')})",
                flush=True,
            )
        else:
            print(
                f"[wp-mcp] warmup: GET /users/me -> {r.status_code} "
                f"(check WP_APP_PASSWORD)",
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


def _trim_user(u: dict) -> dict:
    """Drop the heavyweight gravatar/yoast/_links payload to save tokens.

    Passes through s2_*-prefixed fields and `_all_meta_inspection` if the
    xen-s2member-rest mu-plugin is deployed on xenetwork.org. Until that
    plugin is deployed, those fields simply won't be in the upstream
    response and are silently absent here.
    """
    out = {
        "id": u.get("id"),
        "username": u.get("username"),
        "email": u.get("email"),
        "name": u.get("name"),
        "first_name": u.get("first_name"),
        "last_name": u.get("last_name"),
        "slug": u.get("slug"),
        "description": u.get("description") or None,
        "url": u.get("url") or None,
        "link": u.get("link"),
        "registered_date": u.get("registered_date"),
        "roles": u.get("roles"),
        "extra_capabilities": u.get("extra_capabilities"),
        "meta": u.get("meta") or None,
        "acf": u.get("acf") or None,
    }
    # Pass through every s2_*-prefixed field and the inspection dump if the
    # mu-plugin is live. Missing keys are absent from `u` and skipped silently.
    for k, v in u.items():
        if k.startswith("s2_") or k == "_all_meta_inspection":
            out[k] = v
    return out


# ---------------------------------------------------------------------------
# Tools
# ---------------------------------------------------------------------------

@mcp.tool(
    description=(
        "Health check — round-trip to /users/me. Returns the authenticated "
        "WordPress user's id, name, and slug. Use this to confirm the MCP "
        "is connected and the application password works."
    ),
)
async def whoami() -> dict | str:
    try:
        r = await client.get("/users/me", params={"context": "edit"})
        r.raise_for_status()
    except Exception as e:
        return _err("whoami", e)
    return _trim_user(r.json())


@mcp.tool(
    description=(
        "Find a WordPress user by email address on xenetwork.org. Primary "
        "support workflow primitive. Returns id, name, slug, and link. "
        "WP REST search matches across email/name/slug — for an exact "
        "email match the result list is typically 1 item."
    ),
)
async def find_user_by_email(email: str) -> list[dict] | str:
    try:
        r = await client.get(
            "/users",
            params={"search": email, "context": "edit", "per_page": 10},
        )
        r.raise_for_status()
    except Exception as e:
        return _err("find_user_by_email", e)
    return [_trim_user(u) for u in r.json()]


@mcp.tool(
    description=(
        "Get a full WordPress user record by numeric ID. Use after "
        "find_user_by_email when you need the full record including "
        "meta and ACF fields."
    ),
)
async def get_user(id: int) -> dict | str:
    try:
        r = await client.get(f"/users/{id}", params={"context": "edit"})
        r.raise_for_status()
    except Exception as e:
        return _err("get_user", e)
    return _trim_user(r.json())


@mcp.tool(
    description=(
        "List WordPress users on xenetwork.org, paginated.\n"
        "\n"
        "Args:\n"
        "  page: 1-indexed page number (default 1).\n"
        "  per_page: results per page, max 100 (default 25).\n"
        "  role: optional WP role filter ('subscriber', 'editor', "
        "'administrator', etc.).\n"
        "  search: optional substring search across name/email/slug.\n"
        "\n"
        "Returns trimmed user records plus X-WP-Total / X-WP-TotalPages "
        "header values for pagination."
    ),
)
async def list_users(
    page: int = 1,
    per_page: int = 25,
    role: str | None = None,
    search: str | None = None,
) -> dict | str:
    params: dict[str, Any] = {
        "context": "edit",
        "page": page,
        "per_page": min(per_page, 100),
    }
    if role:
        params["roles"] = role
    if search:
        params["search"] = search
    try:
        r = await client.get("/users", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_users", e)
    return {
        "users": [_trim_user(u) for u in r.json()],
        "total": int(r.headers.get("X-WP-Total", 0)),
        "total_pages": int(r.headers.get("X-WP-TotalPages", 0)),
        "page": page,
    }


# ---------------------------------------------------------------------------
# Institutional Registration pages (xen_institutional custom post type)
# ---------------------------------------------------------------------------
#
# IR pages live at xenetwork.org as a custom post type. Each is a landing
# page with [s2Member-Pro-Stripe-Form] shortcodes that grant a specific
# membership level + ccap (custom capability) to anyone who registers via
# that page. Slugs are short (e.g. "sabuqcf"), URLs land at:
#   https://xenetwork.org/become-a-member-ets/institutions/<slug>/
#
# These tools support a duplicate-and-modify workflow:
#  1. list_institutional / get_institutional to find a template
#  2. duplicate_institutional with content_replacements to clone+modify
#  3. update_institutional to fix typos on an existing page
#
# All write operations DEFAULT TO status='draft' so Justin can review in
# wp-admin before publishing. Status='publish' must be passed explicitly.


def _trim_institutional(p: dict, with_content: bool = False) -> dict:
    """Compress xen_institutional payload. Drops yoast/_links/avatar bloat.

    By default omits the content body to save tokens; pass with_content=True
    to include the small `content.raw` source (the real data needed for
    duplication; content.rendered can be 50KB+ of expanded HTML and is
    almost never useful via MCP)."""
    out = {
        "id": p.get("id"),
        "type": p.get("type"),
        "status": p.get("status"),
        "date": p.get("date"),
        "modified": p.get("modified"),
        "slug": p.get("slug"),
        "link": p.get("link"),
        "title": (p.get("title") or {}).get("rendered"),
        "title_raw": (p.get("title") or {}).get("raw"),
        "author": p.get("author"),
        "parent": p.get("parent"),
        "xen_institutional_type": p.get("xen_institutional_type"),
    }
    if with_content:
        c = p.get("content") or {}
        out["content_raw"] = c.get("raw")
    return out


@mcp.tool(
    description=(
        "List Institutional Registration pages (xen_institutional CPT) on "
        "xenetwork.org. There are ~234 of these — short-slug landing pages "
        "(e.g. 'sabuqcf', 'asjqcf') under /become-a-member-ets/institutions/. "
        "Each grants a specific s2Member level + ccap to people who register.\n"
        "\n"
        "Args:\n"
        "  search: substring search across title.\n"
        "  status: filter (default 'publish'; 'draft', 'any', etc).\n"
        "  page: 1-indexed page number (default 1).\n"
        "  per_page: results per page, max 100 (default 25).\n"
        "\n"
        "Returns trimmed records (no content body) plus pagination info. "
        "Use get_institutional to fetch the body of a specific page."
    ),
)
async def list_institutional(
    search: str | None = None,
    status: str = "publish",
    page: int = 1,
    per_page: int = 25,
) -> dict | str:
    params: dict[str, Any] = {
        "context": "edit",
        "status": status,
        "page": page,
        "per_page": min(per_page, 100),
    }
    if search:
        params["search"] = search
    try:
        r = await client.get("/xen_institutional", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_institutional", e)
    return {
        "institutional_pages": [_trim_institutional(p) for p in r.json()],
        "total": int(r.headers.get("X-WP-Total", 0)),
        "total_pages": int(r.headers.get("X-WP-TotalPages", 0)),
        "page": page,
    }


@mcp.tool(
    description=(
        "Get a single Institutional Registration page by ID, including "
        "ALL postmeta and taxonomy assignments. Hits the custom "
        "/xen/v1/institutional/<id> endpoint exposed by the mu-plugin — "
        "this is necessary because the default wp/v2 REST endpoint hides "
        "unregistered postmeta keys (institution name, registration "
        "limit, ToS text, email whitelist, etc.).\n"
        "\n"
        "Returns post fields + a `meta` dict with every non-private "
        "postmeta key + a `taxonomies` map. Use this before "
        "duplicate_institutional to inspect what you're cloning."
    ),
)
async def get_institutional(id: int) -> dict | str:
    # Custom endpoint lives under /wp-json/xen/v1, not /wp-json/wp/v2.
    # We need to hit the absolute URL since our httpx client's base_url
    # is set to /wp-json/wp/v2.
    try:
        r = await client.get(f"{WP_BASE}/wp-json/xen/v1/institutional/{id}")
        r.raise_for_status()
    except Exception as e:
        return _err("get_institutional", e)
    return r.json()


@mcp.tool(
    description=(
        "Duplicate an existing Institutional Registration page to a NEW "
        "DRAFT, copying ALL postmeta + taxonomies + content. This is the "
        "full-fidelity clone for the IR setup workflow — preserves "
        "institution name, registration limit, ToS text, email "
        "whitelist, auto-renewal toggle, type=active flag, and every "
        "other custom field. Hits the /xen/v1/institutional/duplicate "
        "mu-plugin endpoint for the postmeta-copy work.\n"
        "\n"
        "Args:\n"
        "  source_id: ID of the existing page to copy from. Use "
        "list_institutional/get_institutional to find a template.\n"
        "  new_title: Title for the new page (e.g. 'Institutional "
        "Registration – Foo University – QCF Global South Grant "
        "Program').\n"
        "  new_slug: URL slug (e.g. 'fooqcf'). Must be URL-safe and unique.\n"
        "  content_replacements: Optional dict of {find: replace} pairs "
        "applied to the source's body. Use to swap ccaps code, "
        "institution name, image URLs, etc. Example: "
        "{'sabuqcf': 'fooqcf', 'Sabancı University': 'Foo University'}.\n"
        "  meta_overrides: Optional dict of postmeta key→value pairs "
        "applied AFTER the bulk copy. Use to change institution-specific "
        "settings like email whitelist, registration limit, welcome "
        "page ID, etc. Run get_institutional first to see what keys "
        "exist on the source.\n"
        "  status: 'draft' (default — REVIEW BEFORE PUBLISHING) or "
        "'publish'. Defaulting to draft is the safe support-workflow "
        "behavior.\n"
        "\n"
        "Returns the new page's ID, slug, status, edit URL, preview "
        "link, and a summary of what was copied/overridden."
    ),
    annotations={
        "destructiveHint": False,
        "idempotentHint": False,
        "openWorldHint": True,
    },
)
async def duplicate_institutional(
    source_id: int,
    new_title: str,
    new_slug: str,
    content_replacements: dict[str, str] | None = None,
    meta_overrides: dict[str, Any] | None = None,
    status: str = "draft",
) -> dict | str:
    """Hits the custom /xen/v1/institutional/duplicate endpoint, which
    copies content + all postmeta + all taxonomies server-side. The MCP
    just passes overrides through — heavy lifting is done in PHP where we
    have direct DB access via update_post_meta()."""
    payload: dict[str, Any] = {
        "source_id": source_id,
        "new_title": new_title,
        "new_slug": new_slug,
        "status": status,
        "content_replacements": content_replacements or {},
        "meta_overrides": meta_overrides or {},
    }
    try:
        r = await client.post(
            f"{WP_BASE}/wp-json/xen/v1/institutional/duplicate",
            json=payload,
        )
        r.raise_for_status()
    except Exception as e:
        return _err("duplicate_institutional", e)
    return r.json()


@mcp.tool(
    description=(
        "Update an existing Institutional Registration page. Use to fix "
        "typos in a draft (or correct a published page).\n"
        "\n"
        "Args:\n"
        "  id: post ID to update.\n"
        "  title: optional new title.\n"
        "  slug: optional new URL slug.\n"
        "  content: optional new full content body (replaces existing).\n"
        "  status: optional new status ('draft', 'publish', 'pending', "
        "'private'). To explicitly publish a draft, pass status='publish'.\n"
        "\n"
        "Only specified fields are changed; unspecified fields preserved."
    ),
    annotations={
        "destructiveHint": True,
        "idempotentHint": True,
        "openWorldHint": True,
    },
)
async def update_institutional(
    id: int,
    title: str | None = None,
    slug: str | None = None,
    content: str | None = None,
    status: str | None = None,
) -> dict | str:
    payload: dict[str, Any] = {}
    if title is not None:
        payload["title"] = title
    if slug is not None:
        payload["slug"] = slug
    if content is not None:
        payload["content"] = content
    if status is not None:
        if status not in ("draft", "publish", "pending", "private", "future"):
            return f"ERROR: invalid status {status!r}"
        payload["status"] = status

    if not payload:
        return "ERROR: nothing to update — at least one of title/slug/content/status required"

    try:
        r = await client.post(f"/xen_institutional/{id}", json=payload)
        r.raise_for_status()
    except Exception as e:
        return _err("update_institutional", e)
    updated = r.json()
    return {
        "ok": True,
        "id": updated.get("id"),
        "slug": updated.get("slug"),
        "status": updated.get("status"),
        "title": (updated.get("title") or {}).get("rendered"),
        "modified": updated.get("modified"),
        "edit_url": f"https://xenetwork.org/wp-admin/post.php?post={id}&action=edit",
        "fields_updated": list(payload.keys()),
    }


# ---------------------------------------------------------------------------
# Formidable Forms (network root, read-only)
# ---------------------------------------------------------------------------
#
# The 5 high-volume forms on the xenetwork.org network root (Pre Cancellation
# 1.8K entries, Share a Free Month 900+ entries, Gift Memberships, Purchase
# Bulk Accounts, Contact Us) aren't reachable via Formidable's frm/v2 REST
# namespace — that's only enabled on the ETS subsite. So we read them via
# our own /xen/v1/frm/* endpoints, which query Formidable's tables directly
# via $wpdb in the xen-formidable-rest mu-plugin.
#
# Read-only by design. No POST/PUT/DELETE tools.

FRM_BASE = f"{WP_BASE}/wp-json/xen/v1/frm"


@mcp.tool(
    description=(
        "List all Formidable Forms on the xenetwork.org network root, "
        "with entry counts. Read-only.\n"
        "\n"
        "There are 5 forms as of inspection: Contact Us (id=6, "
        "0 entries), Gift Memberships (id=9, 79), Pre Cancellation "
        "Form (id=10, 1.8K entries — the high-volume one for cancel "
        "feedback analysis), Purchase Bulk Accounts (id=11, 81), "
        "Share a Free Month (id=13, 908).\n"
        "\n"
        "For ETS-subsite forms (contact2, nxgbi, studentdiscountform, "
        "etc.) use the wordpress-energytransitionshow MCP's list_forms "
        "tool instead."
    ),
)
async def list_forms() -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/forms")
        r.raise_for_status()
    except Exception as e:
        return _err("list_forms", e)
    return r.json()


@mcp.tool(
    description=(
        "Get a single Formidable Form definition by ID or form_key on "
        "the xenetwork.org network root. Read-only.\n"
        "\n"
        "Args:\n"
        "  id: numeric form ID (e.g. 10) or form_key (e.g. "
        "'precancellationform').\n"
        "\n"
        "Returns trimmed form record with entry count. Pair with "
        "list_form_fields to see the schema."
    ),
)
async def get_form(id: str) -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/forms/{id}")
        r.raise_for_status()
    except Exception as e:
        return _err("get_form", e)
    return r.json()


@mcp.tool(
    description=(
        "List the field schema for a Formidable Form on the network "
        "root. Read-only.\n"
        "\n"
        "Args:\n"
        "  id: numeric form ID or form_key.\n"
        "\n"
        "Returns each field's id, key, name, type, options, required, "
        "field_order. Use this to interpret entry `metas` (which are "
        "keyed by field_id)."
    ),
)
async def list_form_fields(id: str) -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/forms/{id}/fields")
        r.raise_for_status()
    except Exception as e:
        return _err("list_form_fields", e)
    return r.json()


@mcp.tool(
    description=(
        "List paginated entries (submissions) for a Formidable Form on "
        "the network root. Read-only.\n"
        "\n"
        "Args:\n"
        "  id: numeric form ID or form_key (e.g. 'precancellationform').\n"
        "  page: 1-indexed page number (default 1).\n"
        "  per_page: results per page, max 100 (default 25).\n"
        "  search: optional substring search across entry name + all "
        "submitted meta values. Useful for 'find entries mentioning <X>'.\n"
        "\n"
        "Returns trimmed entries with their `metas` dict (field_id → "
        "submitted value), plus pagination metadata (total, "
        "total_pages, page, per_page).\n"
        "\n"
        "For Pre Cancellation feedback analysis, paginate through this "
        "with the form_id 10 (or key 'precancellationform') — 1,863 "
        "entries total, so you'll need ~75 pages at per_page=25."
    ),
)
async def list_form_entries(
    id: str,
    page: int = 1,
    per_page: int = 25,
    search: str | None = None,
) -> dict | str:
    params: dict[str, Any] = {"page": page, "per_page": min(per_page, 100)}
    if search:
        params["search"] = search
    try:
        r = await client.get(f"{FRM_BASE}/forms/{id}/entries", params=params)
        r.raise_for_status()
    except Exception as e:
        return _err("list_form_entries", e)
    return r.json()


@mcp.tool(
    description=(
        "Get a single Formidable Forms entry by ID (or item_key) on "
        "the network root. Read-only.\n"
        "\n"
        "Returns the entry's metadata + `metas` dict of submitted "
        "field values. Use list_form_fields on the form_id to "
        "interpret the field_ids in metas."
    ),
)
async def get_form_entry(id: str) -> dict | str:
    try:
        r = await client.get(f"{FRM_BASE}/entries/{id}")
        r.raise_for_status()
    except Exception as e:
        return _err("get_form_entry", e)
    return r.json()


if __name__ == "__main__":
    print(
        f"[wp-mcp] starting {SERVER_NAME} on http://localhost:{PORT}/mcp",
        flush=True,
    )
    print(f"[wp-mcp] base URL: {WP_BASE}/wp-json/wp/v2", flush=True)
    print(f"[wp-mcp] frm base: {FRM_BASE} (network root, custom mu-plugin)", flush=True)
    print(f"[wp-mcp] user:     {WP_USER}", flush=True)
    mcp.run(transport="http", host="0.0.0.0", port=PORT)
