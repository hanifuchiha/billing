# PELANGGAN MENUNGGAK API - SQL DIAGNOSTIC QUERIES

**Database**: Mybillingq  
**Note**: Run these queries directly in MySQL/phpMyAdmin to diagnose issues

---

## 🔧 SECTION 1: AUTHENTICATION CHECKS

### Q1.1: Verify User Exists
```sql
SELECT id, USERNAME, STATUS, grup, server 
FROM user 
WHERE USERNAME = 'admin'
LIMIT 1;
```

**Expected**: Should return 1 row with user details
**If empty**: User doesn't exist - create or use different username

---

### Q1.2: List All Users
```sql
SELECT id, USERNAME, STATUS, grup 
FROM user 
ORDER BY STATUS DESC, USERNAME ASC
LIMIT 20;
```

**Expected**: Should see admin users or ASSISTANT users
**If empty**: No users in system

---

### Q1.3: Check User's Role
```sql
SELECT id, USERNAME, STATUS 
FROM user 
WHERE USERNAME = 'admin' 
  AND STATUS IN ('ADMIN', 'ASSISTANT')
LIMIT 1;
```

**Expected**: Returns user with role
**If not found**: User doesn't have ADMIN/ASSISTANT role

---

## 🔍 SECTION 2: SCOPE RESOLUTION CHECKS

### Q2.1: Find PEMILIK for Admin User
```sql
SELECT DISTINCT PEMILIK 
FROM server 
WHERE user_id = (SELECT id FROM user WHERE USERNAME = 'admin')
LIMIT 10;
```

**Expected**: Should return PEMILIK values
**If empty**: No server records linked to this user

---

### Q2.2: Find All PEMILIK in Server Table
```sql
SELECT DISTINCT PEMILIK 
FROM server 
LIMIT 20;
```

**Expected**: Should see company/owner names
**If empty**: No server records exist

---

### Q2.3: Check Server Table for a Specific PEMILIK
```sql
SELECT id, PEMILIK, AREA, user_id 
FROM server 
WHERE PEMILIK = 'admin' 
LIMIT 10;
```

**Expected**: Should return server records
**If empty**: This PEMILIK has no servers defined

---

### Q2.4: Find AREA for a PEMILIK
```sql
SELECT DISTINCT AREA 
FROM server 
WHERE PEMILIK = 'admin'
LIMIT 10;
```

**Expected**: Should see area names like 'Jakarta', 'Surabaya'
**If empty**: No areas for this PEMILIK

---

## 📊 SECTION 3: PELANGGAN DATA CHECKS

### Q3.1: Count Total Pelanggan
```sql
SELECT COUNT(*) as total_pelanggan 
FROM pelanggan;
```

**Expected**: Should be > 0
**If 0**: No customers in database

---

### Q3.2: Count Pelanggan for a Specific PEMILIK
```sql
SELECT COUNT(*) as count_for_pemilik 
FROM pelanggan 
WHERE PEMILIK = 'admin';
```

**Expected**: Should be > 0 if test data exists
**If 0**: No customers for this PEMILIK

---

### Q3.3: Sample Pelanggan Data
```sql
SELECT IDPEL, NAMA, PAKET, PEMILIK, AREA, 
       TIPE_BAYAR, TIPE_TEMPO, TANGGALPASANG, 
       STATUS, HARGA 
FROM pelanggan 
WHERE PEMILIK = 'admin'
LIMIT 10;
```

**Expected**: Should see customer records with all fields populated
**Check for**:
- TIPE_BAYAR: 'prabayar' or 'pascabayar'
- TIPE_TEMPO: 'mengikuti_tanggal_tempo' or 'mengikuti_tanggal_bayar'
- PAKET: Should match values in paket table
- HARGA: Should have numeric value or be in paket table

---

### Q3.4: Pelanggan by TIPE_BAYAR
```sql
SELECT TIPE_BAYAR, COUNT(*) as count 
FROM pelanggan 
WHERE PEMILIK = 'admin'
GROUP BY TIPE_BAYAR;
```

**Expected**: Should see mix of 'prabayar' and 'pascabayar'
**If only pascabayar**: Filter 1 might remove many records

---

### Q3.5: Check TANGGALPASANG Dates
```sql
SELECT IDPEL, NAMA, TANGGALPASANG, 
       DATEDIFF(CURDATE(), TANGGALPASANG) as days_active
FROM pelanggan 
WHERE PEMILIK = 'admin'
ORDER BY TANGGALPASANG DESC
LIMIT 10;
```

**Expected**: Mix of old and new customers
**If all recent**: Recent customers might not show as menunggak

---

## 💰 SECTION 4: PAKET & HARGA CHECKS

### Q4.1: List All Paket
```sql
SELECT PAKET, HARGA, BRAND, AREA 
FROM paket 
ORDER BY PAKET
LIMIT 20;
```

**Expected**: Should see package names with prices
**If empty**: No packages defined

---

### Q4.2: Check Paket HARGA Values
```sql
SELECT PAKET, HARGA 
FROM paket 
WHERE HARGA IS NULL 
   OR HARGA = ''
   OR HARGA = '0'
LIMIT 10;
```

**Expected**: Should be empty (no NULL/empty/zero prices)
**If returns data**: These packages will be filtered out

---

### Q4.3: Compare Pelanggan vs Paket Names
```sql
SELECT DISTINCT p.PAKET as pelanggan_paket
FROM pelanggan p
LEFT JOIN paket pk ON LOWER(p.PAKET) = LOWER(pk.PAKET)
WHERE p.PEMILIK = 'admin'
  AND pk.PAKET IS NULL;
```

**Expected**: Should be empty (all pelanggan pakets exist in paket table)
**If returns data**: These pakets not found in paket table (HARGA filter will remove them)

---

### Q4.4: Case-Sensitive PAKET Mismatch Check
```sql
SELECT DISTINCT p.PAKET 
FROM pelanggan p
WHERE p.PEMILIK = 'admin'

UNION ALL

SELECT DISTINCT pk.PAKET 
FROM paket pk;
```

Run this and compare both lists manually:
```
Pelanggan PAKET list:
- Paket 10Mbps
- Paket 20Mbps

Paket PAKET list:
- paket 10mbps
- paket 20mbps
```

**If different**: Case/format mismatch! Update one to match the other.

---

## 📅 SECTION 5: TRANSAKSI & LAST_PAID CHECKS

### Q5.1: Count Transaksi Records
```sql
SELECT COUNT(*) as total_transactions 
FROM transaksi 
WHERE STATUS = 'BERHASSI';  -- Note: Check exact spelling in your DB
```

**Expected**: Should be > 0
**If 0**: No successful transactions

---

### Q5.2: Find Last Transaction for Each Customer
```sql
SELECT IDPEL, MAX(TANGGALBAYAR) as last_paid, 
       COUNT(*) as total_transactions
FROM transaksi 
WHERE STATUS = 'BERHASIL'
GROUP BY IDPEL
LIMIT 10;
```

**Expected**: Should see various last_paid dates
**If empty**: No successful transactions to track payment history

---

### Q5.3: Check Transaction Date Formats
```sql
SELECT DISTINCT TANGGALBAYAR 
FROM transaksi 
WHERE STATUS = 'BERHASIL'
LIMIT 20;
```

**Expected**: Should see dates in parseable format (YYYY-MM-DD or month/day format)
**If strange format**: Might cause date parsing to fail

---

## 🔔 SECTION 6: REMINDER FILE CHECKS

### Q6.1: Check if Reminder File Exists
```sql
-- MySQL can't check files directly, but check if system has these:
-- /crm/billing/notifbot/data/reminder-admin.json
-- /crm/billing/notifbot/data/reminder-{username}.json
```

**How to check manually**:
- SSH to server: `ls -la /var/www/quenbytekniksejahtera/crm/billing/notifbot/data/`
- Should see files named `reminder-admin.json`, etc.

**If missing**: Create with content:
```json
[{"jatuh_tempo": 28}]
```

---

## 🎯 SECTION 7: COMPLETE SCOPE + DATA CHECK

### Q7.1: Simulate API Scope Resolution (Non-ASSISTANT)
```sql
-- Step 1: Get user
SELECT id, USERNAME, STATUS, grup 
FROM user 
WHERE USERNAME = 'admin'
INTO @user_id, @username, @status, @grup;

-- Step 2: Query server for PEMILIK/AREA
SELECT DISTINCT PEMILIK, AREA 
FROM server 
WHERE user_id = @user_id 
   OR PEMILIK = @username;

-- Check results - these become the PEMILIK/AREA filter
```

---

### Q7.2: Simulate Complete API Query (No Filters)
```sql
-- Use the PEMILIK/AREA from Q7.1
SELECT p.IDPEL, p.NAMA, p.PAKET, p.PEMILIK, p.AREA,
       MAX(t.TANGGALBAYAR) as last_paid,
       COUNT(t.IDPEL) as transaction_count
FROM pelanggan p
LEFT JOIN transaksi t ON p.IDPEL = t.IDPEL AND t.STATUS = 'BERHASIL'
WHERE p.PEMILIK = 'admin'
  AND p.AREA IN ('Jakarta', 'Surabaya')
GROUP BY p.IDPEL
ORDER BY last_paid DESC
LIMIT 100;
```

**Expected**: Should return customer records
**If 0**: Scope issue (no data for PEMILIK/AREA)

---

### Q7.3: Check HARGA Availability for Customers
```sql
SELECT p.IDPEL, p.NAMA, p.PAKET, p.HARGA as customer_harga,
       pk.HARGA as paket_harga,
       CASE 
         WHEN p.HARGA IS NULL THEN 'Missing in pelanggan'
         WHEN pk.HARGA IS NULL THEN 'Missing in paket'
         ELSE 'OK'
       END as status
FROM pelanggan p
LEFT JOIN paket pk ON p.PAKET = pk.PAKET
WHERE p.PEMILIK = 'admin'
LIMIT 20;
```

**Expected**: All rows should have status 'OK'
**If 'Missing in paket'**: These will be filtered out by API

---

## 🧪 SECTION 8: FILTER SIMULATION

### Q8.1: Simulate Filter 1 (TIPE_BAYAR Logic)
```sql
-- Count how many would be filtered by menunggak logic
SELECT 
  TIPE_BAYAR,
  COUNT(*) as total,
  SUM(CASE 
    WHEN TIPE_BAYAR = 'prabayar' THEN 1  -- All prabayar included
    WHEN TIPE_BAYAR != 'pascabayar' THEN 1
    ELSE 0  
  END) as would_pass,
  COUNT(*) - SUM(CASE 
    WHEN TIPE_BAYAR = 'prabayar' THEN 1
    WHEN TIPE_BAYAR != 'pascabayar' THEN 1
    ELSE 0  
  END) as would_filter
FROM pelanggan
WHERE PEMILIK = 'admin'
GROUP BY TIPE_BAYAR;
```

**Expected**: Should see some PRABAYAR (pass) and some PASCABAYAR (might filter)

---

### Q8.2: Simulate Filter 3 (HARGA Logic)
```sql
-- Count how many would be filtered by HARGA
SELECT 
  COUNT(*) as total_customers,
  SUM(CASE WHEN p.HARGA IS NOT NULL AND CAST(p.HARGA AS DECIMAL) > 0 
           THEN 1 ELSE 0 END) as valid_harga,
  SUM(CASE WHEN p.HARGA IS NULL OR CAST(p.HARGA AS DECIMAL) <= 0 
           THEN 1 ELSE 0 END) as invalid_harga,
  SUM(CASE WHEN p.HARGA IS NULL AND pk.HARGA IS NOT NULL 
           THEN 1 ELSE 0 END) as would_resolve_from_paket
FROM pelanggan p
LEFT JOIN paket pk ON LOWER(p.PAKET) = LOWER(pk.PAKET)
WHERE p.PEMILIK = 'admin';
```

**Expected**: valid_harga > 0
**If invalid_harga is high**: HARGA issue is main problem

---

## 📋 SECTION 9: QUICK DIAGNOSTIC CHECKLIST

Copy-paste these one by one:

```sql
-- 1. User exists?
SELECT COUNT(*) as user_count FROM user WHERE USERNAME = 'admin';

-- 2. Server entries exist?
SELECT COUNT(*) as server_count FROM server WHERE PEMILIK = 'admin';

-- 3. Pelanggan entries exist?
SELECT COUNT(*) as pelanggan_count FROM pelanggan WHERE PEMILIK = 'admin';

-- 4. Paket entries exist?
SELECT COUNT(*) as paket_count FROM paket WHERE HARGA > 0;

-- 5. Transaction entries exist?
SELECT COUNT(*) as transaction_count FROM transaksi WHERE STATUS = 'BERHASIL';

-- 6. Paket-Pelanggan match?
SELECT COUNT(DISTINCT p.PAKET) as unmatchable_pakets
FROM (SELECT DISTINCT PAKET FROM pelanggan WHERE PEMILIK = 'admin') p
LEFT JOIN paket pk ON LOWER(p.PAKET) = LOWER(pk.PAKET)
WHERE pk.PAKET IS NULL;
```

**Expected results**:
- user_count: >= 1
- server_count: >= 1
- pelanggan_count: > 0
- paket_count: > 0
- transaction_count: > 0
- unmatchable_pakets: 0

If any fail, that's your issue!

---

## 🚀 ADVANCED: Track Filtering Step-by-Step

```sql
-- This shows exactly which customers get filtered and why

SET @username = 'admin';
SET @today = CURDATE();

SELECT 
  p.IDPEL, p.NAMA, p.PAKET, p.TIPE_BAYAR,
  CASE 
    WHEN p.TIPE_BAYAR = 'prabayar' THEN 'PASS-Prabayar'
    WHEN MONTH(p.TANGGALPASANG) = MONTH(@today) AND YEAR(p.TANGGALPASANG) = YEAR(@today) 
      THEN 'FAIL-NewThisMonth'
    ELSE 'PASS-Pascabayar'
  END as filter1_result,
  pk.HARGA,
  CASE 
    WHEN pk.HARGA IS NULL OR pk.HARGA <= 0 THEN 'FAIL-NoHarga'
    ELSE 'PASS-HasHarga'
  END as filter3_result
FROM pelanggan p
LEFT JOIN paket pk ON LOWER(p.PAKET) = LOWER(pk.PAKET)
WHERE p.PEMILIK = @username
ORDER BY p.IDPEL
LIMIT 20;
```

Run this and look for which customers are being filtered and why!

---

## 💾 HELPFUL SQL EXPORTS

### Export Pelanggan Data for Review
```sql
SELECT * FROM pelanggan 
WHERE PEMILIK = 'admin'
INTO OUTFILE '/tmp/pelanggan_export.csv'
FIELDS TERMINATED BY ','
ENCLOSED BY '"'
LINES TERMINATED BY '\n';
```

Then download and review in Excel/Sheets.

---

## 🆘 WHEN TO CHECK EACH SECTION

| Issue | Check These Sections |
|-------|----------------------|
| Can't log in | Section 1 (Authentication) |
| Data says empty | Sections 2, 3 (Scope, Pelanggan) |
| Some data missing | Section 4 (Paket/HARGA) |
| Wrong dates | Sections 5, 6 (Transaksi, Reminder) |
| Entire API broken | Sections 1-3 (Auth, Scope, Data) |
| Wrong filtering | Sections 7-8 (Complete query, Filters) |

---

End of SQL Diagnostic Guide
