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
