<?php

if (!function_exists('acsNormalizeValue')) {
    function acsNormalizeValue($value)
    {
        if (is_array($value)) {
            if (array_key_exists('_value', $value) && !is_array($value['_value']) && !is_object($value['_value'])) {
                return trim((string)$value['_value']);
            }
            return '';
        }

        if (is_object($value)) {
            return '';
        }

        return trim((string)$value);
    }
}

if (!function_exists('acsFlattenParams')) {
    function acsFlattenParams($data, $prefix = '', $maxDepth = 12, $depth = 0)
    {
        $result = [];
        if (!is_array($data) || $depth > $maxDepth) {
            return $result;
        }

        foreach ($data as $key => $value) {
            $fullKey = ($prefix === '') ? (string)$key : $prefix . '.' . (string)$key;

            if (is_array($value)) {
                if (array_key_exists('_value', $value) && !is_array($value['_value']) && !is_object($value['_value'])) {
                    $result[$fullKey] = (string)$value['_value'];
                } else {
                    $result = $result + acsFlattenParams($value, $fullKey, $maxDepth, $depth + 1);
                }
                continue;
            }

            if (!is_object($value)) {
                $result[$fullKey] = (string)$value;
            }
        }

        return $result;
    }
}

if (!function_exists('acsFindByLeafKey')) {
    function acsFindByLeafKey($data, array $leafKeys)
    {
        if (!is_array($data)) {
            return '';
        }

        foreach ($data as $k => $v) {
            if (in_array((string)$k, $leafKeys, true)) {
                $val = acsNormalizeValue($v);
                if ($val !== '') {
                    return $val;
                }
            }

            if (is_array($v)) {
                $nested = acsFindByLeafKey($v, $leafKeys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }
}

if (!function_exists('acsPickValue')) {
    function acsPickValue(array $source, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source)) {
                $val = acsNormalizeValue($source[$key]);
                if ($val !== '') {
                    return $val;
                }
            }
        }

        foreach ($source as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            foreach ($keys as $key) {
                $len = strlen($key);
                if ($len > 0 && strlen($k) >= $len && substr($k, -$len) === $key) {
                    $val = acsNormalizeValue($v);
                    if ($val !== '') {
                        return $val;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('acsExtractWlanSsid')) {
    function acsExtractWlanSsid(array $flattenedParams, $index)
    {
        $idx = (string)$index;
        $candidates = [
            "InternetGatewayDevice.LANDevice.1.WLANConfiguration.$idx.SSID",
            "Device.LANDevice.1.WLANConfiguration.$idx.SSID",
            "WLANConfiguration.$idx.SSID",
        ];

        foreach ($candidates as $key) {
            if (isset($flattenedParams[$key]) && trim((string)$flattenedParams[$key]) !== '') {
                return trim((string)$flattenedParams[$key]);
            }
        }

        $suffix = "WLANConfiguration.$idx.SSID";
        foreach ($flattenedParams as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            if (strlen($k) >= strlen($suffix) && substr($k, -strlen($suffix)) === $suffix && trim((string)$v) !== '') {
                return trim((string)$v);
            }
        }

        return '-';
    }
}

if (!function_exists('acsGetAccessibleServers')) {
    function acsGetAccessibleServers($conn, $userId, $akses)
    {
        $servers = [];

        if ($akses === 'ADMIN') {
            $sql = "SELECT id, nama_server, domain, port, username_acs, password_acs FROM acs_servers ORDER BY id DESC";
        } else {
            $uid = (int)$userId;
            $sql = "SELECT s.id, s.nama_server, s.domain, s.port, s.username_acs, s.password_acs
                    FROM acs_servers s
                    INNER JOIN acs_user_server_assignment a ON s.id = a.server_id
                    WHERE a.user_id = $uid
                    ORDER BY s.id DESC";
        }

        $res = mysqli_query($conn, $sql);
        if (!$res) {
            return $servers;
        }

        while ($row = mysqli_fetch_assoc($res)) {
            $servers[] = $row;
        }

        return $servers;
    }
}

if (!function_exists('acsCallDevicesApi')) {
    function acsCallDevicesApi(array $server)
    {
        $domain = trim((string)($server['domain'] ?? ''));
        $basePort = (int)($server['port'] ?? 0);
        if ($domain === '' || $basePort <= 0) {
            return [];
        }

        $nbiPort = $basePort + 2;
        $url = "http://{$domain}:{$nbiPort}/devices";

        $ch = curl_init($url);
        if (!$ch) {
            return [];
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, (string)($server['username_acs'] ?? '') . ':' . (string)($server['password_acs'] ?? ''));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 18);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return [];
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('acsBuildRowsFromDevices')) {
    function acsBuildRowsFromDevices(array $devices, $idpelMatch, $limit = 5)
    {
        $rows = [];
        $idpelNorm = strtolower(trim((string)$idpelMatch));

        foreach ($devices as $device) {
            if (!is_array($device)) {
                continue;
            }

            $allParams = acsFlattenParams($device);

            $username = acsPickValue($allParams, [
                'VirtualParameters.pppoeUsername2',
                'VirtualParameters.pppoeUsername',
                'pppoeUsername2',
                'pppoeUsername'
            ]);
            if ($username === '') {
                $username = acsFindByLeafKey($device, ['pppoeUsername2', 'pppoeUsername']);
            }

            $serial = trim((string)($device['_id'] ?? ''));
            if ($serial === '') {
                $serial = trim((string)acsPickValue($allParams, ['DeviceID.SerialNumber', 'SerialNumber']));
            }

            $userNorm = strtolower(trim($username));
            $serialNorm = strtolower($serial);

            $isMatch = false;
            if ($idpelNorm !== '') {
                $isExact = ($userNorm === $idpelNorm || $serialNorm === $idpelNorm);
                $isContains = (
                    ($userNorm !== '' && strpos($userNorm, $idpelNorm) !== false) ||
                    ($serialNorm !== '' && strpos($serialNorm, $idpelNorm) !== false)
                );
                $isMatch = ($isExact || $isContains);
            }

            if (!$isMatch) {
                continue;
            }

            $rows[] = [
                'serial' => $serial !== '' ? $serial : '-',
                'username' => $username !== '' ? $username : '-',
                'rx' => acsPickValue($allParams, ['VirtualParameters.RXPower', 'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower', 'RXPower']),
                'tx' => acsPickValue($allParams, ['VirtualParameters.TXPower', 'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TXPower', 'TXPower']),
                'status' => (string)($device['_lastInform'] ?? '') !== '' ? 'ONLINE' : 'UNKNOWN',
                'last_inform' => (string)($device['_lastInform'] ?? '-'),
                'pppoe_ip' => acsPickValue($allParams, ['VirtualParameters.pppoeIP', 'VirtualParameters.pppoeIP2', 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.ExternalIPAddress', 'pppoeIP']),
                'ssid_1' => acsExtractWlanSsid($allParams, 1),
                'ssid_2' => acsExtractWlanSsid($allParams, 2),
                'ssid_3' => acsExtractWlanSsid($allParams, 3),
                'all_params' => $allParams
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }
}

if (!function_exists('acsFetchLiveRowsByIdpel')) {
    function acsFetchLiveRowsByIdpel($conn, $userId, $akses, $idpelMatch, $limit = 5)
    {
        $rows = [];
        $servers = acsGetAccessibleServers($conn, $userId, $akses);

        foreach ($servers as $server) {
            $devices = acsCallDevicesApi($server);
            if (empty($devices)) {
                continue;
            }

            $serverRows = acsBuildRowsFromDevices($devices, $idpelMatch, $limit);
            foreach ($serverRows as $row) {
                $rows[] = $row;
                if (count($rows) >= $limit) {
                    break 2;
                }
            }
        }

        return $rows;
    }
}

if (!function_exists('acsEnsureCacheDir')) {
    function acsEnsureCacheDir($dir)
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }
}

if (!function_exists('acsSafeIdpelFileKey')) {
    function acsSafeIdpelFileKey($idpel)
    {
        $key = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$idpel);
        return $key !== '' ? $key : 'unknown';
    }
}

if (!function_exists('acsReadCacheFile')) {
    function acsReadCacheFile($file)
    {
        if (!file_exists($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('acsWriteCacheFile')) {
    function acsWriteCacheFile($file, array $payload)
    {
        @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}

?>