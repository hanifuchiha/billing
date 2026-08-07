# Dokumentasi Update: Penyimpanan Data Fee dan Payment Method Tripay

## Ringkasan Perubahan
Ditambahkan 4 kolom baru ke tabel `transaksi` untuk menyimpan informasi fee, payment method, dan harga gross dari callback Tripay, serta filter dan tampilan di halaman Transaction.php.

## 1. Perubahan Database

### File: ALTER_TABLE_transaksi.sql
Tambahkan kolom berikut ke tabel `transaksi`:

```sql
ALTER TABLE `transaksi` 
ADD COLUMN `fee_merchant` VARCHAR(50) NULL AFTER `CEK`,
ADD COLUMN `fee_customer` VARCHAR(50) NULL AFTER `fee_merchant`,
ADD COLUMN `payment_method` VARCHAR(100) NULL AFTER `fee_customer`,
ADD COLUMN `harga_gross` VARCHAR(50) NULL AFTER `payment_method`;
```

**Penjelasan Kolom:**
- `fee_merchant` - Biaya admin yang ditanggung pemilik (dari `fee_merchant` di Tripay)
- `fee_customer` - Biaya admin yang ditanggung customer (dari `fee_customer` di Tripay)  
- `payment_method` - Metode pembayaran dari Tripay (contoh: "Indomaret", "Transfer Bank", dll)
- `harga_gross` - Harga total/gross (dari `total_amount` di Tripay)

## 2. Perubahan Kode Callback

### File: callback_tripay_FIBERQ.php

#### A. Ekstraksi Data (Line 321-325)
```php
$amount = (float)($arr["amount_received"] ?? 0);  // Harga bersih yang diterima
$harga_gross = (float)($arr["total_amount"] ?? 0);  // Harga kotor/gross
$fee_merchant = (float)($arr["fee_merchant"] ?? 0);
$fee_customer = (float)($arr["fee_customer"] ?? 0);
```

#### B. 3 INSERT Statement Diupdate
- Line 947 - PPPOE
- Line 1263-1264 - HOTSPOT/Freeradius  
- Line 1400 - VPN

Semua menyimpan kolom-kolom baru: `fee_merchant`, `fee_customer`, `payment_method`, `harga_gross`

## 3. Perubahan Frontend - Transaction.php

### A. Filter Tambahan (Form)
Ditambahkan filter baru:
- **Payment Method** - Filter berdasarkan metode pembayaran dari Tripay (text input, fuzzy search)

### B. Kolom Tambahan di Detail Transaksi
Ditambahkan kolom tampilan:
- Prize (Harga Bersih): `$data['HARGA']` = amount_received
- Prize Gross (Harga Kotor): `$data['harga_gross']` = total_amount
- Fee Merchant: `$data['fee_merchant']`
- Fee Customer: `$data['fee_customer']`
- Payment Method: `$data['payment_method']` (dari Tripay)

### C. Filter Logic
- Filter payment_method: `LIKE '%...%'` (case-insensitive fuzzy search)
- Filter otomatis include payment_method dalam export parameters

## 4. Data yang Disimpan

Contoh data dari Tripay callback:

```json
{
  "reference": "T2343633409842YNQWZ",
  "merchant_ref": "FQ-154@221125",
  "payment_method": "Indomaret",        // → payment_method
  "total_amount": 116890,                // → harga_gross
  "fee_merchant": 0,                     // → fee_merchant
  "fee_customer": 3500,                  // → fee_customer
  "amount_received": 113390,             // → HARGA (amount_received)
  "status": "PAID"
}
```

**Rumus:**
```
harga_gross = HARGA + fee_customer + fee_merchant
116890 = 113390 + 3500 + 0
```

## 5. Langkah Implementasi

### Untuk DBA/Admin:
1. Login ke phpMyAdmin atau MySQL client
2. Pilih database yang sesuai
3. Buka MySQL console
4. Copy-paste query dari file `ALTER_TABLE_transaksi.sql`
5. Jalankan query
6. Verifikasi kolom baru sudah ada:
   ```sql
   DESCRIBE transaksi;
   ```

### Untuk Developer:
1. Update file `callback_tripay_FIBERQ.php` (sudah dilakukan)
2. Update file `Transaction.php` (sudah dilakukan)
3. Upload kedua file
4. Test callback dengan mengirim data Tripay ke endpoint
5. Verifikasi di halaman Transaction dengan filter payment_method

## 6. Testing

### Verifikasi Data Tersimpan:
```sql
SELECT 
    BUKTI, 
    IDPEL, 
    PAKET, 
    HARGA,           -- amount_received
    harga_gross,     -- total_amount
    fee_merchant, 
    fee_customer, 
    payment_method, 
    TANGGALBAYAR 
FROM transaksi 
WHERE STATUS = 'BERHASIL' 
  AND fee_merchant IS NOT NULL 
ORDER BY TANGGALBAYAR DESC 
LIMIT 10;
```

### Filter di Frontend:
1. Buka Transaction.php
2. Filter berdasarkan "Payment Method"
3. Contoh: Filter "Indomaret" akan menampilkan semua transaksi dengan payment method Indomaret

## 7. Perbedaan HARGA vs harga_gross

| Field | Nilai | Sumber | Keterangan |
|-------|-------|--------|-----------|
| HARGA | 113390 | amount_received | Yang benar-benar diterima sistem |
| harga_gross | 116890 | total_amount | Harga kotor termasuk fee customer |
| fee_customer | 3500 | fee_customer | Biaya yang ditanggung customer |
| fee_merchant | 0 | fee_merchant | Biaya yang ditanggung pemilik |


