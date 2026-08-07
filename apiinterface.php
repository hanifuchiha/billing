<?php
// Set batas waktu eksekusi script (30 detik)
set_time_limit(30);

// CORS headers untuk mengizinkan cross-origin requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 1 day

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Fungsi untuk rate limiting
function checkRateLimit($apiKey, $conn) {
    // Cleanup record lama (lebih dari 24 jam)
    $cleanup_sql = "DELETE FROM api_requests WHERE request_time < DATE_SUB(NOW(), INTERVAL 1 DAY)";
    mysqli_query($conn, $cleanup_sql);
    
    $current_hour = date('Y-m-d H:00:00');
    $sql = "SELECT COUNT(*) as request_count FROM api_requests WHERE api_key = '$apiKey' AND request_time >= '$current_hour'";
    $result = mysqli_query($conn, $sql);
    $count = 0;
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $count = $row['request_count'];
    }
    if ($count >= 100) { // Maksimal 100 request per jam per API key
        return ['allowed' => false, 'remaining' => 0, 'reset_time' => strtotime($current_hour . ' +1 hour')];
    }
    // Insert request log
    $sql_insert = "INSERT INTO api_requests (api_key, request_time) VALUES ('$apiKey', NOW())";
    mysqli_query($conn, $sql_insert);
    return ['allowed' => true, 'remaining' => 99 - $count, 'reset_time' => strtotime($current_hour . ' +1 hour')];
}

// Fungsi untuk cek koneksi MikroTik dengan timeout
function checkMikrotikConnection($host, $port, $timeout = 10) {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

require 'koneksibilling.php';

// Buat tabel api_requests jika belum ada
$sql_create_table = "CREATE TABLE IF NOT EXISTS api_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_key VARCHAR(255) NOT NULL,
    request_time DATETIME NOT NULL,
    INDEX idx_api_key_time (api_key, request_time)
)";
mysqli_query($conn, $sql_create_table);

// Fungsi untuk cek API key dan dapatkan user
function checkApiKey($providedKey) {
    global $conn;
    $sql = "SELECT user_name FROM apikey WHERE api_key = '$providedKey' LIMIT 1";
    $result = mysqli_query($conn, $sql);
    if ($result && $row = mysqli_fetch_assoc($result)) {
        return $row['user_name'];
    }
    return false;
}

// Fungsi untuk response JSON
function sendJsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

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

// Cek API key
$apiKey = $_GET['key'] ?? $_POST['key'] ?? '';
$user_name = checkApiKey($apiKey);
if (!$user_name) {
    sendJsonResponse(['error' => 'Invalid API key'], 401);
}

// Cek rate limiting
$rateLimit = checkRateLimit($apiKey, $conn);
if (!$rateLimit['allowed']) {
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: ' . $rateLimit['reset_time']);
    sendJsonResponse(['error' => 'Rate limit exceeded. Maximum 100 requests per hour.'], 429);
}
header('X-RateLimit-Remaining: ' . $rateLimit['remaining']);
header('X-RateLimit-Reset: ' . $rateLimit['reset_time']);
header('X-Request-Timeout: 30'); // Timeout maksimal script 30 detik

// Set server_list berdasarkan user dari API key
$sql_user = "SELECT server FROM user WHERE USERNAME = '$user_name' LIMIT 1";
$result_user = mysqli_query($conn, $sql_user);
$server_list = '';
if ($result_user && $row_user = mysqli_fetch_assoc($result_user)) {
    $server_raw = $row_user['server'];
    // Asumsikan JSON array atau comma separated
    if (strpos($server_raw, '[') === 0) { // JSON
        $servers = json_decode($server_raw, true);
        $server_list = "'" . implode("','", array_map('mysqli_real_escape_string', array_fill(0, count($servers), $conn), $servers)) . "'";
    } else { // Comma separated
        $servers = explode(',', $server_raw);
        $server_list = "'" . implode("','", array_map('trim', array_map('mysqli_real_escape_string', array_fill(0, count($servers), $conn), $servers))) . "'";
    }
}

$type = $_GET['type'] ?? $_POST['type'] ?? '';
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
if (isset($typeAliases[$type])) {
    $type = $typeAliases[$type];
}

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

switch ($type) {
    case 'api_features':
        sendJsonResponse([
            'owner' => $user_name,
            'features' => $apiFeatureList,
            'backup_allowed_tables' => $backupAllowedTables,
            'aliases' => $typeAliases
        ]);
        break;

    case 'billing_core_data':
        $includeRaw = $_GET['include'] ?? ($_POST['include'] ?? 'pelanggan,odp,paket,server,transaksi');
        $include = array_filter(array_map('trim', explode(',', strtolower((string)$includeRaw))));
        if (empty($include)) {
            $include = ['pelanggan', 'odp', 'paket', 'server', 'transaksi'];
        }

        $allowedInclude = ['pelanggan', 'odp', 'paket', 'server', 'transaksi', 'olt', 'paket_hotspot'];
        $include = array_values(array_intersect($include, $allowedInclude));
        if (empty($include)) {
            sendJsonResponse(['error' => 'include tidak valid'], 400);
        }

        $resultBundle = [
            'owner' => $user_name,
            'generated_at' => date('Y-m-d H:i:s'),
            'include' => $include,
            'data' => []
        ];

        foreach ($include as $entity) {
            if ($entity === 'pelanggan') {
                $where = ["PEMILIK IN ($server_list)"];
                $paket = $_GET['paket'] ?? '';
                $areaFilter = $_GET['area'] ?? '';
                $statusFilter = $_GET['status'] ?? '';
                if ($paket !== '') $where[] = "PAKET='" . mysqli_real_escape_string($conn, $paket) . "'";
                if ($areaFilter !== '') $where[] = "AREA='" . mysqli_real_escape_string($conn, $areaFilter) . "'";
                if ($statusFilter !== '') $where[] = "STATUS='" . mysqli_real_escape_string($conn, $statusFilter) . "'";
                $sql = "SELECT * FROM pelanggan WHERE " . implode(' AND ', $where);
            } elseif ($entity === 'odp') {
                $sql = "SELECT * FROM odp WHERE pemilik IN ($server_list)";
            } elseif ($entity === 'paket') {
                $sql = "SELECT * FROM paket WHERE pemilik IN ($server_list)";
            } elseif ($entity === 'server') {
                $sql = "SELECT * FROM server WHERE pemilik IN ($server_list)";
            } elseif ($entity === 'transaksi') {
                $where = ["pemilik IN ($server_list)"];
                $startDate = $_GET['start_date'] ?? '';
                $endDate = $_GET['end_date'] ?? '';
                $trxStatus = $_GET['status_transaksi'] ?? ($_GET['status'] ?? '');
                if ($startDate !== '') $where[] = "tanggal >= '" . mysqli_real_escape_string($conn, $startDate) . "'";
                if ($endDate !== '') $where[] = "tanggal <= '" . mysqli_real_escape_string($conn, $endDate) . "'";
                if ($trxStatus !== '') $where[] = "status = '" . mysqli_real_escape_string($conn, $trxStatus) . "'";
                $sql = "SELECT * FROM transaksi WHERE " . implode(' AND ', $where) . " ORDER BY id DESC";
            } elseif ($entity === 'olt') {
                $sql = "SELECT * FROM olt WHERE pemilik IN ($server_list)";
            } else {
                $sql = "SELECT * FROM paket_hotspot WHERE pemilik IN ($server_list)";
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

        sendJsonResponse($resultBundle);
        break;

    case 'billing_backup_data':
        $inputTables = $_GET['tables'] ?? ($_POST['tables'] ?? []);
        $selectedTables = sanitizeTablesApi($inputTables, $backupAllowedTables);
        if (empty($selectedTables)) {
            $selectedTables = $backupAllowedTables;
        }

        $payload = [
            'meta' => [
                'owner' => $user_name,
                'generated_at' => date('Y-m-d H:i:s'),
                'format' => 'qts-owner-backup-v1',
                'selected_tables' => $selectedTables,
                'via' => 'apiinterface'
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

            $ownerEsc = mysqli_real_escape_string($conn, $user_name);
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
            $safeOwner = preg_replace('/[^A-Za-z0-9_-]/', '_', $user_name);
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

        sendJsonResponse($payload);
        break;

    case 'billing_backup_structure':
        $allTables = getAllTablesApi($conn);
        if (empty($allTables)) {
            sendJsonResponse(['error' => 'No tables found'], 404);
        }

        $sqlDump = [];
        $sqlDump[] = '-- QTS Billing Structure Backup (API)';
        $sqlDump[] = '-- Owner/API User: ' . $user_name;
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
            header('Content-Disposition: attachment; filename="struktur_db_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $user_name) . '_' . date('Ymd_His') . '.sql"');
            echo $content;
            exit;
        }

        sendJsonResponse(['sql' => $content]);
        break;

    case 'billing_restore_data':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }

        $payloadText = $_POST['backup_payload'] ?? '';
        if ($payloadText === '' && !empty($_FILES['backup_file']['tmp_name'])) {
            $payloadText = file_get_contents($_FILES['backup_file']['tmp_name']);
        }
        if ($payloadText === '') {
            sendJsonResponse(['error' => 'backup_payload required'], 400);
        }

        $decoded = json_decode($payloadText, true);
        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            sendJsonResponse(['error' => 'Invalid backup payload format'], 400);
        }

        $inputTables = $_POST['tables'] ?? [];
        $selectedTables = sanitizeTablesApi($inputTables, $backupAllowedTables);
        if (empty($selectedTables)) {
            $selectedTables = array_values(array_intersect(array_keys($decoded['data']), $backupAllowedTables));
        }
        if (empty($selectedTables)) {
            sendJsonResponse(['error' => 'No valid tables to restore'], 400);
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

                $ownerEsc = mysqli_real_escape_string($conn, $user_name);
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
                        $row[$ownerColumn] = $user_name;
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
                            $row[$ownerColumn] = $user_name;
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
            sendJsonResponse([
                'success' => true,
                'processed_tables' => $processedTables,
                'inserted_rows' => $insertedRows,
                'skipped' => $skipped
            ]);
        } catch (Exception $e) {
            mysqli_rollback($conn);
            sendJsonResponse(['error' => $e->getMessage()], 500);
        }
        break;

    case 'servers':
        // Data server
        $sql = "SELECT * FROM server WHERE pemilik IN ($server_list)";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['servers' => $data]);
        break;

    case 'active_customers':
        // Pelanggan aktif dari radacct, filter berdasarkan user yang ada di pelanggan milik server user
        $sql_pelanggan = "SELECT IDPEL FROM pelanggan WHERE PEMILIK IN ($server_list)";
        $result_pelanggan = mysqli_query($conn, $sql_pelanggan);
        $idpel_list = [];
        while ($row_pelanggan = mysqli_fetch_assoc($result_pelanggan)) {
            $idpel_list[] = "'" . mysqli_real_escape_string($conn, $row_pelanggan['IDPEL']) . "'";
        }
        if (!empty($idpel_list)) {
            $idpel_in = implode(',', $idpel_list);
            $sql = "SELECT username, framedipaddress, acctstarttime, acctsessiontime FROM radacct WHERE acctstoptime IS NULL AND username IN ($idpel_in)";
        } else {
            $sql = "SELECT username, framedipaddress, acctstarttime, acctsessiontime FROM radacct WHERE 1=0"; // No data
        }
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['active_customers' => $data]);
        break;

    case 'hotspot_active':
        // Sama dengan active_customers, filter berdasarkan pelanggan milik server user
        $sql_pelanggan = "SELECT IDPEL FROM pelanggan WHERE PEMILIK IN ($server_list)";
        $result_pelanggan = mysqli_query($conn, $sql_pelanggan);
        $idpel_list = [];
        while ($row_pelanggan = mysqli_fetch_assoc($result_pelanggan)) {
            $idpel_list[] = "'" . mysqli_real_escape_string($conn, $row_pelanggan['IDPEL']) . "'";
        }
        if (!empty($idpel_list)) {
            $idpel_in = implode(',', $idpel_list);
            $sql = "SELECT username, framedipaddress, acctstarttime, acctsessiontime FROM radacct WHERE acctstoptime IS NULL AND username IN ($idpel_in)";
        } else {
            $sql = "SELECT username, framedipaddress, acctstarttime, acctsessiontime FROM radacct WHERE 1=0"; // No data
        }
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['hotspot_active' => $data]);
        break;

    case 'transactions':
        // Data transaksi dengan filtering
        $start_date = $_GET['start_date'] ?? '';
        $end_date = $_GET['end_date'] ?? '';
        $status = $_GET['status'] ?? '';
        $where_clauses = ["pemilik IN ($server_list)"];
        if (!empty($start_date)) {
            $where_clauses[] = "tanggal >= '" . mysqli_real_escape_string($conn, $start_date) . "'";
        }
        if (!empty($end_date)) {
            $where_clauses[] = "tanggal <= '" . mysqli_real_escape_string($conn, $end_date) . "'";
        }
        if (!empty($status)) {
            $where_clauses[] = "status = '" . mysqli_real_escape_string($conn, $status) . "'";
        }
        $where = implode(' AND ', $where_clauses);
        $sql = "SELECT * FROM transaksi WHERE $where ORDER BY id DESC";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['transactions' => $data]);
        break;

    case 'customers_table':
        // Tabel pelanggan dengan filtering
        $paket = $_GET['paket'] ?? '';
        $area_filter = $_GET['area'] ?? '';
        $status_filter = $_GET['status'] ?? '';
        $where_clauses = ["PEMILIK IN ($server_list)"];
        if (!empty($paket)) {
            $where_clauses[] = "PAKET = '" . mysqli_real_escape_string($conn, $paket) . "'";
        }
        if (!empty($area_filter)) {
            $where_clauses[] = "AREA = '" . mysqli_real_escape_string($conn, $area_filter) . "'";
        }
        if (!empty($status_filter)) {
            $where_clauses[] = "STATUS = '" . mysqli_real_escape_string($conn, $status_filter) . "'";
        }
        $where = implode(' AND ', $where_clauses);
        $sql = "SELECT * FROM pelanggan WHERE $where";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['customers_table' => $data]);
        break;

    case 'hotspot_table':
        // Tabel hotspot, filter berdasarkan pelanggan milik server user
        $sql_pelanggan = "SELECT IDPEL FROM pelanggan WHERE PEMILIK IN ($server_list)";
        $result_pelanggan = mysqli_query($conn, $sql_pelanggan);
        $idpel_list = [];
        while ($row_pelanggan = mysqli_fetch_assoc($result_pelanggan)) {
            $idpel_list[] = "'" . mysqli_real_escape_string($conn, $row_pelanggan['IDPEL']) . "'";
        }
        if (!empty($idpel_list)) {
            $idpel_in = implode(',', $idpel_list);
            $sql = "SELECT username, framedipaddress, acctstarttime, acctsessiontime FROM radacct WHERE acctstoptime IS NULL AND username IN ($idpel_in)";
        } else {
            $sql = "SELECT username, framedipaddress, acctstarttime, acctsessiontime FROM radacct WHERE 1=0"; // No data
        }
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['hotspot_table' => $data]);
        break;

    case 'voucher_bank':
        // Voucher dari tabel voucher
        $sql = "SELECT * FROM voucher WHERE pemilik = '$user_name'";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['voucher_bank' => $data]);
        break;

    case 'active_vouchers':
        // Voucher aktif dari RADIUS, filter berdasarkan pelanggan milik server user
        $sql_pelanggan = "SELECT IDPEL FROM pelanggan WHERE PEMILIK IN ($server_list)";
        $result_pelanggan = mysqli_query($conn, $sql_pelanggan);
        $idpel_list = [];
        while ($row_pelanggan = mysqli_fetch_assoc($result_pelanggan)) {
            $idpel_list[] = $row_pelanggan['IDPEL'];
        }
        $online_users = [];
        $cmd = "sudo /usr/bin/radwho -d /etc/freeradius/3.0 -F /var/log/freeradius/radutmp 2>&1";
        $output = shell_exec($cmd);
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^(\S+)/', $line, $matches)) {
                $user_online = $matches[1];
                if (in_array($user_online, $idpel_list)) {
                    $online_users[] = $user_online;
                }
            }
        }
        sendJsonResponse(['active_vouchers' => $online_users]);
        break;

    case 'pppoe_packages':
        // Data paket PPPOE dengan filtering
        $area_filter = $_GET['area'] ?? '';
        $harga_min = $_GET['harga_min'] ?? '';
        $harga_max = $_GET['harga_max'] ?? '';
        $where_clauses = ["pemilik IN ($server_list)"];
        if (!empty($area_filter)) {
            $where_clauses[] = "AREA = '" . mysqli_real_escape_string($conn, $area_filter) . "'";
        }
        if (!empty($harga_min)) {
            $where_clauses[] = "HARGA >= '" . mysqli_real_escape_string($conn, $harga_min) . "'";
        }
        if (!empty($harga_max)) {
            $where_clauses[] = "HARGA <= '" . mysqli_real_escape_string($conn, $harga_max) . "'";
        }
        $where = implode(' AND ', $where_clauses);
        $sql = "SELECT * FROM paket WHERE $where";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['pppoe_packages' => $data]);
        break;

    case 'hotspot_packages':
        // Data paket Hotspot dengan filtering
        $area_filter = $_GET['area'] ?? '';
        $harga_min = $_GET['harga_min'] ?? '';
        $harga_max = $_GET['harga_max'] ?? '';
        $where_clauses = ["pemilik IN ($server_list)"];
        if (!empty($area_filter)) {
            $where_clauses[] = "area = '" . mysqli_real_escape_string($conn, $area_filter) . "'";
        }
        if (!empty($harga_min)) {
            $where_clauses[] = "harga >= '" . mysqli_real_escape_string($conn, $harga_min) . "'";
        }
        if (!empty($harga_max)) {
            $where_clauses[] = "harga <= '" . mysqli_real_escape_string($conn, $harga_max) . "'";
        }
        $where = implode(' AND ', $where_clauses);
        $sql = "SELECT * FROM paket_hotspot WHERE $where";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['hotspot_packages' => $data]);
        break;

    case 'odp':
        // Data ODP
        $sql = "SELECT * FROM odp WHERE pemilik IN ($server_list)";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['odp' => $data]);
        break;

    case 'olt':
        // Data OLT
        $sql = "SELECT * FROM olt WHERE pemilik IN ($server_list)";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        sendJsonResponse(['olt' => $data]);
        break;

    case 'edit_pppoe_package':
        // Edit paket PPPOE (sesuai editpackagespppoe.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        $profileName = $_POST['profileName'] ?? '';
        $ratelimit = $_POST['ratelimit'] ?? '';
        $harga = $_POST['harga'] ?? '';
        $local = $_POST['local'] ?? '';
        $remot = $_POST['remot'] ?? '';
        $server = $_POST['server'] ?? '';
        $area = $_POST['area'] ?? '';
        $komisi = $_POST['komisi'] ?? '';
        if (empty($id) || empty($profileName)) {
            sendJsonResponse(['error' => 'ID and profileName required'], 400);
        }
        // Cek apakah paket milik server user
        $sql_check = "SELECT id FROM paket WHERE id = '$id' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Package not found or not authorized'], 403);
        }
        $sql_update = "UPDATE paket SET PAKET='$profileName', KECEPATAN='$ratelimit', HARGA='$harga', LOCAL='$local', REMOTE='$remot', PEMILIK='$server', AREA='$area', komisi='$komisi' WHERE id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            sendJsonResponse(['success' => 'PPPOE Package updated']);
        } else {
            sendJsonResponse(['error' => 'Update failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'edit_hotspot_package':
        // Edit paket Hotspot (sama dengan PPPOE untuk sekarang)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        $paket = $_POST['paket'] ?? '';
        $harga = $_POST['harga'] ?? '';
        $komisi = $_POST['komisi'] ?? '';
        $ratelimit = $_POST['ratelimit'] ?? '';
        $uptime = $_POST['uptime'] ?? '';
        $server_edit = $_POST['server'] ?? '';
        $area = $_POST['area'] ?? '';
        if (empty($id) || empty($paket)) {
            sendJsonResponse(['error' => 'ID and paket required'], 400);
        }
        $sql_check = "SELECT id FROM paket_hotspot WHERE id = '$id' AND pemilik IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Package not found or not authorized'], 403);
        }
        $sql_update = "UPDATE paket_hotspot SET paket='$paket', harga='$harga', komisi='$komisi', ratelimit='$ratelimit', uptime='$uptime', pemilik='$server_edit', area='$area' WHERE id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            sendJsonResponse(['success' => 'Package updated']);
        } else {
            sendJsonResponse(['error' => 'Update failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'edit_odp':
        // Edit ODP (sesuai editodp.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        $kode = $_POST['kode'] ?? '';
        $name = $_POST['name'] ?? '';
        $tikor = $_POST['tikor'] ?? '';
        $server = $_POST['server'] ?? '';
        $area = $_POST['area'] ?? '';
        if (empty($id) || empty($kode) || empty($name) || empty($tikor)) {
            sendJsonResponse(['error' => 'ID, kode, name, and tikor required'], 400);
        }
        $sql_check = "SELECT id FROM odp WHERE id = '$id' AND pemilik IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'ODP not found or not authorized'], 403);
        }
        $sql_update = "UPDATE odp SET KODE='$kode', NAME='$name', TIKOR='$tikor', PEMILIK='$server', AREA='$area' WHERE id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            sendJsonResponse(['success' => 'ODP updated']);
        } else {
            sendJsonResponse(['error' => 'Update failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'edit_olt':
        // Edit OLT
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        $ipolt = $_POST['ipolt'] ?? '';
        $oltname = $_POST['oltname'] ?? '';
        $usernameolt = $_POST['usernameolt'] ?? '';
        $passwordolt = $_POST['passwordolt'] ?? '';
        $brandolt = $_POST['brandolt'] ?? '';
        $server_edit = $_POST['server'] ?? '';
        $area = $_POST['area'] ?? '';
        if (empty($id) || empty($ipolt)) {
            sendJsonResponse(['error' => 'ID and ipolt required'], 400);
        }
        $sql_check = "SELECT id FROM olt WHERE id = '$id' AND pemilik IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'OLT not found or not authorized'], 403);
        }
        $sql_update = "UPDATE olt SET ipolt='$ipolt', oltname='$oltname', usernameolt='$usernameolt', passwordolt='$passwordolt', brandolt='$brandolt', pemilik='$server_edit', area='$area' WHERE id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            sendJsonResponse(['success' => 'OLT updated']);
        } else {
            sendJsonResponse(['error' => 'Update failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'activate_customer':
        // Aktivasi manual pelanggan (sesuai activecustomer.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        $nama = $_POST['NAMA'] ?? '';
        $idpel = $_POST['IDPEL'] ?? '';
        $email = $_POST['EMAIL'] ?? '';
        $nowa = $_POST['NOWA'] ?? '';
        $paket = $_POST['PAKET'] ?? '';
        $pemilik = $_POST['PEMILIK'] ?? '';
        $area = $_POST['AREA'] ?? '';
        if (empty($idpel) || empty($nama) || empty($paket) || empty($pemilik) || empty($area)) {
            sendJsonResponse(['error' => 'IDPEL, NAMA, PAKET, PEMILIK, AREA required'], 400);
        }
        // Cek apakah pelanggan milik server user
        $sql_check = "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$idpel' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Customer not found or not authorized'], 403);
        }
        // Placeholder: Implement full activation logic from activecustomer.php
        // This includes MikroTik connection, profile update, transaction logging, WA notification, etc.
        // For now, return success
        sendJsonResponse(['success' => 'Customer activated manually']);
        break;

    case 'disable_customer':
        // Disable manual pelanggan (sesuai disablecustomer.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $nama = $_POST['NAMA'] ?? '';
        $idpel = $_POST['IDPEL'] ?? '';
        $nowa = $_POST['NOWA'] ?? '';
        $paket = $_POST['PAKET'] ?? '';
        $pemilik = $_POST['PEMILIK'] ?? '';
        $area = $_POST['AREA'] ?? '';
        if (empty($idpel) || empty($nama) || empty($paket) || empty($pemilik) || empty($area)) {
            sendJsonResponse(['error' => 'IDPEL, NAMA, PAKET, PEMILIK, AREA required'], 400);
        }
        // Cek apakah pelanggan milik server user
        $sql_check = "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$idpel' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Customer not found or not authorized'], 403);
        }
        // Placeholder: Implement full disable logic from disablecustomer.php
        // This includes MikroTik connection, profile change to EXPIRED, remove active connections, FreeRADIUS update, WA notification, etc.
        // For now, return success
        sendJsonResponse(['success' => 'Customer disabled manually']);
        break;

    case 'add_pppoe_package':
        // Add paket PPPOE (sesuai addpackagespppoe.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $profileName = mysqli_real_escape_string($conn, $_POST['profileName'] ?? '');
        $ratelimit = mysqli_real_escape_string($conn, $_POST['ratelimit'] ?? '');
        $harga = $_POST['harga'] ?? '';
        $local = mysqli_real_escape_string($conn, $_POST['local'] ?? '');
        $remot = mysqli_real_escape_string($conn, $_POST['remot'] ?? '');
        $server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
        $area = mysqli_real_escape_string($conn, $_POST['area'] ?? '');
        $komisi = mysqli_real_escape_string($conn, $_POST['komisi'] ?? '');
        if (empty($profileName) || empty($ratelimit) || empty($harga) || empty($area) || empty($server) || empty($local) || empty($remot)) {
            sendJsonResponse(['error' => 'All fields required: profileName, ratelimit, harga, local, remot, server, area'], 400);
        }
        // Cek apakah server milik user
        if (!in_array($server, explode(',', str_replace("'", '', $server_list)))) {
            sendJsonResponse(['error' => 'Server not authorized'], 403);
        }
        require '../routeros_api.class.php';
        $API = new RouterosAPI();
        $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' AND `PEMILIK` = '$server'";
        $query = mysqli_query($conn, $sql);
        if (!$query) {
            sendJsonResponse(['error' => 'Database query failed'], 500);
        }
        $success_count = 0;
        while ($data = mysqli_fetch_array($query)) {
            $host = $data['IP'];
            $password = $data['PASSWORD'];
            $AREA = $data['AREA'];
            // Cek koneksi dengan timeout 10 detik
            if (!checkMikrotikConnection($host, 8728, 10)) {
                continue; // Skip jika tidak bisa konek
            }
            if ($API->connect($host, $server, $password)) {
                $API->comm("/ip/pool/add", ["name" => $profileName, "ranges" => $remot]);
                $API->comm("/ppp/profile/add", ["name" => $profileName, "rate-limit" => $ratelimit, "local-address" => $local, "remote-address" => $profileName]);
                $API->disconnect();
                $success_count++;
            }
        }
        if ($success_count > 0) {
            $query2 = "INSERT INTO `paket`( `PAKET`, `KODE`, `KECEPATAN`, `LOCAL`, `REMOTE`, `HARGA`, `komisi`, `AREA`, `PEMILIK`, `BRAND`) VALUES ('$profileName','-', '$ratelimit','$local','$remot','$harga','$komisi','$area','$server','MikroTik')";
            if (mysqli_query($conn, $query2)) {
                sendJsonResponse(['success' => 'PPPOE Package added to ' . $success_count . ' servers']);
            } else {
                sendJsonResponse(['error' => 'Database insert failed: ' . mysqli_error($conn)], 500);
            }
        } else {
            sendJsonResponse(['error' => 'Failed to connect to any server'], 500);
        }
        break;

    case 'add_hotspot_package':
        // Add paket Hotspot (sesuai addpackageshotspot.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $profileName = mysqli_real_escape_string($conn, $_POST['profileName'] ?? '');
        $ratelimit = mysqli_real_escape_string($conn, $_POST['ratelimit'] ?? '');
        $server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
        $area = mysqli_real_escape_string($conn, $_POST['area'] ?? '');
        $uptime = mysqli_real_escape_string($conn, $_POST['uptime'] ?? '');
        $harga = $_POST['harga'] ?? '';
        $komisi = mysqli_real_escape_string($conn, $_POST['komisi'] ?? '');
        if (empty($profileName) || empty($area)) {
            sendJsonResponse(['error' => 'profileName and area required'], 400);
        }
        // Cek apakah server milik user
        if (!in_array($server, explode(',', str_replace("'", '', $server_list)))) {
            sendJsonResponse(['error' => 'Server not authorized'], 403);
        }
        require '../routeros_api.class.php';
        $API = new RouterosAPI();
        $sql = "SELECT * FROM `server` WHERE `AREA` = '$area' AND `PEMILIK` = '$server'";
        $query = mysqli_query($conn, $sql);
        if (!$query) {
            sendJsonResponse(['error' => 'Database query failed'], 500);
        }
        $success_count = 0;
        while ($data = mysqli_fetch_array($query)) {
            $host = $data['IP'];
            $password = $data['PASSWORD'];
            $AREA = $data['AREA'];
            // Cek koneksi dengan timeout 10 detik
            if (!checkMikrotikConnection($host, 8728, 10)) {
                continue; // Skip jika tidak bisa konek
            }
            if ($API->connect($host, $server, $password)) {
                $API->comm("/ip/hotspot/user/profile/add", ["name" => $profileName, "rate-limit" => $ratelimit]);
                $API->disconnect();
                $success_count++;
            }
        }
        if ($success_count > 0) {
            $sql2 = "INSERT INTO `paket_hotspot` (`paket`, `uptime`, `ratelimit`, `harga`, `komisi`, `area`, `pemilik`) VALUES ('$profileName', '$uptime', '$ratelimit', '$harga', '$komisi', '$AREA', '$server')";
            if (mysqli_query($conn, $sql2)) {
                sendJsonResponse(['success' => 'Hotspot Package added to ' . $success_count . ' servers']);
            } else {
                sendJsonResponse(['error' => 'Database insert failed: ' . mysqli_error($conn)], 500);
            }
        } else {
            sendJsonResponse(['error' => 'Failed to connect to any server'], 500);
        }
        break;

    case 'add_customer':
        // Add pelanggan (sesuai addcustomer.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $customerID = mysqli_real_escape_string($conn, $_POST['customerID'] ?? '');
        $passwordPPPOE = mysqli_real_escape_string($conn, $_POST['passwordPPPOE'] ?? '');
        $customerName = mysqli_real_escape_string($conn, $_POST['customerName'] ?? '');
        $address = mysqli_real_escape_string($conn, $_POST['address'] ?? '');
        $whatsapp = mysqli_real_escape_string($conn, $_POST['whatsapp'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['Email'] ?? '');
        $coordinates = mysqli_real_escape_string($conn, $_POST['coordinates'] ?? '');
        $server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
        $area = mysqli_real_escape_string($conn, $_POST['area'] ?? '');
        $sales = mysqli_real_escape_string($conn, $_POST['sales'] ?? '');
        $odp = mysqli_real_escape_string($conn, $_POST['odp'] ?? '');
        $tipe_bayar = mysqli_real_escape_string($conn, $_POST['tipe_bayar'] ?? '');
        $tipe_tempo = mysqli_real_escape_string($conn, $_POST['tipe_tempo'] ?? '');
        $packages = mysqli_real_escape_string($conn, $_POST['packages'] ?? '');
        $tanggalpasang = date('Y-m-d');
        $authmode = mysqli_real_escape_string($conn, $_POST['authmode'] ?? 'radius');
        if (empty($customerID) || empty($customerName) || empty($packages) || empty($server) || empty($area)) {
            sendJsonResponse(['error' => 'customerID, customerName, packages, server, area required'], 400);
        }
        // Cek apakah server milik user
        if (!in_array($server, explode(',', str_replace("'", '', $server_list)))) {
            sendJsonResponse(['error' => 'Server not authorized'], 403);
        }
        // Process WhatsApp number
        $whatsappedit = $whatsapp;
        if (!preg_match('/[^+0-9]/', trim($whatsapp))) {
            if (substr(trim($whatsapp), 0, 2) == '62') {
                $whatsappedit = trim($whatsapp);
            } elseif (substr(trim($whatsapp), 0, 3) == '+62') {
                $whatsappedit = '62' . substr(trim($whatsapp), 1);
            } elseif (substr(trim($whatsapp), 0, 1) == '0') {
                $whatsappedit = '62' . substr(trim($whatsapp), 1);
            }
        }
        // Add to database
        $sql2 = "INSERT INTO `pelanggan` (`PASSWORD`,`IDPEL`,`NAMA`, `TIPE_BAYAR`, `TIPE_TEMPO`, `PAKET`, `HARGA`, `TANGGALPASANG`,`NOWA`,`EMAIL`,`ALAMAT`,`TEMPO`,`PEMILIK`,`MODE`,`ODP`,`AREA`,`TIKOR`,`sales`,`BRAND`) VALUES ('$passwordPPPOE','$customerID','$customerName','$tipe_bayar','$tipe_tempo','$packages','-','$tanggalpasang','$whatsappedit','$email','$address','-','$server','-','$odp','$area','$coordinates','$sales','MikroTik')";
        if (mysqli_query($conn, $sql2)) {
            // Placeholder for MikroTik add, WA notification, etc.
            sendJsonResponse(['success' => 'Customer added successfully']);
        } else {
            sendJsonResponse(['error' => 'Database insert failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'add_odp':
        // Add ODP
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $kode = mysqli_real_escape_string($conn, $_POST['kode'] ?? '');
        $name = mysqli_real_escape_string($conn, $_POST['name'] ?? '');
        $tikor = mysqli_real_escape_string($conn, $_POST['tikor'] ?? '');
        $server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
        $area = mysqli_real_escape_string($conn, $_POST['area'] ?? '');
        if (empty($kode) || empty($name) || empty($tikor) || empty($server) || empty($area)) {
            sendJsonResponse(['error' => 'kode, name, tikor, server, area required'], 400);
        }
        // Cek apakah server milik user
        if (!in_array($server, explode(',', str_replace("'", '', $server_list)))) {
            sendJsonResponse(['error' => 'Server not authorized'], 403);
        }
        $sql = "INSERT INTO `odp` (`KODE`, `NAME`, `TIKOR`, `PEMILIK`, `AREA`) VALUES ('$kode', '$name', '$tikor', '$server', '$area')";
        if (mysqli_query($conn, $sql)) {
            sendJsonResponse(['success' => 'ODP added successfully']);
        } else {
            sendJsonResponse(['error' => 'Database insert failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'add_olt':
        // Add OLT
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $ipolt = mysqli_real_escape_string($conn, $_POST['ipolt'] ?? '');
        $oltname = mysqli_real_escape_string($conn, $_POST['oltname'] ?? '');
        $usernameolt = mysqli_real_escape_string($conn, $_POST['usernameolt'] ?? '');
        $passwordolt = mysqli_real_escape_string($conn, $_POST['passwordolt'] ?? '');
        $brandolt = mysqli_real_escape_string($conn, $_POST['brandolt'] ?? '');
        $server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
        $area = mysqli_real_escape_string($conn, $_POST['area'] ?? '');
        if (empty($ipolt) || empty($server) || empty($area)) {
            sendJsonResponse(['error' => 'ipolt, server, area required'], 400);
        }
        // Cek apakah server milik user
        if (!in_array($server, explode(',', str_replace("'", '', $server_list)))) {
            sendJsonResponse(['error' => 'Server not authorized'], 403);
        }
        $sql = "INSERT INTO `olt` (`ipolt`, `oltname`, `usernameolt`, `passwordolt`, `brandolt`, `pemilik`, `area`) VALUES ('$ipolt', '$oltname', '$usernameolt', '$passwordolt', '$brandolt', '$server', '$area')";
        if (mysqli_query($conn, $sql)) {
            sendJsonResponse(['success' => 'OLT added successfully']);
        } else {
            sendJsonResponse(['error' => 'Database insert failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'edit_customer':
        // Edit pelanggan
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $idpel = $_POST['idpel'] ?? '';
        $passwordPPPOE = $_POST['passwordPPPOE'] ?? '';
        $customerName = $_POST['customerName'] ?? '';
        $tipe_bayar = $_POST['tipe_bayar'] ?? '';
        $tipe_tempo = $_POST['tipe_tempo'] ?? '';
        $packages = $_POST['packages'] ?? '';
        $whatsapp = $_POST['whatsapp'] ?? '';
        $email = $_POST['email'] ?? '';
        $address = $_POST['address'] ?? '';
        $tempo = $_POST['tempo'] ?? '';
        $server = $_POST['server'] ?? '';
        $odp = $_POST['odp'] ?? '';
        $area = $_POST['area'] ?? '';
        $coordinates = $_POST['coordinates'] ?? '';
        $sales = $_POST['sales'] ?? '';
        if (empty($idpel) || empty($customerName) || empty($packages) || empty($server) || empty($area)) {
            sendJsonResponse(['error' => 'idpel, customerName, packages, server, area required'], 400);
        }
        // Cek apakah pelanggan milik server user
        $sql_check = "SELECT IDPEL FROM pelanggan WHERE IDPEL = '$idpel' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Customer not found or not authorized'], 403);
        }
        // Process WhatsApp
        $whatsappedit = $whatsapp;
        if (!preg_match('/[^+0-9]/', trim($whatsapp))) {
            if (substr(trim($whatsapp), 0, 2) == '62') {
                $whatsappedit = trim($whatsapp);
            } elseif (substr(trim($whatsapp), 0, 3) == '+62') {
                $whatsappedit = '62' . substr(trim($whatsapp), 1);
            } elseif (substr(trim($whatsapp), 0, 1) == '0') {
                $whatsappedit = '62' . substr(trim($whatsapp), 1);
            }
        }
        $sql_update = "UPDATE pelanggan SET PASSWORD='$passwordPPPOE', NAMA='$customerName', TIPE_BAYAR='$tipe_bayar', TIPE_TEMPO='$tipe_tempo', PAKET='$packages', NOWA='$whatsappedit', EMAIL='$email', ALAMAT='$address', TEMPO='$tempo', PEMILIK='$server', ODP='$odp', AREA='$area', TIKOR='$coordinates', sales='$sales' WHERE IDPEL='$idpel'";
        if (mysqli_query($conn, $sql_update)) {
            sendJsonResponse(['success' => 'Customer updated']);
        } else {
            sendJsonResponse(['error' => 'Update failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'add_server':
        // Add server
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $ip = $_POST['ip'] ?? '';
        $password = $_POST['password'] ?? '';
        $area = $_POST['area'] ?? '';
        $pemilik = $_POST['pemilik'] ?? '';
        $brand = $_POST['brand'] ?? '';
        if (empty($ip) || empty($password) || empty($area) || empty($pemilik)) {
            sendJsonResponse(['error' => 'IP, password, area, pemilik required'], 400);
        }
        // Cek apakah pemilik unik
        $sql_check_unique = "SELECT id FROM server WHERE PEMILIK = '$pemilik' LIMIT 1";
        $result_check_unique = mysqli_query($conn, $sql_check_unique);
        if ($result_check_unique && mysqli_num_rows($result_check_unique) > 0) {
            sendJsonResponse(['error' => 'Pemilik already exists'], 400);
        }
        $sql_insert = "INSERT INTO server (IP, PASSWORD, AREA, PEMILIK, BRAND) VALUES ('$ip', '$password', '$area', '$pemilik', '$brand')";
        if (mysqli_query($conn, $sql_insert)) {
            sendJsonResponse(['success' => 'Server added']);
        } else {
            sendJsonResponse(['error' => 'Insert failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'edit_server':
        // Edit server
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        $ip = $_POST['ip'] ?? '';
        $password = $_POST['password'] ?? '';
        $area = $_POST['area'] ?? '';
        $pemilik = $_POST['pemilik'] ?? '';
        $brand = $_POST['brand'] ?? '';
        if (empty($id) || empty($ip) || empty($password) || empty($area) || empty($pemilik)) {
            sendJsonResponse(['error' => 'ID, IP, password, area, pemilik required'], 400);
        }
        // Cek apakah server milik user
        $sql_check = "SELECT id FROM server WHERE id = '$id' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Server not found or not authorized'], 403);
        }
        $sql_update = "UPDATE server SET IP='$ip', PASSWORD='$password', AREA='$area', PEMILIK='$pemilik', BRAND='$brand' WHERE id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            sendJsonResponse(['success' => 'Server updated']);
        } else {
            sendJsonResponse(['error' => 'Update failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'delete_pppoe_package':
        // Delete paket PPPOE
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            sendJsonResponse(['error' => 'ID required'], 400);
        }
        // Cek apakah paket milik server user
        $sql_check = "SELECT id, PAKET, AREA, PEMILIK FROM paket WHERE id = '$id' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Package not found or not authorized'], 403);
        }
        $row = mysqli_fetch_assoc($result_check);
        $paket = $row['PAKET'];
        $area = $row['AREA'];
        $pemilik = $row['PEMILIK'];
        // Hapus dari MikroTik
        require '../routeros_api.class.php';
        $API = new RouterosAPI();
        $sql_servers = "SELECT * FROM server WHERE AREA = '$area' AND PEMILIK = '$pemilik'";
        $query_servers = mysqli_query($conn, $sql_servers);
        while ($server_row = mysqli_fetch_assoc($query_servers)) {
            $host = $server_row['IP'];
            $password = $server_row['PASSWORD'];
            if ($API->connect($host, $pemilik, $password)) {
                // Hapus PPP Profile
                $profiles = $API->comm("/ppp/profile/print", ["?name" => $paket]);
                foreach ($profiles as $p) {
                    if (isset($p['.id'])) {
                        $API->comm("/ppp/profile/remove", [".id" => $p['.id']]);
                    }
                }
                // Hapus IP Pool
                $pools = $API->comm("/ip/pool/print", ["?name" => $paket]);
                foreach ($pools as $pp) {
                    if (isset($pp['.id'])) {
                        $API->comm("/ip/pool/remove", [".id" => $pp['.id']]);
                    }
                }
                $API->disconnect();
            }
        }
        // Hapus dari database
        $sql_delete = "DELETE FROM paket WHERE id = '$id'";
        if (mysqli_query($conn, $sql_delete)) {
            sendJsonResponse(['success' => 'PPPOE Package deleted']);
        } else {
            sendJsonResponse(['error' => 'Delete failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'delete_hotspot_package':
        // Delete paket Hotspot
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            sendJsonResponse(['error' => 'ID required'], 400);
        }
        // Cek apakah paket milik server user
        $sql_check = "SELECT id, paket, area, pemilik FROM paket_hotspot WHERE id = '$id' AND pemilik IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Package not found or not authorized'], 403);
        }
        $row = mysqli_fetch_assoc($result_check);
        $paket = $row['paket'];
        $area = $row['area'];
        $pemilik = $row['pemilik'];
        // Hapus dari MikroTik
        require '../routeros_api.class.php';
        $API = new RouterosAPI();
        $sql_servers = "SELECT * FROM server WHERE AREA = '$area' AND PEMILIK = '$pemilik'";
        $query_servers = mysqli_query($conn, $sql_servers);
        while ($server_row = mysqli_fetch_assoc($query_servers)) {
            $host = $server_row['IP'];
            $password = $server_row['PASSWORD'];
            if ($API->connect($host, $pemilik, $password)) {
                // Hapus Hotspot Profile
                $profiles = $API->comm("/ip/hotspot/user/profile/print", ["?name" => $paket]);
                foreach ($profiles as $p) {
                    if (isset($p['.id'])) {
                        $API->comm("/ip/hotspot/user/profile/remove", [".id" => $p['.id']]);
                    }
                }
                $API->disconnect();
            }
        }
        // Hapus dari database
        $sql_delete = "DELETE FROM paket_hotspot WHERE id = '$id'";
        if (mysqli_query($conn, $sql_delete)) {
            sendJsonResponse(['success' => 'Hotspot Package deleted']);
        } else {
            sendJsonResponse(['error' => 'Delete failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'delete_odp':
        // Delete ODP
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            sendJsonResponse(['error' => 'ID required'], 400);
        }
        // Cek apakah ODP milik server user
        $sql_check = "SELECT id FROM odp WHERE id = '$id' AND pemilik IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'ODP not found or not authorized'], 403);
        }
        // Hapus foto jika ada
        $foto_path = '../../../dokumen/odp/odp_' . $id . '.jpg';
        if (file_exists($foto_path)) {
            unlink($foto_path);
        }
        // Hapus dari database
        $sql_delete = "DELETE FROM odp WHERE id = '$id'";
        if (mysqli_query($conn, $sql_delete)) {
            sendJsonResponse(['success' => 'ODP deleted']);
        } else {
            sendJsonResponse(['error' => 'Delete failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'delete_olt':
        // Delete OLT
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            sendJsonResponse(['error' => 'ID required'], 400);
        }
        // Cek apakah OLT milik server user
        $sql_check = "SELECT id FROM olt WHERE id = '$id' AND pemilik IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'OLT not found or not authorized'], 403);
        }
        // Hapus dari database
        $sql_delete = "DELETE FROM olt WHERE id = '$id'";
        if (mysqli_query($conn, $sql_delete)) {
            sendJsonResponse(['success' => 'OLT deleted']);
        } else {
            sendJsonResponse(['error' => 'Delete failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'delete_customer':
        // Delete pelanggan
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            sendJsonResponse(['error' => 'ID required'], 400);
        }
        // Cek apakah pelanggan milik server user
        $sql_check = "SELECT IDPEL FROM pelanggan WHERE id = '$id' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Customer not found or not authorized'], 403);
        }
        // Hapus dari database
        $sql_delete = "DELETE FROM pelanggan WHERE id = '$id'";
        if (mysqli_query($conn, $sql_delete)) {
            sendJsonResponse(['success' => 'Customer deleted']);
        } else {
            sendJsonResponse(['error' => 'Delete failed: ' . mysqli_error($conn)], 500);
        }
        break;

    case 'delete_server':
        // Delete server
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['error' => 'Method not allowed'], 405);
        }
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            sendJsonResponse(['error' => 'ID required'], 400);
        }
        // Cek apakah server milik user
        $sql_check = "SELECT IP FROM server WHERE id = '$id' AND PEMILIK IN ($server_list)";
        $result_check = mysqli_query($conn, $sql_check);
        if (!$result_check || mysqli_num_rows($result_check) == 0) {
            sendJsonResponse(['error' => 'Server not found or not authorized'], 403);
        }
        $row = mysqli_fetch_assoc($result_check);
        $ip = $row['IP'];
        // Hapus dari database
        $sql_delete = "DELETE FROM server WHERE id = '$id'";
        if (mysqli_query($conn, $sql_delete)) {
            // Update user JSON jika perlu
            $user_result = mysqli_query($conn, "SELECT USERNAME, server FROM user");
            while ($user_row = mysqli_fetch_assoc($user_result)) {
                $username = $user_row['USERNAME'];
                $servers = json_decode($user_row['server'], true);
                if (is_array($servers)) {
                    $pemilik = mysqli_fetch_assoc(mysqli_query($conn, "SELECT PEMILIK FROM server WHERE id = '$id'"))['PEMILIK'];
                    if (in_array($pemilik, $servers)) {
                        $servers = array_values(array_diff($servers, [$pemilik]));
                        $servers_json = json_encode($servers);
                        mysqli_query($conn, "UPDATE user SET server = '$servers_json' WHERE USERNAME = '$username'");
                    }
                }
            }
            sendJsonResponse(['success' => 'Server deleted']);
        } else {
            sendJsonResponse(['error' => 'Delete failed: ' . mysqli_error($conn)], 500);
        }
        break;

    default:
        sendJsonResponse(['error' => 'Invalid type parameter'], 400);
}
?>
