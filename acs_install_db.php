<?php
/**
 * ACS Database Installation Script
 * Auto-creates database tables for ACS integration
 * Called automatically when accessing ACS menu
 */

function installACSDatabase($conn) {
    $tables_created = [];
    $errors = [];

    // Table 1: acs_servers - Store ACS server configurations
    $sql_servers = "CREATE TABLE IF NOT EXISTS `acs_servers` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `nama_server` VARCHAR(255) NOT NULL,
        `domain` VARCHAR(255) NOT NULL,
        `port` INT(11) NOT NULL,
        `username_acs` VARCHAR(255) NOT NULL,
        `password_acs` VARCHAR(255) NOT NULL,
        `container_name` VARCHAR(255) NOT NULL,
        `owner_id` INT(11) NOT NULL,
        `status` ENUM('RUNNING', 'STOPPED', 'CREATING', 'ERROR') DEFAULT 'STOPPED',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `port` (`port`),
        UNIQUE KEY `container_name` (`container_name`),
        KEY `owner_id` (`owner_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    if (mysqli_query($conn, $sql_servers)) {
        $tables_created[] = 'acs_servers';
    } else {
        $errors[] = 'acs_servers: ' . mysqli_error($conn);
    }

    // Table 2: acs_devices - Store customer ONT devices
    $sql_devices = "CREATE TABLE IF NOT EXISTS `acs_devices` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `server_id` INT(11) NOT NULL,
        `device_id` VARCHAR(255) NOT NULL,
        `serial_number` VARCHAR(255) NOT NULL,
        `manufacturer` VARCHAR(255) DEFAULT NULL,
        `oui` VARCHAR(255) DEFAULT NULL,
        `product_class` VARCHAR(255) DEFAULT NULL,
        `hardware_version` VARCHAR(255) DEFAULT NULL,
        `software_version` VARCHAR(255) DEFAULT NULL,
        `ip_address` VARCHAR(255) DEFAULT NULL,
        `mac_address` VARCHAR(255) DEFAULT NULL,
        `last_inform` DATETIME DEFAULT NULL,
        `rx_power` VARCHAR(50) DEFAULT NULL,
        `tx_power` VARCHAR(50) DEFAULT NULL,
        `ssid` VARCHAR(255) DEFAULT NULL,
        `wifi_password` VARCHAR(255) DEFAULT NULL,
        `status` ENUM('ONLINE', 'OFFLINE') DEFAULT 'OFFLINE',
        `owner_id` INT(11) NOT NULL,
        `virtual_parameters` LONGTEXT DEFAULT NULL COMMENT 'JSON data dari GenieACS NBI VirtualParameters',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `device_serial` (`server_id`, `serial_number`),
        KEY `serial_number` (`serial_number`),
        KEY `server_id` (`server_id`),
        KEY `owner_id` (`owner_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    if (mysqli_query($conn, $sql_devices)) {
        $tables_created[] = 'acs_devices';
    } else {
        $errors[] = 'acs_devices: ' . mysqli_error($conn);
    }

    // Table 3: acs_pppoe_mapping - Mapping PPPoE username to customer ID
    $sql_mapping = "CREATE TABLE IF NOT EXISTS `acs_pppoe_mapping` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `device_serial` VARCHAR(255) NOT NULL,
        `pppoe_username` VARCHAR(255) NOT NULL,
        `customer_id` INT(11) DEFAULT NULL,
        `customer_name` VARCHAR(255) DEFAULT NULL,
        `status` ENUM('MATCH', 'NOT_FOUND', 'PENDING') DEFAULT 'PENDING',
        `owner_id` INT(11) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `device_pppoe` (`device_serial`, `pppoe_username`),
        KEY `pppoe_username` (`pppoe_username`),
        KEY `customer_id` (`customer_id`),
        KEY `owner_id` (`owner_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    if (mysqli_query($conn, $sql_mapping)) {
        $tables_created[] = 'acs_pppoe_mapping';
    } else {
        $errors[] = 'acs_pppoe_mapping: ' . mysqli_error($conn);
    }

    // Table 4: acs_user_server_assignment - Assign server to user
    $sql_assignment = "CREATE TABLE IF NOT EXISTS `acs_user_server_assignment` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `server_id` INT(11) NOT NULL,
        `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_server` (`user_id`, `server_id`),
        KEY `user_id` (`user_id`),
        KEY `server_id` (`server_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    if (mysqli_query($conn, $sql_assignment)) {
        $tables_created[] = 'acs_user_server_assignment';
    } else {
        $errors[] = 'acs_user_server_assignment: ' . mysqli_error($conn);
    }

    // Table 5: acs_sync_log - Log for data synchronization
    $sql_sync_log = "CREATE TABLE IF NOT EXISTS `acs_sync_log` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `server_id` INT(11) NOT NULL,
        `sync_type` VARCHAR(50) NOT NULL,
        `devices_synced` INT(11) DEFAULT 0,
        `status` ENUM('SUCCESS', 'FAILED', 'PARTIAL') DEFAULT 'SUCCESS',
        `message` TEXT DEFAULT NULL,
        `synced_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `server_id` (`server_id`),
        KEY `synced_at` (`synced_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    if (mysqli_query($conn, $sql_sync_log)) {
        $tables_created[] = 'acs_sync_log';
    } else {
        $errors[] = 'acs_sync_log: ' . mysqli_error($conn);
    }

    // Ensure virtual_parameters column exists in acs_devices (migration for existing tables)
    $check_column = mysqli_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='acs_devices' AND column_name='virtual_parameters' AND table_schema=DATABASE()");
    
    if (!mysqli_fetch_array($check_column)) {
        // Column doesn't exist, add it
        $sql_alter = "ALTER TABLE `acs_devices` ADD COLUMN `virtual_parameters` LONGTEXT DEFAULT NULL COMMENT 'JSON data dari GenieACS NBI VirtualParameters' AFTER `owner_id`";
        
        if (mysqli_query($conn, $sql_alter)) {
            $tables_created[] = 'acs_devices (migrated - added virtual_parameters column)';
        } else {
            $errors[] = 'acs_devices migration: ' . mysqli_error($conn);
        }
    }

    return [
        'success' => count($errors) === 0,
        'tables_created' => $tables_created,
        'errors' => $errors
    ];
}

/**
 * Migrate acs_servers columns needed for external (non-Docker) ACS servers.
 * Runs unconditionally (unlike installACSDatabase, which only runs when a
 * required table is missing) so it also applies to already-provisioned DBs.
 */
function migrateACSServersColumns($conn) {
    $migrations = [];

    $tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'acs_servers'");
    if (!$tbl_check || mysqli_num_rows($tbl_check) === 0) {
        return $migrations;
    }

    $add_column = function ($column, $definition) use ($conn, &$migrations) {
        $check = mysqli_query($conn, "SELECT column_name FROM information_schema.columns WHERE table_name='acs_servers' AND column_name='" . $column . "' AND table_schema=DATABASE()");
        if ($check && !mysqli_fetch_array($check)) {
            if (mysqli_query($conn, "ALTER TABLE `acs_servers` ADD COLUMN `$column` $definition")) {
                $migrations[] = "acs_servers.$column added";
            }
        }
    };

    $add_column('allowed_ips', 'TEXT NULL AFTER password_acs');
    $add_column('is_external', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status');
    $add_column('ui_port', 'INT(11) NULL AFTER is_external');
    $add_column('cwmp_port', 'INT(11) NULL AFTER ui_port');
    $add_column('nbi_port', 'INT(11) NULL AFTER cwmp_port');
    $add_column('fs_port', 'INT(11) NULL AFTER nbi_port');

    // Docker-provisioned servers reserve a fixed 4-port bundle; external servers
    // may reuse common default ports (3000/7547/7557/7567) across many rows, so
    // the old single-column UNIQUE keys must be relaxed to plain indexes.
    foreach (['port', 'container_name'] as $key_name) {
        $index_check = mysqli_query($conn, "SHOW INDEX FROM acs_servers WHERE Key_name = '$key_name' AND Non_unique = 0");
        if ($index_check && mysqli_fetch_array($index_check)) {
            if (mysqli_query($conn, "ALTER TABLE `acs_servers` DROP INDEX `$key_name`, ADD INDEX `$key_name` (`$key_name`)")) {
                $migrations[] = "acs_servers.$key_name unique constraint relaxed";
            }
        }
    }

    // Backfill ui/cwmp/nbi/fs ports for existing Docker-provisioned rows (sequential scheme)
    mysqli_query($conn, "UPDATE acs_servers SET ui_port = port, cwmp_port = port + 1, nbi_port = port + 2, fs_port = port + 3 WHERE is_external = 0 AND (ui_port IS NULL OR cwmp_port IS NULL OR nbi_port IS NULL OR fs_port IS NULL)");

    return $migrations;
}

function checkACSTablesExist($conn) {
    $required_tables = [
        'acs_servers',
        'acs_devices',
        'acs_pppoe_mapping',
        'acs_user_server_assignment',
        'acs_sync_log'
    ];

    $existing_tables = [];
    $result = mysqli_query($conn, "SHOW TABLES");
    while ($row = mysqli_fetch_array($result)) {
        $existing_tables[] = $row[0];
    }

    $missing_tables = array_diff($required_tables, $existing_tables);
    
    return [
        'all_exist' => count($missing_tables) === 0,
        'missing' => $missing_tables,
        'existing' => array_intersect($required_tables, $existing_tables)
    ];
}

// Auto-install if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once 'cek-sesi.php';
    
    // Only ADMIN can manually run this
    if ($AKSES !== 'ADMIN') {
        die('Access denied. Only ADMIN can run database installation.');
    }

    $check = checkACSTablesExist($conn);
    
    if (!$check['all_exist']) {
        echo "<h3>ACS Database Installation</h3>";
        echo "<p>Missing tables: " . implode(', ', $check['missing']) . "</p>";
        echo "<p>Installing...</p>";
        
        $result = installACSDatabase($conn);
        
        if ($result['success']) {
            echo "<p style='color:green;'>✓ Database tables created successfully:</p>";
            echo "<ul>";
            foreach ($result['tables_created'] as $table) {
                echo "<li>$table</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color:red;'>✗ Errors occurred:</p>";
            echo "<ul>";
            foreach ($result['errors'] as $error) {
                echo "<li>$error</li>";
            }
            echo "</ul>";
        }
    } else {
        echo "<p style='color:green;'>✓ All ACS tables already exist:</p>";
        echo "<ul>";
        foreach ($check['existing'] as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
    }
    
    echo "<p><a href='acs_servers_list.php'>Go to ACS Server List</a></p>";
}
?>
