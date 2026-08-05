# ACF options pages — xenetwork.org root and /ets

Status: shipped 2026-08-05, mu-plugin `xen-s2member-rest.php` 1.1.0, verified on production.

---

## What exists

| | |
|---|---|
| Endpoint | `GET`/`POST` `/wp-json/xen/v1/acf-options` |
| MCP tools | `get_acf_options`, `set_acf_options` — on **both** `wordpress-xenetwork` and `wordpress-energytransitionshow` |
| Permission | `manage_options` |

**One mu-plugin file serves both blogs.** mu-plugins load network-wide and ACF options resolve
against the current blog's own options table, so the URL selects the blog and there is no `site`
parameter:

```
https://xenetwork.org/wp-json/xen/v1/acf-options          blog_id 1  (root)
https://xenetwork.org/ets/wp-json/xen/v1/acf-options      blog_id 2  (/ets)
```

Both blogs expose a page with the **same slug** — `site-general-settings` — holding **different
values**. Never assume a value read on one applies to the other.

Call with no `page` to list what a blog actually has, rather than guessing.

---

## Field inventory — /ets Announcement Bar

Resolved from ACF definitions, not from admin labels. The admin labels are not the field names.

| Field name | Type | Admin label | Value at time of writing |
|---|---|---|---|
| `bar_visibility_mode` | radio | Visibility Mode | `public` — **GUARDED** |
| `bar_background_color` | color_picker | Background Color | `#000` |
| `bar_text_color` | color_picker | Text Color | `#ffffff` — **see duplicate-name warning** |
| `bar_content` | text | Content | the announcement copy |
| `bar_show_button` | true_false | Show Button | `1` |
| `bar_button_text` | text | Button Text | `More Info` |
| `bar_button_position` | radio | Button Position | `left` (choices: `left`, `right`) |
| `bar_button_color` | color_picker | Button Color | `rgb(28,128,238)` |
| `bar_text_color` | color_picker | Text Color (button) | `#ffffff` — **duplicate** |
| `bar_button_link` | link | Button Link | object `{url, title, target}` |

`bar_visibility_mode` choices: `public`, `hidden` (Draft — hidden on frontend), `admin-only`.

---

## ⚠️ Two fields share the name `bar_text_color`

The bar's text colour and the button's text colour are **both** named `bar_text_color`. Different
keys (`field_67087cca08b73`, `field_67087d4008b77`), identical labels, same name.

Consequences:

- `get_field('bar_text_color')` can only return one of them
- a write by name is ambiguous — ACF resolves one and the other is unreachable
- both currently hold `#ffffff`, so nothing *looks* broken today

This is an ACF misconfiguration on the site, not a tooling gap. **Fix it in ACF** by renaming one
field; do not build a workaround. Until then, the button text colour cannot be set by name.

---

## Guarded fields

Some fields change what the public site renders with no other symptom, and an API read-back cannot
tell you the damage. Writing them requires naming them explicitly:

```json
{"page": "site-general-settings",
 "fields": {"bar_visibility_mode": "hidden"},
 "allow_guarded": ["bar_visibility_mode"]}
```

Currently guarded on /ets:

- **`bar_visibility_mode`** — `hidden` or `admin-only` removes the announcement banner from the
  live site. The write succeeds, the value reads back correctly, and the banner is simply gone.
- **`show_full_episode_feeds_to_non-members`** — gates paid episode content. Not flagged in the
  original ticket; caught by the guard heuristic. Revenue consequences.

The guard matches on name *and* label, so new fields matching `visib`, `_mode_`, `enabled`, or
`show_*` are caught automatically as the site grows.

---

## The integrity block — read it, don't trust `ok`

Every write is re-read through ACF and compared, and the `_options_<name>` shadow row is confirmed
present:

```json
"integrity": {
  "write_returned_true":    {"bar_content": true},
  "verified_by_reread":     true,
  "mismatched_fields":      [],
  "field_key_row_present":  {"bar_content": true}
}
```

**Why the shadow-row check matters.** ACF stores each field as two rows: `options_<name>` holding
the value and `_options_<name>` holding the field key. The underscore row binds value to
definition. Lose it and wp-admin renders an **empty field over a populated database** — and that
state reads back perfectly over the API. Writing through ACF by field key (which this does) is what
prevents it; the check is there to prove it every time rather than assume.

If `mismatched_fields` is non-empty or a `field_key_row_present` entry is `false`, an `ATTENTION`
key is raised to the top level. **Confirm in wp-admin, not just by API read-back** — that
divergence is the whole point of the check.

---

## Cache — intent is not reality

An ACF write does **not** flush page cache. Measured on this network at the time of writing:

```
ACF bar_content : "The Energy Transition Show continues, our next episode arrives in mid-August"
Rendered banner : "The Energy Transition Show continues, next episode arrives in mid-August"
```

The copy was already corrected in ACF and the public page was still serving the previous wording.
The three-surface drift this tooling exists to prevent had *already been fixed* in the database and
was invisible on the site.

After any write that renders publicly, verify with a cache-busting query string and purge if it
needs to be live now. `get_acf_options` reads intent; only fetching the rendered page reads reality.

---

## Safe testing

Do not iterate on the live Announcement Bar. The root blog's `mailchimp_form_shortcode` is empty
and non-rendering — a good throwaway target for write-path work. Restore it to `""` afterwards.

The full acceptance run used it: `dry_run` left it unchanged, the real write verified by re-read
with the field-key row present, an unknown field name was refused, and a guarded field was refused
without opt-in.

---

## Still open

1. **Rename one `bar_text_color`** in ACF so the button colour is addressable.
2. **Criterion 6 — visual wp-admin confirmation** after a programmatic write. The API integrity
   check passes and the shadow row is present, but the ticket rightly asks for a human to look at
   the admin screen. Not yet done.
3. **The banner copy sync** is a separate task per the ticket's non-goals. The tooling is ready;
   it most likely needs a cache purge rather than an edit.
