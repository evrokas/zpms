#!/bin/bash
#
# One-time setup of the "update from git" machinery (run as root):
#   - a dedicated Linux user that owns the checkout and holds the GitHub deploy key
#   - the wrapper /usr/local/sbin/zpms-git-update and its sudoers rule
#   - /etc/zpms/update.conf and the backup folder
# Safe to run again.  --dry-run prints every command and changes nothing.
#
# Usage: sudo bash deploy/setup_update_user.sh [--app-root DIR] [--user NAME]
#            [--web-user NAME] [--backup-dir DIR] [--dry-run]

set -u
APP_ROOT=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
DEPLOY_USER=zpmsdeploy
WEB_USER=""
BACKUP_DIR=/var/backups/zpms
DRY=0

while [[ $# -gt 0 ]]; do
    case $1 in
        --app-root)   APP_ROOT=$(readlink -f "$2"); shift ;;
        --user)       DEPLOY_USER=$2; shift ;;
        --web-user)   WEB_USER=$2; shift ;;
        --backup-dir) BACKUP_DIR=$2; shift ;;
        --dry-run)    DRY=1 ;;
        -h|--help)    sed -n '2,12p' "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
    shift
done

[[ $EUID -eq 0 || $DRY == 1 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ $DEPLOY_USER =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo "bad user name" >&2; exit 2; }
[[ -d $APP_ROOT/bin && -f $APP_ROOT/bin/update_from_git.sh ]] || { echo "$APP_ROOT is not a ZPMS checkout" >&2; exit 1; }

# the web server user: given, or from Apache's envvars / a running process, else www-data
if [[ -z $WEB_USER ]]; then
    for lib in "$APP_ROOT/bin/lib/detect_apache_user.sh"; do
        [[ -f $lib ]] && source "$lib" && type detect_apache_user >/dev/null 2>&1 && WEB_USER=$(detect_apache_user 2>/dev/null)
    done
    [[ -n $WEB_USER ]] || WEB_USER=$(ps -eo user,comm 2>/dev/null | awk '$2 ~ /^(apache2|httpd|php-fpm.*)$/ && $1 != "root" {print $1; exit}')
    [[ -n $WEB_USER ]] || WEB_USER=www-data
fi

run() { if [[ $DRY == 1 ]]; then echo "[dry-run] $*"; else echo "+ $*"; "$@"; fi; }
note() { echo; echo "== $*"; }

echo "Application:  $APP_ROOT"
echo "Deploy user:  $DEPLOY_USER"
echo "Web user:     $WEB_USER"
echo "Backup dir:   $BACKUP_DIR"

note "1. the deploy user"
if id "$DEPLOY_USER" >/dev/null 2>&1; then
    echo "user $DEPLOY_USER exists"
else
    run adduser --system --group --home "/var/lib/$DEPLOY_USER" --shell /bin/bash "$DEPLOY_USER"
fi
run usermod -aG "$WEB_USER" "$DEPLOY_USER"
DEPLOY_HOME=$(getent passwd "$DEPLOY_USER" | cut -d: -f6); DEPLOY_HOME=${DEPLOY_HOME:-/var/lib/$DEPLOY_USER}

note "2. ownership of the checkout (code: $DEPLOY_USER:$WEB_USER; web-written folders stay with $WEB_USER)"
FW=$APP_ROOT/fw; [[ -e $FW ]] && FW=$(readlink -f "$FW")
for d in "$APP_ROOT" "$FW"; do
    [[ -d $d ]] || continue
    run chown -R "$DEPLOY_USER:$WEB_USER" "$d"
    run find "$d" -path "$d/.git" -prune -o -type d -exec chmod 2755 {} +
done
# folders the web server (or both users) write to
for d in "$APP_ROOT/web/cache" "$APP_ROOT/web/files"; do
    [[ -d $d ]] || continue
    run chown -R "$WEB_USER:$WEB_USER" "$d"; run find "$d" -type d -exec chmod 2775 {} +
done
run mkdir -p "$APP_ROOT/logs"
run chown "$WEB_USER:$WEB_USER" "$APP_ROOT/logs"
run chmod 2775 "$APP_ROOT/logs"          # the deploy user is in the web group and writes update.log here
if [[ -f $APP_ROOT/config/db.php ]]; then
    run chgrp "$WEB_USER" "$APP_ROOT/config/db.php"; run chmod 640 "$APP_ROOT/config/db.php"   # the backup reads it
fi

note "3. backup folder"
run mkdir -p "$BACKUP_DIR"
run chown "$DEPLOY_USER:$DEPLOY_USER" "$BACKUP_DIR"
run chmod 700 "$BACKUP_DIR"

note "4. config, wrapper, sudoers"
run mkdir -p /etc/zpms
if [[ -f /etc/zpms/update.conf ]]; then
    echo "/etc/zpms/update.conf exists: left alone (check DEPLOY_APP_ROOT=$APP_ROOT)"
else
    if [[ $DRY == 1 ]]; then echo "[dry-run] install update.conf.example as /etc/zpms/update.conf with DEPLOY_APP_ROOT=$APP_ROOT BACKUP_DIR=$BACKUP_DIR"
    else
        sed -e "s#^DEPLOY_APP_ROOT=.*#DEPLOY_APP_ROOT=$APP_ROOT#" -e "s#^BACKUP_DIR=.*#BACKUP_DIR=$BACKUP_DIR#" \
            "$APP_ROOT/deploy/update.conf.example" > /etc/zpms/update.conf
        chown root:root /etc/zpms/update.conf; chmod 644 /etc/zpms/update.conf; echo "wrote /etc/zpms/update.conf"
    fi
fi
run install -o root -g root -m 0755 "$APP_ROOT/deploy/zpms-git-update" /usr/local/sbin/zpms-git-update
SUDOERS=/etc/sudoers.d/zpms-update
if [[ $DRY == 1 ]]; then
    echo "[dry-run] write $SUDOERS: $WEB_USER ALL=($DEPLOY_USER) NOPASSWD: /usr/local/sbin/zpms-git-update"
else
    tmp=$(mktemp)
    sed -e "s/^www-data ALL=(zpmsdeploy)/$WEB_USER ALL=($DEPLOY_USER)/" "$APP_ROOT/deploy/zpms-update.sudoers" > "$tmp"
    if visudo -cf "$tmp" >/dev/null; then
        install -o root -g root -m 0440 "$tmp" "$SUDOERS"; echo "wrote $SUDOERS"
    else
        echo "sudoers check failed; not installed" >&2; rm -f "$tmp"; exit 1
    fi
    rm -f "$tmp"
fi

note "5. git for the deploy user"
for d in "$APP_ROOT" "$FW"; do
    [[ -d $d/.git || -f $d/.git ]] || continue
    run runuser -u "$DEPLOY_USER" -- git config --global --add safe.directory "$d"
done
run runuser -u "$DEPLOY_USER" -- mkdir -p "$DEPLOY_HOME/.ssh"
if [[ ! -f $DEPLOY_HOME/.ssh/id_ed25519 ]]; then
    run runuser -u "$DEPLOY_USER" -- ssh-keygen -t ed25519 -N "" -C "zpms-deploy@$(hostname)" -f "$DEPLOY_HOME/.ssh/id_ed25519"
fi
if ! grep -qs github.com "$DEPLOY_HOME/.ssh/known_hosts"; then
    if [[ $DRY == 1 ]]; then echo "[dry-run] ssh-keyscan github.com >> $DEPLOY_HOME/.ssh/known_hosts"
    else ssh-keyscan -t ed25519 github.com 2>/dev/null | runuser -u "$DEPLOY_USER" -- tee -a "$DEPLOY_HOME/.ssh/known_hosts" >/dev/null; fi
fi

echo
echo "== Still to do by hand"
echo "1. Add this public key as a READ-ONLY deploy key on the GitHub repositories (zpms and zeusfw):"
if [[ -f $DEPLOY_HOME/.ssh/id_ed25519.pub ]]; then sed 's/^/     /' "$DEPLOY_HOME/.ssh/id_ed25519.pub"; else echo "     (shown after a real run: $DEPLOY_HOME/.ssh/id_ed25519.pub)"; fi
echo "2. Point the remotes at SSH (as $DEPLOY_USER):"
echo "     git -C $APP_ROOT remote set-url origin git@github.com:evrokas/zpms.git"
echo "     git -C ${FW} remote set-url origin git@github.com:evrokas/zeusfw.git"
echo "3. Check it:  sudo -u $WEB_USER sudo -u $DEPLOY_USER /usr/local/sbin/zpms-git-update branches"
echo "4. Edit /etc/zpms/update.conf (ALLOWED_BRANCHES, DEFAULT_BRANCH, ...)."
echo "5. Give the 'system-update' permission to a role (php bin/migrate_roles.php --yes adds it to administrator)."
