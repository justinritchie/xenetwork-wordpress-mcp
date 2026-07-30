#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "fastmcp>=3.2,<4",
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
import json
import os
import sys
from contextlib import asynccontextmanager
from typing import Annotated, Any, Dict, Optional

import httpx
from fastmcp import FastMCP
from pydantic import BeforeValidator


def _coerce_obj(v: Any) -> Any:
    """Coerce a JSON-object-like value into a real dict.

    Why this exists: some MCP clients serialize dict-typed tool params as a
    JSON *string* when the param's JSON schema doesn't carry an explicit
    `"type": "object"`. Pydantic then rejects the string with a `dict_type`
    error ("Input should be a valid dictionary") BEFORE the tool body runs.
    Running this as a Pydantic BeforeValidator intercepts that case: a JSON
    string is parsed to a dict before the dict validation happens, so both a
    proper object and a stringified object are accepted. Belt-and-suspenders
    alongside the explicit object typing on the annotated params below.
    """
    if v is None or isinstance(v, dict):
        return v
    if isinstance(v, str):
        s = v.strip()
        if not s:
            return None
        try:
            parsed = json.loads(s)
        except Exception as e:  # noqa: BLE001 — surface as a clean validation error
            raise ValueError(f"expected a JSON object, got unparseable string: {e}")
        if not isinstance(parsed, dict):
            raise ValueError("expected a JSON object (a {key: value} mapping)")
        return parsed
    raise ValueError(f"expected an object/dict, got {type(v).__name__}")


# An optional {str: value} object param that tolerates string-serialized JSON.
# Using Annotated keeps an explicit object type in the generated schema (so
# well-behaved clients send a real object) while the BeforeValidator catches
# clients that send a JSON string anyway.
ObjParam = Annotated[Optional[Dict[str, Any]], BeforeValidator(_coerce_obj)]


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
# Cross-site disambiguation: prepend a "[WP site: <label>]" tag to every
# tool's description so MCP clients (and the LLMs driving them) can pick the
# right wordpress-* connector by semantic search. When Justin has all three
# WP sites wired up (xenetwork.org root, jumbo.live root, energytransitionshow
# subsite), tools like get_form / list_users / find_user_by_email otherwise
# look identical across instances.
#
# WP_SITE_LABEL overrides the auto-derivation. Defaults derive from SERVER_NAME
# via a small slug table (wordpress-xenetwork → "xenetwork.org network root",
# wordpress-jumbo → "jumbo.live network root", etc.).
# ---------------------------------------------------------------------------
_SITE_LABELS = {
    "wordpress-xenetwork": "xenetwork.org (network root)",
    "wordpress-jumbo": "jumbo.live (network root)",
    "wordpress-energytransitionshow": "energytransitionshow.com (subsite)",
}
SITE_LABEL = os.environ.get("WP_SITE_LABEL", "") or _SITE_LABELS.get(
    SERVER_NAME, SERVER_NAME
)

if SITE_LABEL:
    _label_prefix = f"[WP site: {SITE_LABEL}] "
    _original_mcp_tool = mcp.tool

    def _site_labeled_tool(*args, **kwargs):
        """Wrap mcp.tool so every registered tool gets `[WP site: …]`
        prepended to its description. Critical for multi-WP semantic search.
        Forwards everything else through unchanged."""
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
    content_replacements: ObjParam = None,
    meta_overrides: ObjParam = None,
    status: str = "draft",
) -> dict | str:
    """Hits the custom /xen/v1/institutional/duplicate endpoint, which
    copies content + all postmeta + all taxonomies server-side. The MCP
    just passes overrides through — heavy lifting is done in PHP where we
    have direct DB access via update_post_meta().

    The PHP endpoint auto-clears the source's per-cohort fields on duplicate
    (registered_member_list + form_entry_id) alongside the counter reset, so
    a clone never inherits the prior institution's registrant emails.
    """
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
    result = r.json()
    # QoL: surface the clean public URL the slug will resolve to once
    # published (reviewers want this in addition to the ?p=ID&preview link).
    # The PHP endpoint also returns this now; compute a fallback here so the
    # field is present even before the mu-plugin redeploy.
    if isinstance(result, dict) and not result.get("public_url"):
        slug = result.get("new_slug") or new_slug
        result["public_url"] = (
            f"{WP_BASE}/become-a-member-ets/institutions/{slug}/"
        )
    return result


@mcp.tool(
    description=(
        "Update an existing Institutional Registration page. Use to fix "
        "typos in a draft, correct a published page, or — via `meta` — set "
        "institution-specific postmeta WITHOUT re-cloning (institution_name, "
        "whitelisted_email, registration_limit, welcome_page, ToS text, "
        "etc.).\n"
        "\n"
        "Args:\n"
        "  id: post ID to update.\n"
        "  title: optional new title.\n"
        "  slug: optional new URL slug.\n"
        "  content: optional new full content body (replaces existing).\n"
        "  status: optional new status ('draft', 'publish', 'pending', "
        "'private'). To explicitly publish a draft, pass status='publish'.\n"
        "  meta: optional object of postmeta key→value pairs to write "
        "directly (hits the mu-plugin's postmeta endpoint, which can write "
        "keys the default wp/v2 REST hides). Example: "
        "{'institution_name': 'University of Nairobi', 'whitelisted_email': "
        "'uonbi.ac.ke', 'registration_limit': '50'}. Run get_institutional "
        "first to see existing keys.\n"
        "\n"
        "At least one of title/slug/content/status/meta is required. Only "
        "specified fields are changed; unspecified fields preserved."
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
    meta: ObjParam = None,
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

    if not payload and not meta:
        return (
            "ERROR: nothing to update — at least one of "
            "title/slug/content/status/meta required"
        )

    fields_updated: list[str] = []
    result: dict[str, Any] = {
        "ok": True,
        "id": id,
        "edit_url": f"https://xenetwork.org/wp-admin/post.php?post={id}&action=edit",
    }

    # Core post fields go through the standard wp/v2 endpoint.
    if payload:
        try:
            r = await client.post(f"/xen_institutional/{id}", json=payload)
            r.raise_for_status()
        except Exception as e:
            return _err("update_institutional", e)
        updated = r.json()
        result.update({
            "slug": updated.get("slug"),
            "status": updated.get("status"),
            "title": (updated.get("title") or {}).get("rendered"),
            "modified": updated.get("modified"),
        })
        fields_updated.extend(payload.keys())

    # Postmeta goes through the mu-plugin endpoint (writes keys wp/v2 hides).
    if meta:
        try:
            mr = await client.post(
                f"{WP_BASE}/wp-json/xen/v1/institutional/{id}/meta",
                json={"meta": meta},
            )
            mr.raise_for_status()
        except Exception as e:
            return _err("update_institutional (meta)", e)
        meta_result = mr.json()
        applied = (
            meta_result.get("meta_updated")
            if isinstance(meta_result, dict) else None
        ) or list(meta.keys())
        result["meta_updated"] = applied
        fields_updated.extend(f"meta.{k}" for k in applied)

    result["fields_updated"] = fields_updated
    return result


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

# count-users-by-role tool (managed by xen_count_users_patch)
@mcp.tool(
    description=(
        "Single-call rollup of WordPress user counts grouped by role on "
        "xenetwork.org (Energy Transition Show / XE Network). Returns counts "
        "for EVERY role on the site — both stock WordPress roles "
        "(administrator, editor, subscriber) AND s2Member membership levels "
        "(s2member_level0 through s2member_level5).\n"
        "\n"
        "USE WHENEVER the user asks any of:\n"
        "  - 'how many users at each level' / 'membership level rollup' / "
        "'level distribution'\n"
        "  - 'count users by role' / 'role distribution' / 'WP role counts'\n"
        "  - 'how many subscribers' / 'how many level 1' / 'level 2 / 3 / 4 / 5'\n"
        "  - 'monthly user count' / 'month-end user numbers' / 'who's at what tier'\n"
        "  - 'paid tier breakdown' / 'membership tiers' / 'how big is the membership'\n"
        "  - 'total active members' / 'XE Network user roster size'\n"
        "  - any monthly-close, bookkeeping, or board-report question about "
        "user counts on the XE Network main site\n"
        "\n"
        "PREFER THIS over list_users + manual counting — list_users requires "
        "paginating through 10,000+ user records (5+ minutes of LLM context "
        "burn); this returns the entire rollup in ONE HTTP call (~200 bytes) "
        "by hitting the custom /xen/v1/users/count-by-role endpoint that "
        "wraps WordPress's native count_users() SQL function (single GROUP BY "
        "query at the database).\n"
        "\n"
        "DO NOT use for filtering by Custom Capability (CCAP) like 'qcf' or "
        "'sabu' — those don't appear as roles. Use count_users_by_ccap "
        "instead for any CCAP / capability / s2Member-ccap question.\n"
        "\n"
        "Returns:\n"
        "  {\n"
        "    total_users: int,\n"
        "    avail_roles: {role_slug: count, ...},  # every role with users\n"
        "    sorted_desc: same map sorted by count descending,\n"
        "    site, generated_at\n"
        "  }\n"
        "\n"
        "Requires list_users capability on xenetwork.org (admin/editor)."
    ),
)
async def count_users_by_role() -> dict | str:
    try:
        r = await client.get(f"{WP_BASE}/wp-json/xen/v1/users/count-by-role")
        r.raise_for_status()
    except Exception as e:
        return _err("count_users_by_role", e)
    return r.json()

# count-users-by-ccap tool (managed by xen_count_users_patch v2)
@mcp.tool(
    description=(
        "Count xenetwork.org users by s2Member Custom Capability (CCAP) with "
        "substring/pattern matching. Server-side aggregation via the "
        "/xen/v1/users/by-ccap endpoint — returns just counts (and optional "
        "user list for spot-checking), NOT full user records.\n"
        "\n"
        "WHAT IS A CCAP: Custom Capability. s2Member uses these for "
        "fine-grained access alongside membership levels. They live in the "
        "user's wp_capabilities blob as keys like 'access_s2member_ccap_<slug>'. "
        "The CCAP slug is the part AFTER 'access_s2member_ccap_'. Examples: "
        "'sunqcf', 'sabuqcf', 'kmutqcf', 'witsqcf', 'premium_yearly', "
        "'inspireqcf', 'unopsqcf', 'elaqcf', 'cvfqcf'.\n"
        "\n"
        "USE WHENEVER the user asks any of:\n"
        "  - 'how many users have CCAP X' / 'count users with capability X'\n"
        "  - 'how many people have a CCAP ending with qcf' (QCF Global South "
        "Grant program tracking)\n"
        "  - 'show me everyone with the sabu / kmut / wits / sun / inspire / "
        "unops / ela / cvf capability'\n"
        "  - 'who has a premium-* CCAP' / 'count premium ccap users'\n"
        "  - 'rollup of QCF participants' / 'QCF cohort sizes' / "
        "'how many QCF licenses are active'\n"
        "  - 'filter users by capability' / 'users by access_s2member_ccap_*'\n"
        "  - any question matching 'how many users have' + a capability/CCAP/"
        "access pattern\n"
        "\n"
        "MATCH MODES:\n"
        "  pattern='qcf', match='ends_with'      → CCAPs ending in qcf "
        "(sunqcf, sabuqcf, etc.)\n"
        "  pattern='premium', match='starts_with' → premium_yearly, premium_monthly\n"
        "  pattern='sabu', match='contains'       → any CCAP with 'sabu' "
        "anywhere in the slug\n"
        "  pattern='sunqcf', match='exact'        → only the exact slug 'sunqcf'\n"
        "\n"
        "PREFER THIS over list_users + per-user inspection — list_users "
        "returns ~50KB per user record (s2Member adds tons of fields); this "
        "endpoint queries wp_usermeta directly with a SQL prefilter, "
        "deserializes only matching capability blobs server-side, and "
        "returns ~200 bytes total.\n"
        "\n"
        "DO NOT use for plain WP role counting (administrator, editor, "
        "subscriber, s2member_level1, etc.) — those are ROLES, not CCAPs. "
        "Use count_users_by_role for level/role rollups.\n"
        "\n"
        "Args:\n"
        "  pattern: required substring of the CCAP slug to match\n"
        "  match: 'contains' (default) | 'ends_with' | 'starts_with' | 'exact'\n"
        "  include_users: default False. True returns the list of matching "
        "users with id/email/display_name/matching_ccaps — useful for "
        "spot-checking who's in a cohort, but slower.\n"
        "  limit: max users to scan in the SQL prefilter (default 1000). "
        "Bump if you expect >1000 matches.\n"
        "\n"
        "Returns:\n"
        "  {\n"
        "    pattern, match,\n"
        "    total_matching_users: int  (distinct users that matched),\n"
        "    ccap_breakdown: {ccap_slug: user_count, ...} sorted by count desc,\n"
        "    users: [...] | null  (only when include_users=True),\n"
        "    rows_scanned, limit_used, site, generated_at\n"
        "  }"
    ),
)
async def count_users_by_ccap(
    pattern: str,
    match: str = "contains",
    include_users: bool = False,
    limit: int = 1000,
) -> dict | str:
    params = {
        "pattern": pattern,
        "match": match,
        "include_users": "true" if include_users else "false",
        "limit": limit,
    }
    try:
        r = await client.get(
            f"{WP_BASE}/wp-json/xen/v1/users/by-ccap",
            params=params,
        )
        r.raise_for_status()
    except Exception as e:
        return _err("count_users_by_ccap", e)
    return r.json()


# ---------------------------------------------------------------------------
# ccap-filtered roster export (list_users_by_ccap)
# ---------------------------------------------------------------------------
#
# Replaces the three unreliable roster methods this pipeline used to depend on:
# the broken Admin Columns Pro CSV export, copy-paste from the wp-admin user
# list (silently truncates), and list_users domain search (misses members on
# outside email domains, and overflows the tool response cap).
#
# CSV is written TO DISK, never returned inline — a 55-user roster already
# exceeded the response cap, and a truncated roster that looks complete is the
# precise failure this tool exists to eliminate.

_EM_DASH = "—"

# Columns the stats workbook wraps in IF(ISNUMBER(...), ..., 0). A blank there
# silently breaks the Tenure-Weighted columns, so a missing value must render
# as an em-dash, matching the ACP export it replaces.
_EM_DASH_FIELDS = ("logins", "member_feed_login")

# (CSV header label, record key) in the exact ACP column order.
_CSV_COLUMNS = [
    ("Username", "username"),
    ("Name", "name"),
    ("Email", "email"),
    ("Role", "role"),
    ("Ccap", "ccap"),
    ("Registered", "registered"),
    ("# Of Logins", "logins"),
    ("Coupon Code", "coupon_code"),
    ("Member Feed Login", "member_feed_login"),
    ("Reg. Page ID", "reg_page_id"),
    ("First Name", "first_name"),
    ("Last Name", "last_name"),
    ("Newsletter Optin", "newsletter_optin"),
    ("S2 EOT", "s2_eot"),
]

EXPORT_DIR = os.environ.get(
    "XEN_EXPORT_DIR", os.path.expanduser("~/Downloads/xen-ccap-exports")
)


def _csv_quote(value: Any) -> str:
    """Quote a field iff it contains a comma, quote, space, or newline.

    Matches the ACP export convention exactly: bare `Username`, quoted
    `"# Of Logins"`. Python's csv.QUOTE_MINIMAL does NOT quote on spaces,
    so the quoting is hand-rolled rather than delegated.
    """
    s = "" if value is None else str(value)
    if any(ch in s for ch in (",", '"', " ", "\n", "\r")):
        return '"' + s.replace('"', '""') + '"'
    return s


def _render_csv(users: list[dict]) -> str:
    lines = [",".join(_csv_quote(label) for label, _ in _CSV_COLUMNS)]
    for u in users:
        cells = []
        for _, key in _CSV_COLUMNS:
            val = u.get(key)
            if val is None and key in _EM_DASH_FIELDS:
                val = _EM_DASH
            cells.append(_csv_quote(val))
        lines.append(",".join(cells))
    # RFC 4180 line terminator. csv.reader and pandas.read_csv both accept
    # CRLF and LF identically, so this is not a consumer-visible choice.
    return "\r\n".join(lines) + "\r\n"


async def _fetch_all_ccap_users(params: dict[str, Any]) -> dict:
    """Page through the endpoint until every matching user is collected.

    Verifies the collected count against the server's own `total` and fails
    loudly on a mismatch rather than returning a short roster.
    """
    page_params = dict(params, per_page=500, page=1)
    r = await client.get(
        f"{WP_BASE}/wp-json/xen/v1/users/export-by-ccap", params=page_params
    )
    r.raise_for_status()
    first = r.json()

    users = list(first.get("users") or [])
    total_pages = int(first.get("total_pages") or 0)
    for pg in range(2, total_pages + 1):
        rp = await client.get(
            f"{WP_BASE}/wp-json/xen/v1/users/export-by-ccap",
            params=dict(params, per_page=500, page=pg),
        )
        rp.raise_for_status()
        users.extend(rp.json().get("users") or [])

    first["users"] = users
    total = int(first.get("total") or 0)
    if len(users) != total:
        first.setdefault("warnings", []).append(
            f"INCOMPLETE: collected {len(users)} records but server reported "
            f"total={total}. Do NOT use this roster."
        )
    return first


@mcp.tool(
    description=(
        "Complete, verifiable roster of xenetwork.org users for a given "
        "s2Member ccap — the authoritative first step of every group "
        "subscription renewal. Writes the CSV to disk in the exact 14-column "
        "Admin Columns Pro schema the stats workbooks consume.\n"
        "\n"
        "USE THIS for any 'who is on the X licence' / 'pull the roster for X' "
        "/ 'how many seats does X have' / renewal-stats question.\n"
        "\n"
        "DO NOT use list_users with an email-domain search to build a roster. "
        "It matches on email domain rather than ccap, so it silently drops "
        "members on outside domains (St. Lawrence had two Gmail-based members "
        "it missed), and a 55-user pull overflows the tool response cap.\n"
        "\n"
        "DO NOT use count_users_by_ccap to size a licence. That tool reads "
        "only the root wp_capabilities blob, and s2Member STRIPS the ccap from "
        "that blob when it demotes a user at EOT — so it under-reports every "
        "org with expired members (returns 121 for `uva` where the roster is "
        "~414, and 0 for `dartdemo`). This tool reads the `ccaps` usermeta key "
        "and the subsite capability blobs, both of which survive demotion.\n"
        "\n"
        "CCAP FAMILIES: an organisation's licence usually spans more than one "
        "ccap — Dartmouth is `dartmouth` (active) plus `dartdemo` (demoted "
        "trial accounts), and both count toward cumulative usage. Pass a list "
        "or use ccap_prefix; matching one exact ccap reintroduces the "
        "incompleteness bug in a new form.\n"
        "\n"
        "Args:\n"
        "  ccap: required unless ccap_prefix. Slug, or comma-separated list "
        "('dartmouth,dartdemo').\n"
        "  ccap_prefix: prefix match — 'dart' catches dartmouth + dartdemo.\n"
        "  include_demoted: default True. Demoted accounts belong to the "
        "licence; excluding them understates cumulative usage.\n"
        "  format: 'csv' (default — writes the complete roster to disk and "
        "returns the path plus counts), 'counts' (verification numbers only, "
        "no records, fastest), or 'json' (paginated records inline).\n"
        "  page / per_page: json format only (default 200/page).\n"
        "  since / until: optional registered_date bounds (YYYY-MM-DD), for "
        "'seats active during the licence year' figures.\n"
        "  save_to: override the output path for csv format.\n"
        "\n"
        "ALWAYS CHECK before trusting the roster: `total` and "
        "`counts_by_role` are the completeness proof — compare them against "
        "the expected seat count, and read `warnings` and `verification`. A "
        "non-empty `warnings` means the roster may be short."
    ),
)
async def list_users_by_ccap(
    ccap: str | None = None,
    ccap_prefix: str | None = None,
    include_demoted: bool = True,
    format: str = "csv",
    page: int = 1,
    per_page: int = 200,
    since: str | None = None,
    until: str | None = None,
    save_to: str | None = None,
) -> dict | str:
    if not ccap and not ccap_prefix:
        return "ERROR: provide ccap and/or ccap_prefix"
    if format not in ("csv", "json", "counts"):
        return f"ERROR: format must be 'csv', 'json', or 'counts' — got {format!r}"

    params: dict[str, Any] = {
        "include_demoted": "true" if include_demoted else "false",
    }
    if ccap:
        params["ccap"] = ccap
    if ccap_prefix:
        params["ccap_prefix"] = ccap_prefix
    if since:
        params["since"] = since
    if until:
        params["until"] = until

    try:
        if format == "counts":
            r = await client.get(
                f"{WP_BASE}/wp-json/xen/v1/users/export-by-ccap",
                params=dict(params, per_page=0),
            )
            r.raise_for_status()
            return r.json()

        if format == "json":
            r = await client.get(
                f"{WP_BASE}/wp-json/xen/v1/users/export-by-ccap",
                params=dict(params, per_page=min(per_page, 500), page=page),
            )
            r.raise_for_status()
            return r.json()

        payload = await _fetch_all_ccap_users(params)
    except Exception as e:
        return _err("list_users_by_ccap", e)

    users = payload.get("users") or []
    csv_text = _render_csv(users)

    if save_to:
        path = os.path.expanduser(save_to)
    else:
        slug = (ccap or ccap_prefix or "ccap").replace(",", "-").replace(" ", "")
        stamp = payload.get("snapshot_date") or "export"
        os.makedirs(EXPORT_DIR, exist_ok=True)
        path = os.path.join(EXPORT_DIR, f"{slug}-users-export-{stamp}.csv")

    try:
        os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
        with open(path, "w", encoding="utf-8", newline="") as fh:
            fh.write(csv_text)
    except Exception as e:
        return _err("list_users_by_ccap (write csv)", e)

    preview = csv_text.split("\r\n")[: min(4, len(users) + 1)]
    return {
        "csv_path": path,
        "rows_written": len(users),
        "bytes": len(csv_text.encode("utf-8")),
        "header": ",".join(_csv_quote(label) for label, _ in _CSV_COLUMNS),
        "preview": preview,
        "total": payload.get("total"),
        "counts_by_role": payload.get("counts_by_role"),
        "counts_by_ccap": payload.get("counts_by_ccap"),
        "verification": payload.get("verification"),
        "warnings": payload.get("warnings") or [],
        "snapshot_date": payload.get("snapshot_date"),
        "generated_at": payload.get("generated_at"),
        "note": (
            "Encoding UTF-8 (no BOM); consumers should read with "
            "encoding='utf-8-sig' to tolerate either. Missing values in "
            "'# Of Logins' and 'Member Feed Login' render as an em-dash to "
            "match the ACP export. Check `total` and `counts_by_role` against "
            "the expected seat count before using this roster."
        ),
    }


# ---------------------------------------------------------------------------
# Member access writes — role + Auto-EOT (set_member_access)
# ---------------------------------------------------------------------------
#
# The only user-write surface on this server. Closes the last manual step in
# the group cancellation flow: demote members L4 -> L2 (which suppresses the
# "EOT Reminder L4" mail that would otherwise go to a cancelled group) and set
# Auto-EOT to the paid-through date so access lapses on schedule.
#
# The one thing that must not regress: a demotion performed here MUST leave the
# member visible to list_users_by_ccap. s2Member's own demotion strips the ccap
# from the root capability blob, which is why count_users_by_ccap under-reports
# every org with expired members. This endpoint preserves ccaps in every blob.


async def _set_access_call(payload: dict[str, Any]) -> dict | str:
    try:
        r = await client.post(
            f"{WP_BASE}/wp-json/xen/v1/users/set-access", json=payload
        )
    except Exception as e:
        return _err("set_member_access", e)
    try:
        data = r.json()
    except Exception:
        return f"ERROR: HTTP {r.status_code}, non-JSON body: {r.text[:300]}"
    if isinstance(data, dict):
        # Hoist anything that must not be skimmed past.
        if data.get("warnings"):
            data["ATTENTION"] = data["warnings"]
        bad = [
            u for u in (data.get("users") or [])
            if u.get("status") in ("write_unconfirmed", "not_found", "ambiguous")
        ]
        if bad:
            data["NEEDS_REVIEW"] = bad
    return data


@mcp.tool(
    description=(
        "Set a member's s2Member level/role and/or Auto-EOT date on "
        "xenetwork.org. Dry-run by default — pass dry_run=False to write.\n"
        "\n"
        "USE FOR group cancellations and seat removals: demoting members from "
        "Level 4 to Level 2 (which suppresses the 'EOT Reminder L4' email that "
        "would otherwise go to a cancelled group) and setting Auto-EOT to the "
        "paid-through date so access lapses on schedule rather than being cut "
        "early or left open.\n"
        "\n"
        "CCAP SAFETY: a demotion through this tool does NOT strip the member's "
        "ccap. s2Member's own EOT demotion removes access_s2member_ccap_<slug> "
        "from the root capability blob — which is why count_users_by_ccap "
        "under-reports every org with expired members. This preserves ccaps in "
        "every blob, so the member still appears in list_users_by_ccap "
        "afterwards. Verify that with a roster pull after any demotion.\n"
        "\n"
        "Args:\n"
        "  user: ID, email, login, or slug. Exactly one identifier.\n"
        "  level: 0-5, a role slug ('s2member_level2'), or 'demoted'. Must be "
        "a REGISTERED role — unknown slugs are refused, not invented.\n"
        "  auto_eot: 'YYYY-MM-DD' (midnight UTC), unix seconds, or 'clear'. "
        "The response reports which interpretation was used.\n"
        "  reason: REQUIRED free text. Written to the s2Member notes field and "
        "an audit key so a demotion traces back to its cancellation.\n"
        "  preserve_ccaps: default True. Leave it True.\n"
        "  dry_run: default True. Reads current values and reports the exact "
        "delta without writing.\n"
        "\n"
        "Returns per user: old_level, new_level, old_auto_eot, new_auto_eot, "
        "ccaps_before, ccaps_after, status. Status is would_change / "
        "already_set / applied / write_unconfirmed / not_found. A write counts "
        "as `applied` ONLY if reading the record back confirms it; a silently "
        "discarded write surfaces as write_unconfirmed."
    ),
    annotations={"destructiveHint": True, "idempotentHint": True, "openWorldHint": True},
)
async def set_member_access(
    user: str,
    reason: str,
    level: str | None = None,
    auto_eot: str | None = None,
    preserve_ccaps: bool = True,
    dry_run: bool = True,
) -> dict | str:
    if not reason or not reason.strip():
        return "ERROR: `reason` is required — it is the audit trail."
    if level is None and auto_eot is None:
        return "ERROR: supply at least one of level or auto_eot."
    payload: dict[str, Any] = {
        "user": user,
        "reason": reason,
        "preserve_ccaps": bool(preserve_ccaps),
        "dry_run": bool(dry_run),
    }
    if level is not None:
        payload["level"] = level
    if auto_eot is not None:
        payload["auto_eot"] = auto_eot
    return await _set_access_call(payload)


@mcp.tool(
    description=(
        "Set level and/or Auto-EOT for MULTIPLE members on xenetwork.org in "
        "one batch. Dry-run by default — pass dry_run=False to write.\n"
        "\n"
        "The cancellation primitive: demote a whole group to Level 2 and set "
        "everyone's Auto-EOT to the paid-through date in a single verified "
        "operation, while leaving any member who is being migrated to an "
        "individual membership untouched.\n"
        "\n"
        "SAFETY: every identifier is resolved and every value validated BEFORE "
        "anything is written. Any unresolvable user, unknown role slug, or "
        "malformed date aborts the ENTIRE batch with nothing written, unless "
        "allow_partial=True. A batch larger than max_batch is refused outright "
        "rather than truncated — a silently half-applied cancellation is the "
        "failure this is built to prevent.\n"
        "\n"
        "CCAP SAFETY: demotions here preserve ccaps, so members stay visible "
        "to list_users_by_ccap. Confirm with a roster pull afterwards.\n"
        "\n"
        "Args:\n"
        "  users: list of identifiers (ID/email/login), or list of objects "
        "{identifier, level?, auto_eot?} for per-user overrides. Accepts a "
        "JSON string too.\n"
        "  level / auto_eot: batch defaults, overridden per user.\n"
        "  reason: REQUIRED. Written to each member's audit trail.\n"
        "  dry_run: default True.\n"
        "  allow_partial: default False. Leave it False for cancellations.\n"
        "  max_batch: default 100. Oversized batches are refused.\n"
        "\n"
        "Returns a per-user table plus a `summary` count by status. Check "
        "`summary` and any ATTENTION / NEEDS_REVIEW keys before considering "
        "the run done."
    ),
    annotations={"destructiveHint": True, "idempotentHint": True, "openWorldHint": True},
)
async def bulk_set_member_access(
    users: Any,
    reason: str,
    level: str | None = None,
    auto_eot: str | None = None,
    preserve_ccaps: bool = True,
    dry_run: bool = True,
    allow_partial: bool = False,
    max_batch: int = 100,
) -> dict | str:
    if not reason or not reason.strip():
        return "ERROR: `reason` is required — it is the audit trail."
    if isinstance(users, str):
        try:
            users = json.loads(users)
        except Exception:
            users = [u.strip() for u in users.split(",") if u.strip()]
    if not isinstance(users, list) or not users:
        return "ERROR: `users` must be a non-empty list."
    norm = [u if isinstance(u, dict) else {"identifier": u} for u in users]

    payload: dict[str, Any] = {
        "users": norm,
        "reason": reason,
        "preserve_ccaps": bool(preserve_ccaps),
        "dry_run": bool(dry_run),
        "allow_partial": bool(allow_partial),
        "max_batch": int(max_batch),
    }
    if level is not None:
        payload["level"] = level
    if auto_eot is not None:
        payload["auto_eot"] = auto_eot
    return await _set_access_call(payload)


if __name__ == "__main__":
    print(
        f"[wp-mcp] starting {SERVER_NAME} on http://localhost:{PORT}/mcp",
        flush=True,
    )
    print(f"[wp-mcp] base URL: {WP_BASE}/wp-json/wp/v2", flush=True)
    print(f"[wp-mcp] frm base: {FRM_BASE} (network root, custom mu-plugin)", flush=True)
    print(f"[wp-mcp] user:     {WP_USER}", flush=True)
    mcp.run(transport="http", host="0.0.0.0", port=PORT)
