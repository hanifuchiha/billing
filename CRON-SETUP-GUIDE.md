#!/bin/bash
# OLT & Server Background Caching System - Setup Guide
# Installation path: /path/to/qts/crm/billing/

## SYSTEM COMPONENTS

### 1. Background Scripts
- getdata/olt-cache-background.php      (Caches OLT data)
- getdata/server-cache-background.php   (Caches Server data)
- getdata/cron-manager.php              (Manages cron jobs)

### 2. Admin Interface
- cron-jobs.php (Management dashboard for cron jobs)

### 3. Caching Storage
- cache/olt-data.json      (Cached OLT data)
- cache/server-data.json   (Cached Server data)
- logs/olt-cache-background.log
- logs/server-cache-background.log

---

## INSTALLATION STEPS

### Step 1: Create Required Directories
```bash
mkdir -p /var/www/html/crm/billing/cache
mkdir -p /var/www/html/crm/billing/logs
chmod 755 /var/www/html/crm/billing/cache
chmod 755 /var/www/html/crm/billing/logs
```

### Step 2: Set Up Cron Jobs on Linux/Unix
```bash
# Edit crontab
crontab -e

# Add these lines:
# OLT Cache - Updates every 5 minutes
*/5 * * * * /usr/bin/php /var/www/html/crm/billing/getdata/olt-cache-background.php >> /var/log/qts-olt-cache.log 2>&1

# Server Cache - Updates every 5 minutes
*/5 * * * * /usr/bin/php /var/www/html/crm/billing/getdata/server-cache-background.php >> /var/log/qts-server-cache.log 2>&1

# Save and exit (:wq in vim)
```

### Step 3: Set Up Cron Jobs on Windows (Task Scheduler)
1. Open Task Scheduler (taskschd.msc)
2. Create Basic Task:
   - Name: "QTS OLT Cache"
   - Trigger: Daily, repeat every 5 minutes indefinitely
   - Action: Start program
     - Program: C:\php\php.exe
     - Arguments: "C:\path\to\crm\billing\getdata\olt-cache-background.php"

### Step 4: Access Management Dashboard
1. Login to QTS Admin Panel
2. Go to Cron Jobs menu (accessible to ADMIN/SUPERADMIN users)
3. Enable/disable jobs as needed
4. View job status and run manually if needed

---

## MANAGEMENT DASHBOARD

Location: /crm/billing/cron-jobs.php

Features:
- View all cron jobs and their status
- Enable/Disable jobs with Turn On/Off buttons
- View last run time for each job
- Run jobs manually (useful for testing)
- Copy crontab setup instructions
- Real-time job status updates

Access Requirements:
- Must be logged in as ADMIN or SUPERADMIN

---

## DATABASE TABLE

The system automatically creates the `cron_jobs` table with:
- id: Job ID
- job_name: Unique job identifier
- script_path: Path to the script
- interval_minutes: How often to run (in minutes)
- enabled: 0=disabled, 1=enabled
- last_run: Last execution timestamp
- next_run: Planned next execution
- created_at: When the job was created
- updated_at: Last modification timestamp

---

## CACHING BEHAVIOR

### OLT Cache (olt-cache-background.php)
- Runs every 5 minutes
- Fetches all OLT data from database
- Stores in cache/olt-data.json
- Instant display via JavaScript (no waiting)
- Fresh data updates in background
- Caches for 5 minutes before browser refreshes from server

### Server Cache (server-cache-background.php)
- Runs every 5 minutes
- Fetches all server data from database
- Stores in cache/server-data.json
- Same instant display behavior as OLT cache

---

## MONITORING

Check logs:
```bash
# Linux/Unix
tail -f /var/log/qts-olt-cache.log
tail -f /var/log/qts-server-cache.log

# Or via web interface
# Check /crm/billing/logs/olt-cache-background.log
# Check /crm/billing/logs/server-cache-background.log
```

---

## TROUBLESHOOTING

### Cron job not running
1. Verify crontab: `crontab -l`
2. Check logs for errors
3. Verify PHP CLI path: `which php`
4. Test manually: `php /var/www/html/crm/billing/getdata/olt-cache-background.php`

### No cache files created
1. Verify cache directory exists and is writable: `chmod 755 cache/`
2. Check permissions: `ls -la cache/`
3. Look for errors in logs

### Manual trigger not working
1. Go to cron-jobs.php admin panel
2. Try "Run OLT Cache Now" button
3. Check console for errors
4. Verify database connection

### Cache not updating
1. Check if jobs are enabled in admin panel
2. Verify cron job is configured correctly
3. Check server logs for execution
4. Verify script paths in cron-manager.php

---

## API ENDPOINTS

### GET /getdata/cron-manager.php?action=list
Returns all cron jobs and their status

### GET /getdata/cron-manager.php?action=toggle&job=olt_cache
Toggles a cron job on/off

### GET /getdata/cron-manager.php?action=run&job=olt_cache
Runs a cron job immediately

### GET /getdata/cron-manager.php?action=setup_crontab
Returns crontab setup instructions

---

## CACHE FILE FORMAT

cache/olt-data.json:
```json
{
  "success": true,
  "count": 50,
  "data": [
    {
      "id": 1,
      "ipolt": "10.0.0.11",
      "oltname": "OLT-AREA1",
      "pemilik": "BRAND1",
      "area": "AREA1",
      "usernameolt": "admin",
      "passwordolt": "password",
      "brandolt": "ZTE GPON C300",
      "community_read": "",
      "community_write": ""
    }
  ],
  "timestamp": 1714828800,
  "generated_at": "2024-05-04 12:00:00"
}
```

---

## PERFORMANCE BENEFITS

1. **Instant Display**: Page loads from cache immediately (no waiting)
2. **Background Updates**: Fresh data loads silently every 5 minutes
3. **Reduced Load**: Database queries only every 5 minutes, not on every page load
4. **Better UX**: Users never see "Loading..." for data
5. **Scalable**: Works efficiently with hundreds of OLT/Server entries

---

## SECURITY NOTES

1. Background scripts accessible only via CLI
2. Management API requires ADMIN/SUPERADMIN login
3. Passwords in cache files should be protected (they're database-native)
4. Consider enabling file encryption for cache directory
5. Logs may contain sensitive information - restrict access

---

## MANUAL SETUP VERIFICATION

After setup, verify everything works:

```bash
# 1. Test script manually
php /var/www/html/crm/billing/getdata/olt-cache-background.php

# 2. Check cache file was created
ls -la /var/www/html/crm/billing/cache/

# 3. Check cron job in system
sudo crontab -l

# 4. Check logs
tail /var/www/html/crm/billing/logs/olt-cache-background.log

# 5. Access admin dashboard
# Navigate to http://your-domain/crm/billing/cron-jobs.php
```

---

Generated: 2024-05-04
For support, contact your system administrator.
