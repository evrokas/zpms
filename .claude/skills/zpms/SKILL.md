---
name: zpms
description: Map of the ZPMS patient management app (/var/www/shtml/apps/zpms) — where each feature's code, routes, tables, permissions and tests are, the clinical tags parser, zpmscli, the test suite and deploy steps. Use for any change, bug or question in zpms; load the zeusfw skill too for framework mechanics.
---

# ZPMS

Patient management for a neurosurgery/spine practice. PHP on ZeusFW (`fw -> ../zeusfw`, see the `zeusfw` skill),
MySQL `zpmsdb`, Greek UI. The always-on rules (patient data, credentials, commits) are in `CLAUDE.md`.

## Data model (`web/classes/yaml/`)

| Table | What | Key fields |
|---|---|---|
| `patients` | patient record | `guid`, `pname`, `pdob`, `pgender`, `pamka`, `ptel`, `paddr`, `pemail`, `firstapp`, `pnote` (patient note), `deleted` |
| `appointments` | visits **and** operations | `pguid` -> patients.guid, `adate`, `aplace`, `anote` (note), `atype` (`operation` or anything else = appointment, fixed at creation), `duration_minutes`, `remote_*`, `google_event_id`, `deleted` |
| `appointment_files` | attachments (files on disk, `appointment_files_resolve_storage_path()`) | |
| `appointment_history` | per-appointment edit history | |
| `pending_appointments` | front-desk consultation requests, converted into patient + appointment | |
| `note_fields`, `note_list_entries` | clinical tags read from notes; picker lists | see `reference/clinical-tags.md` |
| `schedule_templates`, `schedule_overrides`, `schedule_locations`, `slot_reservations`, `location_travel_times` | appointment schedule | rules in `web/schedule.php` |
| `locations` (feeder `web/content/locations/`), `clinics`, `doctors`, `mail_settings` | settings | |
| `calendar_sync_state` | Google Calendar sync | |
| `audit_log` | GDPR audit trail | never patient text |
| `user_notes` | personal notes on the home page | |

Soft delete everywhere (`deleted` datetime); purge = hard delete (zpmscli). Patients link to appointments by
**guid** (`pguid`), not id. Users/roles/permissions are framework tables.

## Where the code is

| Feature | Code | Templates/JS |
|---|---|---|
| Routes, menu, modules, roles | `config/settings.info.yaml` | |
| Patients list/search/record, appointments, operations, consultation intake, pending appointments, settings, QR | `web/index.php` (handlers) | `web/templates/content/*.zetem`, `web/js/scripts.js`, `finance.js` |
| Patient search API for DocArc | `web/api_patients.php` (`/api/v1/patients/...`) | |
| Attachments | `web/appointment_files.php` | `appointment-files.js`, `attachments-sidebar.js` |
| Edit history | `web/appointment_history.php` | |
| Schedule (calendars per location, drag booking, remote) | `web/schedule.php` (pure rules), `schedule_pages.php`, `schedule_calendar.php` | `schedule*.js` |
| Clinical tags | `web/note_tags.php`, `web/note_lists.php`, `config/note_tags.yaml` | `note-tags.js`, `note-box.js` |
| Permissions / seed | `web/rbac.php` (`ZPMS_PERM_*`), `web/rbac_seed.php` (roles doctor, secretary, maintenance, administrator) | |
| Audit | `web/audit.php` (`zpms_audit()`, `zeusfw_app_audit_event()`, `/audit`) | |
| Email | `web/zpms_mailer.php` (SMTP from `mail_settings`) | |
| Google Calendar | `web/google_calendar_client.php`, `bin/sync_google_calendar.php` | |
| apyweb, DocArc, MedScribe, ErnsAuth | `web/apyweb_client.php`, `config/*_api.php` (from `*.php.in`) | `ernsauth-sso.js` |
| Update from git page | `web/system_update.php`, `bin/update_from_git.sh` (`/system/update`) | `system-update.js` |
| Entity extensions | `web/ClassesEx.php` (`usersClassEx::getUserAccount()`, ...), `web/locationsClassEx.php` | |
| CLI | `bin/zpmscli.php` + `bin/lib/` | |

Permission slugs (`web/rbac.php`): `patients-view-list`, `patients-new-patient`, `patients-edit-patient`,
`patients-delete-patient`, `appointment-edit`, `appointment-view`, `patient-financial-view`,
`pending-appointments-manage`, `settings-manage`, `backup-access`, `audit-log-view`, `schedule-manage`,
`schedule-override-shadow`, `system-update`. New permission: constant in `rbac.php`, grant in `rbac_seed.php`,
check with `rbacClass::require()` in the handler, `permission:` on the menu item.

## Commands

- `php bin/zpmscli.php` — dry run unless `--yes`; `ZPMS_DB_CONFIG=<file>` for another DB. Commands: `users:list`,
  `patients:list`, `appointments:list`, `patients:reassign`, `patients:delete|purge`, `appointments:delete|purge`,
  `cleanup:deleted`, `cleanup:orphans`, `note-fields:rebuild`, `note-lists:seed`, `notes:export`,
  `notes:import [--allow-edits] [--revert=<backup.json>]`, `cache:clean`. Back up the DB before purge/reassign/import.
- Deploy after a pull: `fw/bin/update.sh --non-interactive --yes`, `php bin/import_dictionary.php`; after changing
  `config/note_tags.yaml` or the parser: `php bin/zpmscli.php note-fields:rebuild --yes`.
- Backups: zops (`lib/zops/`), status page `/apps/backup`; `bin/backup.sh`/`restore.sh` are the old scripts.

## Working on it

- UI text in Greek through `t()`; add new terms to `config/dictionary.gr.php` (a static test checks this).
- Escape every patient value in templates (`| e` / `{{{ }}}`).
- Write audit events for anything that reads or changes patient data in a new way; context holds ids and field
  names, never values.
- Big decisions and history per feature are in `README.md` (search by heading) and `docs/*_PLAN.md`.
- Tests: `reference/testing.md`. Clinical tags: `reference/clinical-tags.md`.
