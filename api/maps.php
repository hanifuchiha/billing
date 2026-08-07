<?php
// Maps API - Direct HTML Leaflet Map
// Loads data dari pre-generated serverlog JSON file yang sama dengan dashboard
// Auth diperluas supaya juga terima API key dari tabel `apikey` (session -> username+password ->
// API key), mengikuti urutan precedence _bootstrap.php::api_authenticate(). File ini me-render HTML
// (bukan JSON) jadi SENGAJA TIDAK pakai api_cors()/api_authenticate() langsung dari bootstrap --
// keduanya mengasumsikan response JSON (Content-Type: application/json) yang akan merusak halaman
// map ini. Cuma reuse api_read_input()/api_rate_limit() yang aman dipakai di luar konteks JSON.
header('Content-Type: text/html; charset=utf-8');

try {
    require_once '../koneksibilling.php';
    require_once '_bootstrap.php';
    session_start();

    if (!$conn) {
        throw new Exception("Database connection failed");
    }

    $input = api_read_input();
    $auth_username = null;
    $auth_api_key = null;

    if (isset($_SESSION['status']) && $_SESSION['status'] === 'login' && !empty($_SESSION['PEMILIK'])) {
        $auth_username = (string)$_SESSION['PEMILIK'];
    } else {
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        if ($username !== '' && $password !== '') {
            $stmt = $conn->prepare("SELECT USERNAME, PASWORD FROM user WHERE USERNAME = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row && (password_verify($password, (string)$row['PASWORD']) || $password === (string)$row['PASWORD'])) {
                    $auth_username = (string)$row['USERNAME'];
                }
            }
        }

        if ($auth_username === null) {
            $apiKey = trim((string)($input['key'] ?? ($input['api_key'] ?? '')));
            if ($apiKey !== '') {
                $stmtKey = $conn->prepare('SELECT user_name FROM apikey WHERE api_key = ? LIMIT 1');
                if ($stmtKey) {
                    $stmtKey->bind_param('s', $apiKey);
                    $stmtKey->execute();
                    $rowKey = $stmtKey->get_result()->fetch_assoc();
                    if ($rowKey && !empty($rowKey['user_name'])) {
                        $auth_username = (string)$rowKey['user_name'];
                        $auth_api_key = $apiKey;
                    }
                }
            }
        }
    }

    if (!$auth_username) {
        http_response_code(401);
        echo "Autentikasi gagal";
        exit;
    }
    if ($auth_api_key) {
        api_rate_limit($conn, $auth_api_key);
    }

    // Load dari serverlog JSON file (sama seperti dashboard)
    $serverlog_file = __DIR__ . '/../serverlog/' . strtolower($auth_username) . '_online_client.txt';
    
    $odpMarkers = [];
    $pelangganMarkers = [];
    $lines = [];
    $stats = ['total_odp' => 0, 'total_pelanggan' => 0, 'online' => 0, 'offline' => 0];

    // Try to load dari serverlog file
    if (file_exists($serverlog_file)) {
        $json_data = file_get_contents($serverlog_file);
        if ($json_data) {
            $data = json_decode($json_data, true);
            if ($data) {
                $odpMarkers = $data['odpMarkers'] ?? [];
                $pelangganMarkers = $data['pelangganMarkers'] ?? [];
                $lines = $data['lines'] ?? [];
                
                $stats['total_odp'] = count($odpMarkers);
                $stats['total_pelanggan'] = count($pelangganMarkers);
                
                // Count online/offline from pelangganMarkers
                foreach ($pelangganMarkers as $marker) {
                    if ($marker['status'] === 'online') {
                        $stats['online']++;
                    } else {
                        $stats['offline']++;
                    }
                }
            }
        }
    }
    
    // If file doesn't exist or failed, show message
    if (empty($odpMarkers) && empty($pelangganMarkers)) {
        // Fallback: query langsung dari database jika serverlog belum tersedia
        // This ensures maps still work even if cron hasn't run yet
        
        // Get user's PEMILIK values from server table
        $user_sql = "SELECT ID FROM user WHERE USERNAME = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("s", $auth_username);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        
        $user_id = null;
        if ($user_result && $user_result->num_rows > 0) {
            $user_row = $user_result->fetch_assoc();
            $user_id = $user_row['ID'];
        }
        
        if ($user_id) {
            // Get PEMILIK values from server table for this user
            $server_sql = "SELECT DISTINCT PEMILIK FROM server WHERE user_id = ?";
            $server_stmt = $conn->prepare($server_sql);
            $server_stmt->bind_param("i", $user_id);
            $server_stmt->execute();
            $server_result = $server_stmt->get_result();
            
            $pemilik_list = [];
            while ($row = $server_result->fetch_assoc()) {
                $pemilik_list[] = "'" . $conn->real_escape_string($row['PEMILIK']) . "'";
            }
            
            if (!empty($pemilik_list)) {
                $pemilik_in = implode(',', $pemilik_list);
                
                // Get ODP filtered by PEMILIK
                $sql_odp = "SELECT NAME, KODE, PORT, BRAND, AREA, TIKOR FROM odp WHERE PEMILIK IN ($pemilik_in)";
                $result_odp = $conn->query($sql_odp);
                
                $odpMapByKode = [];
                if ($result_odp && $result_odp->num_rows > 0) {
                    while ($row = $result_odp->fetch_assoc()) {
                        $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
                        if (empty($tikor) || strpos($tikor, ',') === false) continue;
                        
                        $coords = explode(',', $tikor);
                        if (count($coords) < 2) continue;
                        
                        $lat = floatval(trim($coords[0]));
                        $lng = floatval(trim($coords[1]));
                        
                        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
                        
                        $popup = "<b>ODP:</b> " . htmlspecialchars($row['NAME']) . 
                                 "<br><b>Kode:</b> " . htmlspecialchars($row['KODE']) . 
                                 "<br><b>Port:</b> " . htmlspecialchars($row['PORT']) . 
                                 "<br><b>Brand:</b> " . htmlspecialchars($row['BRAND']) . 
                                 "<br><b>Area:</b> " . htmlspecialchars($row['AREA']);
                        
                        $odpMarkers[] = [
                            'lat' => $lat,
                            'lng' => $lng,
                            'name' => $row['NAME'],
                            'kode' => $row['KODE'],
                            'popup' => $popup,
                            'area' => $row['AREA']
                        ];
                        
                        $odpMapByKode[$row['KODE']] = ['lat' => $lat, 'lng' => $lng];
                    }
                    $stats['total_odp'] = count($odpMarkers);
                }
                
                // Get Pelanggan filtered by PEMILIK
                $sql_pelanggan = "SELECT IDPEL, NAMA, ALAMAT, TIKOR, PAKET, ODP, TANGGALPASANG FROM pelanggan WHERE PEMILIK IN ($pemilik_in)";
                $result_pelanggan = $conn->query($sql_pelanggan);
                
                if ($result_pelanggan && $result_pelanggan->num_rows > 0) {
                    while ($row = $result_pelanggan->fetch_assoc()) {
                        $tikor = str_replace(' ', '', trim($row['TIKOR'] ?? ''));
                        if (empty($tikor) || strpos($tikor, ',') === false) continue;
                        
                        $coords = explode(',', $tikor);
                        if (count($coords) < 2) continue;
                        
                        $lat = floatval(trim($coords[0]));
                        $lng = floatval(trim($coords[1]));
                        
                        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
                        
                        $popup = "<b>Pelanggan:</b> " . htmlspecialchars($row['NAMA']) . 
                                 "<br><b>ID:</b> " . htmlspecialchars($row['IDPEL']) . 
                                 "<br><b>Alamat:</b> " . htmlspecialchars($row['ALAMAT']) . 
                                 "<br><b>ODP:</b> " . htmlspecialchars($row['ODP']) . 
                                 "<br><b>Paket:</b> " . htmlspecialchars($row['PAKET']) . 
                                 "<br><b>Pasang:</b> " . htmlspecialchars($row['TANGGALPASANG']);
                        
                        $pelangganMarkers[] = [
                            'lat' => $lat,
                            'lng' => $lng,
                            'nama' => $row['NAMA'],
                            'idpel' => $row['IDPEL'],
                            'odp' => $row['ODP'],
                            'popup' => $popup,
                            'status' => 'online'
                        ];
                        
                        // Create line to ODP
                        if (isset($odpMapByKode[$row['ODP']])) {
                            $lines[] = [
                                'odp' => $row['ODP'],
                                'from' => [$lat, $lng],
                                'to' => [$odpMapByKode[$row['ODP']]['lat'], $odpMapByKode[$row['ODP']]['lng']],
                                'status' => 'online'
                            ];
                        }
                    }
                    $stats['total_pelanggan'] = count($pelangganMarkers);
                    $stats['online'] = count($pelangganMarkers);
                }
            }
        }
    }

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ODP Coverage Maps</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; height: 100vh; }
        #map { width: 100%; height: 100vh; }
        
        /* Fullscreen button */
        .fullscreen-btn {
            position: fixed;
            top: 10px;
            right: 10px;
            background: white;
            border: 2px solid #ccc;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            z-index: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        .fullscreen-btn:hover {
            background: #f0f0f0;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show {
            display: flex;
        }
        .modal-box {
            background: white;
            border-radius: 8px;
            padding: 20px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            position: relative;
        }
        .modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        .modal-close:hover {
            color: #000;
        }
        .modal-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 15px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .modal-content {
            font-size: 14px;
            line-height: 1.6;
        }
        .modal-content .info-row {
            margin: 8px 0;
            display: flex;
            justify-content: space-between;
        }
        .modal-content .label {
            font-weight: bold;
            color: #333;
            min-width: 120px;
        }
        .modal-content .value {
            color: #666;
            flex: 1;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin-top: 10px;
        }
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        .status-offline {
            background: #f8d7da;
            color: #721c24;
        }
        .modal-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
            flex: 1;
        }
        .btn-secondary:hover {
            background: #545b62;
        }
        
        /* Custom Marker Icons */
        .odp-marker {
            background: #FF9800;
            border: 2px solid #333;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .pelanggan-marker {
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
            border: 2px solid #333;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        .pelanggan-online {
            background: #00FF00;
        }
        .pelanggan-offline {
            background: #FF0000;
        }
    </style>
</head>
<body>
    <button class="fullscreen-btn" id="fullscreenBtn" onclick="toggleFullscreen()">⛶ Fullscreen</button>
    <div id="map"></div>
    
    <!-- Modal -->
    <div id="detailModal" class="modal-overlay">
        <div class="modal-box">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <div class="modal-title" id="modalTitle">Detail</div>
            <div class="modal-content" id="modalContent"></div>
            <div class="modal-actions" id="modalActions"></div>
        </div>
    </div>

    <script>
        var map = L.map('map').setView([-6.2088, 106.8456], 11);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);

        // Fullscreen toggle
        function toggleFullscreen() {
            var btn = document.getElementById('fullscreenBtn');
            var mapEl = document.getElementById('map');
            
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(err => {
                    console.log('Exit fullscreen error:', err);
                });
                btn.textContent = '⛶ Fullscreen';
            } else {
                // Try fullscreen API
                if (mapEl.requestFullscreen) {
                    mapEl.requestFullscreen().catch(err => {
                        console.log('Fullscreen not available:', err);
                        alert('Fullscreen tidak tersedia di device ini');
                    });
                } else if (mapEl.webkitRequestFullscreen) {
                    // iOS Safari support
                    mapEl.webkitRequestFullscreen();
                } else {
                    alert('Fullscreen tidak didukung di browser ini');
                    return;
                }
                btn.textContent = '✕ Exit Fullscreen';
            }
            
            // Refresh map size
            setTimeout(() => {
                map.invalidateSize();
            }, 200);
        }

        // Custom ODP Icon
        var odpIcon = L.divIcon({
            html: '<div class="odp-marker">ODP</div>',
            iconSize: [32, 32],
            iconAnchor: [16, 16],
            popupAnchor: [0, -16],
            className: 'custom-icon'
        });

        // ODP Markers
        var odpData = <?php echo json_encode($odpMarkers); ?>;
        odpData.forEach(function(marker) {
            L.marker([marker.lat, marker.lng], {
                icon: odpIcon
            }).on('click', function() {
                showOdpDetail(marker);
            }).addTo(map);
        });

        // Custom Pelanggan Icons
        var pelangganIcon = {
            online: L.divIcon({
                html: '<div class="pelanggan-marker pelanggan-online">●</div>',
                iconSize: [24, 24],
                iconAnchor: [12, 12],
                popupAnchor: [0, -12],
                className: 'custom-icon'
            }),
            offline: L.divIcon({
                html: '<div class="pelanggan-marker pelanggan-offline">●</div>',
                iconSize: [24, 24],
                iconAnchor: [12, 12],
                popupAnchor: [0, -12],
                className: 'custom-icon'
            })
        };

        // Pelanggan Markers
        var pelangganData = <?php echo json_encode($pelangganMarkers); ?>;
        pelangganData.forEach(function(marker) {
            var icon = marker.status === 'online' ? pelangganIcon.online : pelangganIcon.offline;
            L.marker([marker.lat, marker.lng], {
                icon: icon
            }).on('click', function() {
                showPelangganDetail(marker);
            }).addTo(map);
        });

        // Lines connecting customers to ODP
        var linesData = <?php echo json_encode($lines); ?>;
        linesData.forEach(function(line) {
            var lineColor = line.status === 'online' ? '#00ff00' : '#ff0000';
            L.polyline([line.from, line.to], {
                color: lineColor,
                weight: 2,
                opacity: 0.6,
                dashArray: '5, 5'
            }).addTo(map);
        });

        // Modal Functions
        function closeModal() {
            document.getElementById('detailModal').classList.remove('show');
        }

        function showOdpDetail(marker) {
            var content = '<div class="modal-content">' +
                '<div class="info-row"><span class="label">Nama ODP:</span><span class="value">' + escapeHtml(marker.name) + '</span></div>' +
                '<div class="info-row"><span class="label">Kode:</span><span class="value">' + escapeHtml(marker.kode) + '</span></div>' +
                '<div class="info-row"><span class="label">Area:</span><span class="value">' + escapeHtml(marker.area || 'N/A') + '</span></div>' +
                '<div class="info-row"><span class="label">Koordinat:</span><span class="value">' + marker.lat + ', ' + marker.lng + '</span></div>' +
                '</div>';
            
            document.getElementById('modalTitle').textContent = 'Detail ODP';
            document.getElementById('modalContent').innerHTML = content;
            document.getElementById('modalActions').innerHTML = '<button class="btn btn-secondary" onclick="closeModal()">Tutup</button>';
            document.getElementById('detailModal').classList.add('show');
        }

        function showPelangganDetail(marker) {
            var statusClass = marker.status === 'online' ? 'status-online' : 'status-offline';
            var statusText = marker.status === 'online' ? '🟢 ONLINE' : '🔴 OFFLINE';
            
            var content = '<div class="modal-content">' +
                '<div class="info-row"><span class="label">Nama:</span><span class="value">' + escapeHtml(marker.nama) + '</span></div>' +
                '<div class="info-row"><span class="label">ID Pelanggan:</span><span class="value">' + escapeHtml(marker.idpel) + '</span></div>' +
                '<div class="info-row"><span class="label">ODP:</span><span class="value">' + escapeHtml(marker.odp || 'N/A') + '</span></div>' +
                '<div class="info-row"><span class="label">Koordinat:</span><span class="value">' + marker.lat + ', ' + marker.lng + '</span></div>' +
                '<div class="status-badge ' + statusClass + '">' + statusText + '</div>' +
                '</div>';
            
            // Use custom URL scheme for Android app integration
            var detailButton = '<button class="btn btn-primary" onclick="openCustomerDetail(\'' + escapeHtml(marker.idpel) + '\')">Lihat Detail</button>';
            var actions = detailButton + '<button class="btn btn-secondary" onclick="closeModal()">Tutup</button>';
            
            document.getElementById('modalTitle').textContent = 'Detail Pelanggan';
            document.getElementById('modalContent').innerHTML = content;
            document.getElementById('modalActions').innerHTML = actions;
            document.getElementById('detailModal').classList.add('show');
        }

        // Function to open customer detail in Android app
        function openCustomerDetail(idpel) {
            // Navigate to custom URL scheme for Android app
            window.location.href = 'qts://customer/' + idpel;
        }

        // HTML escape utility
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal when clicking outside
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Update fullscreen button when exiting fullscreen
        document.addEventListener('fullscreenchange', function() {
            var btn = document.getElementById('fullscreenBtn');
            if (!document.fullscreenElement) {
                btn.textContent = '⛶ Fullscreen';
            }
        });

        console.log('✓ Maps loaded: ' + odpData.length + ' ODP, ' + pelangganData.length + ' Pelanggan');
    </script>
</body>
</html>
<?php
} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}
?>
