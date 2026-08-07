<?php
require_once __DIR__ . '/../cek-sesi.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header('Location: ../vlan.php?statusnotif=failed_delete');
    exit;
}

$whereArea = '';
if ($AKSES === 'ASSISTANT') {
    if (!empty($area_list)) {
        $whereArea = " AND s.AREA IN ($area_list)";
    } else {
        header('Location: ../vlan.php?statusnotif=failed_delete');
        exit;
    }
}

$checkSql = "SELECT v.id, v.vlan_id, v.interface_name
             FROM vlan v
             LEFT JOIN server s ON s.id = v.server_id
             WHERE v.id = $id
             AND v.pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . $whereArea . "
             LIMIT 1";
$checkRes = mysqli_query($conn, $checkSql);
if (!$checkRes || mysqli_num_rows($checkRes) === 0) {
    header('Location: ../vlan.php?statusnotif=failed_delete');
    exit;
}

$data = mysqli_fetch_assoc($checkRes);
$deleteSql = "DELETE FROM vlan WHERE id = $id LIMIT 1";
if (mysqli_query($conn, $deleteSql)) {
    $history_file = __DIR__ . '/../notifbot/data/history-' . $ceknama . '.json';
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) {
        $history = [];
    }
    $actor = !empty($asistant_name) ? $asistant_name : $ceknama;
    $history[] = '[ ' . $actor . ' - ' . date('Y-m-d H:i:s') . ' ] Menghapus VLAN ' . $data['vlan_id'] . ' dari interface ' . $data['interface_name'];
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

    header('Location: ../vlan.php?statusnotif=success_delete');
    exit;
}

header('Location: ../vlan.php?statusnotif=failed_delete');
exit;
