#!/bin/bash
# =================== CRON JOB SETUP FOR QTS ===================
# This script sets up automatic background cache refresh via cron jobs
# Run this script ONCE to configure cron jobs

# Configuration
PROJECT_PATH="/d/quenbytekniksejahtera.com/QTS/crm/billing"
PHP_PATH=$(which php)
SCRIPT="$PROJECT_PATH/cache-manager.php"
LOG_FILE="/var/log/qts-cache-refresh.log"

echo "=================== QTS CACHE REFRESH CRON SETUP ==================="
echo ""
echo "Project Path: $PROJECT_PATH"
echo "PHP Path: $PHP_PATH"
echo "Log File: $LOG_FILE"
echo ""

# Check if PHP is available
if [ -z "$PHP_PATH" ]; then
    echo "ERROR: PHP not found in PATH"
    exit 1
fi

# Check if script exists
if [ ! -f "$SCRIPT" ]; then
    echo "ERROR: Script not found at $SCRIPT"
    exit 1
fi

# Create log file
touch "$LOG_FILE"
chmod 666 "$LOG_FILE"

# Add cron jobs
echo "Adding cron jobs..."

# Every 5 minutes - Quick cache refresh
(crontab -l 2>/dev/null; echo "*/5 * * * * $PHP_PATH $SCRIPT refresh >> $LOG_FILE 2>&1") | crontab -

# Every 30 minutes - Full cache refresh with cleanup
(crontab -l 2>/dev/null; echo "*/30 * * * * $PHP_PATH $SCRIPT refresh >> $LOG_FILE 2>&1") | crontab -

# Daily at 2 AM - Clear expired caches
(crontab -l 2>/dev/null; echo "0 2 * * * $PHP_PATH $SCRIPT clear >> $LOG_FILE 2>&1") | crontab -

echo ""
echo "✓ Cron jobs added successfully!"
echo ""
echo "Cron Schedule:"
echo "  - Every 5 minutes: Quick cache refresh"
echo "  - Every 30 minutes: Full cache refresh"
echo "  - Daily at 2 AM: Clear expired caches"
echo ""
echo "View cron logs:"
echo "  tail -f $LOG_FILE"
echo ""
echo "View cron jobs:"
echo "  crontab -l"
echo ""
echo "Remove cron jobs (if needed):"
echo "  crontab -e  (then delete the QTS lines)"
echo ""
echo "=================== SETUP COMPLETE ==================="
