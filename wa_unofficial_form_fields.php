<?php
/**
 * Partial form fields untuk modal Tambah/Edit Integrasi WA Unofficial Lain
 * (Fonnte, Wablas, UltraMsg, Evolution API, Gowa Eksternal, Custom).
 * Diinclude dari wabot.php. Variabel $rowU (array|null) dan
 * $unofficialProviderMeta harus sudah tersedia dari scope pemanggil.
 *
 * Saat Edit ($rowU sudah ada provider-nya), field grup lain di-skip di
 * SERVER (bukan cuma disembunyikan lewat CSS/JS) supaya tidak bergantung ke
 * JavaScript sama sekali -- ini juga mencegah field dengan `name` yang sama
 * antar provider (mis. `api_token` dipakai 5 provider berbeda) ikut
 * ke-submit dari grup yang tidak aktif saat form di-Simpan.
 * Saat Tambah ($rowU masih null, provider belum dipilih), semua grup tetap
 * dirender supaya JS (waUnofficialApplyProviderVisibility) bisa memfilter
 * secara interaktif begitu user memilih Penyedia di dropdown.
 */
$rowU = $rowU ?? null;
$valU = function ($key, $default = '') use ($rowU) {
    return htmlspecialchars((string)($rowU[$key] ?? $default));
};
$currentGroupU = $rowU !== null ? ($unofficialProviderMeta[$rowU['provider'] ?? '']['group'] ?? '') : null;
$showGroupU = function ($groups) use ($currentGroupU) {
    if ($currentGroupU === null) {
        return true;
    }
    return in_array($currentGroupU, explode(',', $groups), true);
};
?>
<input type="hidden" name="id" value="<?= $rowU['id'] ?? 0 ?>">

<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label fw-bold">Nama Integrasi</label>
        <input type="text" class="form-control" name="nama_integrasi" value="<?= $valU('nama_integrasi') ?>" placeholder="mis. Fonnte Utama" required>
    </div>
    <div class="col-md-6">
        <label class="form-label fw-bold">Penyedia (Provider)</label>
        <select class="form-control" name="provider" required>
            <option value="">-- Pilih Penyedia --</option>
            <?php foreach ($unofficialProviderMeta as $key => $meta): ?>
                <option value="<?= $key ?>" <?= ($rowU['provider'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($meta['label']) ?></option>
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
    <?php if ($showGroupU('fonnte,wablas,ultramsg,evolution,gowaext,customu')): ?>
    <div class="col-md-6" data-groupu="fonnte,wablas,ultramsg,evolution,gowaext,customu">
        <label class="form-label fw-bold">Base URL</label>
        <input type="text" class="form-control" name="base_url" value="<?= $valU('base_url') ?>" placeholder="https://...">
        <?php if ($showGroupU('fonnte,wablas,ultramsg')): ?>
        <small class="text-muted" data-groupu="fonnte,wablas,ultramsg">Kosongkan untuk pakai default resminya. Isi kalau Anda pakai server cluster/subdomain khusus.</small>
        <?php endif; ?>
        <?php if ($showGroupU('evolution')): ?>
        <small class="text-muted" data-groupu="evolution">Wajib diisi — alamat server Evolution API Anda sendiri (self-hosted).</small>
        <?php endif; ?>
        <?php if ($showGroupU('gowaext')): ?>
        <small class="text-muted" data-groupu="gowaext">Alamat lengkap server gowa eksternal, mis. http://203.0.113.5:3005</small>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($showGroupU('fonnte,evolution')): ?>
    <div class="col-md-6" data-groupu="fonnte,evolution">
        <label class="form-label fw-bold">Token / API Key</label>
        <input type="password" class="form-control" name="api_token" value="<?= $valU('api_token') ?>">
    </div>
    <?php endif; ?>

    <?php if ($showGroupU('wablas')): ?>
    <div class="col-md-6" data-groupu="wablas">
        <label class="form-label fw-bold">Token Wablas</label>
        <input type="password" class="form-control" name="api_token" value="<?= $valU('api_token') ?>">
    </div>
    <div class="col-md-6" data-groupu="wablas">
        <label class="form-label fw-bold">Secret Key Wablas</label>
        <input type="password" class="form-control" name="secret_key" value="<?= $valU('secret_key') ?>">
        <small class="text-muted">Dari menu Device Settings di dashboard Wablas.</small>
    </div>
    <?php endif; ?>

    <?php if ($showGroupU('ultramsg')): ?>
    <div class="col-md-6" data-groupu="ultramsg">
        <label class="form-label fw-bold">Instance ID UltraMsg</label>
        <input type="text" class="form-control" name="instance_id" value="<?= $valU('instance_id') ?>" placeholder="instance12345">
    </div>
    <div class="col-md-6" data-groupu="ultramsg">
        <label class="form-label fw-bold">Token UltraMsg</label>
        <input type="password" class="form-control" name="api_token" value="<?= $valU('api_token') ?>">
    </div>
    <?php endif; ?>

    <?php if ($showGroupU('evolution')): ?>
    <div class="col-md-6" data-groupu="evolution">
        <label class="form-label fw-bold">Nama Instance Evolution API</label>
        <input type="text" class="form-control" name="instance_id" value="<?= $valU('instance_id') ?>">
    </div>
    <?php endif; ?>

    <?php if ($showGroupU('gowaext')): ?>
    <div class="col-md-4" data-groupu="gowaext">
        <label class="form-label fw-bold">Versi Server Gowa</label>
        <select class="form-control" name="gowa_version">
            <option value="legacy" <?= ($rowU['gowa_version'] ?? 'legacy') === 'legacy' ? 'selected' : '' ?>>Lama / Single Device (tanpa Device ID)</option>
            <option value="multi_device" <?= ($rowU['gowa_version'] ?? '') === 'multi_device' ? 'selected' : '' ?>>Baru / Multi-Device (wajib Device ID, mis. v8.x+)</option>
        </select>
        <small class="text-muted">Cek versinya di halaman utama server gowa itu (biasanya tertera di pojok kanan atas, mis. "v8.5.1").</small>
    </div>
    <div class="col-md-4" data-groupu="gowaext">
        <label class="form-label fw-bold">Nama Session / Bot di Server Eksternal</label>
        <input type="text" class="form-control" name="instance_id" value="<?= $valU('instance_id') ?>" placeholder="namebot yang terdaftar di server gowa tsb">
    </div>
    <div class="col-md-4" data-groupu="gowaext">
        <label class="form-label fw-bold">Password Bot di Server Eksternal</label>
        <input type="password" class="form-control" name="api_token" value="<?= $valU('api_token') ?>">
    </div>
    <div class="col-md-4" data-groupu="gowaext">
        <label class="form-label fw-bold">Sender / Device ID</label>
        <input type="text" class="form-control" name="sender_number" value="<?= $valU('sender_number') ?>" placeholder="628xxx atau nama device (mis. hanif)">
        <small class="text-muted">Isi nomor WA (versi lama, opsional) atau <strong>nama Device ID</strong> persis seperti di server gowa itu (wajib kalau pilih "Baru / Multi-Device" di atas).</small>
    </div>
    <?php endif; ?>

    <?php if ($showGroupU('customu')): ?>
    <div class="col-md-12" data-groupu="customu">
        <label class="form-label fw-bold">Endpoint Path Kirim Pesan</label>
        <input type="text" class="form-control" name="custom_endpoint_path" value="<?= $valU('custom_endpoint_path') ?>" placeholder="/send/message">
    </div>
    <div class="col-md-4" data-groupu="customu">
        <label class="form-label fw-bold">Tipe Otentikasi</label>
        <select class="form-control" name="auth_header_type">
            <option value="bearer" <?= ($rowU['auth_header_type'] ?? 'bearer') === 'bearer' ? 'selected' : '' ?>>Bearer Token</option>
            <option value="apikey" <?= ($rowU['auth_header_type'] ?? '') === 'apikey' ? 'selected' : '' ?>>Header API Key</option>
            <option value="basic" <?= ($rowU['auth_header_type'] ?? '') === 'basic' ? 'selected' : '' ?>>Basic Auth</option>
        </select>
    </div>
    <div class="col-md-4" data-groupu="customu">
        <label class="form-label fw-bold">Nama Header</label>
        <input type="text" class="form-control" name="auth_header_name" value="<?= $valU('auth_header_name', 'Authorization') ?>">
    </div>
    <div class="col-md-4" data-groupu="customu">
        <label class="form-label fw-bold">Token / API Key / user:pass</label>
        <input type="password" class="form-control" name="api_token" value="<?= $valU('api_token') ?>">
    </div>
    <div class="col-md-12" data-groupu="customu">
        <label class="form-label fw-bold">Template Body JSON</label>
        <textarea class="form-control" name="custom_body_template" rows="3" placeholder='{"to":"{{to}}","message":"{{message}}","sender":"{{sender}}"}'><?= $valU('custom_body_template') ?></textarea>
        <small class="text-muted">Gunakan placeholder <code>{{to}}</code>, <code>{{message}}</code>, <code>{{sender}}</code>.</small>
    </div>
    <?php endif; ?>

    <?php if ($showGroupU('fonnte,wablas,ultramsg,evolution')): ?>
    <div class="col-md-6" data-groupu="fonnte,wablas,ultramsg,evolution">
        <label class="form-label fw-bold">Nomor Sender (opsional, catatan saja)</label>
        <input type="text" class="form-control" name="sender_number" value="<?= $valU('sender_number') ?>" placeholder="628xxxxxxxxxx">
    </div>
    <?php endif; ?>
</div>
