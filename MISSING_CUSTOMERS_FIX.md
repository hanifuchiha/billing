# Fix: Some Customers Not Displaying (Status & Package Missing)

## Problem
Some customers were not appearing in the billing table at all - their status and package columns were not displayed.

## Root Cause
The table row rendering was **inside the server query loop**:

```php
$query = mysqli_query($conn, $sql);  // Query servers for this customer
while ($data = mysqli_fetch_array($query)) {
    // Render <tr>...</tr>
    // If NO servers found → loop never executes → row never rendered
}
```

**If a customer had no matching server records (AREA & PEMILIK):**
- Query returned 0 results
- While loop never executed
- No `<tr>` rendered
- Customer completely missing from table ❌

**Expected:** Customer should still display even without server data

## Solution Implemented

Changed the server query loop to store results in an array first, then check if empty:

```php
// Fetch all servers for this customer
$servers = [];
while ($serverData = mysqli_fetch_array($query)) {
    $servers[] = $serverData;
}

// If NO servers found, add default entry
if (empty($servers)) {
    $servers[] = [
        'PEMILIK' => $pemilik,
        'IP' => 'N/A',
        'PASSWORD' => 'N/A',
        'BRAND' => 'Unknown'
    ];
}

// Now render one row per server (guaranteed at least 1)
foreach ($servers as $data) {
    $user = $data['PEMILIK'];
    $ip = $data['IP'];
    $password = $data['PASSWORD'];
    $brand = isset($data['BRAND']) ? $data['BRAND'] : 'Unknown';
    
    // Render <tr>...</tr>
}
```

## Result

✅ **Customers with no servers now display**
- Shows default row with IP/PASSWORD as "N/A"
- Status and package columns will still load (they're outside the loop)
- Modals can still be opened
- Table won't have empty rows

✅ **Customers with 1+ servers display normally**
- One row per server (unchanged behavior)
- Real server data displayed

## Files Modified
- `crm/billing/tables.php`
  - Lines 2554-2588: Changed from nested `while` loop to array + `foreach` pattern
  - Added default server entry when query returns no results

## Testing

Load the table with customers that have:
1. ✓ Normal servers (AREA & PEMILIK match) → Should display normally
2. ✓ No servers (no AREA & PEMILIK match) → Should display with "N/A" values
3. ✓ Multiple servers → Should display one row per server (unchanged)

**Expected outcome for no-server customers:**
- Row appears in table ✓
- "No" column: Number displayed ✓
- "Name ID" column: Customer name/ID ✓
- "Status" column: Loading then displays "⏱️ TIMEOUT" or error ✓
- "Product" column: Shows brand "Unknown" ✓
- "Packages" column: Shows package from database ✓
- Modal can be opened ✓

## Technical Details

### Why "Unknown" for BRAND?
- If no server record exists, we can't query the database for BRAND
- Default to "Unknown" to indicate missing server configuration
- User can add server record if needed

### Why N/A for IP/PASSWORD?
- These would be used to fetch online/offline status from RADIUS/Mikrotik
- If no server configured, these APIs can't be called
- Display "N/A" to indicate this information unavailable
- The fetch queue will timeout after 8 seconds (per earlier fix)

### Modals Location
- Modals are generated AFTER the foreach loop
- So modals only render once per customer (not per server)
- Modals remain fully functional even with default server entry

## No Breaking Changes
- Customers with servers: Same behavior as before
- Fetch queue: Still works (fetches attempt even with N/A values, then timeout gracefully)
- JavaScript: No changes needed (element IDs remain customer-based)

## Notes for Future Improvements
1. Consider making server entry mandatory before allowing customer registration
2. Add warning/indicator when customer lacks server configuration
3. Add admin interface to bulk-assign servers to customers
4. Improve BRAND query to work with default values

