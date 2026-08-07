# 📊 VISUAL BREAKDOWN QUERY SQL - DENGAN CONTOH DATA REAL

## 🎯 VISUALISASI ALUR PENGHITUNGAN

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        SISTEM CEK TAGIHAN HARIAN                         │
│                    FLOW QUERY DAN PENGHITUNGAN DATA                      │
└─────────────────────────────────────────────────────────────────────────┘

           USER LOGIN
              ↓
    ┌────────────────────────────┐
    │ Query Ambil Data User      │
    │ SELECT * FROM user         │
    │ WHERE USERNAME = 'FIBERQ'  │
    │ Result: id = 5             │
    └────────────────────────────┘
              ↓
    ┌────────────────────────────────────────────┐
    │ Query Ambil Server Milik User              │
    │ SELECT * FROM server                       │
    │ WHERE user_id = 5                          │
    │ Result: 2 server (FIBERQ, FIBERQ2)        │
    └────────────────────────────────────────────┘
              ↓
         FOR SETIAP SERVER
              ↓
    ┌──────────────────────────────────────────────┐
    │ SERVER 1: FIBERQ (JATINANGOR)               │
    │ ┌──────────────────────────────────────────┐ │
    │ │ Query Ambil Pelanggan di FIBERQ          │ │
    │ │ SELECT * FROM pelanggan                  │ │
    │ │ WHERE PEMILIK='FIBERQ' AND AREA='JTN'   │ │
    │ │ Result: 57 pelanggan                     │ │
    │ │                                          │ │
    │ │ ┌─ FIBERQ001 (Rudi Hartono)  ────────┐  │ │
    │ │ │ Query Bayar Terakhir                │  │ │
    │ │ │ SELECT MAX(waktu) ...               │  │ │
    │ │ │ WHERE IDPEL='FIBERQ001'             │  │ │
    │ │ │ Result: 2026-03-04 10:30:00        │  │ │
    │ │ │                                      │  │ │
    │ │ │ Hitung JT = 2026-03-04 + 30 hari   │  │ │
    │ │ │ JT = 2026-04-03                     │  │ │
    │ │ │                                      │  │ │
    │ │ │ Status = BELUM BAYAR ✗              │  │ │
    │ │ │ Tampil di List                      │  │ │
    │ │ └─────────────────────────────────────┘  │ │
    │ │                                          │ │
    │ │ ┌─ FIBERQ002 (Siti Nurhaliza) ────────┐  │ │
    │ │ │ Query Cek Periode                   │  │ │
    │ │ │ SELECT COUNT(*) ... PENGUNAAN=..    │  │ │
    │ │ │ Result: 1 (sudah bayar April)       │  │ │
    │ │ │                                      │  │ │
    │ │ │ Status = SUDAH BAYAR ✓              │  │ │
    │ │ │ TIDAK tampil di list                │  │ │
    │ │ └─────────────────────────────────────┘  │ │
    │ │                                          │ │
    │ │ ... (55 pelanggan lainnya)               │ │
    │ │                                          │ │
    │ │ HASIL AKHIR SERVER FIBERQ:               │ │
    │ │ • Total Pelanggan: 57                   │ │
    │ │ • Sudah Bayar: 45                       │ │
    │ │ • Belum Bayar: 12                       │ │
    │ └──────────────────────────────────────────┘ │
    │                                              │
    │ ┌──────────────────────────────────────────┐ │
    │ │ SERVER 2: FIBERQ2 (CIFAHSI)              │ │
    │ │ ... (proses sama seperti SERVER 1) ...   │ │
    │ │ HASIL: Total 45, Bayar 32, Belum 13     │ │
    │ └──────────────────────────────────────────┘ │
    └──────────────────────────────────────────────┘
              ↓
    ┌─────────────────────────────────────────────┐
    │ Query Total Sudah Bayar                     │
    │ SELECT COUNT(*) FROM transaksi t            │
    │ JOIN pelanggan p ON ...                     │
    │ WHERE STATUS='BERHASIL' AND PENGUNAAN=...  │
    │ AND p.PEMILIK IN (...) AND p.AREA IN (...)│
    │ Result: 77 pelanggan sudah bayar            │
    └─────────────────────────────────────────────┘
              ↓
    ┌─────────────────────────────────────────────┐
    │ Query Total Belum Bayar                     │
    │ SELECT COUNT(*) FROM transaksi t            │
    │ JOIN pelanggan p ON ...                     │
    │ WHERE STATUS='PENAGIHAN' AND PENGUNAAN=... │
    │ AND p.PEMILIK IN (...) AND p.AREA IN (...)│
    │ Result: 25 pelanggan belum bayar            │
    └─────────────────────────────────────────────┘
              ↓
    ┌──────────────────────────────────────────────────┐
    │              STATISTIK AKHIR DITAMPILKAN         │
    │  Total: 102 | Bayar: 77 | Belum: 25 | Apr'26    │
    └──────────────────────────────────────────────────┘
```

---

## 📋 TABEL CONTOH DATA - TRANSAKSI

### Tabel: transaksi

```
┌─────────┬───────────┬──────────────┬────────────┬──────────────┬──────────┐
│ IDPEL   │ PENGUNAAN │ TANGGALBAYAR │ waktu      │ HARGA        │ STATUS   │
├─────────┼───────────┼──────────────┼────────────┼──────────────┼──────────┤
│ FBQ001  │ Maret26   │ 2026-03-04   │ 2026-03... │ 250000       │ BERHASIL │
│ FBQ001  │ April26   │ NULL         │ NULL       │ 250000       │ PENAGIHAN│
│ FBQ002  │ Maret26   │ 2026-03-08   │ 2026-03... │ 500000       │ BERHASIL │
│ FBQ002  │ April26   │ 2026-04-02   │ 2026-04... │ 500000       │ BERHASIL │
│ FBQ003  │ Feb26     │ 2026-02-10   │ 2026-02... │ 300000       │ BERHASIL │
│ FBQ003  │ Maret26   │ NULL         │ NULL       │ 300000       │ PENAGIHAN│
│ FBQ003  │ April26   │ NULL         │ NULL       │ 300000       │ PENAGIHAN│
│ FBQ004  │ Januari26 │ 2026-01-15   │ 2026-01... │ 400000       │ BERHASIL │
│ FBQ004  │ Feb26     │ NULL         │ NULL       │ 400000       │ BATAL    │ ← Dibatalkan
│ FBQ004  │ Maret26   │ 2026-03-20   │ 2026-03... │ 400000       │ BERHASIL │
│ FBQ004  │ April26   │ NULL         │ NULL       │ 400000       │ PENAGIHAN│
└─────────┴───────────┴──────────────┴────────────┴──────────────┴──────────┘
```

### Tabel: pelanggan

```
┌─────────┬─────────────────┬────────────────┬────────────┬──────────┬─────────────┐
│ IDPEL   │ NAMA            │ NOWA           │ PAKET      │ TIPE_BYR │ TIPE_TEMPO  │
├─────────┼─────────────────┼────────────────┼────────────┼──────────┼─────────────┤
│ FBQ001  │ Rudi Hartono    │ 081234567890   │ 5 Mbps     │ PRABAYAR │ tgl_bayar   │
│ FBQ002  │ Siti Nurhaliza  │ 081987654321   │ 10 Mbps    │ PASCABYR │ tgl_tempo   │
│ FBQ003  │ Budi Santoso    │ 081111111111   │ 3 Mbps     │ PRABAYAR │ tgl_bayar   │
│ FBQ004  │ Ani Wijaya      │ 081222222222   │ 7 Mbps     │ PASCABYR │ tgl_bayar   │
└─────────┴─────────────────┴────────────────┴────────────┴──────────┴─────────────┘
```

---

## 🔍 CONTOH PERHITUNGAN - STEP BY STEP

### KASUS 1: FIBERQ001 - BELUM BAYAR (Prabayar + Tanggal Bayar)

```
Data Pelanggan:
├─ IDPEL: FIBERQ001
├─ Nama: Rudi Hartono
├─ Tipe: PRABAYAR + mengikuti_tanggal_bayar
├─ Tanggal Pasang: 2026-03-01
└─ Hari Sekarang: 2026-04-04

PROSES PENGHITUNGAN:
───────────────────────────────────────────────

Step 1: Query Pembayaran Terakhir
╔════════════════════════════════════════════════╗
║ SELECT MAX(waktu) FROM transaksi              ║
║ WHERE IDPEL='FIBERQ001' AND STATUS='BERHASIL' ║
╚════════════════════════════════════════════════╝
Result: 2026-03-04 10:30:00
        ↓
        Pembayaran terakhir: 4 Maret 2026

Step 2: Hitung Jatuh Tempo
╔════════════════════════════════════════════════╗
║ Jatuh Tempo = Pembayaran Terakhir + 30 Hari   ║
║ JT = 2026-03-04 + 30 hari                     ║
║ JT = 2026-04-03 (3 April 2026)                ║
╚════════════════════════════════════════════════╝
        ↓
        Harus bayar paling lambat: 3 April 2026

Step 3: Bandingkan dengan Hari Ini
╔════════════════════════════════════════════════╗
║ Hari Ini: 2026-04-04 (4 April 2026)           ║
║ Jatuh Tempo: 2026-04-03 (3 April 2026)       ║
║                                              ║
║ 2026-04-04 > 2026-04-03 ?                    ║
║ YES → Sudah melewati jatuh tempo!             ║
╚════════════════════════════════════════════════╝
        ↓
        Perhitungan Overdue:
        Overdue = (4 April - 3 April) = 1 hari

────────────────────────────────────────────────

✗ KESIMPULAN: BELUM BAYAR
  ├─ Tertunggak: 1 hari
  ├─ Jatuh Tempo: 3 April 2026
  ├─ Bayar Terakhir: 4 Maret 2026
  └─ Status di List: TAMPILKAN (Merah/BELUM BAYAR)

INFORMASI YANG DITAMPILKAN:
├─ Kode: FIBERQ001
├─ Nama: Rudi Hartono
├─ WA: 081234567890
├─ Paket: 5 Mbps
├─ Pembayaran Terakhir: 2026-03-04 10:30:00
├─ Jatuh Tempo: 2026-04-03 (MERAH ‼️ Urgent!)
└─ Tombol Aksi: [MATIKAN] [NYALAKAN] [PORTAL]
```

---

### KASUS 2: FIBERQ002 - SUDAH BAYAR (Pascabayar + Tanggal Tempo)

```
Data Pelanggan:
├─ IDPEL: FIBERQ002
├─ Nama: Siti Nurhaliza
├─ Tipe: PASCABAYAR + mengikuti_tanggal_tempo
├─ Field TEMPO: 2026-04-15
└─ Hari Sekarang: 2026-04-04

PROSES PENGHITUNGAN:
───────────────────────────────────────────────

Step 1: Bandingkan Hari Ini dengan TEMPO
╔════════════════════════════════════════════════╗
║ Hari Ini: 2026-04-04 (4 April 2026)           ║
║ TEMPO: 2026-04-15 (15 April 2026)            ║
║                                              ║
║ 2026-04-04 < 2026-04-15 ?                    ║
║ YES → Belum melewati TEMPO (belum expired)   ║
╚════════════════════════════════════════════════╝
        ↓
        Belum berakhir, masih aktif

Step 2: Cek Pembayaran di Periode April 2026
╔════════════════════════════════════════════════╗
║ SELECT COUNT(*) FROM transaksi                ║
║ WHERE IDPEL='FIBERQ002'                       ║
║ AND PENGUNAAN='April 2026'                    ║
║ AND STATUS='BERHASIL'                         ║
╚════════════════════════════════════════════════╝
Result: 1 (ada 1 transaksi berhasil)
        ↓
        Sudah bayar di periode April 2026

────────────────────────────────────────────────

✓ KESIMPULAN: SUDAH BAYAR
  ├─ Waktu Bayar: 2 April 2026
  ├─ TEMPO Expiry: 15 April 2026
  ├─ Periode: April 2026
  └─ Status di List: JANGAN TAMPILKAN (Sudah OK!)

TIDAK MUNCUL DI LIST BELUM BAYAR
└─ User tidak perlu action apa-apa
```

---

### KASUS 3: FIBERQ003 - BELUM BAYAR (Prabayar + Tanggal Bayar, Belum Pernah Bayar)

```
Data Pelanggan:
├─ IDPEL: FIBERQ003
├─ Nama: Budi Santoso
├─ Tipe: PRABAYAR + mengikuti_tanggal_bayar
├─ Tanggal Pasang: 2026-01-20
└─ Hari Sekarang: 2026-04-04

PROSES PENGHITUNGAN:
───────────────────────────────────────────────

Step 1: Query Pembayaran Terakhir
╔════════════════════════════════════════════════╗
║ SELECT MAX(waktu) FROM transaksi              ║
║ WHERE IDPEL='FIBERQ003' AND STATUS='BERHASIL' ║
╚════════════════════════════════════════════════╝
Result: NULL
        ↓
        BELUM PERNAH BAYAR!

Step 2: Cek Tipe Bayar (PRABAYAR)
╔════════════════════════════════════════════════╗
║ Tipe: PRABAYAR → harus bayar di awal!         ║
║                                              ║
║ Tanggal Pasang: 2026-01-20                    ║
║ Hari Ini: 2026-04-04                         ║
║                                              ║
║ 2026-04-04 > 2026-01-20 ? YES                 ║
║ → Sudah waktunya bayar!                       ║
╚════════════════════════════════════════════════╝
        ↓
        Sudah melampaui tanggal pasang

────────────────────────────────────────────────

✗ KESIMPULAN: BELUM BAYAR
  ├─ Alasan: Prabayar belum pernah bayar
  ├─ Dipasang: 20 Januari 2026
  ├─ Belum Bayar Selama: 74 hari (sangat mendesak!)
  └─ Status di List: TAMPILKAN (Merah/URGENT)

INFORMASI YANG DITAMPILKAN:
├─ Kode: FIBERQ003
├─ Nama: Budi Santoso
├─ WA: 081111111111
├─ Paket: 3 Mbps
├─ Pembayaran Terakhir: BELUM PERNAH BAYAR
├─ Jatuh Tempo: 20 Januari 2026 (SANGAT MERAH!!!)
└─ Tombol Aksi: [MATIKAN] [NYALAKAN] [PORTAL]
```

---

## 📊 QUERY TOTAL SUDAH BAYAR - DARI CONTOH DATA

```
Query:
═══════════════════════════════════════════════════════════════════════
SELECT COUNT(*) as total_sudah_bayar
FROM transaksi t
INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
WHERE t.STATUS = 'BERHASIL'                          ← Hanya BERHASIL
  AND t.HARGA != '0'                                 ← Bukan 0 rupiah
  AND t.PENGUNAAN = 'April 2026'                     ← Periode April
  AND p.PEMILIK IN ('FIBERQ','FIBERQ2')              ← Server FIBERQ
  AND p.AREA IN ('JATINANGOR','CIFAHSI')             ← Area tertentu
═══════════════════════════════════════════════════════════════════════

Dari Contoh Data Tabel Transaksi:
├─ FIBERQ001, April26, STATUS=PENAGIHAN → SKIP (bukan BERHASIL)
├─ FIBERQ002, April26, STATUS=BERHASIL → COUNT   ✓ 1
├─ FIBERQ003, April26, STATUS=PENAGIHAN → SKIP
├─ FIBERQ004, April26, STATUS=PENAGIHAN → SKIP
└─ ... (data dari server lain) ...

Result: total_sudah_bayar = 77
        ↓
        Dari seluruh sistem, 77 pelanggan BERHASIL bayar April 2026
```

---

## 📊 QUERY TOTAL BELUM BAYAR - DARI CONTOH DATA

```
Query:
═══════════════════════════════════════════════════════════════════════
SELECT COUNT(*) as total_belum_bayar
FROM transaksi t
INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL
WHERE t.STATUS = 'PENAGIHAN'                         ← Status belum bayar
  AND t.HARGA != '0'                                 ← Bukan 0 rupiah
  AND t.PENGUNAAN = 'April 2026'                     ← Periode April
  AND p.PEMILIK IN ('FIBERQ','FIBERQ2')              ← Server FIBERQ
  AND p.AREA IN ('JATINANGOR','CIFAHSI')             ← Area tertentu
═══════════════════════════════════════════════════════════════════════

Dari Contoh Data Tabel Transaksi:
├─ FIBERQ001, April26, STATUS=PENAGIHAN → COUNT  ✓ 1
├─ FIBERQ002, April26, STATUS=BERHASIL → SKIP
├─ FIBERQ003, April26, STATUS=PENAGIHAN → COUNT  ✓ 1
├─ FIBERQ004, April26, STATUS=PENAGIHAN → COUNT  ✓ 1
└─ ... (data dari server lain) ...

Result: total_belum_bayar = 25
        ↓
        Dari seluruh sistem, 25 pelanggan masih dalam PENAGIHAN April 2026
```

---

## 📈 STATISTIK AKHIR YANG DITAMPILKAN

```
┌──────────────────────────────────────────────────────────────┐
│                  STATISTIK RINGKAS                           │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  Stat Card 1:  Total Pelanggan = 102 pelanggan              │
│  Stat Card 2:  Sudah Bayar = 77 pelanggan (75.5%)           │
│  Stat Card 3:  Belum Bayar = 25 pelanggan (24.5%)           │
│  Stat Card 4:  Periode = April 2026                         │
│                                                              │
└──────────────────────────────────────────────────────────────┘

Breakdown:
├─ Server 1 (FIBERQ): 57 total, 45 bayar, 12 belum
├─ Server 2 (FIBERQ2): 45 total, 32 bayar, 13 belum
└─ TOTAL: 102 total, 77 bayar, 25 belum

25 Pelanggan Belum Bayar akan ditampilkan dalam:
├─ Prabayar + Tanggal Bayar: 10 pelanggan
├─ Pascabayar + Tanggal Bayar: 8 pelanggan
├─ Prabayar + Tanggal Tempo: 4 pelanggan
└─ Pascabayar + Tanggal Tempo: 3 pelanggan
```

---

**Terakhir Update: 04 April 2026**
**Versi: 1.0 - Visual Breakdown**
