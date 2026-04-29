<?php
/*
Plugin Name: XE Network — Formidable Forms REST exposure
Description: Read-only REST endpoints for Formidable Forms data on the
             network root site. The network root has Formidable installed
             but the frm/v2 data REST namespace isn't enabled there (only
             on the ETS subsite). These endpoints query Formidable's tables
             directly via $wpdb so we get reliable read access to the
             forms, fields, entries, and entry meta on xenetwork.org root.
Author: Justin Ritchie
Version: 1.0.0

Routes (all under xen/v1/, all GET, all require edit_posts):
  GET /frm/forms
  GET /frm/forms/<id>
  GET /frm/forms/<id>/fields
  GET /frm/forms/<id>/entries?page=N&per_page=N&search=...
  GET /frm/entries/<id>

NO write endpoints. Read-only by design.
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {

    $perm = function () {
        return current_user_can('edit_posts');
    };

    // ---- helpers (closure-scoped) -----------------------------------------

    $maybe_unserialize_options = function ($val) {
        if (!is_string($val)) return $val;
        if (strncmp($val, 'a:', 2) === 0 || strncmp($val, 'O:', 2) === 0) {
            $u = @unserialize($val);
            if ($u !== false) return $u;
        }
        return $val;
    };

    $trim_form = function ($row) {
        return [
            'id'             => (int) $row->id,
            'form_key'       => $row->form_key,
            'name'           => $row->name,
            'description'    => $row->description ?: null,
            'status'         => $row->status,
            'parent_form_id' => (int) ($row->parent_form_id ?? 0),
            'created_at'     => $row->created_at,
            'entry_count'    => isset($row->entry_count) ? (int) $row->entry_count : null,
        ];
    };

    $trim_field = function ($row) use ($maybe_unserialize_options) {
        return [
            'id'            => (int) $row->id,
            'form_id'       => (int) $row->form_id,
            'field_key'     => $row->field_key,
            'name'          => $row->name,
            'description'   => $row->description ?: null,
            'type'          => $row->type,
            'default_value' => $row->default_value ?: null,
            'options'       => $maybe_unserialize_options($row->options),
            'required'      => (int) ($row->required ?? 0),
            'field_order'   => (int) $row->field_order,
        ];
    };

    $trim_entry = function ($row, $metas = null) use ($maybe_unserialize_options) {
        $out = [
            'id'         => (int) $row->id,
            'form_id'    => (int) $row->form_id,
            'item_key'   => $row->item_key,
            'name'       => $row->name,
            'user_id'    => (int) ($row->user_id ?? 0) ?: null,
            'ip'         => $row->ip,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
        if ($metas !== null) {
            // metas is a list of stdClass with field_id / meta_value
            $cleaned = [];
            foreach ($metas as $m) {
                $cleaned[(string) $m->field_id] = $maybe_unserialize_options($m->meta_value);
            }
            $out['metas'] = $cleaned;
        }
        return $out;
    };

    // ---- routes -----------------------------------------------------------

    register_rest_route('xen/v1', '/frm/forms', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback' => function () use ($trim_form) {
            global $wpdb;
            $sql = "
                SELECT f.id, f.form_key, f.name, f.description, f.status,
                       f.parent_form_id, f.created_at,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}frm_items i
                        WHERE i.form_id = f.id) AS entry_count
                FROM {$wpdb->prefix}frm_forms f
                WHERE f.status IN ('published','draft')
                ORDER BY f.id
            ";
            $rows = $wpdb->get_results($sql);
            if ($wpdb->last_error) {
                return new WP_Error('db_error', $wpdb->last_error, ['status' => 500]);
            }
            return [
                'forms' => array_map($trim_form, $rows ?: []),
                'total' => count($rows ?: []),
            ];
        },
    ]);

    register_rest_route('xen/v1', '/frm/forms/(?P<id>[\w-]+)', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback' => function ($req) use ($trim_form) {
            global $wpdb;
            $id_or_key = $req['id'];
            // accept either numeric id or form_key
            $where = is_numeric($id_or_key) ? 'id = %d' : 'form_key = %s';
            $sql = $wpdb->prepare("
                SELECT f.id, f.form_key, f.name, f.description, f.status,
                       f.parent_form_id, f.created_at,
                       (SELECT COUNT(*) FROM {$wpdb->prefix}frm_items i
                        WHERE i.form_id = f.id) AS entry_count
                FROM {$wpdb->prefix}frm_forms f
                WHERE $where
                LIMIT 1
            ", $id_or_key);
            $row = $wpdb->get_row($sql);
            if (!$row) {
                return new WP_Error('not_found', 'Form not found', ['status' => 404]);
            }
            return $trim_form($row);
        },
    ]);

    register_rest_route('xen/v1', '/frm/forms/(?P<id>[\w-]+)/fields', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback' => function ($req) use ($trim_field) {
            global $wpdb;
            $id_or_key = $req['id'];
            // resolve to numeric id
            if (is_numeric($id_or_key)) {
                $form_id = (int) $id_or_key;
            } else {
                $form_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}frm_forms WHERE form_key = %s LIMIT 1",
                    $id_or_key
                ));
            }
            if (!$form_id) {
                return new WP_Error('not_found', 'Form not found', ['status' => 404]);
            }
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, form_id, field_key, name, description, type,
                        default_value, options, required, field_order
                 FROM {$wpdb->prefix}frm_fields
                 WHERE form_id = %d
                 ORDER BY field_order, id",
                $form_id
            ));
            return [
                'form_id' => $form_id,
                'fields'  => array_map($trim_field, $rows ?: []),
                'total'   => count($rows ?: []),
            ];
        },
    ]);

    register_rest_route('xen/v1', '/frm/forms/(?P<id>[\w-]+)/entries', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'args' => [
            'page'     => ['type' => 'integer', 'default' => 1],
            'per_page' => ['type' => 'integer', 'default' => 25],
            'search'   => ['type' => 'string'],
        ],
        'callback' => function ($req) use ($trim_entry) {
            global $wpdb;
            $id_or_key = $req['id'];
            $page      = max(1, (int) $req->get_param('page'));
            $per_page  = min(100, max(1, (int) $req->get_param('per_page') ?: 25));
            $search    = $req->get_param('search');
            $offset    = ($page - 1) * $per_page;

            // resolve to numeric form_id
            if (is_numeric($id_or_key)) {
                $form_id = (int) $id_or_key;
            } else {
                $form_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}frm_forms WHERE form_key = %s LIMIT 1",
                    $id_or_key
                ));
            }
            if (!$form_id) {
                return new WP_Error('not_found', 'Form not found', ['status' => 404]);
            }

            // build entries query with optional substring search across metas
            if ($search) {
                $matching_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT DISTINCT i.id
                    FROM {$wpdb->prefix}frm_items i
                    LEFT JOIN {$wpdb->prefix}frm_item_metas m ON m.item_id = i.id
                    WHERE i.form_id = %d
                      AND (
                          i.name LIKE %s
                          OR i.item_key LIKE %s
                          OR m.meta_value LIKE %s
                      )
                    ORDER BY i.created_at DESC
                ", $form_id, '%' . $wpdb->esc_like($search) . '%',
                   '%' . $wpdb->esc_like($search) . '%',
                   '%' . $wpdb->esc_like($search) . '%'));
                $total = count($matching_ids);
                $page_ids = array_slice($matching_ids, $offset, $per_page);
                if (empty($page_ids)) {
                    return [
                        'entries' => [], 'total' => $total,
                        'total_pages' => (int) ceil($total / $per_page),
                        'page' => $page, 'per_page' => $per_page,
                    ];
                }
                $placeholders = implode(',', array_fill(0, count($page_ids), '%d'));
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, form_id, item_key, name, user_id, ip, created_at, updated_at
                     FROM {$wpdb->prefix}frm_items
                     WHERE id IN ($placeholders)
                     ORDER BY created_at DESC",
                    ...$page_ids
                ));
            } else {
                $total = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}frm_items WHERE form_id = %d",
                    $form_id
                ));
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, form_id, item_key, name, user_id, ip, created_at, updated_at
                     FROM {$wpdb->prefix}frm_items
                     WHERE form_id = %d
                     ORDER BY created_at DESC
                     LIMIT %d OFFSET %d",
                    $form_id, $per_page, $offset
                ));
            }

            // hydrate metas for each entry in this page
            $entries = [];
            foreach ($rows ?: [] as $row) {
                $metas = $wpdb->get_results($wpdb->prepare(
                    "SELECT field_id, meta_value
                     FROM {$wpdb->prefix}frm_item_metas
                     WHERE item_id = %d",
                    $row->id
                ));
                $entries[] = $trim_entry($row, $metas);
            }

            return [
                'entries'     => $entries,
                'total'       => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 0,
                'page'        => $page,
                'per_page'    => $per_page,
            ];
        },
    ]);

    register_rest_route('xen/v1', '/frm/entries/(?P<id>[\w-]+)', [
        'methods'             => 'GET',
        'permission_callback' => $perm,
        'callback' => function ($req) use ($trim_entry) {
            global $wpdb;
            $id_or_key = $req['id'];
            $where = is_numeric($id_or_key) ? 'id = %d' : 'item_key = %s';
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, form_id, item_key, name, user_id, ip, created_at, updated_at
                 FROM {$wpdb->prefix}frm_items
                 WHERE $where
                 LIMIT 1",
                $id_or_key
            ));
            if (!$row) {
                return new WP_Error('not_found', 'Entry not found', ['status' => 404]);
            }
            $metas = $wpdb->get_results($wpdb->prepare(
                "SELECT field_id, meta_value
                 FROM {$wpdb->prefix}frm_item_metas
                 WHERE item_id = %d",
                $row->id
            ));
            return $trim_entry($row, $metas);
        },
    ]);
});
