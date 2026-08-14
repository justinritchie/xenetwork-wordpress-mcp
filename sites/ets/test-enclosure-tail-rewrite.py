#!/usr/bin/env python3
# /// script
# requires-python = ">=3.11"
# dependencies = ["fastmcp>=3.2,<4", "httpx>=0.27.0,<1.0", "mcp"]
# ///
"""Unit tests for the PowerPress serialized-tail rewrite. No network.

WHAT IS BEING PROVEN, and why it needs proving

  set_episode_enclosure carries the serialized PHP settings tail from another
  episode when this tier has none. Three keys in that tail are definitionally
  per-episode — duration, episode_title, episode_no — so carrying it verbatim
  carried the donor's values, and post 4447 announced itself as episode 266
  with 266's title and runtime on all three tiers. wp-admin looked right; every
  feed and podcast app was wrong.

  Rewriting them means editing PHP serialization by hand, where `s:<n>:` counts
  BYTES. A guest name with an accent makes character count and byte count
  disagree, PHP then refuses to unserialize, and PowerPress reads an EMPTY
  settings array — a worse outcome than the bug being fixed. Hence the
  non-ASCII case, and hence the PHP-side check: real php -r unserialize() is
  the only authority on whether the output is valid.

  The two fixture tails are the REAL values read off episodes 4338 and 4447 on
  2026-08-10, not hand-written approximations.

Run:
  cd sites/ets && uv run --with fastmcp --with httpx --with mcp \
    python test-enclosure-tail-rewrite.py
"""
import importlib.util
import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

os.environ.setdefault("WP_BASE_URL", "https://example.invalid")
os.environ.setdefault("WP_USERNAME", "x")
os.environ.setdefault("WP_APP_PASSWORD", "y")

_spec = importlib.util.spec_from_file_location(
    "ets_server", Path(__file__).with_name("server.py"))
m = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(m)

fails = 0


def check(name, ok, detail=""):
    global fails
    if not ok:
        fails += 1
    print(f"{'PASS' if ok else 'FAIL'}  {name}" + (f"  — {detail}" if detail else ""))


# Real tail from episode 4338 (#266), public tier. 684 bytes.
TAIL_4338 = (
    'a:20:{s:12:"value_pubkey";a:0:{}s:15:"value_lightning";a:0:{}'
    's:16:"value_custom_key";a:0:{}s:18:"value_custom_value";a:0:{}'
    's:11:"value_split";a:0:{}s:12:"person_names";a:1:{i:0;s:0:"";}'
    's:12:"person_roles";a:1:{i:0;s:0:"";}s:11:"person_urls";a:1:{i:0;s:0:"";}'
    's:9:"link_urls";a:1:{i:0;s:0:"";}s:16:"soundbite_starts";a:1:{i:0;s:0:"";}'
    's:19:"soundbite_durations";a:1:{i:0;s:0:"";}'
    's:16:"soundbite_titles";a:1:{i:0;s:0:"";}s:8:"duration";s:7:"1:47:56";'
    's:12:"set_duration";s:1:"0";s:8:"set_size";s:1:"0";s:8:"explicit";s:1:"0";'
    's:13:"episode_title";s:50:"Global Electricity Review 2025 (Lagniappe edition)";'
    's:10:"episode_no";s:3:"266";s:12:"episode_type";s:4:"full";'
    's:10:"podcast_id";s:0:"";}'
)

# Real tail from episode 4447 (#281), public tier, as corrected in wp-admin.
TAIL_4447 = TAIL_4338.replace(
    's:8:"duration";s:7:"1:47:56";', 's:8:"duration";s:7:"0:53:29";'
).replace(
    's:13:"episode_title";s:50:"Global Electricity Review 2025 (Lagniappe edition)";',
    's:13:"episode_title";s:45:"Revisiting the Jet Stream (Lagniappe edition)";'
).replace('s:10:"episode_no";s:3:"266";', 's:10:"episode_no";s:3:"281";')

PHP = shutil.which("php")


def php_unserialize(tail: str):
    """Authoritative check — does real PHP accept this string?"""
    if not PHP:
        return "NO_PHP"
    script = Path(__file__).parent / ".tail-check.php"
    script.write_text(
        "<?php\n$s = file_get_contents($argv[1]);\n"
        "$v = @unserialize($s);\n"
        "if ($v === false) { echo json_encode(['ok'=>false]); exit; }\n"
        "echo json_encode(['ok'=>true,'value'=>$v]);\n"
    )
    data = Path(__file__).parent / ".tail-check.txt"
    data.write_text(tail, encoding="utf-8")
    try:
        r = subprocess.run([PHP, str(script), str(data)], capture_output=True, text=True)
        return json.loads(r.stdout or "{}")
    finally:
        script.unlink(missing_ok=True)
        data.unlink(missing_ok=True)


# --- 1. round-trip: an untouched tail must survive parse+emit byte-identically
for label, tail in (("4338", TAIL_4338), ("4447", TAIL_4447)):
    parsed, end = m._php_unserialize(tail.encode())
    check(f"tail {label} parses to the end", end == len(tail.encode()),
          f"consumed {end} of {len(tail.encode())}")
    check(f"tail {label} round-trips byte-identically",
          m._php_serialize(parsed) == tail.encode())
    check(f"tail {label} has 20 keys", len(parsed) == 20, f"got {len(parsed)}")

# --- 2. PHP itself accepts the fixtures (proves they are real, not typos)
r = php_unserialize(TAIL_4338)
check("real PHP unserializes fixture 4338", r == "NO_PHP" or r.get("ok"),
      "php not installed — skipped" if r == "NO_PHP" else str(r)[:200])

# --- 3. the rewrite replaces exactly the three per-episode keys
new, report = m._rewrite_enclosure_tail(TAIL_4338, {
    "duration": "0:53:29", "episode_title": "Revisiting the Jet Stream",
    "episode_no": "281"})
check("rewrite reports success", report.get("rewritten") is True, str(report)[:200])
check("rewrite adds no keys", report.get("keys_added") == [], str(report.get("keys_added")))
parsed_new, _ = m._php_unserialize(new.encode())
parsed_old, _ = m._php_unserialize(TAIL_4338.encode())
check("duration replaced", parsed_new["duration"] == "0:53:29")
check("episode_title replaced", parsed_new["episode_title"] == "Revisiting the Jet Stream")
check("episode_no replaced", parsed_new["episode_no"] == "281")
untouched = {k: v for k, v in parsed_old.items() if k not in m._TAIL_PER_EPISODE_KEYS}
check("every other key is untouched",
      all(m._php_serialize(parsed_new[k]) == m._php_serialize(v)
          for k, v in untouched.items()))
check("length prefix recomputed for the new title",
      's:25:"Revisiting the Jet Stream";' in new,
      re.search(r's:13:"episode_title";s:\d+:"[^"]*"', new).group(0))
check("array count still 20", new.startswith("a:20:{"), new[:12])
r = php_unserialize(new)
check("real PHP unserializes the rewritten tail", r == "NO_PHP" or r.get("ok"), str(r)[:200])

# --- 4. THE BYTE-LENGTH TEST. Non-ASCII title: characters != bytes.
NON_ASCII = "Valérie Trouet — Ærø, Ringe & Résumés 🌍"
char_len = len(NON_ASCII)
byte_len = len(NON_ASCII.encode("utf-8"))
check("the non-ASCII fixture actually distinguishes chars from bytes",
      byte_len != char_len, f"{char_len} chars vs {byte_len} bytes")

new2, report2 = m._rewrite_enclosure_tail(
    TAIL_4338, {"duration": "1:02:03", "episode_title": NON_ASCII, "episode_no": "999"})
check("non-ASCII rewrite reports success", report2.get("rewritten") is True)
check("length prefix is the BYTE count, not the character count",
      f's:13:"episode_title";s:{byte_len}:"{NON_ASCII}";' in new2,
      re.search(r's:13:"episode_title";s:\d+:', new2).group(0)
      + f"  (chars={char_len}, bytes={byte_len})")
reparsed, end2 = m._php_unserialize(new2.encode())
check("non-ASCII tail re-parses to the end", end2 == len(new2.encode()))
check("non-ASCII title survives the round trip", reparsed["episode_title"] == NON_ASCII)
r = php_unserialize(new2)
check("real PHP unserializes the non-ASCII tail", r == "NO_PHP" or r.get("ok"), str(r)[:200])
if isinstance(r, dict) and r.get("ok"):
    check("real PHP reads back the exact non-ASCII title",
          r["value"]["episode_title"] == NON_ASCII, repr(r["value"]["episode_title"]))

# A character-count prefix must be REJECTED by PHP — this is the failure mode
# the byte arithmetic exists to avoid, so prove it is a real failure.
wrong = new2.replace(f's:{byte_len}:"{NON_ASCII}"', f's:{char_len}:"{NON_ASCII}"')
r = php_unserialize(wrong)
check("a character-count prefix IS rejected by PHP (the bug is real)",
      r == "NO_PHP" or r.get("ok") is False,
      "php not installed — skipped" if r == "NO_PHP" else str(r)[:160])

# --- 5. refusals
new3, report3 = m._rewrite_enclosure_tail("a:1:{s:3:\"abc\";", {"duration": "1:00:00"})
check("truncated tail is refused", new3 is None and "error" in report3, str(report3)[:160])
new4, report4 = m._rewrite_enclosure_tail("", {"duration": "1:00:00"})
check("empty tail is refused", new4 is None, str(report4)[:120])
new5, report5 = m._rewrite_enclosure_tail(TAIL_4338 + "junk", {"duration": "1:00:00"})
check("trailing junk is refused", new5 is None and report5.get("error") == "trailing_bytes",
      str(report5)[:160])
new6, report6 = m._rewrite_enclosure_tail(TAIL_4338, {})
check("no overrides leaves the tail exactly as-is",
      new6 == TAIL_4338 and report6.get("rewritten") is False)

# A key not already present is added, and the array count grows to match.
new7, report7 = m._rewrite_enclosure_tail(
    'a:1:{s:8:"duration";s:7:"1:00:00";}', {"episode_no": "5"})
check("adding a missing key bumps the array count", new7.startswith("a:2:{"), new7)
check("added key is reported", report7.get("keys_added") == ["episode_no"], str(report7))
r = php_unserialize(new7)
check("real PHP accepts the grown array", r == "NO_PHP" or r.get("ok"), str(r)[:160])

# --- 6. episode_no derivation (the rule that DOES hold) and the one that does not
for title, want in (
    ("[Episode #281] – Revisiting the Jet Stream", "281"),
    ("[Episode #42] - Can Renewables Power the World?", "42"),
    ("[ Episode # 100 ] – Teaching Energy Transition", "100"),
    ("[Duke Energy Week extra #1] - Energy and Environment Education", None),
    ("Some untitled draft", None),
    (None, None),
):
    check(f"episode_no from {title!r} -> {want!r}",
          m._episode_no_from_title(title) == want,
          repr(m._episode_no_from_title(title)))

# --- 7. hms formatting, against the four measured live durations
for secs, want in ((3209.1167346938773, "0:53:29"), (6476.408163265306, "1:47:56"),
                   (6527.869387755102, "1:48:48"), (0, "0:00:00"), (59.6, "0:01:00")):
    check(f"hms({secs}) -> {want}", m._seconds_to_hms(secs) == want,
          m._seconds_to_hms(secs))

print(f"\n{'ALL PASS' if not fails else str(fails) + ' FAILED'}"
      + ("" if PHP else "   (php not on PATH — PHP-side checks were skipped)"))
sys.exit(1 if fails else 0)
