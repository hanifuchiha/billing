<script>
// Select All for Cetak Ulang
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCetak = document.getElementById('select-all-cetak');
    // Query ulang tiap kali dipakai (bukan sekali di awal) supaya checkbox dari
    // baris yang baru direveal lazy-load ikut ke-cover, bukan cuma 20 pertama.
    function getVoucherCetakCheckboxes() {
        return document.querySelectorAll('.voucher-checkbox-cetak');
    }
    if (selectAllCetak) {
        selectAllCetak.addEventListener('change', function() {
            const checked = selectAllCetak.checked;
            // Voucher tercetak yang belum ke-scroll (lazy-load) belum ada
            // checkbox-nya di DOM - muat semua dulu supaya "select all" benar-benar
            // mencakup semua voucher yang sudah dicetak.
            if (checked && typeof window.voucherCetakRevealAllRemaining === 'function') {
                window.voucherCetakRevealAllRemaining();
            }
            getVoucherCetakCheckboxes().forEach(cb => cb.checked = checked);
        });
        document.addEventListener('change', function(ev) {
            if (ev.target && ev.target.classList && ev.target.classList.contains('voucher-checkbox-cetak')) {
                const checkboxesCetak = getVoucherCetakCheckboxes();
                selectAllCetak.checked = checkboxesCetak.length > 0 && Array.from(checkboxesCetak).every(ch => ch.checked);
            }
        });
    }

    // Cetak ulang logic
    const formCetak = document.getElementById('voucher-cetak-form');
    const logoInputCetak = document.getElementById('logo_file_cetak');
    const nocsCetak = document.getElementById('nocs_cetak');
    const btnCetakCetak = document.getElementById('btn-cetak-voucher-cetak');
    const loginCetak = document.getElementById('login_cetak');
    btnCetakCetak.addEventListener('click', function() {
        // Cek apakah logo diisi
        if (!logoInputCetak.files.length) {
            notif.innerText = 'Silakan upload logo terlebih dahulu';
            notif.style.display = 'block';
            setTimeout(() => { notif.style.display = 'none'; }, 2000);
            logoInputCetak.focus();
            return;
        }
        if (!nocsCetak.value) {
            notif.innerText = 'Silakan isi No WhatsApp CS';
            notif.style.display = 'block';
            setTimeout(() => { notif.style.display = 'none'; }, 2000);
            nocsCetak.focus();
            return;
        }
        if (!loginCetak.value) {
            notif.innerText = 'Masukan URL LOGIN VOUCHER';
            notif.style.display = 'block';
            setTimeout(() => { notif.style.display = 'none'; }, 2000);
            loginCetak.focus();
            return;
        }
        // Submit ke iframe
        let cetakInput = document.createElement('input');
        cetakInput.type = 'hidden';
        cetakInput.name = 'cetak_voucher';
        cetakInput.value = '1';
        formCetak.appendChild(cetakInput);

        const actionBackup = formCetak.getAttribute('action');
        const targetBackup = formCetak.getAttribute('target');
        formCetak.setAttribute('action', 'voucherhotspot/cetak.php');
        formCetak.setAttribute('target', 'voucher-iframe');
        formCetak.submit();
        setTimeout(() => { formCetak.removeChild(cetakInput); }, 1000);
        formCetak.setAttribute('action', actionBackup || '');
        formCetak.setAttribute('target', targetBackup || '');

        // Tampilkan modal
        const voucherModal = new bootstrap.Modal(document.getElementById('voucherModal'));
        voucherModal.show();
        const modalEl = document.getElementById('voucherModal');
        modalEl.addEventListener('hidden.bs.modal', function handler() {
            modalEl.removeEventListener('hidden.bs.modal', handler);
            location.reload();
        });
    });
});
</script>
<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Voucher_Bank', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Voucher Bank.</div></div>';
        require 'footer.php';
        exit;
    }
}

// Auto-alter: kolom expired_at (masa aktif voucher) & catatan (catatan
// per-batch) ditambahkan belakangan -- self-heal sekali kalau belum ada, sama
// seperti bootstrap di voucherhotspot/preview-done.php (biar page ini tidak
// error kalau dibuka SEBELUM pernah generate voucher baru pasca-update).
if (isset($conn) && $conn instanceof mysqli) {
    $voucherbank_col_check = mysqli_query($conn, "SHOW COLUMNS FROM voucher LIKE 'expired_at'");
    if ($voucherbank_col_check && mysqli_num_rows($voucherbank_col_check) === 0) {
        @mysqli_query($conn, "ALTER TABLE voucher ADD COLUMN expired_at DATE NULL DEFAULT NULL");
    }
    $voucherbank_col_check2 = mysqli_query($conn, "SHOW COLUMNS FROM voucher LIKE 'catatan'");
    if ($voucherbank_col_check2 && mysqli_num_rows($voucherbank_col_check2) === 0) {
        @mysqli_query($conn, "ALTER TABLE voucher ADD COLUMN catatan VARCHAR(255) NULL DEFAULT NULL");
    }
}
 ?>

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

$domain = $config['domain'];

// Ambil semua voucher dari database

// Ambil semua voucher dari database, tambahkan status cetak
$pemilik = $ceknama;
$voucher_exist = [];
$voucher_baru = [];
$voucher_cetak = [];


if ($current_user_id) {
                                                  
if ($AKSES == 'ASSISTANT') {

// Asumsi: ada field 'status_cetak' (0=baru, 1=sudah dicetak) di tabel voucher
$sql = "SELECT voucher, paket, harga, status_cetak, expired_at, catatan FROM voucher WHERE pemilik = '" . mysqli_real_escape_string($conn, $pemilik) . "'";

} else {
// Asumsi: ada field 'status_cetak' (0=baru, 1=sudah dicetak) di tabel voucher
$sql = "SELECT voucher, paket, harga, status_cetak, expired_at, catatan FROM voucher WHERE pemilik = '" . mysqli_real_escape_string($conn, $pemilik) . "'";
}
}





$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $voucher_exist[$row['voucher']] = [
            'paket' => $row['paket'],
            'harga' => $row['harga'],
            'status_cetak' => $row['status_cetak'] ?? 0,
            'expired_at' => $row['expired_at'] ?? '',
            'catatan' => $row['catatan'] ?? ''
        ];
        if (($row['status_cetak'] ?? 0) == 0) {
            $voucher_baru[$row['voucher']] = $voucher_exist[$row['voucher']];
        } else {
            $voucher_cetak[$row['voucher']] = $voucher_exist[$row['voucher']];
        }
    }
}

    // // Debug: tampilkan isi $users dan $voucher_exist
    // var_dump($users);
    // echo "<hr>";
    // var_dump($voucher_exist);
    // echo "<hr>";

?>

    










<!-- Tabs Voucher (Custom JS) -->
<div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    <button class="btn btn-outline-primary" id="tab-baru-btn">Voucher Baru</button>
    <button class="btn btn-outline-primary" id="tab-cetak-btn">Voucher Sudah Dicetak</button>
    <a href="proses/export_voucher_bank.php" class="btn btn-outline-success ms-auto" data-perm="btn_voucherbank_export">
        <i class="fas fa-file-excel"></i> Export Excel
    </a>
</div>
<div id="voucherTabContent">
        <div id="baru" class="tab-pane-custom">
    <!-- Form Voucher Baru -->
    <form method="post" id="voucher-form" action="" enctype="multipart/form-data">
        <!-- Tombol dan upload -->
        <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
            <a href="vouchergenerator.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Generate
            </a>
            <button type="submit" name="delete_selected" class="btn btn-danger" formtarget="_self">
                Hapus yang Dicentang
            </button>
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
                        <input type="text" name="login" id="login" class="form-control" value="<?php echo "https://$domain/crm/login"; ?>" placeholder="<?php echo "https://$domain/crm/login"; ?>">
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
        <!-- Tabel Voucher Baru (desktop/tablet) -->
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
                                <th>harga</th>
                                <th>Uptime</th>
                                <th>Status</th>
                                <th>Expired</th>
                                <th>Catatan</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="voucherBaruTableBody">
                            <?php
                            // Lazy-load: $users hasil parsing file radius (tidak bisa di-SQL-LIMIT),
                            // jadi baris desktop & kartu mobile ditampung dulu (bukan langsung
                            // di-echo), lalu direveal bertahap 20 per batch saat discroll - sama
                            // seperti tableshotspot.php. Desktop & mobile direveal berbarengan
                            // (index sama) supaya kedua tampilan tetap sinkron.
                            $voucherBaruRowsDesktop = [];
                            $voucherBaruRowsMobile = [];
                            foreach ($users as $u):
                                $host = $u['ip'] ?? '127.0.0.1';
                                $user = $u['username'];
                                $pass = $u['password'];
                                $login = $u['login'] ?? $user;
                                $uptime_seconds = $u['session_timeout'] ?? 0;
                                $ratelimit = $u['rate_limit'] ?? '';
                                $packages = $u['packages'] ?? '';
                                if (isset($voucher_baru[$user])) {
                                    $voucher_data = json_encode([
                                        'ip' => $host,
                                        'us' => $user,
                                        'ps' => $pass,
                                        'login' => $login,
                                        'packages' => $voucher_baru[$user]['paket'] ?? '',
                                        'harga' => $voucher_baru[$user]['harga'] ?? '',
                                        'uptime' => $uptime_seconds,
                                        'ratelimit' => $ratelimit,
                                        'radius' => $u['radius'] ?? '',
                                        'logo_path' => '-',
                                        'bg_path' => '-',
                                        'vouchers' => [['user' => $user, 'pass' => $pass]]
                                    ]);
                                    ob_start();
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="voucher-checkbox" name="selected[]" value="<?= htmlspecialchars($voucher_data) ?>">
                                </td>
                                <td class="text-truncate" style="max-width:120px;"><?= htmlspecialchars($user) ?></td>
                                <td class="text-truncate" style="max-width:120px;"><?= htmlspecialchars($pass) ?></td>
                                <td><?= htmlspecialchars($voucher_baru[$user]['paket']) ?></td>
                                <td><?= htmlspecialchars($voucher_baru[$user]['harga']) ?></td>
                                <td><?= htmlspecialchars($uptime_seconds) ?></td>
                                <td><?= htmlspecialchars($ratelimit) ?></td>
                                <td class="<?= strpos($u['sisa_waktu'], 'expired') !== false ? 'text-danger' : (strpos($u['sisa_waktu'], 'unknown') !== false ? 'text-secondary' : 'text-success') ?>">
                                    <?= htmlspecialchars($u['sisa_waktu']) ?>
                                </td>
                                <td class="text-nowrap"><?= !empty($voucher_baru[$user]['expired_at']) ? htmlspecialchars(date('d/m/Y', strtotime($voucher_baru[$user]['expired_at']))) : '-' ?></td>
                                <td class="text-truncate" style="max-width:150px;" title="<?= htmlspecialchars($voucher_baru[$user]['catatan'] ?? '') ?>"><?= htmlspecialchars($voucher_baru[$user]['catatan'] ?: '-') ?></td>
                                <td>
                                    <button type="submit" name="delete_user" value="<?= htmlspecialchars($user) ?>" class="btn btn-danger btn-sm" onclick="return confirm('Hapus voucher <?= htmlspecialchars($user) ?> ini?')">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php
                                    $voucherBaruRowsDesktop[] = ob_get_clean();
                                }
                            endforeach; ?>
                            <tr id="voucherBaruLazySentinelDesktop" style="height:1px;"><td colspan="10" style="padding:0;border:0;"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <!-- Kartu Voucher Baru (mobile) -->
        <div class="d-block d-md-none" id="voucherBaruMobileList">
            <?php foreach ($users as $u):
                $host = $u['ip'] ?? '127.0.0.1';
                $user = $u['username'];
                $pass = $u['password'];
                $login = $u['login'] ?? $user;
                $uptime_seconds = $u['session_timeout'] ?? 0;
                $ratelimit = $u['rate_limit'] ?? '';
                $packages = $u['packages'] ?? '';
                if (isset($voucher_baru[$user])) {
                    $voucher_data = json_encode([
                        'ip' => $host,
                        'us' => $user,
                        'ps' => $pass,
                        'login' => $login,
                        'packages' => $voucher_baru[$user]['paket'],
                        'harga' => $voucher_baru[$user]['harga'],
                        'uptime' => $uptime_seconds,
                        'ratelimit' => $ratelimit,
                        'radius' => $u['radius'] ?? '',
                        'logo_path' => '-',
                        'bg_path' => '-',
                        'vouchers' => [['user' => $user, 'pass' => $pass]]
                    ]);
                    ob_start();
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
                    <div class="small mb-1">Packages: <?= htmlspecialchars($voucher_baru[$user]['paket']) ?></div>
                    <div class="small mb-1">Rate Limit: <?= htmlspecialchars($ratelimit) ?></div>
                    <div class="small mb-1">Uptime: <?= htmlspecialchars($uptime_seconds) ?></div>
                    <div class="small <?= strpos($u['sisa_waktu'], 'expired') !== false ? 'text-danger' : (strpos($u['sisa_waktu'], 'unknown') !== false ? 'text-secondary' : 'text-success') ?>">
                        Status: <?= htmlspecialchars($u['sisa_waktu']) ?>
                    </div>
                    <?php if (!empty($voucher_baru[$user]['expired_at'])): ?>
                    <div class="small mb-1">Expired: <?= htmlspecialchars(date('d/m/Y', strtotime($voucher_baru[$user]['expired_at']))) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($voucher_baru[$user]['catatan'])): ?>
                    <div class="small mb-1">Catatan: <?= htmlspecialchars($voucher_baru[$user]['catatan']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
                    $voucherBaruRowsMobile[] = ob_get_clean();
                }
            endforeach; ?>
            <div id="voucherBaruLazySentinelMobile" style="height:1px;"></div>
        </div>
        <div id="voucherBaruLazyLoadWrap" class="text-center py-3 <?php echo count($voucherBaruRowsDesktop) <= 20 ? 'd-none' : ''; ?>">
            <div id="voucherBaruLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>
            <span id="voucherBaruLazyLoadStatusText" class="text-secondary text-xs"></span>
        </div>
        <script>
            var voucherBaruRowsDesktop = <?php echo json_encode($voucherBaruRowsDesktop, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
            var voucherBaruRowsMobile = <?php echo json_encode($voucherBaruRowsMobile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        </script>
    </form>
  </div>
    <div id="cetak" class="tab-pane-custom">
    <!-- Voucher Sudah Dicetak (bisa cetak ulang, select all, dan upload logo/CS/login/bg) -->
    <form method="post" id="voucher-cetak-form" action="" enctype="multipart/form-data">
        <div class="mb-3 d-flex flex-wrap align-items-center gap-2">
            <button id="btn-cetak-voucher-cetak" class="btn btn-success" type="button">Cetak Ulang Voucher</button>
            <div class="ms-3">
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <div style="flex: 1 1 250px;">
                        <label for="logo_file_cetak" class="form-label fw-bold mb-1">Upload Logo (Opsional, PNG/JPG)</label>
                        <input type="file" name="logo" id="logo_file_cetak" accept=".png,.jpg,.jpeg,image/png,image/jpeg" class="form-control">
                    </div>
                    <div style="flex: 1 1 250px;">
                        <label for="nocs_cetak" class="form-label fw-bold mb-1">Nomor CS</label>
                        <input type="number" name="nocs" id="nocs_cetak" class="form-control" placeholder="Masukkan No CS">
                    </div>
                    <div style="flex: 1 1 250px;">
                        <label for="login_cetak" class="form-label fw-bold mb-1">Login URL</label>
                        <input type="text" name="login" id="login_cetak" class="form-control" value="<?php echo "https://$domain/crm/login"; ?>" placeholder="<?php echo "https://$domain/crm/login"; ?>">
                    </div>
                    <div style="flex: 1 1 250px;">
                        <label for="bg_file_cetak" class="form-label fw-bold mb-1">Upload Background (Opsional)</label>
                        <input type="file" name="bg" id="bg_file_cetak" class="form-control">
                    </div>
                </div>
            </div>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-items-center mb-0 table-sm">
                        <thead class="table-light">
                            <tr>
                                <th><input type="checkbox" id="select-all-cetak"></th>
                                <th>Username</th>
                                <th>Password</th>
                                <th>Packages</th>
                                <th>harga</th>
                                <th>Expired</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody id="voucherCetakTableBody">
                            <?php
                            // Lazy-load sama seperti tabel "Voucher Baru" di atas.
                            $voucherCetakRowsHtml = [];
                            $voucher_cetak_count = 0;
                            foreach ($users as $u):
                                $user = $u['username'];
                                $pass = $u['password'];
                                if (isset($voucher_cetak[$user])) {
                                    $voucher_cetak_count++;
                                    $voucher_data = json_encode([
                                        'ip' => $u['ip'] ?? '127.0.0.1',
                                        'us' => $user,
                                        'ps' => $pass,
                                        'login' => $u['login'] ?? $user,
                                        'packages' => $voucher_cetak[$user]['paket'],
                                        'harga' => $voucher_cetak[$user]['harga'],
                                        'uptime' => $u['session_timeout'] ?? 0,
                                        'ratelimit' => $u['rate_limit'] ?? '',
                                        'radius' => $u['radius'] ?? '',
                                        'logo_path' => '-',
                                        'bg_path' => '-',
                                        'vouchers' => [['user' => $user, 'pass' => $pass]]
                                    ]);
                                    ob_start();
                            ?>
                            <tr>
                                <td><input type="checkbox" class="voucher-checkbox-cetak" name="selected[]" value="<?= htmlspecialchars($voucher_data) ?>"></td>
                                <td class="text-truncate" style="max-width:120px;"><?= htmlspecialchars($user) ?></td>
                                <td class="text-truncate" style="max-width:120px;"><?= htmlspecialchars($pass) ?></td>
                                <td><?= htmlspecialchars($voucher_cetak[$user]['paket']) ?></td>
                                <td><?= htmlspecialchars($voucher_cetak[$user]['harga']) ?></td>
                                <td class="text-nowrap"><?= !empty($voucher_cetak[$user]['expired_at']) ? htmlspecialchars(date('d/m/Y', strtotime($voucher_cetak[$user]['expired_at']))) : '-' ?></td>
                                <td class="text-truncate" style="max-width:150px;" title="<?= htmlspecialchars($voucher_cetak[$user]['catatan'] ?? '') ?>"><?= htmlspecialchars($voucher_cetak[$user]['catatan'] ?: '-') ?></td>
                            </tr>
                            <?php
                                    $voucherCetakRowsHtml[] = ob_get_clean();
                                }
                            endforeach;
                            if ($voucher_cetak_count === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">Tidak ada voucher yang sudah dicetak.</td>
                            </tr>
                            <?php endif; ?>
                            <tr id="voucherCetakLazySentinel" style="height:1px;"><td colspan="7" style="padding:0;border:0;"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div id="voucherCetakLazyLoadWrap" class="text-center py-3 <?php echo $voucher_cetak_count <= 20 ? 'd-none' : ''; ?>">
                <div id="voucherCetakLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>
                <span id="voucherCetakLazyLoadStatusText" class="text-secondary text-xs"></span>
            </div>
            <script>
                var voucherCetakRowsHtml = <?php echo json_encode($voucherCetakRowsHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
            </script>
        </div>
    </form>
  </div>
</div>

<!-- ============================================================
     LAZY LOAD: voucher baru (desktop+mobile sinkron) & voucher
     sudah dicetak, direveal bertahap 20 per batch saat discroll.
     Data sudah lengkap di memori (hasil parsing file radius users
     yang tidak bisa di-SQL-LIMIT) - sama seperti tableshotspot.php.
     ============================================================ -->
<script>
(function() {
    var CHUNK_SIZE = 20;
    var revealedCount = 0;
    var isRevealing = false;
    var allRevealed = false;

    var tableBody   = document.getElementById('voucherBaruTableBody');
    var mobileList   = document.getElementById('voucherBaruMobileList');
    var sentinelDesktop = document.getElementById('voucherBaruLazySentinelDesktop');
    var sentinelMobile  = document.getElementById('voucherBaruLazySentinelMobile');
    var lazyWrap = document.getElementById('voucherBaruLazyLoadWrap');
    var lazyIndicator = document.getElementById('voucherBaruLazyLoadIndicator');
    var lazyStatusText = document.getElementById('voucherBaruLazyLoadStatusText');

    function updateStatusText() {
        if (!lazyStatusText) return;
        var total = voucherBaruRowsDesktop.length;
        if (total === 0) { lazyStatusText.textContent = ''; return; }
        lazyStatusText.textContent = allRevealed
            ? 'Semua voucher baru sudah dimuat (' + total + ').'
            : 'Menampilkan ' + revealedCount + ' dari ' + total + ' voucher baru...';
    }

    function revealChunk(count) {
        if (allRevealed || isRevealing) return;
        var chunkDesktop = voucherBaruRowsDesktop.slice(revealedCount, revealedCount + count);
        var chunkMobile = voucherBaruRowsMobile.slice(revealedCount, revealedCount + count);
        if (chunkDesktop.length === 0) {
            allRevealed = true;
            updateStatusText();
            if (lazyIndicator) lazyIndicator.classList.add('d-none');
            return;
        }

        isRevealing = true;
        if (lazyWrap) lazyWrap.classList.remove('d-none');
        if (lazyIndicator) lazyIndicator.classList.remove('d-none');

        if (sentinelDesktop) {
            sentinelDesktop.insertAdjacentHTML('beforebegin', chunkDesktop.join(''));
        } else if (tableBody) {
            tableBody.insertAdjacentHTML('beforeend', chunkDesktop.join(''));
        }
        if (sentinelMobile) {
            sentinelMobile.insertAdjacentHTML('beforebegin', chunkMobile.join(''));
        } else if (mobileList) {
            mobileList.insertAdjacentHTML('beforeend', chunkMobile.join(''));
        }

        revealedCount += chunkDesktop.length;
        allRevealed = revealedCount >= voucherBaruRowsDesktop.length;
        updateStatusText();
        if (allRevealed && lazyIndicator) lazyIndicator.classList.add('d-none');
        isRevealing = false;
    }

    function revealAllRemaining() {
        while (!allRevealed) {
            revealChunk(voucherBaruRowsDesktop.length);
        }
    }
    // Dipakai tombol "select-all" supaya voucher yang belum ke-scroll tidak
    // diam-diam terlewat dari seleksi hapus/cetak-ulang.
    window.voucherBaruRevealAllRemaining = revealAllRemaining;

    [sentinelDesktop, sentinelMobile].forEach(function(sentinel) {
        if (sentinel && 'IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) revealChunk(CHUNK_SIZE);
                });
            }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
            observer.observe(sentinel);
        }
    });

    window.addEventListener('scroll', function() {
        if (allRevealed || isRevealing) return;
        var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
        if (nearBottom) revealChunk(CHUNK_SIZE);
    }, { passive: true });

    function initialReveal() { revealChunk(CHUNK_SIZE); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialReveal);
    } else {
        initialReveal();
    }
})();

(function() {
    var CHUNK_SIZE = 20;
    var revealedCount = 0;
    var isRevealing = false;
    var allRevealed = false;

    var tableBody = document.getElementById('voucherCetakTableBody');
    var sentinel = document.getElementById('voucherCetakLazySentinel');
    var lazyWrap = document.getElementById('voucherCetakLazyLoadWrap');
    var lazyIndicator = document.getElementById('voucherCetakLazyLoadIndicator');
    var lazyStatusText = document.getElementById('voucherCetakLazyLoadStatusText');

    if (!tableBody || !sentinel) return;

    function updateStatusText() {
        if (!lazyStatusText) return;
        var total = voucherCetakRowsHtml.length;
        if (total === 0) { lazyStatusText.textContent = ''; return; }
        lazyStatusText.textContent = allRevealed
            ? 'Semua voucher tercetak sudah dimuat (' + total + ').'
            : 'Menampilkan ' + revealedCount + ' dari ' + total + ' voucher tercetak...';
    }

    function revealChunk(count) {
        if (allRevealed || isRevealing) return;
        var chunk = voucherCetakRowsHtml.slice(revealedCount, revealedCount + count);
        if (chunk.length === 0) {
            allRevealed = true;
            updateStatusText();
            if (lazyIndicator) lazyIndicator.classList.add('d-none');
            return;
        }

        isRevealing = true;
        if (lazyWrap) lazyWrap.classList.remove('d-none');
        if (lazyIndicator) lazyIndicator.classList.remove('d-none');

        sentinel.insertAdjacentHTML('beforebegin', chunk.join(''));

        revealedCount += chunk.length;
        allRevealed = revealedCount >= voucherCetakRowsHtml.length;
        updateStatusText();
        if (allRevealed && lazyIndicator) lazyIndicator.classList.add('d-none');
        isRevealing = false;
    }

    function revealAllRemaining() {
        while (!allRevealed) {
            revealChunk(voucherCetakRowsHtml.length);
        }
    }
    window.voucherCetakRevealAllRemaining = revealAllRemaining;

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) revealChunk(CHUNK_SIZE);
            });
        }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
        observer.observe(sentinel);
    }

    window.addEventListener('scroll', function() {
        if (allRevealed || isRevealing) return;
        var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
        if (nearBottom) revealChunk(CHUNK_SIZE);
    }, { passive: true });

    function initialReveal() { revealChunk(CHUNK_SIZE); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialReveal);
    } else {
        initialReveal();
    }
})();
</script>
<style>
.tab-pane-custom { display: none; }
.tab-pane-custom.active { display: block; }

#voucherModal .modal-dialog {
    width: min(96vw, 1600px);
    max-width: 96vw;
    margin: 1rem auto;
}

#voucherModal .modal-content {
    min-height: 92vh;
    max-height: 96vh;
    overflow: hidden;
}

#voucherModal .modal-body {
    padding: 0 !important;
    min-height: calc(92vh - 72px);
    overflow: auto;
    background: #f8fafc;
}

#voucherModal iframe {
    width: 100%;
    min-height: calc(92vh - 72px);
    border: none;
    display: block;
    background: #ffffff;
}

@media (max-width: 768px) {
    #voucherModal .modal-dialog {
        width: 100vw;
        max-width: 100vw;
        margin: 0;
    }

    #voucherModal .modal-content {
        min-height: 100vh;
        max-height: 100vh;
        border-radius: 0;
    }

    #voucherModal .modal-body,
    #voucherModal iframe {
        min-height: calc(100vh - 64px);
    }
}
</style>
<!-- Modal Preview Voucher -->
<div class="modal fade" id="voucherModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-md-down modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Preview Cetak Voucher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
            <div class="modal-body p-0" id="voucher-modal-body">
                <iframe id="voucher-iframe" name="voucher-iframe"></iframe>
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
    // Query ulang tiap kali dipakai (bukan sekali di awal) supaya checkbox dari
    // baris yang baru direveal lazy-load ikut ke-cover, bukan cuma 20 pertama.
    function getVoucherBaruCheckboxes() {
        return document.querySelectorAll('.voucher-checkbox');
    }

    selectAll.addEventListener('change', function() {
        const checked = selectAll.checked;
        // Voucher yang belum ke-scroll (lazy-load) belum ada checkbox-nya di DOM -
        // muat semua dulu supaya "select all" benar-benar mencakup semua voucher baru.
        if (checked && typeof window.voucherBaruRevealAllRemaining === 'function') {
            window.voucherBaruRevealAllRemaining();
        }
        getVoucherBaruCheckboxes().forEach(cb => cb.checked = checked);
    });

    document.addEventListener('change', function(ev) {
        if (ev.target && ev.target.classList && ev.target.classList.contains('voucher-checkbox')) {
            const checkboxes = getVoucherBaruCheckboxes();
            selectAll.checked = checkboxes.length > 0 && Array.from(checkboxes).every(ch => ch.checked);
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    const voucherIframe = document.getElementById('voucher-iframe');
    const voucherModalBody = document.getElementById('voucher-modal-body');

    function resizeVoucherPreview() {
        if (!voucherIframe || !voucherModalBody) return;

        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 900;
        const minHeight = Math.max(620, viewportHeight - 140);
        const maxHeight = Math.max(minHeight, viewportHeight - 90);

        let contentHeight = minHeight;

        try {
            const iframeDoc = voucherIframe.contentDocument || (voucherIframe.contentWindow && voucherIframe.contentWindow.document);
            if (iframeDoc && iframeDoc.body) {
                const bodyHeight = iframeDoc.body.scrollHeight || 0;
                const htmlHeight = iframeDoc.documentElement ? iframeDoc.documentElement.scrollHeight : 0;
                contentHeight = Math.max(minHeight, bodyHeight, htmlHeight);
            }
        } catch (error) {
            contentHeight = minHeight;
        }

        const finalHeight = Math.min(contentHeight + 16, maxHeight);
        voucherModalBody.style.height = finalHeight + 'px';
        voucherIframe.style.height = finalHeight + 'px';
    }

    if (voucherIframe) {
        voucherIframe.addEventListener('load', function() {
            resizeVoucherPreview();
            setTimeout(resizeVoucherPreview, 250);
            setTimeout(resizeVoucherPreview, 800);
        });
    }

    window.addEventListener('resize', resizeVoucherPreview);
    document.getElementById('voucherModal')?.addEventListener('shown.bs.modal', resizeVoucherPreview);
});
</script>


<script>

// Custom tab logic (no Bootstrap)
document.addEventListener('DOMContentLoaded', function() {
    const tabBaruBtn = document.getElementById('tab-baru-btn');
    const tabCetakBtn = document.getElementById('tab-cetak-btn');
    const paneBaru = document.getElementById('baru');
    const paneCetak = document.getElementById('cetak');

    function activateTab(tab) {
        if (tab === 'baru') {
            paneBaru.classList.add('active');
            paneCetak.classList.remove('active');
            tabBaruBtn.classList.add('btn-primary');
            tabBaruBtn.classList.remove('btn-outline-primary');
            tabCetakBtn.classList.remove('btn-primary');
            tabCetakBtn.classList.add('btn-outline-primary');
        } else {
            paneBaru.classList.remove('active');
            paneCetak.classList.add('active');
            tabBaruBtn.classList.remove('btn-primary');
            tabBaruBtn.classList.add('btn-outline-primary');
            tabCetakBtn.classList.add('btn-primary');
            tabCetakBtn.classList.remove('btn-outline-primary');
        }
    }

    // Default: show baru
    paneBaru.classList.add('active');
    paneCetak.classList.remove('active');
    tabBaruBtn.classList.add('btn-primary');
    tabBaruBtn.classList.remove('btn-outline-primary');
    tabCetakBtn.classList.remove('btn-primary');
    tabCetakBtn.classList.add('btn-outline-primary');

    tabBaruBtn.addEventListener('click', function() { activateTab('baru'); });
    tabCetakBtn.addEventListener('click', function() { activateTab('cetak'); });

    // Hide all tab-panes except the active one
    function updateTabPaneDisplay() {
        document.querySelectorAll('.tab-pane-custom').forEach(function(pane) {
            if (pane.classList.contains('active')) {
                pane.style.display = 'block';
            } else {
                pane.style.display = 'none';
            }
        });
    }
    // Initial
    updateTabPaneDisplay();
    // On tab change
    tabBaruBtn.addEventListener('click', updateTabPaneDisplay);
    tabCetakBtn.addEventListener('click', updateTabPaneDisplay);
});

// Fix: Voucher Baru Cetak Button Logic
// Ensure DOM elements are defined and used correctly

document.addEventListener('DOMContentLoaded', function() {
    // --- Voucher Baru Cetak Button ---
    const btnCetak = document.getElementById('btn-cetak-voucher');
    const notif = document.getElementById('notif');
    const logoInput = document.getElementById('logo_file');
    const nocs = document.getElementById('nocs');
    const login = document.getElementById('login');
    const form = document.getElementById('voucher-form');

    if (btnCetak && form && logoInput && nocs && login && notif) {
        btnCetak.addEventListener('click', function() {
            // Cek apakah logo diisi
            if (!logoInput.files.length) {
                notif.innerText = 'Silakan upload logo terlebih dahulu';
                notif.style.display = 'block';
                setTimeout(() => { notif.style.display = 'none'; }, 2000);
                logoInput.focus();
                return;
            }
            // Cek apakah No WhatsApp CS diisi
            if (!nocs.value) {
                notif.innerText = 'Silakan isi No WhatsApp CS';
                notif.style.display = 'block';
                setTimeout(() => { notif.style.display = 'none'; }, 2000);
                nocs.focus();
                return;
            }
            if (!login.value) {
                notif.innerText = 'Masukan URL LOGIN VOUCHER';
                notif.style.display = 'block';
                setTimeout(() => { notif.style.display = 'none'; }, 2000);
                login.focus();
                return;
            }
            // Tambahkan input hidden untuk trigger update status cetak
            let cetakInput = document.createElement('input');
            cetakInput.type = 'hidden';
            cetakInput.name = 'cetak_voucher';
            cetakInput.value = '1';
            form.appendChild(cetakInput);

            // Submit ke iframe tanpa mengganggu tombol delete
            const actionBackup = form.getAttribute('action');
            const targetBackup = form.getAttribute('target');
            form.setAttribute('action', 'voucherhotspot/cetak.php');
            form.setAttribute('target', 'voucher-iframe');
            form.submit();
            setTimeout(() => { form.removeChild(cetakInput); }, 1000);
            form.setAttribute('action', actionBackup || '');
            form.setAttribute('target', targetBackup || '');

            // Tampilkan modal hanya jika logo ada
            const voucherModal = new bootstrap.Modal(document.getElementById('voucherModal'));
            voucherModal.show();
            const modalEl = document.getElementById('voucherModal');
            modalEl.addEventListener('hidden.bs.modal', function handler() {
                modalEl.removeEventListener('hidden.bs.modal', handler);
                location.reload();
            });
        });
    }
});
</script>


<?php require 'footer.php'; ?>

