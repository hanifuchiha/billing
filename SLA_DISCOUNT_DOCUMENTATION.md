# 📊 Fitur Diskon SLA Pelanggan - Dokumentasi

## 📝 Ringkasan Fitur

Fitur ini memberikan **potongan harga otomatis** kepada pelanggan berdasarkan performa jaringan (SLA) mereka di bulan sebelumnya.

### Cara Kerja
- **Jika SLA 95%** → Pelanggan bayar **95%** (diskon 5%)  
- **Jika SLA 90%** → Pelanggan bayar **90%** (diskon 10%)  
- **Jika SLA 100%** → Pelanggan bayar **100%** (tidak ada diskon)

---

## 🔧 Komponen Implementasi

### 1. **API Endpoint** - `get_customer_sla_discount.php`
**Lokasi:** `/crm/billing/getdata/get_customer_sla_discount.php`

**Fungsi:** Mengambil data SLA dan menghitung diskon untuk pelanggan

**Penggunaan:**
```
GET /crm/billing/getdata/get_customer_sla_discount.php?idpel=XXXXX
```

**Response:**
```json
{
  "success": true,
  "idpel": "XXXXX",
  "sla_discount_enabled": true,
  "sla_percent": 95.50,
  "discount_percent": 4.50,
  "last_month": "2026-05",
  "status": "active",
  "message": "SLA 95.50% - Diskon 4.50%"
}
```

---

### 2. **Helper Functions** - `sla_discount_helper.php`
**Lokasi:** `/crm/billing/getdata/sla_discount_helper.php`

**Functions:**

#### `isSlaDiscountEnabled()`
Cek apakah fitur diskon SLA diaktifkan
```php
if (isSlaDiscountEnabled()) {
    // Fitur aktif
}
```

#### `getSlaDicount($conn, $idpel)`
Ambil data SLA dan diskon untuk pelanggan
```php
$sla_discount = getSlaDicount($conn, $idpel);
if ($sla_discount) {
    echo "Diskon: " . $sla_discount['discount_percent'] . "%";
}
```

#### `calculateInvoiceWithSlaDiscount($conn, $idpel, $base_amount)`
Hitung total tagihan dengan diskon SLA
```php
$breakdown = calculateInvoiceWithSlaDiscount($conn, $idpel, 500000);
// $breakdown['total_amount'] = total setelah diskon
// $breakdown['sla_discount_amount'] = jumlah diskon
```

#### `renderSlaDiscountBreakdown($breakdown)`
Render HTML breakdown diskon untuk ditampilkan di portal
```php
echo renderSlaDiscountBreakdown($breakdown);
```

---

### 3. **Payment Settings** - `paymentset.php`
**Lokasi:** `/crm/billing/paymentset.php`

**Fitur:**
- Toggle on/off fitur diskon SLA
- Menampilkan contoh perhitungan
- Menampilkan informasi teknis database
- Konfigurasi disimpan di: `notifbot/data/sla_discount_config.json`

---

## 🚀 Cara Menggunakan di Portal Pembayaran

### Step 1: Include Helper File
```php
<?php
require_once 'getdata/sla_discount_helper.php';
?>
```

### Step 2: Hitung Breakdown Diskon
```php
$idpel = $_GET['idpel'] ?? '';
$base_amount = 500000; // Contoh: Rp 500.000

$breakdown = calculateInvoiceWithSlaDiscount($conn, $idpel, $base_amount);
```

### Step 3: Tampilkan Breakdown di Portal
```php
// Tampilkan diskon jika ada
echo renderSlaDiscountBreakdown($breakdown);

// Atau dengan custom tampilan
if ($breakdown['has_discount']) {
    echo "Tagihan asli: " . formatCurrency($breakdown['base_amount']);
    echo "Diskon: " . $breakdown['sla_discount_percent'] . "%";
    echo "Total bayar: " . formatCurrency($breakdown['total_amount']);
}
```

---

## 📊 Database Schema

### Tabel: `customer_sla_monthly_snapshots`
```sql
CREATE TABLE customer_sla_monthly_snapshots (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    snapshot_month CHAR(7),           -- Format: YYYY-MM
    idpel VARCHAR(64),                -- Customer ID
    pemilik VARCHAR(150),             -- Owner
    total_sla_percent DECIMAL(6,2),   -- SLA persentase (0-100)
    total_checks INT,                 -- Total checks yang dilakukan
    online_checks INT,                -- Jumlah online checks
    PRIMARY KEY (snapshot_month, idpel)
);
```

---

## ⚙️ Konfigurasi

### File: `notifbot/data/sla_discount_config.json`
```json
{
  "enabled": true,
  "updated_by": "admin",
  "updated_at": "2026-05-15 10:30:00"
}
```

---

## ✅ Integrasi Checklist

- [ ] API endpoint `/crm/billing/getdata/get_customer_sla_discount.php` sudah ditest
- [ ] Helper functions di `sla_discount_helper.php` sudah diimpor di portal pembayaran
- [ ] Setting toggle di paymentset.php sudah aktif
- [ ] Menampilkan breakdown diskon di portal pembayaran
- [ ] Database tabel `customer_sla_monthly_snapshots` sudah ada
- [ ] Snapshot data SLA bulanan sudah di-populate

---

## 🧪 Testing

### Test 1: Cek API Endpoint
```bash
curl "https://domain.com/crm/billing/getdata/get_customer_sla_discount.php?idpel=CUST001"
```

### Test 2: Test di Portal
1. Login ke portal pembayaran
2. Lihat tagihan pelanggan
3. Verifikasi diskon ditampilkan dengan benar

### Test 3: Hitung Manual
- SLA bulan lalu: 95.5%
- Diskon: 100 - 95.5 = 4.5%
- Tagihan Rp 100.000 → Total: Rp 95.500

---

## 📝 Catatan

1. **Diskon hanya untuk pelanggan lama** - Pelanggan baru tanpa riwayat SLA tidak dapat diskon
2. **Maximal diskon 100%** - Jika tidak ada data SLA, diskon = 0%
3. **Diskon per bulan** - Dihitung dari SLA bulan sebelumnya
4. **Flexible activation** - Admin dapat mengaktifkan/menonaktifkan kapan saja
5. **Audit trail** - Semua perubahan setting disimpan dengan timestamp

---

## 🔐 Security Considerations

- Validasi input `idpel` untuk mencegah SQL injection
- Cek session/authentication sebelum menampilkan diskon
- Log semua aktivitas pengaktifan/penonaktifan fitur
- Proteksi API endpoint dengan rate limiting

---

## 📞 Support & Troubleshooting

### Diskon tidak muncul
1. Cek apakah fitur diaktifkan di paymentset.php
2. Cek apakah pelanggan memiliki data SLA bulan lalu
3. Cek file config di `notifbot/data/sla_discount_config.json`

### API Error
1. Pastikan database table `customer_sla_monthly_snapshots` ada
2. Verifikasi koneksi database
3. Cek permission file

---

## 📄 File References

| File | Deskripsi |
|------|-----------|
| `getdata/get_customer_sla_discount.php` | API endpoint diskon SLA |
| `getdata/sla_discount_helper.php` | Helper functions |
| `paymentset.php` | Admin settings |
| `notifbot/data/sla_discount_config.json` | Konfigurasi |

---

**Dibuat:** 2026-05-15  
**Versi:** 1.0  
**Status:** Ready for Integration
