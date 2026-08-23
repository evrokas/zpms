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

## Backups

`bin/backup.sh`, run nightly via cron/systemd (reference configs:
`deploy/zpms-backup.cron`, `deploy/zpms-backup.timer`/`.service` — install
one or the other manually, neither is auto-applied by anything in this
repo), takes a consistent MySQL dump (`mysqldump --single-transaction`, so
InnoDB doesn't need locking for a consistent snapshot) and hardlinks
`web/files/` (the app's upload/library store — e.g. `web/files/pdflib/`
invoice/receipt scans) alongside it, then ships both off-site via rsync
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
