<?php
/*
Plugin Name: XE Network — s2Member REST exposure
Description: Surfaces s2Member's user metadata + custom registration fields
             via the standard /wp-json/wp/v2/users endpoint, so the
             wordpress-xenetwork MCP can read subscription state, EOT
             timestamps, gateway IDs, login counts, and custom fields.
             Read-only; only exposes fields when context=edit (auth required).
Author: Justin Ritchie
Version: 1.0.0
*/

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// 1. Named s2Member fields — the ones we know about and want documented
// ---------------------------------------------------------------------------
//
// Each entry maps a REST field name (left) to the wp_usermeta key (right).
// Adding a new field here is the right way to give a custom-field key a
// stable, documented name in the REST surface. Until then, it'll still show
// up in `_all_meta_inspection` below — see that for discovery.

function xen_s2_named_fields() {
    global $wpdb;
    // s2Member prefixes its meta keys with the WP table prefix
    // (typically `wp_`) for multisite-safety. Discovered via the
    // `_all_meta_inspection` field: real keys are `wp_s2member_subscr_id`,
    // not `s2member_subscr_id`.
    $p = $wpdb->prefix; // 'wp_' on a default install

    return [
        // --- Subscription identifiers (Stripe, PayPal, WordPress) ---
        's2_subscr_gateway'          => $p . 's2member_subscr_gateway',          // 'stripe' | 'paypal' | 'free'
        's2_subscr_id'               => $p . 's2member_subscr_id',               // Stripe sub_xxx OR PayPal I-xxx
        's2_subscr_cid'              => $p . 's2member_subscr_cid',              // Stripe cus_xxx
        's2_subscr_baid'             => $p . 's2member_subscr_baid',             // PayPal billing agreement ID
        's2_subscr_or_wp_id'         => $p . 's2member_subscr_or_wp_id',
        's2_first_payment_txn_id'    => $p . 's2member_first_payment_txn_id',
        's2_custom'                  => $p . 's2member_custom',                  // 'xenetwork.org' (originating domain)
        's2_registration_ip'         => $p . 's2member_registration_ip',
        's2_coupon_codes'            => $p . 's2member_coupon_codes',            // array of coupons used

        // --- Lifecycle timestamps ---
        's2_paid_registration_times' => $p . 's2member_paid_registration_times', // {level0:ts, level1:ts, ...}
        's2_last_payment_time'       => $p . 's2member_last_payment_time',
        's2_auto_eot_time'           => $p . 's2member_auto_eot_time',           // ⭐ When access ends
        's2_subscr_eot_per'          => $p . 's2member_subscr_eot_per',

        // --- Activity ---
        's2_login_counter'           => $p . 's2member_login_counter',           // "# Of Logins" in WP admin export
        's2_last_login_time'         => $p . 's2member_last_login_time',
        's2_last_logged_in_string'   => 'last_logged_in',                        // Human-readable companion field

        // --- Custom registration fields blob (s2Member's consolidated JSON) ---
        's2_custom_fields'           => $p . 's2member_custom_fields',           // {secondary_email, phone, ...}

        // --- IPN signup snapshot (full payload from gateway at signup) ---
        's2_ipn_signup_vars'         => $p . 's2member_ipn_signup_vars',         // amount, period, item_name, etc.

        // --- XE Network site-specific custom fields (top-level usermeta) ---
        's2_custom_newsletter_optin' => 'newsletter_optin',                       // 1 = opted in, null = not
        's2_custom_member_feed_qty'  => 'member_feed_access_qty',                 // Member feed access quantity
        's2_custom_reg_page_id'      => 'reg_page_id',                            // Registration page ID
        's2_custom_phone'            => 'phone',
        's2_custom_new_episode_notify'     => 'new_episode_notify',
        's2_custom_new_episode_notify_sms' => 'new_episode_notify_sms',
        's2_custom_new_job_post_notify_sms'=> 'new_job_post_notify_sms',
        's2_custom_gift_accounts_remaining'=> 'gift_accounts_remaining',
        's2_custom_gift_account_reset_date'=> 'gift_account_reset_date',
    ];
}

add_action('rest_api_init', function () {
    foreach (xen_s2_named_fields() as $rest_key => $meta_key) {
        register_rest_field('user', $rest_key, [
            'get_callback' => function ($user) use ($meta_key) {
                $val = get_user_meta($user['id'], $meta_key, true);
                if ($val === '' || $val === null) {
                    return null;
                }
                // s2Member often stores serialized arrays — unserialize where safe.
                if (is_string($val) && strncmp($val, 'a:', 2) === 0) {
                    $maybe = @unserialize($val);
                    if ($maybe !== false) {
                        return $maybe;
                    }
                }
                return $val;
            },
            'schema' => [
                'description' => 's2Member: ' . $meta_key,
                'type'        => ['string', 'integer', 'array', 'object', 'null'],
                'context'     => ['edit'],
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // 2. _all_meta_inspection — discovery field
    // -----------------------------------------------------------------------
    //
    // Returns every wp_usermeta key for the user, with secrets denylisted.
    // Only available with context=edit (admin auth required). Use this once
    // to see exactly which keys exist on your site, then add the interesting
    // ones to xen_s2_named_fields() for stable named exposure.
    //
    // After named exposure is dialed in, you can comment this out if you
    // want to be paranoid. Single-user setup makes the risk low.

    // -----------------------------------------------------------------------
    // 3. Custom routes for xen_institutional post duplication
    // -----------------------------------------------------------------------
    //
    // Endpoints under /wp-json/xen/v1/:
    //   GET  /institutional/<id>             — full post + ALL postmeta + tax
    //   POST /institutional/duplicate         — clone source post w/ overrides
    //   POST /institutional/<id>/meta         — write postmeta on an existing post
    //
    // The default wp/v2/xen_institutional REST endpoints only return meta
    // keys that have been registered with show_in_rest=>true via
    // register_post_meta(). We don't want to register every key one-by-one
    // for a CPT with many custom fields, so these custom routes work at the
    // get_post_meta()/update_post_meta() level directly.
    //
    // Both routes require edit_posts capability (Justin's app password
    // grants this since he's Super Admin).

    register_rest_route('xen/v1', '/institutional/(?P<id>\d+)', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => function ($req) {
            $id   = (int) $req['id'];
            $post = get_post($id);
            if (!$post || $post->post_type !== 'xen_institutional') {
                return new WP_Error('not_found', 'Institutional post not found', ['status' => 404]);
            }

            // Collect all non-private postmeta
            $raw  = get_post_meta($id);
            $meta = [];
            foreach ($raw as $key => $values) {
                if (strncmp($key, '_', 1) === 0) {
                    continue; // skip WP-private (_edit_lock, _edit_last, etc.)
                }
                $value = (count($values) === 1)
                    ? maybe_unserialize($values[0])
                    : array_map('maybe_unserialize', $values);
                $meta[$key] = $value;
            }

            // Collect taxonomies
            $taxonomies = [];
            foreach (get_object_taxonomies('xen_institutional') as $tax) {
                $terms = wp_get_object_terms($id, $tax, ['fields' => 'ids']);
                if (!is_wp_error($terms)) {
                    $taxonomies[$tax] = array_map('intval', $terms);
                }
            }

            return [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'slug'       => $post->post_name,
                'status'     => $post->post_status,
                'date'       => $post->post_date,
                'modified'   => $post->post_modified,
                'author'     => (int) $post->post_author,
                'parent'     => (int) $post->post_parent,
                'content'    => $post->post_content,
                'excerpt'    => $post->post_excerpt,
                'meta'       => $meta,
                'taxonomies' => $taxonomies,
                'edit_url'   => admin_url("post.php?post={$id}&action=edit"),
                'permalink'  => get_permalink($id),
            ];
        },
    ]);

    register_rest_route('xen/v1', '/institutional/duplicate', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => function ($req) {
            $source_id            = (int) $req->get_param('source_id');
            $new_title            = (string) $req->get_param('new_title');
            $new_slug             = (string) $req->get_param('new_slug');
            $content_replacements = (array) ($req->get_param('content_replacements') ?: []);
            $meta_overrides       = (array) ($req->get_param('meta_overrides') ?: []);
            $status               = $req->get_param('status') ?: 'draft';

            if (!$source_id || !$new_title || !$new_slug) {
                return new WP_Error('bad_request', 'source_id, new_title, new_slug required', ['status' => 400]);
            }

            $source = get_post($source_id);
            if (!$source || $source->post_type !== 'xen_institutional') {
                return new WP_Error('not_found', 'Source institutional post not found', ['status' => 404]);
            }

            // Apply find/replace to content
            $content = $source->post_content;
            foreach ($content_replacements as $find => $replace) {
                $content = str_replace($find, $replace, $content);
            }

            // Create the new post
            $new_id = wp_insert_post([
                'post_type'    => 'xen_institutional',
                'post_status'  => $status,
                'post_title'   => $new_title,
                'post_name'    => $new_slug,
                'post_content' => $content,
                'post_excerpt' => $source->post_excerpt,
                'post_author'  => get_current_user_id(),
            ], true);
            if (is_wp_error($new_id)) {
                return $new_id;
            }

            // Copy ALL postmeta from source (skip private + WP locks)
            $skip_keys = ['_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug'];
            foreach (get_post_meta($source_id) as $key => $values) {
                if (in_array($key, $skip_keys, true)) {
                    continue;
                }
                $value = (count($values) === 1)
                    ? maybe_unserialize($values[0])
                    : array_map('maybe_unserialize', $values);
                update_post_meta($new_id, $key, $value);
            }

            // Auto-reset counter fields — these are runtime stats from the
            // source page (registration count, view count) that have no
            // meaning on a fresh duplicate. Always reset, regardless of
            // what was copied from source. Caller's meta_overrides still
            // win below if they explicitly need a non-default value.
            $counter_resets = [
                'registration_count' => '0',
                'iawp_total_views'   => '0',
            ];
            foreach ($counter_resets as $ck => $cv) {
                update_post_meta($new_id, $ck, $cv);
            }

            // Auto-clear per-cohort fields — these belong to the SOURCE
            // institution's registrants and must NOT carry over to a fresh
            // clone (otherwise the new portal inherits the prior cohort's
            // email list, which is wrong data and a privacy/publish risk).
            // Cleared to empty string; caller meta_overrides still win below.
            $cohort_clears = [
                'registered_member_list' => '',
                'form_entry_id'          => '',
            ];
            foreach ($cohort_clears as $ck => $cv) {
                update_post_meta($new_id, $ck, $cv);
            }

            // Apply meta overrides (after copy + reset, so caller wins)
            $overrides_applied = [];
            foreach ($meta_overrides as $key => $value) {
                update_post_meta($new_id, $key, $value);
                $overrides_applied[] = $key;
            }

            // Copy all taxonomies
            $tax_copied = [];
            foreach (get_object_taxonomies('xen_institutional') as $tax) {
                $terms = wp_get_object_terms($source_id, $tax, ['fields' => 'ids']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    wp_set_object_terms($new_id, $terms, $tax);
                    $tax_copied[$tax] = array_map('intval', $terms);
                }
            }

            $final_slug = get_post_field('post_name', $new_id);

            return [
                'ok'                  => true,
                'new_id'              => $new_id,
                'new_slug'            => $final_slug,
                'status'               => get_post_status($new_id),
                'title'                => get_the_title($new_id),
                'edit_url'             => admin_url("post.php?post={$new_id}&action=edit"),
                'preview_link'         => get_preview_post_link($new_id),
                // Clean URL the slug resolves to once published. Built from
                // the IR archive base so it's correct even while still draft
                // (get_permalink() returns the ?p=ID form for drafts).
                'public_url'           => home_url("/become-a-member-ets/institutions/{$final_slug}/"),
                'source_id'            => $source_id,
                'content_replacements' => array_keys($content_replacements),
                'counters_reset'       => array_keys($counter_resets),
                'cohort_cleared'       => array_keys($cohort_clears),
                'meta_overrides'       => $overrides_applied,
                'taxonomies_copied'    => $tax_copied,
                'note'                 => "Duplicated as {$status}. Counters reset to 0 and per-cohort fields (registered_member_list, form_entry_id) cleared. Review at the edit_url before publishing.",
            ];
        },
    ]);

    // POST /institutional/<id>/meta — write postmeta on an existing IR post
    // without re-cloning. Lets us correct institution_name, whitelisted_email,
    // registration_limit, welcome_page, ToS text, etc. on a draft or live
    // page. Works at update_post_meta() level so it can write keys the
    // default wp/v2 REST hides.
    register_rest_route('xen/v1', '/institutional/(?P<id>\d+)/meta', [
        'methods'             => 'POST',
        'permission_callback' => function () {
            return current_user_can('edit_posts');
        },
        'callback' => function ($req) {
            $id   = (int) $req['id'];
            $post = get_post($id);
            if (!$post || $post->post_type !== 'xen_institutional') {
                return new WP_Error('not_found', 'Institutional post not found', ['status' => 404]);
            }

            $meta = $req->get_param('meta');
            if (!is_array($meta) || empty($meta)) {
                return new WP_Error('bad_request', 'meta must be a non-empty object of key=>value pairs', ['status' => 400]);
            }

            $updated = [];
            $skipped = [];
            foreach ($meta as $key => $value) {
                // Refuse WP-private keys (_edit_lock, _thumbnail_id, etc.) so
                // a stray override can't clobber WordPress internals.
                if (strncmp($key, '_', 1) === 0) {
                    $skipped[] = $key;
                    continue;
                }
                update_post_meta($id, $key, $value);
                $updated[] = $key;
            }

            return [
                'ok'           => true,
                'id'           => $id,
                'meta_updated' => $updated,
                'meta_skipped' => $skipped,
                'edit_url'     => admin_url("post.php?post={$id}&action=edit"),
            ];
        },
    ]);

    register_rest_field('user', '_all_meta_inspection', [
        'get_callback' => function ($user) {
            $all = get_user_meta($user['id']);
            $denylist = [
                // WordPress sessions and security
                'session_tokens',
                'community-events-location',
                // Application passwords (HASHES are here — still don't expose)
                '_application_passwords',
                // Password reset / account recovery
                'default_password_nag',
                'password_reset_request_token',
                // Capabilities (already exposed via roles + extra_capabilities)
                'wp_capabilities',
                'wp_user_level',
                // Hidden WP internals
                'closedpostboxes_user-edit',
                'metaboxhidden_user-edit',
                'syntax_highlighting',
                'admin_color',
                'rich_editing',
                'comment_shortcuts',
                'use_ssl',
                'show_admin_bar_front',
                'locale',
                'wp_dashboard_quick_press_last_post_id',
                'managenav-menuscolumnshidden',
                'screen_layout_users',
                'persisted_preferences',
            ];

            $out = [];
            foreach ($all as $key => $values) {
                if (in_array($key, $denylist, true)) {
                    continue;
                }
                if (strncmp($key, '_', 1) === 0) {
                    continue; // private WP internals
                }
                // get_user_meta returns arrays of values; unwrap single-value entries
                $value = (count($values) === 1) ? $values[0] : $values;
                if (is_string($value) && strncmp($value, 'a:', 2) === 0) {
                    $maybe = @unserialize($value);
                    if ($maybe !== false) {
                        $value = $maybe;
                    }
                }
                $out[$key] = $value;
            }
            return $out;
        },
        'schema' => [
            'description' => 'All non-secret wp_usermeta keys for inspection. Use to discover custom field keys, then add them to xen_s2_named_fields() for stable named exposure.',
            'type'        => 'object',
            'context'     => ['edit'],
        ],
    ]);
});

// ===========================================================================
// Append to xen-s2member-rest.php (xenetwork.org mu-plugin)
// ===========================================================================
//
// Adds GET /wp-json/xen/v1/users/count-by-role — returns a single dict of
// {role_slug: count} using WordPress's native count_users() function.
//
// One HTTP call gives the full role rollup. No paging, no per-role queries.
// Required: 'list_users' capability (admin/editor).
//
// Append this at the bottom of xen-s2member-rest.php, before any closing tag.

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/users/count-by-role', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can('list_users');
        },
        'callback' => function () {
            // count_users() returns:
            //   {
            //     'total_users' => int,
            //     'avail_roles' => ['administrator' => N, 'editor' => N, ...],
            //   }
            // Per-role counts include s2Member-added roles
            // (s2member_level0, s2member_level1, etc.) since s2Member registers
            // them as proper WP roles.
            $counts = count_users();
            return [
                'total_users' => (int) $counts['total_users'],
                'avail_roles' => $counts['avail_roles'],
                // Add a sorted-by-count convenience alongside the raw map
                'sorted_desc' => (function ($roles) {
                    arsort($roles);
                    return $roles;
                })($counts['avail_roles']),
                'site' => home_url(),
                'generated_at' => current_time('c'),
            ];
        },
    ]);
});

// ===========================================================================
// Append to xen-s2member-rest.php — count_users_by_ccap endpoint
// ===========================================================================
//
// Adds GET /wp-json/xen/v1/users/by-ccap — filter users by their s2Member
// Custom Capability (CCAP) using contains / ends_with / starts_with / exact
// pattern matching.
//
// Why a separate endpoint from count-by-role: CCAPs live in the serialized
// `wp_capabilities` usermeta blob, not as proper WP roles, so count_users()
// doesn't see them. We have to query wp_usermeta directly + deserialize.
//
// CCAP key format in wp_capabilities (after deserialize):
//   {
//     'subscriber'                           => true,
//     's2member_level1'                      => true,
//     'access_s2member_ccap_sabuqcf'         => true,   ← CCAP slug = 'sabuqcf'
//     'access_s2member_ccap_kmutqcf'         => true,   ← CCAP slug = 'kmutqcf'
//   }
// The CCAP slug is whatever follows 'access_s2member_ccap_' in the cap key.

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/users/by-ccap', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('list_users'); },
        'args' => [
            'pattern' => [
                'required'          => true,
                'type'              => 'string',
                'description'       => 'CCAP slug substring to match.',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'match' => [
                'default'           => 'contains',
                'type'              => 'string',
                'description'       => "Match type: 'contains' | 'ends_with' | 'starts_with' | 'exact' (default: contains).",
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'include_users' => [
                'default'     => false,
                'type'        => 'boolean',
                'description' => 'If true, include the list of matching users (id, email, display_name, matching_ccaps) — slower but useful for spot-checking.',
            ],
            'limit' => [
                'default'     => 1000,
                'type'        => 'integer',
                'description' => 'Max users to scan (default 1000). Increase if your site has more than 1000 capability rows.',
            ],
        ],
        'callback' => function ($request) {
            global $wpdb;
            $pattern       = (string) $request->get_param('pattern');
            $match         = (string) $request->get_param('match');
            $include_users = filter_var($request->get_param('include_users'), FILTER_VALIDATE_BOOLEAN);
            $limit         = max(1, min(100000, (int) $request->get_param('limit')));

            $cap_prefix = 'access_s2member_ccap_';
            // The capability meta key is prefixed with the WP table prefix
            // (e.g. wp_capabilities). On multisite the per-blog prefix differs,
            // but for a single-site install $wpdb->prefix is 'wp_' so the
            // meta_key is 'wp_capabilities'.
            $cap_meta_key = $wpdb->prefix . 'capabilities';

            // Coarse SQL prefilter: any row whose meta_value text contains
            // both the cap_prefix AND the pattern. This narrows from "all
            // users" to "users likely to match"; we then PHP-deserialize each
            // matched row and verify per the requested match type.
            $sql_like = '%' . $wpdb->esc_like($cap_prefix) . '%' . $wpdb->esc_like($pattern) . '%';

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_value FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value LIKE %s
                 LIMIT %d",
                $cap_meta_key, $sql_like, $limit
            ));

            $ccap_counts = [];      // [ccap_slug => user_count]
            $matching_users = [];   // [{id, email, display_name, matching_ccaps}]
            $matching_user_ids = [];

            foreach ($rows as $row) {
                $caps = maybe_unserialize($row->meta_value);
                if (!is_array($caps)) continue;

                $user_ccaps_matched = [];
                foreach ($caps as $cap_key_inner => $granted) {
                    if (!$granted) continue;
                    if (strpos((string) $cap_key_inner, $cap_prefix) !== 0) continue;
                    $ccap = substr((string) $cap_key_inner, strlen($cap_prefix));

                    $matches = false;
                    switch ($match) {
                        case 'ends_with':
                            $matches = (strlen($pattern) <= strlen($ccap)) &&
                                       (substr($ccap, -strlen($pattern)) === $pattern);
                            break;
                        case 'starts_with':
                            $matches = (strpos($ccap, $pattern) === 0);
                            break;
                        case 'exact':
                            $matches = ($ccap === $pattern);
                            break;
                        case 'contains':
                        default:
                            $matches = (strpos($ccap, $pattern) !== false);
                            break;
                    }
                    if ($matches) {
                        $user_ccaps_matched[] = $ccap;
                        $ccap_counts[$ccap] = ($ccap_counts[$ccap] ?? 0) + 1;
                    }
                }

                if (!empty($user_ccaps_matched)) {
                    $matching_user_ids[(int) $row->user_id] = true;
                    if ($include_users) {
                        $u = get_userdata((int) $row->user_id);
                        $matching_users[] = [
                            'id'             => (int) $row->user_id,
                            'email'          => $u ? $u->user_email : null,
                            'display_name'   => $u ? $u->display_name : null,
                            'matching_ccaps' => $user_ccaps_matched,
                        ];
                    }
                }
            }

            arsort($ccap_counts);

            return [
                'pattern'              => $pattern,
                'match'                => $match,
                'total_matching_users' => count($matching_user_ids),
                'ccap_breakdown'       => (object) $ccap_counts,
                'users'                => $include_users ? $matching_users : null,
                'site'                 => home_url(),
                'generated_at'         => current_time('c'),
                'rows_scanned'         => count($rows),
                'limit_used'           => $limit,
            ];
        },
    ]);
});

// ===========================================================================
// Append to xen-s2member-rest.php — ccap-filtered roster export
// ===========================================================================
//
// GET /wp-json/xen/v1/users/export-by-ccap
//
// Returns the COMPLETE user record set for a ccap (or ccap family) in the
// 14-column Admin Columns Pro export schema, plus verification counts.
//
// WHY THIS DOES NOT MATCH ON wp_capabilities ALONE
// ---------------------------------------------------------------------------
// s2Member STRIPS the ccap out of the root `wp_capabilities` blob when it
// demotes a user at EOT. Verified against live data 2026-07-30:
//
//   active  uva (id 12439): wp_capabilities = {s2member_level4,
//                                              access_s2member_ccap_uva}
//   demoted uva (id  3851): wp_capabilities = {demoted}       <-- ccap GONE
//
// So the sibling /users/by-ccap endpoint, which matches only that blob,
// silently under-reports every organisation with expired members. Measured
// on this install: it returns 121 for `uva` where the real roster is ~414,
// and returns ZERO for `dartdemo` because all of those accounts are demoted.
// That is the exact silent-incompleteness failure this endpoint exists to end.
//
// Two things DO survive demotion, and both are used here:
//
//   1. the flat `ccaps` usermeta key  (value: "uva")            <-- primary
//   2. the SUBSITE capability blobs (wp_2_capabilities, wp_3_…, wp_4_…),
//      which keep access_s2member_ccap_<slug> post-demotion     <-- secondary
//
// The endpoint returns the UNION of both, tags each user with the sources
// that matched, and reports any disagreement between the sources explicitly
// in `verification`. A discrepancy must be loud, never quietly resolved.
//
// Read-only. SELECT only, every query through $wpdb->prepare().

/**
 * Normalise a ccap argument into a list of lowercase slugs.
 * Accepts "a,b", "a b", ["a","b"], or "a".
 */
function xen_ccap_export_normalize_list($raw) {
    if (is_string($raw)) {
        $raw = preg_split('/[\s,|]+/', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $v) {
        $v = strtolower(trim((string) $v));
        if ($v !== '') {
            $out[$v] = true;
        }
    }
    return array_keys($out);
}

/** Exact-slug match against the wanted list, or prefix match. */
function xen_ccap_export_match($ccap, array $wanted, $prefix) {
    $c = strtolower(trim((string) $ccap));
    if ($c === '') {
        return false;
    }
    if (in_array($c, $wanted, true)) {
        return true;
    }
    if ($prefix !== '' && strpos($c, $prefix) === 0) {
        return true;
    }
    return false;
}

function xen_users_export_by_ccap($request) {
    global $wpdb;

    $wanted   = xen_ccap_export_normalize_list($request->get_param('ccap'));
    $prefix   = strtolower(trim((string) $request->get_param('ccap_prefix')));
    $per_page = (int) $request->get_param('per_page');
    $page     = max(1, (int) $request->get_param('page'));
    $max_scan = max(1, min(200000, (int) $request->get_param('max_scan')));
    $since    = trim((string) $request->get_param('since'));
    $until    = trim((string) $request->get_param('until'));
    $include_demoted = filter_var(
        $request->get_param('include_demoted'), FILTER_VALIDATE_BOOLEAN
    );
    $include_no_site_role = filter_var(
        $request->get_param('include_no_site_role'), FILTER_VALIDATE_BOOLEAN
    );
    // Optional role-slug allowlist, applied AFTER the ccap match so the
    // verification counts still describe the whole cohort.
    $role_filter = xen_ccap_export_normalize_list($request->get_param('role'));

    if (empty($wanted) && $prefix === '') {
        return new WP_Error(
            'bad_request',
            'Provide ccap (string or array) and/or ccap_prefix.',
            ['status' => 400]
        );
    }

    // A date-only `until` must cover the whole day, otherwise a same-day
    // registration sorts after it and is silently dropped.
    if ($until !== '' && strlen($until) === 10) {
        $until .= ' 23:59:59';
    }

    // Coarse SQL prefilter needles. Every hit is re-verified in PHP below,
    // so a needle that is too broad costs time, never correctness.
    $needles = $wanted;
    if ($prefix !== '') {
        $needles[] = $prefix;
    }

    $cap_prefix = 'access_s2member_ccap_';
    // s2Member writes its per-user keys on the BASE prefix even on multisite
    // (observed: wp_s2member_login_counter, never wp_2_s2member_login_counter).
    $p = $wpdb->base_prefix;

    // --- source 1: the flat `ccaps` usermeta key (survives demotion) --------
    $src_ccaps = [];
    $where1  = [];
    $params1 = ['ccaps'];
    foreach ($needles as $n) {
        $where1[]  = 'meta_value LIKE %s';
        $params1[] = '%' . $wpdb->esc_like($n) . '%';
    }
    $params1[] = $max_scan;
    $rows1 = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta}
         WHERE meta_key = %s AND (" . implode(' OR ', $where1) . ")
         LIMIT %d",
        $params1
    ));
    foreach ($rows1 as $row) {
        foreach (preg_split('/[\s,|]+/', (string) $row->meta_value) as $c) {
            if (xen_ccap_export_match($c, $wanted, $prefix)) {
                $src_ccaps[(int) $row->user_id][strtolower(trim($c))] = true;
            }
        }
    }

    // --- source 2: capability blobs, root + every subsite ------------------
    $src_caps = [];
    $where2  = [];
    $params2 = [$wpdb->esc_like($p) . '%capabilities'];
    foreach ($needles as $n) {
        $where2[]  = 'meta_value LIKE %s';
        $params2[] = '%' . $wpdb->esc_like($cap_prefix . $n) . '%';
    }
    $params2[] = $max_scan;
    $rows2 = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta}
         WHERE meta_key LIKE %s AND (" . implode(' OR ', $where2) . ")
         LIMIT %d",
        $params2
    ));
    foreach ($rows2 as $row) {
        $caps = maybe_unserialize($row->meta_value);
        if (!is_array($caps)) {
            continue;
        }
        foreach ($caps as $cap_key => $granted) {
            if (!$granted || strpos((string) $cap_key, $cap_prefix) !== 0) {
                continue;
            }
            $c = substr((string) $cap_key, strlen($cap_prefix));
            if (xen_ccap_export_match($c, $wanted, $prefix)) {
                $src_caps[(int) $row->user_id][strtolower($c)] = true;
            }
        }
    }

    $all_ids = array_values(array_unique(array_merge(
        array_keys($src_ccaps), array_keys($src_caps)
    )));
    sort($all_ids, SORT_NUMERIC);

    // Two queries total for the whole roster: one for wp_users rows, one for
    // every usermeta row. get_userdata()/get_user_meta() below hit cache.
    if (!empty($all_ids)) {
        cache_users($all_ids);
        update_meta_cache('user', $all_ids);
    }

    $role_names = wp_roles()->get_names();

    $records         = [];
    $counts_by_role  = [];
    $counts_by_ccap  = [];
    $role_slugs_seen = [];
    $excluded_demoted = 0;
    $excluded_by_date = 0;
    $excluded_no_site_role = 0;
    $excluded_by_role = 0;
    $no_site_role_ids = [];

    foreach ($all_ids as $uid) {
        $u = get_userdata($uid);
        if (!$u) {
            continue;
        }

        $role_slug  = !empty($u->roles) ? (string) reset($u->roles) : '';
        $role_label = ($role_slug !== '' && isset($role_names[$role_slug]))
            ? translate_user_role($role_names[$role_slug])
            : $role_slug;

        // No role on THIS blog means not a member of this site. Verified
        // 2026-07-30: GET /wp/v2/users/2328 returns 404 for one of these, so
        // WordPress itself does not consider them users here. They are
        // orphaned subsite capability rows (wp_2_/wp_3_/wp_4_capabilities)
        // left behind when the account was removed from the root blog — all
        // 2019-2022 vintage, with no login counter, no feed access and no
        // `ccaps` meta. Counting them inflated UVA from 414 to 471.
        //
        // Excluded by default, but COUNTED and sampled in `verification` so
        // the residue stays visible. Silently dropping them would be the same
        // class of bug as silently including them.
        if ($role_slug === '') {
            $excluded_no_site_role++;
            if (count($no_site_role_ids) < 25) {
                $no_site_role_ids[] = (int) $uid;
            }
            if (!$include_no_site_role) {
                continue;
            }
        }

        if (!$include_demoted && $role_slug === 'demoted') {
            $excluded_demoted++;
            continue;
        }

        if (!empty($role_filter) && !in_array($role_slug, $role_filter, true)) {
            $excluded_by_role++;
            continue;
        }

        $registered = $u->user_registered; // 'Y-m-d H:i:s', UTC
        if (($since !== '' && $registered < $since)
            || ($until !== '' && $registered > $until)) {
            $excluded_by_date++;
            continue;
        }

        $meta = function ($key) use ($uid) {
            $v = get_user_meta($uid, $key, true);
            return ($v === '' || $v === null || $v === false) ? null : $v;
        };

        $ccaps_matched = array_keys(
            ($src_ccaps[$uid] ?? []) + ($src_caps[$uid] ?? [])
        );
        sort($ccaps_matched);

        $sources = [];
        if (isset($src_ccaps[$uid])) { $sources[] = 'ccaps_meta'; }
        if (isset($src_caps[$uid]))  { $sources[] = 'capabilities'; }

        $coupons = $meta($p . 's2member_coupon_codes');
        if (is_array($coupons)) {
            $coupons = implode(';', array_filter(
                array_map('strval', $coupons), 'strlen'
            ));
            if ($coupons === '') {
                $coupons = null;
            }
        }

        $logins = $meta($p . 's2member_login_counter');
        // NOTE: `member_feed_access_qty` is the ONLY storage key for the
        // "Member Feed Login" column. The MCP's s2_custom_member_feed_qty
        // field is an alias of this same key, so the two can never disagree.
        $feed   = $meta('member_feed_access_qty');
        $eot    = $meta($p . 's2member_auto_eot_time');

        $records[] = [
            'user_id'           => (int) $uid,
            'username'          => $u->user_login,
            'name'              => $u->display_name,
            'email'             => $u->user_email,
            'role'              => $role_label,
            'role_slug'         => $role_slug,
            'ccap'              => implode(';', $ccaps_matched),
            'registered'        => $registered,
            'logins'            => ($logins === null) ? null : (int) $logins,
            'coupon_code'       => $coupons,
            'member_feed_login' => ($feed === null) ? null : (int) $feed,
            'reg_page_id'       => $meta('reg_page_id'),
            'first_name'        => $meta('first_name'),
            'last_name'         => $meta('last_name'),
            'newsletter_optin'  => $meta('newsletter_optin'),
            's2_eot'            => $eot,
            's2_eot_iso'        => $eot ? gmdate('c', (int) $eot) : null,
            'all_ccaps'         => $meta('ccaps'),
            'ccap_sources'      => $sources,
        ];

        $counts_by_role[$role_label]  = ($counts_by_role[$role_label] ?? 0) + 1;
        $role_slugs_seen[$role_slug]  = $role_label;
        foreach ($ccaps_matched as $c) {
            $counts_by_ccap[$c] = ($counts_by_ccap[$c] ?? 0) + 1;
        }
    }

    $total = count($records);
    if ($per_page <= 0) {
        // per_page=0 is the cheap "counts only" mode — the fast completeness
        // check without paying for the record payload.
        $page_records = [];
        $total_pages  = 0;
    } else {
        $total_pages  = (int) ceil($total / $per_page);
        $page_records = array_slice($records, ($page - 1) * $per_page, $per_page);
    }

    // A truncated scan yields a roster that looks complete and is not. Say so.
    $warnings = [];
    if (count($rows1) >= $max_scan) {
        $warnings[] = "ccaps-meta scan hit max_scan ({$max_scan}) — RESULTS MAY BE INCOMPLETE. Raise max_scan and re-run.";
    }
    if (count($rows2) >= $max_scan) {
        $warnings[] = "capabilities scan hit max_scan ({$max_scan}) — RESULTS MAY BE INCOMPLETE. Raise max_scan and re-run.";
    }

    arsort($counts_by_ccap);

    return [
        'users'          => $page_records,
        'total'          => $total,
        'total_pages'    => $total_pages,
        'page'           => $page,
        'per_page'       => $per_page,
        'counts_by_role' => (object) $counts_by_role,
        'counts_by_ccap' => (object) $counts_by_ccap,
        'query' => [
            'ccap'                 => $wanted,
            'ccap_prefix'          => $prefix,
            'include_demoted'      => $include_demoted,
            'include_no_site_role' => $include_no_site_role,
            'role'                 => $role_filter,
            'since'           => $since ?: null,
            'until'           => $until ?: null,
            'max_scan'        => $max_scan,
        ],
        // Cross-source agreement. `only_in_*` should normally be empty for
        // active-only cohorts and non-empty only where s2Member stripped the
        // capability on demotion. Anything unexpected here means investigate
        // before trusting the roster.
        'verification' => [
            'matched_by_ccaps_meta'   => count($src_ccaps),
            'matched_by_capabilities' => count($src_caps),
            'union_before_filters'    => count($all_ids),
            'only_in_ccaps_meta'      => array_values(array_diff(
                array_keys($src_ccaps), array_keys($src_caps)
            )),
            'only_in_capabilities'    => array_values(array_diff(
                array_keys($src_caps), array_keys($src_ccaps)
            )),
            'excluded_demoted'        => $excluded_demoted,
            'excluded_by_date'        => $excluded_by_date,
            // Ccap residue on accounts that are no longer users of this site.
            // Expected to be non-zero on long-running licences; a sudden jump
            // is worth a look before trusting the roster.
            'excluded_no_site_role'   => $excluded_no_site_role,
            'no_site_role_sample_ids' => $no_site_role_ids,
            'excluded_by_role'        => $excluded_by_role,
            'rows_scanned_ccaps_meta' => count($rows1),
            'rows_scanned_caps'       => count($rows2),
        ],
        'role_slugs_seen' => (object) $role_slugs_seen,
        'warnings'        => $warnings,
        'snapshot_date'   => current_time('Y-m-d'),
        'generated_at'    => current_time('c'),
        'site'            => home_url(),
    ];
}

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/users/export-by-ccap', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('list_users'); },
        'args' => [
            'ccap' => [
                'required'    => false,
                'description' => 'CCAP slug, comma-separated list, or array. e.g. "cmu" or "dartmouth,dartdemo".',
            ],
            'ccap_prefix' => [
                'default'           => '',
                'type'              => 'string',
                'description'       => 'Prefix match, e.g. "dart" matches dartmouth + dartdemo.',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'include_demoted' => [
                'default'     => true,
                'type'        => 'boolean',
                'description' => 'Include accounts demoted at EOT. Default true — they belong to the licence and count toward cumulative usage.',
            ],
            'include_no_site_role' => [
                'default'     => false,
                'type'        => 'boolean',
                'description' => 'Include ccap residue on accounts that are no longer users of this site (no role on the root blog). Default false — these are orphaned subsite capability rows, not members. Counted either way in verification.excluded_no_site_role.',
            ],
            'role' => [
                'default'           => '',
                'type'              => 'string',
                'description'       => 'Optional role-slug filter, comma-separated. e.g. "s2member_level4" for active only, or "demoted". Applied after the ccap match.',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'per_page' => [
                'default'     => 200,
                'type'        => 'integer',
                'description' => 'Records per page. 0 returns counts only (fast completeness check).',
            ],
            'page' => [
                'default' => 1,
                'type'    => 'integer',
            ],
            'max_scan' => [
                'default'     => 20000,
                'type'        => 'integer',
                'description' => 'Row cap on each prefilter scan. Hitting it emits a loud warning rather than silently truncating.',
            ],
            'since' => [
                'default'           => '',
                'type'              => 'string',
                'description'       => 'Only users registered on/after this date (YYYY-MM-DD).',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'until' => [
                'default'           => '',
                'type'              => 'string',
                'description'       => 'Only users registered on/before this date (YYYY-MM-DD).',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
        'callback' => 'xen_users_export_by_ccap',
    ]);
});

