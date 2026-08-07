<?php
require 'header.php';
require 'acs_helper.php';

// Initialize ACS helper
$acs = new ACSHelper($conn, $USER_ID, $AKSES);
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
    $selected_server = $servers[0];
}

// Get devices with optical power info
$devices = [];
if ($selected_server) {
    $sql = "SELECT * FROM acs_devices WHERE server_id = " . $selected_server['id'] . " ORDER BY last_inform DESC";
    $result = mysqli_query($conn, $sql);
    while ($row = mysqli_fetch_assoc($result)) {
        // Extract RX/TX Power from virtual parameters
        $vparams = json_decode($row['virtual_parameters'] ?? '{}', true);
        
        // Extract RXPower
        $rx_power = null;
        if (isset($vparams['RXPower'])) {
            $rx_val = $vparams['RXPower'];
            if (is_array($rx_val) && isset($rx_val['_value'])) {
                $rx_power = $rx_val['_value'];
            } else {
                $rx_power = $rx_val;
            }
        }
        
        // Extract TXPower
        $tx_power = null;
        if (isset($vparams['TXPower'])) {
            $tx_val = $vparams['TXPower'];
            if (is_array($tx_val) && isset($tx_val['_value'])) {
                $tx_power = $tx_val['_value'];
            } else {
                $tx_power = $tx_val;
            }
        }
        
        // Extract pppoeIP
        $pppoe_ip = null;
        if (isset($vparams['pppoeIP'])) {
            $ip_val = $vparams['pppoeIP'];
            if (is_array($ip_val) && isset($ip_val['_value'])) {
                $pppoe_ip = $ip_val['_value'];
            } else {
                $pppoe_ip = $ip_val;
            }
        }
        
        // Determine signal quality based on RX Power
        $rx_status = 'Unknown';
        if ($rx_power !== null && is_numeric($rx_power)) {
            $rx_float = floatval($rx_power);
            if ($rx_float > -20) {
                $rx_status = 'Normal';
            } elseif ($rx_float > -28) {
                $rx_status = 'Warning';
            } else {
                $rx_status = 'Critical';
            }
        }
        
        $row['RXPower'] = $rx_power;
        $row['TXPower'] = $tx_power;
        $row['pppoeIP'] = $pppoe_ip;
        $row['rx_status'] = $rx_status;
        $devices[] = $row;
    }
}

// Filter devices: show only devices whose virtual parameter pppoeUsername/pppoeUsername2 exists in pelanggan.IDPEL
$filtered_devices = [];
if (!empty($devices)) {
    $extractPppoeUsername = function ($vparams) {
        if (!is_array($vparams)) return '';
        $candidates = [];
        if (isset($vparams['pppoeUsername'])) $candidates[] = $vparams['pppoeUsername'];
        if (isset($vparams['pppoeUsername2'])) $candidates[] = $vparams['pppoeUsername2'];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['_value'])) $candidate = $candidate['_value'];
            $candidate = trim((string)$candidate);
            if ($candidate !== '') return $candidate;
        }
        return '';
    };

    $pppoe_ids = [];
    foreach ($devices as $device) {
        $vparams = json_decode($device['virtual_parameters'] ?? '{}', true);
        $pppoe_value = $extractPppoeUsername($vparams);
        if ($pppoe_value !== '') $pppoe_ids[$pppoe_value] = true;
    }

    $existing_idpel = [];
    if (!empty($pppoe_ids)) {
        $escaped_ids = [];
        foreach (array_keys($pppoe_ids) as $idpel) {
            $escaped_ids[] = "'" . mysqli_real_escape_string($conn, $idpel) . "'";
        }
        $pelanggan_sql = "SELECT IDPEL FROM pelanggan WHERE IDPEL IN (" . implode(',', $escaped_ids) . ")";
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
        .signal-normal { color: #28a745; font-weight: bold; }
        .signal-warning { color: #ffc107; font-weight: bold; }
        .signal-critical { color: #dc3545; font-weight: bold; }
        .power-value {
            font-family: monospace;
            font-size: 14px;
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
        body.app-theme-dark .form-control,
        body.app-theme-dark .form-select,
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
        body.app-theme-dark .form-control,
        body.app-theme-dark .form-select {
            background: #111827 !important;
            border-color: rgba(148, 163, 184, 0.35) !important;
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
                <h4><i class="fas fa-signal"></i> Redaman Pelanggan (Optical Power)</h4>
            </div>
            <div class="card-body">
                <?php if (empty($servers)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Tidak ada server ACS yang tersedia.
                    </div>
                <?php else: ?>
                    <div class="row mb-3">
                        <div class="col-md-8">
                            <label class="form-label"><strong>Pilih Server:</strong></label>
                            <select class="form-select" onchange="location.href='?server_id=' + this.value">
                                <?php foreach ($servers as $srv): ?>
                                    <option value="<?php echo $srv['id']; ?>" <?php echo ($selected_server && $srv['id'] == $selected_server['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($srv['nama_server']); ?> (Port: <?php echo $srv['port']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> Tentang Redaman Fiber</h6>
                        <p class="mb-0">
                            <strong>RX Power:</strong> Kekuatan sinyal yang DITERIMA oleh ONT dari OLT<br>
                            <strong>TX Power:</strong> Kekuatan sinyal yang DIKIRIM oleh ONT ke OLT<br>
                            <strong>Status:</strong> 
                            <span class="signal-normal">Normal</span> (> -20 dBm), 
                            <span class="signal-warning">Warning</span> (-20 sampai -28 dBm), 
                            <span class="signal-critical">Critical</span> (< -28 dBm)
                        </p>
                    </div>

                    <?php if (empty($devices)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Belum ada data perangkat. Sync data terlebih dahulu dari menu "Perangkat Pelanggan".
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="opticalTable" class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Serial Number</th>
                                        <th>IP Address</th>
                                        <th>Status ONT</th>
                                        <th>RX Power (dBm)</th>
                                        <th>TX Power (dBm)</th>
                                        <th>Signal Status</th>
                                        <th>Last Inform</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($devices as $device): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($device['serial_number']); ?></code></td>
                                            <td><?php echo !empty($device['pppoeIP']) ? htmlspecialchars($device['pppoeIP']) : '<span class="text-muted">N/A</span>'; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $device['status'] === 'ONLINE' ? 'success' : 'danger'; ?>">
                                                    <?php echo $device['status']; ?>
                                                </span>
                                            </td>
                                            <td class="power-value">
                                                <?php echo $device['RXPower'] !== null ? $device['RXPower'] . ' dBm' : '<span class="text-muted">N/A</span>'; ?>
                                            </td>
                                            <td class="power-value">
                                                <?php echo $device['TXPower'] !== null ? $device['TXPower'] . ' dBm' : '<span class="text-muted">N/A</span>'; ?>
                                            </td>
                                            <td>
                                                <span class="signal-<?php echo strtolower($device['rx_status']); ?>">
                                                    <i class="fas fa-circle"></i> <?php echo $device['rx_status']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $device['last_inform'] ? date('Y-m-d H:i', strtotime($device['last_inform'])) : '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Statistics -->
                        <div class="row mt-4">
                            <div class="col-md-3">
                                <div class="card bg-success text-white">
                                    <div class="card-body text-center">
                                        <h3><?php echo count(array_filter($devices, fn($d) => $d['rx_status'] === 'Normal')); ?></h3>
                                        <p class="mb-0">Normal</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-warning text-dark">
                                    <div class="card-body text-center">
                                        <h3><?php echo count(array_filter($devices, fn($d) => $d['rx_status'] === 'Warning')); ?></h3>
                                        <p class="mb-0">Warning</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-danger text-white">
                                    <div class="card-body text-center">
                                        <h3><?php echo count(array_filter($devices, fn($d) => $d['rx_status'] === 'Critical')); ?></h3>
                                        <p class="mb-0">Critical</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card bg-secondary text-white">
                                    <div class="card-body text-center">
                                        <h3><?php echo count(array_filter($devices, fn($d) => $d['rx_status'] === 'Unknown')); ?></h3>
                                        <p class="mb-0">Unknown</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#opticalTable').DataTable({
                order: [[6, 'desc']],
                pageLength: 25
            });
        });
    </script>
</body>
</html>
