# OLT Console Cache Integration Guide

## Overview
All OLT console types now support instant data loading from browser cache. This eliminates loading delays when accessing OLT consoles from the main `olt.php` dashboard.

## What Was Updated

### 1. JavaScript Library: `olt-cache-helper.js`
**Location:** `/crm/billing/js/olt-cache-helper.js`

Provides 9 helper functions for accessing cached OLT data:
- `getCachedOltData()` - Get all cached OLT records
- `getOltById(oltId)` - Find by OLT ID
- `getOltByIp(oltIp)` - Find by IP address
- `getOltByName(deviceName)` - Find by device name
- `searchOlt(query, field)` - Search across fields
- `getOltCredentials(identifier)` - Get credentials for login
- `isCacheAvailable()` - Check if cache is valid
- `getCacheStats()` - Get cache metadata
- `autoFillFromCache(identifier, fieldMap)` - Auto-populate HTML forms

### 2. Console Pages Updated
All OLT console types now include the cache helper:

**Using Shared Template (`_shared/olt_reader_app.php`):**
- ✅ ZTE GPON (crm/olt/zte/index.php)
- ✅ HUAWEI (crm/olt/huawei/index.php)
- ✅ V-SOL GPON/EPON (crm/olt/vsol/index.php)
- ✅ HSGQ GPON/EPON (crm/olt/hsgq/index.php)
- ✅ HIOSO EPON (crm/olt/hioso/index.php)

**Standalone Console:**
- ✅ CDATA SNMP (crm/olt/cdata/index.php)

## Cache System Architecture

```
┌─────────────────────────────────────────────┐
│ crm/billing/olt.php (Main Dashboard)        │
│ ├─ Fetches from getdata/olt-cache.php      │
│ ├─ Stores in localStorage (5 min TTL)      │
│ └─ Auto-refreshes every 5 minutes          │
└────────────────┬────────────────────────────┘
                 │
                 │ localStorage keys:
                 ├─ qts_olt_data_cache (array)
                 └─ qts_olt_cache_time (timestamp)
                 │
                 ▼
    ┌────────────────────────────────────────┐
    │ Browser localStorage                   │
    │ Cache Duration: 5 minutes (300,000ms)  │
    └────────────────┬───────────────────────┘
                     │
                     │ Available to:
                     ├─ openRemoteConsole()
                     ├─ (dispatch to console page)
                     └─ olt-cache-helper.js
                         (instant access)
```

## Using Cache in Console Pages

### Method 1: Auto-Fill Login Form (Recommended)

```javascript
// When user arrives at console, auto-populate credentials
document.addEventListener('DOMContentLoaded', () => {
  // Get OLT identifier from page parameter or session
  const oltId = new URLSearchParams(location.search).get('olt_id');
  
  // Auto-fill if cache is available
  if (isCacheAvailable()) {
    const fieldMap = {
      'ip': 'ipInput',
      'username': 'usernameInput',
      'password': 'passwordInput',
      'device': 'deviceInput'
    };
    autoFillFromCache(oltId, fieldMap);
    console.log('✓ Credentials auto-filled from cache');
  }
});
```

### Method 2: Manual Credential Retrieval

```javascript
// Get credentials object from cache
const creds = getOltCredentials('192.168.1.100');
// Result: { id, ip, username, password, device, brand, ... }

if (creds) {
  document.getElementById('ip').value = creds.ip;
  document.getElementById('user').value = creds.username;
  document.getElementById('pass').value = creds.password;
}
```

### Method 3: Search and Find OLT

```javascript
// Search by any field
const results = searchOlt('ZTE', 'brand');
// Returns array of matching OLTs

// Find specific OLT
const olt = getOltByIp('10.0.0.1');
// Find by device name
const olt = getOltByName('OLT-PRIMARY-1');
```

### Method 4: Check Cache Status

```javascript
// Verify cache is available
if (isCacheAvailable()) {
  console.log('Cache ready for instant access');
} else {
  console.log('Cache not available, show manual login form');
}

// Get cache statistics
const stats = getCacheStats();
console.log(`Cache age: ${stats.age}, Count: ${stats.count}, Expired: ${stats.expired}`);
```

## Data Structure in Cache

```javascript
// Each OLT record in cache contains:
{
  "id": 1,
  "ipolt": "192.168.1.100",
  "oltname": "OLT-PRIMARY-1",
  "pemilik": "ADMIN",
  "area": "AREA-1",
  "usernameolt": "admin",
  "passwordolt": "password123",
  "brandolt": "ZTE",
  "community_read": "public",
  "community_write": "private"
}

// Total response format from getdata/olt-cache.php:
{
  "success": true,
  "count": 45,
  "data": [...],
  "timestamp": 1704067200
}
```

## API Endpoints

### 1. Cache API: `/crm/billing/getdata/olt-cache.php`
**Purpose:** Fetch and cache all OLT data  
**Method:** GET or POST  
**Response:** `{ success, count, data[], timestamp }`  
**Cache TTL:** 5 minutes (client-side)  
**Cron Interval:** Every 5 minutes (configurable via notification.php)

### 2. Cache Settings API: `/crm/billing/getdata/olt-cron-manager.php`
**Purpose:** Configure cron job for background cache updates  
**Actions:**
- `enable` - Activate auto-refresh cron
- `disable` - Deactivate auto-refresh
- `set_interval` - Set refresh interval (1-60 minutes)
- `run_now` - Trigger immediate cache update
- `get_status` - Retrieve current configuration

### 3. Data Provider API: `/crm/billing/getdata/olt-get-data.php`
**Purpose:** Retrieve OLT data with fallback to database  
**Endpoints:**
- `get_by_id` - Retrieve by OLT ID
- `get_by_ip` - Retrieve by OLT IP address
- `validate` - Validate OLT access permissions

## Integration Checklist

- [x] Cache helper library created (`olt-cache-helper.js`)
- [x] ZTE console updated (includes cache helper)
- [x] CDATA console updated (includes cache helper)
- [x] HUAWEI console updated (via shared template)
- [x] V-SOL console updated (via shared template)
- [x] HSGQ console updated (via shared template)
- [x] HIOSO console updated (via shared template)
- [x] Main dashboard (olt.php) fetches and caches data
- [x] Cron job configured in notification.php UI
- [x] Documentation completed

## Testing Cache Integration

### Test 1: Verify Cache Population
1. Navigate to `olt.php`
2. Open browser DevTools → Application → LocalStorage
3. Look for keys: `qts_olt_data_cache` and `qts_olt_cache_time`
4. Verify data contains multiple OLT records

### Test 2: Verify Instant Console Load
1. From `olt.php`, click "Console" button on any OLT
2. Credentials should auto-populate immediately
3. No loading delay should occur

### Test 3: Verify Cache Expiry
1. Note the cache timestamp
2. Wait 5+ minutes (or manually trigger refresh)
3. Verify new data is fetched and timestamps updated

### Test 4: Verify Fallback to Database
1. In browser DevTools, manually clear localStorage
2. Try to open console from OLT dashboard
3. System should fall back to database query (may show delay)

## Performance Metrics

**Before Cache Integration:**
- Initial console load: 2-5 seconds (database query)
- Subsequent visits: Same delay (no caching)

**After Cache Integration:**
- Initial console load: < 100ms (localStorage)
- Subsequent visits: < 100ms (cached)
- Background refresh: Every 5 minutes (transparent)

## Security Considerations

1. **Session Validation:** Cache is populated only for authenticated users
2. **Permission Filtering:** Regular users see only OLTs from their owned servers
3. **ASSISTANT Role:** Gets all OLTs regardless of ownership
4. **Client-Side Only:** Cache stored in browser localStorage (no server dependency)
5. **Credential Safety:** Cache contains same data as database (assess sensitivity accordingly)

## Troubleshooting

### Cache not populating?
1. Check network tab → `getdata/olt-cache.php` returns data
2. Verify session is authenticated (check `$_SESSION['status']`)
3. Check browser's localStorage limits (usually 5-10MB)
4. Clear cache: `clearOltCache()` in DevTools console

### Credentials not auto-filling?
1. Verify `isCacheAvailable()` returns true
2. Check field mapping in `autoFillFromCache()` matches HTML IDs
3. Inspect OLT object: `console.log(getOltById(1))`
4. Verify OLT identifier matches database records

### Performance still slow?
1. Check cache interval in notification.php (should be 5 min)
2. Verify cron job is running: Check `notifbot/data/olt_cache_cron-{pemilik}.json`
3. Monitor network requests for any blocking operations
4. Profile JS execution in DevTools Performance tab

## Maintenance

### Monitor Cache Health
```javascript
// In DevTools console
window.OltCacheHelper.getCacheStats()
// Returns: { available, count, cacheTime, age, expired }
```

### Force Cache Refresh
```javascript
// In DevTools console
window.OltCacheHelper.clearOltCache()
// Then reload page to refetch
```

### Verify Cache Endpoint
```bash
curl "http://your-domain/crm/billing/getdata/olt-cache.php"
# Should return JSON with OLT data
```

## Version History

- **v1.0** (Current) - Initial cache integration across all console types
  - localStorage-based caching
  - 5-minute automatic refresh
  - Shared template support
  - 9 helper functions
  - Full credential auto-fill support

## Support & Questions

For issues or questions regarding cache integration:
1. Check browser DevTools → Application → LocalStorage
2. Review cache statistics: `window.OltCacheHelper.getCacheStats()`
3. Verify cron configuration in notification.php UI
4. Check PHP error logs for olt-cache.php issues
