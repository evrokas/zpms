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
assignable bundles like `doctor`), `role_permissions` (which
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

**Four roles, matching four real job functions at this practice**
(`web/rbac_seed.php`'s `zpms_role_seed_definitions()`):

| Role | Label | Permissions |
|---|---|---|
| `administrator` | Διαχειριστής | Everything (`is_superuser` bypasses the permission check entirely — no explicit grants needed or shown) |
| `doctor` | Ιατρός | `patients-view-list`, `patients-new-patient`, `patients-edit-patient`, `patients-delete-patient`, `appointment-edit`, `backup-access`, `settings-manage`, `pending-appointments-manage` — full clinical access |
| `secretary` | Γραμματεία | `patients-view-list`, `pending-appointments-manage` — see below |
| `maintenance` | Συντήρηση | `backup-access`, `settings-manage` — ops-only, zero patient-data access |

**`secretary` can see a patient, not change one.** `patients-view-list`
covers both the patient list *and* opening an individual patient's page
(name/AMKA/contact details/appointment history) — deliberately one
permission for "can see patient data, read-only" rather than a separate
view-list/view-record split (see that constant's own docblock in
`web/rbac.php`). `patient_edit()` (`web/index.php`) renders the page for
anyone holding just this permission with every patient field inside a
disabled `<fieldset>`, no save button (a plain "← Back to list" link
instead), and no "New appointment"/"New operation" links. Appointments/
operations themselves render as a compact, read-only table (date +
location only, per-row — see `$appointment_summary` in `patient_edit()`)
rather than the full editable card (`view_appointment.zetem` — notes,
save/delete, file uploads) a holder of `appointment-edit` gets: neither
the appointment's own notes nor its attachments are shown, since those
can carry more clinical detail than a front-desk lookup needs, and every
`appointment_file_*` route still requires `appointment-edit` regardless
of what this page shows, so hiding the section is a UI courtesy matching
a gate that already exists, not the only thing enforcing it. The patient
list's own "Add new patient" button and per-row delete form are likewise
hidden when the viewer lacks `patients-new-patient`/
`patients-delete-patient`. Actually saving a change always re-checks
`patients-edit-patient`/`appointment-edit` server-side in
`patient_edit_post()`/`appointment_edit_post()`, regardless of what a
tampered request submits — the read-only rendering is a UX nicety on top
of a real, independently-enforced gate, not the gate itself. `secretary`
additionally holds `pending-appointments-manage` (see "Google Calendar
sync" below) — it can book/edit/cancel a phone
appointment in the waiting room, but converting one into a real patient
record stays a `doctor`-only action.

**`maintenance` is a technical/ops account**, for whoever administers the
server — `backup-access` (check that backups actually ran) and
`settings-manage` (keep the clinics/doctors reference data current), and
nothing else: no patient, appointment, or pending-appointment access at
all, and no `users-manage` either (account/role administration stays an
`administrator`-only concern, same as it already was for `doctor`).

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
string value), deliberately **not** granted to `doctor` or `maintenance`
by default: this page can create a new `is_superuser` role and assign it
to any account, including its own operator's, so treat it as more
sensitive than the `settings-manage`-gated Clinics/Doctors pages. Grant it
explicitly (via the User Roles page itself, or
`user_rolesClassEx::assignRole(...)` directly) to whichever accounts
should have it — an `is_superuser` account (the seeded `administrator`
role) always has it implicitly and needs no explicit grant.

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

**Upgrading an existing deployment that still has `power-user`/`user`
roles** (from before the role-vocabulary refactor above) — run this once,
after deploying this code, in the same order:

```sh
php bin/migrate_role_refactor.php --dry-run    # always first
php bin/migrate_role_refactor.php --yes
```

Renames `power-user` to `doctor` **in place** — the role's id is
preserved, so every account already assigned `power-user` keeps its exact
access under the new name with zero manual reassignment. Retires `user`,
but refuses to delete it (and reports exactly who) if any account is
still assigned it, rather than silently locking that account out of
every permission check — reassign those accounts to another role first
(e.g. via `/admin/user_roles`), then re-run. Also seeds the new
`maintenance` role in the same pass. Safe to run more than once —
each step is a no-op once it's already done (see
`zpms_rename_role()`/`zpms_retire_role()` in `web/rbac_seed.php` for the
exact idempotence/merge behavior, including the out-of-order case where
`bin/migrate_roles.php`'s own additive seeding already created a fresh
`doctor` role before this script got a chance to rename the old one).

**Verified**: `bin/migrate_role_refactor.php` was run against a database
seeded with the *old* shape (a `power-user`-assigned account, a
`user`-assigned account) — the rename preserved the account's access
under the new `doctor` name with no reassignment needed (confirmed
directly against `user_roles`); the retire step correctly refused to
delete `user` while an account still held it, reported that account by
name, and then correctly deleted it once that account was reassigned and
the script re-run; a second `--yes` run afterward was a clean no-op
(every step reported "exists"/"not found" rather than re-doing anything).
`bin/run_tests.sh` (34/34 static, 35/35 functional, including a new
`secretary`-role functional test covering the read-only patient page)
stayed green throughout.

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

## Google Calendar sync

Consultation scheduling: a patient calls, staff take their name/phone/
AMKA/email/location and a date/time on one fast screen (`/consultation/new`,
"Ραντεβού → Νέο (τηλεφωνικά)" in the nav), which pushes a matching event to
a shared Google Calendar in the same request — so the practice's day-to-day
calendar (glanceable from anyone's phone, gets Google's own reminders)
always reflects what's been booked, with no separate "now go create the
calendar event too" step.

**No patient record is created at booking time.** A phone call is not yet
a patient on file — the doctor creates that record deliberately, once the
person actually shows up (or calls back to confirm), by "converting" the
booking. Every booking, whether made on this screen or directly in the
Calendar app, first becomes a row in a dedicated **Εκκρεμή Ραντεβού**
("pending appointments") list — a waiting room, not the patient list. A
**secretary** role can book, view, edit, and cancel entries on that list
(front-desk work), and can look up/open a real patient record read-only
(see "Roles and permissions" above), but cannot create, edit, or delete
one; only a **doctor** (which already has both `patients-new-patient` and
`appointment-edit`) sees the "create patient record" action and can
perform the actual conversion. See "Front-desk role: secretary" below for
the full permission shape.

Staff who reschedule or cancel directly in the Calendar app have that
reflected back automatically by a periodic sync — against the pending
entry if it's still pending, or against the real appointment if it's
already been converted. Staff who create an event directly in Calendar (no
ZPMS screen involved at all) get it queued as a new pending appointment
(patient name from the event title, time from the event) for a human to
fill in phone/AMKA/location and either convert or cancel — same list,
same flow, regardless of where the booking originated.

**Why this direction, not Calendar-first with ZPMS parsing the event
text**: AMKA/phone/dedup only exist as structured, validated fields in
ZPMS today. Typing them into a Calendar event's title/description and
parsing them back out later is fragile (Greek name spelling variants,
staff typos, no shared convention for what goes where) — the "Calendar →
ZPMS" pull sync deliberately never tries to extract that from free text.
It only crosses two things safely both ways: the *time* an appointment is
at, and cancellation — never patient PII. That data flows into ZPMS
exactly once, at intake, structured, either on the quick-booking screen or
via a human filling in the review queue.

### Setup (one-time)

1. **Google Cloud**: create a project, enable the Calendar API, create a
   service account, download its JSON key. No OAuth consent screen,
   nothing per-user — a service account is a long-lived credential this
   app signs its own short-lived (1h) access tokens with, on every
   process that needs one (`web/google_calendar_client.php`, RFC 7523
   JWT bearer flow, hand-rolled via `openssl_sign()`+cURL — no Composer/
   SDK, consistent with this app family having no build step anywhere).
2. **Create a Google Calendar** for consultations (e.g. "ΖΠΜΣ Ραντεβού")
   in the normal Google Calendar UI, and share it with the service
   account's email (shown on its Credentials page) with "Make changes to
   events" permission. Staff keep using this calendar exactly as before —
   the service account is just a second, silent editor on it.
3. Save the JSON key somewhere outside the web root (this repo expects
   `config/google_calendar_key.json`, gitignored — never commit it).
4. `cp config/google_calendar.php.in config/google_calendar.php` and fill
   in `service_account_key_path`/`calendar_id` (gitignored, same
   `*.php.in` → `*.php` convention as `config/db.php`/`config/
   ernsauth.php`).

A missing/invalid config file leaves `googleCalendarClass::isEnabled()`
false — `/consultation/new` still queues the pending appointment normally,
`google_event_id` just stays `NULL`, and `bin/sync_google_calendar.php`
exits immediately with a log line. Never a fatal error; nothing here can
block a real phone booking.

### Front-desk role: secretary

A `secretary` role (`web/rbac_seed.php`) holds exactly two permissions:
`patients-view-list` (read-only access to the existing patient list, same
as the plain `user` role) and `pending-appointments-manage` (the new
`ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE` slug, `web/rbac.php`) — book, edit,
and cancel entries on the pending-appointments list. It deliberately has
neither `patients-new-patient` nor `appointment-edit`, so a secretary
account can never create a patient record or a real appointment directly,
only queue and manage the waiting-room entries that a doctor later acts on.
`bin/migrate_roles.php --yes` adds this role/permission to an existing
deployment the same way any other RBAC change here is rolled out (see
"Roles and permissions" above) — safe to re-run, it only ever inserts
what's missing.

The "Ραντεβού" nav menu (and its "Εκκρεμή Ραντεβού" list) is visible to both
`doctor` and `secretary`; the "create patient record" (👤+) action on
each pending row, and the `/consultation/pending/{id}/convert` route behind
it, are gated on `ZPMS_PERM_PATIENTS_NEW_PATIENT` **and**
`ZPMS_PERM_APPOINTMENT_EDIT` together — a secretary account never sees that
action and is refused outright (the app's standard `error_401()` page) on a
direct hit of the URL. The "Ασθενείς" (patient list) menu itself is
also visible to `secretary` (via `access: doctor secretary` on that menu
entry) — see "Roles and permissions" above for what a secretary account
can actually do once there (view only; the "New" submenu item and the
per-row/page-level create/edit/delete controls stay `doctor`-only).

**Breadcrumb path on the edit/convert pages.** `pending_appointment_edit`
and `pending_appointment_convert` are deliberately never menu items
themselves (only reachable via a row-action link on
`pending_appointments_list`'s own page) — that used to mean their
breadcrumb was a single, parent-less segment (just their own page title,
no "Ραντεβού / Εκκρεμή Ραντεβού /" leading up to it), since zeusfw's
`Menutrail::search_menu_trail_for_key()` only ever matched a route name
that's literally a menu item's own key. Fixed via a new, additive
`breadcrumb_aliases: [pending_appointment_edit, pending_appointment_convert]`
key on `pending_appointments_list`'s own menu entry (see zeusfw's own
`CLAUDE.md`, "Breadcrumbs" entry, for the general mechanism) — both pages
now show the full "Ραντεβού / Εκκρεμή Ραντεβού / ..." path. A second,
unrelated bug in the same code path was fixed at the same time: any
breadcrumb segment for a pure menu-grouping label with no route of its
own (e.g. "Ραντεβού"/"Ασθενείς" themselves) was rendering the literal
string `nolangtext` instead of its actual label — see that same zeusfw
`CLAUDE.md` entry for the root cause. `pending_appointment_edit`/`_post`'s
own route `title:` also gained a Greek translation in the same pass
(`config/settings.info.yaml`), matching its sibling
`pending_appointment_convert`, which already had one.

### Schema

`pending_appointments` (`web/classes/yaml/pending_appointments.yaml`) is
the waiting-room table every booking lands in first, regardless of origin:
`patient_name`/`patient_phone`/`patient_amka`/`patient_email`,
`appointment_datetime`, `location` (free text — see "Locations" below),
`notes`, `google_event_id` (nullable `UNIQUE`)/`google_synced_at` (the
Calendar link, same shape as before), and three terminal markers:
`converted_at`/`converted_patient_id`/`converted_appointment_id` (set once
a doctor turns it into a real patient + appointment) and `cancelled_at`
(set if it's cancelled/dismissed without ever being converted) — an entry
is "still pending" exactly when both are `NULL`. `appointments.google_event_id`/
`google_synced_at` are unchanged from before and still exist: once a
pending entry is converted, its Calendar link carries over onto the new
`appointments` row unchanged (see "The two sync directions" below for why
the extendedProperty itself is never rewritten). `calendar_sync_state` is
also unchanged — one row per synced calendar, holding the incremental
`sync_token`.

As with every other schema change in this app, there's no migration
runner: `cd web/classes && php ../core/maker/maker.php spill:class:all
spill:sql:all` regenerates the entity classes + `CREATE TABLE` SQL from
the yaml (`web/classes/yaml/pending_appointments.yaml`) — directly
`mysql`-runnable as-is on a live database, since this is a brand new table
with nothing to `ALTER`.

### Locations

Consultations happen at more than one physical location, so both the
booking screen and the pending-appointment edit form offer a `<select>`
populated from `locationsClassEx::sgetAll()` — the same `locations` table
this app already uses for the appointment-location field elsewhere in the
codebase, so there's nothing new to configure. `location` on
`pending_appointments` is plain text, not a foreign key (consistent with
how this app already stores denormalized snapshot values elsewhere), and
carries straight over onto the resulting `appointments.aplace` on
conversion.

### The two sync directions

**ZPMS → Calendar (synchronous, on save)**: `consultation_new_post()` and
`pending_appointment_edit_post()` (both in `web/index.php`) call
`googleCalendarClass::createEvent()`/`updateEvent()` right after
inserting/updating the pending-appointment row, storing the returned event
id on that row. The event's
`extendedProperties.private.zpms_pending_appointment_id` is what a later
pull sync uses to recognize "this is one of ours" — patient name/phone/
AMKA/email/location/notes go into the event's plain description text (for
a human glancing at the calendar to read), never into `extendedProperties`
itself. **This property always names a `pending_appointments.id`, never an
`appointments.id`, even after conversion** — it is never rewritten once
set, so the same Calendar event keeps working as a link across the
pending → converted transition; the sync script (below) is what
distinguishes the two cases.

**Calendar → ZPMS (polling, every few minutes)**: `bin/sync_google_calendar.php`
(reference cron: `deploy/zpms-calendar-sync.cron` — install manually, same
"reference only" convention as `deploy/zpms-backup.cron`) calls
`googleCalendarClass::listChangedEvents()`, which follows Google's
incremental `syncToken` (only what changed since last run, not a full
rescan) and its own pagination. Per changed event:

- Carries the extendedProperty, and that pending appointment is already
  **converted** → the booking became a real appointment since this event
  was created; reschedule/cancel the linked *appointments* row instead
  (mirroring `appointment_delete()`'s own `deleted` timestamp convention
  on cancel). Never touches patient_name/phone/AMKA/notes on either table.
- Carries the extendedProperty, still **pending** (not converted, not
  cancelled) → reschedule/cancel that `pending_appointments` row directly.
- Carries the extendedProperty, but it doesn't resolve to any row at all
  (deleted from ZPMS some other way) → skip; nothing local left to update.
- No extendedProperty → a Calendar-native event (created directly in the
  Calendar app, or by hand during a call when ZPMS wasn't at hand).
  Upserted into `pending_appointments` by `google_event_id` (only
  `patient_name`, from the event summary, and `appointment_datetime` are
  known — phone/AMKA/email/location are left blank for staff to fill in),
  so it shows up in the same "Εκκρεμή Ραντεβού" list as everything booked
  through `/consultation/new`.

A `410 Gone` response (Google's documented signal that a stored
`sync_token` is too old to resume from) clears the stored token so the
next run does a full resync from "now" rather than silently missing
whatever changed in between — never a caught, ignored error.

Five minutes' polling latency (not real-time push notifications via
Google's `events.watch()`) is a deliberate simplification: no public-
facing webhook endpoint to expose, no channel-renewal job to keep
running. Revisit only if that latency is ever a real problem in practice.

### Verified

`php -l` clean on every touched/new file; `bin/run_tests.sh` (34/34
static, 35/35 functional) green throughout. The JWT/token-exchange half
of `googleCalendarClient` was verified against Google's **real**
`oauth2.googleapis.com` endpoint (this sandbox can reach it, unlike
`www.google.com` — confirmed by a hand-signed JWT for a fake service
account coming back `invalid_grant: account not found` rather than a
malformed-request error, which only happens if Google successfully
parsed and validated the JWT's structure/signature first).

The full secretary-books → doctor-converts flow was verified end-to-end
via Playwright against a real MariaDB-backed test server, using two real
accounts (a `secretary`-role account and a `doctor` account — `power-user`
at the time, since this predates the role-vocabulary refactor in "Roles
and permissions" above): a secretary can book, edit, and cancel a pending
appointment, cannot see the convert action anywhere in the UI, and is
refused (the app's `error_401()` page) on a direct GET to the convert URL;
a doctor sees the same entry pre-filled on the convert form (including the
existing-patient duplicate-name check reused from `/patient/new`) and, on
submit, gets a real patient + appointment created, with the pending list
emptied afterward. Edit and cancel were verified separately: editing a
pending entry's fields/location/datetime saves correctly and re-syncs to
Calendar when configured; cancelling calls
`googleCalendarClass::deleteEvent()` when a `google_event_id` is present
and marks the row `cancelled_at` rather than deleting it outright. **Not**
verified: a real, successful `createEvent()`/`listChangedEvents()` call
against an actual configured calendar (needs real service-account
credentials + a real shared calendar, which only a live deployment can
provide) — same "known limitation, not a bug" caveat zeusfw's
`Recaptcha.php` documents for its own reCAPTCHA integration, except here a
live test is realistically possible once real credentials exist, since
the sandbox's network access to Google's endpoints turned out not to be
the blocker it was there.

**A real, framework-level bug was found and fixed while verifying this
(at the time secretary still had no patient-page access at all, before
the read-only-view change in "Roles and permissions" above)**: `zeusfw`'s
`core/modules/mainnavigation/mainnavigation.php` gated a nav menu item's
`access:` string via `SecurityClass::require()`, which treats the
`"authenticated"` role — always present for any logged-in user — as an
automatic pass regardless of what `access:` actually requires. That made
every `access:`-restricted nav menu item visible to every logged-in user
no matter their role (caught here because the secretary account could see
the "Ασθενείς" menu despite it being `access: power-user` at the time —
now `access: doctor secretary`, since a secretary account genuinely is
meant to see that menu today), even though both this app's own
`config/settings.info.yaml` comments and zeusfw's `core/lib/Rbac.php`
docblock already documented nav-menu gating as going through
`SecurityClass::userIsPermitted()` instead — a plain role-identity check
with no such special case. Fixed in `zeusfw` by switching that one call
site to `userIsPermitted()`, matching the framework's own documented
design; `zpms`'s full test suite stayed green throughout.

## Appointment email notifications

`/consultation/new` (and the pending-appointment edit form) now has a
"Ιατρός" field next to Location: which `users` account (one holding the
RBAC `doctor` role — see "Roles and permissions" above) this consultation
is for. **Preselected, not just left as the sole option, when exactly one
doctor account exists** — a single-doctor practice (this app's own,
today) shouldn't make staff click a dropdown with nothing to actually
choose between; a practice with several doctor accounts sees a real
choice with nothing preselected. The submitted value is never trusted
directly — `zpms_resolve_assigned_doctor()` (`web/index.php`) re-checks it
against the real, current list of doctor accounts, so a tampered request
can't assign a booking to an arbitrary `users.id`.

**On a new booking only** (not on a later edit — editing lets staff
correct an initial mis-selection or fill one in on a Calendar-native row,
but never re-sends the notification), `consultation_new_post()` emails the
assigned doctor a summary (patient name/phone, date, time, location,
notes) right after the pending row is saved and synced to Calendar. This
never blocks or fails the booking itself — a phone call that already
happened must be recorded regardless of whether the notification email
goes out; a missing doctor selection, an unconfigured/unreachable SMTP
server, or a doctor account with no email on file all just mean no email
is sent, with a flash warning shown to whoever booked it and the reason
logged via `error_log()`.

**The email body is rendered via `Renderer::render()`** against
`web/templates/email/consultation_assigned.zetem` — a plain, standalone
`.zetem` template with no page chrome (`Renderer::render()` just
compiles+executes the one file; the header/nav/footer composition only
happens inside `Kernel::renderPage()`, which this never goes through),
using the same `{{{ }}}`-escaped-output/`{% if %}` syntax as every other
template in this app. The Subject line is a plain PHP string
(`web/zpms_mailer.php`), same as apyweb's own invoice-email code.

### SMTP transport

Sending goes through PHPMailer over real SMTP, not PHP's built-in
`mail()` — the same choice DocArc and apyweb already made for exactly the
same reason (unreliable without a properly configured local MTA, prone to
being flagged as spam). `lib/phpmailer/` is a manually-cloned checkout
(pinned to tag `v7.1.1`, git-ignored, not a Composer dependency — same
"no build step" convention as `lib/ernsauth`):

```sh
git clone --branch v7.1.1 https://github.com/PHPMailer/PHPMailer.git lib/phpmailer
```

Only `src/{Exception,PHPMailer,SMTP}.php` are ever `require_once`'d, and
only lazily, inside `zpms_build_mailer()` (`web/zpms_mailer.php`) — a
normal page view never loads any of it. Host/port/encryption/credentials/
sender identity live in the `mail_settings` table (a singleton row,
`id = 1`, same shape as apyweb's own `mail_settings` — see
`scripts/migrate_mail.sql` there for the identical convention this
followed), edited from Settings → Email (`/settings/mail`,
`settings-manage`-gated). The password field is never pre-filled with the
stored value — a blank submission means "leave it as it is", not "clear
it", same convention used everywhere else in this app family for a value
that's never re-displayed. `zpms_build_mailer()` fails closed on every
error path (unconfigured settings, PHPMailer not vendored, a real SMTP
connection failure) — a friendly Greek error string or `false`, never an
uncaught exception.

### Schema

`web/classes/yaml/pending_appointments.yaml` gained `assigned_user_id`
(nullable `int(11)`, not a real foreign key — this framework's tables
never declare DB-level FK constraints, referential integrity is app-level
only) and a new `web/classes/yaml/mail_settings.yaml` table (no
`guid`/`cdate`/`cuser` — unlike every other table in this app, this isn't
a managed record staff browse a list of, it's config, always read/written
as the one row with `id = 1`, same as DocArc's key/value `settings` table
skipping the same audit columns for the same reason).

**Deploying this against an existing database** (this table already has
real data on any real install, unlike `pending_appointments`' own
original rollout, which needed no migration at all):

```sh
php bin/migrate_appointment_email.php --dry-run     # preview
php bin/migrate_appointment_email.php --yes         # ALTER + CREATE + seed
```

Idempotent and safe to re-run — it checks whether the column/table/row
already exist before touching anything. A fresh install gets both from a
normal `spill:class:all`/`update:bootstrap`/`spill:sql:all` regeneration
instead (see the top of this file's own setup instructions), same as
every other table.

### Verified

`php -l` clean on every touched/new file; `bin/run_tests.sh` (40/40
static, 35/35 functional) green throughout — unaffected by this feature,
which touches no code path any existing test exercises. A dedicated
end-to-end run against a real MariaDB-backed `php -S` test server (not
just the static suite) drove the actual HTTP flow: logged in as a real
`doctor`-role test account, confirmed the "Ιατρός" `<select>` renders and
is genuinely preselected when exactly one doctor account exists, POSTed a
real booking, confirmed `pending_appointments.assigned_user_id` was
stored correctly, and confirmed the post-booking flash correctly reported
the notification email failing to send when `mail_settings` pointed at a
real-but-unreachable host/port (`127.0.0.1:2525`) — proving PHPMailer
genuinely attempted a real SMTP connection (not just a "not configured"
short-circuit) and that a failure there degrades to a warning rather than
breaking the booking. **Not verified**: an actual successful send against
a real, reachable SMTP server (this sandbox has no such server available)
— same "known limitation, not a bug" caveat as this app's own Google
Calendar integration above; a real deployment with real SMTP credentials
configured should work correctly, since the send path itself has no
sandbox-specific workaround.

## Profile page: editable email + mobile checkbox layout

`/profile`'s account form (`web/modules/userprofile/`) let staff edit their
own display name and password but never their `users.email` — a required
schema column (see zeusfw core's `users.yaml`) that was otherwise only
settable via the admin-only `/admin/users` CRUD. It's now a normal field
on the self-service form, right after Όνομα, validated with
`FILTER_VALIDATE_EMAIL` on save (rejected with a flash error and nothing
written on an invalid value, same "validate, don't silently coerce"
pattern used elsewhere in this app) and pre-filled with the account's
current value (never blank-by-default the way a password field is).

**Mobile checkbox layout**: `.user-profile .fields`' mobile breakpoint
(`@media (max-width: 470px)`, `web/css/styles.css`) collapses its normal
two-column `label | input` grid to a single stacked column — fine for a
text/password/select field, but a checkbox row rendered as a whole line
of label text followed by a separate line holding just a small box below
it, which read as clutter for a control that small. The two checkbox
rows (Ενεργός λογαριασμός/Ληγμένος λογαριασμός) now carry an explicit
`checkbox-field` class; on mobile only, that class switches the row to a
single inline flex row with `order` putting the checkbox before its
label, box-then-text like a checkbox normally reads, while every other
field on the same form keeps the generic stacked layout unchanged.

**Verified** end-to-end against a real MariaDB-backed `php -S` test
server: the email field renders pre-filled with the account's real
current address; submitting an invalid value is rejected and the stored
value is confirmed unchanged; submitting a valid one is confirmed saved;
the served `web/css/styles.css` was fetched directly over HTTP and
confirmed to carry the new `.checkbox-field` mobile rule. `php -l` clean;
`bin/run_tests.sh` (40/40 static, 35/35 functional) stayed green
throughout. **Files**: `web/templates/blocks/user_profile.zetem`,
`web/modules/userprofile/userprofile.php`, `web/css/styles.css`.

## Topbar user block: show the account name alongside the username

zeusfw core's own `userblock` module (`core/modules/userblock/`, shared by
every app on the framework) renders a bare `User <username>. Logout` in
the topbar — it only ever has the raw session username
(`Kernel::getUserName()`) to work with, not the account's real Όνομα
(`users.name`). This app now shows both: "User **{Όνομα}** (username).
Logout" — e.g. "User **ZPMS Test User** (zpms_test_user). Logout" — so an
account can still be told apart from another with a similar display name
at a glance.

**Done entirely at this app's own template layer, with zero change to
zeusfw core.** `web/templates/modules/userblock.zetem` is a new file with
the exact same basename as core's own
`core/templates/modules/userblock.zetem` — `config/settings.info.yaml`'s
`templates:` list already scans `./core/templates/` before `./templates/`,
and `Renderer::scanTemplates()`/`findTemplates()` resolve a duplicate
basename by simple last-write-wins across that scan order (confirmed by
reading `ZETEMTemplate.php` directly, not assumed), so this app's own
copy silently wins over core's for every render — no `core/bootstrap.php`
change, no module-registration reordering, and every other app on the
framework (mweb/zweb/erweb) keeps rendering core's original bare-username
block exactly as before, unaffected. `UserModule::render()` (zeusfw core)
already passes the raw username into the template as `$name` — the
override template just does more with that same value than core's own
version does.

The account lookup itself is a new small helper,
`zpms_userblock_display_name(string $uname): string` (`web/ClassesEx.php`,
right after `usersClassEx`, since it needs
`usersClassEx::getUserAccount()`), called directly from the template
(`{{{zpms_userblock_display_name($name)}}}` — HTML-escaped, since
`users.name` is admin/self-service-editable free text, unlike the
session-derived username next to it). Falls back to the bare username if
the account can't be found or has no name on file, so the block can never
render blank.

**Verified** with a new, permanent regression test
(`tests/functional/auth_csrf.php`, "the topbar user block shows the
account name and username") rather than a one-off manual check: logs in
as the suite's own fixture account (`name` = "ZPMS Test User", `uname` =
`zpms_test_user`) and confirms the rendered `.user-block` reads
`>ZPMS Test User</a> (zpms_test_user)` — the `</a>` in the middle is real:
the name sits inside its own link to `/profile`, the username in
parentheses sits outside it as plain text right after. `php -l` clean on
`web/ClassesEx.php`; `bin/run_tests.sh` (41/41 static, 36/36 functional —
the one new test included) stayed fully green throughout. **Files**:
`web/ClassesEx.php`, `web/templates/modules/userblock.zetem` (new),
`tests/functional/auth_csrf.php`.

## Calendar Sync menu item restored; "Προηγούμενα Ραντεβού" added to the pending-appointments page

Direct request, after the calendar_pending_events review-queue design (its
own removed page, `calendar_review_queue.zetem`, and its "Νέα από
Ημερολόγιο" menu item) was folded into the single pending-appointments
waiting room (see this file's own "Google Calendar sync"/"Pending
appointments" sections) — a Calendar-native event with no ZPMS id now
lands directly in that same "Εκκρεμή Ραντεβού" list
(`bin/sync_google_calendar.php`), so there's no second page left to point
a restored menu item at.

**Menu** (`config/settings.info.yaml`, the `consultations:` submenu): a
new `calendar_sync` entry, "Συγχρονισμός Ημερολογίου" / "Calendar Sync",
sitting alongside "Νέο (τηλεφωνικά)" and "Εκκρεμή Ραντεβού" — same
`/consultation/pending` URL as the latter, under its own calendar-flavored
label, rather than a second page duplicating that list's content. No new
route or handler needed for this on its own.

**"Προηγούμενα Ραντεβού"** — a real-appointments history section, added to
`pending_appointments_list.zetem` below the still-pending list, since a
call-in that's already been converted into a patient (or an appointment
logged directly from a patient's own file) previously had nowhere to show
up on this page at all. `appointmentsClassEx::getPreviousAppointments()`
(`web/ClassesEx.php`) is the cross-patient equivalent of the existing
`getAppointmentsForPatient()` right above it — a single `JOIN` against
`patients` (excluding soft-deleted rows on both sides, the same filter the
old, removed `appointments_list()` handler applied by hand via a
per-row `patientsClassEx::sgetByGuid()` lookup) rather than an N+1 query,
since this one walks every appointment ever logged, not one patient's own
handful. `pending_appointments_list()` (`web/index.php`) passes the result
through as `previous`, newest first; each row links the patient's name to
their own `/patient/{id}/edit` page.

**Verified** with a new, permanent regression test
(`tests/functional/appointment_crud.php`, "nav has the Calendar Sync menu
item, and /consultation/pending lists pending appointments before previous
ones") rather than a one-off manual check: confirms the nav's `/patients`
render includes "Συγχρονισμός Ημερολογίου" linking to
`/consultation/pending`; inserts a fresh patient + real appointment and
confirms `/consultation/pending` shows both the patient's name and the
appointment's location under a "Προηγούμενα Ραντεβού" heading; and
confirms that heading's position in the response body comes *after*
"Εκκρεμή Ραντεβού"'s, so pending appointments are never pushed below the
history section. `php -l` clean on `web/index.php`/`web/ClassesEx.php`;
`bin/run_tests.sh` (43/43 static, 38/38 functional — the one new test
included) stayed fully green throughout. **Files**: `config/settings.info.yaml`,
`web/ClassesEx.php`, `web/index.php`,
`web/templates/content/pending_appointments_list.zetem`,
`tests/functional/appointment_crud.php`.

## New-appointment form styling, backups menu visibility, patient deletion placement, appointment-view permission, financial-view permission, a Home Screen appointments card, and per-appointment edit history

A batch of eight direct requests, landed together:

**1. New-appointment form now matches the edit form's look, with no
autosave.** `edit_appointment.zetem` (the single-page "Νέο
Ραντεβού"/"Νέο Χειρουργείο" form, reached from a patient's own record)
previously used a native `datetime-local` input and none of the shared
`.appointment-entry`/`.input-header`/`.edit-appointment` styling
`view_appointment.zetem` already had. Restyled to match: the same
flatpickr-backed `dateFormat: 'Y-m-d H:i'`/`altFormat: 'd-m-Y H:i'` text
input every other date field in this app uses (see "Date fields" above),
inside the same markup classes. Deliberately carries **none** of
`view_appointment.zetem`'s autosave wiring — no `loader="..."`
attributes, no `.loader0` element, no `onChange` re-dispatching a
synthetic `input` event — since the appointment doesn't have an id yet to
`PATCH` against; this page only ever does one explicit, full-page POST via
its own Αποθήκευση button.

**2. Backups menu item no longer shown to doctors.** Backup status is an
ops/infrastructure concern, not a clinical one, and — worth flagging
separately — the item was already effectively dead for doctors before
this change: the real gate on `/apps/backup` is zeusfw core's
`ZEUSFW_PERM_MANAGE_USERS` (since the backup module moved into
`core/modules/backup/`), which `doctor` has never held. The menu link was
reachable but any click landed on a 401. `config/settings.info.yaml`'s
`backup:` menu entry now reads `access: maintenance` (was
`access: doctor maintenance`). **`maintenance` has the identical problem**
— it doesn't hold `ZEUSFW_PERM_MANAGE_USERS` either, so it's the intended
audience of this menu item and still can't actually open the page it links
to. That's a pre-existing bug this change doesn't fix (out of scope for
what was asked), and is called out explicitly here so it isn't mistaken
for resolved: granting `maintenance` (or a purpose-built permission)
`ZEUSFW_PERM_MANAGE_USERS` — or, better, gating `/apps/backup` on
something narrower than "can manage users/roles/permissions" — is a
follow-up someone should pick up deliberately, not as a side effect of a
menu-visibility change.

**3. Patient deletion moved off the list page, onto the patient's own
record.** `patients_list.zetem` no longer has an "Ενέργειες" column or a
per-row delete form at all. `edit_patient.zetem` gained a "Διαγραφή
Ασθενή" danger-zone block (a `.btn-danger` button, same confirmation-form
shape as the removed list-row version) right below the main edit form,
shown only when `$can_delete_patient` (`ZPMS_PERM_PATIENTS_DELETE_PATIENT`)
is held and `$action !== 'new'` (nothing to delete on the not-yet-saved
new-patient form). `web/js/scripts.js`'s `trashforms` confirmation-dialog
list gained `.edit-patient form[confirmation]` alongside the existing
container selectors, since the delete form now lives inside that
container instead of `.patients-list`. New `.patient-danger-zone`/
`.patient-danger-zone .btn-danger` rules in `web/css/styles.css` (border/
color from the existing `--error` token, same shape as `.btn-secondary`).

**4 + 7. `appointment-view`: read-only appointment/operation detail for
secretary.** These two bullets were the same request from two angles —
"secretary can view patient records" and "a permission so secretary can
view appointment details... but not update them" — implemented as one
new permission, `ZPMS_PERM_APPOINTMENT_VIEW` (`appointment-view`,
`web/rbac.php`), granted to `secretary` alongside its existing
`patients-view-list`/`pending-appointments-manage` (`web/rbac_seed.php`).
`patient_edit()` (`web/index.php`) now checks
`$canEditAppointment || $canViewAppointment` (was `$canEditAppointment`
alone) to decide whether a patient's appointments render as the full
`view_appointment.zetem` card (notes/dates/attachments) or the bare
date+location summary — a `appointment-view`-only holder now gets the
full card, exactly like an editor, just rendered inside a disabled
`<fieldset>` with the Αποθήκευση/Διαγραφή buttons omitted outright
(`can_edit` passed into the template as `$canEditAppointment`, unchanged
in meaning — it always meant "render this editable", it just now also
controls a `disabled` fieldset instead of being the sole gate on whether
the card renders at all). The same flag hides the attachment upload
dropzone, the clipboard-paste control, and each file's Διαγραφή button —
`appointment_files.php`'s own handlers already independently require
`ZPMS_PERM_APPOINTMENT_EDIT` regardless of what a crafted request submits,
so this is cosmetic, not the authorization boundary. The existing-files
list and each file's Προβολή (view/download) button stay visible either
way, since viewing historic attachments is exactly what this permission is
for.

**5. `patient-financial-view`: doctor only, not secretary.** New
`ZPMS_PERM_PATIENT_FINANCIAL_VIEW` (`patient-financial-view`), granted to
`doctor` only. `patient_edit()` now only calls
`zpms_apyweb_fetch_financials()` (the APYweb integration) at all when this
permission is held — a viewer without it never triggers the outbound
lookup, not just a hidden result block.

**6. Home Screen "Ραντεβού" card.** A new card on `homepage.zetem`,
matching the existing Ασθενείς/Νέος Ασθενής/Δημιουργία QR cards' shape,
linking to `/consultation/pending` — the same "Εκκρεμή Ραντεβού" +
"Προηγούμενα Ραντεβού" page the nav's Calendar Sync/Pending Appointments
entries already point at (see the section above), rather than a second,
separate appointments page. Gated on `ZPMS_PERM_PENDING_APPOINTMENTS_MANAGE`,
the exact permission that page itself requires, so the card never links
anywhere its viewer would immediately be refused from.

**8. Appointment edit history, aggregated into 5-minute sessions.** New
`appointment_history` table (`web/classes/yaml/appointment_history.yaml`)
and `web/appointment_history.php`. The instrumentation point is
`appointment_edit_post()` (`web/index.php`) — the single write path for
every save on an appointment, whether from the explicit Αποθήκευση button
or from `loader.js`'s own per-keystroke autosave (which submits the
*whole* form on a 1-second debounce after the last edit, regardless of
which single field the user actually typed into). The handler now
captures each field's stored value before its own setters run, and only
counts a field as "changed" when the newly posted value actually differs
— never just "present in the POST" — before calling
`zpms_record_appointment_change($appointmentId, $cuser, $changedFields)`.

That function is the aggregation logic: it looks up the most recent
`appointment_history` row for this exact appointment+user pair
(`appointmentHistoryClassEx::getMostRecentSession()`, `web/ClassesEx.php`)
and, if its `last_change_at` is within `ZPMS_APPOINTMENT_HISTORY_SESSION_MINUTES`
(5) of now, **extends** that row — merges the newly changed field names
into its de-duplicated `changed_fields` list, bumps `change_count`, and
moves `last_change_at` to now — rather than inserting a new row. A gap of
5+ minutes starts a fresh row instead. This is a **sliding** window, not a
fixed bucket from the session's start: a user who keeps steadily editing
(never idle for a full 5 minutes) stays in one session no matter how long
the whole stretch runs, so "aggregate 5-minute sessions" means "don't
create a new history entry for every single autosave call", not "cap a
session at 5 minutes of wall-clock time". Two different users editing the
same appointment within the same window still get two separate rows —
`cuser` is part of what identifies "the same session" — since attributing
a change to the wrong person would defeat the point of a feature titled
"see what changes everybody made".

Displayed in a new collapsed-by-default "Ιστορικό Αλλαγών" section on
`view_appointment.zetem` (`zpms_appointment_history_for_display()`,
`web/appointment_history.php` — resolves each raw field name like
`appointment-notes` to its Greek label via
`zpms_appointment_history_field_labels()`, and formats each session as
either one timestamp or a start–end range depending on whether it was a
single save), visible to both `appointment-edit` and `appointment-view`
holders — this is a read-only log, not an editable control, and "see what
changed" is squarely what `appointment-view` is meant to allow. Uses its
own `.appointment-history-section` class (`web/css/file-uploads.css`),
not a second `.file-upload-section`, since `appointment-files.js`
auto-initializes upload/dropzone/delete handling on every
`.file-upload-section` element on the page — reusing that class here
would have it wire up (harmlessly, but pointlessly) against a container
that has none of those controls. The section's own collapse/expand click
handler is instead a small inline `<script>` in `view_appointment.zetem`,
scoped per-appointment-index the same way that template's own flatpickr
init script already is (`getElementById` with an `{{$index}}`-suffixed
id), so it doesn't double-bind across the several appointment cards one
patient page can render.

As with every other schema change in this app, there's no migration
runner: only `web/classes/yaml/appointment_history.yaml` is committed
(`web/classes/*.php`/`web/classes/sql/*` are gitignored, generated
locally) — deploying this means, from `web/classes`:
```sh
php ../core/maker/maker.php spill:class:all
php ../core/maker/maker.php update:bootstrap
php ../core/maker/maker.php spill:sql:all
mysql -u <user> -p <db> < sql/appointment_history.sql
```
a brand new table with nothing to `ALTER`, same as `pending_appointments`
before it.

**Deploying items 4/5 above also needs a database step**, separate from
the schema step: the two new permissions
(`ZPMS_PERM_APPOINTMENT_VIEW`/`ZPMS_PERM_PATIENT_FINANCIAL_VIEW`) and
their role grants only take effect once seeded into the existing
`permissions`/`role_permissions` tables — `bin/migrate_roles.php --yes` is
idempotent and safe to re-run against a live, already-migrated database
(it only ever grants a missing (role, permission) pair; it never revokes
one), so this is exactly the same one command a from-scratch RBAC
deployment already runs.

**Verified** with new permanent regression tests across
`tests/functional/patient_crud.php` ("patient deletion is a per-record
control, not a per-row action on the patient list"),
`tests/functional/auth_csrf.php` ("a secretary account sees full
appointment details read-only via appointment-view, but no save/upload/
delete controls" and "doctor holds patient-financial-view; secretary does
not"), and `tests/functional/appointment_crud.php` ("appointment history
aggregates consecutive edits into one 5-minute session" — covers a fresh
session, a same-session merge, and a backdated `last_change_at` correctly
starting a new session instead of extending a stale one, via a direct
`UPDATE ... DATE_SUB(...)` rather than sleeping in the suite — and "the
Home Screen has an appointments card linking to the pending/previous
appointments page"). `php -l` clean on every touched PHP file;
`bin/run_tests.sh` (99/99 static, 43/43 functional — five new tests
included) stayed fully green throughout. **Files**:
`config/settings.info.yaml`, `web/ClassesEx.php`,
`web/appointment_history.php` (new), `web/classes/yaml/appointment_history.yaml`
(new), `web/css/file-uploads.css`, `web/css/styles.css`, `web/index.php`,
`web/js/scripts.js`, `web/rbac.php`, `web/rbac_seed.php`,
`web/templates/content/edit_appointment.zetem`,
`web/templates/content/edit_patient.zetem`,
`web/templates/content/homepage.zetem`,
`web/templates/content/patients_list.zetem`,
`web/templates/content/view_appointment.zetem`,
`tests/functional/appointment_crud.php`, `tests/functional/auth_csrf.php`,
`tests/functional/patient_crud.php`.

## "Προηγούμενα Ραντεβού" re-sourced from the Google Calendar sync, not the patient list

Direct follow-up correction to "Calendar Sync menu item restored;
'Προηγούμενα Ραντεβού' added to the pending-appointments page" above: that
section originally sourced from `appointments` (real, patient-record-linked
visits — `appointmentsClassEx::getPreviousAppointments()`), i.e. exactly
"the appointments in the patient list". Per direct request, it now shows
**Google Calendar's own history instead** — `appointmentsClassEx::
getPreviousAppointments()` is deleted outright (grepped first to confirm
its one and only call site was this page); the section is now built from
`pendingAppointmentsClassEx::getPreviousFromCalendar()` (`web/ClassesEx.php`,
new), which reads `pending_appointments` — the same table
`bin/sync_google_calendar.php` already pulls Calendar-native events into —
filtered to rows with a `google_event_id` (i.e. actually synced with
Calendar, not a plain phone booking taken on `/consultation/new`) whose
`appointment_datetime` has already passed, newest first.

**Deliberately not filtered on `converted_at`/`cancelled_at`.** A past
Calendar event is history either way, whether or not it was ever turned
into a real patient record through this app, or cancelled here — none of
that changes what the calendar itself shows already happened. This is
also *why* this had to stop reading `appointments` at all: a Calendar
event that came and went with no action taken in ZPMS (cancelled on the
calendar directly, a no-show, or simply never converted) never gets an
`appointments` row, so the old query silently missed exactly the events
this feature was asked to surface, while including only the subset that
happened to go through the *patient*-record side of this app.

Each row still links the patient's name to their real record when one
exists (`converted_patient_id` set), and falls back to plain text
otherwise — `pending_appointments.patient_name`/`patient_phone`/
`location` are free text either way, not looked up from `patients`, so
there's no join needed regardless of conversion state.
`pending_appointments_list.zetem`'s table columns changed to match this
new source's own fields (Ημ/νία & Ώρα / Όνομα / Τηλέφωνο / Τοποθεσία,
dropping the old "Τύπος" column — real appointments can be a Ραντεβού or
a Χειρουργείο, `pending_appointments` has no equivalent distinction at
all).

**Verified** by updating the existing regression test in
`tests/functional/appointment_crud.php` rather than adding a parallel one
(the old fixture — a real `patients`+`appointments` row — no longer
proves anything about this section, so it was replaced rather than kept
alongside a new one): inserts a past, `google_event_id`-bearing
`pending_appointments` row and confirms it's listed under "Προηγούμενα
Ραντεβού" with its location; separately inserts a past
`pending_appointments` row with **no** `google_event_id` (a plain phone
booking that simply elapsed) and confirms it still shows under the
still-pending "Εκκρεμή Ραντεβού" section above (nothing converted/
cancelled it, so that section's own rule is unaffected) but does **not**
appear under "Προηγούμενα Ραντεβού" — proving the calendar filter
actually excludes non-Calendar rows, not just that Calendar rows are
included. `php -l` clean on `web/ClassesEx.php`/`web/index.php`;
`bin/run_tests.sh` (99/99 static, 43/43 functional) stayed fully green
throughout. **Files**: `web/ClassesEx.php`, `web/index.php`,
`web/templates/content/pending_appointments_list.zetem`,
`tests/functional/appointment_crud.php`.

## Mobile header: location and language selector kept on one line

Direct request. The header's `right-column` (`config/settings.info.yaml`'s
`structure:`) holds the location badge and the language selector
side by side on desktop (`.section-right-column`, `web/css/styles.css`) --
but the `@media screen and (max-width: 768px)` block switched that same
container to `flex-direction: column`, stacking them as two separate
centered rows on any tablet/phone width. Changed to `flex-direction: row`
(with `flex-wrap: wrap` kept as a safety net, and `justify-content: center`
replacing the per-child `text-align: center`/`justify-content: center`
rules already on `.location-place`/`.language-selector ul`, which still
apply and now center each element's own internal content instead of the
whole stacked column). Both elements are compact -- a small icon + short
text badge, and a short row of flag icons -- so there's no real width
pressure that motivated stacking them in the first place.

**Verified** with a standalone static reproduction of the real header
markup (`.section-header-grid-2x1`/`.section-left-column`/
`.section-right-column`/`.location-place`/`.language-selector`) styled
with the actual, unmodified `styles.css`/`location.css`/
`language_selector.css` files, screenshotted via Playwright at 375px,
430px, 600px (all inside the 768px breakpoint) and 900px (desktop, outside
it) -- confirmed location and the language selector sit on one line at
every mobile width tested, and the desktop layout is pixel-for-pixel
unchanged. `bin/run_tests.sh` (99/99 static, 43/43 functional) stayed
fully green throughout -- no template or handler changes were needed for
this, it's CSS-only. **Files**: `web/css/styles.css`.

## Build number

A plain, tracked `BUILD_NUMBER` file at the project root -- a single
integer, bumped by 1 on every commit -- shown in the page footer next to
the existing git-hash block ("Build #N"). Not derived from
`git rev-list --count HEAD` at request time (the simpler option, and
what `githashModule` right next to this one already does by reading
`.git/HEAD` directly): a plain counter file keeps incrementing across a
future rebase/squash the same way a build number normally would, where a
commit-count would instead move backwards or jump.

**Setup (one-time per clone)**, since git never runs a hook it doesn't
know about, and `.git/hooks/` itself is never version-controlled:

```sh
bin/install_git_hooks.sh
```

This points the repo's `core.hooksPath` at the tracked `githooks/`
directory instead of the default `.git/hooks/`. From then on,
`githooks/pre-commit` increments `BUILD_NUMBER` and stages it *before*
each commit is created, so the bump lands in that same commit -- no
separate follow-up commit, nothing left uncommitted. A clone that never
runs this setup step simply keeps whatever `BUILD_NUMBER` value it
checked out; the number itself, and every other part of the app, work
fine either way.

Every real commit action bumps it once, deliberately including a real
(non-fast-forward) merge and a `commit --amend` -- this counts commit
*events* through the hook, not unique logical changes, so a rebase or
cherry-pick replay of N commits can jump the number by N. That's expected,
not a bug.

`web/modules/buildnumber/` (`buildnumberModule`, registered like every
other opt-in module in `config/settings.info.yaml`'s `modules:` list, and
added to the `footer` region's `structure:` block list right after
`githash`) reads the file and renders nothing at all when it's missing --
same "optional, never a fatal error" convention as the rest of this app's
footer/status blocks.

**Files**: `githooks/pre-commit`, `bin/install_git_hooks.sh`,
`BUILD_NUMBER`, `web/modules/buildnumber/{buildnumber.php,
buildnumber.info.yaml}`, `web/templates/blocks/buildnumber.zetem`,
`config/settings.info.yaml`.

## Stale pending appointments now drop into "Προηγούμενα Ραντεβού" instead of lingering forever, with a reschedule action

Direct bug report: a booking from a past day, nobody having converted or
cancelled it, was still showing in "Εκκρεμή Ραντεβού" mixed in with
genuinely upcoming ones — that query never filtered on date at all, so a
stale entry just accumulated there indefinitely. Fixed by adding
`DATE(appointment_datetime) >= CURDATE()` to `pending_appointments_list()`'s
query (`web/index.php`) — compared by calendar day, not exact time
(`NOW()`), so today's own bookings stay visible all day regardless of
what hour they were for; only entries from a fully past day drop off.

**Nothing is lost — it moves into "Προηγούμενα Ραντεβού" below.** That
section's own query (`pendingAppointmentsClassEx::getPreviousFromCalendar()`,
renamed `getPastPendingAppointments()`) used to require a non-null
`google_event_id`, i.e. "only rows that synced with Google Calendar." That
had to go: a stale, non-Calendar-synced booking newly excluded from the
still-pending list above would otherwise have nowhere to go at all — on an
install with no Calendar integration configured, every row's
`google_event_id` is permanently `NULL`, so it would simply vanish from
the page the instant its date passed. Dropping that filter also fixed a
narrower, pre-existing version of the same gap: a row that got converted
without ever having synced to Calendar had nowhere to show up here either,
despite being exactly the kind of settled history this section exists
for. The section's actual scope is now "every past `pending_appointments`
row that isn't cancelled," Calendar-linked or not.

**A new "Ενέργειες" column on "Προηγούμενα Ραντεβού"** offers a
"Επαναπρογραμματισμός" (reschedule) action — a plain link to
`/consultation/pending/{id}/edit`, the same edit form the still-pending
list already uses — for any row that hasn't been converted. A converted
row gets none: `pending_appointment_edit()` itself already refuses a
converted row ("already processed"), so offering that link there would
just be a dead end; its row already links the patient's name through to
their real record instead, unchanged from before.

**Verified**: extended the existing regression test in
`tests/functional/appointment_crud.php` rather than replacing it —
confirms a past-due, non-Calendar-synced booking now shows under
"Προηγούμενα Ραντεβού" (previously asserted the opposite, back when that
was the deliberate, if ultimately wrong, design) rather than "Εκκρεμή
Ραντεβού"; a genuinely upcoming booking still shows under "Εκκρεμή
Ραντεβού" (the date filter isn't over-broad); a cancelled row still shows
under neither (unchanged from the previous fix); and — checked by each
fixture's own specific edit URL, not a page-wide search for the label
text, so one row's link can't be mistaken for another's — both an
unconverted Calendar-synced and an unconverted non-Calendar fixture offer
a reschedule link, while a converted fixture does not and links to its
real patient record instead. `bin/run_tests.sh` (99/99 static, 43/43
functional) stayed fully green throughout. **Files**: `web/index.php`,
`web/ClassesEx.php`, `web/templates/content/pending_appointments_list.zetem`,
`tests/functional/appointment_crud.php`.

## Styled the pending/previous appointments tables to match the patients list

Direct request: `pending_appointments_list.zetem`'s two tables rendered
as bare, unstyled `<table>`s — `.pending-appointments-list`/
`.previous-appointments-list` (the wrapper `<div>` classes the template
already used) had no CSS of their own at all, unlike `.patients-list`'s
gradient header, row hover, zebra striping, and rounded shadowed card.
Added a shared rule set in `web/css/styles.css` targeting both selectors
together (the two tables are genuine twins on the same page, so one
block covers both rather than duplicating it) that matches `.patients-list`
table's own look — same "match the existing look via a dedicated,
purpose-named class" pattern `.settings-table-scroll` already uses
elsewhere in this file, rather than attaching the literal `patients-list`
class to unrelated content. Column-specific touches: bold patient names,
tabular-nums on the datetime column, a centered fixed-width sync-icon
column, and the same centered-icon-row treatment as `.patients-list table
td.actions` for both tables' actions columns (previously plain, unstyled,
left-aligned icons with no consistent spacing).

**Verified visually**, not just via the static template-compile check
(which doesn't touch layout): booted a real MariaDB-backed test server
with a representative spread of fixtures (upcoming, today, a past
Calendar-synced one, a past non-Calendar one, a cancelled one, a
converted one) and screenshotted `/consultation/pending` via Playwright
at desktop (1280px) and mobile (390px) — confirmed the gradient header,
zebra striping, and reschedule/edit icons render correctly, the cancelled
fixture appears in neither table, the converted fixture links to its
patient record with no reschedule icon, and both tables scroll
horizontally on mobile exactly like `.patients-list` already does
(confirmed via `scrollWidth > clientWidth`, not just visually — a
`fullPage` screenshot at a narrow viewport can look "cut off" even when
the underlying `overflow-x: auto` is working correctly). `bin/run_tests.sh`
(99/99 static, 43/43 functional) stayed green throughout — CSS-only, no
template or handler changes. **Files**: `web/css/styles.css`.

## Restored real "Backups" nav access for `backup-access` holders

The "Backups" nav menu item (Apps → Αντίγραφα Ασφαλείας, `/apps/backup`)
was visible to `maintenance` but every click 401'd — the page's own
permission check, in zeusfw core's `core/modules/backup/backup.php`, had
been switched to the framework-level `ZEUSFW_PERM_MANAGE_USERS`, which
`maintenance` deliberately never holds (see that role's own docblock in
`web/rbac_seed.php`: "account/role administration stays an
administrator-only concern"). `doctor` — which *does* hold this app's own
`backup-access` permission — had been dropped from the menu's `access:`
list entirely for the same reason, one step earlier.

Fixed at the source: zeusfw core now offers
`zeusfw_app_backup_permission()`, an opt-in override (see zeusfw's own
CLAUDE.md, same date, for the framework-level half of this). `web/rbac.php`
defines it to return `ZPMS_PERM_BACKUP_ACCESS` — the permission this app
already had seeded and granted to `doctor`/`maintenance`, just never
actually wired to the page that name implies — instead of accepting the
framework's broader default. `config/settings.info.yaml`'s "Backups" menu
item is `access: doctor maintenance` again, matching exactly who holds
that permission now that the page-level check does too.

**Verified**: a real `doctor` test account now gets a genuine `200` from
`/apps/backup` (previously `401`); a `secretary` account (holds neither
`backup-access` nor `users-manage`) is still correctly refused.
`bin/run_tests.sh` (99/99 static, 44/44 functional — one new test added)
stayed fully green throughout. **Files**: `web/rbac.php`,
`config/settings.info.yaml`, `tests/functional/auth_csrf.php`.

## `administrator` also restored to nav items gated by roles it doesn't literally hold

Follow-up to the entry above: `administrator` (is_superuser) still didn't
see "Backups" — or "Settings", same `access: doctor maintenance` — even
after that fix, despite always being able to open either page directly.
Root cause was in zeusfw core, not this app's own config: the nav's
`access:` check (`SecurityClass::userIsPermitted()`) is a plain role-name
match against the session, with no concept of `is_superuser` at all —
unlike the actual permission check each page runs (`rbacClass::
isPermitted()`), which does bypass for `is_superuser`. An administrator-only
account's session literally has `['administrator', 'authenticated']` as its
role list; neither string is in `doctor maintenance`, so the nav item was
hidden even though the page underneath it always worked.

Considered switching nav `access:` onto the RBAC permission-slug system
outright (it already has the bypass) and rejected it — see zeusfw's own
CLAUDE.md, same date, for the concrete reasons (it isn't nav-specific, the
role-name validator hard-crashes on an unrecognized token, RBAC's own check
takes one permission at a time rather than "any of these", it re-queries the
database per check where the current one doesn't, and it's shared code used
by apps with no RBAC setup at all). Fixed narrowly in zeusfw core instead:
`SecurityClass::userIsPermitted()` now bypasses for `is_superuser` too, via
a new, defensive `rbacClass::currentUserIsSuperuser()` helper — nothing in
this app's own config changed.

**Verified**: a real `administrator` test account now sees both `/apps/backup`
and `/settings` in its rendered nav (previously absent) and can still open
both pages, unchanged. `bin/run_tests.sh` (101/101 static, 45/45
functional — two new tests added) stayed fully green throughout. **Files**:
`core/lib/Rbac.php`, `core/lib/Security.php` (zeusfw, see its own CLAUDE.md);
`tests/lib/TestFixtures.php`, `tests/functional/auth_csrf.php` (this repo).

## Live-editing, step 1: a content-source overlay (`core/modules/live_edit/`)

A small, transparent, opt-in overlay (top-right corner on every page) showing
which `.zetem` template the page's main content actually came from — e.g.
`templates/content/patients_list.zetem` on `/patients`. Nothing editable
yet; this is explicitly step 1 toward a planned live-editing feature, laying
down the "which file" indicator later steps (click-to-edit, write-back to
disk) build on. Built as a real, reusable zeusfw module
(`core/modules/live_edit/`) — see that repo's own CLAUDE.md for the full
design writeup (how "which template" is known via a new, generic,
content-agnostic tracking mechanism in `Renderer::render()`/
`RouterClass::routerCallFunction()` — nothing zpms-specific) — this app just
opts in: `live_edit` added to `config/settings.info.yaml`'s `modules:` list,
one call site in `web/templates/page/page.zetem`
(`module('live_edit', ['enabled' => true])`).

**Gated by a new, dedicated permission, `content-live-edit`** (`ZEUSFW_PERM_LIVE_EDIT`,
zeusfw core) — not `users-manage`, since seeing a template path has nothing
to do with user/role administration. Granted to `maintenance` in
`web/rbac_seed.php` (the existing "ops-only, zero patient access" role,
the natural home for a dev-tooling permission like this); `administrator`
sees it for free via the existing is_superuser bypass, same as every other
permission in this app. `doctor`/`secretary`/a logged-out visitor never see
it.

**A real, unrelated test-infrastructure bug was found and fixed while
verifying this**: `tests/lib/ServerManager.php`'s `TestServer` opened its
`php -S` child's stdout/stderr as pipes nothing ever read from — once
enough requests accumulated across a long functional run (php -S's own
per-request log line, plus every PHP warning the app emits), the unread
64KB pipe buffer filled and the *child* blocked on its next write, hanging
the dev server mid-request with no warning. Fixed by redirecting both to
real files instead (cleaned up in `stop()`) — see zeusfw's own CLAUDE.md,
same date, for the full root-cause story (found via this exact feature,
reproduced twice, confirmed fixed across 5 consecutive full suite runs).

**Verified**: the overlay shows the correct relative path on both a plain
list page and a form-heavy create page (confirming it picks the real page
template, not one of the many individual form-field sub-templates rendered
first); an `administrator` and a real `maintenance`-role account both see
it; a `secretary` account and a logged-out visitor don't. `bin/run_tests.sh`
(101/101 static, 46/46 functional — one new test added) stayed fully green
across 5 consecutive runs. **Files**: `config/settings.info.yaml`,
`web/rbac.php`, `web/rbac_seed.php`, `web/templates/page/page.zetem`,
`tests/functional/auth_csrf.php`, `tests/lib/ServerManager.php` (this repo);
`core/templates/ZETEMTemplate.php`, `core/router/Router.php`,
`core/lib/Rbac.php`, `core/modules/live_edit/`,
`core/templates/modules/live_edit/` (zeusfw, see its own CLAUDE.md).

## Live-editing overlay moved in-flow (top, right-aligned); a CSS typo had zero'd out its styling entirely

Direct follow-up to the entry above, at the requester's explicit instruction
after reporting the overlay wasn't visible: `position: fixed` wasn't
pinning the badge to the viewport's top-right corner on this app's real
pages — it rendered wherever it fell in the normal document flow instead,
almost certainly the same `transform`/`filter`/`perspective`/`will-change`-
on-an-ancestor containing-block hazard zeusfw's own accessibility-widget
CLAUDE.md entry already documents once. Rather than chase down which of
this app's own ancestor rules causes it, the module's CSS switched to a
plain in-flow, right-aligned flex block instead, and `page.zetem`'s call
site moved to render it first, before the header/nav/footer regions —
guaranteed visible regardless of any ancestor's CSS, at the cost of nudging
the rest of the page down one row. A disclosed, temporary trade-off, per
the requester's own "this might mess up the page a bit, let's try it for
now."

**That move then exposed a second, independent, pre-existing bug**: with
the new CSS in place, the overlay was still invisible, this time with zero
visible styling (plain browser-default block/button chrome). Root cause,
found entirely on the zeusfw side — see that repo's own CLAUDE.md entry,
same date, for the full story — was a typo in `live_edit.css`'s own leading
doc-comment: describing this module's `--zfl-*` custom property next to
`accessibility.css`'s `--zfa-*`/`google-analytics.css`'s `--zga-*` wrote
`--zfa-*/--zga-*`, and `*` immediately followed by `/` is a literal CSS
comment-closing token — regardless of being inside a sentence. That closed
the file's doc-comment nine lines early, and every word of prose after it
up to the *real* closing `*/` got fed to the browser's CSS parser as
syntax, invalidating the *entire* stylesheet (zero parsed rules, not just
the comment itself coming out wrong). Fixed with a single space around the
slash; no change needed on this app's side.

**Verified**: a Playwright screenshot of `/patients` after both fixes shows
the overlay exactly as designed — a small, semi-transparent, pill-shaped,
right-aligned block at the top of the page, monospace font, rounded
corners, showing `templates/content/patients_list.zetem`.
`bin/run_tests.sh` (101/101 static, 47/47 functional) stayed fully green.
**Files**: `web/templates/page/page.zetem` (this repo);
`core/modules/live_edit/css/live_edit.css`,
`core/modules/live_edit/live_edit.php` (zeusfw, see its own CLAUDE.md).

## Appointment card markup had a cross-card closing-tag bug; "Ιστορικό Αλλαγών" moved from per-appointment to per-patient scope

Two bugs/design changes reported together against a patient with more than
one appointment: the appointment cards rendered "messed up", and the
existing per-appointment edit-history section (see "a Home Screen
appointments card, and per-appointment edit history" above) was asked to
become a single, patient-wide section instead of fragmenting across every
appointment card.

**The rendering bug**: `web/templates/content/view_appointment.zetem`'s
`</fieldset>` closed right after the notes `<div>`, but the fieldset it
belonged to had opened *before* `.appointment-wrapper`/`.edit-appointment`
— two levels of `<div>` the closing tag never actually closed, since it
sat between them instead of after both. Overlapping (not properly nested)
tags force a browser's HTML parser to close elements up through whichever
one actually matches a mismatched closing tag, which can orphan later,
otherwise-correct closing tags in ways that only become visible once a
page renders *two or more* of these cards back to back — a single-
appointment patient never exposed it, since there was nothing after the
first card's malformed structure for the corruption to bleed into. Fixed
by moving `</fieldset>` to its correct position, right before `</form>`,
after every element it was actually meant to wrap.

**The history redesign**: the per-appointment `<div class="appointment-
history-section" id="appointment-history-{{$index}}">` block (one per
card, independently toggleable) is gone from `view_appointment.zetem`
entirely. In its place, `edit_patient.zetem` now renders one
`#patient-history` section, once per patient, aggregating every edit
across *all* of that patient's appointments into a single chronological
list — a patient's edit history is one continuous record of who touched
their file and when, not something that should fragment into a
separate, easy-to-miss list per appointment card. Each history line now
also names which appointment it belongs to (`{{{$h['appointment_label']}}}`,
e.g. "Ραντεβού 12-03-2026" or "Χειρουργείο 02-04-2026"), since merging
several appointments' histories into one list would otherwise lose that
context. `appointmentHistoryClassEx::getHistoryForAppointments(array
$appointmentIds): array` (`web/ClassesEx.php`) replaces the old
single-id `getHistoryForAppointment()`, joining against `appointments`
for `adate`/`atype`; `zpms_patient_appointment_history_for_display(array
$appointmentIds): array` (`web/appointment_history.php`) is its matching
display-layer wrapper. `web/index.php` collects every appointment id the
viewer can edit/view into `$appointmentIdsForHistory` alongside the
existing `$appdates` loop and passes the aggregated result to
`edit_patient.zetem` as `patient_history`, instead of rendering
per-appointment history inside each `view_appointment.zetem` call.
`.history-appointment` (`web/css/file-uploads.css`) styles the new
per-line appointment label.

**Verified with a new, permanent regression test**
(`tests/functional/appointment_crud.php`, "a patient with more than one
appointment renders both correctly (no cross-card HTML corruption)"):
creates two appointments with distinct, distinguishable notes on the same
patient, loads the edit page, and confirms both notes survive intact and
every `<fieldset>` tag the page renders is properly opened and closed
(comments stripped first, so prose mentioning `<fieldset>` inside an HTML
comment elsewhere on the same page can't produce a false match). The
pre-existing per-appointment history test was updated to assert the
history now renders once, patient-wide, and includes the appointment
label. `bin/run_tests.sh` (101/101 static, 47/47 functional) stayed fully
green. **Files**: `web/templates/content/view_appointment.zetem`,
`web/templates/content/edit_patient.zetem`, `web/index.php`,
`web/ClassesEx.php`, `web/appointment_history.php`,
`web/css/file-uploads.css`, `tests/functional/appointment_crud.php`.

## Patient edit page: delete button moved next to the Add buttons; appointment cards tightened

Reported directly against a real patient record: "Διαγραφή Ασθενή" (Delete
Patient) sat in its own separated, full-width section below "Νέο
Ραντεβού"/"Νέο Χειρουργείο" instead of alongside them, and the appointment
card below had a visibly large amount of dead space around it -- a thick
gray margin from `ul.patient-appointments-list`'s own border/padding plus
`margin-top`, then more again from the `<li>`'s own padding, then more
again from `.appointment-entry`'s own padding (`--spacing-md`, 1.5rem) --
four layers of inset all compounding around the same card.

**Button move**: `edit_patient.zetem`'s separate `.patient-danger-zone`
block is gone -- the delete form now renders inside `.patient-appointments`
(the same flex row "Νέο Ραντεβού"/"Νέο Χειρουργείο" already use), after
both buttons, gated on `$can_delete_patient` independently of
`$can_edit_appointment` so the row still renders correctly for a viewer
with only one of the two permissions. `.btn-danger`'s CSS (`web/css/
styles.css`) is no longer scoped under the now-removed `.patient-danger-
zone` selector -- it's a standalone rule now, same visual shape
(red-outlined pill, inverts to solid red on hover) as before.

**Spacing**: trimmed every layer that was compounding around the card --
`ul.patient-appointments-list`'s `padding` (was 0.5rem, now 0) and
`margin-top` (was `--spacing-md`/1.5rem, now `--spacing-sm`/0.5rem, the
`<hr>` above it already provides separation from the Add-buttons row);
`.patient-appointments-list>li`'s `padding` (was 0.2rem, now 0);
`.appointment-entry`'s own `padding` (was `--spacing-md`/1.5rem, now
`--spacing-sm`/0.5rem); and its `.input-header`'s side `margin` (was
`auto 1rem`, now `0` -- it was only ever duplicating `.appointment-entry`'s
own padding on the same edge, since the vertical half of `auto 1rem` was
already computing to 0 in normal block flow).

**Verified**: a fresh test patient with one appointment, screenshotted at
both 390px (mobile) and 1280px (desktop) -- the delete button now sits in
the same row as the two Add buttons on desktop (wraps to its own line on
the narrow mobile width, same `flex-wrap` behavior the row already had),
and the appointment card's computed padding/margins came out at the new,
smaller values (confirmed via `getComputedStyle()`, not just reading the
CSS). `bin/run_tests.sh` (101/101 static, 47/47 functional) stayed fully
green, including the existing test that only checks for the "Διαγραφή
Ασθενή" label text, unaffected by the button's new position. **Files**:
`web/templates/content/edit_patient.zetem`, `web/css/styles.css`.

## Three follow-up bugs from the previous layout pass: a leaked form style, a broken age badge, and a taller-than-needed notes field

Reported directly against a real patient record, all three in one message.

**"Διαγραφή Ασθενή" had visible whitespace around it, unlike its two
neighbors.** Root cause: `.edit-patient { form { ... } }` (`web/css/
styles.css`) targets *every* `<form>` under `.edit-patient` -- written for
the big patient-fields form, it was also catching the small delete form
that now lives in the same `.patient-appointments` row (see the previous
entry), giving it the identical white card background/padding/box-shadow
meant for the whole-page form. Fixed with a more specific override,
`.edit-patient .inline-delete-form { display: inline-flex; padding: 0;
background-color: transparent; border-radius: 0; box-shadow: none; }` --
deliberately *not* `.edit-patient form:not(.inline-delete-form)` on the
original rule, which would have raised that rule's own specificity above
`.appointment-entry form`'s (same specificity today, relying on source
order to win) and broken the appointment cards' own flex layout as a side
effect of fixing an unrelated button.

**The age badge showed nonsense like "-1y 11m" for a patient born today.**
Two compounding bugs in `web/js/scripts.js`:
1. `dobChange()` read the field's *alt* input (flatpickr's visible
   `d-m-Y`-formatted one, per `edit_patient.zetem`'s own comment on why)
   and built `new Date(ymd[2], ymd[1], ymd[0])` -- but `ymd[1]` is a
   1-indexed month string ("10" for October) while `Date`'s own month
   argument is 0-indexed, so every birth date was silently parsed one
   month later than what was actually typed. A birth date of today
   therefore parsed as next month -- in the future relative to "now".
2. `dateAgo()` assumed its input date was always in the past:
   `new Date(new Date() - startDate)` with a *negative* difference wraps
   to just before the Unix epoch (a `-1` day difference lands on
   1969-12-31), and reading the year/month off that gives exactly the
   `"-1y 11m"`-shaped garbage that was reported -- a real date, just not
   one that means anything as an age.

Fixed both: `dobChange()` now subtracts 1 from the month
(`ymd[1] - 1`), so a correctly-typed birth date parses to the actual day
intended; `dateAgo()` now checks the diff up front and returns `'-'`
whenever it's zero or negative, rather than ever constructing that
pre-epoch date at all -- covering not just today's date (now fixed at the
source) but any genuinely future date a typo could still produce.
Verified directly in a real browser by calling `dobChange()`/`dateAgo()`
against several dates, not just by reading the fix: today -> `"0y 0m"`;
15-05-1990 -> `"36y 4m"` (correct against this session's real date);
tomorrow -> `"-"`; 31-12-2020 (the day=31 edge the old, scrambled argument
order could have also mishandled) -> `"5y 9m"` (correct).

**Appointment cards still felt tall even after the previous pass's margin/
padding trim** -- because the Σημειώσεις textarea's `rows="5"` reserved
that height regardless of content. `textarea-autoexapand.js` already
grows a `[autoexpand]` textarea to fit its content on input, so a tall
starting size only ever bought blank space for the common case of a short
or empty note. Reduced to `rows="2"` in `view_appointment.zetem` -- a
genuinely shorter card for the common case, not just tighter margins
around the same content, with no loss of capacity for a longer note
(confirmed autoexpand still grows it: computed height for a fresh,
empty-notes card dropped from what `rows="5"` reserved down to 54px at
`rows="2"`).

**Verified**: `bin/run_tests.sh` (101/101 static, 47/47 functional) stayed
fully green. A real browser session confirmed the delete button's
computed height/padding/background now exactly match "Νέο Ραντεβού"'s
(43px tall, transparent background, no box-shadow, previously boxed in
its own white 87px-tall card), the age badge produces correct output for
every case above, and a fresh appointment card's notes textarea renders
at the new, shorter height. **Files**: `web/css/styles.css`, `web/js/
scripts.js`, `web/templates/content/view_appointment.zetem`.

## Appointment card: another pass tightening margin/padding, plus a specificity leak it uncovered

Direct follow-up, after the previous two passes: the card still had more
room to tighten than margins alone, and the `.buttons` row specifically
wasn't shrinking at all despite being edited in the previous commit.

**Why `.buttons` wasn't shrinking**: `.edit-patient { form { .buttons
{...} } }` -- `justify-content: space-between`, `gap: var(--spacing)`,
`margin-top: var(--spacing)`, `padding: 0.5rem` -- is meant for the big
patient-fields form's own Save/Cancel row, but `.appointment-entry`'s own
`<form>` also sits under `.edit-patient`, and that selector (2 classes + 1
element) is more specific than `.appointment-entry .buttons` (2 classes),
so it silently won regardless of this file's own source order -- the
exact same leak class as the delete-button fix two commits ago, just on a
different property set. Fixed the same way: a more specific
`.appointment-entry .buttons` override (3 classes) right after the
leaking rule, resetting `justify-content`/`gap`/`margin-top`/`padding`
back to what the card actually wants.

**Further trims**: `.appointment-entry`'s own padding (`--spacing-sm` ->
`--spacing-xs`); the gap between the date/place columns and between a
field's own label and its input (`--spacing`/1rem -> `--spacing-sm`/
`--spacing-xs`); the form's own vertical gap between the date/place row,
loader strip, and notes/buttons block (`--spacing` -> `--spacing-sm`);
`ul.patient-appointments-list`'s `margin-top` (`--spacing-sm` ->
`--spacing-xs`).

**Verified**: a fresh appointment card's height dropped further (398px vs
426px immediately before this pass, measured via `getBoundingClientRect()`
at 390px viewport width); `.appointment-entry .buttons`'s computed
`margin-top` is now `0px` (was silently `1rem`-equivalent despite the
earlier edit). Screenshotted at both 390px and 1280px. `bin/run_tests.sh`
(101/101 static, 47/47 functional) stayed fully green. **Files**:
`web/css/styles.css` only.

## Appointment/operation cards redesigned for visual clarity and scannability

Direct request to make the cards "more visually appealing, better
information and better appearance" -- up to this point the previous three
passes had only ever trimmed the existing flat, zebra-striped list design
(one shared bordered box, alternating `lightgray`/white `<li>` rows); this
pass redesigns the cards themselves rather than just their spacing.

**Each appointment/operation is now its own elevated card**, not a row in
one shared box: `ul.patient-appointments-list` dropped its own border and
the `<li>` zebra striping, replaced with a plain flex column and a gap
between cards -- each `.appointment-entry` now carries its own white
background, rounded corners, and a subtle shadow (`--shadow-sm`, slightly
deeper on hover) instead.

**Ραντεβού and Χειρουργείο are now visually distinct at a glance, not just
by their label text**: each card gets a 4px colored left accent --
`--primary` (teal) for a plain appointment, `--warning-dark` (amber) for a
surgery, deliberately not `--error`/red, which already means "destructive
action" everywhere else on this page (Διαγραφή). The type label itself
dropped its old flat 1.2rem red text for a bold, icon-prefixed label in
the same accent color (`bx-calendar-check` / `bx-plus-medical`, the latter
the same codepoint `.new-operation`'s own button icon already uses), and
the location field gained a `bx-map-pin` icon too, both confirmed against
the vendored `boxicons.min.css` rather than guessed.

**A small `#N` index badge** in each card's top-right corner, colored to
match that card's own type accent, echoes the same index the "Date N:
..." jump-links above the list already use -- useful once there are
several cards and the jump-link row has scrolled out of view.

**Two real bugs found building this, both the same recurring class as
three earlier commits' `.inline-delete-form`/`.buttons` leaks**:
1. The badge's text/background were both muted neutrals (`var(--dark-
   gray)` on `var(--light-gray)`) that nearly disappeared against the
   card's own white background -- confirmed positioned correctly via
   `getBoundingClientRect()` but functionally invisible. Fixed with
   white text on the card's own accent color instead.
2. Even after that fix, the badge still didn't render -- confirmed via
   `document.elementFromPoint()` at the badge's own coordinates that the
   appointment's `<form>` was the topmost element there, not the badge.
   Root cause: `.edit-patient { form { position: relative; ... } }`
   (meant for the main patient-fields form) leaks `position: relative`
   onto every form under `.edit-patient`, appointment forms included --
   since the form comes after the badge in the markup and both ended up
   in the same `z-index: auto` paint layer, the form silently painted
   over it. Fixed with an explicit `z-index: 1` on the badge, which wins
   regardless of DOM order without needing to chase down (or risk
   disturbing) whatever else depends on the form's own `position:
   relative`.

**Verified against a real test server**: created a patient with one of
each card type, confirmed via `getComputedStyle()` that each card's
border-left/border-radius/box-shadow and the badge's text/background/
z-index all resolve as intended, and via `document.elementFromPoint()`
that the badge -- not the form -- is now the topmost element at its own
coordinates. Screenshotted at 390px and 1280px widths, plus a high-
resolution single-card crop and a zoomed left-edge crop to directly
confirm the colored accent border and the map-pin icon render correctly
(both were hard to make out at normal screenshot resolution and needed
closer inspection to confirm, not just assumed from the CSS). `bin/
run_tests.sh` (101/101 static, 47/47 functional) stayed fully green.
**Files**: `web/css/styles.css`, `web/templates/content/
view_appointment.zetem`.

## Fixed dark-background form controls; textareas auto-size via pure CSS, JS resize removed

Two independent fixes requested together.

**Date/select fields rendering with a dark background** -- this app has
exactly one, fixed light design (no dark-theme toggle anywhere), but
never declared that to the browser: without `color-scheme: light`, a
visitor running their OS/browser in dark mode gets every native form
control (a `<select>`'s own dropdown chrome, a date `<input>`'s native
picker/background) rendered in the browser's *own* dark palette instead,
since nothing in this app's CSS ever set an explicit background/color on
them (confirmed by grep: no input/select `background-color` rule exists
anywhere in `styles.css`) -- every custom-styled element around them
stays light, so only the native-chrome ones stood out as wrong. Added
`color-scheme: light` to the existing `html {}` rule -- a single
declaration that fixes every native control on every page at once,
rather than chasing down and hardcoding a background on each one
individually. Verified by loading a real page with Playwright's
`colorScheme: 'dark'` emulation (the only way to actually reproduce this
-- a normal light-mode browser never shows the bug) and confirming via
`getComputedStyle()` that `<select>`/date fields stayed light regardless.

**Textareas now size to their own content via CSS (`field-sizing:
content` + `min-block-size: 5lh`), not JS**: `web/js/
textarea-autoexapand.js` (and its `textarea-autoexpand-library`/dead
`textarea-expandable-library` `attach_library()` wiring) is gone
entirely -- it did the identical job by hand on every keyup, reading
`scrollHeight` and writing it back as an inline `style.height`.
`min-block-size: 5lh` is the floor `field-sizing` alone wouldn't give:
without it, an empty/short textarea would size down to its bare `rows`
default instead of a comfortable minimum, in a unit (`lh`, line-heights)
that scales with the font rather than a fixed px value. Applied globally
to every `textarea` in the app, not just the three that used to carry the
JS-only `autoexpand` attribute (now removed from all three, since nothing
reads it anymore) -- the two `pending_appointment_edit.zetem`/
`new_consultation.zetem` notes fields and the two webform search-response
boxes get the same content-based growth for free.

**A real, easy-to-miss side effect of `field-sizing: content`, caught
only by measuring the rendered element, not by reading MDN's own
description of the property**: it sizes *both* axes to the content's
natural size, not just the height -- an empty textarea collapsed to
~19px wide (just enough for its own placeholder/cursor) instead of
filling its container, a far more jarring regression than the
tall-empty-field problem this was adopted to fix. Added `inline-size:
100%` alongside it, confirmed via `getBoundingClientRect()` that a fresh
textarea now spans its full container width while still floored at the
5-line height, and that typing ten lines into it grows the element
(82.7px -> 183.6px) with zero JS involved.

**Verified**: `bin/run_tests.sh` (100/100 static, 47/47 functional -- JS
syntax dropped from 8/8 to 7/7, the exact file count after deleting
`textarea-autoexapand.js`, confirmed expected rather than a missed
reference) stayed fully green. Screenshotted the full-size appointment
form, the compact appointment-card notes field, and a 10-line note mid-
type, all at their real rendered sizes. **Files**: `web/css/styles.css`,
`config/settings.info.yaml`, `web/templates/content/{edit_patient,
edit_appointment,view_appointment}.zetem`, `web/js/
textarea-autoexapand.js` (deleted).

## Flatpickr's own date/time popup still showed a dark background, despite `color-scheme: light`

Follow-up to the entry above: that fix covers a *plain* native control (a
bare `<select>`, a date `<input>` before flatpickr takes over) correctly,
but flatpickr's own calendar popup -- the custom widget that actually
replaces those controls once JS initializes -- wraps several native
`<input type="number">`/`<select>` elements of its own: the year field,
the hour/minute fields on a date+time picker (`enableTime: true`, used by
`edit_appointment.zetem`/`pending_appointment_edit.zetem`/
`new_consultation.zetem`), and the month dropdown. Grepped `web/modules/
flatpickr/vendor/flatpickr/flatpickr.min.css` directly: every one of these
is given `background:transparent` by flatpickr's own stylesheet, relying
on the white `.flatpickr-calendar` background behind them to show through
-- and confirmed that file carries no `prefers-color-scheme`/
`color-scheme` rule of its own either.

A transparent background still lets a browser paint its own native-widget
chrome underneath before compositing that transparency on top, and
`color-scheme: light` on `<html>` is an inherited property, not a
guarantee that every engine keys a deeply-nested, dynamically-inserted
third-party widget's own descendants off it the same way for every native
control type (`<input type="number">`, `<select>`) -- this sandbox's own
Chromium rendered everything correctly either way (confirmed with
Playwright's `colorScheme: 'dark'` emulation, both before and after this
fix, same result), but the dark background was still reported in
practice, consistent with a different browser/engine not fully extending
`color-scheme` into that native-chrome base layer for elements this deep.

Rather than keep trusting inheritance for elements inside a third-party
widget's own DOM, added explicit, opaque `background-color`/`color` (plus
a `color-scheme: light` declaration of their own, belt-and-suspenders) to
every one of flatpickr's native sub-inputs directly: `.numInputWrapper
input` (year, hour, minute), `.flatpickr-current-month input.cur-year`,
`.flatpickr-current-month .flatpickr-monthDropdown-months` (+ its
`<option>`s), and `.flatpickr-time input`/`.flatpickr-am-pm`. An explicit,
opaque background is a real painted surface for the browser to show --
never a transparent one a native dark-mode layer could show through
instead, regardless of how faithfully any given engine propagates
`color-scheme` into that specific layer.

**Verified** with Playwright's `colorScheme: 'dark'` emulation against a
real date+time picker (`edit_appointment.zetem`'s "Ημ/νια & Ώρα ραντεβού"
field): `getComputedStyle()` on the hour/minute inputs, the month
`<select>`, and the year input all now report an explicit
`rgb(255, 255, 255)` background (were previously resolving to whatever
the browser's own native chrome painted, invisible to `getComputedStyle()`
since that's a UA-internal paint layer, not a CSS property value) -- and a
full-page screenshot confirms the whole popup, including the "23 : 30"
time row, renders light. `bin/run_tests.sh` (101/101 static, 47/47
functional) stayed fully green throughout -- this is CSS-only, nothing
behavioral changed. **Files**: `web/css/styles.css`.

## Modernized borders, spacing and tables: patients list, patient page, appointment cards, pending appointments

One visual system across the record pages: white cards with a 1px
hairline border (new `--border-color` token, since `--medium-gray` nearly
disappears as a line on white) and a 0.75rem radius; tables that run edge
to edge inside their card, with a quiet uppercase header on a faint fill,
hairline row dividers and a hover tint instead of a solid teal bar plus
zebra striping; compact right-aligned buttons instead of full-width slabs.

**Nested frames removed.** Most of the "box inside a box" look came from
three leaks, not from deliberate design:
- The patient form and every appointment card wrap their controls in a
  `<fieldset>` purely so `disabled` can lock them for read-only roles. Left
  unstyled, the browser drew its default groove border around it: a second
  gray frame inside every card. Reset (no border/padding/margin).
- `.edit-patient form` turns every `<form>` on the page into a white
  shadowed card, including each appointment card's own form. That was the
  inner card inside each appointment card. Reset for `.appointment-entry form`.
- The same family of selectors made each card's Save/Delete two
  full-width slabs (`flex: 1`, an 8rem min-width). Now auto-width, with
  Delete as a red text button at the far left of the row and Save on the
  right, plus a gap above the row so Save no longer sits against the notes field.

**Patient page.** Plain left-aligned page title instead of a solid teal
banner. One catch: the global `.header` rule's `justify-items: center`,
inert on a block container until browsers started supporting
`justify-self` in block layout, kept the title centered (shrunk to fit)
in current Chromium despite `text-align: left`, so `.edit-patient .header`
resets it. Labels are lighter and 10rem wide (was 13rem bold). A *valid*
AMKA/phone/email keeps a neutral border and shows only its green check
icon; a permanent green outline on every correct field was the loudest
thing on the card. Invalid still gets a red border. Νέο Ραντεβού / Νέο
Χειρουργείο are tinted buttons in their card types' colors (teal / amber);
Διαγραφή Ασθενή sits at the far right. Date jump-links are white pills.
Attachments (Συνημμένα Αρχεία) are a card footer under a hairline instead
of a dashed gray box with 1.5rem margins, and the history section is a
normal bordered card.

**Appointment cards.** Each card's autosave spinner (hidden while idle)
still took a full row plus two gaps between the date row and the notes.
It's now pinned in the card's top-right corner beside the `#N` badge.
loader.css's leftover `bottom: 0` had to be reset there: with `top` also
set, Chromium placed the box about 110px down the card (measured 1571px
vs. the expected 1462px). The `#N` badge and file-count badge are soft
tinted pills. Operation labels and badges use a new `--operation-text`
token, because `--warning-dark` as text on white (and white on it) was
about 2:1 contrast.

**Patients list.** The search field is its own white surface (its old
white wrapper card is gone), so title, search and table share one left
edge. AMKA uses tabular figures in the body font instead of monospace.

**Pending appointments.** Both tables on `/consultation/pending` now match
the patients list. Converted appointments' names link in the primary color
instead of browser-default blue. Calendar sync status is a small tinted
dot (`.sync-status`, replacing inline `style="color:..."` icons). Upcoming
dates are in full text color and history dates muted. The Actions column
was a `display: flex` `<td>`, which takes the cell out of table layout and
broke the row's divider and hover tint under it. It's a real table cell
again, and the same fix is applied to `.patients-list td.actions` (used by
the admin lists). Both table wrappers carry `lang="el"`, so the uppercase
Greek headers drop their tonos the way Greek capitals should (the page
itself still declares `lang="en"`; see below).

`.settings-table-scroll` (the admin lists, settings, and the patient
page's read-only summary/financial tables) gets the same table treatment.
Its gradient header and zebra rules used to outrank `.patients-list`'s,
leaving the admin lists half one style and half the other.

**Entry forms** (`new_consultation`, `pending_appointment_edit`/`_convert`)
share the patient page's form styling. Their supporting paragraphs were
unstyled `class="muted"` with an inline 1rem side padding that pushed them
out of line. They're now `.page-back` (a primary-colored back link),
`.page-intro` (muted context line) and `.page-notice` (an info callout for
"Google Calendar not configured"), all aligned with the title and card.

**Not changed:** `main.zetem` still declares `<html lang="en">` for a
Greek UI. Fixing it at the page level (from the active language) would be
the more correct fix, for screen readers and hyphenation too, but it
touches every page.

**Verified** with Playwright screenshots at 1280px and 390px of the
patients list, patient page (patient with 3 appointments and 1 operation),
pending list, new/edit/convert entry forms and `/admin/users`, against
seeded data; geometry checked via `getBoundingClientRect()` (Delete's label
aligned with the fields' edge, spinner position, title alignment).
`bin/run_tests.sh` (100/100 static, 47/47 functional) fully green.
**Files**: `web/css/{styles,color-palette,file-uploads,appointment-improvements}.css`,
`web/templates/content/{pending_appointments_list,new_consultation,pending_appointment_edit,pending_appointment_convert}.zetem`.

## Apps → User Management menu item, shown by permission

`Εφαρμογές → Διαχείριση Χρηστών` links to `/admin/users` (the framework's
users/roles/permissions admin pages). It's gated on the RBAC permission
`users-manage` itself, via zeusfw mainnavigation's new `permission:` menu
key, not on a list of role names like the other items' `access:`. That's
the same permission every `/admin/*` page checks, so the link appears
exactly for whoever can open the page: today that's `administrator`
(is_superuser). Granting `users-manage` to any other role at
`/admin/role_permissions` makes the link appear for that role too, with no
config change. The page's own permission check is unchanged; hiding the
link is cosmetic. See zeusfw's CLAUDE.md, "Nav menu items gain an optional
`permission:` key", for the mechanism.

**Verified**: a new functional test (`tests/functional/auth_csrf.php`)
covers administrator (sees it, page opens), doctor (hidden, page refused)
and doctor with `users-manage` granted (sees it, page opens; the grant is
rolled back afterwards). `bin/run_tests.sh` (100/100 static, 48/48
functional) green. **Files**: `config/settings.info.yaml`,
`tests/functional/auth_csrf.php`.

## Modernized topbar, navigation, breadcrumbs and footer; footer year now automatic

The page chrome was four separately styled bands stacked on top of each
other: a teal title block, two floating white pills (user; location +
language), a white breadcrumb bar, then a second teal bar for the menu.

- **Topbar** (`.region-header`, `styles.css`): one teal bar. Logo and app
  name on the left; on the right a location chip, the language flags
  (current one ringed in white) and a user chip: avatar initial, `Name
  (username)` and a logout icon button. The user block template
  (`web/templates/modules/userblock.zetem`) was bare text nodes ("User …
  . Logout") that CSS couldn't shape into anything, so it's restructured,
  keeping the exact `>Name</a> (username)` run the topbar test asserts on.
  On narrow screens the chips move to a second row; on phones the header
  runs edge to edge and a long name truncates with an ellipsis (the avatar
  carries the full name as its label).
- **Navigation** (`navigation.css`, rewritten): a light bar attached under
  the topbar, with the current section underlined (left accent on mobile)
  and white dropdown panels. The old file had accumulated contradictory
  and dead rules (red debug colors, a `fontawesome` font that's never
  loaded, `::after:has()`, duplicated margins and paddings on mobile items).
  The duplicates were what made the **mobile menu unusable**: opening it
  filled the screen with a teal panel where the items sat hundreds of
  pixels apart. It's now a normal list with tap-to-expand sections.
- **Breadcrumbs** moved out of the header into the top of `main_content`,
  as a small muted trail just above the page title
  (`config/settings.info.yaml`, `structure:`), with chevron separators.
  `modconf: breadcrumbs: access: authenticated` keeps them off the login
  page, which also renders `main_content`. Placeholder segments link to
  `/#`, which silently reloaded the home page when clicked; they're shown
  as plain text now, and any real segment URL would still be clickable.
- **Footer**: a quiet line under a hairline instead of a full teal block.
  Copyright on the left, build info (db | branch | commit | build) smaller
  on the right. **The end year now comes from the server clock**
  (`{{date('Y')}}` in `web/templates/blocks/copyright.zetem`), so it reads
  2013–2026 now and won't need a manual bump each January.

**JS fixes found along the way** (`web/js/scripts.js`):
- `toggle_dropdown()`, called by every menu dropdown checkbox in zeusfw's
  `show_menu.zetem`, was never defined, so each tap threw a
  `ReferenceError`. It's defined now, and opening one dropdown closes its
  siblings, which is what the checkboxes' shared `role="toggle-dropdown-N"`
  was for. A click outside the menu also closes any tap-opened dropdown.
- The resize listener behind `adjustSubmenuJustification()` (keeping
  dropdowns from running off-screen) was attached to `document`, which
  never fires `resize`. It's on `window` now.
- New `markCurrentNavItem()`: the menu template renders no active state,
  so the link for the current page gets `.is-current`/`aria-current` and
  its ancestors `.in-trail`. The longest matching path wins.

The old `--main-navigation-menu-*` color variables are gone (only the old
`navigation.css` used them).

**Verified** with Playwright at 1280px and 390px. The topbar meets the nav
with a 0px gap (it showed a 16px gap until `.region`'s margin was
overridden). The mobile menu opens, expands "Ραντεβού" and collapses it
again when "Ασθενείς" is tapped. A click-opened desktop dropdown closes on
an outside click. The current item is detected (`/patients` → Ασθενείς >
Λίστα; `/` → Αρχική). Zero page errors throughout. The login page renders
no breadcrumbs, header, nav or footer. (The flag images load from an
external CDN this sandbox blocks, so screenshots show their alt text.)
`bin/run_tests.sh` (100/100 static, 48/48 functional) green.
**Files**: `config/settings.info.yaml`,
`web/css/{navigation,breadcrumbs,styles}.css`, `web/js/scripts.js`,
`web/templates/modules/userblock.zetem`, `web/templates/blocks/copyright.zetem`.

## Modernized Settings page

`/settings` was a run of centered serif headings and `<hr>` rules: an
English "Settings" title with the placeholder text "Place here all
settings", English labels and Submit/Reset/Cancel buttons on the add
forms in an otherwise Greek UI, each add form floating in its own card
apart from its table, and plain blue links for user management. The
template also wrapped headings, tables and forms in `<p>` tags, which is
invalid HTML (browsers close the `<p>` early).

Now (`web/templates/content/settings.zetem`, rewritten; styles at the end
of `web/css/styles.css`, "Settings page"):
- **Ρυθμίσεις** title, with jump links to each section.
- One bordered card per area, each with an icon header and a one-line
  description: **Κλινικές**, **Ιατροί**, **Email ειδοποιήσεων (SMTP)**,
  **Χρήστες & ρόλοι** (the last only for users with `users-manage`, as before).
- Clinics and doctors: the table runs edge to edge in its card, and the add
  form is a light footer row under it (label above each field, one
  **Προσθήκη** button), stacking on phones. The doctors table now shows the
  specialty too: it used the name-only `table_short` view, so a specialty
  typed into the add form was never shown anywhere.
- SMTP: a two-column form (one column on phones) with Greek labels, a hint
  that a blank password keeps the current one, and a right-aligned
  Αποθήκευση. Field names and the save handler are unchanged.
- Users & roles: five link tiles with icons and a short description each.

**Clinic/doctor labels and buttons are now Greek**
(`web/classes/yaml/{clinics,doctors}.yaml`: Όνομα κλινικής, Όνομα
ιατρού, Ειδικότητα, Ενέργειες; Αποθήκευση/Επαναφορά/Άκυρο on the row edit
pages). Each YAML also gets a compact `view_add` form view (just
Προσθήκη) that the settings page renders; the full view stays the default
for the row edit pages. **Deploy step:** these definitions are stored in
the `webforms` DB table, so run `bin/update.sh` and answer **all** at
"Do you want to update forms?" (or `maker.php form:load
yaml/clinics.yaml` / `yaml/doctors.yaml` from `web/classes`). `form:load`
updates the existing rows in place. Until then the page works but shows
the old English labels and all three buttons.

**Verified** with Playwright at 1280px and 390px, against a test DB with
both forms loaded: adding a clinic and adding a doctor with a specialty
both save and appear in their tables; saving the SMTP form persists
(host and sender name re-read after reload); a clinic's edit page shows
Αποθήκευση / Επαναφορά / Άκυρο; zero page errors. `bin/run_tests.sh`
(100/100 static, 48/48 functional) green. **Files**:
`web/templates/content/settings.zetem`, `web/css/styles.css`,
`web/index.php` (`settings()` passes the `view_add` views and the doctors
table's default view), `web/classes/yaml/{clinics,doctors}.yaml`.

## Patient record page: identity header, collapsible details, appointment rows, documents list

The patient page (`/patient/{id}/edit`) used to open with the whole edit form, then a stack of full appointment cards. It now opens with who the patient is, and the rest is one click away. The "new patient" page is unchanged.

- **Identity header** (`.patient-identity`, `edit_patient.zetem`): avatar initials, name, age (`58y 5m`, same shape as the form's badge), birth date, AMKA with a copy icon, phone and email as `tel:`/`mailto:` links, address, the first two lines of the note, appointment/operation counts, and the last and next visit dates. Built by `zpms_patient_identity()` in `web/index.php` from the appointment list `patient_edit()` already loads, so it adds no query.
- **Personal details** are the same form as before, in a panel that starts closed. The button in the header ("Επεξεργασία στοιχείων", or "Στοιχεία ασθενή" for a view-only account) opens it as three columns (Βασικά, Επικοινωνία, Σημειώσεις); below 1000px it is two columns, below 640px one. Fields still autosave as you type, so the panel's old "Ακύρωση" (which left for the patient list and never undid anything) is now "Κλείσιμο", which just closes the panel. While the panel is open the header follows the fields (name, initials, phone, email, address, AMKA, note, birth date and age).
- **Appointments & operations** are one summary row each (date, type, place, note, file count), newest first. Clicking a row opens the existing editable card in place, with its inline edit, attachments and delete untouched; the newest row starts open. The "New appointment" and "New operation" buttons moved into this section's header. The "Date N" jump pills are gone. A link to `#app-N` (from the documents list, or a URL hash) opens the row it points into before scrolling.
- **Έγγραφα** (right column, shown when any appointment has a file): every attachment across all appointments, each linking to the file and back to its appointment.
- **Ιστορικό Αλλαγών** is restyled as a timeline; the data and its collapsed-by-default behaviour are unchanged.
- **Οικονομικά** (APYweb tables) keep their own full-width section, and **Διαγραφή Ασθενή** sits alone at the bottom.
- The page is wider (1200px instead of 900px) for this page only; the topbar, menu and footer widen with it.

Styles are in `web/css/patient-record.css`, registered as the `patient-record` library in `config/settings.info.yaml` so it loads after `styles.css` and can override its broad `.edit-patient form` rules. No database changes.

Tests: `tests/functional/patient_crud.php` (identity header, details starting hidden, rows newest first with one open, documents list) and `tests/functional/auth_csrf.php` (a view-only account gets the "Στοιχεία ασθενή" toggle and no "new appointment" button). `bin/run_tests.sh`: 101/101 static, 49/49 functional.

## Patients list: paging, rows per page, sortable headers, compact mode; gender field

### Patients list (`/patients`)

Three columns instead of name / AMKA / last appointment: **Patient** (initials avatar, name, age and AMKA with a copy icon), **Contact** (phone as a `tel:` link, email) and **Last appointment** (date, a Ραντεβού/Χειρουργείο chip, area). A date in the future is tagged "Προσεχές" so it is not read as a past visit. Phone and email show to everyone who can see the list; the patient's own page already shows them read-only to the same roles.

- **Address-driven.** Everything is in the query string: `/patients?q=&area=&sort=name|last&dir=asc|desc&page=&per_page=10|25|50|100|all`. Values equal to the default are left out of generated links. Anything outside the allowed lists falls back to its default (a bad `per_page` becomes 25, a `page` past the end becomes the last page), so a hand-edited address never errors. Built by `patients_list()` and `patientsClassEx::getPatientList()`.
- **Sorting is kept**: click **Patient** or **Last appointment** to sort, click again to reverse; defaults are as before (appointments newest first, names A-Z). A patient with no appointment sorts by the date the record was created.
- **Paging.** A pager with a **rows-per-page** menu (10, 25, 50, 100, all; default 25). "All" hides the pager. The menu and the new **area** filter submit when changed; the search box submits on Enter and keeps the existing suggestions dropdown.
- **Compact mode** (two buttons by the search box): one line per patient, dropping the avatar, age, email and area. Remembered in the browser (`localStorage`).
- **Summary line** under the title: patients on file, records created in the last 30 days, appointments today.
- **Area filter / place names.** `appointments.aplace` stores the place's *name* as it was in the language active when the appointment was saved, so one place can be stored as "Αθήνα" on some appointments and "Athens" on others. The filter therefore works on the location's **machine name** (looked up in the `locations` table): one entry per place, labelled in the current language, and choosing it matches the appointments saved under any of that place's names. A place that is not in the `locations` table (free text, e.g. from a pending appointment) is still offered, by its own text. The "last appointment" column also shows the place in the current language. (`patientsClassEx::appointmentPlaces()`, `areaNames()`, `placeLabels()`.)
- Clicking anywhere on a row opens the record; a "new appointment" button (for roles that can create one) shows on hover.
- **Fixed on the way:** the old "last appointment" query counted deleted appointments, and the old search put its `OR`s ahead of the `deleted IS NULL` test. Both are corrected. The old `/patients/sort/{key}/{order}` and `/patients/search/{term}` addresses (and the search form's POST) redirect to the query-string form, so bookmarks keep working. `getPatientsByName()`/`getPatientsByLastAppointment()` are no longer called by anything.
- The list no longer renders a CSRF token (its search is a plain GET form); `tests/functional/patient_crud.php` now takes the token from the patient's own page.

Styles: `web/css/patients-list.css` (library `patients-list`). The list no longer uses the old `.patients-list` table rules in `styles.css`.

### Router and `?`

Paging and filters need real query parameters. zeusfw's `RequestClass` now reads them (`getParams()`/`getParam()`, reached via `global $Request;`), accepts a literal `?` in the route, and keeps a `&` or `?` that was percent-encoded inside a path (a search for `Smith & Sons`), and `/?x=1` on the home page works. Details and the before/after table are in zeusfw's `CLAUDE.md` ("`core/router/Request.php` -- query parameters"). **zpms now needs that zeusfw change** (zeusfw `cff53c7`); deploy both. The test server shim `tests/lib/router.php` now appends the real query string the way Apache's `[QSA]` does, so functional tests can exercise query parameters.

### Gender (`patients.pgender`)

`'M'` (male), `'F'` (female) or `NULL` (not set), `char(1)`, nullable, no default. On the patient form it is three radio buttons in the Βασικά Στοιχεία section -- **Άνδρας / Γυναίκα / —** -- so choosing is one click; the third, empty-valued radio is "not set" (a radio group cannot be un-picked otherwise). It autosaves with the rest of the form. The record page's header shows it next to the age. Saving accepts only `M`/`F` (anything else is stored as not set), and a request that omits the field leaves the stored value alone (`zpms_normalize_gender()`).

**Existing installs** need the column: run `bin/update.sh` and accept the `diff:sql` prompt, or by hand:

```sql
ALTER TABLE patients ADD COLUMN pgender char(1) DEFAULT NULL AFTER pdob;
```

Tests: `tests/functional/patient_crud.php` (gender create/edit/blank/tampered/omitted; rows per page, paging, "all", sorting, accent-insensitive search, area filter, bad values, deleted appointments ignored, redirects, `&` in a search term). `bin/run_tests.sh`: 102/102 static, 52/52 functional.

### Back link on the patient record

A "← Πίσω στη λίστα ασθενών" / "← Back to patient list" link sits above the title of every saved patient's record (`.page-back-top`, `edit_patient.zetem`), pointing at `/patients`. The text goes through the dictionary like every translatable term: `{{t('Back to patient list')}}`. Greek is not guessed at runtime; it is seeded from `config/dictionary.gr.php` by `php bin/import_dictionary.php` (add `--dry-run` to preview; `ZPMS_DB_CONFIG` points it at another DB config). The import adds missing terms and fills in terms whose Greek has never been set (`gr_set=0`), and never overwrites a translation someone edited (`gr_set=1`), so it is safe to re-run on every deploy. **Deploy step:** run it once after pulling, otherwise the link shows in English until the term is translated in the dictionary table. To translate another term, add it to `config/dictionary.gr.php` (or any `config/dictionary.<lang>.php`). The new-patient form does not have the link.

**Duplicate headings removed.** The breadcrumb already names the page, so the record page no longer repeats it as a title ("Επεξεργασία Στοιχείων Ασθενή"; the patient's name in the identity card is the heading), the patient list's "Patients" title is visually hidden, and the new-patient form's title likewise. They remain as `.sr-only` headings (global rule in `styles.css`) so screen readers still get a page heading. The other pages were cleaned up the same way in the next entry.

## Interface text in the dictionary; no repeated page titles

Two clean-ups across the staff pages, both following the pattern started with the patient record page.

### Every visible term goes through `t()`

Text that appears on screen is no longer typed into templates or scripts in Greek (or English). Templates say `{{{t('English term')}}}`; the Greek comes from `config/dictionary.gr.php`, which `php bin/import_dictionary.php` loads into the `dictionary` table (see "Back link on the patient record" for how the import treats terms someone already edited). Covered: the record page, patients list, home, settings, my account (profile), QR generator, new/edit pending appointment, create-record-from-pending, new appointment/operation and the appointment card; the flash messages in `web/index.php` and the appointment-history labels; the status/confirm/alert text in `web/js/scripts.js` and `web/js/appointment-files.js`; the two mailer error messages.

- **Names inside a message** use `@placeholders`: `t('The record of patient @name has been saved.', ['name' => zpms_bold($name)])`. The dictionary text never carries markup; `zpms_bold()` escapes the value and adds the `<b>`. Placeholder names must not start with another placeholder's name (`@to` would also match `@total`), which is why the pager message uses `@start` / `@end`.
- **Scripts** read their text from `window.zpmsText`, which `page/main.zetem` prints once per page from `zpms_js_text()` (`web/index.php`). To give a script a new message, add a key there, use `zpmsText.<key>` in the script, and add the term to the dictionary file. The scripts carry no wording of their own.
- **`{{ }}` is one line.** The template compiler reads each `{{ ... }}` on a single line, and a `|` inside it is the filter pipe (use `+` between `JSON_*` flags).
- **Route titles** (the breadcrumb) keep their `en:`/`gr:` maps in `config/settings.info.yaml`; the new-appointment/operation routes gained one (they used to show an English title in the breadcrumb).
- **Deploy step:** after pulling, run `php bin/import_dictionary.php`. Until then Greek pages show the English terms. New terms translate automatically; terms edited by hand in the dictionary are left alone.
- The login page's own terms (`Username`, `Password`, ...) are not in the file, so the import does not change them. The settings and profile pages use separate terms (`SMTP username`, `Account username`) for the same reason.
- Not converted: the e-mail subject/body sent to doctors (`zpms_mailer.php`, `templates/email/`), the text written to Google Calendar events, the role labels seeded by `rbac_seed.php`, the older list/form templates (`operations_list`, `invoices_list`, `webforms/`), and the `lang="el"` attributes inside the settings and pending-appointment pages (the page shell's `<html lang="en">` is also unchanged).

Tests: the new `tests/static/dictionary_terms.php` fails if a converted template or `web/index.php` / `appointment_history.php` / `zpms_mailer.php` calls `t()` with a term missing from `config/dictionary.gr.php`, or if a converted template has Greek typed into it outside comments and `<script>` blocks. `TestSchema::reset()` loads the dictionary file, as a deploy does. `TestHttpClient` now strips the `?<unix time>` cache-busting suffix from page bodies: ten digits can contain "401", which the auth tests treat as the sign of the 401 page, and one of them failed now and then for no reason.

### Page titles that repeated the breadcrumb are hidden

The breadcrumb already names the page, so the heading underneath it said the same thing twice. The titles on **Settings, Pending appointments (the first table), New pending appointment, Edit pending appointment, Create patient record, New appointment/operation and the QR generator** are now visually hidden (`.sr-only`, kept as the heading for screen readers), like the patient record, patients list and new patient form before them. Headings that are not a copy of the breadcrumb stay: "Προηγούμενα Ραντεβού" (a second section), the home page greeting, and "Ο Λογαριασμός μου" on the profile page (the breadcrumb there reads "Προφιλ χρήστη").

`bin/run_tests.sh`: 131/131 static, 53/53 functional.

## TOTP removed

The two-factor (TOTP) feature was never finished -- the profile page section was already hidden (`totp_ui_enabled` was `false`) and `totp_handler()` only encoded a fixed test string into a QR code, never a per-user secret. It is gone: the `/totp/{action}` route and `totp_handler()` (`web/index.php`, `config/settings.info.yaml`), the profile page section and its two template variables (`user_profile.zetem`, `UserProfileModule`), `totp_action()` / `call_totp_action()` (`web/js/scripts.js`), the `.totp` / modal-QR styles (`userprofile.css`) and the ten dictionary terms only it used. Nothing in the database referenced it, so there is nothing to migrate. The QR generator under Apps is a separate feature and is unchanged.
