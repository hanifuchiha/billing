<?php
/**
 * notif_cron_files_helper.php
 *
 * Generate salinan file cron per-akun (mis. cek_tagihan_harian_FIBERQ.php) dari
 * template generik di notifbot/notifphp/*.php -- supaya tiap akun/tenant punya
 * file cron sendiri yang bisa dijadwalkan di crontab server (lihat
 * system_setting.php, yang menunjuk langsung ke file hasil-copy ini).
 *
 * Logika ini SEBELUMNYA cuma inline di notification.php (jalan tiap kali
 * halaman Notifikasi dibuka) -- diekstrak ke sini supaya bisa dipanggil juga
 * saat login billing (index.php), tidak lagi bergantung admin pernah buka
 * menu Notifikasi dulu sebelum cron di system_setting.php bisa jalan benar.
 */

if (!function_exists('notifCronFilesGenerate')) {
    /**
     * $username = USERNAME akun (owner) yang jadi suffix nama file hasil copy.
     * $actorName = nama yang dicatat sbg pelaku di history log (assistant kalau
     * yang login assistant, selain itu sama dgn $username).
     *
     * Idempotent: cuma copy kalau file tujuan belum ada, atau template sumber
     * lebih baru dari file tujuan (mtime) -- aman dipanggil berkali-kali/tiap
     * login, tidak menimpa ulang tiap request.
     */
    function notifCronFilesGenerate(string $username, string $actorName = ''): void
    {
        $username = trim($username);
        if ($username === '') {
            return;
        }
        if ($actorName === '') {
            $actorName = $username;
        }

        $folder = dirname(__DIR__) . '/notifbot/notifphp/';

        $allowFiles = [
            'hapus_kode_permintaan_bayar.php',
            'matikan_client_baru.php',
            'non_aktif_tempo.php',
            'non_aktif_by_tanggal.php',
            'cek_tagihan_harian.php',
            'notif_cek_servernotif.php',
            'notif_remainder_pembayaran.php',
            'notif_odp_semua_los.php',
            'invoice_generator_penagihan.php',
            'invoice_generator_rolling_monthversary.php',
            'notif_server_tidak_konek.php',
            'update_grafik.php',
        ];

        $historyFile = dirname(__DIR__) . "/notifbot/data/history-$username.json";
        $history = null; // lazy-load, cuma dibaca kalau memang ada file yang disalin

        foreach ($allowFiles as $filename) {
            $file = $folder . $filename;
            if (!file_exists($file)) {
                continue;
            }

            $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);

            // Kalau nama file sudah mengandung username (mis. dipanggil ulang dgn
            // nama yang sudah di-suffix), lewati -- mencegah double-suffix.
            if (preg_match('/_' . preg_quote($username, '/') . '$/', $nameOnly)) {
                continue;
            }

            $baru = $folder . $nameOnly . '_' . $username . '.' . $ext;

            $perluSalin = false;
            if (!file_exists($baru)) {
                $perluSalin = true;
            } elseif (filemtime($file) > filemtime($baru)) {
                $perluSalin = true;
            }

            if (!$perluSalin) {
                continue;
            }

            if (!copy($file, $baru)) {
                continue;
            }
            @chmod($baru, 0777);
            @chown($baru, 'qts');
            @chgrp($baru, 'www-data');
            @chgrp($baru, 'qts');

            if ($history === null) {
                $history = [];
                if (file_exists($historyFile)) {
                    $decoded = json_decode((string)file_get_contents($historyFile), true);
                    if (is_array($decoded)) {
                        $history = $decoded;
                    }
                }
            }
            $history[] = "[ $actorName - " . date('Y-m-d H:i:s') . " ] File notifikasi otomatis ($filename) berhasil disalin/diperbarui untuk akun $username";
        }

        if ($history !== null) {
            file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
        }
    }
}
