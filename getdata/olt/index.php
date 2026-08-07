<?php
// Get OLT parameters from GET request
$oltip = $_GET['oltip'] ?? '';
$oltport = $_GET['oltport'] ?? '22';
$oltuser = $_GET['oltuser'] ?? '';
$oltpass = $_GET['oltpass'] ?? '';
$oltbrand = $_GET['oltbrand'] ?? '';
$oltmethod = $_GET['oltmethod'] ?? 'ssh'; // Default SSH
$server = $_GET['server'] ;
// Auto-load mode if all parameters are provided
$autoLoad = !empty($oltip) && !empty($oltuser) && !empty($oltpass) && !empty($oltbrand);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>OLT Manager - Direct Access</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
  <style>
    body { background: #f8f9fa; }
    .login-card, .main-card { max-width: 1200px; margin: auto; margin-top: 20px; }
    .hidden { display: none; }
    .monos { font-family: monospace; }
    .power-good { color: #28a745; font-weight: bold; }
    .power-warning { color: #ffc107; font-weight: bold; }
    .power-danger { color: #dc3545; font-weight: bold; }
    .connection-status { padding: 10px; border-radius: 5px; margin-bottom: 15px; }
    .connection-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    .connection-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
  </style>
</head>
<body>

<div class="container">
  <!-- LOGIN FORM -->
  <div class="card login-card <?= $autoLoad ? 'hidden' : '' ?>">
    <div class="card-body">
      <h4 class="mb-3">Koneksi ke OLT</h4>
      <form method="GET" action="">
        <div class="row g-2">

          <div class="col-md-3">
            <label class="form-label">Brand OLT</label>
            <select name="oltbrand" class="form-select" required>
              <option value="">-- Pilih Brand --</option>
              <option value="HUAWEI GPON" <?= $oltbrand === 'HUAWEI GPON' ? 'selected' : '' ?>>HUAWEI GPON</option>
              <option value="ZTE GPON" <?= $oltbrand === 'ZTE GPON' ? 'selected' : '' ?>>ZTE GPON</option>
              <option value="FIBERHOME GPON" <?= $oltbrand === 'FIBERHOME GPON' ? 'selected' : '' ?>>FIBERHOME GPON</option>
              <option value="NOKIA GPON" <?= $oltbrand === 'NOKIA GPON' ? 'selected' : '' ?>>NOKIA GPON</option>
              <option value="VSOL GPON" <?= $oltbrand === 'VSOL GPON' ? 'selected' : '' ?>>VSOL GPON</option>
              <option value="VSOL EPON" <?= $oltbrand === 'VSOL EPON' ? 'selected' : '' ?>>VSOL EPON</option>
              <option value="HSGQ GPON" <?= $oltbrand === 'HSGQ GPON' ? 'selected' : '' ?>>HSGQ GPON</option>
              <option value="HSGQ EPON" <?= $oltbrand === 'HSGQ EPON' ? 'selected' : '' ?>>HSGQ EPON</option>
              <option value="EPON LAIN" <?= $oltbrand === 'EPON LAIN' ? 'selected' : '' ?>>EPON LAIN</option>
              <option value="GPON LAIN" <?= $oltbrand === 'GPON LAIN' ? 'selected' : '' ?>>GPON LAIN</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">IP OLT</label>
            <input type="text" name="oltip" class="form-control" placeholder="192.168.1.2" required value="<?= htmlspecialchars($oltip) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Port</label>
            <input type="number" name="oltport" class="form-control" value="<?= htmlspecialchars($oltport) ?>" required>
          </div>

          <div class="col-md-2">
            <label class="form-label">Username</label>
            <input type="text" name="oltuser" class="form-control" required value="<?= htmlspecialchars($oltuser) ?>">
          </div>

          <div class="col-md-2">
            <label class="form-label">Password</label>
            <input type="password" name="oltpass" class="form-control" required value="<?= htmlspecialchars($oltpass) ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label">Metode Akses</label>
            <select name="oltmethod" class="form-select" required>
              <option value="">-- Pilih Metode --</option>
              <option value="ssh" <?= $oltmethod === 'ssh' ? 'selected' : '' ?>>SSH (Secure Shell)</option>
              <option value="snmp" <?= $oltmethod === 'snmp' ? 'selected' : '' ?>>SNMP (Simple Network Management Protocol)</option>
            </select>
          </div>

          <div class="col-md-6 d-flex align-items-end">
            <button class="btn btn-primary w-100">Connect to OLT</button>
          </div>
        </div>
      </form>
      <div class="mt-3">
        <small class="text-muted">
          <strong>URL Format:</strong><br>
          <code>?oltip=192.168.1.1&oltport=22&oltuser=admin&oltpass=admin123&oltbrand=HUAWEI GPON&oltmethod=ssh</code>
        </small>
      </div>
    </div>
  </div>

  <!-- MAIN APP -->
  <div class="card main-card <?= $autoLoad ? '' : 'hidden' ?>" id="mainApp">
    <div class="card-body">
      <div class="d-flex justify-content-between mb-3">
        <h4>OLT Manager - <span id="vendorName">Loading...</span></h4>
        <div>
          <span class="badge bg-info me-2" id="oltInfo"><?= $oltip ?></span>
          <button class="btn btn-danger btn-sm" onclick="window.location.href=window.location.pathname">Disconnect</button>
        </div>
      </div>

      <!-- Connection Status -->
      <div id="connectionStatus"></div>

      <div class="row">
        <div class="col-md-3">
          <label class="form-label">Pilih PON</label>
          <select id="ponSelect" class="form-select">
            <option value="">Loading PON list...</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Metode Akses</label>
          <select id="methodSelect" class="form-select">
            <option value="ssh" <?= $oltmethod === 'ssh' ? 'selected' : '' ?>>SSH</option>
            <option value="snmp" <?= $oltmethod === 'snmp' ? 'selected' : '' ?>>SNMP</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Total ONU</label>
          <input type="text" id="totalOnu" class="form-control" readonly>
        </div>
        <div class="col-md-2">
          <label class="form-label">ONU Online</label>
          <input type="text" id="onuOnline" class="form-control" readonly>
        </div>
        <div class="col-md-2">
          <label class="form-label">Action</label>
          <button class="btn btn-success w-100" onclick="refreshConnection()">
            <i class="fas fa-sync"></i> Refresh
          </button>
        </div>
      </div>

      <hr>

      <!-- ONT Table -->
      <div class="table-responsive">
        <table class="table table-bordered table-hover" id="ontTable">
          <thead class="table-dark">
            <tr>
              <th>No</th>
              <th>MAC Address</th>
              <th>RX Power</th>
              <th>TX Power</th>
              <th>Status</th>
              <th>Port</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- MODAL CONFIG -->
<div class="modal fade" id="configOntModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Configure ONT</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <p><strong>MAC Address:</strong> <span id="configMacAddress"></span></p>
            <p><strong>Current Status:</strong> <span id="configCurrentStatus"></span></p>
          </div>
          <div class="col-md-4">
            <button class="btn btn-success w-100 configBtn" data-action="enable">Enable ONT</button>
          </div>
          <div class="col-md-4">
            <button class="btn btn-warning w-100 configBtn" data-action="disable">Disable ONT</button>
          </div>
          <div class="col-md-4">
            <button class="btn btn-danger w-100 configBtn" data-action="reboot">Reboot ONT</button>
          </div>
        </div>
        <div id="configResult" class="mt-3"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
// Global variables
let oltParams = {
    ip: '<?= $oltip ?>',
    port: '<?= $oltport ?>',
    username: '<?= $oltuser ?>',
    password: '<?= $oltpass ?>',
    brand: '<?= $oltbrand ?>',
    method: '<?= $oltmethod ?>',
    server: '<?= $server ?? 'unknown' ?>'
};

$(function(){
    <?php if ($autoLoad): ?>
    // Auto-load if parameters are provided
    loadPonList();
    <?php endif; ?>

    // PON selection change event
    $("#ponSelect").change(function(){
        let pon = $(this).val();
        if (pon) {
            loadOnuList(pon);
        }
    });

    // Method selection change event
    $("#methodSelect").change(function(){
        oltParams.method = $(this).val();
        $("#connectionStatus").html('<div class="alert alert-info">Method changed to ' + oltParams.method.toUpperCase() + '. Please refresh connection.</div>');
    });

    // Configuration button clicks
    $(document).on('click', '.configBtn', function(){
        let action = $(this).data('action');
        let pon = $("#ponSelect").val();
        let ontId = $(this).closest('tr').data('ont-id');
        
        executeConfig(pon, ontId, action);
    });

    // Show config modal
    $(document).on('click', '.showConfigBtn', function(){
        let mac = $(this).data('mac');
        let status = $(this).data('status');
        
        $("#configMacAddress").text(mac);
        $("#configCurrentStatus").text(status);
        $("#configOntModal").modal('show');
    });
});

function loadPonList() {
    $("#connectionStatus").html('<div class="connection-status">Connecting to OLT...</div>');
    
    // Update method from selector
    oltParams.method = $("#methodSelect").val() || 'ssh';
    
    $.getJSON("olt_api.php", {
        action: 'list_pons',
        ip: oltParams.ip,
        port: oltParams.port,
        username: oltParams.username,
        password: oltParams.password,
        brand: oltParams.brand,
        method: oltParams.method
    })
    .done(function(data){
        if (data.success) {
            $("#connectionStatus").html('<div class="connection-status connection-success">✓ Connected to ' + data.vendor + ' via ' + data.method + '</div>');
            $("#vendorName").text(data.vendor);
            
            $("#ponSelect").empty().append('<option value="">-- Select PON --</option>');
            $.each(data.pons, function(i, pon){
                $("#ponSelect").append(`<option value="${pon}">PON ${pon}</option>`);
            });
            
            if (data.message) {
                $("#connectionStatus").append('<div class="alert alert-warning mt-2">' + data.message + '</div>');
            }
        } else {
            $("#connectionStatus").html('<div class="connection-status connection-error">✗ Connection failed: ' + data.error + '</div>');
        }
    })
    .fail(function(){
        $("#connectionStatus").html('<div class="connection-status connection-error">✗ Connection failed: Network error</div>');
    });
}

function loadOnuList(pon) {
    $("#ontTable tbody").html('<tr><td colspan="7" class="text-center">Loading ONU data...</td></tr>');
    
    // Update method from selector
    oltParams.method = $("#methodSelect").val() || 'ssh';
    
    $.getJSON("olt_api.php", {
        action: 'list_onts',
        ip: oltParams.ip,
        port: oltParams.port,
        username: oltParams.username,
        password: oltParams.password,
        brand: oltParams.brand,
        method: oltParams.method,
        server: oltParams.server,
        pon: pon
    })
    .done(function(data){
        let tbody = $("#ontTable tbody").empty();
        let totalOnu = 0;
        let onuOnline = 0;
        
        if (data.success && data.onts.length > 0) {
            $.each(data.onts, function(i, ont){
                totalOnu++;
                if (ont.status === 'Online') onuOnline++;
                
                let rowClass = '';
                let powerClass = '';
                
                // Determine row color based on status
                if (ont.status === 'Online') {
                    rowClass = 'table-success';
                } else if (ont.status === 'Offline') {
                    rowClass = 'table-danger';
                } else {
                    rowClass = 'table-warning';
                }
                
                // Determine power level colors
                if (ont.rx && ont.rx !== 'N/A') {
                    let rxValue = parseFloat(ont.rx.replace(' dBm', ''));
                    if (rxValue >= -25) {
                        powerClass = 'power-good';
                    } else if (rxValue >= -30) {
                        powerClass = 'power-warning';
                    } else {
                        powerClass = 'power-danger';
                    }
                }
                
                tbody.append(`
                    <tr class="${rowClass}" data-ont-id="${ont.ont_id}">
                        <td>${i + 1}</td>
                        <td class="monos">${ont.mac}</td>
                        <td class="${powerClass}">${ont.rx}</td>
                        <td class="${powerClass}">${ont.tx}</td>
                        <td>
                            <span class="badge ${ont.status === 'Online' ? 'bg-success' : ont.status === 'Offline' ? 'bg-danger' : 'bg-warning'}">${ont.status}</span>
                        </td>
                        <td>${ont.port || 'N/A'}</td>
                        <td>
                            <button class="btn btn-sm btn-primary showConfigBtn" data-mac="${ont.mac}" data-status="${ont.status}">Config</button>
                        </td>
                    </tr>
                `);
            });
        } else {
            tbody.append('<tr><td colspan="7" class="text-center text-muted">No ONU found on PON ' + pon + '</td></tr>');
        }
        
        $("#totalOnu").val(totalOnu);
        $("#onuOnline").val(onuOnline);
        
        // Show file save notification if available
        if (data.saved_file) {
            $("#connectionStatus").append('<div class="alert alert-success mt-2">✓ Data ONU disimpan ke file: ' + data.saved_file + '</div>');
        }
    })
    .fail(function(){
        $("#ontTable tbody").html('<tr><td colspan="7" class="text-center text-danger">Failed to load ONU data</td></tr>');
    });
}

function executeConfig(pon, ontId, action) {
    $("#configResult").html('<div class="alert alert-info">Executing ' + action + '...</div>');
    
    // Update method from selector
    oltParams.method = $("#methodSelect").val() || 'ssh';
    
    $.getJSON("olt_api.php", {
        action: 'configure_ont',
        ip: oltParams.ip,
        port: oltParams.port,
        username: oltParams.username,
        password: oltParams.password,
        brand: oltParams.brand,
        method: oltParams.method,
        pon: pon,
        ont_id: ontId,
        config_type: action
    })
    .done(function(data){
        if (data.success) {
            $("#configResult").html('<div class="alert alert-success">' + data.message + '</div>');
            // Reload ONU list after configuration
            setTimeout(function(){
                loadOnuList(pon);
                $("#configOntModal").modal('hide');
            }, 2000);
        } else {
            $("#configResult").html('<div class="alert alert-danger">Configuration failed: ' + data.error + '</div>');
        }
    })
    .fail(function(){
        $("#configResult").html('<div class="alert alert-danger">Configuration failed: Network error</div>');
    });
}

// Refresh connection function
function refreshConnection() {
    $("#ponSelect").empty().append('<option value="">Loading PON list...</option>');
    $("#ontTable tbody").html('<tr><td colspan="7" class="text-center">Refreshing...</td></tr>');
    $("#totalOnu").val('');
    $("#onuOnline").val('');
    loadPonList();
}
</script>
</body>
</html>
