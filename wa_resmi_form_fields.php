<?php
/**
 * Partial form fields untuk modal Tambah/Edit Integrasi WA Resmi.
 * Diinclude dari wa_resmi_integrasi.php. Variabel $row (array|null) dan
 * $providerMeta harus sudah tersedia dari scope pemanggil.
 *
 * Saat Edit ($row sudah ada provider-nya), field grup lain di-skip di
 * SERVER (bukan cuma disembunyikan lewat CSS/JS) supaya tidak bergantung ke
 * JavaScript sama sekali -- ini juga mencegah field dengan `name` yang sama
 * antar provider (mis. `access_token` dipakai cloud/qiscus/custom) ikut
 * ke-submit dari grup yang tidak aktif saat form di-Simpan.
 * Saat Tambah ($row masih null), semua grup tetap dirender supaya JS
 * (waResmiApplyProviderVisibility) bisa memfilter interaktif begitu user
 * memilih Penyedia di dropdown.
 */
$row = $row ?? null;
$val = function ($key, $default = '') use ($row) {
    return htmlspecialchars((string)($row[$key] ?? $default));
};
$currentGroup = $row !== null ? ($providerMeta[$row['provider'] ?? '']['group'] ?? '') : null;
$showGroup = function ($groups) use ($currentGroup) {
    if ($currentGroup === null) {
        return true;
    }
    return in_array($currentGroup, explode(',', $groups), true);
};
?>
<input type="hidden" name="id" value="<?= $row['id'] ?? 0 ?>">

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label fw-bold">Nama Integrasi</label>
        <input type="text" class="form-control" name="nama_integrasi" value="<?= $val('nama_integrasi') ?>" placeholder="mis. WA Resmi Produksi" required>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-bold">Penyedia (Provider)</label>
        <select class="form-control" name="provider" required>
            <option value="">-- Pilih Penyedia --</option>
            <?php foreach ($providerMeta as $key => $meta): ?>
                <option value="<?= $key ?>" <?= ($row['provider'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($meta['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<hr>

<?php
$refBoxStyle = 'background-color:#1a233a !important;border:1px solid rgba(59,130,246,.25) !important;border-radius:8px;padding:14px 16px;font-size:.85em;';
$refTextStyle = 'color:#e2e8f0 !important;';
$refTheadStyle = 'background-color:rgba(59,130,246,.12) !important;';
$refCellStyle = 'color:#e2e8f0 !important;border-color:rgba(59,130,246,.15) !important;';
?>


<div class="row g-3">
    <?php if ($showGroup('cloud,qiscus,custom')): ?>
    <div class="col-md-6" data-group="cloud,qiscus,custom">
        <label class="form-label fw-bold">Base URL</label>
        <input type="text" class="form-control" name="base_url" value="<?= $val('base_url') ?>" placeholder="https://...">
        <small class="text-muted">Sesuaikan dengan dashboard/API portal penyedia Anda.</small>
    </div>
    <?php endif; ?>

    <?php if ($showGroup('cloud')): ?>
    <div class="col-md-6" data-group="cloud">
        <label class="form-label fw-bold">API Version</label>
        <input type="text" class="form-control" name="api_version" value="<?= $val('api_version', 'v22.0') ?>" placeholder="v22.0">
    </div>

    <div class="col-md-6" data-group="cloud">
        <label class="form-label fw-bold">Phone Number ID</label>
        <input type="text" class="form-control" name="phone_number_id" value="<?= $val('phone_number_id') ?>" placeholder="dari Meta Business Manager / dashboard BSP">
    </div>
    <div class="col-md-6" data-group="cloud">
        <label class="form-label fw-bold">WABA ID</label>
        <input type="text" class="form-control" name="waba_id" value="<?= $val('waba_id') ?>" placeholder="WhatsApp Business Account ID (opsional)">
    </div>
    <div class="col-md-6" data-group="cloud">
        <label class="form-label fw-bold">Access Token</label>
        <input type="password" class="form-control" name="access_token" value="<?= $val('access_token') ?>" placeholder="Permanent access token">
    </div>
    <?php endif; ?>

    <?php if ($showGroup('qiscus')): ?>
    <div class="col-md-4" data-group="qiscus">
        <label class="form-label fw-bold">App ID</label>
        <input type="text" class="form-control" name="app_id" value="<?= $val('app_id') ?>">
    </div>
    <div class="col-md-4" data-group="qiscus">
        <label class="form-label fw-bold">Channel ID</label>
        <input type="text" class="form-control" name="channel_id" value="<?= $val('channel_id') ?>">
    </div>
    <div class="col-md-4" data-group="qiscus">
        <label class="form-label fw-bold">Secret Key</label>
        <input type="password" class="form-control" name="access_token" value="<?= $val('access_token') ?>">
    </div>
    <?php endif; ?>

    <?php if ($showGroup('custom')): ?>
    <div class="col-md-12" data-group="custom">
        <label class="form-label fw-bold">Endpoint Path Kirim Pesan</label>
        <input type="text" class="form-control" name="custom_endpoint_path" value="<?= $val('custom_endpoint_path') ?>" placeholder="/whatsapp/v1/messages">
        <small class="text-muted">Path endpoint kirim pesan sesuai dokumentasi penyedia (digabung ke Base URL).</small>
    </div>
    <?php endif; ?>

    <?php if ($showGroup('cloud,qiscus,custom')): ?>
    <div class="col-md-6" data-group="cloud,qiscus,custom">
        <label class="form-label fw-bold">Nomor WhatsApp Terdaftar (Sender)</label>
        <input type="text" class="form-control" name="sender_number" value="<?= $val('sender_number') ?>" placeholder="628xxxxxxxxxx">
    </div>
    <?php endif; ?>

    <?php if ($showGroup('custom')): ?>
    <div class="col-md-4" data-group="custom">
        <label class="form-label fw-bold">Tipe Otentikasi</label>
        <select class="form-control" name="auth_header_type">
            <option value="bearer" <?= ($row['auth_header_type'] ?? 'bearer') === 'bearer' ? 'selected' : '' ?>>Bearer Token</option>
            <option value="apikey" <?= ($row['auth_header_type'] ?? '') === 'apikey' ? 'selected' : '' ?>>Header API Key</option>
            <option value="basic" <?= ($row['auth_header_type'] ?? '') === 'basic' ? 'selected' : '' ?>>Basic Auth</option>
        </select>
    </div>
    <div class="col-md-4" data-group="custom">
        <label class="form-label fw-bold">Nama Header</label>
        <input type="text" class="form-control" name="auth_header_name" value="<?= $val('auth_header_name', 'Authorization') ?>">
    </div>
    <div class="col-md-4" data-group="custom">
        <label class="form-label fw-bold">Token / API Key / user:pass</label>
        <input type="password" class="form-control" name="access_token" value="<?= $val('access_token') ?>">
    </div>
    <div class="col-md-12" data-group="custom">
        <label class="form-label fw-bold">Template Body JSON</label>
        <textarea class="form-control" name="custom_body_template" rows="3" placeholder='{"to":"{{to}}","message":"{{message}}","sender":"{{sender}}"}'><?= $val('custom_body_template') ?></textarea>
        <small class="text-muted">Gunakan placeholder <code>{{to}}</code>, <code>{{message}}</code>, <code>{{sender}}</code>.</small>
    </div>
    <?php endif; ?>
</div>

<?php if ($showGroup('cloud')): ?>
<div class="accordion mt-3" data-group="cloud">
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#tplCollapse<?= $row['id'] ?? 'new' ?>">
                Opsi Lanjutan: Kirim via Template (di luar window 24 jam)
            </button>
        </h2>
        <div id="tplCollapse<?= $row['id'] ?? 'new' ?>" class="accordion-collapse collapse">
            <div class="accordion-body">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="use_template_for_notif" id="tplChk<?= $row['id'] ?? 'new' ?>" value="1" <?= !empty($row['use_template_for_notif']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="tplChk<?= $row['id'] ?? 'new' ?>">Kirim notifikasi memakai WhatsApp Template (bukan pesan bebas)</label>
                </div>
                <small class="text-muted d-block mb-2">WhatsApp resmi hanya mengizinkan pesan bebas dalam 24 jam sejak balasan terakhir pelanggan. Untuk notifikasi tagihan/isolir yang dikirim di luar window itu, gunakan template pesan yang sudah disetujui Meta.</small>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Nama Template</label>
                        <input type="text" class="form-control" name="template_name" value="<?= $val('template_name') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Kode Bahasa</label>
                        <input type="text" class="form-control" name="template_language" value="<?= $val('template_language', 'id') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
