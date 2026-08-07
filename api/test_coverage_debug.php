<?php
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- file debug/scaffolding, sebelumnya WAJIB
// username+password plaintext dan tidak pernah baca param `key`/`api_key`.

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

    $response = ['success' => true, 'step' => 'start', 'odpMarkers' => [], 'stats' => ['total_odp' => 0, 'total_pelanggan' => 0, 'online_customers' => 0]];

    // ODP
    $sql_odp = "SELECT id, NAME, KODE, PORT, BRAND, AREA, TIKOR FROM odp";
    $result_odp = $conn->query($sql_odp);
    $response['step'] = 'odp_query_done: ' . ($result_odp ? 'OK' : 'ERROR');

    if (!$result_odp) {
        throw new Exception("ODP query error: " . $conn->error);
    }

    $odpMapByKode = [];
    if ($result_odp && $result_odp->num_rows > 0) {
        $response['stats']['total_odp'] = $result_odp->num_rows;
        $odp_processed = 0;
        
        while ($row = $result_odp->fetch_assoc()) {
            $odp_processed++;
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
            $popup = "ODP: $name";
            
            $response['odpMarkers'][] = [
                'id' => $row['id'],
                'kode' => $kode,
                'lat' => $lat,
                'lng' => $lng
            ];
            
            $odpMapByKode[$kode] = ['lat' => $lat, 'lng' => $lng];
        }
        $response['odp_processed'] = $odp_processed;
        $response['odp_added'] = count($response['odpMarkers']);
    }

    $response['step'] = 'odp_done, now pelanggan';

    // PELANGGAN
    $sql_pelanggan = "SELECT IDPEL, NAMA, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG, PEMILIK, AREA FROM pelanggan WHERE PEMILIK = ?";
    
    $stmt = $conn->prepare($sql_pelanggan);
    if (!$stmt) {
        throw new Exception("Prepare error: " . $conn->error);
    }

    $stmt->bind_param("s", $pemilik);
    if (!$stmt->execute()) {
        throw new Exception("Execute error: " . $stmt->error);
    }

    $result_pelanggan = $stmt->get_result();
    $response['pelangganMarkers'] = [];
    $response['lines'] = [];
    
    if ($result_pelanggan && $result_pelanggan->num_rows > 0) {
        $response['stats']['total_pelanggan'] = $result_pelanggan->num_rows;
        $pel_processed = 0;
        
        while ($row = $result_pelanggan->fetch_assoc()) {
            $pel_processed++;
            if ($pel_processed > 20) break; // Limit to first 20
            
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
                     "<br><b>ID Pel:</b> " . htmlspecialchars($row['IDPEL']);
            
            $marker = [
                'idpel' => $row['IDPEL'],
                'nama' => $row['NAMA'],
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
                    'odp' => $odp_kode
                ];
            }
        }
        $response['pel_processed'] = $pel_processed;
        $response['pel_added'] = count($response['pelangganMarkers']);
    }
    
    $response['step'] = 'done';

    echo json_encode($response, JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
