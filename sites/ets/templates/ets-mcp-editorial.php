<?php
/**
 * Plugin Name: ETS MCP — Editorial State (read-only)
 * Description: Exposes edit-lock, last-editor, and podcast enclosure meta for
 *              xen_episodes as a single computed REST field, `mcp_editorial`,
 *              so the wordpress-energytransitionshow MCP can see who has a post
 *              open in the block editor and read the audio enclosure without
 *              scraping rendered HTML.
 * Version:     1.0.0
 * Author:      XE Network
 *
 * WHY A COMPUTED FIELD RATHER THAN register_meta
 *   `_edit_lock`, `_edit_last` and the PowerPress keys are underscore-prefixed
 *   PROTECTED meta. WordPress does not return them over REST — verified against
 *   this install on 2026-07-28, where GET /wp/v2/episodes/4425?context=edit
 *   returned meta keys ['_acf_changed','footnotes'] and nothing else.
 *
 *   register_meta() would expose them, but it also opens a WRITE path through
 *   the REST meta handler. The MCP work this supports is explicitly read-only
 *   ("no write tools, do not add anything that can modify a post"), so a
 *   register_rest_field with only a get_callback is the correct shape: there is
 *   no setter to disable and no way to PATCH through it.
 *
 *   It also lets the raw `<timestamp>:<user_id>` lock string be resolved
 *   server-side into something a caller can act on, instead of every consumer
 *   re-implementing the parse.
 *
 * MULTISITE SCOPING
 *   mu-plugins load network-wide. This install is a multisite and the field is
 *   only meaningful on the ETS subsite, so the guard below restricts it to that
 *   blog. Set ETS_MCP_BLOG_ID if the subsite id ever differs.
 *
 * PERMISSIONS
 *   The callback returns data only to a user who can edit the specific post.
 *   An Application Password for a lesser role sees null rather than an error,
 *   which keeps this off the public API surface.
 *
 * INSTALL: drop into wp-content/mu-plugins/ on the ETS subsite. Auto-activates.
 */

if (!defined('ABSPATH')) {
    exit; // no direct access
}

if (!defined('ETS_MCP_BLOG_ID')) {
    define('ETS_MCP_BLOG_ID', 2); // ETS subsite of xenetwork.org
}

add_action('rest_api_init', function () {

    if (function_exists('get_current_blog_id') && is_multisite()
        && (int) get_current_blog_id() !== (int) ETS_MCP_BLOG_ID) {
        return;
    }

    register_rest_field('xen_episodes', 'mcp_editorial', array(
        'schema' => array(
            'description' => 'Read-only editorial state: edit lock, last editor, enclosure meta.',
            'type'        => 'object',
            'context'     => array('edit'),
        ),
        'get_callback' => function ($post_arr) {

            $post_id = isset($post_arr['id']) ? (int) $post_arr['id'] : 0;
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                return null;
            }

            $describe_user = function ($user_id) {
                $user_id = (int) $user_id;
                if ($user_id <= 0) {
                    return null;
                }
                $u = get_userdata($user_id);
                if (!$u) {
                    return array('user_id' => $user_id, 'resolved' => false);
                }
                return array(
                    'user_id'      => $user_id,
                    'resolved'     => true,
                    'display_name' => $u->display_name,
                    'user_login'   => $u->user_login,
                    'email'        => $u->user_email,
                );
            };

            // _edit_lock is "<unix_timestamp>:<user_id>". WordPress treats the
            // lock as stale after 150s by default (see wp_check_post_lock), so
            // report age and let the caller judge rather than guessing here.
            $lock_raw = get_post_meta($post_id, '_edit_lock', true);
            $lock = null;
            if (is_string($lock_raw) && strpos($lock_raw, ':') !== false) {
                list($ts, $uid) = array_pad(explode(':', $lock_raw, 2), 2, null);
                $ts = (int) $ts;
                $age = $ts ? (time() - $ts) : null;
                $lock = array(
                    'raw'            => $lock_raw,
                    'timestamp'      => $ts,
                    'timestamp_iso'  => $ts ? gmdate('c', $ts) : null,
                    'age_seconds'    => $age,
                    'considered_active' => ($age !== null && $age < 150),
                    'user'           => $describe_user($uid),
                );
            }

            // PowerPress stores the audio enclosure under a few possible keys
            // depending on version and settings. Return whatever is populated
            // rather than assuming one, and say which key it came from.
            $enclosure = array();
            $all_meta = get_post_meta($post_id);
            if (is_array($all_meta)) {
                foreach ($all_meta as $key => $vals) {
                    $k = strtolower($key);
                    if ($k === 'enclosure' || strpos($k, 'powerpress') !== false) {
                        $v = is_array($vals) ? reset($vals) : $vals;
                        $enclosure[$key] = maybe_unserialize($v);
                    }
                }
            }

            return array(
                'edit_lock'   => $lock,
                'edit_last'   => $describe_user(get_post_meta($post_id, '_edit_last', true)),
                'enclosure'   => $enclosure ? $enclosure : null,
                'server_time' => gmdate('c'),
            );
        },
        // No update_callback on purpose. Without one there is no write path
        // through this field at all — the read-only guarantee is structural,
        // not a matter of the MCP choosing not to call it.
    ));
});


/* =========================================================================
 * WP Activity Log (WSAL) — read-only REST access
 * =========================================================================
 *
 * WHY THIS LIVES HERE
 *   Folded into the same mu-plugin deliberately: the editorial field above
 *   already has to be deployed, and one file means one deploy rather than two
 *   chances to end up half-configured.
 *
 * WHY REST ROUTES RATHER THAN A FIELD
 *   WSAL keeps occurrences in its own database tables, not post meta, so there
 *   is nothing for register_rest_field to hang off. These are custom routes
 *   that run parameterised SELECTs.
 *
 * READ-ONLY IS ENFORCED, NOT PROMISED
 *   Every statement below is a SELECT built through $wpdb->prepare. There is no
 *   INSERT, UPDATE, DELETE or prune path anywhere in this file, and the routes
 *   register only GET methods. This log is evidence in a live matter; it must
 *   not be writable from here even by accident.
 *
 * THE MULTISITE TRAP
 *   WSAL writes network-wide to the BASE prefix — wp_wsal_occurrences — and
 *   identifies the subsite with a site_id column. Querying wp_2_wsal_occurrences
 *   returns nothing and looks exactly like an empty log, which is the failure
 *   most likely to waste an afternoon. $wpdb->base_prefix is used throughout.
 *
 * SCHEMA DRIFT
 *   WSAL 4.x denormalised username / client_ip / object / event_type / severity
 *   onto the occurrences row. Older versions keep them as key/value rows in
 *   wsal_metadata joined on occurrence_id. The layout is DETECTED at runtime
 *   rather than assumed, because guessing wrong yields empty columns instead of
 *   an error, which is worse.
 */

if (!defined('ETS_MCP_NS')) {
    define('ETS_MCP_NS', 'ets-mcp/v1');
}

/** Occurrences/metadata table names on the base prefix. */
function ets_mcp_wsal_tables() {
    global $wpdb;
    return array(
        'occ'  => $wpdb->base_prefix . 'wsal_occurrences',
        'meta' => $wpdb->base_prefix . 'wsal_metadata',
    );
}

function ets_mcp_wsal_table_exists($table) {
    global $wpdb;
    return (bool) $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $table)
    );
}

/** Column list for the occurrences table, used for layout detection. */
function ets_mcp_wsal_columns() {
    global $wpdb;
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    $t = ets_mcp_wsal_tables();
    if (!ets_mcp_wsal_table_exists($t['occ'])) {
        $cols = array();
        return $cols;
    }
    // Table name comes from $wpdb->base_prefix, not user input, so it cannot be
    // parameterised and does not need to be.
    $cols = $wpdb->get_col('DESCRIBE ' . $t['occ'], 0);
    if (!is_array($cols)) {
        $cols = array();
    }
    return $cols;
}

/** Accept an ISO date, a date string, or a unix timestamp; return float ts. */
function ets_mcp_to_ts($value, $default = null) {
    if ($value === null || $value === '') {
        return $default;
    }
    if (is_numeric($value)) {
        return (float) $value;
    }
    $t = strtotime($value . (preg_match('/\d:\d/', $value) ? '' : ' 00:00:00 UTC'));
    return $t ? (float) $t : $default;
}

/** Metadata rows for a set of occurrence ids, grouped by occurrence. */
function ets_mcp_wsal_meta_for($ids) {
    global $wpdb;
    $out = array();
    if (empty($ids)) {
        return $out;
    }
    $t = ets_mcp_wsal_tables();
    if (!ets_mcp_wsal_table_exists($t['meta'])) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT occurrence_id, name, value FROM {$t['meta']} WHERE occurrence_id IN ($ph)",
            $ids
        ),
        ARRAY_A
    );
    foreach ((array) $rows as $r) {
        $oid = (int) $r['occurrence_id'];
        $val = maybe_unserialize($r['value']);
        $out[$oid][$r['name']] = $val;
    }
    return $out;
}

/**
 * Human label / message for an event code.
 *
 * WSAL stores a template plus placeholders, not a finished string. Rendering is
 * delegated to WSAL's own classes when the plugin is loaded; if it is not, the
 * template and the metadata dict are returned as-is. Hand-rolling placeholder
 * substitution would silently drift from WSAL's own output.
 */
function ets_mcp_wsal_alert_info($alert_id) {
    static $cache = array();
    if (isset($cache[$alert_id])) {
        return $cache[$alert_id];
    }

    $info = array('label' => null, 'template' => null, 'rendered' => false,
                  'via' => null);

    // WSAL has moved its alert registry more than once across majors, so try
    // the known shapes in order rather than betting on one. Deliberately NO
    // hardcoded fallback table of code->label: a wrong label in an evidence
    // tool is worse than an honest null, because it reads as authoritative.
    $attempts = array();

    try {
        // 4.x / 5.x: singleton with an alert manager.
        if (class_exists('WpSecurityAuditLog')) {
            $attempts[] = 'WpSecurityAuditLog exists';
            $wsal = null;
            if (method_exists('WpSecurityAuditLog', 'GetInstance')) {
                $wsal = WpSecurityAuditLog::GetInstance();
                $attempts[] = 'GetInstance ok';
            } elseif (function_exists('wsal_freemius')) {
                $attempts[] = 'freemius present but no GetInstance';
            }
            if ($wsal) {
                $mgr = null;
                foreach (array('alerts', 'alert_manager') as $prop) {
                    if (isset($wsal->$prop)) {
                        $mgr = $wsal->$prop;
                        $attempts[] = "manager via ->$prop";
                        break;
                    }
                }
                if ($mgr) {
                    foreach (array('GetAlert', 'get_alert') as $meth) {
                        if (method_exists($mgr, $meth)) {
                            $a = $mgr->$meth($alert_id);
                            if ($a) {
                                $info['label'] = isset($a->desc) ? $a->desc
                                    : (isset($a->title) ? $a->title : null);
                                $info['template'] = isset($a->mesg) ? $a->mesg : null;
                                $info['rendered'] = true;
                                $info['via'] = "manager::$meth";
                            }
                            $attempts[] = "$meth called";
                            break;
                        }
                    }
                }
            }
        } else {
            $attempts[] = 'WpSecurityAuditLog NOT loaded in this request';
        }

        // 5.x namespaced static registry.
        if (!$info['rendered']) {
            foreach (array('\WSAL\Helpers\Woocommerce_Helper',
                           '\WSAL\Controllers\Alert',
                           '\WSAL\Entities\Metadata_Entity') as $cls) {
                if (class_exists($cls)) {
                    $attempts[] = "namespaced class present: $cls";
                }
            }
            if (class_exists('\WSAL\Controllers\Alert')) {
                $c = '\WSAL\Controllers\Alert';
                foreach (array('get_alert', 'get_alert_details') as $meth) {
                    if (method_exists($c, $meth)) {
                        $a = $c::$meth($alert_id);
                        if ($a) {
                            $info['label'] = is_array($a)
                                ? (isset($a['desc']) ? $a['desc'] : (isset($a['title']) ? $a['title'] : null))
                                : (isset($a->desc) ? $a->desc : null);
                            $info['template'] = is_array($a)
                                ? (isset($a['mesg']) ? $a['mesg'] : null)
                                : (isset($a->mesg) ? $a->mesg : null);
                            $info['rendered'] = (bool) $info['label'];
                            $info['via'] = "Alert::$meth";
                        }
                        $attempts[] = "Alert::$meth called";
                        break;
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        $info['error'] = $e->getMessage();
    }

    $info['lookup_attempts'] = $attempts;
    $cache[$alert_id] = $info;
    return $info;
}

/**
 * One-shot diagnostic: what WSAL surface is actually reachable from a REST
 * request? Labels came back null on first deploy and guessing why would just
 * cost another deploy cycle, so report the facts instead.
 */
function ets_mcp_wsal_integration_report() {
    $classes = array(
        'WpSecurityAuditLog', 'WSAL_AlertManager', 'WSAL_Alert',
        '\WSAL\Controllers\Alert', '\WSAL\Entities\Occurrences_Entity',
    );
    $out = array('active_plugins_hint' => null, 'classes' => array(),
                 'functions' => array());
    foreach ($classes as $c) {
        $out['classes'][$c] = class_exists($c);
    }
    foreach (array('wsal_freemius', 'wsal_init') as $f) {
        $out['functions'][$f] = function_exists($f);
    }
    if (function_exists('get_option')) {
        $ap = get_option('active_plugins', array());
        $net = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
        $all = array_merge((array) $ap, $net);
        $out['active_plugins_hint'] = array_values(array_filter($all, function ($p) {
            return stripos($p, 'wsal') !== false || stripos($p, 'activity-log') !== false;
        }));
    }
    return $out;
}

add_action('rest_api_init', function () {

    if (function_exists('get_current_blog_id') && is_multisite()
        && (int) get_current_blog_id() !== (int) ETS_MCP_BLOG_ID) {
        return;
    }

    $can_read = function () {
        return current_user_can('manage_options');
    };

    // ---- 6. events -------------------------------------------------------
    register_rest_route(ETS_MCP_NS, '/activity-log', array(
        'methods'             => 'GET',
        'permission_callback' => $can_read,
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $t = ets_mcp_wsal_tables();
            if (!ets_mcp_wsal_table_exists($t['occ'])) {
                return new WP_REST_Response(array(
                    'ok' => false,
                    'reason' => 'WSAL occurrences table not found at ' . $t['occ']
                        . '. On multisite WSAL uses the BASE prefix, not the '
                        . 'subsite prefix — if this path looks wrong, that is why.',
                ), 200);
            }

            $cols = ets_mcp_wsal_columns();
            $denorm = in_array('username', $cols, true);
            $blog_id = (int) ETS_MCP_BLOG_ID;

            $where = array();
            $args = array();

            if (in_array('site_id', $cols, true)) {
                $where[] = 'site_id = %d';
                $args[] = $blog_id;
            }

            $since = ets_mcp_to_ts($req->get_param('since'));
            if ($since !== null) {
                $where[] = 'created_on >= %f';
                $args[] = $since;
            }
            $until = ets_mcp_to_ts($req->get_param('until'));
            if ($until !== null) {
                $where[] = 'created_on <= %f';
                $args[] = $until;
            }

            // Exclusion-based by default. An allowlist would hide the events
            // nobody thought to anticipate, which are the ones that matter.
            $excl = $req->get_param('exclude_event_ids');
            if (is_string($excl)) {
                $excl = array_filter(array_map('intval', explode(',', $excl)));
            }
            if (is_array($excl) && count($excl)) {
                $where[] = 'alert_id NOT IN (' . implode(',', array_fill(0, count($excl), '%d')) . ')';
                $args = array_merge($args, array_map('intval', $excl));
            }

            $only = $req->get_param('event_ids');
            if (is_string($only)) {
                $only = array_filter(array_map('intval', explode(',', $only)));
            }
            if (is_array($only) && count($only)) {
                $where[] = 'alert_id IN (' . implode(',', array_fill(0, count($only), '%d')) . ')';
                $args = array_merge($args, array_map('intval', $only));
            }

            $username = $req->get_param('username');
            $username_filtered_in_sql = false;
            if ($username && $denorm) {
                $where[] = 'username = %s';
                $args[] = $username;
                $username_filtered_in_sql = true;
            }

            $limit = (int) ($req->get_param('limit') ?: 100);
            $limit = max(1, min($limit, 500));

            $sql = "SELECT * FROM {$t['occ']}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY created_on DESC LIMIT %d';
            $args[] = $limit;

            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            $rows = is_array($rows) ? $rows : array();

            $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
            $meta = ets_mcp_wsal_meta_for($ids);

            $events = array();
            foreach ($rows as $r) {
                $oid = (int) $r['id'];
                $m = isset($meta[$oid]) ? $meta[$oid] : array();
                $alert_id = (int) $r['alert_id'];
                $info = ets_mcp_wsal_alert_info($alert_id);

                $uname = $denorm && !empty($r['username'])
                    ? $r['username']
                    : (isset($m['Username']) ? $m['Username'] : (isset($m['CurrentUserID']) ? $m['CurrentUserID'] : null));

                // Older layouts keep username only in metadata, so SQL could not
                // filter it. Filter here instead of silently ignoring the arg.
                if ($username && !$username_filtered_in_sql) {
                    if (strcasecmp((string) $uname, (string) $username) !== 0) {
                        continue;
                    }
                }

                $ts = isset($r['created_on']) ? (float) $r['created_on'] : null;
                $events[] = array(
                    'occurrence_id' => $oid,
                    'alert_id'      => $alert_id,
                    'event_label'   => $info['label'],
                    'message_template' => $info['template'],
                    'message_rendered_by_wsal' => $info['rendered'],
                    'severity'      => $denorm && isset($r['severity']) ? $r['severity'] : (isset($m['Severity']) ? $m['Severity'] : null),
                    'created_on'    => $ts,
                    'created_on_iso' => $ts ? gmdate('c', (int) $ts) : null,
                    'username'      => $uname,
                    'user_roles'    => $denorm && isset($r['user_roles']) ? maybe_unserialize($r['user_roles']) : (isset($m['CurrentUserRoles']) ? $m['CurrentUserRoles'] : null),
                    'client_ip'     => $denorm && isset($r['client_ip']) ? $r['client_ip'] : (isset($m['ClientIP']) ? $m['ClientIP'] : null),
                    'object'        => $denorm && isset($r['object']) ? $r['object'] : (isset($m['Object']) ? $m['Object'] : null),
                    'event_type'    => $denorm && isset($r['event_type']) ? $r['event_type'] : (isset($m['EventType']) ? $m['EventType'] : null),
                    'site_id'       => isset($r['site_id']) ? (int) $r['site_id'] : null,
                    'metadata'      => $m,
                );
            }

            return new WP_REST_Response(array(
                'ok'            => true,
                'schema_layout' => $denorm ? 'denormalized (WSAL 4.x+)' : 'metadata-joined (pre-4.x)',
                'table'         => $t['occ'],
                'blog_id'       => $blog_id,
                'excluded_event_ids' => array_values((array) $excl),
                'count'         => count($events),
                'events'        => $events,
            ), 200);
        },
    ));

    // ---- 7. summary ------------------------------------------------------
    register_rest_route(ETS_MCP_NS, '/activity-log/summary', array(
        'methods'             => 'GET',
        'permission_callback' => $can_read,
        'callback'            => function (WP_REST_Request $req) {
            global $wpdb;
            $t = ets_mcp_wsal_tables();
            if (!ets_mcp_wsal_table_exists($t['occ'])) {
                return new WP_REST_Response(array(
                    'ok' => false, 'reason' => 'WSAL occurrences table not found at ' . $t['occ'],
                ), 200);
            }
            $cols = ets_mcp_wsal_columns();
            $denorm = in_array('username', $cols, true);

            $where = array();
            $args = array();
            if (in_array('site_id', $cols, true)) {
                $where[] = 'site_id = %d';
                $args[] = (int) ETS_MCP_BLOG_ID;
            }
            $since = ets_mcp_to_ts($req->get_param('since'));
            if ($since !== null) {
                $where[] = 'created_on >= %f';
                $args[] = $since;
            }
            $username = $req->get_param('username');
            if ($username && $denorm) {
                $where[] = 'username = %s';
                $args[] = $username;
            }

            $sql = "SELECT alert_id, COUNT(*) AS n, MIN(created_on) AS first_seen, "
                 . "MAX(created_on) AS last_seen FROM {$t['occ']}";
            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' GROUP BY alert_id ORDER BY n DESC';

            $rows = $args
                ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A)
                : $wpdb->get_results($sql, ARRAY_A);

            $out = array();
            foreach ((array) $rows as $r) {
                $info = ets_mcp_wsal_alert_info((int) $r['alert_id']);
                $out[] = array(
                    'alert_id'    => (int) $r['alert_id'],
                    'event_label' => $info['label'],
                    'count'       => (int) $r['n'],
                    'first_seen_iso' => $r['first_seen'] ? gmdate('c', (int) $r['first_seen']) : null,
                    'last_seen_iso'  => $r['last_seen'] ? gmdate('c', (int) $r['last_seen']) : null,
                );
            }
            return new WP_REST_Response(array(
                'ok' => true,
                'note' => 'Includes ALL event codes, failed logins among them — '
                        . 'the point of a summary is that high-volume noise '
                        . 'appears as one number instead of hundreds of rows.',
                'groups' => $out,
                'total_events' => array_sum(array_column($out, 'count')),
            ), 200);
        },
    ));

    // ---- 8. retention ----------------------------------------------------
    register_rest_route(ETS_MCP_NS, '/activity-log/retention', array(
        'methods'             => 'GET',
        'permission_callback' => $can_read,
        'callback'            => function () {
            global $wpdb;
            $t = ets_mcp_wsal_tables();
            $exists = ets_mcp_wsal_table_exists($t['occ']);

            // WSAL settings live in options prefixed wsal_; on multisite they
            // may be network options. Check both rather than assuming.
            $get = function ($key) {
                $v = is_multisite() ? get_site_option($key, null) : null;
                if ($v === null || $v === false) {
                    $v = get_option($key, null);
                }
                return $v;
            };

            $settings = array(
                'pruning_date_enabled'  => $get('wsal_pruning-date-e'),
                'pruning_date'          => $get('wsal_pruning-date'),
                'pruning_limit_enabled' => $get('wsal_pruning-limit-e'),
                'pruning_limit'         => $get('wsal_pruning-limit'),
            );

            $oldest = $newest = $total = null;
            if ($exists) {
                $cols = ets_mcp_wsal_columns();
                if (in_array('site_id', $cols, true)) {
                    $row = $wpdb->get_row($wpdb->prepare(
                        "SELECT MIN(created_on) AS oldest, MAX(created_on) AS newest, COUNT(*) AS n "
                        . "FROM {$t['occ']} WHERE site_id = %d", (int) ETS_MCP_BLOG_ID
                    ), ARRAY_A);
                } else {
                    $row = $wpdb->get_row(
                        "SELECT MIN(created_on) AS oldest, MAX(created_on) AS newest, COUNT(*) AS n FROM {$t['occ']}",
                        ARRAY_A
                    );
                }
                if ($row) {
                    $oldest = $row['oldest'] ? (float) $row['oldest'] : null;
                    $newest = $row['newest'] ? (float) $row['newest'] : null;
                    $total  = (int) $row['n'];
                }
            }

            $pruning_on = !empty($settings['pruning_date_enabled'])
                       || !empty($settings['pruning_limit_enabled']);

            return new WP_REST_Response(array(
                'ok' => true,
                'table_exists' => $exists,
                'settings' => $settings,
                'pruning_active' => $pruning_on,
                'oldest_event_iso' => $oldest ? gmdate('c', (int) $oldest) : null,
                'newest_event_iso' => $newest ? gmdate('c', (int) $newest) : null,
                'history_days' => ($oldest && $newest)
                    ? round(($newest - $oldest) / 86400, 1) : null,
                'total_events' => $total,
                'wsal_integration' => ets_mcp_wsal_integration_report(),
                'warning' => $pruning_on
                    ? 'PRUNING IS ACTIVE. History is being deleted on a rolling '
                      . 'basis and anything already pruned is unrecoverable. If '
                      . 'this log is evidence, widen the retention window now — '
                      . 'that is more urgent than any tooling built on top of it.'
                    : null,
            ), 200);
        },
    ));
});
