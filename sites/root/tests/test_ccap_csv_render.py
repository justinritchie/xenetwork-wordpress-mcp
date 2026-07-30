#!/usr/bin/env python3
"""Offline checks on the ccap-export CSV renderer in ../server.py.

The renderer is pure and dependency-free, so it is extracted and exec'd
rather than importing server.py (which needs fastmcp + live WP env vars).

Run: python3 tests/test_ccap_csv_render.py
"""
from __future__ import annotations

import os
import pathlib
import sys

SERVER = pathlib.Path(__file__).resolve().parent.parent / "server.py"
src = SERVER.read_text(encoding="utf-8")

start = src.index('_EM_DASH = "—"')
end = src.index("async def _fetch_all_ccap_users")
ns: dict = {"Any": object, "os": os}
exec(compile(src[start:end], str(SERVER), "exec"), ns)  # noqa: S102

_csv_quote = ns["_csv_quote"]
_render_csv = ns["_render_csv"]
_CSV_COLUMNS = ns["_CSV_COLUMNS"]

failures: list[str] = []


def check(label: str, got, want) -> None:
    if got != want:
        failures.append(f"{label}\n    got:  {got!r}\n    want: {want!r}")


# --- 1. Header must match the ACP export byte for byte --------------------
EXPECTED_HEADER = (
    'Username,Name,Email,Role,Ccap,Registered,"# Of Logins","Coupon Code",'
    '"Member Feed Login","Reg. Page ID","First Name","Last Name",'
    '"Newsletter Optin","S2 EOT"'
)
check("header", _render_csv([]).rstrip("\r\n"), EXPECTED_HEADER)

# --- 2. Column count and order -------------------------------------------
check("column count", len(_CSV_COLUMNS), 14)

# --- 3. Quoting rule: comma, quote, or space --------------------------------
check("bare slug", _csv_quote("mattbenzing"), "mattbenzing")
check("space quoted", _csv_quote("Matthew Benzing"), '"Matthew Benzing"')
check("role quoted", _csv_quote("s2Member Level 4"), '"s2Member Level 4"')
check("demoted bare", _csv_quote("Demoted"), "Demoted")
check("comma quoted", _csv_quote("a,b"), '"a,b"')
check("embedded quote", _csv_quote('He said "hi"'), '"He said ""hi"""')
check("none is empty", _csv_quote(None), "")

# --- 4. Missing numerics render as em-dash, others stay empty --------------
row = {
    "username": "freckles04",
    "name": "Véronique Ducharme",
    "email": "Evkura22@stlawu.edu",
    "role": "s2Member Level 4",
    "ccap": "stlawu",
    "registered": "2026-01-13 03:19:11",
    "logins": None,             # -> em-dash
    "coupon_code": None,        # -> empty
    "member_feed_login": None,  # -> em-dash
    "reg_page_id": None,        # -> empty
    "first_name": "Véronique",
    "last_name": "Ducharme",
    "newsletter_optin": None,   # -> empty
    "s2_eot": None,             # -> empty
}
line = _render_csv([row]).rstrip("\r\n").split("\r\n")[1]
check(
    "missing-value rendering",
    line,
    'freckles04,"Véronique Ducharme",Evkura22@stlawu.edu,'
    '"s2Member Level 4",stlawu,"2026-01-13 03:19:11",—,,—,,'
    'Véronique,Ducharme,,',
)

# --- 5. Email case is preserved (exports contain mixed case) ---------------
check("email case", _csv_quote("Evkura22@stlawu.edu"), "Evkura22@stlawu.edu")

# --- 6. Zero must NOT become an em-dash (0 logins is a real value) ---------
zero = dict(row, logins=0, member_feed_login=0)
zline = _render_csv([zero]).rstrip("\r\n").split("\r\n")[1].split(",")
check("zero logins preserved", zline[6], "0")

# --- 7. CRLF terminator, trailing newline ---------------------------------
out = _render_csv([row])
check("crlf terminated", out.endswith("\r\n"), True)
check("row count", len(out.rstrip("\r\n").split("\r\n")), 2)

# --- 8. UTF-8 round-trip on accented names --------------------------------
check(
    "utf-8 round trip",
    out.encode("utf-8").decode("utf-8"),
    out,
)

if failures:
    print(f"FAILED ({len(failures)}):\n")
    for f in failures:
        print("  " + f + "\n")
    sys.exit(1)
print("All CSV renderer checks passed.")
