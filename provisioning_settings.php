<?php
/**
 * Provisioning Settings - Server allowlist for joblist provisioning
 * Billing owners can select which servers allow provisioning from joblist
 * Settings stored in database table `provisioning_settings`
 */
require 'cek-sesi.php';
require 'header.php';

// Auto-create settings table
$check_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning_settings'");
if (mysqli_num_rows($check_tbl) == 0) {
    mysqli_query($conn, "CREATE TABLE `provisioning_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `owner` VARCHAR(100) NOT NULL,
        `server_id` INT NOT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_owner_server` (`owner`, `server_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Auto-create cron settings table
$check_cron_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning_cron_settings'");
if (mysqli_num_rows($check_cron_tbl) == 0) {
    mysqli_query($conn, "CREATE TABLE `provisioning_cron_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `owner` VARCHAR(100) NOT NULL UNIQUE,
        `auto_expire_enabled` TINYINT(1) DEFAULT 1,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Get all servers owned by this user
$sql_servers = "SELECT id, PEMILIK, BRAND, AREA, IP FROM server WHERE user_id = " . intval($USER_ID) . " ORDER BY BRAND, AREA";
$result_servers = mysqli_query($conn, $sql_servers);
$my_servers = [];
if ($result_servers) {
    while ($row = mysqli_fetch_assoc($result_servers)) {
        $my_servers[] = $row;
    }
}

// Current allowed servers for this owner (from DB)
$allowed = [];
$stmt_get = $conn->prepare("SELECT server_id FROM provisioning_settings WHERE owner = ?");
if ($stmt_get) {
    $stmt_get->bind_param('s', $ceknama);
    $stmt_get->execute();
    $res_get = $stmt_get->get_result();
    while ($r = $res_get->fetch_assoc()) {
        $allowed[] = (int)$r['server_id'];
    }
    $stmt_get->close();
}

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_provisioning_settings'])) {
    $selected_servers = isset($_POST['allowed_servers']) ? array_map('intval', $_POST['allowed_servers']) : [];
    
    // Delete old settings for this owner
    $stmt_del = $conn->prepare("DELETE FROM provisioning_settings WHERE owner = ?");
    $stmt_del->bind_param('s', $ceknama);
    $stmt_del->execute();
    $stmt_del->close();
    
    // Insert new settings
    $ok = true;
    if (!empty($selected_servers)) {
        $stmt_ins = $conn->prepare("INSERT INTO provisioning_settings (owner, server_id) VALUES (?, ?)");
        foreach ($selected_servers as $sid) {
            $stmt_ins->bind_param('si', $ceknama, $sid);
            if (!$stmt_ins->execute()) { $ok = false; }
        }
        $stmt_ins->close();
    }
    
    if ($ok) {
        $save_message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Pengaturan provisioning berhasil disimpan!</div>';
    } else {
        $save_message = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Gagal menyimpan beberapa pengaturan.</div>';
    }
    
    // Reload
    $allowed = $selected_servers;
}

// Handle cron toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cron_settings'])) {
    $cron_enabled = isset($_POST['auto_expire_enabled']) ? 1 : 0;
    $stmt_cron = $conn->prepare("INSERT INTO provisioning_cron_settings (owner, auto_expire_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE auto_expire_enabled = ?");
    $stmt_cron->bind_param('sii', $ceknama, $cron_enabled, $cron_enabled);
    if ($stmt_cron->execute()) {
        $cron_message = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Pengaturan cron berhasil disimpan!</div>';
    } else {
        $cron_message = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Gagal menyimpan pengaturan cron.</div>';
    }
    $stmt_cron->close();
}

// Load cron setting for this owner
$cron_enabled = 1; // Default ON
$stmt_cron_get = $conn->prepare("SELECT auto_expire_enabled FROM provisioning_cron_settings WHERE owner = ?");
if ($stmt_cron_get) {
    $stmt_cron_get->bind_param('s', $ceknama);
    $stmt_cron_get->execute();
    $res_cron = $stmt_cron_get->get_result();
    if ($res_cron->num_rows > 0) {
        $cron_enabled = (int)$res_cron->fetch_assoc()['auto_expire_enabled'];
    }
    $stmt_cron_get->close();
}

// Auto-create field settings table
$check_field_tbl = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning_field_settings'");
if (mysqli_num_rows($check_field_tbl) == 0) {
    mysqli_query($conn, "CREATE TABLE `provisioning_field_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `owner` VARCHAR(100) NOT NULL,
        `field_type` VARCHAR(30) NOT NULL,
        `field_value` VARCHAR(255) NOT NULL,
        `server_pemilik` VARCHAR(100) NOT NULL DEFAULT '',
        `area` VARCHAR(100) NOT NULL DEFAULT '',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_setting` (`owner`, `field_type`, `field_value`(100), `server_pemilik`, `area`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Handle field settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_field_settings'])) {
    $stmt_del_fs = $conn->prepare("DELETE FROM provisioning_field_settings WHERE owner = ?");
    $stmt_del_fs->bind_param('s', $ceknama);
    $stmt_del_fs->execute();
    $stmt_del_fs->close();

    $ok_fs = true;
    $stmt_ins_fs = $conn->prepare("INSERT INTO provisioning_field_settings (owner, field_type, field_value, server_pemilik, area) VALUES (?, ?, ?, ?, ?)");

    $field_types_map = [
        'field_tipe_bayar' => 'tipe_bayar',
        'field_tipe_tempo' => 'tipe_tempo',
    ];
    foreach ($field_types_map as $post_key => $ft) {
        if (isset($_POST[$post_key]) && is_array($_POST[$post_key])) {
            foreach ($_POST[$post_key] as $val) {
                $val = trim($val);
                $empty = '';
                $stmt_ins_fs->bind_param('sssss', $ceknama, $ft, $val, $empty, $empty);
                if (!$stmt_ins_fs->execute()) $ok_fs = false;
            }
        }
    }

    // Paket & ODP (value format: "PEMILIK|AREA|VALUE")
    foreach (['field_paket' => 'paket', 'field_odp' => 'odp'] as $post_key => $ft) {
        if (isset($_POST[$post_key]) && is_array($_POST[$post_key])) {
            foreach ($_POST[$post_key] as $val) {
                $parts = explode('|', $val, 3);
                if (count($parts) === 3) {
                    $stmt_ins_fs->bind_param('sssss', $ceknama, $ft, $parts[2], $parts[0], $parts[1]);
                    if (!$stmt_ins_fs->execute()) $ok_fs = false;
                }
            }
        }
    }
    $stmt_ins_fs->close();

    $field_message = $ok_fs
        ? '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Pengaturan field provisioning berhasil disimpan!</div>'
        : '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Gagal menyimpan pengaturan field.</div>';
}

// Load current field settings
$field_settings = ['tipe_bayar' => [], 'tipe_tempo' => [], 'paket' => [], 'odp' => []];
$stmt_fs = $conn->prepare("SELECT field_type, field_value, server_pemilik, area FROM provisioning_field_settings WHERE owner = ?");
if ($stmt_fs) {
    $stmt_fs->bind_param('s', $ceknama);
    $stmt_fs->execute();
    $res_fs = $stmt_fs->get_result();
    while ($r = $res_fs->fetch_assoc()) {
        $type = $r['field_type'];
        if (isset($field_settings[$type])) {
            $field_settings[$type][] = $r;
        }
    }
    $stmt_fs->close();
}

// Helper: check if field value is in saved settings
function isFieldChecked($settings, $type, $value, $server = '', $area = '') {
    if (empty($settings[$type])) return false;
    foreach ($settings[$type] as $s) {
        if ($type === 'tipe_bayar' || $type === 'tipe_tempo') {
            if ($s['field_value'] === $value) return true;
        } else {
            if ($s['field_value'] === $value && $s['server_pemilik'] === $server && $s['area'] === $area) return true;
        }
    }
    return false;
}

// Load paket and ODP for allowed servers
$all_paket = [];
$all_odp = [];
$server_labels = [];
foreach ($my_servers as $srv) {
    if (!empty($allowed) && !in_array($srv['id'], $allowed)) continue;
    $pemilik = $srv['PEMILIK'];
    $area_val = $srv['AREA'];
    $brand = $srv['BRAND'];
    $key = $pemilik . '|' . $area_val;
    $server_labels[$key] = $brand . ' - ' . $area_val;

    $esc_pemilik = mysqli_real_escape_string($conn, $pemilik);
    $esc_area = mysqli_real_escape_string($conn, $area_val);

    $res_paket = mysqli_query($conn, "SELECT DISTINCT PAKET FROM paket WHERE PEMILIK = '$esc_pemilik' AND AREA = '$esc_area' ORDER BY PAKET");
    if ($res_paket) {
        while ($rp = mysqli_fetch_assoc($res_paket)) {
            $all_paket[$key][] = $rp['PAKET'];
        }
    }

    $res_odp = mysqli_query($conn, "SELECT KODE, NAME FROM odp WHERE PEMILIK = '$esc_pemilik' AND AREA = '$esc_area' ORDER BY KODE");
    if ($res_odp) {
        while ($ro = mysqli_fetch_assoc($res_odp)) {
            $all_odp[$key][] = $ro;
        }
    }
}
?>

<style>
    .provisioning-theme-fix {
        --prov-surface: #f8fafc;
        --prov-surface-soft: #eef2f7;
        --prov-border: #d5dde8;
        --prov-text: #1e293b;
        --prov-muted: #64748b;
    }

    body.app-theme-dark .provisioning-theme-fix,
    body.dark-version .provisioning-theme-fix,
    body.dark .provisioning-theme-fix,
    body.theme-dark .provisioning-theme-fix,
    body[data-theme="dark"] .provisioning-theme-fix {
        --prov-surface: rgba(15, 23, 42, 0.62);
        --prov-surface-soft: rgba(30, 41, 59, 0.75);
        --prov-border: rgba(59, 130, 246, 0.25);
        --prov-text: #e2e8f0;
        --prov-muted: #cbd5e1;
    }

    .provisioning-theme-fix .cron-status-panel {
        border-radius: 0.5rem;
        border: 1px solid var(--prov-border);
        background: var(--prov-surface);
        color: var(--prov-text);
    }
    .provisioning-theme-fix .cron-status-panel.is-enabled {
        border-color: rgba(var(--bs-success-rgb, 25, 135, 84), 0.45);
        background: rgba(var(--bs-success-rgb, 25, 135, 84), 0.16);
    }
    .provisioning-theme-fix .cron-status-panel.is-disabled {
        border-color: rgba(var(--bs-danger-rgb, 220, 53, 69), 0.45);
        background: rgba(var(--bs-danger-rgb, 220, 53, 69), 0.16);
    }
    .provisioning-theme-fix .cron-status-title,
    .provisioning-theme-fix .cron-status-note,
    .provisioning-theme-fix .field-settings .form-check-label,
    .provisioning-theme-fix .field-settings .field-section-title,
    .provisioning-theme-fix .field-settings .accordion-button,
    .provisioning-theme-fix .field-settings .accordion-body {
        color: var(--prov-text);
    }
    .provisioning-theme-fix .cron-status-note {
        opacity: 0.95;
    }

    .provisioning-theme-fix .field-settings .field-group-card {
        background: var(--prov-surface);
        border: 1px solid var(--prov-border);
    }
    .provisioning-theme-fix .field-settings .field-section-icon {
        color: var(--bs-primary, #0d6efd);
    }
    .provisioning-theme-fix .field-settings .accordion-item {
        background: transparent;
        border-color: var(--prov-border);
    }
    .provisioning-theme-fix .field-settings .accordion-button {
        background: var(--prov-surface-soft);
        border: 0;
        box-shadow: none;
    }
    .provisioning-theme-fix .field-settings .accordion-button:not(.collapsed) {
        background: var(--bs-primary-bg-subtle, rgba(var(--bs-primary-rgb, 13, 110, 253), 0.2));
        color: var(--prov-text);
    }
    .provisioning-theme-fix .field-settings .accordion-button::after {
        opacity: 0.9;
    }
    .provisioning-theme-fix .field-settings .accordion-body {
        background: var(--prov-surface-soft);
    }
    .provisioning-theme-fix .field-settings .empty-note {
        color: var(--prov-muted);
    }
</style>

<div class="container-fluid py-4 provisioning-theme-fix">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6><i class="fas fa-cog"></i> Pengaturan Provisioning dari Joblist</h6>
                        <p class="text-sm text-muted">Pilih server yang mengizinkan provisioning langsung dari Joblist (teknisi lapangan)</p>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (isset($save_message)) echo $save_message; ?>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Cara kerja:</strong> Server yang dicentang akan tersedia untuk teknisi saat melakukan provisioning di halaman evidence Joblist. 
                        Pelanggan yang di-provisioning oleh teknisi akan berstatus <span class="badge bg-warning text-dark">PENDING</span> selama 3 hari 
                        dan memerlukan approval Anda di menu <a href="provisioning_approval.php">Provisioning Joblist</a>.
                    </div>
                    
                    <form method="post">
                        <input type="hidden" name="save_provisioning_settings" value="1">
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-items-center">
                                <thead>
                                    <tr>
                                        <th class="text-center" style="width:60px;">
                                            <input type="checkbox" id="selectAll" onclick="toggleAll(this)">
                                        </th>
                                        <th>Server (PEMILIK)</th>
                                        <th>Brand</th>
                                        <th>Area</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($my_servers)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">Tidak ada server ditemukan. Buat server terlebih dahulu.</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($my_servers as $srv): ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" name="allowed_servers[]" value="<?php echo $srv['id']; ?>" class="server-checkbox"
                                                <?php echo in_array($srv['id'], $allowed) ? 'checked' : ''; ?>>
                                        </td>
                                        <td><?php echo htmlspecialchars($srv['PEMILIK']); ?></td>
                                        <td><?php echo htmlspecialchars($srv['BRAND']); ?></td>
                                        <td><?php echo htmlspecialchars($srv['AREA']); ?></td>
                                        <td><code><?php echo htmlspecialchars($srv['IP']); ?></code></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan Pengaturan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Cron Auto-Expire Card -->
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6><i class="fas fa-clock"></i> Auto Expire & Hapus Secret (Cron)</h6>
                    <p class="text-sm text-muted">Otomatis menghapus secret PPPoE dari MikroTik/RADIUS jika provisioning sudah 3 hari belum di-approve</p>
                </div>
                <div class="card-body">
                    <?php if (isset($cron_message)) echo $cron_message; ?>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Perhatian:</strong> Jika fitur ini <strong>aktif</strong>, cron akan otomatis menghapus secret PPPoE dari MikroTik dan entry RADIUS 
                        untuk provisioning yang sudah melewati masa berlaku 3 hari. Jika <strong>nonaktif</strong>, secret akan tetap ada di MikroTik meskipun status berubah EXPIRED.
                    </div>

                    <form method="post">
                        <input type="hidden" name="save_cron_settings" value="1">
                        <div class="d-flex align-items-center justify-content-between p-3 cron-status-panel <?php echo $cron_enabled ? 'is-enabled' : 'is-disabled'; ?>">
                            <div>
                                <h6 class="mb-1 cron-status-title">
                                    <i class="fas fa-<?php echo $cron_enabled ? 'toggle-on' : 'toggle-off'; ?> fa-lg"></i>
                                    Auto Expire Cron: <strong><?php echo $cron_enabled ? 'AKTIF' : 'NONAKTIF'; ?></strong>
                                </h6>
                                <small class="cron-status-note">
                                    <?php echo $cron_enabled 
                                        ? 'Cron akan otomatis menghapus secret dari MikroTik/RADIUS saat provisioning expired.' 
                                        : 'Secret PPPoE akan tetap tersimpan di MikroTik meskipun provisioning expired.'; ?>
                                </small>
                            </div>
                            <div>
                                <div class="form-check form-switch ms-3">
                                    <input class="form-check-input" type="checkbox" name="auto_expire_enabled" id="cronToggle" style="width:50px;height:25px;" <?php echo $cron_enabled ? 'checked' : ''; ?> onchange="this.form.submit()">
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php
                    $cfg_prov = file_exists(__DIR__ . '/config.json') ? json_decode(file_get_contents(__DIR__ . '/config.json'), true) : [];
                    $domain_prov = trim($cfg_prov['URL'] ?? '');
                    if ($domain_prov !== '' && stripos($domain_prov, 'http://') !== 0 && stripos($domain_prov, 'https://') !== 0) {
                        $domain_prov = 'https://' . ltrim($domain_prov, '/');
                    }
                    $cron_url = $domain_prov . '/crm/billing/provisioning_cron.php';
                    ?>
                </div>
            </div>

            <!-- Field Provisioning Settings Card -->
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6><i class="fas fa-sliders-h"></i> Pengaturan Opsi Field Provisioning</h6>
                    <p class="text-sm text-muted">Tentukan opsi apa saja yang tersedia untuk teknisi saat provisioning dari Joblist</p>
                </div>
                <div class="card-body">
                    <?php if (isset($field_message)) echo $field_message; ?>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Catatan:</strong> Jika tidak ada opsi yang dicentang pada suatu kategori, maka <strong>semua opsi</strong> akan tersedia secara default.
                    </div>

                    <form method="post" class="field-settings">
                        <input type="hidden" name="save_field_settings" value="1">

                        <!-- Tipe Bayar -->
                        <div class="p-3 rounded mb-3 field-group-card">
                            <h6 class="mb-3 field-section-title"><i class="fas fa-money-bill-wave field-section-icon"></i> Tipe Bayar</h6>
                            <div class="d-flex gap-4">
                                <div class="form-check">
                                    <input type="checkbox" name="field_tipe_bayar[]" value="prabayar" class="form-check-input" id="fs_tb_prabayar"
                                        <?php echo isFieldChecked($field_settings, 'tipe_bayar', 'prabayar') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="fs_tb_prabayar">Prabayar</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="field_tipe_bayar[]" value="pascabayar" class="form-check-input" id="fs_tb_pascabayar"
                                        <?php echo isFieldChecked($field_settings, 'tipe_bayar', 'pascabayar') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="fs_tb_pascabayar">Pasca Bayar</label>
                                </div>
                            </div>
                        </div>

                        <!-- Tipe Tempo -->
                        <div class="p-3 rounded mb-3 field-group-card">
                            <h6 class="mb-3 field-section-title"><i class="fas fa-calendar-alt field-section-icon"></i> Tipe Tempo</h6>
                            <div class="d-flex gap-4">
                                <div class="form-check">
                                    <input type="checkbox" name="field_tipe_tempo[]" value="mengikuti_tanggal_tempo" class="form-check-input" id="fs_tt_fixed"
                                        <?php echo isFieldChecked($field_settings, 'tipe_tempo', 'mengikuti_tanggal_tempo') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="fs_tt_fixed">Fixed Due Date</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="field_tipe_tempo[]" value="mengikuti_tanggal_bayar" class="form-check-input" id="fs_tt_aktivasi"
                                        <?php echo isFieldChecked($field_settings, 'tipe_tempo', 'mengikuti_tanggal_bayar') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="fs_tt_aktivasi">Rolling Due Date</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" name="field_tipe_tempo[]" value="monthversary" class="form-check-input" id="fs_tt_monthversary"
                                        <?php echo isFieldChecked($field_settings, 'tipe_tempo', 'monthversary') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="fs_tt_monthversary">Monthversary Due Date</label>
                                </div>
                            </div>
                        </div>

                        <!-- Paket -->
                        <div class="p-3 rounded mb-3 field-group-card">
                            <h6 class="mb-3 field-section-title"><i class="fas fa-box field-section-icon"></i> Paket</h6>
                            <?php if (empty($all_paket)): ?>
                                <p class="empty-note mb-0">Tidak ada paket ditemukan. Pastikan server sudah dipilih di pengaturan di atas.</p>
                            <?php else: ?>
                                <div class="accordion" id="accordionPaket">
                                    <?php $pi = 0; foreach ($all_paket as $key => $pakets): $pi++;
                                        list($pemilik_p, $area_p) = explode('|', $key);
                                        $label_p = isset($server_labels[$key]) ? $server_labels[$key] : $key;
                                        $collapseIdP = 'paket_' . md5($key);
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseIdP; ?>">
                                                <input type="checkbox" class="form-check-input me-2" onclick="event.stopPropagation(); toggleGroupCb(this, '.paket-cb-<?php echo $pi; ?>');">
                                                <?php echo htmlspecialchars($label_p); ?>
                                                <span class="badge bg-primary ms-2"><?php echo count($pakets); ?> paket</span>
                                            </button>
                                        </h2>
                                        <div id="<?php echo $collapseIdP; ?>" class="accordion-collapse collapse" data-bs-parent="#accordionPaket">
                                            <div class="accordion-body py-2">
                                                <?php foreach ($pakets as $p):
                                                    $val_p = $pemilik_p . '|' . $area_p . '|' . $p;
                                                    $chk_p = isFieldChecked($field_settings, 'paket', $p, $pemilik_p, $area_p) ? 'checked' : '';
                                                ?>
                                                <div class="form-check">
                                                    <input type="checkbox" name="field_paket[]" value="<?php echo htmlspecialchars($val_p); ?>"
                                                        class="form-check-input paket-cb-<?php echo $pi; ?>" <?php echo $chk_p; ?>>
                                                    <label class="form-check-label"><?php echo htmlspecialchars($p); ?></label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- ODP -->
                        <div class="p-3 rounded mb-3 field-group-card">
                            <h6 class="mb-3 field-section-title"><i class="fas fa-network-wired field-section-icon"></i> ODP</h6>
                            <?php if (empty($all_odp)): ?>
                                <p class="empty-note mb-0">Tidak ada ODP ditemukan. Pastikan server sudah dipilih dan memiliki data ODP.</p>
                            <?php else: ?>
                                <div class="accordion" id="accordionODP">
                                    <?php $oi = 0; foreach ($all_odp as $key => $odps): $oi++;
                                        list($pemilik_o, $area_o) = explode('|', $key);
                                        $label_o = isset($server_labels[$key]) ? $server_labels[$key] : $key;
                                        $collapseIdO = 'odp_' . md5($key);
                                    ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button collapsed py-2" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseIdO; ?>">
                                                <input type="checkbox" class="form-check-input me-2" onclick="event.stopPropagation(); toggleGroupCb(this, '.odp-cb-<?php echo $oi; ?>');">
                                                <?php echo htmlspecialchars($label_o); ?>
                                                <span class="badge bg-success ms-2"><?php echo count($odps); ?> ODP</span>
                                            </button>
                                        </h2>
                                        <div id="<?php echo $collapseIdO; ?>" class="accordion-collapse collapse" data-bs-parent="#accordionODP">
                                            <div class="accordion-body py-2" style="max-height:300px;overflow-y:auto;">
                                                <?php foreach ($odps as $o):
                                                    $val_o = $pemilik_o . '|' . $area_o . '|' . $o['KODE'];
                                                    $chk_o = isFieldChecked($field_settings, 'odp', $o['KODE'], $pemilik_o, $area_o) ? 'checked' : '';
                                                ?>
                                                <div class="form-check">
                                                    <input type="checkbox" name="field_odp[]" value="<?php echo htmlspecialchars($val_o); ?>"
                                                        class="form-check-input odp-cb-<?php echo $oi; ?>" <?php echo $chk_o; ?>>
                                                    <label class="form-check-label"><?php echo htmlspecialchars($o['KODE'] . ' (' . $o['NAME'] . ')'); ?></label>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="text-end mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Simpan Pengaturan Field
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
function toggleAll(master) {
    document.querySelectorAll('.server-checkbox').forEach(function(cb) {
        cb.checked = master.checked;
    });
}
function toggleGroupCb(master, selector) {
    document.querySelectorAll(selector).forEach(function(cb) {
        cb.checked = master.checked;
    });
}
</script>

<?php require 'footer.php'; ?>
