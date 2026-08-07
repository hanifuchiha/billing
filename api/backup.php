<?php
// api/backup.php - Backup / Restore / catalog API (ported from apiinterface.php, being retired).
// Dispatches on ?type= (same convention as the source file, since this is a small family of
// related bulk-data actions rather than a single-resource REST endpoint):
//   - api_features            (GET)  feature/endpoint catalog + backup-allowed-tables + type aliases
//   - billing_core_data       (GET)  bundles pelanggan/odp/paket/server/transaksi/olt/paket_hotspot
//   - billing_backup_data     (GET)  exports caller's own rows from selected tables as JSON
//   - billing_backup_structure(GET)  dumps `SHOW CREATE TABLE` for every table as a .sql file
//   - billing_restore_data    (POST) restores rows into the caller's own account only
//
// Backup/restore scoping note: unlike most other api/*.php files in this batch, billing_core_data
// uses the broader api_allowed_pemilik_list()/api_pemilik_in_sql() scoping (matches api/pelanggan.php,
// api/odp.php), but billing_backup_data/billing_backup_structure/billing_restore_data intentionally
// scope only to the caller's OWN username ($pemilik), exactly like the original apiinterface.php did -
// you back up/restore your OWN account's data, not an aggregate of every server you can see as an
// assistant.
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();

api_cors();
$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    // This endpoint deals in bulk data exports/imports, so keeping rate-limiting active for
    // apikey callers here matters more than most - don't drop it.
    api_rate_limit($conn, $auth['api_key']);
}
$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}

// ---------------------------------------------------------------------------
// Helper functions ported near-verbatim from apiinterface.php
// ---------------------------------------------------------------------------

function tableExistsApi($conn, $table)
{
    $tableEsc = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$tableEsc'");
    return $res && mysqli_num_rows($res) > 0;
}

function getTableColumnsApi($conn, $table)
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

function detectOwnerColumnApi($columns)
{
    foreach (['PEMILIK', 'pemilik'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
}

function sanitizeTablesApi($inputTables, $allowedTables)
{
    $clean = [];
    if (is_string($inputTables) && $inputTables !== '') {
        if ($inputTables[0] === '[') {
            $decoded = json_decode($inputTables, true);
            if (is_array($decoded)) {
                $inputTables = $decoded;
            }
        } else {
            $inputTables = explode(',', $inputTables);
        }
    }
    if (!is_array($inputTables)) {
        return [];
    }
    foreach ($inputTables as $table) {
        $table = trim((string)$table);
        if (in_array($table, $allowedTables, true)) {
            $clean[] = $table;
        }
    }
    return array_values(array_unique($clean));
}

function getAllTablesApi($conn)
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

// ---------------------------------------------------------------------------
// Static catalog data (ported verbatim from apiinterface.php)
// ---------------------------------------------------------------------------

$typeAliases = [
    'pelanggan' => 'customers_table',
    'data_pelanggan' => 'customers_table',
    'paket' => 'pppoe_packages',
    'data_paket' => 'pppoe_packages',
    'server' => 'servers',
    'data_server' => 'servers',
    'transaksi' => 'transactions',
    'data_transaksi' => 'transactions'
];

$backupAllowedTables = [
    'pelanggan',
    'odp',
    'paket',
    'server',
    'pool',
    'transaksi',
    'diskon',
    'biaya_tambahan',
    'botwa',
    'paket_hotspot',
    'olt',
    'voucher'
];

$apiFeatureList = [
    'servers',
    'pelanggan',
    'data_pelanggan',
    'active_customers',
    'hotspot_active',
    'transactions',
    'transaksi',
    'data_transaksi',
    'customers_table',
    'hotspot_table',
    'voucher_bank',
    'active_vouchers',
    'pppoe_packages',
    'paket',
    'data_paket',
    'hotspot_packages',
    'odp',
    'olt',
    'server',
    'data_server',
    'billing_core_data',
    'add_pppoe_package',
    'add_hotspot_package',
    'add_customer',
    'add_odp',
    'add_olt',
    'add_server',
    'edit_pppoe_package',
    'edit_hotspot_package',
    'edit_customer',
    'edit_odp',
    'edit_olt',
    'edit_server',
    'activate_customer',
    'disable_customer',
    'delete_pppoe_package',
    'delete_hotspot_package',
    'delete_customer',
    'delete_odp',
    'delete_olt',
    'delete_server',
    'api_features',
    'billing_backup_data',
    'billing_backup_structure',
    'billing_restore_data'
];

$type = $_GET['type'] ?? $_POST['type'] ?? '';
if (isset($typeAliases[$type])) {
    $type = $typeAliases[$type];
}

switch ($type) {

    // -------------------------------------------------------------------
    case 'api_features':
        api_json([
            'success' => true,
            'data' => [
                'owner' => $pemilik,
                'features' => $apiFeatureList,
                'backup_allowed_tables' => $backupAllowedTables,
                'aliases' => $typeAliases
            ]
        ]);
        break;

    // -------------------------------------------------------------------
    case 'billing_core_data':
        $pemilikList = api_allowed_pemilik_list($conn, $ctx);
        $pemilikInSql = api_pemilik_in_sql($conn, $pemilikList);

        $includeRaw = $_GET['include'] ?? ($_POST['include'] ?? 'pelanggan,odp,paket,server,transaksi');
        $include = array_filter(array_map('trim', explode(',', strtolower((string)$includeRaw))));
        if (empty($include)) {
            $include = ['pelanggan', 'odp', 'paket', 'server', 'transaksi'];
        }

        $allowedInclude = ['pelanggan', 'odp', 'paket', 'server', 'transaksi', 'olt', 'paket_hotspot'];
        $include = array_values(array_intersect($include, $allowedInclude));
        if (empty($include)) {
            api_json(['success' => false, 'error' => 'include tidak valid'], 400);
        }

        $resultBundle = [
            'owner' => $pemilik,
            'generated_at' => date('Y-m-d H:i:s'),
            'include' => $include,
            'data' => []
        ];

        foreach ($include as $entity) {
            if ($entity === 'pelanggan') {
                $where = ["PEMILIK IN ($pemilikInSql)"];
                $paket = $_GET['paket'] ?? '';
                $areaFilter = $_GET['area'] ?? '';
                $statusFilter = $_GET['status'] ?? '';
                if ($paket !== '') $where[] = "PAKET='" . mysqli_real_escape_string($conn, $paket) . "'";
                if ($areaFilter !== '') $where[] = "AREA='" . mysqli_real_escape_string($conn, $areaFilter) . "'";
                if ($statusFilter !== '') $where[] = "STATUS='" . mysqli_real_escape_string($conn, $statusFilter) . "'";
                $sql = "SELECT * FROM pelanggan WHERE " . implode(' AND ', $where);
            } elseif ($entity === 'odp') {
                $sql = "SELECT * FROM odp WHERE pemilik IN ($pemilikInSql)";
            } elseif ($entity === 'paket') {
                $sql = "SELECT * FROM paket WHERE pemilik IN ($pemilikInSql)";
            } elseif ($entity === 'server') {
                $sql = "SELECT * FROM server WHERE pemilik IN ($pemilikInSql)";
            } elseif ($entity === 'transaksi') {
                $where = ["pemilik IN ($pemilikInSql)"];
                $startDate = $_GET['start_date'] ?? '';
                $endDate = $_GET['end_date'] ?? '';
                $trxStatus = $_GET['status_transaksi'] ?? ($_GET['status'] ?? '');
                if ($startDate !== '') $where[] = "tanggal >= '" . mysqli_real_escape_string($conn, $startDate) . "'";
                if ($endDate !== '') $where[] = "tanggal <= '" . mysqli_real_escape_string($conn, $endDate) . "'";
                if ($trxStatus !== '') $where[] = "status = '" . mysqli_real_escape_string($conn, $trxStatus) . "'";
                $sql = "SELECT * FROM transaksi WHERE " . implode(' AND ', $where) . " ORDER BY id DESC";
            } elseif ($entity === 'olt') {
                $sql = "SELECT * FROM olt WHERE pemilik IN ($pemilikInSql)";
            } else {
                $sql = "SELECT * FROM paket_hotspot WHERE pemilik IN ($pemilikInSql)";
            }

            $res = mysqli_query($conn, $sql);
            if (!$res) {
                $resultBundle['data'][$entity] = [
                    'error' => mysqli_error($conn),
                    'rows' => []
                ];
                continue;
            }

            $rows = [];
            while ($row = mysqli_fetch_assoc($res)) {
                $rows[] = $row;
            }
            $resultBundle['data'][$entity] = [
                'count' => count($rows),
                'rows' => $rows
            ];
        }

        api_json(['success' => true, 'data' => $resultBundle]);
        break;

    // -------------------------------------------------------------------
    case 'billing_backup_data':
        $inputTables = $_GET['tables'] ?? ($_POST['tables'] ?? []);
        $selectedTables = sanitizeTablesApi($inputTables, $backupAllowedTables);
        if (empty($selectedTables)) {
            $selectedTables = $backupAllowedTables;
        }

        $payload = [
            'meta' => [
                'owner' => $pemilik,
                'generated_at' => date('Y-m-d H:i:s'),
                'format' => 'qts-owner-backup-v1',
                'selected_tables' => $selectedTables,
                'via' => 'api/backup.php'
            ],
            'data' => [],
            'summary' => []
        ];

        foreach ($selectedTables as $table) {
            if (!tableExistsApi($conn, $table)) {
                $payload['summary'][$table] = ['status' => 'skip', 'reason' => 'table not found'];
                continue;
            }

            $columns = getTableColumnsApi($conn, $table);
            $ownerColumn = detectOwnerColumnApi($columns);
            if ($ownerColumn === null) {
                $payload['summary'][$table] = ['status' => 'skip', 'reason' => 'owner column not found'];
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

        $download = ($_GET['download'] ?? $_POST['download'] ?? '0') === '1';
        $compress = ($_GET['compress'] ?? $_POST['compress'] ?? '0') === '1';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($download) {
            $safeOwner = preg_replace('/[^A-Za-z0-9_-]/', '_', $pemilik);
            if ($compress && function_exists('gzencode')) {
                $gzip = gzencode($json, 6);
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="backup_' . $safeOwner . '_' . date('Ymd_His') . '.json.gz"');
                echo $gzip;
                exit;
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="backup_' . $safeOwner . '_' . date('Ymd_His') . '.json"');
            echo $json;
            exit;
        }

        api_json(['success' => true, 'data' => $payload]);
        break;

    // -------------------------------------------------------------------
    case 'billing_backup_structure':
        // Restricted to $backupAllowedTables (tenant-owned tables), NOT every table in the
        // database. The original apiinterface.php dumped getAllTablesApi() unrestricted here,
        // which leaked every other tenant's/system table's schema (user, apikey, api_requests,
        // etc.) to any single owner's API key - fixed rather than replicated.
        $requestedTables = $_GET['tables'] ?? ($_POST['tables'] ?? []);
        $allTables = sanitizeTablesApi($requestedTables, $backupAllowedTables);
        if (empty($allTables)) {
            $allTables = $backupAllowedTables;
        }
        $allTables = array_values(array_filter($allTables, function ($t) use ($conn) {
            return tableExistsApi($conn, $t);
        }));
        if (empty($allTables)) {
            api_json(['success' => false, 'error' => 'No tables found'], 404);
        }

        $sqlDump = [];
        $sqlDump[] = '-- QTS Billing Structure Backup (API)';
        $sqlDump[] = '-- Owner/API User: ' . $pemilik;
        $sqlDump[] = '-- Generated at: ' . date('Y-m-d H:i:s');
        $sqlDump[] = '';
        $sqlDump[] = 'SET NAMES utf8mb4;';
        $sqlDump[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $sqlDump[] = '';

        foreach ($allTables as $table) {
            $res = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
            if (!$res) {
                $sqlDump[] = '-- Failed table `' . $table . '` : ' . mysqli_error($conn);
                $sqlDump[] = '';
                continue;
            }
            $row = mysqli_fetch_assoc($res);
            $createSql = $row['Create Table'] ?? ($row['Create View'] ?? '');
            if ($createSql === '') {
                continue;
            }
            $sqlDump[] = '-- ----------------------------';
            $sqlDump[] = '-- Structure for `' . $table . '`';
            $sqlDump[] = '-- ----------------------------';
            $sqlDump[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
            $sqlDump[] = $createSql . ';';
            $sqlDump[] = '';
        }
        $sqlDump[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $content = implode("\n", $sqlDump);

        $download = ($_GET['download'] ?? $_POST['download'] ?? '1') === '1';
        if ($download) {
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="struktur_db_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $pemilik) . '_' . date('Ymd_His') . '.sql"');
            echo $content;
            exit;
        }

        api_json(['success' => true, 'data' => ['sql' => $content]]);
        break;

    // -------------------------------------------------------------------
    case 'billing_restore_data':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            api_json(['success' => false, 'error' => 'Method not allowed'], 405);
        }

        $payloadText = $input['backup_payload'] ?? ($_POST['backup_payload'] ?? '');
        if ($payloadText === '' && !empty($_FILES['backup_file']['tmp_name'])) {
            $payloadText = file_get_contents($_FILES['backup_file']['tmp_name']);
        }
        if ($payloadText === '') {
            api_json(['success' => false, 'error' => 'backup_payload required'], 400);
        }

        $decoded = json_decode($payloadText, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            api_json(['success' => false, 'error' => 'Invalid backup payload format'], 400);
        }

        $inputTables = $input['tables'] ?? ($_POST['tables'] ?? []);
        $selectedTables = sanitizeTablesApi($inputTables, $backupAllowedTables);
        if (empty($selectedTables)) {
            $selectedTables = array_values(array_intersect(array_keys($decoded['data']), $backupAllowedTables));
        }
        if (empty($selectedTables)) {
            api_json(['success' => false, 'error' => 'No valid tables to restore'], 400);
        }

        $chunkSize = 300;
        $insertedRows = 0;
        $processedTables = 0;
        $skipped = [];

        mysqli_begin_transaction($conn);
        try {
            foreach ($selectedTables as $table) {
                if (!isset($decoded['data'][$table]) || !is_array($decoded['data'][$table])) {
                    $skipped[] = $table . ': no data in payload';
                    continue;
                }
                if (!tableExistsApi($conn, $table)) {
                    $skipped[] = $table . ': table not found';
                    continue;
                }

                $dbColumns = getTableColumnsApi($conn, $table);
                $ownerColumn = detectOwnerColumnApi($dbColumns);
                if ($ownerColumn === null) {
                    $skipped[] = $table . ': owner column not found';
                    continue;
                }

                $ownerEsc = mysqli_real_escape_string($conn, $pemilik);
                if (!mysqli_query($conn, "DELETE FROM `$table` WHERE `$ownerColumn` = '$ownerEsc'")) {
                    throw new Exception('Delete failed on ' . $table . ': ' . mysqli_error($conn));
                }

                $rows = $decoded['data'][$table];
                $rowCount = count($rows);
                for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
                    $chunk = array_slice($rows, $offset, $chunkSize);
                    foreach ($chunk as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        // Rewrite owner column to the caller's own username on every inserted row,
                        // and strip id so autoincrement assigns fresh ids - a restore can only ever
                        // populate the caller's own account, never someone else's.
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
                            throw new Exception('Insert failed on ' . $table . ': ' . mysqli_error($conn));
                        }
                        $insertedRows++;
                    }
                }

                $processedTables++;
            }

            mysqli_commit($conn);
            api_json([
                'success' => true,
                'data' => [
                    'processed_tables' => $processedTables,
                    'inserted_rows' => $insertedRows,
                    'skipped' => $skipped
                ]
            ]);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            api_json(['success' => false, 'error' => $e->getMessage()], 500);
        }
        break;

    // -------------------------------------------------------------------
    default:
        api_json([
            'success' => false,
            'error' => 'Parameter type tidak valid atau belum diisi.',
            'valid_types' => [
                'api_features',
                'billing_core_data',
                'billing_backup_data',
                'billing_backup_structure',
                'billing_restore_data'
            ]
        ], 400);
}
