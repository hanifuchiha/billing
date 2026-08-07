<?php
date_default_timezone_set('Asia/Jakarta');

$logPrefix = '[' . date('Y-m-d H:i:s') . '] ';
$configFile = __DIR__ . '/config.json';
if (!is_file($configFile)) {
    echo $logPrefix . "ERROR: config.json not found\n";
    exit(1);
}

$config = json_decode((string) file_get_contents($configFile), true);

$conn = mysqli_connect($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if (!$conn) {
    echo $logPrefix . "ERROR: DB connection failed\n";
    exit(1);
}

require_once __DIR__ . '/komisi_helper.php';
komisi_ensure_schema($conn);
komisi_ensure_cron_settings_table($conn);

$conn->query("CREATE TABLE IF NOT EXISTS komisi_cron_runs (
    id INT NOT NULL AUTO_INCREMENT,
    owner VARCHAR(255) NOT NULL,
    kategori VARCHAR(20) NOT NULL,
    periode VARCHAR(50) NOT NULL,
    ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_owner_category_period (owner, kategori, periode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$currentDay = (int) date('j');
$currentHour = (int) date('G');
$currentMinute = (int) date('i');
$currentMonth = (int) date('m');
$currentYear = (int) date('Y');
[$targetMonth, $targetYear] = komisi_previous_period();
$targetPeriode = komisi_build_period_label($targetMonth, $targetYear);
$today = date('Y-m-d');

$kategoriInput = $_GET['kategori'] ?? 'all';
if (PHP_SAPI === 'cli' && isset($argv[1]) && trim((string) $argv[1]) !== '') {
    $kategoriInput = $argv[1];
}
$kategoriRequest = strtolower(trim((string) $kategoriInput));
if (!in_array($kategoriRequest, ['regular', 'area', 'awal', 'all'], true)) {
    $kategoriRequest = 'all';
}

$forceInput = $_GET['force'] ?? '0';
if (PHP_SAPI === 'cli' && isset($argv[2]) && trim((string) $argv[2]) !== '') {
    $forceInput = $argv[2];
}
$forceRun = in_array(strtolower((string) $forceInput), ['1', 'true', 'yes', 'on'], true);

$rerunInput = $_GET['rerun'] ?? '0';
if (PHP_SAPI === 'cli' && isset($argv[3]) && trim((string) $argv[3]) !== '') {
    $rerunInput = $argv[3];
}
$allowRerun = in_array(strtolower((string) $rerunInput), ['1', 'true', 'yes', 'on'], true);

$enableYearBackfill = true;

$targetPeriods = [];
for ($m = 1; $m <= $currentMonth; $m++) {
    $targetPeriods[] = [
        'month' => $m,
        'year' => $currentYear,
        'periode' => komisi_build_period_label($m, $currentYear)
    ];
}

$singleKategoriRequest = in_array($kategoriRequest, ['regular', 'area', 'awal'], true);
$webSingleKategoriTest = (PHP_SAPI !== 'cli' && $singleKategoriRequest);
if ($webSingleKategoriTest) {
    // Saat endpoint kategori tunggal dipanggil langsung via browser, pakai mode test agar tidak terhalang jadwal/lock.
    $forceRun = true;
    $allowRerun = true;
}

$whereClause = "WHERE auto_regular_enabled = 1 OR auto_area_enabled = 1 OR auto_awal_enabled = 1";
if ($webSingleKategoriTest) {
    $whereClause = '';
}
$res = $conn->query("SELECT owner, auto_regular_enabled, auto_area_enabled, auto_awal_enabled, regular_mode, area_mode, awal_mode, run_day, run_hour, run_minute, regular_run_day, regular_run_hour, regular_run_minute, area_run_day, area_run_hour, area_run_minute, awal_run_day, awal_run_hour, awal_run_minute FROM komisi_cron_settings {$whereClause}");
if (!$res || $res->num_rows === 0) {
    echo $logPrefix . "No active komisi cron settings for kategori={$kategoriRequest}\n";
    mysqli_close($conn);
    exit(0);
}

function komisi_mark_run(mysqli $conn, $owner, $kategori, $periode)
{
    $stmt = $conn->prepare("INSERT IGNORE INTO komisi_cron_runs (owner, kategori, periode) VALUES (?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $owner, $kategori, $periode);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $ok && $affected > 0;
}

$executed = 0;
$skipped = 0;
$errors = 0;

while ($row = $res->fetch_assoc()) {
    $owner = trim((string) ($row['owner'] ?? ''));
    if ($owner === '') {
        $skipped++;
        continue;
    }

    $defaultRunDay = max(1, min(28, (int) ($row['run_day'] ?? 1)));
    $defaultRunHour = max(0, min(23, (int) ($row['run_hour'] ?? 1)));
    $defaultRunMinute = max(0, min(59, (int) ($row['run_minute'] ?? 0)));

    $runRegularEnabled = !empty($row['auto_regular_enabled']) || $forceRun || ($webSingleKategoriTest && $kategoriRequest === 'regular');
    $runAreaEnabled = !empty($row['auto_area_enabled']) || $forceRun || ($webSingleKategoriTest && $kategoriRequest === 'area');
    $runAwalEnabled = !empty($row['auto_awal_enabled']) || $forceRun || ($webSingleKategoriTest && $kategoriRequest === 'awal');

    if ($forceRun) {
        echo $logPrefix . "owner {$owner}: force=1, regular_enabled=" . (!empty($row['auto_regular_enabled']) ? '1' : '0') . ", area_enabled=" . (!empty($row['auto_area_enabled']) ? '1' : '0') . ", awal_enabled=" . (!empty($row['auto_awal_enabled']) ? '1' : '0') . "\n";
    }

    if ($kategoriRequest !== 'area' && $kategoriRequest !== 'awal' && $runRegularEnabled) {
        $runDay = max(1, min(28, (int) ($row['regular_run_day'] ?? $defaultRunDay)));
        $runHour = max(0, min(23, (int) ($row['regular_run_hour'] ?? $defaultRunHour)));
        $runMinute = max(0, min(59, (int) ($row['regular_run_minute'] ?? $defaultRunMinute)));
        if (!$forceRun && ($currentDay !== $runDay || $currentHour !== $runHour || $currentMinute !== $runMinute)) {
            $skipped++;
        } else {
            foreach ($targetPeriods as $tp) {
                if ($allowRerun || komisi_mark_run($conn, $owner, 'regular', $tp['periode'])) {
                    $result = komisi_generate_regular_monthly($conn, $owner, $tp['month'], $tp['year'], $row['regular_mode'] ?? 'persen', $today);
                    if (!empty($result['errors'])) {
                        $errors += count($result['errors']);
                        echo $logPrefix . "regular {$owner} ({$tp['periode']}): " . implode('; ', $result['errors']) . "\n";
                    }
                    $executed++;
                    echo $logPrefix . "regular {$owner} ({$tp['periode']}): inserted={$result['inserted']} updated={$result['updated']} locked={$result['locked']} skipped={$result['skipped']}\n";
                } else {
                    $skipped++;
                    if ($forceRun || $allowRerun) {
                        echo $logPrefix . "regular {$owner}: skipped by run lock for periode {$tp['periode']}\n";
                    }
                }
            }
        }
    } elseif ($kategoriRequest !== 'area' && $kategoriRequest !== 'awal') {
        echo $logPrefix . "regular {$owner}: disabled in settings\n";
    }

    if ($kategoriRequest !== 'regular' && $kategoriRequest !== 'awal' && $runAreaEnabled) {
        $runDay = max(1, min(28, (int) ($row['area_run_day'] ?? $defaultRunDay)));
        $runHour = max(0, min(23, (int) ($row['area_run_hour'] ?? $defaultRunHour)));
        $runMinute = max(0, min(59, (int) ($row['area_run_minute'] ?? $defaultRunMinute)));
        if (!$forceRun && ($currentDay !== $runDay || $currentHour !== $runHour || $currentMinute !== $runMinute)) {
            $skipped++;
        } else {
            foreach ($targetPeriods as $tp) {
                if ($allowRerun || komisi_mark_run($conn, $owner, 'area', $tp['periode'])) {
                    $result = komisi_generate_area_monthly($conn, $owner, $tp['month'], $tp['year'], $row['area_mode'] ?? 'persen', $today);
                    if (!empty($result['errors'])) {
                        $errors += count($result['errors']);
                        echo $logPrefix . "area {$owner} ({$tp['periode']}): " . implode('; ', $result['errors']) . "\n";
                    }
                    $executed++;
                    echo $logPrefix . "area {$owner} ({$tp['periode']}): inserted={$result['inserted']} updated={$result['updated']} locked={$result['locked']} skipped={$result['skipped']}\n";
                } else {
                    $skipped++;
                    if ($forceRun || $allowRerun) {
                        echo $logPrefix . "area {$owner}: skipped by run lock for periode {$tp['periode']}\n";
                    }
                }
            }
        }
    } elseif ($kategoriRequest !== 'regular' && $kategoriRequest !== 'awal') {
        echo $logPrefix . "area {$owner}: disabled in settings\n";
    }

    if ($kategoriRequest !== 'regular' && $kategoriRequest !== 'area' && $runAwalEnabled) {
        $runDay = max(1, min(28, (int) ($row['awal_run_day'] ?? $defaultRunDay)));
        $runHour = max(0, min(23, (int) ($row['awal_run_hour'] ?? $defaultRunHour)));
        $runMinute = max(0, min(59, (int) ($row['awal_run_minute'] ?? $defaultRunMinute)));
        if (!$forceRun && ($currentDay !== $runDay || $currentHour !== $runHour || $currentMinute !== $runMinute)) {
            $skipped++;
        } else {
            foreach ($targetPeriods as $tp) {
                if ($allowRerun || komisi_mark_run($conn, $owner, 'awal', $tp['periode'])) {
                    $result = komisi_generate_awal_monthly($conn, $owner, $tp['month'], $tp['year'], $row['awal_mode'] ?? 'persen', $today);
                    if (!empty($result['errors'])) {
                        $errors += count($result['errors']);
                        echo $logPrefix . "awal {$owner} ({$tp['periode']}): " . implode('; ', $result['errors']) . "\n";
                    }
                    $executed++;
                    echo $logPrefix . "awal {$owner} ({$tp['periode']}): inserted={$result['inserted']} updated={$result['updated']} locked={$result['locked']} skipped={$result['skipped']}\n";
                } else {
                    $skipped++;
                    if ($forceRun || $allowRerun) {
                        echo $logPrefix . "awal {$owner}: skipped by run lock for periode {$tp['periode']}\n";
                    }
                }
            }
        }
    } elseif ($kategoriRequest !== 'regular' && $kategoriRequest !== 'area') {
        echo $logPrefix . "awal {$owner}: disabled in settings\n";
    }
}

echo $logPrefix . "Done: kategori={$kategoriRequest}, executed={$executed}, skipped={$skipped}, errors={$errors}, periode={$targetPeriode}, force=" . ($forceRun ? '1' : '0') . ", rerun=" . ($allowRerun ? '1' : '0') . ", backfill_year=1\n";
mysqli_close($conn);
