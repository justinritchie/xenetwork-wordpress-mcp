#!/usr/bin/env python3
"""Live acceptance tests for POST /xen/v1/users/set-access.

Runs the ticket's five cases against xenetwork.org. Everything is dry-run
unless `--write` is passed, so this is safe to run repeatedly.

Credentials come from ~/.mcp-credentials/wordpress-root.env and are never
printed.

  python3 tests/acceptance_set_access.py          # dry run only
  python3 tests/acceptance_set_access.py --write  # performs the Secant demotion
"""
from __future__ import annotations

import base64
import json
import pathlib
import sys
import urllib.error
import urllib.request

ENV_FILE = pathlib.Path("~/.mcp-credentials/wordpress-root.env").expanduser()

# The live blocked case. jochem (11900) is deliberately absent — he is held for
# an individual-membership migration and must not be touched.
SECANT = [
    "marc.guilbert@secantfuel.com",       # 11901 secantfuel1mg
    "bruna.rego.de.vasconcelos@secantfuel.com",  # 11904 brunardv
    "veronique.ducharme@secantfuel.com",  # 11938 veronique
]
HELD = 11900
EOT = "1788016415"  # 2026-08-29 15:13 UTC — the term-end instant


def creds() -> tuple[str, str]:
    env = {}
    for raw in ENV_FILE.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, _, v = line.partition("=")
        k = k.removeprefix("export ").strip()
        v = v.strip()
        if len(v) >= 2 and v[0] == v[-1] and v[0] in ("'", '"'):
            v = v[1:-1]
        env[k] = v
    tok = base64.b64encode(
        f"{env['WP_USERNAME']}:{env['WP_APP_PASSWORD']}".encode()
    ).decode()
    return env["WP_BASE_URL"].rstrip("/"), tok


def call(base, tok, path, body=None, method="GET", params=None):
    import urllib.parse
    url = base + path + ("?" + urllib.parse.urlencode(params) if params else "")
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Authorization", f"Basic {tok}")
    req.add_header("Accept", "application/json")
    req.add_header("User-Agent", "wordpress-xenetwork-mcp/1.0")
    if data:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=180) as r:  # noqa: S310
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", "replace")
        try:
            return e.code, json.loads(raw)
        except Exception:
            return e.code, raw[:500]


def roster(base, tok):
    _, d = call(base, tok, "/wp-json/xen/v1/users/export-by-ccap",
                params={"ccap": "secant", "per_page": 50})
    return d


def show(users):
    for u in users:
        print(f"    {u.get('username','?'):<16} {u.get('old_level','?'):<18}"
              f" -> {u.get('new_level','?'):<18} eot {u.get('old_auto_eot')}"
              f" -> {u.get('new_auto_eot')}  ccaps {u.get('ccaps_before')}"
              f" -> {u.get('ccaps_after')}  [{u.get('status')}]")


def main() -> int:
    write = "--write" in sys.argv
    base, tok = creds()
    fails = 0

    def check(label, ok, detail=""):
        nonlocal fails
        print(f"{'✓' if ok else '✗'} {label}{('  ' + detail) if detail else ''}")
        if not ok:
            fails += 1

    print("=== BASELINE roster ===")
    r0 = roster(base, tok)
    print(f"    total={r0.get('total')} roles={r0.get('counts_by_role')}")
    print()

    # --- 4. bad input rejection (run FIRST — must write nothing) -----------
    print("=== TEST 4: bad input must abort the whole batch ===")
    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT, "level": "s2member_level99",
                       "reason": "acceptance: bad role", "dry_run": True})
    check("unknown role slug refused", st == 400 and d.get("error") == "batch_aborted",
          f"HTTP {st} {d.get('error')}")

    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT, "level": 2, "auto_eot": "not-a-date",
                       "reason": "acceptance: bad date", "dry_run": True})
    check("malformed date refused", st == 400 and d.get("error") == "batch_aborted",
          f"HTTP {st} {d.get('error')}")

    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT + ["nobody@nowhere.invalid"], "level": 2,
                       "reason": "acceptance: bad email", "dry_run": True})
    check("unresolvable email aborts batch", st == 400 and d.get("error") == "batch_aborted",
          f"HTTP {st} {d.get('error')}")

    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT, "level": 2, "max_batch": 2,
                       "reason": "acceptance: oversize", "dry_run": True})
    check("oversized batch refused not truncated",
          st == 400 and d.get("code") == "batch_too_large", f"HTTP {st} {d.get('code')}")

    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT, "level": 2, "dry_run": True})
    check("missing reason refused", st == 400, f"HTTP {st}")
    print()

    # --- 1. Secant demotion, dry run --------------------------------------
    print("=== TEST 1: Secant demotion L4 -> L2 + EOT (dry run) ===")
    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT, "level": 2, "auto_eot": EOT,
                       "reason": "Secant Fuel cancelled 2026-07-30, term ends 2026-08-29",
                       "dry_run": True})
    users = d.get("users") or []
    show(users)
    check("3 users planned", len(users) == 3, f"got {len(users)}")
    check("all would_change",
          all(u.get("status") == "would_change" for u in users))
    check("held user 11900 absent",
          all(u.get("user_id") != HELD for u in users))
    check("ccaps preserved in plan",
          all(u.get("ccaps_before") == u.get("ccaps_after") for u in users))
    print()

    if not write:
        print("DRY RUN ONLY — re-run with --write to apply test 1, then tests 2/3/5.")
        print(f"\n{'FAILED' if fails else 'All dry-run checks passed'} ({fails} failure(s))")
        return 1 if fails else 0

    # --- 1b. apply ---------------------------------------------------------
    print("=== TEST 1b: applying ===")
    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT, "level": 2, "auto_eot": EOT,
                       "reason": "Secant Fuel cancelled 2026-07-30, term ends 2026-08-29",
                       "dry_run": False})
    users = d.get("users") or []
    show(users)
    check("all applied", all(u.get("status") == "applied" for u in users),
          str(d.get("summary")))
    check("no warnings", not d.get("warnings"), str(d.get("warnings")))
    print()

    # --- 2. ccap survival regression — THE critical one -------------------
    print("=== TEST 2: ccap survival regression ===")
    r1 = roster(base, tok)
    roles = r1.get("counts_by_role") or {}
    print(f"    total={r1.get('total')} roles={roles}")
    check("roster still returns 4", r1.get("total") == 4, f"got {r1.get('total')}")
    check("1x L4 + 3x L2",
          roles.get("s2Member Level 4") == 1 and roles.get("s2Member Level 2") == 3,
          str(roles))
    print()

    # --- 3. idempotency ----------------------------------------------------
    print("=== TEST 3: idempotency ===")
    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"users": SECANT, "level": 2, "auto_eot": EOT,
                       "reason": "acceptance: idempotency re-run", "dry_run": False})
    users = d.get("users") or []
    check("all already_set", all(u.get("status") == "already_set" for u in users),
          str(d.get("summary")))
    print()

    # --- 5. EOT clear ------------------------------------------------------
    print("=== TEST 5: auto_eot='clear' on one user, then restore ===")
    _, before = call(base, tok, "/wp-json/wp/v2/users/11938",
                     params={"context": "edit"})
    last_before = (before.get("_all_meta_inspection") or {}).get(
        "wp_s2member_last_auto_eot_time")
    st, d = call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
                 body={"user": "11938", "auto_eot": "clear",
                       "reason": "acceptance: EOT clear", "dry_run": False})
    u = (d.get("users") or [{}])[0]
    check("eot cleared", u.get("new_auto_eot") in (None, "", "0"), str(u.get("new_auto_eot")))
    _, after = call(base, tok, "/wp-json/wp/v2/users/11938", params={"context": "edit"})
    last_after = (after.get("_all_meta_inspection") or {}).get(
        "wp_s2member_last_auto_eot_time")
    check("last_auto_eot_time undisturbed", last_before == last_after,
          f"{last_before!r} -> {last_after!r}")
    call(base, tok, "/wp-json/xen/v1/users/set-access", method="POST",
         body={"user": "11938", "auto_eot": EOT,
               "reason": "acceptance: restore after clear test", "dry_run": False})
    print("    restored EOT on 11938")
    print()

    print(f"{'FAILED' if fails else 'ALL ACCEPTANCE TESTS PASSED'} ({fails} failure(s))")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
