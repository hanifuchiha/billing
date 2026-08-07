# RADIUS Mode Timeout & Hang Fix Documentation

## Problem Summary
When all customers had RADIUS MODE data without Mikrotik credentials:
- ❌ Page hangs with status stuck on "Loading..."
- ❌ Package column doesn't display
- ❌ Modal overview cannot be opened
- ❌ No subsequent customers load
- ❌ Network timeout never completes gracefully

## Root Cause Analysis
- `getonlinecustomer.php` times out when RADIUS credentials are missing/invalid
- No timeout mechanism → infinite wait state
- Fetch operations create blocking state that prevents UI interaction
- No fallback/graceful degradation when network fails

## Solutions Implemented

### 1. **8-Second Timeout with AbortController**
**File**: `tables.php` → `fetchData()` function (line ~1907)

```javascript
const fetchTimeout = 8000; // 8 seconds timeout
const controller = new AbortController();
const timeoutId = setTimeout(() => controller.abort(), fetchTimeout);

fetch('getdata/getonlinecustomer.php', {
    signal: controller.signal,
    ...
})
```

**Effect**: Any fetch that doesn't complete within 8 seconds is automatically aborted.

---

### 2. **Failure Count Tracking & Graceful Retry Limits**
**File**: `tables.php` → `fetchData()` function

```javascript
// Track failed attempts per customer
if (!window.fetchFailureCount) {
    window.fetchFailureCount = {};
}

// Increment on error
window.fetchFailureCount[idPel]++;

// Stop retrying after 5 consecutive failures
if (failureCount >= 5) {
    console.warn(`Stopped retrying after ${failureCount} failures`);
    clearInterval(window.fetchIntervals[idPel]);
    // Display "Service unavailable" instead of hang
}
```

**Effect**: 
- Prevents infinite retry loops
- Shows user that service is unavailable after 5 retries
- Clears interval to free up resources

---

### 3. **Graceful Timeout Display**
**File**: `tables.php` → `fetchData()` catch block

Instead of:
```javascript
// OLD: Hang forever
setTimeout(...);
```

Now:
```javascript
// NEW: Show timeout badge + message
statusElement2.innerHTML = `<span class="badge badge-sm bg-warning">⏱️ TIMEOUT</span>`;
infoElement2.innerHTML = `<span>⚠️ Request timeout (RADIUS/Mikrotik tidak merespons) (Retry #${failureCount})</span>`;
```

**Visual Feedback**:
- Yellow "⏱️ TIMEOUT" badge displays immediately after 8 seconds
- Error message shows retry attempt number
- User understands what happened (not stuck)

---

### 4. **Non-Blocking Async Operations**
All sub-operations are wrapped in try-catch and optional:

```javascript
// dBm fetching (optional)
try {
    rxTxDbm = await getDbmFromOnulist(macToCheck, serverListStr, idPel);
} catch (dBmError) {
    console.warn(`⚠️ dBm fetch error: ${dBmError}`);
}

// ACS data fetching (optional)
try {
    let acsResponse = await fetch('getdata/acs_cache_data.php?...');
    // ... process response
} catch (e) {
    console.warn(`⚠️ ACS fetch error: ${e.message}`);
}
```

**Effect**: If ACS or dBm data fails, it won't crash the entire fetch chain

---

### 5. **Queue Processor with Per-Customer Delays**
**File**: `tables.php` → DOMContentLoaded processor (line ~5794)

```javascript
document.addEventListener('DOMContentLoaded', function() {
    if (window.customerFetchQueue && window.customerFetchQueue.length > 0) {
        window.customerFetchQueue.forEach(function(customer, index) {
            // 100ms delay per customer to prevent overload
            setTimeout(function() {
                startFetching(customer.idPel, customer.ip, ...);
            }, 100 * (index + 1));
        });
    }
});
```

**Effect**:
- First customer fetches after 100ms
- Second after 200ms
- And so on...
- Prevents server overload from simultaneous 100+ fetch requests

---

### 6. **Interval ID Management**
**File**: `tables.php` → `startFetching()` function

```javascript
// Store interval ID per customer
if (!window.fetchIntervals) {
    window.fetchIntervals = {};
}
window.fetchIntervals[idPel] = setInterval(() => {
    fetchData(idPel, ...);
}, 10000); // Retry every 10 seconds
```

**Effect**:
- Can track and clear intervals individually
- Prevents orphaned intervals from consuming resources

---

## Testing Checklist

### Test 1: RADIUS-Only Customer (No Mikrotik Credentials)
```
EXPECTED BEHAVIOR:
✓ Page loads without hanging
✓ Initial status shows "Loading..."
✓ After 8 seconds: Yellow "⏱️ TIMEOUT" badge appears
✓ Error message shows: "⚠️ Request timeout (RADIUS/Mikrotik tidak merespons)"
✓ Package column displays (from database, not fetch)
✓ Modal overview can be opened immediately
✓ Other customers continue to load (if any)
✓ Every 10 seconds: Retries fade to Retry #2, #3, etc.
✓ After 5 failed retries: "❌ Service unavailable (stopped retrying)"
```

### Test 2: Multiple Customers with Mixed Modes
```
EXPECTED BEHAVIOR:
✓ Online customers (Mikrotik OK): Full data displays
✓ RADIUS-only customers: "⏱️ TIMEOUT" after 8 seconds
✓ All modals open without delay
✓ Page remains responsive
✓ Console shows no JavaScript errors
```

### Test 3: Modal Functionality
```
EXPECTED BEHAVIOR (regardless of fetch status):
✓ Click customer row → Modal opens instantly
✓ "Buat Tiket" button works
✓ "Live Chat" button works
✓ Modal can be closed and reopened
✓ No lag or delay on modal operations
```

### Test 4: Browser Console Logs
```
Expected messages:
✓ "✅ Error handlers initialized"
✓ "📊 Memulai fetch data untuk X pelanggan..."
✓ "🔄 [1/N] Fetching: IDPEL123"
✓ "[IDPEL123] Fetch Error: AbortError: Request timeout"
✓ "[IDPEL123] Stopped retrying after 5 failures"
✓ NO red error alerts in top-right (that's only for JS errors)
✓ NO console.error messages (only console.warn for timeouts)
```

---

## File Changes Summary

**Modified File**: `crm/billing/tables.php`

**Key Function Changes**:
1. `fetchData()` - Added timeout, failure tracking, graceful error display
2. `startFetching()` - Added interval tracking, error handling wrapper
3. Global error handlers - Already in place for JS errors
4. Queue processor - Already processes customer fetch data sequentially

**No New Files Created**: All changes are in-place modifications to existing functions

---

## Performance Impact

| Metric | Before | After |
|--------|--------|-------|
| Hang Duration | ∞ (infinite) | 8 seconds max |
| Page Responsiveness | ❌ Frozen | ✓ Always responsive |
| Modal Delay | ❌ 30+ seconds | ✓ Instant |
| Error Visibility | ❌ None | ✓ Yellow badge + message |
| Retry Attempt Count | ∞ (infinite) | 5 (configurable) |
| Resource Usage (intervals) | ❌ Orphaned | ✓ Tracked & cleaned |

---

## Configuration

To adjust timeout or retry behavior, modify these values in `fetchData()`:

```javascript
// Line ~1909
const fetchTimeout = 8000;  // Change to 10000 for 10 seconds, etc.

// Line ~2098 (in catch block)
if (failureCount >= 5) {    // Change to 10 for 10 retries, etc.
```

---

## Rollback Instructions

If any issue arises, revert to previous version:
1. Go to file history (Git): `git revert HEAD~1`
2. Or manually restore from backup

All changes are isolated to `fetchData()` and `startFetching()` functions.

---

## Notes for Developers

### Why 8 Seconds?
- Reasonable timeout for LAN RADIUS query
- Long enough for slow networks
- Short enough to prevent UI freeze perception

### Why 5 Retries?
- ~50 seconds total wait time (5 × 10s intervals)
- Enough for transient network glitches
- Not too many to consume resources

### Why 100ms Queue Delay?
- Staggered load prevents server spike
- With 100 customers: ~10 seconds to start all fetches
- After that: ~10 second periodic refresh for each

---

## Future Improvements (Optional)

1. Add configurable timeout via settings/admin panel
2. Add "Retry" button in UI to manually retry failed fetches
3. Add customer status history/cache to fallback on if network down
4. Add separate error log file for debugging
5. Add health check endpoint to pre-test RADIUS/Mikrotik connectivity

---

## Support

For issues or questions about this fix, check:
1. Browser Developer Tools → Console tab (F12)
2. Network tab → getonlinecustomer.php response
3. This documentation for expected behavior

If timeout still occurs, may indicate:
- RADIUS server is offline
- Mikrotik server is unreachable
- Network connectivity issue
- Credentials are incorrect
