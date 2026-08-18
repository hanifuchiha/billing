<?php
// api/reseller_paket_price.php - Filter Harga Paket per reseller/mitra ISP (tabel
// reseller_paket_price), API version dari modal "Atur Filter Harga Paket" di user.php +
// proses/save_reseller_price_filter.php. Sama seperti web: hanya OWNER (bukan ASSISTANT) yang
// boleh kelola daftar harga custom sub-akun reseller-nya sendiri.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
require_once '../reseller_helper.php';
session_start();
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
api_require_module_enabled($conn, $pemilik, 'user_assistant');
if ($ctx['is_assistant']) {
    api_json(['success' => false, 'error' => 'Akun ASSISTANT tidak diizinkan mengelola filter harga sub-akun reseller lain'], 403);
}

reseller_bootstrap_schema($conn);
$owner_id = (int)$ctx['owner_user_id'];
$method = $_SERVER['REQUEST_METHOD'];

/** Pastikan reseller_user_id benar milik owner yang sedang login, kembalikan baris `user`-nya. */
function rpp_load_owned_reseller($conn, $reseller_user_id, $owner_id) {
    $stmt = $conn->prepare("SELECT * FROM user WHERE id = ? AND STATUS = 'ASSISTANT' AND grup = ? LIMIT 1");
    api_bind($stmt, 'ii', [$reseller_user_id, $owner_id]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        api_json(['success' => false, 'error' => 'Reseller tidak ditemukan atau bukan milik akun ini'], 404);
    }
    return $row;
}

switch ($method) {

    // GET ?reseller_user_id=123 -> daftar paket broadband+hotspot di area reseller ini, dengan
    // status enabled/custom_harga yang sudah tersimpan (sama seperti isi modal "Atur Filter Harga
    // Paket" di user.php).
    case 'GET':
        $reseller_user_id = (int)($_GET['reseller_user_id'] ?? 0);
        if (!$reseller_user_id) {
            api_json(['success' => false, 'error' => 'reseller_user_id diperlukan'], 400);
        }
        $resellerRow = rpp_load_owned_reseller($conn, $reseller_user_id, $owner_id);
        $areaNames = reseller_resolve_area_names($conn, $resellerRow['server'] ?? '');

        api_json([
            'success' => true,
            'reseller_user_id' => $reseller_user_id,
            'area' => $areaNames,
            'broadband' => reseller_get_paket_rows($conn, $areaNames, $reseller_user_id, 'broadband'),
            'hotspot' => reseller_get_paket_rows($conn, $areaNames, $reseller_user_id, 'hotspot'),
        ]);
        break;

    // POST/PUT: simpan filter harga. Body: { reseller_user_id, items: [{paket_type, paket_id,
    // paket_nama, enabled, custom_harga}, ...] } -- satu request bisa kirim campuran
    // broadband+hotspot sekaligus (bedanya cuma field paket_type per item).
    case 'POST':
    case 'PUT':
        $reseller_user_id = (int)($input['reseller_user_id'] ?? 0);
        if (!$reseller_user_id) {
            api_json(['success' => false, 'error' => 'reseller_user_id diperlukan'], 400);
        }
        rpp_load_owned_reseller($conn, $reseller_user_id, $owner_id);

        $items = $input['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            api_json(['success' => false, 'error' => 'items (array paket yang mau diatur) diperlukan'], 400);
        }

        $saved = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $paket_type = ($item['paket_type'] ?? 'broadband') === 'hotspot' ? 'hotspot' : 'broadband';
            $paket_id = (int)($item['paket_id'] ?? 0);
            if ($paket_id <= 0) {
                continue;
            }
            $paket_nama = (string)($item['paket_nama'] ?? '');
            $enabled = !empty($item['enabled']) ? 1 : 0;
            $custom_harga = isset($item['custom_harga']) && $item['custom_harga'] !== '' ? (float)$item['custom_harga'] : null;

            $stmt = $conn->prepare("INSERT INTO reseller_paket_price (reseller_user_id, paket_type, paket_id, paket_nama, enabled, custom_harga)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE paket_nama = VALUES(paket_nama), enabled = VALUES(enabled), custom_harga = VALUES(custom_harga)");
            api_bind($stmt, 'isisid', [$reseller_user_id, $paket_type, $paket_id, $paket_nama, $enabled, $custom_harga]);
            if ($stmt->execute()) {
                $saved++;
            }
        }

        api_json(['success' => true, 'saved' => $saved]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
