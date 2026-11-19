# Tests

`bin/run_tests.sh` (`--static-only`, `--functional-only`) -> `tests/run_all.php`. Setup and the safety design:
`tests/README.md`. The user runs the suite in a sandbox; say what you could not run.

- **Static** (`tests/static/`): PHP lint, JS lint, CSS and template consistency, every UI term in the
  dictionary, clinical-tag parser cases.
- **Functional** (`tests/functional/`): real HTTP against `php -S` with `tests/lib/router.php` (replicates Apache
  `[QSA]`), on a disposable DB from `config/db.test.php` whose name **must contain "test"** (enforced). The schema
  is rebuilt from the YAMLs each run (`tests/lib/TestSchema.php`), so a new table/column needs nothing extra.
  Files: admin_crud, appointment_crud, audit_log, auth_csrf, booking, calendar_sync, config_files, file_upload,
  medscribe_api, note_fields, patient_crud, profile_notes, schedule, session_policy, system_update,
  update_scripts, zpmscli.
- **Helpers** (`tests/lib/`): `Assert.php`, `HttpClient.php` (cookies, CSRF), `TestFixtures.php`
  (`loginAsAdministrator()`, users per role), `ServerManager.php`.
- **JS** (`tests/note_tags/`): `node tests/note_tags/run_cases.js`, `editing_cases.js`.
- **Shell** (`tests/shell/`): update.sh flags, update-from-git wrapper.

Add a functional test for every bug fixed in a handler and every new permission check (both the allowed and the
refused role). Fixtures use invented names only — never real patient data.

Times: tests use the app's timezone (`tz:` in config). A 401 page is recognised by its text, not by the digits.
