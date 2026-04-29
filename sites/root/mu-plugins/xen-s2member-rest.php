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
    // Two endpoints under /wp-json/xen/v1/:
    //   GET  /institutional/<id>             — full post + ALL postmeta + tax
    //   POST /institutional/duplicate         — clone source post w/ overrides
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

            return [
                'ok'                  => true,
                'new_id'              => $new_id,
                'new_slug'            => get_post_field('post_name', $new_id),
                'status'               => get_post_status($new_id),
                'title'                => get_the_title($new_id),
                'edit_url'             => admin_url("post.php?post={$new_id}&action=edit"),
                'preview_link'         => get_preview_post_link($new_id),
                'source_id'            => $source_id,
                'content_replacements' => array_keys($content_replacements),
                'counters_reset'       => array_keys($counter_resets),
                'meta_overrides'       => $overrides_applied,
                'taxonomies_copied'    => $tax_copied,
                'note'                 => "Duplicated as {$status}. Counters reset to 0. Review at the edit_url before publishing.",
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
