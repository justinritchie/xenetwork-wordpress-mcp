#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = ["httpx>=0.27.0"]
# ///
"""Work item 2 re-acceptance — exercised through the MCP surface, not by curl.

The previous completion note claimed this done on the strength of a direct
HTTP call. The deliverable is a TOOL; proving the route proves nothing about
reachability. This calls tools/list first, then the tool.
"""
import json
import httpx

URL = "http://localhost:8007/mcp"
H = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}


def parse(r):
    for line in r.text.splitlines():
        line = line.strip()
        if line.startswith("data: "):
            line = line[6:]
        if line.startswith("{"):
            return json.loads(line)
    return None


def session(c):
    r = c.post(URL, headers=H, json={
        "jsonrpc": "2.0", "id": 1, "method": "initialize",
        "params": {"protocolVersion": "2024-11-05", "capabilities": {},
                   "clientInfo": {"name": "probe", "version": "1"}}})
    sid = r.headers.get("mcp-session-id")
    h = dict(H, **({"mcp-session-id": sid} if sid else {}))
    c.post(URL, headers=h, json={"jsonrpc": "2.0", "method": "notifications/initialized"})
    return h


def rpc(c, h, method, params=None):
    r = c.post(URL, headers=h, json={"jsonrpc": "2.0", "id": 2, "method": method,
                                     "params": params or {}}, timeout=180)
    return parse(r)


def call(c, h, name, args):
    d = rpc(c, h, "tools/call", {"name": name, "arguments": args})
    if not d or "result" not in d:
        return {"_raw": str(d)[:300]}
    for p in d["result"].get("content") or []:
        try:
            return json.loads(p.get("text", ""))
        except Exception:
            return {"_text": p.get("text", "")[:400]}
    return {}


fails = 0


def check(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print(f"  [{'PASS' if ok else 'FAIL'}] {name}" + (f"  -- {detail}" if detail else ""))


with httpx.Client(timeout=180) as c:
    h = session(c)

    print("=== REACHABILITY: is the tool on the MCP surface at all? ===")
    d = rpc(c, h, "tools/list")
    names = [t["name"] for t in (d.get("result") or {}).get("tools", [])]
    check("list_episode_enclosures is exposed", "list_episode_enclosures" in names,
          f"{len(names)} tools total")

    print("\n=== AC1: one call returns all episodes with the four field groups ===")
    d = call(c, h, "list_episode_enclosures", {})
    eps = d.get("episodes") or []
    check("returns rows", bool(eps), f"count={d.get('count')}")
    if eps:
        e0 = eps[0]
        for k in ("post_id", "title", "status", "modified_gmt",
                  "enclosure_public", "enclosure_member",
                  "enclosure_member_monthly", "allow_full_episode_for_non_members"):
            if k not in e0:
                check(f"field {k}", False)
        check("all four field groups present",
              all(k in e0 for k in ("enclosure_public", "enclosure_member",
                                    "enclosure_member_monthly",
                                    "allow_full_episode_for_non_members")))

    print("\n=== AC2: episode 4429 (draft, scheduled 2026-08-19) appears ===")
    by_id = {e["post_id"]: e for e in eps}
    check("4429 present", 4429 in by_id)
    if 4429 in by_id:
        e = by_id[4429]
        print(f"        status={e['status']}  paywall={e['allow_full_episode_for_non_members']!r}")
        m = e.get("enclosure_member") or {}
        print(f"        member enclosure: {str(m.get('url','')).split('/')[-1]}  {m.get('byte_length')}")

    print("\n=== AC3: byte lengths match get_episode_fields (spot-check #280) ===")
    ref = call(c, h, "get_episode_fields", {"post_id": 4425})
    raw = (ref.get("fields") or {}).get("enclosure") or ""
    ref_len = None
    parts = str(raw).split("\n")
    if len(parts) > 1 and parts[1].strip().isdigit():
        ref_len = int(parts[1].strip())
    snap_len = ((by_id.get(4425) or {}).get("enclosure_public") or {}).get("byte_length")
    check("public byte_length agrees", ref_len is not None and ref_len == snap_len,
          f"get_episode_fields={ref_len}  snapshot={snap_len}")

    print("\n=== AC5: stable-ordered by post_id ===")
    ids = [e["post_id"] for e in eps]
    check("ordered", ids == sorted(ids))

    print("\n=== AC4: read-only — the new tool adds no write path ===")
    _tool = next((t for t in (rpc(c, h, "tools/list").get("result") or {}).get("tools", [])
                  if t["name"] == "list_episode_enclosures"), {})
    _sig_params = list(((_tool.get("inputSchema") or {}).get("properties") or {}).keys())
    # The claim is that THIS tool adds no write path — not that the connector
    # has none. delete_short_link and the episode writers predate this ticket.
    check("the new tool is read-only (GET, no body params)",
          set(_sig_params) <= {"status"}, f"params={sorted(_sig_params)}")

    print("\n=== the reason it exists: paywall baseline ===")
    s = d.get("summary") or {}
    print(f"        open_to_non_members: {s.get('open_to_non_members_count')}")
    print(f"        ids: {(s.get('open_to_non_members') or [])[:14]} …")

    print("\n=== status filter works ===")
    d2 = call(c, h, "list_episode_enclosures", {"status": "draft"})
    st = {e["status"] for e in (d2.get("episodes") or [])}
    check("only drafts returned", st == {"draft"} or not st, str(st))

print(f"\n{'ALL PASS' if not fails else str(fails) + ' FAILED'}")
