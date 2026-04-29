# xenetwork-wordpress-mcp

Two FastMCP servers for the [XE Network](https://xenetwork.org) WordPress multisite, sharing a single repo and launchd templating. One server targets the network root (users, memberships, the institutional registration system), the other targets the `/ets` subsite (Energy Transition Show episodes, forms, short links).

This is **scoped to the XE Network's specific WordPress install** — it depends on custom post types (`xen_episodes`, `xen_institutional`), custom mu-plugins shipped in this repo (`xen-s2member-rest.php`, `xen-formidable-rest.php`), and the URL Shortify + Formidable Forms plugins. If you're not running the same stack, the standard `wp/v2` REST tools will still work for any WordPress site, but the custom routes will 404.

## Why this exists vs. existing WordPress MCPs

The popular `docdyhr/mcp-wordpress` server is great for generic WP admin but had three problems for this workflow:

- **Slow stdio init.** ~80s on cold start, which made Claude Desktop launches painful when the connector list was large.
- **Big tool surface (59 tools).** Almost all of them irrelevant for the support / member-management work I actually do — every tool burns prompt tokens whether or not it's used.
- **No coverage of the custom stuff.** s2Member subscription metadata, the `xen_institutional` registration pages, Formidable Forms entries on the network root (which uses a custom REST namespace because Formidable's `frm/v2` is only published on the subsite). All of those are where the actual work happens.

So: two thin local servers, FastMCP-based, ~6-10 tools each, exposing exactly the surface I need. Same launchd-managed pattern as [craft-mcp](https://github.com/justinritchie/craft-mcp), survives reboots and Claude Desktop restarts, sub-second cold start.

## Layout

```
xenetwork-wordpress-mcp/
├── README.md                              # this file
├── SETUP_NEW_MACHINE.md                   # step-by-step new-machine runbook
├── LICENSE                                # MIT
├── .gitignore
├── _warmup-deps.py                        # uv pre-warm for fastmcp + httpx
├── start.sh <site>                        # source env file, run server
├── stop.sh <site>                         # kill the listener
├── setup-site.sh <site>                   # generate launchd plist + load it (idempotent)
├── uninstall-site.sh <site>               # bootout + remove plist
├── templates/
│   └── launchd.plist.template
└── sites/
    ├── root/
    │   ├── server.py                      # network root: users, s2Member, Formidable, IR
    │   └── mu-plugins/
    │       ├── xen-s2member-rest.php      # exposes wp_s2member_* meta via REST
    │       └── xen-formidable-rest.php    # custom xen/v1/frm/* read-only routes
    └── ets/
        └── server.py                      # ETS subsite: episodes, posts, taxonomies,
                                           # Formidable v2, URL Shortify CRUD
```

## What each site exposes

### `root` — xenetwork.org network root

Tools:

- `whoami` — health check (returns the WP user the credentials authenticate as).
- **Users:** `find_user_by_email`, `get_user`, `list_users` — read-only user lookups, with the s2Member subscription state (level, level1-4 expiry timestamps, custom field, login counts) merged in via the `xen-s2member-rest.php` mu-plugin. The standard `/wp/v2/users` doesn't expose any of that.
- **Institutional registrations** (`xen_institutional` CPT): `list_institutional`, `get_institutional`, `update_institutional`, `duplicate_institutional`. The duplicate tool clones a registration page (postmeta + taxonomy + content replacement + counter resets in one call).
- **Formidable Forms:** `list_forms`, `get_form`, `list_form_fields`, `list_form_entries`, `get_form_entry`. Uses the custom `xen/v1/frm/*` namespace defined in `xen-formidable-rest.php` — necessary because Formidable's native `frm/v2` namespace is only published on the ETS subsite, not the root.

Required plugins/mu-plugins on xenetwork.org:

- `s2Member` (active)
- `Formidable Forms` (active on root)
- `xen-s2member-rest.php` and `xen-formidable-rest.php` from `sites/root/mu-plugins/` deployed to `wp-content/mu-plugins/` on the WP server.

### `ets` — xenetwork.org/ets subsite

Tools:

- **Posts/pages/taxonomies:** `get_post`, `list_posts`, `get_page`, `list_pages`, `list_categories`, `list_tags`. Wraps `/wp/v2/*`.
- **Episodes** (`xen_episodes` CPT): `get_episode`, `list_episodes`.
- **Formidable Forms** (native `frm/v2`): `list_forms`, `get_form`, `list_form_fields`, `list_form_entries`, `get_form_entry`.
- **URL Shortify:** `list_short_links`, `get_short_link`, `find_short_link_for_url`, `create_short_link`, `update_short_link`, `delete_short_link`. The CRUD surface for the URL Shortify plugin's REST API.

Required plugins on the ETS subsite:

- `Formidable Forms` (active on subsite, native `frm/v2` REST enabled)
- `URL Shortify` (active)

## Setup

```bash
# Prerequisites
brew install uv
npm install -g mcp-remote   # only needed for Claude Desktop integration

# Clone this repo
git clone https://github.com/justinritchie/xenetwork-wordpress-mcp.git ~/justinritchie-mcp-servers/xenetwork-wordpress-mcp

# Drop credentials into ~/.mcp-credentials/wordpress-{root,ets}.env
# (copy wordpress-site.env.example for the format; one file per site)
mkdir -p ~/.mcp-credentials && chmod 700 ~/.mcp-credentials
# ... create the two env files, chmod 600 each ...

# Wire up both sites as launchd services
cd ~/justinritchie-mcp-servers/xenetwork-wordpress-mcp
./setup-site.sh root
./setup-site.sh ets
```

Add to `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
"wordpress-root": { "command": "/opt/homebrew/bin/mcp-remote", "args": ["http://localhost:8001/mcp"] },
"wordpress-ets":  { "command": "/opt/homebrew/bin/mcp-remote", "args": ["http://localhost:8002/mcp"] }
```

⌘Q + relaunch Claude Desktop.

See [SETUP_NEW_MACHINE.md](SETUP_NEW_MACHINE.md) for the full new-machine runbook.

## Architecture notes

Both servers follow the same pattern:

- **HTTP Basic auth** with `WP_USERNAME` + `WP_APP_PASSWORD` (Application Passwords from WP admin). Single httpx client with the auth header pre-baked, reused across all tool calls.
- **Lifespan hook** does a `GET /wp-json/` at boot to warm the httpx pool. First user-facing tool call hits a hot connection.
- **streamable-http transport on a local port** (8001 root, 8002 ets). `mcp-remote` bridges Claude Desktop → local HTTP. Servers run as `launchd` jobs with `RunAtLoad` + `KeepAlive` so they survive reboots and Claude restarts.
- **Configuration via env vars** sourced from `~/.mcp-credentials/wordpress-<site>.env`. Repo is fully sanitized — no credentials, no hardcoded user paths.

The custom mu-plugins (`sites/root/mu-plugins/*.php`) need to be deployed to the WordPress server at `wp-content/mu-plugins/` for the s2Member and root-Formidable tools to work. They expose read-only REST routes under `/xen/v1/...` that the MCP server consumes. They do not modify any WordPress data — the only write operation in the entire MCP surface is `duplicate_institutional`, which uses the standard `/wp/v2/xen_institutional` endpoint plus a small custom POST route in `xen-s2member-rest.php`.

## Logs

```bash
tail -f /tmp/wordpress-mcp-root.out.log   /tmp/wordpress-mcp-root.err.log
tail -f /tmp/wordpress-mcp-ets.out.log    /tmp/wordpress-mcp-ets.err.log
```

## License

[MIT](LICENSE).
