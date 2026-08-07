# 🚀 Panduan Aktivasi Fitur Diskon SLA

## 📋 Checklist Aktivasi

### ✅ Status Saat Ini
- [x] Config file sudah dibuat: `sla_discount_config.json` (**ENABLED**)
- [x] Helper functions sudah dibuat: `sla_discount_helper.php`
- [x] Backend API sudah dibuat: `get_customer_sla_discount.php`
- [x] Admin settings sudah ditambahkan ke: `paymentset.php`
- [x] Portal integration sudah ditambahkan ke: `portal_bayar.php`
- [ ] **Database SLA data perlu diinisialisasi** ← **LANGKAH INI YANG HILANG**

---

## 🔧 Langkah Aktivasi

### 1. **Inisialisasi Database SLA (PENTING!)**

Buka URL ini di browser Anda (sebagai admin):
```
http://yourdomain.com/crm/billing/getdata/init_sla_database.php
```

Script ini akan:
- ✅ Membuat table `customer_sla_monthly_snapshots` jika belum ada
- ✅ Membuat sample SLA data untuk 5 customer pertama
- ✅ Verifikasi config file sudah enabled

**Output akan menunjukkan:**
```json
{
    "table_exists": true,
    "sample_data_created": 5,
    "config_enabled": true,
    "initialization_complete": true
}
```

---

### 2. **Verifikasi Data SLA untuk Customer Tertentu**

Buka URL ini dengan mengganti XXXXX dengan IDPEL customer:
```
http://yourdomain.com/crm/billing/getdata/debug_sla.php?idpel=XXXXX
```

**Contoh:**
```
http://yourdomain.com/crm/billing/getdata/debug_sla.php?idpel=PRABAYAR0001
```

Endpoint akan menampilkan:
```json
{
    "idpel": "PRABAYAR0001",
    "feature_enabled": true,
    "sla_data": {
        "sla_percent": 94.50,
        "discount_percent": 5.50,
        "last_month": "2026-05"
    },
    "has_discount": true
}
```

---

### 3. **Reload Portal Pembayaran**

Sekarang buka portal pembayaran customer:
```
http://yourdomain.com/crm/billing/broadband/portal_bayar.php
```

Anda akan melihat breakdown diskon SLA:
```
Tagihan                          Rp159.000,00
Pajak (11%)                      Rp17.490,00
BHPS USO                         Rp0,00
─────────────────────────────────────────────
Diskon SLA (5.50%)              -Rp8.823,00  ← BARU!
SLA Bulan Lalu: 94.50%
─────────────────────────────────────────────
TOTAL BAYAR                      Rp167.667,00  ← SUDAH DIKURANGI!
```

---

## 🎯 Cara Kerja Otomatis

Setelah aktivasi, sistem akan:

1. **Setiap hari**: Cek SLA performance customer dari data monitoring
2. **Akhir bulan**: Generate snapshot SLA ke tabel `customer_sla_monthly_snapshots`
3. **Saat checkout**: 
   - Ambil SLA bulan lalu dari snapshot
   - Hitung diskon: `100 - SLA%`
   - Tampilkan breakdown di rincian
   - Kurangi total otomatis

---

## 📊 Contoh Perhitungan Diskon

| SLA Bulan Lalu | Diskon Diberikan | Tagihan Rp100.000 | Diskon Rupiah | Total Bayar |
|---|---|---|---|---|
| 100% | 0% | Rp100.000 | Rp0 | **Rp100.000** |
| 98% | 2% | Rp100.000 | Rp2.000 | **Rp98.000** |
| 95% | 5% | Rp100.000 | Rp5.000 | **Rp95.000** |
| 90% | 10% | Rp100.000 | Rp10.000 | **Rp90.000** |
| 85% | 15% | Rp100.000 | Rp15.000 | **Rp85.000** |

---

## ⚙️ Pengaturan Admin

Admin bisa toggle fitur di:
```
http://yourdomain.com/crm/billing/paymentset.php
```

Section: **"Diskon SLA Berdasarkan Performance"**
- Toggle ON/OFF
- Lihat contoh tabel mapping
- Info teknis tentang implementation

---

## 🐛 Troubleshooting

### ❌ Diskon SLA masih tidak muncul?

1. **Cek step 1** - Sudah jalankan `init_sla_database.php`?
2. **Cek step 2** - Cek `debug_sla.php?idpel=XXXXX` untuk verifikasi data
3. **Cek config** - Pastikan `sla_discount_config.json` memiliki `"enabled": 1`
4. **Clear cache** - Force reload browser (Ctrl+Shift+R)

### ⚠️ Error saat jalankan init_sla_database.php?

- Pastikan sudah login sebagai admin
- Pastikan database user punya permission CREATE TABLE
- Cek error message di JSON response

### 📝 Data SLA tidak tersedia untuk customer?

- Customer harus memiliki SLA snapshot dari bulan sebelumnya
- Baru pelanggan tidak akan mendapat diskon (belum ada history)
- Check database: 
  ```sql
  SELECT * FROM customer_sla_monthly_snapshots 
  WHERE idpel = 'XXXXX' 
  AND snapshot_month = DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH), '%Y-%m')
  ```

---

## 📝 Testing Checklist

- [ ] Jalankan `init_sla_database.php` - Berhasil membuat data
- [ ] Jalankan `debug_sla.php?idpel=XXXXX` - Lihat data SLA
- [ ] Reload `portal_bayar.php` - Lihat diskon muncul
- [ ] Test semua payment method:
  - [ ] Tripay
  - [ ] Duitku
  - [ ] Xendit
  - [ ] Manual Bank
- [ ] Toggle feature di `paymentset.php` - Diskon hilang saat disabled
- [ ] Test dengan customer berbeda - Diskon berbeda sesuai SLA mereka

---

## 🔐 Security Notes

- ✅ Helper functions pakai prepared statements (SQL injection safe)
- ✅ Config file hanya bisa diubah melalui admin settings
- ✅ API endpoint (`get_customer_sla_discount.php`) check session
- ✅ Database queries parameter-bound

---

## 📞 Support

Jika ada masalah:

1. Cek `debug_sla.php` output untuk detail error
2. Cek browser console untuk JS error
3. Cek PHP error log
4. Cek database permissions

**File helper untuk troubleshoot:**
- `init_sla_database.php` - Setup & verifikasi
- `debug_sla.php` - Debug individual customer
- `SLA_DISCOUNT_DOCUMENTATION.md` - Doc lengkap
