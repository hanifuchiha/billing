<?php
// livechat_setting.php - Menu tersendiri utk SEMUA pengaturan Live Chat (AI Bot),
// dipisah dari system_setting.php (yang isinya campur macam-macam pengaturan
// tidak terkait) supaya lebih mudah ditemukan. Logic sesungguhnya ada di
// livechat_ai_helper.php - halaman ini cuma UI + AJAX handler tipis.
if (isset($_POST['action']) && in_array($_POST['action'], ['toggle_livechat_ai', 'save_livechat_ai', 'save_livechat_menu', 'test_livechat_ai', 'check_livechat_ai_pelanggan', 'remove_livechat_ai_cron', 'register_livechat_ai_cron'], true)) {
    require_once __DIR__ . '/cek-sesi.php';
    require_once __DIR__ . '/../webhook/ai_provider_helper.php';
    require_once __DIR__ . '/livechat_ai_helper.php';

    header('Content-Type: application/json; charset=utf-8');

    if ($AKSES === 'ASSISTANT') {
        echo json_encode(['success' => false, 'message' => 'Assistant tidak berhak mengubah pengaturan AI Live Chat.']);
        exit;
    }

    // Akun ADMIN (master admin pemilik web ini) pakai kunci 'admin', SAMA dgn
    // PEMILIK pelanggan miliknya sendiri di tabel `pelanggan` -- lihat komentar
    // lengkap di livechatAiScopeKey().
    $livechatAiScope = livechatAiScopeKey($AKSES, $ceknama);

    if ($_POST['action'] === 'remove_livechat_ai_cron') {
        $rmResult = livechatAiRemoveCronRegistration();
        echo json_encode(['success' => $rmResult['ok'], 'message' => $rmResult['message']]);
        exit;
    }

    if ($_POST['action'] === 'register_livechat_ai_cron') {
        $regResult = livechatAiEnsureCronRegistered();
        echo json_encode(['success' => $regResult['ok'], 'message' => $regResult['message']]);
        exit;
    }

    if ($_POST['action'] === 'check_livechat_ai_pelanggan') {
        // Diagnosa mismatch scope: toggle/pengaturan AI disimpan atas nama scope
        // akun yang login, TAPI saat pesan pelanggan masuk, chat_send.php cek AI
        // berdasarkan PEMILIK pelanggan itu sendiri -- kalau pelanggan itu ternyata
        // "milik" reseller/area lain (PEMILIK beda dari scope akun ini), AI yang
        // di-ON-kan dari sini TIDAK akan pernah kepakai utk pelanggan tsb. Cek ini
        // supaya ketahuan tanpa perlu buka error_log server.
        $idpelCheck = trim((string)($_POST['idpel'] ?? ''));
        if ($idpelCheck === '') {
            echo json_encode(['success' => false, 'message' => 'IDPEL belum diisi.']);
            exit;
        }
        $idpelEsc = mysqli_real_escape_string($conn, $idpelCheck);
        $cekPelangganAi = mysqli_query($conn, "SELECT IDPEL, NAMA, PEMILIK FROM pelanggan WHERE TRIM(IDPEL) = '$idpelEsc' LIMIT 1");
        $rowPelangganAi = $cekPelangganAi ? mysqli_fetch_assoc($cekPelangganAi) : null;
        if (!$rowPelangganAi) {
            echo json_encode(['success' => false, 'message' => "Pelanggan dengan IDPEL '$idpelCheck' tidak ditemukan."]);
            exit;
        }
        $pemilikPelanggan = trim((string)($rowPelangganAi['PEMILIK'] ?? ''));
        $settingsPelanggan = livechatAiGetSettings($conn, $pemilikPelanggan);
        $enabledPelanggan = !empty($settingsPelanggan['ai_enabled']);
        $sameTenant = (strtolower($pemilikPelanggan) === strtolower($livechatAiScope));

        $msg = "Pelanggan '{$rowPelangganAi['NAMA']}' (PEMILIK: $pemilikPelanggan) -- AI Bot untuk PEMILIK ini: " . ($enabledPelanggan ? 'ON' : 'OFF') . '.';
        if (!$sameTenant) {
            $msg .= " PERHATIAN: PEMILIK pelanggan ini ($pemilikPelanggan) BEDA dengan scope akun yang sedang login ($livechatAiScope) -- toggle/pengaturan yang Anda simpan di sini TIDAK berlaku untuk pelanggan ini. Login sebagai akun pemilik $pemilikPelanggan (atau atur AI dari sana) untuk mengaktifkannya.";
        } elseif (!$enabledPelanggan) {
            $msg .= ' Toggle AI Bot masih OFF untuk akun ini -- aktifkan dulu di atas.';
        }

        echo json_encode([
            'success' => $enabledPelanggan && $sameTenant,
            'message' => $msg,
            'pemilik' => $pemilikPelanggan,
            'same_tenant' => $sameTenant,
            'ai_enabled' => $enabledPelanggan,
        ]);
        exit;
    }

    if ($_POST['action'] === 'test_livechat_ai') {
        // Test langsung pakai setting YANG SUDAH TERSIMPAN (bukan form yg sedang
        // diedit tapi belum disimpan) -- supaya hasil test benar2 mencerminkan apa
        // yg akan dipakai chat_send.php saat pelanggan kirim pesan sungguhan.
        $testSettings = livechatAiGetSettings($conn, $livechatAiScope);
        if (empty($testSettings['ai_enabled'])) {
            echo json_encode(['success' => false, 'message' => 'AI Bot masih OFF. Aktifkan dulu togglenya, lalu simpan pengaturan sebelum test.']);
            exit;
        }
        $testReply = aiChatComplete($testSettings, 'Kamu asisten customer service ISP. Jawab singkat.', 'Halo, ini pesan test dari admin.');
        $testOk = ($testReply !== '' && strpos(trim($testReply), '❌') !== 0);
        echo json_encode([
            'success' => $testOk,
            'message' => $testOk ? 'AI berhasil merespon.' : 'AI gagal merespon.',
            'reply' => $testReply,
        ]);
        exit;
    }

    if ($_POST['action'] === 'toggle_livechat_ai') {
        $enable = isset($_POST['enable']) ? (bool)(int)$_POST['enable'] : false;
        $ok = livechatAiSetEnabled($conn, $livechatAiScope, $enable);
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? ($enable ? 'AI Bot Live Chat diaktifkan.' : 'AI Bot Live Chat dinonaktifkan.') : 'Gagal menyimpan status AI Bot.',
            'enabled' => $enable,
        ]);
        exit;
    }

    if ($_POST['action'] === 'save_livechat_menu') {
        $ok = livechatAiSaveMenuSettings($conn, $livechatAiScope, $_POST);
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Menu & jawaban kustom berhasil disimpan.' : 'Gagal menyimpan menu & jawaban kustom.',
        ]);
        exit;
    }

    // save_livechat_ai
    $ok = livechatAiSaveSettings($conn, $livechatAiScope, $_POST);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? 'Pengaturan AI Bot Live Chat berhasil disimpan.' : 'Gagal menyimpan pengaturan AI Bot Live Chat.',
    ]);
    exit;
}

require 'cek-sesi.php';

if ($AKSES === 'ASSISTANT') {
    echo "<script>alert('Assistant tidak memiliki akses ke halaman ini.'); window.location.href='dashboard.php';</script>";
    exit;
}

$page_title = "Pengaturan Live Chat";
// header.php SUDAH include sidebar.php + buka <main> di dalamnya sendiri
// (lihat header.php:1927 & sidebar.php:3375) -- require sidebar.php lagi di
// sini bikin sidebar & <main> ke-render DUA KALI, itu penyebab layout
// "miring/tidak center" sebelumnya (bukan cuma soal <main> ganda tempo hari).
require 'header.php';

require_once __DIR__ . '/../webhook/ai_provider_helper.php';
require_once __DIR__ . '/livechat_ai_helper.php';
$_livechatAiScope = livechatAiScopeKey($AKSES, $ceknama);
$_livechatAi = livechatAiGetSettings($conn, $_livechatAiScope);
$_livechatAiCatalog = aiProviderCatalog();
$_livechatAiModelsText = implode("\n", aiParseModelList($_livechatAi['ai_models'] ?? ''));
$_livechatAiKeywordsText = '';
foreach (livechatAiParseKeywordPairs($_livechatAi['custom_keywords'] ?? '') as $_kwPair) {
    $_livechatAiKeywordsText .= $_kwPair['keyword'] . ' => ' . $_kwPair['response'] . "\n";
}
$_livechatAiLogs = livechatAiGetRecentLogs($conn, $_livechatAiScope, 50);
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3"><i class="fas fa-comments me-2"></i>Pengaturan Live Chat</h4>

            <!-- Card: Status & Provider AI -->
            <div class="card shadow-sm mb-4" id="cardLivechatAi">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-robot"></i>
                        <span class="fw-bold">AI Bot Live Chat</span>
                        <span id="livechatAiStatusBadge" class="badge <?php echo !empty($_livechatAi['ai_enabled']) ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo !empty($_livechatAi['ai_enabled']) ? 'AKTIF' : 'NONAKTIF'; ?>
                        </span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="toggleLivechatAi"
                            <?php echo !empty($_livechatAi['ai_enabled']) ? 'checked' : ''; ?> style="width:3em;height:1.5em;cursor:pointer;">
                        <label class="form-check-label text-white" for="toggleLivechatAi">
                            <span id="toggleLivechatAiLabel"><?php echo !empty($_livechatAi['ai_enabled']) ? 'ON' : 'OFF'; ?></span>
                        </label>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Kalau aktif, pesan yang masuk dari pelanggan lewat Live Chat (widget chat di portal TagihanWifiku)
                        akan dibalas AI memakai provider/model yang sama seperti fitur AI di WA Bot. Dibalas lewat cron
                        yang jalan tiap 1 menit (bukan instan) -- lihat status cron di bawah. Bisa juga di-ON/OFF langsung
                        dari halaman Live Chat (<a href="livechat.php">buka di sini</a>) tanpa perlu balik ke sini.
                    </div>
                    <?php $_livechatAiCronOn = livechatAiIsCronRegistered(); ?>
                    <div class="alert py-2 small mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2 <?php echo $_livechatAiCronOn ? 'alert-success' : 'alert-warning'; ?>" id="livechatAiCronStatus">
                        <span>
                            <i class="fas fa-clock me-1"></i>
                            Cron pembalas AI:
                            <strong id="livechatAiCronStatusText"><?php echo $_livechatAiCronOn ? 'AKTIF (jalan tiap 1 menit)' : 'BELUM TERDAFTAR'; ?></strong>
                        </span>
                        <?php if (!$_livechatAiCronOn): ?>
                            <button type="button" class="btn btn-warning btn-sm" id="btnRegisterLivechatAiCron">
                                <i class="fas fa-plus me-1"></i>Daftarkan Sekarang
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnRemoveLivechatAiCron">
                                <i class="fas fa-trash me-1"></i>Nonaktifkan Cron
                            </button>
                        <?php endif; ?>
                    </div>
                    <form id="formLivechatAiSettings" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Provider AI</label>
                            <select class="form-select" name="ai_provider" id="livechatAiProvider" required>
                                <?php foreach ($_livechatAiCatalog as $key => $info): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($_livechatAi['ai_provider'] === $key) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($info['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">API Key</label>
                            <input type="text" class="form-control" name="ai_api_key" value="<?php echo htmlspecialchars($_livechatAi['ai_api_key'] ?? ''); ?>" placeholder="API key dari provider di atas" autocomplete="off">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Endpoint Custom</label>
                            <input type="text" class="form-control" name="ai_base_url" value="<?php echo htmlspecialchars($_livechatAi['ai_base_url'] ?? ''); ?>" placeholder="Wajib diisi jika Provider = Provider Lain (Custom)">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Daftar Model (satu per baris, urutan = prioritas fallback)</label>
                            <textarea class="form-control" name="ai_models" rows="3" style="font-family: monospace;" placeholder="contoh:&#10;llama3.1-8b&#10;llama3.3-70b"><?php echo htmlspecialchars($_livechatAiModelsText); ?></textarea>
                            <div class="form-text">Kalau model pertama gagal (mis. kena rate limit), otomatis dicoba model berikutnya di daftar ini.</div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">System Prompt (opsional)</label>
                            <textarea class="form-control" name="ai_system_prompt" rows="3" placeholder="Kosongkan untuk pakai prompt default customer service ramah Bahasa Indonesia."><?php echo htmlspecialchars($_livechatAi['ai_system_prompt'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-save me-1"></i>Simpan Pengaturan AI Live Chat
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnTestLivechatAi">
                                <i class="fas fa-vial me-1"></i>Test AI
                            </button>
                            <span id="livechatAiSaveMsg" class="small ms-2"></span>
                        </div>
                        <div class="col-12">
                            <div id="livechatAiTestResult" class="d-none alert py-2 small mb-0 mt-2"></div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Card: Menu & Jawaban Kustom -->
            <div class="card shadow-sm mb-4" id="cardLivechatMenu">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-list me-2"></i>
                    <span class="fw-bold">Menu &amp; Jawaban Kustom</span>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary py-2 small mb-3">
                        <i class="fas fa-magic me-1"></i>
                        Perintah bawaan (selalu aktif, boleh pakai <code>#</code> di depan atau tidak):
                        <code>cek data</code>, <code>gangguan &lt;keluhan&gt;</code>, <code>bayar</code> lalu <code>bayar &lt;nomor&gt;</code>, <code>batalbayar</code>.
                        Di bawah ini tambahan yang bisa Anda atur sendiri, sama seperti pengaturan Menu &amp; Jawaban di WA Bot.
                    </div>
                    <form id="formLivechatMenuSettings" class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Pesan Menu (muncul saat pelanggan ketik "MENU")</label>
                            <textarea class="form-control" name="menu_text" rows="4" placeholder="Kosongkan untuk pakai daftar perintah default."><?php echo htmlspecialchars($_livechatAi['menu_text'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Jawaban Kustom (satu per baris, format: <code>kata kunci => jawaban</code>)</label>
                            <textarea class="form-control" name="custom_keywords" rows="6" style="font-family: monospace;" placeholder="contoh:&#10;jam operasional => Kami buka Senin-Sabtu jam 08.00-17.00 WIB.&#10;alamat kantor => Kantor kami di Jl. Contoh No. 1."><?php echo htmlspecialchars($_livechatAiKeywordsText); ?></textarea>
                            <div class="form-text">Dicek dengan mencari potongan kata kunci di dalam pesan pelanggan (tidak harus persis sama). Kalau ada beberapa yang cocok, yang paling atas dipakai.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-save me-1"></i>Simpan Menu &amp; Jawaban
                            </button>
                            <span id="livechatMenuSaveMsg" class="small ms-2"></span>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Card: Diagnostik -->
            <div class="card shadow-sm mb-4" id="cardLivechatDiagnostik">
                <div class="card-header bg-dark text-white">
                    <i class="fas fa-stethoscope me-2"></i>
                    <span class="fw-bold">Diagnostik</span>
                </div>
                <div class="card-body">
                    <div class="small fw-semibold mb-2"><i class="fas fa-search me-1"></i>Cek Status AI untuk Pelanggan Tertentu</div>
                    <p class="text-muted small mb-2">
                        Toggle di atas cuma berlaku untuk akun yang sedang login. Kalau AI sudah ON tapi tidak merespon pelanggan
                        tertentu, kemungkinan pelanggan itu tercatat di akun/reseller lain. Cek di sini.
                    </p>
                    <div class="input-group input-group-sm" style="max-width:420px;">
                        <input type="text" class="form-control" id="livechatAiCheckIdpel" placeholder="Masukkan IDPEL pelanggan">
                        <button class="btn btn-outline-primary" type="button" id="btnCheckLivechatAiPelanggan">Cek</button>
                    </div>
                    <div id="livechatAiCheckResult" class="d-none alert py-2 small mb-0 mt-2"></div>
                </div>
            </div>

            <!-- Card: Log AI (paling bawah) -->
            <div class="card shadow-sm mb-4" id="cardLivechatLog">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <i class="fas fa-clipboard-list me-2"></i>
                        <span class="fw-bold">Log AI (50 aktivitas terakhir)</span>
                    </div>
                    <button type="button" class="btn btn-outline-light btn-sm" id="btnRefreshLivechatLog">
                        <i class="fas fa-rotate me-1"></i>Muat Ulang
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:480px;">
                        <table class="table table-sm table-hover mb-0" id="tableLivechatAiLog">
                            <thead class="table-light" style="position:sticky;top:0;">
                                <tr>
                                    <th style="white-space:nowrap;">Waktu</th>
                                    <th>Pelanggan</th>
                                    <th>Pesan Masuk</th>
                                    <th>Jenis</th>
                                    <th>Status</th>
                                    <th>Balasan / Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($_livechatAiLogs)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">Belum ada aktivitas AI tercatat.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($_livechatAiLogs as $_log): ?>
                                        <tr>
                                            <td style="white-space:nowrap;font-size:.8rem;"><?php echo htmlspecialchars($_log['created_at']); ?></td>
                                            <td style="font-size:.8rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($_log['customer_identity']); ?></td>
                                            <td style="font-size:.8rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars(mb_substr((string)$_log['message_in'], 0, 120)); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($_log['response_type']); ?></span></td>
                                            <td><span class="badge <?php echo $_log['status'] === 'ok' ? 'bg-success' : 'bg-danger'; ?>"><?php echo strtoupper(htmlspecialchars($_log['status'])); ?></span></td>
                                            <td style="font-size:.8rem;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:pre-wrap;"><?php echo htmlspecialchars(mb_substr((string)$_log['detail'], 0, 200)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    const toggle = document.getElementById('toggleLivechatAi');
    const badge = document.getElementById('livechatAiStatusBadge');
    const label = document.getElementById('toggleLivechatAiLabel');
    if (toggle) {
        toggle.addEventListener('change', function () {
            const enabled = toggle.checked;
            label.textContent = enabled ? 'ON' : 'OFF';
            badge.textContent = enabled ? 'AKTIF' : 'NONAKTIF';
            badge.className = 'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
            fetch('livechat_setting.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=toggle_livechat_ai&enable=' + (enabled ? '1' : '0')
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Gagal mengubah status AI Bot.');
                    toggle.checked = !enabled;
                    label.textContent = !enabled ? 'ON' : 'OFF';
                    badge.textContent = !enabled ? 'AKTIF' : 'NONAKTIF';
                    badge.className = 'badge ' + (!enabled ? 'bg-success' : 'bg-secondary');
                }
            })
            .catch(() => {
                alert('Gagal menghubungi server.');
                toggle.checked = !enabled;
            });
        });
    }

    const form = document.getElementById('formLivechatAiSettings');
    const saveMsg = document.getElementById('livechatAiSaveMsg');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new URLSearchParams(new FormData(form));
            formData.append('action', 'save_livechat_ai');
            saveMsg.textContent = 'Menyimpan...';
            saveMsg.className = 'small ms-2 text-muted';
            fetch('livechat_setting.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                saveMsg.textContent = data.message || (data.success ? 'Tersimpan.' : 'Gagal.');
                saveMsg.className = 'small ms-2 ' + (data.success ? 'text-success' : 'text-danger');
            })
            .catch(() => {
                saveMsg.textContent = 'Gagal menghubungi server.';
                saveMsg.className = 'small ms-2 text-danger';
            });
        });
    }

    const btnTest = document.getElementById('btnTestLivechatAi');
    const testResult = document.getElementById('livechatAiTestResult');
    if (btnTest && testResult) {
        btnTest.addEventListener('click', function () {
            btnTest.disabled = true;
            btnTest.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Menguji...';
            testResult.className = 'alert alert-secondary py-2 small mb-0 mt-2';
            testResult.textContent = 'Memanggil AI, tunggu sebentar...';
            fetch('livechat_setting.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=test_livechat_ai'
            })
            .then(r => r.json())
            .then(data => {
                testResult.className = 'alert py-2 small mb-0 mt-2 ' + (data.success ? 'alert-success' : 'alert-danger');
                testResult.textContent = (data.message || '') + (data.reply ? ('\n\nBalasan AI: ' + data.reply) : '');
                testResult.style.whiteSpace = 'pre-wrap';
            })
            .catch(() => {
                testResult.className = 'alert alert-danger py-2 small mb-0 mt-2';
                testResult.textContent = 'Gagal menghubungi server.';
            })
            .finally(() => {
                btnTest.disabled = false;
                btnTest.innerHTML = '<i class="fas fa-vial me-1"></i>Test AI';
            });
        });
    }

    const menuForm = document.getElementById('formLivechatMenuSettings');
    const menuSaveMsg = document.getElementById('livechatMenuSaveMsg');
    if (menuForm) {
        menuForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new URLSearchParams(new FormData(menuForm));
            formData.append('action', 'save_livechat_menu');
            menuSaveMsg.textContent = 'Menyimpan...';
            menuSaveMsg.className = 'small ms-2 text-muted';
            fetch('livechat_setting.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                menuSaveMsg.textContent = data.message || (data.success ? 'Tersimpan.' : 'Gagal.');
                menuSaveMsg.className = 'small ms-2 ' + (data.success ? 'text-success' : 'text-danger');
            })
            .catch(() => {
                menuSaveMsg.textContent = 'Gagal menghubungi server.';
                menuSaveMsg.className = 'small ms-2 text-danger';
            });
        });
    }

    const btnCheck = document.getElementById('btnCheckLivechatAiPelanggan');
    const checkIdpelInput = document.getElementById('livechatAiCheckIdpel');
    const checkResult = document.getElementById('livechatAiCheckResult');
    if (btnCheck && checkIdpelInput && checkResult) {
        const runCheck = function () {
            const idpel = checkIdpelInput.value.trim();
            if (!idpel) {
                checkResult.className = 'alert alert-warning py-2 small mb-0 mt-2';
                checkResult.textContent = 'Isi IDPEL dulu.';
                return;
            }
            btnCheck.disabled = true;
            checkResult.className = 'alert alert-secondary py-2 small mb-0 mt-2';
            checkResult.textContent = 'Mengecek...';
            fetch('livechat_setting.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=check_livechat_ai_pelanggan&idpel=' + encodeURIComponent(idpel)
            })
            .then(r => r.json())
            .then(data => {
                checkResult.className = 'alert py-2 small mb-0 mt-2 ' + (data.success ? 'alert-success' : 'alert-warning');
                checkResult.textContent = data.message || 'Gagal cek status.';
            })
            .catch(() => {
                checkResult.className = 'alert alert-danger py-2 small mb-0 mt-2';
                checkResult.textContent = 'Gagal menghubungi server.';
            })
            .finally(() => { btnCheck.disabled = false; });
        };
        btnCheck.addEventListener('click', runCheck);
        checkIdpelInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); runCheck(); }
        });
    }

    const btnRemoveCron = document.getElementById('btnRemoveLivechatAiCron');
    const btnRegisterCron = document.getElementById('btnRegisterLivechatAiCron');
    const cronStatusWrap = document.getElementById('livechatAiCronStatus');
    if (btnRemoveCron) {
        btnRemoveCron.addEventListener('click', function () {
            btnRemoveCron.disabled = true;
            btnRemoveCron.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Menonaktifkan...';
            fetch('livechat_setting.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=remove_livechat_ai_cron'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Gagal menonaktifkan cron.');
                    btnRemoveCron.disabled = false;
                    btnRemoveCron.innerHTML = '<i class="fas fa-trash me-1"></i>Nonaktifkan Cron';
                }
            })
            .catch(() => {
                alert('Gagal menghubungi server.');
                btnRemoveCron.disabled = false;
                btnRemoveCron.innerHTML = '<i class="fas fa-trash me-1"></i>Nonaktifkan Cron';
            });
        });
    }
    if (btnRegisterCron) {
        btnRegisterCron.addEventListener('click', function () {
            btnRegisterCron.disabled = true;
            btnRegisterCron.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Mendaftarkan...';
            fetch('livechat_setting.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=register_livechat_ai_cron'
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Gagal mendaftarkan cron.');
                    btnRegisterCron.disabled = false;
                    btnRegisterCron.innerHTML = '<i class="fas fa-plus me-1"></i>Daftarkan Sekarang';
                }
            })
            .catch(() => {
                alert('Gagal menghubungi server.');
                btnRegisterCron.disabled = false;
                btnRegisterCron.innerHTML = '<i class="fas fa-plus me-1"></i>Daftarkan Sekarang';
            });
        });
    }

    const btnRefreshLog = document.getElementById('btnRefreshLivechatLog');
    if (btnRefreshLog) {
        btnRefreshLog.addEventListener('click', function () {
            window.location.reload();
        });
    }
})();
</script>

<?php require 'footer.php'; ?>
