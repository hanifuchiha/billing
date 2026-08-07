# Panduan Setup Cron Tiket Otomatis (Per-Owner)

## Ringkasan Perubahan

Sistem cron telah diperbarui untuk **memastikan setiap PEMILIK/pengguna hanya memproses servers dan customers miliknya sendiri**, bukan semua data dari semua pengguna.

---

## Arsitektur Baru

### 1. **Config File (`config_cron.json`)**

**Struktur Baru:**
```json
{
    "cron_dismantle_ticket": {
        "enabled_by": ["PEMILIK1", "PEMILIK2"],
        "interval_hours": 2,
        "_comment": "enabled_by = array PEMILIK yang mengaktifkan. Kosong = disabled."
    },
    "cron_maintenance_ticket": {
        "enabled_by": ["PEMILIK1"],
        "interval_hours": 2,
        "_comment": "enabled_by = array PEMILIK yang mengaktifkan. Kosong = disabled."
    }
}
```

- **`enabled_by`**: Array berisi PEMILIK yang telah mengaktifkan cron ini
- **`interval_hours`**: Jarak minimum waktu antara eksekusi (1-24 jam)
- Kosong `[]` = cron disabled

---

## Cara Kerja Per-Owner

### **Saat ADMIN mengaktifkan cron dari UI:**

1. **Sistem membaca server ADMIN:**
   ```sql
   SELECT DISTINCT PEMILIK FROM server WHERE user_id = [current_user_id]
   ```

2. **Tambahkan PEMILIK ke `enabled_by` array:**
   - Jika PEMILIK "PT. ABC" milik ADMIN sudah ada di array → skip
   - Jika belum → tambahkan

3. **Saat cron script berjalan:**
   - Baca `enabled_by` array dari config
   - Filter query database: `WHERE PEMILIK IN (enabled_by array)`
   - Proses HANYA data dari PEMILIK yang listed

### **Saat ADMIN menonaktifkan cron:**

1. Hapus PEMILIK-nya dari `enabled_by` array
2. Jika array menjadi kosong → cron otomatis disabled untuk semua

---

## Implementasi pada File-File

### **A. Cron Maintenance Ticket** (`crm/billing/cron/cron_maintenance_ticket.php`)

**Perubahan:**
- Membaca `enabled_by` array dari config
- Filter server query: `WHERE s.PEMILIK IN (enabled_by array)`
- Filter pelanggan query: `WHERE p.PEMILIK IN (enabled_by array)`
- Log: `[INFO] Diproses untuk PEMILIK: PT. ABC, PT. XYZ`

**Fungsi:**
- Membuat tiket MAINTENANCE untuk customer offline
- Membatalkan tiket jika customer online kembali

---

### **B. Cron Dismantle Ticket** (`crm/billing/cron/cron_dismantle_ticket.php`)

**Perubahan:**
- Membaca `enabled_by` array dari config
- Filter server query: `WHERE s.PEMILIK IN (enabled_by array)`
- Filter pelanggan query: `WHERE p.PEMILIK IN (enabled_by array)`
- Log: `[INFO] Diproses untuk PEMILIK: PT. ABC`

**Fungsi:**
- Membuat tiket DISMANTLE untuk customer menunggak/expired
- Otomatis convert ke MAINTENANCE jika online kembali
- Cancel tiket jika sudah bayar

---

### **C. Toggle Handler - tables.php** (Maintenance Cron)

**Endpoint AJAX:** `toggle_cron_maintenance`

**Logika:**
```php
// Tombol ON:
1. Query PEMILIK milik user: SELECT DISTINCT PEMILIK FROM server WHERE user_id = X
2. Tambahkan ke enabled_by array
3. Simpan ke config_cron.json

// Tombol OFF:
1. Query PEMILIK milik user
2. Hapus dari enabled_by array
3. Simpan ke config_cron.json
```

**Response JSON:**
```json
{
    "success": true,
    "enabled": true,
    "pemilik": ["PT. ABC"],
    "message": "Cron maintenance diaktifkan untuk: PT. ABC"
}
```

---

### **D. Toggle Handler - pelanggan_menunggak.php** (Dismantle Cron)

**Endpoint AJAX:** `toggle_cron_dismantle`

**Logika:** Sama dengan maintenance, tapi untuk dismantle cron

**Special Case - ASSISTANT:**
- ASSISTANT tidak punya direct PEMILIK
- Sistem query PEMILIK dari server yang AREA-nya sesuai dengan zone ASSISTANT
```php
SELECT DISTINCT PEMILIK FROM server WHERE AREA IN (area_list ASSISTANT)
```

---

## Setup Crontab di Server (Manual - SSH)

⚠️ **PENTING:** Toggle UI **TIDAK** otomatis register ke crontab. Harus manual via SSH.

### **Step 1: Login ke Server**
```bash
ssh user@your-server.com
```

### **Step 2: Edit Crontab untuk user www-data**
```bash
sudo crontab -u www-data -e
```

### **Step 3: Tambahkan 2 baris cron entries**

```cron
# Cron Maintenance Ticket - setiap 2 jam
0 */2 * * * /usr/bin/php /var/www/quenbytekniksejahtera/crm/billing/cron/cron_maintenance_ticket.php >> /var/www/quenbytekniksejahtera/crm/billing/cron/cron_maintenance.log 2>&1

# Cron Dismantle Ticket - setiap 2 jam
0 */2 * * * /usr/bin/php /var/www/quenbytekniksejahtera/crm/billing/cron/cron_dismantle_ticket.php >> /var/www/quenbytekniksejahtera/crm/billing/cron/cron_dismantle.log 2>&1
```

### **Step 4: Verifikasi Crontab**
```bash
sudo crontab -u www-data -l
```

Output harus menampilkan kedua baris di atas.

---

## Cara Menggunakan dari UI

### **1. Maintenance Cron (Billing → Tables Page)**

**Navigasi:**
```
CRM > Billing > Tabel Server
```

**Lihat Card:** "Cron Tiket Maintenance Otomatis"

**Tombol Toggle:**
- ✅ ON: Aktivasi untuk server Anda
- ❌ OFF: Nonaktifkan

**Hasil:**
- Config akan update `cron_maintenance_ticket.enabled_by`
- Cron akan process hanya server/customer Anda

---

### **2. Dismantle Cron (Billing → Pelanggan Menunggak)**

**Navigasi:**
```
CRM > Billing > Pelanggan Menunggak
```

**Lihat Card:** "Cron Tiket Dismantle Otomatis" (di atas ADMIN section)

**Tombol Toggle:**
- ✅ ON: Aktivasi untuk pelanggan menunggak Anda
- ❌ OFF: Nonaktifkan

**Hasil:**
- Config akan update `cron_dismantle_ticket.enabled_by`
- Cron akan process hanya customer menunggak Anda

---

## Troubleshooting

### **1. Cron tidak berjalan meskipun sudah diaktifkan**

**Check:**
- Apakah crontab sudah di-register di server?
  ```bash
  sudo crontab -u www-data -l
  ```
- Apakah toggle di UI sudah diaktifkan?
  - Cek `config_cron.json`: `enabled_by` array harus punya nilai

### **2. Cron hanya process sebagian PEMILIK**

**Penyebab:** `enabled_by` hanya contains beberapa PEMILIK

**Solusi:** Setiap PEMILIK perlu toggle ON cron dari UI mereka masing-masing

### **3. Error Log Cron**

**Cek Log Files:**
```bash
# Maintenance log
tail -f /var/www/quenbytekniksejahtera/crm/billing/cron/cron_maintenance.log

# Dismantle log
tail -f /var/www/quenbytekniksejahtera/crm/billing/cron/cron_dismantle.log
```

**Common Errors:**
- `[ERROR] Cron sudah berjalan` → Cron sedang running, tunggu selesai
- `[INFO] Diproses untuk PEMILIK:` (empty) → Toggle belum diaktifkan
- `[ERROR] Gagal koneksi DB` → Check db credentials di `config.json`

### **4. MikroTik Tidak Bisa Dicek**

**Log:** `[WARNING] Gagal konek MikroTik X.X.X.X`

**Solusi:**
- Cek IP MikroTik di data server
- Cek username/password di server config
- Cek koneksi network dari server ke MikroTik

---

## Contoh Skenario

### **Skenario: Multi-PEMILIK**

**Peserta:**
- PEMILIK A (PT. ABC) → 3 servers, 50 customers
- PEMILIK B (PT. XYZ) → 2 servers, 30 customers

**Workflow:**

1. **ADMIN PT. ABC login, navigate ke Billing > Tables**
   - Toggle ON Maintenance Cron
   - Config: `cron_maintenance_ticket.enabled_by = ["PT. ABC"]`

2. **ADMIN PT. XYZ login, navigate ke Billing > Tables**
   - Toggle ON Maintenance Cron
   - Config: `cron_maintenance_ticket.enabled_by = ["PT. ABC", "PT. XYZ"]`

3. **Cron script berjalan (setiap 2 jam):**
   - Proses PT. ABC servers → 3 servers, 50 customers
   - Proses PT. XYZ servers → 2 servers, 30 customers
   - **Hanya 2 PEMILIK, bukan semua** ✅

4. **Log Output:**
   ```
   [INFO] Diproses untuk PEMILIK: PT. ABC, PT. XYZ
   [INFO] Server dimuat: 5
   [INFO] Pelanggan dimuat: 80
   ...
   ```

---

## Rollback / Disable

Jika perlu rollback ke struktur lama:

1. **Toggle OFF semua cron dari UI**
2. **Remove dari crontab server:**
   ```bash
   sudo crontab -u www-data -e
   # Delete kedua baris cron
   ```

3. **Atau manual edit config:**
   ```bash
   echo '{}' > /var/www/quenbytekniksejahtera/crm/billing/cron/config_cron.json
   ```

---

## Summary Checklist

- [ ] Update `config_cron.json` ke struktur baru ✅
- [ ] Update `cron_maintenance_ticket.php` dengan filter PEMILIK ✅
- [ ] Update `cron_dismantle_ticket.php` dengan filter PEMILIK ✅
- [ ] Update toggle handler di `tables.php` ✅
- [ ] Update toggle handler di `pelanggan_menunggak.php` ✅
- [ ] Setup crontab di server (manual SSH)
- [ ] Test: Activate maintenance cron dari UI
- [ ] Test: Activate dismantle cron dari UI
- [ ] Check log files untuk verifikasi
- [ ] Verifikasi hanya own PEMILIK yang diprocess

---

## File-File yang Berubah

| File | Perubahan |
|------|-----------|
| `crm/billing/cron/config_cron.json` | Struktur: boolean → object dengan enabled_by array |
| `crm/billing/cron/cron_maintenance_ticket.php` | Tambah filter PEMILIK di query, baca enabled_by |
| `crm/billing/cron/cron_dismantle_ticket.php` | Tambah filter PEMILIK di query, baca enabled_by |
| `crm/billing/tables.php` | Toggle handler: capture PEMILIK, update enabled_by |
| `crm/billing/pelanggan_menunggak.php` | Toggle handler: capture PEMILIK, update enabled_by |

---

**Last Updated:** May 20, 2026  
**Version:** 1.0 (Per-Owner Scoping)
