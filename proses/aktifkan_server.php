<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>🔥 Hotspot & PPPoE Setup Panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body {
  background: linear-gradient(135deg, #6a11cb, #2575fc);
  font-family: 'Poppins', sans-serif;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
}
.card {
  max-width: 750px;
  width: 100%;
  border-radius: 15px;
  box-shadow: 0 6px 25px rgba(0,0,0,0.25);
  border: none;
}
.card-header {
  background: linear-gradient(90deg, #2575fc, #6a11cb);
  color: #fff;
  text-align: center;
  font-size: 1.3rem;
  font-weight: 600;
  border-top-left-radius: 15px;
  border-top-right-radius: 15px;
}
.btn-primary {
  background: linear-gradient(90deg, #6a11cb, #2575fc);
  border: none;
  border-radius: 8px;
  transition: 0.3s;
}
.btn-primary:hover {
  transform: scale(1.03);
  background: linear-gradient(90deg, #2575fc, #6a11cb);
}
pre {
  background: #f8f9fa;
  padding: 10px;
  border-radius: 10px;
  font-size: 0.9rem;
}
</style>
</head>
<body>

<div class="card">
  <div class="card-header">🌐 Hotspot & PPPoE Setup Panel</div>
  <div class="card-body">

<?php
require '../cek-sesi.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
include('../routeros_api.class.php');
$API = new RouterosAPI();
$result_message = '';
$log = [];


      // Ambil data pool
















if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $server_ip = trim($_POST['server_ip'] ?? '');
    $username  = trim($_POST['pemilik'] ?? 'admin');
    $password  = trim($_POST['password'] ?? 'admin123');

    if ($API->connect($server_ip, $username, $password)) {

        // --- Dapatkan daftar interface dan IP ---
        $interfaces = [];
        $iface_ips = [];
        $iface_data = $API->comm("/interface/print");
        foreach ($iface_data as $iface) {
            $interfaces[] = $iface['name'];
        }
        $ip_data = $API->comm("/ip/address/print");
        foreach ($ip_data as $ip) {
            if (isset($ip['interface'], $ip['address'])) {
                $iface_ips[$ip['interface']] = $ip['address'];
            }
        }

        // === PPPoE langsung dari POST ===
        if (!empty($_POST['pppoe'])) {
            foreach ($_POST['pppoe'] as $iface) {
                if (!in_array($iface, $interfaces)) {
                    $log[] = "⚠️ PPPoE: Interface <b>$iface</b> tidak ditemukan.";
                    continue;
                }
                $pppoe_exist = $API->comm("/interface/pppoe-server/server/print", ["?interface" => $iface]);
                if (!empty($pppoe_exist)) {
                    $log[] = "⚠️ PPPoE di <b>$iface</b> sudah aktif.";
                    continue;
                }
                $API->comm("/interface/pppoe-server/server/add", [
                    "service-name" => "PPPOE-$iface",
                    "interface" => $iface,
                    "disabled" => "no",
                    "default-profile" => "default"
                ]);
                $log[] = "✅ PPPoE diaktifkan di <b>$iface</b>.";
            }
        }

        // === HOTSPOT (melalui form) ===
        if (!empty($_POST['setup_hotspot'])) {
            $iface = $_POST['hotspot_if'] ?? '';
            $hotspot_ip = $_POST['hotspot_ip'] ?? '10.5.50.1/24';
            $hotspot_pool_start = $_POST['hotspot_pool_start'] ?? '10.5.50.2';
            $hotspot_pool_end = $_POST['hotspot_pool_end'] ?? '10.5.50.254';

            if (!in_array($iface, $interfaces)) {
                $log[] = "⚠️ Interface <b>$iface</b> tidak ditemukan.";
            } else {
                // Cek pool
                $pool_list = $API->comm("/ip/pool/print");
                $pool_exists = false;
                foreach ($pool_list as $p) {
                    if ($p['name'] === "hs-pool") $pool_exists = true;
                }
                if (!$pool_exists) {
                    $API->comm("/ip/pool/add", [
                        "name" => "hs-pool",
                        "ranges" => "$hotspot_pool_start-$hotspot_pool_end"
                    ]);
                    $log[] = "✅ Pool hs-pool dibuat ($hotspot_pool_start-$hotspot_pool_end).";
                } else {
                    $log[] = "⚠️ Pool hs-pool sudah ada.";
                }

                // Tambah IP jika belum ada
                if (isset($iface_ips[$iface])) {
                    $log[] = "ℹ️ $iface sudah memiliki IP <b>{$iface_ips[$iface]}</b>.";
                } else {
                    $API->comm("/ip/address/add", [
                        "address" => $hotspot_ip,
                        "interface" => $iface,
                        "comment" => "Hotspot $iface"
                    ]);
                    $log[] = "✅ IP $hotspot_ip ditambahkan ke <b>$iface</b>.";
                }

                // Tambahkan Hotspot Server jika belum ada
                $hs_exist = $API->comm("/ip/hotspot/print", ["?interface" => $iface]);
                if (empty($hs_exist)) {
                    $API->comm("/ip/hotspot/add", [
                        "interface" => $iface,
                        "address-pool" => "hs-pool",
                        "profile" => "default",
                        "disabled" => "no"
                    ]);
                    $log[] = "✅ Hotspot aktif di <b>$iface</b>.";
                } else {
                    $log[] = "⚠️ Hotspot di <b>$iface</b> sudah aktif.";
                }
            }
        }

        $API->disconnect();
        $result_message = "<div class='alert alert-success mt-3'><b>✅ Setup berhasil dijalankan di $server_ip</b></div>";

    } else {
        $result_message = "<div class='alert alert-danger mt-3'>❌ Gagal konek ke router $server_ip</div>";
    }
}

// === Form Hotspot ===
if (!empty($_POST['hotspot'])) {
    $iface = $_POST['hotspot'][0];
    $current_ip = $iface_ips[$iface] ?? '10.5.50.1/24';
    list($ip_only) = explode('/', $current_ip);
    $part = explode('.', $ip_only);
    $pool_start = "{$part[0]}.{$part[1]}.{$part[2]}.2" ?? '';
    $pool_end = "{$part[0]}.{$part[1]}.{$part[2]}.254" ?? '';
?>
<form method="POST">
    <input type="hidden" name="server_ip" value="<?= htmlspecialchars($_POST['server_ip']) ?>">
    <input type="hidden" name="pemilik" value="<?= htmlspecialchars($_POST['pemilik']) ?>">
    <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password']) ?>">
    <input type="hidden" name="hotspot_if" value="<?= htmlspecialchars($iface) ?>">
    <input type="hidden" name="setup_hotspot" value="1">

    <h5 class="mb-3">🔥 Konfigurasi Hotspot</h5>
    <div class="mb-3">
        <label class="form-label">Interface:</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars($iface) ?>" disabled>
    </div>



      <?php if(!$current_ip)
        {  ?>
    <div class="mb-3">
        <label class="form-label">IP Address (Gateway Hotspot):</label>
        <input type="text" name="hotspot_ip" class="form-control" value="<?= htmlspecialchars($current_ip) ?>" required>
    </div>


  
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">IP Pool Start:</label>
            <input type="text" name="hotspot_pool_start" class="form-control" value="<?= htmlspecialchars($pool_start) ?>" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">IP Pool End:</label>
            <input type="text" name="hotspot_pool_end" class="form-control" value="<?= htmlspecialchars($pool_end) ?>" required>
        </div>
    </div>
   <?php }
   else
   {
                                                
                                                $sqlPool = "SELECT iplocal, ipawal, ipakhir FROM pool WHERE pemilik='$ceknama'";
                                                $resultPool = mysqli_query($conn, $sqlPool);

                                                // Ambil semua paket untuk cek LOCAL & REMOTE
                                                $sqlPaket = "SELECT LOCAL, REMOTE FROM paket WHERE PEMILIK='$ceknama'";
                                                $resultPaket = mysqli_query($conn, $sqlPaket);

                                                $usedLocal = [];
                                                $usedRemot = [];
                                                while($row = mysqli_fetch_array($resultPaket)){
                                                    if(!empty($row['LOCAL'])) $usedLocal[] = $row['LOCAL'];
                                                    if(!empty($row['REMOTE'])) $usedRemot[] = $row['REMOTE'];
                                                }

                                                // Cek apakah pool tersedia
                                                $poolAvailable = false;
                                                $poolMap = [];
                                                while($row = mysqli_fetch_array($resultPool)){
                                                    $iplocal = $row['iplocal'];
                                                    $ipremote = $row['ipawal'].'-'.$row['ipakhir'];
                                                    $ipakhir = $row['ipakhir'];
                                                    $ipawal = $row['ipawal'];
                                                    $poolMap[$iplocal] = $ipremote;

                                                    if(!in_array($iplocal, $usedLocal) && !in_array($ipremote, $usedRemot)){
                                                        $poolAvailable = true; // ada pool yang belum dipakai
                                                    }
                                                }

                                          
                                                ?>
                                                

                                                <div class="mb-3">
                                                    <label for="hotspot_ip" class="form-label">IP Address (Gateway Hotspot):</label>
                                                 
                                                      <input <?php echo $poolAvailable ? '' : 'disabled'; ?> type="text" name="hotspot_ip" class="form-control" value="<?= htmlspecialchars($iplocal) ?>" required>
                                                </div>
                                                <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">IP Pool Start:</label>
                                                            <input <?php echo $poolAvailable ? '' : 'disabled'; ?> type="text" name="hotspot_pool_start" class="form-control" value="<?= htmlspecialchars($ipawal) ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">IP Pool End:</label>
                                                            <input <?php echo $poolAvailable ? '' : 'disabled'; ?> type="text" name="hotspot_pool_end" class="form-control" value="<?= htmlspecialchars($ipakhir) ?>" required>
                                                        </div>
                                                    </div>

                                                <?php if(!$poolAvailable): ?>
                                                    <a href="../pool.php" class="btn btn-warning">Buat IP Pool</a>
                                                <?php endif; ?>



  <?php } ?>













    <div class="text-center">
        <button type="submit" class="btn btn-primary btn-lg px-4 mt-2">🚀 Jalankan Setup Hotspot</button>
    </div>
</form>
<?php } ?>

<?= $result_message ?>
<?php if (!empty($log)): ?>
<hr>
<h6>📋 Log Eksekusi:</h6>
<ul class="list-group">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?= $l ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<div class="text-center">
        <a type="submit" class="btn btn-primary btn-lg px-4 mt-2" href='../server.php'>Kembali</a>
    </div>
</div>
</div>
</body>
</html>
