# zpms
Zeus Patient Management System

## Regression tests

Run before every update to the live app:

```sh
bin/run_tests.sh
```

Static PHP/JS/CSS/template consistency checks, then patient/appointment/
auth/file-upload functional tests driven over real HTTP against a
dedicated, disposable test database — never the live one. See
`tests/README.md` for one-time setup and the safety design.

## Roles and permissions

Staff accounts (the `users` table) are authorized via a standard
role-based model: `permissions` (the fixed set of things the app actually
checks — `patients-view-list`, `appointment-edit`, etc.), `roles` (named,
assignable bundles like `power-user`), `role_permissions` (which
permissions a role grants), and `user_roles` (which roles a user has). The
*engine* — this schema, the `rolesClassEx`/`permissionsClassEx`/
`role_permissionsClassEx`/`user_rolesClassEx` extension classes, and the
`rbacClass::isPermitted()`/`rbacClass::require()` permission-check
functions — lives in the shared ZeusFW framework
(`web/core/lib/Rbac.php`, `web/core/ClassExFW.php`,
`web/core/classes/yaml/{roles,permissions,role_permissions,
user_roles}.yaml`), the same "defined once in core" pattern already used
for the `users` table — see that file's own docblock for the full design,
including two real bugs in the previous system this replaced (most
importantly, every permission check silently passed for any logged-in
user regardless of role). This app only owns its own permission
*vocabulary*: the `ZPMS_PERM_*` constants + `zpms_all_permission_slugs()`
in `web/rbac.php`, and the seed role/label data in `web/rbac_seed.php`.

**Admin UI:** list/add/edit/delete for users and all four RBAC tables
lives at `/admin/{entity}` (`entity` is `users`, `permissions`, `roles`,
`role_permissions`, or `user_roles`) — linked from Settings under "User &
Role Management" for anyone who can see it. This UI is also framework-
provided (`web/core/modules/admin/admin_crud.php`, one generic engine
driving all five entities from a metadata array). Its routes are
registered by the framework itself in `web/core/config/zeusfw.info.yaml` —
unconditionally, independent of this (or any) app's own module opt-ins —
as the `admin_user_crud` package; it can be turned off for this
deployment via `config/site.info.yaml`'s `disabled_packages:` list (empty
by default here, since this app actively uses the page — see that file's
own comments, and `web/core/lib/Packages.php`, for the general
enable/disable mechanism any future framework package uses the same way).
Gated behind the framework's own `ZEUSFW_PERM_MANAGE_USERS`
permission (slug `users-manage`, seeded by this app under that same
string value), deliberately **not** granted to `power-user` by default:
this page can create a new `is_superuser` role and assign it to any
account, including its own operator's, so treat it as more sensitive than
the `settings-manage`-gated Clinics/Doctors pages. Grant it explicitly
(via the User Roles page itself, or `user_rolesClassEx::assignRole(...)`
directly) to whichever accounts should have it — an `is_superuser` account
(the seeded `administrator` role) always has it implicitly and needs no
explicit grant.

**Deploying this for the first time / to a server still on the old
scheme:** the RBAC tables must exist before the app code that uses them
does, and every existing account needs an actual `user_roles` row before
it can pass any permission check again (it can still log in either way —
only fine-grained permission checks are affected, not authentication
itself):

```sh
# 1. Generate + load the RBAC tables -- these live in the ZeusFW
#    framework checkout now (web/core, vendored per your normal deploy
#    process), not this app's own web/classes/. Same steps your normal
#    deploy already runs for any zeusfw core schema change — there's no
#    migration runner, so this part is manual:
cd web/core/classes
php ../maker/maker.php spill:class:all
php ../maker/maker.php update:bootstrap
php ../maker/maker.php spill:sql:all
mysql -u <user> -p <db> < sql/permissions.sql
mysql -u <user> -p <db> < sql/roles.sql
mysql -u <user> -p <db> < sql/role_permissions.sql
mysql -u <user> -p <db> < sql/user_roles.sql

# 2. Preview the migration (writes nothing):
cd -
php bin/migrate_roles.php --dry-run

# 3. Apply it -- seeds permissions/roles/role_permissions and transfers
#    every user's existing users.roles value into a user_roles row:
php bin/migrate_roles.php --yes
```

`bin/migrate_roles.php --dry-run` prints exactly what it would do,
including a warning for any account whose legacy `roles` value doesn't
match a known role name (nothing is ever silently dropped — see the
script's own header comment). It's idempotent, so re-running it after
fixing something it flagged is safe.

## Settings page: Clinics/Doctors management

The Clinics/Doctors sections on `/settings` are rendered by zeusfw core's
generic `formsClass` (`web/core/lib/WebForms.php`) from a `webforms` DB row
per form, generated from `web/classes/yaml/{clinics,doctors}.yaml`'s own
`form:` block — but nothing creates that row automatically. On a database
that has never had this step run, `formsClass::getForm('clinics')` returns
null and the sections render as empty (`renderFormResults()`/`renderForm()`
both degrade gracefully rather than crash — see zeusfw's own `CLAUDE.md` for
the fix that made that true). Run once per app database (idempotent — safe
to re-run, it updates the existing row instead of duplicating it):

```sh
cd web/classes
php ../core/maker/maker.php form:load yaml/clinics.yaml
php ../core/maker/maker.php form:load yaml/doctors.yaml
```

Needs `config/db.php` in place (the real, live database config) since this
writes directly to it — same prerequisite as the RBAC deploy steps above.

## Backups

`bin/backup.sh`, run nightly via cron/systemd (reference configs:
`deploy/zpms-backup.cron`, `deploy/zpms-backup.timer`/`.service` — install
one or the other manually, neither is auto-applied by anything in this
repo), takes a consistent MySQL dump (`mysqldump --single-transaction`, so
InnoDB doesn't need locking for a consistent snapshot) and hardlinks
`web/files/` (the app's upload/library store — e.g. appointment
attachments) alongside it, then ships both off-site via rsync
over SSH using rotating `--link-dest` generations: `daily/` (kept 14 by
default), promoted into `weekly/` (8) and `monthly/` (12) using the first
generation of each new ISO week/month, so a missed cron night never leaves
a permanent gap in a tier. An unchanged file costs zero extra network/disk
on every run after the first. `web/cache/` (ephemeral QR codes, purged
every request) is excluded.

**GDPR erasure vs. retention.** The newest generation always reflects
current state — a deleted patient/record is simply absent from the next
night's dump. Older, already-published generations still contain it
(retention *expiry*, not active scrubbing) since `--link-dest` hardlinks
preserve content regardless of what happens on the primary afterward. With
the default 14/8/12 tiers, a record deleted right after a monthly snapshot
was promoted can remain recoverable from that monthly generation for up to
~13 months before it ages out — lower `BACKUP_KEEP_MONTHLY` in
`backup.conf` if your erasure obligations need a tighter bound.

**Setup:**
```sh
sudo mkdir -p /etc/zpms
sudo cp deploy/backup.conf.example /etc/zpms/backup.conf
sudo $EDITOR /etc/zpms/backup.conf
sudo chmod 600 /etc/zpms/backup.conf
```
See `deploy/backup.conf.example` for every variable. Credentials for
`mysqldump`/`mysql` are read from this app's own `config/db.php` — never
placed on the command line or in an environment variable, both of which
leak to `ps`/shell history/`/proc` — instead handed to the MySQL client
tools via a temporary, mode-600 `--defaults-extra-file` that's deleted the
moment the script exits.

Each run writes `web/files/logs/backup_status.json` (last run time,
success/failure), surfaced read-only on the admin **Backups** page
(`/apps/backup`) — that page doesn't trigger backups itself, it just shows
whether last night's run succeeded.

**Restoring:**
```sh
bin/restore.sh --list
bin/restore.sh daily/2026-08-08T020000Z
```
Fetches the chosen generation, verifies the dump loads cleanly into a
throwaway scratch database, and restores `web/files/`. It deliberately
does **not** auto-import the dump into your live database — overwriting a
live production database automatically is a much higher-consequence
action than restoring files, so the verified dump is left in place with
the exact `mysql ... < zpms.sql` command printed for you to run manually
against whichever database you choose.

## ErnsAuth SSO login

Optional number-matching sign-in alongside the existing username/password
form — staff type their ZPMS username, approve a shown number from an
already-authenticated ErnsAuth dashboard session (on another device or
tab), and are signed in as that same local account. See
[CLIENT-INTEGRATION.md](https://github.com/evrokas/ernsauth/blob/main/CLIENT-INTEGRATION.md)
in the ernsauth repo, "Requiring a username before Flow A", for the full
protocol and the security requirements this implementation follows. The
reusable engine (`ernsauthClass`, the `ernsauth_sso_attempts` rate-limit/
one-pending-challenge table, and the `/login/ernsauth/{start,poll,exchange}`
routes) lives in zeusfw core, shared by any app on the framework — this
app only supplies its own config, vendored client library, and the
login-page UI.

**Setup:**

1. Register ZPMS as a client app in the ErnsAuth dashboard: **Admin →
   Client Apps → Add App**. Copy the API key shown at creation — it's
   never shown again.
2. Vendor the client library outside the web root:
   ```sh
   git clone -b stable https://github.com/evrokas/ernsauth.git lib/ernsauth
   ```
3. Copy `config/ernsauth.php.in` to `config/ernsauth.php` and fill in the
   real `sso_api_url`/`api_key` (see that file's own comments).

`ernsauth_sso` is already listed under `config/settings.info.yaml`'s
`modules:` block — that alone does **not** turn the feature on;
`ernsauthClass::isEnabled()` also requires step 3's config file to be
present and valid. Until both are done, `login.zetem` renders exactly as
it did before this integration existed — no "Sign in with ErnsAuth"
section, no error.

**Mapping a ZPMS account to its real ErnsAuth identity.** These are two
independent username spaces — ErnsAuth has no idea a ZPMS `uname` even
exists, let alone that it means anything on its side. Every account that
will sign in via ErnsAuth needs its real ErnsAuth username set in the
**ErnsAuth Username** field on `/admin/users` (Settings → Users →
edit) — a plain `users.ernsauth_username` column (zeusfw core), so it's
just another field on the existing admin form, nothing to deploy. Leave it
blank and `ernsauthClass::startChallenge()` falls back, in order, to an
app-defined `zeusfw_app_resolve_ernsauth_username(usersClass $user):
string` hook in `web/ClassesEx.php` (same `function_exists()`
extension-point convention as `zeusfw_app_resolve_user_roles()`) if one
exists, and only then to assuming the ZPMS `uname` and the ErnsAuth
username are spelled identically — a convenience for a quick local test,
**not a safe production default**: the moment a real account's ErnsAuth
username is spelled differently, every login attempt for it silently
rejects (logged as `ernsauth sso mismatched for <uname>`) with nothing in
the log to explain why beyond that. Set the field explicitly for every
real account rather than relying on the guess.
