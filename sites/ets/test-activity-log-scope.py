#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = ["httpx>=0.27.0,<1.0"]
# ///
"""Work item 1 acceptance: network-scope reads on the activity log."""
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


def call(c, h, name, args):
    r = c.post(URL, headers=h, json={
        "jsonrpc": "2.0", "id": 2, "method": "tools/call",
        "params": {"name": name, "arguments": args}}, timeout=180)
    d = parse(r)
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

    print("=== AC1: 6060 visible with site_id='all' ===")
    d = call(c, h, "get_activity_log", {
        "event_ids": [6060], "since": "2026-08-06", "all_users": True,
        "site_id": "all", "limit": 60})
    n = d.get("count")
    check("returns rows", bool(n), f"count={n}")
    ev = d.get("events") or []
    if ev:
        check("user is justin", {e["username"] for e in ev} == {"justin"},
              str({e["username"] for e in ev}))
        check("from blog 1 (network scope)", 1 in {e["site_id"] for e in ev},
              f"site_ids={sorted({e['site_id'] for e in ev})}")
        print(f"        window: {ev[-1]['created_on_iso']} .. {ev[0]['created_on_iso']}")
        print(f"        ip(s):  {sorted({e['client_ip'] for e in ev})}")

    print("\n=== AC2: same call WITHOUT site_id still returns 0 (default unchanged) ===")
    d = call(c, h, "get_activity_log", {
        "event_ids": [6060], "since": "2026-08-06", "all_users": True, "limit": 60})
    check("count is 0", d.get("count") == 0, f"count={d.get('count')}")
    check("scope reported as subsite",
          (d.get("query_scope") or {}).get("scope") == "subsite",
          str((d.get("query_scope") or {}).get("scope")))

    print("\n=== AC3: summary includes 6060 network-wide, not subsite ===")
    a = call(c, h, "get_activity_log_summary",
             {"all_users": True, "site_id": "all", "since": "2026-08-06"})
    b = call(c, h, "get_activity_log_summary", {"all_users": True, "since": "2026-08-06"})
    ga = {g.get("alert_id") or g.get("event_id") for g in (a.get("groups") or [])}
    gb = {g.get("alert_id") or g.get("event_id") for g in (b.get("groups") or [])}
    check("6060 in network summary", 6060 in ga, f"network codes={sorted(x for x in ga if x)[:10]}")
    check("6060 NOT in subsite summary", 6060 not in gb)

    print("\n=== AC4: retention reports scope; network total >= subsite ===")
    rn = call(c, h, "get_activity_log_retention", {"site_id": "all"})
    rs = call(c, h, "get_activity_log_retention", {})
    tn, ts = rn.get("total_events"), rs.get("total_events")
    check("network total >= subsite total", (tn or 0) >= (ts or 0), f"{tn} >= {ts}")
    check("both report scope",
          (rn.get("query_scope") or {}).get("scope") == "network"
          and (rs.get("query_scope") or {}).get("scope") == "subsite",
          f"{(rn.get('query_scope') or {}).get('scope')} / {(rs.get('query_scope') or {}).get('scope')}")

    print("\n=== AC5: every response states its scope ===")
    for label, resp in (("events", d), ("summary", a), ("retention", rn)):
        check(f"{label} has query_scope", bool(resp.get("query_scope")))

    print("\n=== scope discipline: widest possible query is refused ===")
    d = call(c, h, "get_activity_log", {"all_users": True, "site_id": "all"})
    check("refused", d.get("ok") is False, str(d.get("reason"))[:70])

    print("\n=== AC6: no existing signature breaks (regression) ===")
    d = call(c, h, "get_activity_log", {"username": "chris", "since": "2026-08-01"})
    check("chris query still works", d.get("ok") is not False, f"count={d.get('count')}")
    check("still subsite-scoped by default",
          (d.get("query_scope") or {}).get("scope") == "subsite")

print(f"\n{'ALL PASS' if not fails else str(fails) + ' FAILED'}")
