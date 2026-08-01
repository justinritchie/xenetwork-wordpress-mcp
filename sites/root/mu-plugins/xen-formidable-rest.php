<?php
// ===========================================================================
// FORMIDABLE FIELD STRUCTURE WRITES — additive by default
// ===========================================================================
//
// Scope: form and FIELD STRUCTURE only. There are deliberately NO entry write
// tools here and none should ever be added. Form 10's entries are member
// cancellation submissions and are potential evidence; read-only on entries is
// a constraint, not an oversight.
//
// THE THING THAT MAKES THIS DANGEROUS, stated once so nobody has to rediscover
// it: entry metas bind to OPTION KEYS for the "Other" option, not to labels.
// Verified on live data 2026-08-01 — a real entry stores:
//
//     "105": {"other_4": "New status of ETS publication."}
//
// 8 of 100 sampled entries on form 10 use that shape (~150 of 1,930). Renaming
// or renumbering `other_4` orphans every one of them, and the field would read
// back as perfectly correct afterwards. Regular selections DO store the label
// as the value, which is what makes the Other case so easy to miss.
//
// Therefore the rules enforced below:
//   1. Existing option keys are NEVER renamed, renumbered, or removed unless
//      allow_destructive is explicitly true.
//   2. New options get a fresh non-colliding key.
//   3. Display position is set by ARRAY ORDER, which is independent of key
//      names — so an option can be placed anywhere without touching a key.
//   4. field_id and field_key are never reassigned; updates mutate in place.
//   5. dry_run is the default; every write returns before AND after.

function xen_frm_can_edit() {
    return current_user_can('frm_edit_forms') || current_user_can('administrator')
        || current_user_can('manage_options');
}

// Allocate a key that cannot collide with an existing one, and cannot be
// confused with the `other_N` key either.
function xen_frm_next_option_key($options) {
    $max = -1;
    foreach (array_keys((array) $options) as $k) {
        if (preg_match('~(\d+)$~', (string) $k, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return (string) ($max + 1);
}

function xen_frm_normalize_options($options) {
    // Formidable stores options as an ordered map. Preserve order exactly.
    return is_array($options) ? $options : [];
}

function xen_frm_field_record($field) {
    return [
        'id'          => (int) $field->id,
        'field_key'   => $field->field_key,
        'name'        => $field->name,
        'description' => $field->description,
        'type'        => $field->type,
        'field_order' => (int) $field->field_order,
        'required'    => (int) $field->required,
        'form_id'     => (int) $field->form_id,
        'options'     => xen_frm_normalize_options(maybe_unserialize($field->options)),
        // Conditional logic + per-field settings live HERE, not in `options`.
        // Exposed read-only so the shape can be inspected rather than guessed.
        'field_options' => xen_frm_normalize_options(maybe_unserialize($field->field_options)),
    ];
}

// Survey helper: which fields anywhere on this site actually use conditional
// logic? Used once, to learn the real structure from live data instead of
// inventing one. Read-only.
function xen_frm_conditional_survey(WP_REST_Request $req) {
    if (!class_exists('FrmField')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'formidable_not_active'], 503);
    }
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT id, form_id, field_key, name, type, field_options
           FROM {$wpdb->prefix}frm_fields
          WHERE field_options LIKE '%hide_field%'
          LIMIT 25"
    );
    $out = [];
    foreach ((array) $rows as $r) {
        $fo = maybe_unserialize($r->field_options);
        if (!is_array($fo)) { continue; }
        $cond = [];
        foreach (['hide_field', 'hide_field_cond', 'hide_opt', 'show_hide', 'any_all'] as $k) {
            if (isset($fo[$k])) { $cond[$k] = $fo[$k]; }
        }
        if (!array_filter($cond, function ($v) { return $v !== '' && $v !== []; })) { continue; }
        $out[] = ['id' => (int) $r->id, 'form_id' => (int) $r->form_id,
                  'field_key' => $r->field_key, 'type' => $r->type,
                  'name' => mb_substr((string) $r->name, 0, 70), 'conditional' => $cond];
    }
    return ['ok' => true, 'count' => count($out), 'fields' => $out,
            'note' => 'Live examples of Formidable conditional logic on this install.'];
}

// ---------------------------------------------------------------------------
// Conditional logic
// ---------------------------------------------------------------------------
//
// Formidable stores conditional logic in `field_options` as PARALLEL ARRAYS —
// index i of hide_field / hide_field_cond / hide_opt together form condition i:
//
//   hide_field      => ['105']   the field being TESTED (field ID)
//   hide_field_cond => ['==']    operator: == != > < like not like
//   hide_opt        => ['No longer listen to the episodes']   value compared
//   show_hide       => 'show'    what to do when the conditions match
//   any_all         => 'any'     how to combine multiple conditions
//
// VERIFICATION STATUS, stated honestly: the CONTAINER shape above is confirmed
// against this install (25 fields carry it), but every one of them is EMPTY —
// no field on xenetwork.org currently uses conditional logic. So the populated
// semantics (field ID vs field key in hide_field; option label vs option key in
// hide_opt) are from Formidable's documented model, NOT observed here. The
// first thing anyone using this must do is set one condition, then open the
// field in the Formidable builder and confirm the UI shows what was intended.
// A condition that round-trips through this API but renders blank in the
// builder is the failure mode to watch for.

function xen_frm_build_conditional($spec, $existing) {
    $fo = is_array($existing) ? $existing : [];
    if (!is_array($spec)) {
        return [$fo, 'conditional spec must be an object'];
    }
    $conds = $spec['conditions'] ?? [];
    if (!is_array($conds)) {
        return [$fo, 'conditions must be an array'];
    }
    $hide_field = [];
    $hide_cond  = [];
    $hide_opt   = [];
    foreach ($conds as $c) {
        if (!is_array($c) || !isset($c['field'])) {
            return [$fo, 'each condition needs {field, operator, value}'];
        }
        $op = $c['operator'] ?? '==';
        if (!in_array($op, ['==', '!=', '>', '<', 'like', 'not like'], true)) {
            return [$fo, "unsupported operator '{$op}'"];
        }
        $hide_field[] = (string) $c['field'];
        $hide_cond[]  = $op;
        $hide_opt[]   = (string) ($c['value'] ?? '');
    }
    $sh = $spec['show_hide'] ?? 'show';
    if (!in_array($sh, ['show', 'hide'], true)) {
        return [$fo, "show_hide must be 'show' or 'hide'"];
    }
    $aa = $spec['any_all'] ?? 'any';
    if (!in_array($aa, ['any', 'all'], true)) {
        return [$fo, "any_all must be 'any' or 'all'"];
    }
    $fo['hide_field']      = $hide_field;
    $fo['hide_field_cond'] = $hide_cond;
    $fo['hide_opt']        = $hide_opt;
    $fo['show_hide']       = $sh;
    $fo['any_all']         = $aa;
    return [$fo, null];
}

// POST /xen/v1/frm/forms/<form_id>/fields — create ONE field.
// Additive by definition: a new field gets a new id and touches nothing that
// exists. No entry data can be affected by adding a field.
function xen_frm_add_field_endpoint(WP_REST_Request $req) {
    if (!class_exists('FrmField')) {
        return new WP_REST_Response(['ok' => false, 'error' => 'formidable_not_active'], 503);
    }
    $form_id = (int) $req->get_param('form_id');
    $type    = trim((string) $req->get_param('type'));
    $name    = (string) $req->get_param('name');
    $raw_dry = $req->get_param('dry_run');
    $dry_run = ($raw_dry === null) ? true : rest_sanitize_boolean($raw_dry);

    if (!class_exists('FrmForm') || !FrmForm::getOne($form_id)) {
        return new WP_REST_Response([
            'ok' => false, 'error' => 'form_not_found',
            'message' => "No form with id {$form_id}",
        ], 404);
    }
    $allowed = ['text', 'textarea', 'checkbox', 'radio', 'select', 'email',
                'number', 'url', 'phone', 'date', 'hidden'];
    if (!in_array($type, $allowed, true)) {
        return new WP_REST_Response([
            'ok' => false, 'error' => 'unsupported_type',
            'message' => "type must be one of: " . implode(', ', $allowed),
        ], 400);
    }
    if ($name === '') {
        return new WP_REST_Response([
            'ok' => false, 'error' => 'name_required',
            'message' => 'A field needs a name — it is the question members read.',
        ], 400);
    }

    $field_options = [];
    $cond = $req->get_param('conditional');
    if (is_string($cond) && $cond !== '') {
        $cond = json_decode($cond, true);
    }
    if (is_array($cond)) {
        list($field_options, $err) = xen_frm_build_conditional($cond, $field_options);
        if ($err) {
            return new WP_REST_Response(['ok' => false, 'error' => 'bad_conditional',
                                         'message' => $err], 400);
        }
    }

    $opts = $req->get_param('options');
    if (is_string($opts) && $opts !== '') {
        $opts = json_decode($opts, true);
    }

    $plan = [
        'form_id'       => $form_id,
        'type'          => $type,
        'name'          => $name,
        'description'   => (string) $req->get_param('description'),
        'required'      => (int) $req->get_param('required'),
        'field_order'   => $req->get_param('field_order') !== null
                            ? (int) $req->get_param('field_order') : null,
        'options'       => is_array($opts) ? $opts : null,
        'field_options' => $field_options ?: null,
    ];

    if ($dry_run) {
        return ['ok' => true, 'dry_run' => true, 'would_create' => $plan,
                'message' => 'DRY RUN — no field created. Send dry_run=false to apply.',
                'reminder' => 'After creating a conditional field, OPEN IT IN THE '
                            . 'FORMIDABLE BUILDER and confirm the condition displays as '
                            . 'intended. No field on this install uses conditional logic '
                            . 'yet, so the populated shape is unverified here.'];
    }

    $new = ['type' => $type, 'name' => $name, 'form_id' => $form_id,
            'description' => $plan['description'], 'required' => $plan['required']];
    if ($plan['field_order'] !== null) { $new['field_order'] = $plan['field_order']; }
    if (is_array($opts))               { $new['options'] = $opts; }
    if ($field_options)                { $new['field_options'] = $field_options; }

    $new_id = FrmField::create($new);
    if (!$new_id) {
        return new WP_REST_Response(['ok' => false, 'error' => 'create_failed',
                                     'message' => 'FrmField::create returned no id.'], 500);
    }
    FrmField::delete_form_transient($form_id);
    wp_cache_flush();
    $fresh = FrmField::getOne($new_id);
    return ['ok' => true, 'dry_run' => false, 'field_id' => (int) $new_id,
            'created' => xen_frm_field_record($fresh),
            'message' => "Created field {$new_id} on form {$form_id}, verified by re-read.",
            'reminder' => 'Open it in the Formidable builder and confirm any conditional '
                        . 'logic renders as intended before trusting it.'];
}

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/frm/forms/(?P<form_id>\d+)/fields', [
        'methods'             => 'POST',
        'permission_callback' => 'xen_frm_can_edit',
        'args' => [
            'form_id'     => ['required' => true, 'type' => 'integer'],
            'type'        => ['required' => true, 'type' => 'string'],
            'name'        => ['required' => true, 'type' => 'string'],
            'description' => ['required' => false, 'type' => 'string'],
            'required'    => ['default' => 0, 'type' => 'integer'],
            'field_order' => ['required' => false, 'type' => 'integer'],
            'options'     => ['required' => false],
            'conditional' => ['required' => false,
                              'description' => '{conditions:[{field,operator,value}], show_hide, any_all}'],
            'dry_run'     => ['default' => true, 'type' => 'boolean'],
        ],
        'callback' => 'xen_frm_add_field_endpoint',
    ]);

    register_rest_route('xen/v1', '/frm/conditional-survey', [
        'methods'             => 'GET',
        'permission_callback' => 'xen_frm_can_edit',
        'callback'            => 'xen_frm_conditional_survey',
    ]);
});

function xen_frm_update_field_endpoint(WP_REST_Request $req) {
    if (!class_exists('FrmField')) {
        return new WP_REST_Response([
            'ok' => false, 'error' => 'formidable_not_active',
            'message' => 'FrmField class not found — is Formidable Forms active on this site?',
        ], 503);
    }

    $form_id  = (int) $req->get_param('form_id');
    $field_id = (int) $req->get_param('field_id');
    $raw_dry  = $req->get_param('dry_run');
    $dry_run  = ($raw_dry === null) ? true : rest_sanitize_boolean($raw_dry);
    $allow_destructive = rest_sanitize_boolean($req->get_param('allow_destructive'));

    $field = FrmField::getOne($field_id);
    if (!$field) {
        return new WP_REST_Response([
            'ok' => false, 'error' => 'field_not_found',
            'message' => "No field with id {$field_id}",
        ], 404);
    }
    if ($form_id && (int) $field->form_id !== $form_id) {
        return new WP_REST_Response([
            'ok' => false, 'error' => 'form_mismatch',
            'message' => "Field {$field_id} belongs to form {$field->form_id}, not {$form_id}. "
                       . 'Refusing — a wrong form_id is usually a wrong field.',
        ], 409);
    }

    $before = xen_frm_field_record($field);
    $opts   = $before['options'];
    $after_opts = $opts;
    $actions = [];

    // ---- additive: append option(s), optionally positioned ----------------
    $append = $req->get_param('append_options');
    if (is_string($append) && $append !== '') {
        $decoded = json_decode($append, true);
        $append = is_array($decoded) ? $decoded : [$append];
    }
    if (is_array($append) && count($append)) {
        $before_key = trim((string) $req->get_param('insert_before_key'));
        $new_entries = [];
        foreach ($append as $item) {
            $label = is_array($item) ? ($item['label'] ?? null) : (string) $item;
            if ($label === null || $label === '') {
                return new WP_REST_Response([
                    'ok' => false, 'error' => 'bad_option',
                    'message' => 'Each appended option needs a non-empty label.',
                ], 400);
            }
            $value = is_array($item) && array_key_exists('value', $item) ? $item['value'] : $label;

            // Refuse a duplicate label outright — two identically-labelled
            // choices make reporting ambiguous forever after.
            foreach ($after_opts as $existing) {
                $el = is_array($existing) ? ($existing['label'] ?? null) : $existing;
                if ($el !== null && (string) $el === (string) $label) {
                    return new WP_REST_Response([
                        'ok' => false, 'error' => 'duplicate_label',
                        'message' => "Option '{$label}' already exists on this field.",
                    ], 409);
                }
            }

            $key = xen_frm_next_option_key(array_merge($after_opts, $new_entries));
            // Mirror the shape of this field's existing options so the new one
            // is indistinguishable in structure from its siblings.
            $shape = ['label' => $label, 'value' => $value];
            foreach ($after_opts as $sib) {
                if (is_array($sib)) {
                    foreach ($sib as $sk => $sv) {
                        if (!array_key_exists($sk, $shape) && $sk !== 'label' && $sk !== 'value') {
                            $shape[$sk] = $sv;
                        }
                    }
                    break;
                }
            }
            $new_entries[$key] = $shape;
            $actions[] = "append option key={$key} label=" . $label;
        }

        if ($before_key !== '') {
            if (!array_key_exists($before_key, $after_opts)) {
                return new WP_REST_Response([
                    'ok' => false, 'error' => 'anchor_not_found',
                    'message' => "insert_before_key '{$before_key}' is not an option key on this "
                               . 'field. Keys: ' . implode(', ', array_keys($after_opts)),
                ], 400);
            }
            // Rebuild preserving order, splicing the new entries in ahead of the
            // anchor. Every existing key and value is carried across untouched.
            $rebuilt = [];
            foreach ($after_opts as $k => $v) {
                if ($k === $before_key) {
                    foreach ($new_entries as $nk => $nv) {
                        $rebuilt[$nk] = $nv;
                    }
                }
                $rebuilt[$k] = $v;
            }
            $after_opts = $rebuilt;
            $actions[] = "positioned before key={$before_key}";
        } else {
            foreach ($new_entries as $nk => $nv) {
                $after_opts[$nk] = $nv;
            }
        }
    }

    // ---- destructive: wholesale options replacement -----------------------
    $replace = $req->get_param('options');
    if ($replace !== null && $replace !== '') {
        if (is_string($replace)) {
            $replace = json_decode($replace, true);
        }
        if (!is_array($replace)) {
            return new WP_REST_Response([
                'ok' => false, 'error' => 'bad_options',
                'message' => 'options must be a JSON object/array.',
            ], 400);
        }
        if (!$allow_destructive) {
            return new WP_REST_Response([
                'ok' => false, 'error' => 'destructive_refused',
                'message' => 'Replacing the whole options array can rename or remove existing '
                           . 'choices. Formidable stores the selected LABEL as the entry value, '
                           . 'and the Other option binds by KEY — so a rename or removal orphans '
                           . 'historical entries irreversibly. Pass allow_destructive=true only '
                           . 'if that is genuinely intended.',
            ], 409);
        }
        $after_opts = $replace;
        $actions[] = 'REPLACED entire options array (destructive)';
    }

    // ---- scalar field properties ------------------------------------------
    $updates = [];
    foreach (['name', 'description', 'required', 'field_order'] as $k) {
        $v = $req->get_param($k);
        if ($v !== null && $v !== '') {
            $updates[$k] = $v;
            $actions[] = "set {$k}";
        }
    }

    // ---- integrity check: what did we do to existing keys? ----------------
    $lost = array_values(array_diff(array_keys($opts), array_keys($after_opts)));
    $changed = [];
    foreach ($opts as $k => $v) {
        if (array_key_exists($k, $after_opts) && $after_opts[$k] !== $v) {
            $changed[] = $k;
        }
    }
    if (($lost || $changed) && !$allow_destructive) {
        return new WP_REST_Response([
            'ok' => false, 'error' => 'would_mutate_existing_options',
            'message' => 'Refusing: this would remove or alter existing option key(s) '
                       . implode(', ', array_merge($lost, $changed))
                       . '. Additive changes only unless allow_destructive=true.',
            'keys_removed' => $lost, 'keys_altered' => $changed,
        ], 409);
    }

    $after_preview = $before;
    $after_preview['options'] = $after_opts;
    foreach ($updates as $k => $v) {
        $after_preview[$k] = $v;
    }

    $result = [
        'ok' => true,
        'dry_run' => $dry_run,
        'actions' => $actions,
        'before' => $before,
        'after'  => $after_preview,
        'integrity' => [
            'field_id_preserved'  => $before['id'] === $after_preview['id'],
            'field_key_preserved' => $before['field_key'] === $after_preview['field_key'],
            'existing_keys_removed' => $lost,
            'existing_keys_altered' => $changed,
            'option_keys_before' => array_keys($opts),
            'option_keys_after'  => array_keys($after_opts),
        ],
        '_meta' => ['site' => home_url(), 'plugin' => 'xen-formidable-rest'],
    ];

    if (!$actions) {
        $result['message'] = 'Nothing to change — no update parameters supplied.';
        return $result;
    }
    if ($dry_run) {
        $result['message'] = 'DRY RUN — nothing written. Send dry_run=false to apply.';
        return $result;
    }

    // ---- apply via the Formidable API, never direct SQL -------------------
    if (!empty($after_opts) || $replace !== null) {
        $updates['options'] = $after_opts;
    }
    FrmField::update($field_id, $updates);

    // ---- read back from a FRESH object; never trust the write -------------
    FrmField::delete_form_transient($field->form_id);
    wp_cache_flush();
    $fresh = FrmField::getOne($field_id);
    $result['after'] = xen_frm_field_record($fresh);
    $result['integrity']['field_id_preserved']  = (int) $fresh->id === $before['id'];
    $result['integrity']['field_key_preserved'] = $fresh->field_key === $before['field_key'];
    $result['integrity']['option_keys_after']   = array_keys($result['after']['options']);

    $verify_lost = array_values(array_diff(
        array_keys($opts), array_keys($result['after']['options'])));
    if ($verify_lost) {
        $result['ok'] = false;
        $result['error'] = 'post_write_verification_failed';
        $result['message'] = 'After writing, option key(s) ' . implode(', ', $verify_lost)
                           . ' are missing. The before block above is the record to restore from.';
        return new WP_REST_Response($result, 500);
    }
    $result['message'] = 'Applied and verified by re-reading the field.';
    return $result;
}

add_action('rest_api_init', function () {
    register_rest_route('xen/v1', '/frm/fields/(?P<field_id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => 'xen_frm_can_edit',
        'args' => [
            'field_id'          => ['required' => true, 'type' => 'integer'],
            'form_id'           => ['required' => false, 'type' => 'integer',
                                    'description' => 'Cross-check: refuses if the field is not on this form.'],
            'append_options'    => ['required' => false,
                                    'description' => 'Array (or JSON string) of labels or {label,value} objects.'],
            'insert_before_key' => ['required' => false, 'type' => 'string',
                                    'description' => 'Existing option key to splice the new options ahead of. Order only — no key is renamed.'],
            'options'           => ['required' => false, 'description' => 'DESTRUCTIVE full replacement. Needs allow_destructive.'],
            'name'              => ['required' => false, 'type' => 'string'],
            'description'       => ['required' => false, 'type' => 'string'],
            'required'          => ['required' => false, 'type' => 'integer'],
            'field_order'       => ['required' => false, 'type' => 'integer'],
            'allow_destructive' => ['default' => false, 'type' => 'boolean'],
            'dry_run'           => ['default' => true, 'type' => 'boolean'],
        ],
        'callback' => 'xen_frm_update_field_endpoint',
    ]);
});

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
