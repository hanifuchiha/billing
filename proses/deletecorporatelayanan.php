<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
require_once __DIR__ . '/../radius_sync_lib.php';
require_once __DIR__ . '/../corporate_helper.php';
corporateEnsureSchema($conn);

function redirectDeleteLayanan($corporateId, $status, $text = '') {
    $url = "../corporate_layanan.php?corporate_id=" . (int) $corporateId . "&deleted=" . urlencode($status);
    if ($text !== '') {
        $url .= "&text=" . urlencode($text);
    }
    header("Location: " . $url);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    redirectDeleteLayanan(0, '0', 'ID tidak valid');
}

$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('c.AREA', $AKSES, $area_list ?? '');
$row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT cl.*, s.IP AS server_ip, s.PASSWORD AS server_password, s.PEMILIK AS server_pemilik
    FROM corporate_layanan cl
    LEFT JOIN server s ON s.id = cl.server_id
    JOIN corporate c ON c.id = cl.corporate_id
    WHERE cl.id = $id AND c.PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$row) {
    redirectDeleteLayanan(0, '0', 'Layanan tidak ditemukan');
}
$corporateId = (int) $row['corporate_id'];

if ((int) $row['provisioning_aktif'] === 1 && !empty($row['server_ip'])) {
    $serverForDeprovision = [
        'IP' => $row['server_ip'],
        'PASSWORD' => $row['server_password'],
        'PEMILIK' => $row['server_pemilik'],
    ];
    corporateLayananDeprovision($serverForDeprovision, (string) $row['auth_mode'], (string) $row['pppoe_username']);
}

$del = mysqli_query($conn, "DELETE FROM corporate_layanan WHERE id = $id");

$history_file = "../notifbot/data/history-$ceknama.json";
$history = [];
if (file_exists($history_file)) {
    $history = json_decode(file_get_contents($history_file), true);
}
if (!is_array($history)) { $history = []; }

if ($del && mysqli_affected_rows($conn) > 0) {
    $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus Layanan id=$id corporate_id=$corporateId";
    @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    redirectDeleteLayanan($corporateId, '1');
} else {
    redirectDeleteLayanan($corporateId, '0', 'Gagal menghapus dari database');
}
