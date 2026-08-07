<?php

if (!function_exists('qts_sync_wwwdata_crontab')) {
    /**
     * Sync auto cron entries for www-data.
     *
     * @return array{success:bool,message:string,applied_lines?:array<int,string>,output?:array<int,string>}
     */
    function qts_sync_wwwdata_crontab(array $maintenanceOwners, array $dismantleOwners): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'success' => false,
                'message' => 'Auto sync crontab hanya berjalan di server Linux.',
            ];
        }

        if (!function_exists('exec')) {
            return [
                'success' => false,
                'message' => 'Fungsi exec() nonaktif di PHP. Tidak bisa sync crontab otomatis.',
            ];
        }

        $billingDir = dirname(__DIR__, 2);
        $curlBin = 'curl';
        $markerPrefix = '# QTS-AUTO-CRON:';

        $maintenanceOwners = array_values(array_unique(array_filter(array_map('trim', $maintenanceOwners), static function ($v) {
            return $v !== '';
        })));
        $dismantleOwners = array_values(array_unique(array_filter(array_map('trim', $dismantleOwners), static function ($v) {
            return $v !== '';
        })));

        $desiredLines = [];
        $generatedFiles = [];

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

        foreach ($maintenanceOwners as $pemilik) {
            $slug = $slugify($pemilik);
            $url = $cronBaseUrl . '/cron_maintenance_ticket.php?pemilik=' . rawurlencode($pemilik);
            $generatedFiles[] = $url;
            $desiredLines[] = '*/5 * * * * ' . $curlBin . ' -fsS --max-time 180 ' . escapeshellarg($url) . ' >/dev/null 2>&1 ' . $markerPrefix . 'maintenance:' . $slug;
        }

        foreach ($dismantleOwners as $pemilik) {
            $slug = $slugify($pemilik);
            $url = $cronBaseUrl . '/cron_dismantle_ticket.php?pemilik=' . rawurlencode($pemilik);
            $generatedFiles[] = $url;
            $desiredLines[] = '*/5 * * * * ' . $curlBin . ' -fsS --max-time 180 ' . escapeshellarg($url) . ' >/dev/null 2>&1 ' . $markerPrefix . 'dismantle:' . $slug;
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
        } else {
            $current = [];
        }

        $kept = [];
        foreach ($current as $line) {
            $trim = trim((string)$line);
            if ($trim === '') {
                continue;
            }
            if (strpos($line, $markerPrefix . 'maintenance:') !== false || strpos($line, $markerPrefix . 'dismantle:') !== false) {
                continue;
            }
            $kept[] = $line;
        }

        $finalLines = array_merge($kept, $desiredLines);
        $payload = implode(PHP_EOL, $finalLines) . PHP_EOL;

        $tmpFile = rtrim(sys_get_temp_dir(), '/\\') . '/qts_crontab_' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmpFile, $payload) === false) {
            return [
                'success' => false,
                'message' => 'Gagal membuat file temporary untuk sync crontab.',
            ];
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
            return [
                'success' => false,
                'message' => 'Gagal apply crontab otomatis. Pastikan user web punya izin mengubah crontab www-data.',
                'output' => $applyOut,
            ];
        }

        return [
            'success' => true,
            'message' => 'Crontab www-data berhasil disinkronkan otomatis.',
            'applied_lines' => $desiredLines,
            'generated_files' => $generatedFiles,
        ];
    }
}
