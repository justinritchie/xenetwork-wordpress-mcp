<?php
/**
 * Plugin Name: Jumbo MCP — User Meta REST Registration
 * Description: Registers allowlisted user-meta keys with show_in_rest so the
 *              wordpress-jumbo MCP can READ and WRITE them via WordPress core
 *              REST (GET/POST /wp/v2/users/<id>). Without this, WordPress
 *              accepts a meta write with HTTP 200 and SILENTLY DISCARDS it,
 *              and the key is invisible to reads — so the MCP would report
 *              every write as `write_unconfirmed`.
 * Version:     1.0.0
 * Author:      Jumbo / Opus Advisors event ops
 *
 * INSTALL: drop this file into  wp-content/mu-plugins/  on each event site.
 * mu-plugins auto-activate; no admin toggle. One copy per site.
 *
 * The $keys array below MUST match WP_WRITABLE_META_KEYS in
 * ~/.mcp-credentials/wordpress-jumbo.env on the MCP host. Keep them in sync.
 */

if (!defined('ABSPATH')) {
    exit; // no direct access
}

add_action('init', function () {
    // Allowlisted user-meta keys the MCP may read/write.
    $keys = array(
        'event_registration_status',
    );

    foreach ($keys as $key) {
        register_meta('user', $key, array(
            'type'         => 'string',
            'single'       => true,
            'show_in_rest' => true,
            // Only users who can edit other users may write this via REST.
            // Matches the MCP's own edit_users expectation and keeps the write
            // surface off the public API surface for lesser roles.
            'auth_callback' => function () {
                return current_user_can('edit_users');
            },
        ));
    }
});
