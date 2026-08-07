<?php
if (!function_exists('komisi_month_names')) {
    function komisi_month_names()
    {
        return [
            '01' => 'Januari',
            '02' => 'Februari',
            '03' => 'Maret',
            '04' => 'April',
            '05' => 'Mei',
            '06' => 'Juni',
            '07' => 'Juli',
            '08' => 'Agustus',
            '09' => 'September',
            '10' => 'Oktober',
            '11' => 'November',
            '12' => 'Desember'
        ];
    }
}

if (!function_exists('komisi_build_period_label')) {
    function komisi_build_period_label($month, $year)
    {
        $names = komisi_month_names();
        $key = str_pad((string) ((int) $month), 2, '0', STR_PAD_LEFT);
        return ($names[$key] ?? $key) . ' ' . (int) $year;
    }
}

if (!function_exists('komisi_previous_period')) {
    function komisi_previous_period()
    {
        $ts = strtotime(date('Y-m-01') . ' -1 month');
        return [(int) date('m', $ts), (int) date('Y', $ts)];
    }
}

if (!function_exists('komisi_normalize_mode')) {
    function komisi_normalize_mode($mode)
    {
        return $mode === 'nominal' ? 'nominal' : 'persen';
    }
}

if (!function_exists('komisi_escape_like_literal')) {
    function komisi_escape_like_literal($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
    }
}

if (!function_exists('komisi_ensure_schema')) {
    function komisi_ensure_schema(mysqli $conn)
    {
        $existing = [];
        $res = $conn->query("SHOW COLUMNS FROM transaksi_komisi");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $existing[strtolower((string) $row['Field'])] = true;
            }
        }

        $columns = [
            'jabatan' => "ALTER TABLE transaksi_komisi ADD COLUMN jabatan VARCHAR(100) DEFAULT NULL",
            'kecamatan' => "ALTER TABLE transaksi_komisi ADD COLUMN kecamatan VARCHAR(100) DEFAULT NULL",
            'kelurahan' => "ALTER TABLE transaksi_komisi ADD COLUMN kelurahan VARCHAR(100) DEFAULT NULL",
            'rw' => "ALTER TABLE transaksi_komisi ADD COLUMN rw VARCHAR(10) DEFAULT NULL",
            'rt' => "ALTER TABLE transaksi_komisi ADD COLUMN rt VARCHAR(10) DEFAULT NULL",
            'total_pelanggan' => "ALTER TABLE transaksi_komisi ADD COLUMN total_pelanggan INT DEFAULT NULL",
            'komisi_kategori' => "ALTER TABLE transaksi_komisi ADD COLUMN komisi_kategori VARCHAR(20) DEFAULT NULL"
        ];

        foreach ($columns as $column => $sql) {
            if (!isset($existing[$column])) {
                $conn->query($sql);
            }
        }
    }
}

if (!function_exists('komisi_ensure_cron_settings_table')) {
    function komisi_ensure_cron_settings_table(mysqli $conn)
    {
        $sql = "CREATE TABLE IF NOT EXISTS komisi_cron_settings (
            id INT NOT NULL AUTO_INCREMENT,
            owner VARCHAR(255) NOT NULL,
            auto_regular_enabled TINYINT(1) NOT NULL DEFAULT 0,
            auto_area_enabled TINYINT(1) NOT NULL DEFAULT 0,
            auto_awal_enabled TINYINT(1) NOT NULL DEFAULT 0,
            regular_mode VARCHAR(20) NOT NULL DEFAULT 'persen',
            area_mode VARCHAR(20) NOT NULL DEFAULT 'persen',
            awal_mode VARCHAR(20) NOT NULL DEFAULT 'persen',
            run_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
            run_hour TINYINT UNSIGNED NOT NULL DEFAULT 1,
            run_minute TINYINT UNSIGNED NOT NULL DEFAULT 0,
            regular_run_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
            regular_run_hour TINYINT UNSIGNED NOT NULL DEFAULT 1,
            regular_run_minute TINYINT UNSIGNED NOT NULL DEFAULT 0,
            area_run_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
            area_run_hour TINYINT UNSIGNED NOT NULL DEFAULT 1,
            area_run_minute TINYINT UNSIGNED NOT NULL DEFAULT 0,
            awal_run_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
            awal_run_hour TINYINT UNSIGNED NOT NULL DEFAULT 1,
            awal_run_minute TINYINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_owner (owner)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($sql);

        $existing = [];
        $res = $conn->query("SHOW COLUMNS FROM komisi_cron_settings");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $existing[strtolower((string) ($row['Field'] ?? ''))] = true;
            }
        }

        $columns = [
            'auto_awal_enabled' => "ALTER TABLE komisi_cron_settings ADD COLUMN auto_awal_enabled TINYINT(1) NOT NULL DEFAULT 0",
            'awal_mode' => "ALTER TABLE komisi_cron_settings ADD COLUMN awal_mode VARCHAR(20) NOT NULL DEFAULT 'persen'",
            'regular_run_day' => "ALTER TABLE komisi_cron_settings ADD COLUMN regular_run_day TINYINT UNSIGNED NOT NULL DEFAULT 1",
            'regular_run_hour' => "ALTER TABLE komisi_cron_settings ADD COLUMN regular_run_hour TINYINT UNSIGNED NOT NULL DEFAULT 1",
            'regular_run_minute' => "ALTER TABLE komisi_cron_settings ADD COLUMN regular_run_minute TINYINT UNSIGNED NOT NULL DEFAULT 0",
            'area_run_day' => "ALTER TABLE komisi_cron_settings ADD COLUMN area_run_day TINYINT UNSIGNED NOT NULL DEFAULT 1",
            'area_run_hour' => "ALTER TABLE komisi_cron_settings ADD COLUMN area_run_hour TINYINT UNSIGNED NOT NULL DEFAULT 1",
            'area_run_minute' => "ALTER TABLE komisi_cron_settings ADD COLUMN area_run_minute TINYINT UNSIGNED NOT NULL DEFAULT 0",
            'awal_run_day' => "ALTER TABLE komisi_cron_settings ADD COLUMN awal_run_day TINYINT UNSIGNED NOT NULL DEFAULT 1",
            'awal_run_hour' => "ALTER TABLE komisi_cron_settings ADD COLUMN awal_run_hour TINYINT UNSIGNED NOT NULL DEFAULT 1",
            'awal_run_minute' => "ALTER TABLE komisi_cron_settings ADD COLUMN awal_run_minute TINYINT UNSIGNED NOT NULL DEFAULT 0"
        ];
        foreach ($columns as $column => $alterSql) {
            if (!isset($existing[$column])) {
                $conn->query($alterSql);
            }
        }
    }
}

if (!function_exists('komisi_ensure_awal_claims_table')) {
    function komisi_ensure_awal_claims_table(mysqli $conn)
    {
        $sql = "CREATE TABLE IF NOT EXISTS komisi_awal_claims (
            id INT NOT NULL AUTO_INCREMENT,
            owner VARCHAR(255) NOT NULL,
            sales_name VARCHAR(255) DEFAULT NULL,
            idpel VARCHAR(100) NOT NULL,
            periode VARCHAR(100) DEFAULT NULL,
            tanggal_bayar_awal DATE DEFAULT NULL,
            transaksi_komisi_id INT DEFAULT NULL,
            claim_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_owner_idpel (owner, idpel),
            KEY idx_owner_status (owner, claim_status),
            KEY idx_transaksi (transaksi_komisi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($sql);
    }
}

if (!function_exists('komisi_awal_claim_upsert_list')) {
    function komisi_awal_claim_upsert_list(mysqli $conn, $owner, $salesName, $periode, $transaksiKomisiId, $status, array $claimRows)
    {
        komisi_ensure_awal_claims_table($conn);

        $owner = trim((string) $owner);
        $salesName = trim((string) $salesName);
        $periode = trim((string) $periode);
        $status = $status === 'berhasil' ? 'berhasil' : 'pending';
        $transaksiKomisiId = (int) $transaksiKomisiId;
        if ($owner === '' || $transaksiKomisiId <= 0) {
            return;
        }

        foreach ($claimRows as $row) {
            $idpel = trim((string) ($row['idpel'] ?? ''));
            if ($idpel === '') {
                continue;
            }
            $tgl = trim((string) ($row['tanggal_bayar_awal'] ?? ''));
            $tgl = $tgl !== '' ? substr($tgl, 0, 10) : null;

            $sel = $conn->prepare("SELECT id, claim_status FROM komisi_awal_claims WHERE owner = ? AND idpel = ? LIMIT 1");
            if (!$sel) {
                continue;
            }
            $sel->bind_param('ss', $owner, $idpel);
            $sel->execute();
            $res = $sel->get_result();
            $existing = $res ? $res->fetch_assoc() : null;
            $sel->close();

            if ($existing) {
                $existingStatus = trim((string) ($existing['claim_status'] ?? 'pending'));
                if ($existingStatus === 'berhasil') {
                    continue;
                }
                $id = (int) $existing['id'];
                $upd = $conn->prepare("UPDATE komisi_awal_claims SET sales_name = ?, periode = ?, tanggal_bayar_awal = ?, transaksi_komisi_id = ?, claim_status = ? WHERE id = ?");
                if (!$upd) {
                    continue;
                }
                $upd->bind_param('sssisi', $salesName, $periode, $tgl, $transaksiKomisiId, $status, $id);
                $upd->execute();
                $upd->close();
                continue;
            }

            $ins = $conn->prepare("INSERT INTO komisi_awal_claims (owner, sales_name, idpel, periode, tanggal_bayar_awal, transaksi_komisi_id, claim_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            if (!$ins) {
                continue;
            }
            $ins->bind_param('sssssis', $owner, $salesName, $idpel, $periode, $tgl, $transaksiKomisiId, $status);
            $ins->execute();
            $ins->close();
        }
    }
}

if (!function_exists('komisi_awal_claim_mark_berhasil_by_transaksi_ids')) {
    function komisi_awal_claim_mark_berhasil_by_transaksi_ids(mysqli $conn, $owner, array $transaksiIds)
    {
        komisi_ensure_awal_claims_table($conn);

        $ids = array_values(array_unique(array_filter(array_map('intval', $transaksiIds))));
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "UPDATE komisi_awal_claims SET claim_status = 'berhasil' WHERE owner = ? AND transaksi_komisi_id IN ({$placeholders})";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $types = 's' . str_repeat('i', count($ids));
        $params = array_merge([(string) $owner], $ids);
        $refs = [];
        $refs[] = &$types;
        foreach ($params as $k => $val) {
            $refs[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('komisi_awal_claim_delete_pending_by_transaksi_ids')) {
    function komisi_awal_claim_delete_pending_by_transaksi_ids(mysqli $conn, $owner, array $transaksiIds)
    {
        komisi_ensure_awal_claims_table($conn);

        $ids = array_values(array_unique(array_filter(array_map('intval', $transaksiIds))));
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM komisi_awal_claims WHERE owner = ? AND claim_status = 'pending' AND transaksi_komisi_id IN ({$placeholders})";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }

        $types = 's' . str_repeat('i', count($ids));
        $params = array_merge([(string) $owner], $ids);
        $refs = [];
        $refs[] = &$types;
        foreach ($params as $k => $val) {
            $refs[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('komisi_awal_claim_sync_status')) {
    function komisi_awal_claim_sync_status(mysqli $conn, $owner)
    {
        komisi_ensure_awal_claims_table($conn);

        $owner = trim((string) $owner);
        if ($owner === '') {
            return;
        }

        $sql = "UPDATE komisi_awal_claims kac
                INNER JOIN transaksi_komisi tk ON tk.id = kac.transaksi_komisi_id
                SET kac.claim_status = 'berhasil'
                WHERE kac.owner = ?
                  AND kac.claim_status <> 'berhasil'
                  AND tk.status = 'berhasil'";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $owner);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('komisi_get_cron_settings')) {
    function komisi_get_cron_settings(mysqli $conn, $owner)
    {
        komisi_ensure_cron_settings_table($conn);

        $defaults = [
            'owner' => (string) $owner,
            'auto_regular_enabled' => 0,
            'auto_area_enabled' => 0,
            'auto_awal_enabled' => 0,
            'regular_mode' => 'persen',
            'area_mode' => 'persen',
            'awal_mode' => 'persen',
            'run_day' => 1,
            'run_hour' => 1,
            'run_minute' => 0,
            'regular_run_day' => 1,
            'regular_run_hour' => 1,
            'regular_run_minute' => 0,
            'area_run_day' => 1,
            'area_run_hour' => 1,
            'area_run_minute' => 0,
            'awal_run_day' => 1,
            'awal_run_hour' => 1,
            'awal_run_minute' => 0
        ];

        $stmt = $conn->prepare("SELECT owner, auto_regular_enabled, auto_area_enabled, auto_awal_enabled, regular_mode, area_mode, awal_mode, run_day, run_hour, run_minute, regular_run_day, regular_run_hour, regular_run_minute, area_run_day, area_run_hour, area_run_minute, awal_run_day, awal_run_hour, awal_run_minute FROM komisi_cron_settings WHERE owner = ? LIMIT 1");
        if (!$stmt) {
            return $defaults;
        }

        $stmt->bind_param('s', $owner);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            return $defaults;
        }

        return [
            'owner' => (string) ($row['owner'] ?? $owner),
            'auto_regular_enabled' => (int) ($row['auto_regular_enabled'] ?? 0),
            'auto_area_enabled' => (int) ($row['auto_area_enabled'] ?? 0),
            'auto_awal_enabled' => (int) ($row['auto_awal_enabled'] ?? 0),
            'regular_mode' => komisi_normalize_mode($row['regular_mode'] ?? 'persen'),
            'area_mode' => komisi_normalize_mode($row['area_mode'] ?? 'persen'),
            'awal_mode' => komisi_normalize_mode($row['awal_mode'] ?? 'persen'),
            'run_day' => max(1, min(28, (int) ($row['run_day'] ?? 1))),
            'run_hour' => max(0, min(23, (int) ($row['run_hour'] ?? 1))),
            'run_minute' => max(0, min(59, (int) ($row['run_minute'] ?? 0))),
            'regular_run_day' => max(1, min(28, (int) ($row['regular_run_day'] ?? ($row['run_day'] ?? 1)))),
            'regular_run_hour' => max(0, min(23, (int) ($row['regular_run_hour'] ?? ($row['run_hour'] ?? 1)))),
            'regular_run_minute' => max(0, min(59, (int) ($row['regular_run_minute'] ?? ($row['run_minute'] ?? 0)))),
            'area_run_day' => max(1, min(28, (int) ($row['area_run_day'] ?? ($row['run_day'] ?? 1)))),
            'area_run_hour' => max(0, min(23, (int) ($row['area_run_hour'] ?? ($row['run_hour'] ?? 1)))),
            'area_run_minute' => max(0, min(59, (int) ($row['area_run_minute'] ?? ($row['run_minute'] ?? 0)))),
            'awal_run_day' => max(1, min(28, (int) ($row['awal_run_day'] ?? ($row['run_day'] ?? 1)))),
            'awal_run_hour' => max(0, min(23, (int) ($row['awal_run_hour'] ?? ($row['run_hour'] ?? 1)))),
            'awal_run_minute' => max(0, min(59, (int) ($row['awal_run_minute'] ?? ($row['run_minute'] ?? 0))))
        ];
    }
}

if (!function_exists('komisi_save_cron_settings')) {
    function komisi_save_cron_settings(mysqli $conn, $owner, array $input)
    {
        komisi_ensure_cron_settings_table($conn);

        $existing = komisi_get_cron_settings($conn, $owner);

        $regularEnabled = array_key_exists('auto_regular_enabled', $input)
            ? (!empty($input['auto_regular_enabled']) ? 1 : 0)
            : (int) ($existing['auto_regular_enabled'] ?? 0);
        $areaEnabled = array_key_exists('auto_area_enabled', $input)
            ? (!empty($input['auto_area_enabled']) ? 1 : 0)
            : (int) ($existing['auto_area_enabled'] ?? 0);
        $awalEnabled = array_key_exists('auto_awal_enabled', $input)
            ? (!empty($input['auto_awal_enabled']) ? 1 : 0)
            : (int) ($existing['auto_awal_enabled'] ?? 0);

        $regularMode = komisi_normalize_mode($input['regular_mode'] ?? ($existing['regular_mode'] ?? 'persen'));
        $areaMode = komisi_normalize_mode($input['area_mode'] ?? ($existing['area_mode'] ?? 'persen'));
        $awalMode = komisi_normalize_mode($input['awal_mode'] ?? ($existing['awal_mode'] ?? 'persen'));

        $runDay = max(1, min(28, (int) ($input['run_day'] ?? ($existing['run_day'] ?? 1))));
        $runHour = max(0, min(23, (int) ($input['run_hour'] ?? ($existing['run_hour'] ?? 1))));
        $runMinute = max(0, min(59, (int) ($input['run_minute'] ?? ($existing['run_minute'] ?? 0))));

        $regularRunDay = max(1, min(28, (int) ($input['regular_run_day'] ?? ($existing['regular_run_day'] ?? $runDay))));
        $regularRunHour = max(0, min(23, (int) ($input['regular_run_hour'] ?? ($existing['regular_run_hour'] ?? $runHour))));
        $regularRunMinute = max(0, min(59, (int) ($input['regular_run_minute'] ?? ($existing['regular_run_minute'] ?? $runMinute))));

        $areaRunDay = max(1, min(28, (int) ($input['area_run_day'] ?? ($existing['area_run_day'] ?? $runDay))));
        $areaRunHour = max(0, min(23, (int) ($input['area_run_hour'] ?? ($existing['area_run_hour'] ?? $runHour))));
        $areaRunMinute = max(0, min(59, (int) ($input['area_run_minute'] ?? ($existing['area_run_minute'] ?? $runMinute))));

        $awalRunDay = max(1, min(28, (int) ($input['awal_run_day'] ?? ($existing['awal_run_day'] ?? $runDay))));
        $awalRunHour = max(0, min(23, (int) ($input['awal_run_hour'] ?? ($existing['awal_run_hour'] ?? $runHour))));
        $awalRunMinute = max(0, min(59, (int) ($input['awal_run_minute'] ?? ($existing['awal_run_minute'] ?? $runMinute))));

        $stmt = $conn->prepare("INSERT INTO komisi_cron_settings (owner, auto_regular_enabled, auto_area_enabled, auto_awal_enabled, regular_mode, area_mode, awal_mode, run_day, run_hour, run_minute, regular_run_day, regular_run_hour, regular_run_minute, area_run_day, area_run_hour, area_run_minute, awal_run_day, awal_run_hour, awal_run_minute) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE auto_regular_enabled = VALUES(auto_regular_enabled), auto_area_enabled = VALUES(auto_area_enabled), auto_awal_enabled = VALUES(auto_awal_enabled), regular_mode = VALUES(regular_mode), area_mode = VALUES(area_mode), awal_mode = VALUES(awal_mode), run_day = VALUES(run_day), run_hour = VALUES(run_hour), run_minute = VALUES(run_minute), regular_run_day = VALUES(regular_run_day), regular_run_hour = VALUES(regular_run_hour), regular_run_minute = VALUES(regular_run_minute), area_run_day = VALUES(area_run_day), area_run_hour = VALUES(area_run_hour), area_run_minute = VALUES(area_run_minute), awal_run_day = VALUES(awal_run_day), awal_run_hour = VALUES(awal_run_hour), awal_run_minute = VALUES(awal_run_minute)");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('siiisssiiiiiiiiiiii', $owner, $regularEnabled, $areaEnabled, $awalEnabled, $regularMode, $areaMode, $awalMode, $runDay, $runHour, $runMinute, $regularRunDay, $regularRunHour, $regularRunMinute, $areaRunDay, $areaRunHour, $areaRunMinute, $awalRunDay, $awalRunHour, $awalRunMinute);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('komisi_sync_global_cron')) {
    function komisi_sync_global_cron(mysqli $conn, $billingDir)
    {
        komisi_ensure_cron_settings_table($conn);

        $regularCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM komisi_cron_settings WHERE auto_regular_enabled = 1");
        $areaCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM komisi_cron_settings WHERE auto_area_enabled = 1");
        $awalCountRes = $conn->query("SELECT COUNT(*) AS cnt FROM komisi_cron_settings WHERE auto_awal_enabled = 1");
        $enabledRegular = 0;
        $enabledArea = 0;
        $enabledAwal = 0;
        if ($regularCountRes && ($row = $regularCountRes->fetch_assoc())) {
            $enabledRegular = (int) ($row['cnt'] ?? 0);
        }
        if ($areaCountRes && ($row = $areaCountRes->fetch_assoc())) {
            $enabledArea = (int) ($row['cnt'] ?? 0);
        }
        if ($awalCountRes && ($row = $awalCountRes->fetch_assoc())) {
            $enabledAwal = (int) ($row['cnt'] ?? 0);
        }

        $cronScript = rtrim($billingDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'komisi_cron.php';
        if (!is_file($cronScript)) {
            return false;
        }

        $cronScriptArg = escapeshellarg($cronScript);
        $needle = 'komisi_cron.php';
        $regularCronLine = "* * * * * /usr/bin/env php {$cronScriptArg} regular > /dev/null 2>&1";
        $areaCronLine = "* * * * * /usr/bin/env php {$cronScriptArg} area > /dev/null 2>&1";
        $awalCronLine = "* * * * * /usr/bin/env php {$cronScriptArg} awal > /dev/null 2>&1";

        $current = shell_exec('crontab -u www-data -l 2>&1');
        if (!is_string($current) || stripos($current, 'no crontab for') !== false) {
            $current = '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $current);
        $filtered = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            if ($line === '') {
                continue;
            }
            if (strpos($line, $needle) !== false) {
                continue;
            }
            $filtered[] = $line;
        }

        if ($enabledRegular > 0) {
            $filtered[] = $regularCronLine;
        }
        if ($enabledArea > 0) {
            $filtered[] = $areaCronLine;
        }
        if ($enabledAwal > 0) {
            $filtered[] = $awalCronLine;
        }

        $newCrontab = implode("\n", $filtered);
        if ($newCrontab !== '') {
            $newCrontab .= "\n";
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'komisi_cron_');
        if ($tempFile === false) {
            return false;
        }

        file_put_contents($tempFile, $newCrontab);
        shell_exec('crontab -u www-data ' . escapeshellarg($tempFile));
        @unlink($tempFile);
        return true;
    }
}

if (!function_exists('komisi_get_paket_info')) {
    function komisi_get_paket_info(mysqli $conn, $paketName)
    {
        $paketName = trim((string) $paketName);
        if ($paketName === '') {
            return ['harga' => 0.0, 'komisi' => 0.0, 'komisi_nominal' => 0.0];
        }

        $escaped = $conn->real_escape_string($paketName);
        $queries = [
            "SELECT harga, komisi, komisi_nominal FROM paket WHERE PAKET = '{$escaped}' LIMIT 1",
            "SELECT harga, komisi, komisi_nominal FROM paket_hotspot WHERE paket = '{$escaped}' LIMIT 1"
        ];

        foreach ($queries as $sql) {
            $res = $conn->query($sql);
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                return [
                    'harga' => (float) ($row['harga'] ?? 0),
                    'komisi' => (float) ($row['komisi'] ?? 0),
                    'komisi_nominal' => (float) ($row['komisi_nominal'] ?? 0)
                ];
            }
        }

        return ['harga' => 0.0, 'komisi' => 0.0, 'komisi_nominal' => 0.0];
    }
}

if (!function_exists('komisi_calculate_value')) {
    function komisi_calculate_value(array $paketInfo, $mode)
    {
        $mode = komisi_normalize_mode($mode);
        $harga = (float) ($paketInfo['harga'] ?? 0);
        $komisiPersen = (float) ($paketInfo['komisi'] ?? 0);
        $komisiNominal = (float) ($paketInfo['komisi_nominal'] ?? 0);

        if ($mode === 'nominal') {
            return max(0, $komisiNominal);
        }

        return max(0, ($komisiPersen / 100) * $harga);
    }
}

if (!function_exists('komisi_get_regular_preview')) {
    function komisi_get_regular_preview(mysqli $conn, $owner, $salesName, $month, $year, $mode)
    {
        $preview = [
            'penggunaan' => komisi_build_period_label($month, $year),
            'sudah_bayar' => [],
            'belum_bayar' => [],
            'total_bayar' => 0,
            'total_belum' => 0,
            'total_komisi' => 0.0,
            'total_harga_belum_bayar' => 0.0
        ];

        $salesName = trim((string) $salesName);
        $owner = trim((string) $owner);
        if ($salesName === '' || $owner === '') {
            return $preview;
        }

        $penggunaan = $preview['penggunaan'];
        $stmt = $conn->prepare("SELECT * FROM pelanggan WHERE sales = ? AND PEMILIK = ? ORDER BY NAMA ASC");
        if (!$stmt) {
            return $preview;
        }

        $stmt->bind_param('ss', $salesName, $owner);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($pel = $res->fetch_assoc()) {
            $idpel = (string) ($pel['IDPEL'] ?? '');
            $paketInfo = komisi_get_paket_info($conn, $pel['PAKET'] ?? '');
            $pel['HARGA'] = (float) ($paketInfo['harga'] ?? 0);

            $trxStmt = $conn->prepare("SELECT TANGGALBAYAR, BUKTI FROM transaksi WHERE IDPEL = ? AND PENGUNAAN = ? AND STATUS = 'BERHASIL' AND PEMILIK = ? ORDER BY TANGGALBAYAR DESC LIMIT 1");
            if ($trxStmt) {
                $trxStmt->bind_param('sss', $idpel, $penggunaan, $owner);
                $trxStmt->execute();
                $trxRes = $trxStmt->get_result();
                $trx = $trxRes ? $trxRes->fetch_assoc() : null;
                $trxStmt->close();
            } else {
                $trx = null;
            }

            if ($trx) {
                $pel['TANGGALBAYAR'] = $trx['TANGGALBAYAR'] ?? '';
                $pel['BUKTI'] = $trx['BUKTI'] ?? '';
                $pel['komisi'] = komisi_calculate_value($paketInfo, $mode);
                $preview['sudah_bayar'][] = $pel;
                $preview['total_bayar']++;
                $preview['total_komisi'] += (float) $pel['komisi'];
            } else {
                $preview['belum_bayar'][] = $pel;
                $preview['total_belum']++;
                $preview['total_harga_belum_bayar'] += (float) $pel['HARGA'];
            }
        }

        $stmt->close();
        return $preview;
    }
}

if (!function_exists('komisi_get_awal_preview')) {
    function komisi_get_awal_preview(mysqli $conn, $owner, $salesName, $month, $year, $mode)
    {
        komisi_ensure_awal_claims_table($conn);

        $preview = [
            'penggunaan' => komisi_build_period_label($month, $year),
            'awal_bayar' => [],
            'total_awal_bayar' => 0,
            'total_komisi' => 0.0
        ];

        $salesName = trim((string) $salesName);
        $owner = trim((string) $owner);
        if ($salesName === '' || $owner === '') {
            return $preview;
        }

        $stmt = $conn->prepare("SELECT * FROM pelanggan WHERE sales = ? AND PEMILIK = ? ORDER BY NAMA ASC");
        if (!$stmt) {
            return $preview;
        }

        $stmt->bind_param('ss', $salesName, $owner);
        $stmt->execute();
        $res = $stmt->get_result();

        $claimedMap = [];
        $claimedStmt = $conn->prepare("SELECT idpel FROM komisi_awal_claims WHERE owner = ? AND claim_status = 'berhasil'");
        if ($claimedStmt) {
            $claimedStmt->bind_param('s', $owner);
            $claimedStmt->execute();
            $claimedRes = $claimedStmt->get_result();
            if ($claimedRes) {
                while ($claimed = $claimedRes->fetch_assoc()) {
                    $cid = trim((string) ($claimed['idpel'] ?? ''));
                    if ($cid !== '') {
                        $claimedMap[$cid] = true;
                    }
                }
            }
            $claimedStmt->close();
        }

        while ($pel = $res->fetch_assoc()) {
            $idpel = (string) ($pel['IDPEL'] ?? '');
            if ($idpel === '') {
                continue;
            }
            if (isset($claimedMap[$idpel])) {
                continue;
            }

            $trxStmt = $conn->prepare("SELECT TANGGALBAYAR, BUKTI, PENGUNAAN FROM transaksi WHERE IDPEL = ? AND STATUS = 'BERHASIL' AND PEMILIK = ? ORDER BY TANGGALBAYAR ASC LIMIT 1");
            if (!$trxStmt) {
                continue;
            }
            $trxStmt->bind_param('ss', $idpel, $owner);
            $trxStmt->execute();
            $trxRes = $trxStmt->get_result();
            $trx = $trxRes ? $trxRes->fetch_assoc() : null;
            $trxStmt->close();

            if (!$trx) {
                continue;
            }

            $isMatch = false;
            $tglBayar = trim((string) ($trx['TANGGALBAYAR'] ?? ''));
            if ($tglBayar !== '') {
                $ts = strtotime($tglBayar);
                if ($ts !== false) {
                    $isMatch = ((int) date('m', $ts) === (int) $month) && ((int) date('Y', $ts) === (int) $year);
                }
            }
            if (!$isMatch) {
                $penggunaanTrx = trim((string) ($trx['PENGUNAAN'] ?? ''));
                if ($penggunaanTrx !== '' && strcasecmp($penggunaanTrx, $preview['penggunaan']) === 0) {
                    $isMatch = true;
                }
            }

            if (!$isMatch) {
                continue;
            }

            $paketInfo = komisi_get_paket_info($conn, $pel['PAKET'] ?? '');
            $pel['HARGA'] = (float) ($paketInfo['harga'] ?? 0);
            $pel['TANGGALBAYAR'] = $tglBayar;
            $pel['BUKTI'] = (string) ($trx['BUKTI'] ?? '');
            $pel['komisi'] = komisi_calculate_value($paketInfo, $mode);

            $preview['awal_bayar'][] = $pel;
            $preview['total_awal_bayar']++;
            $preview['total_komisi'] += (float) $pel['komisi'];
        }

        $stmt->close();
        return $preview;
    }
}

if (!function_exists('komisi_find_area_mitra')) {
    function komisi_find_area_mitra(mysqli $conn, $owner, array $area)
    {
        $ownerEsc = $conn->real_escape_string((string) $owner);
        $mappings = [
            ['jabatan_db' => 'kepala_camat', 'label' => 'Kepala Camat', 'where' => ["LOWER(TRIM(kecamatan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kecamatan'] ?? '')) . "'))"]],
            ['jabatan_db' => 'kepala_lurah', 'label' => 'Kepala Lurah', 'where' => ["LOWER(TRIM(kecamatan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kecamatan'] ?? '')) . "'))", "LOWER(TRIM(kelurahan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kelurahan'] ?? '')) . "'))"]],
            ['jabatan_db' => 'kepala_rw', 'label' => 'Kepala RW', 'where' => ["LOWER(TRIM(kecamatan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kecamatan'] ?? '')) . "'))", "LOWER(TRIM(kelurahan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kelurahan'] ?? '')) . "'))", "LOWER(TRIM(rw))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['rw'] ?? '')) . "'))"]],
            ['jabatan_db' => 'kepala_rt', 'label' => 'Kepala RT', 'where' => ["LOWER(TRIM(kecamatan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kecamatan'] ?? '')) . "'))", "LOWER(TRIM(kelurahan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kelurahan'] ?? '')) . "'))", "LOWER(TRIM(rw))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['rw'] ?? '')) . "'))", "LOWER(TRIM(rt))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['rt'] ?? '')) . "'))"]],
            ['jabatan_db' => 'sales_regular', 'label' => 'Sales Regular', 'where' => ["LOWER(TRIM(kecamatan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kecamatan'] ?? '')) . "'))", "LOWER(TRIM(kelurahan))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['kelurahan'] ?? '')) . "'))", "LOWER(TRIM(rw))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['rw'] ?? '')) . "'))", "LOWER(TRIM(rt))=LOWER(TRIM('" . $conn->real_escape_string((string) ($area['rt'] ?? '')) . "'))"]]
        ];

        $mitraList = [];
        foreach ($mappings as $mapping) {
            foreach ($mapping['where'] as $condition) {
                if (strpos($condition, "''") !== false) {
                    continue 2;
                }
            }
            $sql = "SELECT nama FROM mitra WHERE server = '{$ownerEsc}' AND jabatan = '{$mapping['jabatan_db']}' AND " . implode(' AND ', $mapping['where']);
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $mitraList[] = [
                        'nama' => (string) ($row['nama'] ?? '-'),
                        'jabatan' => $mapping['label']
                    ];
                }
            }
        }

        if (empty($mitraList)) {
            $mitraList[] = ['nama' => '-', 'jabatan' => '-'];
        }

        return $mitraList;
    }
}

if (!function_exists('komisi_get_area_preview')) {
    function komisi_get_area_preview(mysqli $conn, $owner, $month, $year, $mode, array $filters = [])
    {
        $where = ["PEMILIK = '" . $conn->real_escape_string((string) $owner) . "'"];
        foreach (['kecamatan', 'kelurahan', 'rw', 'rt'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = $field . " = '" . $conn->real_escape_string((string) $filters[$field]) . "'";
            }
        }
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $penggunaan = komisi_build_period_label($month, $year);

        $areas = [];
        $q = $conn->query("SELECT * FROM pelanggan {$whereSql} ORDER BY kecamatan, kelurahan, rw, rt, NAMA");
        if ($q) {
            while ($pel = $q->fetch_assoc()) {
                $key = trim((string) ($pel['kecamatan'] ?? '')) . '|' . trim((string) ($pel['kelurahan'] ?? '')) . '|' . trim((string) ($pel['rw'] ?? '')) . '|' . trim((string) ($pel['rt'] ?? ''));
                if (!isset($areas[$key])) {
                    $areas[$key] = [
                        'kecamatan' => (string) ($pel['kecamatan'] ?? ''),
                        'kelurahan' => (string) ($pel['kelurahan'] ?? ''),
                        'rw' => (string) ($pel['rw'] ?? ''),
                        'rt' => (string) ($pel['rt'] ?? ''),
                        'total_pelanggan' => 0,
                        'total_pembayaran' => 0.0,
                        'total_komisi' => 0.0,
                        'pelanggan' => []
                    ];
                }

                $areas[$key]['total_pelanggan']++;
                $paketInfo = komisi_get_paket_info($conn, $pel['PAKET'] ?? '');
                $pel['pembayaran'] = 0.0;
                $pel['status_bayar'] = '-';
                $pel['tanggal_bayar'] = '';
                $pel['komisi'] = 0.0;

                $trxStmt = $conn->prepare("SELECT TANGGALBAYAR FROM transaksi WHERE IDPEL = ? AND STATUS = 'BERHASIL' AND PENGUNAAN = ? AND PEMILIK = ? ORDER BY TANGGALBAYAR DESC LIMIT 1");
                if ($trxStmt) {
                    $idpel = (string) ($pel['IDPEL'] ?? '');
                    $trxStmt->bind_param('sss', $idpel, $penggunaan, $owner);
                    $trxStmt->execute();
                    $trxRes = $trxStmt->get_result();
                    $trx = $trxRes ? $trxRes->fetch_assoc() : null;
                    $trxStmt->close();
                } else {
                    $trx = null;
                }

                if ($trx) {
                    $pel['status_bayar'] = 'BERHASIL';
                    $pel['tanggal_bayar'] = (string) ($trx['TANGGALBAYAR'] ?? '');
                    $pel['pembayaran'] = (float) ($paketInfo['harga'] ?? 0);
                    $pel['komisi'] = komisi_calculate_value($paketInfo, $mode);
                    $areas[$key]['total_pembayaran'] += (float) $pel['pembayaran'];
                    $areas[$key]['total_komisi'] += (float) $pel['komisi'];
                }

                $areas[$key]['pelanggan'][] = $pel;
            }
        }

        $mitraSummary = [];
        foreach ($areas as $area) {
            $mitraList = komisi_find_area_mitra($conn, $owner, $area);
            foreach ($mitraList as $mitraInfo) {
                $key = $mitraInfo['nama'] . '|' . $mitraInfo['jabatan'];
                if (!isset($mitraSummary[$key])) {
                    $mitraSummary[$key] = [
                        'nama' => $mitraInfo['nama'],
                        'jabatan' => $mitraInfo['jabatan'],
                        'wilayah' => [],
                        'total_pelanggan' => 0,
                        'total_pembayaran' => 0.0,
                        'komisi' => 0.0,
                        'pelanggan' => []
                    ];
                }
                $mitraSummary[$key]['wilayah'][] = [
                    'kecamatan' => $area['kecamatan'],
                    'kelurahan' => $area['kelurahan'],
                    'rw' => $area['rw'],
                    'rt' => $area['rt']
                ];
                $mitraSummary[$key]['total_pelanggan'] += (int) $area['total_pelanggan'];
                $mitraSummary[$key]['total_pembayaran'] += (float) $area['total_pembayaran'];
                $mitraSummary[$key]['komisi'] += (float) $area['total_komisi'];
                $mitraSummary[$key]['pelanggan'] = array_merge($mitraSummary[$key]['pelanggan'], $area['pelanggan']);
            }
        }

        return [
            'penggunaan' => $penggunaan,
            'rekap' => $areas,
            'rekap_mitra' => $mitraSummary
        ];
    }
}

if (!function_exists('komisi_normalize_multiline_field')) {
    function komisi_normalize_multiline_field($value)
    {
        $text = (string) $value;
        $text = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $text);
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $parts = explode("\n", $text);
        $normalized = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || $part === '-') {
                continue;
            }
            if (!in_array($part, $normalized, true)) {
                $normalized[] = $part;
            }
        }

        return implode("\n", $normalized);
    }
}

if (!function_exists('komisi_upsert_transaksi')) {
    function komisi_upsert_transaksi(mysqli $conn, array $payload)
    {
        komisi_ensure_schema($conn);

        $rawKategori = trim((string) ($payload['komisi_kategori'] ?? 'regular'));
        if ($rawKategori === 'area') {
            $kategori = 'area';
        } elseif ($rawKategori === 'awal') {
            $kategori = 'awal';
        } else {
            $kategori = 'regular';
        }
        $nama = trim((string) ($payload['nama'] ?? ''));
        $tanggal = trim((string) ($payload['tanggal'] ?? date('Y-m-d')));
        $periode = trim((string) ($payload['periode'] ?? ''));
        $pembayaran = (float) ($payload['pembayaran'] ?? 0);
        $status = trim((string) ($payload['status'] ?? 'pending')) ?: 'pending';
        $server = trim((string) ($payload['server'] ?? ''));
        $jabatan = preg_replace('/\s+/', ' ', trim((string) ($payload['jabatan'] ?? '')));
        $kecamatan = komisi_normalize_multiline_field($payload['kecamatan'] ?? '');
        $kelurahan = komisi_normalize_multiline_field($payload['kelurahan'] ?? '');
        $rw = komisi_normalize_multiline_field($payload['rw'] ?? '');
        $rt = komisi_normalize_multiline_field($payload['rt'] ?? '');
        $totalPelanggan = (int) ($payload['total_pelanggan'] ?? 0);

        if ($nama === '' || $periode === '' || $server === '' || $pembayaran <= 0) {
            return ['status' => 'skipped'];
        }

        $sql = "SELECT id, status FROM transaksi_komisi WHERE nama = ? AND periode = ? AND server = ? AND COALESCE(komisi_kategori, '') = ? AND COALESCE(jabatan, '') = ? AND COALESCE(kecamatan, '') = ? AND COALESCE(kelurahan, '') = ? AND COALESCE(rw, '') = ? AND COALESCE(rt, '') = ? ORDER BY CASE WHEN status = 'pending' THEN 0 ELSE 1 END, id DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['status' => 'error', 'message' => $conn->error];
        }
        $stmt->bind_param('sssssssss', $nama, $periode, $server, $kategori, $jabatan, $kecamatan, $kelurahan, $rw, $rt);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($existing && ($existing['status'] ?? '') === 'berhasil') {
            return ['status' => 'locked', 'id' => (int) $existing['id']];
        }

        if ($existing) {
            $stmt = $conn->prepare("UPDATE transaksi_komisi SET tanggal = ?, pembayaran = ?, status = ?, total_pelanggan = ?, komisi_kategori = ?, jabatan = ?, kecamatan = ?, kelurahan = ?, rw = ?, rt = ?, server = ? WHERE id = ?");
            if (!$stmt) {
                return ['status' => 'error', 'message' => $conn->error];
            }
            $id = (int) $existing['id'];
            $stmt->bind_param('sdsissssssssi', $tanggal, $pembayaran, $status, $totalPelanggan, $kategori, $jabatan, $kecamatan, $kelurahan, $rw, $rt, $server, $id);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok ? ['status' => 'updated', 'id' => $id] : ['status' => 'error', 'message' => $conn->error];
        }

        $stmt = $conn->prepare("INSERT INTO transaksi_komisi (nama, tanggal, periode, pembayaran, status, server, jabatan, kecamatan, kelurahan, rw, rt, total_pelanggan, komisi_kategori) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return ['status' => 'error', 'message' => $conn->error];
        }
        $stmt->bind_param('sssdsssssssis', $nama, $tanggal, $periode, $pembayaran, $status, $server, $jabatan, $kecamatan, $kelurahan, $rw, $rt, $totalPelanggan, $kategori);
        $ok = $stmt->execute();
        $insertId = (int) $conn->insert_id;
        $stmt->close();

        return $ok ? ['status' => 'inserted', 'id' => $insertId] : ['status' => 'error', 'message' => $conn->error];
    }
}

if (!function_exists('komisi_generate_regular_monthly')) {
    function komisi_generate_regular_monthly(mysqli $conn, $owner, $month, $year, $mode, $tanggal = null)
    {
        $tanggal = $tanggal ?: date('Y-m-d');
        $owner = trim((string) $owner);
        if ($owner === '') {
            return ['inserted' => 0, 'updated' => 0, 'locked' => 0, 'skipped' => 0, 'errors' => []];
        }

        $result = ['inserted' => 0, 'updated' => 0, 'locked' => 0, 'skipped' => 0, 'errors' => []];
        $salesRes = $conn->query("SELECT DISTINCT nama FROM mitra WHERE server = '" . $conn->real_escape_string($owner) . "' AND TRIM(COALESCE(nama,'')) <> '' ORDER BY nama ASC");
        if (!$salesRes) {
            $result['errors'][] = $conn->error;
            return $result;
        }

        while ($sales = $salesRes->fetch_assoc()) {
            $salesName = trim((string) ($sales['nama'] ?? ''));
            if ($salesName === '' || $salesName === '-') {
                continue;
            }
            $preview = komisi_get_regular_preview($conn, $owner, $salesName, $month, $year, $mode);
            $upsert = komisi_upsert_transaksi($conn, [
                'komisi_kategori' => 'regular',
                'nama' => $salesName,
                'tanggal' => $tanggal,
                'periode' => $preview['penggunaan'],
                'pembayaran' => $preview['total_komisi'],
                'status' => 'pending',
                'server' => $owner,
                'total_pelanggan' => $preview['total_bayar']
            ]);
            if (isset($result[$upsert['status']])) {
                $result[$upsert['status']]++;
            } elseif (($upsert['status'] ?? '') === 'skipped') {
                $result['skipped']++;
            } elseif (($upsert['status'] ?? '') === 'error') {
                $result['errors'][] = $upsert['message'] ?? 'Unknown error';
            }
        }

        return $result;
    }
}

if (!function_exists('komisi_generate_area_monthly')) {
    function komisi_generate_area_monthly(mysqli $conn, $owner, $month, $year, $mode, $tanggal = null)
    {
        $tanggal = $tanggal ?: date('Y-m-d');
        $owner = trim((string) $owner);
        $summary = komisi_get_area_preview($conn, $owner, $month, $year, $mode);
        $result = ['inserted' => 0, 'updated' => 0, 'locked' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($summary['rekap_mitra'] as $mitra) {
            $namaMitra = trim((string) ($mitra['nama'] ?? ''));
            if ($namaMitra === '' || $namaMitra === '-') {
                continue;
            }

            $kecamatanList = [];
            $kelurahanList = [];
            $rwList = [];
            $rtList = [];
            foreach ($mitra['wilayah'] as $wilayah) {
                $kecamatanList[] = trim((string) ($wilayah['kecamatan'] ?? ''));
                $kelurahanList[] = trim((string) ($wilayah['kelurahan'] ?? ''));
                $rwList[] = trim((string) ($wilayah['rw'] ?? ''));
                $rtList[] = trim((string) ($wilayah['rt'] ?? ''));
            }

            $upsert = komisi_upsert_transaksi($conn, [
                'komisi_kategori' => 'area',
                'nama' => $namaMitra,
                'tanggal' => $tanggal,
                'periode' => $summary['penggunaan'],
                'pembayaran' => $mitra['komisi'],
                'status' => 'pending',
                'server' => $owner,
                'jabatan' => (string) ($mitra['jabatan'] ?? ''),
                'kecamatan' => implode("\n", array_values(array_filter(array_unique($kecamatanList), 'strlen'))),
                'kelurahan' => implode("\n", array_values(array_filter(array_unique($kelurahanList), 'strlen'))),
                'rw' => implode("\n", array_values(array_filter(array_unique($rwList), 'strlen'))),
                'rt' => implode("\n", array_values(array_filter(array_unique($rtList), 'strlen'))),
                'total_pelanggan' => $mitra['total_pelanggan']
            ]);
            if (isset($result[$upsert['status']])) {
                $result[$upsert['status']]++;
            } elseif (($upsert['status'] ?? '') === 'skipped') {
                $result['skipped']++;
            } elseif (($upsert['status'] ?? '') === 'error') {
                $result['errors'][] = $upsert['message'] ?? 'Unknown error';
            }
        }

        return $result;
    }
}

if (!function_exists('komisi_generate_awal_monthly')) {
    function komisi_generate_awal_monthly(mysqli $conn, $owner, $month, $year, $mode, $tanggal = null)
    {
        $tanggal = $tanggal ?: date('Y-m-d');
        $owner = trim((string) $owner);
        if ($owner === '') {
            return ['inserted' => 0, 'updated' => 0, 'locked' => 0, 'skipped' => 0, 'errors' => []];
        }

        komisi_awal_claim_sync_status($conn, $owner);

        $result = ['inserted' => 0, 'updated' => 0, 'locked' => 0, 'skipped' => 0, 'errors' => []];
        $salesRes = $conn->query("SELECT DISTINCT nama FROM mitra WHERE server = '" . $conn->real_escape_string($owner) . "' AND TRIM(COALESCE(nama,'')) <> '' ORDER BY nama ASC");
        if (!$salesRes) {
            $result['errors'][] = $conn->error;
            return $result;
        }

        while ($sales = $salesRes->fetch_assoc()) {
            $salesName = trim((string) ($sales['nama'] ?? ''));
            if ($salesName === '' || $salesName === '-') {
                continue;
            }

            $preview = komisi_get_awal_preview($conn, $owner, $salesName, $month, $year, $mode);
            $upsert = komisi_upsert_transaksi($conn, [
                'komisi_kategori' => 'awal',
                'nama' => $salesName,
                'tanggal' => $tanggal,
                'periode' => $preview['penggunaan'],
                'pembayaran' => $preview['total_komisi'],
                'status' => 'pending',
                'server' => $owner,
                'total_pelanggan' => $preview['total_awal_bayar']
            ]);

            if (($upsert['status'] ?? '') === 'inserted' || ($upsert['status'] ?? '') === 'updated') {
                $trxId = (int) ($upsert['id'] ?? 0);
                if ($trxId > 0) {
                    komisi_awal_claim_delete_pending_by_transaksi_ids($conn, $owner, [$trxId]);
                    $claimRows = [];
                    foreach ($preview['awal_bayar'] as $row) {
                        $idpel = trim((string) ($row['IDPEL'] ?? ''));
                        if ($idpel === '') {
                            continue;
                        }
                        $claimRows[] = [
                            'idpel' => $idpel,
                            'tanggal_bayar_awal' => trim((string) ($row['TANGGALBAYAR'] ?? ''))
                        ];
                    }
                    komisi_awal_claim_upsert_list($conn, $owner, $salesName, $preview['penggunaan'], $trxId, 'pending', $claimRows);
                }
            }

            if (isset($result[$upsert['status']])) {
                $result[$upsert['status']]++;
            } elseif (($upsert['status'] ?? '') === 'skipped') {
                $result['skipped']++;
            } elseif (($upsert['status'] ?? '') === 'error') {
                $result['errors'][] = $upsert['message'] ?? 'Unknown error';
            }
        }

        return $result;
    }
}
