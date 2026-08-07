<?php
require 'header.php';
require_once __DIR__ . '/notifbot/mailer_helper.php';

// Email SMTP HANYA boleh diakses akun utama (owner) -- kredensial email
// sensitif spt kredensial payment gateway, pola sama persis paymentset.php.
if ($AKSES === 'ASSISTANT') {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Halaman Email SMTP hanya bisa diakses oleh akun utama.</div></div>';
    require 'footer.php';
    exit;
}

$smtp = smtpSettingGet($conn, $ceknama);
?>

<div class="container-fluid py-4 px-3 px-md-4">

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
            <i class="fas fa-envelope"></i>
            <span class="fw-bold">Pengaturan Email SMTP</span>
        </div>
        <div class="card-body">
            <div class="alert alert-info py-2 small mb-3">
                <i class="fas fa-circle-info me-1"></i>
                Dipakai untuk kirim notifikasi/broadcast ke email pelanggan (kolom EMAIL di data pelanggan).
                Pilih <strong>Internal</strong> kalau tidak punya SMTP sendiri (pakai punya sistem), atau
                <strong>External</strong> kalau mau pakai email sendiri (Gmail, domain sendiri, dll).
            </div>

            <form id="smtpSettingForm">
                <div class="mb-3">
                    <div class="btn-group" role="group">
                        <input type="radio" class="btn-check" name="mode" id="smtpModeInternal" value="internal" <?= $smtp['mode'] === 'internal' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="smtpModeInternal">Internal (Default Sistem)</label>

                        <input type="radio" class="btn-check" name="mode" id="smtpModeExternal" value="external" <?= $smtp['mode'] === 'external' ? 'checked' : '' ?>>
                        <label class="btn btn-outline-primary" for="smtpModeExternal">External (SMTP Sendiri)</label>
                    </div>
                </div>

                <div id="smtpExternalFields" class="row g-3" style="display:none;">
                    <div class="col-md-6">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" class="form-control" name="smtp_host" id="smtp_host" value="<?= htmlspecialchars((string)$smtp['smtp_host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.gmail.com">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-control" name="smtp_port" id="smtp_port" value="<?= (int)$smtp['smtp_port'] ?>" placeholder="587">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Enkripsi</label>
                        <select class="form-select" name="smtp_secure" id="smtp_secure">
                            <option value="tls" <?= $smtp['smtp_secure'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                            <option value="ssl" <?= $smtp['smtp_secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="" <?= $smtp['smtp_secure'] === '' ? 'selected' : '' ?>>Tanpa enkripsi</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SMTP Username</label>
                        <input type="text" class="form-control" name="smtp_user" id="smtp_user" value="<?= htmlspecialchars((string)$smtp['smtp_user'], ENT_QUOTES, 'UTF-8') ?>" placeholder="nama@gmail.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">SMTP Password</label>
                        <input type="password" class="form-control" name="smtp_pass" id="smtp_pass" value="<?= htmlspecialchars((string)$smtp['smtp_pass'], ENT_QUOTES, 'UTF-8') ?>" placeholder="App Password / password email">
                        <div class="form-text">Untuk Gmail, pakai "App Password", bukan password login biasa.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Pengirim (From)</label>
                        <input type="email" class="form-control" name="smtp_from_email" id="smtp_from_email" value="<?= htmlspecialchars((string)$smtp['smtp_from_email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Kosongkan = sama dengan SMTP Username">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nama Pengirim (From Name)</label>
                        <input type="text" class="form-control" name="smtp_from_name" id="smtp_from_name" value="<?= htmlspecialchars((string)$smtp['smtp_from_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama perusahaan Anda">
                    </div>
                    <div class="col-12"><hr class="my-1"></div>
                    <div class="col-12">
                        <div class="small text-muted mb-2">
                            <i class="fas fa-inbox me-1"></i>
                            Pengaturan IMAP (opsional) -- dipakai tombol "Cek Inbox" di bawah utk lihat email masuk.
                            Kosongkan Host IMAP kalau sama dengan SMTP Host di atas.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">IMAP Host</label>
                        <input type="text" class="form-control" name="imap_host" id="imap_host" value="<?= htmlspecialchars((string)$smtp['imap_host'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Kosongkan = sama dengan SMTP Host">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Port</label>
                        <input type="number" class="form-control" name="imap_port" id="imap_port" value="<?= (int)$smtp['imap_port'] ?>" placeholder="993">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Enkripsi</label>
                        <select class="form-select" name="imap_secure" id="imap_secure">
                            <option value="ssl" <?= $smtp['imap_secure'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="" <?= $smtp['imap_secure'] === '' ? 'selected' : '' ?>>Tanpa enkripsi</option>
                        </select>
                    </div>
                </div>

                <div id="smtpInternalNote" class="alert alert-secondary py-2 small mb-0" style="display:none;">
                    <i class="fas fa-server me-1"></i>
                    Mode Internal pakai kredensial SMTP default sistem, tidak perlu isi apa-apa.
                    Nama pengirim akan pakai nama perusahaan Anda (kalau diisi di bawah, opsional).
                    <div class="mt-2">
                        <label class="form-label mb-1">Nama Pengirim (opsional)</label>
                        <input type="text" class="form-control form-control-sm" name="smtp_from_name_internal" id="smtp_from_name_internal" value="<?= htmlspecialchars((string)$smtp['smtp_from_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Nama perusahaan Anda" style="max-width:320px;">
                    </div>
                </div>

                <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary" id="smtpSaveBtn">Simpan Pengaturan</button>
                    <div class="input-group" style="max-width:340px;">
                        <input type="email" class="form-control" id="smtpTestEmail" placeholder="Email tujuan test...">
                        <button type="button" class="btn btn-outline-secondary" id="smtpTestBtn">Test Kirim</button>
                    </div>
                    <button type="button" class="btn btn-outline-dark" id="smtpCheckInboxBtn"><i class="fas fa-inbox me-1"></i>Cek Inbox</button>
                </div>
                <div id="smtpStatusBox" class="alert d-none mt-3 mb-0"></div>

                <div id="smtpInboxResult" class="mt-3" style="display:none;">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:24px;"></th>
                                    <th>Dari</th>
                                    <th>Subjek</th>
                                    <th>Tanggal</th>
                                </tr>
                            </thead>
                            <tbody id="smtpInboxRows"></tbody>
                        </table>
                    </div>
                </div>
            </form>
        </div>
    </div>

</div>

<script>
function toggleSmtpModeFields() {
    var isExternal = document.getElementById('smtpModeExternal').checked;
    document.getElementById('smtpExternalFields').style.display = isExternal ? 'flex' : 'none';
    document.getElementById('smtpInternalNote').style.display = isExternal ? 'none' : 'block';
}
document.getElementById('smtpModeInternal').addEventListener('change', toggleSmtpModeFields);
document.getElementById('smtpModeExternal').addEventListener('change', toggleSmtpModeFields);
document.addEventListener('DOMContentLoaded', toggleSmtpModeFields);

function collectSmtpFormData(action) {
    var form = document.getElementById('smtpSettingForm');
    var fd = new FormData(form);
    fd.set('action', action);
    var isExternal = document.getElementById('smtpModeExternal').checked;
    if (!isExternal) {
        // Mode internal: nama pengirim custom (opsional) dipetakan ke smtp_from_name
        fd.set('smtp_from_name', document.getElementById('smtp_from_name_internal').value);
    }
    return fd;
}

function showSmtpStatus(success, message) {
    var box = document.getElementById('smtpStatusBox');
    box.className = 'alert mt-3 mb-0 ' + (success ? 'alert-success' : 'alert-danger');
    box.textContent = message;
}

document.getElementById('smtpSettingForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('smtpSaveBtn');
    btn.disabled = true;
    fetch('proses/save_smtp_setting.php', { method: 'POST', body: collectSmtpFormData('save') })
        .then(function (r) { return r.json(); })
        .then(function (data) { showSmtpStatus(data.success, data.message || (data.success ? 'Tersimpan.' : 'Gagal menyimpan.')); })
        .catch(function () { showSmtpStatus(false, 'Terjadi kesalahan koneksi.'); })
        .finally(function () { btn.disabled = false; });
});

document.getElementById('smtpTestBtn').addEventListener('click', function () {
    var btn = this;
    var testEmail = document.getElementById('smtpTestEmail').value.trim();
    if (!testEmail) {
        showSmtpStatus(false, 'Isi dulu email tujuan test.');
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Mengirim...';
    var fd = collectSmtpFormData('test');
    fd.set('test_email', testEmail);
    fetch('proses/save_smtp_setting.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) { showSmtpStatus(data.success, data.message || (data.success ? 'Email test terkirim.' : 'Gagal mengirim.')); })
        .catch(function () { showSmtpStatus(false, 'Terjadi kesalahan koneksi.'); })
        .finally(function () { btn.disabled = false; btn.textContent = 'Test Kirim'; });
});

function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : str;
    return div.innerHTML;
}

document.getElementById('smtpCheckInboxBtn').addEventListener('click', function () {
    var btn = this;
    var resultBox = document.getElementById('smtpInboxResult');
    var rowsBox = document.getElementById('smtpInboxRows');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Mengecek...';
    resultBox.style.display = 'none';
    rowsBox.innerHTML = '';

    fetch('proses/save_smtp_setting.php', { method: 'POST', body: collectSmtpFormData('check_inbox') })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            showSmtpStatus(data.success, data.message || (data.success ? 'Berhasil cek inbox.' : 'Gagal cek inbox.'));
            if (data.success && Array.isArray(data.messages)) {
                if (data.messages.length === 0) {
                    rowsBox.innerHTML = '<tr><td colspan="4" class="text-muted small">Tidak ada pesan di INBOX.</td></tr>';
                } else {
                    data.messages.forEach(function (msg) {
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td>' + (msg.seen ? '' : '<i class="fas fa-circle text-primary" style="font-size:.5rem;" title="Belum dibaca"></i>') + '</td>' +
                            '<td class="small">' + escapeHtml(msg.from) + '</td>' +
                            '<td class="small' + (msg.seen ? '' : ' fw-bold') + '">' + escapeHtml(msg.subject) + '</td>' +
                            '<td class="small text-muted">' + escapeHtml(msg.date) + '</td>';
                        rowsBox.appendChild(tr);
                    });
                }
                resultBox.style.display = 'block';
            }
        })
        .catch(function () { showSmtpStatus(false, 'Terjadi kesalahan koneksi.'); })
        .finally(function () { btn.disabled = false; btn.innerHTML = '<i class="fas fa-inbox me-1"></i>Cek Inbox'; });
});
</script>

<?php require 'footer.php'; ?>
