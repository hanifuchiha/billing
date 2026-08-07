<?php
// api/transaksi.php - Transaksi (payment/invoice) API. Full CRUD (GET/POST/PUT/DELETE).
// Data is always scoped to PEMILIK values reachable by the authenticated account (own servers,
// or - for ASSISTANT accounts - the subset of the owner's servers they're assigned to).
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();

$method = $_SERVER['REQUEST_METHOD'];

api_cors();
$input = api_read_input();
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}

$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $pemilik, 'transaksi');

// PEMILIK values this account is allowed to read/write transaksi rows for. Falls back to the
// account's own username when it owns no `server` rows yet (matches the original behaviour).
$pemilikList = api_allowed_pemilik_list($conn, $ctx);
if (empty($pemilikList)) {
    $pemilikList = [$pemilik];
}
$pemilik_in_sql = api_pemilik_in_sql($conn, $pemilikList);

// --- Defensive schema check: several columns were added to `transaksi` via runtime ALTER
// (payment gateway fee tracking, manual-activation audit fields) and may not exist on every
// installation yet. BUKTI/CEK are always present (base schema) but NOT NULL with no default -
// that's the bug this endpoint used to hit on POST; ensure_column() only needs to cover the
// columns that might genuinely be missing.
function tx_ensure_column($conn, $column_name, $definition_sql) {
    $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column_name);
    if ($safe_col === '') {
        return;
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM transaksi LIKE '" . mysqli_real_escape_string($conn, $safe_col) . "'");
    if ($check && mysqli_num_rows($check) > 0) {
        return;
    }
    mysqli_query($conn, "ALTER TABLE transaksi ADD COLUMN " . $definition_sql);
}
tx_ensure_column($conn, 'PENGUNAAN', "PENGUNAAN VARCHAR(255) DEFAULT '-'");
tx_ensure_column($conn, 'METODE_BAYAR', "METODE_BAYAR VARCHAR(50) DEFAULT ''");
tx_ensure_column($conn, 'MANUAL_ACTIVE_BY', "MANUAL_ACTIVE_BY VARCHAR(255) DEFAULT NULL");
tx_ensure_column($conn, 'MANUAL_ACTIVE_SESSION', "MANUAL_ACTIVE_SESSION VARCHAR(255) DEFAULT NULL");
tx_ensure_column($conn, 'payment_method', "payment_method VARCHAR(100) DEFAULT NULL");
tx_ensure_column($conn, 'harga_gross', "harga_gross VARCHAR(50) DEFAULT NULL");
tx_ensure_column($conn, 'fee_merchant', "fee_merchant VARCHAR(50) DEFAULT NULL");
tx_ensure_column($conn, 'fee_customer', "fee_customer VARCHAR(50) DEFAULT NULL");

switch ($method) {
    case 'GET':
        $where = [];
        $where[] = "PEMILIK IN ($pemilik_in_sql)";

        // Filter by periode (kolom PENGUNAAN)
        $periode = $_GET['periode'] ?? null;
        $tanggal = $_GET['tanggal'] ?? null;
        $tanggal_awal = $_GET['tanggal_awal'] ?? null;
        $tanggal_akhir = $_GET['tanggal_akhir'] ?? null;
        // New date range params from Android app
        $start_date = trim($_GET['start_date'] ?? '');
        $end_date   = trim($_GET['end_date']   ?? '');
        // Periode bulan/tahun separate
        $periode_bulan = trim($_GET['periode_bulan'] ?? '');
        $periode_tahun = trim($_GET['periode_tahun'] ?? '');

        $allowed_bulan = ['Januari','Februari','Maret','April','Mei','Juni',
                          'Juli','Agustus','September','Oktober','November','Desember'];

        if ($periode) {
            $where[] = "PENGUNAAN LIKE '%" . mysqli_real_escape_string($conn, $periode) . "%'";
        } elseif ($start_date !== '' && $end_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            // Proper date range — parse TANGGALBAYAR using DATE()
            $tanggal_bayar_expr = "COALESCE(
                DATE(TANGGALBAYAR),
                STR_TO_DATE(TANGGALBAYAR,'%Y-%m-%d'),
                STR_TO_DATE(TRIM(SUBSTRING_INDEX(TANGGALBAYAR,',',-1)),'%d %M %Y'),
                STR_TO_DATE(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                    SUBSTRING_INDEX(TANGGALBAYAR,',',-1),
                    'Januari','01'),'Februari','02'),'Maret','03'),'April','04'),'Mei','05'),'Juni','06'),
                    'Juli','07'),'Agustus','08'),'September','09'),'Oktober','10'),'November','11'),'Desember','12')),'%d %m %Y')
            )";
            $where[] = "($tanggal_bayar_expr BETWEEN '" . mysqli_real_escape_string($conn, $start_date) . "' AND '" . mysqli_real_escape_string($conn, $end_date) . "')";
        } elseif ($tanggal) {
            $where[] = "TANGGALBAYAR = '" . mysqli_real_escape_string($conn, $tanggal) . "'";
        } elseif ($tanggal_awal && $tanggal_akhir) {
            $bulan_tahun_awal = date('F Y', strtotime($tanggal_awal));
            $bulan_tahun_akhir = date('F Y', strtotime($tanggal_akhir));
            if ($bulan_tahun_awal === $bulan_tahun_akhir) {
                $where[] = "TANGGALBAYAR LIKE '%" . mysqli_real_escape_string($conn, $bulan_tahun_awal) . "%'";
            } else {
                $where[] = "(TANGGALBAYAR LIKE '%" . mysqli_real_escape_string($conn, $bulan_tahun_awal) . "%' OR TANGGALBAYAR LIKE '%" . mysqli_real_escape_string($conn, $bulan_tahun_akhir) . "%')";
            }
        }

        // Periode bulan / tahun filter on PENGUNAAN
        if ($periode_bulan !== '' && in_array($periode_bulan, $allowed_bulan, true) && $periode_tahun !== '' && preg_match('/^\d{4}$/', $periode_tahun)) {
            $where[] = "TRIM(COALESCE(PENGUNAAN,'')) LIKE '%" . mysqli_real_escape_string($conn, $periode_bulan . ' ' . $periode_tahun) . "'";
        } elseif ($periode_bulan !== '' && in_array($periode_bulan, $allowed_bulan, true)) {
            $where[] = "TRIM(COALESCE(PENGUNAAN,'')) LIKE '" . mysqli_real_escape_string($conn, $periode_bulan) . " %'";
        } elseif ($periode_tahun !== '' && preg_match('/^\d{4}$/', $periode_tahun)) {
            $where[] = "TRIM(COALESCE(PENGUNAAN,'')) LIKE '% " . mysqli_real_escape_string($conn, $periode_tahun) . "'";
        }

        // Filter by metode bayar
        $metode_bayar = strtolower(trim($_GET['metode_bayar'] ?? ''));
        if ($metode_bayar === 'cash') {
            $where[] = "LOWER(COALESCE(METODE_BAYAR,'')) = 'cash'";
        } elseif ($metode_bayar === 'transfer') {
            $where[] = "LOWER(COALESCE(METODE_BAYAR,'')) = 'transfer'";
        } elseif ($metode_bayar === 'payment_gateway') {
            $where[] = "LOWER(COALESCE(METODE_BAYAR,'')) NOT IN ('cash','transfer','')";
        }

        // Filter by status
        $status = $_GET['status'] ?? null;
        if ($status) {
            $statusSafe = mysqli_real_escape_string($conn, strtoupper(trim($status)));
            $where[] = "(TRIM(UPPER(COALESCE(STATUS,''))) = '$statusSafe' OR TRIM(UPPER(COALESCE(STATUS,''))) LIKE '$statusSafe %')";
        }

        // Filter by idpel
        $idpel = $_GET['idpel'] ?? null;
        if ($idpel) {
            $where[] = "IDPEL = '" . mysqli_real_escape_string($conn, $idpel) . "'";
        }

        // Filter by nama
        $nama = $_GET['nama'] ?? null;
        if ($nama) {
            $where[] = "NAMA LIKE '%" . mysqli_real_escape_string($conn, $nama) . "%'";
        }

        // Filter by paket
        $paket = $_GET['paket'] ?? null;
        if ($paket) {
            $where[] = "PAKET = '" . mysqli_real_escape_string($conn, $paket) . "'";
        }

        // Generic search (Android/web quick search)
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $searchEsc = mysqli_real_escape_string($conn, $search);
            $where[] = "(IDPEL LIKE '%$searchEsc%' OR NAMA LIKE '%$searchEsc%' OR PAKET LIKE '%$searchEsc%' OR STATUS LIKE '%$searchEsc%')";
        }

        $sql = "SELECT * FROM transaksi WHERE " . implode(' AND ', $where) . " ORDER BY TANGGALBAYAR DESC, ID DESC";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $data[] = $row;
        }
        api_json(['success' => true, 'data' => $data]);
        break;

    // POST: create a new transaksi row (manual invoice generate style). BUKTI/CEK are NOT NULL
    // in the base schema with no column default, so they must always be included in the INSERT
    // (defaulting to '' when the caller doesn't send them) - omitting them is the bug that used
    // to make this endpoint fail.
    case 'POST':
        $data = $input;
        $idpel  = trim((string)($data['idpel'] ?? ''));
        $nama   = trim((string)($data['nama'] ?? ''));
        $paket  = trim((string)($data['paket'] ?? ''));
        $harga  = $data['harga'] ?? '';
        $tanggal = $data['tanggal'] ?? date('Y-m-d');
        $status = $data['status'] ?? 'PENAGIHAN';
        $bukti  = (string)($data['bukti'] ?? '');
        $cek    = (string)($data['cek'] ?? '');

        if ($idpel === '' || $nama === '' || $paket === '' || $harga === '') {
            api_json(['success' => false, 'error' => 'Data tidak lengkap']);
        }

        $columns = ['IDPEL', 'NAMA', 'PAKET', 'HARGA', 'TANGGALBAYAR', 'STATUS', 'PEMILIK', 'BUKTI', 'CEK'];
        $types   = 'sssssssss';
        $params  = [$idpel, $nama, $paket, $harga, $tanggal, $status, $pemilik, $bukti, $cek];

        // Optional fields - only included when the caller actually sent them.
        if (array_key_exists('pengunaan', $data)) {
            $columns[] = 'PENGUNAAN';
            $types    .= 's';
            $params[]  = (string)$data['pengunaan'];
        }
        if (array_key_exists('metode_bayar', $data)) {
            $columns[] = 'METODE_BAYAR';
            $types    .= 's';
            $params[]  = (string)$data['metode_bayar'];
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $conn->prepare('INSERT INTO transaksi (' . implode(',', $columns) . ") VALUES ($placeholders)");
        api_bind($stmt, $types, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error]);
        }
        api_json(['success' => true, 'id' => $conn->insert_id]);
        break;

    // PUT: dynamic field update - only touches columns actually present in the request body.
    // Mirrors the "confirm payment" flow in proses/activecustomer.php, which rewrites many
    // columns at once (not just STATUS). Always scoped by ID + PEMILIK IN (owned).
    case 'PUT':
        $data = $input;
        $id = $data['id'] ?? '';
        if (!$id) {
            api_json(['success' => false, 'error' => 'Data tidak lengkap']);
        }

        $existing = mysqli_query($conn, "SELECT id FROM transaksi WHERE id = " . (int)$id . " AND PEMILIK IN ($pemilik_in_sql) LIMIT 1");
        if (!$existing || mysqli_num_rows($existing) === 0) {
            api_json(['success' => false, 'error' => 'Transaksi tidak ditemukan atau tidak diizinkan']);
        }

        // input key (lowercase) => [column name, bind type]
        $field_map = [
            'tanggalbayar'          => ['TANGGALBAYAR', 's'],
            'pengunaan'             => ['PENGUNAAN', 's'],
            'status'                => ['STATUS', 's'],
            'idpel'                 => ['IDPEL', 's'],
            'nama'                  => ['NAMA', 's'],
            'paket'                 => ['PAKET', 's'],
            'harga'                 => ['HARGA', 's'],
            'bukti'                 => ['BUKTI', 's'],
            'cek'                   => ['CEK', 's'],
            'metode_bayar'          => ['METODE_BAYAR', 's'],
            'manual_active_by'      => ['MANUAL_ACTIVE_BY', 's'],
            'manual_active_session' => ['MANUAL_ACTIVE_SESSION', 's'],
            'payment_method'        => ['payment_method', 's'],
            'harga_gross'           => ['harga_gross', 's'],
            'fee_merchant'          => ['fee_merchant', 's'],
            'fee_customer'          => ['fee_customer', 's'],
        ];

        $fields = [];
        $types  = '';
        $params = [];
        foreach ($field_map as $key => $spec) {
            if (array_key_exists($key, $data)) {
                [$column, $type] = $spec;
                $fields[] = "`$column` = ?";
                $types   .= $type;
                $params[] = (string)$data[$key];
            }
        }

        if (empty($fields)) {
            api_json(['success' => false, 'error' => 'Tidak ada data untuk diupdate']);
        }

        $types .= 'i';
        $params[] = (int)$id;
        $sql = "UPDATE transaksi SET " . implode(', ', $fields) . " WHERE id = ? AND PEMILIK IN ($pemilik_in_sql)";
        $stmt = $conn->prepare($sql);
        api_bind($stmt, $types, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            api_json(['success' => false, 'error' => $stmt->error]);
        }
        api_json(['success' => true]);
        break;

    // DELETE: scoped to PEMILIK IN (owned) - stricter than the web page's own delete flow
    // (Transaction.php has no PEMILIK check at all in its SQL), keep it that way.
    case 'DELETE':
        $data = $input;
        $id = $data['id'] ?? ($_GET['id'] ?? '');
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID tidak ditemukan']);
        }
        $stmt = $conn->prepare("DELETE FROM transaksi WHERE id = ? AND PEMILIK IN ($pemilik_in_sql)");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        if ($ok && $stmt->affected_rows === 0) {
            api_json(['success' => false, 'error' => 'Transaksi tidak ditemukan atau tidak diizinkan']);
        }
        api_json(['success' => $ok]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
