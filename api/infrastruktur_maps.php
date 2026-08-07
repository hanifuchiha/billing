<?php
// Auth diganti ke _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) -- sebelumnya endpoint ini WAJIB username+password plaintext, tidak
// pernah cek API key ataupun sesi browser aktif.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

function normalize_customer_map_status($rawStatus) {
    $value = strtolower(trim((string)$rawStatus));
    if ($value === '') {
        return 'online';
    }

    $offlineKeywords = ['los', 'offline', 'off line', 'redaman', 'gangguan', 'putus', 'isolir', 'nonaktif', 'non aktif', 'berhenti', 'dismantle', 'cancel'];
    foreach ($offlineKeywords as $keyword) {
        if (strpos($value, $keyword) !== false) {
            return 'offline';
        }
    }

    return 'online';
}

try {
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $input = api_read_input();
    $auth = api_authenticate($conn, $input);
    $authUsername = $auth['pemilik'];
    if ($auth['method'] === 'apikey') {
        api_rate_limit($conn, $auth['api_key']);
    }

    $serverlogFile = __DIR__ . '/../serverlog/' . strtolower($authUsername) . '_online_client.txt';
    $odpMarkers = [];
    $pelangganMarkers = [];
    $lines = [];
    $stats = ['total_odp' => 0, 'total_pelanggan' => 0, 'online' => 0, 'offline' => 0];

    if (file_exists($serverlogFile)) {
        $jsonData = file_get_contents($serverlogFile);
        if ($jsonData) {
            $data = json_decode($jsonData, true);
            if ($data) {
                $odpMarkers = $data['odpMarkers'] ?? [];
                $pelangganMarkers = $data['pelangganMarkers'] ?? [];
                $lines = $data['lines'] ?? [];
                $stats['total_odp'] = count($odpMarkers);
                $stats['total_pelanggan'] = count($pelangganMarkers);
                foreach ($pelangganMarkers as $marker) {
                    if (normalize_customer_map_status($marker['status'] ?? '') === 'online') {
                        $stats['online']++;
                    } else {
                        $stats['offline']++;
                    }
                }
            }
        }
    }

    if (empty($odpMarkers) && empty($pelangganMarkers)) {
        $userSql = "SELECT ID FROM user WHERE USERNAME = ?";
        $userStmt = $conn->prepare($userSql);
        $userStmt->bind_param("s", $authUsername);
        $userStmt->execute();
        $userResult = $userStmt->get_result();

        $userId = null;
        if ($userResult && $userResult->num_rows > 0) {
            $userRow = $userResult->fetch_assoc();
            $userId = $userRow['ID'];
        }

        if ($userId) {
            $serverSql = "SELECT DISTINCT PEMILIK FROM server WHERE user_id = ?";
            $serverStmt = $conn->prepare($serverSql);
            $serverStmt->bind_param("i", $userId);
            $serverStmt->execute();
            $serverResult = $serverStmt->get_result();

            $pemilikList = [];
            while ($row = $serverResult->fetch_assoc()) {
                $pemilikList[] = "'" . $conn->real_escape_string($row['PEMILIK']) . "'";
            }

            if (!empty($pemilikList)) {
                $pemilikIn = implode(',', $pemilikList);
                $sqlOdp = "SELECT NAME, KODE, PORT, BRAND, AREA, TIKOR FROM odp WHERE PEMILIK IN ($pemilikIn)";
                $resultOdp = $conn->query($sqlOdp);

                $odpMapByKode = [];
                if ($resultOdp && $resultOdp->num_rows > 0) {
                    while ($row = $resultOdp->fetch_assoc()) {
                        $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
                        if ($tikor === '' || strpos($tikor, ',') === false) {
                            continue;
                        }

                        $coords = explode(',', $tikor);
                        if (count($coords) < 2) {
                            continue;
                        }

                        $lat = floatval(trim($coords[0]));
                        $lng = floatval(trim($coords[1]));
                        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                            continue;
                        }

                        $odpMarkers[] = [
                            'lat' => $lat,
                            'lng' => $lng,
                            'name' => $row['NAME'] ?? '',
                            'kode' => $row['KODE'] ?? '',
                            'port' => $row['PORT'] ?? '',
                            'brand' => $row['BRAND'] ?? '',
                            'area' => $row['AREA'] ?? ''
                        ];
                        $odpMapByKode[$row['KODE']] = ['lat' => $lat, 'lng' => $lng];
                    }
                    $stats['total_odp'] = count($odpMarkers);
                }

                $sqlPelanggan = "SELECT IDPEL, NAMA, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG, STATUS FROM pelanggan WHERE PEMILIK IN ($pemilikIn)";
                $resultPelanggan = $conn->query($sqlPelanggan);
                if ($resultPelanggan && $resultPelanggan->num_rows > 0) {
                    while ($row = $resultPelanggan->fetch_assoc()) {
                        $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
                        if ($tikor === '' || strpos($tikor, ',') === false) {
                            continue;
                        }

                        $coords = explode(',', $tikor);
                        if (count($coords) < 2) {
                            continue;
                        }

                        $lat = floatval(trim($coords[0]));
                        $lng = floatval(trim($coords[1]));
                        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                            continue;
                        }

                        $normalizedStatus = normalize_customer_map_status($row['STATUS'] ?? '');

                        $pelangganMarkers[] = [
                            'lat' => $lat,
                            'lng' => $lng,
                            'nama' => $row['NAMA'] ?? '',
                            'idpel' => $row['IDPEL'] ?? '',
                            'alamat' => $row['ALAMAT'] ?? '',
                            'odp' => $row['ODP'] ?? '',
                            'paket' => $row['PAKET'] ?? '',
                            'tanggalpasang' => $row['TANGGALPASANG'] ?? '',
                            'status' => $normalizedStatus
                        ];

                        if (isset($odpMapByKode[$row['ODP']])) {
                            $lines[] = [
                                'odp' => $row['ODP'],
                                'from' => [$lat, $lng],
                                'to' => [
                                    $odpMapByKode[$row['ODP']]['lat'],
                                    $odpMapByKode[$row['ODP']]['lng']
                                ],
                                'status' => $normalizedStatus
                            ];
                        }

                        if ($normalizedStatus === 'online') {
                            $stats['online']++;
                        } else {
                            $stats['offline']++;
                        }
                    }
                    $stats['total_pelanggan'] = count($pelangganMarkers);
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'odp_markers' => $odpMarkers,
        'pelanggan_markers' => $pelangganMarkers,
        'lines' => $lines
    ]);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
