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

## Backups + health monitoring

Backups and health checks both run on **zops**
(<https://github.com/evrokas/zops>), a shared engine used across this
practice's app suite — replacing the old app-specific `bin/backup.sh`
(deleted; see git history if ever needed for reference) and this app's
own `web/modules/backup/` (replaced by a generalized module now in
zeusfw core, `core/modules/backup/` — see zeusfw's own CLAUDE.md).
`deploy/backup-handler.php`/`deploy/health-handler.php` tell zops how to
back up and health-check ZPMS specifically; zops itself carries no
knowledge of this app's schema.

`deploy/backup-handler.php` ships 3 elements: `db` (`mysqldump
--single-transaction`), `files` (`web/files/appointment_files/` — patient
appointment files + generated JPEG thumbnails, hardlinked live), and
`config` (`config/db.php`, plus `config/docarc_api.php` if the DocArc
patient-lookup integration is configured) — and reports
`missing_files` in `stats` by cross-checking
`appointment_files.file_path`/`thumbnail_path` against what's actually on
disk. An unchanged file costs zero extra network/disk on every run after
the first (`--link-dest`-style hardlinking, both locally and over SSH).

**GDPR erasure vs. retention.** The newest generation always reflects
current state — a deleted patient/record is simply absent from the next
night's dump. Older, already-published generations still contain it
(retention *expiry*, not active scrubbing) since hardlinked generations
preserve content regardless of what happens on the primary afterward. With
the default 14/8/12 tiers, a record deleted right after a monthly snapshot
was promoted can remain recoverable from that monthly generation for up to
~13 months before it ages out — lower `KEEP_MONTHLY` in this site's zops
config if your erasure obligations need a tighter bound.

**Setup:**
```sh
git clone https://github.com/evrokas/zops lib/zops
sudo mkdir -p /etc/zops/sites.d
sudo cp deploy/backup.conf.example /etc/zops/sites.d/zpms.conf
sudo $EDITOR /etc/zops/sites.d/zpms.conf
sudo chmod 600 /etc/zops/sites.d/zpms.conf
sudo cp deploy/zpms-backup.cron /etc/cron.d/zpms-backup
```
See `deploy/backup.conf.example` for every variable. Credentials for
`mysqldump` are read from this app's own `config/db.php` — never placed
on the command line or in an environment variable, both of which leak to
`ps`/shell history/`/proc` — instead handed to the MySQL client tools via
a temporary, mode-600 `--defaults-extra-file` that's deleted the moment
the run exits.

Verify before trusting cron with it:
```sh
php lib/zops/bin/zops-doctor --site=zpms
php lib/zops/bin/zops-backup --site=zpms --dry-run
```

Each run writes a status report (see `lib/zops/docs/PROTOCOL.md`) to
`STATUS_FILE`; set `zops_backup_status_file` to that same path in
`config/site.info.yaml` and the admin **Backups** page (`/apps/backup`,
now backed by zeusfw core's own module, gated by the
`ZEUSFW_PERM_MANAGE_USERS` permission rather than this app's own
`ZPMS_PERM_BACKUP_ACCESS`, which is no longer consumed anywhere) surfaces
the full report — status, per-element sizes, destination tiers — not just
a last-run timestamp. That page doesn't trigger backups itself.

**Restoring:**
```sh
php lib/zops/bin/zops-restore --site=zpms list
php lib/zops/bin/zops-restore --site=zpms fetch --tier=daily --gen=<name> --to=/tmp/zpms-restore
```
Fetches the chosen generation's `db.sql.gz` and `files/` down to a local
directory. Deliberately **no** `restore` verb on this app's handler —
same reasoning the old `bin/restore.sh` already documented: overwriting a
live production database automatically is a much higher-consequence
action than restoring files, so importing the verified dump
(`zcat db.sql.gz | mysql ...`) into whichever database you choose stays a
deliberate, manual step.

**Health checks**: `php lib/zops/bin/zops-monitor --site=zpms` checks the
database connects, `web/files/appointment_files/` is genuinely writable
(a real write-and-delete probe), and the home route + (if configured)
`api_patients.php` behave correctly with no credentials involved. Surfaces
`patients_total`, `appointments_today`, and `files_uploaded_today`. The
same `zpms-backup.cron` file runs this every 15 minutes with
`--email-on-change`.

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

**ZPMS does not check which ErnsAuth identity approved an SSO login — this
is a deliberate choice, not a gap.** `ernsauthClass::finish()`
(`core/lib/ErnsAuth.php`, zeusfw) trusts any successful `exchangeCode()` for
the pending username: whichever ErnsAuth account clicks approve on the
dashboard, that click signs the browser into the ZPMS account whose `uname`
was typed at the login form. ErnsAuth's Pending Logins card still shows the
requested username as a courtesy ("Claiming to be `guest`") so a human
approver can visually catch an impersonation attempt before clicking, but
nothing enforces it — that's the entire remaining check, and it's a human
one, not a code one.

This was decided after weighing the tradeoff directly: with a small,
trusted set of people holding ErnsAuth dashboard access, requiring an exact
username match added friction (accounts like `guest` whose ErnsAuth
identity is spelled differently kept failing SSO for no operational
reason) without a corresponding benefit worth that friction. The
consequence to be clear-eyed about: **any currently-logged-in ErnsAuth
user can sign into *any* ZPMS account by typing its username** — there is
no per-account restriction. Keep the pool of ErnsAuth accounts with
dashboard access to people who should be trusted with every ZPMS account
this way, and treat an ErnsAuth account compromise as equivalent to
compromising every SSO-reachable ZPMS account at once. This doesn't apply
to password login, which is unaffected and still gates each account
independently. See ernsauth's own `CLIENT-INTEGRATION.md` ("Requiring a
username before Flow A") for the general-purpose guidance this
intentionally departs from, and its note on apps that make this same
choice.

## Date fields (flatpickr)

The patient date-of-birth field (`edit_patient.zetem`) uses
[flatpickr](https://flatpickr.js.org) for a consistent `dd-mm-yyyy` calendar
picker regardless of the browser's own locale — the same reasoning DocArc's
own `CLAUDE.md` documents for its (committed) copy of the same library.
This previously ran on [Pikaday](https://github.com/Pomax/Pikaday) (plus
`moment.js` for its locale handling), wired up via a `pikaday-library`
entry in `config/settings.info.yaml` — removed outright, along with a
separate, never-finished `datepicker` module that predates both and was
never wired into any template. Neither was ever actually functional in
production: both `attach_library()` calls sat commented out in
`edit_patient.zetem`, so every date field was, in practice, a plain native
`<input type="date">`/free-typed text field until this change.

Same convention as DocArc: flatpickr's built files are committed
directly into the repo, at `web/modules/flatpickr/vendor/flatpickr/`
(`flatpickr.min.js`, `flatpickr.min.css`, `LICENSE.md` — currently
v4.6.13) — already in the repo, nothing to clone or install. This is a
static asset needing no build step of its own to serve, same reasoning
DocArc's `CLAUDE.md` gives for its copy; the one wrinkle is that
flatpickr's own upstream repo doesn't commit this build output itself
(`dist/flatpickr.min.js`/`.css` are gitignored *there*, generated by
their TypeScript toolchain), so updating this vendored copy to a newer
flatpickr release means building it once yourself before committing the
result:

```sh
git clone https://github.com/flatpickr/flatpickr.git /tmp/flatpickr-src
cd /tmp/flatpickr-src && git checkout <tag> && npm install && npm run build:build
cp dist/flatpickr.min.js dist/flatpickr.min.css LICENSE.md \
   /path/to/zpms/web/modules/flatpickr/vendor/flatpickr/
```

That's a one-off step on a machine with Node, done by whoever updates
the vendored copy — not part of any deploy or checkout, and nothing in
the running app ever invokes npm. The `flatpickr` module
(`web/modules/flatpickr/`, listed under `config/settings.info.yaml`'s
`modules:`, same "always registered" mechanism as `datepicker` before
it) just serves those two committed files as plain static CSS/JS,
`@app`-macro-resolved to
`web/modules/flatpickr/vendor/flatpickr/flatpickr.min.{css,js}`.

The real, submitted value stays plain ISO `Y-m-d` (flatpickr's hidden
`dateFormat` input) — what `getDBformattime()` (zeusfw
`core/kernel/utils.php`) already expects — while flatpickr's separate,
visible "alt" input displays and accepts typing in this app's usual
`d-m-Y` convention (`altFormat`, matching `formatDate()`/
`formatDateTime()` in the same file). The live age badge next to the
field (`dobChange()` in `web/js/scripts.js`) needed no changes: it's
simply pointed at that alt input instead of the original one, via
flatpickr's `onReady`/`onValueUpdate` hooks.
