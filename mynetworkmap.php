<?php
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('NMS', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu NMS.</div></div>';
        require 'footer.php';
        exit;
    }
}


// Cache control meta tags for performance optimization
?>
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<?php

// Load config.json for radius_ip
$config = json_decode(file_get_contents('config.json'), true);
$radius_ip = isset($config['radius_ip']) ? $config['radius_ip'] : '127.0.0.1';
$billing_server_name = 'Billing Server';




// Cek dan buat tabel network_devices jika belum ada
$checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'network_devices'");

if (mysqli_num_rows($checkTable) == 0) {
	$createTable = "CREATE TABLE network_devices (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT DEFAULT 0,
		name VARCHAR(100),
		ip_address VARCHAR(45),
		`type` VARCHAR(50),
		location VARCHAR(100),
		parent_id INT DEFAULT NULL,
		`description` TEXT,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
	)";
	if (mysqli_query($conn, $createTable)) {
		echo "<div style='color:blue'>Tabel network_devices berhasil dibuat otomatis.</div>";
	} else {
		echo "<div style='color:red'>Gagal membuat tabel network_devices: ".mysqli_error($conn)."</div>";
		// Debug: tampilkan query yang gagal
		echo "<pre>Query: ".$createTable."\nError: ".mysqli_error($conn)."</pre>";
		die();
	}
} else {
	// Tambahkan kolom user_id jika belum ada
	$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM network_devices LIKE 'user_id'");
	if (mysqli_num_rows($colCheck) == 0) {
		mysqli_query($conn, "ALTER TABLE network_devices ADD COLUMN user_id INT DEFAULT 0");
	}
	// Tambahkan kolom parent_id jika belum ada
	$colParent = mysqli_query($conn, "SHOW COLUMNS FROM network_devices LIKE 'parent_id'");
	if (mysqli_num_rows($colParent) == 0) {
		mysqli_query($conn, "ALTER TABLE network_devices ADD COLUMN parent_id INT DEFAULT NULL");
	}
	// Posisi manual hasil drag-and-drop kanvas (offset dari posisi auto-layout,
	// BUKAN koordinat absolut -- supaya tetap konsisten kalau device lain
	// ditambah/dihapus dan auto-layout re-arrange).
	$colPosX = mysqli_query($conn, "SHOW COLUMNS FROM network_devices LIKE 'pos_dx'");
	if (mysqli_num_rows($colPosX) == 0) {
		mysqli_query($conn, "ALTER TABLE network_devices ADD COLUMN pos_dx FLOAT DEFAULT 0");
	}
	$colPosY = mysqli_query($conn, "SHOW COLUMNS FROM network_devices LIKE 'pos_dy'");
	if (mysqli_num_rows($colPosY) == 0) {
		mysqli_query($conn, "ALTER TABLE network_devices ADD COLUMN pos_dy FLOAT DEFAULT 0");
	}
}

// Proses tambah device
if (isset($_POST['add_device'])) {
	$name = mysqli_real_escape_string($conn, $_POST['name']);
	$ip = mysqli_real_escape_string($conn, $_POST['ip_address']);
	$type = mysqli_real_escape_string($conn, $_POST['type']);
	$loc = mysqli_real_escape_string($conn, $_POST['location']);
	$desc = mysqli_real_escape_string($conn, $_POST['description']);
	$uid = (int)$current_user_id;
	$parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : 'NULL';
	$monitor_interface = isset($_POST['monitor_interface']) ? trim((string)$_POST['monitor_interface']) : '';
	$monitor_interface_sql = $monitor_interface !== '' ? "'" . mysqli_real_escape_string($conn, $monitor_interface) . "'" : 'NULL';
	$alert_enabled = isset($_POST['alert_enabled']) ? 1 : 0;
	// Cek duplikat berdasarkan IP dan user
	$cek = mysqli_query($conn, "SELECT id FROM network_devices WHERE ip_address='$ip' AND user_id='$uid'");
	if (mysqli_num_rows($cek) == 0) {
		mysqli_query($conn, "INSERT INTO network_devices (user_id, name, ip_address, type, location, parent_id, description, monitor_interface, alert_enabled) VALUES ('$uid', '$name', '$ip', '$type', '$loc', $parent_id, '$desc', $monitor_interface_sql, $alert_enabled)");
		// Redirect agar POST hilang
		echo "<script>window.location='mynetworkmap.php';</script>";
		exit();
	} else {
		echo "<script>window.location='mynetworkmap.php';</script>";

	}
}

// --- AUTO INSERT SERVER KE network_devices UNTUK MONITORING ---
// Ambil semua server milik user
$servers_auto = [];
$sql_servers = mysqli_query($conn, "SELECT * FROM server WHERE user_id = '".(int)$current_user_id."'");
while ($srv = mysqli_fetch_assoc($sql_servers)) {
	$servers_auto[] = $srv;
}
// Ambil id Billing Server (harus id=0 di network_devices)
$billing_id = 0;
// Untuk setiap server, cek dan insert ke network_devices jika belum ada (bandingkan full IP:port)
$existing_ips = [];
$res_exist = mysqli_query($conn, "SELECT ip_address FROM network_devices WHERE user_id='".(int)$current_user_id."'");
while ($row_exist = mysqli_fetch_assoc($res_exist)) {
	$ip_exist = trim($row_exist['ip_address']);
	$existing_ips[$ip_exist] = true;
}
foreach ($servers_auto as $srv) {
	$ip_full = trim($srv['IP']); // Bisa mengandung port
	$ip_sql = mysqli_real_escape_string($conn, $ip_full);
	$name = mysqli_real_escape_string($conn, $srv['BRAND']);
	$type = 'Router';
	$loc = mysqli_real_escape_string($conn, $srv['AREA']);
	$desc = 'Auto monitoring';
	$uid = (int)$current_user_id;
	if (!isset($existing_ips[$ip_full])) {
		// Insert, parent_id = 0 (Billing Server)
		mysqli_query($conn, "INSERT INTO network_devices (user_id, name, ip_address, type, location, parent_id, description) VALUES ('$uid', '$name', '$ip_sql', '$type', '$loc', $billing_id, '$desc')");
		$existing_ips[$ip_full] = true;
	} else {
		// Jika sudah ada, pastikan parent_id tetap 0 (Billing Server)
		mysqli_query($conn, "UPDATE network_devices SET parent_id=$billing_id WHERE ip_address='$ip_sql' AND user_id='$uid'");
	}
}

// Proses hapus device
if (isset($_POST['delete_device']) && isset($_POST['device_id'])) {
	$did = (int)$_POST['device_id'];
	mysqli_query($conn, "DELETE FROM network_devices WHERE id='$did' AND user_id='".(int)$current_user_id."'");
	// Redirect agar POST hilang
	echo "<script>window.location='mynetworkmap.php';</script>";

}

// Proses edit device
if (isset($_POST['edit_device']) && isset($_POST['device_id'])) {
	$did = (int)$_POST['device_id'];
	$name = mysqli_real_escape_string($conn, $_POST['name']);
	$ip = mysqli_real_escape_string($conn, $_POST['ip_address']);
	$type = mysqli_real_escape_string($conn, $_POST['type']);
	$loc = mysqli_real_escape_string($conn, $_POST['location']);
	$desc = mysqli_real_escape_string($conn, $_POST['description']);
	$parent_id = isset($_POST['parent_id']) && $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : 'NULL';
	$monitor_interface = isset($_POST['monitor_interface']) ? trim((string)$_POST['monitor_interface']) : '';
	$monitor_interface_sql = $monitor_interface !== '' ? "'" . mysqli_real_escape_string($conn, $monitor_interface) . "'" : 'NULL';
	$alert_enabled = isset($_POST['alert_enabled']) ? 1 : 0;
	mysqli_query($conn, "UPDATE network_devices SET name='$name', ip_address='$ip', type='$type', location='$loc', parent_id=$parent_id, description='$desc', monitor_interface=$monitor_interface_sql, alert_enabled=$alert_enabled WHERE id='$did' AND user_id='".(int)$current_user_id."'");
	// Redirect agar POST hilang
	echo "<script>window.location='mynetworkmap.php';</script>";
	
}

?>


<!-- CANVAS SECTION FIRST -->
<div class="card mb-4 mt-4">
   <div class="card-header bg-info text-white d-flex justify-content-between align-items-center" style="padding: 12px 15px;">
	   <h6 class="mb-0" style="font-weight: 600; font-size: 1em;"><i class="fas fa-project-diagram"></i> Network Map (Visual Monitoring)</h6>
	   <div class="d-flex gap-2 flex-wrap">
		   <button id="zoomOutBtn" class="btn btn-sm btn-light me-1" title="Zoom Out" style="font-weight: 500;"><i class="fas fa-minus"></i></button>
		   <button id="zoomInBtn" class="btn btn-sm btn-light me-1" title="Zoom In" style="font-weight: 500;"><i class="fas fa-plus"></i></button>
		   <button id="pauseUpdatesBtn" class="btn btn-sm btn-warning me-1" title="Pause/Resume Updates" style="font-weight: 500;"><i class="fas fa-pause-circle"></i> Pause</button>
		   <button id="clearCacheBtn" class="btn btn-sm btn-danger" title="Clear Cache" style="font-weight: 500;"><i class="fas fa-trash"></i> Clear</button>
	   </div>
   </div>
   <div class="card-body p-0">
	   <div style="overflow:auto; background:#f8f8f8; width:100%; height:520px; border-bottom: 1px solid #e2e8f0;">
		   <canvas id="networkMap" width="1800" height="900" style="min-width:1000px; width:100%; max-width:100%; display:block;"></canvas>
	   </div>
   </div>


<!-- TABLE SECTION BELOW CANVAS -->
<div class="container-fluid py-4">
   <div class="row">
	   <div class="col-12">
		   <!-- Tab Buttons -->
		   <div class="d-flex mb-3">
			   <button id="tabDeviceMonitoring" class="btn btn-primary me-2">Device Monitoring</button>
			   <button id="tabGrafikTrafik" class="btn btn-secondary me-2">Grafik Trafik Semua Device</button>
			   <button id="tabGrafikAktif" class="btn btn-secondary">Grafik Aktif PPPoE & Hotspot</button>
		   </div>

		   <!-- Tab Content 1: Device Monitoring -->
		   <div id="contentDeviceMonitoring">
			   <div class="card mb-4">
				   <div class="card-header pb-0">
					   <div class="card-header bg-primary text-white">
						   Device Monitoring
					   </div>
					   <br>
					   <!-- Button trigger modal (for add device) -->
					   <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDeviceModal">
						   Tambah Device
					   </button>
				   </div>
				   <div class="card-body px-0 pt-0 pb-2">
					   <div class="table-responsive p-0">
						   <style>
							   @media screen and (max-width: 600px) {
								   td:nth-child(3), th:nth-child(3), td:nth-child(4), th:nth-child(4) {
									   display: none;
								   }
							   }
							   .small-text { font-size: 8px; }
						   </style>
						   <div class="mb-2">
							   <input type="text" id="searchDeviceInput" class="form-control" placeholder="Cari Device, IP, Tipe ..." onkeyup="filterDeviceTable()">
						   </div>
						   <table class="table align-items-center mb-0" style="font-size: 10px;">
							  <thead>
								  <tr>
									  <th>Nama</th>
									  <th>IP</th>
									  <th>Tipe</th>
									  <th>Lokasi</th>
									  <th>Status</th>
									  <th>Latency ms</th>
									  <th>Last Online</th>
									  <th>Keterangan</th>
									  <th>Aksi</th>
								  </tr>
							  </thead>
							  <tbody id="deviceTable">
							  <?php
							 $devices = [];
							 $result = mysqli_query($conn, "SELECT * FROM network_devices WHERE user_id = '" . (int)$current_user_id . "'");
							 // Load last online data
							 $lastOnlineFile = 'history-last-online.txt';
							 $lastOnlineData = [];
							 if (file_exists($lastOnlineFile)) {
								 $lastOnlineData = json_decode(file_get_contents($lastOnlineFile), true) ?: [];
							 }
							 while ($row = mysqli_fetch_assoc($result)) {
								 // Get server credentials for this IP
								 $server_query = mysqli_query($conn, "SELECT PEMILIK, PASSWORD FROM server WHERE IP = '" . mysqli_real_escape_string($conn, $row['ip_address']) . "' AND user_id = '" . (int)$current_user_id . "' LIMIT 1");
								 $server_data = mysqli_fetch_assoc($server_query);
								 $row['pemilik'] = $server_data['PEMILIK'] ?? '';
								 $row['password'] = $server_data['PASSWORD'] ?? '';
								 $devices[] = [
									 'id' => $row['id'],
									 'name' => $row['name'],
									 'ip' => $row['ip_address'],
									 'type' => $row['type'],
									 'location' => $row['location'],
									 'desc' => $row['description'],
									 'parent_id' => $row['parent_id'],
									 'pemilik' => $row['pemilik'],
									 'password' => $row['password'],
									 'last_online' => $lastOnlineData[$row['ip_address']] ?? null,
									 'pos_dx' => (float)($row['pos_dx'] ?? 0),
									 'pos_dy' => (float)($row['pos_dy'] ?? 0),
									 'monitor_interface' => $row['monitor_interface'] ?? '',
									 'alert_enabled' => (int)($row['alert_enabled'] ?? 1)
								 ];
								 echo "<tr>
									 <td>".htmlspecialchars($row['name'])."</td>
									 <td>".htmlspecialchars($row['ip_address'])."</td>
									 <td>".htmlspecialchars($row['type'])."</td>
									 <td>".htmlspecialchars($row['location'])."</td>
									 <td class='status-cell' data-ip='".htmlspecialchars($row['ip_address'])."'><span class='spinner-border spinner-border-sm text-secondary'></span></td>
									 <td class='latency-cell' data-ip='".htmlspecialchars($row['ip_address'])."'>-</td>
									 <td class='last-online-cell' data-ip='".htmlspecialchars($row['ip_address'])."'>" . ($lastOnlineData[$row['ip_address']] ? htmlspecialchars($lastOnlineData[$row['ip_address']]) : 'Never') . "</td>
									 <td>".htmlspecialchars($row['description'])."</td>
									 <td>
										 <button type=\"button\" class=\"btn btn-sm btn-info me-1\" onclick=\"showTrafficModal('".htmlspecialchars($row['ip_address'])."', '".htmlspecialchars($row['pemilik'])."', '".htmlspecialchars($row['password'])."', ".(int)$row['id'].")\">Grafik</button>
										 <form method=\"POST\" action=\"\" style=\"display:inline-block\">
											 <input type=\"hidden\" name=\"device_id\" value=\"".$row['id']."\">
											 <button type=\"button\" class=\"btn btn-sm btn-warning\" data-bs-toggle=\"modal\" data-bs-target=\"#editDeviceModal_".$row['id']."\">Edit</button>
										 </form>
										 <form method=\"POST\" action=\"\" style=\"display:inline-block\" onsubmit=\"return confirm('Yakin hapus device ini?')\">
											 <input type=\"hidden\" name=\"device_id\" value=\"".$row['id']."\">
											 <button type=\"submit\" class=\"btn btn-sm btn-danger\" name=\"delete_device\">Hapus</button>
										 </form>
									 </td>
								 </tr>";
							 }
							 // Tambahkan Billing Server ke $devices jika belum ada (hanya untuk visualisasi, id = 0)
							 $billing_server = [
								 'id' => 0,
								 'name' => $billing_server_name,
								 'ip' => $radius_ip,
								 'type' => 'Billing Server',
								 'location' => '-',
								 'desc' => 'Server utama billing',
								 'parent_id' => null,
								 'pemilik' => '',
								 'password' => '',
								 'online' => true, // Selalu hijau
								 'last_online' => $lastOnlineData[$radius_ip] ?? null,
								 'pos_dx' => 0,
								 'pos_dy' => 0
							 ];
							 $has_billing = false;
							 foreach ($devices as $d) {
								 if ($d['ip'] === $radius_ip) {
									 $has_billing = true;
									 break;
								 }
							 }
							 if (!$has_billing) {
								 array_unshift($devices, $billing_server);
							 }
							  ?>
						  </tbody>
					   </table>
				   <!-- Modal Edit Device (one per device) -->
				   <?php
				   if (!empty($devices)) {
					   foreach ($devices as $dev) {
				   ?>
				   <div class="modal fade" id="editDeviceModal_<?php echo $dev['id']; ?>" tabindex="-1" aria-labelledby="editDeviceModalLabel_<?php echo $dev['id']; ?>" aria-hidden="true">
					   <div class="modal-dialog">
						   <div class="modal-content">
							   <div class="modal-header">
								   <h5 class="modal-title" id="editDeviceModalLabel_<?php echo $dev['id']; ?>">Edit Device Monitoring</h5>
								   <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
							   </div>
							   <div class="modal-body">
								   <form method="POST" action="">
									   <input type="hidden" name="device_id" value="<?php echo $dev['id']; ?>">
									   <div class="mb-3">
										   <label for="name_<?php echo $dev['id']; ?>" class="form-label">Nama Device</label>
										   <input type="text" class="form-control" name="name" id="name_<?php echo $dev['id']; ?>" value="<?php echo htmlspecialchars($dev['name']); ?>" required>
									   </div>
									   <div class="mb-3">
										   <label for="ip_address_<?php echo $dev['id']; ?>" class="form-label">IP Address <span style='font-weight:normal;color:#888;font-size:11px'>(bisa IP saja atau IP:PORT)</span></label>
										   <input type="text" class="form-control" name="ip_address" id="ip_address_<?php echo $dev['id']; ?>" value="<?php echo htmlspecialchars($dev['ip']); ?>" placeholder="Contoh: 192.168.1.1 atau 192.168.1.1:8728" required>
									   </div>
									   <div class="mb-3">
										   <label for="type_<?php echo $dev['id']; ?>" class="form-label">Tipe (Router/Switch/etc)</label>
										   <input type="text" class="form-control" name="type" id="type_<?php echo $dev['id']; ?>" value="<?php echo htmlspecialchars($dev['type']); ?>" required>
									   </div>
									   <div class="mb-3">
										   <label for="location_<?php echo $dev['id']; ?>" class="form-label">Lokasi</label>
										   <input type="text" class="form-control" name="location" id="location_<?php echo $dev['id']; ?>" value="<?php echo htmlspecialchars($dev['location']); ?>">
									   </div>
									   <div class="mb-3">
										   <label for="description_<?php echo $dev['id']; ?>" class="form-label">Keterangan</label>
										   <textarea class="form-control" name="description" id="description_<?php echo $dev['id']; ?>"><?php echo htmlspecialchars($dev['desc']); ?></textarea>
									   </div>
									   <div class="mb-3">
										   <label for="parent_id_<?php echo $dev['id']; ?>" class="form-label">Parent Network (Terhubung ke)</label>
										<select class="form-control" name="parent_id" id="parent_id_<?php echo $dev['id']; ?>">
										   <option value="">-- Tidak Terhubung --</option>
                                           <option value="0" <?php echo ($dev['parent_id'] == 0) ? 'selected' : ''; ?>><?php echo $billing_server_name . ' (' . $radius_ip . ') - Lokasi: -'; ?></option>
										   <?php
										   // List device lain milik user ini (kecuali dirinya sendiri)
                                           $resdev2 = mysqli_query($conn, "SELECT id, name, ip_address, location FROM network_devices WHERE user_id = '".(int)$current_user_id."' AND id != '".$dev['id']."'");
										   while($drow2 = mysqli_fetch_assoc($resdev2)) {
											   $selected = ($dev['parent_id'] == $drow2['id']) ? 'selected' : '';
                                               $locLabel = trim((string)($drow2['location'] ?? '')) !== '' ? $drow2['location'] : '-';
                                               echo '<option value="'.$drow2['id'].'" '.$selected.'>'.htmlspecialchars($drow2['name']).' ('.$drow2['ip_address'].') - Lokasi: '.htmlspecialchars($locLabel).'</option>';
										   }
										   ?>
										</select>
									   </div>
									   <div class="mb-3">
										   <label for="monitor_interface_<?php echo $dev['id']; ?>" class="form-label">Interface yang Dimonitor <span style='font-weight:normal;color:#888;font-size:11px'>(opsional, mis. ether1 atau pppoe-out1)</span></label>
										   <input type="text" class="form-control" name="monitor_interface" id="monitor_interface_<?php echo $dev['id']; ?>" value="<?php echo htmlspecialchars($dev['monitor_interface'] ?? ''); ?>" placeholder="Contoh: ether1-wan">
									   </div>
									   <div class="mb-3 form-check">
										   <input type="checkbox" class="form-check-input" name="alert_enabled" id="alert_enabled_<?php echo $dev['id']; ?>" <?php echo !empty($dev['alert_enabled']) ? 'checked' : ''; ?>>
										   <label class="form-check-label" for="alert_enabled_<?php echo $dev['id']; ?>">Kirim alert WA kalau device ini down</label>
									   </div>
									   <div class="modal-footer">
										   <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
										   <button type="submit" class="btn btn-primary" name="edit_device">Simpan Perubahan</button>
									   </div>
								   </form>
							   </div>
						   </div>
					   </div>
				   </div>
				   <?php
					   }
				   }
				   ?>
				   </div>
			   </div>
		   </div>

		  
</div>

 <!-- Tab Content 2: Grafik Trafik Semua Device -->
		   <div id="contentGrafikTrafik" style="display: none;">
			   <div class="card mb-4">
				   <div class="card-header bg-primary text-white">
					   <h6 class="mb-0 text-white">📊 Grafik Trafik Semua Device</h6>
					   <button id="updateGrafikTrafikBtn" class="btn btn-sm btn-light mt-2">Update Sekarang</button>
				   </div>
				   <div class="card-body px-3 py-3">
					   <div class="row g-3">
						   <?php
						   $deviceCount = 0;
						   foreach ($devices as $index => $dev) {
							   if ($dev['type'] === 'Billing Server') continue;
							   $deviceCount++;
							   echo '<div class="col-xl-4 col-lg-6 col-md-12">
								   <div class="card shadow-sm border">
									   <div class="card-header bg-light py-2 px-3">
										   <small class="fw-bold text-dark">' . htmlspecialchars($dev['name']) . ' (' . htmlspecialchars($dev['ip']) . ') - ' . htmlspecialchars($dev['location']) . '</small>
									   </div>
									   <div class="card-body p-3 text-center">
										   <canvas id="chart-all-' . $dev['id'] . '" class="w-100" style="height: 250px;"></canvas>
									   </div>
								   </div>
							   </div>';
						   }
						   if ($deviceCount == 0) {
							   echo '<div class="col-12">
								   <div class="alert alert-info text-center">
									   <h5>Tidak ada device untuk ditampilkan grafiknya.</h5>
									   <p>Silakan tambah device terlebih dahulu.</p>
								   </div>
							   </div>';
						   }
						   ?>
					   </div>
				   </div>
			   </div>
		   </div> <!-- Tab Content 3: Grafik Aktif PPPoE & Hotspot -->
		   <div id="contentGrafikAktif" style="display: none;">
			   <div class="card mb-4">
				   <div class="card-header bg-primary text-white">
					   <h6 class="mb-0 text-white">📊 Grafik Aktif PPPoE & Hotspot Semua Device</h6>
					   <button id="updateGrafikAktifBtn" class="btn btn-sm btn-light mt-2">Update Sekarang</button>
				   </div>
				   <div class="card-body px-3 py-3">
					   <div class="row g-3">
						   <?php
						   $deviceCount = 0;
						   foreach ($devices as $index => $dev) {
							   if ($dev['type'] === 'Billing Server') continue;
							   // Skip jika tidak ada credentials
							   if (empty($dev['pemilik']) || empty($dev['password'])) continue;
							   // Hitung total pelanggan berdasarkan brand dan area
							   $pemilik = mysqli_real_escape_string($conn, $dev['pemilik']);
							   $area = mysqli_real_escape_string($conn, $dev['location']);
							   $query_customers = mysqli_query($conn, "SELECT COUNT(*) as total FROM pelanggan WHERE pemilik='$pemilik' AND area='$area'");
                              
							   $total_customers = 0;
							   if ($query_customers && $row = mysqli_fetch_assoc($query_customers)) {
								   $total_customers = $row['total'];
							   }
							   $deviceCount++;
							   echo '<div class="col-xl-4 col-lg-6 col-md-12">
								   <div class="card shadow-sm border">
									   <div class="card-header bg-light py-2 px-3">
										   <small class="fw-bold text-dark">' . htmlspecialchars($dev['name']) . ' (' . htmlspecialchars($dev['ip']) . ') - ' . htmlspecialchars($dev['location']) . ' - <br>pppoe terdaftar: ' . $total_customers . '</small>
									   </div>
									   <div class="card-body p-3 text-center">
										   <canvas id="chart-active-' . $dev['id'] . '" class="w-100" style="height: 250px;"></canvas>
									   </div>
								   </div>
							   </div>';
						   }
						   if ($deviceCount == 0) {
							   echo '<div class="col-12">
								   <div class="alert alert-info text-center">
									   <h5>Tidak ada device dengan credentials untuk ditampilkan grafik aktifnya.</h5>
									   <p>Silakan tambah credentials di tabel server untuk device yang ingin dimonitor.</p>
								   </div>
							   </div>';
						   }
						   ?>
					   </div>
				   </div>
			   </div>
		   </div>
<!-- Modal Add Device -->
<div class="modal fade" id="addDeviceModal" tabindex="-1" aria-labelledby="addDeviceModalLabel" aria-hidden="true">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="addDeviceModalLabel">Tambah Device Monitoring</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<form method="POST" action="">
					<div class="mb-3">
						<label for="name" class="form-label">Nama Device</label>
						<input type="text" class="form-control" name="name" id="name" placeholder="Nama Device" required>
					</div>
					<div class="mb-3">
						<label for="ip_address" class="form-label">IP Address <span style='font-weight:normal;color:#888;font-size:11px'>(bisa IP saja atau IP:PORT)</span></label>
						<input type="text" class="form-control" name="ip_address" id="ip_address" placeholder="Contoh: 192.168.1.1 atau 192.168.1.1:8728" required>
					</div>
					<div class="mb-3">
						<label for="type" class="form-label">Tipe (Router/Switch/etc)</label>
						<input type="text" class="form-control" name="type" id="type" placeholder="Tipe" required>
					</div>
					<div class="mb-3">
						<label for="location" class="form-label">Lokasi</label>
						<input type="text" class="form-control" name="location" id="location" placeholder="Lokasi">
					</div>
					<div class="mb-3">
						<label for="description" class="form-label">Keterangan</label>
						<textarea class="form-control" name="description" id="description" placeholder="Keterangan"></textarea>
					</div>
					<div class="mb-3">
						<label for="parent_id" class="form-label">Parent Network (Terhubung ke)</label>
						<select class="form-control" name="parent_id" id="parent_id">
						   <option value="">-- Tidak Terhubung --</option>
                           <option value="0" selected><?php echo $billing_server_name . ' (' . $radius_ip . ') - Lokasi: -'; ?></option>
						   <?php
						   // List device lain milik user ini
                           $resdev = mysqli_query($conn, "SELECT id, name, ip_address, location FROM network_devices WHERE user_id = '".(int)$current_user_id."'");
						   while($drow = mysqli_fetch_assoc($resdev)) {
                               $locLabel = trim((string)($drow['location'] ?? '')) !== '' ? $drow['location'] : '-';
                               echo '<option value="'.$drow['id'].'">'.htmlspecialchars($drow['name']).' ('.$drow['ip_address'].') - Lokasi: '.htmlspecialchars($locLabel).'</option>';
						   }
						   ?>
						</select>
					</div>
					<div class="mb-3">
						<label for="monitor_interface" class="form-label">Interface yang Dimonitor <span style='font-weight:normal;color:#888;font-size:11px'>(opsional, utk grafik historis trafik -- isi persis nama interface di MikroTik, mis. ether1 atau pppoe-out1)</span></label>
						<input type="text" class="form-control" name="monitor_interface" id="monitor_interface" placeholder="Contoh: ether1-wan">
					</div>
					<div class="mb-3 form-check">
						<input type="checkbox" class="form-check-input" name="alert_enabled" id="alert_enabled" checked>
						<label class="form-check-label" for="alert_enabled">Kirim alert WA kalau device ini down</label>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
						<button type="submit" class="btn btn-primary" name="add_device">Tambah Device</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<!-- Modal Traffic -->
<div class="modal fade" id="trafficModal" tabindex="-1" aria-labelledby="trafficModalLabel" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title" id="trafficModalLabel">Grafik Trafik Real Time</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
					<span class="small fw-semibold">Periode:</span>
					<div class="btn-group btn-group-sm" role="group" id="trafficPeriodGroup">
						<button type="button" class="btn btn-primary" data-period="live">Live</button>
						<button type="button" class="btn btn-outline-primary" data-period="daily">Harian</button>
						<button type="button" class="btn btn-outline-primary" data-period="weekly">Mingguan</button>
						<button type="button" class="btn btn-outline-primary" data-period="monthly">Bulanan</button>
					</div>
					<span class="badge bg-light text-dark border ms-auto" id="trafficUptimeBadge">Uptime: -</span>
				</div>
				<canvas id="trafficChart"></canvas>
			</div>
		</div>
</div>

<script>
	var input = document.getElementById('searchDeviceInput');
	var filter = input.value.toLowerCase();
	var table = document.getElementById('deviceTable');
	var trs = table.getElementsByTagName('tr');
	for (var i = 0; i < trs.length; i++) {
		var rowText = trs[i].innerText.toLowerCase();
		trs[i].style.display = rowText.indexOf(filter) > -1 ? '' : 'none';
	}

function filterDeviceTable() {
    var input = document.getElementById('searchDeviceInput');
    var filter = input.value.toLowerCase();
    var table = document.getElementById('deviceTable');
    var trs = table.getElementsByTagName('tr');
    for (var i = 0; i < trs.length; i++) {
        var rowText = trs[i].innerText.toLowerCase();
        trs[i].style.display = rowText.indexOf(filter) > -1 ? '' : 'none';
    }
}


// AJAX ping status
document.addEventListener('DOMContentLoaded', function() {
	const statusCells = document.querySelectorAll('.status-cell');
	statusCells.forEach(function(cell) {
		const ip = cell.getAttribute('data-ip');
		fetch('ping_device.php?ip=' + encodeURIComponent(ip))
			.then(r => r.json())
			.then(data => {
				if(data.status === 'ok') {
					cell.innerHTML = data.online ? "<span class='badge bg-success'>Online</span>" : "<span class='badge bg-danger'>Offline</span>";
					// Update last online
					const lastOnlineCell = document.querySelector(`.last-online-cell[data-ip="${ip}"]`);
					if (lastOnlineCell) {
						lastOnlineCell.innerHTML = data.online ? '-' : (lastOnlineData[ip] || 'Never');
					}
					// Update latency
					const latencyCell = document.querySelector(`.latency-cell[data-ip="${ip}"]`);
					if (latencyCell) {
						latencyCell.innerHTML = data.latency !== null ? data.latency + ' ms' : '-';
					}
				} else {
					cell.innerHTML = '<span class="badge bg-secondary">Error</span>';
					const lastOnlineCell = document.querySelector(`.last-online-cell[data-ip="${ip}"]`);
					if (lastOnlineCell) {
						lastOnlineCell.innerHTML = 'Error';
					}
					const latencyCell = document.querySelector(`.latency-cell[data-ip="${ip}"]`);
					if (latencyCell) {
						latencyCell.innerHTML = '-';
					}
				}
			})
			.catch(() => {
				cell.innerHTML = '<span class="badge bg-secondary">Error</span>';
				const lastOnlineCell = document.querySelector(`.last-online-cell[data-ip="${ip}"]`);
				if (lastOnlineCell) {
					lastOnlineCell.innerHTML = 'Error';
				}
				const latencyCell = document.querySelector(`.latency-cell[data-ip="${ip}"]`);
				if (latencyCell) {
					latencyCell.innerHTML = '-';
				}
			});
	});
});
</script>




<script>
// Data device dari PHP
const devices = <?php echo json_encode($devices); ?>;
const lastOnlineData = <?php echo json_encode($lastOnlineData); ?>;

const canvas = document.getElementById('networkMap');
const ctx = canvas.getContext('2d');
canvas.style.cursor = 'grab';
ctx.clearRect(0,0,canvas.width,canvas.height);

// Layout node otomatis (grid/lingkaran)
const n = devices.length;
let cx = canvas.width/2, cy = canvas.height/2, r = Math.min(cx,cy)-120;
let panX = 0, panY = 0, isDragging = false, lastX = 0, lastY = 0;

let nodePos = [];
let zoom = 0.78;
const minZoom = 0.45, maxZoom = 2.2, zoomStep = 0.12;

// RX/TX cache for lines: key = childIdx-parentIdx, value = {rx, tx}
let linkTxRx = {};
// Ping cache: key = deviceIdx, value = {latency, loading}
let pingCache = {};

// Device info popup state
let popupInfo = null; // {idx, x, y}
let selectedIP = '';
let selectedPemilik = '';
let selectedPassword = '';
let lastSelectedIP = '';

// Track tab visibility for performance optimization
let isTabVisible = true;
let cacheClearInterval; // Interval for auto cache clearing
let chartUpdateInterval; // Interval for chart updates
let updatesPaused = false; // Manual pause state

function truncateText(text, maxLen) {
    return text.length > maxLen ? text.substring(0, maxLen) + '...' : text;
}

function getNodeRadius() {
    return 70 * zoom;
}

let renderNodePos = [];
let nodeActionButtons = [];
let pointerDownOnButton = false;

function worldToScreenPoint(p) {
    return {
	   x: cx + (p.x - cx) * zoom,
	   y: cy + (p.y - cy) * zoom
    };
}

function getActiveNodePos() {
    return renderNodePos.length ? renderNodePos : nodePos;
}

function drawRoundedRect(x, y, w, h, r) {
    const rr = Math.max(2, Math.min(r, w / 2, h / 2));
    ctx.beginPath();
    ctx.moveTo(x + rr, y);
    ctx.lineTo(x + w - rr, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + rr);
    ctx.lineTo(x + w, y + h - rr);
    ctx.quadraticCurveTo(x + w, y + h, x + w - rr, y + h);
    ctx.lineTo(x + rr, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - rr);
    ctx.lineTo(x, y + rr);
    ctx.quadraticCurveTo(x, y, x + rr, y);
    ctx.closePath();
}

function getNodeButtonAt(mx, my) {
    for (let i = nodeActionButtons.length - 1; i >= 0; i--) {
	   const b = nodeActionButtons[i];
	   if (mx >= b.x && mx <= b.x + b.w && my >= b.y && my <= b.y + b.h) {
		   return b.nodeIdx;
	   }
    }
    return -1;
}

// Hit-test badan node (bukan tombol Grafik-nya) utk drag-and-drop posisi.
// Pakai renderNodePos (screen-space, sudah kena transform zoom) supaya
// konsisten dgn koordinat mouse dari getCanvasPointerPos().
function getNodeBodyAt(mx, my) {
    const positions = getActiveNodePos();
    const radius = getNodeRadius();
    for (let i = positions.length - 1; i >= 0; i--) {
        const p = positions[i];
        if (!p) continue;
        const dist = Math.hypot(mx - p.x, my - p.y);
        if (dist <= radius) return i;
    }
    return -1;
}

function getCanvasPointerPos(evt) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
        x: (evt.clientX - rect.left) * scaleX,
        y: (evt.clientY - rect.top) * scaleY
    };
}

// Add visibility change listener to pause animations and updates when tab is not visible
document.addEventListener('visibilitychange', function() {
    isTabVisible = !document.hidden;
    if (isTabVisible) {
        // Resume canvas redraws and chart updates
        drawNetworkMap();
    } else {
        // Pause heavy operations when tab is not visible
        console.log('Tab not visible, pausing updates');
    }
});

// Auto clear browser cache every hour
cacheClearInterval = setInterval(clearBrowserCache, 60 * 60 * 1000);
// Initial cache clear
clearBrowserCache();

function clearBrowserCache() {
    // Clear localStorage except essential data
    const keysToKeep = [];
    for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        // Keep map-related data if exists
        if (key && (key.includes('map') || key.includes('zoom'))) {
            keysToKeep.push(key);
        }
    }

    // Clear all localStorage
    localStorage.clear();

    // Restore kept keys
    keysToKeep.forEach(key => {
        // Note: We can't restore values as they're cleared, but we can keep the structure
    });

    // Clear sessionStorage
    sessionStorage.clear();

    console.log('Browser cache cleared');
}

let selectedDeviceId = null;
let selectedTrafficPeriod = 'live';

function showTrafficModal(ip, pemilik, password, deviceId) {
    selectedIP = ip;
    selectedPemilik = pemilik;
    selectedPassword = password;
    selectedDeviceId = deviceId || null;
    selectedTrafficPeriod = 'live';
    document.querySelectorAll('#trafficPeriodGroup [data-period]').forEach(function(btn) {
        btn.classList.toggle('btn-primary', btn.dataset.period === 'live');
        btn.classList.toggle('btn-outline-primary', btn.dataset.period !== 'live');
    });
    document.getElementById('trafficUptimeBadge').textContent = 'Uptime: -';
    const modalEl = document.getElementById('trafficModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    // Wait for modal to be shown
    modalEl.addEventListener('shown.bs.modal', function() {
        loadChart();
        loadUptimeBadge();
    }, { once: true });
}

// Grafik historis (Harian/Mingguan/Bulanan) -- ambil dari network_device_log
// hasil polling cron (lihat notifbot/notifphp/network_devices_poll_cron.php),
// beda dari 'live' yang polling langsung ke router tiap detik.
function loadHistoricalChart(period) {
    if (!selectedDeviceId) {
        alert('Grafik historis cuma tersedia utk device yang sudah terdaftar (bukan preview).');
        return;
    }
    if (!window.trafficChartInstance) return;
    fetch('getdata/get_network_device_history.php?device_id=' + encodeURIComponent(selectedDeviceId) + '&period=' + encodeURIComponent(period))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Gagal memuat grafik historis.');
                return;
            }
            window.trafficChartInstance.data.labels = data.labels;
            window.trafficChartInstance.data.datasets = [{
                label: 'RX (Mbps)',
                data: data.rx,
                borderColor: 'rgb(75, 192, 192)',
                tension: 0.1
            }, {
                label: 'TX (Mbps)',
                data: data.tx,
                borderColor: 'rgb(255, 99, 132)',
                tension: 0.1
            }];
            window.trafficChartInstance.options.plugins.title.text = 'Trafik Historis (' + period + ') untuk ' + selectedIP;
            window.trafficChartInstance.update();
        })
        .catch(() => alert('Gagal menghubungi server utk grafik historis.'));
}

function loadUptimeBadge() {
    const badge = document.getElementById('trafficUptimeBadge');
    if (!selectedDeviceId) {
        badge.textContent = 'Uptime: - (device belum terdaftar)';
        return;
    }
    fetch('getdata/get_network_device_history.php?device_id=' + encodeURIComponent(selectedDeviceId) + '&period=monthly&uptime_only=1')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.uptime_percent !== null && data.uptime_percent !== undefined) {
                badge.textContent = 'Uptime 30 hari: ' + data.uptime_percent + '%';
                badge.className = 'badge ms-auto border ' + (data.uptime_percent >= 99 ? 'bg-success' : (data.uptime_percent >= 95 ? 'bg-warning text-dark' : 'bg-danger')) + ' text-white';
            } else {
                badge.textContent = 'Uptime: belum ada data';
                badge.className = 'badge bg-light text-dark border ms-auto';
            }
        })
        .catch(() => { badge.textContent = 'Uptime: gagal memuat'; });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#trafficPeriodGroup [data-period]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const period = this.dataset.period;
            selectedTrafficPeriod = period;
            document.querySelectorAll('#trafficPeriodGroup [data-period]').forEach(function(b) {
                b.classList.toggle('btn-primary', b === btn);
                b.classList.toggle('btn-outline-primary', b !== btn);
            });
            if (period === 'live') {
                trafficHistory = { labels: [], rx: [], tx: [], ping: [] };
                loadChart();
            } else {
                loadHistoricalChart(period);
            }
        });
    });
});

// Global history for realtime chart
let trafficHistory = { labels: [], rx: [], tx: [], ping: [] };

function loadChart() {
    let ctx = document.getElementById('trafficChart').getContext('2d');
    // If chart doesn't exist, create it
    if (!window.trafficChartInstance) {
        window.trafficChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'RX (Mbps)',
                    data: [],
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }, {
                    label: 'TX (Mbps)',
                    data: [],
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                animation: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Trafik Total PPPoE & Hotspot untuk IP: ' + selectedIP
                    }
                }
            }
        });
    }

    // Reset history if IP changed
    if (selectedIP !== lastSelectedIP) {
        trafficHistory = { labels: [], rx: [], tx: [], ping: [] };
        lastSelectedIP = selectedIP;
        // Update title
        window.trafficChartInstance.options.plugins.title.text = 'Trafik Total PPPoE & Hotspot untuk IP: ' + selectedIP;
    }

    // Check if credentials exist
    if (!selectedPemilik || !selectedPassword) {
        // Load ping chart for modal
        fetch('ping_device.php?ip=' + encodeURIComponent(selectedIP))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'ok' && data.latency !== null) {
                    // Jika sudah sempit (100 titik), lakukan dari ulang
                    if (trafficHistory.labels.length >= 100) {
                        trafficHistory.labels = [];
                        trafficHistory.ping = [];
                    }
                    // Update history with new ping data
                    const now = new Date();
                    const timeStr = now.toTimeString().split(' ')[0]; // HH:MM:SS
                    trafficHistory.labels.push(timeStr);
                    trafficHistory.ping.push(data.latency);
                    // Update chart data
                    window.trafficChartInstance.data.labels = trafficHistory.labels;
                    window.trafficChartInstance.data.datasets = [{
                        label: 'Ping (ms)',
                        data: trafficHistory.ping,
                        borderColor: 'rgb(255, 165, 0)',
                        tension: 0.1
                    }];
                    window.trafficChartInstance.options.plugins.title.text = 'Ping Latency untuk IP: ' + selectedIP;
                    window.trafficChartInstance.update();
                } else {
                    // No data, clear chart
                    window.trafficChartInstance.data.labels = [];
                    window.trafficChartInstance.data.datasets = [];
                    window.trafficChartInstance.options.plugins.title.text = 'Tidak ada data ping untuk IP: ' + selectedIP;
                    window.trafficChartInstance.update();
                }
            })
            .catch(err => {
                console.error('Error loading ping chart:', err);
                alert('Gagal memuat data ping. Pastikan endpoint ping_device.php berfungsi.');
            });
    } else {
        // Load traffic chart
        fetch(`getdata/get-trafikinterface.php?ip=${encodeURIComponent(selectedIP)}&ps=${encodeURIComponent(selectedPassword)}&us=${encodeURIComponent(selectedPemilik)}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    console.error('Error:', data.error);
                    alert('Gagal memuat data grafik: ' + data.error);
                    return;
                }
                // Calculate total TX and RX from PPPoE and Hotspot
                let totalTx = 0;
                let totalRx = 0;

                if (data.pppoe_trafik && Array.isArray(data.pppoe_trafik)) {
                    data.pppoe_trafik.forEach(iface => {
                        totalTx += iface.output;
                        totalRx += iface.input;
                    });
                }

                if (data.hotspot_trafik && Array.isArray(data.hotspot_trafik)) {
                    data.hotspot_trafik.forEach(iface => {
                        totalTx += iface.output;
                        totalRx += iface.input;
                    });
                }

                // Jika sudah sempit (100 titik), lakukan dari ulang
                if (trafficHistory.labels.length >= 100) {
                    trafficHistory.labels = [];
                    trafficHistory.rx = [];
                    trafficHistory.tx = [];
                }
                // Update history with new data
                const now = new Date();
                const timeStr = now.toTimeString().split(' ')[0]; // HH:MM:SS
                trafficHistory.labels.push(timeStr);
                trafficHistory.rx.push(totalRx);
                trafficHistory.tx.push(totalTx);
                // Update chart data
                window.trafficChartInstance.data.labels = trafficHistory.labels;
                window.trafficChartInstance.data.datasets = [{
                    label: 'RX (Mbps)',
                    data: trafficHistory.rx,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }, {
                    label: 'TX (Mbps)',
                    data: trafficHistory.tx,
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1
                }];
                window.trafficChartInstance.options.plugins.title.text = 'Trafik Total PPPoE & Hotspot untuk IP: ' + selectedIP;
                window.trafficChartInstance.update();
            })
            .catch(err => {
                console.error('Error loading chart:', err);
                alert('Gagal memuat data grafik. Pastikan endpoint get-trafikinterface.php berfungsi.');
            });
    }
}

function updateNodePos() {
   nodePos = new Array(n);
    const centerX = cx + panX;
    const centerY = cy + panY;
    const densityFactor = Math.max(1, Math.sqrt(Math.max(1, n) / 8));
    const outerRadius = Math.max(400, (Math.min(canvas.width, canvas.height) * 0.45) * densityFactor);

   // Fast lookup map for parent/child placement.
   const idToIndex = {};
   for (let i = 0; i < n; i++) {
       idToIndex[String(devices[i].id)] = i;
   }

   const billingIdx = devices.findIndex(dev => dev.type === 'Billing Server');
   const placed = {};

   // Put Billing Server in the center as the hub to reduce crossing.
   if (billingIdx !== -1) {
       nodePos[billingIdx] = { x: centerX, y: centerY };
       placed[billingIdx] = true;
   }

   // Place devices connected to Billing in the outer ring.
   const firstRing = [];
   for (let i = 0; i < n; i++) {
       if (i === billingIdx) continue;
       const d = devices[i];
       if (billingIdx !== -1 && String(d.parent_id) === String(devices[billingIdx].id)) {
           firstRing.push(i);
       }
   }

   const ringStart = -Math.PI / 2;
   const ringCount = Math.max(1, firstRing.length);
   for (let k = 0; k < firstRing.length; k++) {
       const idx = firstRing[k];
       const angle = ringStart + (2 * Math.PI * k) / ringCount;
       nodePos[idx] = {
           x: centerX + Math.cos(angle) * outerRadius,
           y: centerY + Math.sin(angle) * outerRadius
       };
       placed[idx] = true;
   }

   // Place remaining nodes around their parent if available, else fallback ring.
   const remaining = [];
   for (let i = 0; i < n; i++) {
       if (placed[i]) continue;
       remaining.push(i);
   }

   const childrenByParent = {};
   remaining.forEach(idx => {
       const p = idToIndex[String(devices[idx].parent_id)];
       const key = (p !== undefined) ? String(p) : 'none';
       if (!childrenByParent[key]) childrenByParent[key] = [];
       childrenByParent[key].push(idx);
   });

   Object.keys(childrenByParent).forEach(key => {
       const group = childrenByParent[key];
       if (key === 'none' || nodePos[Number(key)] === undefined) {
           const cnt = Math.max(1, group.length);
           for (let j = 0; j < group.length; j++) {
               const angle = ringStart + (2 * Math.PI * j) / cnt;
               nodePos[group[j]] = {
                   x: centerX + Math.cos(angle) * (outerRadius * 1.15),
                   y: centerY + Math.sin(angle) * (outerRadius * 1.15)
               };
           }
           return;
       }

       const parentIdx = Number(key);
       const parentPos = nodePos[parentIdx];
    const localR = Math.max(210, outerRadius * 0.54);
       const cnt = Math.max(1, group.length);
       for (let j = 0; j < group.length; j++) {
           const angle = ringStart + (2 * Math.PI * j) / cnt;
           nodePos[group[j]] = {
               x: parentPos.x + Math.cos(angle) * localR,
               y: parentPos.y + Math.sin(angle) * localR
           };
       }
   });

   resolveNodeOverlaps(centerX, centerY);

   // Terapkan offset manual hasil drag-and-drop (world-space, ditambahkan
   // SETELAH auto-layout + overlap-resolution, supaya posisi yg sudah digeser
   // admin jadi kata akhir -- tidak didorong lagi oleh resolver di atas).
   for (let i = 0; i < n; i++) {
       if (!nodePos[i]) continue;
       nodePos[i].x += (devices[i].pos_dx || 0);
       nodePos[i].y += (devices[i].pos_dy || 0);
   }
}

function resolveNodeOverlaps(centerX, centerY) {
    const baseNodeRadius = 70;
    const minNodeGap = (baseNodeRadius * 2) + 84;
    const maxIter = 40;

   for (let iter = 0; iter < maxIter; iter++) {
       let moved = false;

       for (let i = 0; i < n; i++) {
           for (let j = i + 1; j < n; j++) {
               const a = nodePos[i];
               const b = nodePos[j];
               if (!a || !b) continue;

               const dx = b.x - a.x;
               const dy = b.y - a.y;
               const dist = Math.max(0.001, Math.hypot(dx, dy));
               if (dist >= minNodeGap) continue;

               const push = (minNodeGap - dist) / 2;
               const ux = dx / dist;
               const uy = dy / dist;
               a.x -= ux * push;
               a.y -= uy * push;
               b.x += ux * push;
               b.y += uy * push;
               moved = true;
           }
       }

       // Light pull to center to avoid drifting too far while keeping wide spacing.
       for (let i = 0; i < n; i++) {
           const p = nodePos[i];
           if (!p) continue;
           p.x += (centerX - p.x) * 0.006;
           p.y += (centerY - p.y) * 0.006;
       }

       if (!moved) break;
   }
}

function rectanglesOverlap(a, b, gap) {
   return !(a.x + a.w + gap < b.x || b.x + b.w + gap < a.x || a.y + a.h + gap < b.y || b.y + b.h + gap < a.y);
}

function getNonOverlappingCardCenter(midX, midY, cardW, cardH, placedCards) {
   const candidates = [];
   candidates.push({ x: midX, y: midY });

   const steps = [22, 34, 46, 58, 72, 88, 104];
   for (let s = 0; s < steps.length; s++) {
       const d = steps[s] * zoom;
       candidates.push({ x: midX + d, y: midY });
       candidates.push({ x: midX - d, y: midY });
       candidates.push({ x: midX, y: midY + d });
       candidates.push({ x: midX, y: midY - d });
       candidates.push({ x: midX + d, y: midY + d });
       candidates.push({ x: midX - d, y: midY + d });
       candidates.push({ x: midX + d, y: midY - d });
       candidates.push({ x: midX - d, y: midY - d });
   }

   for (let i = 0; i < candidates.length; i++) {
       const c = candidates[i];
       const rect = {
           x: c.x - cardW / 2,
           y: c.y - cardH / 2,
           w: cardW,
           h: cardH
       };

       if (rect.x < 8 || rect.y < 8 || rect.x + rect.w > canvas.width - 8 || rect.y + rect.h > canvas.height - 8) {
           continue;
       }

       let hasCollision = false;
       for (let k = 0; k < placedCards.length; k++) {
           if (rectanglesOverlap(rect, placedCards[k], 6 * zoom)) {
               hasCollision = true;
               break;
           }
       }

       if (!hasCollision) {
           placedCards.push(rect);
           return c;
       }
   }

    // Fallback: keep original position if every candidate collides.
    const fallback = { x: midX - cardW / 2, y: midY - cardH / 2, w: cardW, h: cardH };
   placedCards.push(fallback);
   return { x: midX, y: midY };
}
updateNodePos();

function buildDeviceIndexMap() {
   const idToIndex = {};
   for (let i = 0; i < devices.length; i++) {
	   idToIndex[String(devices[i].id)] = i;
   }
   return idToIndex;
}

function buildLinkSeparationMap(idToIndex) {
    const activePos = getActiveNodePos();
   const linksByParent = {};
   const slotMap = {};

   for (let i = 0; i < n; i++) {
	   const d = devices[i];
	   const hasParent = d.parent_id !== null && d.parent_id !== undefined && d.parent_id !== '';
	   if (!hasParent) continue;

	   const parentIdx = idToIndex[String(d.parent_id)];
	   if (parentIdx === undefined) continue;

	   if (!linksByParent[parentIdx]) {
		   linksByParent[parentIdx] = [];
	   }
	   linksByParent[parentIdx].push(i);
   }

   Object.keys(linksByParent).forEach(parentKey => {
	   const parentIdx = Number(parentKey);
       const children = linksByParent[parentIdx].sort((a, b) => {
           const angleA = Math.atan2(activePos[a].y - activePos[parentIdx].y, activePos[a].x - activePos[parentIdx].x);
           const angleB = Math.atan2(activePos[b].y - activePos[parentIdx].y, activePos[b].x - activePos[parentIdx].x);
           return angleA - angleB;
       });
	   const midSlot = (children.length - 1) / 2;
	   for (let order = 0; order < children.length; order++) {
		   const childIdx = children[order];
		   slotMap[`${childIdx}-${parentIdx}`] = order - midSlot;
	   }
   });

   return slotMap;
}

function getCurvedLinkGeometry(childIdx, parentIdx, slot) {
    const activePos = getActiveNodePos();
    const childCenter = activePos[childIdx];
    const parentCenter = activePos[parentIdx];
    const dx = parentCenter.x - childCenter.x;
    const dy = parentCenter.y - childCenter.y;
    const dist = Math.max(1, Math.hypot(dx, dy));

    // Direction from child -> parent.
    const ux = dx / dist;
    const uy = dy / dist;

    const nodeRadius = getNodeRadius();

    // Straight line: exactly from edge of child node to edge of parent node.
    const start = {
	   x: childCenter.x + ux * nodeRadius,
	   y: childCenter.y + uy * nodeRadius
    };
    const end = {
	   x: parentCenter.x - ux * nodeRadius,
	   y: parentCenter.y - uy * nodeRadius
    };

    // Midpoint for card placement.
    const midX = (start.x + end.x) / 2;
    const midY = (start.y + end.y) / 2;

    return { start, end, midX, midY };
}

function drawPopupInfo() {
   if (!popupInfo) return;
    const activePos = getActiveNodePos();
   const idx = popupInfo.idx;
   const d = devices[idx];
    const {x, y} = activePos[idx];
   // Ukuran popup tetap, tidak terlalu besar saat zoom kecil
   let baseW = 260, baseH = 150, minZoomPopup = Math.max(zoom, 0.7);
   let cardW = baseW * minZoomPopup, cardH = baseH * minZoomPopup, radius = 16 * minZoomPopup;
   let px = x + 32 * minZoomPopup, py = y - cardH / 2;
   // Pastikan popup tidak keluar dari canvas
   if (px + cardW > canvas.width - 10) px = canvas.width - cardW - 10;
   if (px < 10) px = 10;
   if (py < 10) py = 10;
   if (py + cardH > canvas.height - 10) py = canvas.height - cardH - 10;

   // Card
   ctx.save();
   ctx.globalAlpha = 0.98;
   ctx.beginPath();
   ctx.moveTo(px + radius, py);
   ctx.lineTo(px + cardW - radius, py);
   ctx.quadraticCurveTo(px + cardW, py, px + cardW, py + radius);
   ctx.lineTo(px + cardW, py + cardH - radius);
   ctx.quadraticCurveTo(px + cardW, py + cardH, px + cardW - radius, py + cardH);
   ctx.lineTo(px + radius, py + cardH);
   ctx.quadraticCurveTo(px, py + cardH, px, py + cardH - radius);
   ctx.lineTo(px, py + radius);
   ctx.quadraticCurveTo(px, py, px + radius, py);
   ctx.closePath();
   ctx.fillStyle = '#fff';
   ctx.shadowColor = '#607d8b';
   ctx.shadowBlur = 10 * minZoomPopup;
   ctx.fill();
   ctx.shadowBlur = 0;
   ctx.strokeStyle = '#1976d2';
   ctx.lineWidth = 2 * minZoomPopup;
   ctx.stroke();
   ctx.restore();

   // Info text
   ctx.save();
   ctx.globalAlpha = 1;
   ctx.fillStyle = '#263238';
   ctx.font = `bold ${15 * minZoomPopup}px Arial`;
   ctx.textAlign = 'left';
   let tx = px + 18 * minZoomPopup, ty = py + 28 * minZoomPopup;
   ctx.fillText(d.name, tx, ty);
   ctx.font = `${13 * minZoomPopup}px Arial`;
   ctx.fillStyle = '#37474f';
   ctx.fillText(`IP: ${d.ip}`, tx, ty + 22 * minZoomPopup);
   ctx.fillText(`MAC: -`, tx, ty + 40 * minZoomPopup);
   ctx.fillText(`Tipe: ${d.type}`, tx, ty + 58 * minZoomPopup);
   ctx.fillText(`Lokasi: ${d.location}`, tx, ty + 76 * minZoomPopup);
   ctx.font = `italic ${12 * minZoomPopup}px Arial`;
   ctx.fillStyle = '#607d8b';
   ctx.fillText(`Keterangan: ${d.desc}`, tx, ty + 94 * minZoomPopup);
   ctx.fillText(`Last Online: ${d.online ? '-' : (d.last_online || 'Never')}`, tx, ty + 112 * minZoomPopup);
   ctx.fillText(`Latency: ${d.latency !== undefined && d.latency !== null ? d.latency + ' ms' : '-'}`, tx, ty + 130 * minZoomPopup);
   ctx.restore();
}


function drawNetworkMap() {
    // Skip redraw if tab is not visible to save resources
    if (!isTabVisible) return;

   ctx.clearRect(0,0,canvas.width,canvas.height);
    renderNodePos = nodePos.map(worldToScreenPoint);
    nodeActionButtons = [];
   // Draw subtle grid background
   ctx.save();
   ctx.strokeStyle = '#eee';
   ctx.lineWidth = 1;
   let gridStep = 80 * zoom;
    for(let gx=(panX)%gridStep;gx<canvas.width;gx+=gridStep){
	   ctx.beginPath();ctx.moveTo(gx,0);ctx.lineTo(gx,canvas.height);ctx.stroke();
   }
    for(let gy=(panY)%gridStep;gy<canvas.height;gy+=gridStep){
	   ctx.beginPath();ctx.moveTo(0,gy);ctx.lineTo(canvas.width,gy);ctx.stroke();
   }
   ctx.restore();

   // Draw parent-child links (green if online, red if offline) and RX/TX+ping card
   ctx.save();
   ctx.lineWidth = 2.2 * zoom;
   ctx.font = `${11*zoom}px Arial`;
   ctx.textAlign = 'center';
   ctx.globalAlpha = 0.85;
   const idToIndex = buildDeviceIndexMap();
   const linkSeparationMap = buildLinkSeparationMap(idToIndex);
    const placedLinkCards = [];
   for(let i=0;i<n;i++){
	   const d = devices[i];
	   const hasParent = d.parent_id !== null && d.parent_id !== undefined && d.parent_id !== '';
	   if (hasParent) {
		   // Find parent index
		   let parentIdx = idToIndex[String(d.parent_id)];
		   if (parentIdx !== undefined) {
			   const slot = linkSeparationMap[`${i}-${parentIdx}`] ?? 0;
			   const linkGeo = getCurvedLinkGeometry(i, parentIdx, slot);
			   // Green if both online, red if either offline, gray if unknown
			   let color = '#bdbdbd';
			   if (d.online === true && devices[parentIdx].online === true) color = '#4caf50';
			   else if (d.online === false || devices[parentIdx].online === false) color = '#e53935';

			   // --- FIX: If d.online is true, always green (even if parent is unknown/gray) ---
			   if (d.online === true && (devices[parentIdx].online === undefined || devices[parentIdx].online === null)) {
				   color = '#4caf50';
			   }

			   ctx.save();
			   ctx.strokeStyle = color;
			   ctx.beginPath();
			   ctx.moveTo(linkGeo.start.x, linkGeo.start.y);
               ctx.lineTo(linkGeo.end.x, linkGeo.end.y);
			   ctx.stroke();
			   ctx.restore();

			   // Draw RX/TX + ping card at midpoint
			   let key = i+"-"+parentIdx;
               let midX = linkGeo.midX;
               let midY = linkGeo.midY;
			   let rx = '', tx = '';
			   if (linkTxRx[key]) {
				   rx = linkTxRx[key].rx;
				   tx = linkTxRx[key].tx;
			   } else {
				   // Fetch RX/TX asynchronously (demo: by IP)
				   fetch(`getdata/gettxrx_simple.php?ip=${encodeURIComponent(d.ip)}`)
					   .then(r=>r.json())
					   .then(data=>{
						   linkTxRx[key] = {rx: data.rx, tx: data.tx};
						   drawNetworkMap();
					   });
			   }

			   // Fetch ping latency (only once per device)
			   if (!pingCache[i]) {
				   pingCache[i] = {latency: null, loading: true};
				   fetch(`ping_device.php?ip=${encodeURIComponent(d.ip)}`)
					   .then(r=>r.json())
					   .then(data=>{
						   pingCache[i] = {latency: data.latency, loading: false};
						   drawNetworkMap();
					   })
					   .catch(()=>{
						   pingCache[i] = {latency: null, loading: false};
						   drawNetworkMap();
					   });
			   }
			   let pingMs = pingCache[i] ? pingCache[i].latency : null;
			   let pingLoading = pingCache[i] ? pingCache[i].loading : false;

			   if (rx && tx) {
				   // Card style
				   ctx.save();
                   let cardW = 78*zoom, cardH = 50*zoom, radius = 9*zoom;
                   const cardCenter = getNonOverlappingCardCenter(midX, midY, cardW, cardH, placedLinkCards);
                   midX = cardCenter.x;
                   midY = cardCenter.y;
				   ctx.globalAlpha = 0.92;
				   ctx.beginPath();
				   ctx.moveTo(midX - cardW/2 + radius, midY - cardH/2);
				   ctx.lineTo(midX + cardW/2 - radius, midY - cardH/2);
				   ctx.quadraticCurveTo(midX + cardW/2, midY - cardH/2, midX + cardW/2, midY - cardH/2 + radius);
				   ctx.lineTo(midX + cardW/2, midY + cardH/2 - radius);
				   ctx.quadraticCurveTo(midX + cardW/2, midY + cardH/2, midX + cardW/2 - radius, midY + cardH/2);
				   ctx.lineTo(midX - cardW/2 + radius, midY + cardH/2);
				   ctx.quadraticCurveTo(midX - cardW/2, midY + cardH/2, midX - cardW/2, midY + cardH/2 - radius);
				   ctx.lineTo(midX - cardW/2, midY - cardH/2 + radius);
				   ctx.quadraticCurveTo(midX - cardW/2, midY - cardH/2, midX - cardW/2 + radius, midY - cardH/2);
				   ctx.closePath();
				   ctx.fillStyle = '#fff';
				   ctx.shadowColor = color;
				   ctx.shadowBlur = 8*zoom;
				   ctx.fill();
				   ctx.shadowBlur = 0;
				   ctx.strokeStyle = color;
				   ctx.lineWidth = 2*zoom;
				   ctx.stroke();
				   ctx.restore();

				   // Text RX/TX
				   ctx.save();
				   ctx.fillStyle = color;
				   ctx.globalAlpha = 1;
				   ctx.font = `bold ${11*zoom}px Arial`;
				   ctx.fillText(`RX: ${rx}`, midX, midY-10*zoom);
				   ctx.font = `bold ${11*zoom}px Arial`;
				   ctx.fillText(`TX: ${tx}`, midX, midY+6*zoom);
				   // Ping ms
				   ctx.font = `italic ${10*zoom}px Arial`;
				   ctx.fillStyle = '#607d8b';
				   if (pingLoading) {
					   ctx.fillText('Ping: ...', midX, midY+22*zoom);
				   } else if (pingMs !== null && pingMs !== undefined) {
					   ctx.fillText(`Ping: ${pingMs} ms`, midX, midY+22*zoom);
				   } else {
					   ctx.fillText('Ping: -', midX, midY+22*zoom);
				   }
				   ctx.restore();
			   }
		   }
	   }
   }
   ctx.restore();

   // Draw nodes
   for(let i=0;i<n;i++){
	   const d = devices[i];
       const {x,y} = renderNodePos[i];
       const nodeRadius = getNodeRadius();
	   // Node shadow
	   let nodeColor = '#bdbdbd';
	   if (d.online === true) nodeColor = '#4caf50';
	   else if (d.online === false) nodeColor = '#e53935';
	   ctx.save();
	   ctx.beginPath();
       ctx.arc(x, y, nodeRadius, 0, 2*Math.PI);
	   ctx.shadowColor = nodeColor;
	   ctx.shadowBlur = d.online === true ? 10 : (d.online === false ? 6 : 0);
	   ctx.fillStyle = nodeColor;
	   ctx.fill();
	   ctx.shadowBlur = 0;
	   ctx.lineWidth = 2.2;
	   ctx.strokeStyle = '#263238';
	   ctx.stroke();
	   ctx.restore();

	   // Device name
	   ctx.save();
	   ctx.fillStyle = '#263238';
	   ctx.font = `${10*zoom}px Arial`;
	   ctx.textAlign = 'center';
	   ctx.fillText(truncateText(d.name, 15), x, y-10*zoom);
	   ctx.fillText(truncateText(d.ip, 15), x, y+5*zoom);
	   ctx.fillText(truncateText(d.location, 15), x, y+20*zoom);
	   ctx.restore();

       // Draw in-node action button to open traffic modal.
       const btnW = Math.max(36, Math.min(60, 46 * zoom));
       const btnH = Math.max(16, Math.min(24, 18 * zoom));
       const btnX = x - (btnW / 2);
       const btnY = y + Math.min(nodeRadius * 0.32, 26 * zoom);

       ctx.save();
       drawRoundedRect(btnX, btnY, btnW, btnH, 6 * zoom);
       ctx.fillStyle = 'rgba(255,255,255,0.92)';
       ctx.fill();
       ctx.lineWidth = Math.max(1, 1.2 * zoom);
       ctx.strokeStyle = '#2e7d32';
       ctx.stroke();
       ctx.fillStyle = '#1b5e20';
       ctx.font = `bold ${Math.max(8, 8.5 * zoom)}px Arial`;
       ctx.textAlign = 'center';
       ctx.textBaseline = 'middle';
       ctx.fillText('Grafik', x, btnY + btnH / 2);
       ctx.restore();

       nodeActionButtons.push({ x: btnX, y: btnY, w: btnW, h: btnH, nodeIdx: i });
   }

   // Draw popup info if any
   drawPopupInfo();
}

function centerNodeOnCanvas(nodeIdx) {
    if (nodeIdx < 0 || nodeIdx >= nodePos.length) return;
    const p = nodePos[nodeIdx];
    if (!p) return;

    // Move camera pan so selected node world position aligns with canvas center.
    panX += (cx - p.x);
    panY += (cy - p.y);
    updateNodePos();
    drawNetworkMap();
}

// Mouse drag panning, NODE drag-and-drop (posisi tersimpan per device), dan
// node click for popup.
let isDraggingNode = false;
let draggedNodeIdx = -1;
let nodeDragMoved = false;

function saveNodePosition(idx) {
    const dev = devices[idx];
    if (!dev || !dev.id) return; // Billing Server (id=0) tidak disimpan, murni visual
    const fd = new FormData();
    fd.append('id', dev.id);
    fd.append('pos_dx', dev.pos_dx || 0);
    fd.append('pos_dy', dev.pos_dy || 0);
    fetch('proses/save_network_device_position.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .catch(() => {}); // gagal simpan posisi bukan error fatal, biarkan diam2 (tetap kepakai di sesi ini)
}

canvas.addEventListener('mousedown', function(e) {
    const p = getCanvasPointerPos(e);
    pointerDownOnButton = getNodeButtonAt(p.x, p.y) !== -1;
    if (pointerDownOnButton) {
        isDragging = false;
        isDraggingNode = false;
    } else {
        const nodeIdx = getNodeBodyAt(p.x, p.y);
        if (nodeIdx !== -1) {
            isDraggingNode = true;
            draggedNodeIdx = nodeIdx;
            nodeDragMoved = false;
            isDragging = false;
        } else {
            isDraggingNode = false;
            draggedNodeIdx = -1;
            isDragging = true;
        }
    }
    lastX = p.x;
    lastY = p.y;
});
canvas.addEventListener('mousemove', function(e) {
    const p = getCanvasPointerPos(e);
	if (isDraggingNode && draggedNodeIdx !== -1) {
         const dx = (p.x - lastX) / zoom;
         const dy = (p.y - lastY) / zoom;
         if (dx !== 0 || dy !== 0) nodeDragMoved = true;
         devices[draggedNodeIdx].pos_dx = (devices[draggedNodeIdx].pos_dx || 0) + dx;
         devices[draggedNodeIdx].pos_dy = (devices[draggedNodeIdx].pos_dy || 0) + dy;
         lastX = p.x;
         lastY = p.y;
         updateNodePos();
         drawNetworkMap();
         canvas.style.cursor = 'move';
    } else if (isDragging) {
         panX += (p.x - lastX) / zoom;
         panY += (p.y - lastY) / zoom;
         lastX = p.x;
         lastY = p.y;
		 updateNodePos();
		 drawNetworkMap();
    } else {
         const overButton = getNodeButtonAt(p.x, p.y) !== -1;
         const overNode = !overButton && getNodeBodyAt(p.x, p.y) !== -1;
         canvas.style.cursor = overButton ? 'pointer' : (overNode ? 'move' : 'grab');
	}
});
canvas.addEventListener('mouseup', function(e) {
	if (isDraggingNode && draggedNodeIdx !== -1 && nodeDragMoved) {
        saveNodePosition(draggedNodeIdx);
    }
    isDragging = false;
    isDraggingNode = false;
    draggedNodeIdx = -1;
    pointerDownOnButton = false;
});
canvas.addEventListener('mouseleave', function(e) {
	if (isDraggingNode && draggedNodeIdx !== -1 && nodeDragMoved) {
        saveNodePosition(draggedNodeIdx);
    }
    // Mouse keluar kanvas -> tidak akan ada event 'click' susulan yg biasanya
    // membersihkan nodeDragMoved, jadi reset di sini juga supaya tidak nyangkut
    // "true" dan salah menekan klik normal berikutnya.
    nodeDragMoved = false;
    isDragging = false;
    isDraggingNode = false;
    draggedNodeIdx = -1;
    pointerDownOnButton = false;
    canvas.style.cursor = 'default';
});

canvas.addEventListener('click', function(e) {
    // Kalau baru saja selesai drag node (posisi berubah), jangan buka/tutup
    // popup -- itu bukan klik biasa, cuma efek ikutan mouseup setelah drag.
    if (nodeDragMoved) {
        nodeDragMoved = false;
        return;
    }
    // Open modal only when clicking in-node Grafik button.
    const p = getCanvasPointerPos(e);
    const btnIdx = getNodeButtonAt(p.x, p.y);
    if (btnIdx !== -1) {
        centerNodeOnCanvas(btnIdx);
        showTrafficModal(devices[btnIdx].ip, devices[btnIdx].pemilik, devices[btnIdx].password, devices[btnIdx].id);
    } else {
        popupInfo = null;
    }
	drawNetworkMap();
});

// Zoom in/out buttons
document.getElementById('zoomInBtn').addEventListener('click', function() {
   zoom = Math.min(maxZoom, zoom + zoomStep);
   updateNodePos();
   drawNetworkMap();
});
document.getElementById('zoomOutBtn').addEventListener('click', function() {
   zoom = Math.max(minZoom, zoom - zoomStep);
   updateNodePos();
   drawNetworkMap();
});

// Mouse wheel zoom
canvas.addEventListener('wheel', function(e) {
   e.preventDefault();
   e.stopPropagation();
   if (e.deltaY < 0) {
	   zoom = Math.min(maxZoom, zoom + zoomStep);
   } else {
	   zoom = Math.max(minZoom, zoom - zoomStep);
   }
   updateNodePos();
   drawNetworkMap();
}, { passive: false });


drawNetworkMap();

// Setelah AJAX ping status, update node di canvas
document.addEventListener('DOMContentLoaded', function() {
   const statusCells = document.querySelectorAll('.status-cell');
   statusCells.forEach(function(cell) {
	   let ipFull = cell.getAttribute('data-ip');
	   // Lakukan AJAX ping, update badge
	   fetch('ping_device.php?ip=' + encodeURIComponent(ipFull))
		   .then(r => r.json())
		   .then(data => {
			   // Update badge di tabel
			   if(data.status === 'ok') {
				   cell.innerHTML = data.online ? "<span class='badge bg-success'>Online</span>" : "<span class='badge bg-danger'>Offline</span>";
				   // Update last online
				   const lastOnlineCell = document.querySelector(`.last-online-cell[data-ip="${ipFull}"]`);
				   if (lastOnlineCell) {
					   lastOnlineCell.innerHTML = data.online ? '-' : (lastOnlineData[ipFull] || 'Never');
				   }
				   // Update latency
				   const latencyCell = document.querySelector(`.latency-cell[data-ip="${ipFull}"]`);
				   if (latencyCell) {
					   latencyCell.innerHTML = data.latency !== null ? data.latency + ' ms' : '-';
				   }
			   } else {
				   cell.innerHTML = '<span class="badge bg-secondary">Error</span>';
				   const lastOnlineCell = document.querySelector(`.last-online-cell[data-ip="${ipFull}"]`);
				   if (lastOnlineCell) {
					   lastOnlineCell.innerHTML = 'Error';
				   }
				   const latencyCell = document.querySelector(`.latency-cell[data-ip="${ipFull}"]`);
				   if (latencyCell) {
					   latencyCell.innerHTML = '-';
				   }
			   }
			   // Sinkronkan status online di array devices sesuai badge tabel
			   for (let i = 0; i < devices.length; i++) {
				   if (devices[i].ip === ipFull) {
					   if (devices[i].type !== 'Billing Server') {
						   // Jika badge tabel hijau, node canvas juga hijau
						   devices[i].online = (data.status === 'ok') ? data.online : null;
						   devices[i].latency = data.latency;
					   }
					   break;
				   }
			   }
			   drawNetworkMap();
		   })
		   .catch(() => {
			   cell.innerHTML = '<span class="badge bg-secondary">Error</span>';
			   const lastOnlineCell = document.querySelector(`.last-online-cell[data-ip="${ipFull}"]`);
			   if (lastOnlineCell) {
				   lastOnlineCell.innerHTML = 'Error';
			   }
			   const latencyCell = document.querySelector(`.latency-cell[data-ip="${ipFull}"]`);
			   if (latencyCell) {
				   latencyCell.innerHTML = '-';
			   }
			   for (let i = 0; i < devices.length; i++) {
				   if (devices[i].ip === ipFull) {
					   if (devices[i].type !== 'Billing Server') {
						   devices[i].online = null;
					   }
					   break;
				   }
			   }
			   drawNetworkMap();
		   });
   });
});

// Global history for active charts
let deviceActiveHistories = {};

// Global history for all charts
let deviceHistories = {};

// Global interval for all charts
let allChartsInterval;

// Global interval for active charts
let activeChartsInterval;

function loadActiveCharts() {
    console.log('loadActiveCharts called, devices:', devices);
    devices.forEach(dev => {
        if (dev.type === 'Billing Server') return;
        // Hanya load jika ada credentials
        if (!dev.pemilik || !dev.password) return;
        console.log('Loading active chart for device:', dev.name, dev.id);
        loadChartActiveForDevice(dev.id, dev.ip, dev.pemilik, dev.password);
    });
    // Interval removed, now handled globally
}

function loadChartActiveForDevice(deviceId, ip, pemilik, password) {
    console.log('loadChartActiveForDevice called for deviceId:', deviceId, 'ip:', ip);
    if (!deviceActiveHistories[deviceId]) {
        deviceActiveHistories[deviceId] = { labels: [], pppoe: [], hotspot: [] };
    }
    let history = deviceActiveHistories[deviceId];

    // Load history from server
    fetch(`getdata/traffic/load_history.php?deviceId=${deviceId}&type=active`)
        .then(r => r.json())
        .then(savedHistory => {
            if (savedHistory && savedHistory.labels) {
                history.labels = savedHistory.labels;
                history.pppoe = savedHistory.pppoe || [];
                history.hotspot = savedHistory.hotspot || [];
            }
            // Now load new data
            loadNewDataForActiveChart(deviceId, ip, pemilik, password, history);
        })
        .catch(err => {
            console.error('Error loading active history:', err);
            // Proceed without history
            loadNewDataForActiveChart(deviceId, ip, pemilik, password, history);
        });
}

function loadNewDataForActiveChart(deviceId, ip, pemilik, password, history) {
    let ctx = document.getElementById('chart-active-' + deviceId);
    console.log('Canvas element:', ctx);
    if (!ctx) {
        console.log('Canvas not found for deviceId:', deviceId);
        return; // Skip if canvas not found
    }
    ctx = ctx.getContext('2d');
    // If chart doesn't exist, create it
    if (!window['chartInstance_active_' + deviceId]) {
        window['chartInstance_active_' + deviceId] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: 'PPPoE Aktif',
                    data: [],
                    backgroundColor: 'rgba(54, 162, 235, 0.6)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                }, {
                    label: 'Hotspot Aktif',
                    data: [],
                    backgroundColor: 'rgba(75, 192, 192, 0.6)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                animation: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Aktif PPPoE & Hotspot untuk ' + ip
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    // Check if credentials exist
    if (!pemilik || !password) {
        console.log('No credentials for deviceId:', deviceId, 'showing zero');
        // No data, show zero
        window['chartInstance_active_' + deviceId].data.labels = [];
        window['chartInstance_active_' + deviceId].data.datasets[0].data = [];
        window['chartInstance_active_' + deviceId].data.datasets[1].data = [];
        window['chartInstance_active_' + deviceId].options.plugins.title.text = 'Tidak ada data aktif untuk ' + ip;
        window['chartInstance_active_' + deviceId].update();
    } else {
        console.log('Credentials exist for deviceId:', deviceId, 'loading active data');
        // Load active data
        fetch(`getdata/get-active-pppoe-hotspot.php?ip=${encodeURIComponent(ip)}&us=${encodeURIComponent(pemilik)}&ps=${encodeURIComponent(password)}`)
            .then(r => r.json())
            .then(data => {
                console.log('Active data for', ip, ':', data);
                if (data.error) {
                    console.error('Error:', data.error);
                    // Show zero on error
                    window['chartInstance_active_' + deviceId].data.labels = [];
                    window['chartInstance_active_' + deviceId].data.datasets[0].data = [];
                    window['chartInstance_active_' + deviceId].data.datasets[1].data = [];
                    window['chartInstance_active_' + deviceId].options.plugins.title.text = 'Error loading data untuk ' + ip;
                    window['chartInstance_active_' + deviceId].update();
                    return;
                }
                // Jika sudah sempit (100 titik), lakukan dari ulang
                if (history.labels.length >= 100) {
                    history.labels = [];
                    history.pppoe = [];
                    history.hotspot = [];
                }
                // Update history with new data
                const now = new Date();
                const timeStr = now.toTimeString().split(' ')[0]; // HH:MM:SS
                history.labels.push(timeStr);
                history.pppoe.push(data.pppoe_active);
                history.hotspot.push(data.hotspot_active);
                // Update chart data
                window['chartInstance_active_' + deviceId].data.labels = history.labels;
                window['chartInstance_active_' + deviceId].data.datasets[0].data = history.pppoe;
                window['chartInstance_active_' + deviceId].data.datasets[1].data = history.hotspot;
                window['chartInstance_active_' + deviceId].options.plugins.title.text = 'Aktif PPPoE & Hotspot untuk ' + ip;
                window['chartInstance_active_' + deviceId].update();
                // Save history
                saveHistory(deviceId, 'active', history);
            })
            .catch(err => {
                console.error('Error loading active chart:', err);
                // Show zero on error
                window['chartInstance_active_' + deviceId].data.labels = [];
                window['chartInstance_active_' + deviceId].data.datasets[0].data = [];
                window['chartInstance_active_' + deviceId].data.datasets[1].data = [];
                window['chartInstance_active_' + deviceId].options.plugins.title.text = 'Error loading data untuk ' + ip;
                window['chartInstance_active_' + deviceId].update();
            });
    }
}

function loadAllCharts() {
    console.log('loadAllCharts called, devices:', devices);
    devices.forEach(dev => {
        if (dev.type === 'Billing Server') return;
        console.log('Loading chart for device:', dev.name, dev.id);
        loadChartForDeviceAll(dev.id, dev.ip, dev.pemilik, dev.password);
    });
    // Interval removed, now handled globally
}

function loadChartForDeviceAll(deviceId, ip, pemilik, password) {
    console.log('loadChartForDeviceAll called for deviceId:', deviceId, 'ip:', ip);
    if (!deviceHistories[deviceId]) {
        deviceHistories[deviceId] = { labels: [], rx: [], tx: [], ping: [] };
    }
    let history = deviceHistories[deviceId];

    // Load history from server
    fetch(`getdata/traffic/load_history.php?deviceId=${deviceId}&type=trafik`)
        .then(r => r.json())
        .then(savedHistory => {
            if (savedHistory && savedHistory.labels) {
                history.labels = savedHistory.labels;
                history.rx = savedHistory.rx || [];
                history.tx = savedHistory.tx || [];
                history.ping = savedHistory.ping || [];
            }
            // Now load new data
            loadNewDataForChart(deviceId, ip, pemilik, password, history);
        })
        .catch(err => {
            console.error('Error loading history:', err);
            // Proceed without history
            loadNewDataForChart(deviceId, ip, pemilik, password, history);
        });
}

function loadNewDataForChart(deviceId, ip, pemilik, password, history) {
    let ctx = document.getElementById('chart-all-' + deviceId);
    console.log('Canvas element:', ctx);
    if (!ctx) {
        console.log('Canvas not found for deviceId:', deviceId);
        return; // Skip if canvas not found
    }
    ctx = ctx.getContext('2d');
    // If chart doesn't exist, create it
    if (!window['chartInstance_all_' + deviceId]) {
        window['chartInstance_all_' + deviceId] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: history.labels,
                datasets: [{
                    label: 'RX (Mbps)',
                    data: history.rx,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }, {
                    label: 'TX (Mbps)',
                    data: history.tx,
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                animation: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Trafik untuk ' + ip
                    }
                }
            }
        });
    } else {
        // Update existing chart with loaded history
        window['chartInstance_all_' + deviceId].data.labels = history.labels;
        window['chartInstance_all_' + deviceId].data.datasets[0].data = history.rx;
        window['chartInstance_all_' + deviceId].data.datasets[1].data = history.tx;
        window['chartInstance_all_' + deviceId].update();
    }

    // Check if credentials exist
    if (!pemilik || !password) {
        console.log('No credentials for deviceId:', deviceId, 'loading ping chart');
        // Load ping chart
        fetch('ping_device.php?ip=' + encodeURIComponent(ip))
            .then(r => r.json())
            .then(data => {
                console.log('Ping data for', ip, ':', data);
                if (data.status === 'ok' && data.latency !== null) {
                    // Jika sudah sempit (100 titik), lakukan dari ulang
                    if (history.labels.length >= 100) {
                        history.labels = [];
                        history.ping = [];
                    }
                    // Update history with new ping data
                    const now = new Date();
                    const timeStr = now.toTimeString().split(' ')[0]; // HH:MM:SS
                    history.labels.push(timeStr);
                    history.ping.push(data.latency);
                    // Update chart data
                    window['chartInstance_all_' + deviceId].data.labels = history.labels;
                    window['chartInstance_all_' + deviceId].data.datasets = [{
                        label: 'Ping (ms)',
                        data: history.ping,
                        borderColor: 'rgb(255, 165, 0)',
                        tension: 0.1
                    }];
                    window['chartInstance_all_' + deviceId].options.plugins.title.text = 'Ping Latency untuk ' + ip;
                    window['chartInstance_all_' + deviceId].update();
                    // Save history
                    saveHistory(deviceId, 'trafik', history);
                } else {
                    // No data, clear chart
                    window['chartInstance_all_' + deviceId].data.labels = [];
                    window['chartInstance_all_' + deviceId].data.datasets = [];
                    window['chartInstance_all_' + deviceId].options.plugins.title.text = 'Tidak ada data ping untuk ' + ip;
                    window['chartInstance_all_' + deviceId].update();
                }
            })
            .catch(err => {
                console.error('Error loading ping chart:', err);
            });
    } else {
        console.log('Credentials exist for deviceId:', deviceId, 'loading traffic chart');
        // Load traffic chart
        fetch(`getdata/get-trafikinterface.php?ip=${encodeURIComponent(ip)}&ps=${encodeURIComponent(password)}&us=${encodeURIComponent(pemilik)}`)
            .then(r => r.json())
            .then(data => {
                console.log('Traffic data for', ip, ':', data);
                if (data.error) {
                    console.error('Error:', data.error);
                    return;
                }
                // Calculate total TX and RX from PPPoE and Hotspot
                let totalTx = 0;
                let totalRx = 0;

                if (data.pppoe_trafik && Array.isArray(data.pppoe_trafik)) {
                    data.pppoe_trafik.forEach(iface => {
                        totalTx += iface.output;
                        totalRx += iface.input;
                    });
                }

                if (data.hotspot_trafik && Array.isArray(data.hotspot_trafik)) {
                    data.hotspot_trafik.forEach(iface => {
                        totalTx += iface.output;
                        totalRx += iface.input;
                    });
                }

                // Jika sudah sempit (100 titik), lakukan dari ulang
                if (history.labels.length >= 100) {
                    history.labels = [];
                    history.rx = [];
                    history.tx = [];
                }
                // Update history with new data
                const now = new Date();
                const timeStr = now.toTimeString().split(' ')[0]; // HH:MM:SS
                history.labels.push(timeStr);
                history.rx.push(totalRx);
                history.tx.push(totalTx);
                // Update chart data
                window['chartInstance_all_' + deviceId].data.labels = history.labels;
                window['chartInstance_all_' + deviceId].data.datasets = [{
                    label: 'RX (Mbps)',
                    data: history.rx,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }, {
                    label: 'TX (Mbps)',
                    data: history.tx,
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1
                }];
                window['chartInstance_all_' + deviceId].options.plugins.title.text = 'Trafik untuk ' + ip;
                window['chartInstance_all_' + deviceId].update();
                // Save history
                saveHistory(deviceId, 'trafik', history);
            })
            .catch(err => {
                console.error('Error loading chart:', err);
            });
    }
}

function saveHistory(deviceId, type, history) {
    fetch('getdata/traffic/save_history.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `deviceId=${deviceId}&type=${type}&data=${encodeURIComponent(JSON.stringify(history))}`
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            console.error('Error saving history:', data.error);
        }
    })
    .catch(err => {
        console.error('Error saving history:', err);
    });
}

// Load charts on page load
document.addEventListener('DOMContentLoaded', function() {
    // loadAllCharts(); // Remove this, load only when tab is active

    // Add event listener for clear cache button
    document.getElementById('clearCacheBtn').addEventListener('click', function() {
        clearBrowserCache();
        alert('Cache cleared successfully!');
    });

    // Add event listener for pause/resume updates button
    document.getElementById('pauseUpdatesBtn').addEventListener('click', function() {
        updatesPaused = !updatesPaused;
        this.textContent = updatesPaused ? 'Resume Updates' : 'Pause Updates';
        this.classList.toggle('btn-warning');
        this.classList.toggle('btn-success');
        console.log(updatesPaused ? 'Updates paused' : 'Updates resumed');
    });

    // Tab toggle functionality
    const tabDeviceMonitoring = document.getElementById('tabDeviceMonitoring');
    const tabGrafikTrafik = document.getElementById('tabGrafikTrafik');
    const tabGrafikAktif = document.getElementById('tabGrafikAktif');
    const contentDeviceMonitoring = document.getElementById('contentDeviceMonitoring');
    const contentGrafikTrafik = document.getElementById('contentGrafikTrafik');
    const contentGrafikAktif = document.getElementById('contentGrafikAktif');

    function showTab(tabButton, contentDiv) {
        console.log('showTab called for', contentDiv.id);
        // Remove active class from all buttons
        tabDeviceMonitoring.classList.remove('btn-primary');
        tabDeviceMonitoring.classList.add('btn-secondary');
        tabGrafikTrafik.classList.remove('btn-primary');
        tabGrafikTrafik.classList.add('btn-secondary');
        tabGrafikAktif.classList.remove('btn-primary');
        tabGrafikAktif.classList.add('btn-secondary');

        // Add active class to clicked button
        tabButton.classList.remove('btn-secondary');
        tabButton.classList.add('btn-primary');

        // Hide all content
        contentDeviceMonitoring.style.display = 'none';
        contentGrafikTrafik.style.display = 'none';
        contentGrafikAktif.style.display = 'none';

        // Show selected content
        contentDiv.style.display = 'block';

        // If switching to grafik tab, load charts
        if (contentDiv === contentGrafikTrafik) {
            console.log('Loading charts for grafik tab');
            loadAllCharts();
        } else if (contentDiv === contentGrafikAktif) {
            console.log('Loading active charts for aktif tab');
            loadActiveCharts();
        }
    }

    tabDeviceMonitoring.addEventListener('click', function() {
        showTab(tabDeviceMonitoring, contentDeviceMonitoring);
    });

    tabGrafikTrafik.addEventListener('click', function() {
        showTab(tabGrafikTrafik, contentGrafikTrafik);
    });

    tabGrafikAktif.addEventListener('click', function() {
        showTab(tabGrafikAktif, contentGrafikAktif);
    });

    // Manual update buttons
    document.getElementById('updateGrafikTrafikBtn').addEventListener('click', function() {
        loadAllCharts();
    });

    document.getElementById('updateGrafikAktifBtn').addEventListener('click', function() {
        loadActiveCharts();
    });

    // Global auto-update every 20 seconds if tab is active and visible
    chartUpdateInterval = setInterval(() => {
        if (isTabVisible && !updatesPaused) {
            if (contentGrafikTrafik.style.display === 'block') {
                loadAllCharts();
            }
            if (contentGrafikAktif.style.display === 'block') {
                loadActiveCharts();
            }
        }
    }, 20000);
});
</script>
<style>
/* =========================================
   MYNETWORKMAP CARD HEADER STYLING
   ========================================= */
.card-header {
  font-size: 0.95em;
  font-weight: 600;
  padding: 12px 15px !important;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.card-header.bg-light {
  border-bottom: 2px solid #e2e8f0;
}

.card-header h6 {
  margin: 0;
  font-size: 1em;
  font-weight: 600;
}

.card-header small {
  font-size: 0.85em;
  font-weight: 500;
}

/* =========================================
   DARK MODE CARD HEADERS - MYNETWORKMAP
   ========================================= */
body.app-theme-dark .card-header.bg-light {
  background: linear-gradient(135deg, #1a233a 0%, #0f172a 100%) !important;
  border-bottom: 2px solid rgba(59, 130, 246, 0.3) !important;
  color: #e2e8f0 !important;
}

body.app-theme-dark .card-header.bg-light small {
  color: #cbd5e1 !important;
}

body.app-theme-dark .card-header.bg-light .fw-bold.text-dark {
  color: #e2e8f0 !important;
}

body.app-theme-dark .card-body {
  background-color: #0f172a !important;
  color: #e2e8f0 !important;
}

body.app-theme-dark .card {
  background-color: #0f172a !important;
  border-color: rgba(59, 130, 246, 0.2) !important;
}

/* Canvas background dark mode */
body.app-theme-dark div[style*="overflow:auto"] {
  background-color: #1a233a !important;
}

/* Table styling dark mode */
body.app-theme-dark .table {
  color: #e2e8f0 !important;
}

body.app-theme-dark .table thead th {
  background-color: rgba(59, 130, 246, 0.15) !important;
  color: #f1f5f9 !important;
  border-color: rgba(59, 130, 246, 0.2) !important;
}

body.app-theme-dark .table tbody td {
  border-color: rgba(59, 130, 246, 0.15) !important;
}

body.app-theme-dark .table tbody tr:hover {
  background-color: rgba(59, 130, 246, 0.08) !important;
}
</style>

<?php require 'footer.php'; ?>