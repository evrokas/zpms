# Plan: configurable "update from git" script

Status: PLAN ONLY, nothing implemented. Written 2026-10-20.

## What exists today (checked)
- `fw/bin/update.sh` lives in the ZeusFW repo and must run from the app root
  (needs `web/core`). It is fully interactive: ~10 `read` prompts (import missing
  core/web tables y/N/all, apply `diff:sql` y/N, update content, generate feeders,
  clean feed data, update webforms). Several paths just `exit` with no status code.
- `bin/import_dictionary.php` (ZPMS) loads `config/dictionary.*.php` into the
  `dictionary` table; it supports `--dry-run`. There is **no** `bin/import_translations.php`;
  this plan assumes the dictionary importer is what was meant (confirm).
- Backup/cron configs already follow the pattern "settings in /etc/zpms/*.conf,
  reference files under deploy/, never auto-installed".

## 1. Make `fw/bin/update.sh` scriptable (ZeusFW repo, backward compatible)
Every prompt gets a flag; with no flags and a terminal it behaves exactly as now.
| Flag | Values | Replaces prompt |
|---|---|---|
| `--core-tables` | ask / yes / all / no | import missing core tables |
| `--web-tables` | ask / yes / all / no | import missing app tables |
| `--core-diff` | ask / yes / no | apply core `diff:sql` |
| `--web-diff` | ask / yes / no | apply app `diff:sql` |
| `--content` | ask / yes / no | update content |
| `--generate-feeders` | ask / yes / all / no | generate feeder .yml files |
| `--clean-feeds` | ask / yes / no | clean old feed data |
| `--forms` | ask / yes / all / no | update webforms |
| `--yes` | | shorthand: every `ask` becomes `yes` except the two `diff` flags |
| `--non-interactive` | | any still-`ask` item becomes `no` (never blocks on stdin) |
| `--dry-run` | | print the diffs and the table list, change nothing |
- Applying `diff:sql` changes the live database, so `--yes` does NOT imply it; it must
  be asked for explicitly (`--core-diff=yes --web-diff=yes`). The diff is always
  printed (and logged) before it is applied.
- Fix the bare `exit` calls to return non-zero on failure so the wrapper can stop.
- Unknown flag = error with usage text.

## 2. The update script (`bin/update_from_git.sh`, ZPMS repo)
Bash, `set -euo pipefail`, runs from anywhere (finds the app root itself).

Configuration, in priority order: command line > `/etc/zpms/update.conf` > built-in
defaults. A reference file `deploy/update.conf.example` lists every variable:
- `REPOS` : list of `name|path|remote` (default: the app root and `fw/`, both `origin`).
- `ALLOWED_BRANCHES` : regex or list per repo (e.g. `^(main|prod|release/.*)$`);
  anything else is refused. `DEFAULT_BRANCH` per repo.
- `PULL_MODE` : `ff-only` (default) / `merge` / `rebase`.
- `ALLOW_DIRTY` : `no` (default): refuse to run on a modified working tree.
- `RUN_UPDATE_SH` : yes/no, and `UPDATE_ARGS` : the flags from section 1.
- `RUN_IMPORT_DICTIONARY` : yes/no (and the script name, so it can be renamed).
- `POST_STEPS` : optional extra commands, e.g. `php bin/migrate_roles.php --yes`.
- `BACKUP_BEFORE` : command run first (e.g. DB dump or `bin/backup.sh`); update aborts if it fails.
- `RESTART_CMD` : optional (`systemctl reload php8.x-fpm`), `LOG_FILE`, `LOCK_FILE`.

Flow:
1. Load config, take the lock (`flock`), open the log.
2. Select the branch: `--branch NAME` (and `--fw-branch NAME`), or interactive menu
   built from `git fetch` + `git branch -r`, filtered by `ALLOWED_BRANCHES`. Non-interactive
   with no `--branch` uses `DEFAULT_BRANCH`.
3. Per repo: refuse a dirty tree, `git fetch`, print incoming commits
   (`git log HEAD..origin/<branch>`), remember the current commit, check out the branch,
   pull as `PULL_MODE`. `--dry-run` stops after the fetch and the log.
4. Order: ZeusFW first, then ZPMS, so code and framework match.
5. `BACKUP_BEFORE`, then `fw/bin/update.sh $UPDATE_ARGS`, then
   `php bin/import_dictionary.php`, then `POST_STEPS`, then `RESTART_CMD`.
6. Print a summary (old to new commit per repo, steps run) and, on failure, the exact
   commands to roll back (`git checkout <old commit>`); it never rolls back by itself,
   because a database change cannot be undone that way.
Options: `--branch`, `--fw-branch`, `--no-update-sh`, `--no-dictionary`, `--dry-run`,
`--yes`, `--config FILE`, `--help`. Exit codes: 0 ok, 1 refused (dirty tree, bad branch,
lock held), 2 a step failed.

## 3. Running git with elevated rights
Constraint: **Linux ignores the setuid bit on scripts**, so "a script with sticky
permissions" will not work as a plain setuid shell script. (The "sticky bit" itself only
applies to directories.) Options, best first:
1. **sudo to a dedicated user (recommended).** Create a deploy user (the Linux user that owns the script, e.g. `zpmsdeploy`) that owns the checkout files and holds a
   read-only GitHub deploy key. Install a small root-owned wrapper
   `/usr/local/sbin/zpms-git-update` (0755, not writable by anyone else) that:
   validates its arguments (branch matches `^[A-Za-z0-9._/-]+$`, no leading `-`, is in
   `ALLOWED_BRANCHES`), then runs `bin/update_from_git.sh` as the deploy user with a
   clean environment. A sudoers line in `deploy/zpms-update.sudoers` lets only the
   chosen account (an admin, or the web server user if a web button is wanted) run
   exactly that wrapper, with no shell and no arbitrary arguments.
2. A tiny compiled setuid wrapper (C): only if sudo is not available. Needs the same
   argument validation; more to maintain.
3. Group permissions only: the checkout is group-writable (setgid directories `2775`)
   for a `zpmsdeploy` group; no privilege escalation, the person running it must be in
   the group. Simplest, but needs each user to hold the deploy key.
Also needed: `git config --global safe.directory <path>` for the deploy user, correct
ownership after pull (reuse the `data/` permission rules; the web server must still read
`web/` and write `logs/`/`data/`), and nothing secret in the repo.

## 4. The web page (now in scope; decided 2026-10-20)
An admin-only page `/admin/update` ("Update from git"), permission `system-update`
(administrator only), CSRF-protected, that calls the sudo wrapper:
- **Branch selection box** for ZPMS, filled from the wrapper's `--list-branches`
  (the deploy user does `git fetch` and prints the allowed remote branches; the web
  user never touches git or the key). Current branch and commit shown above it.
- **Checkbox "Also update the framework (fw/)"**; when ticked, a second selection box
  appears with the ZeusFW branches.
- **Checkbox "Back up the database first"**, checked by default.
- More checkboxes, all passed to the wrapper as validated flags: "Run update.sh"
  (checked), "Import translations/dictionary" (checked), "Apply database schema changes"
  (UNCHECKED by default, it changes the live DB).
- A "Preview" button runs `--dry-run` (fetch + incoming commits only); "Update" runs it for
  real after a confirm dialog. The run happens in the background under the lock; the
  page polls the log and shows the output and the final result.
- Audit event `system_update` with branch names, old/new commit ids, flags and result
  only. Permission added by `bin/migrate_roles.php`; Greek strings in the dictionary.
- The wrapper accepts only: `--list-branches`, `--status`, `--dry-run`, and
  `--branch X [--fw-branch Y] [--backup|--no-backup] [--no-update-sh] [--no-dictionary]
  [--apply-schema]`. Branch names are checked against the allow-list from the config.

## 4b. Setting up the Linux user (one time, as root on the server)
1. `adduser --system --group --home /var/lib/zpmsdeploy --shell /bin/bash zpmsdeploy`
   and `usermod -aG www-data zpmsdeploy`.
2. Ownership: checkout files `zpmsdeploy:www-data`; directories setgid `2775`, files
   `664`/`775`, so the web server can still read `web/` and write `logs/` and `data/`
   exactly as now. `bin/fix_permissions`-style script to re-apply after each update.
3. As `zpmsdeploy`: `ssh-keygen -t ed25519`; add the public key as a read-only deploy
   key on the zpms and zeusfw GitHub repos; `ssh-keyscan github.com >> ~/.ssh/known_hosts`;
   `git remote set-url origin git@github.com:evrokas/<repo>.git` in both checkouts;
   `git config --global --add safe.directory <zpms path>` (and the fw path).
4. Install the wrapper `/usr/local/sbin/zpms-git-update` (root:root 0755) and
   `/etc/zpms/update.conf` (root:root 0644, no secrets in it).
5. `/etc/sudoers.d/zpms-update` (checked with `visudo -c`):
   `www-data ALL=(zpmsdeploy) NOPASSWD: /usr/local/sbin/zpms-git-update`
6. Backups: `zpmsdeploy` must be able to read `config/db.php` (group `www-data`, mode 640),
   have `mysqldump`, and own a backup directory (e.g. `/var/backups/zpms`).
Reference copies go in `deploy/` (wrapper, sudoers, update.conf.example, a
`setup_update_user.sh` that does steps 1-6 with `--dry-run`).

## 5. Tests and docs
- `update.sh`: run with every flag combination against the disposable test DB
  (`--non-interactive` must never block; `--dry-run` must change nothing).
- `update_from_git.sh`: shell tests against two throwaway local git repos
  (dirty tree refused, branch not in allow-list refused, ff-only refuses a diverged
  branch, lock prevents a second run, interactive menu fed by stdin).
- Wrapper: argument-validation tests (`; rm`, `--upload-pack=...`, `-x`, spaces).
- README section "Updating from git", `deploy/update.conf.example`,
  `deploy/zpms-update.sudoers`, and a note in ZeusFW's CLAUDE.md for the new flags.

## 6. Order of work
1. `update.sh` flags (ZeusFW), tests.
2. `update_from_git.sh` + config example, tests.
3. Wrapper + sudoers + deploy user setup notes.
4. The `/admin/update` page, permission, audit event, tests. 5. README and docs.

## 7. Open questions
1. `bin/import_translations.php` does not exist: is `bin/import_dictionary.php` the right one?
2. (Answered) The wrapper's owner is a Linux user, the dedicated deploy user: it owns the checkout and the wrapper runs the update as that user.
3. (Answered) Yes, the web page triggers it through the sudo wrapper (section 4). Still to confirm: sudo is installed on the server.
4. (Answered) Yes, optional: a checkbox on the page, with its own branch selection box.
5. (Answered) A checkbox, checked by default. Still to confirm: the backup command (default: `mysqldump` to a dated, compressed file in the backup directory).
