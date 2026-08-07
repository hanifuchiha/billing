/**
 * OLT Cache Helper
 * Provides functions to access cached OLT data from localStorage
 * Used by all OLT console types (ZTE, CDATA, HUAWEI, etc.)
 * 
 * Include this in console pages:
 * <script src="/crm/billing/js/olt-cache-helper.js"></script>
 */

const OLT_CACHE_KEY = 'qts_olt_data_cache';
const OLT_CACHE_TIME_KEY = 'qts_olt_cache_time';
const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

/**
 * Get all cached OLT data
 * @returns {Array|null} Array of OLT objects or null if cache is empty/expired
 */
function getCachedOltData() {
    const cached = localStorage.getItem(OLT_CACHE_KEY);
    const cacheTime = localStorage.getItem(OLT_CACHE_TIME_KEY);
    
    if (!cached || !cacheTime) return null;
    
    const now = Date.now();
    if (now - parseInt(cacheTime) > CACHE_DURATION) {
        console.log('[OLT Cache] Cache expired');
        return null;
    }
    
    try {
        return JSON.parse(cached);
    } catch (e) {
        console.log('[OLT Cache] Parse error:', e.message);
        return null;
    }
}

/**
 * Find OLT by ID
 * @param {number} oltId - OLT ID
 * @returns {Object|null} OLT data or null
 */
function getOltById(oltId) {
    const data = getCachedOltData();
    if (!data) return null;
    
    return data.find(olt => olt.id === parseInt(oltId)) || null;
}

/**
 * Find OLT by IP (with or without port)
 * @param {string} oltIp - OLT IP or IP:port
 * @returns {Object|null} OLT data or null
 */
function getOltByIp(oltIp) {
    const data = getCachedOltData();
    if (!data) return null;
    
    const ipOnly = oltIp.includes(':') ? oltIp.split(':')[0] : oltIp;
    return data.find(olt => {
        const cachedIpOnly = olt.ipolt.includes(':') ? olt.ipolt.split(':')[0] : olt.ipolt;
        return cachedIpOnly === ipOnly;
    }) || null;
}

/**
 * Find OLT by device name
 * @param {string} deviceName - OLT device name
 * @returns {Object|null} OLT data or null
 */
function getOltByName(deviceName) {
    const data = getCachedOltData();
    if (!data) return null;
    
    return data.find(olt => olt.oltname === deviceName) || null;
}

/**
 * Search OLT by any field
 * @param {string} query - Search query
 * @param {string} field - Field to search ('ipolt', 'oltname', 'pemilik', 'area', 'brandolt')
 * @returns {Array} Array of matching OLT objects
 */
function searchOlt(query, field = null) {
    const data = getCachedOltData();
    if (!data) return [];
    
    const q = query.toLowerCase();
    
    if (field) {
        return data.filter(olt => 
            String(olt[field] || '').toLowerCase().includes(q)
        );
    }
    
    // Search all fields
    return data.filter(olt => 
        Object.values(olt).some(val =>
            String(val || '').toLowerCase().includes(q)
        )
    );
}

/**
 * Get OLT credentials for immediate use (from cache)
 * @param {string} identifier - OLT ID, IP, or name
 * @returns {Object|null} OLT data with credentials or null if not found
 */
function getOltCredentials(identifier) {
    // Try by ID first
    let olt = null;
    
    if (!isNaN(identifier)) {
        olt = getOltById(parseInt(identifier));
    } else if (identifier.includes('.') || identifier.includes(':')) {
        // Looks like an IP
        olt = getOltByIp(identifier);
    } else {
        // Try by name
        olt = getOltByName(identifier);
    }
    
    if (!olt) {
        console.log('[OLT Cache] OLT not found:', identifier);
        return null;
    }
    
    console.log('[OLT Cache] Found OLT:', olt);
    return {
        id: olt.id,
        ip: olt.ipolt,
        username: olt.usernameolt,
        password: olt.passwordolt,
        device: olt.oltname,
        brand: olt.brandolt,
        pemilik: olt.pemilik,
        area: olt.area,
        community_read: olt.community_read || '',
        community_write: olt.community_write || ''
    };
}

/**
 * Check if cache is available
 * @returns {boolean} True if cache exists and is valid
 */
function isCacheAvailable() {
    const data = getCachedOltData();
    return data !== null && data.length > 0;
}

/**
 * Get cache statistics
 * @returns {Object} Cache info
 */
function getCacheStats() {
    const cached = localStorage.getItem(OLT_CACHE_KEY);
    const cacheTime = localStorage.getItem(OLT_CACHE_TIME_KEY);
    
    if (!cached || !cacheTime) {
        return { available: false, count: 0 };
    }
    
    try {
        const data = JSON.parse(cached);
        const now = Date.now();
        const age = now - parseInt(cacheTime);
        const expired = age > CACHE_DURATION;
        
        return {
            available: !expired,
            count: data.length,
            cacheTime: new Date(parseInt(cacheTime)),
            age: Math.round(age / 1000) + ' seconds',
            expired: expired
        };
    } catch (e) {
        return { available: false, count: 0, error: e.message };
    }
}

/**
 * Clear cache (for debugging)
 */
function clearOltCache() {
    localStorage.removeItem(OLT_CACHE_KEY);
    localStorage.removeItem(OLT_CACHE_TIME_KEY);
    console.log('[OLT Cache] Cache cleared');
}

/**
 * Auto-fill form fields from cache
 * @param {string} identifier - OLT ID, IP, or name
 * @param {Object} fieldMap - Map of field names to input element IDs
 *        Example: { 'ip': 'ipInput', 'username': 'userInput', 'password': 'passInput' }
 */
function autoFillFromCache(identifier, fieldMap) {
    const creds = getOltCredentials(identifier);
    
    if (!creds) {
        console.log('[OLT Cache] Could not auto-fill: OLT not found');
        return false;
    }
    
    for (const [field, elementId] of Object.entries(fieldMap)) {
        const element = document.getElementById(elementId);
        if (element && creds[field]) {
            element.value = creds[field];
            element.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    
    console.log('[OLT Cache] Auto-filled form');
    return true;
}

// Export for debugging in console
window.OltCacheHelper = {
    getCachedOltData,
    getOltById,
    getOltByIp,
    getOltByName,
    searchOlt,
    getOltCredentials,
    isCacheAvailable,
    getCacheStats,
    clearOltCache,
    autoFillFromCache
};

console.log('[OLT Cache Helper] Loaded. Use window.OltCacheHelper for debugging.');
