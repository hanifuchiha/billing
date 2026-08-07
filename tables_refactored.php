<?php 
/**
 * BILLING TABLES - REFACTORED STRUCTURE
 * Structure: PHP Logic → Data Processing → HTML Display → JavaScript
 */

require 'header.php';

// ============================================================================
// PART 1: PHP LOGIC & DATABASE QUERIES
// ============================================================================

// Initialize variables
$sql1 = '';
$query1 = null;
$displayed_total = 0;
$hasDiskonTable = false;
$hasBiayaTable = false;
$hasAcsDevicesTable = false;

// Get session user info
$current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$AKSES = $_SESSION['ROLE'] ?? 'USER';

// Log form submission
$selected_server = mysqli_real_escape_string($conn, $_POST['server'] ?? '');
$selected_area   = mysqli_real_escape_string($conn, $_POST['area'] ?? '');

// ============================================================================
// QUERY 1: BUILD MAIN CUSTOMER QUERY
// ============================================================================
if (!empty($selected_server) && !empty($selected_area)) {
    if ($selected_server !== 'ALL') {
        $sql1 = "SELECT * FROM `pelanggan` 
                WHERE `pemilik` = '$selected_server' 
                AND `AREA` = '$selected_area' 
                ORDER BY `IDPEL`";
    } else {
        $sql1 = "SELECT * FROM `pelanggan` 
                WHERE `AREA` = '$selected_area' 
                ORDER BY `IDPEL`";
    }
    
    // Execute main customer query
    $query1 = mysqli_query($conn, $sql1);
    if (!$query1) {
        $query_error = "Query gagal: " . mysqli_error($conn);
    }
} 

// ============================================================================
// QUERY 2: CHECK TABLE EXISTENCE
// ============================================================================
$checkDiskonTable = mysqli_query($conn, "SHOW TABLES LIKE 'diskon_pelanggan'");
$hasDiskonTable = ($checkDiskonTable && mysqli_num_rows($checkDiskonTable) > 0);

$checkBiayaTable = mysqli_query($conn, "SHOW TABLES LIKE 'biaya_tambahan_pelanggan'");
$hasBiayaTable = ($checkBiayaTable && mysqli_num_rows($checkBiayaTable) > 0);

$checkAcsDevicesTable = mysqli_query($conn, "SHOW TABLES LIKE 'acs_devices'");
$hasAcsDevicesTable = ($checkAcsDevicesTable && mysqli_num_rows($checkAcsDevicesTable) > 0);

// ============================================================================
// QUERY 3: GET PERIOD LABEL
// ============================================================================
$bulanNamaPeriode = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];
$periodeAktifLabel = $bulanNamaPeriode[(int)date('n')] . ' ' . date('Y');

// ============================================================================
// QUERY 4: COLLECT SERVER CREDENTIALS FOR EACH CUSTOMER
// ============================================================================
$customerData = [];
if ($query1) {
    $displayed_total = mysqli_num_rows($query1);
    
    while ($customer = mysqli_fetch_array($query1)) {
        $pemilik = $customer['PEMILIK'];
        $area = $customer['AREA'];
        
        // Query server info
        $sqlServer = "SELECT * FROM `server` 
                     WHERE `AREA` = '$area' 
                     AND `PEMILIK` = '$pemilik'";
        $queryServer = mysqli_query($conn, $sqlServer);
        
        if ($queryServer && mysqli_num_rows($queryServer) > 0) {
            $serverData = mysqli_fetch_array($queryServer);
            
            // Combine customer + server data
            $customer['server_ip'] = $serverData['IP'];
            $customer['server_user'] = $serverData['PEMILIK'];
            $customer['server_password'] = $serverData['PASSWORD'];
            $customer['server_brand'] = $serverData['BRAND'] ?? '';
        }
        
        // Query diskon if table exists
        if ($hasDiskonTable) {
            $idpelEsc = mysqli_real_escape_string($conn, $customer['IDPEL']);
            $sqlDiskon = "SELECT MODE, NOMINAL_TYPE, NOMINAL, KETERANGAN
                         FROM diskon_pelanggan 
                         WHERE IDPEL = '$idpelEsc' 
                         LIMIT 3";
            $resDiskon = mysqli_query($conn, $sqlDiskon);
            $customer['diskon'] = [];
            while ($row = mysqli_fetch_assoc($resDiskon)) {
                $customer['diskon'][] = $row;
            }
        }
        
        // Query biaya tambahan if table exists
        if ($hasBiayaTable) {
            $idpelEsc = mysqli_real_escape_string($conn, $customer['IDPEL']);
            $sqlBiaya = "SELECT MODE, NOMINAL_TYPE, NOMINAL, KETERANGAN
                        FROM biaya_tambahan_pelanggan 
                        WHERE IDPEL = '$idpelEsc' 
                        LIMIT 3";
            $resBiaya = mysqli_query($conn, $sqlBiaya);
            $customer['biaya'] = [];
            while ($row = mysqli_fetch_assoc($resBiaya)) {
                $customer['biaya'][] = $row;
            }
        }
        
        $customerData[] = $customer;
    }
    
    // Reset query pointer untuk looping HTML
    mysqli_data_seek($query1, 0);
}

?>

<!-- ============================================================================
     PART 2: CSS STYLES (Consolidated)
     ============================================================================ -->
<style>
    body {
        margin: 0;
        padding: 0;
        font-family: Arial, sans-serif;
    }

    #loading {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: radial-gradient(circle at 15% 20%, rgba(var(--bs-primary-rgb, 37, 99, 235), 0.32) 0%, transparent 45%),
                    radial-gradient(circle at 85% 80%, rgba(59, 130, 246, 0.22) 0%, transparent 45%),
                    linear-gradient(135deg, var(--bs-body-bg, #0b1220) 0%, #0f172a 55%, #111827 100%);
        background-size: 200% 200%, 200% 200%, 100% 100%;
        animation: gradientShift 8s ease infinite;
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        opacity: 1;
        transition: opacity 0.8s ease-out, visibility 0.8s ease-out;
    }

    @keyframes gradientShift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    #loading.fade-out {
        opacity: 0;
        visibility: hidden;
    }

    .loading-content {
        text-align: center;
        color: var(--bs-body-color, #f8fafc);
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    }

    .spinner {
        width: 60px;
        height: 60px;
        border: 4px solid rgba(var(--bs-primary-rgb, 37, 99, 235), 0.25);
        border-top-color: var(--logo-secondary, #3b82f6);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
        box-shadow: 0 0 20px rgba(var(--bs-primary-rgb, 37, 99, 235), 0.3);
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .loading-text {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.45);
        animation: pulse 2s ease-in-out infinite;
        letter-spacing: 0.5px;
    }

    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.9; transform: scale(1.02); }
    }

    .modal-dialog {
        width: 90vw !important;
        max-width: none !important;
        margin: auto;
    }

    .modal-content {
        flex-direction: column !important;
        display: flex;
        width: 100%;
        max-height: 90vh;
        overflow: hidden;
        background: linear-gradient(to bottom, #ffffff, #e0e0e0, #ffffff);
        border-radius: 10px;
        border: 2px solid #d0d0d0;
        box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);
        font-size: inherit;
    }

    .modal-body {
        overflow-y: auto;
        flex: 1;
        min-height: 0;
        padding: 15px;
    }

    .user-card {
        margin-bottom: 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        background: #fff;
    }

    .user-card h6 {
        margin-bottom: 10px;
        color: #333;
    }

    .user-card p {
        margin: 5px 0;
        font-size: 14px;
    }

    .customer-action-stack {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-top: 6px;
        width: 130px;
    }

    .customer-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 130px;
        min-height: 30px;
        padding: 0.3rem 0.5rem;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.2;
        text-align: center;
    }

    .qts-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(2, 6, 23, 0.62);
        backdrop-filter: blur(3px);
        z-index: 10000;
        justify-content: center;
        align-items: center;
        padding: 16px;
    }

    .qts-modal-card {
        width: 420px;
        max-width: 95%;
        background: var(--bs-body-bg, #111827);
        color: var(--bs-body-color, #e5e7eb);
        border: 1px solid rgba(var(--bs-primary-rgb, 37, 99, 235), 0.35);
        border-radius: 12px;
        box-shadow: 0 16px 35px rgba(15, 23, 42, 0.45);
        padding: 20px;
        position: relative;
    }
</style>

<!-- ============================================================================
     PART 3: HTML DISPLAY - STATUS MESSAGES
     ============================================================================ -->
<div class="container-fluid py-4 bg-white">
    <div class="row">
        <!-- Success Messages -->
        <?php if (isset($_GET['pesan']) && $_GET['pesan'] === 'berhasil'): ?>
            <?php 
                $raw_success_text = trim(urldecode($_GET['text'] ?? ''));
                $is_delete_success = (bool) preg_match('/^success\s+delete\b/i', $raw_success_text);
            ?>
            
            <?php if ($is_delete_success): ?>
                <div class="col-12">
                    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
                        Success dismantle pelanggan
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-12">
                    <div id="regSuccessCard" class="alert alert-success border mb-3 p-3">
                        <!-- Registration success details -->
                        <p>✅ Registrasi perangkat berhasil</p>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Query Error Messages -->
        <?php if (isset($query_error)): ?>
            <div class="col-12">
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error:</strong> <?= htmlspecialchars($query_error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        <?php endif; ?>

        <!-- No Filter Selected -->
        <?php if (empty($selected_server) || empty($selected_area)): ?>
            <div class="col-12">
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <strong>Peringatan!</strong> Belum tentukan mana yang ditampilkan.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Filter Form -->
    <div class="row my-4">
        <div class="col-12">
            <form method="POST" class="mb-2" id="form-filter-pelanggan">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <!-- Server Filter -->
                            <div class="col-md-6 mb-3">
                                <label for="server" class="form-label">Pilih Server Area</label>
                                <select id="server" name="server" class="form-select" required>
                                    <option value="">-- Pilih --</option>
                                    <option value="ALL">ALL SERVER</option>
                                    <?php 
                                        // Query distinct servers
                                        $queryServers = mysqli_query($conn, 
                                            "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server ORDER BY PEMILIK");
                                        while ($row = mysqli_fetch_assoc($queryServers)):
                                    ?>
                                        <option value="<?= htmlspecialchars($row['PEMILIK']); ?>">
                                            <?= htmlspecialchars($row['PEMILIK'] . ' - ' . $row['BRAND']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <!-- Area Filter -->
                            <div class="col-md-6 mb-3">
                                <label for="area" class="form-label">Pilih Area</label>
                                <select id="area" name="area" class="form-select" required>
                                    <option value="">-- Pilih --</option>
                                    <?php 
                                        $queryArea = mysqli_query($conn, 
                                            "SELECT DISTINCT AREA FROM server ORDER BY AREA");
                                        while ($row = mysqli_fetch_assoc($queryArea)):
                                    ?>
                                        <option value="<?= htmlspecialchars($row['AREA']); ?>">
                                            <?= htmlspecialchars($row['AREA']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <!-- ODP Filter -->
                            <div class="col-md-6 mb-3">
                                <label for="odp" class="form-label">Filter ODP</label>
                                <input type="text" id="odp" name="odp" class="form-control" placeholder="Cari ODP...">
                            </div>

                            <!-- Paket Filter -->
                            <div class="col-md-6 mb-3">
                                <label for="paket" class="form-label">Filter Paket</label>
                                <select id="paket" name="paket" class="form-select">
                                    <option value="">-- Semua --</option>
                                    <?php 
                                        $queryPaket = mysqli_query($conn, 
                                            "SELECT DISTINCT PAKET FROM paket ORDER BY PAKET");
                                        while ($row = mysqli_fetch_assoc($queryPaket)):
                                    ?>
                                        <option value="<?= htmlspecialchars($row['PAKET']); ?>">
                                            <?= htmlspecialchars($row['PAKET']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-search"></i> Tampilkan Data
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Query Results Summary -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0">📊 Hasil Query Database</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h4 class="text-primary"><?= $displayed_total; ?></h4>
                            <p class="text-muted mb-0">Total Pelanggan</p>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-info"><?= date('Y-m-d H:i:s'); ?></h4>
                            <p class="text-muted mb-0">Waktu Query</p>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-success"><?= $periodeAktifLabel; ?></h4>
                            <p class="text-muted mb-0">Periode Aktif</p>
                        </div>
                        <div class="col-md-3">
                            <h4 class="text-warning"><?= $hasDiskonTable ? '✓' : '✗'; ?></h4>
                            <p class="text-muted mb-0">Tabel Diskon</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Data Table -->
    <?php if ($query1 && $displayed_total > 0): ?>
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0">📋 Daftar Pelanggan (<?= $displayed_total; ?>)</h6>
                </div>
                <div class="card-body" style="overflow-x: auto;">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">No</th>
                                <th>IDPEL</th>
                                <th>Nama Pelanggan</th>
                                <th>Area</th>
                                <th>ODP</th>
                                <th>Paket</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                                $nomor = 0;
                                while ($customer = mysqli_fetch_array($query1)): 
                                    $nomor++;
                            ?>
                            <tr>
                                <td><?= $nomor; ?></td>
                                <td><code><?= htmlspecialchars($customer['IDPEL']); ?></code></td>
                                <td><?= htmlspecialchars($customer['NAMA'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($customer['AREA'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($customer['ODP'] ?? '-'); ?></td>
                                <td><?= htmlspecialchars($customer['PAKET'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge bg-secondary">Pending</span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info fetch-customer-data" 
                                            data-idpel="<?= htmlspecialchars($customer['IDPEL']); ?>"
                                            data-ip="<?= htmlspecialchars($customer['server_ip'] ?? ''); ?>"
                                            data-user="<?= htmlspecialchars($customer['server_user'] ?? ''); ?>"
                                            data-pass="<?= htmlspecialchars($customer['server_password'] ?? ''); ?>">
                                        Refresh
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ============================================================================
     PART 4: JAVASCRIPT - Data Fetching
     ============================================================================ -->
<script>
// Fetch customer data when button clicked
document.querySelectorAll('.fetch-customer-data').forEach(btn => {
    btn.addEventListener('click', function() {
        const idpel = this.getAttribute('data-idpel');
        const ip = this.getAttribute('data-ip');
        const user = this.getAttribute('data-user');
        const pass = this.getAttribute('data-pass');
        
        console.log('Fetching data for:', { idpel, ip, user });
        
        fetchCustomerData(idpel, ip, user, pass);
    });
});

// Fetch customer online status
async function fetchCustomerData(idpel, ipServer, userServer, passwordServer) {
    try {
        const response = await fetch('getdata/getonlinecustomer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 
                ip: ipServer, 
                idpel: idpel, 
                us: userServer, 
                ps: passwordServer 
            })
        });
        
        if (!response.ok) {
            console.error('Response error:', response.status);
            return;
        }
        
        const data = await response.json();
        console.log('Customer data received:', data);
        
        // Update UI with status
        updateCustomerStatus(idpel, data);
        
    } catch (error) {
        console.error('Fetch error:', error);
    }
}

function updateCustomerStatus(idpel, data) {
    // Find the row for this customer
    const btn = document.querySelector(`[data-idpel="${idpel}"]`);
    if (!btn) return;
    
    const row = btn.closest('tr');
    if (!row) return;
    
    // Update status badge
    let statusBadge = 'bg-secondary';
    let statusText = 'Unknown';
    
    if (data.status === 'Online') {
        statusBadge = 'bg-success';
        statusText = '🟢 Online';
    } else {
        statusBadge = 'bg-danger';
        statusText = '🔴 Offline (LOS)';
    }
    
    const statusCell = row.querySelector('td:nth-child(7)');
    if (statusCell) {
        statusCell.innerHTML = `<span class="badge ${statusBadge}">${statusText}</span>`;
    }
}

// Auto refresh every 30 seconds
setInterval(() => {
    document.querySelectorAll('.fetch-customer-data').forEach(btn => {
        const idpel = btn.getAttribute('data-idpel');
        const ip = btn.getAttribute('data-ip');
        const user = btn.getAttribute('data-user');
        const pass = btn.getAttribute('data-pass');
        
        fetchCustomerData(idpel, ip, user, pass);
    });
}, 30000);
</script>

<?php require 'footer.php'; ?>
