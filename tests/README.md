# ZPMS regression suite

Run this before every update to the live app:

```sh
bin/run_tests.sh
```

It checks two things, in order, and stops after the first if it fails:

1. **Static consistency** -- `php -l` on every PHP file, `node --check` on
   every JS file, a brace-balance check on every CSS file, and every
   `.zetem` template compiled through the real ZETEMTemplate compiler and
   `php -l`'d. No database involved.
2. **Functional regression tests** -- creates/edits/deletes a patient,
   creates/edits/deletes an appointment from inside a patient record,
   uploads/downloads/deletes a file attached to an appointment, and
   exercises login + CSRF enforcement (wrong password rejected, a POST
   with no or a tampered CSRF token rejected and confirmed not to have
   written anything). Driven over real HTTP (`php -S` + curl, cookie jar
   and all) against real route handlers -- not a mock of any kind.

```sh
bin/run_tests.sh --static-only     # skip the DB-backed functional suite
bin/run_tests.sh --functional-only
```

## Setup (one-time, per machine)

1. **Vendor the framework**, same as any dev/deploy checkout of this app --
   `web/core` is gitignored and never committed:
   ```sh
   ln -s /path/to/zeusfw/core web/core
   ```
2. **Create a dedicated test database.** Not the live one, not shared with
   anything else -- this suite drops and recreates it from scratch on
   every run. A separate MySQL user, granted privileges on this database
   only, is strongly recommended:
   ```sql
   CREATE USER 'zpms_test'@'localhost' IDENTIFIED BY '...';
   CREATE DATABASE zpms_test_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON zpms_test_db.* TO 'zpms_test'@'localhost';
   ```
   The name must contain "test" (`zpms_test_db` above, not e.g. `zpms2`) --
   see "Safety" below for why that's enforced, not just a suggestion.
3. **Configure it:**
   ```sh
   cp config/db.test.php.in config/db.test.php
   # edit config/db.test.php with the credentials from step 2
   ```
4. **Config for file uploads/template cache** (also gitignored, same as a
   normal checkout needs regardless of testing):
   ```sh
   cp config/site.info.yaml.in config/site.info.yaml
   ```

That's it -- `bin/run_tests.sh` regenerates the entity classes and the
database schema itself on every run (see "What a run actually does"
below), so there's no separate migration step to remember.

## Safety: this suite is built to never be able to touch the live database

This app runs a live medical database. A test suite that could, through
misconfiguration or a copy-paste mistake, run its DROP DATABASE / DELETE
logic against the real thing would be worse than no test suite at all.
Every layer below is independently sufficient on its own; they all run
anyway, because a false negative (the suite refuses to run) costs a
minute of debugging, and a false positive is unacceptable here.

- **`tests/bootstrap.php` is the only file under `tests/` allowed to read
  database credentials**, and it never reads them from `config/db.php`
  (the real one) -- only from `config/db.test.php`, a completely separate
  file.
- **The test database's name must contain "test"**, checked both at
  startup and again immediately before the actual `DROP DATABASE`
  statement (`TestSchema::reset()`) -- independent checks, so a change to
  one doesn't silently disable the other.
- **If a real `config/db.php` exists** (a real dev/prod checkout, as
  opposed to a fresh clone with no production config at all), bootstrap.php
  reads it as plain text -- never `require`s it, so this process can never
  actually obtain real credentials -- purely to confirm `config/db.test.php`
  didn't somehow end up pointing at that same database name. Refuses to
  run if it did.
- **The HTTP-level functional tests' own dev server** (`tests/lib/router.php`
  + `tests/lib/prepend_test_db.php`, started only by `tests/run_all.php`)
  locks in the test DB constants *before* `web/index.php`'s own
  `include_once(".../config/db.php")` runs. `define()` on an
  already-defined constant is a silent no-op that keeps the first value --
  so even if that file holds real production credentials, they can never
  actually take effect for this server. Nothing this suite starts can ever
  end up connected to the real database, regardless of what's in
  `config/db.php`.
- **Every functional test calls `TestSchema::assertSafeToMutate()`** before
  writing anything, which confirms the current connection is to a database
  this suite itself just rebuilt (a `_test_suite_marker` table it creates
  as the last step of `TestSchema::reset()`) -- not just a database whose
  name happens to contain "test".

If you ever see this suite refuse to start with a `FATAL:` message, that's
the point -- fix the configuration it's complaining about rather than
looking for a way around it.

## What a run actually does

`TestSchema::regenerateClasses()` / `TestSchema::reset()` (see
`tests/lib/TestSchema.php`) run, in order:

1. Regenerate the entity class PHP files (`patientsClass`,
   `appointmentsClass`, ...) and `bootstrap_classes.php`, for both the
   framework (`web/core/classes/`) and the app (`web/classes/`), from
   `*/yaml/*.yaml` via zeusfw's `maker.php` -- the exact commands a
   developer runs by hand for a normal checkout. This is deliberately not
   a committed fixture: regenerating on every run means the suite always
   tests against today's actual schema, and doubles as its own consistency
   check (a broken/renamed YAML field fails loudly here).
2. `DROP DATABASE IF EXISTS` / `CREATE DATABASE` on the test database
   (guarded as above), then load the SQL generated in step 1.
3. Insert a `power-user` test account directly via the entity class (there
   being no user-management UI to create one through).
4. Start `php -S` (`tests/lib/router.php` replicates `web/.htaccess`'s
   rewrite rule, which `php -S` doesn't read on its own) and run the
   functional suites against it over real HTTP.

## Known upstream quirks (not fixed here)

- **zeusfw's `maker.php` `spill_class()` code generator** emits a few
  lines of stray whitespace before the opening `<?php` tag in every
  generated entity class file, which PHP echoes verbatim on every
  `require`. `tests/bootstrap.php` buffers and discards it so test output
  stays readable. Confirmed harmless in the real app -- every request path
  that matters is wrapped in its own output buffering that already
  discards it (this suite's own file-download test, which would fail on a
  corrupted binary response, passes) -- but it's a real code-generation
  bug in the framework, worth fixing there at some point.

## Adding a new test

- **Static check**: add a new file under `tests/static/`, a function
  taking a `TestRunner` and calling `$runner->add($name, $fn)` for each
  case, then wire it into `tests/run_all.php`'s static block.
- **Functional test**: add a new file under `tests/functional/`, same
  `TestRunner` shape. Call `TestSchema::assertSafeToMutate()` as the first
  line of anything that writes data. Wire it into `tests/run_all.php`'s
  functional block.
- Assertions live in `tests/lib/Assert.php` (`assert_true`, `assert_equal`,
  `assert_contains`, ...) -- plain functions, no framework.
