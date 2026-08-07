
<?php require 'header.php'; ?>

<?php
require_once __DIR__ . '/radius_sync_lib.php';

// Data pendukung untuk tab Clients/Filter/Default/User & Authorize di bawah.
$radius_global_settings_ui = radiusGetGlobalSettings($conn);
$radius_managed_state_ui = radiusReadManagedState();
$radius_server_list_ui = [];
$rs_ui = mysqli_query($conn, "SELECT IP, AREA, PEMILIK FROM server WHERE IP IS NOT NULL AND IP <> '' ORDER BY AREA ASC");
if ($rs_ui) {
    while ($row_srv_ui = mysqli_fetch_assoc($rs_ui)) {
        $radius_server_list_ui[] = $row_srv_ui;
    }
}
?>

<!-- ====== Config Editor (multi-file) ====== -->
<?php
// Agar user www-data bisa mengedit file-file config berikut,
// jalankan perintah berikut di terminal sebagai root:
//
// sudo chown www-data:www-data /etc/freeradius/3.0/clients.conf /etc/freeradius/3.0/users /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/policy.d/filter
// sudo chmod 664 /etc/freeradius/3.0/clients.conf /etc/freeradius/3.0/users /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/policy.d/filter
//
$config_files = [
    'clients.conf' => '/etc/freeradius/3.0/clients.conf',
    'users' => '/etc/freeradius/3.0/users',
    // File yang BENERAN dibaca modul `files` (lihat filename di
    // mods-enabled/files). /etc/freeradius/3.0/users idealnya symlink ke sini,
    // tapi kalau symlink itu putus, tab ini yang jadi sumber kebenaran --
    // kalau isi tab ini kosong/beda dari tab "users", itu sebabnya user baru
    // kelihatan "ada" tapi tetap gagal auth ("[files] = noop").
    'authorize' => '/etc/freeradius/3.0/mods-config/files/authorize',
    'default' => '/etc/freeradius/3.0/sites-available/default',
    'filter' => '/etc/freeradius/3.0/policy.d/filter',
];


// ================= Config =================
$clients_file = '/etc/freeradius/3.0/clients.conf';
$users_file   = '/etc/freeradius/3.0/users';
$debug_file   = '/var/log/freeradius/debug-radius-web.log';

if (!function_exists('readFileLinesWithSudoFallback')) {
    /**
     * Baca file jadi array baris, dengan fallback "sudo cat" kalau www-data
     * tidak punya izin baca langsung -- sama seperti pola yang sudah dipakai
     * di tab Config Editor (is_readable() -> sudo cat). Sebelumnya tabel
     * Clients dan modal Users di bawah pakai file_exists()+file() LANGSUNG
     * tanpa fallback ini, jadi kalau www-data tidak bisa baca langsung, tabel
     * tampil kosong padahal isi file di tab Config Editor (yang punya
     * fallback) terlihat normal -- itu sebabnya modal Users kelihatan kosong
     * padahal file users jelas berisi data.
     */
    function readFileLinesWithSudoFallback(string $path): array
    {
        if (is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return $lines !== false ? $lines : [];
        }
        $out = shell_exec('sudo /bin/cat ' . escapeshellarg($path) . ' 2>/dev/null');
        if ($out === null || $out === '') {
            return [];
        }
        return preg_split('/\r\n|\r|\n/', rtrim($out));
    }
}

// ================= Fungsi Crontab =================

$domain = $config['domain'];


function getCronStatus() {
    global $domain;
   $cron_line = "*/30 * * * * curl https://$domain/crm/billing/notifbot/notifphp/sync_freeradius_users.php";
    $current_cron = shell_exec("sudo crontab -u www-data -l 2>/dev/null || true");
    return strpos($current_cron, $cron_line) !== false ? 'enabled' : 'disabled';
}


function enableCron() {
    global $domain;
   $cron_line = "*/30 * * * * curl https://$domain/crm/billing/notifbot/notifphp/sync_freeradius_users.php";
    $current_cron = shell_exec("sudo crontab -u www-data -l 2>/dev/null || echo ''");
    if (strpos($current_cron, $cron_line) === false) {
        $new_cron = $current_cron . $cron_line . "\n";
        $temp_file = tempnam(sys_get_temp_dir(), 'cron');
        file_put_contents($temp_file, $new_cron);
        shell_exec("sudo crontab -u www-data " . escapeshellarg($temp_file));
        unlink($temp_file);
    }
    return true;
}

function disableCron() {
    global $domain;
  
    $cron_line = "*/30 * * * * curl https://$domain/crm/billing/notifbot/notifphp/sync_freeradius_users.php";
    $current_cron = shell_exec("sudo crontab -u www-data -l 2>/dev/null || echo ''");
    $lines = explode("\n", trim($current_cron));
    $new_lines = [];
    foreach ($lines as $line) {
        if (trim($line) !== trim($cron_line)) {
            $new_lines[] = $line;
        }
    }
    $new_cron = implode("\n", $new_lines) . "\n";
    $temp_file = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($temp_file, $new_cron);
    shell_exec("sudo crontab -u www-data " . escapeshellarg($temp_file));
    unlink($temp_file);
    return true;
}

// Handle toggle crontab
if (isset($_POST['toggle_cron'])) {
    $current_status = getCronStatus();
    if ($current_status == 'enabled') {
        disableCron();
        $message = "Crontab untuk sync FreeRADIUS dinonaktifkan.";
    } else {
        enableCron();
        $message = "Crontab untuk sync FreeRADIUS diaktifkan.";
    }
}
$cron_status = getCronStatus();

// ================= Fungsi =================
function getFreeradiusPID() {
    // Coba pakai pidof
    $pid = trim(shell_exec("pidof freeradius"));
    if($pid != '') return (int)$pid;

    // Alternatif pakai systemctl
    $output = shell_exec("systemctl show -p MainPID freeradius");
    if (preg_match('/MainPID=(\d+)/', trim($output), $m)) {
        return (int)$m[1];
    }

    // Alternatif pakai pgrep
    $pid = trim(shell_exec("pgrep -f 'freeradius -X'"));
    if($pid != '') return (int)$pid;

    return 0;
}

function cleanupDuplicateFiles() {
    // Fungsi untuk membersihkan file backup yang dapat menyebabkan duplicate virtual server
    $cleanup_commands = [
        'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "default.save*" -delete 2>/dev/null',
        'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "default.bak*" -delete 2>/dev/null',
        'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "default.backup*" -delete 2>/dev/null',
        'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "*.save" -delete 2>/dev/null',
        'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "*.bak" -delete 2>/dev/null'
    ];
    
    foreach($cleanup_commands as $cmd) {
        shell_exec($cmd);
    }
    
    return true;
}

function validateFreeradiusConfig() {
    // Validasi konfigurasi FreeRADIUS untuk memastikan tidak ada duplicate virtual server
    $output = shell_exec('sudo freeradius -X -C 2>&1');
    
    if (strpos($output, 'Duplicate virtual server') !== false) {
        return ['valid' => false, 'error' => $output];
    }
    
    if (strpos($output, 'Configuration appears to be OK') !== false || strpos($output, 'radiusd: ') === false) {
        return ['valid' => true, 'output' => $output];
    }
    
    return ['valid' => false, 'error' => $output];
}

// ================= Status =================
// Bersihkan file backup secara otomatis untuk mencegah duplicate virtual server
cleanupDuplicateFiles();

$pid = getFreeradiusPID();
$status = ($pid > 0) ? 'active' : 'inactive';

// Validasi konfigurasi jika FreeRADIUS sedang berjalan
if ($status === 'active') {
    $validation = validateFreeradiusConfig();
    if (!$validation['valid']) {
        // Jika ada masalah duplicate, bersihkan dan restart
        shell_exec('sudo systemctl stop freeradius 2>/dev/null');
        cleanupDuplicateFiles();
        shell_exec('sudo systemctl start freeradius 2>/dev/null');
        $pid = getFreeradiusPID();
        $status = ($pid > 0) ? 'active' : 'inactive';
    }
}
?>

<div class="card">
  <div class="card-body">
    <h2 class="mb-4">FreeRADIUS Dashboard</h2>

<!-- Toggle FreeRADIUS -->
<form method="post" action="radiuscontrol/proses.php" class="mb-3">
    <input type="hidden" name="freeradius_toggle" value="1">
    <input type="hidden" name="cleanup_before_start" value="1">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="freeradius" id="freeradius"
        <?php if($status=='active') echo 'checked'; ?>
        <?php if($status!='active') echo ''; ?>
        onchange="this.form.submit()">
        <label class="form-check-label" for="freeradius">
            FreeRADIUS <?= ($status=='active') ? "Aktif ✅ (PID: $pid)" : "Tidak Aktif ❌" ?>
        </label>
    </div>
    <small class="text-muted">
        <i class="fas fa-shield-alt"></i> Sistem akan otomatis membersihkan file backup untuk mencegah duplicate virtual server
    </small>
</form>

<!-- Toggle Crontab Sync -->
<form method="post" class="mb-3">
    <input type="hidden" name="toggle_cron" value="1">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="cron_sync" id="cron_sync"
        <?php if($cron_status=='enabled') echo 'checked'; ?>
        onchange="this.form.submit()">
        <label class="form-check-label" for="cron_sync">
            Crontab Sync FreeRADIUS <?= ($cron_status=='enabled') ? "Aktif ✅ (Setiap 30 menit)" : "Tidak Aktif ❌" ?>
        </label>
    </div>
    <small class="text-muted">
        <i class="fas fa-clock"></i> Mengaktifkan crontab akan menjalankan sync_freeradius_users.php setiap 30 menit untuk user www-data
    </small>
</form>

<!-- Sync Manual FreeRADIUS -->
<div class="mb-3">
    <button type="button" id="btnSyncNow" class="btn btn-outline-primary">
        <i class="fas fa-sync"></i> Sync Sekarang
    </button>
    <small class="text-muted d-block mt-1">
        <i class="fas fa-info-circle"></i> Jalankan sinkronisasi RADIUS sekarang juga tanpa menunggu jadwal cron 30 menit -- berguna setelah menambah/mengubah data pelanggan, atau untuk memulihkan user yang sempat hilang dari file users.
    </small>
    <div id="syncNowResult" class="mt-2" style="display:none;">
        <pre id="syncNowOutput" style="max-height:400px; overflow-y:auto; background:#1e1e1e; color:#cccccc; padding:10px; white-space:pre-wrap; font-size:0.85rem; border-radius:4px;"></pre>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('btnSyncNow');
    if (!btn) {
        return;
    }
    btn.addEventListener('click', function () {
        var resultBox = document.getElementById('syncNowResult');
        var output = document.getElementById('syncNowOutput');
        var originalHtml = btn.innerHTML;

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyinkronkan...';
        resultBox.style.display = 'block';
        output.textContent = 'Sedang menjalankan sinkronisasi, mohon tunggu...';

        fetch('notifbot/notifphp/sync_freeradius_users.php')
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var text = html.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '');
                output.textContent = text.trim();
            })
            .catch(function (err) {
                output.textContent = 'Gagal menjalankan sync: ' + err;
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
    });
})();
</script>

<?php if(isset($message)): ?>
<div class="alert alert-info"><?php echo $message; ?></div>
<?php endif; ?>
<?php if(isset($_GET['text']) && $_GET['text'] !== ''): ?>
<div class="alert alert-info"><?php echo htmlspecialchars($_GET['text']); ?></div>
<?php endif; ?>
  </div>
</div>

<style>

#log-container {
    max-height: 400px;
    overflow-y: auto;
    padding: 10px;
    background: #1e1e1e;
    color: #cccccc;
    white-space: pre-wrap;
}
</style>

<!-- Debug Terminal -->
<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">Debug Log Terminal</h3>
    </div>
    <div class="card-body">
        <div id="log-container"></div>
        <script>
        function loadLog(){
            fetch('radiuscontrol/debug_terminal_fetch.php')
                .then(res => res.text())
                .then(html => {
                    const c = document.getElementById('log-container');
                    c.innerHTML = html;
                    c.scrollTop = c.scrollHeight;
                });
        }
        loadLog();
        setInterval(loadLog, 15000);
        </script>
    </div>
</div>

<div class="card mb-3 bg-white shadow-sm">
    <div class="card-header bg-primary">
        <h3 class="mb-0">FreeRADIUS Config Editor</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">Edit common FreeRADIUS config files. Each Save will backup the file, validate configuration, and attempt restart.</p>
        <div class="mb-3">
            <ul class="nav nav-tabs" id="configTabs" role="tablist">
                <?php $i=0; foreach($config_files as $k=>$path):
                        // $k dipakai sebagai id HTML dan diselipkan mentah-mentah ke
                        // dalam CSS/JS selector (data-tab-target -> querySelector).
                        // Key seperti "clients.conf" mengandung titik, yang di dalam
                        // selector CSS berarti "class", BUKAN bagian dari id --
                        // "#pane-clients.conf" dibaca sebagai id="pane-clients" DAN
                        // class="conf", jadi tidak pernah cocok. Efeknya: tab
                        // clients.conf tampil benar cuma di render pertama (PHP
                        // langsung menulis show active ke HTML-nya), tapi begitu
                        // pindah ke tab lain lalu balik lagi, querySelector gagal
                        // menemukan pane-nya dan isinya tidak pernah muncul lagi.
                        // Makanya id/selector pakai slug yang disanitasi, label
                        // tampilan ($k) tetap apa adanya.
                        $slug = preg_replace('/[^A-Za-z0-9_-]/', '-', $k);
                ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $i===0? 'active':''; ?>"
                                id="tab-<?php echo $slug; ?>"
                                data-tab-toggle="tab"
                                data-tab-target="#pane-<?php echo $slug; ?>"
                                type="button"
                                role="tab"
                                aria-controls="pane-<?php echo $slug; ?>"
                                aria-selected="<?php echo $i===0? 'true':'false'; ?>">
                            <?php echo htmlspecialchars($k); ?>
                        </button>
                    </li>
                <?php $i++; endforeach; ?>
            </ul>
            <div class="tab-content mt-3">
                <?php $i=0; foreach($config_files as $k=>$path):
                        $slug = preg_replace('/[^A-Za-z0-9_-]/', '-', $k);
                        $content = '';
                        if(is_readable($path)){
                                $content = @file_get_contents($path);
                        } else {
                                $r = run_cmd('sudo /bin/cat '.escapeshellarg($path).' || true');
                                $content = is_array($r['out']) ? implode("\n", $r['out']) : ($r['out'] ?? '');
                        }
                ?>
                <div class="tab-pane fade <?php echo $i===0? 'show active':''; ?>"
                     id="pane-<?php echo $slug; ?>"
                     role="tabpanel"
                     aria-labelledby="tab-<?php echo $slug; ?>"
                     tabindex="0">
                    <form method="post" action="radiuscontrol/proses.php">
                        <input type="hidden" name="save_config" value="1">
                        <input type="hidden" name="config_file" value="<?php echo htmlspecialchars($path); ?>">
                        <input type="hidden" name="prevent_duplicate" value="1">
                        <div class="mb-2">
                            <textarea name="config_content" class="form-control" rows="12"><?php echo htmlspecialchars($content); ?></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">Simpan <?php echo htmlspecialchars($k); ?></button>
                        <small class="text-muted d-block mt-1">
                            <i class="fas fa-info-circle"></i> Backup file otomatis akan dibersihkan untuk mencegah duplicate virtual server
                        </small>
                    </form>
                </div>
                <?php $i++; endforeach; ?>
            </div>
    
        <script>
        // Tab switching untuk Config Editor -- SENGAJA tidak memakai plugin Tab
        // bawaan Bootstrap (data-bs-toggle="tab"). Sebelumnya elemen ini pakai
        // data-bs-toggle="tab" SEKALIGUS listener klik manual di bawah ini; karena
        // bootstrap.min.js (dimuat di footer.php) otomatis memasang handler-nya
        // sendiri ke setiap [data-bs-toggle="tab"], dua mekanisme tab berjalan
        // bersamaan dan rebutan class "active"/"show". Keduanya kebetulan cocok
        // saat load pertama (mengikuti HTML yang dirender server), tapi begitu
        // pindah tab lalu balik lagi, state internal Tab.js Bootstrap sudah beda
        // dari DOM yang diubah manual, sehingga class "show active" dicopot lagi
        // -- itu sebabnya tab clients.conf terlihat kosong setelah pindah tab.
        // Atribut sekarang dinamai data-tab-toggle/data-tab-target (bukan
        // data-bs-*) supaya Bootstrap tidak pernah mengenali elemen ini sama
        // sekali, dan hanya ada SATU mekanisme yang mengatur tab ini.
        (function () {
            var tabButtons = document.querySelectorAll('#configTabs button[data-tab-toggle]');
            if (!tabButtons.length) {
                return;
            }

            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var target = btn.getAttribute('data-tab-target');
                    if (!target) {
                        return;
                    }

                    tabButtons.forEach(function (b) {
                        b.classList.remove('active');
                        b.setAttribute('aria-selected', 'false');
                    });
                    document.querySelectorAll('#configTabs ~ .tab-content .tab-pane').forEach(function (p) {
                        p.classList.remove('show', 'active');
                    });

                    btn.classList.add('active');
                    btn.setAttribute('aria-selected', 'true');
                    var pane = document.querySelector(target);
                    if (pane) {
                        pane.classList.add('show', 'active');
                    }
                });
            });
        })();
        </script>
    </div>
    </div>
</div>

<!-- ====== Clients ====== -->
<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">Clients (NAS)</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <i class="fas fa-info-circle"></i> <strong>Apa fungsi bagian ini?</strong><br>
            "Client" di FreeRADIUS = perangkat NAS (biasanya router Mikrotik) yang diizinkan mengirim permintaan autentikasi ke server ini, dicocokkan lewat IP + secret (password rahasia antara router dan RADIUS, BUKAN password pelanggan).
            Pilih <strong>"Router Tertentu"</strong> kalau hanya satu router yang boleh memakai secret ini (lebih aman).
            Pilih <strong>"Semua Router (Wildcard)"</strong> kalau banyak router perlu memakai secret yang sama -- lebih praktis, tapi berarti SEMUA perangkat yang tahu secret itu bisa mengirim permintaan auth ke server ini.
        </p>
        <div class="mb-3 p-2 border rounded bg-light">
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="client_ip_mode_ui" id="clientModeSpecific" value="specific" checked onchange="toggleClientIpModeUi()">
                <label class="form-check-label" for="clientModeSpecific">Router Tertentu</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="client_ip_mode_ui" id="clientModeWildcard" value="wildcard" onchange="toggleClientIpModeUi()">
                <label class="form-check-label" for="clientModeWildcard">Semua Router (Wildcard 0.0.0.0/0)</label>
            </div>
            <div class="mt-2">
                <select id="clientRouterSelectUi" class="form-select form-select-sm" style="max-width:420px;" onchange="if(this.value){document.getElementById('clientIpInputUi').value=this.value;}">
                    <option value="">-- Pilih router untuk isi otomatis IP (opsional) --</option>
                    <?php foreach ($radius_server_list_ui as $srv_ui): ?>
                        <option value="<?= htmlspecialchars($srv_ui['IP'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($srv_ui['IP'] . ' (' . $srv_ui['AREA'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <script>
        function toggleClientIpModeUi(){
            var wildcard = document.getElementById('clientModeWildcard').checked;
            var ipField = document.getElementById('clientIpInputUi');
            var select = document.getElementById('clientRouterSelectUi');
            if (wildcard) {
                ipField.value = '0.0.0.0/0';
                ipField.readOnly = true;
                select.disabled = true;
            } else {
                ipField.readOnly = false;
                select.disabled = false;
                if (ipField.value === '0.0.0.0/0') { ipField.value = ''; }
            }
        }
        </script>
        <div class="mb-2">
            <input type="text" id="searchClient" class="form-control" placeholder="Cari Client...">
        </div>
        <div class="table-responsive">
        <table class="table table-bordered table-sm">
            <thead class="table-light">
<tr>
<th>Nama</th>
<th>IP</th>
<th>Secret</th>
<th>Action</th>
</tr>
</thead>
<tbody id="client-table">
<?php
$lines = readFileLinesWithSudoFallback($clients_file);
if (!empty($lines)) {
    $client = [];
    foreach($lines as $line){
        $line = trim($line);
        if($line === '' || $line === '{' || $line === '}') continue;
        if(strpos($line,'client ')===0){
            $client['name'] = trim(str_replace(['client','{'],'',$line));
        } elseif(strpos($line,'ipaddr')!==false){
            $client['ip'] = trim(explode('=',$line)[1]);
        } elseif(strpos($line,'secret')!==false){
            $client['secret'] = trim(explode('=',$line)[1]);
            if(!empty($client['name']) && !empty($client['secret'])){
                echo "<tr>
                <td>".htmlspecialchars($client['name'])."</td>
                <td>".htmlspecialchars($client['ip'])."</td>
                <td>".htmlspecialchars($client['secret'])."</td>
                <td>
                    <form method='post' action='radiuscontrol/proses.php' onsubmit='return confirm(\"Yakin ingin menghapus client ini?\");'>
                        <input type='hidden' name='delete_client' value='".htmlspecialchars($client['name'])."'>
                        <button type='submit' class='btn btn-sm btn-danger'>Hapus</button>
                    </form>
                </td>
                </tr>";
            }
            $client = [];
        }
    }
}
?>
<tr>
<form method="post" action="radiuscontrol/proses.php">
<td><input type="text" name="name" class="form-control" placeholder="Nama Client" required></td>
<td><input type="text" name="ip" id="clientIpInputUi" class="form-control" placeholder="IP Address" required></td>
<td><input type="text" name="secret" class="form-control" placeholder="Secret" required></td>
<td><button type="submit" name="add_client" class="btn btn-sm btn-primary">Tambah</button></td>
</form>
</tr>
</tbody>
</table>
</div>
    </div>
</div>

<hr>
<!-- ====== Filter ====== -->
<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">Filter</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <i class="fas fa-info-circle"></i> <strong>Apa fungsi bagian ini?</strong><br>
            Preset filter menentukan bagaimana FreeRADIUS memperlakukan permintaan autentikasi.
            "Tolak user tak dikenal" (rekomendasi) berarti username yang tidak terdaftar di tab User &amp; Authorize akan ditolak, seperti perilaku default FreeRADIUS.
            Mode "Terima Semua" di bawah HANYA untuk testing/debug di jaringan tertutup -- baca peringatannya sebelum mengaktifkan.
        </p>
        <form method="post" action="radiuscontrol/proses.php" onsubmit="return confirmAcceptAllUi();">
            <div class="mb-3">
                <label class="form-label">Preset Filter</label>
                <select name="filter_preset" class="form-select" style="max-width:420px;">
                    <?php $fp_ui = $radius_global_settings_ui['filter_preset'] ?? 'reject_unknown'; ?>
                    <option value="reject_unknown" <?= $fp_ui === 'reject_unknown' ? 'selected' : ''; ?>>Tolak user tak dikenal (Rekomendasi)</option>
                    <option value="permissive_logged_only" <?= $fp_ui === 'permissive_logged_only' ? 'selected' : ''; ?>>Permisif tapi tercatat di log</option>
                    <option value="custom" <?= $fp_ui === 'custom' ? 'selected' : ''; ?>>Custom (atur manual lewat Config Editor)</option>
                </select>
            </div>

            <div class="alert alert-danger">
                <strong><i class="fas fa-triangle-exclamation"></i> PERINGATAN KEAMANAN:</strong>
                Mode di bawah ini membuat FreeRADIUS menerima <u>SIAPA PUN</u> yang login dengan username/password APA PUN (termasuk yang salah) --
                secara harfiah SAMA DENGAN mematikan autentikasi RADIUS di server ini. HANYA aktifkan untuk testing/debug di jaringan tertutup,
                dan SEGERA nonaktifkan setelah selesai. Setiap perubahan tercatat di log sinkronisasi (siapa &amp; kapan).
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="accept_all_debug_enabled" id="acceptAllToggleUi" value="1"
                        <?= !empty($radius_global_settings_ui['accept_all_debug_enabled']) ? 'checked' : ''; ?>
                        onchange="document.getElementById('acceptAllConfirmWrapUi').style.display = this.checked ? 'block' : 'none';">
                    <label class="form-check-label" for="acceptAllToggleUi"><strong>Terima Semua User/Password (Testing/Debug)</strong></label>
                </div>
                <div id="acceptAllConfirmWrapUi" class="form-check mt-2" style="display: <?= !empty($radius_global_settings_ui['accept_all_debug_enabled']) ? 'block' : 'none'; ?>;">
                    <input class="form-check-input" type="checkbox" name="accept_all_debug_confirm" id="acceptAllConfirmUi" value="1">
                    <label class="form-check-label" for="acceptAllConfirmUi">Saya paham risikonya dan tetap ingin mengaktifkan mode ini.</label>
                </div>
                <?php if (!empty($radius_global_settings_ui['accept_all_debug_enabled_at'])): ?>
                    <small class="text-muted d-block mt-2">Terakhir diaktifkan: <?= htmlspecialchars((string) $radius_global_settings_ui['accept_all_debug_enabled_at'], ENT_QUOTES, 'UTF-8'); ?> oleh <?= htmlspecialchars((string) ($radius_global_settings_ui['accept_all_debug_enabled_by'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
                <?php if (!empty($radius_global_settings_ui['accept_all_debug_disabled_at'])): ?>
                    <small class="text-muted d-block">Terakhir dinonaktifkan: <?= htmlspecialchars((string) $radius_global_settings_ui['accept_all_debug_disabled_at'], ENT_QUOTES, 'UTF-8'); ?> oleh <?= htmlspecialchars((string) ($radius_global_settings_ui['accept_all_debug_disabled_by'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </div>

            <button type="submit" name="save_radius_filter" class="btn btn-primary">Simpan Pengaturan Filter</button>
        </form>
        <script>
        function confirmAcceptAllUi(){
            var toggle = document.getElementById('acceptAllToggleUi');
            var confirmBox = document.getElementById('acceptAllConfirmUi');
            if (toggle && toggle.checked && (!confirmBox || !confirmBox.checked)) {
                alert('Centang juga kotak konfirmasi risiko sebelum mengaktifkan mode "Terima Semua User/Password".');
                return false;
            }
            if (toggle && toggle.checked) {
                return confirm('Yakin ingin MENGAKTIFKAN mode "Terima Semua User/Password"? Ini mematikan autentikasi RADIUS di server ini.');
            }
            return true;
        }
        </script>
    </div>
</div>

<hr>
<!-- ====== Default ====== -->
<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">Default</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <i class="fas fa-info-circle"></i> <strong>Apa fungsi bagian ini?</strong><br>
            Pengaturan di sini berlaku GLOBAL untuk semua pelanggan PPPoE yang paketnya diset "Profil RADIUS Langsung" (diatur per-paket di halaman Kelola Paket PPPoE).
            Toggle master di bawah HARUS aktif <em>dan</em> paket pelanggan harus diset "RADIUS Langsung" -- kalau salah satu mati, perilaku lama (profil Mikrotik, cuma Mikrotik-Group) tetap berlaku, jadi AMAN diaktifkan tanpa mengubah pelanggan existing.
        </p>
        <p class="text-muted">Contoh atribut RADIUS yang dihasilkan kalau aktif, untuk pelanggan yang <strong>sudah bayar</strong>:</p>
        <pre class="bg-dark text-light p-2 rounded" style="font-size:0.8em; white-space:pre-wrap;">NamaPelanggan Cleartext-Password := "passwordnya"
	Service-Type := Framed-User,
	Framed-Protocol := PPP,
	Mikrotik-Rate-Limit := "20M/20M",
	Mikrotik-Address-List := "Pelanggan",
	Session-Timeout := 86400,
	Mikrotik-Group := "NamaPaket"</pre>
        <p class="text-muted">Untuk pelanggan yang <strong>menunggak</strong>, <code>Mikrotik-Rate-Limit</code> SENGAJA TIDAK dikirim (bukan diisi angka kecil) -- pembatasan saat isolir jadi tanggung jawab aturan firewall/queue di router yang mencocokkan <code>Mikrotik-Address-List</code>/<code>Mikrotik-Group</code> ke nilai isolir di bawah, persis seperti profil Mikrotik biasa (kontrol ada di identitas "EXPIRED", bukan di angka rate per-sesi):</p>
        <pre class="bg-dark text-light p-2 rounded" style="font-size:0.8em; white-space:pre-wrap;">NamaPelanggan Cleartext-Password := "passwordnya"
	Service-Type := Framed-User,
	Framed-Protocol := PPP,
	Mikrotik-Address-List := "EXPIRED",
	Session-Timeout := 86400,
	Mikrotik-Group := "EXPIRED"</pre>
        <p class="text-muted"><i class="fas fa-info-circle"></i> Pastikan ada aturan firewall/queue di router yang mencocokkan address-list "EXPIRED" (nama sesuai isian "Address-List/Grup Nunggak" di bawah) untuk membatasi bandwidth dan/atau mengarahkan ke halaman pembayaran -- sama seperti yang biasanya sudah disiapkan untuk profil Mikrotik "EXPIRED".</p>

        <form method="post" action="radiuscontrol/proses.php" class="row g-3">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="pppoe_radius_langsung_master_enabled" id="masterToggleUi" value="1" <?= !empty($radius_global_settings_ui['pppoe_radius_langsung_master_enabled']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="masterToggleUi"><strong>Aktifkan Profil RADIUS Langsung untuk paket yang di-set demikian</strong></label>
                </div>
                <small class="text-muted">Default NONAKTIF -- tidak mengubah pelanggan existing sampai diaktifkan.</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Session-Timeout Default (detik)</label>
                <input type="number" min="0" name="session_timeout_default" class="form-control" value="<?= htmlspecialchars((string) ($radius_global_settings_ui['session_timeout_default'] ?? 86400), ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">86400 = 24 jam (paksa re-autentikasi PPPoE)</small>
            </div>
            <div class="col-md-4">
                <label class="form-label">Address-List Aktif (sudah bayar)</label>
                <input type="text" name="address_list_active" class="form-control" value="<?= htmlspecialchars((string) ($radius_global_settings_ui['address_list_active'] ?? 'Pelanggan'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Address-List/Grup Nunggak (isolir)</label>
                <input type="text" name="address_list_expired" class="form-control" value="<?= htmlspecialchars((string) ($radius_global_settings_ui['address_list_expired'] ?? 'EXPIRED'), ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted">Harus SAMA dengan grup isolir yang dipakai cron/script isolir lain (default: EXPIRED).</small>
            </div>
            <div class="col-12">
                <button type="submit" name="save_radius_defaults" class="btn btn-primary">Simpan Pengaturan Default</button>
            </div>
        </form>
    </div>
</div>

<hr>
<!-- ====== Users ====== -->
<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">User &amp; Authorize</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <i class="fas fa-info-circle"></i> <strong>Apa fungsi bagian ini?</strong><br>
            <strong>Users</strong> (<code>/etc/freeradius/3.0/users</code>) dan <strong>Authorize</strong>
            (<code>/etc/freeradius/3.0/mods-config/files/authorize</code>) adalah pasangan file yang HARUS identik --
            modul <code>files</code> FreeRADIUS sebenarnya membaca "authorize", sementara "users" idealnya cuma symlink ke sana.
            Panel ini otomatis menulis ke keduanya sekaligus supaya tidak pernah berbeda.
            Kolom <strong>"Dikelola Sistem?"</strong> menandai user yang dibuat otomatis dari data pelanggan (lewat cron/form tambah pelanggan) --
            user ini akan disinkron ulang otomatis kalau diedit lewat halaman pelanggan; user "Manual" (ditambah lewat form di bawah) tidak pernah disentuh proses otomatis.
        </p>
        <div class="mb-3">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#usersModal">View Users</button>
        </div>
        <div class="mb-3">
            <h5>Add New User</h5>
            <form method="post" action="radiuscontrol/proses.php" class="row g-3">
                <div class="col-md-3">
                    <input type="text" name="user" class="form-control" placeholder="User" required>
                </div>
                <div class="col-md-3">
                    <input type="text" name="pass" class="form-control" placeholder="Password" required>
                </div>
                <div class="col-md-3">
                    <input type="text" name="group" class="form-control" placeholder="Group (Mikrotik-Group)">
                </div>
                <div class="col-md-3">
                    <button type="submit" name="add_user" class="btn btn-primary">Tambah</button>
                </div>
                <div class="col-12">
                    <a class="small" data-bs-toggle="collapse" href="#advancedUserFieldsUi" role="button">
                        <i class="fas fa-sliders-h"></i> Atribut lanjutan (opsional -- Service-Type, Framed-Protocol, Rate-Limit, Address-List, Session-Timeout)
                    </a>
                </div>
                <div class="collapse col-12" id="advancedUserFieldsUi">
                    <div class="row g-3 p-2 border rounded bg-light">
                        <div class="col-md-3">
                            <input type="text" name="service_type" class="form-control" placeholder="Service-Type (mis. Framed-User)">
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="framed_protocol" class="form-control" placeholder="Framed-Protocol (mis. PPP)">
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="rate_limit" class="form-control" placeholder='Mikrotik-Rate-Limit (mis. 20M/20M)'>
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="address_list" class="form-control" placeholder="Mikrotik-Address-List">
                        </div>
                        <div class="col-md-3">
                            <input type="number" min="0" name="session_timeout" class="form-control" placeholder="Session-Timeout (detik)">
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal for Users Table -->
<div class="modal fade" id="usersModal" tabindex="-1" aria-labelledby="usersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="usersModalLabel">Users</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <input type="text" id="searchUser" class="form-control" placeholder="Cari User...">
                </div>
                <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
<tr>
<th>User</th>
<th>Password</th>
<th>Group</th>
<th>Dikelola Sistem?</th>
<th>Action</th>
</tr>
</thead>
<tbody id="user-table">
<?php
// Parsing terpadu lewat radiusReadMergedBlocks() (baca KEDUA path
// users+mirror, per-block bukan per-line) -- versi lama (4 regex per baris,
// baru echo saat ketemu Mikrotik-Group) TIDAK PERNAH menampilkan block yang
// tidak punya Mikrotik-Group sama sekali (mis. block DEFAULT Auth-Type:=Accept
// dari toggle Filter, atau user manual yang cuma diisi atribut lanjutan tanpa
// Group).
$radius_blocks_ui = radiusReadMergedBlocks();
foreach ($radius_blocks_ui as $blk_ui) {
    $raw_ui = $blk_ui['raw'];
    $uname_ui = $blk_ui['username'];

    $pass_ui = '';
    if ($uname_ui !== null && preg_match('/^' . preg_quote($uname_ui, '/') . '\s+Cleartext-Password\s*:=\s*"(.*)"/', $raw_ui, $mpass_ui)) {
        $pass_ui = $mpass_ui[1];
    }
    $group_ui = '';
    if (preg_match('/Mikrotik-Group\s*:?=\s*"([^"]+)"/', $raw_ui, $mgroup_ui)) {
        $group_ui = $mgroup_ui[1];
    }

    $isManaged_ui = $uname_ui !== null && array_key_exists($uname_ui, $radius_managed_state_ui);
    $displayName_ui = $uname_ui ?? '(block tanpa username, mis. DEFAULT)';

    echo '<tr>';
    echo '<td>' . htmlspecialchars($displayName_ui, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($pass_ui, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($group_ui, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . ($isManaged_ui ? '<span class="badge bg-success">Sistem (pelanggan)</span>' : '<span class="badge bg-secondary">Manual</span>') . '</td>';
    echo '<td>';
    if ($uname_ui !== null) {
        echo "<form method='post' action='radiuscontrol/proses.php' onsubmit='return confirm(\"Yakin ingin menghapus user ini?\");'>"
            . "<input type='hidden' name='delete_user' value='" . htmlspecialchars($uname_ui, ENT_QUOTES, 'UTF-8') . "'>"
            . "<button type='submit' class='btn btn-sm btn-danger'>Hapus</button>"
            . "</form>";
    } else {
        echo '<span class="text-muted">-</span>';
    }
    echo '</td>';
    echo '</tr>';
}
?>
</tbody>
</table>
</div>
            </div>
        </div>
    </div>
</div>

        <script>
        // ====== Filter Pencarian ======
        document.getElementById("searchUser").addEventListener("keyup", function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll("#user-table tr");
            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                row.style.display = text.includes(filter) ? "" : "none";
            });
        });
        </script>

<hr>

<?php
$timer_dir = "/etc/freeradius/user_timers";

// Hapus user tunggal
if (isset($_GET['hapus'])) {
    $username = preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['hapus']);
    $file = "$timer_dir/$username.json";
    if (file_exists($file)) unlink($file);
    
    
}

// Hapus beberapa user sekaligus
if (isset($_POST['hapus_terpilih'])) {
    if (!empty($_POST['users'])) {
        foreach ($_POST['users'] as $user) {
            $user = preg_replace('/[^a-zA-Z0-9._-]/', '', $user);
            $file = "$timer_dir/$user.json";
            if (file_exists($file)) unlink($file);
        }
    }
    
   
}

// Ambil semua file JSON
$timers = glob("$timer_dir/*.json");
?>


<title>User Timers FreeRADIUS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
function toggleSelectAll(source) {
    checkboxes = document.getElementsByName('users[]');
    for(var i=0, n=checkboxes.length;i<n;i++) {
        checkboxes[i].checked = source.checked;
    }
}
</script>


<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">User Timers FreeRADIUS</h3>
    </div>
    <div class="card-body">
        <form method="POST">
        <div class="table-responsive">
        <table class="table table-bordered table-striped">
            <thead class="table-light">
        <tr>
            <th><input type="checkbox" onclick="toggleSelectAll(this)"></th>
            <th>User</th>
            <th>Sisa Waktu</th>
            <th>Aksi</th>
        </tr>
    </thead>
    <tbody>
        <?php
        if (!empty($timers)) {
            foreach ($timers as $file) {
                $data = json_decode(file_get_contents($file), true);
                $username = basename($file, ".json");

                // Hitung sisa waktu
                $expire_at = isset($data['expire_at']) ? $data['expire_at'] : 0;
                $now = time();
                $remaining = max(0, $expire_at - $now);
                $hours = floor($remaining / 3600);
                $minutes = floor(($remaining % 3600) / 60);
                $seconds = $remaining % 60;
                $remaining_str = "$hours h $minutes m $seconds s";

                echo "<tr>
                        <td><input type='checkbox' name='users[]' value='$username'></td>
                        <td>$username</td>
                        <td>$remaining_str</td>
                        <td>
                            <a href='?hapus=$username' class='btn btn-danger btn-sm' onclick='return confirm(\"Hapus timer $username?\")'>Hapus</a>
                        </td>
                    </tr>";
            }
        } else {
            echo "<tr><td colspan='4' class='text-center'>Tidak ada user timer</td></tr>";
        }
        ?>
        </tbody>
        </table>
        </div>
        <button type="submit" name="hapus_terpilih" class="btn btn-danger" onclick="return confirm('Hapus semua user yang dipilih?')">Hapus Terpilih</button>
        </form>
    </div>
</div>

<hr>

<!-- ====== FreeRADIUS Request Log ====== -->
<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">FreeRADIUS Request Log</h3>
    </div>
    <div class="card-body">
        <div class="mb-2">
            <input type="text" id="searchLog" class="form-control" placeholder="Cari Log...">
        </div>
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
        <table class="table table-bordered table-striped table-sm">
            <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
<tr>
<th>No</th>
<th>User</th>
<th>NAS IP</th>
<th>Client IP</th>
<th>MAC</th>
<th>Service</th>
<th>NAS Port</th>
<th>Status</th>
<th>Detail</th>
</tr>
</thead>
<tbody id="log-body">
<tr><td colspan="7" class="text-center">Memuat log...</td></tr>
</tbody>
</table>
</div>

        <script>
        // ================== Filter Client ==================
        document.getElementById('searchClient').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            document.querySelectorAll('#client-table tr').forEach(tr => {
                if(tr.querySelectorAll('td').length > 0){
                    tr.style.display = Array.from(tr.cells).some(td => td.textContent.toLowerCase().includes(filter)) ? '' : 'none';
                }
            });
        });

        // ================== Filter User ==================
        document.getElementById('searchUser').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            document.querySelectorAll('#user-table tr').forEach(tr => {
                if(tr.querySelectorAll('td').length > 0){
                    tr.style.display = Array.from(tr.cells).some(td => td.textContent.toLowerCase().includes(filter)) ? '' : 'none';
                }
            });
        });

        // ================== Filter Log ==================
        document.getElementById('searchLog').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            document.querySelectorAll('#log-body tr').forEach(tr => {
                if(tr.querySelectorAll('td').length > 0){
                    tr.style.display = Array.from(tr.cells).some(td => td.textContent.toLowerCase().includes(filter)) ? '' : 'none';
                }
            });
        });

        // ================== Load Log ==================
        function loadLog(){
            fetch('radiuscontrol/fetch_radius_log.php')
                .then(resp => resp.text())
                .then(html => {
                    document.getElementById('log-body').innerHTML = html;
                })
                .catch(err => console.error(err));
        }

        // Load pertama kali & auto-refresh setiap 20 detik
        loadLog();
        setInterval(loadLog, 20000);
        </script>
    </div>
</div>

<hr>

<?php
// radutmp_setup.php (updated)
// Tool web untuk setup radutmp / user_timers + fix ketika file radutmp hilang.
// WARNING: Gunakan hanya di server terkontrol. Anda mungkin butuh mengizinkan sudo untuk www-data.

function run_cmd($cmd) {
    $output = [];
    $ret = 0;
    exec($cmd . ' 2>&1', $output, $ret);
    return ['cmd'=>$cmd, 'out'=>implode("\n",$output), 'rc'=>$ret];
}

function is_radutmp_in_accounting($file='/etc/freeradius/3.0/sites-enabled/default'){
    if(!is_readable($file)) return false;
    $c = file_get_contents($file);
    return (strpos($c, "accounting") !== false && strpos($c, "radutmp") !== false);
}

// Ringkasan owner:group + mode + bisa-diakses-www-data-atau-tidak untuk satu
// path -- dipakai "Cek Status Lengkap" supaya kelihatan jelas file mana yang
// masih perlu diperbaiki tanpa harus buka terminal.
function radiusPermSummary(string $path): string {
    if (!file_exists($path)) {
        return 'TIDAK ADA';
    }
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($path))['name'] ?? (string) fileowner($path)) : (string) fileowner($path);
    $group = function_exists('posix_getgrgid') ? (posix_getgrgid(filegroup($path))['name'] ?? (string) filegroup($path)) : (string) filegroup($path);
    $mode = substr(sprintf('%o', fileperms($path)), -4);
    $rw = (is_readable($path) ? 'R' : '-') . (is_writable($path) ? 'W' : '-');
    return "$owner:$group $mode (www-data: $rw)";
}

$action = $_POST['action'] ?? null;
$results = [];

if($action){
    if($action === 'check_status'){
        // Cek LENGKAP -- semua file/direktori yang dibutuhkan supaya www-data
        // bisa kontrol FreeRADIUS sepenuhnya, bukan cuma radutmp/user_timers.
        $status = [];
        $status['clients.conf']            = radiusPermSummary('/etc/freeradius/3.0/clients.conf');
        $status['users (symlink)']         = radiusPermSummary('/etc/freeradius/3.0/users');
        $status['mods-config/files/ (dir)']= radiusPermSummary('/etc/freeradius/3.0/mods-config/files');
        $status['authorize (file asli)']   = radiusPermSummary('/etc/freeradius/3.0/mods-config/files/authorize');
        $status['sites-available/default'] = radiusPermSummary('/etc/freeradius/3.0/sites-available/default');
        $status['sites-enabled/default']   = radiusPermSummary('/etc/freeradius/3.0/sites-enabled/default');
        $status['policy.d/filter']         = radiusPermSummary('/etc/freeradius/3.0/policy.d/filter');
        $status['radutmp_in_accounting']   = is_radutmp_in_accounting() ? 'ya' : 'TIDAK';
        $status['user_timers/ (dir)']      = radiusPermSummary('/etc/freeradius/user_timers');
        $status['managed_pelanggan.json']  = radiusPermSummary('/etc/freeradius/managed_pelanggan.json');
        $status['log/radutmp']             = radiusPermSummary('/var/log/freeradius/radutmp');
        $status['log/sync-decisions.log']  = radiusPermSummary('/var/log/freeradius/sync-decisions.log');
        $status['log/debug-radius-web.log']= radiusPermSummary('/var/log/freeradius/debug-radius-web.log');
        $status['log/request-log-history.json'] = radiusPermSummary('/var/log/freeradius/request-log-history.json');
        $g = trim((string) shell_exec("getent group freerad 2>/dev/null"));
        $status['grup freerad'] = $g !== '' ? 'ada' : 'BELUM ADA';
        $groupsOut = trim((string) shell_exec('groups www-data 2>/dev/null'));
        $status['www-data anggota grup freerad'] = (strpos($groupsOut, 'freerad') !== false) ? 'ya' : 'TIDAK (klik "Perbaiki Semua Permission")';
        $sudoCheck = trim((string) shell_exec('sudo -n true 2>&1'));
        $status['sudo tanpa password untuk www-data'] = ($sudoCheck === '') ? 'OK' : "BERMASALAH: $sudoCheck";
        $results[] = ['title'=>'Status Lengkap Permission FreeRADIUS', 'data'=>$status];
    }

if ($action === 'run_all') {
    $results = [];

    // LANGKAH 0: Bersihkan semua file backup yang dapat menyebabkan duplicate
    $results[] = run_cmd("
        sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name 'default.save*' -delete &&
        sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name 'default.bak*' -delete &&
        sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name 'default.backup*' -delete &&
        sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name '*.save' -delete &&
        sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name '*.bak' -delete &&
        echo 'semua file backup di sites-enabled dibersihkan'
    ");

    // LANGKAH 1: Stop FreeRADIUS service untuk operasi yang aman
    $results[] = run_cmd("sudo systemctl stop freeradius && echo 'FreeRADIUS service stopped'");

    // Baca file default aktif
    $default_file = '/etc/freeradius/3.0/sites-enabled/default';
    if (!file_exists($default_file)) {
        $results[] = ['title' => 'run_all', 'out' => "WARNING: File default tidak ditemukan di $default_file, akan dibuat ulang"];
        
        // Buat symlink baru
        $results[] = run_cmd("sudo /bin/ln -sf /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/sites-enabled/default && echo 'symlink default dibuat'");
    } else {
        $content = @file_get_contents($default_file);
        if ($content === false) {
            $results[] = ['title' => 'run_all', 'out' => "WARNING: Tidak dapat membaca $default_file — pastikan PHP punya izin akses."];
        } else {
            // Tambahkan radutmp ke dalam blok accounting jika belum ada
            if (strpos($content, 'radutmp') === false) {
                $new = preg_replace_callback('/(^\s*accounting\s*\{\s*\n)/mi', function ($m) {
                    return $m[1] . "        radutmp\n";
                }, $content, 1);

                $tmpfile = sys_get_temp_dir() . '/radutmp_new.tmp';
                file_put_contents($tmpfile, $new);

                $results[] = run_cmd("sudo /bin/tee " . escapeshellarg($default_file) . " < " . escapeshellarg($tmpfile));
                $results[] = run_cmd("rm -f " . escapeshellarg($tmpfile));
            } else {
                $results[] = ['title' => 'run_all', 'out' => 'radutmp sudah ada di konfigurasi.'];
            }
        }
    }

    // Buat direktori user_timers
    $results[] = run_cmd("sudo /bin/mkdir -p /etc/freeradius/user_timers && sudo /bin/chmod -R 777 /etc/freeradius/user_timers && echo 'user_timers ok'");

    // Buat log radutmp
    $results[] = run_cmd("
        sudo /bin/mkdir -p /var/log/freeradius &&
        sudo /bin/touch /var/log/freeradius/radutmp &&
        sudo /bin/chown freerad:freerad /var/log/freeradius/radutmp &&
        sudo /bin/chmod 664 /var/log/freeradius/radutmp &&
        echo 'radutmp log ok'
    ");

    // Ownership+permission file config utama supaya www-data bisa
    // baca/tulis LANGSUNG (Config Editor, tambah client/user, dst) tanpa
    // selalu bergantung pada fallback sudo di tiap request.
    $results[] = run_cmd("
        sudo /bin/chown www-data:www-data /etc/freeradius/3.0/clients.conf /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/policy.d/filter 2>/dev/null;
        sudo /bin/chmod 664 /etc/freeradius/3.0/clients.conf /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/policy.d/filter 2>/dev/null;
        echo 'permission config utama ok'
    ");

    // File authorize (yang BENERAN dibaca modul `files`) + direktori
    // induknya -- paket FreeRADIUS Debian/Ubuntu sering set mods-config ke
    // mode 750 root:freerad, jadi www-data butuh execute-bit (755) di
    // direktori supaya bisa traversal, bukan cuma mode file itu sendiri.
    $results[] = run_cmd("
        sudo /bin/mkdir -p /etc/freeradius/3.0/mods-config/files &&
        sudo /bin/chmod 755 /etc/freeradius/3.0/mods-config /etc/freeradius/3.0/mods-config/files &&
        sudo /bin/touch /etc/freeradius/3.0/mods-config/files/authorize &&
        sudo /bin/chown www-data:www-data /etc/freeradius/3.0/mods-config/files/authorize &&
        sudo /bin/chmod 664 /etc/freeradius/3.0/mods-config/files/authorize &&
        echo 'permission authorize ok'
    ");

    // Symlink users -> authorize (kalau belum ada / putus). Kalau `users`
    // sudah berupa file biasa (bukan symlink) yang berisi data, JANGAN
    // ditimpa -- radiusReadMergedBlocks() sudah menggabungkan isi kedua
    // path jadi aman, tapi symlink tetap lebih baik supaya keduanya SELALU
    // identik tanpa perlu ditulis dua kali.
    $results[] = run_cmd("
        if [ ! -e /etc/freeradius/3.0/users ]; then
            sudo /bin/ln -sf /etc/freeradius/3.0/mods-config/files/authorize /etc/freeradius/3.0/users && echo 'symlink users->authorize dibuat';
        elif [ -L /etc/freeradius/3.0/users ]; then
            echo 'users sudah berupa symlink';
        else
            echo 'users adalah file biasa (bukan symlink) -- dibiarkan, radius_sync_lib.php sudah menulis ke keduanya sekaligus';
        fi
    ");

    // File state/log tambahan yang dipakai radius_sync_lib.php & dashboard
    // ini (managed-state, log keputusan sync, debug terminal, riwayat
    // request log) -- dibuat + dibuka izinnya di muka supaya www-data bisa
    // baca/tulis langsung, bukan cuma lewat fallback sudo tiap kali.
    $results[] = run_cmd("
        sudo /bin/touch /etc/freeradius/managed_pelanggan.json &&
        sudo /bin/chown www-data:www-data /etc/freeradius/managed_pelanggan.json &&
        sudo /bin/chmod 664 /etc/freeradius/managed_pelanggan.json &&
        sudo /bin/touch /var/log/freeradius/sync-decisions.log /var/log/freeradius/debug-radius-web.log /var/log/freeradius/request-log-history.json &&
        sudo /bin/chmod 666 /var/log/freeradius/sync-decisions.log /var/log/freeradius/debug-radius-web.log /var/log/freeradius/request-log-history.json &&
        echo 'file state/log tambahan ok'
    ");

    // Buat grup freerad kalau belum ada (usermod -aG di bawah akan gagal
    // kalau grup ini belum ada sama sekali).
    $results[] = run_cmd("if ! getent group freerad >/dev/null 2>&1; then sudo /usr/sbin/groupadd freerad && echo 'group freerad dibuat'; else echo 'group freerad sudah ada'; fi");

    // Tambahkan www-data ke grup freerad
    $results[] = run_cmd("sudo /usr/sbin/usermod -aG freerad www-data && echo 'www-data group add attempted'");

    // VALIDASI: Cek konfigurasi sebelum restart
    $results[] = run_cmd("sudo freeradius -X -C 2>&1 | head -20 && echo 'validasi konfigurasi selesai'");

    // START: Jalankan FreeRADIUS
    $results[] = run_cmd("sudo systemctl start freeradius && sudo systemctl enable freeradius && echo 'FreeRADIUS service started and enabled'");

    // VERIFIKASI: Cek status final
    $results[] = run_cmd("sudo systemctl status freeradius --no-pager | head -15 && echo 'status check completed'");

    $results[] = ['title' => 'Selesai', 'out' => "✅ Semua permission & file pendukung sudah diperbaiki. Kalau ini PERTAMA KALI www-data ditambahkan ke grup freerad, restart PHP-FPM/Apache/Nginx supaya keanggotaan grup itu efektif."];
}

    if($action === 'reset_default'){
        // LANGKAH 1: Bersihkan semua file backup dan duplicate di sites-enabled
        $cleanup_commands = [
            'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "default.save*" -delete',
            'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "default.bak*" -delete',
            'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "default.backup*" -delete',
            'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "*.save" -delete',
            'sudo /bin/find /etc/freeradius/3.0/sites-enabled -maxdepth 1 -type f -name "*.bak" -delete'
        ];
        
        foreach($cleanup_commands as $cmd) {
            shell_exec($cmd . ' 2>&1');
        }
        
        // LANGKAH 2: Stop FreeRADIUS service
        shell_exec('sudo systemctl stop freeradius 2>&1');
        
        // Konten default baru
        $default_content = <<<EOD
server default {
    listen {
        type = auth
        ipaddr = *
        port = 1812
    }

    listen {
        type = acct
        ipaddr = *
        port = 1813
    }

    authorize {
        files
        pap
        chap
        mschap
    }

    authenticate {
        Auth-Type PAP {
            pap
        }
        Auth-Type CHAP {
            chap
        }
        Auth-Type MS-CHAP {
            mschap
        }
    }

    preacct {
        # kosong
    }

    accounting {
        detail
        radutmp
    }

    session {
        # kosong
    }

    post-proxy {
        # kosong
    }
}
EOD;

        // LANGKAH 3: Hapus file enabled lama dengan aman
        if(file_exists($enabled_file)) {
            shell_exec('sudo /bin/rm -f ' . escapeshellarg($enabled_file) . ' 2>&1');
        }

        // LANGKAH 4: Buat file sites-available/default baru
        $temp_file = tempnam(sys_get_temp_dir(), 'freeradius_default_');
        file_put_contents($temp_file, $default_content);
        shell_exec('sudo /bin/cp ' . escapeshellarg($temp_file) . ' ' . escapeshellarg($default_file) . ' 2>&1');
        unlink($temp_file);

        // LANGKAH 5: Buat symbolic link bersih di sites-enabled
        shell_exec('sudo /bin/ln -sf ' . escapeshellarg($default_file) . ' ' . escapeshellarg($enabled_file) . ' 2>&1');

        // LANGKAH 6: Validasi konfigurasi sebelum restart
        $validate_output = shell_exec('sudo freeradius -X -C 2>&1');
        
        if(strpos($validate_output, 'Duplicate virtual server') !== false) {
            $results[] = ['title'=>'Reset Default', 'out'=>"ERROR: Masih ada duplicate virtual server. Validasi output: $validate_output"];
        } else {
            // LANGKAH 7: Restart FreeRADIUS jika validasi berhasil
            $restart_output = shell_exec('sudo systemctl start freeradius 2>&1');
            shell_exec('sudo systemctl enable freeradius 2>&1');
            
            $status_output = shell_exec('sudo systemctl status freeradius --no-pager 2>&1');
            
            $out = "Default FreeRADIUS berhasil di-reset dan dibersihkan dari duplicate files.\n";
            $out .= "Validasi: $validate_output\n";
            $out .= "Restart: $restart_output\n";
            $out .= "Status: $status_output";
            $results[] = ['title'=>'Reset Default', 'out'=>$out];
        }
    }


}
?>

<div class="card mb-3">
    <div class="card-header bg-primary">
        <h3 class="mb-0">MULTI MODE Checklist</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">
            <i class="fas fa-info-circle"></i> <strong>Apa fungsi bagian ini?</strong><br>
            Untuk pelanggan bermode <strong>MULTI MODE</strong> (secret Mikrotik + entry RADIUS sekaligus), fallback ke RADIUS saat secret Mikrotik hilang/terhapus
            adalah <strong>perilaku bawaan RouterOS</strong> -- TIDAK ADA kode di aplikasi ini yang "memindahkan mode" pelanggan. Ada dua syarat supaya fallback ini benar-benar bekerja:
        </p>
        <ol>
            <li>Entry RADIUS pelanggan MULTI MODE harus SELALU ada &amp; fresh. Ini dijamin aplikasi ini: setiap tambah/edit/isolir pelanggan langsung menulis ke RADIUS saat itu juga (bukan menunggu cron 30 menit), dan cron sync jadi jaring pengaman tambahan.</li>
            <li><strong>PPP &gt; AAA &gt; "Use RADIUS"</strong> harus AKTIF di router (NAS) yang melayani pelanggan tersebut. Ini murni pengaturan SISI ROUTER, di luar kendali kode PHP manapun -- kalau opsi ini mati di router, RouterOS TIDAK akan pernah mencoba RADIUS sama sekali walau entry-nya sudah benar di server ini.</li>
        </ol>
        <p class="text-muted">Cek status "Use RADIUS" per router (read-only, tidak mengubah apa pun):</p>
        <form method="post" action="radiuscontrol/proses.php" class="row g-2 align-items-end">
            <div class="col-md-6">
                <select name="server_ip" class="form-select" required>
                    <option value="">-- Pilih router --</option>
                    <?php foreach ($radius_server_list_ui as $srv_ui2): ?>
                        <option value="<?= htmlspecialchars($srv_ui2['IP'], ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($srv_ui2['IP'] . ' (' . $srv_ui2['AREA'] . ')', ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-auto">
                <button type="submit" name="check_ppp_aaa" class="btn btn-outline-primary">Cek Status Use-RADIUS</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-success">
        <h1 class="mb-0">Setup &amp; Permission FreeRADIUS</h1>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            <i class="fas fa-info-circle"></i> <strong>Apa fungsi bagian ini?</strong><br>
            www-data (proses PHP) butuh izin baca/tulis ke banyak file di <code>/etc/freeradius/</code> dan <code>/var/log/freeradius/</code> supaya semua fitur di halaman ini (Config Editor, Clients, User &amp; Authorize, sync otomatis) bisa jalan tanpa gagal diam-diam. Cukup 2 tombol untuk kebutuhan normal:
        </p>
        <ul class="text-muted">
            <li><strong>Cek Status Lengkap</strong> -- lihat owner/permission tiap file penting saat ini, tanpa mengubah apa pun.</li>
            <li><strong>Perbaiki Semua Permission (Otomatis)</strong> -- atur ulang ownership/permission SEMUA file config, file authorize, direktori user_timers, grup <code>freerad</code>, lalu validasi &amp; restart FreeRADIUS. Aman dijalankan berkali-kali (idempotent).</li>
        </ul>

  <form method="post" class="mb-3">
    <div class="row g-2">
      <div class="col-auto"><button name="action" value="check_status" class="btn btn-outline-primary">Cek Status Lengkap</button></div>
      <div class="col-auto"><button name="action" value="run_all" class="btn btn-dark">Perbaiki Semua Permission (Otomatis)</button></div>
      <div class="col-auto"><button name="action" value="reset_default" class="btn btn-danger" onclick="return confirm('Yakin? Ini akan MENIMPA sites-available/default dengan template minimal bawaan dan restart FreeRADIUS. Konfigurasi khusus yang pernah ditambahkan manual akan hilang.');">Reset Default &amp; Restart FreeRADIUS</button></div>
      <div class="col-auto"><button name="action" value="reset_users" formaction='radiuscontrol/proses.php' class="btn btn-danger" onclick="return confirm('Yakin? Ini akan MENGHAPUS SEMUA user di file users/authorize -- termasuk yang dikelola otomatis dari data pelanggan. Semua pelanggan RADIUS/MULTI MODE tidak akan bisa login sampai sync berikutnya membuat ulang entrinya.');">Reset Users (Hapus Semua)</button></div>
    </div>
    <small class="text-muted d-block mt-2">
        <i class="fas fa-triangle-exclamation text-danger"></i> Dua tombol merah di atas destruktif -- "Reset Default" menimpa konfigurasi virtual server, "Reset Users" mengosongkan SEMUA user RADIUS (baru terisi lagi setelah sync berikutnya). Gunakan hanya kalau memang diperlukan.
    </small>
  </form>

  <?php if($results): ?>
    <?php foreach($results as $r): ?>
      <div class="card mb-3">
        <div class="card-header"><?php echo htmlspecialchars($r['title'] ?? 'Hasil'); ?></div>
        <div class="card-body">
          <?php if(isset($r['data']) && is_array($r['data'])): ?>
                        <table class="table table-sm"><caption class="caption-top h6">Check Output</caption><tbody>
              <?php foreach($r['data'] as $k=>$v): ?>
                <tr><th style="width:35%"><?php echo htmlspecialchars($k); ?></th><td><pre class="mb-0"><?php echo htmlspecialchars($v); ?></pre></td></tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php else: ?>
            <pre><?php echo htmlspecialchars($r['out'] ?? json_encode($r)); ?></pre>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

        <hr>

        <!-- Copy-Paste Manual -->
        <h5>Setup Manual (Copy-Paste ke Terminal)</h5>
        <p class="text-muted">Kalau tombol "Perbaiki Semua Permission (Otomatis)" di atas gagal (biasanya karena sudoers belum diizinkan -- lihat di bawah), copy-paste semua perintah ini ke terminal server sebagai root/sudo. Isinya SAMA PERSIS dengan yang dijalankan tombol otomatis di atas.</p>
        <div class="mb-3">
            <textarea class="form-control" rows="26" readonly onclick="this.select()">
# Jalankan semua perintah ini secara berurutan sebagai root/sudo

# 1. Ownership + permission file config utama
sudo chown www-data:www-data /etc/freeradius/3.0/clients.conf /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/policy.d/filter
sudo chmod 664 /etc/freeradius/3.0/clients.conf /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/policy.d/filter

# 2. File authorize (yang BENERAN dibaca modul `files`) + direktori induknya
sudo mkdir -p /etc/freeradius/3.0/mods-config/files
sudo chmod 755 /etc/freeradius/3.0/mods-config /etc/freeradius/3.0/mods-config/files
sudo touch /etc/freeradius/3.0/mods-config/files/authorize
sudo chown www-data:www-data /etc/freeradius/3.0/mods-config/files/authorize
sudo chmod 664 /etc/freeradius/3.0/mods-config/files/authorize

# 3. Symlink users -> authorize (kalau /etc/freeradius/3.0/users belum ada)
sudo ln -sf /etc/freeradius/3.0/mods-config/files/authorize /etc/freeradius/3.0/users

# 4. Direktori & file radutmp (dipakai fitur online/offline session)
sudo mkdir -p /var/log/freeradius
sudo touch /var/log/freeradius/radutmp
sudo chown freerad:freerad /var/log/freeradius/radutmp
sudo chmod 664 /var/log/freeradius/radutmp

# 5. Direktori user_timers (voucher hotspot)
sudo mkdir -p /etc/freeradius/user_timers
sudo chmod -R 777 /etc/freeradius/user_timers

# 6. File state/log tambahan (radius_sync_lib.php + dashboard)
sudo touch /etc/freeradius/managed_pelanggan.json
sudo chown www-data:www-data /etc/freeradius/managed_pelanggan.json
sudo chmod 664 /etc/freeradius/managed_pelanggan.json
sudo touch /var/log/freeradius/sync-decisions.log /var/log/freeradius/debug-radius-web.log /var/log/freeradius/request-log-history.json
sudo chmod 666 /var/log/freeradius/sync-decisions.log /var/log/freeradius/debug-radius-web.log /var/log/freeradius/request-log-history.json

# 7. Grup freerad + www-data jadi anggotanya
sudo groupadd freerad 2>/dev/null || true
sudo usermod -aG freerad www-data

# 8. WAJIB: restart PHP-FPM/Apache/Nginx supaya keanggotaan grup freerad efektif
sudo systemctl restart apache2 2>/dev/null || sudo systemctl restart nginx 2>/dev/null || true
sudo systemctl restart php*-fpm 2>/dev/null || true

# 9. Validasi & restart FreeRADIUS
sudo freeradius -X -C
sudo systemctl restart freeradius
sudo systemctl enable freeradius
            </textarea>
        </div>

        <hr>
        <h5>Sudoers (wajib untuk tombol otomatis di halaman ini)</h5>
        <p class="text-muted">Semua tombol di halaman FreeRADIUS ini (bukan cuma panel Setup) menjalankan perintah lewat <code>sudo</code> tanpa password. Tambahkan baris ini via <code>visudo</code> (sesuaikan kebijakan keamanan server Anda -- idealnya batasi lebih spesifik daripada mengizinkan seluruh binary):</p>
        <pre class="bg-dark text-light p-2 rounded" style="font-size:0.8em; white-space:pre-wrap;">www-data ALL=(ALL) NOPASSWD: /bin/mkdir, /bin/chmod, /bin/chown, /bin/touch, /bin/cp, /bin/mv, /bin/rm, /bin/ln, /bin/find, /bin/cat, /bin/kill, /bin/tee, /usr/bin/tee, /usr/sbin/usermod, /usr/sbin/groupadd, /usr/sbin/freeradius, /usr/bin/freeradius, /bin/systemctl, /usr/bin/systemctl, /usr/bin/crontab</pre>
        <p class="text-muted">Kalau <code>which freeradius</code> atau <code>which systemctl</code> di server Anda menunjuk ke path lain, sesuaikan baris di atas -- "Cek Status Lengkap" menampilkan baris <strong>"sudo tanpa password untuk www-data"</strong> yang langsung memberi tahu kalau sudoers ini belum benar.</p>
        <p class="text-muted mb-0">Selalu pastikan ada backup sebelum mengedit langsung file <code>/etc/freeradius/3.0/sites-enabled/default</code> di luar halaman ini.</p>
    </div>
</div>

<?php require 'footer.php'; ?>
