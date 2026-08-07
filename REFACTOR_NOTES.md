# 📋 STRUKTUR BILLING TABLES - REFACTORED

## 🏗️ ARSITEKTUR FILE

File sudah diubah dari: `tables.php` → `tables_refactored.php`

**Struktur yang terorganisir:**

```
├── PART 1: PHP LOGIC & DATABASE QUERIES
│   ├── Initialize variables
│   ├── Query 1: Build Main Customer Query
│   ├── Query 2: Check Table Existence
│   ├── Query 3: Get Period Label
│   └── Query 4: Collect Server Credentials
│
├── PART 2: CSS STYLES (Consolidated)
│   └── Semua styling di satu tempat
│
├── PART 3: HTML DISPLAY
│   ├── Status Messages
│   ├── Filter Form
│   ├── Query Results Summary
│   └── Customer Data Table
│
└── PART 4: JAVASCRIPT (Data Fetching)
    ├── Fetch Customer Data
    ├── Update UI Status
    └── Auto Refresh
```

---

## 🔍 SQL QUERIES YANG DIJALANKAN

### **QUERY 1: Main Customer Data**
```sql
-- Jika server != 'ALL':
SELECT * FROM `pelanggan` 
WHERE `pemilik` = '$selected_server' 
AND `AREA` = '$selected_area' 
ORDER BY `IDPEL`

-- Jika server = 'ALL':
SELECT * FROM `pelanggan` 
WHERE `AREA` = '$selected_area' 
ORDER BY `IDPEL`
```
**Purpose:** Ambil semua data pelanggan berdasarkan filter server dan area

---

### **QUERY 2: Check Table Existence**
```sql
SHOW TABLES LIKE 'diskon_pelanggan'
SHOW TABLES LIKE 'biaya_tambahan_pelanggan'
SHOW TABLES LIKE 'acs_devices'
```
**Purpose:** Cek apakah tabel tambahan tersedia di database

---

### **QUERY 3: Server Information**
```sql
SELECT * FROM `server` 
WHERE `AREA` = '$area' 
AND `PEMILIK` = '$pemilik'
```
**Purpose:** Ambil credential server untuk setiap pelanggan (IP, Username, Password)

---

### **QUERY 4: Diskon Pelanggan**
```sql
SELECT MODE, NOMINAL_TYPE, NOMINAL, KETERANGAN
FROM diskon_pelanggan 
WHERE IDPEL = '$idpel'
LIMIT 3
```
**Purpose:** Ambil max 3 diskon terbaru untuk setiap pelanggan

---

### **QUERY 5: Biaya Tambahan**
```sql
SELECT MODE, NOMINAL_TYPE, NOMINAL, KETERANGAN
FROM biaya_tambahan_pelanggan 
WHERE IDPEL = '$idpel'
LIMIT 3
```
**Purpose:** Ambil max 3 biaya tambahan untuk setiap pelanggan

---

### **QUERY 6: Server & Area Dropdown**
```sql
-- Get distinct servers
SELECT DISTINCT PEMILIK, BRAND, AREA FROM server ORDER BY PEMILIK

-- Get distinct areas
SELECT DISTINCT AREA FROM server ORDER BY AREA

-- Get pakets
SELECT DISTINCT PAKET FROM paket ORDER BY PAKET
```
**Purpose:** Populate filter dropdown di form

---

## 📊 DATA FLOW

```
┌─────────────────────────────────────┐
│  User Submit Filter Form             │
│  (Server + Area)                    │
└────────────┬────────────────────────┘
             │
             ▼
┌─────────────────────────────────────┐
│  QUERY 1: Main Customer Query       │
│  → Ambil semua pelanggan filtered   │
└────────────┬────────────────────────┘
             │
             ▼
┌─────────────────────────────────────┐
│  Loop Each Customer                 │
│                                     │
│  ├─ QUERY 3: Get Server Creds      │
│  ├─ QUERY 4: Get Diskon            │
│  └─ QUERY 5: Get Biaya Tambahan    │
└────────────┬────────────────────────┘
             │
             ▼
┌─────────────────────────────────────┐
│  Store in $customerData Array       │
└────────────┬────────────────────────┘
             │
             ▼
┌─────────────────────────────────────┐
│  PART 3: Display HTML Table         │
│  → Show semua data pelanggan        │
│  → Setiap row ada tombol Refresh    │
└────────────┬────────────────────────┘
             │
             ▼
┌─────────────────────────────────────┐
│  PART 4: JavaScript Fetch           │
│  → Click Refresh button             │
│  → Panggil getonlinecustomer.php    │
│  → Update status realtime           │
│  → Auto refresh setiap 30 detik     │
└─────────────────────────────────────┘
```

---

## 🚀 JAVASCRIPT FETCH DATA

### **Function: fetchCustomerData()**
```javascript
// Fetch customer online status dari server
fetch('getdata/getonlinecustomer.php', {
    method: 'POST',
    body: {
        ip: ipServer,           // IP server RADIUS
        idpel: idpel,          // ID Pelanggan
        us: userServer,        // Username RADIUS
        ps: passwordServer     // Password RADIUS
    }
})
```

### **Response Data Expected:**
```json
{
    "status": "Online" | "Los",
    "login_via": "PPPoE",
    "download": 50.5,
    "upload": 10.2,
    "remote_ip": "192.168.1.100",
    "active_caller_id": "00:11:22:33:44:55",
    "cekexpired": "Active" | "Expired",
    "ceklastloggedout": "2026-05-05 10:30:00",
    "ceklastdisconnect": "2026-05-05 10:30:00"
}
```

---

## 📝 PERUBAHAN UTAMA

### ❌ SEBELUMNYA (tables.php):
- PHP logic tercampur dengan HTML
- CSS inline di tengah HTML
- JavaScript inline di multiple places
- Multiple query loops bersarang
- Sulit untuk maintenance

### ✅ SETELAH (tables_refactored.php):
- ✓ Semua PHP logic di awal (PART 1)
- ✓ CSS terkonsolidasi (PART 2)
- ✓ HTML terstruktur jelas (PART 3)
- ✓ JavaScript terpisah di akhir (PART 4)
- ✓ Query results ditampilkan summary
- ✓ Data collection lebih efisien
- ✓ Mudah untuk debugging & maintenance

---

## 🔧 CARA MENGGUNAKAN

### **1. Backup file asli:**
```bash
cp tables.php tables.php.backup
```

### **2. Ganti dengan versi baru:**
```bash
# Test dulu di tables_refactored.php
# Jika OK, copy ke tables.php
cp tables_refactored.php tables.php
```

### **3. Access di browser:**
```
http://quenbytekniksejahtera.com/crm/billing/tables.php
```

### **4. Filter data:**
1. Pilih **Server/Product**
2. Pilih **Area**
3. Opsional: Filter ODP & Paket
4. Klik **Tampilkan Data**

### **5. Refresh status customer:**
- Klik tombol **Refresh** di setiap baris
- ATAU tunggu auto-refresh (30 detik)

---

## 🐛 DEBUGGING

### **Lihat Console Browser:**
```javascript
// Open DevTools (F12)
// Tab: Console
// Lihat log: Fetching data for: { idpel, ip, user }
// Lihat response: Customer data received: { ... }
```

### **Check Network:**
```
DevTools → Network → Filter: getonlinecustomer.php
Lihat response JSON dari server
```

---

## 📌 NOTES

- File baru tidak menghapus fitur apapun
- Semua functionality tetap sama
- Hanya struktur & organisasi yang berubah
- Query yg sama, hanya terorganisir lebih baik
- Performance tetap sama atau lebih baik (moins query nesting)

---

**Created:** 2026-05-05  
**Status:** Ready to Deploy  
**Testing:** Pending
