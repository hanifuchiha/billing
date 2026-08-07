<?php

if (!function_exists('acs_live_default_config')) {
    function acs_live_default_config()
    {
        return [
            'enabled' => true,
            'cron_enabled' => false,
            'ttl_seconds' => 3600,
            'refresh_seconds' => 30,
            'max_rows' => 5,
            'max_devices_scan_per_server' => 200,
            'track_keep_seconds' => 7200,
        ];
    }
}

if (!function_exists('acs_live_base_dir')) {
    function acs_live_base_dir()
    {
        return dirname(__DIR__) . '/notifdata/acs_cache';
    }
}

if (!function_exists('acs_live_config_path')) {
    function acs_live_config_path()
    {
        return dirname(__DIR__) . '/notifdata/acs_cache_config.json';
    }
}

if (!function_exists('acs_live_track_path')) {
    function acs_live_track_path()
    {
        return acs_live_base_dir() . '/tracked_customers.json';
    }
}

if (!function_exists('acs_live_ensure_dir')) {
    function acs_live_ensure_dir()
    {
        $dir = acs_live_base_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
}

if (!function_exists('acs_live_get_config')) {
    function acs_live_get_config()
    {
        $defaults = acs_live_default_config();
        $path = acs_live_config_path();

        if (!file_exists($path)) {
            acs_live_ensure_dir();
            @file_put_contents($path, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $defaults;
        }

        $raw = @file_get_contents($path);
        $cfg = json_decode((string)$raw, true);
        if (!is_array($cfg)) {
            return $defaults;
        }

        $cfg = array_merge($defaults, $cfg);
        $cfg['ttl_seconds'] = max(60, (int)$cfg['ttl_seconds']);
        $cfg['refresh_seconds'] = max(10, (int)$cfg['refresh_seconds']);
        $cfg['max_rows'] = max(1, min(20, (int)$cfg['max_rows']));
        $cfg['max_devices_scan_per_server'] = max(50, min(1000, (int)$cfg['max_devices_scan_per_server']));
        $cfg['track_keep_seconds'] = max(1800, (int)$cfg['track_keep_seconds']);
        $cfg['enabled'] = !empty($cfg['enabled']);
        $cfg['cron_enabled'] = !empty($cfg['cron_enabled']);

        return $cfg;
    }
}

if (!function_exists('acs_live_save_config')) {
    function acs_live_save_config($newValues)
    {
        $current = acs_live_get_config();
        $merged = array_merge($current, is_array($newValues) ? $newValues : []);

        $merged['enabled'] = !empty($merged['enabled']);
        $merged['cron_enabled'] = !empty($merged['cron_enabled']);
        $merged['ttl_seconds'] = max(60, (int)$merged['ttl_seconds']);
        $merged['refresh_seconds'] = max(10, (int)$merged['refresh_seconds']);
        $merged['max_rows'] = max(1, min(20, (int)$merged['max_rows']));
        $merged['max_devices_scan_per_server'] = max(50, min(1000, (int)$merged['max_devices_scan_per_server']));
        $merged['track_keep_seconds'] = max(1800, (int)$merged['track_keep_seconds']);

        acs_live_ensure_dir();
        @file_put_contents(acs_live_config_path(), json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $merged;
    }
}

if (!function_exists('acs_live_cache_file')) {
    function acs_live_cache_file($idpel)
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$idpel);
        if ($safe === '') {
            $safe = md5((string)$idpel);
        }
        return acs_live_base_dir() . '/idpel-' . $safe . '.json';
    }
}

if (!function_exists('acs_live_read_cache')) {
    function acs_live_read_cache($idpel)
    {
        $path = acs_live_cache_file($idpel);
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('acs_live_write_cache')) {
    function acs_live_write_cache($idpel, $pemilik, $rows, $lastLiveFetch, $cfg)
    {
        $now = time();
        $payload = [
            'idpel' => (string)$idpel,
            'pemilik' => (string)$pemilik,
            'rows' => is_array($rows) ? $rows : [],
            'rows_count' => is_array($rows) ? count($rows) : 0,
            'last_live_fetch' => (int)$lastLiveFetch,
            'fetched_at' => $now,
            'expires_at' => $now + (int)$cfg['ttl_seconds'],
        ];

        acs_live_ensure_dir();
        @file_put_contents(acs_live_cache_file($idpel), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $payload;
    }
}

if (!function_exists('acs_live_track_customer')) {
    function acs_live_track_customer($idpel, $pemilik, $cfg)
    {
        $idpel = trim((string)$idpel);
        if ($idpel === '') {
            return;
        }

        acs_live_ensure_dir();
        $trackPath = acs_live_track_path();
        $track = [];

        if (file_exists($trackPath)) {
            $trackRaw = @file_get_contents($trackPath);
            $track = json_decode((string)$trackRaw, true);
            if (!is_array($track)) {
                $track = [];
            }
        }

        $now = time();
        $keepSeconds = (int)$cfg['track_keep_seconds'];
        foreach ($track as $k => $v) {
            $seen = isset($v['last_seen']) ? (int)$v['last_seen'] : 0;
            if ($seen <= 0 || ($now - $seen) > $keepSeconds) {
                unset($track[$k]);
            }
        }

        $track[$idpel] = [
            'idpel' => $idpel,
            'pemilik' => (string)$pemilik,
            'last_seen' => $now,
        ];

        @file_put_contents($trackPath, json_encode($track, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

if (!function_exists('acs_live_http_get')) {
    function acs_live_http_get($url, $username, $password)
    {
        if (!function_exists('curl_init')) {
            return [false, 'cURL not available'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [false, $err !== '' ? $err : 'request failed'];
        }

        if ($http < 200 || $http >= 300) {
            return [false, 'HTTP ' . $http];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [false, 'invalid JSON'];
        }

        return [true, $json];
    }
}

if (!function_exists('acs_live_get_servers')) {
    function acs_live_get_servers($conn, $pemilik)
    {
        $servers = [];
        $pemilikEsc = mysqli_real_escape_string($conn, (string)$pemilik);

        $sql = "SELECT s.id, s.domain, s.port, s.username_acs, s.password_acs, s.nama_server, u.USERNAME AS owner_username
                FROM acs_servers s
                LEFT JOIN user u ON s.owner_id = u.id
                WHERE COALESCE(s.domain, '') <> ''
                  AND COALESCE(s.username_acs, '') <> ''
                  AND COALESCE(s.password_acs, '') <> ''";

        if ($pemilikEsc !== '') {
            $sql .= " AND (u.USERNAME = '$pemilikEsc' OR s.nama_server = '$pemilikEsc')";
        }

        $sql .= " ORDER BY s.id DESC";

        $res = mysqli_query($conn, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $servers[] = $row;
            }
        }

        if ($pemilikEsc !== '' || count($servers) > 0) {
            return $servers;
        }

        // Final fallback for systems without owner mapping.
        $res2 = mysqli_query($conn, "SELECT id, domain, port, username_acs, password_acs, nama_server FROM acs_servers ORDER BY id DESC");
        if ($res2) {
            while ($row = mysqli_fetch_assoc($res2)) {
                if (trim((string)($row['domain'] ?? '')) === '') {
                    continue;
                }
                if (trim((string)($row['username_acs'] ?? '')) === '') {
                    continue;
                }
                if (trim((string)($row['password_acs'] ?? '')) === '') {
                    continue;
                }
                $servers[] = $row;
            }
        }

        return $servers;
    }
}

if (!function_exists('acs_live_flatten')) {
    function acs_live_flatten($data, $prefix, &$out, &$count, $max)
    {
        if (!is_array($data) || $count >= $max) {
            return;
        }

        foreach ($data as $k => $v) {
            if ($count >= $max) {
                break;
            }

            $k = (string)$k;
            if ($k === '_timestamp' || $k === '_writable' || $k === '_object' || $k === '_type') {
                continue;
            }

            $full = $prefix === '' ? $k : $prefix . '.' . $k;
            if (is_array($v)) {
                if (array_key_exists('_value', $v) && !is_array($v['_value'])) {
                    $out[$full] = trim((string)$v['_value']);
                    $count++;
                } else {
                    acs_live_flatten($v, $full, $out, $count, $max);
                }
            } elseif (!is_object($v)) {
                $out[$full] = trim((string)$v);
                $count++;
            }
        }
    }
}

if (!function_exists('acs_live_pick')) {
    function acs_live_pick($flat, $keys)
    {
        if (!is_array($flat)) {
            return '';
        }

        foreach ($keys as $k) {
            if (isset($flat[$k]) && trim((string)$flat[$k]) !== '') {
                return trim((string)$flat[$k]);
            }
        }

        foreach ($flat as $k => $v) {
            $v = trim((string)$v);
            if ($v === '') {
                continue;
            }
            foreach ($keys as $candidate) {
                if (substr((string)$k, -strlen($candidate)) === $candidate) {
                    return $v;
                }
            }
        }

        return '';
    }
}

if (!function_exists('acs_live_extract_ssid')) {
    function acs_live_extract_ssid($flat, $idx)
    {
        $suffix = 'WLANConfiguration.' . (string)$idx . '.SSID';
        return acs_live_pick($flat, [
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.' . (string)$idx . '.SSID',
            'Device.LANDevice.1.WLANConfiguration.' . (string)$idx . '.SSID',
            $suffix,
        ]);
    }
}

if (!function_exists('acs_live_is_match')) {
    function acs_live_is_match($idpel, $serial, $flat)
    {
        $idpel = strtolower(trim((string)$idpel));
        if ($idpel === '') {
            return false;
        }

        $serial = strtolower(trim((string)$serial));
        if ($serial !== '' && (strpos($serial, $idpel) !== false || strpos($idpel, $serial) !== false)) {
            return true;
        }

        $username = strtolower(acs_live_pick($flat, [
            'VirtualParameters.pppoeUsername2',
            'VirtualParameters.pppoeUsername',
            'pppoeUsername2',
            'pppoeUsername',
        ]));

        if ($username !== '' && (strpos($username, $idpel) !== false || strpos($idpel, $username) !== false)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('acs_live_parse_devices')) {
    function acs_live_parse_devices($devices, $idpel, $maxRows, $maxFlatten)
    {
        $rows = [];
        if (!is_array($devices)) {
            return $rows;
        }

        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }

            $serial = isset($device['_id']) ? trim((string)$device['_id']) : '';
            $flat = [];
            $count = 0;
            acs_live_flatten($device, '', $flat, $count, $maxFlatten);

            if (!acs_live_is_match($idpel, $serial, $flat)) {
                continue;
            }

            $rx = acs_live_pick($flat, ['VirtualParameters.RXPower', 'RXPower', 'X_CT-COM_EponInterfaceConfig.RXPower']);
            $tx = acs_live_pick($flat, ['VirtualParameters.TXPower', 'TXPower', 'X_CT-COM_EponInterfaceConfig.TXPower']);
            $pppoeIp = acs_live_pick($flat, ['VirtualParameters.pppoeIP2', 'VirtualParameters.pppoeIP', 'pppoeIP', 'ExternalIPAddress']);
            $username = acs_live_pick($flat, ['VirtualParameters.pppoeUsername2', 'VirtualParameters.pppoeUsername', 'pppoeUsername2', 'pppoeUsername']);
            $lastInform = isset($device['_lastInform']) ? (string)$device['_lastInform'] : '';
            $status = 'OFFLINE';
            if ($lastInform !== '') {
                $lastTs = is_numeric($lastInform) ? (int)$lastInform : strtotime($lastInform);
                if ($lastTs > 10000000000) {
                    $lastTs = (int)($lastTs / 1000);
                }
                if ($lastTs > 0 && (time() - $lastTs) <= 600) {
                    $status = 'ONLINE';
                }
            }

            $rows[] = [
                'serial' => $serial !== '' ? $serial : '-',
                'username' => $username !== '' ? $username : '-',
                'pppoe_ip' => $pppoeIp !== '' ? $pppoeIp : '-',
                'rx' => $rx !== '' ? $rx : '-',
                'tx' => $tx !== '' ? $tx : '-',
                'status' => $status,
                'last_inform' => $lastInform !== '' ? $lastInform : '-',
                'ssid_1' => ($v1 = acs_live_extract_ssid($flat, 1)) !== '' ? $v1 : '-',
                'ssid_2' => ($v2 = acs_live_extract_ssid($flat, 2)) !== '' ? $v2 : '-',
                'ssid_3' => ($v3 = acs_live_extract_ssid($flat, 3)) !== '' ? $v3 : '-',
                'all_params' => $flat,
            ];

            if (count($rows) >= $maxRows) {
                break;
            }
        }

        return $rows;
    }
}

if (!function_exists('acs_live_fetch_from_servers')) {
    function acs_live_fetch_from_servers($conn, $idpel, $pemilik, $cfg)
    {
        $servers = acs_live_get_servers($conn, $pemilik);
        if (count($servers) === 0) {
            return [false, [], 'Server ACS tidak ditemukan'];
        }

        $allRows = [];
        $errors = [];

        foreach ($servers as $server) {
            $domain = trim((string)($server['domain'] ?? ''));
            $portBase = (int)($server['port'] ?? 0);
            $nbiPort = $portBase > 0 ? ($portBase + 2) : 7557;
            $user = (string)($server['username_acs'] ?? '');
            $pass = (string)($server['password_acs'] ?? '');
            if ($domain === '' || $user === '' || $pass === '') {
                continue;
            }

            $baseUrl = 'http://' . $domain . ':' . $nbiPort . '/devices';

            $queryOr = [
                '$or' => [
                    ['_id' => $idpel],
                    ['_id' => ['$regex' => $idpel]],
                    ['VirtualParameters.pppoeUsername._value' => $idpel],
                    ['VirtualParameters.pppoeUsername2._value' => $idpel],
                ]
            ];

            $queryUrl = $baseUrl
                . '?query=' . rawurlencode(json_encode($queryOr))
                . '&limit=' . (int)$cfg['max_devices_scan_per_server'];

            list($ok, $dataOrError) = acs_live_http_get($queryUrl, $user, $pass);
            if (!$ok) {
                $fallbackUrl = $baseUrl . '?limit=' . (int)$cfg['max_devices_scan_per_server'];
                list($ok2, $data2) = acs_live_http_get($fallbackUrl, $user, $pass);
                if (!$ok2) {
                    $errors[] = (string)($server['nama_server'] ?? $domain) . ': ' . (string)$data2;
                    continue;
                }
                $dataOrError = $data2;
            }

            $rows = acs_live_parse_devices($dataOrError, $idpel, (int)$cfg['max_rows'], 2000);
            if (count($rows) > 0) {
                $allRows = array_merge($allRows, $rows);
                if (count($allRows) >= (int)$cfg['max_rows']) {
                    $allRows = array_slice($allRows, 0, (int)$cfg['max_rows']);
                    break;
                }
            }
        }

        if (count($allRows) > 0) {
            return [true, $allRows, 'OK'];
        }

        return [false, [], count($errors) > 0 ? implode(' | ', $errors) : 'Tidak ada data match'];
    }
}

if (!function_exists('acs_live_get_data')) {
    function acs_live_get_data($conn, $idpel, $pemilik, $forceRefresh)
    {
        $cfg = acs_live_get_config();
        $idpel = trim((string)$idpel);
        $pemilik = trim((string)$pemilik);

        if ($idpel === '') {
            return [
                'success' => false,
                'message' => 'IDPEL wajib diisi',
                'rows' => [],
                'meta' => ['source' => 'none'],
            ];
        }

        if (!$cfg['enabled']) {
            return [
                'success' => true,
                'message' => 'Fitur cache ACS nonaktif',
                'rows' => [],
                'meta' => ['source' => 'disabled', 'config' => $cfg],
            ];
        }

        acs_live_track_customer($idpel, $pemilik, $cfg);

        $now = time();
        $cache = acs_live_read_cache($idpel);
        $hasCache = is_array($cache);
        $cacheExpired = true;
        $needBackgroundRefresh = true;

        if ($hasCache) {
            $cacheExpired = $now >= (int)($cache['expires_at'] ?? 0);
            $lastLiveFetch = (int)($cache['last_live_fetch'] ?? 0);
            $needBackgroundRefresh = ($now - $lastLiveFetch) >= (int)$cfg['refresh_seconds'];
        }

        $mustRefresh = !$hasCache || $cacheExpired || ($forceRefresh && $needBackgroundRefresh);

        if ($mustRefresh) {
            list($ok, $rows, $msg) = acs_live_fetch_from_servers($conn, $idpel, $pemilik, $cfg);
            if ($ok) {
                $newCache = acs_live_write_cache($idpel, $pemilik, $rows, $now, $cfg);
                return [
                    'success' => true,
                    'message' => 'Data ACS live berhasil diupdate',
                    'rows' => $newCache['rows'],
                    'meta' => [
                        'source' => 'live',
                        'rows_count' => (int)$newCache['rows_count'],
                        'fetched_at' => (int)$newCache['fetched_at'],
                        'expires_at' => (int)$newCache['expires_at'],
                        'config' => $cfg,
                    ],
                ];
            }

            if ($hasCache) {
                return [
                    'success' => true,
                    'message' => 'Live gagal, menampilkan cache terakhir',
                    'rows' => is_array($cache['rows'] ?? null) ? $cache['rows'] : [],
                    'meta' => [
                        'source' => 'cache-stale',
                        'rows_count' => (int)($cache['rows_count'] ?? 0),
                        'fetched_at' => (int)($cache['fetched_at'] ?? 0),
                        'expires_at' => (int)($cache['expires_at'] ?? 0),
                        'error' => $msg,
                        'config' => $cfg,
                    ],
                ];
            }

            return [
                'success' => true,
                'message' => 'Data ACS belum tersedia',
                'rows' => [],
                'meta' => [
                    'source' => 'empty',
                    'error' => $msg,
                    'config' => $cfg,
                ],
            ];
        }

        return [
            'success' => true,
            'message' => 'Data ACS dari cache',
            'rows' => is_array($cache['rows'] ?? null) ? $cache['rows'] : [],
            'meta' => [
                'source' => 'cache',
                'rows_count' => (int)($cache['rows_count'] ?? 0),
                'fetched_at' => (int)($cache['fetched_at'] ?? 0),
                'expires_at' => (int)($cache['expires_at'] ?? 0),
                'config' => $cfg,
            ],
        ];
    }
}

if (!function_exists('acs_live_refresh_tracked_customers')) {
    function acs_live_refresh_tracked_customers($conn)
    {
        $cfg = acs_live_get_config();
        if (!$cfg['enabled']) {
            return ['success' => true, 'processed' => 0, 'message' => 'Fitur cache ACS nonaktif'];
        }

        $path = acs_live_track_path();
        if (!file_exists($path)) {
            return ['success' => true, 'processed' => 0, 'message' => 'Belum ada customer terlacak'];
        }

        $raw = @file_get_contents($path);
        $track = json_decode((string)$raw, true);
        if (!is_array($track) || count($track) === 0) {
            return ['success' => true, 'processed' => 0, 'message' => 'Data tracking kosong'];
        }

        $now = time();
        $processed = 0;
        $keepSeconds = (int)$cfg['track_keep_seconds'];

        foreach ($track as $idpel => $row) {
            $lastSeen = isset($row['last_seen']) ? (int)$row['last_seen'] : 0;
            if ($lastSeen <= 0 || ($now - $lastSeen) > $keepSeconds) {
                unset($track[$idpel]);
                continue;
            }

            $pemilik = isset($row['pemilik']) ? (string)$row['pemilik'] : '';
            $cache = acs_live_read_cache($idpel);
            $lastLive = is_array($cache) ? (int)($cache['last_live_fetch'] ?? 0) : 0;
            if ($lastLive > 0 && ($now - $lastLive) < (int)$cfg['refresh_seconds']) {
                continue;
            }

            acs_live_get_data($conn, $idpel, $pemilik, true);
            $processed++;
        }

        @file_put_contents($path, json_encode($track, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return ['success' => true, 'processed' => $processed, 'message' => 'Refresh cache ACS selesai'];
    }
}
