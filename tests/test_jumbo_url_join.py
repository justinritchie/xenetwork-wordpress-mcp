#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = [
#   "fastmcp>=3.2,<4",
#   "httpx>=0.27.0",
# ]
# ///
"""Ticket test plan for the wordpress-jumbo doubled-URL bug.

Exercises the server's real code path (imports server.py, calls the tool
functions) so the result does not depend on the Claude Desktop MCP proxy
holding a fresh session.

Run with the credential env already sourced.
"""
import asyncio
import inspect
import sys

sys.path.insert(0, str(__import__("pathlib").Path(__file__).resolve().parent.parent / "sites" / "jumbo"))

import server as S  # noqa: E402

PASS, FAIL = "PASS", "FAIL"
results = []


def record(name, ok, detail=""):
    results.append((PASS if ok else FAIL, name, detail))
    print(f"  [{PASS if ok else FAIL}] {name}" + (f"  -- {detail}" if detail else ""))


async def call(fn, **kw):
    """Call a tool whether or not FastMCP wrapped it."""
    f = getattr(fn, "fn", fn)
    r = f(**kw)
    return await r if inspect.isawaitable(r) else r


def blob(d):
    return str(d)


async def main():
    print("=== 0. the join rule itself ===")
    s = S._resolve_site("aarlocal") if hasattr(S, "_resolve_site") else None
    if s is None:
        for cand in ("_site", "_lookup_site", "_get_site_cfg"):
            if hasattr(S, cand):
                s = getattr(S, cand)("aarlocal")
                break
    if s is None:
        s = S.SITES[[k for k in S.SITES if "local" in k][0]]
    for path, want_doubled in [
        ("/wp-json/jumbo-qa/v1/whoami", False),
        ("/users", False),
    ]:
        u = S._site_join(s, path)
        record(f"join {path}", u.count("/wp-json/") == 1, u.replace(s.url, ""))

    print("\n=== 1. get_acp_user_prefs on aarlocal (was 404) ===")
    r = await call(S.get_acp_user_prefs, site="aarlocal", user="justin@jumbo.live")
    t = blob(r)
    record("aarlocal prefs not a doubled-URL 404",
           "wp/v2/wp-json" not in t, "no doubled path in response")
    record("aarlocal prefs returned ok", r.get("ok") is not False or "prefs" in t,
           t[:90])

    print("\n=== 2. get_content on aarlocal ===")
    await call(S.switch_site, name="aarlocal")
    c = await call(S.get_content, slug="confirmation-concord", post_type="event")
    ct = blob(c)
    record("get_content not a doubled-URL 404", "wp/v2/wp-json" not in ct)
    record("contains 'Embassy Suites'", "Embassy Suites" in ct,
           "found" if "Embassy Suites" in ct else ct[:120])
    record("does NOT contain 'Under 65'", "Under 65" not in ct,
           "clean" if "Under 65" not in ct else "LEAKED from the u65 site")

    print("\n=== 3. same two against aaru65 (second explicit site) ===")
    r2 = await call(S.get_acp_user_prefs, site="aaru65", user="justin@jumbo.live")
    record("aaru65 prefs not a doubled-URL 404", "wp/v2/wp-json" not in blob(r2),
           blob(r2)[:90])

    print("\n=== 4. regression: wp/v2 tools must still work ===")
    w = await call(S.whoami)
    record("whoami", "wp/v2/wp-json" not in blob(w) and "error" not in blob(w).lower()[:40],
           blob(w)[:90])
    lu = await call(S.list_users, per_page=2)
    record("list_users", "wp/v2/wp-json" not in blob(lu), blob(lu)[:70])

    print("\n=== 5. negative: is the 404 hint now accurate? ===")
    import httpx
    req = httpx.Request("GET", f"{s.url}/wp-json/wp/v2/wp-json/jumbo-qa/v1/whoami")
    resp = httpx.Response(404, text='{"code":"rest_no_route"}', request=req)
    msg = S._err("whoami", httpx.HTTPStatusError("x", request=req, response=resp))
    record("doubled URL is called out as malformed", "MALFORMED URL" in msg)
    req2 = httpx.Request("GET", f"{s.url}/wp-json/jumbo-qa/v1/whoami")
    resp2 = httpx.Response(404, text='{"code":"rest_no_route"}', request=req2)
    msg2 = S._err("whoami", httpx.HTTPStatusError("x", request=req2, response=resp2))
    record("genuine 404 is NOT mislabelled malformed", "MALFORMED URL" not in msg2)

    n_fail = sum(1 for r, _, _ in results if r == FAIL)
    print(f"\n{'=' * 46}\n  {len(results) - n_fail} passed, {n_fail} failed")
    return 1 if n_fail else 0


if __name__ == "__main__":
    sys.exit(asyncio.run(main()))
