<?php require 'header.php'; ?>

    <div class="container-fluid py-4">
        <?php if (isset($_GET['pesan']) && $_GET['pesan'] == "berhasil"): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <strong>Success!</strong> <?php echo htmlspecialchars($_GET['text']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

      <div class="col-12 mb-4">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center pb-0">
          
       
                 <h3 class="mb-3">Radius Vouchers</h3>
 </div>





<?php


require 'cek-sesi.php';



$now = time();

$users_file = "/etc/freeradius/3.0/users";
$timer_dir  = "/etc/freeradius/user_timers";
$debug_file = '/var/log/freeradius/debug-radius-web.log';

// ================= Fungsi Restart =================
function getFreeradiusPID() {
    $pid = trim(shell_exec("pidof freeradius"));
    if($pid != '') return (int)$pid;

    $output = shell_exec("systemctl show -p MainPID freeradius");
    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
        return (int)$m[1];
    }
    return 0;
}

function restartFreeradius() {
    global $debug_file;

    $pid = getFreeradiusPID();
    if($pid > 0){
        shell_exec('sudo systemctl stop freeradius');
        shell_exec("sudo kill -9 $pid");
    }

    // Reset debug file
    if(file_exists($debug_file)) shell_exec("sudo rm -f $debug_file");
    shell_exec("sudo touch $debug_file");
    shell_exec("sudo chmod 666 $debug_file");

    // Jalankan FreeRADIUS di background
    shell_exec("sudo freeradius -X > $debug_file 2>&1 &");
}

// ================= Fungsi Hapus User =================
function hapus_user($username) {
    global $users_file, $timer_dir;

    // Validasi username
    $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
    if(empty($username)) return false;

    $timer_file = "$timer_dir/$username.json";

    // Baca isi file users
    $content = @file_get_contents($users_file);
    if($content === false) {
        error_log("❌ Gagal membaca file users");
        return false;
    }

    // Hapus blok user multiline (hingga baris kosong atau akhir file)
    $pattern = "/^" . preg_quote($username, '/') . ".*?(?=^\S|\z)/ms";
    $new_content = preg_replace($pattern, '', $content, -1, $count);

    // Hilangkan baris kosong berlebih
    $new_content = preg_replace("/\n{3,}/", "\n\n", $new_content);
    $new_content = trim($new_content) . "\n\n";

    if($count === 0) {
        error_log("⚠️ User $username tidak ditemukan di file users.");
        return false;
    }

    // Tulis ulang file users via temporary file
    $tmp = tempnam(sys_get_temp_dir(), 'usr');
    file_put_contents($tmp, $new_content);
    shell_exec("sudo tee " . escapeshellarg($users_file) . " < $tmp > /dev/null");
    unlink($tmp);

    // Hapus timer jika ada
    if(file_exists($timer_file)) unlink($timer_file);

    // Restart FreeRADIUS
    restartFreeradius();

    return true;
}




// Tangani form hapus (POST biasa, jika tidak pakai AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $username = $_POST['delete_user'];
    if (hapus_user($username)) {
        echo "<script>alert('✅ User $username dihapus. FreeRADIUS direstart.');location.reload();</script>";
    }
}


// Tangani hapus beberapa user sekaligus (delete yang dicentang)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected']) && !empty($_POST['selected'])) {
    $deleted_users = [];
    foreach ($_POST['selected'] as $item_json) {
        $data = json_decode($item_json, true);
        if (!$data) continue;
        $username = $data['us'] ?? $data['username'] ?? null;
        if ($username && hapus_user($username)) {
            $deleted_users[] = $username;
        }
    }

    if (!empty($deleted_users)) {
        $list = implode(', ', $deleted_users);
        echo "<script>alert('✅ Users berhasil dihapus: $list');location.reload();</script>";
    }
}



// Ambil timer
$user_timers = [];
foreach (glob("$timer_dir/*.json") as $jsonfile) {
    $data = json_decode(file_get_contents($jsonfile), true);
    $username = $data['username'] ?? null;
    if (!$username) continue;

    $timeout = $data['session_timeout'] ?? null;
    $used = $data['used_seconds'] ?? 0;

    if ($timeout !== null) {
        $sisa = $timeout - $used;
        if ($sisa <= 0) {
            $user_timers[$username] = ['text' => '❌ expired', 'color' => 'red'];
        } else {
            $jam = floor($sisa / 3600);
            $menit = floor(($sisa % 3600) / 60);
            $detik = $sisa % 60;
            $user_timers[$username] = [
                'text' => "{$jam}j {$menit}m {$detik}d",
                'color' => 'black'
            ];
        }
    } else {
        $user_timers[$username] = ['text' => '🟢 tanpa timer', 'color' => 'green'];
    }
}

// Ambil user dari file users
$output = file_get_contents($users_file);
if (!$output) {
}

$users = [];
$user_blocks = preg_split('/\n\s*\n/', $output); // pisah antar blok user berdasarkan baris kosong

foreach ($user_blocks as $block) {
    $lines = explode("\n", $block);
    $user = [];
    $skip_user = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line == "" || strpos($line, "#") === 0) continue;

        if (preg_match('/^([^\s]+)\s+Cleartext-Password\s*:=\s*"([^"]+)"/', $line, $matches)) {
            $username = $matches[1];
            $user['username'] = $username;
            $user['password'] = $matches[2];
            $user['sisa_waktu'] = $user_timers[$username]['text'] ?? '❓ unknown';
            $user['warna'] = $user_timers[$username]['color'] ?? 'gray';
            $user['rate_limit'] = '';
            $user['session_timeout'] = '';
            $user['group'] = '';
        } elseif (stripos($line, "Framed-IP-Address") !== false) {
            $skip_user = true;
        } elseif (stripos($line, "Mikrotik-Group") !== false) {
            $group = trim(explode(':=', $line)[1], ' ",');
           $user['group'] = $group;
        } elseif (stripos($line, "Mikrotik-Rate-Limit") !== false) {
            $user['rate_limit'] = trim(explode(':=', $line)[1], ' ",');
        } elseif (stripos($line, "Session-Timeout") !== false) {
            $user['session_timeout'] = trim(explode(':=', $line)[1], ' ",');
        }
    }

    if (!empty($user) && !$skip_user && isset($user['username'])) {
        $users[] = $user;
    }
}
?>

<?php
// Tampilkan error PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Pastikan koneksi $conn ada
if (!isset($conn)) {
    die("Database connection not available.");
}



// Ambil semua voucher dari database
$pemilik = $ceknama;
$voucher_exist = [];
$sql = "SELECT voucher, paket, harga FROM voucher WHERE pemilik = '" . mysqli_real_escape_string($conn, $pemilik) . "'";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // key = voucher, value = array paket + harga
        $voucher_exist[$row['voucher']] = [
            'paket' => $row['paket'],
            'harga' => $row['harga']
        ];
    }
}

    // // Debug: tampilkan isi $users dan $voucher_exist
    // var_dump($users);
    // echo "<hr>";
    // var_dump($voucher_exist);
    // echo "<hr>";

?>

    









<!-- Form Voucher -->
<form method="post" id="voucher-form" action="" enctype="multipart/form-data">

 
    <!-- Tombol -->
    <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
        <a href="vouchergenerator.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Generate
        </a>

        <button type="submit" name="delete_selected" class="btn btn-danger" formtarget="_self">
            Hapus yang Dicentang
        </button>

    <!-- Upload Logo & Background -->
<div class="ms-3">

    <div class="d-flex flex-wrap gap-3 mb-3">
        <div style="flex: 1 1 250px;">
            <label for="logo_file" class="form-label fw-bold mb-1">Upload Logo (Opsional, PNG/JPG)</label>
            <input type="file" name="logo" id="logo_file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" class="form-control">
        </div>
        <div style="flex: 1 1 250px;">
            <label for="nocs" class="form-label fw-bold mb-1">Nomor CS</label>
            <input type="number" name="nocs" id="nocs" class="form-control" placeholder="Masukkan No CS">
        </div>
       <div style="flex: 1 1 250px;">
            <label for="login" class="form-label fw-bold mb-1">Login URL</label>
            <input type="text" name="login" id="login" class="form-control" placeholder="URL Login">
        </div>
        <div style="flex: 1 1 250px;">
            <label for="bg_file" class="form-label fw-bold mb-1">Upload Background (Opsional)</label>
            <input type="file" name="bg" id="bg_file" class="form-control">
        </div>

       
    </div>

    <div>
        <button id="btn-cetak-voucher" class="btn btn-success" type="button">Cetak Voucher</button>
    </div>

</div>

    </div>

    <!-- Tabel Voucher (desktop/tablet) -->
    <div class="card shadow-sm mb-3 d-none d-md-block">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover align-items-center mb-0 table-sm">
                    <thead class="table-light">
                        <tr>
                            <th><input type="checkbox" id="select-all"></th>
                            <th>Username</th>
                            <th>Password</th>
                            <th>Packages</th>
                            <th>harga jual</th>
                            <th>Speedrate</th>
                            <th>Uptime</th>
                            <th>Sisa waktu</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $host = $u['ip'] ?? '127.0.0.1';
                            $user = $u['username'];
                            $pass = $u['password'];
                            $login = $u['login'] ?? $user;
                            $uptime_seconds = $u['session_timeout'] ?? 0;
                            $ratelimit = $u['rate_limit'] ?? '';
                            $packages = $u['packages'] ?? '';



if (isset($voucher_exist[$user])) {
    $voucher_data = json_encode([
        'ip' => $host,
        'us' => $user,
        'ps' => $pass,
        'login' => $login,
        'packages' => $voucher_exist[$user]['paket'] ?? '',
        'harga' => $voucher_exist[$user]['harga'] ?? '',
        'uptime' => $uptime_seconds,
        'ratelimit' => $ratelimit,
        'radius' => $u['radius'] ?? '',
        'logo_path' => '-',
        'bg_path' => '-',
        'vouchers' => [['user' => $user, 'pass' => $pass]]
    ]);

    // Proses $voucher_data di sini
    // misal: echo $voucher_data;



                            
                            
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" class="voucher-checkbox" name="selected[]" value="<?= htmlspecialchars($voucher_data) ?>">
                            </td>
                            <td class="text-truncate" style="max-width:120px;"><?= htmlspecialchars($user) ?></td>
                            <td class="text-truncate" style="max-width:120px;"><?= htmlspecialchars($pass) ?></td>
                            <td><?= htmlspecialchars($voucher_exist[$user]['paket']) ?></td>
                            <td><?= htmlspecialchars($voucher_exist[$user]['harga']) ?></td>
                            <td><?= htmlspecialchars($ratelimit) ?></td>
                            <td><?= htmlspecialchars($uptime_seconds) ?></td>
                            <td class="<?= strpos($u['sisa_waktu'], 'expired') !== false ? 'text-danger' : (strpos($u['sisa_waktu'], 'unknown') !== false ? 'text-secondary' : 'text-success') ?>">
                                <?= htmlspecialchars($u['sisa_waktu']) ?>
                            </td>
                            <td>
                                <button type="submit" name="delete_user" value="<?= htmlspecialchars($user) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus voucher <?= htmlspecialchars($user) ?> ini?')">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                        <?php 
                    
                    }
                    
                    endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Kartu Voucher (mobile) -->
    <div class="d-block d-md-none">
      <?php foreach ($users as $u):
                            $host = $u['ip'] ?? '127.0.0.1';
                            $user = $u['username'];
                            $pass = $u['password'];
                            $login = $u['login'] ?? $user;
                            $uptime_seconds = $u['session_timeout'] ?? 0;
                            $ratelimit = $u['rate_limit'] ?? '';
                            $packages = $u['packages'] ?? '';


if (isset($voucher_exist[$user])) {
    $voucher_data = json_encode([
        'ip' => $host,
        'us' => $user,
        'ps' => $pass,
        'login' => $login,
        'packages' => $voucher_exist[$user]['paket'],
        'harga' => $voucher_exist[$user]['harga'], // aman, karena key = voucher
        'uptime' => $uptime_seconds,
        'ratelimit' => $ratelimit,
        'radius' => $u['radius'] ?? '',
        'logo_path' => '-',
        'bg_path' => '-',
        'vouchers' => [['user' => $user, 'pass' => $pass]]
    ]);




                            
                            
                        ?>
        <div class="card mb-2 shadow-sm">
            <div class="card-body p-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div>
                        <input type="checkbox" class="voucher-checkbox me-2" name="selected[]" value="<?= htmlspecialchars($voucher_data) ?>">
                        <strong><?= htmlspecialchars($user) ?></strong>
                    </div>
                    <button type="submit" name="delete_user" value="<?= htmlspecialchars($user) ?>" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
                <div class="small mb-1">Password: <?= htmlspecialchars($pass) ?></div>
                <div class="small mb-1">Packages: <?= htmlspecialchars($voucher_exist[$user]['paket']) ?></div>
                <div class="small mb-1">Rate Limit: <?= htmlspecialchars($ratelimit) ?></div>
                <div class="small mb-1">Uptime: <?= htmlspecialchars($uptime_seconds) ?></div>
                <div class="small <?= strpos($u['sisa_waktu'], 'expired') !== false ? 'text-danger' : (strpos($u['sisa_waktu'], 'unknown') !== false ? 'text-secondary' : 'text-success') ?>">
                    Status: <?= htmlspecialchars($u['sisa_waktu']) ?>
                </div>
            </div>
        </div>
        <?php
    }
    endforeach; ?>
    </div>

</form>
<!-- Modal Preview Voucher -->
<div class="modal fade" id="voucherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-md-down modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Preview Cetak Voucher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0" style="height:80vh;">
        <iframe id="voucher-iframe" name="voucher-iframe" style="width:100%; height:100%; border:none;"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- Notifikasi jika logo kosong -->
<div id="notif" style="
    position: fixed;
    top: 20px;
    right: 20px;
    background: #f44336;
    color: white;
    padding: 15px 20px;
    border-radius: 5px;
    display: none;
    z-index: 9999;
    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
">
    Silakan upload file logo terlebih dahulu!
</div>


<!-- Select All Checkbox -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.voucher-checkbox');

    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = selectAll.checked);
    });

    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            selectAll.checked = Array.from(checkboxes).every(ch => ch.checked);
        });
    });
});
</script>

<!-- Cetak Voucher -->
<script>
const form = document.getElementById('voucher-form');
const logoInput = document.getElementById('logo_file');
const nocs = document.getElementById('nocs');
const btnCetak = document.getElementById('btn-cetak-voucher');
const notif = document.getElementById('notif');
const login = document.getElementById('login');
btnCetak.addEventListener('click', function() {
    // Cek apakah logo diisi
    if (!logoInput.files.length) {
        notif.innerText = 'Silakan upload logo terlebih dahulu';
        notif.style.display = 'block';
        setTimeout(() => { notif.style.display = 'none'; }, 2000);
        logoInput.focus();
        return; // hentikan submit, modal tidak muncul
    }

    // Cek apakah No WhatsApp CS diisi
    if (!nocs.value) {
        notif.innerText = 'Silakan isi No WhatsApp CS';
        notif.style.display = 'block';
        setTimeout(() => { notif.style.display = 'none'; }, 2000);
        nocs.focus();
        return; // hentikan submit
    }
// Cek apakah No WhatsApp CS diisi
    if (!login.value) {
        notif.innerText = 'Masukan URL LOGIN VOUCHER';
        notif.style.display = 'block';
        setTimeout(() => { notif.style.display = 'none'; }, 2000);
        login.focus();
        return; // hentikan submit
    }
    // Submit ke iframe tanpa mengganggu tombol delete
    const actionBackup = form.getAttribute('action');
    const targetBackup = form.getAttribute('target');

    form.setAttribute('action', 'voucherhotspot/cetak.php');
    form.setAttribute('target', 'voucher-iframe');
    form.submit();

    // Kembalikan action & target agar tombol delete normal
    form.setAttribute('action', actionBackup || '');
    form.setAttribute('target', targetBackup || '');

    // Tampilkan modal hanya jika logo ada
    const voucherModal = new bootstrap.Modal(document.getElementById('voucherModal'));
    voucherModal.show();
});
</script>


<?php require 'footer.php'; ?>

