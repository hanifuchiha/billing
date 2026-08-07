<?php
// Coverage ODP & Customers API - Returns markers data for maps
// Sebelumnya cuma dukung username+password -- tidak pernah baca param
// `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi akses via
// API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.

try {
    require_once '../koneksibilling.php';
    require_once '_bootstrap.php';
    session_start();
    api_cors();

    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    $input = api_read_input();

    $auth = api_authenticate($conn, $input);
    $pemilik = $auth['pemilik'];
    if ($auth['method'] === 'apikey') {
        api_rate_limit($conn, $auth['api_key']);
    }

    $response = [
        'success' => true,
        'odpMarkers' => [],
        'pelangganMarkers' => [],
        'lines' => [],
        'stats' => ['total_odp' => 0, 'total_pelanggan' => 0, 'online_customers' => 0, 'offline_customers' => 0]
    ];

    // ODP MARKERS
    $sql_odp = "SELECT id, NAME, KODE, PORT, BRAND, AREA, TIKOR FROM odp";
    $result_odp = $conn->query($sql_odp);

    if (!$result_odp) {
        throw new Exception("ODP query error: " . $conn->error);
    }

    $odpMapByKode = [];

    if ($result_odp && $result_odp->num_rows > 0) {
        $response['stats']['total_odp'] = $result_odp->num_rows;
        
        while ($row = $result_odp->fetch_assoc()) {
            $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
            
            if (empty($tikor) || strpos($tikor, ',') === false) {
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
            
            $kode = $row['KODE'];
            $name = $row['NAME'];
            $popup = "<b>ODP:</b> " . htmlspecialchars($name) . 
                     "<br><b>Kode:</b> " . htmlspecialchars($kode) . 
                     "<br><b>Port:</b> " . htmlspecialchars($row['PORT']) . 
                     "<br><b>Brand:</b> " . htmlspecialchars($row['BRAND']) . 
                     "<br><b>Area:</b> " . htmlspecialchars($row['AREA']);
            
            $response['odpMarkers'][] = [
                'id' => $row['id'],
                'kode' => $kode,
                'name' => $name,
                'port' => $row['PORT'],
                'brand' => $row['BRAND'],
                'area' => $row['AREA'],
                'lat' => $lat,
                'lng' => $lng,
                'color' => '#f39c12',
                'popup' => $popup
            ];
            
            $odpMapByKode[$kode] = ['lat' => $lat, 'lng' => $lng, 'name' => $name, 'popup' => $popup];
        }
    }

    // PELANGGAN MARKERS
    $sql_pelanggan = "SELECT IDPEL, NAMA, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG, PEMILIK, AREA FROM pelanggan WHERE PEMILIK = ? ORDER BY IDPEL";
    
    $stmt = $conn->prepare($sql_pelanggan);
    if (!$stmt) {
        throw new Exception("Pelanggan prepare error: " . $conn->error);
    }

    $stmt->bind_param("s", $pemilik);
    if (!$stmt->execute()) {
        throw new Exception("Pelanggan execute error: " . $stmt->error);
    }

    $result_pelanggan = $stmt->get_result();

    if ($result_pelanggan && $result_pelanggan->num_rows > 0) {
        $response['stats']['total_pelanggan'] = $result_pelanggan->num_rows;
        
        while ($row = $result_pelanggan->fetch_assoc()) {
            $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
            
            if (empty($tikor) || strpos($tikor, ',') === false) {
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
            
            $status = 'online';
            $response['stats']['online_customers']++;
            
            $popup = "<b>Pelanggan:</b> " . htmlspecialchars($row['NAMA']) . 
                     "<br><b>ID Pel:</b> " . htmlspecialchars($row['IDPEL']) . 
                     "<br><b>Alamat:</b> " . htmlspecialchars($row['ALAMAT']) . 
                     "<br><b>ODP:</b> " . htmlspecialchars($row['ODP']) . 
                     "<br><b>Paket:</b> " . htmlspecialchars($row['PAKET']) . 
                     "<br><b>Tanggal Pasang:</b> " . htmlspecialchars($row['TANGGALPASANG']);
            
            $marker = [
                'idpel' => $row['IDPEL'],
                'nama' => $row['NAMA'],
                'alamat' => $row['ALAMAT'],
                'odp' => $row['ODP'],
                'paket' => $row['PAKET'],
                'area' => $row['AREA'],
                'status' => $status,
                'lat' => $lat,
                'lng' => $lng,
                'popup' => $popup
            ];
            
            $response['pelangganMarkers'][] = $marker;
            
            $odp_kode = $row['ODP'];
            if (isset($odpMapByKode[$odp_kode])) {
                $odp = $odpMapByKode[$odp_kode];
                $response['lines'][] = [
                    'from' => [$odp['lat'], $odp['lng']],
                    'to' => [$lat, $lng],
                    'status' => $status,
                    'odp' => $odp_kode,
                    'idpel' => $row['IDPEL'],
                    'color' => '#00ff00'
                ];
            }
        }
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}
?>

