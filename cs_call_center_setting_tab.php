<?php
// cs_call_center_setting_tab.php - Partial (di-include dari cs_call_center.php
// Tab "Pengaturan CS Agent"). Butuh $_csSettings, $_csRingtoneOptions, $AKSES
// dari file pemanggil -- BUKAN halaman berdiri sendiri.
?>
<div class="card shadow-sm mb-4" id="cardCsccSettings">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
        <span class="fw-bold"><i class="fas fa-sliders-h me-2"></i>Pengaturan CS Agent</span>
        <span id="csccSettingStatusBadge" class="badge <?php echo !empty($_csSettings['enabled']) ? 'bg-success' : 'bg-secondary'; ?>">
            <?php echo !empty($_csSettings['enabled']) ? 'AKTIF' : 'NONAKTIF'; ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($AKSES === 'ADMIN'): ?>
        <div class="alert alert-secondary py-2 small mb-3">
            <i class="fas fa-circle-info me-1"></i>
            Pengaturan server TURN/STUN sekarang GLOBAL untuk semua akun, dikelola dari menu
            <a href="webrtc_turn_settings.php" class="alert-link">Server TURN/STUN (Call)</a> di Administrator Panel --
            tidak ada lagi di sini.
        </div>
        <?php endif; ?>
        <form id="formCsccSettings" class="row g-3">
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="csccEnabledToggle"
                        <?php echo !empty($_csSettings['enabled']) ? 'checked' : ''; ?> style="width:3em;height:1.5em;">
                    <label class="form-check-label fw-semibold" for="csccEnabledToggle">Aktifkan Fitur CS Call Center</label>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Ringtone Panggilan Masuk (Agent)</label>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select" name="agent_ringtone" id="csccRingtoneSelect">
                        <?php foreach ($_csRingtoneOptions as $file => $labelText): ?>
                        <option value="<?php echo htmlspecialchars($file); ?>" <?php echo ($_csSettings['agent_ringtone'] ?? 'bell1.mp3') === $file ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelText); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnPreviewRingtone"><i class="fas fa-play"></i></button>
                </div>
                <audio id="csccRingtonePreview" preload="none"></audio>
            </div>
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Timeout Panggilan Masuk (detik)</label>
                <input type="number" class="form-control" name="ring_timeout_seconds" min="5" max="120"
                    value="<?php echo (int) ($_csSettings['ring_timeout_seconds'] ?? 30); ?>">
                <div class="form-text">Kalau agent tidak angkat dalam durasi ini, panggilan otomatis dianggap ditolak
                    dan dialihkan ke agent lain yang available (5-120 detik).</div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Simpan Pengaturan</button>
                <span id="csccSettingSaveMsg" class="small ms-2"></span>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('formCsccSettings');
    const saveMsg = document.getElementById('csccSettingSaveMsg');
    const statusBadge = document.getElementById('csccSettingStatusBadge');
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new URLSearchParams(new FormData(form));
            // checkbox yang tidak dicentang tidak ikut terkirim oleh FormData -- pastikan tetap ada key-nya
            if (!formData.has('enabled')) formData.set('enabled', '0'); else formData.set('enabled', '1');
            formData.set('action', 'save_settings');
            saveMsg.textContent = 'Menyimpan...';
            saveMsg.className = 'small ms-2 text-muted';
            fetch('cs_call_center.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(r => r.json())
            .then(data => {
                saveMsg.textContent = data.message || '';
                saveMsg.className = 'small ms-2 ' + (data.success ? 'text-success' : 'text-danger');
                if (data.success) {
                    const enabled = document.getElementById('csccEnabledToggle').checked;
                    statusBadge.textContent = enabled ? 'AKTIF' : 'NONAKTIF';
                    statusBadge.className = 'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
                }
            })
            .catch(() => { saveMsg.textContent = 'Gagal menghubungi server.'; saveMsg.className = 'small ms-2 text-danger'; });
        });
    }

    const btnPreview = document.getElementById('btnPreviewRingtone');
    const select = document.getElementById('csccRingtoneSelect');
    const previewAudio = document.getElementById('csccRingtonePreview');
    if (btnPreview) {
        btnPreview.addEventListener('click', function () {
            previewAudio.src = 'assets/notifcall/' + select.value;
            previewAudio.currentTime = 0;
            previewAudio.play().catch(() => {});
        });
    }
})();
</script>
