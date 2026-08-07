<?php
if (!function_exists('getWebProxyStatus')) {
    /**
     * Status Web Proxy saat ini (/ip/proxy adalah config tunggal, bukan list).
     */
    function getWebProxyStatus($API): array
    {
        $rows = $API->comm('/ip/proxy/print');
        $row = (is_array($rows) && isset($rows[0])) ? $rows[0] : [];
        $enabledRaw = $row['enabled'] ?? 'false';
        $enabled = ($enabledRaw === 'true' || $enabledRaw === true || $enabledRaw === 'yes');
        $port = isset($row['port']) ? (string)$row['port'] : '';

        return ['enabled' => $enabled, 'port' => $port];
    }
}

if (!function_exists('ensureWebProxyEnabledDefault')) {
    /**
     * Kalau Web Proxy belum aktif, aktifkan dengan port default. Kalau sudah aktif,
     * biarkan port yang sedang berjalan (jangan timpa pengaturan admin yang sudah ada).
     * Return port efektif yang dipakai (string).
     */
    function ensureWebProxyEnabledDefault($API, string $defaultPort = '8080'): string
    {
        $status = getWebProxyStatus($API);
        if ($status['enabled'] && $status['port'] !== '') {
            return $status['port'];
        }

        $port = $status['port'] !== '' ? $status['port'] : $defaultPort;
        $API->comm('/ip/proxy/set', [
            'enabled' => 'yes',
            'port' => $port,
        ]);

        return $port;
    }
}

if (!function_exists('resolveIsolirPoolRange')) {
    /**
     * Sama seperti resolve_remote_address di getdata/get_mikrotik_hotspot_profiles.php:
     * remote-address di PPP Profile MikroTik biasanya berisi NAMA pool, bukan range IP
     * langsung. Fungsi ini mengembalikan range IP asli-nya.
     */
    function resolveIsolirPoolRange($API, string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-' || strtolower($raw) === 'none') {
            return '';
        }
        // Sudah berbentuk range IP langsung, mis. "10.10.10.2-10.10.10.254"
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3}$/', $raw)) {
            return $raw;
        }
        // Coba resolve sebagai nama pool
        $poolRows = $API->comm('/ip/pool/print', ['?name' => $raw]);
        if (!empty($poolRows) && isset($poolRows[0]['ranges'])) {
            $firstRange = trim(explode(',', $poolRows[0]['ranges'])[0]);
            return $firstRange;
        }
        return '';
    }
}

if (!function_exists('findExpiredProfileAndPoolRange')) {
    /**
     * Cari PPP Profile bernama EXPIRED di router. Kalau ada, resolve pool range-nya
     * (remote-address). Return:
     *   ['exists' => bool, 'local_address' => string, 'pool_range' => string]
     */
    function findExpiredProfileAndPoolRange($API): array
    {
        $rows = $API->comm('/ppp/profile/print', ['?name' => 'EXPIRED']);
        if (empty($rows) || !isset($rows[0])) {
            return ['exists' => false, 'local_address' => '', 'pool_range' => ''];
        }

        $profile = $rows[0];
        $localAddress = $profile['local-address'] ?? '';
        $remoteRaw = $profile['remote-address'] ?? '';
        $poolRange = resolveIsolirPoolRange($API, $remoteRaw);

        return [
            'exists' => true,
            'local_address' => $localAddress,
            'pool_range' => $poolRange,
        ];
    }
}

if (!function_exists('createExpiredProfileOnRouter')) {
    /**
     * Buat IP Pool "EXPIRED" (kalau belum ada) + PPP Profile "EXPIRED" yang memakai
     * pool tersebut sebagai remote-address. Mirror pola yang sama dengan "Tambah Paket
     * PPPoE" (proses/addpackagespppoe.php): pool diberi nama sama dengan nama profile.
     */
    function createExpiredProfileOnRouter($API, string $localIp, string $remoteRange): array
    {
        $existingPool = $API->comm('/ip/pool/print', ['?name' => 'EXPIRED']);
        if (empty($existingPool)) {
            $API->comm('/ip/pool/add', [
                'name' => 'EXPIRED',
                'ranges' => $remoteRange,
            ]);
        } else {
            $API->comm('/ip/pool/set', [
                '.id' => $existingPool[0]['.id'],
                'ranges' => $remoteRange,
            ]);
        }

        $existingProfile = $API->comm('/ppp/profile/print', ['?name' => 'EXPIRED']);
        if (empty($existingProfile)) {
            $API->comm('/ppp/profile/add', [
                'name' => 'EXPIRED',
                'local-address' => $localIp,
                'remote-address' => 'EXPIRED',
            ]);
        } else {
            $API->comm('/ppp/profile/set', [
                '.id' => $existingProfile[0]['.id'],
                'local-address' => $localIp,
                'remote-address' => 'EXPIRED',
            ]);
        }

        return ['exists' => true, 'local_address' => $localIp, 'pool_range' => $remoteRange];
    }
}

if (!function_exists('removeIsolirForwardingRulesFromRouter')) {
    /**
     * "Hapus" isolir-forwarding mencabut mekanisme "landing page experience" (yang butuh CDN
     * & Web Proxy), TAPI SENGAJA MENYISAKAN blokir inti supaya pelanggan EXPIRED tetap
     * terblokir dari internet:
     *   DIHAPUS:
     *     - Semua rule /ip proxy access (Allow domain + Redirect ke landing page)
     *     - Address list ALLOW-CDN (semua entry-nya)
     *     - Firewall filter "Allow billing server" (accept ke IP billing)
     *     - Firewall filter "Allow CDN and payment gateway" (accept ke ALLOW-CDN)
     *   TETAP DIBIARKAN (tidak disentuh):
     *     - Firewall filter "Block expired internet" (drop) -- blokir inti
     *     - NAT "Bypass billing server (isolir)" & "Redirect expired user"
     *     - Address list EXPIRED & PPP Profile/Pool EXPIRED (masih dipakai untuk downgrade
     *       profile pelanggan menunggak)
     */
    function removeIsolirForwardingRulesFromRouter($API): array
    {
        $log = [];

        // --- 1. Semua rule /ip proxy access ---
        $legacyComments = ['Allow billing domain (isolir)', 'Redirect expired to landing page'];
        foreach ($legacyComments as $comment) {
            $rows = $API->comm('/ip/proxy/access/print', ['?comment' => $comment]);
            if (!empty($rows) && isset($rows[0]['.id'])) {
                $API->comm('/ip/proxy/access/remove', ['.id' => $rows[0]['.id']]);
                $log[] = "Web Proxy access '$comment' dihapus";
            }
        }

        // Rule "Allow domain (isolir): <domain> [<range>]" dan "Redirect expired to landing
        // page [<range>]" jumlahnya dinamis (per domain x per range), jadi dicari lewat
        // prefix comment, bukan daftar nama tetap.
        $prefixes = ['Allow domain (isolir): ', 'Redirect expired to landing page ['];
        $allProxyAccessRows = $API->comm('/ip/proxy/access/print');
        if (is_array($allProxyAccessRows)) {
            foreach ($allProxyAccessRows as $row) {
                $comment = $row['comment'] ?? '';
                foreach ($prefixes as $prefix) {
                    if (strpos($comment, $prefix) === 0 && isset($row['.id'])) {
                        $API->comm('/ip/proxy/access/remove', ['.id' => $row['.id']]);
                        $log[] = "Web Proxy access '$comment' dihapus";
                        break;
                    }
                }
            }
        }

        // --- 2. Address list ALLOW-CDN (semua entry) ---
        $allowCdnRows = $API->comm('/ip/firewall/address-list/print', ['?list' => 'ALLOW-CDN']);
        if (is_array($allowCdnRows)) {
            foreach ($allowCdnRows as $row) {
                if (isset($row['.id'])) {
                    $API->comm('/ip/firewall/address-list/remove', ['.id' => $row['.id']]);
                    $log[] = 'Address List ALLOW-CDN ' . ($row['address'] ?? '') . ' dihapus';
                }
            }
        }

        // --- 3. Firewall filter "Allow billing server" & "Allow CDN and payment gateway"
        //     (TIDAK termasuk "Block expired internet" -- itu sengaja dibiarkan) ---
        $filterCommentsToRemove = ['Allow billing server', 'Allow CDN and payment gateway'];
        foreach ($filterCommentsToRemove as $comment) {
            $rows = $API->comm('/ip/firewall/filter/print', ['?comment' => $comment]);
            if (!empty($rows) && isset($rows[0]['.id'])) {
                $API->comm('/ip/firewall/filter/remove', ['.id' => $rows[0]['.id']]);
                $log[] = "Filter '$comment' dihapus";
            }
        }

        if (empty($log)) {
            $log[] = 'Tidak ada rule isolir-forwarding yang ditemukan di router (mungkin sudah terhapus sebelumnya)';
        }

        return $log;
    }
}
