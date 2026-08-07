# ACS System - Quick Start Guide

## First Time Setup (ADMIN Only)

### 1. Access the System
Login to billing → Click "Daftar Server ACS" in ADMINISTRATOR PANEL

### 2. Database Auto-Install
Tables will be created automatically on first access:
- acs_servers
- acs_devices
- acs_pppoe_mapping
- acs_user_server_assignment
- acs_sync_log

### 3. Create Your First Server
Click "Tambah Server Baru" and fill:
```
Nama Server: ACS Production
Domain / IP: acs.yourcompany.com (or 192.168.1.100)
Port: 5000
Username: admin
Password: [secure-password]
```
Click "Buat Server ACS" → Wait 30-60 seconds

### 4. Verify Container Running
```bash
docker ps | grep acs_5000
```
Should show 2 containers running

### 5. Access GenieACS Panel
Open: http://acs.yourcompany.com:5000
Login with username/password from step 3

---

## Common Operations

### Add New Server (ADMIN)
```
Menu: Tambah Server ACS
→ Fill form
→ Click "Buat Server ACS"
→ Wait for completion
→ Check "Daftar Server ACS"
```

### Start/Stop Container (ADMIN)
```
Menu: Daftar Server ACS
→ Find server
→ Click green ▶ (start) or yellow ■ (stop)
→ Confirm
→ Status updates automatically
```

### Assign Server to User (ADMIN)
```
Menu: Manajemen Container
→ Find server
→ Click "Assign User"
→ Select user from dropdown
→ Click "Assign"
```

### View Server Info (USER)
```
Menu: Informasi Server ACS
→ See assigned server details
→ Copy username/password
→ Click "http://..." to open ACS panel
```

### Sync Devices
```
Menu: Perangkat Pelanggan
→ Select server
→ Click "Sync dari GenieACS"
→ Wait for completion
→ View device list
```

### Check Signal Quality
```
Menu: Redaman Pelanggan
→ Select server
→ View RX/TX Power for all devices
→ Look for Warning/Critical status
```

### Change Customer WiFi (USER)
```
Menu: Pengaturan WiFi
→ Find device
→ Click "Ubah"
→ Enter new SSID & Password
→ Click "Simpan"
```

### Map PPPoE to Customers
```
Menu: Mapping PPPoE
→ View devices
→ Check MATCH/NOT_FOUND status
→ Investigate NOT_FOUND devices
```

---

## Troubleshooting Quick Fix

### Container Won't Start
```bash
cd /path/to/crm/billing/acs_containers/acs_5000
docker compose logs
docker compose restart
```

### Can't Sync Devices
1. Check GenieACS panel: http://domain:5000
2. Verify devices appear in panel
3. Check username/password correct
4. Try API manually:
```bash
curl -u admin:password http://domain:5000/devices
```

### USER Can't See Assigned Server
1. Check "Manajemen Container"
2. Verify user assigned
3. Ask user to logout/login
4. Check user is not ADMIN (ADMIN sees different menu)

### Port Already in Use
1. Choose different port (5000-5999)
2. Or stop existing service:
```bash
sudo netstat -tulpn | grep :5000
sudo kill [PID]
```

---

## Docker Commands Reference

### View All Containers
```bash
docker ps -a
```

### View Logs
```bash
docker logs acs_5000
docker logs genieacs-mongo-5000
```

### Restart Container
```bash
cd /path/to/acs_containers/acs_5000
docker compose restart
```

### Stop All ACS Containers
```bash
docker ps | grep acs_ | awk '{print $1}' | xargs docker stop
```

### Remove Unused Images
```bash
docker image prune -a
```

---

## File Locations

```
crm/billing/
├── acs_install_db.php          # DB installation
├── acs_helper.php              # Backend logic
├── acs_servers_list.php        # ADMIN: Server list
├── acs_add_server.php          # ADMIN: Add server
├── acs_container_management.php # ADMIN: Manage containers
├── acs_server_info.php         # USER: View server info
├── acs_devices_list.php        # View devices
├── acs_mapping_pppoe.php       # PPPoE mapping
├── acs_optical_power.php       # Signal monitoring
├── acs_wifi_config.php         # WiFi config
├── acs_backup_config/          # Git cloned config
│   ├── config.bson
│   ├── devices.bson
│   ├── presets.bson
│   └── ...
└── acs_containers/             # Docker compose dirs
    ├── acs_5000/
    │   ├── docker-compose.yml
    │   └── mongo/
    ├── acs_5001/
    └── ...
```

---

## Database Queries

### View All Servers
```sql
SELECT * FROM acs_servers ORDER BY created_at DESC;
```

### View Devices for Server
```sql
SELECT * FROM acs_devices WHERE server_id = 1 ORDER BY last_inform DESC;
```

### View User Assignments
```sql
SELECT u.USERNAME, s.nama_server, s.port
FROM acs_user_server_assignment a
JOIN user u ON a.user_id = u.id
JOIN acs_servers s ON a.server_id = s.id;
```

### Check Online Devices
```sql
SELECT COUNT(*) FROM acs_devices WHERE status = 'ONLINE';
```

### View Sync History
```sql
SELECT * FROM acs_sync_log ORDER BY synced_at DESC LIMIT 10;
```

---

## Security Checklist

- [ ] Change default GenieACS passwords
- [ ] Restrict ports 5000-5999 to internal network only
- [ ] Set up firewall rules
- [ ] Regular database backups
- [ ] Keep Docker updated
- [ ] Monitor container logs
- [ ] Review user assignments monthly
- [ ] Use strong passwords (12+ chars)

---

## Performance Tips

1. **Regular Sync**: Sync devices every 5-15 minutes (future: cron job)
2. **Clean Logs**: Delete old sync_log entries monthly
3. **Monitor Resources**: Check Docker memory/CPU usage
4. **Limit Devices**: Each server: max 1000 devices recommended
5. **Use SSD**: Store MongoDB on SSD for better performance

---

## Support Resources

1. **System Documentation**: ACS_SYSTEM_DOCUMENTATION.md
2. **GenieACS Docs**: https://docs.genieacs.com/
3. **Docker Docs**: https://docs.docker.com/
4. **TR-069 Protocol**: ITU-T Recommendation
5. **This Guide**: Quick reference for daily operations

---

## Common Error Messages

### "Docker tidak terinstall atau tidak dapat diakses"
→ Install Docker: `sudo apt-get install docker.io docker-compose`

### "Git tidak terinstall"
→ Install Git: `sudo apt-get install git`

### "Port sudah digunakan"
→ Choose different port or stop existing service

### "Server tidak ditemukan atau akses ditolak"
→ Check user has server assigned (ADMIN only can assign)

### "Gagal mengambil data dari ACS"
→ Check GenieACS running, username/password correct

---

## Quick Reference Card

| Task | ADMIN | USER |
|------|-------|------|
| Create server | ✅ | ❌ |
| Delete server | ✅ | ❌ |
| Start/Stop container | ✅ | ❌ |
| Assign users | ✅ | ❌ |
| View all servers | ✅ | ❌ |
| View assigned server | ✅ | ✅ |
| Sync devices | ✅ | ✅ |
| View devices | ✅ | ✅ (filtered) |
| Map PPPoE | ✅ | ✅ (filtered) |
| Check optical power | ✅ | ✅ (filtered) |
| Configure WiFi | ✅ | ✅ (filtered) |
| Access all ACS panels | ✅ | ❌ |
| Docker management | ✅ | ❌ |

---

## Menu Navigation

### ADMIN Menu Path
```
Login → ADMINISTRATOR PANEL → Daftar Server ACS
Login → ADMINISTRATOR PANEL → Tambah Server ACS
Login → ADMINISTRATOR PANEL → Manajemen Container
Login → ADMINISTRATOR PANEL → Perangkat Pelanggan
Login → ADMINISTRATOR PANEL → Mapping PPPoE
Login → ADMINISTRATOR PANEL → Redaman Pelanggan
Login → ADMINISTRATOR PANEL → WiFi Configuration
```

### USER Menu Path
```
Login → INTEGRASI ACS (USER) → Informasi Server ACS
Login → INTEGRASI ACS (USER) → Perangkat Pelanggan
Login → INTEGRASI ACS (USER) → Mapping PPPoE
Login → INTEGRASI ACS (USER) → Redaman Pelanggan
Login → INTEGRASI ACS (USER) → Pengaturan WiFi
```

---

## Backup & Restore

### Backup Database
```bash
mysqldump -u root -p billing_db acs_servers acs_devices acs_pppoe_mapping acs_user_server_assignment acs_sync_log > acs_backup.sql
```

### Backup Containers
```bash
cd crm/billing/acs_containers/
tar -czf acs_backup_$(date +%Y%m%d).tar.gz acs_*/
```

### Restore Database
```bash
mysql -u root -p billing_db < acs_backup.sql
```

### Restore Containers
```bash
cd crm/billing/acs_containers/
tar -xzf acs_backup_20260310.tar.gz
cd acs_5000
docker compose up -d
```

---

## Contact & Support

For technical issues:
1. Check this guide first
2. Review ACS_SYSTEM_DOCUMENTATION.md
3. Check Docker/GenieACS logs
4. Verify system requirements met
5. Contact system administrator

---

**Last Updated:** March 10, 2026
**Version:** 1.0
**System:** ACS Role-Based Management for ISP Billing
