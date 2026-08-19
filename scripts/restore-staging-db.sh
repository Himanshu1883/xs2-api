#!/usr/bin/env bash
# Restore MySQL from Downloads dump (staging-latest-august-06.sql).
# Run in macOS Terminal — Cursor cannot read ~/Downloads on your machine.
set -euo pipefail

DUMP="${1:-$HOME/Downloads/staging-latest-august-06.sql}"
DB_NAME="${2:-stagingDB}"
MYSQL_USER="${MYSQL_USER:-root}"
MYSQL_PASS="${MYSQL_PASS:-himanshu123}"

if [[ ! -f "$DUMP" ]]; then
  echo "Dump not found: $DUMP"
  exit 1
fi

echo "File: $DUMP"
echo "Target database: $DB_NAME (same as seatsbroker-provider-api .env DB_DATABASE)"
echo ""
echo "This will DROP and recreate \`$DB_NAME\`, then import the full dump."
echo "All current data in that database will be replaced by the backup."
echo "Abort with Ctrl+C in the next 10 seconds if that is not what you want."
sleep 10

export MYSQL_PWD="$MYSQL_PASS"
mysql -u"$MYSQL_USER" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Importing (large dump — may take 10–30+ minutes)..."
mysql -u"$MYSQL_USER" "$DB_NAME" < "$DUMP"
unset MYSQL_PWD

TABLES=$(mysql -u"$MYSQL_USER" -p"$MYSQL_PASS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';" 2>/dev/null || echo "?")
echo "Restore finished. Tables in $DB_NAME: $TABLES"
echo "Next: cd seatsbroker-provider-api && php artisan config:clear"
echo "Then log in again on the provider console (sessions/tokens were reset)."
