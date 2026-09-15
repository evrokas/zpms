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
AMKA/email and a date/time on one fast screen (`/consultation/new`,
"Ραντεβού → Νέο (τηλεφωνικά)" in the nav), which creates the patient +
appointment in ZPMS **and** pushes a matching event to a shared Google
Calendar in the same request — so the practice's day-to-day calendar
(glanceable from anyone's phone, gets Google's own reminders) always
reflects what ZPMS knows, with no separate "now go create the calendar
event too" step. Staff who reschedule or cancel directly in the Calendar
app instead have that reflected back automatically by a periodic sync;
staff who create an event directly in Calendar (no ZPMS screen involved
at all) get it surfaced in a review queue to complete with the patient's
AMKA/phone, rather than the sync guessing at a patient link on its own.

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
false — `/consultation/new` still saves the patient + appointment
normally, `google_event_id` just stays `NULL`, and
`bin/sync_google_calendar.php` exits immediately with a log line. Never a
fatal error; nothing here can block a real phone booking.

### Schema

`appointments.google_event_id` (nullable, `UNIQUE`)/`google_synced_at` —
links an appointment to its Calendar twin; `NULL` on every appointment
predating this feature, and on every one created while it's unconfigured.
`calendar_sync_state` — one row per synced calendar, holding the
incremental `sync_token` Google's `events.list` API hands back between
runs. `calendar_pending_events` — the review queue: one row per
Calendar-native event the pull sync found with no `zpms_appointment_id`
extended property, holding just what Calendar itself knows (summary,
start time, the raw event JSON) until a human turns it into a real
patient + appointment (or dismisses it, e.g. a personal event that landed
on the shared calendar by mistake) via "Ραντεβού → Νέα από Ημερολόγιο".

As with every other schema change in this app, there's no migration
runner: `cd web/classes && php ../core/maker/maker.php spill:class:all
spill:sql:all` regenerates the entity classes + `CREATE TABLE` SQL from
the yaml (`web/classes/yaml/{calendar_pending_events,calendar_sync_state}
.yaml`, plus the two new fields in `appointments.yaml`) — the two new
tables' SQL is directly `mysql`-runnable as-is on a live database; for
the pre-existing `appointments` table, run `php ../core/maker/maker.php
diff:sql:all` first to see the exact `ALTER TABLE` this needs, since the
generated file is a full `CREATE TABLE`, not an `ALTER`.

### The two sync directions

**ZPMS → Calendar (synchronous, on save)**: `consultation_new_post()` and
`calendar_review_resolve()` (both in `web/index.php`) call
`googleCalendarClass::createEvent()` right after inserting the
appointment row, storing the returned event id. The event's
`extendedProperties.private.zpms_appointment_id` is what a later pull
sync uses to recognize "this is one of ours" — patient name/phone/AMKA/
notes go into the event's plain description text (for a human glancing at
the calendar to read), never into `extendedProperties` itself.

**Calendar → ZPMS (polling, every few minutes)**: `bin/sync_google_calendar.php`
(reference cron: `deploy/zpms-calendar-sync.cron` — install manually, same
"reference only" convention as `deploy/zpms-backup.cron`) calls
`googleCalendarClass::listChangedEvents()`, which follows Google's
incremental `syncToken` (only what changed since last run, not a full
rescan) and its own pagination. Per changed event:

- Carries `zpms_appointment_id` + still active → just a reschedule;
  update that appointment's `adate` to match. Never touches patient
  fields.
- Carries `zpms_appointment_id` + cancelled → soft-delete that
  appointment (`deleted`, same convention `appointment_delete()` uses).
- No `zpms_appointment_id`, active → upsert a `calendar_pending_events`
  row by `google_event_id` (refreshed in place if it's already queued and
  the event changed again before anyone reviewed it).
- No `zpms_appointment_id`, cancelled → if it was still sitting
  unreviewed in the queue, mark it resolved with no appointment created
  rather than leaving a stale row for an event that no longer exists.

A `410 Gone` response (Google's documented signal that a stored
`sync_token` is too old to resume from) clears the stored token so the
next run does a full resync from "now" rather than silently missing
whatever changed in between — never a caught, ignored error.

Five minutes' polling latency (not real-time push notifications via
Google's `events.watch()`) is a deliberate simplification: no public-
facing webhook endpoint to expose, no channel-renewal job to keep
running. Revisit only if that latency is ever a real problem in practice.

### Verified

`php -l` clean on every touched/new file; `bin/run_tests.sh` (32/32
static, 35/35 functional) green throughout. The JWT/token-exchange half
of `googleCalendarClient` was verified against Google's **real**
`oauth2.googleapis.com` endpoint (this sandbox can reach it, unlike
`www.google.com` — confirmed by a hand-signed JWT for a fake service
account coming back `invalid_grant: account not found` rather than a
malformed-request error, which only happens if Google successfully
parsed and validated the JWT's structure/signature first). The full
booking → review-queue → resolve/dismiss flow was verified end-to-end via
Playwright against a real MariaDB-backed test server: a phone booking
creates the correct patient/appointment; a simulated calendar-native
event resolves into a patient/appointment that reuses the *existing*
Calendar event id (never creates a duplicate); dismissing a queued event
removes it with no record created. **Not** verified: a real, successful
`createEvent()`/`listChangedEvents()` call against an actual configured
calendar (needs real service-account credentials + a real shared
calendar, which only a live deployment can provide) — same "known
limitation, not a bug" caveat zeusfw's `Recaptcha.php` documents for its
own reCAPTCHA integration, except here a live test is realistically
possible once real credentials exist, since the sandbox's network access
to Google's endpoints turned out not to be the blocker it was there.
