<?php


require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('API_Intergrasi', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu API Integration.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once 'api/_bootstrap.php';

$isAdminAccess = isset($AKSES) && strtoupper(trim((string)$AKSES)) === 'ADMIN';

// === HANDLER REGENERATE API KEY ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_key'])) {
    $new_key = bin2hex(random_bytes(16));
    $sql_check = "SELECT 1 FROM apikey WHERE user_name = '" . mysqli_real_escape_string($conn, $ceknama) . "' LIMIT 1";
    $result_check = mysqli_query($conn, $sql_check);
    if ($result_check && mysqli_num_rows($result_check) > 0) {
        $stmt = $conn->prepare('UPDATE apikey SET api_key = ? WHERE user_name = ?');
        $stmt->bind_param('ss', $new_key, $ceknama);
        $keyRegenOk = $stmt->execute();
    } else {
        $stmt = $conn->prepare('INSERT INTO apikey (user_name, api_key) VALUES (?, ?)');
        $stmt->bind_param('ss', $ceknama, $new_key);
        $keyRegenOk = $stmt->execute();
    }
    $keyRegenMessage = $keyRegenOk
        ? 'API Key baru berhasil di-regenerate: <strong>' . htmlspecialchars($new_key) . '</strong>'
        : 'Gagal regenerate API Key: ' . htmlspecialchars($conn->error);
}

// === HANDLER TOGGLE MODUL API (ADMIN only) ===
$moduleToggleMessage = '';
if ($isAdminAccess && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_module_toggles'])) {
    api_ensure_module_settings_table($conn);
    $insertCols = array_values(API_MODULE_COLUMNS);
    $setParts = array_map(function ($col) {
        return "$col = ?";
    }, $insertCols);
    $stmt = $conn->prepare(
        'INSERT INTO api_module_settings (owner, ' . implode(', ', $insertCols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($insertCols), '?')) . ')
         ON DUPLICATE KEY UPDATE ' . implode(', ', $setParts)
    );
    $bindTypes = 's' . str_repeat('i', count($insertCols)) . str_repeat('i', count($insertCols));
    $insertValues = [];
    foreach (API_MODULE_COLUMNS as $key => $col) {
        $insertValues[] = isset($_POST['module_' . $key]) ? 1 : 0;
    }
    $bindParams = array_merge([$ceknama], $insertValues, $insertValues);
    $refs = [$bindTypes];
    foreach ($bindParams as $k => $v) {
        $refs[] = &$bindParams[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $moduleToggleOk = $stmt->execute();
    $moduleToggleMessage = $moduleToggleOk ? 'Pengaturan modul API berhasil disimpan.' : ('Gagal menyimpan: ' . $stmt->error);
}

// === AMBIL API KEY SAAT INI ===
$sql = "SELECT api_key FROM apikey WHERE user_name = '" . mysqli_real_escape_string($conn, $ceknama) . "' LIMIT 1";
$result = mysqli_query($conn, $sql);
$current_api_key = '';
if ($result && $row = mysqli_fetch_assoc($result)) {
    $current_api_key = $row['api_key'];
}

// === AMBIL STATUS TOGGLE MODUL SAAT INI ===
$moduleSettingsRow = api_module_settings_row($conn, $ceknama);

$moduleLabels = [
    'pelanggan'      => 'Pelanggan (Customer PPPoE/Hotspot)',
    'area_server'    => 'Area Server',
    'odp'            => 'ODP',
    'paket'          => 'Paket (PPPoE + Hotspot)',
    'tiket'          => 'Tiket (Instalasi/Maintenance/Migrasi/Dismantle)',
    'user_assistant' => 'User Assistant',
    'wabot'          => 'WhatsApp Bot',
    'transaksi'      => 'Transaksi',
    'pool'           => 'IP Pool',
    'vlan'           => 'VLAN',
    'log'            => 'Log Billing (read-only)',
];

$baseApiUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/crm/billing/api/';
$displayKey = $current_api_key ?: 'YOUR_API_KEY';

// --- Tabs: "settings" + one per module. Server-side ?tab= switching only, no JavaScript. ---
$tabs = [
    'settings'       => 'API Settings',
    'server'         => 'Server',
    'odp'            => 'ODP',
    'paket'          => 'Paket',
    'pelanggan'      => 'Pelanggan',
    'tiket'          => 'Tiket',
    'user_assistant' => 'User Assistant',
    'wabot'          => 'WhatsApp Bot',
    'transaksi'      => 'Transaksi',
    'ip_pool'        => 'IP Pool',
    'vlan'           => 'VLAN',
    'log'            => 'Log Billing',
    'backup'         => 'Backup/Restore',
];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'settings';

/** Auth block markup, identical across every module doc tab. */
function docAuthNote($displayKey) {
    $displayKey = htmlspecialchars($displayKey);
    return "<p class=\"text-muted\">Autentikasi: session login, atau parameter <code>username</code>+<code>password</code>, atau parameter <code>key</code> (API Key = <code>$displayKey</code>, kena rate limit 100 request/jam). Response selalu JSON <code>{\"success\": true/false, ...}</code>.</p>";
}
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>Pengaturan API</h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="container mt-4">

                        <style>
                            #apiSettingsTabsContent pre {
                                position: relative;
                                background: #1e1e2e;
                                color: #cdd6f4;
                                padding: 34px 16px 16px;
                                border-radius: 10px;
                                overflow-x: auto;
                                margin-bottom: 1.25rem;
                                font-size: 13px;
                                line-height: 1.6;
                                border: 1px solid #313244;
                                box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
                            }
                            #apiSettingsTabsContent pre::before {
                                content: "";
                                position: absolute;
                                top: 12px;
                                left: 14px;
                                width: 11px;
                                height: 11px;
                                border-radius: 50%;
                                background: #ff5f56;
                                box-shadow: 18px 0 0 #ffbd2e, 36px 0 0 #27c93f;
                            }
                            #apiSettingsTabsContent pre code {
                                background: none;
                                color: inherit;
                                padding: 0;
                                font-size: inherit;
                                white-space: pre;
                            }
                            #apiSettingsTabsContent p code,
                            #apiSettingsTabsContent td code,
                            #apiSettingsTabsContent th code,
                            #apiSettingsTabsContent li code {
                                background: #eef2f7;
                                color: #c7254e;
                                padding: 2px 5px;
                                border-radius: 4px;
                                font-size: 90%;
                            }
                            #apiSettingsTabsContent h6 {
                                margin-top: 1.25rem;
                                font-weight: 700;
                            }
                            #apiSettingsTabsContent .code-label {
                                display: inline-block;
                                font-size: 11px;
                                font-weight: 700;
                                letter-spacing: 0.06em;
                                text-transform: uppercase;
                                padding: 3px 10px;
                                border-radius: 5px 5px 0 0;
                                margin: 0 0 -6px 4px;
                                position: relative;
                                z-index: 1;
                            }
                            #apiSettingsTabsContent .code-label-request {
                                background: #2563eb;
                                color: #fff;
                            }
                            #apiSettingsTabsContent .code-label-response {
                                background: #16a34a;
                                color: #fff;
                            }
                        </style>

                        <ul class="nav nav-tabs flex-wrap" id="apiSettingsTabs" role="tablist">
                            <?php foreach ($tabs as $key => $label): ?>
                                <li class="nav-item" role="presentation">
                                    <a class="nav-link<?= $activeTab === $key ? ' active' : '' ?>" href="?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label) ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <div class="pt-4" id="apiSettingsTabsContent">

                        <!-- ============================================================ -->
                        <!-- TAB: API SETTINGS -->
                        <!-- ============================================================ -->
                        <div id="tab-settings" style="<?= $activeTab === 'settings' ? '' : 'display:none;' ?>">

                            <?php if (!empty($keyRegenMessage)): ?>
                                <div class="alert alert-<?= $keyRegenOk ? 'success' : 'danger' ?>"><?= $keyRegenMessage ?></div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="api_key" class="form-label">API Key Anda</label>
                                <input type="text" class="form-control" id="api_key" value="<?php echo htmlspecialchars($current_api_key); ?>" readonly>
                                <div class="form-text">API Key ini unik untuk user Anda dan digunakan untuk autentikasi via parameter <code>key</code> pada endpoint di folder <code>api/</code>.</div>
                            </div>
                            <form method='POST' action='?tab=settings'>
                                <button type="submit" name="regenerate_key" class="btn btn-warning">Regenerate API Key Baru</button>
                            </form>

                            <hr>

                            <?php if ($isAdminAccess): ?>
                                <h5>Kontrol Modul API</h5>
                                <p class="text-muted">Nonaktifkan modul tertentu untuk membatasi akses via API tanpa mempengaruhi akses lewat website. Berlaku untuk akun ini dan sub-akun ASSISTANT di bawahnya. Modul yang tidak dicentang akan membalas <code>403</code> saat diakses lewat API.</p>

                                <?php if ($moduleToggleMessage !== ''): ?>
                                    <div class="alert alert-<?= $moduleToggleOk ? 'success' : 'danger' ?>"><?= htmlspecialchars($moduleToggleMessage) ?></div>
                                <?php endif; ?>

                                <form method="POST" action="?tab=settings">
                                    <div class="row">
                                        <?php foreach ($moduleLabels as $key => $label): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox"
                                                           id="module_<?= htmlspecialchars($key) ?>"
                                                           name="module_<?= htmlspecialchars($key) ?>"
                                                           value="1"
                                                        <?= (!$moduleSettingsRow || (int)($moduleSettingsRow[API_MODULE_COLUMNS[$key]] ?? 1) === 1) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="module_<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="submit" name="save_module_toggles" class="btn btn-primary">Simpan Pengaturan Modul</button>
                                </form>

                                <hr>
                                <h5>API Settings (Global)</h5>
                                <p>Konfigurasi CORS, timeout, dan rate limiting berlaku sama untuk semua endpoint di folder <code>api/</code> lewat <code>api/_bootstrap.php</code>.</p>
                                <div class="mb-3">
                                    <label class="form-label">CORS Origin</label>
                                    <input type="text" class="form-control" value="*" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Rate Limit per Jam (khusus akses via API Key)</label>
                                    <input type="number" class="form-control" value="100" readonly>
                                </div>
                            <?php endif; ?>

                            <hr>
                            <h5>Cara Menggunakan API</h5>
                            <p>Setiap endpoint ada di folder <code>api/</code>, satu file per modul. Klik tab di atas untuk lihat dokumentasi CRUD lengkap masing-masing modul (kolom, parameter, contoh request/response).</p>
                            <p><strong>Contoh Request dengan cURL:</strong></p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>server.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>

                            <h6>Kontrol Akses per Modul</h6>
                            <p>Modul yang dinonaktifkan admin akan membalas:</p>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": false, "error": "Modul API \"wabot\" dinonaktifkan oleh admin untuk akun ini" }</code></pre>
                            <p>dengan HTTP status <code>403</code>.</p>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: SERVER -->
                        <!-- ============================================================ -->
                        <div id="tab-server" style="<?= $activeTab === 'server' ? '' : 'display:none;' ?>">
                            <h5>Server &mdash; <code>server.php</code></h5>
                            <p>Data server billing lengkap: IP, password MikroTik, area, brand. Dibatasi ke server milik akun (<code>user_id</code>).</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom:</strong> <code>id, IP, PASSWORD, AREA, MIK80, PEMILIK, BRAND, user_id</code> (+ <code>online</code> hasil ping, hanya di response GET)</p>

                            <h6>GET &mdash; list server milik akun</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>server.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{
  "success": true,
  "data": [
    { "ID": 1, "IP": "192.168.1.1:8728", "AREA": "Bekasi", "MIK80": "", "PEMILIK": "FIBERQ", "BRAND": "FIBERQ", "user_id": 1, "online": true }
  ]
}</code></pre>

                            <h6>POST &mdash; tambah server</h6>
                            <p>Body wajib: <code>ip, password, area, mik80, brand, user_id</code>. <code>pemilik</code> otomatis dari akun yang login.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>server.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","ip":"192.168.1.2:8728","password":"xxx","area":"Bekasi Utara","mik80":"","brand":"FIBERQ","user_id":1}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6>PUT &mdash; ubah server</h6>
                            <p>Body wajib: <code>id, ip, password, area, mik80, brand, user_id</code> (semua kolom utama wajib dikirim ulang).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>server.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2,"ip":"192.168.1.2:8728","password":"xxx","area":"Bekasi Utara 2","mik80":"","brand":"FIBERQ","user_id":1}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6>DELETE &mdash; hapus server</h6>
                            <p>Body wajib: <code>id</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>server.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: ODP -->
                        <!-- ============================================================ -->
                        <div id="tab-odp" style="<?= $activeTab === 'odp' ? '' : 'display:none;' ?>">
                            <h5>ODP (Optical Distribution Point) &mdash; <code>odp.php</code></h5>
                            <p>Satu ODP bisa terhubung ke beberapa "Server Area" (kombinasi PEMILIK+AREA server).</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom:</strong> <code>id, NAME, KODE, PORT, PEMILIK, AREA, TIKOR, BRAND, Hirarki, splitter, hirarki_parent, FOTO</code> + <code>products</code> (array pemilik/area/brand, hasil join <code>odp_server</code>)</p>
                            <p><strong>Nilai valid:</strong> <code>Hirarki</code>: ODC, ODP, ODP-RASIO, ODP-JUMPER &middot; <code>splitter</code>: 1:2, 1:4, 1:8, 1:16, 1:32</p>

                            <h6>GET &mdash; list (dengan filter) / detail</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Parameter</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>id</code></td><td>Tidak</td><td>Kalau diisi, balikan cuma 1 ODP (detail lengkap + products), parameter filter lain diabaikan</td></tr>
                                    <tr><td><code>hirarki</code></td><td>Tidak</td><td>Filter exact-match kolom <code>Hirarki</code> (ODC/ODP/ODP-RASIO/ODP-JUMPER). Kosongkan atau isi <code>Semua</code> untuk tanpa filter</td></tr>
                                    <tr><td><code>area</code></td><td>Tidak</td><td>Filter exact-match kolom <code>AREA</code></td></tr>
                                    <tr><td><code>splitter</code></td><td>Tidak</td><td>Filter exact-match kolom <code>splitter</code> (1:2/1:4/1:8/1:16/1:32)</td></tr>
                                    <tr><td><code>search</code></td><td>Tidak</td><td>Cari teks bebas di kolom <code>KODE</code> atau <code>NAME</code> (LIKE %search%)</td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>odp.php?key=<?= htmlspecialchars($displayKey) ?>&area=Bekasi"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{
  "success": true,
  "total": 1,
  "data": [
    { "id": 5, "NAME": "ODP Merdeka 1", "KODE": "ODP-001", "PEMILIK": "FIBERQ", "AREA": "Bekasi",
      "TIKOR": "-6.2,106.9", "BRAND": "FIBERQ", "Hirarki": "ODP", "splitter": "1:8",
      "products": [{ "pemilik": "FIBERQ", "area": "Bekasi", "brand": "FIBERQ" }] }
  ]
}</code></pre>

                            <h6>POST &mdash; tambah ODP</h6>
                            <p>Body wajib: <code>kode, name, tikor</code> (atau <code>coordinates</code>), plus minimal 1 Server Area: <code>products: [{"pemilik":"...","area":"..."}]</code> (atau cukup <code>area</code> di top-level, pemilik ikut akun login). Opsional: <code>hirarki, splitter, hirarki_parent, foto</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>odp.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","kode":"ODP-002","name":"ODP Merdeka 2","tikor":"-6.2,106.9","area":"Bekasi","hirarki":"ODP","splitter":"1:8","hirarki_parent":"ODC-001"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 6, "data": { "id": 6, "KODE": "ODP-002", "NAME": "ODP Merdeka 2", "products": [...] } }</code></pre>

                            <h6>PUT &mdash; ubah ODP</h6>
                            <p>Body wajib: <code>id</code>. Opsional: <code>kode, name, tikor, hirarki, splitter, hirarki_parent, foto, products</code> (kirim <code>products</code> untuk mengganti daftar Server Area, kalau tidak dikirim daftar lama dipertahankan).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>odp.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":6,"splitter":"1:16"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "id": 6, "splitter": "1:16", "products": [...] } }</code></pre>

                            <h6>DELETE &mdash; hapus ODP</h6>
                            <p>Body wajib: <code>id</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>odp.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":6}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: PAKET -->
                        <!-- ============================================================ -->
                        <div id="tab-paket" style="<?= $activeTab === 'paket' ? '' : 'display:none;' ?>">
                            <h5>Paket &mdash; <code>paket.php</code> (PPPoE) &amp; <code>paket_hotspot.php</code> (Hotspot)</h5>
                            <?= docAuthNote($displayKey) ?>

                            <h6>Paket PPPoE &mdash; <code>paket.php</code></h6>
                            <p><strong>Kolom:</strong> <code>id, PAKET, KODE, KECEPATAN, LOCAL, REMOTE, HARGA, komisi, AREA, PEMILIK, BRAND</code></p>
                            <p><strong>GET</strong> &mdash; filter opsional: <code>id, area, search</code> (cari PAKET/KECEPATAN/KODE).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>paket.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "id": 1, "PAKET": "Paket 10Mbps", "KECEPATAN": "10M/10M", "HARGA": "150000", "AREA": "Bekasi", "PEMILIK": "FIBERQ" } ] }</code></pre>
                            <p><strong>POST</strong> &mdash; body: <code>paket, kecepatan, harga, local, remote, area, kode</code> (opsional, default "-"), <code>komisi</code> (opsional, default 0).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>paket.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","paket":"Paket 20Mbps","kecepatan":"20M/20M","harga":"200000","local":"10.10.1.1","remote":"10.10.1.2","area":"Bekasi"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 5 }</code></pre>
                            <p><strong>PUT</strong> &mdash; body wajib <code>id</code>, field lain sama seperti POST (hanya yang dikirim yang diubah).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>paket.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":5,"harga":"210000"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                            <p><strong>DELETE</strong> &mdash; body wajib <code>id</code> (ditolak kalau masih dipakai pelanggan).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>paket.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":5}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6>Paket Hotspot &mdash; <code>paket_hotspot.php</code></h6>
                            <p><strong>Kolom:</strong> <code>id, paket, uptime, ratelimit, harga, komisi, area, pemilik, BRAND</code> (perhatikan: nama kolom lowercase kecuali BRAND)</p>
                            <p><strong>GET</strong> &mdash; filter opsional: <code>id, area, search</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>paket_hotspot.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "id": 1, "paket": "Hotspot 1 Jam", "uptime": "1h", "ratelimit": "5M/5M", "harga": "5000", "area": "Bekasi" } ] }</code></pre>
                            <p><strong>POST</strong> &mdash; body: <code>paket, uptime, ratelimit, harga, area</code> (wajib), <code>komisi</code> (opsional).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>paket_hotspot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","paket":"Hotspot 2 Jam","uptime":"2h","ratelimit":"5M/5M","harga":"9000","area":"Bekasi"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 2 }</code></pre>
                            <p><strong>PUT</strong> &mdash; body wajib <code>id</code>, field lain sama seperti POST (hanya yang dikirim yang diubah).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>paket_hotspot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2,"harga":"10000"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                            <p><strong>DELETE</strong> &mdash; body wajib <code>id</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>paket_hotspot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: PELANGGAN -->
                        <!-- ============================================================ -->
                        <div id="tab-pelanggan" style="<?= $activeTab === 'pelanggan' ? '' : 'display:none;' ?>">
                            <h5>Pelanggan (Customer PPPoE/Hotspot) &mdash; <code>pelanggan.php</code></h5>
                            <p>CRUD pelanggan lengkap + aktivasi/nonaktifkan langganan (push ke MikroTik &amp; FreeRADIUS sungguhan, plus catat pembayaran ke <code>transaksi</code>). Pengganti <code>apiinterface.php</code> lama untuk <code>customers_table</code>, <code>active_customers</code>, <code>add_customer</code>, <code>edit_customer</code>, <code>delete_customer</code>, <code>activate_customer</code>, <code>disable_customer</code> (dua yang terakhir dulu cuma stub kosong di <code>apiinterface.php</code> - di sini sudah logika asli).</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom:</strong> <code>id, IDPEL, NAMA, PASSWORD, ALAMAT, PAKET, HARGA, TANGGALPASANG, NOWA, EMAIL, TEMPO, PEMILIK, MODE, ODP, AREA, TIKOR, sales, BRAND, STATUS, TIPE_BAYAR, TIPE_TEMPO, provinsi, kabupaten, kecamatan, kelurahan, rw, rt</code></p>

                            <h6>GET &mdash; list / detail / sesi aktif</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Parameter</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>action=active_sessions</code></td><td>Tidak</td><td>Alih-alih list pelanggan, balikan sesi PPPoE yang sedang online (dari <code>radacct</code>). Alias: <code>active_customers</code>, <code>hotspot_active</code>, <code>hotspot_table</code></td></tr>
                                    <tr><td><code>id</code></td><td>Tidak</td><td>Ambil 1 pelanggan berdasarkan ID</td></tr>
                                    <tr><td><code>idpel</code></td><td>Tidak</td><td>Ambil 1 pelanggan berdasarkan ID Pelanggan (exact match)</td></tr>
                                    <tr><td><code>paket</code>, <code>area</code>, <code>status</code>, <code>odp</code></td><td>Tidak</td><td>Filter exact-match masing-masing kolom</td></tr>
                                    <tr><td><code>q</code></td><td>Tidak</td><td>Cari teks bebas di NAMA/IDPEL/ALAMAT/NOWA sekaligus</td></tr>
                                    <tr><td><code>limit</code></td><td>Tidak</td><td>Jumlah data per halaman, default 500, maksimal 1000</td></tr>
                                    <tr><td><code>offset</code></td><td>Tidak</td><td>Lompat data untuk pagination, default 0</td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>pelanggan.php?key=<?= htmlspecialchars($displayKey) ?>&status=aktif&area=Bekasi"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "id": 10, "IDPEL": "FQ-001", "NAMA": "Budi", "PAKET": "Paket 10Mbps", "AREA": "Bekasi", "STATUS": "aktif", "NOWA": "628123456789" } ], "total": 1, "limit": 500, "offset": 0 }</code></pre>

                            <p><strong><code>action=active_sessions</code></strong> &mdash; sesi PPPoE online saat ini (dari FreeRADIUS <code>radacct</code>).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>pelanggan.php?key=<?= htmlspecialchars($displayKey) ?>&action=active_sessions"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "username": "FQ-001", "framedipaddress": "10.10.1.5", "acctstarttime": "2026-07-16 08:00:00", "acctsessiontime": "7200" } ] }</code></pre>

                            <h6>POST &mdash; tambah pelanggan baru</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Field</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>idpel</code> (atau <code>customerID</code>)</td><td>Ya</td><td>ID Pelanggan, harus unik</td></tr>
                                    <tr><td><code>nama</code> (atau <code>customerName</code>)</td><td>Ya</td><td>Nama pelanggan</td></tr>
                                    <tr><td><code>alamat</code> (atau <code>address</code>)</td><td>Ya</td><td>Alamat lengkap</td></tr>
                                    <tr><td><code>paket</code> (atau <code>packages</code>)</td><td>Ya</td><td>Nama paket langganan</td></tr>
                                    <tr><td><code>pemilik</code> (atau <code>server</code>)</td><td>Ya</td><td>PEMILIK server tujuan - harus salah satu server milik akun ini</td></tr>
                                    <tr><td><code>area</code></td><td>Ya</td><td>Area server</td></tr>
                                    <tr><td><code>odp</code></td><td>Ya</td><td>Kode ODP tempat pelanggan terpasang</td></tr>
                                    <tr><td><code>password</code> (atau <code>passwordPPPOE</code>)</td><td>Tidak</td><td>Password PPPoE</td></tr>
                                    <tr><td><code>whatsapp</code> (atau <code>nowa</code>)</td><td>Tidak</td><td>Nomor WA, otomatis dinormalisasi ke format 62xxx</td></tr>
                                    <tr><td><code>email, tikor/coordinates, sales, brand, tipe_bayar, tipe_tempo, harga, tanggalpasang, tempo, mode/authmode, status, provinsi, kabupaten, kecamatan, kelurahan, rw, rt</code></td><td>Tidak</td><td>Field tambahan, semua opsional</td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>pelanggan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","idpel":"FQ-002","nama":"Siti","alamat":"Jl. Merdeka No.10","paket":"Paket 10Mbps","pemilik":"FIBERQ","area":"Bekasi","odp":"ODP-001","whatsapp":"08123456789"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 11, "data": { "id": 11, "IDPEL": "FQ-002", "NAMA": "Siti", "NOWA": "628123456789", "STATUS": "" } }</code></pre>

                            <h6>PUT &mdash; ubah data pelanggan</h6>
                            <p>Body wajib: <code>id</code> atau <code>idpel</code>. Field lain sama seperti POST (hanya yang dikirim yang diubah). Ganti <code>idpel</code> pakai <code>new_idpel</code> (dicek duplikat dulu). Ganti pemilik server pakai <code>pemilik</code>/<code>server</code> (harus salah satu server milik akun ini).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>pelanggan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":11,"paket":"Paket 20Mbps","harga":"200000"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "id": 11, "PAKET": "Paket 20Mbps", "HARGA": "200000" } }</code></pre>

                            <h6><code>action=activate</code> (POST) &mdash; aktivasi langganan sungguhan</h6>
                            <p>Body wajib: <code>id</code> atau <code>idpel</code>. Opsional: <code>periode</code> (default bulan berjalan), <code>metode_bayar</code> (cash/transfer/gagal payment gateway/kompensasi_free, default cash), <code>bukti</code> (path/URL bukti bayar). Efeknya nyata: set profile PPPoE MikroTik ke paket berbayar + enable + putus sesi lama, catat/replace baris <code>transaksi</code> jadi BERHASIL, update grup FreeRADIUS. Notifikasi WA sengaja tidak dikirim (butuh state sesi web yang tidak ada di API).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>pelanggan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"activate","idpel":"FQ-002","metode_bayar":"transfer"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "idpel": "FQ-002", "servers_checked": 1, "mikrotik_connected": true, "mikrotik_results": [{"server":"192.168.1.1","connected":true}], "transaksi": "inserted", "periode": "Juli 2026", "freeradius_updated": true }, "notes": ["WA notification skipped - see source comment in api/pelanggan.php"] }</code></pre>

                            <h6><code>action=disable</code> (POST) &mdash; nonaktifkan langganan sungguhan</h6>
                            <p>Body wajib: <code>id</code> atau <code>idpel</code>. Efeknya nyata: set profile PPPoE MikroTik ke EXPIRED + putus sesi aktif, update grup FreeRADIUS. Tidak menyentuh <code>transaksi</code> (sama seperti versi web).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>pelanggan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"disable","idpel":"FQ-002"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "idpel": "FQ-002", "servers_checked": 1, "mikrotik_connected": true, "mikrotik_results": [{"server":"192.168.1.1","connected":true,"found_secret":true,"profile_set":true,"active_removed":1}], "freeradius_updated": true }, "notes": ["WA notification skipped - see source comment in api/pelanggan.php"] }</code></pre>

                            <h6>DELETE &mdash; hapus pelanggan</h6>
                            <p>Body wajib: <code>id</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>pelanggan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":11}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: TIKET -->
                        <!-- ============================================================ -->
                        <div id="tab-tiket" style="<?= $activeTab === 'tiket' ? '' : 'display:none;' ?>">
                            <h5>Tiket &mdash; <code>tiket_manager.php</code></h5>
                            <p>Instalasi / Maintenance / Migrasi / Dismantle, status apa saja. Otomatis mengarah ke tabel <code>billing_tiket_manager</code> atau <code>joblist</code> tergantung setting akun (<code>ticket_management_source</code>) &mdash; field <code>source</code> di setiap baris menunjukkan asalnya.</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom (mode tiket_manager):</strong> <code>id, judul, detail, server_id, pemilik, brand, area, project_name, tipe, report, status, teknisi_user_id, created_by_user_id, done_at, created_at, updated_at, source</code></p>
                            <p><strong>Nilai valid:</strong> <code>tipe</code>: INSTALLASI, MAINTENANCE, MIGRASI, DISMANTLE &middot; <code>status</code>: BARU, PENDING, DONE, CANCEL</p>

                            <h6>GET &mdash; list (status/tipe apa saja)</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Parameter</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>id</code></td><td>Tidak</td><td>Ambil 1 tiket spesifik berdasarkan ID</td></tr>
                                    <tr><td><code>status</code></td><td>Tidak</td><td>Default <code>ALL</code> (tanpa filter). Isi salah satu: BARU, PENDING, DONE, CANCEL</td></tr>
                                    <tr><td><code>tipe</code></td><td>Tidak</td><td>Default <code>ALL</code> (tanpa filter). Isi salah satu: INSTALLASI, MAINTENANCE, MIGRASI, DISMANTLE</td></tr>
                                    <tr><td><code>server_id</code></td><td>Tidak</td><td>Filter berdasarkan server tertentu (harus milik akun ini)</td></tr>
                                    <tr><td><code>teknisi_user_id</code></td><td>Tidak</td><td>Filter berdasarkan teknisi yang ditugaskan (kolom <code>teknisi_user_id</code>)</td></tr>
                                    <tr><td><code>brand</code>, <code>area</code>, <code>project_name</code></td><td>Tidak</td><td>Filter exact-match masing-masing kolom</td></tr>
                                    <tr><td><code>q</code></td><td>Tidak</td><td>Cari teks bebas di kolom <code>judul</code>, <code>detail</code>, atau <code>report</code></td></tr>
                                    <tr><td><code>date_from</code>, <code>date_to</code></td><td>Tidak</td><td>Filter rentang tanggal <code>created_at</code>, format <code>YYYY-MM-DD</code></td></tr>
                                    <tr><td><code>limit</code></td><td>Tidak</td><td>Jumlah data per halaman, default 200, maksimal 500</td></tr>
                                    <tr><td><code>offset</code></td><td>Tidak</td><td>Lompat data untuk pagination, default 0</td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>tiket_manager.php?key=<?= htmlspecialchars($displayKey) ?>&status=ALL&tipe=MAINTENANCE"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "id": 12, "judul": "Maintenance ODP", "tipe": "MAINTENANCE", "status": "PENDING", "source": "tiket_manager" } ], "total": 1, "limit": 200, "offset": 0 }</code></pre>

                            <h6>POST &mdash; tambah tiket</h6>
                            <p>Body wajib: <code>server_id, judul</code>. Opsional: <code>detail, tipe</code> (default INSTALLASI), <code>report, status</code> (default BARU), <code>teknisi_user_id</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>tiket_manager.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","server_id":1,"judul":"Instalasi baru","tipe":"INSTALLASI"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 13, "data": { "id": 13, "judul": "Instalasi baru", "status": "BARU", "source": "tiket_manager" } }</code></pre>

                            <h6>PUT &mdash; ubah tiket</h6>
                            <p>Body wajib: <code>id</code>. Opsional: <code>judul, detail, report, tipe, status, teknisi_user_id, server_id</code> (hanya field yang dikirim yang diubah).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>tiket_manager.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":12,"status":"DONE","report":"Selesai"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "id": 12, "status": "DONE", "done_at": "2026-07-16 10:00:00" } }</code></pre>

                            <h6>DELETE &mdash; hapus tiket</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>tiket_manager.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":12}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                            <p class="text-muted">Dokumentasi lebih detail (termasuk mode <code>joblist</code>): lihat file <code>api/DOKUMENTASI_TIKET_MANAGER_API.txt</code>.</p>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: USER ASSISTANT -->
                        <!-- ============================================================ -->
                        <div id="tab-user_assistant" style="<?= $activeTab === 'user_assistant' ? '' : 'display:none;' ?>">
                            <h5>User Assistant &mdash; <code>user_assistant.php</code></h5>
                            <p>Kelola sub-akun teknisi/CS (STATUS=ASSISTANT) di bawah akun ini. Hanya akun OWNER (bukan ASSISTANT) yang boleh memanggil endpoint ini.</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom (PASWORD tidak pernah dikembalikan):</strong> <code>id, USERNAME, STATUS, grup, NOWA, domain (email), server (array id server), akses (array menu), saldo, created_at</code></p>

                            <h6>GET &mdash; list / detail</h6>
                            <p>Parameter opsional: <code>id</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>user_assistant.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "total": 1, "data": [ { "id": 5, "USERNAME": "teknisi1", "STATUS": "ASSISTANT", "server": [1,2], "akses": ["Ticket_create"] } ] }</code></pre>

                            <h6>POST &mdash; tambah ASSISTANT</h6>
                            <p>Body wajib: <code>username, password</code>. Opsional: <code>nowa, email, server: [id,...]</code> (harus subset server milik akun ini), <code>akses: [...]</code> (atau <code>menu</code>).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>user_assistant.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","username":"teknisi2","password":"rahasia123","server":[1],"akses":["Ticket_create"]}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 6, "data": { "id": 6, "USERNAME": "teknisi2", "server": [1], "akses": ["Ticket_create"] } }</code></pre>

                            <h6>PUT &mdash; ubah ASSISTANT</h6>
                            <p>Body wajib: <code>id</code>. Opsional: <code>username, password</code> (kosongkan untuk tidak diubah), <code>nowa, email, server, akses/menu</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>user_assistant.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":6,"server":[1,2]}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "id": 6, "server": [1,2] } }</code></pre>

                            <h6>DELETE &mdash; hapus ASSISTANT</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>user_assistant.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":6}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: WHATSAPP BOT -->
                        <!-- ============================================================ -->
                        <div id="tab-wabot" style="<?= $activeTab === 'wabot' ? '' : 'display:none;' ?>">
                            <h5>WhatsApp Bot &mdash; <code>wabot.php</code>, <code>wabot_integrations.php</code>, <code>wabot_settings.php</code></h5>
                            <p>Tiga file: bot CRUD + kontrol, integrasi gateway WA Resmi/Unofficial, dan setting global (ADMIN). Semua pakai routing <code>action=</code> (bukan REST murni) karena satu modul mencakup banyak operasi.</p>
                            <?= docAuthNote($displayKey) ?>

                            <h6>1) <code>wabot.php</code> &mdash; bot CRUD, service, device actions</h6>
                            <p><strong>Kolom bot (tabel <code>botwa</code>):</strong> <code>id, namebot, addressbot, webhook, password, pemilik, sender, penerima, technical_menu_enabled, allow_read_server, allow_read_customer, allow_create_payment_code, tipe_bot</code></p>
                            <table class="table table-striped">
                                <thead><tr><th>action</th><th>Method</th><th>Body/Query</th></tr></thead>
                                <tbody>
                                    <tr><td>(kosong)</td><td>GET</td><td>List bot milik akun, atau <code>?id=</code> untuk satu bot</td></tr>
                                    <tr><td><code>create</code></td><td>POST</td><td><code>botname, botpass, area, server, botversion, deploy_mode</code> (+ <code>ssh_ip/ssh_user/ssh_pass</code> jika <code>deploy_mode=outside</code>) &mdash; provisioning Docker + MikroTik NAT sungguhan</td></tr>
                                    <tr><td><code>delete</code></td><td>POST</td><td><code>id</code> &mdash; stop Docker, hapus NAT rule</td></tr>
                                    <tr><td><code>start_service</code> / <code>stop_service</code></td><td>POST</td><td><code>id</code> &mdash; kontrol systemd auto-responder</td></tr>
                                    <tr><td><code>device_action</code></td><td>GET</td><td><code>device_action</code> (login/login-with-code/logout/reconnect), <code>addressbot</code>, <code>phone</code></td></tr>
                                    <tr><td><code>edit_sender</code></td><td>POST</td><td><code>id, sender</code></td></tr>
                                    <tr><td><code>save_access_permissions</code></td><td>POST</td><td><code>id, allow_read_server, allow_read_customer, allow_create_payment_code</code></td></tr>
                                    <tr><td><code>save_technical_menu_toggle</code></td><td>POST</td><td><code>id, technical_menu_enabled</code></td></tr>
                                    <tr><td><code>get_operational_hours</code> / <code>save_operational_hours</code></td><td>POST</td><td><code>id</code> (+ <code>enabled, start_time, end_time, timezone, days[], message_outside_hours, offline_mode</code> untuk save)</td></tr>
                                    <tr><td><code>get_prompt</code> / <code>save_prompt</code></td><td>POST</td><td><code>id</code> (+ <code>prompt</code> untuk save)</td></tr>
                                    <tr><td><code>test_message</code></td><td>POST</td><td><code>id, phone, message</code></td></tr>
                                    <tr><td><code>cleanup_orphans</code></td><td>POST</td><td>ADMIN only, tanpa body</td></tr>
                                </tbody>
                            </table>
                            <h6>GET &mdash; list bot</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>wabot.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "id": 3, "namebot": "BotFiberQ", "addressbot": "http://1.2.3.4:3005", "sender": "", "technical_menu_enabled": 1, "port": 3005, "service_status": "active" } ] }</code></pre>

                            <h6><code>action=create</code> &mdash; buat bot (provisioning Docker + MikroTik NAT)</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"create","botname":"BotFiberQ","botpass":"rahasia","botversion":"v8.4.0","deploy_mode":"inside"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 3, "container_id": "a1b2c3...", "namebot": "BotFiberQ", "port": 3005, "addressbot": "http://1.2.3.4:3005", "webhook": "https://.../index.php?id=whatsapp_3005", "cleanup": [] }</code></pre>

                            <h6><code>action=delete</code> &mdash; hapus bot</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"delete","id":3}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "message": "Bot BotFiberQ berhasil dihapus", "mikrotik": "mikrotik dstnat terhapus: 1" }</code></pre>

                            <h6><code>action=start_service</code> / <code>stop_service</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"start_service","id":3}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "message": "BOT whatsapp_3005 berhasil dijalankan." }</code></pre>

                            <h6><code>action=device_action</code> &mdash; proxy login/logout/reconnect (GET)</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>wabot.php?key=<?= htmlspecialchars($displayKey) ?>&action=device_action&device_action=login&addressbot=http://1.2.3.4:3005"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "http_code": 200, "target_url": "http://1.2.3.4:3005/app/login", "device_id": "device_a1b2c3d4e5f6", "upstream_json": { "code": "SUCCESS", "results": { "qr_link": "..." } } }</code></pre>

                            <h6><code>action=edit_sender</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"edit_sender","id":3,"sender":"628123456789"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=save_access_permissions</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"save_access_permissions","id":3,"allow_read_server":1,"allow_read_customer":1,"allow_create_payment_code":0}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=save_technical_menu_toggle</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"save_technical_menu_toggle","id":3,"technical_menu_enabled":0}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=get_operational_hours</code> / <code>save_operational_hours</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"get_operational_hours","id":3}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "enabled": true, "start_time": "08:00", "end_time": "17:00", "timezone": "Asia/Jakarta", "days": ["Senin","Selasa","Rabu","Kamis","Jumat"], "message_outside_hours": "Mohon maaf, di luar jam operasional.", "offline_mode": true } }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"save_operational_hours","id":3,"enabled":true,"start_time":"08:00","end_time":"17:00","timezone":"Asia/Jakarta","days":["Senin","Selasa","Rabu","Kamis","Jumat"],"message_outside_hours":"Mohon maaf, di luar jam operasional.","offline_mode":true}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=get_prompt</code> / <code>save_prompt</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"get_prompt","id":3}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "namebot": "BotFiberQ", "prompt": "Kamu adalah asisten CS FiberQ..." } }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"save_prompt","id":3,"prompt":"Kamu adalah asisten CS FiberQ yang ramah..."}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=test_message</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"test_message","id":3,"phone":"628123456789","message":"Tes pesan dari API"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "http_code": 200, "message": "Message successfully sent" }</code></pre>

                            <h6><code>action=cleanup_orphans</code> (ADMIN only)</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"cleanup_orphans"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "cleaned": [ "Service botrespon_whatsapp_3001.service dihapus (tidak ada di database)" ] }</code></pre>

                            <hr>
                            <h6>2) <code>wabot_integrations.php</code> &mdash; WA Resmi &amp; Unofficial</h6>
                            <p>Parameter <code>type=resmi</code> (Meta Cloud API/Qiscus/Custom) atau <code>type=unofficial</code> (Fonnte/Wablas/UltraMsg/Evolution/Gowa-external/Custom).</p>
                            <table class="table table-striped">
                                <thead><tr><th>action</th><th>Method</th><th>Body/Query</th></tr></thead>
                                <tbody>
                                    <tr><td>(kosong)</td><td>GET</td><td><code>type</code> &mdash; list integrasi + daftar bot yang bisa jadi target</td></tr>
                                    <tr><td><code>save</code></td><td>POST</td><td><code>type, id (0=baru), provider, nama_integrasi</code> + field sesuai provider (base_url, access_token, api_key, dst)</td></tr>
                                    <tr><td><code>activate</code></td><td>POST</td><td><code>type, id, target_botwa_id, new_bot_name</code></td></tr>
                                    <tr><td><code>deactivate</code></td><td>POST</td><td><code>type, id</code></td></tr>
                                    <tr><td><code>delete</code></td><td>POST</td><td><code>type, id</code></td></tr>
                                    <tr><td><code>test_send</code></td><td>POST</td><td><code>type, id, test_phone</code></td></tr>
                                </tbody>
                            </table>

                            <h6>GET &mdash; list integrasi</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>wabot_integrations.php?key=<?= htmlspecialchars($displayKey) ?>&type=resmi"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "type": "resmi", "data": [ { "id": 1, "nama_integrasi": "Meta Utama", "provider": "meta_cloud_api", "status": 1, "last_test_status": "sukses" } ], "available_providers": ["meta_cloud_api","qiscus","custom"], "bots": [ { "id": 3, "namebot": "BotFiberQ", "tipe_bot": "gowa" } ] }</code></pre>

                            <h6><code>action=save</code> &mdash; tambah/edit integrasi</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_integrations.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"save","type":"resmi","id":0,"provider":"meta_cloud_api","nama_integrasi":"Meta Utama","base_url":"https://graph.facebook.com","api_version":"v19.0","phone_number_id":"123456","access_token":"xxx"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 1 }</code></pre>

                            <h6><code>action=activate</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_integrations.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"activate","type":"resmi","id":1,"target_botwa_id":3}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=deactivate</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_integrations.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"deactivate","type":"resmi","id":1}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=delete</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_integrations.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"delete","type":"resmi","id":1}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=test_send</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_integrations.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"test_send","type":"resmi","id":1,"test_phone":"628123456789"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "message": "Terkirim" }</code></pre>

                            <hr>
                            <h6>3) <code>wabot_settings.php</code> &mdash; setting global (ADMIN only)</h6>
                            <table class="table table-striped">
                                <thead><tr><th>action</th><th>Method</th><th>Body/Query</th></tr></thead>
                                <tbody>
                                    <tr><td><code>database</code></td><td>GET/POST</td><td>POST: <code>db_host, db_user, db_pass, db_name, db_billing</code></td></tr>
                                    <tr><td><code>function</code></td><td>GET/POST</td><td>POST: <code>function_name, function_desc, function_file, function_enabled</code></td></tr>
                                    <tr><td><code>technical_menu_db</code></td><td>GET/POST</td><td>POST: <code>tech_db_host, tech_db_user, tech_db_pass, tech_db_name</code></td></tr>
                                    <tr><td><code>port_range</code></td><td>GET/POST</td><td>POST: <code>port_start_bot, port_end_bot</code> (3000-3999)</td></tr>
                                </tbody>
                            </table>

                            <h6><code>action=database</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php?key=<?= htmlspecialchars($displayKey) ?>&action=database"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "db_host": "localhost", "db_user": "qts", "db_name": "webhook_db", "db_billing": "billing_db" } }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"database","db_host":"localhost","db_user":"qts","db_pass":"xxx","db_name":"webhook_db","db_billing":"billing_db"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=function</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php?key=<?= htmlspecialchars($displayKey) ?>&action=function"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "cekIdPelanggan": { "description": "Cek data pelanggan", "file": "functions/cek_idpel.php", "enabled": true } } }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"function","function_name":"cekIdPelanggan","function_desc":"Cek data pelanggan","function_file":"functions/cek_idpel.php","function_enabled":1}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=technical_menu_db</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php?key=<?= htmlspecialchars($displayKey) ?>&action=technical_menu_db"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "host": "localhost", "user": "qts", "name": "absensi" } }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"technical_menu_db","tech_db_host":"localhost","tech_db_user":"qts","tech_db_pass":"xxx","tech_db_name":"absensi"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6><code>action=port_range</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php?key=<?= htmlspecialchars($displayKey) ?>&action=port_range"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "port_start_bot": 3000, "port_end_bot": 3999 } }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>wabot_settings.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"port_range","port_start_bot":3000,"port_end_bot":3999}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: TRANSAKSI -->
                        <!-- ============================================================ -->
                        <div id="tab-transaksi" style="<?= $activeTab === 'transaksi' ? '' : 'display:none;' ?>">
                            <h5>Transaksi &mdash; <code>transaksi.php</code></h5>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom:</strong> <code>ID, IDPEL, NAMA, PAKET, HARGA, TANGGALBAYAR, STATUS, PEMILIK, BUKTI, CEK, PENGUNAAN, METODE_BAYAR</code> (+ kolom fee gateway: <code>payment_method, harga_gross, fee_merchant, fee_customer</code>)</p>

                            <h6>GET &mdash; list dengan banyak filter</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Parameter</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>periode</code></td><td>Tidak</td><td>Cari teks bebas di kolom <code>PENGUNAAN</code> (contoh: "Juli 2026")</td></tr>
                                    <tr><td><code>start_date</code>, <code>end_date</code></td><td>Tidak</td><td>Rentang tanggal <code>TANGGALBAYAR</code>, format <code>YYYY-MM-DD</code>. Kalau dua-duanya diisi, ini yang dipakai (lebih akurat daripada <code>tanggal_awal</code>/<code>tanggal_akhir</code>)</td></tr>
                                    <tr><td><code>tanggal</code></td><td>Tidak</td><td>Filter tanggal bayar persis (exact match)</td></tr>
                                    <tr><td><code>tanggal_awal</code>, <code>tanggal_akhir</code></td><td>Tidak</td><td>Filter berdasarkan bulan+tahun (legacy, dipakai kalau <code>start_date</code>/<code>end_date</code> kosong)</td></tr>
                                    <tr><td><code>periode_bulan</code>, <code>periode_tahun</code></td><td>Tidak</td><td>Filter <code>PENGUNAAN</code> berdasarkan nama bulan Indonesia (mis. "Juli") dan/atau tahun 4 digit</td></tr>
                                    <tr><td><code>metode_bayar</code></td><td>Tidak</td><td>Salah satu: <code>cash</code>, <code>transfer</code>, atau <code>payment_gateway</code> (selain cash/transfer)</td></tr>
                                    <tr><td><code>status</code></td><td>Tidak</td><td>Filter kolom <code>STATUS</code> (exact atau prefix match)</td></tr>
                                    <tr><td><code>idpel</code></td><td>Tidak</td><td>Filter exact-match ID pelanggan</td></tr>
                                    <tr><td><code>nama</code></td><td>Tidak</td><td>Cari teks bebas di kolom <code>NAMA</code></td></tr>
                                    <tr><td><code>paket</code></td><td>Tidak</td><td>Filter exact-match nama paket</td></tr>
                                    <tr><td><code>search</code></td><td>Tidak</td><td>Cari cepat di IDPEL/NAMA/PAKET/STATUS sekaligus</td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>transaksi.php?key=<?= htmlspecialchars($displayKey) ?>&start_date=2026-07-01&end_date=2026-07-16&status=BERHASIL"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "ID": 100, "IDPEL": "FQ-001", "NAMA": "Budi", "HARGA": "150000", "STATUS": "BERHASIL", "TANGGALBAYAR": "2026-07-10" } ] }</code></pre>

                            <h6>POST &mdash; tambah transaksi manual</h6>
                            <p>Body wajib: <code>idpel, nama, paket, harga</code>. Opsional: <code>tanggal</code> (default hari ini), <code>status</code> (default PENAGIHAN), <code>bukti, cek, pengunaan, metode_bayar</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>transaksi.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","idpel":"FQ-002","nama":"Siti","paket":"Paket 10Mbps","harga":"150000"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 101 }</code></pre>

                            <h6>PUT &mdash; ubah transaksi (mis. konfirmasi bayar)</h6>
                            <p>Body wajib: <code>id</code>. Field dinamis lain yang didukung: <code>tanggalbayar, pengunaan, status, idpel, nama, paket, harga, bukti, cek, metode_bayar, manual_active_by, manual_active_session, payment_method, harga_gross, fee_merchant, fee_customer</code> (hanya yang dikirim yang diubah).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>transaksi.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":101,"status":"BERHASIL"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6>DELETE &mdash; hapus transaksi</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>transaksi.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":101}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: IP POOL -->
                        <!-- ============================================================ -->
                        <div id="tab-ip_pool" style="<?= $activeTab === 'ip_pool' ? '' : 'display:none;' ?>">
                            <h5>IP Pool &mdash; <code>ip_pool.php</code></h5>
                            <p>Nama file dipertahankan (bukan <code>pool.php</code>) karena app Android Qbilling sudah hardcode URL ini.</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom:</strong> <code>id, pool_name, ipawal, ipakhir, iplocal, pemilik</code></p>

                            <h6>GET &mdash; list</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Parameter</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>id</code></td><td>Tidak</td><td>Ambil 1 pool spesifik berdasarkan ID</td></tr>
                                    <tr><td><code>search</code></td><td>Tidak</td><td>Cari teks bebas di <code>pool_name</code> atau <code>iplocal</code></td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>ip_pool.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": [ { "id": 1, "pool_name": "Pool1", "ipawal": "192.168.1.2", "ipakhir": "192.168.1.254", "iplocal": "192.168.1.1" } ] }</code></pre>

                            <h6>POST &mdash; tambah pool (atau sync dari MikroTik)</h6>
                            <p>Body wajib: <code>pool_name, local_ip, range_start, range_end</code> (<code>range_start</code> boleh subnet CIDR mis. <code>192.168.1.0/24</code>, otomatis dihitung range_end). Untuk sync: kirim <code>action:"sync", server_ip:"..."</code> (server harus milik akun ini) &mdash; menarik <code>/ip/pool/print</code> dari MikroTik dan upsert.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>ip_pool.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","pool_name":"Pool2","local_ip":"192.168.2.1","range_start":"192.168.2.0/24"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 2 }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>ip_pool.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"sync","server_ip":"192.168.1.1"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "inserted": 2, "updated": 1, "skipped": 0, "errors": [] }</code></pre>

                            <h6>PUT &mdash; ubah pool</h6>
                            <p>Body wajib: <code>id, pool_name, local_ip, range_start, range_end</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>ip_pool.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2,"pool_name":"Pool2","local_ip":"192.168.2.1","range_start":"192.168.2.2","range_end":"192.168.2.254"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6>DELETE &mdash; hapus pool</h6>
                            <p>Ditolak kalau masih dipakai paket (kolom <code>LOCAL</code>).</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>ip_pool.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: VLAN -->
                        <!-- ============================================================ -->
                        <div id="tab-vlan" style="<?= $activeTab === 'vlan' ? '' : 'display:none;' ?>">
                            <h5>VLAN &mdash; <code>vlan.php</code></h5>
                            <p>Provisioning VLAN interface ke MikroTik + pencatatan di tabel <code>vlan</code>.</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom:</strong> <code>id, vlan_id, keterangan, pool_id, server_id, interface_name, ip_gateway, vlan_interface_name, status, sync_source, last_synced_at, error_message, pemilik, created_at</code> (+ <code>pool_name, ipawal, ipakhir, server_area, server_ip, server_brand</code> dari join)</p>

                            <h6>GET &mdash; list</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Parameter</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>id</code></td><td>Tidak</td><td>Ambil 1 VLAN spesifik berdasarkan ID</td></tr>
                                    <tr><td><code>server_id</code></td><td>Tidak</td><td>Filter berdasarkan server tertentu (harus milik akun ini)</td></tr>
                                    <tr><td><code>status</code></td><td>Tidak</td><td>Filter exact-match kolom <code>status</code></td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>vlan.php?key=<?= htmlspecialchars($displayKey) ?>"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "total": 1, "data": [ { "id": 1, "vlan_id": 100, "keterangan": "VLAN Cluster A", "status": "active", "server_ip": "192.168.1.1" } ] }</code></pre>

                            <h6>POST &mdash; tambah VLAN (push ke MikroTik dulu), atau sync</h6>
                            <p>Body wajib (create): <code>vlan_id, server_id, interface_name</code>. Opsional: <code>keterangan, with_ip</code> (boolean), <code>pool_id</code> (kalau <code>with_ip=true</code>). Untuk sync dari router: query/body <code>action=sync&server_id=...</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>vlan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","vlan_id":101,"server_id":1,"interface_name":"ether2","keterangan":"VLAN Baru","with_ip":true,"pool_id":1}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "id": 2 }</code></pre>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>vlan.php?action=sync&server_id=1" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "server_id": 1, "total_api": 5, "upserted": 5, "skipped_invalid": 0, "db_errors": 0 } }</code></pre>

                            <h6>PUT / PATCH &mdash; ubah VLAN</h6>
                            <p>Body wajib: <code>id</code>. Opsional: <code>keterangan, with_ip, pool_id</code>.</p>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X PUT "<?= htmlspecialchars($baseApiUrl) ?>vlan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2,"keterangan":"VLAN diperbarui"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>

                            <h6>DELETE &mdash; hapus VLAN</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X DELETE "<?= htmlspecialchars($baseApiUrl) ?>vlan.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","id":2}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true }</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: LOG BILLING -->
                        <!-- ============================================================ -->
                        <div id="tab-log" style="<?= $activeTab === 'log' ? '' : 'display:none;' ?>">
                            <h5>Log Billing &mdash; <code>log.php</code> (read-only)</h5>
                            <p>Membaca riwayat aktivitas akun dari file JSON <code>notifbot/data/history-&lt;owner&gt;.json</code>, diformat ulang jadi baris terstruktur.</p>
                            <?= docAuthNote($displayKey) ?>
                            <p><strong>Kolom:</strong> <code>username, timestamp, message</code></p>

                            <h6>GET &mdash; satu-satunya method</h6>
                            <table class="table table-sm table-bordered">
                                <thead><tr><th>Parameter</th><th>Wajib?</th><th>Keterangan</th></tr></thead>
                                <tbody>
                                    <tr><td><code>username_filter</code></td><td>Tidak</td><td>Cari teks bebas di kolom <code>username</code> hasil parsing log</td></tr>
                                    <tr><td><code>timestamp_filter</code></td><td>Tidak</td><td>Cari teks bebas di kolom <code>timestamp</code></td></tr>
                                    <tr><td><code>q</code></td><td>Tidak</td><td>Cari teks bebas di isi <code>message</code></td></tr>
                                    <tr><td><code>limit</code></td><td>Tidak</td><td>Jumlah data per halaman, default 200, maksimal 1000</td></tr>
                                    <tr><td><code>offset</code></td><td>Tidak</td><td>Lompat data untuk pagination, default 0</td></tr>
                                </tbody>
                            </table>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>log.php?key=<?= htmlspecialchars($displayKey) ?>&q=login"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{
  "success": true,
  "data": [
    { "username": "FIBERQ", "timestamp": "2026-07-16 09:00:00", "message": "Berhasil login" }
  ],
  "total": 1, "limit": 200, "offset": 0
}</code></pre>
                        </div>

                        <!-- ============================================================ -->
                        <!-- TAB: BACKUP/RESTORE -->
                        <!-- ============================================================ -->
                        <div id="tab-backup" style="<?= $activeTab === 'backup' ? '' : 'display:none;' ?>">
                            <h5>Backup/Restore &mdash; <code>backup.php</code> &amp; <code>backup_restore_mobile.php</code></h5>
                            <?= docAuthNote($displayKey) ?>

                            <h6>1) <code>backup.php</code> &mdash; pengganti langsung <code>apiinterface.php</code> lama</h6>
                            <p>Routing <code>type=</code>, parameter persis sama dengan <code>apiinterface.php</code> versi lama supaya integrasi lama tinggal ganti base URL.</p>
                            <table class="table table-striped">
                                <thead><tr><th>type</th><th>Method</th><th>Parameter</th></tr></thead>
                                <tbody>
                                    <tr><td><code>api_features</code></td><td>GET</td><td>&mdash; daftar fitur, tabel yang bisa di-backup, alias type</td></tr>
                                    <tr><td><code>billing_core_data</code></td><td>GET</td><td><code>include</code> (default pelanggan,odp,paket,server,transaksi), filter per-tabel (<code>paket, area, status, start_date, end_date</code>)</td></tr>
                                    <tr><td><code>billing_backup_data</code></td><td>GET</td><td><code>tables[]</code> (default semua tabel diizinkan), <code>download=1</code>, <code>compress=1</code></td></tr>
                                    <tr><td><code>billing_backup_structure</code></td><td>GET</td><td><code>tables[]</code> (dibatasi ke tabel milik tenant, bukan seluruh DB), <code>download=1</code> (default)</td></tr>
                                    <tr><td><code>billing_restore_data</code></td><td>POST</td><td><code>backup_payload</code> (JSON) atau upload <code>backup_file</code>, <code>tables[]</code> opsional</td></tr>
                                </tbody>
                            </table>
                            <h6><code>type=api_features</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup.php?key=<?= htmlspecialchars($displayKey) ?>&type=api_features"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "owner": "FIBERQ", "features": ["servers","pelanggan","transactions", "..."], "backup_allowed_tables": ["pelanggan","odp","paket","server","pool","transaksi","diskon","biaya_tambahan","botwa","paket_hotspot","olt","voucher"], "aliases": { "pelanggan": "customers_table", "paket": "pppoe_packages" } } }</code></pre>

                            <h6><code>type=billing_core_data</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup.php?key=<?= htmlspecialchars($displayKey) ?>&type=billing_core_data&include=pelanggan,server"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "owner": "FIBERQ", "include": ["pelanggan","server"], "data": { "pelanggan": {"count":10,"rows":[...]}, "server": {"count":2,"rows":[...]} } } }</code></pre>

                            <h6><code>type=billing_backup_data</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup.php?key=<?= htmlspecialchars($displayKey) ?>&type=billing_backup_data&tables[]=pelanggan&tables[]=server&download=1&compress=1" -o backup.json.gz</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "meta": { "owner": "FIBERQ", "generated_at": "2026-07-16 10:00:00", "format": "qts-owner-backup-v1", "selected_tables": ["pelanggan","server"] }, "data": { "pelanggan": [...], "server": [...] }, "summary": { "pelanggan": {"status":"ok","owner_column":"PEMILIK","row_count":10}, "server": {"status":"ok","owner_column":"PEMILIK","row_count":2} } } }</code></pre>

                            <h6><code>type=billing_backup_structure</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup.php?key=<?= htmlspecialchars($displayKey) ?>&type=billing_backup_structure&download=0"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "sql": "-- QTS Billing Structure Backup (API)\n-- Owner/API User: FIBERQ\n...\nDROP TABLE IF EXISTS `pelanggan`;\nCREATE TABLE `pelanggan` (...);\n" } }</code></pre>
                            <p class="text-muted">Dengan <code>download=1</code> (default), response bukan JSON melainkan file <code>.sql</code> langsung (header <code>Content-Disposition: attachment</code>).</p>

                            <h6><code>type=billing_restore_data</code> (POST)</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>backup.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","type":"billing_restore_data","backup_payload":"{\"data\":{\"pelanggan\":[...]}}","tables":["pelanggan"]}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "data": { "processed_tables": 1, "inserted_rows": 10, "skipped": [] } }</code></pre>

                            <hr>
                            <h6>2) <code>backup_restore_mobile.php</code> &mdash; dipakai app Qbilling Android</h6>
                            <p>Routing <code>action=</code>, dipertahankan persis untuk kompatibilitas app Android (<code>BackupRestoreActivity.kt</code>).</p>
                            <table class="table table-striped">
                                <thead><tr><th>action</th><th>Method</th><th>Parameter</th></tr></thead>
                                <tbody>
                                    <tr><td>(kosong)/<code>list</code></td><td>GET</td><td>&mdash; daftar file backup tersimpan milik akun</td></tr>
                                    <tr><td><code>info</code></td><td>GET</td><td>&mdash; tabel yang tersedia untuk backup</td></tr>
                                    <tr><td><code>create_backup</code></td><td>POST</td><td><code>tables[], compress, download</code></td></tr>
                                    <tr><td><code>delete_file</code></td><td>POST</td><td><code>filename</code></td></tr>
                                    <tr><td><code>restore_file</code></td><td>POST</td><td><code>filename, tables[]</code> (restore dari file tersimpan)</td></tr>
                                    <tr><td><code>restore_payload</code></td><td>POST</td><td><code>backup_payload, tables[]</code> (restore langsung dari JSON)</td></tr>
                                    <tr><td><code>core_data</code></td><td>GET</td><td><code>include</code></td></tr>
                                    <tr><td><code>structure</code></td><td>GET</td><td><code>tables[], download</code></td></tr>
                                </tbody>
                            </table>

                            <h6><code>action=list</code> (default)</h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php?key=<?= htmlspecialchars($displayKey) ?>&action=list"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "files": [ { "filename": "backup_FIBERQ_20260716_090000.json.gz", "size": 20480, "modified_at": "2026-07-16 09:00:00" } ] }</code></pre>

                            <h6><code>action=info</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php?key=<?= htmlspecialchars($displayKey) ?>&action=info"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "owner": "FIBERQ", "available_tables": ["pelanggan","odp","paket","server","pool","transaksi","diskon","biaya_tambahan","botwa"], "default_tables": ["pelanggan","odp","paket","server"] }</code></pre>

                            <h6><code>action=create_backup</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"create_backup","tables":["pelanggan","server"],"compress":true}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "message": "Backup berhasil dibuat", "file": "backup_FIBERQ_20260716_100500.json.gz", "summary": { "pelanggan": {"status":"ok","owner_column":"PEMILIK","row_count":10}, "server": {"status":"ok","owner_column":"PEMILIK","row_count":2} } }</code></pre>

                            <h6><code>action=delete_file</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"delete_file","filename":"backup_FIBERQ_20260716_090000.json.gz"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "message": "File backup dihapus" }</code></pre>

                            <h6><code>action=restore_file</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"restore_file","filename":"backup_FIBERQ_20260716_090000.json.gz"}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "message": "Restore selesai", "processed_tables": 2, "inserted_rows": 12, "skipped": [] }</code></pre>

                            <h6><code>action=restore_payload</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl -X POST "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php" \
  -H "Content-Type: application/json" \
  -d '{"key":"<?= htmlspecialchars($displayKey) ?>","action":"restore_payload","backup_payload":{"data":{"pelanggan":[...]}}}'</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "message": "Restore selesai", "processed_tables": 1, "inserted_rows": 10, "skipped": [] }</code></pre>

                            <h6><code>action=core_data</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php?key=<?= htmlspecialchars($displayKey) ?>&action=core_data&include=pelanggan,server"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "owner": "FIBERQ", "generated_at": "2026-07-16 10:10:00", "include": ["pelanggan","server"], "data": { "pelanggan": {"count":10,"rows":[...]}, "server": {"count":2,"rows":[...]} } }</code></pre>

                            <h6><code>action=structure</code></h6>
                            <div class="code-label code-label-request">Request</div>
                            <pre><code>curl "<?= htmlspecialchars($baseApiUrl) ?>backup_restore_mobile.php?key=<?= htmlspecialchars($displayKey) ?>&action=structure&download=0"</code></pre>
                            <div class="code-label code-label-response">Response</div>
                            <pre><code>{ "success": true, "sql": "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\nDROP TABLE IF EXISTS `pelanggan`;\nCREATE TABLE `pelanggan` (...);\n\nSET FOREIGN_KEY_CHECKS=1;\n" }</code></pre>
                        </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
