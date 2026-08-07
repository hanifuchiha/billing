<?php
// Buffer output supaya endpoint AJAX di halaman ini (test kirim, hapus bot)
// bisa balikin JSON murni -- pola sama persis dgn wabot.php.
ob_start();

require 'header.php';
require_once __DIR__ . '/notifbot/telegram_bot_access_helper.php';
require_once __DIR__ . '/notifbot/telegram_send_helper.php';

// Payment Setting/System Setting dkk sudah pakai pola guard ini utk halaman
// yg bisa disembunyikan lewat checkbox "Hak Akses Menu" -- sama di sini.
if ($AKSES === 'ASSISTANT' && (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Telegram_BOT', $akses_menu, true))) {
    ob_end_clean();
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Telegram Bot.</div></div>';
    require 'footer.php';
    exit;
}

$isAdminAccess = isset($AKSES) && strtoupper(trim((string)$AKSES)) === 'ADMIN';
$webhookBaseUrl = rtrim((string)($config['URL'] ?? ''), '/') . '/crm/billing/notifbot/telegram_webhook.php';

// --- Endpoint AJAX internal (aksi test kirim / hapus bot) -----------------
if (isset($_GET['telegram_action']) || isset($_POST['telegram_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['telegram_action'] ?? $_POST['telegram_action'] ?? '';
    $botId = (int)($_POST['bot_id'] ?? $_GET['bot_id'] ?? 0);

    $stmtBot = $conn->prepare("SELECT * FROM bottelegram WHERE id = ? AND pemilik = ? LIMIT 1");
    $stmtBot->bind_param('is', $botId, $ceknama);
    $stmtBot->execute();
    $botRow = $stmtBot->get_result()->fetch_assoc();
    $stmtBot->close();

    if (!$botRow) {
        echo json_encode(['success' => false, 'message' => 'Bot tidak ditemukan.']);
        ob_end_flush();
        exit;
    }
    if (!telegramBotAccessCanManage($AKSES, $assigned_telegram_bot_ids ?? [], $asistant_name ?? '', $botRow)) {
        echo json_encode(['success' => false, 'message' => 'Anda tidak punya akses ke bot ini.']);
        ob_end_flush();
        exit;
    }

    if ($action === 'test_send') {
        $chatId = trim((string)($_POST['chat_id'] ?? $botRow['penerima'] ?? ''));
        if ($chatId === '') {
            echo json_encode(['success' => false, 'message' => 'Chat ID tujuan kosong. Isi salah satu field "Penerima" dulu, atau isi manual di form test.']);
            ob_end_flush();
            exit;
        }
        $result = sendTelegramMessage((string)$botRow['bottoken'], $chatId, "✅ Test kirim dari bot *" . $botRow['namebot'] . "* berhasil.");
        echo json_encode(['success' => (bool)$result['sent'], 'message' => $result['sent'] ? 'Pesan test terkirim.' : ('Gagal: ' . $result['error'])]);
        ob_end_flush();
        exit;
    }

    if ($action === 'delete_bot') {
        $stmtDel = $conn->prepare("DELETE FROM bottelegram WHERE id = ? AND pemilik = ?");
        $stmtDel->bind_param('is', $botId, $ceknama);
        $ok = $stmtDel->execute();
        $stmtDel->close();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Bot dihapus.' : 'Gagal menghapus bot.']);
        ob_end_flush();
        exit;
    }

    if ($action === 'save_penerima') {
        $allowedFields = ['penerima', 'penerima_server', 'penerima_livechat', 'penerima_system_notif', 'penerima_manual_active', 'penerima_odp_los', 'penerima_provisioning'];
        $field = (string)($_POST['field'] ?? '');
        if (!in_array($field, $allowedFields, true)) {
            echo json_encode(['success' => false, 'message' => 'Field tidak dikenal.']);
            ob_end_flush();
            exit;
        }
        $value = trim((string)($_POST['value'] ?? ''));
        $stmtUpd = $conn->prepare("UPDATE bottelegram SET `$field` = ? WHERE id = ? AND pemilik = ?");
        $stmtUpd->bind_param('sis', $value, $botId, $ceknama);
        $ok = $stmtUpd->execute();
        $stmtUpd->close();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'Tersimpan.' : 'Gagal menyimpan.']);
        ob_end_flush();
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    ob_end_flush();
    exit;
}

// --- Daftar bot Telegram milik akun ini (dibatasi per-assistant kalau perlu) ---
$sqlListBot = "SELECT * FROM bottelegram WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'"
    . telegramBotAccessWhereClause($conn, $AKSES, $assigned_telegram_bot_ids ?? [], $asistant_name ?? '')
    . " ORDER BY id DESC";
$resultListBot = mysqli_query($conn, $sqlListBot);
$telegramBots = [];
if ($resultListBot) {
    while ($row = mysqli_fetch_assoc($resultListBot)) {
        $telegramBots[] = $row;
    }
}

$penerimaFieldLabels = [
    'penerima' => 'Default (Transaksi/Registrasi)',
    'penerima_server' => 'Notif Server',
    'penerima_livechat' => 'Live Chat',
    'penerima_system_notif' => 'System Notif',
    'penerima_manual_active' => 'Manual Active',
    'penerima_odp_los' => 'ODP LOS',
    'penerima_provisioning' => 'Provisioning',
];
?>

<div class="container-fluid py-4 px-3 px-md-4">

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fab fa-telegram"></i>
                <span class="fw-bold">Telegram Bot</span>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTelegramBotModal">
                <i class="fas fa-plus me-1"></i> Tambah Bot
            </button>
        </div>
        <div class="card-body">
            <div class="alert alert-info py-2 small mb-3">
                <i class="fas fa-circle-info me-1"></i>
                Bot Telegram cukup 1 <strong>Bot Token</strong> dari
                <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> -- tidak perlu scan QR
                seperti bot WhatsApp. Pelanggan/admin yang mau menerima pesan dari bot ini WAJIB kirim
                <code>/start &lt;IDPEL&gt;</code> ke bot itu dulu satu kali (link otomatis tersedia setelah bot dibuat),
                supaya sistem tahu <em>chat_id</em> Telegram mereka.
            </div>

            <?php if (empty($telegramBots)): ?>
                <div class="text-center text-muted py-4">Belum ada bot Telegram. Klik "Tambah Bot" untuk mulai.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Nama Bot</th>
                            <th>Username</th>
                            <th>Link Hubungkan</th>
                            <th style="min-width:220px;">Penerima Notifikasi</th>
                            <th style="min-width:180px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($telegramBots as $bot): ?>
                        <?php
                            $botIdAttr = (int)$bot['id'];
                            $botUsername = trim((string)($bot['botusername'] ?? ''));
                            $startLink = $botUsername !== '' ? 'https://t.me/' . htmlspecialchars($botUsername, ENT_QUOTES, 'UTF-8') . '?start=IDPEL_ANDA' : '';
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$bot['namebot'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>@<?= htmlspecialchars($botUsername, ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($startLink !== ''): ?>
                                    <a href="<?= $startLink ?>" target="_blank" rel="noopener" class="small">Buka link (ganti IDPEL_ANDA)</a>
                                <?php else: ?>
                                    <span class="text-muted small">Username belum terbaca</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row g-1">
                                    <?php foreach ($penerimaFieldLabels as $fieldKey => $fieldLabel): ?>
                                    <div class="col-12">
                                        <div class="input-group input-group-sm mb-1">
                                            <span class="input-group-text telegram-field-label" style="font-size:.72rem;min-width:150px;"><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            <input type="text" class="form-control form-control-sm telegram-penerima-input" style="font-size:.75rem;"
                                                data-bot-id="<?= $botIdAttr ?>" data-field="<?= $fieldKey ?>"
                                                value="<?= htmlspecialchars((string)($bot[$fieldKey] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                placeholder="chat_id...">
                                            <button type="button" class="btn btn-outline-secondary btn-sm telegram-save-penerima-btn" data-bot-id="<?= $botIdAttr ?>" data-field="<?= $fieldKey ?>">
                                                <i class="fas fa-save"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <button type="button" class="btn btn-outline-primary btn-sm mb-1 telegram-test-btn" data-bot-id="<?= $botIdAttr ?>">
                                    <i class="fas fa-paper-plane me-1"></i>Test Kirim
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm mb-1 telegram-delete-btn" data-bot-id="<?= $botIdAttr ?>" data-bot-name="<?= htmlspecialchars((string)$bot['namebot'], ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="fas fa-trash me-1"></i>Hapus
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Modal Tambah Bot -->
<div class="modal fade" id="addTelegramBotModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fab fa-telegram me-1"></i>Tambah Bot Telegram</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addTelegramBotForm" action="proses/addtelegrambot.php" method="post">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Bot (label internal)</label>
                        <input type="text" class="form-control" name="namebot" required placeholder="Contoh: CS Utama">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Bot Token</label>
                        <input type="text" class="form-control" name="bottoken" required placeholder="123456789:AAExxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                        <div class="form-text">
                            Dapatkan dari <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>
                            (perintah <code>/newbot</code>).
                        </div>
                    </div>
                    <div id="addTelegramBotStatus" class="alert d-none mb-0"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="addTelegramBotSubmitBtn">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('addTelegramBotForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = e.target;
    var btn = document.getElementById('addTelegramBotSubmitBtn');
    var statusBox = document.getElementById('addTelegramBotStatus');
    btn.disabled = true;
    btn.textContent = 'Memvalidasi token...';
    statusBox.className = 'alert d-none mb-0';

    fetch(form.action, { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                statusBox.className = 'alert alert-success mb-0';
                statusBox.textContent = data.message || 'Bot berhasil ditambahkan.';
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                statusBox.className = 'alert alert-danger mb-0';
                statusBox.textContent = data.message || 'Gagal menambahkan bot.';
            }
        })
        .catch(function () {
            statusBox.className = 'alert alert-danger mb-0';
            statusBox.textContent = 'Terjadi kesalahan koneksi.';
        })
        .finally(function () {
            btn.disabled = false;
            btn.textContent = 'Simpan';
        });
});

document.querySelectorAll('.telegram-test-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var botId = btn.getAttribute('data-bot-id');
        btn.disabled = true;
        var fd = new FormData();
        fd.append('telegram_action', 'test_send');
        fd.append('bot_id', botId);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) { alert(data.message || (data.success ? 'Terkirim.' : 'Gagal.')); })
            .catch(function () { alert('Terjadi kesalahan koneksi.'); })
            .finally(function () { btn.disabled = false; });
    });
});

document.querySelectorAll('.telegram-delete-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var botId = btn.getAttribute('data-bot-id');
        var botName = btn.getAttribute('data-bot-name');
        if (!confirm('Hapus bot "' + botName + '"? Tindakan ini tidak bisa dibatalkan.')) return;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('telegram_action', 'delete_bot');
        fd.append('bot_id', botId);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) { window.location.reload(); }
                else { alert(data.message || 'Gagal menghapus.'); btn.disabled = false; }
            })
            .catch(function () { alert('Terjadi kesalahan koneksi.'); btn.disabled = false; });
    });
});

document.querySelectorAll('.telegram-save-penerima-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var botId = btn.getAttribute('data-bot-id');
        var field = btn.getAttribute('data-field');
        var input = document.querySelector('.telegram-penerima-input[data-bot-id="' + botId + '"][data-field="' + field + '"]');
        btn.disabled = true;
        var fd = new FormData();
        fd.append('telegram_action', 'save_penerima');
        fd.append('bot_id', botId);
        fd.append('field', field);
        fd.append('value', input.value);
        fetch(window.location.pathname, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) alert(data.message || 'Gagal menyimpan.');
            })
            .catch(function () { alert('Terjadi kesalahan koneksi.'); })
            .finally(function () { btn.disabled = false; });
    });
});
</script>

<?php ob_end_flush(); ?>
<?php require 'footer.php'; ?>
