
<?php
// Koneksi database (ganti sesuai environment Anda)
$conn = mysqli_connect('localhost', 'root', '', 'billing');
if (!$conn) { die('Koneksi database gagal'); }

// Cek dan buat tabel network_devices jika belum ada
$checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'network_devices'");
if (mysqli_num_rows($checkTable) == 0) {
	$createTable = "CREATE TABLE network_devices (
		id INT AUTO_INCREMENT PRIMARY KEY,
		name VARCHAR(100),
		ip_address VARCHAR(45),
		type VARCHAR(50),
		location VARCHAR(100),
		description TEXT,
		created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
	)";
	if (mysqli_query($conn, $createTable)) {
		echo "<div style='color:blue'>Tabel network_devices berhasil dibuat otomatis.</div>";
	} else {
		die("<div style='color:red'>Gagal membuat tabel network_devices: ".mysqli_error($conn)."</div>");
	}
}

// Proses tambah device
if (isset($_POST['add_device'])) {
	$name = mysqli_real_escape_string($conn, $_POST['name']);
	$ip = mysqli_real_escape_string($conn, $_POST['ip_address']);
	$type = mysqli_real_escape_string($conn, $_POST['type']);
	$loc = mysqli_real_escape_string($conn, $_POST['location']);
	$desc = mysqli_real_escape_string($conn, $_POST['description']);
	mysqli_query($conn, "INSERT INTO network_devices (name, ip_address, type, location, description) VALUES ('$name', '$ip', '$type', '$loc', '$desc')");
	echo "<div style='color:green'>Device berhasil ditambah!</div>";
}

?>
<h2>Tambah Device Monitoring</h2>
<form method="POST" action="">
	<input type="text" name="name" placeholder="Nama Device" required>
	<input type="text" name="ip_address" placeholder="IP Address" required>
	<input type="text" name="type" placeholder="Tipe (Router/Switch/etc)" required>
	<input type="text" name="location" placeholder="Lokasi">
	<textarea name="description" placeholder="Keterangan"></textarea>
	<button type="submit" name="add_device">Tambah Device</button>
</form>


<h2>Daftar Device & Status</h2>
<table border="1" cellpadding="6" cellspacing="0">
	<tr><th>Nama</th><th>IP</th><th>Tipe</th><th>Lokasi</th><th>Status</th><th>Keterangan</th></tr>
	<?php
	$devices = [];
	$result = mysqli_query($conn, "SELECT * FROM network_devices");
	while ($row = mysqli_fetch_assoc($result)) {
		$ip = $row['ip_address'];
		$output = shell_exec("ping -n 1 -w 1000 " . escapeshellarg($ip));
		$isOnline = (strpos($output, 'TTL=') !== false);
		$status = $isOnline ? "<span style='color:green'>Online</span>" : "<span style='color:red'>Offline</span>";
		$devices[] = [
			'name' => $row['name'],
			'ip' => $row['ip_address'],
			'type' => $row['type'],
			'location' => $row['location'],
			'desc' => $row['description'],
			'online' => $isOnline
		];
		echo "<tr>
			<td>".htmlspecialchars($row['name'])."</td>
			<td>".htmlspecialchars($row['ip_address'])."</td>
			<td>".htmlspecialchars($row['type'])."</td>
			<td>".htmlspecialchars($row['location'])."</td>
			<td>$status</td>
			<td>".htmlspecialchars($row['description'])."</td>
		</tr>";
	}
	?>
</table>

<h2>Network Map (Visual Monitoring)</h2>
<div style="overflow:auto; border:1px solid #aaa; background:#f8f8f8; width:100%; max-width:900px; height:500px;">
	<canvas id="networkMap" width="1600" height="900"></canvas>
</div>
<script>
// Data device dari PHP
const devices = <?php echo json_encode($devices); ?>;

const canvas = document.getElementById('networkMap');
const ctx = canvas.getContext('2d');
ctx.clearRect(0,0,canvas.width,canvas.height);

// Layout node otomatis (grid/lingkaran)
const n = devices.length;
const cx = canvas.width/2, cy = canvas.height/2, r = Math.min(cx,cy)-120;
const nodePos = [];
for(let i=0;i<n;i++){
	let angle = (2*Math.PI*i)/n;
	let x = cx + r*Math.cos(angle);
	let y = cy + r*Math.sin(angle);
	nodePos.push({x,y});
}

// Draw links (full mesh, bisa diubah sesuai kebutuhan)
ctx.strokeStyle = '#bbb';
ctx.lineWidth = 1;
for(let i=0;i<n;i++){
	for(let j=i+1;j<n;j++){
		ctx.beginPath();
		ctx.moveTo(nodePos[i].x, nodePos[i].y);
		ctx.lineTo(nodePos[j].x, nodePos[j].y);
		ctx.stroke();
	}
}

// Draw nodes
for(let i=0;i<n;i++){
	const d = devices[i];
	const {x,y} = nodePos[i];
	// Node circle
	ctx.beginPath();
	ctx.arc(x, y, 38, 0, 2*Math.PI);
	ctx.fillStyle = d.online ? '#4caf50' : '#e53935';
	ctx.shadowColor = d.online ? '#4caf50' : '#e53935';
	ctx.shadowBlur = d.online ? 12 : 0;
	ctx.fill();
	ctx.shadowBlur = 0;
	ctx.strokeStyle = '#333';
	ctx.lineWidth = 2;
	ctx.stroke();
	// Device name
	ctx.fillStyle = '#222';
	ctx.font = 'bold 13px Arial';
	ctx.textAlign = 'center';
	ctx.fillText(d.name, x, y-18);
	ctx.font = '12px Arial';
	ctx.fillText(d.ip, x, y+2);
	ctx.font = 'italic 11px Arial';
	ctx.fillText(d.type, x, y+18);
}
</script>
