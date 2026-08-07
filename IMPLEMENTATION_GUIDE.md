# 📚 PANDUAN IMPLEMENTASI - BILLING TABLES REFACTOR

**Created:** 2026-05-05  
**Status:** Production Ready  
**Author:** System Refactor  
**Backup:** tables.php.backup

---

## 🎯 QUICK START

### **Step 1: Backup File Original**
```bash
# Di folder: d:\quenbytekniksejahtera.com\QTS\crm\billing\

cp tables.php tables.php.backup
```

### **Step 2: Test File Refactored Dulu**
```bash
# File sudah tersedia di:
# tables_refactored.php

# Test: Akses di browser
http://quenbytekniksejahtera.com/crm/billing/tables_refactored.php
```

### **Step 3: Jika OK, Ganti File Original**
```bash
cp tables_refactored.php tables.php
```

### **Step 4: Verifikasi**
```bash
# Akses file asli untuk test
http://quenbytekniksejahtera.com/crm/billing/tables.php
```

---

## 📋 CHECKLIST TESTING

### **Pre-Deployment Test**

- [ ] **Test Filter Form:**
  - [ ] Dropdown Server muncul dengan benar
  - [ ] Dropdown Area muncul dengan benar
  - [ ] Dropdown Paket muncul dengan benar
  - [ ] Submit form berjalan lancar

- [ ] **Test Data Display:**
  - [ ] Query results summary muncul
  - [ ] Total pelanggan ditampilkan dengan benar
  - [ ] Customer data table muncul lengkap
  - [ ] Kolom-kolom sesuai (IDPEL, Nama, Area, ODP, Paket, Status)

- [ ] **Test Refresh Button:**
  - [ ] Klik tombol Refresh bekerja
  - [ ] Console F12 tidak ada error
  - [ ] Network tab menunjukkan fetch ke getonlinecustomer.php
  - [ ] Status update di tabel (hijau/merah)

- [ ] **Test Auto-Refresh:**
  - [ ] Tunggu 30 detik
  - [ ] Status otomatis update
  - [ ] Tidak ada error di console

- [ ] **Test Response Handling:**
  - [ ] Online customer → Badge hijau
  - [ ] Offline customer → Badge merah
  - [ ] Ekspired paket → Ditampilkan dengan benar

---

## 🔧 TROUBLESHOOTING

### **Problem: Filter Dropdown Kosong**
```
SOLUSI:
1. Check server table di database
2. Pastikan ada data di: server.PEMILIK, server.AREA
3. Jalankan query:
   SELECT DISTINCT PEMILIK, AREA FROM server;
```

### **Problem: Query Gagal / Error 500**
```
SOLUSI:
1. Check error log di:
   - File: error_log
   - Browser Console: F12
2. Verifikasi connection string di header.php
3. Pastikan database name, username, password benar
```

### **Problem: Data Tidak Ditampilkan**
```
SOLUSI:
1. Pastikan sudah submit filter form
2. Check apakah ada data pelanggan di database:
   SELECT COUNT(*) FROM pelanggan;
3. Verifikasi SQL query di PART 1 tidak ada error
```

### **Problem: Refresh Button Tidak Bekerja**
```
SOLUSI:
1. Buka DevTools (F12)
2. Tab Console → Lihat error message
3. Tab Network → Lihat response dari getonlinecustomer.php
4. Verifikasi credentials server benar (IP, User, Pass)
5. Check RADIUS server status
```

### **Problem: Auto Refresh Tidak Jalan**
```
SOLUSI:
1. Check apakah ada error di console
2. Verifikasi setInterval() timing (30000ms = 30 detik)
3. Pastikan browser tab masih active
4. Try refresh manual dulu, baru auto-refresh
```

---

## 📊 FILE STRUCTURE REFERENCE

```
crm/billing/
├── tables.php (MAIN FILE - yang diakses)
├── tables.php.backup (BACKUP)
├── tables_refactored.php (REFACTORED VERSION)
├── header.php (Database connection)
├── footer.php (Page footer)
├── REFACTOR_NOTES.md (Dokumentasi struktur)
├── SQL_QUERIES_REFERENCE.sql (Semua SQL queries)
├── IMPLEMENTATION_GUIDE.md (File ini)
│
├── proses/
│   ├── ontremot.php (Handle remote ONT)
│   ├── deletecustomer.php (Delete customer)
│   └── ... (other process files)
│
├── getdata/
│   ├── getonlinecustomer.php (Fetch online status)
│   ├── acs_cache_data.php (Get ACS data)
│   ├── list_onulist_files.php (Get ONU list)
│   └── ... (other data files)
│
└── export/
    ├── export_pelanggan_excel.php
    └── ... (export files)
```

---

## 🚀 FEATURES YANG TERSEDIA

### **1. Filter Customer**
```
Fitur:
- Filter by Server/Product
- Filter by Area
- Optional: Filter ODP
- Optional: Filter Paket
- Button: Tampilkan Data
```

### **2. Display Query Results**
```
Informasi:
- Total Pelanggan (count)
- Waktu Query (current time)
- Periode Aktif (bulan/tahun)
- Status Tabel Diskon
```

### **3. Customer Data Table**
```
Kolom:
- No (urutan)
- IDPEL (ID pelanggan)
- Nama Pelanggan
- Area
- ODP
- Paket
- Status (Online/Offline)
- Aksi (Refresh button)
```

### **4. Real-time Status Check**
```
Capability:
- Cek status online/offline
- Tampilkan IP address pelanggan
- Tampilkan MAC address (last caller)
- Cek paket expired status
- Auto-refresh setiap 30 detik
```

### **5. Additional Features** (optional)
```
Jika Table Exists:
- Diskon pelanggan (max 3 baris)
- Biaya tambahan (max 3 baris)
- ACS device data (on-demand via AJAX)
- ONU status data (dari onulist)
```

---

## 🔐 SECURITY NOTES

### **Parameter Sanitization:**
```php
// SEMUA input sudah di-escape:
mysqli_real_escape_string($conn, $_POST['server'])
mysqli_real_escape_string($conn, $_POST['area'])
htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
```

### **SQL Injection Prevention:**
```php
// JANGAN gunakan direct $_GET/$_POST di query
// HARUS escape dahulu dengan mysqli_real_escape_string()
```

### **XSS Prevention:**
```php
// SEMUA output di-encode dengan htmlspecialchars()
// Terutama untuk user input yang ditampilkan di HTML
```

---

## 📈 PERFORMANCE OPTIMIZATION

### **Database Optimization:**
```sql
-- Add indexes untuk faster queries:
ALTER TABLE `pelanggan` ADD INDEX idx_pemilik_area (pemilik, AREA);
ALTER TABLE `pelanggan` ADD INDEX idx_idpel (IDPEL);
ALTER TABLE `server` ADD INDEX idx_area_pemilik (AREA, PEMILIK);
```

### **Query Efficiency:**
```
✓ Queries di-loop efficiently
✓ Diskon & Biaya hanya query jika table exist
✓ Server info di-cache per customer
✓ Dropdown query di-optimize dengan DISTINCT
```

### **JavaScript Optimization:**
```
✓ Event delegation (querySelectorAll)
✓ Async fetch (non-blocking)
✓ Auto-refresh interval (30 detik - configurable)
✓ Console logging (optional - remove di production)
```

---

## 🎨 CUSTOMIZATION

### **Change Auto-Refresh Interval:**
```javascript
// Current: 30 detik (30000ms)
// Edit line di PART 4 JavaScript:
setInterval(() => {
    // ...fetch code...
}, 30000);  // ← Change ini

// Contoh: 60 detik = 60000
// Contoh: 10 detik = 10000
```

### **Change Status Badge Colors:**
```html
<!-- Current: bg-success (hijau) / bg-danger (merah) -->
<!-- Edit di updateCustomerStatus() function -->

if (data.status === 'Online') {
    statusBadge = 'bg-success';  // ← Change warna
    statusText = '🟢 Online';
} else {
    statusBadge = 'bg-danger';   // ← Change warna
    statusText = '🔴 Offline (LOS)';
}
```

### **Add More Columns di Table:**
```php
<!-- PART 3 - HTML Display section -->
<!-- Add kolom baru di <thead> dan <tbody> -->

<th>Kolom Baru</th>  <!-- Di thead -->
<td><?= htmlspecialchars($customer['field_baru']); ?></td>  <!-- Di tbody -->
```

---

## 📝 MAINTENANCE CHECKLIST

### **Daily:**
- [ ] Monitor data table loading time
- [ ] Check error logs
- [ ] Verify RADIUS connection status

### **Weekly:**
- [ ] Review slow queries
- [ ] Backup database
- [ ] Validate customer data integrity

### **Monthly:**
- [ ] Optimize database indexes
- [ ] Review query performance
- [ ] Update documentation if needed
- [ ] Test disaster recovery

---

## 🤝 SUPPORT & REFERENCES

### **File Locations:**
- Main File: `d:\quenbytekniksejahtera.com\QTS\crm\billing\tables.php`
- Refactored: `d:\quenbytekniksejahtera.com\QTS\crm\billing\tables_refactored.php`
- Docs: `d:\quenbytekniksejahtera.com\QTS\crm\billing\REFACTOR_NOTES.md`
- SQL Ref: `d:\quenbytekniksejahtera.com\QTS\crm\billing\SQL_QUERIES_REFERENCE.sql`

### **Related Files:**
- Database Connection: `crm/billing/header.php`
- Online Data: `crm/getdata/getonlinecustomer.php`
- ACS Data: `crm/getdata/acs_cache_data.php`
- Remote Control: `crm/proses/ontremot.php`

### **Key Functions:**
- `fetchCustomerData()` - AJAX fetch
- `updateCustomerStatus()` - UI update
- `mysqli_query()` - Database query
- `mysqli_fetch_array()` - Fetch result

---

## ✅ DEPLOYMENT CHECKLIST

- [ ] Backup original file
- [ ] Test refactored version
- [ ] Verify all queries working
- [ ] Check responsive design (mobile/desktop)
- [ ] Validate security (XSS, SQL Injection)
- [ ] Test error handling
- [ ] Performance test
- [ ] Browser compatibility (Chrome, Firefox, Safari, Edge)
- [ ] Update documentation
- [ ] Deploy to production

---

**Last Updated:** 2026-05-05  
**Version:** 1.0.0-refactored  
**Status:** ✅ Ready for Production
