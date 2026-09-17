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
anyone holding just this permission with every field inside a disabled
`<fieldset>`, no save button (a plain "← Back to list" link instead), no
"New appointment"/"New operation" links, and the attachments section
hidden entirely (uploaded files can be scanned medical documents, and
read-only access was only ever meant to cover the patient/appointment
fields themselves — every `appointment_file_*` route still requires
`appointment-edit` regardless, so this is a UI courtesy matching a gate
that already exists, not the only thing enforcing it). The patient
list's own "Add new patient" button and per-row delete form are likewise
hidden when the viewer lacks `patients-new-patient`/
`patients-delete-patient`. Actually saving a change always re-checks
`patients-edit-patient` server-side in `patient_edit_post()`, regardless
of what a tampered request submits — the read-only rendering is a UX
nicety on top of a real, independently-enforced gate, not the gate
itself. `secretary` additionally holds `pending-appointments-manage` (see
"Google Calendar sync" below) — it can book/edit/cancel a phone
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
