#!/usr/bin/env bash
#
# Nightly database dump.
#
# Contabo's Auto Backup already images the whole machine, so this is not
# about losing the server. It is about the case that one cannot help with:
# noticing on Thursday that something went wrong on Monday. Restoring a
# whole-machine image would take Tuesday and Wednesday with it - every
# listing, order and message from those days. A dump restores the database
# alone, or just one table out of it, and can be copied off the box.
#
# Runs from cron, where almost nothing is set, so PATH is explicit and every
# path is absolute.

set -euo pipefail

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

APP_DIR="$HOME/restrum"
BACKUP_DIR="$HOME/backups"
KEEP_DAYS=14

cd "$APP_DIR"

mkdir -p "$BACKUP_DIR"

PASSWORD=$(grep '^DB_ROOT_PASSWORD=' .env.production | cut -d= -f2-)
DATABASE=$(grep '^DB_DATABASE=' .env.production | cut -d= -f2-)

if [ -z "$PASSWORD" ] || [ -z "$DATABASE" ]; then
    echo "Could not read the database credentials from .env.production." >&2
    exit 1
fi

TARGET="$BACKUP_DIR/${DATABASE}-$(date +%F).sql.gz"

# --single-transaction so the dump is consistent without locking the tables
# and stalling the site while it runs.
docker compose --env-file .env.production -f docker-compose.prod.yml exec -T mysql \
    mysqldump --single-transaction --quick --no-tablespaces \
        -u root -p"$PASSWORD" "$DATABASE" \
    | gzip > "$TARGET"

# A dump that failed halfway still leaves a file, and a zero byte backup
# that nobody notices is worse than no backup at all, because it looks
# like one. gzip -t reads the whole archive rather than trusting the size.
if [ ! -s "$TARGET" ] || ! gzip -t "$TARGET" 2>/dev/null; then
    echo "Backup failed verification, removing $TARGET" >&2
    rm -f "$TARGET"
    exit 1
fi

find "$BACKUP_DIR" -name '*.sql.gz' -mtime "+$KEEP_DAYS" -delete

echo "$(date -Is) wrote $TARGET ($(du -h "$TARGET" | cut -f1))"
