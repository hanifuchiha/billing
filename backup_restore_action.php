<?php
require 'cek-sesi.php';

$allowedTables = [
    'pelanggan',
    'odp',
    'paket',
    'server',
    'pool',
    'transaksi',
    'diskon',
    'biaya_tambahan',
    'botwa'
];

$restoreChunkSize = 300;
$customMaxUploadBytes = 200 * 1024 * 1024; // 200MB

function redirectWithMessage($status, $message)
{
    header('Location: backup_restore.php?status=' . urlencode($status) . '&msg=' . urlencode($message));
    exit;
}

function tableExists($conn, $table)
{
    $tableEsc = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$tableEsc'");
    return $res && mysqli_num_rows($res) > 0;
}

function getTableColumns($conn, $table)
{
    $columns = [];
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function detectOwnerColumn($columns)
{
    $candidates = ['PEMILIK', 'pemilik'];
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function sanitizeTables($inputTables, $allowedTables)
{
    if (!is_array($inputTables)) {
        return [];
    }
    $clean = [];
    foreach ($inputTables as $table) {
        $table = trim((string)$table);
        if (in_array($table, $allowedTables, true)) {
            $clean[] = $table;
        }
    }
    return array_values(array_unique($clean));
}

function getAllTables($conn)
{
    $tables = [];
    $res = mysqli_query($conn, 'SHOW TABLES');
    if ($res) {
        while ($row = mysqli_fetch_row($res)) {
            if (isset($row[0]) && $row[0] !== '') {
                $tables[] = $row[0];
            }
        }
    }
    return $tables;
}

function parseIniSizeToBytes($value)
{
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $num = (float)$value;
    switch ($unit) {
        case 'g':
            return (int)($num * 1024 * 1024 * 1024);
        case 'm':
            return (int)($num * 1024 * 1024);
        case 'k':
            return (int)($num * 1024);
        default:
            return (int)$num;
    }
}

function formatBytesHuman($bytes)
{
    $bytes = (int)$bytes;
    if ($bytes >= 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
    if ($bytes >= 1024 * 1024) {
        return round($bytes / (1024 * 1024), 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

function loadBackupRawContent($tmpPath, $originalName)
{
    $raw = file_get_contents($tmpPath);
    if ($raw === false) {
        return [false, 'Gagal membaca file upload.'];
    }

    $isGzipByName = (strtolower(substr((string)$originalName, -8)) === '.json.gz');
    $isGzipByMagic = (strlen($raw) >= 2 && ord($raw[0]) === 0x1f && ord($raw[1]) === 0x8b);

    if ($isGzipByName || $isGzipByMagic) {
        if (!function_exists('gzdecode')) {
            return [false, 'Server tidak mendukung gzdecode untuk file .json.gz'];
        }
        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            return [false, 'File .json.gz rusak atau tidak valid.'];
        }
        return [$decoded, null];
    }

    return [$raw, null];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithMessage('error', 'Metode request tidak valid.');
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$selectedTables = sanitizeTables($_POST['tables'] ?? [], $allowedTables);

if ($action === 'backup') {
    if (empty($selectedTables)) {
        redirectWithMessage('error', 'Pilih minimal 1 tabel untuk dibackup.');
    }

    $payload = [
        'meta' => [
            'owner' => $ceknama,
            'generated_at' => date('Y-m-d H:i:s'),
            'format' => 'qts-owner-backup-v1',
            'selected_tables' => $selectedTables
        ],
        'data' => [],
        'summary' => []
    ];

    foreach ($selectedTables as $table) {
        if (!tableExists($conn, $table)) {
            $payload['summary'][$table] = ['status' => 'skip', 'reason' => 'Tabel tidak ditemukan'];
            continue;
        }

        $columns = getTableColumns($conn, $table);
        $ownerColumn = detectOwnerColumn($columns);
        if ($ownerColumn === null) {
            $payload['summary'][$table] = ['status' => 'skip', 'reason' => 'Tidak ada kolom owner (PEMILIK/pemilik)'];
            continue;
        }

        $ownerEsc = mysqli_real_escape_string($conn, $ceknama);
        $query = "SELECT * FROM `$table` WHERE `$ownerColumn` = '$ownerEsc'";
        $res = mysqli_query($conn, $query);
        if (!$res) {
            $payload['summary'][$table] = ['status' => 'error', 'reason' => mysqli_error($conn)];
            continue;
        }

        $rows = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }

        $payload['data'][$table] = $rows;
        $payload['summary'][$table] = [
            'status' => 'ok',
            'owner_column' => $ownerColumn,
            'row_count' => count($rows)
        ];
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $compress = isset($_POST['compress_backup']) && $_POST['compress_backup'] === '1';

    if ($compress && function_exists('gzencode')) {
        $gzip = gzencode($json, 6);
        $filename = 'backup_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ceknama) . '_' . date('Ymd_His') . '.json.gz';
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($gzip));
        echo $gzip;
    } else {
        $filename = 'backup_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ceknama) . '_' . date('Ymd_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
    }
    exit;
}

if ($action === 'structure') {
    // Export semua struktur tabel yang ada di database aktif.
    $selectedTables = getAllTables($conn);
    if (empty($selectedTables)) {
        redirectWithMessage('error', 'Tidak ada tabel di database untuk diexport strukturnya.');
    }

    $sqlDump = [];
    $sqlDump[] = '-- QTS Billing Structure Backup';
    $sqlDump[] = '-- Owner: ' . $ceknama;
    $sqlDump[] = '-- Generated at: ' . date('Y-m-d H:i:s');
    $sqlDump[] = '';
    $sqlDump[] = 'SET NAMES utf8mb4;';
    $sqlDump[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $sqlDump[] = '';

    foreach ($selectedTables as $table) {
        if (!tableExists($conn, $table)) {
            $sqlDump[] = '-- Skip table `' . $table . '` (not found)';
            $sqlDump[] = '';
            continue;
        }

        $res = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
        if (!$res) {
            $sqlDump[] = '-- Failed table `' . $table . '` : ' . mysqli_error($conn);
            $sqlDump[] = '';
            continue;
        }

        $row = mysqli_fetch_assoc($res);
        $createSql = '';
        if (isset($row['Create Table'])) {
            $createSql = $row['Create Table'];
        } elseif (isset($row['Create View'])) {
            $createSql = $row['Create View'];
        }

        if ($createSql !== '') {
            $sqlDump[] = '-- ----------------------------';
            $sqlDump[] = '-- Structure for `' . $table . '`';
            $sqlDump[] = '-- ----------------------------';
            $sqlDump[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
            $sqlDump[] = $createSql . ';';
            $sqlDump[] = '';
        } else {
            $sqlDump[] = '-- Skip table `' . $table . '` (create statement not found)';
            $sqlDump[] = '';
        }
    }

    $sqlDump[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $content = implode("\n", $sqlDump);
    $filename = 'struktur_db_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ceknama) . '_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
}

if ($action === 'restore') {
    if (!isset($_FILES['backup_file'])) {
        redirectWithMessage('error', 'File backup tidak ditemukan.');
    }

    $uploadErr = (int)$_FILES['backup_file']['error'];
    if ($uploadErr !== UPLOAD_ERR_OK) {
        $uploadErrMap = [
            UPLOAD_ERR_INI_SIZE => 'Ukuran file melebihi upload_max_filesize di server.',
            UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas form upload.',
            UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian.',
            UPLOAD_ERR_NO_FILE => 'Tidak ada file yang dipilih.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file upload.',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.'
        ];
        $msg = isset($uploadErrMap[$uploadErr]) ? $uploadErrMap[$uploadErr] : 'Upload file gagal dengan kode: ' . $uploadErr;
        redirectWithMessage('error', $msg);
    }

    $fileName = (string)($_FILES['backup_file']['name'] ?? '');
    $tmpPath = (string)$_FILES['backup_file']['tmp_name'];
    $uploadedSize = (int)($_FILES['backup_file']['size'] ?? 0);

    $uploadMax = parseIniSizeToBytes(ini_get('upload_max_filesize'));
    $postMax = parseIniSizeToBytes(ini_get('post_max_size'));
    $effectiveMax = $customMaxUploadBytes;
    if ($uploadMax > 0) {
        $effectiveMax = min($effectiveMax, $uploadMax);
    }
    if ($postMax > 0) {
        $effectiveMax = min($effectiveMax, $postMax);
    }

    if ($uploadedSize <= 0) {
        redirectWithMessage('error', 'Ukuran file upload tidak valid atau file kosong.');
    }
    if ($effectiveMax > 0 && $uploadedSize > $effectiveMax) {
        redirectWithMessage('error', 'Ukuran file ' . formatBytesHuman($uploadedSize) . ' melebihi batas server ' . formatBytesHuman($effectiveMax) . '.');
    }

    list($raw, $rawError) = loadBackupRawContent($tmpPath, $fileName);
    if ($raw === false) {
        redirectWithMessage('error', $rawError ?: 'Gagal memproses file backup.');
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
        redirectWithMessage('error', 'Format file backup tidak valid.');
    }

    if (empty($selectedTables)) {
        $selectedTables = array_values(array_intersect(array_keys($decoded['data']), $allowedTables));
    }

    if (empty($selectedTables)) {
        redirectWithMessage('error', 'Tidak ada tabel valid untuk direstore.');
    }

    $insertedRows = 0;
    $processedTables = 0;
    $skipped = [];

    mysqli_begin_transaction($conn);
    try {
        foreach ($selectedTables as $table) {
            if (!isset($decoded['data'][$table]) || !is_array($decoded['data'][$table])) {
                $skipped[] = $table . ': tidak ada data di file backup';
                continue;
            }

            if (!tableExists($conn, $table)) {
                $skipped[] = $table . ': tabel tidak ditemukan';
                continue;
            }

            $dbColumns = getTableColumns($conn, $table);
            $ownerColumn = detectOwnerColumn($dbColumns);
            if ($ownerColumn === null) {
                $skipped[] = $table . ': tidak ada kolom owner';
                continue;
            }

            $ownerEsc = mysqli_real_escape_string($conn, $ceknama);
            $deleteSql = "DELETE FROM `$table` WHERE `$ownerColumn` = '$ownerEsc'";
            if (!mysqli_query($conn, $deleteSql)) {
                throw new Exception('Gagal membersihkan tabel ' . $table . ': ' . mysqli_error($conn));
            }

            $rows = $decoded['data'][$table];
            $rowCount = count($rows);
            for ($offset = 0; $offset < $rowCount; $offset += $restoreChunkSize) {
                $chunk = array_slice($rows, $offset, $restoreChunkSize);
                foreach ($chunk as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $row[$ownerColumn] = $ceknama;

                    if (array_key_exists('id', $row)) {
                        unset($row['id']);
                    }

                    $insertColumns = [];
                    foreach (array_keys($row) as $colName) {
                        if (in_array($colName, $dbColumns, true)) {
                            $insertColumns[] = $colName;
                        }
                    }

                    if (!in_array($ownerColumn, $insertColumns, true)) {
                        $insertColumns[] = $ownerColumn;
                        $row[$ownerColumn] = $ceknama;
                    }

                    if (empty($insertColumns)) {
                        continue;
                    }

                    $columnSqlParts = [];
                    $valueSqlParts = [];
                    foreach ($insertColumns as $colName) {
                        $columnSqlParts[] = '`' . str_replace('`', '', $colName) . '`';
                        $val = $row[$colName] ?? null;
                        if ($val === null || $val === '') {
                            $valueSqlParts[] = 'NULL';
                        } else {
                            $valueSqlParts[] = "'" . mysqli_real_escape_string($conn, (string)$val) . "'";
                        }
                    }

                    $insertSql = "INSERT INTO `$table` (" . implode(',', $columnSqlParts) . ") VALUES (" . implode(',', $valueSqlParts) . ")";
                    if (!mysqli_query($conn, $insertSql)) {
                        throw new Exception('Gagal insert tabel ' . $table . ': ' . mysqli_error($conn));
                    }
                    $insertedRows++;
                }

                if (function_exists('set_time_limit')) {
                    @set_time_limit(30);
                }
            }

            $processedTables++;
        }

        mysqli_commit($conn);

        $message = "Restore selesai. Tabel diproses: $processedTables, baris diinsert: $insertedRows";
        if (!empty($skipped)) {
            $message .= "\nTabel dilewati: " . implode('; ', $skipped);
        }
        redirectWithMessage('success', $message);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        redirectWithMessage('error', 'Restore gagal: ' . $e->getMessage());
    }
}

redirectWithMessage('error', 'Aksi tidak dikenali.');
