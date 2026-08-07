# Isolir & Forwarding Configuration - Admin Guide

## 📋 Daftar Isi
1. [Pengenalan](#pengenalan)
2. [Instalasi Database](#instalasi-database)
3. [Konfigurasi Server](#konfigurasi-server)
4. [Cara Kerja Sistem](#cara-kerja-sistem)
5. [Step-by-Step Setup](#step-by-step-setup)
6. [Troubleshooting](#troubleshooting)

---

## 🎯 Pengenalan

Fitur **Isolir & Forwarding** memungkinkan Anda untuk:
- ✅ Mengatur isolir customer yang belum membayar
- ✅ Mengarahkan traffic HTTP mereka ke halaman billing
- ✅ Membatasi akses internet mereka sambil tetap bisa akses website pembayaran
- ✅ Kelola hingga **unlimited server MikroTik**

### Cara Kerja
Ketika customer terisolir:
1. IP customer ditambahkan ke address-list `expired_user`
2. Traffic HTTP user diarahkan ke server billing
3. Semua traffic lain diblokir (DROP)
4. User hanya bisa akses halaman pembayaran
5. Setelah bayar, status diaktifkan kembali

---

## 💾 Instalasi Database

### Opsi 1: Via phpMyAdmin
1. Buka **phpMyAdmin** di browser
2. Pilih database billing Anda
3. Klik tab **SQL**
4. Copy-paste isi file `isolir_setup.sql`
5. Klik **Go** untuk eksekusi

### Opsi 2: Via Command Line
```bash
mysql -u your_user -p your_database < isolir_setup.sql
```

### Tabel yang Dibuat:
- `isolir_config` - Menyimpan konfigurasi server MikroTik
- `isolir_sync_log` - Log sinkronisasi rules
- `isolated_customers` - Data customer yang terisolir

---

## ⚙️ Konfigurasi Server

### Akses Menu Admin
1. Login sebagai **ADMIN**
2. Buka **Sidebar → ADMINISTRATOR PANEL → Isolir & Forwarding**
3. Atau akses langsung: `/crm/billing/isolir_forwarding.php`

### Parameter Yang Diperlukan

#### 1. Nama Server
```
Contoh: Server Pusat, Server Cabang 1
```
Gunakan nama unik untuk membedakan server

#### 2. IP Address Server
```
Contoh: 192.168.1.1
```
IP MikroTik RouterOS Anda (yang ingin dikonfigurasi)

#### 3. Username & Password
```
Username: admin
Password: ***
```
Kredensial login MikroTik (sebaiknya buat user khusus dengan permission terbatas)

#### 4. IP Subnet Expired User
```
Contoh: 10.15.0.0/22
```
Subnet IP yang digunakan untuk customer Anda. **PENTING**: Sesuaikan dengan konfigurasi network Anda!

Untuk mengetahui subnet Anda:
- Di MikroTik: `/ip pool print`
- Lihat range IP pool yang digunakan

#### 5. IP Server Billing
```
Contoh: 31.57.178.141
```
IP publik server website billing Anda

---

## 🔧 Cara Kerja Sistem

### Firewall Rules yang Dibuat

#### 1. Address List (expired_user)
```
/ip firewall address-list
add list=expired_user address=10.15.0.0/22
```
**Fungsi**: Membuat list IP untuk customer yang terisolir

#### 2. Allow Billing Server (Filter)
```
/ip firewall filter
add chain=forward src-address-list=expired_user dst-address=31.57.178.141 action=accept comment="Allow expired user to billing"
```
**Fungsi**: Izinkan traffic ke server billing saja

#### 3. Redirect HTTP (NAT)
```
/ip firewall nat
add chain=dstnat src-address-list=expired_user protocol=tcp dst-port=80 action=dst-nat to-addresses=31.57.178.141 to-ports=80 comment="Redirect expired user"
```
**Fungsi**: Arahkan semua HTTP request ke server billing

#### 4. Block Internet Access (Filter)
```
/ip firewall filter
add chain=forward src-address-list=expired_user action=drop comment="Block expired users"
```
**Fungsi**: Block semua traffic lain (selain yang sudah diallow dan redirect)

### ⚠️ PENTING - Urutan Rule

Rules **HARUS** dijalankan dalam urutan ini:

```
1. Address List (expired_user) - PERTAMA
2. Allow Filter (billing server) - KEDUA  
3. NAT Redirect (HTTP) - KETIGA
4. Block Filter (drop) - KEEMPAT
```

**ALASAN**: Jika rule block dijalankan lebih dulu, traffic ke billing juga bakal terblokir!

---

## 📝 Step-by-Step Setup

### Langkah 1: Buat User MikroTik (Opsional tapi Sangat Disarankan)

Di MikroTik, buat user khusus untuk automation:

```
/user
add name=automation password=StrongPassword123 group=full
```

**Tips**: Gunakan password yang kuat untuk keamanan!

### Langkah 2: Setup Database

Jalankan file `isolir_setup.sql` seperti dijelaskan di atas.

### Langkah 3: Tambah Server Configuration

1. Masuk ke menu **Isolir & Forwarding** (Admin Panel)
2. Isi form "Tambah Konfigurasi Server Baru":
   - **Nama Server**: Server Pusat Jabodetabek
   - **IP Address**: 192.168.1.1
   - **Username**: automation
   - **Password**: StrongPassword123
   - **IP Subnet Expired**: 10.15.0.0/22 *(sesuaikan dengan subnet Anda)*
   - **IP Server Billing**: 31.57.178.141 *(sesuaikan dengan IP server Anda)*
3. Klik **Simpan Konfigurasi**

### Langkah 4: Verify & Sync Rules

1. Pastikan server muncul di "Daftar Server Terkonfigurasi"
2. Review rules yang akan dibuat (ditampilkan dalam kartu server)
3. Klik **Sinkronisasi Rules** untuk mengirim langsung ke MikroTik

### Langkah 5: Test

#### Test Manual di MikroTik
```
# Cek address list sudah dibuat
/ip firewall address-list print

# Cek filter rules
/ip firewall filter print

# Cek NAT rules
/ip firewall nat print
```

#### Test dengan Customer
1. Tentukan 1 IP customer sebagai test (misalnya: 10.15.0.10)
2. Isolir customer tersebut via query:
   ```sql
   INSERT INTO isolated_customers (customer_id, server_id, ip_address, status)
   VALUES (123, 1, '10.15.0.10', 'isolated');
   ```
3. Update address list di MikroTik untuk include IP tersebut
4. Coba browse dari IP itu - seharusnya redirect ke halaman billing

---

## 🐛 Troubleshooting

### Masalah 1: Customer Bisa Browse Normal (Isolir Tidak Bekerja)

**Penyebab**: Address list belum diupdate atau rules tidak ada

**Solusi**:
1. Cek di MikroTik apakah address list `expired_user` ada
   ```
   /ip firewall address-list print
   ```
2. Jika belum ada, jalankan manual atau klik **Sinkronisasi Rules** lagi
3. Pastikan IP customer benar-benar ada dalam subnet yang dikonfigurasi

### Masalah 2: Customer Tidak Bisa Akses Halaman Billing

**Penyebab**: NAT atau filter rule salah, atau IP server billing salah

**Solusi**:
1. Verifikasi IP server billing sudah benar
2. Cek NAT rules:
   ```
   /ip firewall nat print
   ```
3. Test ping ke server billing dari customer IP:
   ```
   ping serverip
   ```

### Masalah 3: Semua Customer Terisolir (Rules Error)

**Penyebab**: Address list tidak sengaja include semua subnet

**Solusi**:
1. **JANGAN PANIC** - rules bisa dihapus/edit manual
2. Di MikroTik, hapus rules yang keliru:
   ```
   /ip firewall address-list remove numbers=0
   /ip firewall filter remove numbers=5,6
   /ip firewall nat remove numbers=3
   ```
3. Reconfigure dari awal atau hubungi support

### Masalah 4: Tidak Bisa Connect ke MikroTik dari Admin Panel

**Penyebab**: Kredensial salah atau firewall MikroTik

**Solusi**:
1. Cek kredensial username/password
2. Pastikan API service aktif di MikroTik:
   ```
   /ip service print
   # Cari "api" dan pastikan disabled=no
   /ip service enable api
   ```
3. Pastikan firewall MikroTik allow port 8728 (API port)

---

## 📌 Catatan Penting

### Keamanan
- ⚠️ **Jangan simpan password MikroTik di bentuk plaintext**
- Gunakan user khusus dengan permission terbatas untuk automation
- Gunakan HTTPS untuk admin panel
- Update file ini dengan IP/subnet yang benar sebelum production

### Performance
- Sistem isolir & forwarding lightweight dan tidak mempengaruhi kinerja MikroTik
- Address list dapat handle ribuan IP address

### Backup
- Backup konfigurasi MikroTik secara berkala
- Backup database billing sebelum add/delete server config

---

## 📞 Support

Jika mengalami masalah:
1. Cek troubleshooting section di atas
2. Verify semua konfigurasi sudah benar
3. Check MikroTik logs: `/log print`
4. Hubungi technical support dengan detail masalah

---

**Last Updated**: 2026-03-11  
**Version**: 1.1 (Updated with EXPIRED address-list and rule order verification)
