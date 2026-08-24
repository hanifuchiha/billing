<?php
/**
 * Endpoint ringan untuk card "Informasi System" di paling atas dashboard.php:
 * - Dipakai frontend sbg target ping (waktu round-trip fetch = latensi browser<->server).
 * - Sekaligus mengembalikan status layanan Radius (proses AAA berjalan atau tidak).
 * Sengaja tidak melakukan query berat apa pun supaya waktu respons benar-benar
 * merepresentasikan latensi jaringan, bukan waktu proses backend.
 *
 * Deteksi PID FreeRADIUS SEKARANG pakai helper bersama radius_status_helper.php
 * -- SEBELUMNYA file ini punya isRadiusServiceActive() sendiri (beda urutan
 * cek pidof/systemctl/pgrep dari radius.php), jadi status yang tampil di
 * dashboard bisa TIDAK SINKRON dgn yang ditampilkan radius.php. Sekarang
 * satu sumber kebenaran (PID) utk keduanya, dan status "error" (deteksi
 * gagal total, mis. shell_exec diblokir) dibedakan dari "inactive" (service
 * benar-benar mati) -- sebelumnya keduanya sama-sama jatuh ke "Tidak Aktif".
 */
include '../cek-sesi.php';
require_once __DIR__ . '/../radius_status_helper.php';

header('Content-Type: application/json; charset=utf-8');

$radius = radiusGetServiceStatus();

echo json_encode([
    'success'       => true,
    'server_time'   => date('Y-m-d H:i:s'),
    'radius_status' => $radius['status'], // 'active' | 'inactive' | 'error'
    'radius_pid'    => $radius['pid'],
    // Dipertahankan utk kompatibilitas kalau ada pemanggil lain yang masih
    // baca field lama ini.
    'radius_active' => $radius['status'] === 'active',
]);
