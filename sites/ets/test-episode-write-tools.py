#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = ["httpx>=0.27.0"]
# ///
"""Acceptance tests for the ETS episode write tools.

Creates a real DRAFT on the live site (the ticket's test 1), updates it,
confirms the protected-original refusal, then cleans up after itself.
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


def call(c, h, name, args):
    r = c.post(URL, headers=h, json={
        "jsonrpc": "2.0", "id": 2, "method": "tools/call",
        "params": {"name": name, "arguments": args}}, timeout=120)
    d = parse(r)
    if not d or "result" not in d:
        return {"_raw": str(d)[:300]}
    for p in d["result"].get("content") or []:
        try:
            return json.loads(p.get("text", ""))
        except Exception:
            return {"_text": p.get("text", "")[:300]}
    return {}


fails = 0


def check(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print(f"  [{'PASS' if ok else 'FAIL'}] {name}" + (f"  -- {detail}" if detail else ""))


with httpx.Client(timeout=120) as c:
    h = session(c)

    print("=== read a real episode's fields (4338 / #266) ===")
    d = call(c, h, "get_episode_fields", {"post_id": 4338})
    f = d.get("fields") or {}
    check("returns field dict", len(f) > 20, f"{len(f)} keys")
    check("paywall flag present", "allow_full_episode_for_non_members" in f,
          repr(f.get("allow_full_episode_for_non_members")))
    check("audio enclosure present", "enclosure" in f)
    sample = {k: f.get(k) for k in ("air_date", "recording_date", "geek_rating")}
    print(f"        sample: {sample}")

    print("\n=== ACCEPTANCE 3: update against protected original 1538 refuses ===")
    d = call(c, h, "update_episode", {"id": 1538, "excerpt": "probe"})
    check("refused", d.get("ok") is False and d.get("error") == "protected_post",
          f"error={d.get('error')}")
    check("names the protected ids", bool((d.get("data") or {}).get("protected_ids")),
          str((d.get("data") or {}).get("protected_ids")))

    print("\n=== ACCEPTANCE 1: create_episode as DRAFT with fields ===")
    d = call(c, h, "create_episode", {
        "title": "[MCP PROBE] delete me",
        "content": "<p>probe body</p>",
        "excerpt": "probe",
        "slug": "mcp-probe-delete-me",
        "categories": [111, 1145],
        "fields": {"geek_rating": "7", "air_date": "20260807",
                   "iawp_total_views": "999"},  # must be stripped
    })
    new_id = d.get("id")
    check("created", bool(new_id), f"id={new_id}")
    check("status is draft, not published", d.get("status") == "draft", str(d.get("status")))
    check("do-not-copy key stripped", "iawp_total_views" in (d.get("fields_stripped") or []),
          str(d.get("fields_stripped")))
    fr = d.get("fields_result") or {}
    check("fields wrote and verified", fr.get("ok") is True,
          f"integrity={fr.get('integrity')}")
    ab = (fr.get("integrity") or {}).get("acf_keys_auto_bound") or {}
    check("ACF companion keys auto-bound", bool(ab), str(ab))

    if new_id:
        print("\n=== ACCEPTANCE 2: partial update leaves other fields alone ===")
        d2 = call(c, h, "update_episode", {"id": new_id, "excerpt": "probe FIXED"})
        check("update ok", d2.get("ok") is True, str(d2.get("updated")))
        d3 = call(c, h, "get_episode_fields", {"post_id": new_id})
        f3 = d3.get("fields") or {}
        check("geek_rating untouched by the body update", f3.get("geek_rating") == "7",
              repr(f3.get("geek_rating")))
        check("air_date untouched", f3.get("air_date") == "20260807", repr(f3.get("air_date")))
        check("_air_date companion present", bool(f3.get("_air_date")), repr(f3.get("_air_date")))

        print("\n=== CLEANUP: trash the probe post ===")
        d4 = call(c, h, "update_episode", {"id": new_id, "status": "trash"})
        check("probe trashed", d4.get("ok") is True, f"status={d4.get('status')}")
        print(f"        probe id {new_id} — verify it is gone in wp-admin")

print(f"\n{'ALL PASS' if not fails else str(fails) + ' FAILED'}")
