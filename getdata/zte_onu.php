<?php
session_start();

function snmp_walk($ip, $port, $community, $oid) {
    return @snmpwalk($ip . ":$port", $community, $oid, 1000000, 2);
}

if(isset($_GET['logout'])){
    session_destroy();
    header("Location: zte_onu.php");
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['login'])){
    $_SESSION['ip']=trim($_POST['ip']??'');
    $_SESSION['port']=trim($_POST['port']??'161');
    $_SESSION['community_read']=trim($_POST['community_read']??'');
    $_SESSION['community_write']=trim($_POST['community_write']??'');
}

$logged_in = !empty($_SESSION['ip']) && !empty($_SESSION['port']) && !empty($_SESSION['community_read']);

if(isset($_GET['fetch'])){
    header('Content-Type: application/json');
    if(!$logged_in){ echo json_encode(['error'=>'not_logged_in']); exit; }

    // ------ siapkan folder debug per session ------
    $session_id = session_id();
    $debug_dir = __DIR__."/debug_session/".$session_id;
    if(!is_dir($debug_dir)){
        mkdir($debug_dir, 0777, true);
    }

    $ip = $_SESSION['ip'];
    $port = $_SESSION['port'];
    $community = $_SESSION['community_read'];

    // OID untuk ONU list (dari NSCRTV-FTTX-GPON-MIB)
    $onu_oids = [
        'onuDeviceIndex' => '1.3.6.1.4.1.17409.2.8.4.1.1.1',
        'onuName' => '1.3.6.1.4.1.17409.2.8.4.1.1.2',
        'onuSerialNum' => '1.3.6.1.4.1.17409.2.8.4.1.1.3',
        'onuOperationStatus' => '1.3.6.1.4.1.17409.2.8.4.1.1.7',
        'onuAdminStatus' => '1.3.6.1.4.1.17409.2.8.4.1.1.8',
        'onuTestDistance' => '1.3.6.1.4.1.17409.2.8.4.1.1.9',
        'onuReceivedOpticalPower' => '1.3.6.1.4.1.17409.2.8.4.4.1.4',
        'onuTramsmittedOpticalPower' => '1.3.6.1.4.1.17409.2.8.4.4.1.5',
        'onuBiasCurrent' => '1.3.6.1.4.1.17409.2.8.4.4.1.6',
        'onuWorkingVoltage' => '1.3.6.1.4.1.17409.2.8.4.4.1.7',
        'onuWorkingTemperature' => '1.3.6.1.4.1.17409.2.8.4.4.1.8',
    ];

    $onu_rows = [];
    foreach($onu_oids as $label => $oid){
        $walk = snmp_walk($ip, $port, $community, $oid);
        if($walk !== false){
            foreach($walk as $oid_key => $value){
                $parts = explode('.', $oid_key);
                $idx = end($parts);  // Asumsi index adalah ONU ID
                if(!isset($onu_rows[$idx])) $onu_rows[$idx] = [];
                $onu_rows[$idx][$label] = str_replace(['STRING: ', 'INTEGER: ', '"'], '', $value);
            }
        }
    }

    // Convert to array of arrays like original
    $onu_array = [];
    foreach($onu_rows as $idx => $data){
        $onu_array[] = [
            $idx, // ONU No
            '', // Placeholder
            $data['onuSerialNum'] ?? '',
            $data['onuOperationStatus'] ?? '',
            $data['onuAdminStatus'] ?? '',
            '', // MTU
            '', // CHIP
            '', // PORTNUM
            $data['onuTestDistance'] ?? '', // Distance
            $data['onuWorkingTemperature'] ?? '', // Temperature
            $data['onuTramsmittedOpticalPower'] ?? '', // Tx Power
            $data['onuReceivedOpticalPower'] ?? '', // Rx Power
            $data['onuBiasCurrent'] ?? '', // Bias Current
            $data['onuWorkingVoltage'] ?? '', // Voltage
        ];
    }

    // hapus dan buat ulang onu_list.txt
    @unlink($debug_dir.'/onu_list.txt');
    file_put_contents($debug_dir.'/onu_list.txt', json_encode($onu_array, JSON_PRETTY_PRINT));
    chmod($debug_dir.'/onu_list.txt', 0777);

    echo json_encode(['onu'=>$onu_array]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>ZTE C300/C320 ONU Auto Manager - SNMP</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<style>
body {
	background: #f6f7fb;
}
.main-flat-card {
	background: #fff;
	border-radius: 12px;
	box-shadow: 0 2px 12px 0 rgba(80,100,120,0.08);
	border: 1px solid #e6eaf0;
	margin: 32px 0 0 0;
	padding: 0;
}
.main-flat-card .card-header {
	background: #f7f9fb;
	border-bottom: 1px solid #e6eaf0;
	border-radius: 12px 12px 0 0;
	padding: 1.5rem 2rem;
}
.main-flat-card .card-body {
	padding: 2rem;
}
.dataTables_wrapper .dataTables_filter {
	float: right;
	margin-bottom: 1rem;
}
.dataTables_wrapper .dataTables_length {
	float: left;
	margin-bottom: 1rem;
}
.dataTables_wrapper .dataTables_paginate {
	float: right;
	margin-top: 1rem;
}
.dataTables_wrapper .dataTables_info {
	float: left;
	margin-top: 1rem;
}
.table-flat {
	background: #fff;
	border-radius: 8px;
	border: none;
	font-size: 15px;
	color: #222;
	box-shadow: none;
}
.table-flat th {
	background: #f7f9fb;
	color: #6c63ff;
	font-weight: 600;
	border: none;
	text-align: center;
}
.table-flat td {
	border: none;
	text-align: center;
	vertical-align: middle;
	background: #fff;
}
.table-flat tbody tr {
	transition: background 0.2s;
}
.table-flat tbody tr:hover {
	background: #f3f6fa;
}
.btn-action {
	background: #f7f7fc;
	color: #6c63ff;
	border: 1px solid #e6eaf0;
	border-radius: 6px;
	font-size: 13px;
	padding: 2px 14px;
	margin: 0 2px;
	transition: background 0.2s, color 0.2s;
}
.btn-action:hover {
	background: #6c63ff;
	color: #fff;
}
.status-dot {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-right: 6px;
}
.status-online {
	background: #2ecc71;
}
.status-offline {
	background: #e74c3c;
}
.search-bar {
	background: #f7f9fb;
	border-radius: 8px;
	padding: 1rem 1.5rem;
	margin-bottom: 1.5rem;
	display: flex;
	gap: 1rem;
	align-items: center;
}
.search-bar input, .search-bar select {
	border-radius: 6px;
	border: 1px solid #e6eaf0;
	padding: 0.5rem 1rem;
	font-size: 15px;
	background: #fff;
}
.search-bar label {
	font-weight: 500;
	color: #888;
	margin-right: 0.5rem;
}
.search-bar .btn {
	min-width: 90px;
}
</style>
</head>
<body class="bg-light">
<div class="container-fluid">
<?php if(!$logged_in): ?>
<div class="main-flat-card">
<div class="card-header">
<h5 class="mb-0">Login to ZTE C300/C320 ONU Manager</h5>
</div>
<div class="card-body">
<form method="post">
<div class="mb-3">
<label for="ip" class="form-label">IP / Host</label>
<input type="text" name="ip" class="form-control" required>
</div>
<div class="mb-3">
<label for="port" class="form-label">Port</label>
<input type="text" name="port" class="form-control" value="161" required>
</div>
<div class="mb-3">
<label for="community_read" class="form-label">Community Read</label>
<input type="text" name="community_read" class="form-control" required>
</div>
<div class="mb-3">
<label for="community_write" class="form-label">Community Write (optional)</label>
<input type="text" name="community_write" class="form-control">
</div>
<button type="submit" name="login" class="btn btn-primary">Login</button>
</form>
</div>
</div>
<?php else: ?>
<div class="main-flat-card">
<div class="card-header d-flex justify-content-between align-items-center">
<h5 class="mb-0">ZTE C300/C320 ONU Auto Manager - SNMP</h5>
<a href="?logout=1" class="btn btn-outline-secondary btn-sm">Logout</a>
</div>
<div class="card-body">
<div id="loading" class="text-center">
<div class="spinner-border text-primary" role="status">
<span class="visually-hidden">Loading...</span>
</div>
<p>Loading data...</p>
</div>
<table id="onu_table" class="table table-flat table-striped">
<thead>
<tr>
<th>#</th>
<th>ONU No</th>
<th>MAC</th>
<th>Status</th>
<th>MTU</th>
<th>CHIP</th>
<th>PORTNUM</th>
<th>Distance (m)</th>
<th>Temperature (°C)</th>
<th>Tx Power (dBm)</th>
<th>Rx Power (dBm)</th>
<th>Bias Current (mA)</th>
<th>Voltage (V)</th>
</tr>
</thead>
<tbody id="onu_tbody">
<!-- Data akan dimuat di sini -->
</tbody>
</table>
</div>
</div>
<?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script>
$(document).ready(function() {
    $('#onu_table').DataTable({
        "paging": true,
        "searching": true,
        "ordering": true,
        "info": true,
        "lengthMenu": [10, 25, 50, 100],
        "language": {
            "search": "Cari:",
            "lengthMenu": "Tampilkan _MENU_ entri",
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ entri",
            "paginate": {
                "first": "Pertama",
                "last": "Terakhir",
                "next": "Selanjutnya",
                "previous": "Sebelumnya"
            }
        }
    });
    loadData();
    setInterval(loadData, 10000);
});

function loadData(){
    $('#loading').show();
    fetch('?fetch=1')
        .then(r => r.json())
        .then(data => {
            $('#loading').hide();
            if(data.error) return;
            const tbody = $('#onu_tbody');
            tbody.empty();
            data.onu.forEach((r,i) => {
                let status = r[3] || '';
                let cls = status.toLowerCase().includes('up') || status == '1' ? 'table-success' : 'table-danger';
                let dist = parseFloat(r[8]||0);
                let temp = parseFloat(r[9]||0) / 100;  // Centi-degree to degree
                let txPower = parseFloat(r[10]||0) / 100;  // Centi-dBm to dBm
                let rxPower = parseFloat(r[11]||0) / 100;  // Centi-dBm to dBm
                let bias = parseFloat(r[12]||0) / 100;  // Centi-mA to mA
                let voltage = parseFloat(r[13]||0) / 100;  // Centi-mV to mV, then to V
                let row = '<tr class="' + cls + '">' +
                    '<td>' + (i+1) + '</td>' +
                    '<td>' + (r[0]||'') + '</td>' +
                    '<td>' + (r[2]||'') + '</td>' +
                    '<td>' + status + '</td>' +
                    '<td>' + (r[4]||'') + '</td>' +
                    '<td>' + (r[5]||'') + '</td>' +
                    '<td>' + (r[6]||'') + '</td>' +
                    '<td>' + Math.round(dist) + '</td>' +
                    '<td>' + temp.toFixed(2) + '</td>' +
                    '<td>' + txPower.toFixed(2) + '</td>' +
                    '<td>' + rxPower.toFixed(2) + '</td>' +
                    '<td>' + bias.toFixed(2) + '</td>' +
                    '<td>' + (voltage / 1000).toFixed(2) + '</td>' +  // mV to V
                    '</tr>';
                tbody.append(row);
            });
            $('#onu_table').DataTable().draw();
        })
        .catch(() => {
            $('#loading').hide();
        });
}
</script>
</body>
</html>