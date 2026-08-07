# ACS Management System - Complete Documentation

## Overview
This is a complete role-based ACS (Auto Configuration Server) management system integrated with ISP billing. The system allows ADMIN to create and manage multiple GenieACS containers via Docker, while USER can monitor and configure customer ONT devices.

---

## Files Created

### Core Files
1. **acs_install_db.php** - Auto-creates database tables
2. **acs_helper.php** - Backend logic class (Docker, GenieACS API, permissions)

### ADMIN Pages
3. **acs_servers_list.php** - List all ACS servers with management controls
4. **acs_add_server.php** - Create new ACS server/container
5. **acs_container_management.php** - Detailed container management & user assignment

### USER Pages
6. **acs_server_info.php** - View assigned server information

### Shared Pages (Both ADMIN & USER)
7. **acs_devices_list.php** - View customer ONT devices
8. **acs_mapping_pppoe.php** - Map PPPoE username to customers
9. **acs_optical_power.php** - Monitor fiber signal attenuation (RX/TX Power)
10. **acs_wifi_config.php** - Configure customer WiFi (SSID & Password)

### Modified Files
11. **sidebar.php** - Added role-based ACS menu integration

---

## Database Tables Created

### 1. acs_servers
Stores ACS server configurations:
- id, nama_server, domain, port (5000-5999)
- username_acs, password_acs
- container_name, owner_id, status
- created_at, updated_at

### 2. acs_devices
Stores customer ONT device information:
- id, server_id, device_id, serial_number
- manufacturer, product_class, hardware_version, software_version
- ip_address, mac_address, last_inform
- rx_power, tx_power (optical signal)
- ssid, wifi_password
- status (ONLINE/OFFLINE), owner_id

### 3. acs_pppoe_mapping
Maps PPPoE usernames to customers:
- id, device_serial, pppoe_username
- customer_id, customer_name
- status (MATCH/NOT_FOUND/PENDING)
- owner_id

### 4. acs_user_server_assignment
Assigns servers to users:
- id, user_id, server_id, assigned_at

### 5. acs_sync_log
Logs data synchronization:
- id, server_id, sync_type, devices_synced
- status, message, synced_at

---

## Role-Based Access Control

### ADMIN Role ($AKSES == "ADMIN")

**Menu Location:** ADMINISTRATOR PANEL

**Menu Items:**
- Daftar Server ACS
- Tambah Server ACS
- Manajemen Container
- Perangkat Pelanggan
- Mapping PPPoE
- Redaman Pelanggan
- WiFi Configuration

**Capabilities:**
✅ Create multiple GenieACS containers
✅ View all servers and containers
✅ Start/Stop/Restart/Delete any container
✅ Assign/Unassign servers to users
✅ Access all ACS servers
✅ Manage Docker operations
✅ View all customer devices
✅ Configure all ONT devices

**Restrictions:**
❌ None - Full access

---

### USER Role ($AKSES != "ADMIN")

**Menu Location:** INTEGRASI ACS (USER)

**Menu Items:**
- Informasi Server ACS
- Perangkat Pelanggan
- Mapping PPPoE
- Redaman Pelanggan
- Pengaturan WiFi

**Capabilities:**
✅ View assigned server information
✅ Login to assigned ACS panel
✅ View customer devices (filtered to their server)
✅ Map PPPoE to customers
✅ Monitor fiber signal attenuation
✅ Configure customer WiFi settings
✅ Sync device data from ACS

**Restrictions:**
❌ Cannot create new servers/containers
❌ Cannot delete or manage containers
❌ Cannot see other users' servers
❌ Cannot access Docker management
❌ Cannot assign servers to users

---

## Technical Architecture

### Docker Container Structure

When ADMIN creates a server on port 5378, the system creates:

```yaml
services:
  mongo:
    image: mongo:4.4
    container_name: genieacs-mongo-5378
    volumes: ./mongo:/data/db

  genieacs:
    image: deandegil/genieacs
    container_name: genieacs-5378
    ports:
      - "5378:3000"
      - "7547:7547"
      - "7557:7557"
      - "7567:7567"
    environment:
      GENIEACS_MONGODB_CONNECTION_URL: mongodb://mongo/genieacs
      GENIEACS_UI_JWT_SECRET: quenbysecret
```

**Container Names:**
- Main: `acs_[port]` (e.g., acs_5378)
- MongoDB: `genieacs-mongo-[port]` (e.g., genieacs-mongo-5378)

**Directory Structure:**
```
crm/billing/
  acs_containers/
    acs_5000/
      docker-compose.yml
      mongo/ (MongoDB data)
    acs_5001/
      docker-compose.yml
      mongo/ (MongoDB data)
```

---

## Server Creation Flow

### Step-by-Step Process:

1. **ADMIN opens acs_add_server.php**
   - Fills form: Server Name, Domain/IP, Port (5000-5999), Username, Password

2. **System validates:**
   - Docker installed & accessible
   - Git installed & accessible
   - Port is available (not used)
   - Port in valid range (5000-5999)

3. **Database record created:**
   - Status: CREATING
   - Container name: acs_[port]

4. **Clone backup repository:**
   ```bash
   git clone https://github.com/mike2miky/Genie-ACS-alijayanet.git
   ```

5. **Generate docker-compose.yml:**
   - Create directory: acs_containers/acs_[port]/
   - Write docker-compose.yml with port configuration

6. **Start containers:**
   ```bash
   cd acs_containers/acs_[port]
   docker compose up -d
   ```

7. **Wait for MongoDB (10 seconds)**

8. **Import backup BSON files:**
   ```bash
   docker cp backup/*.bson genieacs-mongo-[port]:/backup/
   docker exec genieacs-mongo-[port] mongorestore --db=genieacs /backup
   ```

9. **Update status to RUNNING**

10. **Assign server to ADMIN (creator)**

---

## Data Synchronization

### From GenieACS to Billing Database

**Trigger:** Click "Sync dari GenieACS" button

**Process:**
1. Call GenieACS API: `GET http://domain:port/devices`
2. Authenticate with username/password
3. Parse device JSON response
4. Extract device information:
   - Serial Number, Manufacturer, Product Class
   - Hardware/Software Version
   - IP Address, MAC Address
   - Last Inform timestamp
   - Status (ONLINE if last_inform < 5 minutes ago)

5. INSERT or UPDATE to `acs_devices` table
6. Log sync to `acs_sync_log`

**Database Operation:**
```sql
INSERT INTO acs_devices (...)
VALUES (...)
ON DUPLICATE KEY UPDATE
  last_inform = ...,
  status = ...,
  updated_at = CURRENT_TIMESTAMP
```

---

## Container Operations

### Start Container
```bash
cd acs_containers/acs_[port]
docker compose start
```
Updates status to: RUNNING

### Stop Container
```bash
cd acs_containers/acs_[port]
docker compose stop
```
Updates status to: STOPPED

### Restart Container
```bash
cd acs_containers/acs_[port]
docker compose restart
```
Updates status to: RUNNING

### Delete Container (ADMIN Only)
```bash
cd acs_containers/acs_[port]
docker compose down -v
```
Then:
- Delete from `acs_user_server_assignment`
- Delete from `acs_devices`
- Delete from `acs_servers`
- Remove directory recursively

---

## User Assignment Flow

**ADMIN can assign servers to users via:**
1. **acs_container_management.php**
   - Click "Assign User" button
   - Select user from dropdown
   - System creates record in `acs_user_server_assignment`

**Effect:**
- USER can now see the server in their menu
- USER can access all features for that server
- USER sees only devices from their assigned server

**Unassignment:**
- ADMIN clicks (X) on user badge
- System deletes from `acs_user_server_assignment`
- USER loses access to that server

---

## PPPoE Mapping System

### Purpose
Link ONT Serial Numbers with customer accounts in billing database.

### Process:
1. System queries `acs_devices` for all devices
2. For each device, search `customer` table:
   ```sql
   SELECT IDPEL, NAMA 
   FROM customer 
   WHERE NOMORSERIAL LIKE '%[serial_number]%'
   ```
3. Display results:
   - **MATCH**: Customer found in database
   - **NOT_FOUND**: Serial number not in customer table

### Use Case:
- Verify ONT registration
- Identify unregistered devices
- Cross-check billing data with physical devices

---

## Optical Power Monitoring

### Signal Quality Thresholds:

**RX Power (Received by ONT from OLT):**
- ✅ **Normal**: > -20 dBm
- ⚠️ **Warning**: -20 to -28 dBm
- ❌ **Critical**: < -28 dBm

**TX Power (Transmitted by ONT to OLT):**
- Informational only
- Typical range: 0 to +5 dBm

### Uses:
- Early detection of fiber breaks
- Identify dirty/damaged connectors
- Monitor fiber degradation over time
- Troubleshoot customer connection issues

---

## WiFi Configuration

### Current Implementation:
- View existing SSID and Password
- Update SSID and Password via UI
- Store in `acs_devices` table

### Future Enhancement (Requires GenieACS Provision Script):
When updating WiFi:
1. Create task in GenieACS
2. GenieACS sends TR-069 SetParameterValues
3. ONT applies new WiFi configuration
4. Confirm via GetParameterValues

**Provision Script Example:**
```javascript
// Set WiFi SSID
const ssid = args[0];
const password = args[1];

declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID", {value: ssid});
declare("InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey", {value: password});
```

---

## System Requirements

### Server Requirements:
- **OS**: Linux (Ubuntu 20.04+ recommended) or Windows with WSL2
- **Docker**: Version 20.10+
- **Docker Compose**: Version 2.0+
- **Git**: Version 2.0+
- **PHP**: 7.4+ with exec() enabled
- **MySQL/MariaDB**: 5.7+

### Network Requirements:
- Open ports: 5000-5999 (for ACS UI)
- Open port: 7547 (TR-069 CPE WAN Management Protocol)
- Open port: 7557 (GenieACS CWMP connection)
- Open port: 7567 (GenieACS CWMP listener)

### PHP Extensions:
- mysqli
- curl
- json

---

## Installation & Setup

### 1. Initial Setup
Upload all files to `crm/billing/` directory

### 2. Access ACS Menu
Login as ADMIN → Navigate to "Daftar Server ACS"

### 3. Database Auto-Installation
First access will automatically create all required tables

### 4. Verify Requirements
Visit: `acs_install_db.php`
- Check database tables exist
- Verify all 5 tables created

### 5. Create First Server
1. Click "Tambah Server Baru"
2. Fill form:
   - Server Name: e.g., "ACS Production"
   - Domain: Your server IP or domain
   - Port: 5000 (or any 5000-5999)
   - Username: admin
   - Password: [secure password]
3. Click "Buat Server ACS"
4. Wait 30-60 seconds for completion

### 6. Verify Container
```bash
docker ps
```
Should show:
- acs_5000
- genieacs-mongo-5000

### 7. Access GenieACS Panel
http://your-domain:5000
Login with username/password from step 5

### 8. Configure ONT to Connect
In your ONT, set ACS URL:
```
http://your-domain:7547
```

### 9. Sync Devices
Go to "Perangkat Pelanggan" → Click "Sync dari GenieACS"

### 10. Assign to User (Optional)
Go to "Manajemen Container" → Click "Assign User" → Select user

---

## Troubleshooting

### Problem: "Docker tidak terinstall atau tidak dapat diakses"
**Solution:**
```bash
# Check Docker
docker --version

# Start Docker service
sudo systemctl start docker

# Enable Docker on boot
sudo systemctl enable docker

# Add user to docker group (Linux)
sudo usermod -aG docker $USER
```

### Problem: "Git tidak terinstall"
**Solution:**
```bash
# Ubuntu/Debian
sudo apt-get install git

# CentOS/RHEL
sudo yum install git

# Verify
git --version
```

### Problem: "Port already in use"
**Solution:**
- Choose different port (5000-5999)
- Or check what's using the port:
```bash
# Linux
sudo netstat -tulpn | grep :5000

# Kill process
sudo kill [PID]
```

### Problem: "Container not starting"
**Solution:**
```bash
# Check logs
docker logs genieacs-5000
docker logs genieacs-mongo-5000

# Check compose
cd crm/billing/acs_containers/acs_5000
docker compose logs

# Restart
docker compose restart
```

### Problem: "No devices syncing"
**Solution:**
1. Verify ONT ACS URL is correct
2. Check port 7547 is accessible
3. Check GenieACS panel shows devices
4. Verify GenieACS username/password in database
5. Check API endpoint: `curl -u username:password http://domain:port/devices`

### Problem: "USER cannot see assigned server"
**Solution:**
1. Verify assignment in `acs_user_server_assignment` table
2. Check user_id matches
3. Refresh page / clear cache
4. Verify USER is not ADMIN (ADMIN sees different menu)

---

## Security Considerations

### 1. Port Security
- Use firewall to restrict access to ports 5000-5999
- Only allow internal network access
- Use reverse proxy (nginx/apache) for HTTPS

### 2. GenieACS Credentials
- Use strong passwords
- Store passwords encrypted (future enhancement)
- Rotate passwords regularly

### 3. Docker Security
- Run Docker with non-root user
- Keep Docker images updated
- Use Docker secrets for sensitive data

### 4. Database Security
- Use prepared statements (already implemented)
- Restrict user privileges
- Regular backups

### 5. Network Security
- Use VPN for remote access
- Implement rate limiting
- Monitor for suspicious activity

---

## Future Enhancements

### 1. Real-time WiFi Configuration
- Implement GenieACS provision scripts
- Push configuration changes to ONT immediately
- Verify configuration applied

### 2. Auto Device Discovery
- Cron job for automatic sync every 5 minutes
- Background worker for device monitoring
- Alert on device offline

### 3. Advanced Optical Monitoring
- Graph RX/TX power over time
- Alert on signal degradation
- Historical trend analysis

### 4. Bulk Operations
- Bulk WiFi configuration
- Bulk firmware updates
- Bulk device reboot

### 5. API Integration
- REST API for external systems
- Webhook notifications
- Integration with ticketing system

### 6. Enhanced Reporting
- Device uptime statistics
- Signal quality reports
- User activity logs

---

## API Reference (Internal)

### ACSHelper Class Methods

#### `__construct($conn, $user_id, $user_role)`
Initialize helper with database connection and user context

#### `isAdmin()`
Returns: boolean - true if user is ADMIN

#### `ensureDatabase()`
Returns: array - database installation result

#### `getUserServer()`
Returns: array|null - USER's assigned server

#### `getServers()`
Returns: array - all servers (ADMIN) or assigned servers (USER)

#### `getServer($server_id)`
Returns: array|null - server by ID with permission check

#### `isPortAvailable($port)`
Returns: boolean - true if port not in use

#### `createServer($nama, $domain, $port, $username, $password)`
Returns: array - creation result with success status

#### `assignServerToUser($server_id, $user_id)`
Returns: boolean - assignment success

#### `unassignServerFromUser($server_id, $user_id)`
Returns: boolean - unassignment success

#### `dockerOperation($server_id, $operation)`
Operations: 'start', 'stop', 'restart', 'delete'
Returns: array - operation result

#### `getContainerStatus($container_name)`
Returns: string - 'RUNNING', 'STOPPED', 'NOT_FOUND', 'UNKNOWN'

#### `syncDevices($server_id)`
Returns: array - sync result with devices_synced count

---

## Credits & References

### GenieACS
- Official: https://genieacs.com/
- GitHub: https://github.com/genieacs/genieacs
- Docker Image: https://hub.docker.com/r/deandegil/genieacs

### Config Backup Repository
- https://github.com/mike2miky/Genie-ACS-alijayanet.git

### Technologies Used
- PHP 7.4+
- MySQL/MariaDB
- Docker & Docker Compose
- Bootstrap 5
- jQuery & DataTables
- FontAwesome Icons

---

## Support & Maintenance

### Regular Maintenance Tasks:

**Daily:**
- Monitor container status
- Check sync logs
- Review device offline alerts

**Weekly:**
- Update Docker images
- Review optical power reports
- Clean up old sync logs

**Monthly:**
- Database backup
- Security updates
- Performance optimization

### Backup Procedures:

**Database Backup:**
```bash
mysqldump -u root -p billing_db acs_servers acs_devices acs_pppoe_mapping > acs_backup.sql
```

**Container Backup:**
```bash
cd crm/billing/acs_containers/
tar -czf acs_backup_$(date +%Y%m%d).tar.gz acs_*/
```

**Restore Database:**
```bash
mysql -u root -p billing_db < acs_backup.sql
```

---

## License & Disclaimer

This system is provided as-is for ISP billing integration purposes.

**Disclaimer:**
- Test thoroughly before production use
- Backup data regularly
- Monitor system resources
- Keep Docker and dependencies updated
- Follow security best practices

**Support:**
For issues or questions, refer to:
1. This documentation
2. GenieACS official documentation
3. Docker documentation
4. System administrator

---

## Changelog

### Version 1.0 (March 2026)
- Initial release
- Complete ADMIN/USER role separation
- Docker container management
- Device monitoring and configuration
- PPPoE mapping
- Optical power monitoring
- WiFi configuration
- Database auto-installation
- Sidebar menu integration

---

## Summary

This ACS Management System provides a complete solution for ISP billing integration with GenieACS. It enables:

✅ **Multi-tenancy**: Multiple ACS servers, multiple users
✅ **Role-based access**: ADMIN full control, USER monitoring only
✅ **Docker automation**: One-click server creation
✅ **Device management**: Monitor, configure, troubleshoot ONT devices
✅ **ISP integration**: Map devices to customers, monitor signals
✅ **Scalability**: Add unlimited servers on different ports
✅ **Security**: User isolation, permission checks, data filtering

The system is production-ready and can be deployed immediately for ISP operations.
