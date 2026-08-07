<?php
/**
 * Plugin Name: ETS MCP — Episode Fields (read + guarded write)
 * Description: Exposes per-episode postmeta over REST for the MCP connector, with a
 *              deliberately narrow write path.
 * Version:     1.1.0
 * Author:      XE Network
 *
 * WHY THIS EXISTS
 *   Every episode's distinguishing content — recording date, air date, guest bio,
 *   geek rating, the Blubrry/PowerPress enclosure — renders on the front end and is
 *   INVISIBLE to core REST. Verified 2026-08-07 against episode 4338 (#266):
 *   /wp/v2/episodes/4338?context=edit returns `acf: []` and meta keys
 *   ['_acf_changed','footnotes'] and nothing else, while post_content is 2,042
 *   characters of intro prose containing none of it.
 *
 *   Without this, create_episode would publish a post with a body and no player,
 *   no dates and no guest — broken in a way that looks like the tool worked.
 *
 * WHY NOT register_meta — following ets-mcp-editorial.php's reasoning
 *   register_meta() exposes protected keys but also opens a blanket WRITE path
 *   through the core REST meta handler, so anything that can PATCH /episodes/<id>
 *   can rewrite any registered key. That sibling plugin avoided it by using a
 *   get_callback with no setter.
 *
 *   Write is now in scope, so the answer is not to relax that — it is to keep the
 *   write OFF the post route entirely. Reads come from a computed field; writes go
 *   through one dedicated, guarded endpoint. There is still no way to modify meta
 *   by PATCHing the episode.
 *
 * NO HARDCODED FIELD LIST
 *   The real meta keys were not enumerable from outside, so this returns whatever
 *   the post actually carries rather than a guessed schema. That makes it correct
 *   on day one and stable as the theme adds fields.
 *
 * MULTISITE SCOPING
 *   mu-plugins load network-wide; this is only meaningful on the ETS subsite.
 *   Set ETS_MCP_BLOG_ID if the subsite id ever differs.
 *
 * PERMISSIONS
 *   Reads require edit_post on the specific post, matching the sibling plugin.
 *   Writes require edit_post AND survive the protected-ID guard below.
 *
 * INSTALL: drop into wp-content/mu-plugins/. Auto-activates.
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('ETS_MCP_EPISODE_FIELDS_VERSION')) {
    define('ETS_MCP_EPISODE_FIELDS_VERSION', '1.1.0');
}

/** Only run on the ETS subsite. */
function ets_mcp_ef_is_target_blog() {
    $target = defined('ETS_MCP_BLOG_ID') ? (int) ETS_MCP_BLOG_ID : 2;
    return !is_multisite() || (int) get_current_blog_id() === $target;
}

/**
 * Keys that must never be written through this endpoint.
 *
 * _edit_lock / _edit_last are WordPress's editor-collision bookkeeping: writing
 * them fakes "someone is editing this" and can lock a human out of their own post.
 * _wp_old_slug drives redirects. _thumbnail_id is set via featured_media on the
 * post route, so allowing it here would create two sources of truth.
 */
function ets_mcp_ef_denied_keys() {
    return array('_edit_lock', '_edit_last', '_wp_old_slug', '_thumbnail_id');
}

/**
 * Post IDs that may never be modified.
 *
 * House rule from the re-release workflow: the ORIGINAL post of a re-released
 * episode is never touched. Seeded from the ETS_PROTECTED_POST_IDS env var so the
 * list lives with the deployment, not in code.
 */
function ets_mcp_ef_protected_ids() {
    $raw = getenv('ETS_PROTECTED_POST_IDS');
    if (defined('ETS_PROTECTED_POST_IDS')) { $raw = ETS_PROTECTED_POST_IDS; }
    $ids = array(1538, 4241); // documented originals; env extends rather than replaces
    foreach (preg_split('/[\s,]+/', (string) $raw) as $p) {
        if ($p !== '' && ctype_digit(trim($p))) { $ids[] = (int) trim($p); }
    }
    return array_values(array_unique($ids));
}

/**
 * Find the ACF field key for a meta name by looking at any episode that has it.
 *
 * A field's key (field_5c06ab5fb383d) is a property of the field DEFINITION, so
 * it is identical on every post carrying that field. Borrowing it from a sibling
 * is therefore correct, not a guess.
 */
function ets_mcp_ef_find_field_key($meta_name) {
    global $wpdb;
    $key = $wpdb->get_var($wpdb->prepare(
        "SELECT pm.meta_value
           FROM {$wpdb->postmeta} pm
           JOIN {$wpdb->posts} p ON p.ID = pm.post_id
          WHERE pm.meta_key = %s
            AND pm.meta_value LIKE 'field\\_%%'
            AND p.post_type = 'xen_episodes'
          LIMIT 1",
        '_' . $meta_name
    ));
    return $key ?: null;
}

/** All meta on a post, with the noise stripped. */
function ets_mcp_ef_read($post_id) {
    $out = array();
    foreach (get_post_meta($post_id) as $key => $values) {
        if (in_array($key, ets_mcp_ef_denied_keys(), true)) { continue; }
        $value = (count($values) === 1)
            ? maybe_unserialize($values[0])
            : array_map('maybe_unserialize', $values);
        $out[$key] = $value;
    }
    ksort($out);
    return $out;
}

add_action('rest_api_init', function () {
    if (!ets_mcp_ef_is_target_blog()) { return; }

    // READ — computed field, no setter. Mirrors ets-mcp-editorial.php's shape so
    // the episode route stays un-PATCHable for meta.
    register_rest_field('xen_episodes', 'episode_fields', array(
        'schema'       => array(
            'description' => 'All non-internal postmeta for this episode. Read-only here; '
                           . 'write via POST /xen-ets/v1/episodes/<id>/fields.',
            'type'        => 'object',
            'context'     => array('edit'),
        ),
        'get_callback' => function ($post_arr) {
            $id = isset($post_arr['id']) ? (int) $post_arr['id'] : 0;
            if (!$id || !current_user_can('edit_post', $id)) { return null; }
            return ets_mcp_ef_read($id);
        },
    ));

    // WRITE — one dedicated, guarded route.
    register_rest_route('xen-ets/v1', '/episodes/(?P<id>\d+)/fields', array(
        'methods'             => 'POST',
        'permission_callback' => function ($req) {
            return current_user_can('edit_post', (int) $req['id']);
        },
        'args' => array(
            'fields'            => array('required' => true, 'type' => 'object',
                                         'description' => 'Meta key => value. Merge semantics.'),
            'dry_run'           => array('type' => 'boolean', 'default' => false),
            'confirm_protected' => array('type' => 'boolean', 'default' => false,
                                         'description' => 'Required to write a protected original.'),
        ),
        'callback' => 'ets_mcp_ef_write',
    ));

    // Diagnostic — what keys does this install actually use? Answers the question
    // that could not be answered from outside, so a caller never has to guess.
    register_rest_route('xen-ets/v1', '/episodes/(?P<id>\d+)/fields', array(
        'methods'             => 'GET',
        'permission_callback' => function ($req) {
            return current_user_can('edit_post', (int) $req['id']);
        },
        'callback' => function ($req) {
            $id = (int) $req['id'];
            if (get_post_type($id) !== 'xen_episodes') {
                return new WP_Error('not_an_episode', "Post {$id} is not a xen_episodes post.", array('status' => 400));
            }
            return array(
                'ok'        => true,
                'post_id'   => $id,
                'fields'    => ets_mcp_ef_read($id),
                'protected' => in_array($id, ets_mcp_ef_protected_ids(), true),
                '_meta'     => array('plugin' => 'ets-mcp-episode-fields',
                                     'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
            );
        },
    ));
});

function ets_mcp_ef_write($req) {
    $id      = (int) $req['id'];
    $fields  = (array) $req->get_param('fields');
    $dry_run = filter_var($req->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);
    $confirm = filter_var($req->get_param('confirm_protected'), FILTER_VALIDATE_BOOLEAN);

    if (get_post_type($id) !== 'xen_episodes') {
        return new WP_Error('not_an_episode', "Post {$id} is not a xen_episodes post.", array('status' => 400));
    }
    if (empty($fields)) {
        return new WP_Error('no_fields', 'fields must be a non-empty object.', array('status' => 400));
    }

    // Protected originals. Refused by default and logged loudly when overridden —
    // an override that leaves no trace is not a safeguard.
    $protected = ets_mcp_ef_protected_ids();
    if (in_array($id, $protected, true)) {
        if (!$confirm) {
            return new WP_Error('protected_post', "Post {$id} is a protected original and is never modified.", array(
                'status'        => 409,
                'protected_ids' => $protected,
                'hint'          => 'House rule from the re-release workflow. If you genuinely mean it, '
                                 . 'pass confirm_protected=true — the override is written to the error log.',
            ));
        }
        error_log(sprintf(
            '[ets-mcp-episode-fields] PROTECTED OVERRIDE: post %d written by user %d; keys: %s',
            $id, get_current_user_id(), implode(',', array_keys($fields))
        ));
    }

    $denied = array_values(array_intersect(array_keys($fields), ets_mcp_ef_denied_keys()));
    if (!empty($denied)) {
        return new WP_Error('denied_key', 'These keys cannot be written through this endpoint.', array(
            'status' => 400,
            'denied' => $denied,
            'hint'   => '_edit_lock/_edit_last are editor-collision bookkeeping; _thumbnail_id is set '
                      . 'via featured_media on the episode route.',
        ));
    }

    // ACF stores every field as a PAIR: `air_date` holds the value and
    // `_air_date` holds the field key (field_5c06ab5fb383d). The underscore row
    // binds value to definition — write the value alone on a NEW post and
    // wp-admin renders an empty field over populated data, exactly the failure
    // seen on the ACF options pages. Verified on episode 4338, where 15 such
    // pairs are present.
    //
    // Rather than make every caller remember, carry the companion across from
    // the source automatically when the target lacks one. Reported, not silent.
    $acf_companions = array();
    $acf_orphans    = array();
    foreach (array_keys($fields) as $k) {
        if (strpos($k, '_') === 0) { continue; }          // already a key row
        if (isset($fields['_' . $k]))  { continue; }      // caller supplied it
        $existing_key_row = get_post_meta($id, '_' . $k, true);
        if ($existing_key_row !== '') { continue; }       // target already bound

        // No binding on the target and none supplied. If a sibling episode has
        // one, reuse it — the field key is identical across posts for a field.
        $donor = ets_mcp_ef_find_field_key($k);
        if ($donor) {
            $fields['_' . $k] = $donor;
            $acf_companions[$k] = $donor;
        } else {
            $acf_orphans[] = $k;
        }
    }

    $before = array();
    foreach (array_keys($fields) as $k) {
        $before[$k] = get_post_meta($id, $k, true);
    }

    if ($dry_run) {
        return array(
            'ok' => true, 'dry_run' => true, 'persisted' => false,
            'post_id' => $id,
            'changes' => array('before' => $before, 'after' => $fields),
            'new_keys' => array_values(array_filter(array_keys($fields), function ($k) use ($before) {
                return $before[$k] === '';
            })),
            'note' => 'DRY RUN — nothing written.',
        );
    }

    foreach ($fields as $k => $v) {
        update_post_meta($id, $k, $v);
    }

    // Verify by re-read. update_post_meta returns false both when it fails AND
    // when the value was already identical, so its return value cannot be trusted
    // as a success signal.
    $after = array();
    $mismatched = array();
    foreach (array_keys($fields) as $k) {
        $after[$k] = get_post_meta($id, $k, true);
        if ($after[$k] != $fields[$k]) { $mismatched[] = $k; }
    }

    $result = array(
        'ok'        => empty($mismatched),
        'dry_run'   => false,
        'persisted' => true,
        'post_id'   => $id,
        'changes'   => array('before' => $before, 'after' => $after),
        'integrity' => array(
            'verified_by_reread'   => empty($mismatched),
            'mismatched_fields'    => $mismatched,
            'acf_keys_auto_bound'  => $acf_companions,
            'acf_unbound_fields'   => $acf_orphans,
        ),
        '_meta' => array('plugin' => 'ets-mcp-episode-fields',
                         'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
    );
    if (!empty($acf_orphans)) {
        $result['ATTENTION'] = 'No ACF field key could be found for: ' . implode(', ', $acf_orphans)
                             . '. These are written as plain meta and wp-admin may render the field '
                             . 'EMPTY over a populated database. Check wp-admin before publishing.';
    }
    if (!empty($mismatched)) {
        $result['ATTENTION'] = 'Re-read does not match what was sent for: '
                             . implode(', ', $mismatched) . '. Do not assume this landed.';
    }
    return $result;
}
