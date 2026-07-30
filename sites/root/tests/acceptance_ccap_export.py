#!/usr/bin/env python3
"""Live acceptance tests for /xen/v1/users/export-by-ccap.

Hits the endpoint directly so verification does not wait on a Claude Desktop
relaunch. Credentials are read from claude_desktop_config.json at runtime and
are never printed.

Run: python3 tests/acceptance_ccap_export.py
"""
from __future__ import annotations

import base64
import json
import pathlib
import sys
import urllib.error
import urllib.parse
import urllib.request

CONFIG = pathlib.Path(
    "~/Library/Application Support/Claude/claude_desktop_config.json"
).expanduser()

# (label, query params, expected_total or None, expected counts_by_role or None)
#
# CMU's expected values were established by this tool on 2026-07-30 (the ticket
# had no known-good for it) and are recorded here so a future regression shows
# up as a diff rather than as a plausible-looking number.
CASES = [
    ("Dartmouth (family)", {"ccap_prefix": "dart"}, 16,
     {"s2Member Level 4": 12, "Demoted": 4}),
    ("St. Lawrence", {"ccap": "stlawu"}, 55, {"s2Member Level 4": 55}),
    ("UVA", {"ccap": "uva"}, 414,
     {"s2Member Level 4": 122, "Demoted": 292}),
    ("Secant Fuel", {"ccap": "secant"}, 4, {"s2Member Level 4": 4}),
    ("Carnegie Mellon", {"ccap": "cmu"}, 42,
     {"s2Member Level 4": 38, "Demoted": 3, "s2Member Level 1": 1}),

    # --- filter behaviour ---------------------------------------------------
    ("UVA active only (include_demoted=false)",
     {"ccap": "uva", "include_demoted": "false"}, 122,
     {"s2Member Level 4": 122}),
    ("UVA role=s2member_level4", {"ccap": "uva", "role": "s2member_level4"},
     122, {"s2Member Level 4": 122}),
    ("UVA role=demoted", {"ccap": "uva", "role": "demoted"}, 292,
     {"Demoted": 292}),

    # The 57 orphaned rows must come back only when explicitly asked for.
    ("UVA incl. orphaned ccap residue",
     {"ccap": "uva", "include_no_site_role": "true"}, 471, None),
]


ENV_FILE = pathlib.Path("~/.mcp-credentials/wordpress-root.env").expanduser()


def _parse_env_file(path: pathlib.Path) -> dict[str, str]:
    out: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, val = line.partition("=")
        key = key.removeprefix("export ").strip()
        val = val.strip()
        if len(val) >= 2 and val[0] == val[-1] and val[0] in ("'", '"'):
            val = val[1:-1]
        out[key] = val
    return out


def load_creds() -> tuple[str, str, str]:
    """The root WP MCP runs under launchd via start.sh, which sources
    ~/.mcp-credentials/wordpress-root.env. The launch agent plist carries only
    PATH, and claude_desktop_config.json holds just the localhost:8006 URL —
    so the env file is the real source. Values are never printed."""
    if ENV_FILE.exists():
        env = _parse_env_file(ENV_FILE)
        if env.get("WP_APP_PASSWORD"):
            return (
                env["WP_BASE_URL"].rstrip("/"),
                env["WP_USERNAME"],
                env["WP_APP_PASSWORD"],
            )

    if CONFIG.exists():
        cfg = json.loads(CONFIG.read_text())
        for entry in cfg.get("mcpServers", {}).values():
            env = entry.get("env") or {}
            if env.get("WP_BASE_URL", "").rstrip("/").endswith("xenetwork.org"):
                return (
                    env["WP_BASE_URL"].rstrip("/"),
                    env["WP_USERNAME"],
                    env["WP_APP_PASSWORD"],
                )

    raise SystemExit(
        f"Could not find xenetwork.org WP credentials in {ENV_FILE} or "
        "claude_desktop_config.json"
    )


def call(base: str, token: str, params: dict) -> dict:
    q = urllib.parse.urlencode({"per_page": 0, **params})
    url = f"{base}/wp-json/xen/v1/users/export-by-ccap?{q}"
    req = urllib.request.Request(
        url,
        headers={
            "Authorization": f"Basic {token}",
            "Accept": "application/json",
            "User-Agent": "wordpress-xenetwork-mcp/1.0",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=180) as resp:  # noqa: S310
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        # The status alone is not a diagnosis. A WP REST error body says
        # whether this is rest_no_route (plugin not deployed), a permission
        # failure, or a PHP fatal — three very different problems.
        body = e.read().decode("utf-8", "replace")[:600]
        raise RuntimeError(f"HTTP {e.code} — {body}") from None


def main() -> int:
    base, user, pw = load_creds()
    token = base64.b64encode(f"{user}:{pw}".encode()).decode()
    del user, pw

    failures = 0
    for label, params, want_total, want_roles in CASES:
        try:
            d = call(base, token, params)
        except Exception as e:  # noqa: BLE001
            print(f"✗ {label}: request failed — {type(e).__name__}: {e}")
            failures += 1
            continue

        total = d.get("total")
        roles = d.get("counts_by_role") or {}
        ccaps = d.get("counts_by_ccap") or {}
        ver = d.get("verification") or {}
        warns = d.get("warnings") or []

        ok = True
        if want_total is not None and total != want_total:
            ok = False
        if want_roles is not None and roles != want_roles:
            ok = False
        if warns:
            ok = False

        mark = "✓" if ok else "✗"
        expect = "" if want_total is None else f" (expected {want_total})"
        print(f"{mark} {label}: total={total}{expect}")
        print(f"    roles:  {roles}")
        print(f"    ccaps:  {ccaps}")
        print(
            "    source: ccaps_meta={} capabilities={} union={} "
            "only_ccaps_meta={} only_caps={}".format(
                ver.get("matched_by_ccaps_meta"),
                ver.get("matched_by_capabilities"),
                ver.get("union_before_filters"),
                len(ver.get("only_in_ccaps_meta") or []),
                len(ver.get("only_in_capabilities") or []),
            )
        )
        print(
            "    excluded: no_site_role={} demoted={} by_role={} by_date={}"
            .format(
                ver.get("excluded_no_site_role"),
                ver.get("excluded_demoted"),
                ver.get("excluded_by_role"),
                ver.get("excluded_by_date"),
            )
        )
        if want_roles is not None and roles != want_roles:
            print(f"    ROLE MISMATCH — expected {want_roles}")
        for w in warns:
            print(f"    WARNING: {w}")
        if not ok:
            failures += 1
        print()

    print(f"{len(CASES) - failures}/{len(CASES)} cases passed.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
