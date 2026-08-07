#!/usr/bin/env bash
# backup-perms.sh
# Backup permission, owner, dan

#!/usr/bin/env bash
# backup-perms.sh
# Menyimpan permission, owner, dan group dari folder sumber ke file TXT
# Lokasi file hasil backup disimpan di direktori script ini

SRC="/var/www/quenbytekniksejahtera"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
OUTFILE="$SCRIPT_DIR/perms-backup-$(date +%Y%m%d_%H%M%S).txt"

echo "📦 Membuat file backup permission: $OUTFILE"
cd "$SRC" || exit 1

# Simpan permission, owner, group, dan path relatif ke file
find . -printf "%m %u %g %p\n" > "$OUTFILE"

echo "✅ Backup selesai."
echo "File disimpan di: $OUTFILE"

