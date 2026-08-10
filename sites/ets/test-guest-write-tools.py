#!/usr/bin/env -S uv run --script
# /// script
# requires-python = ">=3.11"
# dependencies = ["fastmcp>=3.2,<4"]
# ///
"""Acceptance tests for create_guest / update_guest against the LIVE ETS site.

Driven through a real fastmcp.Client on http://localhost:8007/mcp rather than
through the Claude Desktop tool surface, because that surface sits behind the
mcp-remote proxy which flattens anyOf schemas to {} — testing through it would
measure the proxy, not these tools.

EVERY ARGUMENT IS SENT AS A STRING. That is what a real client does once the
schema has been flattened, and it is the half of the 7ea0fd9 fix that keeps
breaking: a bool that arrives as "true" or an int that arrives as "1351" must
still work.

Touches ONLY term 1351, the synthetic test guest. No real guest term is written.
"""
import asyncio
import json

from fastmcp import Client

URL = "http://localhost:8007/mcp"
TEST_TERM_ID = 1351
REAL_TERMS = "564,1285,1347,1350"

passed, failed = 0, 0


def check(label, cond, detail=""):
    global passed, failed
    if cond:
        passed += 1
        print(f"  PASS  {label}")
    else:
        failed += 1
        print(f"  FAIL  {label}  {detail}")


def payload(res):
    """Unwrap a fastmcp CallToolResult into the dict the tool returned."""
    if getattr(res, "structured_content", None):
        sc = res.structured_content
        return sc.get("result", sc) if isinstance(sc, dict) else sc
    for block in (res.content or []):
        text = getattr(block, "text", None)
        if text:
            try:
                return json.loads(text)
            except ValueError:
                return {"_raw": text}
    return {}


async def main():
    async with Client(URL) as c:
        tools = {t.name for t in await c.list_tools()}
        print("=== 0. tool surface ===")
        check("create_guest registered", "create_guest" in tools)
        check("update_guest registered", "update_guest" in tools)
        check("list_guests still registered", "list_guests" in tools)

        print("\n=== 1. dry_run create, ALL ARGS AS STRINGS ===")
        r = payload(await c.call_tool("create_guest", {
            "name": "ZZ String Arg Probe",
            "bio": "<strong>ZZ String Arg Probe</strong> is not real.",
            "dry_run": "true",            # string, not bool
            "allow_duplicate": "false",   # string, not bool
        }))
        check("dry_run honoured from the string 'true'",
              r.get("dry_run") is True and r.get("persisted") is False, json.dumps(r)[:200])
        check("would_create previewed", bool(r.get("would_create")), json.dumps(r)[:200])
        check("archive_url has no doubled /ets/ets/",
              "/ets/ets/" not in (r.get("would_create", {}).get("archive_url") or ""),
              r.get("would_create", {}).get("archive_url"))
        check("nothing flagged as duplicate", r.get("would_be_blocked") is False)

        print("\n=== 2. DUPLICATE REFUSAL — 'Valerie Trouet' (term 564 exists) ===")
        r = payload(await c.call_tool("create_guest", {"name": "Valerie Trouet"}))
        check("refused", r.get("ok") is False, json.dumps(r)[:200])
        check("error is duplicate_term", r.get("error") == "duplicate_term", r.get("error"))
        cands = r.get("existing_candidates") or []
        check("candidate 564 returned", any(c_["term_id"] == 564 for c_ in cands),
              json.dumps(cands)[:300])
        check("candidate carries episode count + archive",
              bool(cands) and cands[0].get("episodes") is not None
              and cands[0].get("archive"), json.dumps(cands)[:300])
        check("tells the caller what to do", bool(r.get("what_to_do")))
        print("        candidates:", json.dumps(cands))

        print("\n=== 3. DUPLICATE REFUSAL — near miss 'Valery Trouet' ===")
        r = payload(await c.call_tool("create_guest", {"name": "Valery Trouet"}))
        check("near-miss refused", r.get("error") == "duplicate_term", json.dumps(r)[:200])
        print("        candidates:", json.dumps(r.get("existing_candidates")))

        print("\n=== 4. update the TEST term's bio with HTML, args as strings ===")
        BIO = ("<strong>ZZ MCP Test Guest</strong> is a synthetic taxonomy term used to "
               "verify the guest write path.\r\n\r\nThey are <em>emphatically</em> "
               "fictional and cite <code>wp_update_term()</code> at parties.\r\n\r\n"
               "On the Web: <a href=\"https://example.org/zz\" title=\"probe\">"
               "example.org/zz</a>")
        r = payload(await c.call_tool("update_guest", {
            "term_id": str(TEST_TERM_ID),   # string, not int
            "bio": BIO,
            "dry_run": "false",             # string, not bool
        }))
        check("write persisted", r.get("ok") is True and r.get("persisted") is True,
              json.dumps(r)[:300])
        integ = r.get("integrity") or {}
        check("verified by DB re-read", integ.get("verified_by_reread") is True)
        check("no content lost", integ.get("content_lost") == [], integ.get("content_lost"))
        check("bio byte-identical",
              (integ.get("fields", {}).get("description", {}) or {}).get("identical") is True,
              json.dumps(integ.get("fields", {}).get("description"))[:300])
        check("untouched fields held", integ.get("untouched_fields_held") is True)
        rev = r.get("review") or {}
        check("before/after review present",
              rev.get("fields_changed") == ["description"] and "bio_before" in rev,
              json.dumps(rev)[:300])
        check("revert payload returned", bool((r.get("revert") or {}).get("args")))

        print("\n=== 5. independent read-back via list_guests ===")
        r = payload(await c.call_tool("list_guests", {"include": str(TEST_TERM_ID)}))
        term = (r.get("terms") or [{}])[0]
        check("bio round-tripped byte-identical", term.get("description") == BIO,
              repr(term.get("description"))[:300])
        check("count still 0 (not attached to any episode)", term.get("count") == 0)

        print("\n=== 6. partial semantics — name only must not move slug or bio ===")
        r = payload(await c.call_tool("update_guest", {
            "term_id": TEST_TERM_ID,
            "name": "ZZ MCP Test Guest 20260810",
        }))
        ch = r.get("changes") or {}
        b, a = ch.get("before") or {}, ch.get("after") or {}
        check("slug unchanged", b.get("slug") == a.get("slug"), f"{b.get('slug')} -> {a.get('slug')}")
        check("bio unchanged", b.get("description") == a.get("description"))
        check("untouched fields held", (r.get("integrity") or {}).get("untouched_fields_held") is True)

        print("\n=== 7. slug change WARNS loudly (dry run) ===")
        r = payload(await c.call_tool("update_guest", {
            "term_id": TEST_TERM_ID, "slug": "zz-would-break-this", "dry_run": "true",
        }))
        warns = " ".join(r.get("warnings") or [])
        check("nothing written", r.get("persisted") is False)
        check("warns about the 404", "404" in warns and "SLUG CHANGE" in warns, warns[:200])
        check("names the episode count", "episode" in warns, warns[:200])
        check("warning URL not doubled", "/ets/ets/" not in warns)

        print("\n=== 8. guardrails ===")
        r = payload(await c.call_tool("update_guest", {"term_id": TEST_TERM_ID}))
        check("empty update refused client-side", r.get("ok") is False, json.dumps(r)[:200])
        r = payload(await c.call_tool("update_guest", {"term_id": "99999999", "bio": "x"}))
        check("unknown term id -> unknown_term", r.get("error") == "unknown_term", json.dumps(r)[:200])
        r = payload(await c.call_tool("create_guest", {"name": "   "}))
        check("blank name refused", r.get("ok") is False, json.dumps(r)[:200])
        r = payload(await c.call_tool("update_guest", {"term_id": "not-a-number", "bio": "x"}))
        check("non-numeric term_id refused cleanly", r.get("ok") is False, json.dumps(r)[:200])

        print("\n=== 9. _json_arg no longer raises NameError (module-level json import) ===")
        r = payload(await c.call_tool("list_episodes", {"per_page": "1"}))
        check("list_episodes still fine", isinstance(r, (dict, list)), json.dumps(r)[:120])
        r = payload(await c.call_tool("update_episode", {
            "id": "999999999", "taxonomies": '{"xen_guests": [1351]}',
        }))
        check("string-serialised dict param parsed, not NameError",
              "NameError" not in json.dumps(r), json.dumps(r)[:250])

        print("\n=== 10. real guest terms untouched ===")
        r = payload(await c.call_tool("list_guests", {"include": REAL_TERMS}))
        expect = {564: ("valerie-trouet", 828), 1285: ("nicolas-fulghum", 500),
                  1347: ("aditya-lolla", 1209), 1350: ("bill-mckibben", 2023)}
        for t in r.get("terms") or []:
            slug, dlen = expect[t["id"]]
            check(f"term {t['id']} {t['name']!r} unchanged",
                  t["slug"] == slug and len(t["description"] or "") == dlen,
                  f"slug={t['slug']} desc_len={len(t['description'] or '')}")
        r = payload(await c.call_tool("list_guests", {"per_page": "1"}))
        check("taxonomy total is 263 (262 real + 1 test)", r.get("total") == 263, r.get("total"))
        r = payload(await c.call_tool("list_guests", {"per_page": "200", "page": "2"}))
        check("page 2 terms is a LIST, not an offset-keyed object",
              isinstance(r.get("terms"), list), type(r.get("terms")).__name__)

    print(f"\n{'='*60}\nPASSED {passed}   FAILED {failed}")
    print(f"Test term left in place: {TEST_TERM_ID} (no delete route by design)")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(asyncio.run(main()))
