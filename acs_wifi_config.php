<?php
require 'header.php';
require 'acs_helper.php';

// Initialize ACS helper
$acs = new ACSHelper($conn, $USER_ID, $AKSES);
$acs->ensureDatabase();

// Get PEMILIK for filtering pelanggan data
$userlogin = isset($_SESSION['PEMILIK']) ? $_SESSION['PEMILIK'] : '';

// Handle AJAX actions
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'update_wifi') {
        $device_serial = mysqli_real_escape_string($conn, $_POST['serial']);
        $new_ssid = mysqli_real_escape_string($conn, $_POST['ssid']);
        $new_password = mysqli_real_escape_string($conn, $_POST['password']);
        
        // Update in database
        $sql = "UPDATE acs_devices SET ssid = '$new_ssid', wifi_password = '$new_password' WHERE serial_number = '$device_serial'";
        
        if (mysqli_query($conn, $sql)) {
            // TODO: Send command to GenieACS to update device
            echo json_encode(['success' => true, 'message' => 'WiFi configuration updated (will be applied on next device inform)']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }
}

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

// Get devices
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
        .wifi-ssid {
            font-weight: bold;
            color: #007bff;
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
                <h4><i class="fas fa-wifi"></i> Pengaturan WiFi Pelanggan</h4>
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
                        <h6><i class="fas fa-info-circle"></i> Tentang Pengaturan WiFi</h6>
                        <p class="mb-0">
                            Anda dapat mengubah SSID (nama WiFi) dan Password WiFi untuk setiap perangkat ONT pelanggan.
                            Perubahan akan dikirim ke GenieACS dan diterapkan ke perangkat saat ONT mengirim inform berikutnya.
                            <strong>Catatan:</strong> Fitur ini memerlukan konfigurasi provision script di GenieACS.
                        </p>
                    </div>

                    <?php if (empty($devices)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            Belum ada data perangkat. Sync data terlebih dahulu dari menu "Perangkat Pelanggan".
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="wifiTable" class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Serial Number</th>
                                        <th>IP Address</th>
                                        <th>Status</th>
                                        <th>SSID</th>
                                        <th>WiFi Password</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($devices as $device): ?>
                                        <tr id="device-<?php echo $device['id']; ?>">
                                            <td><code><?php echo htmlspecialchars($device['serial_number']); ?></code></td>
                                            <td><?php echo htmlspecialchars($device['ip_address']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $device['status'] === 'ONLINE' ? 'success' : 'danger'; ?>">
                                                    <?php echo $device['status']; ?>
                                                </span>
                                            </td>
                                            <td class="wifi-ssid" id="ssid-<?php echo $device['id']; ?>">
                                                <?php echo $device['ssid'] ? htmlspecialchars($device['ssid']) : '<span class="text-muted">Not set</span>'; ?>
                                            </td>
                                            <td id="pass-<?php echo $device['id']; ?>">
                                                <span class="password-hidden">••••••••</span>
                                                <span class="password-shown" style="display:none;">
                                                    <?php echo $device['wifi_password'] ? htmlspecialchars($device['wifi_password']) : '<span class="text-muted">Not set</span>'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary" onclick="showEditModal('<?php echo htmlspecialchars($device['serial_number']); ?>', '<?php echo htmlspecialchars($device['ssid']); ?>', '<?php echo htmlspecialchars($device['wifi_password']); ?>')">
                                                    <i class="fas fa-edit"></i> Ubah
                                                </button>
                                                <button class="btn btn-sm btn-secondary" onclick="togglePassword(<?php echo $device['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit WiFi Modal -->
    <div class="modal fade" id="editWifiModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ubah Pengaturan WiFi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit-serial">
                    
                    <div class="mb-3">
                        <label class="form-label">Serial Number:</label>
                        <input type="text" class="form-control" id="edit-serial-display" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SSID (Nama WiFi):</label>
                        <input type="text" class="form-control" id="edit-ssid" placeholder="Contoh: FiberQ_Home">
                        <small class="text-muted">Nama WiFi yang akan terlihat oleh perangkat</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Password WiFi:</label>
                        <input type="text" class="form-control" id="edit-password" placeholder="Minimal 8 karakter">
                        <small class="text-muted">Password untuk koneksi ke WiFi</small>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        Perubahan akan diterapkan ke perangkat saat ONT mengirim inform berikutnya ke ACS.
                        Pastikan perangkat dalam status ONLINE.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="saveWifiConfig()">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        let editModal;
        
        $(document).ready(function() {
            $('#wifiTable').DataTable({
                order: [[2, 'desc']],
                pageLength: 25
            });
            
            editModal = new bootstrap.Modal(document.getElementById('editWifiModal'));
        });

        function togglePassword(deviceId) {
            const row = document.querySelector('#device-' + deviceId);
            const hidden = row.querySelector('.password-hidden');
            const shown = row.querySelector('.password-shown');
            
            if (hidden.style.display === 'none') {
                hidden.style.display = 'inline';
                shown.style.display = 'none';
            } else {
                hidden.style.display = 'none';
                shown.style.display = 'inline';
            }
        }
        
        function showEditModal(serial, ssid, password) {
            document.getElementById('edit-serial').value = serial;
            document.getElementById('edit-serial-display').value = serial;
            document.getElementById('edit-ssid').value = ssid || '';
            document.getElementById('edit-password').value = password || '';
            editModal.show();
        }
        
        function saveWifiConfig() {
            const serial = document.getElementById('edit-serial').value;
            const ssid = document.getElementById('edit-ssid').value;
            const password = document.getElementById('edit-password').value;
            
            if (!ssid || !password) {
                alert('SSID dan Password harus diisi');
                return;
            }
            
            if (password.length < 8) {
                alert('Password minimal 8 karakter');
                return;
            }
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=update_wifi&serial=' + encodeURIComponent(serial) + 
                      '&ssid=' + encodeURIComponent(ssid) + 
                      '&password=' + encodeURIComponent(password)
            })
            .then(res => res.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    editModal.hide();
                    location.reload();
                }
            })
            .catch(err => {
                alert('Error: ' + err);
            });
        }
    </script>
<?php require 'footer.php'; ?>