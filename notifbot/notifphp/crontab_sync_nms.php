<?php

if (!function_exists('qts_sync_nms_poll_crontab')) {
    /**
     * Sync crontab utk network_devices_poll_cron.php -- FUNGSI TERPISAH dari
     * qts_sync_wwwdata_crontab() (dismantle/maintenance) supaya tidak
     * menyentuh/berisiko merusak baris crontab yang sudah berjalan utk fitur
     * lain. Marker prefix sendiri (# QTS-AUTO-CRON-NMS:) supaya sync ini cuma
     * mengelola baris miliknya sendiri, baris lain (termasuk marker dismantle/
     * maintenance) TIDAK disentuh sama sekali.
     *
     * @return array{success:bool,message:string,applied_lines?:array<int,string>}
     */
    function qts_sync_nms_poll_crontab(array $enabledOwners, int $intervalMinutes): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return ['success' => false, 'message' => 'Auto sync crontab hanya berjalan di server Linux.'];
        }
        if (!function_exists('exec')) {
            return ['success' => false, 'message' => 'Fungsi exec() nonaktif di PHP.'];
        }

        $curlBin = 'curl';
        $markerPrefix = '# QTS-AUTO-CRON-NMS:';
        $intervalMinutes = max(1, min(60, $intervalMinutes));

        $enabledOwners = array_values(array_unique(array_filter(array_map('trim', $enabledOwners), static function ($v) {
            return $v !== '';
        })));

        $scheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '127.0.0.1';
        $scriptDir = isset($_SERVER['SCRIPT_NAME'])
            ? str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME']))
            : '/crm/billing';
        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '\\') {
            $scriptDir = '/crm/billing';
        }
        $scriptDir = rtrim($scriptDir, '/');
        $cronBaseUrl = $scheme . '://' . $host . $scriptDir . '/notifbot/notifphp';

        $slugify = static function (string $pemilik): string {
            $slug = strtoupper($pemilik);
            $slug = preg_replace('/[^A-Z0-9]+/', '_', $slug);
            $slug = trim((string)$slug, '_');
            return $slug !== '' ? $slug : 'UNKNOWN';
        };

        $desiredLines = [];
        foreach ($enabledOwners as $pemilik) {
            $slug = $slugify($pemilik);
            $url = $cronBaseUrl . '/network_devices_poll_cron.php?pemilik=' . rawurlencode($pemilik);
            $desiredLines[] = "*/{$intervalMinutes} * * * * " . $curlBin . ' -fsS --max-time 120 ' . escapeshellarg($url) . ' >/dev/null 2>&1 ' . $markerPrefix . $slug;
        }

        $current = [];
        $out = [];
        $code = 0;
        @exec('crontab -u www-data -l 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $out = [];
            $code = 0;
            @exec('crontab -l 2>/dev/null', $out, $code);
        }
        if ($code === 0 && !empty($out)) {
            $current = $out;
        }

        $kept = [];
        foreach ($current as $line) {
            $trim = trim((string)$line);
            if ($trim === '') continue;
            if (strpos($line, $markerPrefix) !== false) continue; // hapus baris NMS lama, akan digantikan yang baru
            $kept[] = $line;
        }

        $finalLines = array_merge($kept, $desiredLines);
        $payload = implode(PHP_EOL, $finalLines) . PHP_EOL;

        $tmpFile = rtrim(sys_get_temp_dir(), '/\\') . '/qts_crontab_nms_' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmpFile, $payload) === false) {
            return ['success' => false, 'message' => 'Gagal membuat file temporary utk sync crontab.'];
        }

        $applyOut = [];
        $applyCode = 0;
        @exec('cat ' . escapeshellarg($tmpFile) . ' | crontab -u www-data - 2>&1', $applyOut, $applyCode);
        if ($applyCode !== 0) {
            $applyOut = [];
            $applyCode = 0;
            @exec('cat ' . escapeshellarg($tmpFile) . ' | crontab - 2>&1', $applyOut, $applyCode);
        }
        @unlink($tmpFile);

        if ($applyCode !== 0) {
            return ['success' => false, 'message' => 'Gagal apply crontab NMS. Pastikan user web punya izin mengubah crontab www-data.', 'output' => $applyOut];
        }

        return ['success' => true, 'message' => 'Crontab NMS berhasil disinkronkan.', 'applied_lines' => $desiredLines];
    }
}
