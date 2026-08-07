#!/usr/bin/env bash
# restore-perms.sh
# Mengembalikan permission, owner, dan group dari file backup
# File backup dibaca dari direktori script ini

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BACKUP_FILE="$SCRIPT_DIR/perms-backup-latest.txt"
DST="/var/www/html"

# Jika file latest belum ada, cari file backup terbaru
if [ ! -f "$BACKUP_FILE" ]; then
  BACKUP_FILE=$(ls -t "$SCRIPT_DIR"/perms-backup-*.txt 2>/dev/null | head -n 1)
fi

if [ -z "$BACKUP_FILE" ]; then
  echo "❌ Tidak ada file backup ditemukan di $SCRIPT_DIR"
  exit 1
fi

echo "♻️  Mulai restore permission ke: $DST"
echo "Menggunakan file: $BACKUP_FILE"

cd "$DST" || exit 1

while read -r mode owner group path; do
  [ -e "$path" ] || { echo "SKIP: $path (tidak ada di $DST)"; continue; }
  chmod "$mode" "$path" 2>/dev/null || echo "WARN chmod: $path"
  chown "$owner":"$group" "$path" 2>/dev/null || echo "WARN chown: $path"
done < "$BACKUP_FILE"

echo "✅ Selesai restore permission."
