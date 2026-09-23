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
