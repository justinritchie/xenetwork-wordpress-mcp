#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = ["httpx>=0.27.0,<1.0"]
# ///
"""Does the enclosure tool catch the failure it exists to catch?

The real-world mistake: copy another episode's enclosure, swap the URL, leave
the old byte length. Renders fine in wp-admin, mis-seeks in podcast apps.
All dry_run — nothing is written.
"""
import json
import httpx

URL = "http://localhost:8007/mcp"
H = {"Content-Type": "application/json", "Accept": "application/json, text/event-stream"}
EP266_PUBLIC = ("https://xepodcasts.com/assets/podcasts/energytransitionshow/"
                "ETS-266-globalelectricityreview2025lagniappe.mp3")
TRUE_LEN = 104141552


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

    print("=== THE BUG: right URL, WRONG byte length (copied from another episode) ===")
    d = call(c, h, "set_episode_enclosure", {
        "post_id": 4338, "tier": "public", "url": EP266_PUBLIC,
        "byte_length": 157188187,  # the member file's length, a plausible copy error
        "dry_run": True})
    check("refused", d.get("error") == "byte_length_mismatch", str(d.get("error")))
    check("reports both numbers", d.get("actual") == TRUE_LEN,
          f"supplied={d.get('supplied')} actual={d.get('actual')}")
    print(f"        hint: {str(d.get('hint'))[:90]}")

    print("\n=== correct length is accepted, tail carried ===")
    d = call(c, h, "set_episode_enclosure", {
        "post_id": 4338, "tier": "public", "url": EP266_PUBLIC,
        "byte_length": TRUE_LEN, "dry_run": True})
    check("accepted", d.get("ok") is True, str(d.get("error") or ""))
    check("verified against the real file", d.get("byte_length_verified") is True)
    check("settings tail carried, not invented",
          d.get("settings_tail") == "carried from existing", str(d.get("settings_tail")))
    check("nothing written", d.get("persisted") is False)

    print("\n=== omitting byte_length measures the file itself ===")
    d = call(c, h, "set_episode_enclosure", {
        "post_id": 4338, "tier": "public", "url": EP266_PUBLIC, "dry_run": True})
    check("auto-measured", d.get("byte_length") == TRUE_LEN, str(d.get("byte_length")))

    print("\n=== a URL that does not exist is reported, not silently written ===")
    d = call(c, h, "set_episode_enclosure", {
        "post_id": 4338, "tier": "public",
        "url": "https://xepodcasts.com/assets/podcasts/energytransitionshow/NOPE-does-not-exist.mp3",
        "dry_run": True})
    check("refused or flagged",
          d.get("error") == "no_byte_length" or d.get("head_note"),
          str(d.get("error") or d.get("head_note"))[:80])

    print("\n=== bad tier rejected ===")
    d = call(c, h, "set_episode_enclosure", {
        "post_id": 4338, "tier": "premium", "url": EP266_PUBLIC, "dry_run": True})
    check("rejected", d.get("ok") is False, str(d.get("error"))[:60])

print(f"\n{'ALL PASS' if not fails else str(fails) + ' FAILED'}")
