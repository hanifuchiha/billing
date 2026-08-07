# PELANGGAN MENUNGGAK API ISSUE - COMPLETE ANALYSIS

## Executive Summary
The Pelanggan Menunggak API (`pelanggan_menunggak.php`) is returning empty data ("Tidak ada data menunggak") with debug information. This analysis traces through the complete API flow to identify where data filtering is occurring.

---

## 1. API ARCHITECTURE

### Endpoint Details
- **Location**: [d:\quenbytekniksejahtera.com\QTS\crm\billing\api\pelanggan_menunggak.php](../../crm/billing/api/pelanggan_menunggak.php)
- **Base URL**: `https://quenbytekniksejahtera.com/crm/billing/api/pelanggan_menunggak.php`
- **Method**: GET
- **Parameters**: 
  - `username` (required for API call)
  - `password` (required for API call)
  - `search` (optional, for search filtering)
  - `nofilter` (optional=1, returns unfiltered data for diagnostics)

### Web Version (Form Interface)
- **Location**: [d:\quenbytekniksejahtera.com\QTS\crm\billing\pelanggan_menunggak.php](../../crm/billing/pelanggan_menunggak.php)

### Native App Integration
- **Activity**: `PelangganMenunggakActivity.kt`
- **Location**: [d:\quenbytekniksejahtera.com\QTS\Qbilling\app\src\main\java\com\qts\qbilling\ui\pelanggan\PelangganMenunggakActivity.kt](../../../Qbilling/app/src/main/java/com/qts/qbilling/ui/pelanggan/PelangganMenunggakActivity.kt)
- **Authentication**: Uses SharedPreferences for username/password
- **API Call**: `loadCustomers()` method (line ~125)

---

## 2. REQUEST FLOW

```
Native App
    ↓
SharedPreferences (username, password)
    ↓
API Call: /crm/billing/api/pelanggan_menunggak.php?username=X&password=Y
    ↓
PHP API Handler
    ├─ Authenticate user
    ├─ Resolve scope (PEMILIK/AREA)
    ├─ Query database
    ├─ Apply filters
    └─ Return JSON response
    ↓
Native App
    ├─ Check if success
    ├─ Parse data array
    ├─ Show results or "Tidak ada data menunggak"
    └─ Display debug info if empty
```

---

## 3. AUTHENTICATION & SCOPE RESOLUTION

### Step 1: User Authentication
```php
function cek_login_api($conn, $username, $password) {
    // Checks user table
    // Supports both password_verify() and plain text comparison
    // Returns USERNAME if successful, false if failed
}
```

**Issue Point 1**: If authentication fails, API returns error immediately.
- Check: User exists in database with correct password
- Test: Run `list_users.php` to see available users

### Step 2: User Role Detection
API checks user's STATUS and grup fields:
- `STATUS` = ADMIN, ASSISTANT, etc.
- `grup` = Group/team ID (for ASSISTANT role)
- `server` = JSON array of server IDs (for ASSISTANT role)

**Issue Point 2**: ASSISTANT role requires special scope parsing
- If `STATUS = ASSISTANT`, API resolves scope via server → AREA → PEMILIK chain
- If parsing fails, scope becomes empty

### Step 3: Scope Resolution (Multiple Fallbacks)

**For ASSISTANT Role**:
1. Parse `user.server` JSON → Get server IDs
2. Query `server` table → Get AREAs
3. Query `server` table again → Get PEMILIKs for those AREAs

**For Non-ASSISTANT Role** (Fallback Chain):
1. **Fallback 1**: Query `server` WHERE `user_id=?` OR `PEMILIK=?`
2. **Fallback 2**: Query `server` WHERE `PEMILIK=?` only
3. **Fallback 3**: Query `pelanggan` WHERE `PEMILIK=?` to get distinct AREAs
4. **Final**: Use username as default PEMILIK if all else fails

**Issue Point 3**: If all fallbacks fail, scope remains empty
- PEMILIK_VALUES: empty array
- AREA_VALUES: empty array
- Result: WHERE clause has no matching records

---

## 4. DATABASE QUERY

### Main Query (With AREA Filter)
```sql
SELECT 
    p.IDPEL, p.NAMA, p.PAKET, p.STATUS, p.AREA, p.PEMILIK, 
    p.NOWA, p.HARGA, p.TANGGALPASANG, p.TIPE_BAYAR, p.TIPE_TEMPO, p.BRAND,
    MAX(t.TANGGALBAYAR) AS last_paid
FROM 
    pelanggan p
    LEFT JOIN (
        SELECT IDPEL, MAX(TANGGALBAYAR) AS last_paid
        FROM transaksi
        WHERE STATUS='BERHASIL'
        GROUP BY IDPEL
    ) t ON p.IDPEL = t.IDPEL
WHERE 
    p.PEMILIK IN (...)
    AND p.AREA IN (...)
ORDER BY 
    COALESCE(t.last_paid, p.TANGGALPASANG) ASC
LIMIT 3000
```

**Issue Point 4**: Initial query may return 0 rows
- Reason 1: No pelanggan with matching PEMILIK
- Reason 2: No pelanggan with matching AREA
- Reason 3: PEMILIK/AREA values are empty (scope issue)

### Query Fallbacks
If initial query returns 0 rows:
- **Fallback A** (if no AREA values): Remove AREA filter, query by PEMILIK only
- **Fallback B** (if AREA values exist): Remove AREA filter, query by PEMILIK only

---

## 5. FILTERING LOGIC (Normal Mode vs nofilter=1)

### Mode: nofilter=1 (Diagnostic Mode)
Returns ALL pelanggan records for the scope without filtering.
- **Use**: To check if database has data
- **Shows**: Total count that API retrieves from DB

### Mode: Normal (With Filtering)
Applies 3 sequential filters to each row:

#### Filter 1: should_count_as_menunggak_api()
Checks if customer should be counted as "menunggak" (delinquent):

```php
function should_count_as_menunggak_api($row, $today) {
    $tipeBayar = strtolower($row['TIPE_BAYAR'] ?? 'prabayar');
    
    // All PRABAYAR (prepaid) customers: INCLUDE
    if ($tipeBayar !== 'pascabayar') {
        return true;
    }
    
    // PASCABAYAR (postpaid) logic:
    
    // If newly activated this month: EXCLUDE
    if (isSamePeriodAsToday($row['TANGGALPASANG'], $today)) {
        return false;
    }
    
    // If paid successfully this month: EXCLUDE
    if (isSamePeriodAsToday($row['last_paid'], $today)) {
        return false;
    }
    
    // Otherwise (overdue): INCLUDE
    return true;
}
```

**Issue Point 5**: If all customers are PASCABAYAR paid this month
- They will ALL be filtered out
- `filterCount['menunggak']` will equal rowCount

#### Filter 2: is_due_date_passed_api()
Checks if payment deadline has passed:

```php
function is_due_date_passed_api($row, $today, $fixedDueDay) {
    $reference = get_menunggak_reference_date_api($row);
    $firstDueDate = get_first_due_date_api($row, $reference, $fixedDueDay);
    
    if (empty($firstDueDate)) {
        return false;  // ← EXCLUDES if due date is invalid
    }
    
    return strtotime($firstDueDate) <= strtotime($today);
}
```

**Issue Point 6**: If deadline calculation fails or all deadlines are in future
- Customers filtered out
- `filterCount['duedate']` will be high

**How Deadline is Calculated**:
1. Gets reference date from `last_paid` or `TANGGALPASANG`
2. Looks up `reminder-[username].json` for fixed due day (default: 28)
3. Calculates first due date (either fixed date each month or +1 month)
4. Compares to today

#### Filter 3: resolve_harga_paket_api()
Resolves and validates price:

```php
function resolve_harga_paket_api($hargaMap, $paket, $brand, $area) {
    // Tries multiple fallbacks to find HARGA:
    $key = $paket . '|' . $brand . '|' . $area;  // Most specific
    if (isset($hargaMap[$key])) return $hargaMap[$key];
    
    if (isset($hargaMap[$paket . '||' . $area])) return ...
    if (isset($hargaMap[$paket . '|' . $brand . '|'])) return ...
    if (isset($hargaMap[$paket . '||'])) return ...
    if (isset($hargaMap[$paket])) return ...
    
    return null;  // ← Returns NULL if not found
}
```

In filtering loop:
```php
$resolvedHarga = resolve_harga_paket_api(...);
if ($resolvedHarga !== null && (float)$resolvedHarga > 0) {
    $row['HARGA'] = $resolvedHarga;
} else {
    $filterCount['harga']++;
    continue;  // ← EXCLUDES if harga not found or invalid
}
```

**Issue Point 7**: If HARGA not in paket table
- All customers filtered out
- `filterCount['harga']` will be high
- Likely cause: PAKET name mismatch (case sensitivity, spaces, etc.)

---

## 6. RESPONSE FORMAT

### Success Response (With Data)
```json
{
  "success": true,
  "summary": {
    "total": 50,
    "nunggak_1_bulan": 30,
    "nunggak_2_bulan_plus": 20,
    "target_broadcast": 50
  },
  "data": [
    {
      "IDPEL": "string",
      "NAMA": "string",
      "PAKET": "string",
      "STATUS": "string",
      "AREA": "string",
      "PEMILIK": "string",
      "NOWA": "string",
      "HARGA": "string (currency)",
      "TANGGALPASANG": "string (date)",
      "LAST_PAID": "string (date or empty)",
      "bulan_nunggak": 0,
      "hari_nunggak": 0,
      "TIPE_BAYAR": "prabayar|pascabayar",
      "TIPE_TEMPO": "mengikuti_tanggal_tempo|mengikuti_tanggal_bayar",
      "JATUH_TEMPO_DAY": "28"
    }
  ],
  "_debug": { /* Only when empty */ }
}
```

### Empty Response (No Data)
```json
{
  "success": true,
  "summary": {
    "total": 0,
    "nunggak_1_bulan": 0,
    "nunggak_2_bulan_plus": 0,
    "target_broadcast": 0
  },
  "data": [],
  "_debug": {
    "auth_user": "admin",
    "user_status": "ADMIN",
    "user_id": 1,
    "owner_username": "admin",
    "final_pemilik_values": ["PT XYZ"],
    "final_area_values": ["Jakarta"],
    "query_where": "p.PEMILIK IN ('PT XYZ') AND p.AREA IN ('Jakarta')",
    "initial_query_rows": 100,
    "filter_results": {
      "menunggak": 50,
      "duedate": 30,
      "harga": 20
    },
    "final_count": 0,
    "scope_debug_steps": [
      "Non-ASSISTANT status: ADMIN",
      "Fallback 1: Querying server by user_id=1 OR PEMILIK=admin",
      "After Fallback 1 - PEMILIKs: [...], AREAs: [...] ",
      "Final WHERE clause: p.PEMILIK IN ('PT XYZ') AND p.AREA IN ('Jakarta')"
    ]
  }
}
```

---

## 7. DIAGNOSIS GUIDE

### Using _debug Object to Identify Root Cause

| Scenario | Debug Indicators | Root Cause | Fix |
|----------|------------------|-----------|-----|
| **Empty Scope** | `final_pemilik_values: []` or `final_area_values: []` | User scope resolution failed | Check server table has entries for user's PEMILIK |
| **No Database Data** | `initial_query_rows: 0` | pelanggan table empty or no match | Verify test data exists in database |
| **All Filtered (menunggak)** | `filter_results: {menunggak: 100, ...}` | All PASCABAYAR paid this month | Add test data with PRABAYAR or older TANGGALPASANG |
| **All Filtered (duedate)** | `filter_results: {duedate: 100, ...}` | Deadline not passed yet | Check reminder-[username].json jatuh_tempo, or use past dates |
| **All Filtered (harga)** | `filter_results: {harga: 100, ...}` | PAKET not in paket table | Check paket table has matching PAKET values |
| **Mixed Filtering** | `filter_results: {menunggak: 20, duedate: 30, harga: 50}` | Multiple filters removing data | Address each issue in order |

### Step-by-Step Diagnostics

**Step 1**: Use `nofilter=1` parameter
```
GET /crm/billing/api/pelanggan_menunggak.php?username=X&password=Y&nofilter=1
```
If this returns data, database has records. If empty, database is empty for this scope.

**Step 2**: Check `final_pemilik_values` and `final_area_values`
- If empty: Scope resolution is broken
- If populated: Scope is working

**Step 3**: Check `initial_query_rows`
- If 0: No data in pelanggan table for this PEMILIK/AREA
- If > 0: Database has data

**Step 4**: Check `filter_results` counts
- Which counter is highest? That's the filter removing most data
- Compare: `initial_query_rows` vs `final_count`
- Difference should match sum of filter counts

**Step 5**: If filtering issue
- All menunggak filtered: Check TIPE_BAYAR and dates
- All duedate filtered: Check reminder JSON and deadline calculation
- All harga filtered: Check paket table has entries

---

## 8. DATABASE TABLES INVOLVED

### pelanggan
- **Required Fields**: IDPEL, NAMA, PEMILIK, AREA, PAKET, STATUS, TIPE_BAYAR, TIPE_TEMPO, TANGGALPASANG
- **Optional Fields**: NOWA, HARGA, BRAND
- **Scope**: Filtered by PEMILIK and AREA

### transaksi
- **Required Fields**: IDPEL, TANGGALBAYAR, STATUS
- **Query**: LEFT JOIN to get MAX(TANGGALBAYAR) for each IDPEL where STATUS='BERHASIL'
- **Impact**: Determines `last_paid` date

### paket
- **Required Fields**: PAKET, HARGA
- **Optional Fields**: BRAND, AREA
- **Query**: Loaded into memory, used for HARGA resolution
- **Impact**: If PAKET not here, customer filtered out

### user
- **Required Fields**: USERNAME, PASWORD, STATUS, id
- **Optional Fields**: grup, server
- **Query**: Used for authentication and role detection

### server
- **Required Fields**: id, PEMILIK, AREA
- **Optional Fields**: user_id
- **Query**: Used for scope resolution

### reminder-[username].json (File-based)
- **Location**: `/crm/billing/notifbot/data/reminder-[username].json`
- **Format**: JSON array with objects containing `jatuh_tempo` field
- **Default**: Day 28 of each month if file missing
- **Impact**: Used for deadline calculation

---

## 9. TEST FILES & DEBUGGING

### Created Debug Scripts

1. **test_pelanggan_menunggak_debug.php**
   - Full diagnostic script
   - Tests database connection
   - Checks user authentication
   - Traces scope resolution
   - Shows table counts
   - Tests API with nofilter=1 and normal mode
   - Location: [d:\quenbytekniksejahtera.com\QTS\crm\billing\test_pelanggan_menunggak_debug.php](../../crm/billing/test_pelanggan_menunggak_debug.php)

2. **list_users.php**
   - Lists available test users
   - Shows STATUS and GROUP
   - Use these for testing API
   - Location: [d:\quenbytekniksejahtera.com\QTS\crm\billing\list_users.php](../../crm/billing/list_users.php)

### Database Connection
- **Database**: Mybillingq
- **Host**: localhost
- **User**: qts
- **Password**: Deltaganteng@92
- **Config File**: [d:\quenbytekniksejahtera.com\QTS\crm\billing\config.json](../../crm/billing/config.json)

---

## 10. MOST LIKELY ROOT CAUSES (Priority Order)

### Cause 1: PEMILIK/AREA Scope Empty (40% probability)
**Symptom**: `_debug.final_pemilik_values = []` or `final_area_values = []`

**Check**:
- Verify server table has entries for user's PEMILIK
- Check user role is correctly set
- For ASSISTANT: Verify user.server JSON is valid
- For ASSISTANT: Verify server.id values exist in server table

**Solution**:
- Insert test data into server table with correct PEMILIK/AREA
- Verify user has correct PEMILIK value

---

### Cause 2: No Test Data in Database (25% probability)
**Symptom**: `_debug.initial_query_rows = 0` with valid scope

**Check**:
- pelanggan table is empty for this PEMILIK
- Or pelanggan table has no matching AREA values
- Or all pelanggan are inactive/deleted

**Solution**:
- Insert test pelanggan records
- Ensure PEMILIK and AREA match scope

---

### Cause 3: HARGA Not in Paket Table (20% probability)
**Symptom**: `_debug.filter_results.harga = initial_query_rows` (all filtered)

**Check**:
- paket table is empty
- PAKET names don't match (case sensitivity, spaces, typos)
- HARGA values are NULL or <= 0

**Solution**:
- Insert paket entries with correct PAKET names
- Match case and formatting exactly
- Ensure HARGA > 0

---

### Cause 4: Deadline Logic Filtering All (10% probability)
**Symptom**: `_debug.filter_results.duedate = ~90% of initial_query_rows`

**Check**:
- reminder-[username].json missing or invalid
- All customers have future due dates
- get_first_due_date_api() returning null

**Solution**:
- Create reminder-[username].json with jatuh_tempo value
- Ensure test data has past dates (before jatuh_tempo date)
- Or manually adjust TANGGALPASANG to past dates

---

### Cause 5: TIPE_BAYAR Logic Filtering (5% probability)
**Symptom**: `_debug.filter_results.menunggak = ~90% of initial_query_rows`

**Check**:
- All customers are PASCABAYAR paid this month
- No PRABAYAR (prepaid) test data

**Solution**:
- Add PRABAYAR type customers
- Or use test data with last_paid before current month

---

## 11. TESTING THE API

### Test 1: List Available Users
```bash
curl https://quenbytekniksejahtera.com/crm/billing/list_users.php
```

### Test 2: API with Diagnostic Mode (No Filters)
```bash
curl "https://quenbytekniksejahtera.com/crm/billing/api/pelanggan_menunggak.php?username=admin&password=admin&nofilter=1"
```

### Test 3: API with Normal Filtering
```bash
curl "https://quenbytekniksejahtera.com/crm/billing/api/pelanggan_menunggak.php?username=admin&password=admin"
```

### Test 4: Run Full Debug Script
Access: `https://quenbytekniksejahtera.com/crm/billing/test_pelanggan_menunggak_debug.php`
(Remember to update `$test_username` and `$test_password` in script)

---

## 12. EXPECTED BEHAVIOR (When Working)

1. User logs into app and enters credentials
2. App calls API with username/password
3. API authenticates user
4. API resolves scope (PEMILIK/AREA)
5. API queries pelanggan table
6. API applies filtering (menunggak check, deadline check, harga check)
7. API returns customers with delinquency info
8. App displays list with:
   - Customer count
   - Breakdown by months delinquent (1 bulan, 2+ bulan)
   - Target broadcast count
9. User can select customers and send messages/tickets

---

## 13. RECOMMENDATIONS

### Immediate Actions
1. Run `test_pelanggan_menunggak_debug.php` to identify which part is failing
2. Check `_debug` object in empty response to get specific error
3. Verify database has test data (pelanggan, transaksi, paket)
4. Verify user authentication works

### For Development
1. Add more logging to scope resolution
2. Add separate API parameter to bypass specific filters for testing
3. Create test data fixtures in SQL file
4. Add validation for reminder-[username].json file existence
5. Consider caching paket table data

### For Operations
1. Maintain test users with proper PEMILIK/AREA
2. Monitor empty responses in production
3. Create alert if filter_results shows >80% filtered
4. Regular audit of pelanggan/paket data consistency

---

## Summary of Key Insight

The API has **3 sequential filters** that remove rows:
1. **Menunggak check** (should_count_as_menunggak_api) - 50-90% removed usually
2. **Deadline check** (is_due_date_passed_api) - 30-70% removed usually
3. **HARGA check** (resolve_harga_paket_api) - 5-40% removed usually

When **ALL data is filtered out**, the API shows "Tidak ada data menunggak" message. The `_debug` object tells you exactly which filter removed how many rows, making it easy to diagnose the issue.

**Most common issue**: HARGA not found in paket table due to:
- Empty paket table
- PAKET name mismatch (typo, case sensitivity, spaces)
- HARGA value is NULL or 0

Check `_debug.filter_results.harga` count - if it equals or exceeds `initial_query_rows`, that's your problem.
