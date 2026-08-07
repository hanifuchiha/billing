<?php
// API Proxy untuk proses/*
// Contoh: /api/proses.php?file=addserver.php&params=...

$file = $_GET['file'] ?? '';
$allowed = [
    'addserver.php', 'editserver.php', 'deleteserver.php', 'addcustomer.php', 'editcustomer.php', 'deletecustomer.php',
    'addodp.php', 'editodp.php', 'deleteodp.php', 'addpackagespppoe.php', 'editpackagespppoe.php', 'deletepackages.php',
    'addpackageshotspot.php', 'editpackageshotspot.php', 'deletepackageshotspot.php', 'addpppoeserver.php',
    'addhotspotserver.php', 'aktifkan_server.php', 'apply_pool.php', 'delete_pool.php', 'save_biaya_tambahan_pelanggan.php',
    'delete_biaya_tambahan_pelanggan.php', 'save_diskon_pelanggan.php', 'delete_diskon_pelanggan.php',
    'save_diskon_menunggak_massal.php', 'broadcast_berhenti.php', 'broadcast_berhenti_background.php',
    'buat_tiket_menunggak_massal.php', 'notif_gangguan.php', 'notif_manual.php', 'notif_menunggak_manual.php',
    'sendinvoice.php', 'simpannotif.php', 'update_timer.php', 'verify_password.php', 'hapusvpn.php', 'hapus_logo.php',
    'hapus_pelanggan_berhenti.php', 'hapus_profile.php', 'import_server.php', 'import_paket.php', 'import_hotspot.php',
    'import_odp_excel.php', 'import_odp_kmz.php', 'export_server.php', 'export_packages.php', 'export_hotspot.php',
    'export_odp_excel.php', 'export_odp_kml.php', 'manual_generate_invoice.php', 'save_to_db.php', 'konfirmasi.php',
    'belivpn.php', 'ontremot.php', 'radius.php', 'routeros_api.class.php', 'check_server_dependency.php', 'clear_broadcast_status.php',
    'get_broadcast_logs.php', 'nul'
];
if (!in_array($file, $allowed)) {
    http_response_code(403);
    echo json_encode(['error' => 'File not allowed']);
    exit();
}

parse_str($_SERVER['QUERY_STRING'], $params);
unset($params['file']);

ob_start();
include __DIR__ . '/../proses/' . $file;
$output = ob_get_clean();

if (json_decode($output) !== null) {
    header('Content-Type: application/json');
    echo $output;
    exit();
}
header('Content-Type: application/json');
echo json_encode(['result' => $output]);
