<?php
/*
Plugin Name: XE Network — s2Member REST exposure
Description: Surfaces s2Member's user metadata + custom registration fields
             via the standard /wp-json/wp/v2/users endpoint, so the
             wordpress-xenetwork MCP can read subscription state, EOT
             timestamps, gateway IDs, login counts, and custom fields.
             Read-only; only exposes fields when context=edit (auth required).
Author: Justin Ritchie
Version: 1.3.0
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

            // Clone the paired welcome page too. The two are always created
            // together, and leaving welcome_page pointing at the SOURCE org's
            // page shipped a post-registration redirect to another
            // organisation's welcome screen. Defaults ON for that reason;
            // pass duplicate_welcome_page=false for the old behaviour.
            $welcome = null;
            $dup_welcome = $req->get_param('duplicate_welcome_page');
            $dup_welcome = ($dup_welcome === null) ? true : filter_var($dup_welcome, FILTER_VALIDATE_BOOLEAN);
            if ($dup_welcome && function_exists('xen_clone_welcome_for_ir')) {
                $welcome = xen_clone_welcome_for_ir($new_id, [
                    'welcome_slug'     => $req->get_param('welcome_slug') ?: $final_slug,
                    'institution_name' => $meta_overrides['institution_name'] ?? null,
                    // The IR page's content_replacements carry the org-name swaps
                    // already; reuse them so the caller states it once.
                    'replacements'     => $content_replacements + (array) ($req->get_param('welcome_replacements') ?: []),
                    'status'           => $status,
                ]);
                if (is_wp_error($welcome)) {
                    $welcome = ['ok' => false, 'error' => $welcome->get_error_code(),
                                'message' => $welcome->get_error_message()];
                }
            }

            return [
                'ok'                  => true,
                'new_id'              => $new_id,
                'welcome'             => $welcome,
                'welcome_page_id'     => is_array($welcome) ? ($welcome['welcome_page_id'] ?? null) : null,
                'welcome_slug'        => is_array($welcome) ? ($welcome['welcome_slug'] ?? null) : null,
                'welcome_permalink'   => is_array($welcome) ? ($welcome['welcome_permalink'] ?? null) : null,
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

// ===========================================================================
// POST /xen/v1/users/set-access — role + Auto-EOT writes
// ===========================================================================
//
// The ONLY user-write endpoint here. Everything else in this file is read-only.
// Two fields move: the membership role, and wp_s2member_auto_eot_time. Nothing
// else is writable, by construction — see XEN_SET_ACCESS_WRITABLE below.
//
// WHY THIS MUST NOT USE WP_User::set_role()
// ---------------------------------------------------------------------------
// set_role() REPLACES the whole capabilities array, which drops every
// access_s2member_ccap_<slug> entry with it. That is precisely the data loss
// s2Member itself inflicts on demotion at EOT, and the reason
// count_users_by_ccap reports 121 for `uva` against a real roster of 414.
// Reproducing it here would make demoted members vanish from their own roster.
//
// So a role change reads the existing blob, swaps ONLY the role key, and puts
// the ccap capabilities back. The flat `ccaps` usermeta key is never touched.
//
// WHY DIRECT META WRITES RATHER THAN THE ROLE API
// ---------------------------------------------------------------------------
// Two reasons. First, set_user_role fires hooks that s2Member listens on, and
// the entire point of the L4-to-L2 demotion is to SUPPRESS the "EOT Reminder
// L4" email — firing membership hooks risks sending the very mail we are
// trying to stop. Second, the role has to land on the subsite blobs too (see
// below), which the single-blog role API will not do. Caches are cleaned
// explicitly afterwards instead.
//
// WHICH BLOBS GET WRITTEN — the ticket's open question, answered from data
// ---------------------------------------------------------------------------
// All of them: the root blob and every wp_N_capabilities blob that already
// exists for that user. Verified against a real s2Member demotion (UVA user
// 3851), where s2Member set `demoted` on the root AND on wp_2_, wp_3_ and
// wp_4_capabilities. Writing the root alone would demote a member on the
// network root while leaving them at Level 4 on the ETS subsite — access
// removed in one place and live in another, which is worse than not acting.
//
// Note the asymmetry in s2Member's own behaviour: on that user the ccap
// SURVIVED on the subsite blobs and was stripped only from the root. This
// endpoint preserves it everywhere.

// Hard allowlist. This endpoint must not become a general usermeta writer.
function xen_set_access_writable_keys() {
    global $wpdb;
    return [
        $wpdb->base_prefix . 's2member_auto_eot_time',
        $wpdb->base_prefix . 's2member_notes',
        $wpdb->base_prefix . 's2member_access_cap_times',
        $wpdb->base_prefix . 's2member_subscr_id',
        $wpdb->base_prefix . 's2member_subscr_gateway',
        'xen_mcp_access_audit',
        // ccap writes, reachable ONLY through remove_ccap / add_ccap. Their
        // absence from this list was the root cause of the silent-failure bug:
        // preserve_ccaps=false modelled a change to stores the endpoint had no
        // permission or code to touch. Inside the capability blobs only the
        // access_s2member_ccap_* entries move; the role key is rewritten solely
        // when `level` is supplied, and no other capability is ever altered.
        'ccaps',
        $wpdb->base_prefix . 'capabilities [access_s2member_ccap_* only]',
        $wpdb->base_prefix . 'N_capabilities [access_s2member_ccap_* only; removal scans every subsite blob]',
    ];
}

// ===========================================================================
// GET /xen/v1/users/find-by-subscr-id — resolve a payment profile to a member
// ===========================================================================
//
// WHY A BROAD META SCAN RATHER THAN A LOOKUP ON wp_s2member_subscr_id
// ---------------------------------------------------------------------------
// s2Member CLEARS wp_s2member_subscr_id on demotion. Verified 2026-08-03 on
// users 7367 (Carson Witte) and 9412 (Miles Caldwell): neither has the key at
// all, and 9412's profile ID `I-0V2YT315BV52` survives ONLY as free text in
// wp_s2member_notes.
//
// That is fatal for the naive implementation, because the population you need
// to search is exactly the demoted one — a cancelled PayPal profile IS the
// event that demotes the member. A lookup restricted to wp_s2member_subscr_id
// would return not_found for every real case while appearing to work.
//
// So this scans across the keys where a profile ID can survive, and reports
// WHICH key matched. That distinction is the whole point: a hit in
// s2member_subscr_id is authoritative; a hit in `notes` is a human annotation;
// a hit in ipn_signup_vars is the gateway's own signup record. They warrant
// different confidence and the caller is told which it got.
//
// Read-only. Returns a trimmed record: a full user record runs several
// thousand tokens and makes batch reconciliation impossible.

function xen_subscr_match_confidence($meta_key) {
    global $wpdb;
    $p = $wpdb->base_prefix;
    if ($meta_key === $p . 's2member_subscr_id')       return ['authoritative', 'live subscription ID field'];
    if ($meta_key === $p . 's2member_ipn_signup_vars') return ['strong', 'gateway signup record from the IPN payload'];
    if ($meta_key === $p . 's2member_subscr_baid')     return ['strong', 'billing agreement ID'];
    if ($meta_key === $p . 's2member_notes')           return ['annotation', 'free text in the notes field — a human or tool wrote it, s2Member does not read it'];
    if ($meta_key === 'xen_mcp_access_audit')          return ['annotation', 'written by this MCP audit trail'];
    return ['weak', 'matched an unexpected meta key'];
}

function xen_users_find_by_subscr_id($request) {
    global $wpdb;

    // Input parsing, in this order deliberately.
    //
    // An earlier version split on commas FIRST, so a JSON array string like
    // ["I-AAA","I-BBB"] became ["I-AAA and "I-BBB"] — the bracketed first and
    // last elements then returned an ordinary not_found. On a tool whose entire
    // job is finding people, a malformed ID that looks like a genuine miss is
    // the worst available failure: it produces confident wrong conclusions
    // about whether a member exists. So: decode as JSON first, split second,
    // scrub stray delimiters third, and reject anything still malformed as a
    // DISTINCT error rather than letting it masquerade as "no such member".
    $raw = $request->get_param('subscr_id');

    if (is_string($raw)) {
        $trimmed = trim($raw);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            $raw = $decoded;                       // proper JSON array
        } elseif (strpos($trimmed, ',') !== false) {
            $raw = explode(',', $trimmed);         // comma list
        } else {
            $raw = [$trimmed];                     // single ID
        }
    }

    $ids = [];
    $malformed = [];
    foreach ((array) $raw as $v) {
        // Scrub delimiters that survive a hand-built or half-decoded array.
        $v = trim((string) $v);
        $v = trim($v, " \t\n\r\0\x0B[]\"'");
        if ($v === '') { continue; }
        // Gateway profile IDs are alphanumeric with - and _ only. Anything else
        // is input damage, and saying so beats reporting a phantom miss.
        if (!preg_match('~^[A-Za-z0-9_\-]+$~', $v)) {
            $malformed[] = $v;
            continue;
        }
        $ids[$v] = true;
    }
    $ids = array_keys($ids);

    if ($malformed) {
        return new WP_REST_Response([
            'ok'        => false,
            'error'     => 'malformed_subscr_id',
            'message'   => 'These are not valid subscription IDs (letters, digits, - and _ only). '
                         . 'Reporting this rather than not_found, so bad input is never mistaken '
                         . 'for a member who does not exist: ' . implode(', ', $malformed),
            'malformed' => $malformed,
            'parsed_ok' => $ids,
        ], 400);
    }
    if (!$ids) {
        return new WP_Error('bad_request', 'Provide subscr_id (string, comma list, or JSON array).', ['status' => 400]);
    }
    if (count($ids) > 50) {
        return new WP_Error('batch_too_large', 'Max 50 subscription IDs per call. Refusing (not truncating).', ['status' => 400]);
    }

    $p = $wpdb->base_prefix;
    $searchable = [
        $p . 's2member_subscr_id',
        $p . 's2member_ipn_signup_vars',
        $p . 's2member_subscr_baid',
        $p . 's2member_notes',
        'xen_mcp_access_audit',
    ];

    $results = [];
    foreach ($ids as $sid) {
        $like = '%' . $wpdb->esc_like($sid) . '%';
        $ph   = implode(',', array_fill(0, count($searchable), '%s'));
        $args = array_merge($searchable, [$like]);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_key FROM {$wpdb->usermeta}
              WHERE meta_key IN ({$ph}) AND meta_value LIKE %s
              LIMIT 25",
            $args
        ));

        if (!$rows) {
            $results[] = ['subscr_id' => $sid, 'status' => 'not_found',
                          'message' => 'No user record references this subscription ID in any searched key.'];
            continue;
        }

        // Group by user; a single member can match on several keys.
        $by_user = [];
        foreach ($rows as $r) {
            $by_user[(int) $r->user_id][] = $r->meta_key;
        }

        $matches = [];
        foreach ($by_user as $uid => $keys) {
            $u = get_userdata($uid);
            if (!$u) { continue; }
            $best = 'weak'; $best_key = null;
            $rank = ['authoritative' => 4, 'strong' => 3, 'annotation' => 2, 'weak' => 1];
            $sources = [];
            foreach ($keys as $k) {
                list($conf, $why) = xen_subscr_match_confidence($k);
                $sources[] = ['meta_key' => $k, 'confidence' => $conf, 'note' => $why];
                if ($rank[$conf] > $rank[$best]) { $best = $conf; $best_key = $k; }
            }
            $eot = get_user_meta($uid, $p . 's2member_auto_eot_time', true);
            $matches[] = [
                'id'            => (int) $uid,
                'email'         => $u->user_email,
                'username'      => $u->user_login,
                'display_name'  => $u->display_name,
                'roles'         => $u->roles,
                'auto_eot'      => $eot === '' ? null : $eot,
                'auto_eot_iso'  => $eot ? gmdate('c', (int) $eot) : null,
                'subscr_id'     => get_user_meta($uid, $p . 's2member_subscr_id', true) ?: null,
                'subscr_gateway'=> get_user_meta($uid, $p . 's2member_subscr_gateway', true) ?: null,
                'paid_registration_times' => maybe_unserialize(
                    get_user_meta($uid, $p . 's2member_paid_registration_times', true)) ?: null,
                'match_confidence' => $best,
                'matched_in'       => $sources,
            ];
        }

        $results[] = [
            'subscr_id' => $sid,
            'status'    => count($matches) === 1 ? 'found' : (count($matches) > 1 ? 'ambiguous' : 'not_found'),
            'match_count' => count($matches),
            'users'     => $matches,
        ];
    }

    $summary = [];
    foreach ($results as $r) { $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1; }

    return [
        'ok'      => true,
        'count'   => count($results),
        'summary' => $summary,
        'results' => $results,
        'searched_keys' => $searchable,
        'note'    => 'Confidence matters: s2Member CLEARS subscr_id on demotion, so a cancelled '
                   . 'profile often survives only as an annotation in notes. An "annotation" match '
                   . 'is a human record, not an s2Member field — treat it as a lead, not proof.',
        '_meta'   => ['site' => home_url(), 'plugin' => 'xen-s2member-rest'],
    ];
}

// ===========================================================================
// GET /xen/v1/diag/s2member-renewal-refs — does renewal automation read
// subscr_id?
// ===========================================================================
//
// The open question after restoring a subscription ID onto a CANCELLED gateway
// profile: will s2Member's reminder/renewal cron treat the member as actively
// subscribed and email them about a renewal that cannot bill?
//
// Rather than reason about it, grep s2Member's own source for reads of the
// subscr_id meta key inside its reminder/cron paths. Read-only, returns file
// and line references only.

function xen_s2_renewal_refs() {
    $roots = [WP_PLUGIN_DIR . '/s2member', WP_PLUGIN_DIR . '/s2member-pro'];
    $hits = []; $scanned = 0; $present = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) { continue; }
        $present[] = basename($root);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || substr($f->getFilename(), -4) !== '.php') { continue; }
            $name = strtolower($f->getPathname());
            // Only the paths that could send a member an email on a schedule.
            if (!preg_match('~(remind|cron|sched|eot|auto_eot|renew)~', $name)) { continue; }
            $scanned++;
            $lines = @file($f->getPathname());
            if (!$lines) { continue; }
            foreach ($lines as $n => $line) {
                if (strpos($line, 'subscr_id') !== false) {
                    $hits[] = [
                        'file' => str_replace(WP_PLUGIN_DIR . '/', '', $f->getPathname()),
                        'line' => $n + 1,
                        'code' => trim(mb_substr($line, 0, 160)),
                    ];
                    if (count($hits) >= 40) { break 3; }
                }
            }
        }
    }
    return [
        'ok' => true,
        'plugins_present'      => $present,
        'files_scanned'        => $scanned,
        'subscr_id_references' => $hits,
        'reference_count'      => count($hits),
        'interpretation' => count($hits)
            ? 'subscr_id IS read inside reminder/cron/EOT paths — restoring it on a '
              . 'cancelled gateway profile could produce renewal messaging that cannot '
              . 'bill. Review the lines above before doing this in bulk.'
            : 'No reads of subscr_id found in reminder/cron/EOT paths. On this evidence '
              . 'restoring the ID is a record-keeping change, and Auto-EOT governs both '
              . 'access and messaging. Note this greps only files whose PATH matches '
              . 'remind/cron/sched/eot/renew — it is strong evidence, not a proof.',
        '_meta' => ['site' => home_url(), 'plugin' => 'xen-s2member-rest'],
    ];
}

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/diag/s2member-renewal-refs', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => 'xen_s2_renewal_refs',
    ]);

    register_rest_route('xen/v1', '/users/find-by-subscr-id', [
        'methods'             => 'GET',
        'permission_callback' => function () { return current_user_can('list_users'); },
        'args' => [
            'subscr_id' => ['required' => true,
                            'description' => 'One profile ID, a comma list, or a JSON array. Max 50.'],
        ],
        'callback' => 'xen_users_find_by_subscr_id',
    ]);
});

function xen_set_access_resolve_user($ident) {
    $ident = trim((string) $ident);
    if ($ident === '') {
        return null;
    }
    if (ctype_digit($ident)) {
        $u = get_user_by('id', (int) $ident);
        if ($u) return $u;
    }
    if (is_email($ident)) {
        $u = get_user_by('email', $ident);
        if ($u) return $u;
    }
    return get_user_by('login', $ident) ?: get_user_by('slug', $ident) ?: null;
}

// Accepts 0-5, 's2member_level2', or any registered role slug such as
// 'demoted'. Returns the role slug, or null if it is not a REGISTERED role —
// inventing a pseudo-role would produce a user with no working capabilities.
function xen_set_access_normalize_role($level) {
    if ($level === null || $level === '') {
        return null;
    }
    $raw = strtolower(trim((string) $level));
    if (ctype_digit($raw) && (int) $raw >= 0 && (int) $raw <= 5) {
        $raw = 's2member_level' . (int) $raw;
    }
    $names = wp_roles()->get_names();
    return isset($names[$raw]) ? $raw : null;
}

// 'clear' empties the field. A bare YYYY-MM-DD becomes midnight UTC that day;
// a unix integer is used verbatim. Those are NOT the same instant, and the
// difference is real access time, so the caller is told which was applied.
function xen_set_access_normalize_eot($eot, &$how) {
    $how = null;
    if ($eot === null || $eot === '') {
        return ['skip' => true];
    }
    $raw = trim((string) $eot);
    if (strtolower($raw) === 'clear') {
        $how = 'clear';
        return ['value' => '', 'clear' => true];
    }
    if (ctype_digit($raw)) {
        $how = 'unix';
        return ['value' => (string) (int) $raw];
    }
    if (preg_match('~^\d{4}-\d{2}-\d{2}$~', $raw)) {
        $ts = strtotime($raw . ' 00:00:00 UTC');
        if ($ts === false) {
            return ['error' => "unparseable date: {$raw}"];
        }
        $how = 'date_midnight_utc';
        return ['value' => (string) $ts];
    }
    $ts = strtotime($raw . ' UTC');
    if ($ts === false) {
        return ['error' => "unparseable auto_eot: {$raw}"];
    }
    $how = 'parsed';
    return ['value' => (string) $ts];
}

// Every capabilities blob this user actually has: root plus any subsite.
function xen_set_access_cap_keys($user_id) {
    global $wpdb;
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_key FROM {$wpdb->usermeta}
         WHERE user_id = %d AND meta_key LIKE %s",
        $user_id, $wpdb->esc_like($wpdb->base_prefix) . '%capabilities'
    ));
    return is_array($rows) ? $rows : [];
}

function xen_set_access_ccaps_in($caps) {
    $out = [];
    if (is_array($caps)) {
        foreach ($caps as $k => $v) {
            if ($v && strpos((string) $k, 'access_s2member_ccap_') === 0) {
                $out[] = $k;
            }
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// ccap add/remove — the two stores, and why both must move together
// ---------------------------------------------------------------------------
//
// list_users_by_ccap (see /users/export-by-ccap above) reads the UNION of:
//
//   1. the flat `ccaps` usermeta key      — a delimited slug list, e.g. "secant"
//   2. access_s2member_ccap_<slug> inside the capability blobs
//
// so a removal that touches one store and not the other does not remove the
// member from the roster — it makes the two sources disagree, which surfaces as
// only_in_ccaps_meta / only_in_capabilities. That state is detectable, and
// wrong, and there are already 298 users in it from other causes. Every write
// below moves both stores or neither.
//
// WHICH BLOBS — measured 2026-08-14, not assumed. The ccap's location varies by
// membership state, so neither "root only" nor "all blobs" is correct:
//
//   user 11900 (active, level2): access_s2member_ccap_secant in wp_capabilities
//                                ONLY. wp_2_/wp_3_/wp_4_ hold just the role.
//   user  3851 (demoted at EOT): ccap STRIPPED from wp_capabilities, but alive
//                                in wp_2_, wp_3_ AND wp_4_capabilities.
//
// Hence the asymmetry, which is deliberate:
//
//   REMOVE scans EVERY blob. Removing from the root alone would leave a
//          demoted member on the roster via the subsite blobs — the exact
//          shape user 3851 is in.
//   ADD    writes the ROOT blob only, because that is the shape s2Member
//          itself creates for an active member. Mirroring a grant into the
//          subsite blobs would invent state s2Member never creates, the same
//          reason the subscription-identity write below stays on base_prefix.
//
// The flat `ccaps` key moves in both directions regardless — it is the store
// that survives demotion and the roster's primary source.

/**
 * Normalise a ccap argument into bare lowercase slugs.
 *
 * Accepts "a", "a,b", "a b", "a|b", '["a","b"]', or a real array, because
 * mcp-remote flattens anyOf schemas in transit and a caller genuinely cannot
 * tell which shape this wants. Also tolerates a full capability key
 * (access_s2member_ccap_uva -> uva): every response in this file reports ccaps
 * in cap-key form, so a caller pasting one straight back is the expected
 * mistake, not an exotic one.
 *
 * Returns ['slugs' => [...], 'invalid' => [...]]. Invalid input is REPORTED,
 * never dropped — silently discarding a malformed slug would turn "remove
 * dartmouth" into a no-op that still reports success.
 */
function xen_ccap_parse_arg($raw) {
    if ($raw === null || $raw === '' || $raw === []) {
        return ['slugs' => [], 'invalid' => []];
    }
    if (is_string($raw)) {
        $t = trim($raw);
        $decoded = json_decode($t, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/[\s,|]+/', $t);
    }
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $slugs = [];
    $invalid = [];
    foreach ($raw as $v) {
        $v = strtolower(trim((string) $v));
        $v = trim($v, " \t\n\r\0\x0B[]\"'");
        if ($v === '') {
            continue;
        }
        if (strpos($v, 'access_s2member_ccap_') === 0) {
            $v = substr($v, strlen('access_s2member_ccap_'));
        }
        if (!preg_match('~^[a-z0-9_\-]+$~', $v)) {
            $invalid[] = $v;
            continue;
        }
        $slugs[$v] = true;
    }
    return ['slugs' => array_keys($slugs), 'invalid' => $invalid];
}

/** Split the flat `ccaps` usermeta value into slugs, original order kept. */
function xen_ccap_flat_split($value) {
    $out = [];
    foreach (preg_split('/[\s,|]+/', (string) $value) as $c) {
        $c = strtolower(trim($c));
        if ($c !== '' && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    return $out;
}

/**
 * The flat `ccaps` value after removals and additions.
 *
 * PURE, and called by BOTH the dry-run plan and the write. That is not tidiness
 * — it is how the dry run is kept honest. The previous bug was a plan that
 * modelled an outcome the write did not implement; two code paths computing the
 * same answer separately is exactly how that reappears. One function, two
 * callers, so the prediction cannot drift from the result.
 *
 * Slugs the caller did not name are preserved verbatim, which is the whole
 * point on a multi-ccap account.
 */
function xen_ccap_flat_apply($current, array $remove, array $add) {
    $out = [];
    foreach (xen_ccap_flat_split($current) as $c) {
        if (!in_array($c, $remove, true)) {
            $out[] = $c;
        }
    }
    foreach ($add as $c) {
        if (!in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    return implode(',', $out);
}

/** The capability-key ccap set after removals and additions. Pure; same rule. */
function xen_ccap_caps_apply(array $before_keys, array $remove, array $add) {
    $strip = [];
    foreach ($remove as $s) {
        $strip['access_s2member_ccap_' . $s] = true;
    }
    $out = [];
    foreach ($before_keys as $k) {
        if (!isset($strip[$k]) && !in_array($k, $out, true)) {
            $out[] = $k;
        }
    }
    foreach ($add as $s) {
        $k = 'access_s2member_ccap_' . $s;
        if (!in_array($k, $out, true)) {
            $out[] = $k;
        }
    }
    sort($out);
    return $out;
}

function xen_users_set_access($request) {
    global $wpdb;

    $raw_dry = $request->get_param('dry_run');
    $dry_run = ($raw_dry === null) ? true : rest_sanitize_boolean($raw_dry);
    $preserve = $request->get_param('preserve_ccaps');
    $preserve_ccaps = ($preserve === null) ? true : rest_sanitize_boolean($preserve);

    // `preserve_ccaps=false` was accepted, and did nothing. The plan below
    // predicted ccaps_after = [] while the write left every ccap in place: the
    // role rewrite `continue`s past access_s2member_ccap_* keys and never
    // strips them, so the "restore the ccaps" branch it guards was already a
    // no-op. The parameter had no implementation behind it in either store.
    //
    // A missing feature is a nuisance. A DRY RUN THAT PREDICTS A CHANGE THE
    // WRITE CANNOT MAKE is a safety defect, because the preview exists so an
    // operator can trust it before touching a live membership. Refusing the
    // argument is the honest failure: the caller learns immediately, instead
    // of reading "applied" over an unchanged record.
    // Still refused now that removal EXISTS, and deliberately so. A boolean
    // cannot say WHICH ccap to drop, so on a multi-ccap account it can only
    // mean "remove all of them" — an irreversible superset of what the caller
    // usually wants, expressed by a flag that reads like a safety toggle.
    // remove_ccap names its target; the flag stays refused rather than being
    // quietly rehabilitated into an alias for it.
    if ($preserve_ccaps === false) {
        return new WP_Error(
            'preserve_ccaps_unsupported',
            'preserve_ccaps=false is not implemented and is refused rather than ignored '
            . '(it used to be accepted, predicted in the dry run, and silently dropped). '
            . 'Use remove_ccap="<slug>" to take a specific ccap off a member — it names '
            . 'its target, and it updates BOTH the `ccaps` usermeta key and the '
            . 'access_s2member_ccap_* entry in every capability blob, which is what '
            . 'actually removes someone from a roster.',
            ['status' => 400]
        );
    }
    $allow_partial = rest_sanitize_boolean($request->get_param('allow_partial'));
    $reason = trim((string) $request->get_param('reason'));
    $max_batch = (int) ($request->get_param('max_batch') ?: 100);

    $batch_level = $request->get_param('level');
    $batch_eot   = $request->get_param('auto_eot');

    $users = $request->get_param('users');
    if (is_string($users) && $users !== '') {
        $decoded = json_decode($users, true);
        $users = is_array($decoded) ? $decoded : null;
    }
    if (!is_array($users) || !count($users)) {
        $ident = $request->get_param('user');
        if ($ident === null || $ident === '') {
            return new WP_Error('bad_request', 'Provide `user`, or a `users` array.', ['status' => 400]);
        }
        $users = [['identifier' => $ident]];
    }

    // Refuse an oversized batch outright. Truncating would silently half-apply
    // a cancellation, which is the failure mode this whole surface exists to
    // avoid.
    if (count($users) > $max_batch) {
        return new WP_Error(
            'batch_too_large',
            'Batch of ' . count($users) . " exceeds max_batch {$max_batch}. Refusing (not truncating).",
            ['status' => 400]
        );
    }

    if ($reason === '') {
        return new WP_Error('bad_request', '`reason` is required — it is the audit trail.', ['status' => 400]);
    }

    // ---- resolve and validate EVERYTHING before writing anything ----------
    $plans = [];
    $fatal = [];
    foreach ($users as $i => $row) {
        if (!is_array($row)) {
            $row = ['identifier' => $row];
        }
        $ident = $row['identifier'] ?? ($row['user'] ?? ($row['email'] ?? ($row['user_id'] ?? null)));
        $u = xen_set_access_resolve_user($ident);
        if (!$u) {
            $plans[] = ['identifier' => $ident, 'status' => 'not_found'];
            $fatal[] = "row {$i}: no user matched " . json_encode($ident);
            continue;
        }

        $want_level = array_key_exists('level', $row) ? $row['level'] : $batch_level;
        $want_eot   = array_key_exists('auto_eot', $row) ? $row['auto_eot'] : $batch_eot;

        $new_role = null;
        if ($want_level !== null && $want_level !== '') {
            $new_role = xen_set_access_normalize_role($want_level);
            if ($new_role === null) {
                $plans[] = ['identifier' => $ident, 'user_id' => $u->ID, 'status' => 'invalid_level'];
                $fatal[] = "row {$i}: '{$want_level}' is not a registered role. Registered: "
                    . implode(', ', array_keys(wp_roles()->get_names()));
                continue;
            }
        }

        $how = null;
        $eot = xen_set_access_normalize_eot($want_eot, $how);
        if (isset($eot['error'])) {
            $plans[] = ['identifier' => $ident, 'user_id' => $u->ID, 'status' => 'invalid_auto_eot'];
            $fatal[] = "row {$i}: " . $eot['error'];
            continue;
        }

        // NOTE: the "nothing requested" check lives AFTER the subscription
        // block below, not here. Restoring a wiped subscr_id is a legitimate
        // standalone operation — an earlier version rejected it because this
        // guard ran before subscr_id was parsed.

        $eot_key = $wpdb->base_prefix . 's2member_auto_eot_time';
        $old_eot = get_user_meta($u->ID, $eot_key, true);
        $old_role = !empty($u->roles) ? (string) reset($u->roles) : '';

        // --- subscription identity ------------------------------------------
        // s2Member CLEARS these on demotion, so restoring access after a
        // resolved chargeback leaves the member unidentifiable from a gateway
        // notice. Verified 2026-08-03: users 7367 and 9412 have neither key.
        $sid_key = $wpdb->base_prefix . 's2member_subscr_id';
        $gw_key  = $wpdb->base_prefix . 's2member_subscr_gateway';
        $old_sid = get_user_meta($u->ID, $sid_key, true);
        $old_gw  = get_user_meta($u->ID, $gw_key, true);

        $want_sid = array_key_exists('subscr_id', $row) ? $row['subscr_id'] : $request->get_param('subscr_id');
        $want_gw  = array_key_exists('subscr_gateway', $row) ? $row['subscr_gateway'] : $request->get_param('subscr_gateway');

        $new_sid = null; $sid_clear = false;
        if ($want_sid !== null && $want_sid !== '') {
            if (strtolower(trim((string) $want_sid)) === 'clear') {
                $sid_clear = true; $new_sid = '';
            } else {
                $new_sid = trim((string) $want_sid);
            }
        }
        $new_gw = null; $gw_clear = false;
        if ($want_gw !== null && $want_gw !== '') {
            if (strtolower(trim((string) $want_gw)) === 'clear') {
                $gw_clear = true; $new_gw = '';
            } else {
                $new_gw = strtolower(trim((string) $want_gw));
                if (!in_array($new_gw, ['paypal', 'stripe', 'free', 'manual'], true)) {
                    $plans[] = ['identifier' => $ident, 'user_id' => $u->ID, 'status' => 'invalid_gateway'];
                    $fatal[] = "row {$i}: subscr_gateway '{$want_gw}' is not one of paypal, stripe, free, manual.";
                    continue;
                }
            }
        }
        // Writing an ID without naming the gateway leaves s2Member guessing how
        // to interpret it. Require the pair.
        if ($new_sid !== null && !$sid_clear && $new_gw === null && $old_gw === '') {
            $plans[] = ['identifier' => $ident, 'user_id' => $u->ID, 'status' => 'gateway_required'];
            $fatal[] = "row {$i}: subscr_id supplied but no subscr_gateway, and none is stored. "
                     . 'Pass subscr_gateway so s2Member knows how to read the ID.';
            continue;
        }

        $blobs = [];
        foreach (xen_set_access_cap_keys($u->ID) as $ck) {
            $caps = maybe_unserialize(get_user_meta($u->ID, $ck, true));
            $blobs[$ck] = [
                'caps'  => is_array($caps) ? $caps : [],
                'ccaps' => xen_set_access_ccaps_in($caps),
            ];
        }
        $ccaps_before = [];
        foreach ($blobs as $b) {
            $ccaps_before = array_merge($ccaps_before, $b['ccaps']);
        }
        $ccaps_before = array_values(array_unique($ccaps_before));
        sort($ccaps_before); // stable order, so prediction and readback compare cleanly

        // --- ccap add / remove ----------------------------------------------
        $want_rm  = array_key_exists('remove_ccap', $row) ? $row['remove_ccap'] : $request->get_param('remove_ccap');
        $want_add = array_key_exists('add_ccap', $row)    ? $row['add_ccap']    : $request->get_param('add_ccap');

        $parsed_rm  = xen_ccap_parse_arg($want_rm);
        $parsed_add = xen_ccap_parse_arg($want_add);
        if ($parsed_rm['invalid'] || $parsed_add['invalid']) {
            $plans[] = ['identifier' => $ident, 'user_id' => $u->ID, 'status' => 'invalid_ccap'];
            $fatal[] = "row {$i}: not valid ccap slugs (lowercase letters, digits, - and _ only): "
                     . implode(', ', array_merge($parsed_rm['invalid'], $parsed_add['invalid']));
            continue;
        }
        $rm_slugs  = $parsed_rm['slugs'];
        $add_slugs = $parsed_add['slugs'];

        // Naming the same slug on both sides has no defensible reading, and
        // guessing an order would make the result depend on an implementation
        // detail the caller cannot see.
        $both = array_values(array_intersect($rm_slugs, $add_slugs));
        if ($both) {
            $plans[] = ['identifier' => $ident, 'user_id' => $u->ID, 'status' => 'ccap_conflict'];
            $fatal[] = "row {$i}: " . implode(', ', $both)
                     . ' appears in BOTH remove_ccap and add_ccap. Refusing rather than picking an order.';
            continue;
        }

        $flat_before = (string) get_user_meta($u->ID, 'ccaps', true);
        // Both predictions come from the pure helpers the WRITE also calls, so
        // the dry run cannot promise an outcome the write will not produce.
        $ccaps_after_pred = xen_ccap_caps_apply($ccaps_before, $rm_slugs, $add_slugs);
        $flat_after_pred  = xen_ccap_flat_apply($flat_before, $rm_slugs, $add_slugs);

        // A requested removal of a ccap the member does not hold is a no-op, not
        // an error — but it is reported, because "nothing to remove" and
        // "removed" look identical in an empty result and the difference is
        // whether the operator targeted the right person.
        $rm_noop = [];
        foreach ($rm_slugs as $s) {
            $in_caps = in_array('access_s2member_ccap_' . $s, $ccaps_before, true);
            $in_flat = in_array($s, xen_ccap_flat_split($flat_before), true);
            if (!$in_caps && !$in_flat) {
                $rm_noop[] = $s;
            }
        }

        // Now that every field is parsed, decide whether anything was asked for.
        if ($new_role === null && !empty($eot['skip']) && $new_sid === null && $new_gw === null
            && !$rm_slugs && !$add_slugs) {
            $plans[] = ['identifier' => $ident, 'user_id' => $u->ID, 'status' => 'nothing_requested'];
            $fatal[] = "row {$i}: nothing supplied — needs at least one of "
                     . 'level, auto_eot, subscr_id, subscr_gateway, remove_ccap or add_ccap.';
            continue;
        }

        $role_changes = ($new_role !== null && $new_role !== $old_role);
        $eot_changes  = !empty($eot['skip']) ? false : ((string) $old_eot !== (string) $eot['value']);
        $sid_changes  = ($new_sid !== null) && ((string) $old_sid !== (string) $new_sid);
        $gw_changes   = ($new_gw !== null)  && ((string) $old_gw  !== (string) $new_gw);
        $ccap_changes = ($ccaps_after_pred !== $ccaps_before)
                        || ($flat_after_pred !== $flat_before);

        $plans[] = [
            'identifier'      => $ident,
            'user_id'         => $u->ID,
            'email'           => $u->user_email,
            'username'        => $u->user_login,
            'old_level'       => $old_role,
            'new_level'       => $new_role ?? $old_role,
            'old_auto_eot'    => ($old_eot === '' ? null : $old_eot),
            'new_auto_eot'    => !empty($eot['skip']) ? ($old_eot === '' ? null : $old_eot)
                                                      : ($eot['value'] === '' ? null : $eot['value']),
            'auto_eot_source' => $how,
            'old_subscr_id'      => $old_sid === '' ? null : $old_sid,
            'new_subscr_id'      => $new_sid === null ? ($old_sid === '' ? null : $old_sid)
                                                      : ($new_sid === '' ? null : $new_sid),
            'old_subscr_gateway' => $old_gw === '' ? null : $old_gw,
            'new_subscr_gateway' => $new_gw === null ? ($old_gw === '' ? null : $old_gw)
                                                     : ($new_gw === '' ? null : $new_gw),
            'ccaps_before'    => $ccaps_before,
            // Not `$preserve_ccaps ? $ccaps_before : []`. That ternary was the
            // lie: its false branch promised an empty ccap set that no code
            // path could produce. This is now computed by the same pure helper
            // the write calls, so prediction and result agree by construction
            // rather than by coincidence.
            'ccaps_after'     => $ccaps_after_pred,
            'flat_ccaps_meta' => $flat_before === '' ? null : $flat_before,
            // Reported in the DRY RUN too, not just after a write. The whole
            // failure this replaces was a preview that omitted the store it
            // could not change.
            'flat_ccaps_meta_after' => $flat_after_pred === '' ? null : $flat_after_pred,
            'ccaps_removed'   => $rm_slugs,
            'ccaps_added'     => $add_slugs,
            // Slugs asked to be removed that this member never had. Harmless,
            // but it usually means the wrong user or a mistyped slug, and an
            // empty diff looks identical to a successful removal.
            'ccaps_remove_noop' => $rm_noop,
            // Where the ccap actually lives, per blob. Location varies with
            // membership state (active: root only; demoted: subsites only), so
            // this is the difference between a removal that works and one that
            // half-works.
            'ccaps_by_blob'   => array_map(function ($b) { return $b['ccaps']; }, $blobs),
            'capability_blobs'=> array_keys($blobs),
            'status'          => ($role_changes || $eot_changes || $sid_changes || $gw_changes || $ccap_changes)
                                    ? 'would_change' : 'already_set',
            '_apply' => [
                'role'      => $role_changes ? $new_role : null,
                'eot'       => $eot_changes ? $eot['value'] : null,
                'eot_clear' => !empty($eot['clear']),
                'sid'       => $sid_changes ? $new_sid : null,
                'sid_clear' => $sid_clear,
                'gw'        => $gw_changes ? $new_gw : null,
                'gw_clear'  => $gw_clear,
                'blobs'     => $blobs,
                'ccap_rm'   => $rm_slugs,
                'ccap_add'  => $add_slugs,
                // The predictions carried forward so the readback can be
                // checked against WHAT WAS PROMISED, not merely against
                // "something changed".
                'ccaps_after_pred' => $ccaps_after_pred,
                'flat_after_pred'  => $flat_after_pred,
            ],
        ];
    }

    // Any failure aborts the WHOLE batch unless explicitly allowed to proceed.
    if ($fatal && !$allow_partial) {
        foreach ($plans as &$p) {
            unset($p['_apply']);
        }
        unset($p);
        return new WP_REST_Response([
            'ok'      => false,
            'error'   => 'batch_aborted',
            'message' => 'Nothing was written. Resolve these and re-run: ' . implode(' | ', $fatal),
            'errors'  => $fatal,
            'dry_run' => $dry_run,
            'users'   => $plans,
        ], 400);
    }

    $result = [
        'ok'      => true,
        'dry_run' => $dry_run,
        'reason'  => $reason,
        'preserve_ccaps' => $preserve_ccaps,
        'count'   => count($plans),
        'users'   => [],
        'warnings' => $fatal ? ['proceeded with allow_partial: ' . implode(' | ', $fatal)] : [],
        '_meta'   => [
            'site_url' => home_url(),
            'plugin'   => 'xen-s2member-rest',
            'writable_keys' => xen_set_access_writable_keys(),
        ],
    ];

    foreach ($plans as $p) {
        $apply = $p['_apply'] ?? null;
        unset($p['_apply']);

        if ($dry_run || !$apply || $p['status'] !== 'would_change') {
            if ($dry_run && $p['status'] === 'would_change') {
                $p['status'] = 'would_change';
            }
            $result['users'][] = $p;
            continue;
        }

        $uid = $p['user_id'];

        // --- capability blobs: role swap and/or ccap add/remove ------------
        //
        // One pass, one write per blob. The role swap and the ccap edit used to
        // be separable because only the role ever moved; doing them in two
        // passes now would write each blob twice and leave the readback unable
        // to say which pass produced the result.
        //
        // Ccap keys are still skipped by the role-stripping loop — that is what
        // stops a demotion from destroying roster membership. Removal is
        // explicit and targeted, below, never a side effect of a role change.
        $root_blob = $wpdb->base_prefix . 'capabilities';
        if ($apply['role'] !== null || $apply['ccap_rm'] || $apply['ccap_add']) {
            foreach ($apply['blobs'] as $cap_key => $info) {
                $caps = $info['caps'];

                if ($apply['role'] !== null) {
                    foreach (array_keys($caps) as $k) {
                        if (strpos((string) $k, 'access_s2member_ccap_') === 0) {
                            continue; // ccaps are not roles; leave them be
                        }
                        unset($caps[$k]);
                    }
                    $caps[$apply['role']] = true;
                }

                // REMOVE from every blob. An active member carries the ccap on
                // the root only; a member s2Member demoted at EOT carries it on
                // the subsites only. Scanning all of them is the only rule that
                // is correct for both.
                foreach ($apply['ccap_rm'] as $slug) {
                    unset($caps['access_s2member_ccap_' . $slug]);
                }

                // ADD to the root blob only — the shape s2Member itself creates
                // for an active member (verified on users 11900 and 11901).
                if ($cap_key === $root_blob) {
                    foreach ($apply['ccap_add'] as $slug) {
                        $caps['access_s2member_ccap_' . $slug] = true;
                    }
                }

                update_user_meta($uid, $cap_key, $caps);
            }
        }

        // --- the flat `ccaps` usermeta key ---------------------------------
        //
        // The store that survives demotion, and the roster's primary source.
        // Moving the capability blobs without this one does not remove the
        // member from list_users_by_ccap — it just makes the two sources
        // disagree, which is a worse state than before because it looks like
        // data corruption rather than a pending task.
        //
        // Computed by the same pure helper the dry run used, so what was
        // predicted is definitionally what gets written.
        if ($apply['ccap_rm'] || $apply['ccap_add']) {
            update_user_meta($uid, 'ccaps', $apply['flat_after_pred']);
        }

        // --- auto-EOT ------------------------------------------------------
        $eot_key = $wpdb->base_prefix . 's2member_auto_eot_time';
        if ($apply['eot'] !== null || $apply['eot_clear']) {
            if ($apply['eot_clear']) {
                delete_user_meta($uid, $eot_key);
            } else {
                update_user_meta($uid, $eot_key, $apply['eot']);
            }
        }

        // --- subscription identity -----------------------------------------
        // Base prefix only. Verified 2026-08-03: s2Member does NOT mirror
        // subscr_id/gateway to the wp_N_ subsite blobs the way it mirrors
        // capabilities and access_cap_times — user 9412's record carries
        // wp_2_/wp_3_/wp_4_s2member_access_cap_times but no subsite subscr key.
        // Writing subsite copies would invent state s2Member never creates.
        $sid_key = $wpdb->base_prefix . 's2member_subscr_id';
        $gw_key  = $wpdb->base_prefix . 's2member_subscr_gateway';
        if ($apply['sid_clear']) {
            delete_user_meta($uid, $sid_key);
        } elseif ($apply['sid'] !== null) {
            update_user_meta($uid, $sid_key, $apply['sid']);
        }
        if ($apply['gw_clear']) {
            delete_user_meta($uid, $gw_key);
        } elseif ($apply['gw'] !== null) {
            update_user_meta($uid, $gw_key, $apply['gw']);
        }

        // --- audit: s2Member notes + our own key, both APPEND --------------
        $stamp = gmdate('c');
        $note_key = $wpdb->base_prefix . 's2member_notes';
        $notes = (string) get_user_meta($uid, $note_key, true);
        $line = "MCP set-access {$stamp}: level {$p['old_level']} -> {$p['new_level']}"
              . ($apply['eot_clear'] ? ', auto_eot cleared'
                                     : ($apply['eot'] !== null ? ", auto_eot -> {$apply['eot']}" : ''))
              . ($apply['ccap_rm'] ? ', ccap REMOVED: ' . implode('+', $apply['ccap_rm']) : '')
              . ($apply['ccap_add'] ? ', ccap added: ' . implode('+', $apply['ccap_add']) : '')
              . " | {$reason}";
        update_user_meta($uid, $note_key, trim($notes . "\n" . $line));

        $audit = get_user_meta($uid, 'xen_mcp_access_audit', true);
        if (!is_array($audit)) {
            $audit = [];
        }
        $audit[] = [
            'ts' => $stamp, 'reason' => $reason,
            'old_level' => $p['old_level'], 'new_level' => $p['new_level'],
            'old_auto_eot' => $p['old_auto_eot'], 'new_auto_eot' => $p['new_auto_eot'],
            // Recorded even when empty: a seat removal must be reconstructable
            // from the member's own record months later, without the ticket.
            'ccaps_removed' => $apply['ccap_rm'],
            'ccaps_added'   => $apply['ccap_add'],
            'ccaps_before'  => $p['ccaps_before'],
            'flat_ccaps_before' => $p['flat_ccaps_meta'],
        ];
        update_user_meta($uid, 'xen_mcp_access_audit', $audit);

        // --- readback: never report success from the request alone ---------
        clean_user_cache($uid);
        wp_cache_delete($uid, 'user_meta');
        $fresh = get_userdata($uid);
        $now_role = ($fresh && !empty($fresh->roles)) ? (string) reset($fresh->roles) : '';
        $now_eot  = get_user_meta($uid, $eot_key, true);

        $now_ccaps = [];
        foreach (xen_set_access_cap_keys($uid) as $ck) {
            $now_ccaps = array_merge(
                $now_ccaps,
                xen_set_access_ccaps_in(maybe_unserialize(get_user_meta($uid, $ck, true)))
            );
        }
        $now_ccaps = array_values(array_unique($now_ccaps));
        sort($now_ccaps);
        $now_flat = (string) get_user_meta($uid, 'ccaps', true);

        $now_sid = get_user_meta($uid, $sid_key, true);
        $now_gw  = get_user_meta($uid, $gw_key, true);
        $sid_ok = true;
        if ($apply['sid_clear'])        { $sid_ok = ($now_sid === '' || $now_sid === false); }
        elseif ($apply['sid'] !== null) { $sid_ok = ((string) $now_sid === (string) $apply['sid']); }
        $gw_ok = true;
        if ($apply['gw_clear'])        { $gw_ok = ($now_gw === '' || $now_gw === false); }
        elseif ($apply['gw'] !== null) { $gw_ok = ((string) $now_gw === (string) $apply['gw']); }
        $p['new_subscr_id']      = ($now_sid === '' || $now_sid === false) ? null : $now_sid;
        $p['new_subscr_gateway'] = ($now_gw === '' || $now_gw === false) ? null : $now_gw;

        $role_ok = ($apply['role'] === null) || ($now_role === $apply['role']);
        $eot_ok  = true;
        if ($apply['eot_clear']) {
            $eot_ok = ($now_eot === '' || $now_eot === null || $now_eot === false);
        } elseif ($apply['eot'] !== null) {
            $eot_ok = ((string) $now_eot === (string) $apply['eot']);
        }
        // Set EQUALITY against the prediction, in both stores — not the old
        // "did the count shrink" heuristic. This is the check that would have
        // caught the original bug on its first run: the plan said [] and the
        // record still said [access_s2member_ccap_secant], and a count-based
        // guard waved that through because the count had not gone DOWN.
        //
        // It is also what makes the dry run trustworthy in the strong sense —
        // "applied" now means the record matches what the preview promised,
        // not merely that some write was attempted.
        $ccap_ok = ($now_ccaps === $apply['ccaps_after_pred'])
                   && ($now_flat === $apply['flat_after_pred']);

        $p['new_level']    = $now_role;
        $p['new_auto_eot'] = ($now_eot === '' || $now_eot === false) ? null : $now_eot;
        $p['ccaps_after']  = $now_ccaps;
        $p['flat_ccaps_meta_after'] = $now_flat === '' ? null : $now_flat;
        $p['status'] = ($role_ok && $eot_ok && $ccap_ok && $sid_ok && $gw_ok)
                        ? 'applied' : 'write_unconfirmed';

        if (!$ccap_ok) {
            $result['warnings'][] = "user {$uid}: ccap readback does NOT match the plan. "
                . 'predicted caps ' . json_encode($apply['ccaps_after_pred'])
                . ' got ' . json_encode($now_ccaps)
                . '; predicted flat `ccaps` ' . json_encode($apply['flat_after_pred'])
                . ' got ' . json_encode($now_flat)
                . '. The two roster sources may now disagree. INVESTIGATE before trusting any roster.';
        }
        if (!empty($p['ccaps_remove_noop'])) {
            $result['warnings'][] = "user {$uid}: remove_ccap named "
                . implode(', ', $p['ccaps_remove_noop'])
                . ' but this member never held it — no removal happened. '
                . 'Check the slug and the target user.';
        }
        if ($p['status'] === 'write_unconfirmed') {
            $result['ok'] = false;
        }

        $result['users'][] = $p;
    }

    if (!$dry_run) {
        wp_cache_flush();
    }

    $result['summary'] = array_count_values(array_map(
        function ($u) { return $u['status']; }, $result['users']
    ));
    $result['message'] = $dry_run
        ? 'DRY RUN — nothing written. Send dry_run=false to apply.'
        : 'Applied. Every row was verified by reading the record back.';
    return $result;
}

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/users/set-access', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can('edit_users'); },
        'args' => [
            'user'     => ['required' => false, 'description' => 'Single target: ID, email, login, or slug.'],
            'users'    => ['required' => false, 'description' => 'Batch: array of {identifier, level?, auto_eot?}.'],
            'level'    => ['required' => false, 'description' => '0-5, a role slug, or "demoted". Batch default.'],
            'auto_eot' => ['required' => false, 'description' => 'YYYY-MM-DD, unix seconds, or "clear". Batch default.'],
            'subscr_id'      => ['required' => false,
                                 'description' => 'Gateway subscription/profile ID, or "clear". s2Member wipes this on demotion; restoring it makes the member findable from a gateway notice again.'],
            'subscr_gateway' => ['required' => false,
                                 'description' => 'paypal | stripe | free | manual, or "clear". Required alongside subscr_id when none is stored.'],
            'remove_ccap'    => ['required' => false,
                                 'description' => 'Ccap slug(s) to REMOVE — string, comma list, or array. Clears access_s2member_ccap_<slug> from every capability blob AND drops the slug from the flat `ccaps` usermeta key. Other ccaps are preserved. This is how a member leaves a group roster.'],
            'add_ccap'       => ['required' => false,
                                 'description' => 'Ccap slug(s) to GRANT — string, comma list, or array. Written to the root capability blob and the flat `ccaps` key, matching the shape s2Member creates for an active member.'],
            'preserve_ccaps' => ['default' => true, 'type' => 'boolean',
                                 'description' => 'Always true. Passing false is REFUSED with a 400 rather than silently ignored — a boolean cannot name WHICH ccap to drop. Use remove_ccap.'],
            'dry_run'        => ['default' => true, 'type' => 'boolean'],
            'allow_partial'  => ['default' => false, 'type' => 'boolean'],
            'max_batch'      => ['default' => 100, 'type' => 'integer'],
            'reason'         => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        ],
        'callback' => 'xen_users_set_access',
    ]);
});


/* =============================================================================
 * ACF options pages — read + write, for EVERY blog in the multisite.
 *
 * This file is an mu-plugin, so it loads on the network root AND on /ets
 * automatically. ACF options resolve against the current blog's own options
 * table, so the same code serves both:
 *
 *     https://xenetwork.org/wp-json/xen/v1/acf-options
 *     https://xenetwork.org/ets/wp-json/xen/v1/acf-options
 *
 * No site parameter is needed or wanted — the URL selects the blog.
 *
 * Ported from the wordpress-jumbo implementation rather than rewritten. The
 * failure modes below are the reason it is a port.
 *
 *   1. Writes go through ACF by FIELD KEY. That maintains the `_options_<name>`
 *      shadow row binding value to field definition. Write the value row alone
 *      and wp-admin renders an EMPTY FIELD over a populated database — it reads
 *      back fine over the API, so only a human looking at wp-admin sees it.
 *   2. An unrecognised field name is refused, never written. Writing it blind
 *      creates an orphan options_* row with no field-key partner: invisible in
 *      wp-admin, present in the database, unexplainable later.
 *   3. Field NAMES come from ACF definitions and are frequently not what the
 *      admin label suggests. Always read before writing.
 *
 * Two additions over the Jumbo version, both specific to this network:
 *
 *   - `integrity`: every write is verified by re-reading through ACF, and a
 *     mismatch is reported as a failure rather than a bare success. Matches the
 *     shape xen-formidable-rest.php already uses.
 *   - GUARDED FIELDS: some fields silently change what the public site renders.
 *     The ETS Announcement Bar's visibility mode is one — writing "draft" or
 *     "admin" removes the banner from the live site with no other symptom.
 *     Those are refused unless the caller names them explicitly.
 * ========================================================================== */

/**
 * Field names/labels that change public rendering in a way that is invisible
 * from an API read-back. Matched case-insensitively against BOTH name and label.
 */
function xen_acf_guarded_patterns() {
    return ['/visib/i', '/(^|_)mode($|_)/i', '/enabled$/i', '/^show_/i'];
}

function xen_acf_is_guarded($field) {
    foreach (xen_acf_guarded_patterns() as $re) {
        if (preg_match($re, (string) ($field['name'] ?? '')) ||
            preg_match($re, (string) ($field['label'] ?? ''))) {
            return true;
        }
    }
    return false;
}

function xen_acf_pages() {
    if (!function_exists('acf_get_options_pages')) { return []; }
    $pages = acf_get_options_pages();
    if (!is_array($pages)) { return []; }

    $out = [];
    foreach ($pages as $slug => $p) {
        $menu  = is_array($p) ? ($p['menu_slug'] ?? $slug) : $slug;
        $short = preg_replace('/^acf-options-/', '', (string) $menu);
        $out[$short] = [
            'slug'      => $short,
            'menu_slug' => $menu,
            'title'     => is_array($p) ? ($p['page_title'] ?? $short) : $short,
            'post_id'   => is_array($p) ? ($p['post_id'] ?? 'option') : 'option',
        ];
    }
    return $out;
}

function xen_acf_fields_for_page($page) {
    $groups = acf_get_field_groups(['options_page' => $page['menu_slug']]);
    $fields = [];
    foreach ((array) $groups as $g) {
        foreach ((array) acf_get_fields($g) as $f) { $fields[] = $f; }
    }
    return $fields;
}

function xen_acf_field_payload($f, $post_id) {
    $value = get_field($f['name'], $post_id, false); // raw: IDs not objects

    $row = [
        'name'    => $f['name'],
        'key'     => $f['key'],
        'type'    => $f['type'],
        'label'   => $f['label'],
        'value'   => $value,
        'guarded' => xen_acf_is_guarded($f),
    ];

    if (!empty($row['guarded'])) {
        $row['guard_note'] = 'Changing this can alter what the public site renders '
                           . 'with no other visible symptom. Writing it requires allow_guarded.';
    }

    // ACF link fields return url/title/target, not a bare string. Surface the
    // shape so a caller does not send a string and silently flatten it.
    if ($f['type'] === 'link') {
        $row['value_shape'] = 'object: {url, title, target}';
    }

    // Choice fields: list what is actually accepted, so a caller does not guess
    // a label where a value is required.
    if (!empty($f['choices']) && is_array($f['choices'])) {
        $row['choices'] = $f['choices'];
    }

    if (in_array($f['type'], ['image', 'file'], true) && !empty($value)) {
        $att_id = is_array($value) ? ($value['ID'] ?? null) : (int) $value;
        if ($att_id) {
            $row['value_url'] = wp_get_attachment_url($att_id) ?: null;
            $meta = wp_get_attachment_metadata($att_id);
            $row['value_meta'] = (is_array($meta) && isset($meta['width'], $meta['height']))
                ? ['width' => (int) $meta['width'], 'height' => (int) $meta['height'],
                   'mime_type' => get_post_mime_type($att_id) ?: null]
                : null;
        }
    }

    return $row;
}

function xen_acf_meta() {
    return [
        'blog_id'     => get_current_blog_id(),
        'site'        => get_site_url(),
        'plugin'      => 'xen-s2member-rest',
        'acf_version' => defined('ACF_VERSION') ? ACF_VERSION : null,
    ];
}

/** GET /xen/v1/acf-options[?page=slug][&field=name] */
function xen_acf_options_get($req) {
    if (!class_exists('ACF')) {
        return new WP_Error('acf_missing', 'ACF is not active on this blog.', ['status' => 501]);
    }

    $pages = xen_acf_pages();
    $slug  = $req->get_param('page');
    $slug  = ($slug === null) ? null : preg_replace('/^acf-options-/', '', sanitize_text_field($slug));

    if ($slug === null || $slug === '') {
        return [
            'pages' => array_values($pages),
            'hint'  => 'Pass ?page=<slug> to read one. Slugs are per-blog — the root and /ets '
                     . 'have different options pages, and this URL selects the blog.',
            '_meta' => xen_acf_meta(),
        ];
    }

    if (!isset($pages[$slug])) {
        return new WP_Error('page_not_found', "No ACF options page '{$slug}' on this blog.", [
            'status' => 404, 'available' => array_keys($pages), 'blog_id' => get_current_blog_id(),
        ]);
    }

    $page   = $pages[$slug];
    $only   = $req->get_param('field');
    $fields = xen_acf_fields_for_page($page);

    $out = [];
    foreach ($fields as $f) {
        if ($only && $f['name'] !== $only) { continue; }
        $out[] = xen_acf_field_payload($f, $page['post_id']);
    }

    if ($only && empty($out)) {
        return new WP_Error('field_not_found', "Field '{$only}' is not on '{$slug}'.", [
            'status' => 404,
            'available' => array_map(function ($f) { return $f['name']; }, $fields),
        ]);
    }

    return ['page' => $slug, 'title' => $page['title'], 'fields' => $out, '_meta' => xen_acf_meta()];
}

/** POST /xen/v1/acf-options  { page, fields{}, dry_run, allow_guarded[] } */
function xen_acf_options_set($req) {
    if (!class_exists('ACF')) {
        return new WP_Error('acf_missing', 'ACF is not active on this blog.', ['status' => 501]);
    }

    $slug     = preg_replace('/^acf-options-/', '', sanitize_text_field((string) $req->get_param('page')));
    $updates  = $req->get_param('fields');
    $dry_run  = filter_var($req->get_param('dry_run'), FILTER_VALIDATE_BOOLEAN);
    $allowed  = $req->get_param('allow_guarded');
    $allowed  = is_array($allowed) ? array_map('strval', $allowed) : [];

    if (!is_array($updates) || empty($updates)) {
        return new WP_Error('bad_fields', 'fields must be a non-empty object keyed by field name.', ['status' => 400]);
    }

    $pages = xen_acf_pages();
    if (!isset($pages[$slug])) {
        return new WP_Error('page_not_found', "No ACF options page '{$slug}' on this blog.", [
            'status' => 404, 'available' => array_keys($pages),
        ]);
    }

    $page   = $pages[$slug];
    $fields = xen_acf_fields_for_page($page);
    $by_name = [];
    foreach ($fields as $f) { $by_name[$f['name']] = $f; }

    $unknown = array_values(array_diff(array_keys($updates), array_keys($by_name)));
    if (!empty($unknown)) {
        return new WP_Error('unknown_field', 'These field name(s) are not on this options page.', [
            'status'    => 400,
            'unknown'   => $unknown,
            'available' => array_keys($by_name),
            'hint'      => 'Names come from ACF definitions, not admin labels and not options_* rows. '
                         . 'GET this page first.',
        ]);
    }

    // Guarded fields must be named explicitly. This exists because writing the
    // Announcement Bar's visibility mode to "draft" removes the banner from the
    // public site and looks like a successful write from every API angle.
    $guard_hits = [];
    foreach (array_keys($updates) as $n) {
        if (xen_acf_is_guarded($by_name[$n]) && !in_array($n, $allowed, true)) {
            $guard_hits[] = $n;
        }
    }
    if (!empty($guard_hits)) {
        return new WP_Error('guarded_field', 'These field(s) can silently change what the public site renders.', [
            'status'  => 409,
            'guarded' => $guard_hits,
            'hint'    => 'If you really mean it, pass allow_guarded: ["' . implode('","', $guard_hits) . '"]. '
                       . 'Read the current value first — an API read-back cannot tell you the banner vanished.',
        ]);
    }

    $before = [];
    foreach ($updates as $name => $_) {
        $before[$name] = get_field($name, $page['post_id'], false);
    }

    if ($dry_run) {
        return [
            'ok' => true, 'dry_run' => true, 'persisted' => false,
            'page' => $slug,
            'changes' => ['before' => $before, 'after' => $updates],
            'note'  => 'DRY RUN — nothing written. Re-send with dry_run=false to apply.',
            '_meta' => xen_acf_meta(),
        ];
    }

    $write_ok = [];
    foreach ($updates as $name => $new) {
        // By KEY — maintains the _options_<name> shadow row. See the header.
        $write_ok[$name] = (bool) update_field($by_name[$name]['key'], $new, $page['post_id']);
    }

    // ---- integrity: verify by re-reading through ACF, never trust the write --
    $after = [];
    foreach ($updates as $name => $_) {
        $after[$name] = get_field($name, $page['post_id'], false);
    }

    $mismatched = [];
    foreach ($updates as $name => $want) {
        // Loose compare: ACF normalises some types (ints, link arrays) on save.
        if (is_scalar($want) && is_scalar($after[$name])) {
            if ((string) $want !== (string) $after[$name]) { $mismatched[] = $name; }
        } elseif ($want != $after[$name]) {
            $mismatched[] = $name;
        }
    }

    // The shadow row is the thing that silently breaks. Confirm it exists for
    // every field written — this is what an API read-back alone cannot prove.
    $shadow_ok = [];
    foreach ($updates as $name => $_) {
        $shadow_ok[$name] = (get_option('_options_' . $name, null) !== null);
    }

    $result = [
        'ok'        => empty($mismatched) && !in_array(false, $shadow_ok, true),
        'dry_run'   => false,
        'persisted' => true,
        'page'      => $slug,
        'changes'   => ['before' => $before, 'after' => $after],
        'integrity' => [
            'write_returned_true'  => $write_ok,
            'verified_by_reread'   => empty($mismatched),
            'mismatched_fields'    => $mismatched,
            'field_key_row_present' => $shadow_ok,
        ],
        '_meta' => xen_acf_meta(),
    ];

    if (!empty($mismatched)) {
        $result['ATTENTION'] = 'Wrote, but the re-read does not match what was sent for: '
                             . implode(', ', $mismatched) . '. Do not assume this landed.';
    }
    if (in_array(false, $shadow_ok, true)) {
        $result['ATTENTION'] = 'A _options_<name> field-key row is MISSING. wp-admin may now render '
                             . 'this field EMPTY over a populated database. Check wp-admin before trusting.';
    }
    if ($result['ok']) {
        $result['note'] = 'Verified by re-read. An ACF write does NOT flush page cache — if this '
                        . 'renders publicly, confirm with a cache-busting query string.';
    }

    return $result;
}

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/acf-options', [
        [
            'methods'             => 'GET',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'args' => [
                'page'  => ['type' => 'string', 'description' => 'Options page slug, with or without the acf-options- prefix. Omit to list this blog\'s pages.'],
                'field' => ['type' => 'string'],
            ],
            'callback' => 'xen_acf_options_get',
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'args' => [
                'page'          => ['required' => true, 'type' => 'string'],
                'fields'        => ['required' => true, 'type' => 'object', 'description' => 'Keyed by ACF field NAME. Merge semantics.'],
                'dry_run'       => ['type' => 'boolean', 'default' => false],
                'allow_guarded' => ['type' => 'array', 'description' => 'Field names you explicitly accept writing despite the guard.'],
            ],
            'callback' => 'xen_acf_options_set',
        ],
    ]);
});

/* =============================================================================
 * Welcome-page cloning for institutional registration pages.
 *
 * Every IR page has a paired `xen_welcome_page` — the post-registration
 * destination. duplicate_institutional cloned the IR page but left
 * `welcome_page` meta pointing at the SOURCE org's welcome page, so the new
 * portal's success redirect landed on another organisation's page.
 *
 * WHAT A WELCOME PAGE ACTUALLY IS (inspected on RMI, post 2476)
 * post_content is ~371 characters: the org-specific intro paragraph and
 * nothing else. The "Get started" 4-step list, the Vimeo walkthrough and every
 * standard link (/login-ets/, /manage-subscription-ets/, /ets/your-subscription/)
 * come from the THEME TEMPLATE, not the post. They therefore survive any clone
 * unconditionally — there is nothing to preserve and nothing that can break.
 * The ticket's acceptance test 3 is satisfied by construction.
 *
 * So the clone is: copy 371 chars, swap the org names, copy all postmeta.
 *
 * TWO ENTRY POINTS, deliberately
 *   POST /institutional/<id>/welcome-page   clone a welcome page for an
 *                                           IR post that ALREADY exists
 *   duplicate_institutional(duplicate_welcome_page=true)  does it inline
 *
 * The standalone route exists because the first real case (UL, post 3817) was
 * already cloned before this shipped. A create-only implementation would have
 * forced a re-clone of a page someone had already checked over.
 * ========================================================================== */

/**
 * Clone the welcome page currently referenced by an IR post, and repoint that
 * IR post at the copy.
 *
 * @param int   $ir_id  the institutional post that should get its own welcome page
 * @param array $opts   welcome_slug, institution_name, replacements, status, dry_run
 * @return array|WP_Error
 */
function xen_clone_welcome_for_ir($ir_id, $opts = []) {
    $ir = get_post($ir_id);
    if (!$ir || $ir->post_type !== 'xen_institutional') {
        return new WP_Error('not_found', "Institutional post {$ir_id} not found", ['status' => 404]);
    }

    $source_welcome_id = (int) get_post_meta($ir_id, 'welcome_page', true);
    if (!$source_welcome_id) {
        return new WP_Error('no_welcome_page', "IR post {$ir_id} has no welcome_page meta to clone from.", [
            'status' => 400,
            'hint'   => 'Set welcome_page to a source welcome post first, or pass source_welcome_id.',
        ]);
    }
    if (!empty($opts['source_welcome_id'])) {
        $source_welcome_id = (int) $opts['source_welcome_id'];
    }

    $src = get_post($source_welcome_id);
    if (!$src || $src->post_type !== 'xen_welcome_page') {
        return new WP_Error('bad_source', "Post {$source_welcome_id} is not a xen_welcome_page.", ['status' => 400]);
    }

    // Guard against re-running: if welcome_page already points at a page whose
    // slug matches this IR's slug, it has already been cloned. Returning the
    // existing one beats silently creating a second orphan.
    $ir_slug = $ir->post_name;
    $want_slug = $opts['welcome_slug'] ?: $ir_slug;
    if ($src->post_name === $want_slug) {
        return [
            'ok' => true, 'already_done' => true,
            'welcome_page_id' => $source_welcome_id,
            'welcome_slug'    => $src->post_name,
            'welcome_permalink' => home_url("/welcome/{$src->post_name}/"),
            'note' => 'welcome_page already points at a page with this IR slug — nothing to do.',
        ];
    }

    $institution = $opts['institution_name']
        ?: (string) get_post_meta($ir_id, 'institution_name', true)
        ?: preg_replace('/^Registration\s*[-–]\s*/u', '', $ir->post_title);

    // Source org name, for the automatic substitution. Prefer the source IR's
    // recorded name; fall back to parsing the welcome title.
    $src_name = preg_replace('/^Welcome\s*[-–]\s*/u', '', $src->post_title);

    $content = $src->post_content;
    $applied = [];

    // Automatic: full source org name -> new institution name. Longest-first so
    // a short name that is a substring of the long one cannot corrupt it.
    $auto = [];
    if ($src_name && $institution && $src_name !== $institution) {
        $auto[$src_name] = $institution;
    }
    // Caller-supplied replacements run AFTER, so they can fix the short form
    // ("RMI" -> "UL") which cannot be derived from the title.
    $subs = $auto + (array) ($opts['replacements'] ?: []);
    uksort($subs, function ($a, $b) { return strlen($b) - strlen($a); });

    foreach ($subs as $find => $replace) {
        if ($find === '' || strpos($content, (string) $find) === false) { continue; }
        $content = str_replace($find, $replace, $content);
        $applied[] = $find;
    }

    $status = $opts['status'] ?: $ir->post_status;

    // Anything still naming the source org after substitution is reported, not
    // silently shipped — a welcome page that greets the wrong organisation is
    // the exact failure this endpoint exists to prevent.
    $residual = [];
    foreach ([$src_name, $src->post_name] as $probe) {
        if ($probe && stripos($content, (string) $probe) !== false) { $residual[] = $probe; }
    }

    if (!empty($opts['dry_run'])) {
        return [
            'ok' => true, 'dry_run' => true, 'persisted' => false,
            'source_welcome_id' => $source_welcome_id,
            'would_create' => [
                'title'   => "Welcome - {$institution}",
                'slug'    => $want_slug,
                'status'  => $status,
                'content' => $content,
            ],
            'replacements_applied' => $applied,
            'residual_source_mentions' => $residual,
            'note' => 'DRY RUN — nothing written.',
        ];
    }

    $new_id = wp_insert_post([
        'post_type'    => 'xen_welcome_page',
        'post_status'  => $status,
        'post_title'   => "Welcome - {$institution}",
        'post_name'    => $want_slug,
        'post_content' => $content,
        'post_excerpt' => $src->post_excerpt,
        'post_author'  => get_current_user_id(),
    ], true);
    if (is_wp_error($new_id)) { return $new_id; }

    // Copy all postmeta — this is what carries the org logo, which is NOT in
    // post_content and cannot be derived. It will point at the SOURCE org's
    // image until a human swaps it; that is called out in the response.
    $skip = ['_edit_lock', '_edit_last', '_wp_old_slug'];
    foreach (get_post_meta($source_welcome_id) as $key => $values) {
        if (in_array($key, $skip, true)) { continue; }
        $value = (count($values) === 1) ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        update_post_meta($new_id, $key, $value);
    }

    foreach (get_object_taxonomies('xen_welcome_page') as $tax) {
        $terms = wp_get_object_terms($source_welcome_id, $tax, ['fields' => 'ids']);
        if (!is_wp_error($terms) && !empty($terms)) {
            wp_set_object_terms($new_id, $terms, $tax);
        }
    }

    // Repoint the IR page at its own welcome page. This is the whole point.
    update_post_meta($ir_id, 'welcome_page', (string) $new_id);

    $final_slug = get_post_field('post_name', $new_id);
    $permalink  = home_url("/welcome/{$final_slug}/");

    // Verify by re-read rather than trusting update_post_meta's return.
    $verified = ((int) get_post_meta($ir_id, 'welcome_page', true) === (int) $new_id);

    $out = [
        'ok'                => $verified,
        'welcome_page_id'   => $new_id,
        'welcome_slug'      => $final_slug,
        'welcome_permalink' => $permalink,
        'welcome_status'    => get_post_status($new_id),
        'welcome_edit_url'  => admin_url("post.php?post={$new_id}&action=edit"),
        'source_welcome_id' => $source_welcome_id,
        'ir_id'             => $ir_id,
        'ir_welcome_page_meta_verified' => $verified,
        'replacements_applied' => $applied,
        'residual_source_mentions' => $residual,
        'logo_note' => 'The org logo lives in postmeta, not post_content, and was copied from the '
                     . 'source. It still shows the SOURCE org\'s image — swap it in wp-admin.',
    ];

    if ($final_slug !== $want_slug) {
        $out['ATTENTION'] = "Requested slug '{$want_slug}' was taken; WordPress assigned "
                          . "'{$final_slug}'. The IR page's success= URL will NOT match. Fix before publishing.";
    }
    if (!empty($residual)) {
        $out['ATTENTION'] = 'Content still mentions the source org (' . implode(', ', $residual)
                          . '). Pass `replacements` for the short form — it cannot be derived from the title.';
    }
    if (!$verified) {
        $out['ATTENTION'] = 'welcome_page meta did not verify on re-read. Do not publish.';
    }
    return $out;
}

add_action('rest_api_init', function () {
    // POST /institutional/<id>/welcome-page — clone a welcome page for an IR
    // post that already exists. Idempotent: re-running returns the existing one.
    register_rest_route('xen/v1', '/institutional/(?P<id>\d+)/welcome-page', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can('edit_posts'); },
        'args' => [
            'welcome_slug'      => ['type' => 'string', 'description' => 'Defaults to the IR post slug.'],
            'institution_name'  => ['type' => 'string', 'description' => 'Defaults to institution_name meta, else the IR title minus "Registration - ".'],
            'replacements'      => ['type' => 'object', 'description' => 'Extra find/replace. Needed for the SHORT org name (e.g. {"RMI":"UL"}), which cannot be derived.'],
            'source_welcome_id' => ['type' => 'integer', 'description' => 'Override the source; defaults to the IR post\'s current welcome_page meta.'],
            'status'            => ['type' => 'string', 'description' => 'Defaults to the IR post\'s status.'],
            'dry_run'           => ['type' => 'boolean', 'default' => false],
        ],
        'callback' => function ($req) {
            return xen_clone_welcome_for_ir((int) $req['id'], [
                'welcome_slug'      => $req->get_param('welcome_slug'),
                'institution_name'  => $req->get_param('institution_name'),
                'replacements'      => (array) ($req->get_param('replacements') ?: []),
                'source_welcome_id' => $req->get_param('source_welcome_id'),
                'status'            => $req->get_param('status'),
                'dry_run'           => $req->get_param('dry_run'),
            ]);
        },
    ]);
});
