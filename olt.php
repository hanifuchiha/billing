<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('OLT', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu OLT.</div></div>';
        require 'footer.php';
        exit;
    }
}
 
require('routeros_api.class.php');
$API = new RouterosAPI();

// Auto alter table for new columns
$columns_to_add = [
    'community_read' => 'VARCHAR(255) DEFAULT NULL',
    'community_write' => 'VARCHAR(255) DEFAULT NULL'
];
foreach ($columns_to_add as $col => $type) {
    $result = mysqli_query($conn, "SHOW COLUMNS FROM olt LIKE '$col'");
    if (mysqli_num_rows($result) == 0) {
        mysqli_query($conn, "ALTER TABLE olt ADD COLUMN $col $type");
    }
}

    $createTemplateTableSql = "CREATE TABLE IF NOT EXISTS olt_input_templates (
      olt_id INT NOT NULL PRIMARY KEY,
      auth_mode VARCHAR(50) DEFAULT NULL,
      customer_name VARCHAR(200) DEFAULT NULL,
      address TEXT DEFAULT NULL,
      provinsi VARCHAR(120) DEFAULT NULL,
      kabupaten VARCHAR(120) DEFAULT NULL,
      kecamatan VARCHAR(120) DEFAULT NULL,
      kelurahan VARCHAR(120) DEFAULT NULL,
      rw VARCHAR(10) DEFAULT NULL,
      rt VARCHAR(10) DEFAULT NULL,
      whatsapp VARCHAR(50) DEFAULT NULL,
      email VARCHAR(200) DEFAULT NULL,
      coordinates VARCHAR(120) DEFAULT NULL,
      sales VARCHAR(120) DEFAULT NULL,
      tipe_bayar VARCHAR(30) DEFAULT NULL,
      tipe_tempo VARCHAR(60) DEFAULT NULL,
      tanggal_pasang DATE DEFAULT NULL,
      package_name VARCHAR(120) DEFAULT NULL,
      odp VARCHAR(120) DEFAULT NULL,
      auto_register_enabled TINYINT(1) DEFAULT 1,
      with_wan_config TINYINT(1) DEFAULT 1,
      ont_type VARCHAR(120) DEFAULT NULL,
      ont_interface VARCHAR(80) DEFAULT NULL,
      onu_number VARCHAR(20) DEFAULT NULL,
      ont_sn VARCHAR(120) DEFAULT NULL,
      tcont_profile VARCHAR(120) DEFAULT NULL,
      vlan_id VARCHAR(20) DEFAULT NULL,
      service_name VARCHAR(60) DEFAULT NULL,
      vlan_profile VARCHAR(60) DEFAULT NULL,
      gemport VARCHAR(20) DEFAULT NULL,
      cos VARCHAR(20) DEFAULT NULL,
      ethuni VARCHAR(50) DEFAULT NULL,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      updated_by VARCHAR(120) DEFAULT NULL,
      CONSTRAINT fk_olt_input_templates_olt FOREIGN KEY (olt_id) REFERENCES olt(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $createTemplateTableSql);

?>


<?php

// Proses simpan template input OLT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form_action'] ?? '') === 'save_olt_template')) {
  $template_olt_id = (int)($_POST['template_olt_id'] ?? 0);

  if ($template_olt_id <= 0) {
    echo "<script>alert('OLT wajib dipilih untuk template!'); window.location='olt.php?tab=template';</script>";
  } else {
    $ont_type = trim($_POST['ont_type'] ?? '');
    $tcont_profile = trim($_POST['tcont_profile'] ?? '');
    $vlan_manual = trim($_POST['vlan_manual'] ?? '');
    $vlan_id = trim($_POST['vlan_id'] ?? '');
    $service_name = trim($_POST['service_name'] ?? '');
    $vlan_profile = trim($_POST['vlan_profile'] ?? '');
    $gemport = trim($_POST['gemport'] ?? '');
    $cos = trim($_POST['cos'] ?? '');
    $ethuni = trim($_POST['ethuni'] ?? '');
    $updated_by = !empty($asistant_name) ? $asistant_name : (string)($ceknama ?? 'system');

    $checkStmt = $conn->prepare("SELECT id FROM olt WHERE id = ? LIMIT 1");
    $checkStmt->bind_param('i', $template_olt_id);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();
    $existsOlt = $checkRes && $checkRes->num_rows > 0;
    $checkStmt->close();

    if (!$existsOlt) {
      echo "<script>alert('OLT tidak ditemukan!'); window.location='olt.php?tab=template';</script>";
    } else {
      $esc = function ($value) use ($conn) {
        if ($value === null) {
          return 'NULL';
        }
        return "'" . mysqli_real_escape_string($conn, (string)$value) . "'";
      };

      $runSql = "INSERT INTO olt_input_templates (
        olt_id,
        ont_type,
        tcont_profile, vlan_id, service_name, vlan_profile,
        gemport, cos, ethuni, updated_by, ont_sn
      ) VALUES (
        " . (int)$template_olt_id . ",
        " . $esc($ont_type) . ",
        " . $esc($tcont_profile) . ",
        " . $esc($vlan_manual !== '' ? $vlan_manual : $vlan_id) . ",
        " . $esc($service_name) . ",
        " . $esc($vlan_profile) . ",
        " . $esc($gemport) . ",
        " . $esc($cos) . ",
        " . $esc($ethuni) . ",
        " . $esc($updated_by) . ",
        " . $esc($vlan_manual) . "
      ) ON DUPLICATE KEY UPDATE
        ont_type = VALUES(ont_type),
        tcont_profile = VALUES(tcont_profile),
        vlan_id = VALUES(vlan_id),
        service_name = VALUES(service_name),
        vlan_profile = VALUES(vlan_profile),
        gemport = VALUES(gemport),
        cos = VALUES(cos),
        ethuni = VALUES(ethuni),
        ont_sn = VALUES(ont_sn),
        updated_by = VALUES(updated_by),
        updated_at = CURRENT_TIMESTAMP";

      if (mysqli_query($conn, $runSql)) {
        echo "<script>alert('Template input OLT berhasil disimpan!'); window.location='olt.php?tab=template';</script>";
      } else {
        echo "<script>alert('Gagal menyimpan template: " . addslashes(mysqli_error($conn)) . "'); window.location='olt.php?tab=template';</script>";
      }
    }
  }
}

// Proses hapus template input OLT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['form_action'] ?? '') === 'delete_olt_template')) {
  $template_olt_id = (int)($_POST['template_olt_id'] ?? 0);
  if ($template_olt_id <= 0) {
    echo "<script>alert('Template OLT tidak valid!'); window.location='olt.php?tab=template';</script>";
    exit;
  }

  if ($AKSES != 'ASSISTANT') {
    $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
    $checkAccessSql = "SELECT o.id
      FROM olt o
      WHERE o.id = $template_olt_id
        AND EXISTS (SELECT 1 FROM server s WHERE s.PEMILIK = o.pemilik AND s.user_id = $current_user_id)
      LIMIT 1";
  } else {
    $checkAccessSql = "SELECT id FROM olt WHERE id = $template_olt_id AND area IN ($area_list) LIMIT 1";
  }
  $checkAccessRes = mysqli_query($conn, $checkAccessSql);
  if (!$checkAccessRes || mysqli_num_rows($checkAccessRes) === 0) {
    echo "<script>alert('Anda tidak memiliki akses untuk menghapus template ini.'); window.location='olt.php?tab=template';</script>";
    exit;
  }

  if (mysqli_query($conn, "DELETE FROM olt_input_templates WHERE olt_id = $template_olt_id")) {
    echo "<script>alert('Template berhasil dihapus.'); window.location='olt.php?tab=template';</script>";
  } else {
    echo "<script>alert('Gagal menghapus template: " . addslashes(mysqli_error($conn)) . "'); window.location='olt.php?tab=template';</script>";
  }
  exit;
}

// Proses simpan data OLT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(($_POST['form_action'] ?? ''), ['save_olt_template', 'delete_olt_template'], true)) {
    $ipolt       = trim($_POST['ipolt']);
    $oltname     = trim($_POST['oltname']);
    $usernameolt = trim($_POST['usernameolt']);
    $passwordolt = trim($_POST['passwordolt']);
    $brandolt    = trim($_POST['brandolt']);
    $server      = trim($_POST['server']);
    $area        = trim($_POST['area']);
    $community_read  = trim($_POST['community_read'] ?? '');
    $community_write = trim($_POST['community_write'] ?? '');

    // Validasi sederhana
    if (empty($ipolt) || empty($oltname) || empty($server) || empty($area)) {
        echo "<script>alert('Semua field wajib diisi!');</script>";
    } elseif ($brandolt === 'ZTE GPON C300/320' && false) { // ZTE menggunakan telnet, community tidak diperlukan
    } else {
        // Prepared statement untuk insert (tanpa methodolt)
        $stmt = $conn->prepare("INSERT INTO olt (ipolt, oltname, pemilik, area, usernameolt, passwordolt, brandolt, community_read, community_write) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssss", $ipolt, $oltname, $server, $area, $usernameolt, $passwordolt, $brandolt, $community_read, $community_write);
        if ($stmt->execute()) {
            echo "<script>alert('Data berhasil disimpan!'); window.location='';</script>";
        } else {
            echo "Error: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Proses delete
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    mysqli_query($conn, "DELETE FROM `olt` WHERE id = $delete_id");
    echo "<script>alert('Data berhasil dihapus');window.location='olt.php';</script>";
    exit;
}

// Status notification dari import/export
$statusnotif = isset($_GET['statusnotif']) ? $_GET['statusnotif'] : '';
$statusmessage = isset($_GET['message']) ? $_GET['message'] : '';
$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'template') ? 'template' : 'list';

$oltBrandOptions = [
  'HIOSO EPON HA7302CST' => 'HIOSO EPON HA7302CST 2 PORT (Web Interface Port 80)',
  'HIOSO EPON HA7304' => 'HIOSO EPON HA7304',
  'HIOSO EPON HA7308' => 'HIOSO EPON HA7308',
  'ZTE GPON C300' => 'ZTE GPON C300',
  'ZTE GPON C320' => 'ZTE GPON C320',
  'ZTE GPON C600' => 'ZTE GPON C600',
  'HUAWEI MA5608T' => 'HUAWEI SmartAX MA5608T',
  'HUAWEI MA5680T' => 'HUAWEI SmartAX MA5680T',
  'HUAWEI MA5683T' => 'HUAWEI SmartAX MA5683T',
  'VSOL GPON V1600G0/G1' => 'VSOL GPON V1600G0/G1',
  'VSOL EPON V1600D' => 'VSOL EPON V1600D',
  'HSGQ GPON G02/G04/G08/G16' => 'HSGQ GPON G02/G04/G08/G16',
  'HSGQ EPON E04/E08' => 'HSGQ EPON E04/E08',
  'CDATA GPON FD1601S' => 'CDATA GPON FD1601S',
  'CDATA GPON FD1608S-B1' => 'CDATA GPON FD1608S-B1',
  'CDATA EPON FD1104/FD1208' => 'CDATA EPON FD1104/FD1208'

];
?>

<!-- Status Notification -->
<?php if (!empty($statusnotif)): ?>
<div class="alert alert-<?= ($statusnotif === 'success') ? 'success' : (($statusnotif === 'warning') ? 'warning' : 'danger'); ?> alert-dismissible fade show" role="alert">
  <strong><?= ($statusnotif === 'success') ? '✓ Sukses!' : (($statusnotif === 'warning') ? '⚠ Peringatan!' : '✗ Gagal!'); ?></strong>
  <?= htmlspecialchars($statusmessage); ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Modal Form Tambah OLT -->
<div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dataModalLabel">Tambah Data OLT</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post">
          <div class="mb-3">
            <label for="ipolt" class="form-label">IP + PORT (contoh: 192.168.11.1:80)</label>
            <input type="text" class="form-control" id="ipolt" name="ipolt" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            <small class="form-text text-muted">
              <strong>Info:</strong> Untuk HIOSO EPON 2 Port (HA7302CST), gunakan port 80 (HTTP) untuk akses web interface.
            </small>
          </div>
          <div class="mb-3">
            <label for="oltname" class="form-label">OLT NAME</label>
            <input type="text" class="form-control" id="oltname" name="oltname" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>
          <div class="mb-3">
            <label for="brandolt" class="form-label">Brand OLT</label>
            <select class="form-select" id="brandolt" name="brandolt" required onchange="updatePortInfo()">
              <option value="" disabled selected>Pilih Brand OLT</option>
              <?php foreach ($oltBrandOptions as $brandValue => $brandLabel): ?>
                <option value="<?= htmlspecialchars($brandValue); ?>"><?= htmlspecialchars($brandLabel); ?></option>
              <?php endforeach; ?>
            </select>
            <div id="portInfo" class="alert alert-info mt-2" style="display:none;">
              <i class="fas fa-info-circle"></i> 
              <strong>HIOSO EPON HA7302CST 2 PORT:</strong> 
              Device ini menggunakan web interface dengan port 80. Pastikan format IP adalah: <code>192.168.x.x:80</code>
            </div>
          </div>
          <div class="mb-3">
            <label for="usernameolt" class="form-label">Username OLT</label>
            <input type="text" class="form-control" id="usernameolt" name="usernameolt" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>
          <div class="mb-3">
            <label for="passwordolt" class="form-label">Password OLT</label>
            <input type="text" class="form-control" id="passwordolt" name="passwordolt" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>
          <div id="zteInfo" class="alert alert-info mt-2" style="display:none;">
            <i class="fas fa-terminal"></i>
            <strong>ZTE GPON C300/320:</strong> Koneksi menggunakan <strong>Telnet</strong> (port 23).
            Masukkan username dan password OLT di field di atas.
          </div>
          <div id="communityFields" style="display:none;">
            <div class="mb-3">
              <label for="community_read" class="form-label">Community Read</label>
              <input type="text" class="form-control" id="community_read" name="community_read" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
            <div class="mb-3">
              <label for="community_write" class="form-label">Community Write</label>
              <input type="text" class="form-control" id="community_write" name="community_write" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
          </div>

            <div class="mb-3">
              <label for="server" class="form-label">Server Area</label>
              <select required class="form-control" id="server" name="server" onchange="setAreaAddOlt()">
                <option value="">-- Pilih Server Area --</option>
                <?php
                // Ambil user id dari session user yang login
          if ($current_user_id) {
                                                  
            if ($AKSES == 'ASSISTANT') {
             
                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                                        
            } else {

                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                                        
            }
          } 
              
              
                while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                  $area = htmlspecialchars($rowServer['AREA']);
                  echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'">'.$rowServer['BRAND'].'-'.$area.'</option>';
                }
                ?>
              </select>
            </div>
            <input type="hidden" id="area" name="area">
            <div hidden class="mb-3">
              <label class="form-label">AREA</label>
              <input type="text" class="form-control" id="area_display" readonly>
            </div>
            <script>
            function setAreaAddOlt() {
              let serverSelect = document.getElementById('server');
              let areaInput = document.getElementById('area');
              let areaDisplay = document.getElementById('area_display');
              let selected = serverSelect.options[serverSelect.selectedIndex];
              let area = selected ? selected.getAttribute('data-area') : '';
              areaInput.value = area;
              areaDisplay.value = area;
            }
            function updatePortInfo() {
              const brandSelect = document.getElementById('brandolt');
              const portInfo = document.getElementById('portInfo');
              const ipInput = document.getElementById('ipolt');
              const communityFields = document.getElementById('communityFields');
              const communityRead = document.getElementById('community_read');
              const isHiosoWeb = (brandSelect.value === 'HIOSO EPON' || brandSelect.value === 'HIOSO EPON HA7302CST');
              const isLegacyCdataSnmp = (brandSelect.value === 'CDATA GPON SNMP LEGACY');
              const isZteFamily = brandSelect.value.indexOf('ZTE GPON') === 0;
              if (isHiosoWeb) {
                portInfo.style.display = 'block';
                communityFields.style.display = 'none';
                communityRead.required = false;
                document.getElementById('zteInfo').style.display = 'none';
                if (ipInput.value && !ipInput.value.includes(':')) {
                  ipInput.value = ipInput.value + ':80';
                }
              } else if (isLegacyCdataSnmp) {
                portInfo.style.display = 'none';
                communityFields.style.display = 'block';
                communityRead.required = true;
                document.getElementById('zteInfo').style.display = 'none';
              } else if (isZteFamily) {
                portInfo.style.display = 'none';
                communityFields.style.display = 'none';
                communityRead.required = false;
                document.getElementById('zteInfo').style.display = 'block';
              } else {
                portInfo.style.display = 'none';
                communityFields.style.display = 'none';
                communityRead.required = false;
                document.getElementById('zteInfo').style.display = 'none';
              }
            }
            </script>
            <button type="submit" class="btn btn-primary w-100">Simpan</button>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>




<!-- Modal Import OLT dari XLSX -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="importModalLabel">Import OLT dari XLSX</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="importForm" enctype="multipart/form-data">
          <div class="mb-3">
            <label for="excelFile" class="form-label">Pilih File XLSX</label>
            <input type="file" class="form-control" id="excelFile" name="excel_file" accept=".xlsx" required>
            <small class="form-text text-muted">
              Format: XLSX saja. <a href="proses/download_template_olt.php" target="_blank">Download template di sini</a>
            </small>
          </div>
          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" id="skipHeader" name="skip_header" checked>
            <label class="form-check-label" for="skipHeader">
              Skip baris pertama (header)
            </label>
          </div>
          <div id="importMessage" style="display:none;"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-success" id="importBtn">Import</button>
      </div>
    </div>
  </div>
</div>

<!-- Script untuk import XLSX -->
<script>
document.getElementById('importBtn').addEventListener('click', function() {
  var formData = new FormData(document.getElementById('importForm'));
  var fileInput = document.getElementById('excelFile');
  
  if (!fileInput.files || !fileInput.files[0]) {
    alert('Pilih file XLSX terlebih dahulu');
    return;
  }
  
  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'proses/import_olt.php', true);
  
  xhr.onload = function() {
    if (xhr.status === 200) {
      // Redirect ke olt.php dengan status
      window.location.href = 'olt.php';
    }
  };
  
  xhr.onerror = function() {
    alert('Gagal melakukan import');
  };
  
  xhr.send(formData);
});
</script>


<?php
// ------------------ DELETE DATA ------------------
if (isset($_GET['delete_id'])) {
  $delete_id = intval($_GET['delete_id']);
  mysqli_query($conn, "DELETE FROM `olt` WHERE id = $delete_id");
  echo "<script>alert('Data berhasil dihapus'); window.location='?';</script>";
  exit;
}
?>

<style>
/* Card Header Styling - Standard Bootstrap */
.card-header {
  border-bottom: 1px solid rgba(0, 0, 0, 0.1);
}

.card-header.bg-primary {
  background-color: #0d6efd !important;
}

.card-header h5 {
  font-weight: 600;
  font-size: 1.1rem;
  color: white !important;
}

.card-header h5 i {
  font-size: 1.15rem;
}

/* Button Styling */
.btn-group-sm > .btn {
  padding: 0.375rem 0.6rem;
  font-size: 0.85rem;
  border-radius: 0.25rem;
}

.btn-outline-info, .btn-outline-warning, .btn-outline-danger {
  border-width: 1.5px;
  transition: all 0.2s ease;
}

.btn-outline-info:hover {
  background-color: #0dcaf0;
  border-color: #0dcaf0;
  color: white;
  transform: translateY(-1px);
}

.btn-outline-warning:hover {
  background-color: #ffc107;
  border-color: #ffc107;
  color: #000;
  transform: translateY(-1px);
}

.btn-outline-danger:hover {
  background-color: #dc3545;
  border-color: #dc3545;
  color: white;
  transform: translateY(-1px);
}

/* Table Styling */
.table tbody tr {
  border-bottom: 1px solid #dee2e6;
  transition: background-color 0.15s ease;
}

.table tbody tr:hover {
  background-color: #f8f9fa;
}

.table thead.table-light th {
  background-color: #f8f9fa !important;
  color: #495057 !important;
  font-weight: 600 !important;
  border-bottom: 2px solid #dee2e6 !important;
  vertical-align: middle !important;
}

/* Status Badge */
.badge {
  padding: 0.4rem 0.6rem;
  font-weight: 500;
  font-size: 0.8rem;
  border-radius: 0.25rem;
}

/* Data Cell Styling */
.table td {
  vertical-align: middle;
  padding: 0.75rem;
}

/* Responsive table */
.table-responsive {
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.table thead th {
  white-space: nowrap;
}
</style>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card shadow mb-4" id="oltManagementCard">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0">
            <i class="fas fa-network-wired me-2"></i>OLT Management
          </h5>
        </div>

        <div class="card-body">
          <ul class="nav nav-tabs" id="oltMainTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab === 'list' ? 'active' : ''; ?>" id="olt-list-tab" data-bs-toggle="tab" data-bs-target="#olt-list-tab-pane" type="button" role="tab" aria-controls="olt-list-tab-pane" aria-selected="<?php echo $activeTab === 'list' ? 'true' : 'false'; ?>">
                Daftar OLT
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $activeTab === 'template' ? 'active' : ''; ?>" id="olt-template-tab" data-bs-toggle="tab" data-bs-target="#olt-template-tab-pane" type="button" role="tab" aria-controls="olt-template-tab-pane" aria-selected="<?php echo $activeTab === 'template' ? 'true' : 'false'; ?>">
                Setting Template OLT
              </button>
            </li>
          </ul>

          <div class="tab-content pt-3" id="oltMainTabsContent">
          <div class="tab-pane fade <?php echo $activeTab === 'list' ? 'show active' : ''; ?>" id="olt-list-tab-pane" role="tabpanel" aria-labelledby="olt-list-tab" tabindex="0">
          <!-- Button Section -->
          <div class="mb-4 pb-3 border-bottom" id="oltButtonsSection">
            <div class="row g-2">
              <div class="col-auto">
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
                  <i class="fas fa-plus me-1"></i>Add OLT
                </button>
              </div>
              <div class="col-auto">
                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#importModal">
                  <i class="fas fa-file-import me-1"></i>Import XLSX
                </button>
              </div>
              <div class="col-auto">
                <a href="proses/download_template_olt.php" class="btn btn-sm btn-info text-white" target="_blank">
                  <i class="fas fa-file-download me-1"></i>Download Template
                </a>
              </div>
              <div class="col-auto">
                <form method="POST" action="proses/export_olt.php" style="display:inline;">
                  <button type="submit" class="btn btn-sm btn-warning">
                    <i class="fas fa-file-export me-1"></i>Export XLSX
                  </button>
                </form>
              </div>
              <div class="col-auto">
                <a href="vpn.php" class="btn btn-sm btn-danger">
                  <i class="fas fa-shield-alt me-1"></i>Beli VPN Remot
                </a>
              </div>
              <div class="col-auto">
                <a href="olt_documentation.php" class="btn btn-sm btn-secondary" target="_blank">
                  <i class="fas fa-book me-1"></i>Documentation
                </a>
              </div>
            </div>
          </div>

          <!-- Search Section -->
          <div class="mb-3" id="oltSearchSection">
            <input type="text" id="searchOltInput" class="form-control" placeholder="🔍 Cari OLT, Area, Server Area..." onkeyup="filterOltTable()">
          </div>

          <!-- Table Section -->
          <div class="table-responsive" id="oltTableContainer">
            <table class="table table-hover align-items-center mb-0">
              <thead class="table-light">
                <tr>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder">OLT</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder">IP</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder">Server Area</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center">Support Template</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder text-center">Actions</th>
                </tr>
              </thead>
              <tbody id="oltTableBody">
              <?php
              // Ambil hanya OLT yang server-nya dimiliki oleh user yang sedang login
              if($AKSES !='ASSISTANT') {
                  // Ambil semua server yang user_id-nya sama dengan current_user_id
                  $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                  $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
                  $userServerIds = [];
                  while($row = mysqli_fetch_assoc($queryServerId)) {
                      $userServerIds[] = "'".$row['PEMILIK']."'";
                  }
                  $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
                  $sql = "SELECT * FROM olt WHERE `pemilik` IN ($userServerList)";
              } else {
                  // Untuk ASSISTANT, tetap gunakan $server_list dan filter area_list
                  $sql = "SELECT * FROM olt WHERE  `area` IN ($area_list)";
              }
              $query = mysqli_query($conn, $sql);
              while ($data = mysqli_fetch_array($query)) {
                $BRAND = $data['brandolt'];
                $isHiosoWeb = in_array($BRAND, ['HIOSO EPON', 'HIOSO EPON HA7302CST'], true);
                $isTemplateSupported = preg_match('/^ZTE GPON C/i', (string)$BRAND) === 1;
              ?>
                <tr>
                   <td>
                      <div class="d-flex px-2 py-1">
                          <div>
                            <img src="https://img.icons8.com/plasticine/100/stack.png" class="avatar me-3" alt="Server" width="130px" height="130px">

                          </div>
                          <div class="d-flex flex-column justify-content-center">
                             Area: <?= $data['area']; ?><br>
                             Name OLT: <?= $data['oltname']; ?><br>
                             Brand: <?= $isHiosoWeb ? 'HIOSO EPON HA7302CST 2 PORT' : $BRAND; ?>
                             <?php if ($isHiosoWeb) { ?>
                               <br><small class="text-info"><i class="fas fa-info-circle"></i> Web Interface (Port 80)</small>
                             <?php } ?>
                           </div>
                        </div>
                   
                   
                  </td>
         <td class="text-center">
  <code class="text-xs" style="background: #f8f9fa; padding: 0.4rem 0.6rem; border-radius: 0.25rem; display: inline-block;"><?= $data['ipolt']; ?></code>
</td>

                
                  <td class="text-center"><?= $data['pemilik']; ?></td>

                  <td class="text-center">
                    <?php if ($isTemplateSupported) { ?>
                      <span class="badge bg-success">Didukung</span>
                    <?php } else { ?>
                      <span class="badge bg-secondary">Tidak Didukung</span>
                    <?php } ?>
                  </td>
                
                  <td class="text-center">
  <!-- Tombol Edit -->
    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal<?= $data['id']; ?>">
      Edit
    </button>
                    <?php
                      $consoleMap = [
                        'ZTE GPON C300/320' => ['path' => '../olt/zte/index.php', 'model' => '', 'label' => 'ZTE GPON Console'],
                        'ZTE GPON C300'     => ['path' => '../olt/zte/index.php', 'model' => '', 'label' => 'ZTE GPON Console'],
                        'ZTE GPON C320'     => ['path' => '../olt/zte/index.php', 'model' => '', 'label' => 'ZTE GPON Console'],
                        'ZTE GPON C600'     => ['path' => '../olt/zte/index.php', 'model' => '', 'label' => 'ZTE GPON Console'],
                        'CDATA GPON'        => ['path' => '../olt/cdata/index.php', 'model' => 'fd1601s_b1', 'label' => 'C-Data GPON Console'],
                        'CDATA GPON FD1601S' => ['path' => '../olt/cdata/index.php', 'model' => 'fd1601s_b1', 'label' => 'C-Data GPON Console'],
                        'CDATA GPON FD1608S-B1' => ['path' => '../olt/cdata/index.php', 'model' => 'fd1608s_b1', 'label' => 'C-Data GPON Console'],
                        'CDATA EPON FD1104/FD1208' => ['path' => '../olt/cdata/index.php', 'model' => 'fd1104_fd1208', 'label' => 'C-Data EPON Console'],
                        'HUAWEI MA5608T'    => ['path' => '../olt/huawei/index.php', 'model' => 'ma5608t', 'label' => 'Huawei GPON Console'],
                        'HUAWEI MA5680T'    => ['path' => '../olt/huawei/index.php', 'model' => 'ma5680t', 'label' => 'Huawei GPON Console'],
                        'HUAWEI MA5683T'    => ['path' => '../olt/huawei/index.php', 'model' => 'ma5683t', 'label' => 'Huawei GPON Console'],
                        'VSOL GPON V1600G0/G1' => ['path' => '../olt/vsol/index.php', 'model' => 'v1600g0_g1', 'label' => 'V-SOL Console'],
                        'VSOL EPON V1600D'  => ['path' => '../olt/vsol/index.php', 'model' => 'v1600d', 'label' => 'V-SOL Console'],
                        'VSOL GPON'         => ['path' => '../olt/vsol/index.php', 'model' => 'v1600g0_g1', 'label' => 'V-SOL Console'],
                        'VSOL EPON'         => ['path' => '../olt/vsol/index.php', 'model' => 'v1600d', 'label' => 'V-SOL Console'],
                        'HSGQ GPON G02/G04/G08/G16' => ['path' => '../olt/hsgq/index.php', 'model' => 'gpon_g_series', 'label' => 'HSGQ Console'],
                        'HSGQ EPON E04/E08' => ['path' => '../olt/hsgq/index.php', 'model' => 'epon_e_series', 'label' => 'HSGQ Console'],
                        'HSGQ GPON'         => ['path' => '../olt/hsgq/index.php', 'model' => 'gpon_g_series', 'label' => 'HSGQ Console'],
                        'HSGQ EPON'         => ['path' => '../olt/hsgq/index.php', 'model' => 'epon_e_series', 'label' => 'HSGQ Console'],
                        'HIOSO EPON HA7304' => ['path' => '../olt/hioso/index.php', 'model' => 'ha7304', 'label' => 'HIOSO Console'],
                        'HIOSO EPON HA7308' => ['path' => '../olt/hioso/index.php', 'model' => 'ha7308', 'label' => 'HIOSO Console'],
                      ];
                      $consoleCfg = $consoleMap[$BRAND] ?? null;
                    ?>
                    <?php if ($isHiosoWeb) { ?>
                      <!-- Tombol Open pakai modal -->
                      <button type="button" 
                              class="btn btn-sm btn-success open-remote"
                              data-ip="<?= $data['ipolt']; ?>"
                              data-user="<?= $data['usernameolt']; ?>"
                              data-pass="<?= $data['passwordolt']; ?>"
                              data-device="<?= $data['oltname']; ?>">
                        Open
                      </button>
                    <?php } elseif ($consoleCfg) { ?>
                      <button type="button"
                              class="btn btn-sm btn-success open-olt-console"
                              data-console-path="<?= htmlspecialchars($consoleCfg['path'], ENT_QUOTES); ?>"
                              data-model="<?= htmlspecialchars($consoleCfg['model'], ENT_QUOTES); ?>"
                              data-label="<?= htmlspecialchars($consoleCfg['label'], ENT_QUOTES); ?>"
                              data-brand="<?= htmlspecialchars($BRAND, ENT_QUOTES); ?>"
                              data-ip="<?= htmlspecialchars($data['ipolt'], ENT_QUOTES); ?>"
                              data-user="<?= htmlspecialchars($data['usernameolt']); ?>"
                              data-pass="<?= htmlspecialchars($data['passwordolt']); ?>"
                              data-device="<?= htmlspecialchars($data['oltname']); ?>"
                              data-olt-id="<?= (int) $data['id']; ?>">
                        Open
                      </button>
                    <?php } else { ?>
                   <!-- <a type="button"  class="btn btn-sm btn-success" href='getdata/olt/' >Open</a> -->
                         <!-- Tombol Open pakai modal -->
                      <button type="button" 
                              class="btn btn-sm btn-success open-remotegpon"
                              data-ip="<?= $data['ipolt']; ?>"
                              data-user="<?= $data['usernameolt']; ?>"
                              data-pass="<?= $data['passwordolt']; ?>"
                              data-device="<?= $data['oltname']; ?>"
                              data-brand="<?= $data['brandolt']; ?>"
                              data-server="<?= $data['pemilik']; ?>">
                        Open
                      </button>
                    <?php } ?>

                    <a href="?delete_id=<?= $data['id']; ?>" 
                       class="btn btn-sm btn-danger"
                       onclick="return confirm('Yakin ingin hapus data ini?')">
                      Delete
                    </a>
                  </td>
                </tr>




<!-- Modal Edit per baris -->
<div class="modal fade" id="editModal<?= $data['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?= $data['id']; ?>" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel<?= $data['id']; ?>">Edit OLT</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form action="proses/editolt.php" method="POST">
          <input type="hidden" name="id" value="<?= $data['id']; ?>">
          <div class="mb-3">
            <label for="ipolt<?= $data['id']; ?>" class="form-label">IP + PORT</label>
            <input type="text" class="form-control" id="ipolt<?= $data['id']; ?>" name="ipolt" value="<?= $data['ipolt']; ?>" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            <small class="form-text text-muted">
              <strong>Info:</strong> Untuk HIOSO EPON 2 Port (HA7302CST), gunakan port 80 (HTTP) untuk akses web interface.
            </small>
          </div>
          <div class="mb-3">
            <label for="oltname<?= $data['id']; ?>" class="form-label">OLT NAME</label>
            <input type="text" class="form-control" id="oltname<?= $data['id']; ?>" name="oltname" value="<?= $data['oltname']; ?>" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>
          <div class="mb-3">
            <label for="brandolt<?= $data['id']; ?>" class="form-label">Brand OLT</label>
            <select class="form-select" id="brandolt<?= $data['id']; ?>" name="brandolt" required onchange="updatePortInfoEdit(<?= $data['id']; ?>)">
              <option value="" disabled>Pilih Brand OLT</option>
              <?php foreach ($oltBrandOptions as $brandValue => $brandLabel): ?>
                <option value="<?= htmlspecialchars($brandValue); ?>" <?= $data['brandolt'] == $brandValue ? 'selected' : ''; ?>><?= htmlspecialchars($brandLabel); ?></option>
              <?php endforeach; ?>
            </select>
            <div id="portInfoEdit<?= $data['id']; ?>" class="alert alert-info mt-2" style="display:<?= in_array($data['brandolt'], ['HIOSO EPON', 'HIOSO EPON HA7302CST'], true) ? 'block' : 'none'; ?>;">
              <i class="fas fa-info-circle"></i> 
              <strong>HIOSO EPON HA7302CST 2 PORT:</strong> 
              Device ini menggunakan web interface dengan port 80. Pastikan format IP adalah: <code>192.168.x.x:80</code>
            </div>
          </div>
          <div class="mb-3">
            <label for="usernameolt<?= $data['id']; ?>" class="form-label">Username OLT</label>
            <input type="text" class="form-control" id="usernameolt<?= $data['id']; ?>" name="usernameolt" value="<?= $data['usernameolt']; ?>" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>
          <div class="mb-3">
            <label for="passwordolt<?= $data['id']; ?>" class="form-label">Password OLT</label>
            <input type="text" class="form-control" id="passwordolt<?= $data['id']; ?>" name="passwordolt" value="<?= $data['passwordolt']; ?>" required pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
          </div>
          <div id="zteInfoEdit<?= $data['id']; ?>" class="alert alert-info mt-2" style="display:<?= (strpos($data['brandolt'], 'ZTE GPON') === 0) ? 'block' : 'none'; ?>">
            <i class="fas fa-terminal"></i>
            <strong>ZTE GPON:</strong> Koneksi menggunakan <strong>Telnet</strong> 
            Masukkan username dan password OLT di field di atas.
          </div>
          <div id="communityFieldsEdit<?= $data['id']; ?>" style="display:<?= ($data['brandolt'] == 'CDATA GPON SNMP LEGACY') ? 'block' : 'none'; ?>">
            <div class="mb-3">
              <label for="community_read<?= $data['id']; ?>" class="form-label">Community Read</label>
              <input type="text" class="form-control" id="community_read<?= $data['id']; ?>" name="community_read" value="<?= $data['community_read'] ?? ''; ?>" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
            <div class="mb-3">
              <label for="community_write<?= $data['id']; ?>" class="form-label">Community Write</label>
              <input type="text" class="form-control" id="community_write<?= $data['id']; ?>" name="community_write" value="<?= $data['community_write'] ?? ''; ?>" pattern="^(?!\s)(.*\S)?$" oninput="this.value=this.value.replace(/^\s+|\s+$/g,'')">
            </div>
          </div>

          <div class="mb-3">
            <label for="server<?= $data['id']; ?>" class="form-label">Server Area</label>
            <select class="form-select" id="server<?= $data['id']; ?>" name="server" onchange="setAreaEditOlt(<?= $data['id']; ?>)" required>
              <option value="">-- Pilih Server Area --</option>
              <?php
                                           if ($current_user_id) {
                                                  
            if ($AKSES == 'ASSISTANT') {
             
                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                                        
            } else {

                                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                                        
            }
          } 
             
              while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                $selected = ($rowServer['PEMILIK'] == $data['PEMILIK']) ? "selected" : "";
                $area = htmlspecialchars($rowServer['AREA']);
                echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'" '.$selected.'>'.$rowServer['BRAND'].'-'.$area.'</option>';
              }
              ?>
            </select>
          </div>
          <input type="hidden" id="area<?= $data['id']; ?>" name="area" value="<?= $data['area']; ?>">
          <div hidden class="mb-3">
            <label class="form-label">Area</label>
            <input type="text" class="form-control" id="area_display<?= $data['id']; ?>" value="<?= $data['area']; ?>" readonly>
          </div>
          <script>
          function setAreaEditOlt(id) {
            let serverSelect = document.getElementById('server' + id);
            let areaInput = document.getElementById('area' + id);
            let areaDisplay = document.getElementById('area_display' + id);
            let selected = serverSelect.options[serverSelect.selectedIndex];
            let area = selected ? selected.getAttribute('data-area') : '';
            areaInput.value = area;
            areaDisplay.value = area;
          }
          </script>

          <button type="submit" class="btn btn-primary w-100">Simpan Perubahan</button>
          <script>
          // Prevent leading/trailing spaces on all text inputs before submit (add & edit OLT)
          document.addEventListener('DOMContentLoaded', function() {
            // Add OLT form
            var addForm = document.querySelector('.modal-body form[method="post"]');
            if (addForm) {
              addForm.addEventListener('submit', function(e) {
                var inputs = addForm.querySelectorAll('input[type="text"]');
                let hasTrimError = false;
                inputs.forEach(function(input) {
                  if (/^\s|\s$/.test(input.value)) {
                    hasTrimError = true;
                    input.classList.add('is-invalid');
                  } else {
                    input.classList.remove('is-invalid');
                  }
                  input.value = input.value.replace(/^\s+|\s+$/g, '');
                });
                if (hasTrimError) {
                  alert('Input tidak boleh ada spasi di awal atau di akhir!');
                  e.preventDefault();
                }
              });
            }
            // Edit OLT forms
            document.querySelectorAll('form[action="proses/editolt.php"]').forEach(function(editForm) {
              editForm.addEventListener('submit', function(e) {
                var inputs = editForm.querySelectorAll('input[type="text"]');
                let hasTrimError = false;
                inputs.forEach(function(input) {
                  if (/^\s|\s$/.test(input.value)) {
                    hasTrimError = true;
                    input.classList.add('is-invalid');
                  } else {
                    input.classList.remove('is-invalid');
                  }
                  input.value = input.value.replace(/^\s+|\s+$/g, '');
                });
                if (hasTrimError) {
                  alert('Input tidak boleh ada spasi di awal atau di akhir!');
                  e.preventDefault();
                }
              });
            });
          });
          </script>
          </form>
      </div>
    </div>
  </div>
</div>


<!-- Loading Overlay -->
<div id="loadingOverlay" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2000;background:rgba(255,255,255,0.7);align-items:center;justify-content:center;">
  <div class="spinner-border text-primary" style="width:4rem;height:4rem;" role="status">
    <span class="visually-hidden">Loading...</span>
  </div>
</div>

<script>
function updatePortInfoEdit(id) {
  const brandSelect = document.getElementById('brandolt' + id);
  const portInfo = document.getElementById('portInfoEdit' + id);
  const ipInput = document.getElementById('ipolt' + id);
  const communityFields = document.getElementById('communityFieldsEdit' + id);
  const communityRead = document.getElementById('community_read' + id);
  const isHiosoWeb = (brandSelect.value === 'HIOSO EPON' || brandSelect.value === 'HIOSO EPON HA7302CST');
  const isLegacyCdataSnmp = (brandSelect.value === 'CDATA GPON SNMP LEGACY');
  const isZteFamily = brandSelect.value.indexOf('ZTE GPON') === 0;
  
  if (isHiosoWeb) {
    portInfo.style.display = 'block';
    communityFields.style.display = 'none';
    communityRead.required = false;
    const zteInfo = document.getElementById('zteInfoEdit' + id);
    if (zteInfo) zteInfo.style.display = 'none';
    // Auto-suggest port 80 if no port specified
    if (ipInput.value && !ipInput.value.includes(':')) {
      ipInput.value = ipInput.value + ':80';
    }
  } else if (isLegacyCdataSnmp) {
    portInfo.style.display = 'none';
    communityFields.style.display = 'block';
    communityRead.required = true;
    const zteInfo = document.getElementById('zteInfoEdit' + id);
    if (zteInfo) zteInfo.style.display = 'none';
  } else if (isZteFamily) {
    portInfo.style.display = 'none';
    communityFields.style.display = 'none';
    communityRead.required = false;
    const zteInfo = document.getElementById('zteInfoEdit' + id);
    if (zteInfo) zteInfo.style.display = 'block';
  } else {
    portInfo.style.display = 'none';
    communityFields.style.display = 'none';
    communityRead.required = false;
    const zteInfo = document.getElementById('zteInfoEdit' + id);
    if (zteInfo) zteInfo.style.display = 'none';
  }
}

// Show loading overlay on all OLT form submits (add/edit)
document.addEventListener('DOMContentLoaded', function() {
  // Add OLT
  var addForm = document.querySelector('.modal-body form[method="post"]');
  if (addForm) {
    addForm.addEventListener('submit', function() {
      document.getElementById('loadingOverlay').style.display = 'flex';
    });
  }
  // Edit OLT
  document.querySelectorAll('form[action="proses/editolt.php"]').forEach(function(editForm) {
    editForm.addEventListener('submit', function() {
      document.getElementById('loadingOverlay').style.display = 'flex';
    });
  });
});
</script>




              <?php } ?>
            </tbody>
          </table>
        </div>

<script>
function filterOltTable() {
  var input = document.getElementById('searchOltInput');
  var filter = input.value.toLowerCase();
  var table = document.getElementById('oltTableBody');
  var trs = table.getElementsByTagName('tr');
  for (var i = 0; i < trs.length; i++) {
    var rowText = trs[i].innerText.toLowerCase();
    trs[i].style.display = rowText.indexOf(filter) > -1 ? '' : 'none';

  }
}

// Fallback tab switcher (works even if Bootstrap tab plugin is not active)
(function() {
  function setActiveTab(targetPaneId) {
    var listTabBtn = document.getElementById('olt-list-tab');
    var templateTabBtn = document.getElementById('olt-template-tab');
    var listPane = document.getElementById('olt-list-tab-pane');
    var templatePane = document.getElementById('olt-template-tab-pane');

    if (!listTabBtn || !templateTabBtn || !listPane || !templatePane) {
      return;
    }

    var isTemplate = targetPaneId === 'olt-template-tab-pane';

    listTabBtn.classList.toggle('active', !isTemplate);
    listTabBtn.setAttribute('aria-selected', (!isTemplate).toString());
    templateTabBtn.classList.toggle('active', isTemplate);
    templateTabBtn.setAttribute('aria-selected', isTemplate.toString());

    listPane.classList.toggle('show', !isTemplate);
    listPane.classList.toggle('active', !isTemplate);
    templatePane.classList.toggle('show', isTemplate);
    templatePane.classList.toggle('active', isTemplate);

    try {
      var currentUrl = new URL(window.location.href);
      if (isTemplate) {
        currentUrl.searchParams.set('tab', 'template');
      } else {
        currentUrl.searchParams.delete('tab');
      }
      window.history.replaceState({}, '', currentUrl.toString());
    } catch (e) {}
  }

  document.addEventListener('DOMContentLoaded', function() {
    var listTabBtn = document.getElementById('olt-list-tab');
    var templateTabBtn = document.getElementById('olt-template-tab');
    if (!listTabBtn || !templateTabBtn) return;

    listTabBtn.addEventListener('click', function() {
      setActiveTab('olt-list-tab-pane');
    });
    templateTabBtn.addEventListener('click', function() {
      setActiveTab('olt-template-tab-pane');
    });
  });
})();
</script>
          </div>

          <div class="tab-pane fade <?php echo $activeTab === 'template' ? 'show active' : ''; ?>" id="olt-template-tab-pane" role="tabpanel" aria-labelledby="olt-template-tab" tabindex="0">
            <div class="alert alert-info">
              Atur template default input per OLT. Saat OLT dipilih di Add Customer atau Provisioning Joblist, field akan otomatis mengikuti template ini.
            </div>

            <style>
              @keyframes tplFieldLoadingPulse {
                0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.25); }
                70% { box-shadow: 0 0 0 8px rgba(13, 110, 253, 0); }
                100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); }
              }

              #oltTemplateForm .tpl-input-loading {
                animation: tplFieldLoadingPulse 1.1s ease-in-out infinite;
                background-image: linear-gradient(90deg, rgba(13, 110, 253, 0.08), rgba(13, 110, 253, 0.16), rgba(13, 110, 253, 0.08));
                background-size: 240% 100%;
                opacity: 0.82;
              }
            </style>

            <form method="post" id="oltTemplateForm" class="border rounded p-3 bg-light-subtle">
              <input type="hidden" name="form_action" value="save_olt_template">

              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Pilih OLT</label>
                  <select class="form-select" name="template_olt_id" id="template_olt_id" required>
                    <option value="">-- Pilih OLT Support --</option>
                    <?php
                    $hasSupportedOlt = false;
                    if ($AKSES != 'ASSISTANT') {
                        $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                        $queryOltTemplate = mysqli_query($conn, "SELECT o.id, o.oltname, o.ipolt, o.brandolt, o.area, o.usernameolt, o.passwordolt
                            FROM olt o
                            WHERE EXISTS (SELECT 1 FROM server s WHERE s.PEMILIK = o.pemilik AND s.user_id = $current_user_id)
                            ORDER BY o.area, o.oltname");
                    } else {
                        $queryOltTemplate = mysqli_query($conn, "SELECT id, oltname, ipolt, brandolt, area, usernameolt, passwordolt FROM olt WHERE area IN ($area_list) ORDER BY area, oltname");
                    }
                    if ($queryOltTemplate) {
                      while ($oltRow = mysqli_fetch_assoc($queryOltTemplate)) {
                      $brandName = (string)($oltRow['brandolt'] ?? '');
                      if (!preg_match('/^ZTE GPON C/i', $brandName)) {
                        continue;
                      }
                      $hasSupportedOlt = true;
                      $oltLabel = ($oltRow['oltname'] ?? '-') . ' | ' . ($oltRow['brandolt'] ?? '-') . ' | ' . ($oltRow['area'] ?? '-') . ' | ' . ($oltRow['ipolt'] ?? '-');
                      echo '<option value="' . (int)$oltRow['id'] . '"'
                        . ' data-brand="' . htmlspecialchars((string)($oltRow['brandolt'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-ip="' . htmlspecialchars((string)($oltRow['ipolt'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-user="' . htmlspecialchars((string)($oltRow['usernameolt'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-pass="' . htmlspecialchars((string)($oltRow['passwordolt'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                        . ' data-name="' . htmlspecialchars((string)($oltRow['oltname'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
                        . '>' . htmlspecialchars($oltLabel) . '</option>';
                      }
                      if (!$hasSupportedOlt) {
                        echo '<option value="" disabled>Tidak ada OLT yang mendukung pilihan otomatis</option>';
                      }
                    }
                    ?>
                  </select>
                </div>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
                  <h6 class="mb-0">Template Register ONT</h6>
                </div>
                <div class="col-12">
                  <div id="tpl_olt_brand_note" class="alert alert-secondary py-2 mb-0">
                    Template hanya untuk field registrasi ONT. Pilihan VLAN/TCONT/Type akan mengikuti data yang ditampilkan dari OLT yang dipilih.
                  </div>
                  <div id="tpl_olt_loading" class="small text-primary mt-1 d-none d-inline-flex align-items-center">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                    <span class="loading-text">Memuat pilihan dari OLT...</span>
                  </div>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Type ONT</label>
                  <select class="form-select" name="ont_type" id="tpl_ont_type">
                    <option value="">-- Pilih Type ONT --</option>
                  </select>
                </div>

                <div class="col-md-4">
                  <label class="form-label">Profile TCONT</label>
                  <select class="form-select" name="tcont_profile" id="tpl_tcont_profile">
                    <option value="">-- Pilih TCONT --</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">VLAN</label>
                  <select class="form-select" name="vlan_id" id="tpl_vlan_id">
                    <option value="">-- Pilih VLAN --</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">VLAN ID Manual</label>
                  <input type="text" class="form-control" name="vlan_manual" id="tpl_vlan_manual" placeholder="Kosongkan jika pakai dropdown VLAN">
                </div>
                <div class="col-md-2">
                  <label class="form-label">Service Name</label>
                  <input type="text" class="form-control" name="service_name" id="tpl_service_name" value="HSI">
                </div>
                <div class="col-md-2">
                  <label class="form-label">VLAN Profile</label>
                  <input type="text" class="form-control" name="vlan_profile" id="tpl_vlan_profile" value="PPPoE">
                </div>
                <div class="col-md-1">
                  <label class="form-label">Gemport</label>
                  <input type="text" class="form-control" name="gemport" id="tpl_gemport" value="1">
                </div>
                <div class="col-md-1">
                  <label class="form-label">COS</label>
                  <input type="text" class="form-control" name="cos" id="tpl_cos" value="0">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Ethuni</label>
                  <input type="text" class="form-control" name="ethuni" id="tpl_ethuni" value="1,2,3">
                </div>

                <div class="col-12 d-flex justify-content-end mt-2">
                  <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>Simpan Template
                  </button>
                </div>
              </div>
            </form>

            <div class="table-responsive mt-4">
              <table class="table table-sm table-bordered align-middle">
                <thead class="table-light">
                  <tr>
                    <th>OLT</th>
                    <th>Template Register ONT</th>
                    <th>Last Update</th>
                    <th style="width: 120px;">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  if ($AKSES != 'ASSISTANT') {
                      $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                      $sqlTemplateList = "SELECT o.id, o.oltname, o.brandolt, o.area, t.updated_at,
                        t.ont_type, t.vlan_id, t.tcont_profile, t.ont_sn
                          FROM olt o
                          LEFT JOIN olt_input_templates t ON t.olt_id = o.id
                          WHERE EXISTS (SELECT 1 FROM server s WHERE s.PEMILIK = o.pemilik AND s.user_id = $current_user_id)
                          ORDER BY o.area, o.oltname";
                  } else {
                      $sqlTemplateList = "SELECT o.id, o.oltname, o.brandolt, o.area, t.updated_at,
                        t.ont_type, t.vlan_id, t.tcont_profile, t.ont_sn
                          FROM olt o
                          LEFT JOIN olt_input_templates t ON t.olt_id = o.id
                          WHERE o.area IN ($area_list)
                          ORDER BY o.area, o.oltname";
                  }
                  $resTemplateList = mysqli_query($conn, $sqlTemplateList);
                  if ($resTemplateList) {
                    while ($tplRow = mysqli_fetch_assoc($resTemplateList)) {
                        $ontPreview = !empty($tplRow['ont_type']) || !empty($tplRow['vlan_id']) || !empty($tplRow['tcont_profile'])
                          ? ('Type: ' . ($tplRow['ont_type'] ?: '-') . ' | VLAN: ' . ($tplRow['vlan_id'] ?: '-') . ' | VLAN Manual: ' . ($tplRow['ont_sn'] ?: '-') . ' | TCONT: ' . ($tplRow['tcont_profile'] ?: '-'))
                          : '-';
                      echo '<tr>';
                      echo '<td>' . htmlspecialchars(($tplRow['oltname'] ?? '-') . ' | ' . ($tplRow['brandolt'] ?? '-') . ' | ' . ($tplRow['area'] ?? '-')) . '</td>';
                      echo '<td>' . htmlspecialchars($ontPreview) . '</td>';
                      echo '<td>' . htmlspecialchars($tplRow['updated_at'] ?? '-') . '</td>';
                      echo '<td class="text-center">';
                      if (!empty($tplRow['updated_at'])) {
                        echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Hapus template OLT ini?\');">';
                        echo '<input type="hidden" name="form_action" value="delete_olt_template">';
                        echo '<input type="hidden" name="template_olt_id" value="' . (int)$tplRow['id'] . '">';
                        echo '<button type="submit" class="btn btn-sm btn-outline-danger">Hapus</button>';
                        echo '</form>';
                      } else {
                        echo '<span class="text-muted small">-</span>';
                      }
                      echo '</td>';
                      echo '</tr>';
                    }
                  }
                  ?>
                </tbody>
              </table>
            </div>

            <script>
            (function() {
              var oltSelect = document.getElementById('template_olt_id');
              var templateForm = document.getElementById('oltTemplateForm');
              var loadingEl = document.getElementById('tpl_olt_loading');
              var brandNoteEl = document.getElementById('tpl_olt_brand_note');
              var zteConsolePath = '../olt/zte/index.php';
              var pendingTemplateData = null;
              if (!oltSelect) return;

              function setInputsLoading(isLoading) {
                if (!templateForm) return;
                var fields = templateForm.querySelectorAll('.form-control, .form-select');
                fields.forEach(function(field) {
                  field.classList.toggle('tpl-input-loading', !!isLoading);
                  if (field.type !== 'hidden') {
                    field.disabled = !!isLoading;
                  }
                });
              }

              function setField(id, value) {
                var el = document.getElementById(id);
                if (!el) return;
                if (el.type === 'checkbox') {
                  el.checked = !!value && value !== '0';
                } else {
                  el.value = value == null ? '' : String(value);
                }
              }

              function setSelectOptions(id, values, placeholder) {
                var el = document.getElementById(id);
                if (!el) return;
                var prev = el.value;
                el.innerHTML = '<option value="">' + placeholder + '</option>';
                (values || []).forEach(function(value) {
                  var normalized = String(value || '').trim();
                  if (!normalized) return;
                  var opt = document.createElement('option');
                  opt.value = normalized;
                  opt.textContent = normalized;
                  el.appendChild(opt);
                });
                if (prev && Array.from(el.options).some(function(o) { return o.value === prev; })) {
                  el.value = prev;
                }
              }

              function setSelectValueIfExists(id, value) {
                var el = document.getElementById(id);
                if (!el) return false;
                var normalized = String(value || '').trim();
                if (!normalized) return false;
                var exists = Array.from(el.options).some(function(o) { return String(o.value).trim() === normalized; });
                if (!exists) return false;
                el.value = normalized;
                return true;
              }

              function getSelectedOltMeta() {
                var opt = oltSelect.options[oltSelect.selectedIndex];
                if (!opt || !opt.value) return null;
                return {
                  id: opt.value,
                  name: opt.getAttribute('data-name') || '',
                  brand: opt.getAttribute('data-brand') || '',
                  ip: opt.getAttribute('data-ip') || '',
                  user: opt.getAttribute('data-user') || '',
                  pass: opt.getAttribute('data-pass') || ''
                };
              }

              function isZteBrand(brand) {
                return /^ZTE GPON C/i.test(String(brand || '').toUpperCase());
              }

              function parseIpPort(ipData, defaultPort) {
                var ip = ipData || '';
                var port = defaultPort;
                if (ip.indexOf(':') !== -1) {
                  var parts = ip.split(':');
                  ip = parts[0];
                  port = parseInt(parts[1], 10) || defaultPort;
                }
                return { ip: ip, port: port };
              }

              function parseZteVlanSum(raw) {
                var match = String(raw || '').match(/Details are following:\s*([\d ,]+)/s);
                if (!match) return [];
                return match[1].replace(/\s+/g, '').split(',').filter(Boolean);
              }

              function parseZteTcont(raw) {
                var profiles = [];
                String(raw || '').split('\n').forEach(function(line) {
                  var match = line.match(/Profile\s+name\s+:(\S+)/i);
                  if (match && match[1]) profiles.push(match[1].trim());
                });
                return profiles;
              }

              function parseZteOnuTypeNames(raw) {
                // Primary path: extract all "ONU type name:" lines directly from raw output.
                var directSet = new Set();
                var directRegex = /ONU\s+type\s+name\s*:\s*([^\r\n]+)/ig;
                var directMatch;
                while ((directMatch = directRegex.exec(String(raw || ''))) !== null) {
                  var directName = String(directMatch[1] || '').trim();
                  if (directName) directSet.add(directName);
                }
                if (directSet.size > 0) {
                  return Array.from(directSet).filter(Boolean).sort(function(a, b) { return a.localeCompare(b); });
                }

                var set = new Set();
                var inOnuTypeSection = false;
                String(raw || '').split('\n').forEach(function(line) {
                  var clean = line
                    .replace(/\s*--More--\s*/gi, ' ')
                    .replace(/\x1b\[[0-9;]*[A-Za-z]/g, '')
                    .trim();
                  if (!clean) return;

                  // Accept both plain command and prompt-prefixed command, e.g. ZXAN#show onu-type gpon
                  if (/(^|#)\s*show\s+onu-type\s+gpon\s*$/i.test(clean)) {
                    inOnuTypeSection = true;
                    return;
                  }

                  // Extract the real payload first while inside section.
                  if (inOnuTypeSection) {
                    var matchLabel = clean.match(/^ONU\s+type\s+name\s*:\s*(.+)$/i);
                    if (matchLabel && matchLabel[1]) {
                      set.add(matchLabel[1].trim());
                      return;
                    }
                  }

                  // End section when prompt returns after data block.
                  if (inOnuTypeSection && /^(ZXAN#|OLT.*#|\w+@.+[#>])/.test(clean)) {
                    inOnuTypeSection = false;
                    return;
                  }

                  if (!inOnuTypeSection) return;
                  if (/^show\s+/i.test(clean)) return;
                  if (/^-{3,}/.test(clean)) return;
                  if (/^onu\s+type\s+(id|name)/i.test(clean)) return;
                  if (/^total\s*:\s*\d+/i.test(clean)) return;

                  // Format tabel: "12 ZTEG-F670 ..."
                  var matchTable = clean.match(/^\d+\s+([A-Za-z0-9._-]{3,})\b/);
                  if (matchTable && matchTable[1]) {
                    set.add(matchTable[1].trim());
                    return;
                  }
                });
                return Array.from(set).filter(Boolean).sort(function(a, b) { return a.localeCompare(b); });
              }

              function parseZteUncfgTypes(raw) {
                var set = new Set();
                String(raw || '').split('\n').forEach(function(line) {
                  var clean = line.replace(/\s*--More--\s*/gi, ' ').trim();
                  if (!clean) return;
                  var match = clean.match(/onu\s+\d+\s+type\s+(\S+)\s+sn\s+/i);
                  if (!match || !match[1]) return;
                  set.add(match[1].trim());
                });
                return Array.from(set).filter(Boolean).sort(function(a, b) { return a.localeCompare(b); });
              }

              function setLoading(isLoading, message) {
                if (!loadingEl) return;
                setInputsLoading(isLoading);
                loadingEl.classList.toggle('d-none', !isLoading);
                if (isLoading) {
                  var textEl = loadingEl.querySelector('.loading-text');
                  if (textEl) {
                    textEl.textContent = message || 'Memuat pilihan dari OLT...';
                  } else {
                    loadingEl.textContent = message || 'Memuat pilihan dari OLT...';
                  }
                }
              }

              function updateBrandNote() {
                if (!brandNoteEl) return;
                var meta = getSelectedOltMeta();
                if (!meta) {
                  brandNoteEl.className = 'alert alert-secondary py-2 mb-0';
                  brandNoteEl.textContent = 'Template hanya untuk field registrasi ONT. Pilihan VLAN/TCONT/Type akan mengikuti data yang ditampilkan dari OLT yang dipilih.';
                  return;
                }
                if (isZteBrand(meta.brand)) {
                  brandNoteEl.className = 'alert alert-info py-2 mb-0';
                  brandNoteEl.textContent = 'Brand ' + meta.brand + ': pilihan VLAN, TCONT, dan Type ONT dimuat otomatis saat OLT dipilih.';
                } else {
                  brandNoteEl.className = 'alert alert-warning py-2 mb-0';
                  brandNoteEl.textContent = 'Brand ' + meta.brand + ': pilihan dinamis dari OLT saat ini belum didukung. Template ONT tetap bisa diisi manual.';
                }
              }

              function wait(ms) {
                return new Promise(function(resolve) { setTimeout(resolve, ms); });
              }

              function zteLogin(meta) {
                var parsed = parseIpPort(meta.ip || '', 23);
                var fd = new FormData();
                fd.append('action', 'login');
                fd.append('ip', parsed.ip);
                fd.append('port', String(parsed.port));
                fd.append('username', meta.user || '');
                fd.append('password', meta.pass || '');
                fd.append('devname', meta.name || parsed.ip);
                return fetch(zteConsolePath, { method: 'POST', body: fd, credentials: 'same-origin' })
                  .then(function(response) { return response.json(); })
                  .then(function(data) {
                    if (data && data.error) throw new Error(data.error);
                    return data;
                  });
              }

              function zteRunAndPoll(command) {
                var runBody = new URLSearchParams();
                runBody.append('action', 'run');
                runBody.append('command', command);
                return fetch(zteConsolePath, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: runBody.toString(),
                  credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(async function(runData) {
                  if (!runData || runData.error || !runData.pid) {
                    throw new Error((runData && runData.error) ? runData.error : 'Gagal memulai proses baca OLT');
                  }
                  for (var i = 0; i < 150; i++) {
                    var statusResp = await fetch(zteConsolePath + '?action=status&pid=' + encodeURIComponent(runData.pid), { credentials: 'same-origin' });
                    var statusData = await statusResp.json();
                    if (statusData.status === 'done') {
                      return statusData.output || '';
                    }
                    if (statusData.status === 'error') {
                      throw new Error(statusData.message || 'Gagal membaca data OLT');
                    }
                    await wait(1200);
                  }
                  throw new Error('Timeout membaca data OLT');
                });
              }

              function loadZteOptionFromOlt() {
                var meta = getSelectedOltMeta();
                if (!meta || !meta.id) {
                  setLoading(false);
                  return;
                }
                if (!isZteBrand(meta.brand)) {
                  setLoading(false);
                  return;
                }

                setLoading(true, 'Memuat VLAN, TCONT, dan Type ONT dari ' + (meta.name || 'OLT') + '...');
                zteLogin(meta)
                  .then(function() {
                    return zteRunAndPoll('show vlan sum\nshow gpon profile tcont\nshow gpon onu uncfg\nshow onu-type gpon');
                  })
                  .then(function(raw) {
                    setSelectOptions('tpl_vlan_id', parseZteVlanSum(raw), '-- Pilih VLAN --');
                    setSelectOptions('tpl_tcont_profile', parseZteTcont(raw), '-- Pilih TCONT --');
                    var onuTypes = parseZteOnuTypeNames(raw);
                    if (!onuTypes.length) {
                      onuTypes = parseZteUncfgTypes(raw);
                    }
                    setSelectOptions('tpl_ont_type', onuTypes, '-- Pilih Type ONT --');
                    if (pendingTemplateData) {
                      applyTemplate(pendingTemplateData);
                    }
                  })
                  .catch(function(err) {
                    if (brandNoteEl) {
                      brandNoteEl.className = 'alert alert-danger py-2 mb-0';
                      brandNoteEl.textContent = 'Gagal ambil pilihan dari OLT: ' + (err && err.message ? err.message : 'unknown error');
                    }
                  })
                  .finally(function() {
                    setLoading(false);
                  });
              }

              function clearTemplateForm() {
                ['tpl_vlan_manual','tpl_service_name','tpl_vlan_profile','tpl_gemport','tpl_cos','tpl_ethuni'].forEach(function(id) {
                  setField(id, '');
                });
                setSelectOptions('tpl_ont_type', [], '-- Pilih Type ONT --');
                setSelectOptions('tpl_tcont_profile', [], '-- Pilih TCONT --');
                setSelectOptions('tpl_vlan_id', [], '-- Pilih VLAN --');
                setField('tpl_service_name', 'HSI');
                setField('tpl_vlan_profile', 'PPPoE');
                setField('tpl_gemport', '1');
                setField('tpl_cos', '0');
                setField('tpl_ethuni', '1,2,3');
              }

              function applyTemplate(data) {
                if (!data) {
                  clearTemplateForm();
                  return;
                }
                pendingTemplateData = data;
                setSelectValueIfExists('tpl_ont_type', data.ont_type);
                setSelectValueIfExists('tpl_tcont_profile', data.tcont_profile);
                setSelectValueIfExists('tpl_vlan_id', data.vlan_id);
                setField('tpl_vlan_manual', data.ont_sn || '');
                setField('tpl_service_name', data.service_name || 'HSI');
                setField('tpl_vlan_profile', data.vlan_profile || 'PPPoE');
                setField('tpl_gemport', data.gemport || '1');
                setField('tpl_cos', data.cos || '0');
                setField('tpl_ethuni', data.ethuni || '1,2,3');
              }

              function loadTemplateByOltId(oltId) {
                if (!oltId) {
                  pendingTemplateData = null;
                  clearTemplateForm();
                  return;
                }
                fetch('getdata/get_olt_template.php?olt_id=' + encodeURIComponent(oltId), { credentials: 'same-origin' })
                  .then(function(r) { return r.json(); })
                  .then(function(resp) {
                    if (resp && resp.success) {
                      applyTemplate(resp.data || null);
                    } else {
                      pendingTemplateData = null;
                      clearTemplateForm();
                    }
                  })
                  .catch(function() {
                    pendingTemplateData = null;
                    clearTemplateForm();
                  });
              }

              oltSelect.addEventListener('change', function() {
                updateBrandNote();
                loadTemplateByOltId(this.value);
                loadZteOptionFromOlt();
              });

              if (oltSelect.value) {
                updateBrandNote();
                loadTemplateByOltId(oltSelect.value);
                loadZteOptionFromOlt();
              } else {
                updateBrandNote();
              }
            })();
            </script>
          </div>
          </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- ================== FULLSCREEN REMOTE CONSOLE ================== -->
<div id="remoteContainer" style="display:none; width:100%; height:calc(100vh - 140px); background:#fff; border-radius:0.5rem; overflow:hidden; box-shadow:0 0.5rem 1rem rgba(0,0,0,.15);">
  <div style="display:flex; flex-direction:column; width:100%; height:100%;">
    <div class="bg-dark text-white d-flex justify-content-between align-items-center" style="flex-shrink:0; padding:12px 16px; min-height:56px;">
      <h5 class="mb-0" id="remoteModalLabel">
        <i class="fas fa-terminal me-2"></i>Remote OLT Console
      </h5>
      <button type="button" class="btn btn-light btn-sm" onclick="closeRemoteConsole()">
        <i class="fas fa-times me-1"></i>TUTUP
      </button>
    </div>

    <div style="flex:1; overflow:hidden; display:flex; background:#fff; height:calc(100% - 56px);">
      <iframe id="remoteFrame" name="remoteFrame" style="width:100%; height:100%; border:none;"></iframe>
    </div>
  </div>
</div>

<!-- Form hidden untuk POST ke iframe -->
<form id="remoteForm" method="POST" action="../olt/hioso/web_console.php" target="remoteFrame" style="display:none;">
  <input type="hidden" name="ip">
  <input type="hidden" name="user">
  <input type="hidden" name="pass">
  <input type="hidden" name="login" value="login">
</form>

<!-- Form hidden untuk GET ke iframe -->
<form id="remoteFormgpon" method="GET" action="getdata/olt/index.php" target="remoteFrame" style="display:none;">
  <input type="hidden" name="oltip">
  <input type="hidden" name="oltport" value="22">
  <input type="hidden" name="oltuser">
  <input type="hidden" name="oltpass">
  <input type="hidden" name="oltbrand">
  <input type="hidden" name="server">
</form>

<!-- Form hidden untuk CDATA -->
<form id="remoteFormcdata" method="POST" action="getdata/readontcdata.php" target="remoteFrame" style="display:none;">
  <input type="hidden" name="ip">
  <input type="hidden" name="port" value="161">
  <input type="hidden" name="community_read">
  <input type="hidden" name="community_write">
  <input type="hidden" name="login" value="login">
</form>

<!-- ZTE: no hidden form needed, login handled via fetch -->



<!-- ================== SCRIPT ================== -->
<script>
// Close Remote Console
function closeRemoteConsole() {
  document.getElementById('remoteContainer').style.display = 'none';
  document.getElementById('remoteFrame').src = 'about:blank';
  document.getElementById('oltManagementCard').style.display = 'block';
  document.getElementById('oltTableContainer').style.display = 'block';
  document.getElementById('oltButtonsSection').style.display = 'block';
  document.getElementById('oltSearchSection').style.display = 'block';
}

// Open Remote Console
function openRemoteConsole(title) {
  document.getElementById('oltManagementCard').style.display = 'none';
  document.getElementById('oltTableContainer').style.display = 'none';
  document.getElementById('oltButtonsSection').style.display = 'none';
  document.getElementById('oltSearchSection').style.display = 'none';
  document.getElementById('remoteContainer').style.display = 'block';
  document.getElementById('remoteModalLabel').innerText = title;
}

document.querySelectorAll('.open-remote').forEach(btn => {
  btn.addEventListener('click', function () {
    let form = document.getElementById('remoteForm');
    form.ip.value = this.dataset.ip;
    form.user.value = this.dataset.user;
    form.pass.value = this.dataset.pass;

    let portInfo = this.dataset.ip.includes(':80') ? ' (Web Interface - Port 80)' : '';
    let title = "Remote HIOSO EPON HA7302CST 2 PORT - " + this.dataset.device + " (" + this.dataset.user + ")" + portInfo;
    
    form.submit();
    openRemoteConsole(title);
  });
});

document.querySelectorAll('.open-remotegpon').forEach(btn => {
  btn.addEventListener('click', function () {
    let form = document.getElementById('remoteFormgpon');
    
    let ipData = this.dataset.ip;
    let ip = ipData;
    let port = '22';
    
    if (ipData.includes(':')) {
      let parts = ipData.split(':');
      ip = parts[0];
      port = parts[1];
    }
    
    form.oltip.value = ip;
    form.oltport.value = port;
    form.oltuser.value = this.dataset.user;
    form.oltpass.value = this.dataset.pass;
    form.oltbrand.value = this.dataset.brand || 'GPON LAIN';
    form.server.value = this.dataset.server || 'unknown';

    let title = "OLT Management - " + this.dataset.device + " (" + ip + ") - " + this.dataset.brand;
    
    form.submit();
    openRemoteConsole(title);
  });
});

document.querySelectorAll('.open-olt-console').forEach(btn => {
  btn.addEventListener('click', function () {
    const ipData = this.dataset.ip || '';
    const user = this.dataset.user || '';
    const pass = this.dataset.pass || '';
    const device = this.dataset.device || '';
    const brand = this.dataset.brand || '';
    const consolePath = this.dataset.consolePath || '';
    const model = this.dataset.model || '';
    const label = this.dataset.label || 'OLT Console';
    const oltId = this.dataset.oltId || '';

    if (!consolePath) {
      return;
    }

    let oltIp = ipData;
    let oltPort = 23;
    if (ipData.includes(':')) {
      const parts = ipData.split(':');
      oltIp = parts[0];
      oltPort = parseInt(parts[1], 10) || 23;
    }

    let title = label + ' - ' + device + ' (' + oltIp + ') - ' + brand;

    const frame = document.getElementById('remoteFrame');
    frame.src = 'about:blank';

    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('ip', oltIp);
    fd.append('port', String(oltPort));
    fd.append('username', user);
    fd.append('password', pass);
    fd.append('devname', device);
    if (model) {
      fd.append('model', model);
    }
    if (oltId) {
      fd.append('olt_id', oltId);
    }

    fetch(consolePath, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          frame.srcdoc = '<p style="color:red;font-family:sans-serif;padding:20px">Login gagal: ' + data.error + '</p>';
        } else {
          frame.src = consolePath;
        }
      })
      .catch(() => {
        frame.srcdoc = '<p style="color:red;font-family:sans-serif;padding:20px">Gagal menghubungi console OLT.</p>';
      });

    openRemoteConsole(title);
  });
});

document.querySelectorAll('.open-cdata').forEach(btn => {
  btn.addEventListener('click', function () {
    let form = document.getElementById('remoteFormcdata');
    form.ip.value = this.dataset.ip;
    form.community_read.value = this.dataset.community_read;
    form.community_write.value = this.dataset.community_write;

    let title = "Remote CDATA GPON - " + this.dataset.device + " (" + this.dataset.community_read + ")";
    
    form.submit();
    openRemoteConsole(title);
  });
});

document.querySelectorAll('.open-zte').forEach(btn => {
  btn.addEventListener('click', function () {
    const ip       = this.dataset.ip;
    const user     = this.dataset.user;
    const pass     = this.dataset.pass;
    const device   = this.dataset.device;

    let oltIp   = ip;
    let oltPort = 23;
    if (ip.includes(':')) {
      const parts = ip.split(':');
      oltIp   = parts[0];
      oltPort = parseInt(parts[1]) || 23;
    }

    let title = 'ZTE GPON C300/320 Console - ' + device + ' (' + oltIp + ')';

    const frame = document.getElementById('remoteFrame');
    frame.src = 'about:blank';

    const fd = new FormData();
    fd.append('action',   'login');
    fd.append('ip',       oltIp);
    fd.append('port',     oltPort);
    fd.append('username', user);
    fd.append('password', pass);
    fd.append('devname',  device);

    fetch('../olt/zte/index.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json())
      .then(data => {
        if (data.error) {
          frame.srcdoc = '<p style="color:red;font-family:sans-serif;padding:20px">Login gagal: ' + data.error + '</p>';
        } else {
          frame.src = '../olt/zte/index.php';
        }
      })
      .catch(() => {
        frame.srcdoc = '<p style="color:red;font-family:sans-serif;padding:20px">Gagal menghubungi ZTE console.</p>';
      });

    openRemoteConsole(title);
  });
});

// Close console when ESC key pressed
document.addEventListener('keydown', function(event) {
  if(event.key === 'Escape') {
    closeRemoteConsole();
  }
});

// ================== OLT DATA CACHING ==================
const OLT_CACHE_KEY = 'qts_olt_data_cache';
const OLT_CACHE_TIME_KEY = 'qts_olt_cache_time';
const CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

// Get all OLT systems mapped to brand
const OLT_CONSOLES = {
  'ZTE GPON C300/320': { brand: 'ZTE', icon: 'fas fa-microchip' },
  'ZTE GPON C300': { brand: 'ZTE', icon: 'fas fa-microchip' },
  'ZTE GPON C320': { brand: 'ZTE', icon: 'fas fa-microchip' },
  'ZTE GPON C600': { brand: 'ZTE', icon: 'fas fa-microchip' },
  'CDATA GPON': { brand: 'CDATA', icon: 'fas fa-database' },
  'CDATA GPON FD1601S': { brand: 'CDATA', icon: 'fas fa-database' },
  'CDATA GPON FD1608S-B1': { brand: 'CDATA', icon: 'fas fa-database' },
  'CDATA EPON FD1104/FD1208': { brand: 'CDATA', icon: 'fas fa-database' },
  'HUAWEI MA5608T': { brand: 'HUAWEI', icon: 'fas fa-server' },
  'HUAWEI MA5680T': { brand: 'HUAWEI', icon: 'fas fa-server' },
  'HUAWEI MA5683T': { brand: 'HUAWEI', icon: 'fas fa-server' },
  'VSOL GPON V1600G0/G1': { brand: 'VSOL', icon: 'fas fa-network-wired' },
  'VSOL EPON V1600D': { brand: 'VSOL', icon: 'fas fa-network-wired' },
  'HSGQ GPON': { brand: 'HSGQ', icon: 'fas fa-wifi' },
  'HSGQ EPON': { brand: 'HSGQ', icon: 'fas fa-wifi' },
  'HIOSO EPON': { brand: 'HIOSO', icon: 'fas fa-lightbulb' },
  'HIOSO EPON HA7302CST': { brand: 'HIOSO', icon: 'fas fa-lightbulb' },
  'HIOSO EPON HA7304': { brand: 'HIOSO', icon: 'fas fa-lightbulb' },
  'HIOSO EPON HA7308': { brand: 'HIOSO', icon: 'fas fa-lightbulb' }
};

// Fetch OLT data and cache it
function fetchAndCacheOltData() {
  fetch('getdata/olt-cache.php', {
    method: 'GET',
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(response => {
    if (!response.ok) throw new Error('Cache fetch failed');
    return response.json();
  })
  .then(data => {
    if (data.success && data.data) {
      // Cache the data
      localStorage.setItem(OLT_CACHE_KEY, JSON.stringify(data.data));
      localStorage.setItem(OLT_CACHE_TIME_KEY, Date.now().toString());
      console.log('[OLT Cache] Updated with ' + data.count + ' items');
      
      // Update table with fresh data
      updateOltTableFromCache(data.data);
    }
  })
  .catch(error => console.log('[OLT Cache] Fetch error:', error.message));
}

// Build HTML for OLT row
function buildOltRow(olt) {
  const isHiosoWeb = ['HIOSO EPON', 'HIOSO EPON HA7302CST'].includes(olt.brandolt);
  const brandDisplay = isHiosoWeb ? 'HIOSO EPON HA7302CST 2 PORT' : olt.brandolt;
  const portInfo = isHiosoWeb ? '<br><small class="text-info"><i class="fas fa-info-circle"></i> Web Interface (Port 80)</small>' : '';
  
  let actionButtons = `
    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal${olt.id}">
      Edit
    </button>
    <button type="button" class="btn btn-sm btn-info" onclick="openOltConsole('${escapeHtml(olt.ipolt)}', '${escapeHtml(olt.usernameolt)}', '${escapeHtml(olt.passwordolt)}', '${escapeHtml(olt.oltname)}', '${escapeHtml(olt.brandolt)}')">
      Console
    </button>
  `;
  
  return `
    <tr>
      <td>
        <div class="d-flex px-2 py-1">
          <div>
            <img src="https://img.icons8.com/plasticine/100/stack.png" class="avatar me-3" alt="OLT" width="130px" height="130px">
          </div>
          <div class="d-flex flex-column justify-content-center">
            Area: ${escapeHtml(olt.area)}<br>
            Name OLT: ${escapeHtml(olt.oltname)}<br>
            Brand: ${brandDisplay} ${portInfo}
          </div>
        </div>
      </td>
      <td class="text-center">
        <code class="text-xs" style="background: #f8f9fa; padding: 0.4rem 0.6rem; border-radius: 0.25rem; display: inline-block;">${escapeHtml(olt.ipolt)}</code>
      </td>
      <td class="text-center">${escapeHtml(olt.pemilik)}</td>
      <td class="text-center">
        ${actionButtons}
      </td>
    </tr>
  `;
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Update table from cached data
function updateOltTableFromCache(oltData) {
  const tbody = document.getElementById('oltTableBody');
  if (!tbody) return;
  
  if (oltData.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No OLT data available</td></tr>';
    return;
  }
  
  tbody.innerHTML = oltData.map(olt => buildOltRow(olt)).join('');
  console.log('[OLT Cache] Table updated with ' + oltData.length + ' OLTs');
}

// Open OLT console from cache
function openOltConsole(ip, user, pass, device, brand) {
  // Determine console type based on brand
  if (brand.includes('ZTE')) {
    const ipData = ip + ':23';
    const btn = {
      dataset: {
        ip: ipData,
        user: user,
        pass: pass,
        device: device
      }
    };
    // Trigger ZTE console handler
    const event = new Event('click');
    Object.assign(btn, { addEventListener: () => {} });
    document.querySelectorAll('.open-zte')[0]?.dispatchEvent(event);
    // Manually handle since we don't have the button element
    triggerZteConsole(ip, user, pass, device);
  } else if (brand.includes('CDATA')) {
    const btn = {
      dataset: {
        ip: ip,
        device: device,
        community_read: user,
        community_write: pass
      }
    };
    triggerCdataConsole(ip, device, user, pass);
  } else if (brand.includes('HIOSO')) {
    const btn = {
      dataset: {
        ip: ip,
        user: user,
        pass: pass,
        device: device
      }
    };
    triggerHiosoConsole(ip, user, pass, device);
  } else if (brand.includes('HUAWEI') || brand.includes('VSOL') || brand.includes('HSGQ')) {
    triggerGenericConsole(ip, user, pass, device, brand);
  }
}

// Trigger ZTE console
function triggerZteConsole(ip, user, pass, device) {
  const oltIp = ip;
  const oltPort = 23;
  const title = 'ZTE GPON C300/320 Console - ' + device + ' (' + oltIp + ')';
  const frame = document.getElementById('remoteFrame');
  frame.src = 'about:blank';
  
  const fd = new FormData();
  fd.append('action', 'login');
  fd.append('ip', oltIp);
  fd.append('port', oltPort);
  fd.append('username', user);
  fd.append('password', pass);
  fd.append('devname', device);
  
  fetch('../olt/zte/index.php', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (data.error) {
        frame.srcdoc = '<p style="color:red;font-family:sans-serif;padding:20px">Login gagal: ' + data.error + '</p>';
      } else {
        frame.src = '../olt/zte/index.php';
      }
    })
    .catch(() => {
      frame.srcdoc = '<p style="color:red;font-family:sans-serif;padding:20px">Gagal menghubungi ZTE console.</p>';
    });
  
  openRemoteConsole(title);
}

// Trigger CDATA console
function triggerCdataConsole(ip, device, community_read, community_write) {
  const form = document.getElementById('remoteFormcdata');
  form.ip.value = ip;
  form.community_read.value = community_read;
  form.community_write.value = community_write;
  
  const title = "Remote CDATA GPON - " + device + " (" + community_read + ")";
  form.submit();
  openRemoteConsole(title);
}

// Trigger HIOSO console
function triggerHiosoConsole(ip, user, pass, device) {
  const form = document.getElementById('remoteForm');
  form.ip.value = ip;
  form.user.value = user;
  form.pass.value = pass;
  
  const portInfo = ip.includes(':80') ? ' (Web Interface - Port 80)' : '';
  const title = "Remote HIOSO EPON HA7302CST 2 PORT - " + device + " (" + user + ")" + portInfo;
  
  form.submit();
  openRemoteConsole(title);
}

// Load OLT data from cache on page load
document.addEventListener('DOMContentLoaded', function() {
  // First, check if we have cached data
  const cachedData = localStorage.getItem(OLT_CACHE_KEY);
  const cacheTime = localStorage.getItem(OLT_CACHE_TIME_KEY);
  const now = Date.now();
  
  // If cache exists and is recent, use it immediately
  if (cachedData && cacheTime && (now - parseInt(cacheTime)) < CACHE_DURATION) {
    try {
      const oltData = JSON.parse(cachedData);
      console.log('[OLT Cache] Using cached data (' + oltData.length + ' items)');
      updateOltTableFromCache(oltData);
    } catch (e) {
      console.log('[OLT Cache] Cache parse error:', e.message);
    }
  } else {
    console.log('[OLT Cache] Cache expired or empty');
  }
  
  // Always fetch fresh data in background
  fetchAndCacheOltData();
  
  // Set interval to refresh cache every 5 minutes
  setInterval(fetchAndCacheOltData, CACHE_DURATION);
});
</script>




<?php require 'footer.php'; ?>


