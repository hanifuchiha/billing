<?php
session_start();

function snmp_walk($ip, $port, $community, $oid) {
    return @snmpwalk($ip . ":$port", $community, $oid, 1000000, 2);
}

if(isset($_GET['logout'])){
    session_destroy();
    header("Location: zte_optical.php");
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

    // OID untuk ZTE Optical Module Info (dari ZTE-AN-OPTICAL-MODULE-MIB)
    $zte_oids = [
        'rxPower' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.2',  // zxAnOpticalIfRxPwrCurrValue
        'txPower' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.3',  // zxAnOpticalIfTxPwrCurrValue
        'biasCurrent' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.4',  // zxAnOpticalBiasCurrent
        'supplyVoltage' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.5',  // zxAnOpticalSupplyVoltage
        'temperature' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.6',  // zxAnOpticalTemperature
        'wavelength' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.7',  // zxAnOpticalWavelength
        'vendorName' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.9',  // zxAnOpticalVendorName
        'vendorPn' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.10',  // zxAnOpticalVendorPn
        'vendorSn' => '1.3.6.1.4.1.3902.1082.30.40.2.4.1.11',  // zxAnOpticalVendorSn
    ];

    $zte_rows = [];
    foreach($zte_oids as $label => $oid){
        $walk = snmp_walk($ip, $port, $community, $oid);
        if($walk !== false){
            foreach($walk as $oid_key => $value){
                $parts = explode('.', $oid_key);
                $idx = end($parts);  // Asumsi index adalah ifIndex atau slot/port
                if(!isset($zte_rows[$idx])) $zte_rows[$idx] = [];
                $zte_rows[$idx][$label] = str_replace(['STRING: ', 'INTEGER: ', '"'], '', $value);
            }
        }
    }

    // Convert to array of arrays
    $zte_array = [];
    foreach($zte_rows as $idx => $data){
        $zte_array[] = [
            $idx,  // Interface Index
            $data['vendorName'] ?? '',
            $data['vendorPn'] ?? '',
            $data['vendorSn'] ?? '',
            $data['rxPower'] ?? '',
            $data['txPower'] ?? '',
            $data['biasCurrent'] ?? '',
            $data['supplyVoltage'] ?? '',
            $data['temperature'] ?? '',
            $data['wavelength'] ?? '',
        ];
    }

    // hapus dan buat ulang zte_optical.txt
    @unlink($debug_dir.'/zte_optical.txt');
    file_put_contents($debug_dir.'/zte_optical.txt', json_encode($zte_array, JSON_PRETTY_PRINT));
    chmod($debug_dir.'/zte_optical.txt', 0777);

    echo json_encode(['zte'=>$zte_array]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>ZTE Optical Module Manager - SNMP</title>
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
</style>
</head>
<body class="bg-light">
<div class="container-fluid">
<?php if(!$logged_in): ?>
<div class="main-flat-card">
<div class="card-header">
<h5 class="mb-0">Login to ZTE Optical Module Manager</h5>
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
<h5 class="mb-0">ZTE Optical Module Manager - SNMP</h5>
<a href="?logout=1" class="btn btn-outline-secondary btn-sm">Logout</a>
</div>
<div class="card-body">
<div id="loading" class="text-center">
<div class="spinner-border text-primary" role="status">
<span class="visually-hidden">Loading...</span>
</div>
<p>Loading data...</p>
</div>
<table id="zte_table" class="table table-flat table-striped">
<thead>
<tr>
<th>#</th>
<th>Interface</th>
<th>Vendor Name</th>
<th>Part Number</th>
<th>Serial Number</th>
<th>Rx Power (dBm)</th>
<th>Tx Power (dBm)</th>
<th>Bias Current (mA)</th>
<th>Voltage (V)</th>
<th>Temperature (°C)</th>
<th>Wavelength (nm)</th>
</tr>
</thead>
<tbody id="zte_tbody">
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
    $('#zte_table').DataTable({
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
    setInterval(loadData, 30000);  // Refresh setiap 30 detik
});

function loadData(){
    $('#loading').show();
    fetch('?fetch=1')
        .then(r => r.json())
        .then(data => {
            $('#loading').hide();
            if(data.error) return;
            const tbody = $('#zte_tbody');
            tbody.empty();
            data.zte.forEach((r,i) => {
                let row = '<tr>' +
                    '<td>' + (i+1) + '</td>' +
                    '<td>' + (r[0]||'') + '</td>' +
                    '<td>' + (r[1]||'') + '</td>' +
                    '<td>' + (r[2]||'') + '</td>' +
                    '<td>' + (r[3]||'') + '</td>' +
                    '<td>' + (r[4]||'') + '</td>' +
                    '<td>' + (r[5]||'') + '</td>' +
                    '<td>' + (r[6]||'') + '</td>' +
                    '<td>' + (r[7]||'') + '</td>' +
                    '<td>' + (r[8]||'') + '</td>' +
                    '<td>' + (r[9]||'') + '</td>' +
                    '</tr>';
                tbody.append(row);
            });
            $('#zte_table').DataTable().draw();
        })
        .catch(() => {
            $('#loading').hide();
        });
}
</script>
</body>
</html>