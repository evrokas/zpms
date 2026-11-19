# CLAUDE.md — ZPMS

Patient management system for a neurosurgery/spine practice, on the ZeusFW framework (`fw -> ../zeusfw`).
Load the `zpms` skill for the map of this app (tables, features -> files, permissions, clinical tags, tests) and
the `zeusfw` skill for framework questions (YAML entities, maker, routes, templates, RBAC, update.sh).

## Patient data — always

- The live database `zpmsdb` holds real patient records. Don't change it unless the user asks, and back it up
  first (`mysqldump --single-transaction ... | gzip > var/backups/...`, mode 0600).
- Patient text never goes into git, docs, commit messages, test fixtures or any external service. Exports and
  scratch files with patient data live under `var/` (gitignored), mode 0600; delete them when done.
- DB credentials are in `config/db.php`. For the `mysql` client, write a temporary `--defaults-extra-file`
  (0600) and delete it right after; never put the password on a command line.
- Commit or push only when asked; push to the `github` remote. Never write a bare `@name` in GitHub text.

## Layout and commands

- Handlers: `web/index.php` plus feature files in `web/` (`note_tags.php`, `schedule*.php`, `audit.php`, ...).
  Permission slugs and seed: `web/rbac.php`, `web/rbac_seed.php`. Entities: `web/classes/yaml/`.
- Config: `config/site.info.yaml` (per install), `config/settings.info.yaml` (routes, menu, modules, roles),
  `config/note_tags.yaml` (clinical tags). Translations: `config/dictionary.gr.php` -> `php bin/import_dictionary.php`.
- Update after a pull: `fw/bin/update.sh --non-interactive --yes`, then `php bin/import_dictionary.php`.
- CLI: `php bin/zpmscli.php` (e.g. `note-fields:rebuild --yes`, `notes:export`, `notes:import`); `--help` lists all.
- Tests: `bin/run_tests.sh` (static + functional; needs a test DB, `config/db.test.php`). The user runs them in a
  sandbox; JS parser cases are shared in `tests/note_tags/cases.json`.
- Docs: `README.md` (features, deploy, GDPR audit log, backups), `docs/*_PLAN.md` (feature plans).

## Conventions

- Greek UI text; labels go through `t()` and the dictionary.
- Every handler starts with `rbacClass::require(ZPMS_PERM_...)`; nav uses `access:` (roles) or `permission:`.
- Audit events go through `zpms_audit()` / `zeusfw_app_audit_event()`; never put patient text in the audit log.
- Clinical tags: PHP `web/note_tags.php` and JS `web/js/note-tags.js` must parse identically (shared test cases).
