<?php
/**
 * Endpoint ringan untuk card "Informasi System" di paling atas dashboard.php:
 * - Dipakai frontend sbg target ping (waktu round-trip fetch = latensi browser<->server).
 * - Sekaligus mengembalikan status layanan Radius (proses AAA berjalan atau tidak).
 * Sengaja tidak melakukan query berat apa pun supaya waktu respons benar-benar
 * merepresentasikan latensi jaringan, bukan waktu proses backend.
 */
include '../cek-sesi.php';

header('Content-Type: application/json; charset=utf-8');

function isRadiusServiceActive(): bool
{
    $pid = trim((string)@shell_exec('pidof radiusd 2>/dev/null'));
    if ($pid !== '') return true;

    $pid = trim((string)@shell_exec('pidof freeradius 2>/dev/null'));
    if ($pid !== '') return true;

    $active = trim((string)@shell_exec('systemctl is-active freeradius 2>/dev/null'));
    if ($active === 'active') return true;

    $pid = trim((string)@shell_exec("pgrep -f 'radiusd|freeradius' 2>/dev/null"));
    return $pid !== '';
}

echo json_encode([
    'success'       => true,
    'server_time'   => date('Y-m-d H:i:s'),
    'radius_active' => isRadiusServiceActive(),
]);
