#!/bin/bash

set -e
export DEBIAN_FRONTEND=noninteractive

ZIP_FILE="billing.zip"
INSTALL_DIR="/var/www/html/"
SUDOERS_FILE="/etc/sudoers.d/www-data-freeradius"
REQUIRED_FILES=("billing.zip" "restore-perms.sh" "absensi.sql" "keuangan.sql" "Mybillingq.sql" "promosi.sql")

clear
echo " ==============================================================="
echo " 🚀 INSTALLER OTOMATIS CRM BILLING SYSTEM + FREERADIUS + DOCKER"
echo " ==============================================================="
echo " PT QUENBY TEKNIK SEJAHTERA"
echo " by: delta iman "
echo " VERSI INSTALLER : 1.0 (STABIL FIX)"
echo " ==============================================================="

# ===============================================================
# 0️⃣ HAPUS FULL FREERADIUS SEBELUM APA PUN
# ===============================================================
echo "🧹 Membersihkan semua instalasi FreeRADIUS lama..."
sudo systemctl stop freeradius >/dev/null 2>&1 || true
sudo apt purge -y freeradius freeradius-utils freeradius-common freeradius-config >/dev/null 2>&1 || true
sudo apt autoremove --purge -y >/dev/null 2>&1
sudo rm -rf /etc/freeradius /var/log/freeradius /var/lib/freeradius /usr/share/freeradius /run/freeradius
sudo rm -rf /etc/freeradius* /etc/init.d/freeradius /var/run/freeradius
sudo deluser --remove-home freerad >/dev/null 2>&1 || true
sudo delgroup freerad >/dev/null 2>&1 || true
echo "✅ Semua sisa instalasi FreeRADIUS berhasil dihapus bersih."

# ===============================================================
# 1️⃣ CEK FILE WAJIB
# ===============================================================
echo "🔍 Mengecek file pendukung instalasi..."
MISSING_FILES=()
for FILE in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$FILE" ]; then
        MISSING_FILES+=("$FILE")
    fi
done
PERMS_FILES=$(ls perms-backup-*.txt 2>/dev/null | wc -l)
if [ "${#MISSING_FILES[@]}" -gt 0 ] || [ "$PERMS_FILES" -eq 0 ]; then
    echo "❌ Beberapa file wajib tidak ditemukan!"
    echo "File yang hilang:"
    for f in "${MISSING_FILES[@]}"; do echo " - $f"; done
    if [ "$PERMS_FILES" -eq 0 ]; then
        echo " - perms-backup-*.txt (minimal 1 file harus ada)"
    fi
    exit 1
fi
echo "✅ Semua file pendukung ditemukan."

# ===============================================================
# 2️⃣ CEK PHP & MYSQL
# ===============================================================
echo "🔍 Mengecek PHP dan MySQL..."
if ! php -v 2>/dev/null | grep -q "7.4"; then
    echo "❌ PHP 7.4 belum terinstal!"
    exit 1
fi
if ! command -v mysql >/dev/null 2>&1; then
    echo "❌ MySQL belum terinstal!"
    exit 1
fi
echo "✅ PHP 7.4 dan MySQL terdeteksi."

# ===============================================================
# 3️⃣ UPDATE SISTEM & DEPENDENSI
# ===============================================================
echo "📦 Update repository & install dependensi..."
sudo apt update -y >/dev/null 2>&1
sudo apt install -y apache2 unzip curl lsb-release ca-certificates apt-transport-https software-properties-common gnupg >/dev/null 2>&1
echo "✅ Dependensi sistem terpasang."

# ===============================================================
# 4️⃣ INSTALL DOCKER (AUTO YES)
# ===============================================================
echo "🐳 Menginstal Docker..."
sudo apt remove -y docker docker-engine docker.io containerd runc >/dev/null 2>&1 || true
sudo apt install -y ca-certificates curl gnupg lsb-release >/dev/null 2>&1
sudo mkdir -p /etc/apt/keyrings
if [ -f /etc/apt/keyrings/docker.gpg ]; then
    sudo rm -f /etc/apt/keyrings/docker.gpg
fi
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg >/dev/null 2>&1
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
| sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
sudo apt update -y >/dev/null 2>&1
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin >/dev/null 2>&1
sudo systemctl enable docker >/dev/null 2>&1
sudo systemctl start docker >/dev/null 2>&1
echo "✅ Docker berhasil diinstal."

# ===============================================================
# 5️⃣ INSTALL FREERADIUS (AUTO FIX)
# ===============================================================
echo "📡 Menginstal FreeRADIUS..."

# --- Buat ulang user & grup agar tidak error di dpkg ---
if ! getent group freerad >/dev/null; then sudo groupadd --system freerad; fi
if ! id freerad >/dev/null 2>&1; then sudo useradd --system --no-create-home --gid freerad freerad; fi

# --- Fungsi instalasi ulang otomatis ---
install_freeradius() {
    sudo apt update -y >/dev/null 2>&1
    sudo apt install -y freeradius freeradius-utils freeradius-common freeradius-config >/dev/null 2>&1 || return 1
    sudo systemctl daemon-reload
    sleep 2
    sudo systemctl enable freeradius >/dev/null 2>&1
    sudo systemctl restart freeradius >/dev/null 2>&1 || return 1
    sleep 2
    systemctl is-active --quiet freeradius || return 1
}

if ! install_freeradius; then
    echo "⚠️ Instalasi pertama gagal, mencoba reinstall..."
    sudo apt-get remove --purge freeradius freeradius-utils freeradius-common freeradius-config -y >/dev/null 2>&1
    sudo rm -rf /etc/freeradius /var/log/freeradius /var/lib/freeradius
    install_freeradius || { echo "❌ Gagal menjalankan FreeRADIUS."; exit 1; }
fi

echo "✅ FreeRADIUS berhasil dijalankan."

# ===============================================================
# 6️⃣ IZIN DAN SUDOERS
# ===============================================================
echo "🔐 Menyesuaikan permission & sudoers..."
sudo usermod -aG freerad $(whoami)
sudo usermod -aG freerad www-data
sudo mkdir -p /etc/freeradius/3.0
sudo touch /etc/freeradius/3.0/{clients.conf,users}
sudo chown root:freerad /etc/freeradius/3.0/{clients.conf,users}
sudo chmod 664 /etc/freeradius/3.0/{clients.conf,users}
sudo tee "$SUDOERS_FILE" >/dev/null <<EOL
www-data ALL=(ALL) NOPASSWD: /bin/cat, /bin/grep, /usr/bin/nano, /usr/bin/tee, /usr/bin/bash, /usr/bin/systemctl, /usr/bin/docker
www-data ALL=(ALL) NOPASSWD: /bin/mkdir, /bin/chmod, /bin/chown, /usr/sbin/usermod, /bin/cp, /usr/sbin/groupadd
EOL
sudo chmod 440 "$SUDOERS_FILE"
echo "✅ Sudoers disiapkan."

# ===============================================================
# 7️⃣ FOLDER LOG & USER_TIMERS
# ===============================================================
echo "🗂️ Membuat folder log & user_timers..."
sudo mkdir -p /var/log/freeradius /etc/freeradius/user_timers
sudo touch /var/log/freeradius/radutmp
sudo chown freerad:freerad /var/log/freeradius/radutmp
sudo chmod -R 777 /etc/freeradius/user_timers
echo "✅ Folder log & user_timers siap."

# ===============================================================
# 8️⃣ EKSTRAK BILLING
# ===============================================================
echo "📦 Mengekstrak file billing..."
sudo mkdir -p "$INSTALL_DIR"
sudo unzip -o "$ZIP_FILE" -d "$INSTALL_DIR" >/dev/null 2>&1
echo "✅ File billing berhasil diekstrak."

# ===============================================================
# 9️⃣ RESTORE PERMISSIONS
# ===============================================================
if [ -f "restore-perms.sh" ]; then
    echo "🔧 Menjalankan restore-perms.sh..."
    sudo chmod +x restore-perms.sh
    sudo ./restore-perms.sh >/dev/null 2>&1 || echo "⚠️ restore-perms.sh gagal dijalankan."
else
    echo "⚠️ File restore-perms.sh tidak ditemukan."
fi

# ===============================================================
# 🔔 SELESAI
# ===============================================================
echo ""
echo "==============================================================="
echo "✅ INSTALASI SELESAI TANPA IMPORT SQL!"
echo "📍 Path aplikasi: $INSTALL_DIR"
echo "📍 Cron auto-update timer:"
echo "    */5 * * * * php ${INSTALL_DIR}crm/billing/update_timer.php"
echo "==============================================================="
