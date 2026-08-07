<?php
/**
 * getdata/get_isolir_access_hits.php
 *
 * Ambil total "Hits" (counter di MikroTik) dari semua rule /ip proxy access yang dibuat
 * untuk isolir-forwarding server tertentu ("Allow domain (isolir): ..." dan
 * "Redirect expired to landing page [...]"). Dipanggil per-baris secara async supaya
 * tabel "Server yang Sudah Dipasang Isolir Forwarding" tidak perlu connect ke semua
 * MikroTik saat halaman pertama kali dimuat (bisa lambat/nge-hang kalau ada server yang
 * tidak bisa dijangkau).
 *
 * Request: POST { server_id }
 * Response: JSON { success, total_hits, rules: [{comment, hits}] }
 */

ob_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function send_json_and_exit($data) {
    if (ob_get_length() !== false) {
        ob_clean();
    }
    echo json_encode($data);
    exit;
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Server error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')',
        ]);
    }
});

try {
    require_once __DIR__ . '/../cek-sesi.php';
} catch (\Throwable $e) {
    send_json_and_exit(['success' => false, 'error' => 'Gagal load cek-sesi: ' . $e->getMessage()]);
}

if (!isset($conn) || !$conn) {
    send_json_and_exit(['success' => false, 'error' => 'Koneksi database tidak tersedia']);
}
if (empty($_SESSION['id'])) {
    send_json_and_exit(['success' => false, 'error' => 'Unauthorized']);
}
if ($AKSES !== 'ADMIN') {
    send_json_and_exit(['success' => false, 'error' => 'Hanya ADMIN yang bisa mengakses fitur ini']);
}

if (!class_exists('RouterosAPI')) {
    require_once __DIR__ . '/../routeros_api.class.php';
}

$serverId = isset($_POST['server_id']) ? (int) $_POST['server_id'] : 0;
if ($serverId <= 0) {
    send_json_and_exit(['success' => false, 'error' => 'Server tidak valid']);
}

$currentUserId = (int) $_SESSION['id'];
$srvRes = mysqli_query($conn, "SELECT * FROM server WHERE id = $serverId AND user_id = $currentUserId LIMIT 1");
if (!$srvRes || mysqli_num_rows($srvRes) === 0) {
    send_json_and_exit(['success' => false, 'error' => 'Server tidak ditemukan atau bukan milik akun ini']);
}
$server = mysqli_fetch_assoc($srvRes);

$API = new RouterosAPI();
$API->timeout = 6; // pendek -- ini panggilan latar belakang per-baris, jangan sampai bikin banyak tab nunggu lama

if (!$API->connect($server['IP'], $server['PEMILIK'], $server['PASSWORD'])) {
    send_json_and_exit(['success' => false, 'error' => 'Gagal terhubung ke MikroTik ' . $server['IP']]);
}

try {
    $allRows = $API->comm('/ip/proxy/access/print');
    $API->disconnect();
} catch (\Throwable $e) {
    $API->disconnect();
    send_json_and_exit(['success' => false, 'error' => 'Error RouterOS API: ' . $e->getMessage()]);
}

$prefixes = ['Allow domain (isolir): ', 'Redirect expired to landing page [', 'Allow billing domain (isolir)', 'Redirect expired to landing page'];
$rules = [];
$totalHits = 0;

if (is_array($allRows)) {
    foreach ($allRows as $row) {
        $comment = $row['comment'] ?? '';
        $isIsolirRule = false;
        foreach ($prefixes as $prefix) {
            if (strpos($comment, $prefix) === 0) {
                $isIsolirRule = true;
                break;
            }
        }
        if ($isIsolirRule) {
            $hits = isset($row['hits']) ? (int) $row['hits'] : 0;
            $rules[] = ['comment' => $comment, 'hits' => $hits];
            $totalHits += $hits;
        }
    }
}

send_json_and_exit([
    'success'    => true,
    'server_id'  => $serverId,
    'total_hits' => $totalHits,
    'rules'      => $rules,
]);
