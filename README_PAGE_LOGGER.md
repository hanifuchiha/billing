# Sistem Logging Akses Halaman

## Deskripsi
Sistem ini mencatat setiap akses halaman yang dilakukan oleh pengguna di aplikasi billing. Log mencakup informasi seperti username, halaman yang diakses, IP address, user agent, dan waktu akses.

## File yang Dibuat

### 1. `page_logger.php`
File utama yang berisi fungsi logging. File ini di-include otomatis di `cek-sesi.php` sehingga setiap halaman yang diakses akan tercatat.

**Fitur:**
- Mencatat URL lengkap halaman
- Mencatat nama file halaman
- Mencatat IP address pengunjung
- Mencatat user agent (browser/device)
- Mencatat referer (halaman sebelumnya)
- Mencatat HTTP method (GET/POST)
- Silent fail: tidak mengganggu aplikasi jika terjadi error

### 2. `create_page_log_table.sql`
File SQL untuk membuat tabel dan struktur database.

**Isi:**
- Tabel `page_access_log` untuk menyimpan log
- View `v_page_access_log` untuk membaca log dengan format lebih mudah
- Stored Procedure `sp_clean_old_page_logs()` untuk membersihkan log lama (>90 hari)
- Event scheduler (opsional) untuk otomatis membersihkan log

### 3. `view_page_logs.php`
Halaman dashboard untuk melihat dan memfilter log akses.

**Fitur:**
- Filter berdasarkan username
- Filter berdasarkan nama halaman
- Filter berdasarkan tanggal
- Pilihan limit data (50, 100, 500, 1000)
- Statistik: Total akses, total user, total halaman, total IP
- Pembatasan akses: non-admin hanya bisa melihat log mereka sendiri

## Cara Instalasi

### Langkah 1: Buat Tabel Database
Jalankan SQL dari file `create_page_log_table.sql`:

```bash
mysql -u username -p database_name < create_page_log_table.sql
```

Atau copy-paste isi file ke phpMyAdmin.

### Langkah 2: Verifikasi File Sudah Terintegrasi
File `cek-sesi.php` sudah dimodifikasi untuk meng-include `page_logger.php`. Tidak perlu action tambahan.

### Langkah 3: Test
1. Login ke aplikasi
2. Buka beberapa halaman
3. Akses `view_page_logs.php` untuk melihat log

## Struktur Tabel

```sql
page_access_log:
- id (bigint, auto_increment)
- user_id (int)
- username (varchar 100)
- page_url (varchar 500)
- page_name (varchar 255)
- ip_address (varchar 50)
- user_agent (varchar 500)
- referer (varchar 500)
- method (varchar 10)
- access_time (datetime)
```

## Maintenance

### Membersihkan Log Lama Secara Manual
```sql
CALL sp_clean_old_page_logs();
```

### Mengaktifkan Auto-Clean (Event Scheduler)
Edit bagian comment di `create_page_log_table.sql`:

```sql
SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS `evt_clean_page_logs`
ON SCHEDULE EVERY 1 WEEK
DO CALL sp_clean_old_page_logs();
```

### Melihat Log Via Query
```sql
-- 10 akses terakhir
SELECT * FROM v_page_access_log LIMIT 10;

-- Log user tertentu
SELECT * FROM page_access_log WHERE username = 'nama_user' ORDER BY access_time DESC;

-- Log halaman tertentu
SELECT * FROM page_access_log WHERE page_name LIKE '%dashboard%' ORDER BY access_time DESC;

-- Statistik akses per user
SELECT username, COUNT(*) as total_access 
FROM page_access_log 
GROUP BY username 
ORDER BY total_access DESC;

-- Halaman paling sering diakses
SELECT page_name, COUNT(*) as total 
FROM page_access_log 
GROUP BY page_name 
ORDER BY total DESC 
LIMIT 10;
```

## Catatan Keamanan

1. **Data Sensitif**: Log menyimpan IP address dan user agent. Pastikan tabel ini hanya dapat diakses oleh admin.

2. **Privacy**: Pertimbangkan untuk membersihkan log lama secara berkala untuk kepatuhan GDPR/privacy.

3. **Performance**: Untuk aplikasi dengan traffic tinggi, pertimbangkan:
   - Menggunakan tabel dengan partisi berdasarkan tanggal
   - Menggunakan async logging
   - Membatasi panjang URL dan user agent

4. **Storage**: Monitor ukuran tabel dan rutin membersihkan log lama.

## Troubleshooting

### Log tidak tercatat
1. Cek apakah tabel `page_access_log` sudah dibuat
2. Cek permission MySQL user untuk INSERT ke tabel
3. Cek apakah file `page_logger.php` ada dan readable
4. Cek error log PHP/MySQL

### Aplikasi lambat setelah logging
1. Tambahkan index pada kolom yang sering di-query
2. Bersihkan log lama
3. Pertimbangkan async logging untuk traffic tinggi

### Tidak bisa akses view_page_logs.php
1. Pastikan sudah login
2. Periksa hak akses user
3. Pastikan file `view_page_logs.php` ada di folder billing

## Pengembangan Lebih Lanjut

Ide untuk enhancement:
- Export log ke CSV/Excel
- Grafik visualisasi akses per jam/hari/bulan
- Alert untuk aktivitas mencurigakan (banyak akses dari 1 IP)
- Logger untuk API calls
- Integration dengan tools monitoring eksternal

## Support

Untuk pertanyaan atau issue, hubungi tim development.
