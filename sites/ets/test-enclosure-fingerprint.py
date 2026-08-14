#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = ["httpx>=0.27.0,<1.0"]
# ///
"""Work item 3a acceptance — get_enclosure_fingerprint.

AC2 requires proving the hash moves on a real change. Test subject is post 757
"[eLab Extra #6] - TRANSCRIPT TESTING", a draft since 2023 and explicitly a
test post. NOT 4429, NOT anything published, per the ticket.

The flip is Yes -> No, i.e. toward the MORE restrictive value, and is reverted
at the end. A draft is not publicly readable either way.
"""
import json
import httpx

URL = "http://localhost:8007/mcp"
H = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
SUBJECT = 757

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


def rpc(c, h, m, p=None):
    r = c.post(URL, headers=h, json={"jsonrpc": "2.0", "id": 2, "method": m,
                                     "params": p or {}}, timeout=180)
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

    d = rpc(c, h, "tools/list")
    names = [t["name"] for t in (d.get("result") or {}).get("tools", [])]
    check("get_enclosure_fingerprint exposed", "get_enclosure_fingerprint" in names,
          f"{len(names)} tools")

    print("\n=== AC1 + AC3: identical across runs, small payload ===")
    a = call(c, h, "get_enclosure_fingerprint", {})
    b = call(c, h, "get_enclosure_fingerprint", {})
    check("two calls identical", a.get("fingerprint") == b.get("fingerprint"),
          str(a.get("fingerprint"))[:24] + "…")
    size = len(json.dumps(a))
    check("payload is small", size < 4096, f"{size} bytes vs ~213,000 for the full snapshot")

    print("\n=== AC4: open_to_non_members matches the full snapshot ===")
    full = call(c, h, "list_episode_enclosures", {})
    check("counts agree",
          a.get("open_to_non_members_count") == (full.get("summary") or {}).get("open_to_non_members_count"),
          f"fingerprint={a.get('open_to_non_members_count')} snapshot={(full.get('summary') or {}).get('open_to_non_members_count')}")
    check("id lists agree",
          a.get("open_to_non_members") == (full.get("summary") or {}).get("open_to_non_members"))

    print(f"\n=== AC2: change one paywall flag on dormant draft {SUBJECT}, hash must move ===")
    before = call(c, h, "get_episode_fields", {"post_id": SUBJECT})
    orig = (before.get("fields") or {}).get("allow_full_episode_for_non_members")
    print(f"        original value: {orig!r}")
    check("subject is a safe test post", orig is not None)

    flipped = "No" if str(orig).lower() == "yes" else "Yes"
    w = call(c, h, "set_episode_fields", {
        "post_id": SUBJECT,
        "fields": {"allow_full_episode_for_non_members": flipped}})
    check("write landed", w.get("ok") is True, f"-> {flipped!r}")

    changed = call(c, h, "get_enclosure_fingerprint", {})
    check("FINGERPRINT CHANGED", changed.get("fingerprint") != a.get("fingerprint"),
          f"{str(a.get('fingerprint'))[:16]}… -> {str(changed.get('fingerprint'))[:16]}…")
    check("open_to_non_members_count moved too",
          changed.get("open_to_non_members_count") != a.get("open_to_non_members_count"),
          f"{a.get('open_to_non_members_count')} -> {changed.get('open_to_non_members_count')}")

    print("\n=== REVERT ===")
    rv = call(c, h, "set_episode_fields", {
        "post_id": SUBJECT,
        "fields": {"allow_full_episode_for_non_members": orig}})
    check("reverted", rv.get("ok") is True, f"-> {orig!r}")

    back = call(c, h, "get_enclosure_fingerprint", {})
    check("fingerprint returns to the original hash",
          back.get("fingerprint") == a.get("fingerprint"),
          f"{str(back.get('fingerprint'))[:16]}…")
    check("open count restored",
          back.get("open_to_non_members_count") == a.get("open_to_non_members_count"),
          str(back.get("open_to_non_members_count")))

print(f"\n{'ALL PASS' if not fails else str(fails) + ' FAILED'}")
