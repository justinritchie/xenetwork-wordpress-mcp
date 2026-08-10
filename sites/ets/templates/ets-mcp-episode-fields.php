<?php
/**
 * Plugin Name: ETS MCP — Episode Fields (read + guarded write)
 * Description: Exposes per-episode postmeta and non-REST taxonomies over REST for the
 *              MCP connector, with a deliberately narrow write path.
 * Version:     1.5.0
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
    define('ETS_MCP_EPISODE_FIELDS_VERSION', '1.5.0');
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

/**
 * Every taxonomy an episode can carry — INCLUDING the ones core REST hides.
 *
 * WHY THIS IS NOT A HARDCODED LIST OF ['xen_guests']
 *   Measured 2026-08-10 on this install: /wp/v2/taxonomies returns only
 *   category, post_tag, nav_menu and wp_pattern_category, and
 *   /wp/v2/types/xen_episodes reports taxonomies ['category','post_tag'].
 *   Both of those endpoints filter by show_in_rest, so a taxonomy registered
 *   WITHOUT it is invisible to every core route — it does not 404, it simply
 *   never appears. xen_guests is exactly that case: 261 terms, a public archive
 *   at /ets/guests/<slug>/, a Yoast sitemap, and no REST surface whatsoever.
 *
 *   get_object_taxonomies() reads the registry directly and so sees all of them.
 *   Deriving the list means a taxonomy added later is covered without another
 *   round of plugin surgery — the same reasoning behind the exclusion-based
 *   filtering elsewhere in this MCP.
 */
function ets_mcp_ef_episode_taxonomies() {
    return array_values(get_object_taxonomies('xen_episodes'));
}

/** Compress a term to the fields a caller actually needs to pick one. */
function ets_mcp_ef_term_row($t) {
    return array(
        'id'          => (int) $t->term_id,
        'name'        => $t->name,
        'slug'        => $t->slug,
        'count'       => (int) $t->count,
        'taxonomy'    => $t->taxonomy,
        'description' => $t->description !== '' ? $t->description : null,
        'link'        => get_term_link($t) instanceof WP_Error ? null : get_term_link($t),
    );
}

/**
 * Server-side protection for original posts, on the CORE route too.
 *
 * The guard in the fields endpoint only covers meta. Without this, a PATCH to
 * /wp/v2/episodes/1538 could still rewrite the title, body or status of an
 * original — the exact thing the house rule forbids — and a client-side check
 * in the MCP tool is advisory, not enforcement. Anything holding the
 * Application Password could bypass it.
 *
 * Enforcing here makes the rule true regardless of who is calling.
 * X-ETS-Confirm-Protected: yes is the deliberate override, mirroring
 * confirm_protected on the fields route.
 */
add_filter('rest_pre_insert_xen_episodes', function ($prepared, $request) {
    if (!ets_mcp_ef_is_target_blog()) { return $prepared; }

    $id = isset($request['id']) ? (int) $request['id'] : 0;
    if (!$id || !in_array($id, ets_mcp_ef_protected_ids(), true)) {
        return $prepared;
    }

    if (strtolower((string) $request->get_header('X-ETS-Confirm-Protected')) === 'yes') {
        error_log(sprintf(
            '[ets-mcp-episode-fields] PROTECTED OVERRIDE (core route): post %d by user %d',
            $id, get_current_user_id()
        ));
        return $prepared;
    }

    return new WP_Error('protected_post', "Post {$id} is a protected original and is never modified.", array(
        'status'        => 409,
        'protected_ids' => ets_mcp_ef_protected_ids(),
        'hint'          => 'House rule from the re-release workflow. To override, send header '
                         . 'X-ETS-Confirm-Protected: yes — the override is written to the error log.',
    ));
}, 10, 2);

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

    // BULK SNAPSHOT — every episode's enclosures and paywall flag, one call.
    //
    // WHY THIS IS NOT get_episode_fields IN A LOOP
    // 295 episodes means 295 round trips, which is not viable on a schedule.
    // More importantly this is a MONITORING baseline: feedwatch hashes the two
    // podcast feeds, which only sees what reaches a feed. Two things it misses:
    //
    //   allow_full_episode_for_non_members — flip it to 'Yes' and a paid episode
    //   is published to non-members. Arguably worse than an audio swap and
    //   materially easier to do. Nothing watched it.
    //
    //   the member tiers — _member:enclosure and _member-monthly:enclosure never
    //   appear in the public feed at all.
    //
    // Both live in postmeta, and postmeta writes do not reliably bump
    // post_modified, so get_content_fingerprint is not a dependable tripwire for
    // either. Event 2054 now records the change, but that is detection after the
    // fact — this is the baseline you can diff.
    //
    // Ordered by post_id and free of the serialized settings tail so the output
    // diffs cleanly.
    register_rest_route('xen-ets/v1', '/episodes/enclosures', array(
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'args' => array(
            'status' => array('type' => 'string',
                              'description' => "Comma-separated post statuses. Default: all of "
                                             . "publish,draft,future,pending,private. A SCHEDULED "
                                             . "episode is exactly the interesting case, so drafts "
                                             . "and future posts are included by default."),
        ),
        'callback' => 'ets_mcp_ef_enclosure_snapshot',
    ));

    // FINGERPRINT — the cheap tripwire for an hourly monitor.
    //
    // list_episode_enclosures returns ~213 KB for 295 episodes. That is the
    // right payload for a human investigation and the wrong one for a task that
    // runs 15 times a day: every run would blow the tool-output limit and spill
    // to a temp file, burning context on the fourteen runs where nothing moved.
    //
    // Same idiom get_content_fingerprint already documents — store the hash,
    // compare next run, stop if unchanged — applied to postmeta.
    //
    // The hash covers ONLY the state worth alerting on: post_id, the three
    // enclosure URLs, the three byte lengths, and the paywall flag. Nothing
    // volatile, so two unchanged runs are byte-identical. modified_gmt is
    // reported but deliberately NOT hashed: postmeta writes do not reliably bump
    // it, so including it would make the hash both noisy and unreliable in the
    // same stroke.
    register_rest_route('xen-ets/v1', '/episodes/enclosures/fingerprint', array(
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'args' => array(
            'status' => array('type' => 'string',
                              'description' => 'Same default as the full snapshot.'),
        ),
        'callback' => 'ets_mcp_ef_enclosure_fingerprint',
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

    // TAXONOMY DISCOVERY + TERM LISTING.
    //
    // xen_guests has no core REST route because it is registered without
    // show_in_rest, so before this there was NO way to learn a guest's term id
    // from outside wp-admin — which made assigning one impossible from the MCP
    // even though the write itself is one wp_set_object_terms() call.
    //
    // The route is keyed on taxonomy rather than hardcoded to xen_guests so the
    // next custom taxonomy needs a parameter, not another endpoint. It refuses
    // any taxonomy not registered for xen_episodes, which keeps it from becoming
    // a general-purpose term enumerator over the whole multisite.
    register_rest_route('xen-ets/v1', '/terms/(?P<taxonomy>[A-Za-z0-9_-]+)', array(
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'args' => array(
            'search'   => array('type' => 'string',
                                'description' => 'LIKE match against term name AND slug.'),
            'per_page' => array('type' => 'integer', 'default' => 50),
            'page'     => array('type' => 'integer', 'default' => 1),
            'include'  => array('type' => 'string',
                                'description' => 'Comma-separated term ids — resolve known ids to names.'),
        ),
        'callback' => 'ets_mcp_ef_list_terms',
    ));

    // WHICH TERMS ARE ACTUALLY ON THIS POST.
    //
    // core REST answers this for category and post_tag only. class_list happens
    // to leak the xen_guests SLUGS (as `xen_guests-<slug>` entries), which is
    // enough to know a guest is attached but not enough to act on — you cannot
    // reassign, dedupe or compare against guest_link without the ids.
    register_rest_route('xen-ets/v1', '/episodes/(?P<id>\d+)/terms', array(
        'methods'             => 'GET',
        'permission_callback' => function ($req) {
            return current_user_can('edit_post', (int) $req['id']);
        },
        'callback' => 'ets_mcp_ef_read_terms',
    ));

    // ASSIGN TERMS.
    //
    // Kept off the core post route for the same reason meta is: register_taxonomy
    // with show_in_rest would open a blanket write path on /wp/v2/episodes/<id>
    // AND add a taxonomy panel to the block editor for every editor on the site.
    // A dedicated endpoint changes nothing a human sees.
    //
    // NOTE FOR MONITORS: wp_set_object_terms() does not bump post_modified, so a
    // term change is invisible to get_content_fingerprint — same blind spot the
    // enclosure fingerprint exists to cover for postmeta.
    register_rest_route('xen-ets/v1', '/episodes/(?P<id>\d+)/terms', array(
        'methods'             => 'POST',
        'permission_callback' => function ($req) {
            return current_user_can('edit_post', (int) $req['id']);
        },
        'args' => array(
            'terms'             => array('required' => true, 'type' => 'object',
                                         'description' => 'taxonomy => array of term ids.'),
            'append'            => array('type' => 'boolean', 'default' => false,
                                         'description' => 'true adds to the existing terms; '
                                                        . 'false (default) REPLACES them.'),
            'dry_run'           => array('type' => 'boolean', 'default' => false),
            'confirm_protected' => array('type' => 'boolean', 'default' => false),
        ),
        'callback' => 'ets_mcp_ef_write_terms',
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

/**
 * Split a PowerPress enclosure into the parts worth diffing.
 *
 * Stored as four newline-delimited parts: url, byte length, MIME type, and a
 * serialized PHP settings array. The tail is bulky and irrelevant to a diff, so
 * it is deliberately dropped — but its PRESENCE is reported, because an
 * enclosure without one may not render a player.
 */
function ets_mcp_ef_split_enclosure($raw) {
    if (!is_string($raw) || $raw === '') { return null; }
    $p = explode("\n", $raw);
    return array(
        'url'          => isset($p[0]) ? trim($p[0]) : null,
        'byte_length'  => (isset($p[1]) && is_numeric(trim($p[1]))) ? (int) trim($p[1]) : null,
        'mime_type'    => isset($p[2]) ? trim($p[2]) : null,
        'has_settings' => isset($p[3]) && trim($p[3]) !== '',
    );
}

function ets_mcp_ef_enclosure_snapshot($req) {
    $status = $req->get_param('status');
    $statuses = $status
        ? array_values(array_filter(array_map('trim', explode(',', (string) $status))))
        : array('publish', 'draft', 'future', 'pending', 'private');

    $q = new WP_Query(array(
        'post_type'      => 'xen_episodes',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ));

    $rows = array();
    foreach ($q->posts as $pid) {
        $pid = (int) $pid;
        $paywall = get_post_meta($pid, 'allow_full_episode_for_non_members', true);
        $row = array(
            'post_id'      => $pid,
            'title'        => get_the_title($pid),
            'status'       => get_post_status($pid),
            'modified_gmt' => get_post_field('post_modified_gmt', $pid),
            'enclosure_public'         => ets_mcp_ef_split_enclosure(get_post_meta($pid, 'enclosure', true)),
            'enclosure_member'         => ets_mcp_ef_split_enclosure(get_post_meta($pid, '_member:enclosure', true)),
            'enclosure_member_monthly' => ets_mcp_ef_split_enclosure(get_post_meta($pid, '_member-monthly:enclosure', true)),
            'allow_full_episode_for_non_members' => ($paywall === '' ? null : $paywall),
        );
        $rows[] = $row;
    }

    // Count the state that matters at a glance, so a monitor does not have to
    // walk the array to know whether anything is open to non-members.
    $open = array();
    foreach ($rows as $r) {
        if (strtolower((string) $r['allow_full_episode_for_non_members']) === 'yes') {
            $open[] = $r['post_id'];
        }
    }

    return array(
        'ok'        => true,
        'count'     => count($rows),
        'statuses'  => $statuses,
        'episodes'  => $rows,
        'summary'   => array(
            'open_to_non_members'       => $open,
            'open_to_non_members_count' => count($open),
        ),
        '_meta' => array(
            'plugin'  => 'ets-mcp-episode-fields',
            'version' => ETS_MCP_EPISODE_FIELDS_VERSION,
            'blog_id' => get_current_blog_id(),
            'note'    => 'Read-only. Ordered by post_id, serialized settings tail omitted, '
                       . 'so the payload diffs cleanly between runs.',
        ),
    );
}

/**
 * Small, diff-stable hash of the enclosure + paywall state across all episodes.
 *
 * Built from the SAME rows the full snapshot returns, so the two can never
 * disagree about whether something changed — a fingerprint computed by a
 * separate query would be a second source of truth and would eventually drift.
 */
function ets_mcp_ef_enclosure_fingerprint($req) {
    $snap = ets_mcp_ef_enclosure_snapshot($req);
    if (is_wp_error($snap)) { return $snap; }

    $parts  = array();
    $newest = null;
    foreach ($snap['episodes'] as $e) {
        $row = array((string) $e['post_id']);
        foreach (array('enclosure_public', 'enclosure_member', 'enclosure_member_monthly') as $k) {
            $enc = $e[$k];
            $row[] = $enc ? (string) $enc['url'] : '';
            $row[] = $enc ? (string) $enc['byte_length'] : '';
        }
        $row[] = (string) $e['allow_full_episode_for_non_members'];
        $parts[] = implode("\x1f", $row);

        if ($e['modified_gmt'] && (!$newest || $e['modified_gmt'] > $newest)) {
            $newest = $e['modified_gmt'];
        }
    }

    return array(
        'ok'                        => true,
        'fingerprint'               => hash('sha256', implode("\x1e", $parts)),
        'episode_count'             => count($snap['episodes']),
        'open_to_non_members'       => $snap['summary']['open_to_non_members'],
        'open_to_non_members_count' => $snap['summary']['open_to_non_members_count'],
        'newest_modified_gmt'       => $newest,
        'statuses'                  => $snap['statuses'],
        'note'                      => 'Store `fingerprint` and compare next run. Unchanged means no '
                                     . 'enclosure URL, byte length or paywall flag moved on any episode '
                                     . '— stop there. On a change, call list_episode_enclosures for the '
                                     . 'detail. newest_modified_gmt is reported but NOT part of the hash: '
                                     . 'postmeta writes do not reliably bump it.',
        '_meta' => array(
            'plugin'  => 'ets-mcp-episode-fields',
            'version' => ETS_MCP_EPISODE_FIELDS_VERSION,
            'blog_id' => get_current_blog_id(),
            'hashed'  => 'post_id + 3 enclosure urls + 3 byte lengths + paywall flag, per episode',
        ),
    );
}

function ets_mcp_ef_list_terms($req) {
    $tax     = (string) $req['taxonomy'];
    $allowed = ets_mcp_ef_episode_taxonomies();
    if (!in_array($tax, $allowed, true)) {
        return new WP_Error('unknown_taxonomy', "'{$tax}' is not a taxonomy registered for xen_episodes.", array(
            'status'    => 400,
            'available' => $allowed,
        ));
    }

    $per_page = max(1, min((int) $req->get_param('per_page'), 200));
    $page     = max(1, (int) $req->get_param('page'));
    $search   = trim((string) $req->get_param('search'));
    $include  = trim((string) $req->get_param('include'));

    $args = array(
        'taxonomy'   => $tax,
        'hide_empty' => false,          // a guest booked but not yet aired has count 0
        'orderby'    => 'name',
        'order'      => 'ASC',
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
    );
    if ($search !== '') { $args['search'] = $search; }
    if ($include !== '') {
        $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $include))));
        if ($ids) {
            // include + a paging offset is a contradiction; resolving specific ids
            // is a lookup, not a listing, so drop the window rather than silently
            // returning a slice of it.
            $args['include'] = $ids;
            $args['number']  = 0;
            $args['offset']  = 0;
            unset($args['search']);
        }
    }

    $terms = get_terms($args);
    if (is_wp_error($terms)) { return $terms; }

    $total = (int) wp_count_terms(array('taxonomy' => $tax, 'hide_empty' => false));

    return array(
        'ok'       => true,
        'taxonomy' => $tax,
        'terms'    => array_map('ets_mcp_ef_term_row', $terms),
        'returned' => count($terms),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        '_meta'    => array('plugin' => 'ets-mcp-episode-fields',
                            'version' => ETS_MCP_EPISODE_FIELDS_VERSION,
                            'available_taxonomies' => $allowed),
    );
}

function ets_mcp_ef_read_terms($req) {
    $id = (int) $req['id'];
    if (get_post_type($id) !== 'xen_episodes') {
        return new WP_Error('not_an_episode', "Post {$id} is not a xen_episodes post.", array('status' => 400));
    }
    $out = array();
    foreach (ets_mcp_ef_episode_taxonomies() as $tax) {
        $terms = wp_get_object_terms($id, $tax);
        $out[$tax] = is_wp_error($terms) ? array() : array_map('ets_mcp_ef_term_row', $terms);
    }
    return array(
        'ok'      => true,
        'post_id' => $id,
        'terms'   => $out,
        '_meta'   => array('plugin' => 'ets-mcp-episode-fields',
                           'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
    );
}

function ets_mcp_ef_write_terms($req) {
    $id      = (int) $req['id'];
    $terms   = (array) $req->get_param('terms');
    $append  = filter_var($req->get_param('append'), FILTER_VALIDATE_BOOLEAN);
    $dry_run = filter_var($req->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);
    $confirm = filter_var($req->get_param('confirm_protected'), FILTER_VALIDATE_BOOLEAN);

    if (get_post_type($id) !== 'xen_episodes') {
        return new WP_Error('not_an_episode', "Post {$id} is not a xen_episodes post.", array('status' => 400));
    }
    if (empty($terms)) {
        return new WP_Error('no_terms', 'terms must be a non-empty object of taxonomy => [ids].', array('status' => 400));
    }

    $protected = ets_mcp_ef_protected_ids();
    if (in_array($id, $protected, true)) {
        if (!$confirm) {
            return new WP_Error('protected_post', "Post {$id} is a protected original and is never modified.", array(
                'status' => 409, 'protected_ids' => $protected,
            ));
        }
        error_log(sprintf(
            '[ets-mcp-episode-fields] PROTECTED OVERRIDE (terms): post %d by user %d; taxonomies: %s',
            $id, get_current_user_id(), implode(',', array_keys($terms))
        ));
    }

    // VALIDATE BEFORE WRITING ANYTHING. wp_set_object_terms() silently drops a
    // term id that does not exist in the target taxonomy — the call succeeds, the
    // term is absent, and the caller has no way to tell those apart. Since a term
    // id typo looks exactly like a correct id, checking first is the difference
    // between an error and a mystery.
    $allowed  = ets_mcp_ef_episode_taxonomies();
    $resolved = array();
    foreach ($terms as $tax => $ids) {
        if (!in_array($tax, $allowed, true)) {
            return new WP_Error('unknown_taxonomy', "'{$tax}' is not registered for xen_episodes.", array(
                'status' => 400, 'available' => $allowed,
            ));
        }
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        $bad = array();
        foreach ($ids as $tid) {
            $t = get_term($tid, $tax);
            if (!$t || is_wp_error($t)) { $bad[] = $tid; }
        }
        if ($bad) {
            return new WP_Error('unknown_term', 'Term ids not found in this taxonomy.', array(
                'status' => 400, 'taxonomy' => $tax, 'unknown_ids' => $bad,
                'hint'   => 'Look ids up with GET /xen-ets/v1/terms/' . $tax . '?search=<name>.',
            ));
        }
        $resolved[$tax] = $ids;
    }

    $before = array();
    foreach (array_keys($resolved) as $tax) {
        $cur = wp_get_object_terms($id, $tax, array('fields' => 'ids'));
        $before[$tax] = is_wp_error($cur) ? array() : array_map('intval', $cur);
    }

    if ($dry_run) {
        return array(
            'ok' => true, 'dry_run' => true, 'persisted' => false, 'post_id' => $id,
            'append' => $append,
            'changes' => array('before' => $before, 'requested' => $resolved),
            'note' => 'DRY RUN — nothing written.'
                    . ($append ? '' : ' append=false REPLACES the taxonomy on this post.'),
        );
    }

    foreach ($resolved as $tax => $ids) {
        wp_set_object_terms($id, $ids, $tax, $append);
    }

    // Verify by re-read. wp_set_object_terms() returns term_taxonomy_ids, not
    // term_ids, so its return value cannot be compared with what was sent.
    $after      = array();
    $mismatched = array();
    foreach ($resolved as $tax => $ids) {
        $cur = wp_get_object_terms($id, $tax, array('fields' => 'ids'));
        $cur = is_wp_error($cur) ? array() : array_map('intval', $cur);
        $after[$tax] = $cur;
        $expected_present = $append ? $ids : $ids;
        if (array_diff($expected_present, $cur)) { $mismatched[] = $tax; }
        if (!$append && array_diff($cur, $ids)) { $mismatched[] = $tax; }
    }
    $mismatched = array_values(array_unique($mismatched));

    $result = array(
        'ok'        => empty($mismatched),
        'dry_run'   => false,
        'persisted' => true,
        'post_id'   => $id,
        'append'    => $append,
        'changes'   => array('before' => $before, 'after' => $after),
        'integrity' => array(
            'verified_by_reread' => empty($mismatched),
            'mismatched_taxonomies' => $mismatched,
        ),
        'note' => 'wp_set_object_terms does not bump post_modified — a term change is '
                . 'invisible to get_content_fingerprint.',
        '_meta' => array('plugin' => 'ets-mcp-episode-fields',
                         'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
    );
    if (!empty($mismatched)) {
        $result['ATTENTION'] = 'Re-read does not match what was sent for: '
                             . implode(', ', $mismatched) . '. Do not assume this landed.';
    }
    return $result;
}

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
