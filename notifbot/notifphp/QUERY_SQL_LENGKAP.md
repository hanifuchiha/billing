# 📊 QUERY SQL LENGKAP - SISTEM CEK TAGIHAN HARIAN

## 📋 DAFTAR QUERY

1. [Query Ambil Data Pelanggan](#1-query-ambil-data-pelanggan)
2. [Query Pembayaran Terakhir](#2-query-pembayaran-terakhir)
3. [Query Cek Pembayaran Periode](#3-query-cek-pembayaran-periode)
4. [Query Ambil Total Sudah Bayar](#4-query-ambil-total-sudah-bayar)
5. [Query Ambil Total Belum Bayar](#5-query-ambil-total-belum-bayar)
6. [Query Ambil Riwayat Pembayaran](#6-query-ambil-riwayat-pembayaran)
7. [Query Ambil Data Server](#7-query-ambil-data-server)

---

## 1. QUERY AMBIL DATA PELANGGAN

### Fungsi:
Mengambil daftar SEMUA pelanggan yang akan dicek pembayarannya.

### Query:
```sql
SELECT 
    IDPEL,              -- Kode ID Pelanggan (unik)
    NAMA,               -- Nama lengkap pelanggan
    NOWA,               -- Nomor WhatsApp
    EMAIL,              -- Email pelanggan
    PAKET,              -- Nama paket yang diambil
    TANGGALPASANG,      -- Tanggal pelanggan dipasang
    TIPE_BAYAR,         -- Tipe: PRABAYAR atau PASCABAYAR
    TIPE_TEMPO,         -- Tipe: mengikuti_tanggal_bayar atau mengikuti_tanggal_tempo
    TEMPO               -- Tanggal expiry untuk tipe tempo
FROM 
    pelanggan
WHERE 
    PEMILIK = ?         -- Filter berdasarkan pemilik (FIBERQ, dll)
    AND AREA = ?        -- Filter berdasarkan area
;
```

### Penjelasan:
- Mengambil informasi detail setiap pelanggan
- `PEMILIK` = nama server Mikrotik (misal: FIBERQ)
- `AREA` = area/wilayah geografis
- Semua pelanggan di ambil untuk dicek statusnya
- Kolom `PAKET` penting untuk mengembalikan profil saat nyalakan akses

### Contoh Hasil:
```
IDPEL        | NAMA            | NOWA         | PAKET    | TANGGAL_PASANG | TIPE_BAYAR  | TIPE_TEMPO
FIBERQ001    | Rudi Hartono    | 08123456789  | 5 Mbps   | 2026-03-01     | PRABAYAR    | mengikuti_tanggal_bayar
FIBERQ002    | Siti Nurhaliza   | 08987654321  | 10 Mbps  | 2026-02-15     | PASCABAYAR  | mengikuti_tanggal_tempo
FIBERQ003    | Budi Santoso    | 08111111111  | 3 Mbps   | 2026-01-20     | PRABAYAR    | mengikuti_tanggal_bayar
```

---

## 2. QUERY PEMBAYARAN TERAKHIR

### Fungsi:
Mencari kapan pelanggan terakhir kali membayar tagihan.

### Query:
```sql
SELECT 
    MAX(waktu) AS waktu_terakhir    -- Tanggal & waktu pembayaran terakhir
FROM 
    transaksi
WHERE 
    STATUS = 'BERHASIL'             -- Hanya pembayaran yang berhasil
    AND IDPEL = ?                   -- Untuk pelanggan tertentu
;
```

### Penjelasan:
- Mencari pembayaran dengan status `BERHASIL` (pembayaran tuntas)
- `MAX(waktu)` = waktu pembayaran paling baru (terbaru)
- Menggunakan kolom `waktu` dari tabel transaksi
- Result bisa NULL jika belum pernah bayar

### Contoh Hasil:
```
IDPEL = FIBERQ001:
waktu_terakhir = 2026-03-04 10:30:00  ← Pembayaran terakhir di tanggal ini

IDPEL = FIBERQ002:
waktu_terakhir = NULL                 ← Belum pernah bayar
```

### Penggunaan di Aplikasi:
```php
$waktuBayarTerakhir = ambilPembayaranTerakhir($conn, $idPel);
// Result: "2026-03-04 10:30:00" atau null
```

---

## 3. QUERY CEK PEMBAYARAN PERIODE

### Fungsi:
Mengecek apakah pelanggan sudah bayar di periode tertentu (misalnya: April 2026).

### Query:
```sql
SELECT 
    COUNT(*) as jumlah              -- Hitung berapa transaksi ditemukan
FROM 
    transaksi
WHERE 
    STATUS = 'BERHASIL'             -- Pembayaran yang berhasil
    AND PENGUNAAN = ?               -- Periode tertentu (mis: "April 2026")
    AND IDPEL = ?                   -- Untuk pelanggan tertentu
;
```

### Penjelasan:
- `COUNT(*)` = menghitung jumlah transaksi
- `PENGUNAAN` = periode tagihan (format: "Januari 2026", "April 2026", dll)
- Jika COUNT > 0 = sudah bayar di periode ini
- Jika COUNT = 0 = belum bayar di periode ini

### Contoh Hasil:
```
Periode: April 2026, IDPEL: FIBERQ001
jumlah = 1                          ← Sudah bayar 1 kali di April 2026

Periode: April 2026, IDPEL: FIBERQ002
jumlah = 0                          ← Belum bayar di April 2026
```

### Penggunaan di Aplikasi:
```php
$sudahBayarPeriode = cekPembayaranPeriode($conn, $idPel, $periode);
// Result: true (sudah bayar) atau false (belum bayar)
```

---

## 4. QUERY AMBIL TOTAL SUDAH BAYAR

### Fungsi:
Menghitung berapa TOTAL pelanggan yang sudah membayar di periode aktif.

### Query:
```sql
SELECT 
    COUNT(*) as total_sudah_bayar
FROM 
    transaksi t
INNER JOIN 
    pelanggan p ON t.IDPEL = p.IDPEL
WHERE 
    t.STATUS = 'BERHASIL'           -- Pembayaran berhasil
    AND t.HARGA != '0'              -- Bukan pembayaran 0 rupiah
    AND t.PENGUNAAN = ?             -- Periode aktif (April 2026)
    AND p.PEMILIK IN (...)          -- Server yang dimiliki user
    AND p.AREA IN (...)             -- Area yang dimiliki user
;
```

### Penjelasan:
- `INNER JOIN pelanggan` = gabung data pelanggan untuk filter area
- `COUNT(*)` = hitung jumlah transaksi
- `STATUS = 'BERHASIL'` = hanya yang berhasil dibayar
- `HARGA != '0'` = tidak termasuk pembayaran gratis
- `PENGUNAAN = ?` = hanya periode yang sedang dicek
- Filter `PEMILIK IN` dan `AREA IN` = hanya milik user yang login

### Contoh Hasil:
```
Periode: April 2026, Pemilik: FIBERQ, Area: JATINANGOR
total_sudah_bayar = 45              ← 45 pelanggan sudah bayar

Periode: April 2026, Pemilik: FIBERQ, Area: CIFAHSI
total_sudah_bayar = 32              ← 32 pelanggan sudah bayar
```

### SQL Lengkap (di Aplikasi):
```sql
$periode_sql = mysqli_real_escape_string($conn, "April 2026");
$userServerList = "'FIBERQ','FIBERQ2'";  -- Server milik user
$userAreaList = "'JATINANGOR','CIFAHSI'";

$sql_sudah_bayar = "SELECT COUNT(*) as total 
FROM transaksi t 
INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
WHERE t.STATUS = 'BERHASIL' AND t.HARGA != '0'
AND t.PENGUNAAN = '$periode_sql'
AND p.PEMILIK IN ($userServerList) 
AND p.AREA IN ($userAreaList)";
```

---

## 5. QUERY AMBIL TOTAL BELUM BAYAR

### Fungsi:
Menghitung berapa TOTAL pelanggan yang BELUM membayar di periode aktif.

### Query:
```sql
SELECT 
    COUNT(*) as total_belum_bayar
FROM 
    transaksi t
INNER JOIN 
    pelanggan p ON t.IDPEL = p.IDPEL
WHERE 
    t.STATUS = 'PENAGIHAN'          -- Status belum bayar/dalam penagihan
    AND t.HARGA != '0'              -- Bukan pembayaran 0 rupiah
    AND t.PENGUNAAN = ?             -- Periode aktif (April 2026)
    AND p.PEMILIK IN (...)          -- Server yang dimiliki user
    AND p.AREA IN (...)             -- Area yang dimiliki user
;
```

### Penjelasan:
- `STATUS = 'PENAGIHAN'` = status menunjukkan belum dibayar
- Sama seperti query SUDAH_BAYAR, tapi `STATUS` berbeda
- Kolom `STATUS` di tabel `transaksi` bisa bernilai:
  - `BERHASIL` = pembayaran sudah masuk
  - `PENAGIHAN` = masih dalam penagihan (belum bayar)
  - `BATAL` = pembayaran dibatalkan
  - `PROSES` = pembayaran sedang diproses

### Contoh Hasil:
```
Periode: April 2026, Pemilik: FIBERQ, Area: JATINANGOR
total_belum_bayar = 12              ← 12 pelanggan belum bayar

Periode: April 2026, Pemilik: FIBERQ, Area: CIFAHSI
total_belum_bayar = 8               ← 8 pelanggan belum bayar
```

### SQL Lengkap (di Aplikasi):
```sql
$periode_sql = mysqli_real_escape_string($conn, "April 2026");
$userServerList = "'FIBERQ','FIBERQ2'";
$userAreaList = "'JATINANGOR','CIFAHSI'";

$sql_belum_bayar = "SELECT COUNT(*) as total 
FROM transaksi t 
INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
WHERE t.STATUS = 'PENAGIHAN' AND t.HARGA != '0'
AND t.PENGUNAAN = '$periode_sql'
AND p.PEMILIK IN ($userServerList) 
AND p.AREA IN ($userAreaList)";
```

---

## 6. QUERY AMBIL RIWAYAT PEMBAYARAN

### Fungsi:
Mengambil semua riwayat pembayaran untuk satu pelanggan di semua periode.

### Query:
```sql
SELECT 
    IDPEL,                          -- ID Pelanggan
    PENGUNAAN,                      -- Periode (mis: April 2026)
    TANGGALBAYAR,                   -- Tanggal pembayaran
    waktu,                          -- Waktu pembayaran (timestamp)
    HARGA,                          -- Jumlah pembayaran
    STATUS                          -- Status: BERHASIL / PENAGIHAN / BATAL
FROM 
    transaksi
WHERE 
    IDPEL = ?                       -- Untuk pelanggan tertentu
    AND STATUS = 'BERHASIL'         -- Hanya pembayaran berhasil
ORDER BY 
    waktu DESC                      -- Sortir dari terbaru ke terlama
;
```

### Penjelasan:
- `ORDER BY waktu DESC` = tampilkan pembayaran terbaru di atas
- Berguna untuk melihat riwayat lengkap pelanggan
- Bisa melihat kapan terakhir bayar, berapa jumlah, dll

### Contoh Hasil:
```
IDPEL    | PENGUNAAN     | TANGGAL_BAYAR | HARGA      | STATUS
FIBERQ01 | Maret 2026    | 2026-03-04    | 250000     | BERHASIL
FIBERQ01 | Februari 2026 | 2026-02-03    | 250000     | BERHASIL
FIBERQ01 | Januari 2026  | 2026-01-05    | 250000     | BERHASIL
FIBERQ01 | Desember 2025 | 2025-12-02    | 250000     | BERHASIL
```

---

## 7. QUERY AMBIL DATA SERVER

### Fungsi:
Mengambil daftar semua server Mikrotik yang dimiliki user.

### Query:
```sql
SELECT 
    *                               -- Ambil semua kolom
FROM 
    server
WHERE 
    user_id = ?                     -- Filter berdasarkan user yang login
;
```

### Output Kolom Penting:
```
PEMILIK      | Nama server (unik), misal: FIBERQ
AREA         | Wilayah, misal: JATINANGOR
IP           | IP address Mikrotik, misal: 192.168.1.1
PASSWORD     | Password login Mikrotik
BRAND        | Brand/label server
```

### Penjelasan:
- Setiap user bisa memiliki multiple server
- Query ini mengambil semua server milik user tersebut
- Dari data ini kita nanti koneksi ke Mikrotik satu per satu
- IP dan PASSWORD digunakan untuk connect ke RouterOS API

---

## 📊 FLOW LENGKAP PENGHITUNGAN

### Step-by-Step Proses:

```
1. USER LOGIN KE APLIKASI
   ↓
2. QUERY AMBIL SEMUA SERVER (Query #7)
   ↓
3. UNTUK SETIAP SERVER:
   ├─ Query ambil data pelanggan (Query #1)
   ├─ Untuk setiap pelanggan:
   │  ├─ Query ambil pembayaran terakhir (Query #2)
   │  ├─ Query cek pembayaran periode (Query #3)
   │  └─ [LOGIKA] Tentukan: SUDAH BAYAR atau BELUM BAYAR
   └─ Simpan hasilnya ke array
   ↓
4. HITUNG TOTAL (Query #4 & #5)
   ├─ Total pelanggan diperiksa
   ├─ Total sudah bayar
   └─ Total belum bayar
   ↓
5. TAMPILKAN DI HALAMAN BROWSER
   ├─ Statistik card (4 kartu)
   ├─ Daftar pelanggan belum bayar
   └─ Tombol action (matikan/nyalakan)
```

---

## 🔍 CONTOH LOGIKA PENGECEKAN LENGKAP

### Tipe: MENGIKUTI_TANGGAL_BAYAR

```
Input:
- Pelanggan: FIBERQ001
- Tipe Bayar: PRABAYAR
- Tipe Tempo: mengikuti_tanggal_bayar
- Tanggal Pasang: 2026-03-01
- Hari Ini: 2026-04-04

Proses:
1. Query: Ambil pembayaran terakhir
   Result: 2026-03-04 10:30:00

2. Hitung jatuh tempo = pembayaran_terakhir + 30 hari
   = 2026-03-04 + 30 hari
   = 2026-04-03

3. Bandingkan: Hari ini (2026-04-04) vs Jatuh tempo (2026-04-03)
   2026-04-04 > 2026-04-03 ✗ SUDAH LEWAT

4. KESIMPULAN: BELUM BAYAR (Tertunggak 1 hari)
   Tertampilkan di list pelanggan belum bayar
```

### Tipe: MENGIKUTI_TANGGAL_TEMPO

```
Input:
- Pelanggan: FIBERQ002
- Tipe Tempo: mengikuti_tanggal_tempo
- Field TEMPO (Expiry): 2026-04-05
- Hari Ini: 2026-04-04

Proses:
1. Bandingkan: Hari ini (2026-04-04) vs TEMPO (2026-04-05)
   2026-04-04 < 2026-04-05 ✓ BELUM LEWAT

2. Query: Cek pembayaran periode "April 2026"
   Result: 1 (ada 1 transaksi BERHASIL)

3. KESIMPULAN: SUDAH BAYAR (Pembayaran sudah diterima)
   Tidak tampil di list belum bayar
```

---

## 📈 STATISTIK YANG DITAMPILKAN

### Statistik Ringkas (4 Kartu):
```
┌────────────────────────────────────────────┐
│  Total Pelanggan: [Hitung dari Query #1]   │
│  Sudah Bayar:     [Hitung dari Query #4]   │
│  Belum Bayar:     [Hitung dari Query #5]   │
│  Periode Aktif:   [Hitung dari Periode]   │
└────────────────────────────────────────────┘
```

### Contoh Output Statistik:
```
Total Pelanggan: 57 pelanggan
Sudah Bayar:     45 pelanggan  (78,9%)
Belum Bayar:     12 pelanggan  (21,1%)
Periode Aktif:   April 2026
```

---

## 🔐 KEAMANAN QUERY

### Best Practice yang Digunakan:

1. **Prepared Statement (Parameterized Query)**
   ```php
   $stmt = $conn->prepare("SELECT * FROM pelanggan WHERE PEMILIK = ? AND AREA = ?");
   $stmt->bind_param("ss", $pemilik, $area);
   $stmt->execute();
   ```
   ✓ Mencegah SQL Injection
   ✓ Lebih aman dan cepat

2. **Real Escape String (untuk query kompleks)**
   ```php
   $periode_sql = mysqli_real_escape_string($conn, $periode);
   ```
   ✓ Escape karakter khusus
   ✓ Koneksi langsung dengan DB character set

3. **Filter User (Authorization)**
   ```sql
   WHERE p.PEMILIK IN (server_milik_user)
   ```
   ✓ User hanya bisa lihat data miliknya
   ✓ Tidak bisa lihat data user lain

---

## 📝 CATATAN PENTING

1. **Kolom PENGUNAAN**: Format "Januari 2026", "Februari 2026", dll (Bahasa Indonesia)
2. **Kolom STATUS**: BERHASIL (bayar), PENAGIHAN (belum), BATAL, PROSES
3. **Kolom TIPE_BAYAR**: PRABAYAR atau PASCABAYAR
4. **Kolom TIPE_TEMPO**: mengikuti_tanggal_bayar atau mengikuti_tanggal_tempo
5. **Join Table**: transaksi dengan pelanggan lewat kolom IDPEL

---

**Terakhir Update: 04 April 2026**
**Versi: 1.0 - Query Lengkap**
