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

// Get devices and try to map with customers
$mappings = [];
if ($selected_server) {
    $sql = "SELECT d.*, m.pppoe_username, m.customer_id, m.customer_name, m.status as mapping_status
            FROM acs_devices d
            LEFT JOIN acs_pppoe_mapping m ON d.serial_number = m.device_serial
            WHERE d.server_id = " . $selected_server['id'] . "
            ORDER BY d.last_inform DESC";
    
    $result = mysqli_query($conn, $sql);
    
    // Extract function for PPPoE username
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
    
    // Collect all devices first
    $all_devices = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $all_devices[] = $row;
    }
    
    // Build IDPEL list from virtual parameters
    $pppoe_ids = [];
    foreach ($all_devices as $device) {
        $vparams = json_decode($device['virtual_parameters'] ?? '{}', true);
        $pppoe_value = $extractPppoeUsername($vparams);
        if ($pppoe_value !== '') $pppoe_ids[$pppoe_value] = true;
    }
    
    // Get existing IDPEL from pelanggan table with PEMILIK filter
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
    
    // Filter and process devices
    foreach ($all_devices as $row) {
        $vparams = json_decode($row['virtual_parameters'] ?? '{}', true);
        $pppoe_value = $extractPppoeUsername($vparams);
        
        // Only show devices that match pelanggan IDPEL
        if ($pppoe_value !== '' && isset($existing_idpel[$pppoe_value])) {
            // Try to find customer by serial or other method
            $customer_sql = "SELECT IDPEL, NAMA FROM pelanggan WHERE NOMORSERIAL LIKE '%" . mysqli_real_escape_string($conn, $row['serial_number']) . "%'";
            if ($AKSES !== 'ADMIN' && !empty($userlogin)) {
                $customer_sql .= " AND PEMILIK = '" . mysqli_real_escape_string($conn, $userlogin) . "'";
            }
            $customer_sql .= " LIMIT 1";
            $customer_search = mysqli_query($conn, $customer_sql);
            $customer = mysqli_fetch_assoc($customer_search);
            
            $row['found_customer'] = $customer;
            $mappings[] = $row;
        }
    }
}
?>

    <style>
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .match-status {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }
        .match-found {
            background: #28a745;
            color: white;
        }
        .match-not-found {
            background: #dc3545;
            color: white;
        }
        .match-pending {
            background: #ffc107;
            color: black;
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
                <h4><i class="fas fa-exchange-alt"></i> Mapping PPPoE ke Customer</h4>
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
                        <h6><i class="fas fa-info-circle"></i> Tentang Mapping PPPoE</h6>
                        <p class="mb-0">
                            Mapping ini menghubungkan Serial Number ONT dengan data pelanggan di database billing.
                            Status MATCH artinya perangkat ditemukan di database customer. 
                            Status NOT_FOUND artinya serial number belum terdaftar di database customer.
                        </p>
                    </div>

                    <?php if (empty($mappings)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Belum ada data perangkat. Sync data terlebih dahulu dari menu "Perangkat Pelanggan".
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="mappingTable" class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Serial Number</th>
                                        <th>IP Address</th>
                                        <th>Status ONT</th>
                                        <th>ID Pelanggan</th>
                                        <th>Nama Pelanggan</th>
                                        <th>Status Mapping</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mappings as $map): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($map['serial_number']); ?></code></td>
                                            <td><?php echo htmlspecialchars($map['ip_address']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $map['status'] === 'ONLINE' ? 'success' : 'danger'; ?>">
                                                    <?php echo $map['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($map['found_customer']) {
                                                    echo htmlspecialchars($map['found_customer']['IDPEL']);
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($map['found_customer']) {
                                                    echo htmlspecialchars($map['found_customer']['NAMA']);
                                                } else {
                                                    echo '<span class="text-muted">Tidak ditemukan</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="match-status <?php echo $map['found_customer'] ? 'match-found' : 'match-not-found'; ?>">
                                                    <?php echo $map['found_customer'] ? 'MATCH' : 'NOT_FOUND'; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Statistics -->
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="card bg-success text-white">
                                    <div class="card-body text-center">
                                        <h3><?php echo count(array_filter($mappings, fn($m) => !empty($m['found_customer']))); ?></h3>
                                        <p class="mb-0">Match</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-danger text-white">
                                    <div class="card-body text-center">
                                        <h3><?php echo count(array_filter($mappings, fn($m) => empty($m['found_customer']))); ?></h3>
                                        <p class="mb-0">Not Found</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-info text-white">
                                    <div class="card-body text-center">
                                        <h3><?php echo count($mappings); ?></h3>
                                        <p class="mb-0">Total Devices</p>
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
            $('#mappingTable').DataTable({
                order: [[5, 'asc']],
                pageLength: 25
            });
        });
    </script>
</body>
</html>
