<?php
// api/backup_restore_mobile.php - Owner-scoped backup/restore API.
//
// Extends the original mobile backup/restore endpoint (list/info/create_backup/delete_file/
// restore_file - unchanged request/response shapes below, so existing callers keep working) with
// the two capabilities that only used to exist in the now-retired apiinterface.php:
//   - action=core_data   : live filtered read across the core billing tables (was `billing_core_data`)
//   - action=structure    : SQL structure dump (was `billing_backup_structure`) - but restricted to
//                            the same tenant-owned table whitelist as backups, NOT every table in the
//                            database. apiinterface.php's structure dump had no such restriction and
//                            leaked every other tenant's/system table's schema to any single API key;
//                            that is fixed here rather than replicated.
// restore_file now also accepts a payload passed directly in the request body (`backup_payload`),
// not only a filename already sitting in dailybackup/backups/ - full parity with the old
// `billing_restore_data`'s direct-payload mode.
//
// Auth migrated from this file's own username+password-only check to _bootstrap.php's shared
// api_authenticate() (session / username+password / apikey), with rate limiting for apikey callers
// and correct ASSISTANT scoping via api_resolve_owner() - apiinterface.php's original core_data
// scoping (server-id based) is preserved for core_data specifically since that's what the original
// billing_core_data behavior was built around.
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$rawInput = api_read_input();

$auth = api_authenticate($conn, $rawInput);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}

function table_exists_mobile($conn, $table) {
    $tableEsc = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$tableEsc'");
    return $res && mysqli_num_rows($res) > 0;
}

function get_table_columns_mobile($conn, $table) {
    $columns = [];
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$table`");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $columns[] = (string)$row['Field'];
        }
    }
    return $columns;
}

function detect_owner_column_mobile($columns) {
    foreach (['PEMILIK', 'pemilik'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function sanitize_tables_mobile($inputTables, $allowedTables) {
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

function load_backup_content_mobile($path) {
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return [false, 'Gagal membaca file backup'];
    }

    $isGzipByName = (strtolower((string)substr($path, -8)) === '.json.gz');
    $isGzipByMagic = (strlen($raw) >= 2 && ord($raw[0]) === 0x1f && ord($raw[1]) === 0x8b);
    if ($isGzipByName || $isGzipByMagic) {
        if (!function_exists('gzdecode')) {
            return [false, 'Server tidak mendukung gzdecode'];
        }
        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            return [false, 'File gzip tidak valid'];
        }
        return [$decoded, null];
    }

    return [$raw, null];
}

/** Applies a decoded {"data": {table: [rows]}} payload: DELETE-then-INSERT per table, scoped to $pemilik. */
function apply_restore_payload($conn, $pemilik, array $decoded, array $allowedTables, $requestedTables) {
    if (!isset($decoded['data']) || !is_array($decoded['data'])) {
        return [false, 'Format backup tidak valid: field "data" tidak ditemukan', null];
    }

    $selected = sanitize_tables_mobile($requestedTables ?? array_keys($decoded['data']), $allowedTables);
    if (empty($selected)) {
        return [false, 'Tidak ada tabel valid untuk restore', null];
    }

    $restoreChunkSize = 300;
    $insertedRows = 0;
    $processedTables = 0;
    $skipped = [];

    mysqli_begin_transaction($conn);
    try {
        foreach ($selected as $table) {
            if (!isset($decoded['data'][$table]) || !is_array($decoded['data'][$table])) {
                $skipped[] = $table . ': tidak ada data';
                continue;
            }
            if (!table_exists_mobile($conn, $table)) {
                $skipped[] = $table . ': tabel tidak ditemukan';
                continue;
            }

            $dbColumns = get_table_columns_mobile($conn, $table);
            $ownerColumn = detect_owner_column_mobile($dbColumns);
            if ($ownerColumn === null) {
                $skipped[] = $table . ': tidak ada kolom owner';
                continue;
            }

            $ownerEsc = mysqli_real_escape_string($conn, $pemilik);
            if (!mysqli_query($conn, "DELETE FROM `$table` WHERE `$ownerColumn` = '$ownerEsc'")) {
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
                    $row[$ownerColumn] = $pemilik;
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
                        $row[$ownerColumn] = $pemilik;
                    }
                    if (empty($insertColumns)) {
                        continue;
                    }

                    $columnParts = [];
                    $valueParts = [];
                    foreach ($insertColumns as $colName) {
                        $columnParts[] = '`' . str_replace('`', '', $colName) . '`';
                        $val = $row[$colName] ?? null;
                        $valueParts[] = ($val === null || $val === '') ? 'NULL' : ("'" . mysqli_real_escape_string($conn, (string)$val) . "'");
                    }

                    $insertSql = "INSERT INTO `$table` (" . implode(',', $columnParts) . ') VALUES (' . implode(',', $valueParts) . ')';
                    if (!mysqli_query($conn, $insertSql)) {
                        throw new Exception('Gagal insert tabel ' . $table . ': ' . mysqli_error($conn));
                    }
                    $insertedRows++;
                }
            }
            $processedTables++;
        }

        mysqli_commit($conn);
        return [true, null, ['processed_tables' => $processedTables, 'inserted_rows' => $insertedRows, 'skipped' => $skipped]];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        return [false, $e->getMessage(), null];
    }
}

try {
    if (!$conn) {
        echo json_encode(['success' => false, 'error' => 'Koneksi DB gagal']);
        exit;
    }

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

    $action = strtolower(trim((string)($rawInput['action'] ?? ($_GET['action'] ?? 'list'))));

    $safeOwner = preg_replace('/[^A-Za-z0-9_-]/', '_', $pemilik);
    $backupDir = realpath(__DIR__ . '/../../../dailybackup/backups');
    if (!$backupDir || !is_dir($backupDir)) {
        echo json_encode(['success' => false, 'error' => 'Folder backup tidak ditemukan']);
        exit;
    }

    if ($method === 'GET' && $action === 'info') {
        echo json_encode([
            'success' => true,
            'owner' => $pemilik,
            'available_tables' => $allowedTables,
            'default_tables' => ['pelanggan', 'odp', 'paket', 'server']
        ]);
        exit;
    }

    // action=core_data: live filtered read across core billing tables (was apiinterface.php's
    // `billing_core_data`), scoped to servers this account owns/is-assigned-to via _bootstrap.php.
    if ($method === 'GET' && $action === 'core_data') {
        $coreTables = ['pelanggan', 'odp', 'paket', 'server', 'transaksi', 'olt', 'paket_hotspot'];
        $includeParam = trim((string)($_GET['include'] ?? ''));
        $include = $includeParam !== '' ? array_filter(array_map('trim', explode(',', $includeParam))) : ['pelanggan', 'odp', 'paket', 'server', 'transaksi'];
        $include = array_values(array_intersect($include, $coreTables));
        if (empty($include)) {
            $include = ['pelanggan', 'odp', 'paket', 'server', 'transaksi'];
        }

        $allowedPemilik = api_allowed_pemilik_list($conn, $ctx);
        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);

        $result = ['owner' => $pemilik, 'generated_at' => date('Y-m-d H:i:s'), 'include' => $include, 'data' => []];

        foreach ($include as $table) {
            if (!table_exists_mobile($conn, $table)) {
                $result['data'][$table] = ['error' => 'Tabel tidak ditemukan', 'rows' => []];
                continue;
            }
            $columns = get_table_columns_mobile($conn, $table);
            $ownerColumn = detect_owner_column_mobile($columns);
            if ($ownerColumn === null) {
                $result['data'][$table] = ['error' => 'Tidak ada kolom owner', 'rows' => []];
                continue;
            }

            $where = ["`$ownerColumn` IN ($pemilikInSql)"];
            if ($table === 'pelanggan') {
                if (!empty($_GET['paket'])) {
                    $where[] = 'PAKET = \'' . mysqli_real_escape_string($conn, $_GET['paket']) . '\'';
                }
                if (!empty($_GET['area'])) {
                    $where[] = 'AREA = \'' . mysqli_real_escape_string($conn, $_GET['area']) . '\'';
                }
                if (!empty($_GET['status'])) {
                    $where[] = 'STATUS = \'' . mysqli_real_escape_string($conn, $_GET['status']) . '\'';
                }
            }
            if ($table === 'transaksi') {
                if (!empty($_GET['start_date'])) {
                    $where[] = 'tanggal >= \'' . mysqli_real_escape_string($conn, $_GET['start_date']) . '\'';
                }
                if (!empty($_GET['end_date'])) {
                    $where[] = 'tanggal <= \'' . mysqli_real_escape_string($conn, $_GET['end_date']) . '\'';
                }
                if (!empty($_GET['status'])) {
                    $where[] = 'status = \'' . mysqli_real_escape_string($conn, $_GET['status']) . '\'';
                }
            }

            $sql = "SELECT * FROM `$table` WHERE " . implode(' AND ', $where) . ($table === 'transaksi' ? ' ORDER BY id DESC' : '') . ' LIMIT 5000';
            $res = mysqli_query($conn, $sql);
            if (!$res) {
                $result['data'][$table] = ['error' => mysqli_error($conn), 'rows' => []];
                continue;
            }
            $rows = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
            $result['data'][$table] = ['count' => count($rows), 'rows' => $rows];
        }

        echo json_encode(['success' => true] + $result);
        exit;
    }

    // action=structure: SQL structure dump (DROP + CREATE TABLE) restricted to $allowedTables only
    // - unlike apiinterface.php's billing_backup_structure, this never dumps tables outside the
    // tenant-owned whitelist, so one owner's API key can't read another tenant's/system schema.
    if ($method === 'GET' && $action === 'structure') {
        $requested = sanitize_tables_mobile(
            !empty($_GET['tables']) ? explode(',', (string)$_GET['tables']) : $allowedTables,
            $allowedTables
        );
        if (empty($requested)) {
            $requested = $allowedTables;
        }

        $sqlDump = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($requested as $table) {
            if (!table_exists_mobile($conn, $table)) {
                continue;
            }
            $res = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
            if (!$res) {
                continue;
            }
            $row = mysqli_fetch_assoc($res);
            $createStmt = $row['Create Table'] ?? $row['Create View'] ?? null;
            if (!$createStmt) {
                continue;
            }
            $sqlDump .= "DROP TABLE IF EXISTS `$table`;\n$createStmt;\n\n";
        }
        $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

        $download = ($_GET['download'] ?? '1') !== '0';
        if ($download) {
            header_remove('Content-Type');
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="struktur_' . $safeOwner . '_' . date('Ymd_His') . '.sql"');
            echo $sqlDump;
            exit;
        }
        echo json_encode(['success' => true, 'sql' => $sqlDump]);
        exit;
    }

    if ($method === 'POST' && $action === 'create_backup') {
        $selected = sanitize_tables_mobile($rawInput['tables'] ?? [], $allowedTables);
        if (empty($selected)) {
            $selected = ['pelanggan', 'odp', 'paket', 'server'];
        }

        $compress = (bool)($rawInput['compress'] ?? true);

        $payload = [
            'meta' => [
                'owner' => $pemilik,
                'generated_at' => date('Y-m-d H:i:s'),
                'format' => 'qts-owner-backup-v1',
                'selected_tables' => $selected
            ],
            'data' => [],
            'summary' => []
        ];

        foreach ($selected as $table) {
            if (!table_exists_mobile($conn, $table)) {
                $payload['summary'][$table] = ['status' => 'skip', 'reason' => 'Tabel tidak ditemukan'];
                continue;
            }

            $columns = get_table_columns_mobile($conn, $table);
            $ownerColumn = detect_owner_column_mobile($columns);
            if ($ownerColumn === null) {
                $payload['summary'][$table] = ['status' => 'skip', 'reason' => 'Tidak ada kolom owner'];
                continue;
            }

            $ownerEsc = mysqli_real_escape_string($conn, $pemilik);
            $res = mysqli_query($conn, "SELECT * FROM `$table` WHERE `$ownerColumn` = '$ownerEsc'");
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
        $baseName = 'backup_' . $safeOwner . '_' . date('Ymd_His');

        if ($compress && function_exists('gzencode')) {
            $filename = $baseName . '.json.gz';
            $bytes = gzencode((string)$json, 6);
        } else {
            $filename = $baseName . '.json';
            $bytes = (string)$json;
        }

        $targetPath = $backupDir . DIRECTORY_SEPARATOR . $filename;
        $ok = @file_put_contents($targetPath, $bytes);
        if ($ok === false) {
            echo json_encode(['success' => false, 'error' => 'Gagal menyimpan file backup']);
            exit;
        }

        // download=1: also stream the bytes back in this same response (full parity with the old
        // billing_backup_data's download mode), instead of only writing to disk.
        if (!empty($rawInput['download']) || !empty($_GET['download'])) {
            header_remove('Content-Type');
            if ($compress && function_exists('gzencode')) {
                header('Content-Type: application/gzip');
            } else {
                header('Content-Type: application/json; charset=utf-8');
            }
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $bytes;
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Backup berhasil dibuat',
            'file' => $filename,
            'summary' => $payload['summary']
        ]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete_file') {
        $filename = trim((string)($rawInput['filename'] ?? ''));
        if ($filename === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $filename)) {
            echo json_encode(['success' => false, 'error' => 'Nama file tidak valid']);
            exit;
        }
        if (strpos($filename, 'backup_' . $safeOwner . '_') !== 0) {
            echo json_encode(['success' => false, 'error' => 'File bukan milik owner ini']);
            exit;
        }

        $path = $backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            echo json_encode(['success' => false, 'error' => 'File tidak ditemukan']);
            exit;
        }

        if (!@unlink($path)) {
            echo json_encode(['success' => false, 'error' => 'Gagal menghapus file']);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'File backup dihapus']);
        exit;
    }

    if ($method === 'POST' && $action === 'restore_file') {
        $filename = trim((string)($rawInput['filename'] ?? ''));
        if ($filename === '' || !preg_match('/^[A-Za-z0-9_.-]+$/', $filename)) {
            echo json_encode(['success' => false, 'error' => 'Nama file tidak valid']);
            exit;
        }
        if (strpos($filename, 'backup_' . $safeOwner . '_') !== 0) {
            echo json_encode(['success' => false, 'error' => 'File bukan milik owner ini']);
            exit;
        }

        $path = $backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            echo json_encode(['success' => false, 'error' => 'File backup tidak ditemukan']);
            exit;
        }

        list($content, $contentError) = load_backup_content_mobile($path);
        if ($content === false) {
            echo json_encode(['success' => false, 'error' => $contentError ?: 'Gagal membaca backup']);
            exit;
        }

        $decoded = json_decode((string)$content, true);
        if (!is_array($decoded)) {
            echo json_encode(['success' => false, 'error' => 'Format file backup tidak valid']);
            exit;
        }

        [$ok, $error, $stats] = apply_restore_payload($conn, $pemilik, $decoded, $allowedTables, $rawInput['tables'] ?? null);
        if (!$ok) {
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Restore selesai'] + $stats);
        exit;
    }

    // action=restore_payload: restore directly from a JSON payload in the request body (full
    // parity with the old billing_restore_data, which accepted `backup_payload`/an uploaded file
    // directly rather than requiring a filename already saved on the server first).
    if ($method === 'POST' && $action === 'restore_payload') {
        $payloadRaw = $rawInput['backup_payload'] ?? null;
        if ($payloadRaw === null && !empty($_FILES['backup_file']['tmp_name'])) {
            $payloadRaw = @file_get_contents($_FILES['backup_file']['tmp_name']);
        }
        if ($payloadRaw === null || $payloadRaw === '') {
            echo json_encode(['success' => false, 'error' => 'backup_payload atau backup_file wajib diisi']);
            exit;
        }

        $decoded = is_array($payloadRaw) ? $payloadRaw : json_decode((string)$payloadRaw, true);
        if (!is_array($decoded)) {
            echo json_encode(['success' => false, 'error' => 'Format backup_payload tidak valid (harus JSON)']);
            exit;
        }

        [$ok, $error, $stats] = apply_restore_payload($conn, $pemilik, $decoded, $allowedTables, $rawInput['tables'] ?? null);
        if (!$ok) {
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Restore selesai'] + $stats);
        exit;
    }

    // Default (action=list or unrecognized): list this owner's saved backup files.
    $files = [];
    $scan = @scandir($backupDir);
    if (is_array($scan)) {
        foreach ($scan as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            if (strpos($file, 'backup_' . $safeOwner . '_') !== 0) {
                continue;
            }
            $path = $backupDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }
            $files[] = [
                'filename' => $file,
                'size' => (int)filesize($path),
                'modified_at' => date('Y-m-d H:i:s', (int)filemtime($path))
            ];
        }
    }

    usort($files, function ($a, $b) {
        return strcmp((string)$b['modified_at'], (string)$a['modified_at']);
    });

    echo json_encode(['success' => true, 'files' => $files]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
