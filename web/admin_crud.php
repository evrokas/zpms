<?php

/**
 * Generic list/add/edit/delete admin UI for the users table and the
 * modernized RBAC tables (permissions, roles, role_permissions,
 * user_roles -- see web/rbac.php's own docblock for the full design).
 * One reusable engine driven by zpms_admin_entity_defs()'s metadata,
 * rather than five near-identical copies of list/new/edit/delete code --
 * every entity here is a plain single-table CRUD form, so there's nothing
 * entity-specific enough to justify the duplication.
 *
 * Every route below (see config/settings.info.yaml's admin_* routes) is
 * gated on the users-manage permission, deliberately not granted to
 * power-user by default (see web/rbac.php's own comment on
 * ZPMS_PERM_USERS_MANAGE) -- this page can create a new is_superuser role
 * and assign it to any account, including its own operator's, so it's
 * treated as more sensitive than the settings-manage-gated Clinics/
 * Doctors pages.
 *
 * Not a moduleClass (like web/modules/backup/) -- those are page-region
 * UI blocks (nav/footer/sidebar widgets), wired via settings.info.yaml's
 * structure: map. This is ordinary page content, same shape as
 * patients_list()/patient_edit() in web/index.php, just parameterized by
 * an {entity} route segment instead of being one function per entity.
 */

// Every column that get*()/set*() generic dispatch is intentionally
// forbidden from touching, even if a field def somehow named one --
// guid/cdate/cuser are set once at insert() time by this file itself
// (matching the convention every other entity in this app follows), id
// is the primary key.
const ZPMS_ADMIN_PROTECTED_COLUMNS = ['id', 'guid', 'cdate', 'cuser'];

// One row per manageable entity. $fields drives both the new/edit form
// (web/templates/content/admin_form.zetem) and, for 'select' fields, the
// option list (queried fresh on every render from source_table/
// source_label -- these are tiny tables, a few rows each, so there's no
// need to cache this). $list_columns drives the list table
// (web/templates/content/admin_list.zetem); $list_row resolves one raw
// `SELECT *` row into that table's display values (e.g. turning a role_id
// into the role's actual name), since showing bare foreign-key ids would
// be useless to a human operator.
function zpms_admin_entity_defs(): array {
    return [
        'users' => [
            'label' => 'Users',
            'class' => 'usersClass',
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'text', 'required' => true],
                ['name' => 'uname', 'label' => 'Username', 'type' => 'text', 'required' => true],
                // Virtual field -- see zpms_admin_apply_field()'s special
                // case. Not a real users.password column; maps to upass
                // via password_hash(), same PASSWORD_DEFAULT algorithm
                // login_post()'s own transparent-upgrade path uses (see
                // core/lib/UserLogin.php in zeusfw). Required on create,
                // optional on edit ("leave blank to keep the current
                // password") -- the plaintext is never stored or
                // redisplayed, so there's nothing to prefill either way.
                ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required_on_create' => true],
                ['name' => 'active', 'label' => 'Active', 'type' => 'checkbox'],
                ['name' => 'expired', 'label' => 'Expired', 'type' => 'checkbox'],
                // Role assignment is managed on the separate user_roles
                // page (many-to-many -- doesn't fit a single-value field
                // on this form), and the legacy users.roles column isn't
                // read by anything anymore (see web/rbac.php's docblock),
                // so neither appears here.
            ],
            // users.roles is CHAR(36) NOT NULL with no default (it's
            // defined in zeusfw core's shared users.yaml, untouched by
            // this app's move off of it -- see web/rbac.php) -- since no
            // field above sets it, a plain insert() would violate that
            // NOT NULL constraint. Blank, not a role name: nothing in
            // this app reads this column anymore, so it's never meant to
            // look like a real (even if empty) role assignment.
            'create_defaults' => ['roles' => ''],
            'list_columns' => ['ID', 'Name', 'Username', 'Email', 'Active', 'Expired'],
            'list_row' => function (array $row): array {
                return [
                    $row['id'],
                    $row['name'],
                    $row['uname'],
                    $row['email'],
                    $row['active'] ? 'Yes' : 'No',
                    $row['expired'] ? 'Yes' : 'No',
                ];
            },
            // No real FK constraints anywhere in this framework (see
            // web/classes/yaml/user_roles.yaml's own docblock) -- deleting
            // a user without this would leave orphaned user_roles rows
            // pointing at a nonexistent user_id.
            'cascade_delete' => [['user_roles', 'user_id']],
        ],
        'permissions' => [
            'label' => 'Permissions',
            'class' => 'permissionsClass',
            'fields' => [
                ['name' => 'name', 'label' => 'Slug (e.g. patients-view-list)', 'type' => 'text', 'required' => true],
                ['name' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
            ],
            'list_columns' => ['ID', 'Slug', 'Label'],
            'list_row' => function (array $row): array {
                return [$row['id'], $row['name'], $row['label']];
            },
            'cascade_delete' => [['role_permissions', 'permission_id']],
        ],
        'roles' => [
            'label' => 'Roles',
            'class' => 'rolesClass',
            'fields' => [
                ['name' => 'name', 'label' => 'Slug (e.g. power-user)', 'type' => 'text', 'required' => true],
                ['name' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true],
                ['name' => 'is_superuser', 'label' => 'Superuser (bypasses every permission check)', 'type' => 'checkbox'],
            ],
            'list_columns' => ['ID', 'Slug', 'Label', 'Superuser'],
            'list_row' => function (array $row): array {
                return [$row['id'], $row['name'], $row['label'], $row['is_superuser'] ? 'Yes' : 'No'];
            },
            'cascade_delete' => [['role_permissions', 'role_id'], ['user_roles', 'role_id']],
        ],
        'role_permissions' => [
            'label' => 'Role Permissions',
            'class' => 'role_permissionsClass',
            'fields' => [
                ['name' => 'role_id', 'label' => 'Role', 'type' => 'select', 'source_table' => 'roles', 'source_label' => 'name', 'required' => true],
                ['name' => 'permission_id', 'label' => 'Permission', 'type' => 'select', 'source_table' => 'permissions', 'source_label' => 'name', 'required' => true],
            ],
            'list_columns' => ['ID', 'Role', 'Permission'],
            'list_row' => function (array $row): array {
                return [$row['id'], zpms_admin_lookup_label('roles', 'name', (int)$row['role_id']), zpms_admin_lookup_label('permissions', 'name', (int)$row['permission_id'])];
            },
        ],
        'user_roles' => [
            'label' => 'User Roles',
            'class' => 'user_rolesClass',
            'fields' => [
                ['name' => 'user_id', 'label' => 'User', 'type' => 'select', 'source_table' => 'users', 'source_label' => 'uname', 'required' => true],
                ['name' => 'role_id', 'label' => 'Role', 'type' => 'select', 'source_table' => 'roles', 'source_label' => 'name', 'required' => true],
            ],
            'list_columns' => ['ID', 'User', 'Role'],
            'list_row' => function (array $row): array {
                return [$row['id'], zpms_admin_lookup_label('users', 'uname', (int)$row['user_id']), zpms_admin_lookup_label('roles', 'name', (int)$row['role_id'])];
            },
        ],
    ];
}

function zpms_admin_entity_def(string $entity): ?array {
    return zpms_admin_entity_defs()[$entity] ?? null;
}

// Small, uncached lookups (id -> label) for list_row() above -- these
// tables have a handful of rows each, so a fresh query per cell is not
// worth complicating with a cache.
function zpms_admin_lookup_label(string $table, string $labelCol, int $id): string {
    if (!$id) {
        return '—';
    }
    $st = dbConnection::getConnection()->prepare("SELECT `$labelCol` AS label FROM `$table` WHERE id = :id");
    $st->bindValue(':id', $id, PDO::PARAM_INT);
    $st->execute();
    $row = $st->fetch();
    return $row ? (string)$row['label'] : "(deleted #$id)";
}

// [id => label, ...] for a 'select' field's <option> list.
function zpms_admin_select_options(string $table, string $labelCol): array {
    $st = dbConnection::getConnection()->query("SELECT id, `$labelCol` AS label FROM `$table` ORDER BY `$labelCol`");
    $options = [];
    while ($row = $st->fetch()) {
        $options[(int)$row['id']] = $row['label'];
    }
    return $options;
}

// get{$field}() for any real column -- 'password' (users only) is
// virtual and never actually stored/redisplayed, so it always renders
// blank regardless of the account's real password state.
function zpms_admin_field_value(object $instance, string $field) {
    if ($field === 'password') {
        return '';
    }
    $getter = 'get' . $field;
    return $instance->$getter();
}

// set{$field}($value) for any real column, with the type coercion each
// field type needs and the 'password' virtual-field special case. Never
// touches ZPMS_ADMIN_PROTECTED_COLUMNS. Returns false (caller should skip
// this field entirely) only for an on-edit password field left blank --
// "leave blank to keep the current password", same convention DocArc uses
// for its own never-redisplayed secret fields.
function zpms_admin_apply_field(object $instance, array $fieldDef, $rawValue, bool $isCreate): bool {
    $field = $fieldDef['name'];
    if (in_array($field, ZPMS_ADMIN_PROTECTED_COLUMNS, true)) {
        return false;
    }

    if ($field === 'password') {
        $rawValue = trim((string)$rawValue);
        if ($rawValue === '') {
            return false; // create: caller already enforced required_on_create; edit: keep current
        }
        $instance->setupass(password_hash($rawValue, PASSWORD_DEFAULT));
        return true;
    }

    $setter = 'set' . $field;
    switch ($fieldDef['type']) {
        case 'checkbox':
            $instance->$setter(isset($rawValue) && $rawValue !== '' ? 1 : 0);
            break;
        case 'select':
            $instance->$setter((int)$rawValue);
            break;
        default:
            $instance->$setter(trim((string)$rawValue));
    }
    return true;
}

function zpms_admin_new_instance(string $class, string $cuser, array $extraDefaults = []): object {
    return new $class(array_merge($extraDefaults, [
        'guid' => guid(),
        'cuser' => $cuser,
        'cdate' => getDBtime(),
    ]));
}

// -- Route handlers --------------------------------------------------------

function admin_list($params) {
    global $kernel;

    if (($errmsg = zpms_require_permission(ZPMS_PERM_USERS_MANAGE))) return $errmsg;

    $def = zpms_admin_entity_def($params['entity'] ?? '');
    if (!$def) {
        return error_404("Unknown admin entity '" . ($params['entity'] ?? '') . "'");
    }

    // Raw SQL rather than $class::sgetAll()/getFields() -- the generated
    // getFields() (maker.php code-gen) never includes the id column (see
    // core/db/dbal.php's base getFields(), which every generated class's
    // own getFields() starts from and merges into), and every list_row()
    // callback above needs $row['id'] plus the raw foreign-key columns.
    // $table is interpolated directly into the query below, but it's safe:
    // zpms_admin_entity_def() above already returned a 404 for anything
    // other than one of the 5 literal keys hardcoded in
    // zpms_admin_entity_defs() (which happen to equal their own table
    // names), so by this line $params['entity'] can only be one of those.
    $table = $params['entity'];
    $rows = [];
    $st = dbConnection::getConnection()->query("SELECT * FROM `$table` ORDER BY id");
    while ($row = $st->fetch()) {
        $rows[] = ($def['list_row'])($row);
    }

    return Renderer::render('admin_list.zetem', [
        'entity' => $params['entity'],
        'entity_label' => $def['label'],
        'columns' => $def['list_columns'],
        'rows' => $rows,
        'entities' => zpms_admin_entity_defs(),
    ]);
}

function admin_new($params) {
    if (($errmsg = zpms_require_permission(ZPMS_PERM_USERS_MANAGE))) return $errmsg;

    $def = zpms_admin_entity_def($params['entity'] ?? '');
    if (!$def) {
        return error_404("Unknown admin entity '" . ($params['entity'] ?? '') . "'");
    }

    return Renderer::render('admin_form.zetem', [
        'action' => 'new',
        'entity' => $params['entity'],
        'entity_label' => $def['label'],
        'fields' => zpms_admin_render_fields($def['fields'], null),
    ]);
}

function admin_new_post($params) {
    global $kernel;

    if (($errmsg = zpms_require_permission(ZPMS_PERM_USERS_MANAGE))) return $errmsg;
    if (($err = csrfClass::requireValid())) return $err;

    $entity = $params['entity'] ?? '';
    $def = zpms_admin_entity_def($entity);
    if (!$def) {
        return error_404("Unknown admin entity '$entity'");
    }

    foreach ($def['fields'] as $fieldDef) {
        if (!empty($fieldDef['required_on_create']) && trim((string)($_POST[$fieldDef['name']] ?? '')) === '') {
            $kernel->addStatus('error', $fieldDef['label'] . ' is required.');
            header('location: ' . rel_url("/admin/$entity/new"));
            exit();
        }
    }

    $instance = zpms_admin_new_instance($def['class'], $kernel->getUserName() ?? 'admin-ui', $def['create_defaults'] ?? []);
    foreach ($def['fields'] as $fieldDef) {
        zpms_admin_apply_field($instance, $fieldDef, $_POST[$fieldDef['name']] ?? null, true);
    }
    $instance->insert();

    $kernel->addStatus('notice', $def['label'] . ' created.');
    header('location: ' . rel_url("/admin/$entity"));
    exit();
}

function admin_edit($params) {
    if (($errmsg = zpms_require_permission(ZPMS_PERM_USERS_MANAGE))) return $errmsg;

    $entity = $params['entity'] ?? '';
    $def = zpms_admin_entity_def($entity);
    if (!$def) {
        return error_404("Unknown admin entity '$entity'");
    }

    $instance = $def['class']::sgetById((int)($params['id'] ?? 0));
    if (!$instance) {
        return error_404("$entity #{$params['id']} not found");
    }

    return Renderer::render('admin_form.zetem', [
        'action' => 'edit',
        'id' => (int)$params['id'],
        'entity' => $entity,
        'entity_label' => $def['label'],
        'fields' => zpms_admin_render_fields($def['fields'], $instance),
    ]);
}

function admin_edit_post($params) {
    global $kernel;

    if (($errmsg = zpms_require_permission(ZPMS_PERM_USERS_MANAGE))) return $errmsg;
    if (($err = csrfClass::requireValid())) return $err;

    $entity = $params['entity'] ?? '';
    $def = zpms_admin_entity_def($entity);
    if (!$def) {
        return error_404("Unknown admin entity '$entity'");
    }

    $id = (int)($params['id'] ?? 0);
    $instance = $def['class']::sgetById($id);
    if (!$instance) {
        return error_404("$entity #$id not found");
    }

    foreach ($def['fields'] as $fieldDef) {
        zpms_admin_apply_field($instance, $fieldDef, $_POST[$fieldDef['name']] ?? null, false);
    }
    $instance->update();

    $kernel->addStatus('notice', $def['label'] . ' updated.');
    header('location: ' . rel_url("/admin/$entity"));
    exit();
}

function admin_delete($params) {
    global $kernel;

    if (($errmsg = zpms_require_permission(ZPMS_PERM_USERS_MANAGE))) return $errmsg;
    if (($err = csrfClass::requireValid())) return $err;

    $entity = $params['entity'] ?? '';
    $def = zpms_admin_entity_def($entity);
    if (!$def) {
        return error_404("Unknown admin entity '$entity'");
    }

    $id = (int)($params['id'] ?? 0);
    $instance = $def['class']::sgetById($id);
    if ($instance) {
        // No real FK constraints anywhere in this framework, so this has
        // to be done by hand -- delete anything referencing this row
        // (e.g. every role_permissions/user_roles row for a deleted
        // role) BEFORE the row itself, per each entity's own
        // cascade_delete list above.
        foreach ($def['cascade_delete'] ?? [] as [$depTable, $depColumn]) {
            $st = dbConnection::getConnection()->prepare("DELETE FROM `$depTable` WHERE `$depColumn` = :id");
            $st->bindValue(':id', $id, PDO::PARAM_INT);
            $st->execute();
        }

        // A logged-in operator deleting their own only user_roles row
        // (or their users row entirely) is a real footgun -- not blocked
        // here, since a genuine "demote/replace yourself" action is
        // legitimate and this page is already restricted to
        // users-manage holders, same trust level this app extends
        // everywhere else once a permission check passes.
        $instance->delete();
        $kernel->addStatus('warning', $def['label'] . ' #' . $id . ' deleted.');
    }

    header('location: ' . rel_url("/admin/$entity"));
    exit();
}

// Builds the per-field view-model admin_form.zetem renders: current
// value (blank for 'new'), and resolved <option> list for 'select'
// fields. $instance is null for a new-record form.
function zpms_admin_render_fields(array $fieldDefs, ?object $instance): array {
    $out = [];
    foreach ($fieldDefs as $fieldDef) {
        $view = $fieldDef;
        $view['value'] = $instance ? zpms_admin_field_value($instance, $fieldDef['name']) : '';
        if ($fieldDef['type'] === 'select') {
            $view['options'] = zpms_admin_select_options($fieldDef['source_table'], $fieldDef['source_label']);
        }
        $out[] = $view;
    }
    return $out;
}
