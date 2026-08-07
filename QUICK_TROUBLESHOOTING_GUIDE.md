
# PELANGGAN MENUNGGAK API - QUICK TROUBLESHOOTING CHECKLIST

## 🔍 Quick Diagnosis Process

When API returns empty data, follow this checklist in order:

### Step 1: Verify Authentication (2 minutes)
- [ ] Check credentials are correct (username/password in database)
- [ ] Run `/crm/billing/list_users.php` to see available test users
- [ ] Try API with valid test credentials

**If fails**: Authentication error. Check user table password.

---

### Step 2: Check Scope Resolution (2 minutes)
- [ ] API response contains `_debug` object
- [ ] Check `_debug.final_pemilik_values` - is it populated?
- [ ] Check `_debug.final_area_values` - is it populated?

**If empty**: Scope resolution failed
- Check: server table has entries for user's PEMILIK
- Check: For ASSISTANT role, verify user.server JSON is valid

**If populated**: Go to Step 3

---

### Step 3: Check Database Query Result (2 minutes)
- [ ] Check `_debug.initial_query_rows` value

| Value | Status | Next |
|-------|--------|------|
| **0** | No data in pelanggan table | Insert test data |
| **>0** | Database has data | Go to Step 4 |

---

### Step 4: Check Filtering (2 minutes)
- [ ] Check `_debug.filter_results` object
- [ ] Calculate: `initial_query_rows - (menunggak + duedate + harga)` = `final_count`

Look for the highest count:

| Filter | If Highest | Likely Cause | Fix |
|--------|-----------|--------------|-----|
| **menunggak** | >80% of total | PASCABAYAR paid this month | Use PRABAYAR test data |
| **duedate** | >80% of total | Deadline not passed yet | Check reminder JSON, use past dates |
| **harga** | >80% of total | PAKET not in paket table | Insert paket entries, match PAKET name |

---

### Step 5: Use Diagnostic Mode (1 minute)
```
Add ?nofilter=1 to API call:
GET /crm/billing/api/pelanggan_menunggak.php?username=X&password=Y&nofilter=1
```

- [ ] If nofilter=1 returns data → Database is OK, filtering is too aggressive
- [ ] If nofilter=1 returns empty → Database has no data for this scope

---

## 🔧 Common Issues & Fixes

### Issue: final_pemilik_values = []
```
✗ Problem: Scope resolution returned empty PEMILIK list
```
**Fix**:
1. Check `server` table has entries with user's PEMILIK
2. For ASSISTANT role: Verify `user.server` JSON format
3. Run: `SELECT * FROM server WHERE PEMILIK='admin' LIMIT 1`

---

### Issue: initial_query_rows = 0
```
✗ Problem: No pelanggan records matched the scope
```
**Fix**:
1. Check pelanggan table has data: `SELECT COUNT(*) FROM pelanggan`
2. Check PEMILIK match: `SELECT * FROM pelanggan WHERE PEMILIK='admin' LIMIT 1`
3. Insert test data if table is empty

---

### Issue: filter_results.harga = (most filtered)
```
✗ Problem: PAKET values not found in paket table
```
**Fix**:
1. Check paket table: `SELECT PAKET, HARGA FROM paket LIMIT 10`
2. Compare with pelanggan: `SELECT DISTINCT PAKET FROM pelanggan`
3. Insert missing paket entries
4. Check case sensitivity and exact match

---

### Issue: filter_results.duedate = (most filtered)
```
✗ Problem: All customers have future due dates
```
**Fix**:
1. Check reminder file: `/crm/billing/notifbot/data/reminder-[username].json`
2. If missing, create it with: `[{"jatuh_tempo": 28}]`
3. Check pelanggan TANGGALPASANG dates are in past
4. Check transaksi last_paid dates

---

### Issue: filter_results.menunggak = (most filtered)
```
✗ Problem: All customers are PASCABAYAR paid this month
```
**Fix**:
1. Add test customers with TIPE_BAYAR='prabayar'
2. Or use historical dates (past months) for test data
3. Or add transaksi with STATUS='BERHASIL' before this month

---

## 📊 Sample Test Data Insert

### Insert Test PEMILIK/AREA to server table
```sql
INSERT INTO server (pemilik, area, user_id) 
VALUES ('admin', 'Jakarta', 1);
```

### Insert Test Pelanggan
```sql
INSERT INTO pelanggan (
    IDPEL, NAMA, PAKET, PEMILIK, AREA, 
    STATUS, TIPE_BAYAR, TIPE_TEMPO, 
    TANGGALPASANG, NOWA, HARGA, BRAND
) VALUES (
    'test001', 'Test Customer 1', 'Paket 10Mbps',
    'admin', 'Jakarta', 'AKTIF', 'prabayar', 'mengikuti_tanggal_tempo',
    '2025-01-15', '62812345678', '200000', 'FIBER'
);
```

### Insert Test Paket
```sql
INSERT INTO paket (PAKET, HARGA, BRAND, AREA)
VALUES ('Paket 10Mbps', '200000', 'FIBER', 'Jakarta');
```

### Insert Test Transaksi (for last_paid tracking)
```sql
INSERT INTO transaksi (IDPEL, TANGGALBAYAR, STATUS)
VALUES ('test001', '2026-03-15', 'BERHASIL');
```

### Create Reminder File
File: `/crm/billing/notifbot/data/reminder-admin.json`
```json
[{"jatuh_tempo": 28}]
```

---

## 🧪 Testing Script

### Run Full Debug
Visit: `https://quenbytekniksejahtera.com/crm/billing/test_pelanggan_menunggak_debug.php`

This will show:
- Database connection status
- User authentication
- Scope resolution steps  
- Table counts
- Sample data
- API responses with/without filters

---

## 📋 Debug Checklist Template

```
API Test Date: ________
Username: ________
PEMILIK: ________

Query Results:
  [ ] Database connected
  [ ] User authenticated: ________
  [ ] User status: ________
  [ ] final_pemilik_values: ________
  [ ] final_area_values: ________
  
Database Counts:
  [ ] pelanggan total: ________
  [ ] pelanggan for PEMILIK: ________
  [ ] transaksi total: ________
  [ ] paket total: ________

API Results:
  [ ] nofilter=1 returns data: ________
  [ ] nofilter=1 count: ________
  [ ] normal filter count: ________
  [ ] filter_results.menunggak: ________
  [ ] filter_results.duedate: ________
  [ ] filter_results.harga: ________

Root Cause: ________________________________
Solution: ________________________________
```

---

## 🚀 Performance Notes

- **LIMIT 3000**: API queries limit results to 3000 pelanggan per call
- **Paket caching**: Paket table loaded into memory for speed
- **No pagination**: API returns all results up to limit
- **Sorting**: Results sorted by months delinquent (descending)

---

## 📞 Related Files

- API Endpoint: `/crm/billing/api/pelanggan_menunggak.php`
- Web Form: `/crm/billing/pelanggan_menunggak.php`
- Native Activity: `Qbilling/app/src/main/java/com/qts/qbilling/ui/pelanggan/PelangganMenunggakActivity.kt`
- Debug Script: `/crm/billing/test_pelanggan_menunggak_debug.php`
- User List: `/crm/billing/list_users.php`
- Analysis Doc: `/crm/billing/PELANGGAN_MENUNGGAK_API_ANALYSIS.md`

---

## 🎯 Expected Results (When Working)

✓ API returns: `{"success": true, "data": [...], "summary": {...}}`
✓ Data array populated with delinquent customers
✓ Summary shows counts by delinquency period
✓ No `_debug` object (or minimal if development)
✓ Native app displays list of customers
✓ All 3 filter counts > 0 (meaning some filtering occurred)
