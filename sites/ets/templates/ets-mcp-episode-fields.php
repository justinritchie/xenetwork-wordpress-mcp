<?php
/**
 * Plugin Name: ETS MCP — Episode Fields (read + guarded write)
 * Description: Exposes per-episode postmeta and non-REST taxonomies over REST for the
 *              MCP connector, with a deliberately narrow write path.
 * Version:     1.5.2
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
    define('ETS_MCP_EPISODE_FIELDS_VERSION', '1.5.2');
}

/**
 * Similarity percentage at or above which a proposed new term name is treated
 * as a DUPLICATE of an existing one and the create is refused.
 *
 * NOT a guess. Measured 2026-08-10 across all 262 live xen_guests terms: every
 * pair of genuinely DISTINCT guests scores at or below 80.0 — the worst offender
 * is 'Richard Cowart' vs 'Richard Watts'. There are zero existing
 * normalized-name collisions and zero substring containments, so the taxonomy is
 * currently clean and any collision this finds is new.
 *
 * 85 therefore sits above every real pair with headroom: it would have blocked
 * NONE of the 262 terms already in the database. Dropping to 80 would start
 * refusing real guests, which is the failure mode that makes an operator pass
 * allow_duplicate reflexively and thereby disarm the check entirely.
 *
 * Surname collisions are deliberately NOT part of this: 8 surnames are already
 * shared by two or three distinct guests (Miller x3, Cooke, Bowen, Farmer,
 * Ritchie, Brown, Green, Johnson). They are reported as advisory context and
 * never block.
 */
if (!defined('ETS_MCP_DUPE_SIMILARITY_THRESHOLD')) {
    define('ETS_MCP_DUPE_SIMILARITY_THRESHOLD', 85.0);
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
 * Keys that legitimately have NO ACF twin, so their absence is not a defect.
 *
 * The write path warns when it cannot find an ACF field key for a meta name,
 * because a value written without its `_<name>` companion renders as an EMPTY
 * field in wp-admin over a populated database. That warning is worth having.
 *
 * It was also firing on `enclosure` every single time, which is a FALSE
 * POSITIVE: PowerPress owns that key and has no ACF field behind it by design.
 * A warning that fires on every correct write is a warning nobody reads, which
 * costs the real ones their meaning — so these are excluded explicitly rather
 * than by relaxing the check.
 *
 * The two `_`-prefixed member tiers are already skipped by the leading-underscore
 * rule; they are listed anyway so the set reads as one idea and does not depend
 * on a naming coincidence that could change.
 */
function ets_mcp_ef_no_acf_twin_keys() {
    return array('enclosure', '_member:enclosure', '_member-monthly:enclosure');
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
        // xen_guests is hierarchical, so a same-named term CAN legally exist under
        // a different parent — wp_insert_term only refuses a name collision at the
        // SAME parent. Reporting it means a caller can tell a real duplicate from
        // a differently-parented term instead of inferring it from the name.
        'parent'      => (int) $t->parent,
        'description' => $t->description !== '' ? $t->description : null,
        'link'        => get_term_link($t) instanceof WP_Error ? null : get_term_link($t),
    );
}

/**
 * One taxonomy guard, used by every term route.
 *
 * Keyed on the taxonomies registered for xen_episodes so these routes cannot
 * become a general-purpose term editor over the whole multisite — the same
 * reasoning that shaped the read route, now load-bearing because these WRITE.
 */
function ets_mcp_ef_check_taxonomy($tax) {
    $allowed = ets_mcp_ef_episode_taxonomies();
    if (!in_array($tax, $allowed, true)) {
        return new WP_Error('unknown_taxonomy', "'{$tax}' is not a taxonomy registered for xen_episodes.", array(
            'status'    => 400,
            'available' => $allowed,
        ));
    }
    return null;
}

/**
 * The capability that governs editing terms in a taxonomy.
 *
 * Read from the taxonomy object rather than hardcoded, because a taxonomy can
 * declare its own capability set. xen_guests uses the defaults, so this resolves
 * to manage_categories — which the MCP's Application Password user (justin,
 * id 3, administrator) holds. Verified 2026-08-10 against
 * /wp/v2/users/me?context=edit: manage_categories true; edit_terms/manage_terms
 * are meta capabilities and correctly absent from the primitive cap map.
 */
function ets_mcp_ef_term_write_cap($tax) {
    $obj = get_taxonomy($tax);
    if ($obj && isset($obj->cap->edit_terms) && $obj->cap->edit_terms) {
        return $obj->cap->edit_terms;
    }
    return 'manage_categories';
}

/** Fold a guest name to a comparison key: accents removed, case and punctuation dropped. */
function ets_mcp_ef_norm_name($s) {
    $s = remove_accents((string) $s);
    $s = strtolower($s);
    return preg_replace('/[^a-z0-9]+/', '', $s);
}

/**
 * Read a term straight out of the database, bypassing the object cache.
 *
 * get_term() can be served from cache, which makes it a weak witness for "did
 * this actually persist" — the value you just wrote is exactly the value most
 * likely to be sitting in cache. This is the re-read that integrity.
 * verified_by_reread is allowed to rest on.
 */
function ets_mcp_ef_term_db_row($term_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy,
                tt.description, tt.parent, tt.count
           FROM {$wpdb->terms} t
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
          WHERE t.term_id = %d
          LIMIT 1",
        (int) $term_id
    ), ARRAY_A);
    if (!$row) { return null; }
    return array(
        'id'          => (int) $row['term_id'],
        'name'        => $row['name'],
        'slug'        => $row['slug'],
        'taxonomy'    => $row['taxonomy'],
        'description' => $row['description'],
        'parent'      => (int) $row['parent'],
        'count'       => (int) $row['count'],
    );
}

/**
 * The archive URL a term WOULD have, for a term that does not exist yet.
 *
 * get_term_link() is the only authority on this and it needs a real term, so a
 * dry run cannot call it. Hand-building the path from home_url() is what the
 * first cut did and it was wrong: this is a SUBSITE, home_url() already ends in
 * /ets, and the result was https://xenetwork.org/ets/ets/guests/<slug>/ — a
 * plausible-looking 404 offered to the operator as the URL they were about to
 * create.
 *
 * Borrowing the shape from a term that DOES exist keeps the rewrite rules, the
 * subsite path and any permalink customisation correct by construction, the
 * same borrow-from-a-sibling reasoning as ets_mcp_ef_find_field_key().
 */
function ets_mcp_ef_archive_url_preview($tax, $slug) {
    $slug = sanitize_title($slug);
    if ($slug === '') { return null; }
    $sample = get_terms(array('taxonomy' => $tax, 'hide_empty' => false, 'number' => 1));
    if (is_wp_error($sample) || empty($sample)) { return null; }
    $link = get_term_link($sample[0]);
    if ($link instanceof WP_Error) { return null; }
    // Swap the sample's slug for the proposed one in the final path segment.
    $pattern = '#(' . preg_quote($sample[0]->slug, '#') . ')(/?)$#';
    $out = preg_replace($pattern, $slug . '$2', $link, 1);
    return $out ?: null;
}

/**
 * Distinct lowercase tag names appearing in a fragment of HTML.
 *
 * No whitespace is permitted between '<' and the tag name. Allowing it (the
 * first cut used <\s*\/?\s*) makes prose match: a bio reading "a less-than <
 * all have to survive" reported 'all' as a TAG, and then as a DROPPED tag once
 * kses encoded the bare '<' to '&lt;'. A diagnostic that invents losses is
 * worse than none, because the operator cannot tell it from a real one.
 */
function ets_mcp_ef_html_tags($s) {
    preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)/', (string) $s, $m);
    $tags = array_map('strtolower', $m[1]);
    sort($tags);
    return array_values(array_unique($tags));
}

/**
 * Existing terms that a proposed name/slug might be a duplicate of.
 *
 * WHY THIS IS NOT LEFT TO wp_insert_term
 *   wp_insert_term only refuses an EXACT name collision at the same parent. The
 *   damage case here is the near miss — 'Valerie Trouet' alongside 'Valery
 *   Trouet' — which it accepts happily. Two terms then split one guest's episode
 *   archive at /ets/guests/<slug>/, and nothing surfaces it: both terms look
 *   healthy, both have episodes, and the only symptom is a guest page that is
 *   missing half its shows. A near-duplicate is strictly worse than a failed
 *   create, because a failed create is visible immediately.
 *
 * Scans all 262 terms rather than relying on a LIKE search, because the whole
 * point is catching names that do NOT match literally. At this size that is one
 * cheap query and a few hundred string compares.
 */
function ets_mcp_ef_dupe_candidates($tax, $name, $slug = '') {
    $want_slug = sanitize_title($slug !== '' ? $slug : $name);
    $want_norm = ets_mcp_ef_norm_name($name);
    $want_ci   = strtolower(trim((string) $name));

    $want_parts   = preg_split('/\s+/', trim((string) $name));
    $want_surname = $want_parts ? ets_mcp_ef_norm_name(end($want_parts)) : '';

    $all = get_terms(array('taxonomy' => $tax, 'hide_empty' => false, 'number' => 0));
    if (is_wp_error($all)) { return array(); }

    $out = array();
    foreach ($all as $t) {
        $reasons = array();
        $n = ets_mcp_ef_norm_name($t->name);

        if ($t->slug === $want_slug)                        { $reasons[] = 'slug_exact'; }
        if (strtolower(trim($t->name)) === $want_ci)        { $reasons[] = 'name_exact_ci'; }
        if ($n !== '' && $n === $want_norm)                 { $reasons[] = 'name_normalized'; }
        if ($n !== '' && $want_norm !== '' && $n !== $want_norm
            && (strpos($n, $want_norm) !== false || strpos($want_norm, $n) !== false)) {
            $reasons[] = 'name_contains';
        }

        $pct = 0.0;
        if ($n !== '' && $want_norm !== '') {
            similar_text($n, $want_norm, $pct);
            if ($pct >= ETS_MCP_DUPE_SIMILARITY_THRESHOLD
                && !in_array('name_normalized', $reasons, true)) {
                $reasons[] = 'name_similar';
            }
        }

        // Advisory only, and only when nothing stronger fired. Shared surnames
        // are normal in this taxonomy — blocking on one would refuse real guests.
        if (!$reasons && $want_surname !== '') {
            $tp = preg_split('/\s+/', trim($t->name));
            if ($tp && ets_mcp_ef_norm_name(end($tp)) === $want_surname) {
                $reasons[] = 'surname_only';
            }
        }

        if ($reasons) {
            $row = ets_mcp_ef_term_row($t);
            $row['match_reasons'] = $reasons;
            $row['similarity']    = round((float) $pct, 1);
            $row['blocking']      = (bool) array_diff($reasons, array('surname_only'));
            $out[] = $row;
        }
    }

    usort($out, function ($a, $b) {
        if ($a['blocking'] !== $b['blocking']) { return $a['blocking'] ? -1 : 1; }
        return $b['similarity'] <=> $a['similarity'];
    });
    return $out;
}

/**
 * Compare what was sent against what the database now holds.
 *
 * TERM DESCRIPTIONS ARE KSES-FILTERED AND unfiltered_html DOES NOT EXEMPT YOU.
 *   default-filters.php attaches wp_filter_kses to pre_term_description
 *   unconditionally, and kses_remove_filters() — the thing that stands down for
 *   an unfiltered_html user — never touches that filter. So an administrator
 *   writing a bio gets the RESTRICTIVE $allowedtags list, not $allowedposttags.
 *
 *   MEASURED against this install 2026-08-10 by writing a probe containing
 *   every candidate tag and reading the row back out of the database:
 *
 *     SURVIVE  strong, em, b, i, a (href + title), code, blockquote (cite)
 *     STRIPPED p, br, ul, li, div, span, img, script
 *
 *   Note ul/li do NOT survive, which is the opposite of what the $allowedtags
 *   list reads like at a glance — list markup is silently flattened into a run
 *   of concatenated words ("<li>one</li><li>two</li>" stores as "onetwo"). Do
 *   not write bullet lists into a bio.
 *
 *   That matches the live data exactly: all 262 existing bios use only <strong>
 *   and <a>, with paragraphs as bare \r\n\r\n and wpautop doing the rest at
 *   render time.
 *
 *   This is normal WordPress behaviour, not a failure, so it does not make the
 *   write !ok — but a caller who sent <p> and was told "ok" without being told
 *   the tags were dropped would reasonably assume they survived. Hence the
 *   explicit dropped-tag report.
 */
function ets_mcp_ef_field_integrity($sent, $stored) {
    $identical = ((string) $sent === (string) $stored);
    $out = array('identical' => $identical);
    if ($identical) { return $out; }

    // TWO KINDS OF "not identical", and conflating them makes the alarm useless.
    //
    //   ENTITY NORMALISATION — a raw & becomes &amp;, a raw < becomes &lt;, a
    //   non-breaking space becomes &nbsp;. Nothing is lost; the rendered bio is
    //   character-for-character what was intended. Real bios hit this routinely
    //   (term 796 already stores a &nbsp;), so treating it as damage would fire
    //   an ATTENTION on a large share of perfectly correct writes.
    //
    //   CONTENT LOSS — kses removed a tag and its markup. That is worth shouting
    //   about, and it is only legible as a signal if the first case stays quiet.
    $decoded = html_entity_decode((string) $stored, ENT_QUOTES, 'UTF-8');
    $entity_only = ($decoded === (string) $sent);

    $out['entity_normalized_only'] = $entity_only;
    $out['lossless']               = $entity_only;
    $out['sent']                   = $sent;
    $out['stored']                 = $stored;
    $out['dropped_tags']           = $entity_only ? array() : array_values(array_diff(
        ets_mcp_ef_html_tags($sent), ets_mcp_ef_html_tags($stored)
    ));
    $out['sent_length']            = strlen((string) $sent);
    $out['stored_length']          = strlen((string) $stored);
    if ($entity_only) {
        $out['note'] = 'Differs only by HTML entity encoding (& -> &amp; and similar). '
                     . 'Nothing was lost and the rendered bio is unchanged.';
    }
    return $out;
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

    // CREATE A TERM.
    //
    // Registered as a second endpoint on the SAME route as the GET above —
    // register_rest_route() appends rather than replaces when $override is left
    // false, the idiom this file already uses for /episodes/<id>/fields and
    // /episodes/<id>/terms.
    //
    // WHY A DEDICATED ROUTE RATHER THAN show_in_rest ON THE TAXONOMY
    //   Turning on show_in_rest would create /wp/v2/xen_guests with full CRUD
    //   including DELETE, open a blanket taxonomy write path on
    //   /wp/v2/episodes/<id>, and add a guest panel to the block editor for
    //   every editor on the site. Three side effects nobody asked for, to gain
    //   one endpoint. Same reasoning that kept meta off the post route.
    //
    // THERE IS DELIBERATELY NO DELETE ROUTE — SEE THE NOTE AT THE FOOT OF THIS
    // FILE BEFORE ADDING ONE.
    register_rest_route('xen-ets/v1', '/terms/(?P<taxonomy>[A-Za-z0-9_-]+)', array(
        'methods'             => 'POST',
        'permission_callback' => function ($req) {
            return current_user_can('edit_posts')
                && current_user_can(ets_mcp_ef_term_write_cap((string) $req['taxonomy']));
        },
        'args' => array(
            'name'            => array('required' => true, 'type' => 'string',
                                       'description' => 'Display name, e.g. "Valerie Trouet".'),
            'slug'            => array('type' => 'string',
                                       'description' => 'Optional. Derived from name when omitted. '
                                                      . 'This becomes /ets/guests/<slug>/ permanently.'),
            'description'     => array('type' => 'string',
                                       'description' => 'The BIO. Rich text, but kses-filtered: only '
                                                      . 'strong, em, b, i, a, code and blockquote '
                                                      . 'survive. p, br, ul and li are STRIPPED — '
                                                      . 'separate paragraphs with blank lines.'),
            'dry_run'         => array('type' => 'boolean', 'default' => false),
            'allow_duplicate' => array('type' => 'boolean', 'default' => false,
                                       'description' => 'Override the duplicate refusal. Logged.'),
        ),
        'callback' => 'ets_mcp_ef_create_term',
    ));

    // UPDATE A TERM. Partial — only the supplied fields move.
    //
    // The bio IS the term description, so this is the edit path for guest bios.
    // Nothing here can remove a term or detach it from an episode: the only
    // columns it touches are name, slug and description.
    register_rest_route('xen-ets/v1', '/terms/(?P<taxonomy>[A-Za-z0-9_-]+)/(?P<term_id>\d+)', array(
        'methods'             => 'POST',
        'permission_callback' => function ($req) {
            return current_user_can('edit_posts')
                && current_user_can(ets_mcp_ef_term_write_cap((string) $req['taxonomy']));
        },
        'args' => array(
            'name'        => array('type' => 'string'),
            'slug'        => array('type' => 'string',
                                   'description' => 'CHANGING THIS BREAKS the existing '
                                                  . '/ets/guests/<slug>/ archive URL. Terms have no '
                                                  . '_wp_old_slug redirect — the old URL 404s.'),
            'description' => array('type' => 'string', 'description' => 'The bio.'),
            'dry_run'     => array('type' => 'boolean', 'default' => false),
        ),
        'callback' => 'ets_mcp_ef_update_term',
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
        // array_values() is NOT cosmetic. get_terms() on a HIERARCHICAL taxonomy
        // slices the paged window with array_slice(..., true), preserving the
        // original offsets as keys. json_encode then emits an OBJECT keyed
        // "200".."261" instead of an array, so page 2 of this route came back in
        // a different shape from page 1 and every client iterating `terms` as a
        // list broke on it. Measured 2026-08-10 on xen_guests: page 1 a list of
        // 200, page 2 an object of 62.
        'terms'    => array_values(array_map('ets_mcp_ef_term_row', $terms)),
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
        $out[$tax] = is_wp_error($terms)
            ? array()
            : array_values(array_map('ets_mcp_ef_term_row', $terms));
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

function ets_mcp_ef_create_term($req) {
    $tax = (string) $req['taxonomy'];
    $err = ets_mcp_ef_check_taxonomy($tax);
    if ($err) { return $err; }

    $name        = trim((string) $req->get_param('name'));
    $slug        = trim((string) $req->get_param('slug'));
    $description = (string) $req->get_param('description');
    $dry_run     = filter_var($req->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);
    $allow_dupe  = filter_var($req->get_param('allow_duplicate'), FILTER_VALIDATE_BOOLEAN);

    if ($name === '') {
        return new WP_Error('missing_name', 'name is required and cannot be blank.', array('status' => 400));
    }

    $candidates = ets_mcp_ef_dupe_candidates($tax, $name, $slug);
    $blocking   = array_values(array_filter($candidates, function ($c) { return $c['blocking']; }));
    $advisory   = array_values(array_filter($candidates, function ($c) { return !$c['blocking']; }));

    if ($blocking && !$allow_dupe) {
        return new WP_Error('duplicate_term', "A term matching '{$name}' already exists in {$tax}.", array(
            'status'     => 409,
            'taxonomy'   => $tax,
            'requested'  => array('name' => $name, 'slug_preview' => sanitize_title($slug !== '' ? $slug : $name)),
            'candidates' => $blocking,
            'advisory'   => $advisory,
            'hint'       => 'Reuse the existing term id instead of creating a second one — a '
                          . 'near-duplicate splits one guest\'s episode archive across two '
                          . '/ets/guests/<slug>/ pages and nothing surfaces it. If these really '
                          . 'are different people, pass allow_duplicate=true.',
        ));
    }

    $args = array('description' => $description);
    if ($slug !== '') { $args['slug'] = sanitize_title($slug); }

    if ($dry_run) {
        return array(
            'ok' => true, 'dry_run' => true, 'persisted' => false,
            'taxonomy' => $tax,
            'would_create' => array(
                'name'         => $name,
                'slug'         => sanitize_title($slug !== '' ? $slug : $name),
                'description'  => $description,
                'archive_url'  => ets_mcp_ef_archive_url_preview($tax, $slug !== '' ? $slug : $name),
            ),
            'duplicate_candidates' => $candidates,
            'would_be_blocked'     => (bool) $blocking,
            'note' => 'DRY RUN — nothing written.'
                    . ($blocking ? ' This WOULD be refused as a duplicate; see duplicate_candidates.' : ''),
            '_meta' => array('plugin' => 'ets-mcp-episode-fields',
                             'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
        );
    }

    if ($blocking && $allow_dupe) {
        error_log(sprintf(
            '[ets-mcp-episode-fields] DUPLICATE OVERRIDE: creating %s term %s by user %d; existing: %s',
            $tax, $name, get_current_user_id(),
            implode(',', array_map(function ($c) { return $c['id'] . ':' . $c['name']; }, $blocking))
        ));
    }

    // wp_slash() mirrors what WP_REST_Terms_Controller does before calling
    // wp_insert_term. The function unslashes its own input, so handing it the
    // unslashed REST payload eats backslashes out of the bio.
    $res = wp_insert_term(wp_slash($name), $tax, wp_slash($args));
    if (is_wp_error($res)) {
        // wp_insert_term's own 'term_exists' data is a bare term id, so it is
        // kept under its own key rather than merged into an associative array.
        $res->add_data(array(
            'status'         => 400,
            'taxonomy'       => $tax,
            'wp_error_data'  => $res->get_error_data(),
            'candidates'     => $candidates,
        ));
        return $res;
    }

    $term_id = (int) $res['term_id'];
    $fresh   = ets_mcp_ef_term_db_row($term_id);
    if (!$fresh) {
        return new WP_Error('create_unverified', "wp_insert_term reported term {$term_id} but it "
                          . 'could not be read back from the database.', array('status' => 500));
    }

    $desc_integrity = ets_mcp_ef_field_integrity($description, $fresh['description']);
    $slug_requested = $slug !== '' ? sanitize_title($slug) : null;
    $slug_as_asked  = ($slug_requested === null) || ($slug_requested === $fresh['slug']);

    $term_obj = get_term($term_id, $tax);
    $result = array(
        'ok'        => true,
        'dry_run'   => false,
        'persisted' => true,
        'taxonomy'  => $tax,
        'term_id'   => $term_id,
        'term'      => (!$term_obj || is_wp_error($term_obj)) ? null : ets_mcp_ef_term_row($term_obj),
        'db_row'    => $fresh,
        'archive_url' => (!$term_obj || is_wp_error($term_obj) || get_term_link($term_obj) instanceof WP_Error)
                         ? null : get_term_link($term_obj),
        'edit_url'  => admin_url("term.php?taxonomy={$tax}&tag_ID={$term_id}"),
        'duplicate_candidates' => $candidates,
        'duplicate_override'   => (bool) ($blocking && $allow_dupe),
        'integrity' => array(
            'verified_by_reread' => true,
            'reread_source'      => 'direct SELECT on wp_terms/wp_term_taxonomy, object cache bypassed',
            'description'        => $desc_integrity,
            'slug_as_requested'  => $slug_as_asked,
        ),
        '_meta' => array('plugin' => 'ets-mcp-episode-fields',
                         'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
    );

    $attention = array();
    if (!$desc_integrity['identical'] && empty($desc_integrity['lossless'])) {
        $attention[] = 'CONTENT WAS LOST FROM THE BIO'
                     . ($desc_integrity['dropped_tags']
                        ? ' — kses stripped these tags: ' . implode(', ', $desc_integrity['dropped_tags'])
                        : '')
                     . '. Term descriptions are filtered to the restrictive tag set even for '
                     . 'administrators with unfiltered_html: only strong, em, b, i, a, code and '
                     . 'blockquote survive. Use blank lines for paragraphs, and no bullet lists.';
    }
    if (!$slug_as_asked) {
        $attention[] = "Requested slug '{$slug_requested}' was taken; WordPress stored "
                     . "'{$fresh['slug']}'. The archive URL is not the one you asked for.";
    }
    if ($attention) { $result['ATTENTION'] = implode(' ', $attention); }
    return $result;
}

function ets_mcp_ef_update_term($req) {
    $tax = (string) $req['taxonomy'];
    $err = ets_mcp_ef_check_taxonomy($tax);
    if ($err) { return $err; }

    $term_id = (int) $req['term_id'];
    $dry_run = filter_var($req->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);

    $term = get_term($term_id, $tax);
    if (!$term || is_wp_error($term)) {
        return new WP_Error('unknown_term', "Term {$term_id} does not exist in {$tax}.", array(
            'status' => 404, 'taxonomy' => $tax,
            'hint'   => "Look the id up with GET /xen-ets/v1/terms/{$tax}?search=<name>.",
        ));
    }

    $before = ets_mcp_ef_term_db_row($term_id);

    // PARTIAL SEMANTICS. Only keys the caller actually sent are touched, so a
    // bio edit cannot blank a name by omission. has_param() is the distinction
    // between "not supplied" and "supplied empty" — and for `description`,
    // supplied-empty is a legitimate request to clear the bio.
    $args = array();
    $requested = array();
    foreach (array('name', 'slug', 'description') as $k) {
        if ($req->has_param($k) && $req->get_param($k) !== null) {
            $v = (string) $req->get_param($k);
            if ($k === 'name') {
                $v = trim($v);
                if ($v === '') {
                    return new WP_Error('empty_name', 'name cannot be set to blank — wp_update_term '
                                      . 'rejects it and a nameless guest term is unusable.',
                                        array('status' => 400));
                }
            }
            if ($k === 'slug') {
                $v = sanitize_title($v);
                if ($v === '') {
                    return new WP_Error('empty_slug', 'slug sanitised to empty.', array('status' => 400));
                }
            }
            $args[$k]      = $v;
            $requested[$k] = $v;
        }
    }

    if (!$args) {
        return new WP_Error('no_changes', 'Supply at least one of name, slug or description.', array(
            'status' => 400,
            'hint'   => 'Partial semantics: anything not sent is left alone. Sending nothing is '
                      . 'therefore a no-op, which is more likely a mistake than an intention.',
        ));
    }

    $warnings = array();

    // A term slug change is IRREVERSIBLE from the visitor's side. Posts get
    // _wp_old_slug and a redirect; terms get nothing. The old
    // /ets/guests/<slug>/ simply 404s, taking any inbound link, sitemap entry
    // and show-notes reference with it.
    $slug_changing = isset($args['slug']) && $args['slug'] !== $term->slug;
    if ($slug_changing) {
        // The term exists, so get_term_link() is authoritative for the OLD url.
        $old_link = get_term_link($term);
        $warnings[] = sprintf(
            'SLUG CHANGE: %s -> %s. The existing archive URL %s will 404 — terms have no '
            . '_wp_old_slug redirect, so every inbound link, sitemap entry and show-notes '
            . 'reference to the old URL breaks permanently. This guest is on %d episode(s).',
            $term->slug, $args['slug'],
            ($old_link instanceof WP_Error) ? '(unresolvable)' : $old_link,
            (int) $term->count
        );
    }

    // A rename toward an existing guest is reported, never blocked: renaming is
    // how a typo gets fixed, and the failure mode (two similar names) is far
    // milder than it is for a create, which manufactures a second archive.
    $name_candidates = array();
    if (isset($args['name']) && $args['name'] !== $term->name) {
        foreach (ets_mcp_ef_dupe_candidates($tax, $args['name'], '') as $c) {
            if ((int) $c['id'] !== $term_id) { $name_candidates[] = $c; }
        }
        if ($name_candidates) {
            $warnings[] = 'The new name resembles ' . count($name_candidates)
                        . ' other term(s) in this taxonomy — see name_candidates. Reported only, '
                        . 'not blocked.';
        }
    }

    if ($dry_run) {
        $preview = $before;
        foreach ($args as $k => $v) { $preview[$k] = $v; }
        return array(
            'ok' => true, 'dry_run' => true, 'persisted' => false,
            'taxonomy' => $tax, 'term_id' => $term_id,
            'changes'  => array('before' => $before, 'requested' => $requested, 'preview' => $preview),
            'name_candidates' => $name_candidates,
            'warnings' => $warnings,
            'note' => 'DRY RUN — nothing written. The preview does not account for kses '
                    . 'filtering of the description; run for real to see what actually stores.',
            '_meta' => array('plugin' => 'ets-mcp-episode-fields',
                             'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
        );
    }

    $res = wp_update_term($term_id, $tax, wp_slash($args));
    if (is_wp_error($res)) {
        $res->add_data(array(
            'status'        => 400,
            'taxonomy'      => $tax,
            'term_id'       => $term_id,
            'wp_error_data' => $res->get_error_data(),
            'before'        => $before,
        ));
        return $res;
    }

    $after = ets_mcp_ef_term_db_row($term_id);
    if (!$after) {
        return new WP_Error('update_unverified', "wp_update_term reported success on term {$term_id} "
                          . 'but it could not be read back from the database.', array('status' => 500));
    }

    // Verify field by field against what was SENT, not against what
    // wp_update_term returned — it returns term ids, not the stored values, so
    // it cannot witness its own effect.
    $integrity    = array();
    $mismatched   = array();
    $content_lost = array();
    foreach ($requested as $k => $v) {
        $chk = ets_mcp_ef_field_integrity($v, $after[$k]);
        $integrity[$k] = $chk;
        if (!$chk['identical']) {
            $mismatched[] = $k;
            if (empty($chk['lossless'])) { $content_lost[] = $k; }
        }
    }

    $untouched_ok = true;
    foreach (array('name', 'slug', 'description') as $k) {
        if (!array_key_exists($k, $requested) && $before[$k] !== $after[$k]) {
            $untouched_ok = false;
        }
    }

    $term_obj = get_term($term_id, $tax);
    $result = array(
        'ok'        => true,
        'dry_run'   => false,
        'persisted' => true,
        'taxonomy'  => $tax,
        'term_id'   => $term_id,
        'changes'   => array('before' => $before, 'after' => $after, 'requested' => $requested),
        'archive_url' => (!$term_obj || is_wp_error($term_obj) || get_term_link($term_obj) instanceof WP_Error)
                         ? null : get_term_link($term_obj),
        'edit_url'  => admin_url("term.php?taxonomy={$tax}&tag_ID={$term_id}"),
        'name_candidates' => $name_candidates,
        'warnings'  => $warnings,
        'integrity' => array(
            'verified_by_reread'   => true,
            'reread_source'        => 'direct SELECT on wp_terms/wp_term_taxonomy, object cache bypassed',
            'fields'               => $integrity,
            // Byte differences that are pure entity encoding are lossless and
            // are deliberately NOT listed as content loss.
            'not_byte_identical'   => $mismatched,
            'content_lost'         => $content_lost,
            'untouched_fields_held'=> $untouched_ok,
        ),
        'revert' => array(
            'note' => 'No revision history exists for terms. To undo, POST this same route with '
                    . 'the values under changes.before.',
            'args' => array_intersect_key($before, $requested),
        ),
        '_meta' => array('plugin' => 'ets-mcp-episode-fields',
                         'version' => ETS_MCP_EPISODE_FIELDS_VERSION),
    );

    $attention = array();
    if ($slug_changing) { $attention[] = $warnings[0]; }
    if (in_array('description', $mismatched, true)
        && empty($integrity['description']['lossless'])) {
        $dropped = $integrity['description']['dropped_tags'];
        $attention[] = 'CONTENT WAS LOST FROM THE BIO'
                     . ($dropped ? ' — kses stripped these tags: ' . implode(', ', $dropped) : '')
                     . '. Term descriptions are filtered to the restrictive tag set even for '
                     . 'administrators with unfiltered_html: only strong, em, b, i, a, code and '
                     . 'blockquote survive. Use blank lines for paragraphs, and no bullet lists.';
    }
    if (!$untouched_ok) {
        $result['ok'] = false;
        $attention[] = 'A field that was NOT sent changed anyway. Do not trust this write; '
                     . 'compare changes.before and changes.after.';
    }
    if ($attention) { $result['ATTENTION'] = implode(' ', $attention); }
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
    $no_twin = ets_mcp_ef_no_acf_twin_keys();
    foreach (array_keys($fields) as $k) {
        if (strpos($k, '_') === 0) { continue; }          // already a key row
        if (in_array($k, $no_twin, true)) { continue; }   // no ACF twin by design
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

/**
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO DELETE ROUTE FOR TERMS, AND WHY ONE SHOULD NOT BE ADDED
 * ---------------------------------------------------------------------------
 *
 * Measured 2026-08-10: xen_guests holds 262 terms attached across 296 published
 * episodes. wp_delete_term() removes the term AND every object relationship it
 * has, in one call, with no revision history and no undo. There is no term
 * equivalent of the trash.
 *
 * So a delete does not fail loudly — it silently detaches the guest from every
 * episode they ever appeared on. The episodes keep publishing, the pages keep
 * rendering, and the only symptom is that a guest and their archive have
 * evaporated. Nothing here would catch it either: wp_delete_term does not bump
 * post_modified, so get_content_fingerprint is blind to it — exactly the
 * term-assignment blind spot already documented on the write-terms route.
 *
 * The recovery cost is asymmetric to the point of absurdity. Creating a term is
 * one call; reconstructing severed relationships across 296 episodes from a
 * backup is not.
 *
 * Terms that need to go are removed by a human in wp-admin, where the
 * confirmation dialog and the visible episode count are the safeguard. If a
 * merge tool is ever genuinely needed, it should REASSIGN relationships to the
 * surviving term and leave the empty husk for a human to remove — the
 * destructive half stays out of the API either way.
 */
