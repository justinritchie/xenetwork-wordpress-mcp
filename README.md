# xenetwork-wordpress-mcp

FastMCP servers for WordPress sites Justin works on, sharing one repo and launchd templating. Two of the servers target the [XE Network](https://xenetwork.org) WordPress multisite (network root + `/ets` subsite) with custom mu-plugin tools for s2Member / Formidable / Institutional CPT data. A third multi-site read-only server targets Jumbo platform client sites (opusadvisors.events, lcatt.opusadvisors.events, jumbo.live, etc.) using only WordPress core REST endpoints — used primarily for QA verification after Playwright registration tests.

The XE Network servers are **scoped to that specific WordPress install** — they depend on custom post types (`xen_episodes`, `xen_institutional`), custom mu-plugins shipped in this repo (`xen-s2member-rest.php`, `xen-formidable-rest.php`), and the URL Shortify + Formidable Forms plugins. The Jumbo server is intentionally generic and works against any vanilla WordPress install with Application Passwords enabled.

## Why this exists vs. existing WordPress MCPs

The popular `docdyhr/mcp-wordpress` server is great for generic WP admin but had three problems for this workflow:

- **Slow stdio init.** ~80s on cold start, which made Claude Desktop launches painful when the connector list was large.
- **Big tool surface (59 tools).** Almost all of them irrelevant for the support / member-management work I actually do — every tool burns prompt tokens whether or not it's used.
- **No coverage of the custom stuff.** s2Member subscription metadata, the `xen_institutional` registration pages, Formidable Forms entries on the network root (which uses a custom REST namespace because Formidable's `frm/v2` is only published on the subsite). All of those are where the actual work happens.

So: thin local servers, FastMCP-based, ~6-10 tools each, exposing exactly the surface I need. Same launchd-managed pattern as [craft-mcp](https://github.com/justinritchie/craft-mcp), survives reboots and Claude Desktop restarts, sub-second cold start.

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
├── wordpress-site.env.example             # template for single-site env (root, ets)
├── wordpress-jumbo.env.example            # template for multi-site env (jumbo)
├── templates/
│   └── launchd.plist.template
└── sites/
    ├── root/
    │   ├── server.py                      # XE Network root: users, s2Member, Formidable, IR
    │   └── mu-plugins/
    │       ├── xen-s2member-rest.php      # exposes wp_s2member_* meta via REST
    │       └── xen-formidable-rest.php    # custom xen/v1/frm/* read-only routes
    ├── ets/
    │   └── server.py                      # ETS subsite: episodes, posts, taxonomies,
    │                                      # Formidable v2, URL Shortify CRUD
    └── jumbo/
        └── server.py                      # Jumbo client sites (multi-site, read-only):
                                           # opusadvisors.events, lcatt, jumbo.live, etc.
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

### `jumbo` — Jumbo client sites (multi-site, read-only)

Targets multiple Jumbo platform WordPress installs through one connector. The active site is selectable at runtime via the `switch_site` tool, so a single Claude Desktop entry covers all client sites.

Tools:

- **Site management:** `list_sites`, `current_site`, `switch_site` — manage the active site context. Every tool result includes `_meta.{site, url}` so it's always clear which install answered.
- **Users (read-only):** `whoami`, `find_user_by_email`, `get_user`, `list_users`. Uses only WordPress core `/wp/v2/users` — no custom mu-plugins required, works against any vanilla WP install.

Primary use case: after a Playwright registration test creates a guest user (e.g. `guest40-x7k2m@jumbo.live`), call `find_user_by_email` to confirm the user actually landed in WP with the expected profile data + attachments. See [jumbo-dev/jumbo-qa](https://github.com/jumbodotlive/jumbo-docs) for the QA framework that drives this.

Read-only by design — no write tools. Cross-site write risk is zero by construction. Write tools can be added later if a use case appears.

Required on each target WordPress install:

- Application Passwords enabled (default in WP 5.6+ on HTTPS sites)
- One Application Password per site, exported to `WP_SITE_<NAME>_PASSWORD` in `~/.mcp-credentials/wordpress-jumbo.env`

## Setup

```bash
# Prerequisites
brew install uv
npm install -g mcp-remote   # only needed for Claude Desktop integration

# Clone this repo
git clone https://github.com/justinritchie/xenetwork-wordpress-mcp.git ~/justinritchie-mcp-servers/xenetwork-wordpress-mcp

# Drop credentials into ~/.mcp-credentials/wordpress-{root,ets,jumbo}.env
# (copy wordpress-site.env.example for root/ets — single-site shape;
#  copy wordpress-jumbo.env.example for jumbo — multi-site shape)
mkdir -p ~/.mcp-credentials && chmod 700 ~/.mcp-credentials
# ... create the env files, chmod 600 each ...

# Wire up sites as launchd services (run only the ones you need)
cd ~/justinritchie-mcp-servers/xenetwork-wordpress-mcp
./setup-site.sh root
./setup-site.sh ets
./setup-site.sh jumbo
```

Add to `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
"wordpress-root":  { "command": "/opt/homebrew/bin/mcp-remote", "args": ["http://localhost:8001/mcp"] },
"wordpress-ets":   { "command": "/opt/homebrew/bin/mcp-remote", "args": ["http://localhost:8002/mcp"] },
"wordpress-jumbo": { "command": "/opt/homebrew/bin/mcp-remote", "args": ["http://localhost:8003/mcp"] }
```

⌘Q + relaunch Claude Desktop.

See [SETUP_NEW_MACHINE.md](SETUP_NEW_MACHINE.md) for the full new-machine runbook.

## Architecture notes

All servers follow the same pattern:

- **HTTP Basic auth** with WordPress Application Passwords. For single-site servers (`root`, `ets`) one auth header is built once and reused. For the multi-site `jumbo` server, the active site's auth header is rebuilt per call from the in-memory site config.
- **Lifespan hook** does a `GET /wp-json/wp/v2/users/me` at boot to warm the httpx pool and confirm credentials. First user-facing tool call hits a hot connection.
- **streamable-http transport on a local port** (8001 root, 8002 ets, 8003 jumbo). `mcp-remote` bridges Claude Desktop → local HTTP. Servers run as `launchd` jobs with `RunAtLoad` + `KeepAlive` so they survive reboots and Claude restarts.
- **Configuration via env vars** sourced from `~/.mcp-credentials/wordpress-<site>.env`. Repo is fully sanitized — no credentials, no hardcoded user paths.

The single-site servers (root, ets) use `WP_BASE_URL` / `WP_USERNAME` / `WP_APP_PASSWORD`. The multi-site jumbo server uses `WP_SITE_<NAME>_URL` / `_USERNAME` / `_PASSWORD` triples — one set per client site, with `WP_DEFAULT_SITE` picking the active one at boot. `setup-site.sh` validates the right shape per site.

The custom mu-plugins (`sites/root/mu-plugins/*.php`) need to be deployed to the WordPress server at `wp-content/mu-plugins/` for the s2Member and root-Formidable tools to work. They expose read-only REST routes under `/xen/v1/...` that the MCP server consumes. They do not modify any WordPress data — the only write operation in the entire MCP surface is `duplicate_institutional`, which uses the standard `/wp/v2/xen_institutional` endpoint plus a small custom POST route in `xen-s2member-rest.php`. The Jumbo server has no write tools at all.

## Logs

```bash
tail -f /tmp/wordpress-mcp-root.out.log   /tmp/wordpress-mcp-root.err.log
tail -f /tmp/wordpress-mcp-ets.out.log    /tmp/wordpress-mcp-ets.err.log
tail -f /tmp/wordpress-mcp-jumbo.out.log  /tmp/wordpress-mcp-jumbo.err.log
```

## License

[MIT](LICENSE).
