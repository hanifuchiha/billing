<?php
session_start();

function hexToSn($hex) {
    $str = '';
    for ($i = 0; $i < strlen($hex); $i += 2) {
        $str .= chr(hexdec(substr($hex, $i, 2)));
    }
    return $str;
}

function formatMac($mac) {
    if (strlen($mac) == 12) {
        return implode(':', str_split($mac, 2));
    }
    return $mac;
}

function statusToString($status) {
    $statuses = [
        1 => 'LOS',
        2 => 'syncMIB',
        3 => 'WORKING',
        4 => 'DyingGasp',
        6 => 'Offline'
    ];
    return $statuses[$status] ?? $status;
}

function snmp_walk($ip, $port, $community, $oid) {
    return @snmpwalk($ip . ":$port", $community, $oid, 1000000, 2);
}

if(isset($_GET['logout'])){
    session_destroy();
    header("Location: readontzte.php");
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

    $ip = $_SESSION['ip'];
    $port = $_SESSION['port'];
    $community = $_SESSION['community_read'];

    // OID untuk ONU list (ZTE C300/C320 GPON MIB - Enterprise 3902)
    $onu_oids = [
        'onuDeviceIndex' => '1.3.6.1.4.1.3902.1012.3.13.1.1.1',
        'onuName' => '1.3.6.1.4.1.3902.1012.3.28.1.1.2',
        'onuSerialNum' => '1.3.6.1.4.1.3902.1012.3.13.1.1.2',
        'onuOperationStatus' => '1.3.6.1.4.1.3902.1012.3.28.2.1.4', // .(safeInstance ID).13
        'onuOfflineReason' => '1.3.6.1.4.1.3902.1012.3.28.2.1.4', // .(safeInstance ID).4
        'onuLastOnline' => '1.3.6.1.4.1.3902.1012.3.28.2.1.5', // .(safeInstance ID).1
        'onuLastOffline' => '1.3.6.1.4.1.3902.1012.3.28.2.1.6', // .(safeInstance ID).(urutan)
        'onuTestDistance' => '1.3.6.1.4.1.3902.1012.3.13.1.1.4',
        'onuReceivedOpticalPower' => '1.3.6.1.4.1.3902.1012.3.50.12.1.1.10', // .(safeInstance ID).(urutan).1
        'onuTramsmittedOpticalPower' => '1.3.6.1.4.1.3902.1012.3.50.12.1.1.14', // .(safeInstance ID).(urutan).1
        'onuWorkingTemperature' => '1.3.6.1.4.1.3902.1012.3.13.1.1.7',
        'onuWorkingVoltage' => '1.3.6.1.4.1.3902.1012.3.13.1.1.8',
        'onuBiasCurrent' => '1.3.6.1.4.1.3902.1012.3.13.1.1.9',
        'onuSN' => '1.3.6.1.4.1.3902.1012.3.28.1.1.5', // .(safeInstance ID).(urutan)
        'onuMacAddress' => '1.3.6.1.4.1.3902.1012.3.28.1.1.3' // MAC Address
    ];

    $onu_rows = [];
    $debug_info = "Debug Info:\n";
    foreach($onu_oids as $label => $oid){
        $walk = snmp_walk($ip, $port, $community, $oid);
        $debug_info .= "OID $label ($oid):\n" . print_r($walk, true) . "\n";
        if($walk === false){
            $error = error_get_last();
            $debug_info .= "Error for $label: " . print_r($error, true) . "\n";
        }
        if($walk !== false){
            foreach($walk as $oid_key => $value){
                $parts = explode('.', $oid_key);
                $idx = '';
                if(in_array($label, ['onuOperationStatus', 'onuOfflineReason', 'onuLastOnline'])){
                    // Index: safeInstance ID (1 bagian sebelum sub)
                    $idx = $parts[count($parts)-2];
                } elseif(in_array($label, ['onuLastOffline', 'onuName'])){
                    // Index: safeInstance ID . urutan (2 bagian)
                    $idx = implode('.', array_slice($parts, -2));
                } elseif(in_array($label, ['onuReceivedOpticalPower', 'onuTramsmittedOpticalPower'])){
                    // Index: safeInstance ID . urutan (2 bagian, abaikan .1)
                    $idx = implode('.', array_slice($parts, -3, 2));
                } elseif(in_array($label, ['onuSN', 'onuMacAddress'])){
                    // Index: safeInstance ID (1 bagian)
                    $idx = $parts[count($parts)-1];
                } else {
                    // Default: end($parts)
                    $idx = end($parts);
                }
                if(!isset($onu_rows[$idx])) $onu_rows[$idx] = [];
                $onu_rows[$idx][$label] = str_replace(['STRING: ', 'INTEGER: ', '"'], '', $value);
            }
        }
    }

    $debug_info .= "Parsed onu_rows:\n" . print_r($onu_rows, true) . "\n";

    // Convert to array of arrays like original
    $onu_array = [];
    foreach($onu_rows as $idx => $data){
        // Konversi unit optical
        $rxPower = isset($data['onuReceivedOpticalPower']) ? round((floatval($data['onuReceivedOpticalPower']) * 0.002) - 30, 1) : '';
        $txPower = isset($data['onuTramsmittedOpticalPower']) ? round((floatval($data['onuTramsmittedOpticalPower']) * 0.002) - 30, 1) : '';
        $biasCurrent = isset($data['onuBiasCurrent']) ? floatval($data['onuBiasCurrent']) / 10 : '';
        $voltage = isset($data['onuWorkingVoltage']) ? floatval($data['onuWorkingVoltage']) / 10 : '';
        $temperature = isset($data['onuWorkingTemperature']) ? round(floatval($data['onuWorkingTemperature']) / 10, 1) : '';
        
        // Konversi SN dari hex
        $sn = isset($data['onuSN']) ? hexToSn($data['onuSN']) : '';
        
        // Format MAC Address
        $mac = isset($data['onuMacAddress']) ? formatMac($data['onuMacAddress']) : '';
        
        $onu_array[] = [
            $idx, // ONU No
            '', // Placeholder
            $data['onuName'] ?? 'N/A', // Name
            statusToString($data['onuOperationStatus'] ?? '') ?: 'Unknown', // Status
            $data['onuTestDistance'] ?? 'N/A', // Distance
            $temperature ?: 'N/A', // Temperature (°C)
            $txPower ?: 'N/A', // Tx Power (dBm)
            $rxPower ?: 'N/A', // Rx Power (dBm)
            $sn ?: 'N/A', // SN
            $mac ?: 'N/A' // MAC Address
        ];
    }

    echo json_encode(['onu'=>$onu_array, 'debug'=>$debug_info]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>ZTE C300/C320 GPON ONU Auto Manager - SNMP</title>
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
<h5 class="mb-0">Login to ZTE C300/C320 GPON ONU Manager</h5>
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
<h5 class="mb-0">ZTE C300/C320 GPON ONU Auto Manager - SNMP</h5>
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
<th>Name</th>
<th>Status</th>
<th>Distance (m)</th>
<th>Temperature (°C)</th>
<th>Tx Power (dBm)</th>
<th>Rx Power (dBm)</th>
<th>SN</th>
<th>MAC Address</th>
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
    var table = $('#onu_table').DataTable({
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
        },
        "createdRow": function(row, data, dataIndex) {
            let status = data[3] || '';
            $(row).addClass((status === 'WORKING') ? 'table-success' : 'table-danger');
        }
    });
    loadData(table);
    setInterval(() => loadData(table), 300000); // 5 minutes
});

function loadData(table){
    console.log('Starting loadData');
    $('#loading').show();
    fetch('?fetch=1')
        .then(r => {
            console.log('Response received:', r);
            return r.json();
        })
        .then(data => {
            console.log('Data parsed:', data);
            $('#loading').hide();
            if(data.error) {
                console.log('Error from server:', data.error);
                return;
            }
            console.log('Debug Info:', data.debug);
            console.log('ONU Data:', data.onu);
            table.clear();
            data.onu.forEach((r,i) => {
                console.log('Row ' + i + ':', r);
                let status = r[3] || '';
                let dist = parseFloat(r[8]||0);
                table.row.add([
                    (i+1),
                    r[0] || '',
                    r[2] || '',
                    r[3] || '',
                    r[4] || '',
                    r[5] || '',
                    r[6] || '',
                    r[7] || '',
                    r[8] || '',
                    r[9] || ''
                ]);
            });
            table.draw();
        })
        .catch(error => {
            console.log('Fetch error:', error);
            $('#loading').hide();
        });
}
</script>
</body>
</html>