<?php

/**
 * Aktivasi layanan setelah pembayaran berhasil.
 *
 * Aman dipanggil berulang kali: MikroTik/RADIUS hanya disentuh ketika profil
 * pelanggan masih EXPIRED. Pelanggan yang sudah memakai paket normal tidak
 * diputus koneksinya.
 */

if (!function_exists('paymentActivationLog')) {
    function paymentActivationLog(string $message): void
    {
        $dir = __DIR__ . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/payment_activation.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}

if (!function_exists('paymentActivationPeriodIsRelevant')) {
    function paymentActivationPeriodIsRelevant(string $periode, ?int $now = null): bool
    {
        $periode = trim($periode);
        if ($periode === '') {
            return false;
        }

        $now = $now ?? time();
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        $allowed = [];
        foreach ([-1, 0, 1] as $offset) {
            $ts = strtotime(($offset >= 0 ? '+' : '') . $offset . ' month', $now);
            $allowed[] = strtolower($months[(int) date('n', $ts)] . ' ' . date('Y', $ts));
        }

        return in_array(strtolower($periode), $allowed, true);
    }
}

if (!function_exists('activatePaidCustomerIfExpired')) {
    function activatePaidCustomerIfExpired(mysqli $conn, string $idpel, string $periode, string $source = 'payment'): array
    {
        $result = [
            'success' => false,
            'changed' => false,
            'pending' => false,
            'message' => '',
        ];

        $idpel = trim($idpel);
        if ($idpel === '' || !paymentActivationPeriodIsRelevant($periode)) {
            $result['message'] = 'periode pembayaran tidak relevan untuk aktivasi otomatis';
            return $result;
        }

        $stmt = $conn->prepare(
            'SELECT p.IDPEL,p.NAMA,p.NOWA,p.PASSWORD,p.PAKET,p.PEMILIK,p.AREA,p.IP_STATIC,' .
            's.IP,s.PASSWORD AS SERVER_PASSWORD,s.CONNECTION_MODE ' .
            'FROM pelanggan p LEFT JOIN server s ON s.PEMILIK=p.PEMILIK AND s.AREA=p.AREA ' .
            'WHERE p.IDPEL=? LIMIT 1'
        );
        if (!$stmt) {
            $result['message'] = 'gagal menyiapkan query pelanggan: ' . $conn->error;
            return $result;
        }
        $stmt->bind_param('s', $idpel);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$customer) {
            $result['message'] = 'pelanggan tidak ditemukan';
            return $result;
        }

        $mode = strtoupper(trim((string) ($customer['CONNECTION_MODE'] ?? 'API')));
        $useApi = $mode === '' || $mode === 'API' || $mode === 'API MODE' || $mode === 'MULTI' || $mode === 'MULTI MODE';
        $useRadius = $mode === 'RADIUS' || $mode === 'RADIUS MODE' || $mode === 'MULTI' || $mode === 'MULTI MODE';
        $apiChanged = false;
        $apiAlreadyNormal = false;

        if ($useApi) {
            if (!class_exists('RouterosAPI')) {
                require_once __DIR__ . '/routeros_api.class.php';
            }
            $api = new RouterosAPI();
            $serverIp = trim((string) ($customer['IP'] ?? ''));
            $serverUser = trim((string) ($customer['PEMILIK'] ?? ''));
            $serverPassword = (string) ($customer['SERVER_PASSWORD'] ?? '');

            if ($serverIp === '' || !$api->connect($serverIp, $serverUser, $serverPassword)) {
                $result['pending'] = true;
                $result['message'] = 'MikroTik tidak dapat dihubungi';
                paymentActivationLog("PENDING source=$source idpel=$idpel periode=$periode: {$result['message']}");
                return $result;
            }

            $secrets = $api->comm('/ppp/secret/print', [
                '.proplist' => '.id,name,profile,disabled',
                '?name' => $idpel,
            ]);
            if (!is_array($secrets) || empty($secrets) || empty($secrets[0]['.id'])) {
                $api->disconnect();
                $result['pending'] = true;
                $result['message'] = 'PPP secret tidak ditemukan di MikroTik';
                paymentActivationLog("PENDING source=$source idpel=$idpel periode=$periode: {$result['message']}");
                return $result;
            }

            $secret = $secrets[0];
            $profileNow = strtoupper(trim((string) ($secret['profile'] ?? '')));
            if ($profileNow === 'EXPIRED') {
                $api->comm('/ppp/secret/set', [
                    '.id' => $secret['.id'],
                    'profile' => (string) $customer['PAKET'],
                    'disabled' => 'no',
                    'comment' => 'LUNAS ' . $customer['NAMA'] . ' - ' . $customer['NOWA'] . ' - ' . date('Y-m-d H:i:s'),
                ]);
                $active = $api->comm('/ppp/active/print', [
                    '.proplist' => '.id,name',
                    '?name' => $idpel,
                ]);
                if (is_array($active)) {
                    foreach ($active as $session) {
                        if (!empty($session['.id'])) {
                            $api->comm('/ppp/active/remove', ['.id' => $session['.id']]);
                        }
                    }
                }
                $apiChanged = true;
            } else {
                $apiAlreadyNormal = true;
            }
            $api->disconnect();
        }

        if ($useRadius && ($apiChanged || !$useApi)) {
            require_once __DIR__ . '/radius_sync_lib.php';
            $paketStmt = $conn->prepare('SELECT * FROM paket WHERE PAKET=? AND PEMILIK=? LIMIT 1');
            if ($paketStmt) {
                $paketStmt->bind_param('ss', $customer['PAKET'], $customer['PEMILIK']);
                $paketStmt->execute();
                $paket = $paketStmt->get_result()->fetch_assoc();
                $paketStmt->close();
                if ($paket && function_exists('radiusSyncSingleCustomerNow')) {
                    radiusSyncSingleCustomerNow(
                        $idpel,
                        (string) $customer['PASSWORD'],
                        $paket,
                        true,
                        radiusGetGlobalSettings($conn),
                        (string) ($customer['IP_STATIC'] ?? '')
                    );
                }
            }
        }

        $result['success'] = true;
        $result['changed'] = $apiChanged;
        $result['message'] = $apiChanged
            ? "profil EXPIRED dipulihkan ke {$customer['PAKET']}"
            : ($apiAlreadyNormal ? 'profil sudah normal, MikroTik tidak diubah' : 'sinkronisasi aktivasi selesai');
        paymentActivationLog(($apiChanged ? 'SUCCESS' : 'SKIP') . " source=$source idpel=$idpel periode=$periode: {$result['message']}");
        return $result;
    }
}

