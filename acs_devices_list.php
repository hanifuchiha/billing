<?php
// Handle AJAX actions BEFORE loading header to prevent HTML output
if (isset($_POST['action'])) {
    require 'cek-sesi.php';
    require 'acs_helper.php';
    
    // Clean any output buffer and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    
    // Initialize ACS helper
    $acs = new ACSHelper($conn, $USER_ID, $AKSES);
    
    if ($_POST['action'] === 'sync') {
        $server_id = intval($_POST['server_id']);
        try {
            $result = $acs->syncDevices($server_id);
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false, 
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'test_api') {
        $server_id = intval($_POST['server_id']);
        try {
            $acs = new ACSHelper($conn, $USER_ID, $AKSES);
            $server = $acs->getServer($server_id);
            
            if (!$server) {
                echo json_encode(['success' => false, 'message' => 'Server not found']);
                exit;
            }
            
            // Use NBI port (base_port + 2), or stored nbi_port for external servers
            $nbi_port = isset($server['nbi_port']) && $server['nbi_port'] !== null ? intval($server['nbi_port']) : intval($server['port']) + 2;
            $url = "http://{$server['domain']}:{$nbi_port}/devices";

            // Test API connection with curl
            $ch = curl_init($url);
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            @curl_setopt($ch, CURLOPT_USERPWD, "{$server['username_acs']}:{$server['password_acs']}");
            @curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            
            $response = @curl_exec($ch);
            $http_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = @curl_error($ch);
            @curl_close($ch);
            
            // Decode JSON and get stats
            $decoded_data = null;
            $device_count = 0;
            $device_fields = [];
            
            if ($http_code === 200 && $response) {
                $decoded_data = json_decode($response, true);
                if (is_array($decoded_data)) {
                    $device_count = count($decoded_data);
                    if ($device_count > 0) {
                        // Get all fields from devices
                        foreach ($decoded_data as $device) {
                            if (is_array($device)) {
                                $device_fields = array_keys($device);
                                break;
                            }
                        }
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'http_code' => $http_code,
                'url' => $url,
                'nbi_port' => $nbi_port,
                'curl_error' => $curl_error ?: '',
                'response_length' => strlen($response),
                'device_count' => $device_count,
                'device_fields' => $device_fields,
                'full_response' => $decoded_data,
                'raw_response' => $response
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false, 
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'update_credentials') {
        $server_id = intval($_POST['server_id']);
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        try {
            $acs = new ACSHelper($conn, $USER_ID, $AKSES);
            $server = $acs->getServer($server_id);
            
            if (!$server) {
                echo json_encode(['success' => false, 'message' => 'Server not found']);
                exit;
            }
            
            // Test new credentials using NBI port (base_port + 2), or stored nbi_port for external servers
            $nbi_port = isset($server['nbi_port']) && $server['nbi_port'] !== null ? intval($server['nbi_port']) : intval($server['port']) + 2;
            $url = "http://{$server['domain']}:{$nbi_port}/devices";
            $ch = curl_init($url);
            @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            @curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            @curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
            @curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            $response = @curl_exec($ch);
            $http_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
            @curl_close($ch);
            
            if ($http_code !== 200) {
                echo json_encode(['success' => false, 'message' => "Test failed: HTTP $http_code"]);
                exit;
            }
            
            // Update credentials in database
            $update_sql = "UPDATE acs_servers SET 
                           username_acs = '" . mysqli_real_escape_string($conn, $username) . "',
                           password_acs = '" . mysqli_real_escape_string($conn, $password) . "'
                           WHERE id = " . intval($server_id);
            
            if (mysqli_query($conn, $update_sql)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Credentials updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Database error: ' . mysqli_error($conn)
                ]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

require 'header.php';
require 'acs_helper.php';

// Initialize ACS helper
$acs = new ACSHelper($conn, $USER_ID, $AKSES);

// Ensure database is ready
$acs->ensureDatabase();

// Get PEMILIK for filtering pelanggan data
$userlogin = isset($_SESSION['PEMILIK']) ? $_SESSION['PEMILIK'] : '';

// Get servers based on user role
$servers = $acs->getServers();

// Get selected server
$selected_server_id = isset($_GET['server_id']) ? intval($_GET['server_id']) : null;

if (empty($servers)) {
    $selected_server = null;
} elseif ($selected_server_id) {
    $selected_server = $acs->getServer($selected_server_id);
} else {
    $selected_server = $servers[0]; // First server as default
}

// Get devices for selected server
$devices = [];
if ($selected_server) {
    $sql = "SELECT * FROM acs_devices WHERE server_id = " . $selected_server['id'] . " ORDER BY last_inform DESC";
    $result = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($result)) {
        $devices[] = $row;
    }
}

// Filter devices: show only devices whose virtual parameter pppoeUsername/pppoeUsername2 exists in pelanggan.IDPEL
$filtered_devices = [];
if (!empty($devices)) {
    $pppoe_ids = [];

    $extractPppoeUsername = function ($vparams) {
        if (!is_array($vparams)) {
            return '';
        }

        $candidates = [];
        if (isset($vparams['pppoeUsername'])) {
            $candidates[] = $vparams['pppoeUsername'];
        }
        if (isset($vparams['pppoeUsername2'])) {
            $candidates[] = $vparams['pppoeUsername2'];
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['_value'])) {
                $candidate = $candidate['_value'];
            }

            $candidate = trim((string)$candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    };

    foreach ($devices as $device) {
        $vparams = json_decode($device['virtual_parameters'] ?? '{}', true);
        $pppoe_value = $extractPppoeUsername($vparams);
        if ($pppoe_value !== '') {
            $pppoe_ids[$pppoe_value] = true;
        }
    }

    $existing_idpel = [];
    if (!empty($pppoe_ids)) {
        $escaped_ids = [];
        foreach (array_keys($pppoe_ids) as $idpel) {
            $escaped_ids[] = "'" . mysqli_real_escape_string($conn, $idpel) . "'";
        }

        $pelanggan_sql = "SELECT IDPEL FROM pelanggan WHERE IDPEL IN (" . implode(',', $escaped_ids) . ")";
        
        // Filter by PEMILIK if user is not ADMIN
        if ($AKSES !== 'ADMIN' && !empty($userlogin)) {
            $pelanggan_sql .= " AND PEMILIK = '" . mysqli_real_escape_string($conn, $userlogin) . "'";
        }
        
        $pelanggan_result = mysqli_query($conn, $pelanggan_sql);
        if ($pelanggan_result) {
            while ($pel = mysqli_fetch_assoc($pelanggan_result)) {
                $existing_idpel[$pel['IDPEL']] = true;
            }
        }
    }

    foreach ($devices as $device) {
        $vparams = json_decode($device['virtual_parameters'] ?? '{}', true);
        $pppoe_value = $extractPppoeUsername($vparams);
        if ($pppoe_value !== '' && isset($existing_idpel[$pppoe_value])) {
            $filtered_devices[] = $device;
        }
    }
}

$devices = $filtered_devices;
?>

    <style>
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .status-online {
            color: #28a745;
            font-weight: bold;
        }
        .status-offline {
            color: #dc3545;
            font-weight: bold;
        }
        .device-serial {
            font-family: monospace;
            font-weight: bold;
        }

        /* ACS dark theme overrides */
        body.app-theme-dark .card,
        body.app-theme-dark .card-body,
        body.app-theme-dark .modal-content,
        body.app-theme-dark pre,
        body.app-theme-dark code {
            background: #0f172a !important;
            color: #e5e7eb !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
        }
        body.app-theme-dark .table,
        body.app-theme-dark .table td,
        body.app-theme-dark .table th,
        body.app-theme-dark .table-responsive,
        body.app-theme-dark .text-muted,
        body.app-theme-dark p,
        body.app-theme-dark li,
        body.app-theme-dark span,
        body.app-theme-dark h1,
        body.app-theme-dark h2,
        body.app-theme-dark h3,
        body.app-theme-dark h4,
        body.app-theme-dark h5,
        body.app-theme-dark h6,
        body.app-theme-dark label {
            color: #e5e7eb !important;
        }
        body.app-theme-dark .table td,
        body.app-theme-dark .table th {
            border-color: rgba(148, 163, 184, 0.25) !important;
        }
        body.app-theme-dark .table-hover tbody tr:hover {
            background: rgba(59, 130, 246, 0.12) !important;
        }
        body.app-theme-dark .table-dark th {
            color: #f8fafc !important;
        }
        body.app-theme-dark a:not(.btn) {
            color: #7dd3fc !important;
        }
    </style>

    
    
        <div class="card">
            <div class="card-header">
                <h4><i class="fas fa-mobile-alt"></i> Perangkat Pelanggan (ONT)</h4>
            </div>
            <div class="card-body">
                <?php if (empty($servers)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Tidak ada server ACS yang tersedia. Silakan tambah server baru.
                    </div>
                <?php else: ?>
                    <?php if (empty($devices)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Belum ada data perangkat yang cocok dengan data pelanggan (IDPEL = virtual parameter pppoeUsername atau pppoeUsername2).
                            <hr>
                            <small>
                                <strong>📡 Metode Sync:</strong><br>
                                1️⃣ <strong>GenieACS API</strong> - Mengambil data ONT langsung dari server GenieACS (method utama)<br>
                                2️⃣ <strong>PPPoE Fallback</strong> - Jika GenieACS tidak tersedia, sistem akan mengambil data dari PPPoE Active yang di-match dengan data pelanggan
                            </small>
                        </div>
                    <?php else: ?>
                        <?php 
                        $has_vparams = false;
                        // Important parameters to highlight (match both flat and nested names)
                        $important_params = [
                            'RXPower', 'TXPower', 'gettemp', 'pppoeIP', 'pppoeIP2', 'getdeviceuptime', 'getponmode',
                            'pppoeUsername', 'pppoeUsername2', 'getSerialNumber', 'activedevices', 'getpppuptime',
                            'PonMac', 'pppoeMac', 'IPTR069', 'WlanPassword', 'pppoePassword',
                            // TR-069 nested paths
                            'InternetGatewayDevice.DeviceInfo.HardwareVersion',
                            'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
                            'InternetGatewayDevice.DeviceInfo.UpTime',
                            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
                            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TXPower',
                            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TransceiverTemperature',
                            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
                            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.KeyPassphrase',
                            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.2.SSID',
                            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.ExternalIPAddress',
                            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.3.WANPPPConnection.1.Username'
                        ];
                        
                        foreach ($devices as $device): 
                            $vparams = json_decode($device['virtual_parameters'] ?? '{}', true);
                            
                            // Build combined parameters from VirtualParameters + important TR-069 params
                            $all_params = [];
                            
                            // Add all VirtualParameters
                            if (is_array($vparams)) {
                                foreach ($vparams as $key => $value) {
                                    // Skip meta keys that start with underscore
                                    if ($key !== '_object' && $key !== '_timestamp' && $key !== '_writable') {
                                        $all_params[$key] = $value;
                                    }
                                }
                            }
                            
                            // Add device basic info from database columns
                            $all_params['_DeviceSerial'] = $device['serial_number'];
                            $all_params['_Manufacturer'] = $device['manufacturer'];
                            $all_params['_ProductClass'] = $device['product_class'];
                            $all_params['_HardwareVersion'] = $device['hardware_version'];
                            $all_params['_SoftwareVersion'] = $device['software_version'];
                            $all_params['_IPAddress'] = $device['ip_address'];
                            $all_params['_MACAddress'] = $device['mac_address'];
                            $all_params['_Status'] = $device['status'];
                            $all_params['_LastInform'] = $device['last_inform'];
                            
                            if (!empty($all_params)):
                                $has_vparams = true;
                                $serial = htmlspecialchars($device['serial_number']);
                                $matched_idpel = htmlspecialchars($extractPppoeUsername($vparams));
                        ?>
                        
                        <!-- Device Card -->
                        <div class="card mb-3">
                            <div class="card-header bg-dark text-white">
                                <i class="fas fa-mobile-alt"></i> 
                                <strong><?php echo $serial; ?></strong>
                                <span class="badge <?php echo $device['status'] === 'ONLINE' ? 'bg-success' : 'bg-danger'; ?> ms-2">
                                    <?php echo $device['status']; ?>
                                </span>
                                <span class="badge bg-primary ms-2">
                                    IDPEL Match: <?php echo $matched_idpel !== '' ? $matched_idpel : '-'; ?>
                                </span>
                                <span class="float-end">
                                    <small><i class="fas fa-clock"></i> <?php echo $device['last_inform'] ? date('Y-m-d H:i:s', strtotime($device['last_inform'])) : '-'; ?></small>
                                    <button class="btn btn-sm btn-outline-light ms-2" onclick="toggleRawJson('<?php echo $serial; ?>')">
                                        <i class="fas fa-code"></i> Raw JSON
                                    </button>
                                </span>
                            </div>
                            <div id="rawjson_<?php echo $serial; ?>" class="card-body bg-dark text-light" style="display: none;">
                                <pre style="color: #0f0; font-size: 11px; max-height: 400px; overflow-y: auto;"><?php echo htmlspecialchars(json_encode(json_decode($device['virtual_parameters'] ?? '{}', true), JSON_PRETTY_PRINT)); ?></pre>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover table-sm mb-0">
                                        <thead class="table-secondary">
                                            <tr>
                                                <th style="width: 40%;">Parameter Name</th>
                                                <th style="width: 60%;">Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // Group parameters by category based on prefix
                                            $grouped_params = [];
                                            $device_info_params = [];
                                            
                                            foreach ($all_params as $param_name => $param_value) {
                                                if (strpos($param_name, '_') === 0) {
                                                    // Device info from database
                                                    $device_info_params[$param_name] = $param_value;
                                                } else {
                                                    // Group by first part of dot notation
                                                    $parts = explode('.', $param_name);
                                                    $category = $parts[0];
                                                    
                                                    // Further sub-categorize if nested
                                                    if (count($parts) > 2) {
                                                        $category = $parts[0] . '.' . $parts[1];
                                                    }
                                                    
                                                    if (!isset($grouped_params[$category])) {
                                                        $grouped_params[$category] = [];
                                                    }
                                                    $grouped_params[$category][$param_name] = $param_value;
                                                }
                                            }
                                            
                                            // Sort categories alphabetically
                                            ksort($grouped_params);
                                            
                                            // Display device info first
                                            if (!empty($device_info_params)): 
                                            ?>
                                            <tr class="table-primary">
                                                <td colspan="2"><strong><i class="fas fa-info-circle"></i> Device Information (Database)</strong></td>
                                            </tr>
                                            <?php foreach ($device_info_params as $param_name => $param_value): 
                                                $display_name = substr($param_name, 1); // Remove leading underscore
                                            ?>
                                            <tr>
                                                <td style="padding-left: 20px;">
                                                    <?php echo htmlspecialchars($display_name); ?>
                                                </td>
                                                <td>
                                                    <span class="value-cell"><?php echo htmlspecialchars($param_value); ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <!-- Display grouped parameters by category -->
                                            <?php foreach ($grouped_params as $category => $params): ?>
                                            <tr class="table-secondary">
                                                <td colspan="2">
                                                    <strong><i class="fas fa-folder"></i> <?php echo htmlspecialchars($category); ?></strong>
                                                    <small class="text-muted">(<?php echo count($params); ?> params)</small>
                                                </td>
                                            </tr>
                                            <?php foreach ($params as $param_name => $param_value): 
                                                $is_important = in_array($param_name, $important_params);
                                                
                                                // Shorten parameter name (remove category prefix for display)
                                                $short_name = $param_name;
                                                if (strpos($param_name, $category . '.') === 0) {
                                                    $short_name = substr($param_name, strlen($category) + 1);
                                                }
                                                
                                                // Extract _value if it's a structured object
                                                $display_value = $param_value;
                                                if (is_array($param_value)) {
                                                    if (isset($param_value['_value'])) {
                                                        $display_value = $param_value['_value'];
                                                    } else {
                                                        $display_value = json_encode($param_value);
                                                    }
                                                }
                                                
                                                // Limit value length for display
                                                $display_value = (string)$display_value;
                                                if (strlen($display_value) > 100) {
                                                    $display_value = substr($display_value, 0, 100) . '...';
                                                }
                                            ?>
                                            <tr<?php echo $is_important ? ' class="table-warning"' : ''; ?>>
                                                <td style="padding-left: 30px;">
                                                    <code style="font-size: 11px;"><?php echo htmlspecialchars($short_name); ?></code>
                                                    <?php if ($is_important): ?>
                                                    <i class="fas fa-star text-warning ms-1" title="Important Parameter"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="value-cell" style="font-size: 11px;"><?php echo htmlspecialchars($display_value); ?></span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endforeach; ?>
                                            
                                            <!-- Total count -->
                                            <tr class="table-dark">
                                                <td colspan="2" class="text-center">
                                                    <small>
                                                        <strong><?php echo count($grouped_params); ?></strong> categories | 
                                                        <strong><?php echo count($all_params) - count($device_info_params); ?></strong> extracted | 
                                                        <strong><?php echo count($all_params); ?></strong> total
                                                    </small>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <?php 
                            endif;
                        endforeach;
                        
                        if (!$has_vparams):
                        ?>
                            <div class="alert alert-warning text-center py-4">
                                <i class="fas fa-exclamation-triangle fa-2x mb-2"></i><br>
                                <strong>Tidak ada Virtual Parameters</strong><br>
                                <small>Lakukan Sync dari GenieACS untuk mengambil data Virtual Parameters terbaru.</small>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistics -->
        <?php if (!empty($devices)): ?>
            <div class="row mt-4">
                <div class="col-md-4">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3><?php echo count(array_filter($devices, function($d) { return $d['status'] === 'ONLINE'; })); ?></h3>
                            <p class="mb-0">Perangkat Online</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-danger text-white">
                        <div class="card-body text-center">
                            <h3><?php echo count(array_filter($devices, function($d) { return $d['status'] === 'OFFLINE'; })); ?></h3>
                            <p class="mb-0">Perangkat Offline</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3><?php echo count($devices); ?></h3>
                            <p class="mb-0">Total Perangkat</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <style>
        /* Tab Styling */
        body.app-theme-dark .nav-tabs {
            border-bottom-color: rgba(148,163,184,0.25) !important;
        }
        
        body.app-theme-dark .nav-tabs .nav-link {
            color: #94a3b8 !important;
            border-color: rgba(148,163,184,0.25) !important;
        }
        
        body.app-theme-dark .nav-tabs .nav-link.active {
            color: #e5e7eb !important;
            background-color: rgba(15, 23, 42, 0.5) !important;
            border-color: #6366f1 rgba(148,163,184,0.25) rgba(148,163,184,0.25) !important;
        }
        
        body.app-theme-dark .nav-tabs .nav-link:hover:not(.active) {
            color: #cbd5e1 !important;
        }
        
        /* Status Indicators */
        .status-online {
            color: #22c55e !important;
            font-weight: 500;
        }
        
        .status-offline {
            color: #ef4444 !important;
            font-weight: 500;
        }
        
        body.app-theme-dark .status-online,
        body.app-theme-dark .status-offline {
            text-shadow: 0 0 5px rgba(0,0,0,0.5);
        }
        
        /* Detail Table */
        body.app-theme-dark #devicesDetailTable td {
            border-color: rgba(148,163,184,0.25) !important;
            color: #e5e7eb !important;
        }
        
        body.app-theme-dark #devicesDetailTable code {
            background-color: rgba(15, 23, 42, 0.8) !important;
            color: #60a5fa !important;
        }
        
        body.app-theme-dark #devicesDetailTable .table-group-divider {
            border-top-color: rgba(148,163,184,0.4) !important;
        }
        
        /* Summary Table */
        body.app-theme-dark #devicesTable td,
        body.app-theme-dark #devicesTable th {
            border-color: rgba(148,163,184,0.25) !important;
            color: #e5e7eb !important;
        }
        
        body.app-theme-dark #devicesTable thead {
            background-color: rgba(15, 23, 42, 0.8) !important;
        }
        
        body.app-theme-dark #devicesTable tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.1) !important;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            #devicesDetailTable {
                font-size: 0.875rem;
            }
            
            #devicesDetailTable code {
                word-break: break-all;
            }
        }
        
        /* Credentials Modal Dark Theme */
        body.app-theme-dark .modal-content {
            background-color: #0f172a !important;
            color: #e5e7eb !important;
            border-color: rgba(148, 163, 184, 0.3) !important;
        }
        
        body.app-theme-dark .modal-header {
            border-bottom-color: rgba(148, 163, 184, 0.2) !important;
        }
        
        body.app-theme-dark .modal-footer {
            border-top-color: rgba(148, 163, 184, 0.2) !important;
        }
        
        body.app-theme-dark .form-control {
            background-color: rgba(15, 23, 42, 0.8) !important;
            border-color: rgba(148, 163, 184, 0.3) !important;
            color: #e5e7eb !important;
        }
        
        body.app-theme-dark .form-control:focus {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 0.25rem rgba(99, 102, 241, 0.25) !important;
            background-color: rgba(15, 23, 42, 0.9) !important;
        }
        
        body.app-theme-dark .form-label {
            color: #e5e7eb !important;
        }
        
        /* Debug Info Card */
        body.app-theme-dark #debugInfo {
            background-color: rgba(15, 23, 42, 0.8) !important;
            border-color: rgba(148, 163, 184, 0.3) !important;
            color: #e5e7eb !important;
        }
        
        body.app-theme-dark #debugOutput {
            background-color: rgba(0, 0, 0, 0.7) !important;
            color: #60a5fa !important;
            border: 1px solid rgba(96, 165, 250, 0.3) !important;
            padding: 15px !important;
        }
        
        body.app-theme-dark #debugOutput::selection {
            background-color: rgba(96, 165, 250, 0.3);
            color: #e5e7eb;
        }
        
        /* Virtual Parameters Card Styling */
        body.app-theme-dark #vparams-table .card {
            background-color: #0f172a !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
        }
        
        body.app-theme-dark #vparams-table .card-header {
            background-color: rgba(15, 23, 42, 0.9) !important;
            border-bottom-color: rgba(148, 163, 184, 0.35) !important;
        }
        
        body.app-theme-dark #vparams-table .table td,
        body.app-theme-dark #vparams-table .table th {
            border-color: rgba(148, 163, 184, 0.25) !important;
            color: #e5e7eb !important;
        }
        
        body.app-theme-dark #vparams-table .table-secondary th {
            background-color: rgba(15, 23, 42, 0.7) !important;
            color: #e5e7eb !important;
        }
        
        body.app-theme-dark #vparams-table .table tbody tr:hover {
            background-color: rgba(99, 102, 241, 0.1) !important;
        }
        
        body.app-theme-dark #vparams-table code {
            background-color: rgba(15, 23, 42, 0.8) !important;
            color: #60a5fa !important;
            padding: 2px 6px;
            border-radius: 3px;
        }
        
        body.app-theme-dark #vparams-table .value-cell {
            font-family: 'Courier New', monospace;
            color: #a5f3fc !important;
        }
        
        body.app-theme-dark #vparams-table .table-warning {
            background-color: rgba(251, 191, 36, 0.15) !important;
        }
        
        body.app-theme-dark #vparams-table .table-warning:hover {
            background-color: rgba(251, 191, 36, 0.25) !important;
        }
        
        body.app-theme-dark #vparams-table .text-warning {
            color: #fbbf24 !important;
        }
        
        #vparams-table .value-cell {
            font-weight: 500;
        }
    </style>
    
    <script>
        // Background Auto Sync - Silent Mode
        let autoSyncInterval = null;
        let syncIntervalMinutes = 5; // Fixed 5 minutes interval

        // Initialize background auto sync on page load
        document.addEventListener('DOMContentLoaded', function() {
            const serverId = <?php echo $selected_server['id'] ?? 'null'; ?>;
            if (serverId) {
                startBackgroundSync(serverId);
            }
        });

        function startBackgroundSync(serverId) {
            // Clear any existing interval
            if (autoSyncInterval) {
                clearInterval(autoSyncInterval);
            }
            
            // Start auto sync interval
            autoSyncInterval = setInterval(() => {
                syncDevicesBackground(serverId);
            }, syncIntervalMinutes * 60 * 1000);
            
            console.log('🔄 Background auto-sync started - Interval: ' + syncIntervalMinutes + ' minutes');
        }

        function syncDevicesBackground(serverId) {
            console.log('🔄 Background sync started...');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=sync&server_id=' + serverId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ Background sync completed successfully');
                    location.reload();
                } else {
                    console.log('❌ Background sync failed: ' + data.message);
                }
            })
            .catch(err => {
                console.log('❌ Background sync error: ' + err);
            });
        }
        
        function toggleRawJson(serial) {
            const element = document.getElementById('rawjson_' + serial);
            if (element.style.display === 'none') {
                element.style.display = 'block';
            } else {
                element.style.display = 'none';
            }
        }
    </script>
</body>
</html>
