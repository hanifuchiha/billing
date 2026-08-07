# 📚 DOKUMENTASI LENGKAP: PEMBACAAN & PEMROSESAN DATA

## 🎯 RINGKASAN SCRIPT
Script `cek_tagihan_harian_FIBERQ.php` adalah sistem otomatis harian untuk:
- ✅ Memeriksa status pembayaran pelanggan
- ✅ Mengidentifikasi pelanggan yang **BELUM BAYAR**
- ✅ Mengidentifikasi pelanggan yang **SUDAH BAYAR**
- ✅ Melakukan aksi di Mikrotik (set profile EXPIRED, restore profile)
- ✅ Menghasilkan laporan Bootstrap HTML + laporan CLI

---

## 📖 TAHAP PEMBACAAN DATA

### **TAHAP 1: IDENTIFIKASI PEMILIK**
```
File: cek_tagihan_harian_FIBERQ.php
Nama file diparsing → FIBERQ
```

**Output CLI:**
```
=== MULAI PROSES CEK TAGIHAN HARIAN ===
Waktu: 2026-04-04 14:30:45
Pemilik: FIBERQ
```

---

### **TAHAP 2: LOAD KONFIGURASI**

#### 2a. Load dari `config.json` (domain & URL)
```json
{
  "domain": "https://quenbytekniksejahtera.com"
}
```

#### 2b. Load dari `reminder-FIBERQ.json` (konfigurasi tutup buku)
```json
[{
  "jatuh_tempo": 25,                    // Hari jatuh tempo setiap bulan
  "hari_sebelum": 3,                    // Ingatkan 3 hari sebelumnya
  "tanggal_awal_tutup_buku": 24,        // Awal window tutup buku
  "tanggal_akhir_tutup_buku": 5         // Akhir window tutup buku
}]
```

**Output:**
```
Konfigurasi reminder dimuat: jatuh_tempo hari ke-25, tutup buku 24–5
```

---

### **TAHAP 3: LOAD DATA USER & SERVER**

#### Query 1: Cari User berdasarkan Username
```sql
SELECT * FROM `user` WHERE `USERNAME` = 'FIBERQ' LIMIT 1
```

**Hasil:**
```
id:          123
USERNAME:    FIBERQ
IDPEL:       FIBERQ-001
```

**Output:**
```
ID User: 123 | Username: FIBERQ
```

---

#### Query 2: Cari semua Server milik User
```sql
SELECT PEMILIK, AREA FROM server WHERE user_id = 123
```

**Hasil:**
```
Row 1: PEMILIK='FIBERQ', AREA='CIBINONG_JAWA_BARAT'
Row 2: PEMILIK='FIBERQ', AREA='TAJURHALANG_SUKMA_JAYA_JAWA_BARAT'
Row 3: PEMILIK='FIBERQ', AREA='WATI_HUJA_CIBINONG_PERCH'
```

**Hasil Processing:**
```
$userServerList = 'FIBERQ','FIBERQ','FIBERQ'
$userAreaList = 'CIBINONG_JAWA_BARAT','TAJURHALANG_SUKMA_JAYA_JAWA_BARAT','WATI_HUJA_CIBINONG_PERCH'
```

**Output:**
```
--- Server: FIBERQ | Area: CIBINONG_JAWA_BARAT ---
Mikrotik: CONNECTED (timeout 10 detik)
```

---

### **TAHAP 4: QUERY DATA PELANGGAN PER SERVER**

```sql
SELECT IDPEL, NAMA, NOWA, EMAIL, PAKET, ALAMAT, TANGGALPASANG,
       TIPE_BAYAR, TIPE_TEMPO, TEMPO, MODE, ODP
FROM `pelanggan`
WHERE `PEMILIK` = 'FIBERQ' AND `AREA` = 'CIBINONG_JAWA_BARAT'
```

**Hasil Contoh (5 pelanggan):**
```
┌─────────────────────┬─────────────────────────────────┬──────────────┬─────────────┬──────────────────────┐
│ IDPEL               │ NAMA                            │ TANGGALPASANG│ TIPE_BAYAR  │ TIPE_TEMPO           │
├─────────────────────┼─────────────────────────────────┼──────────────┼─────────────┼──────────────────────┤
│ FQ-158@070325       │ ULAN WIDIAWATI                  │ 2025-03-25   │ prabayar    │ mengikuti_tanggal_bayar │
│ FQ-191@271125       │ Opsah                           │ 2025-11-25   │ prabayar    │ mengikuti_tanggal_bayar │
│ FQ-013@241123       │ Boan                            │ 2023-11-24   │ prabayar    │ mengikuti_tanggal_bayar │
│ FQ-045@280923       │ Rifka                           │ 2023-09-28   │ prabayar    │ mengikuti_tanggal_bayar │
│ FQ-041@241025       │ linda                           │ 2025-10-24   │ prasabayar  │ mengikuti_tanggal_tempo │
└─────────────────────┴─────────────────────────────────┴──────────────┴─────────────┴──────────────────────┘
```

---

### **TAHAP 5: QUERY RIWAYAT PEMBAYARAN**

#### Query 5a: Pembayaran Terakhir
```sql
SELECT `IDPEL`, MAX(`waktu`) AS `waktu_terakhir`
FROM `transaksi`
WHERE `STATUS` = 'BERHASIL' AND `IDPEL` IN (...)
GROUP BY `IDPEL`
```

**Hasil:**
```
FQ-158@070325:  2026-03-30 10:25:43  (bayar terakhir Maret 2026)
FQ-191@271125:  2026-03-22 14:15:22  (bayar terakhir Maret 2026)
FQ-013@241123:  2025-11-15 09:00:00  (bayar lama, belum bayar 2026)
FQ-045@280923:  2026-02-14 11:30:00  (bayar Februari)
FQ-041@241025:  2026-03-28 15:45:00  (bayar terakhir Maret 2026)
```

#### Query 5b: Transaksi di Periode Aktif (April 2026)
```sql
SELECT DISTINCT `IDPEL`
FROM `transaksi`
WHERE `STATUS` = 'BERHASIL'
  AND `PENGUNAAN` = 'April 2026'
  AND `IDPEL` IN (...)
```

**Hasil:**
```
FQ-158@070325    ← Ada transaksi Periode April 2026
FQ-191@271125    ← Ada transaksi Periode April 2026
FQ-013@241123    ← TIDAK ada transaksi Periode April 2026
FQ-045@280923    ← TIDAK ada transaksi Periode April 2026
FQ-041@241025    ← Ada transaksi Periode April 2026
```

---

## 🔄 TAHAP PEMROSESAN & LOGIKA KEPUTUSAN

### **KONFIGURASI SISTEM**
```
Hari Ini:                2026-04-04
Periode Aktif:           April 2026
Jatuh Tempo:             25 (setiap bulan)
Tutup Buku:              24 - 5
Hari Sebelum Reminder:   3
```

---

### **SKENARIO 1: PRABAYAR + MENGIKUTI TANGGAL BAYAR**

#### ✅ **Pelanggan: FQ-158@070325 (ULAN WIDIAWATI)**

**Data:**
```
TIPE_BAYAR:   prabayar
TIPE_TEMPO:   mengikuti_tanggal_bayar
Bayar terakhir: 2026-03-30
```

**Proses:**
```
1. Ambil bayar terakhir: 2026-03-30
2. Hitung jatuh tempo: 2026-03-30 + 30 hari = 2026-04-29
3. Cek: Hari ini (2026-04-04) < Jatuh tempo (2026-04-29)? YA
4. Status: SUDAH BAYAR ✅
```

**Output:**
```
✅ SUDAH BAYAR
Status Tagihan: SUDAH BAYAR
Jatuh Tempo: 2026-04-29
Pembayaran Terakhir: 2026-03-30 10:25:43
Keterangan: Pelanggan aktif, pembayaran terjaga
```

**Ditampilkan di Tabel:**
```
PELANGGAN SUDAH BAYAR [78]

| ID              | Nama                | Area          | Pembayaran Terakhir | Status Mik |
|-----------------|---------------------|---------------|---------------------|------------|
| FQ-158@070325   | ULAN WIDIAWATI      | CIBINONG...   | 2026-03-30 10:25:43 | OFFLINE    |
```

---

#### ❌ **Pelanggan: FQ-013@241123 (BOAN)**

**Data:**
```
TIPE_BAYAR:   prabayar
TIPE_TEMPO:   mengikuti_tanggal_bayar
Bayar terakhir: 2025-11-15 (LAMA)
```

**Proses:**
```
1. Ambil bayar terakhir: 2025-11-15
2. Hitung jatuh tempo: 2025-11-15 + 30 hari = 2025-12-15
3. Cek: Hari ini (2026-04-04) > Jatuh tempo (2025-12-15)? YA
4. Hitung overdue: (2026-04-04) - (2025-12-15) = 111 hari
5. Status: BELUM BAYAR ❌
```

**Output:**
```
❌ BELUM BAYAR
Status Tagihan: BELUM BAYAR
Jatuh Tempo: 2025-12-15
Overdue: 111 hari
Pembayaran Terakhir: 2025-11-15 09:00:00
Keterangan: Terakhir bayar: 2025-11-15 | Jatuh tempo: 2025-12-15 | Overdue: 111 hari
```

**Aksi Mikrotik:**
```
1. Baca status di Mikrotik → Profile: 'HOMELINK_20MBPS'
2. Cek: Profile EXPIRED? TIDAK
3. Ubah profile → 'EXPIRED'
4. Putus koneksi aktif (jika ada)
```

**Ditampilkan di Tabel:**
```
PRABAYAR + MENGIKUTI TANGGAL BAYAR [15]

| ID              | Nama  | Area       | Jatuh Tempo | Status Mik | Mikrotik Aksi                    |
|-----------------|-------|------------|-------------|------------|----------------------------------|
| FQ-013@241123   | Boan  | CIBINONG...| 2025-12-15  | OFFLINE    | Profile secret diubah ke EXPIRED |
```

---

### **SKENARIO 2: PASCABAYAR + MENGIKUTI TANGGAL BAYAR**

#### ❌ **Pelanggan: Belum pernah bayar**

**Data:**
```
TIPE_BAYAR:   pascabayar
TIPE_TEMPO:   mengikuti_tanggal_bayar
Bayar terakhir: NULL (belum pernah)
Tanggal Pasang: 2025-10-24
```

**Proses:**
```
1. Cek: Bayar terakhir ada? TIDAK
2. Cek TIPE_BAYAR: pascabayar → beri toleransi 30 hari
3. Hitung jatuh tempo pertama: 2025-10-24 + 30 = 2025-11-23
4. Cek: Hari ini (2026-04-04) >= Jatuh tempo (2025-11-23)? YA
5. Status: BELUM BAYAR ❌ (sudah melewati toleransi)
```

**Output:**
```
❌ BELUM BAYAR
Jatuh Tempo Pertama: 2025-11-23
Keterangan: Pascabayar | Belum pernah bayar | Jatuh tempo pertama: 2025-11-23
```

---

### **SKENARIO 3: MENGIKUTI TANGGAL TEMPO**

#### Data Konfigurasi:
```
$jatuh_tempo_hari = 25      (Jatuh tempo setiap hari ke-25)
$hari_sebelum = 3           (Reminder 3 hari sebelumnya)
$TEMPO (field DB) = 2026-03-31  (Masa aktif sampai tanggal ini)
```

#### ❌ **Pelanggan: FQ-041@241025 (SUDAH LEWAT TEMPO)**

**Data:**
```
TIPE_TEMPO: mengikuti_tanggal_tempo
TEMPO: 2026-03-25 (SUDAH LEWAT)
Bayar Terakhir: 2026-03-28 (SETELAH TEMPO LEWAT)
```

**Proses:**
```
1. Cek: TEMPO (2026-03-25) <= Hari ini (2026-04-04)? YA, SUDAH LEWAT
2. Cek: Bayar terakhir (2026-03-28) >= TEMPO (2026-03-25)? YA
3. Kesimpulan: Pelanggan SUDAH BAYAR setelah TEMPO lewat ✅
```

**Output:**
```
✅ SUDAH BAYAR (Tempo sudah lewat, tapi bayar tepat waktu)
TEMPO Berakhir: 2026-03-25
Pembayaran: 2026-03-28 (3 hari setelah TEMPO lewat)
```

---

## 📊 STATISTIK & RINGKASAN

### **Query Statistik:**

#### Query 1: Count Sudah Bayar
```sql
SELECT COUNT(*) as total 
FROM transaksi t 
INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
WHERE t.STATUS = 'BERHASIL' AND t.HARGA != '0'
AND t.PENGUNAAN = 'April 2026'
AND p.PEMILIK IN ('FIBERQ') 
AND p.AREA IN ('CIBINONG_JAWA_BARAT', 'TAJURHALANG_SUKMA_JAYA_JAWA_BARAT', 'WATI_HUJA_CIBINONG_PERCH')
```

**Output:**
```
COUNT: 78 pelanggan ✅ SUDAH BAYAR
```

#### Query 2: Detail Sudah Bayar
```sql
SELECT p.IDPEL, p.NAMA, p.NOWA, p.EMAIL, p.PAKET, p.TIPE_BAYAR, p.AREA,
       COALESCE(t.TANGGALBAYAR, t.waktu) as pembayaran_terakhir
FROM transaksi t 
INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL 
WHERE t.STATUS = 'BERHASIL' AND t.HARGA != '0'
AND t.PENGUNAAN = 'April 2026'
AND p.PEMILIK IN ('FIBERQ') 
AND p.AREA IN (...)
ORDER BY COALESCE(t.TANGGALBAYAR, t.waktu) DESC
```

**Output (TOP 5):**
```
| IDPEL            | NAMA           | Pembayaran Terakhir | Status |
|------------------|----------------|---------------------|--------|
| FQ-158@070325    | ULAN WIDIAWATI | 2026-03-30 10:25:43 | ✅     |
| FQ-045@280923    | RIFKA          | 2026-03-28 15:45:00 | ✅     |
| FQ-191@271125    | Opsah          | 2026-03-22 14:15:22 | ✅     |
| FQ-041@241025    | linda          | 2026-03-20 09:30:00 | ✅     |
| ...              | ...            | ...                 | ...    |
```

---

## 📋 OUTPUT LAPORAN

### **Statistik Card:**
```
┌─────────────────┬─────────────┬──────────────┬──────────────────┐
│ Total Pelanggan │ Sudah Bayar │ Belum Bayar  │ Profil Dipulihkan │
├─────────────────┼─────────────┼──────────────┼──────────────────┤
│      244        │      78     │     150      │        16        │
└─────────────────┴─────────────┴──────────────┴──────────────────┘
```

### **Konfigurasi Ditampilkan:**
```
⚙️ Konfigurasi Tutup Buku:
  • Awal Tutup Buku: 24
  • Akhir Tutup Buku: 5

⚙️ Konfigurasi Jatuh Tempo:
  • Hari Jatuh Tempo: 25
  • Hari Sebelum: 3
```

### **6 Data Tabel Utama:**
```
1. 🔴 PRABAYAR + MENGIKUTI TANGGAL BAYAR (45)
2. 🔴 PASCABAYAR + MENGIKUTI TANGGAL BAYAR (55)
3. 🔴 PRABAYAR + MENGIKUTI TANGGAL TEMPO (30)
4. 🔴 PASCABAYAR + MENGIKUTI TANGGAL TEMPO (20)
5. ✅ PELANGGAN SUDAH BAYAR (78)
6. ✅ PEMULIHAN PROFILE MIKROTIK (16)
```

---

## 🔍 CONTOH LENGKAP: 1 PELANGGAN SEED-TO-HARVEST

### **Input Awal:**
```
IDPEL: FQ-191@271125
NAMA: Opsah
Pasang Tanggal: 2025-11-25
TIPE_BAYAR: prabayar
TIPE_TEMPO: mengikuti_tanggal_bayar
PAKET: MERDEKA 10 Rp 99.000
Riwayat Pembayaran:
  - 2025-11-29: Rp 99.000 (Verifed)
  - 2025-12-29: Rp 99.000 (Verified)
  - 2026-01-29: Rp 99.000 (Verified)
  - 2026-02-27: Rp 99.000 (Verified)
  - 2026-03-22: Rp 99.000 (Verified) ← Pembayaran terakhir
```

### **Proses di Script:**
```
STEP 1: Load data pelanggan
  ✓ IDPEL: FQ-191@271125
  ✓ NAMA: Opsah
  ✓ TIPE_TEMPO: mengikuti_tanggal_bayar

STEP 2: Query riwayat pembayaran
  ✓ Pembayaran terakhir: 2026-03-22

STEP 3: Tentukan jatuh tempo
  ✓ 2026-03-22 + 30 hari = 2026-04-21

STEP 4: Cek status
  ✓ Hari ini (2026-04-04) < Jatuh tempo (2026-04-21)? YA
  ✓ Status: SUDAH BAYAR ✅

STEP 5: Baca status Mikrotik
  ✓ Status: ONLINE
  ✓ Profile: MERDEKA_10MBPS

STEP 6: Tentukan aksi
  ✓ Pelanggan aktif, bukan EXPIRED
  ✓ Aksi: Tidak ada (profile sudah benar)

STEP 7: Simpan ke statistik
  ✓ statistik['pelanggan_sudah_bayar'][] = [
      'IDPEL' => 'FQ-191@271125',
      'NAMA' => 'Opsah',
      'pembayaran_terakhir' => '2026-03-22',
      'jatuh_tempo' => '2026-04-21',
      'mikrotik_status' => 'ONLINE',
      'mikrotik_profile' => 'MERDEKA_10MBPS'
    ]
```

### **Output Akhir:**
```
HTML TABEL - PELANGGAN SUDAH BAYAR:
┌────┬──────────────┬────────────────┬──────────────┬──────────────┬───────────────────────┐
│ No | ID           | Nama           | Area         | Pembayaran   │ Status Mik / Profile  │
├────┼──────────────┼────────────────┼──────────────┼──────────────┼───────────────────────┤
│ 2  | FQ-191@...   | Opsah          | CIBINONG...  | 2026-03-22   | ONLINE / MERDEKA_10MB │
└────┴──────────────┴────────────────┴──────────────┴──────────────┴───────────────────────┘

CLI OUTPUT:
  ✅ Pelanggan sudah bayar ditemukan
  Jatuh tempo: 2026-04-21
  Mikrotik Status: ONLINE
  Tidak ada aksi yang diperlukan
```

---

## 📝 RINGKASAN TIM PROSES

```
┌─────────────────────────────────────────────────────────────┐
│                    ALUR PEMBACAAN DATA                       │
├─────────────────────────────────────────────────────────────┤
│ 1. Identifikasi Pemilik (dari nama file)                    │
│ 2. Load Konfigurasi (config.json, reminder.json)            │
│ 3. Load User & Server Devices                               │
│ 4. Query Pelanggan per Server/Area                          │
│ 5. Query Riwayat Pembayaran (transaksi.STATUS=BERHASIL)     │
│ 6. Query Transaksi Periode Aktif (untuk cek restorasi)      │
│ 7. Loop setiap pelanggan:                                   │
│    a. Tentukan tipe tempo (30 hari / TEMPO field)           │
│    b. Hitung jatuh tempo                                    │
│    c. Cek: Belum Bayar vs Sudah Bayar                       │
│    d. Baca status Mikrotik                                  │
│    e. Lakukan aksi Mikrotik (jika perlu)                    │
│    f. Simpan ke statistik[]                                 │
│ 8. Generate laporan (HTML + CLI)                            │
└─────────────────────────────────────────────────────────────┘
```

---

## ✨ END OF DOCUMENTATION
