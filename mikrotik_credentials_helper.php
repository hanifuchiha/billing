<?php
// Helper bersama untuk generate & provisioning kredensial admin MikroTik.
// Dipakai oleh proses/addserver.php (saat tambah server baru) dan
// proses/editserver.php (saat admin minta rotasi kredensial di server yang
// sudah ada). Dibungkus function_exists supaya aman kalau ter-include ganda.

if (!function_exists('generateMikrotikCredentials')) {
    function generateMikrotikCredentials($base_username = '') {
        if (empty($base_username)) {
            $base_username = 'user_' . date('YmdHis') . '_' . rand(100, 999);
        }
        // Ganti spasi dengan underscore di seluruh base_username
        $base_username = str_replace(' ', '_', $base_username);
        // Generate username unik
        $username = $base_username . '_' . uniqid();
        // Generate password acak yang kuat
        $password = generateRandomPassword(12);
        return [
            'username' => $username,
            'password' => $password
        ];
    }
}

if (!function_exists('generateRandomPassword')) {
    function generateRandomPassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
}

if (!function_exists('validateUniqueOwner')) {
    function validateUniqueOwner($conn, $owner) {
        $sqlCheck = "SELECT id FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $owner) . "' LIMIT 1";
        $res = mysqli_query($conn, $sqlCheck);

        if (!$res) {
            throw new Exception("Query error: " . mysqli_error($conn));
        }

        return mysqli_num_rows($res) == 0;
    }
}

if (!function_exists('createMikrotikSystemUser')) {
    function createMikrotikSystemUser($API, $username, $password) {
        try {
            // Cek apakah user sudah ada
            $existing_user = $API->comm("/user/print", ["?name" => $username]);

            if (!empty($existing_user)) {
                throw new Exception("User sudah ada di MikroTik");
            }

            // Buat system user baru di MikroTik
            $result = $API->comm("/user/add", [
                "name" => $username,
                "password" => $password,
                "group" => "full", // atau sesuai kebutuhan
                "disabled" => "no",
                "comment" => "Auto-generated system user - " . date('Y-m-d H:i:s')
            ]);

            if (isset($result["!trap"])) {
                throw new Exception("Gagal membuat user: " . print_r($result, true));
            }

            return true;
        } catch (Exception $e) {
            throw new Exception("Error creating MikroTik user: " . $e->getMessage());
        }
    }
}

if (!function_exists('checkMikrotikApiService')) {
    function checkMikrotikApiService($host, $port) {
        // Test common MikroTik ports
        $common_ports = [8728, 8729]; // 8728 = API, 8729 = API-SSL
        $available_ports = [];

        foreach ($common_ports as $test_port) {
            $socket = @fsockopen($host, $test_port, $errno, $errstr, 3);
            if ($socket) {
                $available_ports[] = $test_port;
                fclose($socket);
            }
        }

        return $available_ports;
    }
}

if (!function_exists('getMikrotikTroubleshootingInfo')) {
    function getMikrotikTroubleshootingInfo($host, $port, $username) {
        $info = [];

        // Check if host is reachable
        $ping_result = exec("ping -c 1 -W 1 $host 2>/dev/null || ping -n 1 -w 1000 $host 2>nul", $output, $return_code);
        $info['ping'] = ($return_code === 0) ? 'SUCCESS' : 'FAILED';

        // Check available ports
        $info['available_ports'] = checkMikrotikApiService($host, $port);

        // Common troubleshooting tips
        $info['tips'] = [
            "1. Pastikan API service enabled di MikroTik: /ip service enable api",
            "2. Cek firewall rules tidak block port $port",
            "3. Username '$username' harus ada dan punya group dengan API access",
            "4. Jika menggunakan domain, pastikan DNS resolution benar",
            "5. Port API standar: 8728 (plain), 8729 (SSL)"
        ];

        return $info;
    }
}

if (!function_exists('isMikrotikHostReachable')) {
    // Cek cepat tanpa login API -- cukup buka socket TCP ke port API, dipakai
    // untuk menentukan status online/offline sebelum memutuskan alur rotasi
    // kredensial (lihat proses/editserver.php).
    function isMikrotikHostReachable($host, $port, $timeout = 3) {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket) {
            fclose($socket);
            return true;
        }
        return false;
    }
}
