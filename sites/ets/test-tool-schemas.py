#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = ["fastmcp>=3.2,<4", "httpx>=0.27.0", "mcp"]
# ///
"""Structural tests for the ETS MCP tool surface. No network, no live site.

THREE FAILURES THIS EXISTS TO CATCH, all of which have actually happened here:

  1. THE EMPTY SCHEMA. A parameter annotated as bare `list` or `dict` generates
     the JSON schema {} — which tells an MCP client the parameter is untyped.
     Well-behaved clients then serialise the value as a STRING, the Python-side
     validator rejects it, and the parameter becomes unreachable from every
     client. Schema and validator disagree and nothing reports it. This is the
     P0 bug that made categories/tags unsettable while publishing Episode #281.

  2. THE CAPTURED DECORATOR. A helper function defined BETWEEN an @mcp.tool(...)
     decorator and its `async def` binds the decorator to the helper. The helper
     is registered as a tool and the real tool silently vanishes from the
     surface. Nothing errors; the tool just is not there.

  3. THE LOST TOOL. Any refactor that drops a tool from the registry.

Run:
  cd sites/ets && WP_BASE_URL=https://example.invalid WP_USERNAME=x \
    WP_APP_PASSWORD=y uv run --with fastmcp --with httpx --with mcp \
    python test-tool-schemas.py
"""
import asyncio
import importlib.util
import os
import sys
from pathlib import Path

os.environ.setdefault("WP_BASE_URL", "https://example.invalid")
os.environ.setdefault("WP_USERNAME", "x")
os.environ.setdefault("WP_APP_PASSWORD", "y")

_spec = importlib.util.spec_from_file_location(
    "ets_server", Path(__file__).with_name("server.py"))
m = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(m)

fails = 0


def check(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  — {detail}" if detail else ""))


EXPECTED = {
    # content reads
    "get_episode", "list_episodes", "get_page", "list_pages",
    "list_categories", "list_tags", "list_guests",
    # short links
    "list_short_links", "get_short_link", "find_short_link_for_url",
    "create_short_link", "update_short_link", "delete_short_link",
    # formidable
    "list_forms", "get_form", "list_form_fields", "list_form_entries",
    "get_form_entry",
    # editorial
    "list_episode_revisions", "get_episode_revision", "get_episode_autosave",
    "get_episode_lock", "get_episode_media_meta", "get_content_fingerprint",
    # activity log
    "get_activity_log", "get_activity_log_summary", "get_activity_log_retention",
    # acf options
    "get_acf_options", "set_acf_options",
    # episode write
    "get_episode_fields", "set_episode_fields", "create_episode",
    "update_episode", "set_episode_enclosure",
    "list_episode_enclosures", "get_enclosure_fingerprint",
}

# Module-level callables that must NEVER appear as tools. If one does, a
# decorator bound to it instead of the tool that was meant to follow.
HELPERS_THAT_MUST_NOT_BE_TOOLS = {
    "_err", "_trim_post", "_trim_post_full", "_trim_term", "_short_url_for_slug",
    "_trim_short_link", "_trim_form", "_trim_form_entry", "_trim_form_field",
    "_resolve_user", "_excerpt", "_editorial_field", "_int_list", "_scope_param",
    "_is_network_wide", "_iso_day", "_parse_since", "_windows", "_ets_route",
    "_episode_set_fields", "_term_ids", "_episode_set_terms",
    "_merge_taxonomy_args", "_php_unserialize", "_php_serialize",
    "_rewrite_enclosure_tail", "_hms_to_seconds", "_episode_no_from_title",
    "_site_labeled_tool", "lifespan",
}


def schema_of(t):
    """FastMCP 3.x exposes the JSON schema on Tool.parameters; the wire format
    calls it inputSchema. Read whichever this version populates rather than
    assuming, because an absent attribute silently makes every schema look empty
    and the whole test vacuously pass."""
    for attr in ("inputSchema", "parameters"):
        s = getattr(t, attr, None)
        if isinstance(s, dict) and s:
            return s
    raise AssertionError(f"tool {t.name!r} exposes no JSON schema on either attribute")


async def main():
    tools = {t.name: t for t in await m.mcp._list_tools()}
    print(f"\n{len(tools)} tools registered\n")

    missing = sorted(EXPECTED - set(tools))
    check("every expected tool is registered", not missing, f"missing: {missing}")

    leaked = sorted(set(tools) & HELPERS_THAT_MUST_NOT_BE_TOOLS)
    check("no helper leaked into the tool registry", not leaked, f"leaked: {leaked}")

    extra = sorted(set(tools) - EXPECTED)
    check("no unexpected tool appeared", not extra, f"unexpected: {extra}")

    # THE EMPTY SCHEMA CHECK. A property whose schema is {} — or which is missing
    # from `properties` while still being a real parameter — is unreachable.
    bad = []
    for name, t in sorted(tools.items()):
        props = schema_of(t).get("properties") or {}
        for pname, pschema in props.items():
            if not isinstance(pschema, dict) or pschema == {}:
                bad.append(f"{name}.{pname}")
                continue
            # anyOf/oneOf branches must themselves be typed
            branches = pschema.get("anyOf") or pschema.get("oneOf") or []
            if branches and all(b == {} for b in branches):
                bad.append(f"{name}.{pname} (all branches untyped)")
    check("no parameter has an empty JSON schema", not bad, f"untyped: {bad}")

    # The specific params the P0 bug made unreachable, plus the new ones. Each
    # must be an array/object, not a bare untyped blob.
    def kind(tool, param):
        props = schema_of(tools[tool]).get("properties") or {}
        p = props.get(param)
        if p is None:
            return "MISSING"
        cands = [p] + list(p.get("anyOf") or p.get("oneOf") or [])
        return ",".join(sorted({c.get("type", "?") for c in cands if isinstance(c, dict)}))

    for tool, param, want in (
        ("create_episode", "categories", "array"),
        ("create_episode", "tags", "array"),
        ("create_episode", "guests", "array"),
        ("create_episode", "taxonomies", "object"),
        ("create_episode", "fields", "object"),
        ("create_episode", "author", "integer"),
        ("update_episode", "categories", "array"),
        ("update_episode", "tags", "array"),
        ("update_episode", "guests", "array"),
        ("update_episode", "taxonomies", "object"),
        ("update_episode", "author", "integer"),
    ):
        k = kind(tool, param)
        check(f"{tool}.{param} carries a real type", want in k, f"got {k!r}")

    # Every tool description must carry the cross-site label, otherwise an LLM
    # picking between three wordpress-* connectors has nothing to go on.
    unlabelled = [n for n, t in tools.items()
                  if not (t.description or "").startswith("[WP site:")]
    check("every tool description is site-labelled", not unlabelled, f"{unlabelled}")

    print(f"\n{'ALL PASS' if not fails else str(fails) + ' FAILED'}")
    return 1 if fails else 0


sys.exit(asyncio.run(main()))
