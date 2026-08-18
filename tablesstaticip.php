<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Customer_StaticIP', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Customer Static IP.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/staticip_helper.php';
staticipEnsureSchema($conn);

$status = $_GET['pesan'] ?? '';
$editStatus = $_GET['edit'] ?? '';
$deleted = $_GET['deleted'] ?? '';
?>

<?php if ($status === 'berhasil'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Customer Static IP berhasil disimpan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'gagal'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($editStatus === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Customer Static IP berhasil diedit.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($editStatus === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal edit data.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($deleted === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Customer Static IP berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($deleted === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal menghapus data.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php
// Dropdown Server Area dipakai bareng modal Tambah & Edit -- pola scoping
// ASSISTANT-aware sama persis addcustomerform.php.
function staticip_render_server_options($conn, $AKSES, $area_list, $current_user_id, $selectedPemilik = '') {
    if (!$current_user_id) {
        return;
    }
    if ($AKSES == 'ASSISTANT') {
        $q = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE AREA IN ($area_list)");
    } else {
        $q = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE user_id = $current_user_id");
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $area = htmlspecialchars($row['AREA']);
        $connmode = htmlspecialchars($row['CONNECTION_MODE'] ?? 'API');
        $selected = ($row['PEMILIK'] === $selectedPemilik) ? 'selected' : '';
        echo '<option value="' . htmlspecialchars($row['PEMILIK']) . '" data-area="' . $area . '" data-connmode="' . $connmode . '" ' . $selected . '>' . htmlspecialchars($row['BRAND']) . '-' . $area . '</option>';
    }
}

/**
 * Blok OLT auto-registration (port dari addcustomerform.php/editcustomerform.php)
 * -- ditulis 1x sbg fungsi, dipanggil 2x (modal Tambah & Edit) dgn $prefix beda
 * ('addStatic'/'editStatic') supaya ID elemen tidak bentrok antar modal.
 * JS pengendalinya (createOltController(), lihat <script> di bawah) dibuat
 * generik & di-instantiate 2x dgn prefix yg sama.
 */
function staticip_render_olt_block($prefix) {
    ?>
    <div id="<?php echo $prefix; ?>OltWrap" class="d-none mt-3">
        <div>
            <label for="<?php echo $prefix; ?>OltSelect" class="form-label">Pilih OLT di area ini</label>
            <select id="<?php echo $prefix; ?>OltSelect" class="form-select">
                <option value="">-- Pilih OLT --</option>
            </select>
        </div>
        <div id="<?php echo $prefix; ?>OltEmpty" class="small text-muted mt-2 d-none">Tidak ada data OLT untuk server/area ini.</div>
        <div id="<?php echo $prefix; ?>OltInfo" class="small mt-2 d-none text-muted"></div>
        <div id="<?php echo $prefix; ?>OltUnsupported" class="small text-danger mt-2 d-none"></div>

        <div id="<?php echo $prefix; ?>ZteWrap" class="d-none mt-3">
            <div class="border rounded p-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <strong>Register ONT Otomatis</strong>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="<?php echo $prefix; ?>ZteRefreshBtn">Refresh Data OLT</button>
                </div>
                <div id="<?php echo $prefix; ?>ZteLoading" class="alert alert-info d-none d-flex align-items-center gap-2 py-2" role="status" aria-live="polite">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <span id="<?php echo $prefix; ?>ZteLoadingText">Sedang memuat data OLT...</span>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">ONU Unconfig</label>
                        <select id="<?php echo $prefix; ?>OnuSel" class="form-select">
                            <option value="">— Pilih ONU Unconfig —</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Serial Number (SN)</label>
                        <input id="<?php echo $prefix; ?>Sn" type="text" class="form-control" placeholder="ZTEGXXXXXXXX">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Interface GPON OLT</label>
                        <input id="<?php echo $prefix; ?>Intf" type="text" class="form-control" placeholder="gpon-olt_1/2/1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ONU Number</label>
                        <input id="<?php echo $prefix; ?>OnuNo" type="number" class="form-control" placeholder="Auto dari ONU Unconfig" readonly>
                        <small class="text-muted">Terisi otomatis dari pilihan ONU Unconfig.</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Type ONT</label>
                        <select id="<?php echo $prefix; ?>TypeSel" class="form-select"></select>
                        <input id="<?php echo $prefix; ?>TypeManual" type="text" class="form-control mt-2" placeholder="Manual type" style="display:none;">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Profile TCONT</label>
                        <select id="<?php echo $prefix; ?>TcontProfile" class="form-select">
                            <option value="">— Pilih Profile TCONT —</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3 d-flex align-items-center gap-2 flex-wrap">
                    <input type="checkbox" id="<?php echo $prefix; ?>WithCfg" hidden checked>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="<?php echo $prefix; ?>CfgBtn">✓ Config WAN Aktif</button>
                    <small class="text-muted">Setelah register, WAN internet juga langsung dibuat.</small>
                </div>

                <div id="<?php echo $prefix; ?>CfgWrap" class="mt-3 border rounded p-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">VLAN</label>
                            <select id="<?php echo $prefix; ?>VlanSel" class="form-select">
                                <option value="">— Pilih VLAN —</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">VLAN ID Manual</label>
                            <input id="<?php echo $prefix; ?>Vlan" type="number" class="form-control" placeholder="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Service Name</label>
                            <input id="<?php echo $prefix; ?>Svc" type="text" class="form-control" value="HSI">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">VLAN Profile</label>
                            <input id="<?php echo $prefix; ?>VlanProfile" type="text" class="form-control" value="PPPoE">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gemport</label>
                            <input id="<?php echo $prefix; ?>Gemport" type="number" class="form-control" value="1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">COS</label>
                            <input id="<?php echo $prefix; ?>Cos" type="number" class="form-control" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PPPoE Username</label>
                            <input id="<?php echo $prefix; ?>OltUser" type="text" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">PPPoE Password</label>
                            <input id="<?php echo $prefix; ?>OltPass" type="text" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ethuni Ports</label>
                            <input id="<?php echo $prefix; ?>Ethuni" type="text" class="form-control" value="1,2,3">
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="fw-semibold mb-2">Preview Register</div>
                    <div id="<?php echo $prefix; ?>Preview" class="olt-preview-box" style="background:#0b1220;color:#c9d1d9;padding:10px;border-radius:6px;font-family:monospace;font-size:12px;white-space:pre-wrap;min-height:40px;">(Pilih OLT dan ONU unconfig untuk melihat preview)</div>
                </div>
            </div>
        </div>

        <div id="<?php echo $prefix; ?>ProcessWrap" class="mt-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="fw-semibold mb-0">Log Proses</div>
                <button type="button" id="<?php echo $prefix; ?>ProcessToggle" class="btn btn-sm btn-outline-secondary">Tampilkan Log Proses</button>
            </div>
            <div id="<?php echo $prefix; ?>ProcessPanel" class="d-none mt-2">
                <div id="<?php echo $prefix; ?>ProcessLog" class="olt-process-log" style="background:#0b1220;color:#c9d1d9;padding:10px;border-radius:6px;font-family:monospace;font-size:11px;white-space:pre-wrap;max-height:220px;overflow:auto;"></div>
            </div>
        </div>
    </div>
    <?php
}
?>

<?php
// Saran ID Pelanggan (Customer ID) otomatis -- port persis dari addcustomerform.php
// ($inisial/$username sudah tersedia dari header.php->cek-sesi.php).
$kode_terkecil_static = null;
$inisial_static = $inisial;
if ($inisial_static == '') {
    $words = explode(" ", strtoupper($username));
    $initials = "";
    foreach ($words as $word) {
        $initials .= substr($word, 0, 1);
        if (strlen($initials) >= 3) break;
    }
    if (strlen($initials) < 3) {
        $initials .= strtoupper(substr(str_replace(" ", "", $username), strlen($initials), 3 - strlen($initials)));
    }
    $inisial_static = substr($initials, 0, 3);
}

$used_numbers_static = [];
$prefix_like_static = $inisial_static . '-%';
$stmtKodeStatic = $conn->prepare("SELECT IDPEL FROM `pelanggan` WHERE `IDPEL` LIKE ?");
if ($stmtKodeStatic) {
    $stmtKodeStatic->bind_param("s", $prefix_like_static);
    $stmtKodeStatic->execute();
    $resultKodeStatic = $stmtKodeStatic->get_result();
    while ($rowKodeStatic = $resultKodeStatic->fetch_assoc()) {
        if (preg_match('/^' . preg_quote($inisial_static, '/') . '-(\d{3})(?:@|$)/', $rowKodeStatic['IDPEL'], $matchesStatic)) {
            $nomorStatic = (int) $matchesStatic[1];
            if ($nomorStatic >= 1 && $nomorStatic <= 999) {
                $used_numbers_static[$nomorStatic] = true;
            }
        }
    }
    $stmtKodeStatic->close();
}
$check_prov_tbl_static = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning'");
if ($check_prov_tbl_static && mysqli_num_rows($check_prov_tbl_static) > 0) {
    $stmtProvKodeStatic = $conn->prepare("SELECT idpel FROM provisioning WHERE idpel LIKE ? AND status='PENDING'");
    if ($stmtProvKodeStatic) {
        $stmtProvKodeStatic->bind_param("s", $prefix_like_static);
        $stmtProvKodeStatic->execute();
        $resultProvKodeStatic = $stmtProvKodeStatic->get_result();
        while ($rowProvStatic = $resultProvKodeStatic->fetch_assoc()) {
            if (preg_match('/^' . preg_quote($inisial_static, '/') . '-(\d{3})(?:@|$)/', $rowProvStatic['idpel'], $matchesStatic)) {
                $nomorStatic = (int) $matchesStatic[1];
                if ($nomorStatic >= 1 && $nomorStatic <= 999) {
                    $used_numbers_static[$nomorStatic] = true;
                }
            }
        }
        $stmtProvKodeStatic->close();
    }
}
for ($i = 1; $i <= 999; $i++) {
    if (!isset($used_numbers_static[$i])) {
        $kode_terkecil_static = $inisial_static . "-" . str_pad($i, 3, '0', STR_PAD_LEFT);
        break;
    }
}
$customerid_suggestion_static = $kode_terkecil_static ? ($kode_terkecil_static . "@" . date('dmy')) : '';
?>

<style>
    /* ==== Status/Overview -- disalin dari tables.php (Customer PPPoE), versi ringkas
       fokus monitoring: tanpa peta/grafik/ACS panel besar/SLA history/tombol billing.
       Lihat memory project_staticip_status_overview.md. ==== */
    .status-action-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 4px;
        margin-bottom: 4px;
    }

    .status-action-row > div[id^="remoteContainer-"] {
        display: inline-flex;
    }

    .customer-action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: auto;
        min-height: 0;
        padding: 0.2rem 0.5rem;
        font-size: 10px;
        font-weight: 600;
        line-height: 1.2;
        text-align: center;
        white-space: nowrap;
        border-radius: 999px;
    }

    .modal-action-btn {
        --bs-btn-color: var(--white, #ffffff);
        --bs-btn-hover-color: var(--white, #ffffff);
        --bs-btn-active-color: var(--white, #ffffff);
        --bs-btn-disabled-color: var(--white, #ffffff);
        color: var(--white, #ffffff) !important;
        text-decoration: none !important;
        font-weight: 700 !important;
        opacity: 1 !important;
        text-shadow: none !important;
        -webkit-text-fill-color: var(--white, #ffffff) !important;
        background-clip: border-box !important;
        -webkit-background-clip: border-box !important;
    }

    .modal-action-btn:hover,
    .modal-action-btn:focus,
    .modal-action-btn:active {
        color: var(--white, #ffffff) !important;
        text-decoration: none !important;
        opacity: 1 !important;
        -webkit-text-fill-color: var(--white, #ffffff) !important;
    }

    .modal-action-btn,
    div[id^="remoteContainerModal-"] .modal-action-btn {
        width: 100% !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .modal-action-btn *,
    div[id^="remoteContainerModal-"] .modal-action-btn * {
        color: var(--white, #ffffff) !important;
        -webkit-text-fill-color: var(--white, #ffffff) !important;
        opacity: 1 !important;
        text-shadow: none !important;
    }

    .modal-action-btn.btn-secondary {
        background-color: var(--logo-secondary, #3b82f6) !important;
        border-color: var(--logo-secondary, #3b82f6) !important;
    }

    .modal-action-btn.btn-secondary:hover,
    .modal-action-btn.btn-secondary:focus,
    .modal-action-btn.btn-secondary:active {
        background-color: var(--logo-primary, #2563eb) !important;
        border-color: var(--logo-primary, #2563eb) !important;
    }

    .overview-modal-dialog {
        width: 100vw;
        max-width: 100vw;
        height: 100vh;
        margin: 0;
    }

    .modal[id^="exampleoverviewstatic"] {
        --bs-modal-width: 100vw;
        padding: 0 !important;
    }

    .modal[id^="exampleoverviewstatic"] .overview-modal-dialog,
    .modal[id^="exampleoverviewstatic"] .modal-dialog {
        width: 100vw !important;
        max-width: 100vw !important;
        min-width: 100vw !important;
        height: 100vh !important;
        margin: 0 !important;
    }

    .modal[id^="exampleoverviewstatic"] .overview-modal-content,
    .modal[id^="exampleoverviewstatic"] .modal-content {
        width: 100vw !important;
        max-width: 100vw !important;
        height: 100vh !important;
        max-height: 100vh !important;
        border-radius: 0 !important;
    }

    .overview-modal-content {
        flex-direction: column !important;
        display: flex !important;
        width: 100%;
        height: 100vh;
        max-height: 100vh;
        border-radius: 0;
    }

    .overview-modal-body {
        font-size: 13.5px;
        padding: 16px;
        flex: 1 1 auto;
        overflow-y: auto;
    }

    .modal[id^="exampleoverviewstatic"] .overview-main-row {
        align-items: flex-start;
    }

    .modal[id^="exampleoverviewstatic"] .overview-formdata-col .form-label {
        font-size: 13px;
        margin-bottom: 0.25rem;
        color: #334155;
        font-weight: 700;
    }

    .modal[id^="exampleoverviewstatic"] .overview-formdata-col .form-control {
        font-size: 14px;
        padding: 0.45rem 0.65rem;
    }

    .modal[id^="exampleoverviewstatic"] .overview-formdata-col .mb-1 {
        margin-bottom: 0.75rem !important;
    }

    body.app-theme-dark .modal[id^="exampleoverviewstatic"] .overview-formdata-col .form-label {
        color: #cbd5e1 !important;
    }

    .modal[id^="exampleoverviewstatic"] .overview-health-col .overview-meta-item {
        margin-bottom: 0.25rem !important;
        line-height: 1.35;
    }

    .modal[id^="exampleoverviewstatic"] .overview-health-stack {
        margin-top: 0.45rem;
    }

    body.app-theme-dark .modal[id^="exampleoverviewstatic"] [id^="data-info-"] {
        background: #0f172a;
        border-color: #334155;
        color: #e2e8f0 !important;
    }

    [id^="data-status2-"],
    [id^="data-status-"],
    [id^="data-paket-aktif-"],
    [id^="data-paket-aktif-modal-"],
    [id^="data-realtime-"],
    [id^="data-info-"] {
        color: #1e293b !important;
        font-weight: 700;
        font-size: 11px !important;
        line-height: 1.6;
    }

    body.app-theme-dark [id^="data-status2-"],
    body.app-theme-dark [id^="data-status-"],
    body.app-theme-dark [id^="data-paket-aktif-"],
    body.app-theme-dark [id^="data-paket-aktif-modal-"],
    body.app-theme-dark [id^="data-realtime-"],
    body.app-theme-dark [id^="data-info-"] {
        color: #e2e8f0 !important;
    }

    [id^="data-status2-"] .badge,
    [id^="data-status-"] .badge,
    [id^="data-paket-aktif-"] .badge,
    [id^="data-paket-aktif-modal-"] .badge,
    [id^="data-realtime-"] .badge,
    [id^="data-info-"] .badge {
        color: #ffffff !important;
        text-shadow: none !important;
        border: 1px solid rgba(15, 23, 42, 0.22);
        opacity: 1 !important;
        font-size: 10px !important;
    }

    .status-top-badges {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        align-items: center;
        gap: 4px;
        margin-bottom: 4px;
    }

    .status-top-badges > * {
        margin: 0 !important;
        display: inline-flex !important;
    }

    [id^="data-realtime-"],
    [id^="data-info-"] {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        column-gap: 10px;
        row-gap: 0;
    }

    .status-detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 6px;
        font-size: 10.5px !important;
        font-weight: 700;
        line-height: 1.5;
        text-align: left;
    }

    .status-detail-label {
        color: #8898aa !important;
        font-weight: 500;
        white-space: nowrap;
    }

    .status-detail-value {
        text-align: right;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100px;
    }

    body.app-theme-dark .status-detail-label {
        color: #94a3b8 !important;
    }

    [id^="data-status2-"] .badge.bg-secondary,
    [id^="data-status-"] .badge.bg-secondary,
    [id^="data-paket-aktif-"] .badge.bg-secondary,
    [id^="data-paket-aktif-modal-"] .badge.bg-secondary,
    [id^="data-realtime-"] .badge.bg-secondary,
    [id^="data-info-"] .badge.bg-secondary {
        background-color: #334155 !important;
        border-color: #1e293b !important;
        color: #f8fafc !important;
    }

    /* ==== Panel Data ACS (GenieACS) di modal Overview -- versi TAMPILAN saja,
       disalin dari tables.php. Tombol Edit SSID/WAN & Restart perangkat SENGAJA
       tidak diikutkan krn butuh subsistem modal editor terpisah (di luar
       permintaan "tampilkan" -- lihat memory project_staticip_status_overview.md). ==== */
    .acs-device-card {
        background: #fff;
        color: #27272a;
        border-color: #d4d4d8;
    }

    .acs-device-card,
    .acs-device-card * {
        color: #27272a;
    }

    .acs-device-info-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 6px;
    }

    .acs-device-info-item {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px 10px;
        min-width: 0;
    }

    .acs-device-info-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #f68013;
        margin-bottom: 3px;
    }

    .acs-device-info-value {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #27272a;
        word-break: break-word;
    }

    .acs-ssid-section {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-ssid-title {
        font-weight: 700;
        margin-bottom: 8px;
        color: #27272a;
    }

    .acs-ssid-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .acs-ssid-row {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px 10px;
        min-width: 0;
    }

    .acs-ssid-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 6px;
    }

    .acs-ssid-name {
        font-size: 12px;
        font-weight: 700;
        color: #f68013;
    }

    .acs-ssid-value {
        display: block;
        font-size: 12px;
        font-weight: 600;
        color: #27272a;
        word-break: break-word;
    }

    .acs-param-key {
        color: #f68013 !important;
        font-weight: 700;
    }

    .acs-param-value {
        color: #27272a !important;
        font-weight: 600;
    }

    .acs-empty-text {
        color: #71717a !important;
    }

    .acs-wan-section {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-wan-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .acs-wan-title {
        font-weight: 700;
        color: #27272a;
    }

    .acs-wan-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
    }

    .acs-wan-card {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 10px 12px;
    }

    .acs-wan-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }

    .acs-wan-card-title {
        font-weight: 700;
        color: #27272a;
    }

    .acs-wan-param-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 12px;
    }

    .acs-wan-summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px 12px;
        margin-bottom: 8px;
    }

    .acs-wan-param-item {
        min-width: 0;
    }

    .acs-wan-param-key {
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: #f68013;
        margin-bottom: 2px;
        word-break: break-word;
    }

    .acs-wan-param-value {
        font-size: 12px;
        font-weight: 600;
        color: #27272a;
        word-break: break-word;
    }

    .acs-wan-empty {
        color: #71717a;
        font-size: 12px;
    }

    .acs-local-hosts-wrap {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-local-hosts-title {
        font-weight: 700;
        margin-bottom: 8px;
        color: #27272a;
    }

    .acs-local-hosts-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .acs-local-host-card {
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px 10px;
        line-height: 1.45;
        font-size: 12px;
    }

    .acs-local-host-name {
        font-weight: 700;
        margin-bottom: 4px;
        color: #f68013;
    }

    .acs-local-host-item {
        margin: 2px 0;
        color: #27272a;
        word-break: break-word;
    }

    .acs-raw-toggle-wrap {
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d4d4d8;
    }

    .acs-raw-params-list {
        max-height: 260px;
        overflow-y: auto;
        background: #f4f4f5;
        border: 1px solid #d4d4d8;
        border-radius: 8px;
        padding: 8px;
        margin-top: 8px;
        font-size: 11px;
    }

    .acs-raw-param-row {
        display: flex;
        gap: 8px;
        padding: 3px 0;
        border-bottom: 1px dashed #e4e4e7;
        word-break: break-all;
    }

    .acs-raw-param-row .acs-param-key {
        flex: 0 0 55%;
    }

    body.app-theme-dark .acs-local-host-card {
        background: #1f2937 !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-local-host-name {
        color: #f68013 !important;
    }
    body.app-theme-dark .acs-local-host-item {
        color: #f3f4f6 !important;
    }
    body.app-theme-dark .acs-local-hosts-title {
        color: #f8fafc !important;
    }
    body.app-theme-dark .acs-local-hosts-wrap,
    body.app-theme-dark .acs-raw-toggle-wrap {
        border-top-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-raw-params-list {
        background: #1f2937 !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-raw-param-row {
        border-bottom-color: rgba(255, 255, 255, 0.08) !important;
    }

    .acs-section-title {
        color: #27272a;
    }

    body.app-theme-dark .acs-device-card {
        background: #111827 !important;
        color: #e5e7eb !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    body.app-theme-dark .acs-device-card,
    body.app-theme-dark .acs-device-card * {
        color: #e5e7eb !important;
    }
    body.app-theme-dark .acs-device-info-item,
    body.app-theme-dark .acs-ssid-row,
    body.app-theme-dark .acs-wan-card {
        background: #1f2937 !important;
        border-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-device-info-label,
    body.app-theme-dark .acs-ssid-name,
    body.app-theme-dark .acs-wan-param-key,
    body.app-theme-dark .acs-param-key {
        color: #f68013 !important;
    }
    body.app-theme-dark .acs-device-info-value,
    body.app-theme-dark .acs-ssid-value,
    body.app-theme-dark .acs-wan-param-value,
    body.app-theme-dark .acs-param-value {
        color: #f3f4f6 !important;
    }
    body.app-theme-dark .acs-ssid-title,
    body.app-theme-dark .acs-wan-title,
    body.app-theme-dark .acs-wan-card-title {
        color: #f8fafc !important;
    }
    body.app-theme-dark .acs-ssid-section,
    body.app-theme-dark .acs-wan-section {
        border-top-color: rgba(255, 255, 255, 0.12) !important;
    }
    body.app-theme-dark .acs-wan-empty,
    body.app-theme-dark .acs-empty-text {
        color: #cbd5e1 !important;
    }
    body.app-theme-dark .acs-section-title {
        color: #f8fafc !important;
    }

    [id^="acs-sync-info-"] {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 10px;
        font-weight: 700;
        border: 1px solid transparent;
        line-height: 1.2;
    }

    .acs-sync-badge-fresh {
        background: #dcfce7 !important;
        color: #166534 !important;
        border-color: #86efac !important;
    }

    .acs-sync-badge-stale {
        background: #fef9c3 !important;
        color: #854d0e !important;
        border-color: #fde047 !important;
    }

    .acs-sync-badge-expired {
        background: #fee2e2 !important;
        color: #991b1b !important;
        border-color: #fecaca !important;
    }

    body.app-theme-dark .acs-sync-badge-fresh {
        background: #14532d !important;
        color: #dcfce7 !important;
        border-color: #22c55e !important;
    }

    body.app-theme-dark .acs-sync-badge-stale {
        background: #713f12 !important;
        color: #fef9c3 !important;
        border-color: #eab308 !important;
    }

    body.app-theme-dark .acs-sync-badge-expired {
        background: #7f1d1d !important;
        color: #fee2e2 !important;
        border-color: #f87171 !important;
    }

    /* Mode Cepat: sembunyikan kolom Status dari tabel (bukan hanya stop cek live) --
       aksi WhatsApp/Live Chat tetap bisa diakses lewat modal Overview (klik baris). */
    body.staticip-fast-status-mode-active #staticipCustomerTable thead th.staticip-status-col,
    body.staticip-fast-status-mode-active #staticipCustomerTable tbody td.staticip-status-col {
        display: none;
    }
</style>

<script>
/* ==== Mesin status-fetch (Online/Offline/EXPIRED, dst) -- disalin VERBATIM dari
   tables.php (Customer PPPoE), TANPA ubah logic. Semua DOM write di sini sudah
   dijaga if(element)-checks di source aslinya, jadi aman dipakai walau target
   elemen ACS-panel-besar/SLA-history/chart/peta SENGAJA tidak ikut dirender di
   halaman ini (function-nya otomatis no-op utk elemen yang tidak ada). Ditaruh
   SEBELUM tabel di-render krn tiap baris punya tag "script" (startFetching)
   inline yang harus jalan SETELAH fungsi ini terdefinisi. Lihat memory
   project_staticip_status_overview.md. ==== */
let trafficHistory = {};

async function getDbmFromOnulist(mac, serverListStr, idPel) {
    let macPrefix = mac.split(':').slice(0,5).join(':');
    let pppoeNeedle = String(idPel || '').trim().toLowerCase();
    let servers = serverListStr.replace(/'/g, '').split(',').map(s => s.trim());

    try {
        let fileRes = await fetch('getdata/list_onulist_files.php');
        let fileList = await fileRes.json();

        for (let fileName of fileList) {
            if (servers.some(s => fileName.includes(s))) {
                try {
                    let res = await fetch(`getdata/getonulist.php?file=${fileName}`);
                    let text = await res.text();
                    let lines = text.split('\n');
                    for (let line of lines) {
                        const lineLower = line.toLowerCase();
                        const matchByMac = macPrefix && lineLower.includes(macPrefix.toLowerCase());
                        const matchByPppoe = pppoeNeedle && (
                            lineLower.includes(`pppoe: ${pppoeNeedle}`) ||
                            lineLower.includes(`| nama: ${pppoeNeedle} |`) ||
                            lineLower.includes(`,${pppoeNeedle},`)
                        );

                        if (!matchByMac && !matchByPppoe) continue;

                        let dataPart = line.split('|').find(p => p.trim().startsWith('Data:'));
                        if (dataPart) {
                            let values = dataPart.replace('Data:', '').split(',');
                            let rxDbm = parseFloat(values[values.length - 2]) || 0;
                            let txDbm = parseFloat(values[values.length - 1]) || 0;
                            return { rxDbm, txDbm, file: fileName };
                        }
                    }
                } catch(e) {
                    console.error(`Error reading ${fileName}:`, e);
                }
            }
        }
    } catch(e) {
        console.error(e);
    }

    return { rxDbm: 0, txDbm: 0, file: null };
}

function buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, isModal) {
    const uptimeIdAttr = idPel ? ` id="data-uptime${isModal ? '-modal' : ''}-${idPel}"` : '';
    return `
        <div class="status-detail-row"><span class="status-detail-label">Kuota</span><span class="status-detail-value">${kuota || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Pemakaian</span><span class="status-detail-value">${pemakaian || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Uptime</span><span class="status-detail-value"${uptimeIdAttr}>${uptime || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Link Up</span><span class="status-detail-value">${linkUp || 'N/A'}</span></div>
        <div class="status-detail-row"><span class="status-detail-label">Link Down</span><span class="status-detail-value">${linkDown || 'N/A'}</span></div>`;
}

function renderOfflineRadiusUI(idPel) {
    const retryBtn = `<button type="button" class="btn btn-link btn-sm p-0 ms-1" style="text-decoration:none;" onclick="retryFetchData('${idPel}')" title="Muat ulang status"><i class="fas fa-sync-alt"></i></button>`;
    const statusHtml = `<span class="badge badge-sm bg-gradient-danger">Offline (RADIUS)</span>${retryBtn}`;

    try {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) statusEl.innerHTML = statusHtml;
        const realtimeEl = document.getElementById(`data-realtime-${idPel}`);
        if (realtimeEl) realtimeEl.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (RADIUS)</span>';

        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
        if (modalStatusEl) modalStatusEl.innerHTML = statusHtml;
        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
        if (modalInfoEl) modalInfoEl.innerHTML = '<br><span class="badge badge-sm bg-gradient-danger">Offline (RADIUS)</span>';
    } catch (e) {}
}

function renderFetchErrorUI(idPel, message) {
    const retryBtn = `<button type="button" class="btn btn-link btn-sm p-0 ms-1" style="text-decoration:none;" onclick="retryFetchData('${idPel}')" title="Muat ulang status"><i class="fas fa-sync-alt"></i></button>`;
    const statusHtml = `<span class="badge badge-sm bg-danger">Error</span>${retryBtn}`;

    try {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) statusEl.innerHTML = statusHtml;
        const realtimeEl = document.getElementById(`data-realtime-${idPel}`);
        if (realtimeEl) realtimeEl.innerHTML = `<span class="badge badge-sm bg-danger">${message}</span>`;

        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
        if (modalStatusEl) modalStatusEl.innerHTML = statusHtml;
        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
        if (modalInfoEl) modalInfoEl.innerHTML = `<br><span class="badge badge-sm bg-danger">${message}</span>`;
    } catch (e) {}
}

window.__customerFetchParams = window.__customerFetchParams || {};

function retryFetchData(idPel) {
    const p = window.__customerFetchParams[idPel];
    if (!p) return;
    const loadingHtml = '<span class="badge badge-sm bg-gradient-warning"><i class="fas fa-sync-alt fa-spin"></i> Memuat ulang...</span>';
    try {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) statusEl.innerHTML = loadingHtml;
        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
        if (modalStatusEl) modalStatusEl.innerHTML = loadingHtml;
    } catch (e) {}
    fetchData(idPel, p.ip, p.us, p.ps, p.mode);
}

function updateTrafficChart(idPel, downloadVal, uploadVal) {
    const chartEl = document.getElementById(`trafficChart${idPel}`);
    const ctx = (chartEl && chartEl.tagName === 'CANVAS') ? chartEl.getContext('2d') : null;
    if (!ctx) return;

    const downloadNum = typeof downloadVal === 'number' ? downloadVal : (parseFloat(downloadVal) || 0);
    const uploadNum = typeof uploadVal === 'number' ? uploadVal : (parseFloat(uploadVal) || 0);

    if (!trafficHistory[idPel]) {
        trafficHistory[idPel] = { labels: [], download: [], upload: [] };
    }
    const history = trafficHistory[idPel];

    if (history.labels.length >= 10) {
        history.labels.shift();
        history.download.shift();
        history.upload.shift();
    }

    history.labels.push(new Date().toLocaleTimeString());
    history.download.push(downloadNum);
    history.upload.push(uploadNum);

    try {
        const existingChart = window[`trafficChartInstance${idPel}`];
        if (existingChart && existingChart.canvas === chartEl) {
            existingChart.data.labels = history.labels;
            existingChart.data.datasets[0].data = history.download;
            existingChart.data.datasets[1].data = history.upload;
            existingChart.update('none');
        } else {
            if (existingChart) {
                try { existingChart.destroy(); } catch (destroyErr) {}
            }
            if (typeof Chart === 'undefined') return;
            window[`trafficChartInstance${idPel}`] = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: history.labels,
                    datasets: [
                        { label: 'Download (Mbps)', data: history.download, borderColor: 'blue', backgroundColor: 'rgba(0,0,255,0.2)', fill: true },
                        { label: 'Upload (Mbps)', data: history.upload, borderColor: 'green', backgroundColor: 'rgba(0,128,0,0.2)', fill: true }
                    ]
                },
                options: { responsive: true, scales: { y: { beginAtZero: true } } }
            });
        }
    } catch (chartErr) {
        console.error(`Chart update error for ${idPel}:`, chartErr);
    }
}

const liveTrafficState = {};

async function refreshLiveTraffic(idPel, ipServer, userServer, passwordServer) {
    if (liveTrafficState[idPel]) return;
    liveTrafficState[idPel] = true;

    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);

        const response = await fetch('getdata/get_live_traffic.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ip: ipServer, idpel: idPel, us: userServer || '', ps: passwordServer || '' }),
            signal: controller.signal
        });
        clearTimeout(timeoutId);

        const data = await response.json().catch(() => null);
        if (!data) return;

        const download = (data.download !== undefined && data.download !== null) ? data.download : 'Null';
        const upload = (data.upload !== undefined && data.upload !== null) ? data.upload : 'Null';
        const text = `${download} / ${upload} Mbps`;

        const tableEl = document.getElementById(`data-downup-${idPel}`);
        if (tableEl) tableEl.textContent = text;

        updateTrafficChart(idPel, data.download, data.upload);

        const modalEl = document.getElementById(`data-downup-modal-${idPel}`);
        if (modalEl) modalEl.textContent = text;

        if (data.remote_ip) {
            const ipTableEl = document.getElementById(`data-ip-${idPel}`);
            if (ipTableEl) ipTableEl.textContent = data.remote_ip;
            const ipModalEl = document.getElementById(`data-ip-modal-${idPel}`);
            if (ipModalEl) ipModalEl.textContent = data.remote_ip;
        }
        if (data.uptime) {
            const uptimeTableEl = document.getElementById(`data-uptime-${idPel}`);
            if (uptimeTableEl) uptimeTableEl.textContent = data.uptime;
            const uptimeModalEl = document.getElementById(`data-uptime-modal-${idPel}`);
            if (uptimeModalEl) uptimeModalEl.textContent = data.uptime;
        }
    } catch (e) {
        console.warn(`Live traffic error for ${idPel}:`, e);
    } finally {
        liveTrafficState[idPel] = false;
    }
}

function getCustomerSlaBadgeClass(percent) {
    if (percent >= 99.5) return 'badge badge-sm bg-gradient-success';
    if (percent >= 95) return 'badge badge-sm bg-gradient-warning';
    if (percent <= 0) return 'badge badge-sm bg-gradient-info';
    return 'badge badge-sm bg-gradient-danger';
}

function renderCustomerSlaBadge(idPel) {
    const targets = [
        document.getElementById(`data-sla-${idPel}`),
        document.getElementById(`data-sla-modal-${idPel}`)
    ].filter(Boolean);
    if (targets.length === 0) return;

    const payload = window.customerSlaSummary && window.customerSlaSummary.customers
        ? window.customerSlaSummary.customers[idPel]
        : null;

    if (!payload) {
        targets.forEach(target => {
            target.innerHTML = '<span class="badge badge-sm bg-gradient-info">SLA PELANGGAN 0.00%</span>';
        });
        return;
    }

    const percent = Number(payload.sla_percent || 0);
    targets.forEach(target => {
        target.innerHTML = `<span class="${getCustomerSlaBadgeClass(percent)}">SLA PELANGGAN ${percent.toFixed(2)}%</span>`;
    });
}

function fetchData(idPel, ipServer, userServer, passwordServer, customerMode) {
    try {
        if (!idPel || !ipServer) {
            console.error('Missing required parameters');
            return Promise.resolve();
        }

        const fetchController = new AbortController();
        const fetchTimeoutId = setTimeout(() => fetchController.abort(), 15000);

        return fetch('getdata/get_cached_pppoe_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ip: ipServer, idpel: idPel, us: userServer || '', ps: passwordServer || '' }),
            signal: fetchController.signal
        })
        .then(response => {
            clearTimeout(fetchTimeoutId);
            if (!response) throw new Error('No response');
            return response.json().catch(() => null);
        })
        .then(async data => {
            try {
                if (!data) {
                    throw new Error('No data received');
                }

                let macToCheck = data.status === "Online" ? (data.active_caller_id || 'N/A') : (data.last_caller_secret || 'N/A');

                let serverListStr = <?php echo json_encode(isset($server_list) ? (string)$server_list : ''); ?>;
                let rxTxDbm = null;
                try {
                    rxTxDbm = await getDbmFromOnulist(macToCheck, serverListStr, idPel);
                } catch (e) {
                    console.warn(`DBM error for ${idPel}:`, e);
                    rxTxDbm = { rxDbm: 0, txDbm: 0, file: null };
                }
                if (!rxTxDbm) rxTxDbm = { rxDbm: 0, txDbm: 0, file: null };

                let rxBadge = 'bg-secondary';
                let rxDisplay = 'Null';

                try {
                    if (rxTxDbm && rxTxDbm.rxDbm && rxTxDbm.rxDbm !== 0) {
                        rxBadge = rxTxDbm.rxDbm < -27 ? 'badge-sm bg-gradient-danger' : 'badge-sm bg-gradient-success';
                        rxDisplay = rxTxDbm.rxDbm;
                    }
                } catch (e) {
                    console.warn('RX display error:', e);
                    rxDisplay = 'Null';
                }

                let rxRedaman = 'Null';
                let rxRedamanBadge = 'bg-secondary';
                try {
                    let acsResponse = await fetch('getdata/acs_cache_data.php?idpel=' + encodeURIComponent(idPel));
                    if (!acsResponse) throw new Error('No ACS response');
                    let acsData = await acsResponse.json().catch(() => null);
                    if (acsData && acsData.devices && Array.isArray(acsData.devices) && acsData.devices.length > 0) {
                        let firstDevice = acsData.devices[0];
                        rxRedaman = (firstDevice && firstDevice.rx_power) ? firstDevice.rx_power : 'Null';
                    }
                    if (rxRedaman !== 'Null') {
                        const rxRedamanVal = parseFloat(rxRedaman);
                        if (!isNaN(rxRedamanVal)) {
                            rxRedamanBadge = rxRedamanVal < -27 ? 'badge-sm bg-gradient-danger' : 'badge-sm bg-gradient-success';
                        }
                    }
                } catch (e) {
                    console.error('ACS error:', e);
                    rxRedaman = 'Null';
                }

                let paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                const loginViaForProfile = (data.login_via || '').toLowerCase();

                const isRadiusConfigured = (customerMode || '').toUpperCase().indexOf('RADIUS') !== -1;
                const localProfileEmpty = ['', 'N/A', 'NULL'].includes(paketAktifRaw.toUpperCase());

                if (loginViaForProfile === 'local' && !isRadiusConfigured && !localProfileEmpty) {
                    paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                } else {
                    try {
                        let radiusResponse = await fetch('getdata/getpackagefromradius.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ idpel: idPel })
                        });
                        if (radiusResponse && radiusResponse.ok) {
                            let radiusData = await radiusResponse.json().catch(() => null);
                            if (radiusData && radiusData.package && radiusData.package !== 'Null') {
                                paketAktifRaw = radiusData.package;
                            } else {
                                paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                            }
                        }
                    } catch (e) {
                        console.warn(`Could not fetch package from RADIUS for ${idPel}:`, e);
                        paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                    }
                }

                const isPaketExpired = String(paketAktifRaw).trim().toUpperCase() === "EXPIRED";
                const paketAktifBadgeClass = isPaketExpired
                    ? 'badge badge-sm bg-gradient-danger'
                    : 'badge badge-sm bg-gradient-success';
                const paketAktifHtml = `<span class="${paketAktifBadgeClass}">${paketAktifRaw}</span>`;

                try {
                    const paketAktifEl = document.getElementById(`data-paket-aktif-${idPel}`);
                    if (paketAktifEl) paketAktifEl.innerHTML = paketAktifHtml;

                    const paketAktifModalEl = document.getElementById(`data-paket-aktif-modal-${idPel}`);
                    if (paketAktifModalEl) paketAktifModalEl.innerHTML = paketAktifHtml;
                } catch (e) {
                    console.warn(`Paket aktif display error for ${idPel}:`, e);
                }

                let statusElement2 = null;
                let realtimeElement = null;

                try {
                    statusElement2 = document.getElementById(`data-status2-${idPel}`) || null;
                    realtimeElement = document.getElementById(`data-realtime-${idPel}`) || null;
                } catch (e) {
                    console.warn(`Element access error for ${idPel}:`, e);
                }

                if (data.status === "Online") {
                    try {
                        const rawLoginVia = (data.login_via || '').toLowerCase();
                        const loginVia = (isRadiusConfigured && rawLoginVia === 'local') ? 'radius' : (data.login_via || 'Null');
                        const remoteIp = data.remote_ip || 'Null';
                        const mac = data.active_caller_id || 'Null';
                        const download = (data.download !== undefined && data.download !== null) ? data.download : 'Null';
                        const upload = (data.upload !== undefined && data.upload !== null) ? data.upload : 'Null';
                        const kuota = data.kuota || 'N/A';
                        const uptime = data.uptime || 'N/A';
                        const linkUp = data.last_link_up || 'N/A';
                        const linkDown = data.last_link_down || 'N/A';
                        const pemakaian = data.pemakaian || 'N/A';

                        if (statusElement2) {
                            statusElement2.innerHTML = `<span class="badge badge-sm bg-gradient-success">Online (${loginVia})</span>`;
                        }

                        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
                        if (modalStatusEl) {
                            modalStatusEl.innerHTML = `<span class="badge badge-sm bg-gradient-success">Online (${loginVia})</span>`;
                        }

                        if (realtimeElement) {
                            realtimeElement.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Down/Up</span><span class="status-detail-value" id="data-downup-${idPel}">${download} / ${upload} Mbps</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">IP</span><span class="status-detail-value" id="data-ip-${idPel}">${remoteIp}</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">MAC</span><span class="status-detail-value">${mac}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, false)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
                        if (modalInfoEl) {
                            modalInfoEl.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Down/Up</span><span class="status-detail-value" id="data-downup-modal-${idPel}">${download} / ${upload} Mbps</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">IP</span><span class="status-detail-value" id="data-ip-modal-${idPel}">${remoteIp}</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">MAC</span><span class="status-detail-value">${mac}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, true)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        try {
                            addRemoteButton(idPel, ipServer, userServer, passwordServer, remoteIp);
                        } catch (btnErr) {
                            console.warn(`Remote button error: ${btnErr}`);
                        }

                        refreshLiveTraffic(idPel, ipServer, userServer, passwordServer);
                    } catch (onlineErr) {
                        console.error(`Online status error: ${onlineErr}`);
                    }

                } else {
                    try {
                        const lastDisconnect = data.ceklastdisconnect || 'Null';
                        const kuota = data.kuota || 'N/A';
                        const uptime = data.uptime || 'N/A';
                        const linkUp = data.last_link_up || 'N/A';
                        const linkDown = data.last_link_down || 'N/A';
                        const pemakaian = data.pemakaian || 'N/A';

                        if (statusElement2) {
                            statusElement2.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (LOS)</span>';
                        }

                        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
                        if (modalStatusEl) {
                            modalStatusEl.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (LOS)</span>';
                        }

                        if (realtimeElement) {
                            realtimeElement.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Status</span><span class="status-detail-value">Offline</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">Last Disconnect</span><span class="status-detail-value">${lastDisconnect}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
                        if (modalInfoEl) {
                            modalInfoEl.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Status</span><span class="status-detail-value">Offline</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">Last Disconnect</span><span class="status-detail-value">${lastDisconnect}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        }

                        try {
                            const container = document.getElementById(`remoteContainer-${idPel}`);
                            if (container) container.innerHTML = '';
                            const modalContainer = document.getElementById(`remoteContainerModal-${idPel}`);
                            if (modalContainer) modalContainer.innerHTML = '';
                        } catch (e) {
                            console.warn(`Container clear error: ${e}`);
                        }
                    } catch (offlineErr) {
                        console.error(`Offline status error: ${offlineErr}`);
                    }
                }
            } catch (processErr) {
                console.error(`Data processing error for ${idPel}:`, processErr);
                renderFetchErrorUI(idPel, 'Data processing error');
            }
        })
        .catch(error => {
            clearTimeout(fetchTimeoutId);
            console.error('Fetch error:', error);
            const isRadiusConfiguredMode = (customerMode || '').toUpperCase().indexOf('RADIUS') !== -1;
            if (isRadiusConfiguredMode) {
                renderOfflineRadiusUI(idPel);
            } else {
                renderFetchErrorUI(idPel, error && error.name === 'AbortError' ? 'Timeout - server tidak merespon' : 'Fetch Error');
            }
        });
    } catch (outerErr) {
        console.error('FetchData outer error:', outerErr);
        return Promise.resolve();
    }
}

window.__customerFetchOrder = window.__customerFetchOrder || [];
window.__customerFetchQueueStarted = window.__customerFetchQueueStarted || false;
window.__customerVisibleRows = window.__customerVisibleRows || new Set();
window.__customerRowObserver = window.__customerRowObserver || null;

const CUSTOMER_FETCH_GAP_MS = 350;
const CUSTOMER_FETCH_CYCLE_DELAY_MS = 8000;
const CUSTOMER_FETCH_IDLE_RETRY_MS = 500;

function customerFetchDelay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function isSecondaryQtsModalOpen() {
    return Array.prototype.some.call(
        document.querySelectorAll('.qts-modal-overlay'),
        function(el) { return el.style.display === 'flex'; }
    );
}

function getCustomerRowObserver() {
    if (!window.__customerRowObserver) {
        window.__customerRowObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                const idPel = entry.target.getAttribute('data-fetch-idpel');
                if (!idPel) return;
                if (entry.isIntersecting) {
                    window.__customerVisibleRows.add(idPel);
                } else {
                    window.__customerVisibleRows.delete(idPel);
                }
            });
        }, { root: null, rootMargin: '400px 0px', threshold: 0 });
    }
    return window.__customerRowObserver;
}

async function runCustomerFetchQueue() {
    while (true) {
        const order = window.__customerFetchOrder;
        let didFetch = false;
        for (let i = 0; i < order.length; i++) {
            const idPel = order[i];
            const p = window.__customerFetchParams[idPel];
            if (!p) continue;
            if (!window.__customerVisibleRows.has(idPel)) {
                continue;
            }
            while (isSecondaryQtsModalOpen()) {
                await customerFetchDelay(300);
            }
            didFetch = true;
            try {
                await fetchData(idPel, p.ip, p.us, p.ps, p.mode);
            } catch (e) {
                console.warn(`Queue fetch error for ${idPel}:`, e);
            }
            await customerFetchDelay(CUSTOMER_FETCH_GAP_MS);
        }
        await customerFetchDelay(didFetch ? CUSTOMER_FETCH_CYCLE_DELAY_MS : CUSTOMER_FETCH_IDLE_RETRY_MS);
    }
}

function startFetching(idPel, ipServer, userServer, passwordServer, customerMode) {
    window.__customerFetchParams[idPel] = { ip: ipServer, us: userServer, ps: passwordServer, mode: customerMode || '' };

    renderCustomerSlaBadge(idPel);

    if (window.__staticFastStatusMode) {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) {
            statusEl.innerHTML = '<span class="badge badge-sm bg-gradient-secondary">Buka Overview utk cek status</span>';
        }
        return;
    }

    if (!window.__customerFetchOrder.includes(idPel)) {
        window.__customerFetchOrder.push(idPel);
    }

    const rowEl = document.getElementById(`customerRow-${idPel}`);
    if (rowEl) {
        rowEl.setAttribute('data-fetch-idpel', idPel);
        getCustomerRowObserver().observe(rowEl);
    } else {
        window.__customerVisibleRows.add(idPel);
    }

    if (!window.__customerFetchQueueStarted) {
        window.__customerFetchQueueStarted = true;
        runCustomerFetchQueue();
    }
}

function fetchStatusOnDemandIfFastMode(idPel) {
    if (!window.__staticFastStatusMode) return;
    const p = window.__customerFetchParams[idPel];
    if (!p) return;
    fetchData(idPel, p.ip, p.us, p.ps, p.mode).catch(function(e) {
        console.warn(`On-demand fetch error for ${idPel}:`, e);
    });
}

function openMonitorModal(element, idPel, ip, user, password) {
    try {
        return true;
    } catch(error) {
        console.error(`Error in openMonitorModal:`, error);
        return false;
    }
}

/* ==== Panel Data ACS (GenieACS) -- versi TAMPILAN, disalin & diadaptasi dari
   tables.php renderAcsDevicePanel(). Tombol Edit SSID/WAN & Restart perangkat
   SENGAJA dihilangkan (butuh subsistem modal editor terpisah yg belum ada di
   halaman ini) -- sisanya (info device/SSID/WAN/Local Terhubung/raw params)
   sama persis. Endpoint backend REUSE 100% getdata/acs_cache_data.php. ==== */
function acsHtmlEscape(str) {
    return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function acsCleanParams(rawParams) {
    const cleaned = {};
    Object.keys(rawParams || {}).forEach(function(key) {
        if (/\.(_object|_writable|_timestamp|_type|_attributes)$/.test(key)) {
            return;
        }
        const cleanKey = key.replace(/\._value$/, '');
        cleaned[cleanKey] = rawParams[key];
    });
    return cleaned;
}

function formatAcsSyncBadge(cacheAge, cacheExpired) {
    if (cacheAge === null || cacheAge === undefined) {
        return { label: 'SYNC: -', cls: 'acs-sync-badge-stale' };
    }
    const mins = Math.max(0, Math.floor(cacheAge / 60));
    const label = 'SYNC: ' + new Date(Date.now() - cacheAge * 1000).toLocaleString('id-ID') + ' (' + mins + ' mnt)';
    const cls = cacheExpired ? 'acs-sync-badge-expired' : (mins > 5 ? 'acs-sync-badge-stale' : 'acs-sync-badge-fresh');
    return { label: label, cls: cls };
}

function loadAcsDevicePanel(idpel) {
    const body = document.getElementById('acsPanelBody-' + idpel);
    if (!body) return;
    fetch('getdata/acs_cache_data.php?idpel=' + encodeURIComponent(idpel))
        .then(function(res) { return res.json().catch(function() { return null; }); })
        .then(function(data) { renderAcsDevicePanel(idpel, data); })
        .catch(function(err) {
            console.error('ACS panel load error:', err);
            body.innerHTML = '<span class="acs-empty-text">Gagal memuat data ACS.</span>';
        });
}

function renderAcsDevicePanel(idpel, data) {
    const body = document.getElementById('acsPanelBody-' + idpel);
    const syncInfoEl = document.getElementById('acs-sync-info-' + idpel);
    const panelEl = document.getElementById('acsPanel-' + idpel);
    if (!body) return;

    if (syncInfoEl) {
        const sync = formatAcsSyncBadge(data ? data.cache_age : null, data ? data.cache_expired : true);
        syncInfoEl.textContent = sync.label;
        syncInfoEl.className = 'rounded px-2 py-1 ' + sync.cls;
    }

    if (!data || data.error) {
        body.innerHTML = '<span class="acs-empty-text">' + acsHtmlEscape((data && data.error) || 'Data ACS tidak tersedia.') + '</span>';
        return;
    }

    const devices = Array.isArray(data.devices) ? data.devices : [];
    if (devices.length === 0) {
        body.innerHTML = '<span class="acs-empty-text">Perangkat ACS untuk pelanggan ini belum ditemukan di cache.</span>';
        return;
    }

    const device = devices[0];
    if (panelEl) {
        panelEl.dataset.serverId = device.server_id || '';
        panelEl.dataset.serial = device.serial_raw || device.serial || '';
    }

    let html = '<div class="acs-device-info-grid">';
    const isOnline = String(device.status || '').toUpperCase() === 'ONLINE';
    const infoItems = [
        ['Serial', acsHtmlEscape(device.serial || '-')],
        ['Brand', acsHtmlEscape(device.manufacturer || '-')],
        ['PPPoE Username', acsHtmlEscape(device.pppoe_username || '-')],
        ['PPPoE Username 2', acsHtmlEscape(device.pppoe_username2 || '-')],
        ['PPPoE IP', acsHtmlEscape(device.pppoe_ip || '-')],
        ['RX redaman', acsHtmlEscape(device.rx_power || '-')],
        ['TX', acsHtmlEscape(device.tx_power || '-')],
        ['Status', '<span class="badge ' + (isOnline ? 'bg-success' : 'bg-secondary') + '">' + acsHtmlEscape(device.status || 'UNKNOWN') + '</span>'],
        ['Server', acsHtmlEscape(device.server_name || '-')],
        ['Last Inform', acsHtmlEscape(device.last_inform || '-')]
    ];
    infoItems.forEach(function(item) {
        html += '<div class="acs-device-info-item"><span class="acs-device-info-label">' + item[0] + '</span><span class="acs-device-info-value">' + item[1] + '</span></div>';
    });
    html += '</div>';

    html += '<div class="acs-ssid-section"><div class="acs-ssid-title">SSID Info</div><div class="acs-ssid-grid">';
    for (let i = 1; i <= 4; i++) {
        const ssidName = device['ssid_' + i] || '';
        const enabledRaw = String(device['ssid_enable_' + i] || '').toLowerCase();
        const isOn = enabledRaw === '1' || enabledRaw === 'true' || enabledRaw === 'enabled';
        const ssidPass = device['ssid_pass_' + i] || '';

        html += '<div class="acs-ssid-row">';
        html += '<div class="acs-ssid-card-header"><span class="acs-ssid-name">SSID ' + i + ' <span class="badge ' + (isOn ? 'bg-success' : 'bg-secondary') + '">' + (isOn ? 'ON' : 'OFF') + '</span></span></div>';
        html += '<div class="acs-ssid-value">' + acsHtmlEscape(ssidName || '-') + '</div>';
        html += '<div class="acs-ssid-value">Password: ' + (ssidPass ? '******' : '-') + '</div>';
        html += '</div>';
    }
    html += '</div></div>';

    const params = acsCleanParams(device.all_params || {});

    const wanKeys = {};
    Object.keys(params).forEach(function(key) {
        const match = key.match(/WANConnectionDevice\.(\d+)/);
        if (match) {
            const wanId = 'WANConnectionDevice.' + match[1];
            if (!wanKeys[wanId]) wanKeys[wanId] = {};
            wanKeys[wanId][key] = params[key];
        }
    });

    function acsFindParam(paramSet, suffixes) {
        const keys = Object.keys(paramSet);
        for (let s = 0; s < suffixes.length; s++) {
            for (let k = 0; k < keys.length; k++) {
                if (keys[k].indexOf(suffixes[s]) !== -1) {
                    return { key: keys[k], value: paramSet[keys[k]] };
                }
            }
        }
        return null;
    }

    function acsDetectWanSummary(wanParams) {
        const hasPpp = Object.keys(wanParams).some(function(k) { return k.indexOf('WANPPPConnection') !== -1; });
        const hasIp = Object.keys(wanParams).some(function(k) { return k.indexOf('WANIPConnection') !== -1; });

        let serviceType = 'Tidak diketahui';
        if (hasPpp) {
            serviceType = 'PPPoE';
        } else if (hasIp) {
            const addressing = acsFindParam(wanParams, ['.AddressingType']);
            const addressingVal = addressing ? String(addressing.value).toLowerCase() : '';
            serviceType = addressingVal.indexOf('static') !== -1 ? 'IP Static' : 'DHCP';
        }

        const ipParam = acsFindParam(wanParams, ['.ExternalIPAddress']);
        const statusParam = acsFindParam(wanParams, ['.ConnectionStatus']);
        const usernameParam = hasPpp ? acsFindParam(wanParams, ['.Username']) : null;

        return {
            serviceType: serviceType,
            wanIp: ipParam ? ipParam.value : '-',
            status: statusParam ? statusParam.value : '-',
            username: usernameParam ? usernameParam.value : ''
        };
    }

    html += '<div class="acs-wan-section">';
    html += '<div class="acs-wan-header"><div class="acs-wan-title">WAN Info</div></div>';
    const wanIds = Object.keys(wanKeys);
    if (wanIds.length === 0) {
        html += '<span class="acs-wan-empty">Belum ada data WAN connection di cache.</span>';
    } else {
        html += '<div class="acs-wan-grid">';
        wanIds.forEach(function(wanId) {
            const wanParams = wanKeys[wanId];
            const summary = acsDetectWanSummary(wanParams);
            const isConnected = String(summary.status).toLowerCase().indexOf('connected') !== -1 && String(summary.status).toLowerCase().indexOf('disconnected') === -1;

            html += '<div class="acs-wan-card">';
            html += '<div class="acs-wan-card-header"><span class="acs-wan-card-title">' + acsHtmlEscape(wanId) + '</span></div>';

            html += '<div class="acs-wan-summary-grid">';
            html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">Tipe Layanan</span><span class="acs-wan-param-value">' + acsHtmlEscape(summary.serviceType) + '</span></div>';
            html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">Status</span><span class="acs-wan-param-value"><span class="badge ' + (isConnected ? 'bg-success' : 'bg-secondary') + '">' + acsHtmlEscape(summary.status) + '</span></span></div>';
            html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">IP WAN</span><span class="acs-wan-param-value">' + acsHtmlEscape(summary.wanIp) + '</span></div>';
            if (summary.username) {
                html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">Username</span><span class="acs-wan-param-value">' + acsHtmlEscape(summary.username) + '</span></div>';
            }
            html += '</div>';

            const paramEntries = Object.keys(wanParams).slice(0, 6);
            if (paramEntries.length > 0) {
                html += '<div class="acs-wan-param-list mt-2">';
                paramEntries.forEach(function(pKey) {
                    const shortKey = pKey.split('.').slice(-1)[0];
                    html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">' + acsHtmlEscape(shortKey) + '</span><span class="acs-wan-param-value">' + acsHtmlEscape(wanParams[pKey]) + '</span></div>';
                });
                html += '</div>';
            }
            html += '</div>';
        });
        html += '</div>';
    }
    html += '</div>';

    const localHosts = {};
    Object.keys(params).forEach(function(key) {
        const match = key.match(/Hosts\.Host\.(\d+)\.(HostName|IPAddress|InterfaceType)$/);
        if (!match) return;
        const hostNum = match[1];
        const attr = match[2];
        if (!localHosts[hostNum]) localHosts[hostNum] = {};
        localHosts[hostNum][attr] = params[key];
    });
    const hostKeys = Object.keys(localHosts).sort(function(a, b) { return parseInt(a, 10) - parseInt(b, 10); });

    html += '<div class="acs-local-hosts-wrap">';
    html += '<div class="acs-local-hosts-title">Local Terhubung (' + hostKeys.length + ')</div>';
    if (hostKeys.length === 0) {
        html += '<span class="acs-empty-text">Tidak ada data perangkat lokal terhubung di cache.</span>';
    } else {
        html += '<div class="acs-local-hosts-grid">';
        hostKeys.forEach(function(hostNum) {
            const host = localHosts[hostNum] || {};
            html += '<div class="acs-local-host-card">';
            html += '<div class="acs-local-host-name">Device ' + acsHtmlEscape(hostNum) + '</div>';
            if (host.HostName) html += '<div class="acs-local-host-item"><strong>Host:</strong> ' + acsHtmlEscape(host.HostName) + '</div>';
            if (host.IPAddress) html += '<div class="acs-local-host-item"><strong>IP:</strong> ' + acsHtmlEscape(host.IPAddress) + '</div>';
            if (host.InterfaceType) html += '<div class="acs-local-host-item"><strong>Interface:</strong> ' + acsHtmlEscape(host.InterfaceType) + '</div>';
            html += '</div>';
        });
        html += '</div>';
    }
    html += '</div>';

    const rawParamKeys = Object.keys(params);
    html += '<div class="acs-raw-toggle-wrap">';
    html += '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAcsRawData(\'' + idpel.replace(/'/g, "\\'") + '\')" id="acsRawToggleBtn-' + acsHtmlEscape(idpel) + '">Tampilkan Semua Data ACS (' + rawParamKeys.length + ')</button>';
    html += '<div class="acs-raw-params-list d-none" id="acsRawParamsList-' + acsHtmlEscape(idpel) + '">';
    rawParamKeys.sort().forEach(function(pKey) {
        html += '<div class="acs-raw-param-row"><span class="acs-param-key">' + acsHtmlEscape(pKey) + '</span><span class="acs-param-value">' + acsHtmlEscape(params[pKey]) + '</span></div>';
    });
    html += '</div>';
    html += '</div>';

    body.innerHTML = html;
}

function toggleAcsRawData(idpel) {
    const list = document.getElementById('acsRawParamsList-' + idpel);
    const btn = document.getElementById('acsRawToggleBtn-' + idpel);
    if (!list) return;
    const isHidden = list.classList.contains('d-none');
    if (isHidden) {
        list.classList.remove('d-none');
        if (btn) btn.textContent = btn.textContent.replace('Tampilkan', 'Sembunyikan');
    } else {
        list.classList.add('d-none');
        if (btn) btn.textContent = btn.textContent.replace('Sembunyikan', 'Tampilkan');
    }
}
</script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>

<!-- Map Picker Modal (dipakai bareng Coordinates modal Tambah & Edit -- port dari addcustomerform.php) -->
<div class="modal fade" id="mapPickerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#2152ff 0%,#21d4fd 100%);color:white;">
        <h5 class="modal-title" style="color:white;"><i class="fas fa-map-marked-alt me-2"></i>Pilih Lokasi di Map</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="px-3 pt-3 pb-2 d-flex gap-2 flex-wrap align-items-center" style="border-bottom:1px solid #e0e0e0;">
          <input type="text" id="mapPickerSearchInput" class="form-control form-control-sm" style="max-width:260px;" placeholder="Cari alamat / tempat...">
          <button type="button" class="btn btn-sm btn-secondary" onclick="mapPickerSearch()"><i class="fas fa-search"></i> Cari</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="mapPickerUseMyLocation()"><i class="fas fa-location-arrow"></i> Lokasi Saya</button>
          <span class="ms-auto small text-muted">Klik di peta untuk menandai titik lokasi</span>
        </div>
        <div id="mapPickerContainer" style="width:100%;height:420px;"></div>
        <div class="px-3 py-2" style="border-top:1px solid #e0e0e0;background:#f8f9fa;">
          <strong>Koordinat terpilih:</strong>
          <span id="mapPickerCoordsDisplay" class="text-muted">Belum ada titik dipilih</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" id="mapPickerConfirmBtn" onclick="mapPickerConfirm()" disabled>
          <i class="fas fa-check"></i> Gunakan Koordinat Ini
        </button>
      </div>
    </div>
  </div>
</div>

<script>
var mapPickerMap = null;
var mapPickerMarker = null;
var mapPickerTargetInputId = null;
var mapPickerDefaultCenter = [-6.200000, 106.816666];

function mapPickerSetCoords(lat, lng) {
    var latF = parseFloat(lat).toFixed(6);
    var lngF = parseFloat(lng).toFixed(6);
    var disp = document.getElementById('mapPickerCoordsDisplay');
    var btn  = document.getElementById('mapPickerConfirmBtn');
    if (disp) disp.textContent = latF + ',' + lngF;
    if (btn)  btn.disabled = false;
    mapPickerMap._pickedLat = latF;
    mapPickerMap._pickedLng = lngF;
}

function mapPickerPlaceMarker(lat, lng) {
    if (mapPickerMarker) {
        mapPickerMarker.setLatLng([lat, lng]);
    } else {
        mapPickerMarker = L.marker([lat, lng], { draggable: true }).addTo(mapPickerMap);
        mapPickerMarker.on('dragend', function(e) {
            var pos = e.target.getLatLng();
            mapPickerSetCoords(pos.lat, pos.lng);
        });
    }
    mapPickerSetCoords(lat, lng);
}

function mapPickerInitMap() {
    if (mapPickerMap) return;
    mapPickerMap = L.map('mapPickerContainer').setView(mapPickerDefaultCenter, 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(mapPickerMap);
    mapPickerMap.on('click', function(e) {
        mapPickerPlaceMarker(e.latlng.lat, e.latlng.lng);
    });
}

function openMapPicker(inputId) {
    mapPickerTargetInputId = inputId;
    var modalEl = document.getElementById('mapPickerModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    var disp = document.getElementById('mapPickerCoordsDisplay');
    var btn  = document.getElementById('mapPickerConfirmBtn');
    if (disp) disp.textContent = 'Belum ada titik dipilih';
    if (btn)  btn.disabled = true;
    var searchInput = document.getElementById('mapPickerSearchInput');
    if (searchInput) searchInput.value = '';

    var existingVal = (document.getElementById(inputId) || {}).value || '';
    var startLat = null, startLng = null;
    var parts = existingVal.split(',');
    if (parts.length === 2) {
        var pLat = parseFloat(parts[0].trim());
        var pLng = parseFloat(parts[1].trim());
        if (!isNaN(pLat) && !isNaN(pLng)) { startLat = pLat; startLng = pLng; }
    }

    modalEl.addEventListener('shown.bs.modal', function onShown() {
        modalEl.removeEventListener('shown.bs.modal', onShown);
        mapPickerInitMap();
        setTimeout(function(){ mapPickerMap.invalidateSize(); }, 150);
        if (startLat !== null && startLng !== null) {
            mapPickerMap.setView([startLat, startLng], 17);
            mapPickerPlaceMarker(startLat, startLng);
        } else if (mapPickerMarker) {
            mapPickerMap.removeLayer(mapPickerMarker);
            mapPickerMarker = null;
        }
    });

    bsModal.show();
}

function mapPickerConfirm() {
    if (!mapPickerMap || mapPickerMap._pickedLat === undefined) return;
    var targetInput = document.getElementById(mapPickerTargetInputId);
    if (targetInput) {
        targetInput.value = mapPickerMap._pickedLat + ',' + mapPickerMap._pickedLng;
        targetInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    var modalEl = document.getElementById('mapPickerModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.hide();
}

function mapPickerUseMyLocation() {
    if (!navigator.geolocation) { alert('Browser tidak mendukung geolocation.'); return; }
    navigator.geolocation.getCurrentPosition(function(pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        mapPickerMap.setView([lat, lng], 17);
        mapPickerPlaceMarker(lat, lng);
    }, function() {
        alert('Gagal mendapatkan lokasi Anda. Pastikan izin lokasi diaktifkan.');
    });
}

function mapPickerSearch() {
    var q = (document.getElementById('mapPickerSearchInput') || {}).value || '';
    q = q.trim();
    if (!q) return;
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(results) {
            if (!results || !results.length) { alert('Lokasi tidak ditemukan.'); return; }
            var lat = parseFloat(results[0].lat), lng = parseFloat(results[0].lon);
            mapPickerMap.setView([lat, lng], 16);
            mapPickerPlaceMarker(lat, lng);
        })
        .catch(function() { alert('Gagal mencari lokasi. Periksa koneksi internet.'); });
}

document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('mapPickerSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); mapPickerSearch(); }
        });
    }
});
</script>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <h6>Customer Static IP</h6>
          <p class="text-muted small mt-2">
            Pelanggan di sini TETAP pelanggan PPPoE biasa (Mode API/RADIUS/MULTI penuh
            didukung, billing/invoice/reminder otomatis ikut) -- yang membedakan cuma
            IP-nya TETAP (statis) dari <a href="staticippool.php">IP Pool Static</a>,
            bukan dari PPP Pool dinamis.
          </p>
          <div class="btn-group-custom mb-3">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerStaticModal">
              <i class="fas fa-plus me-1"></i> Tambah Customer Static IP
            </button>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="toggleStaticFastStatusMode" style="width:3em;height:1.5em;cursor:pointer;">
            <label class="form-check-label" for="toggleStaticFastStatusMode">
              <span id="staticFastStatusModeLabel">Mode Cepat: OFF</span>
              <i class="fas fa-info-circle text-muted ms-1"
                 title="Kalau ON: kolom Status disembunyikan total dari tabel (loading halaman lebih cepat untuk banyak pelanggan, tidak ada lagi cek terus-menerus ke Mikrotik). Status detail tetap bisa diakses lewat modal Overview (klik ID Pelanggan). Perubahan berlaku setelah halaman di-refresh."></i>
            </label>
          </div>
          <script>
          (function() {
              var FAST_MODE_KEY = 'staticip_fast_status_mode';
              var toggle = document.getElementById('toggleStaticFastStatusMode');
              var label = document.getElementById('staticFastStatusModeLabel');
              if (!toggle || !label) return;
              window.__staticFastStatusMode = localStorage.getItem(FAST_MODE_KEY) === '1';
              toggle.checked = window.__staticFastStatusMode;
              label.textContent = 'Mode Cepat: ' + (window.__staticFastStatusMode ? 'ON' : 'OFF');
              document.body.classList.toggle('staticip-fast-status-mode-active', window.__staticFastStatusMode);
              toggle.addEventListener('change', function() {
                  localStorage.setItem(FAST_MODE_KEY, toggle.checked ? '1' : '0');
                  label.textContent = 'Mode Cepat: ' + (toggle.checked ? 'ON' : 'OFF');
                  alert('Pengaturan disimpan. Refresh halaman ini supaya Mode Cepat ' + (toggle.checked ? 'aktif' : 'nonaktif') + '.');
              });
          })();
          </script>
        </div>

        <div class="card-body">
          <div class="table-responsive">
            <table id="staticipCustomerTable" class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>ID Pelanggan</th>
                  <th>Nama</th>
                  <th>Paket</th>
                  <th>IP Static</th>
                  <th>Mode</th>
                  <th class="staticip-status-col">Status</th>
                  <th>Server</th>
                  <th>Area</th>
                  <th>WhatsApp</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $areaFilter = staticipAreaFilterSql('AREA', $AKSES, $area_list ?? '');
                $ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
                $sqlCust = "SELECT * FROM pelanggan WHERE PEMILIK = '$ceknamaEsc' AND TIPE_LAYANAN = 'PPPOE_STATIC'" . $areaFilter . " ORDER BY IDPEL ASC";
                $qCust = mysqli_query($conn, $sqlCust);
                $staticipModalsBuffer = '';
                if ($qCust && mysqli_num_rows($qCust) > 0) {
                    $no = 1;
                    while ($row = mysqli_fetch_assoc($qCust)) {
                        // Lookup IP/kredensial server -- dipakai startFetching() utk cek status
                        // online/offline live ke Mikrotik/RADIUS, pola sama persis tables.php.
                        $srvIp = $srvUser = $srvPassword = '';
                        $areaRow = (string) ($row['AREA'] ?? '');
                        $pemilikRow = (string) ($row['PEMILIK'] ?? '');
                        if ($areaRow !== '' && $pemilikRow !== '') {
                            $areaRowEsc = mysqli_real_escape_string($conn, $areaRow);
                            $pemilikRowEsc = mysqli_real_escape_string($conn, $pemilikRow);
                            $qSrvRow = mysqli_query($conn, "SELECT IP, PEMILIK, PASSWORD FROM server WHERE AREA = '$areaRowEsc' AND PEMILIK = '$pemilikRowEsc' LIMIT 1");
                            $srvRow = $qSrvRow ? mysqli_fetch_assoc($qSrvRow) : null;
                            if ($srvRow) {
                                $srvIp = (string) ($srvRow['IP'] ?? '');
                                $srvUser = (string) ($srvRow['PEMILIK'] ?? '');
                                $srvPassword = (string) ($srvRow['PASSWORD'] ?? '');
                            }
                        }

                        $idpelAttr = htmlspecialchars($row['IDPEL'], ENT_QUOTES);
                        $namaAttr = htmlspecialchars($row['NAMA'], ENT_QUOTES);
                        $passwordAttr = htmlspecialchars($row['PASSWORD'] ?? '', ENT_QUOTES);
                        $nikAttr = htmlspecialchars($row['NIK'] ?? '', ENT_QUOTES);
                        $alamatAttr = htmlspecialchars($row['ALAMAT'], ENT_QUOTES);
                        $provinsiAttr = htmlspecialchars($row['provinsi'] ?? '', ENT_QUOTES);
                        $kabupatenAttr = htmlspecialchars($row['kabupaten'] ?? '', ENT_QUOTES);
                        $kecamatanAttr = htmlspecialchars($row['kecamatan'] ?? '', ENT_QUOTES);
                        $kelurahanAttr = htmlspecialchars($row['kelurahan'] ?? '', ENT_QUOTES);
                        $rtAttr = htmlspecialchars($row['rt'] ?? '', ENT_QUOTES);
                        $rwAttr = htmlspecialchars($row['rw'] ?? '', ENT_QUOTES);
                        $nowaAttr = htmlspecialchars($row['NOWA'], ENT_QUOTES);
                        $emailAttr = htmlspecialchars($row['EMAIL'], ENT_QUOTES);
                        $tikorAttr = htmlspecialchars($row['TIKOR'] ?? '', ENT_QUOTES);
                        $odpAttr = htmlspecialchars($row['ODP'], ENT_QUOTES);
                        $paketAttr = htmlspecialchars($row['PAKET'], ENT_QUOTES);
                        $salesAttr = htmlspecialchars($row['sales'] ?? '', ENT_QUOTES);
                        $areaAttr = htmlspecialchars($areaRow, ENT_QUOTES);
                        $pemilikAttr = htmlspecialchars($pemilikRow, ENT_QUOTES);
                        $modeAttr = htmlspecialchars($row['MODE'], ENT_QUOTES);

                        $hp = '';
                        $nohpRaw = trim((string) ($row['NOWA'] ?? ''));
                        if (!preg_match('/[^+0-9]/', $nohpRaw)) {
                            if (substr($nohpRaw, 0, 2) === '62') {
                                $hp = $nohpRaw;
                            } elseif (substr($nohpRaw, 0, 3) === '+62') {
                                $hp = '62' . substr($nohpRaw, 1);
                            } elseif (substr($nohpRaw, 0, 1) === '0') {
                                $hp = '62' . substr($nohpRaw, 1);
                            } else {
                                $hp = $nohpRaw;
                            }
                        }
                        $hpAttr = htmlspecialchars($hp, ENT_QUOTES);

                        echo "<tr>";
                        echo "<td>" . $no++ . "</td>";
                        echo '<td><div style="cursor:pointer;" data-bs-toggle="modal" data-bs-target="#exampleoverviewstatic' . $idpelAttr . '" onclick="openMonitorModal(this, \'' . $idpelAttr . '\', \'' . htmlspecialchars($srvIp, ENT_QUOTES) . '\', \'' . htmlspecialchars($srvUser, ENT_QUOTES) . '\', \'' . htmlspecialchars($srvPassword, ENT_QUOTES) . '\')">' . $idpelAttr . '</div></td>';
                        echo "<td>" . $namaAttr . "</td>";
                        echo "<td>" . $paketAttr . "</td>";
                        echo "<td><span class='badge bg-info text-dark'>" . htmlspecialchars($row['IP_STATIC'] ?? '-') . "</span></td>";
                        echo "<td>" . $modeAttr . "</td>";

                        echo '<td class="align-middle text-center text-sm staticip-status-col">';
                        echo '<div class="status-action-row">';
                        echo '<button type="button" class="btn btn-danger btn-sm customer-action-btn" data-bs-toggle="modal" data-bs-target="#examplelivechatstatic' . $idpelAttr . '">Live Chat</button>';
                        echo '<div id="remoteContainer-' . $idpelAttr . '"></div>';
                        echo '</div>';
                        echo '<div class="status-top-badges">';
                        echo '<span id="data-status2-' . $idpelAttr . '"><span class="badge badge-sm bg-gradient-warning">Loading...</span></span>';
                        echo '<div id="data-paket-aktif-' . $idpelAttr . '"></div>';
                        echo '</div>';
                        echo '<span id="data-realtime-' . $idpelAttr . '" class="text-secondary text-xs font-weight-bold">Memuat...</span>';
                        if ($srvIp !== '' && $srvUser !== '') {
                            echo '<script>startFetching("' . $idpelAttr . '", "' . htmlspecialchars($srvIp, ENT_QUOTES) . '", "' . htmlspecialchars($srvUser, ENT_QUOTES) . '", "' . htmlspecialchars($srvPassword, ENT_QUOTES) . '", "' . $modeAttr . '");</script>';
                        }
                        echo '</td>';

                        echo "<td>" . $pemilikAttr . "</td>";
                        echo "<td>" . $areaAttr . "</td>";
                        echo "<td>" . $nowaAttr . "</td>";
                        echo "<td>";
                        echo "<button type='button' class='btn btn-warning btn-sm' data-bs-toggle='modal' data-bs-target='#editCustomerStaticModal'"
                            . " data-idpel='" . $idpelAttr . "'"
                            . " data-nama='" . $namaAttr . "'"
                            . " data-alamat='" . $alamatAttr . "'"
                            . " data-wa='" . $nowaAttr . "'"
                            . " data-email='" . $emailAttr . "'"
                            . " data-tikor='" . $tikorAttr . "'"
                            . " data-pemilik='" . $pemilikAttr . "'"
                            . " data-area='" . $areaAttr . "'"
                            . " data-odp='" . $odpAttr . "'"
                            . " data-paket='" . $paketAttr . "'"
                            . " data-tipebayar='" . htmlspecialchars($row['TIPE_BAYAR'], ENT_QUOTES) . "'"
                            . " data-tipetempo='" . htmlspecialchars($row['TIPE_TEMPO'], ENT_QUOTES) . "'"
                            . " data-mode='" . $modeAttr . "'"
                            . " data-ipstatic='" . htmlspecialchars($row['IP_STATIC'] ?? '', ENT_QUOTES) . "'"
                            . " data-sales='" . $salesAttr . "'"
                            . " data-nik='" . $nikAttr . "'"
                            . " data-provinsi='" . $provinsiAttr . "'"
                            . " data-kabupaten='" . $kabupatenAttr . "'"
                            . " data-kecamatan='" . $kecamatanAttr . "'"
                            . " data-kelurahan='" . $kelurahanAttr . "'"
                            . " data-rw='" . $rwAttr . "'"
                            . " data-rt='" . $rtAttr . "'"
                            . ">Edit</button> ";
                        echo "<form method='post' action='proses/deletecustomerstaticip.php' style='display:inline' onsubmit='return confirm(\"Yakin ingin menghapus pelanggan ini? Koneksi PPPoE-nya juga akan dihapus dari router/RADIUS.\")'>"
                            . "<input type='hidden' name='idpel' value='" . $idpelAttr . "'>"
                            . "<button type='submit' class='btn btn-danger btn-sm'>Hapus</button></form>";
                        echo "</td>";
                        echo "</tr>";

                        // ==== Modal Overview (versi ringkas -- fokus monitoring, lihat memory
                        // project_staticip_status_overview.md utk daftar lengkap yang SENGAJA
                        // tidak ikut: peta/grafik/ACS panel besar/SLA history/tombol billing). ====
                        $staticipModalsBuffer .= '<div class="modal fade" id="exampleoverviewstatic' . $idpelAttr . '" tabindex="-1" aria-hidden="true" data-bs-backdrop="false">';
                        $staticipModalsBuffer .= '<div class="modal-dialog modal-dialog-centered overview-modal-dialog"><div class="modal-content overview-modal-content">';
                        $staticipModalsBuffer .= '<div class="modal-header"><h5 class="modal-title">Overview Customer Static IP</h5>';
                        $staticipModalsBuffer .= '<button type="button" class="btn-close overview-close-btn" data-bs-dismiss="modal" aria-label="Close"></button></div>';
                        $staticipModalsBuffer .= '<div class="modal-body overview-modal-body"><div class="row overview-main-row">';

                        $staticipModalsBuffer .= '<div class="col-12 col-lg-6 overview-formdata-col">';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">ID Pelanggan / User PPPoE username</label><input type="text" class="form-control form-control-sm" value="' . $idpelAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">User PPPoE password</label><input type="text" class="form-control form-control-sm" value="' . $passwordAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Name</label><input type="text" class="form-control form-control-sm" value="' . $namaAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">NIK</label><input type="text" class="form-control form-control-sm" value="' . $nikAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Server Area</label><input type="text" class="form-control form-control-sm" value="' . $pemilikAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Area</label><input type="text" class="form-control form-control-sm" value="' . $areaAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Package</label><input type="text" class="form-control form-control-sm" value="' . $paketAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">IP Static</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($row['IP_STATIC'] ?? '-', ENT_QUOTES) . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Address</label><input type="text" class="form-control form-control-sm" value="' . $alamatAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Provinsi</label><input type="text" class="form-control form-control-sm" value="' . $provinsiAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Kabupaten/Kota</label><input type="text" class="form-control form-control-sm" value="' . $kabupatenAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Kecamatan</label><input type="text" class="form-control form-control-sm" value="' . $kecamatanAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Kelurahan</label><input type="text" class="form-control form-control-sm" value="' . $kelurahanAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">RT/RW</label><input type="text" class="form-control form-control-sm" value="' . $rtAttr . '/' . $rwAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">WhatsApp</label><input type="text" class="form-control form-control-sm" value="' . $nowaAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Email</label><input type="email" class="form-control form-control-sm" value="' . $emailAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Coordinates</label><input type="text" class="form-control form-control-sm" value="' . $tikorAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">ODP</label><input type="text" class="form-control form-control-sm" value="' . $odpAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '<div class="mb-1"><label class="form-label">Sales</label><input type="text" class="form-control form-control-sm" value="' . $salesAttr . '" readonly></div>';
                        $staticipModalsBuffer .= '</div>';

                        $staticipModalsBuffer .= '<div class="col-12 col-lg-6 overview-health-col">';
                        $staticipModalsBuffer .= '<span style="font-size:15px;" id="data-status-' . $idpelAttr . '"><span class="badge badge-sm bg-gradient-warning">Loading...</span></span>';
                        $staticipModalsBuffer .= '<div id="data-paket-aktif-modal-' . $idpelAttr . '" class="overview-meta-item"></div>';
                        $staticipModalsBuffer .= '<div class="d-flex flex-column gap-2 overview-health-stack">';
                        $staticipModalsBuffer .= '<span id="data-info-' . $idpelAttr . '" class="text-secondary text-xs font-weight-bold">Memuat...</span>';
                        $staticipModalsBuffer .= '<div id="remoteContainerModal-' . $idpelAttr . '"></div>';
                        if ($hpAttr !== '') {
                            $staticipModalsBuffer .= '<a href="https://wa.me/' . $hpAttr . '" target="_blank" class="btn btn-success btn-sm">WhatsApp ' . $hpAttr . '</a>';
                        }
                        $staticipModalsBuffer .= '<button type="button" class="btn btn-primary btn-sm modal-action-btn" data-bs-toggle="modal" data-bs-target="#examplelivechatstatic' . $idpelAttr . '">Live Chat</button>';
                        $staticipModalsBuffer .= '<button type="button" class="btn btn-secondary btn-sm modal-action-btn" data-bs-toggle="modal" data-bs-target="#editCustomerStaticModal"'
                            . ' data-idpel="' . $idpelAttr . '" data-nama="' . $namaAttr . '" data-alamat="' . $alamatAttr . '" data-wa="' . $nowaAttr . '" data-email="' . $emailAttr . '"'
                            . ' data-tikor="' . $tikorAttr . '" data-pemilik="' . $pemilikAttr . '" data-area="' . $areaAttr . '" data-odp="' . $odpAttr . '" data-paket="' . $paketAttr . '"'
                            . ' data-tipebayar="' . htmlspecialchars($row['TIPE_BAYAR'], ENT_QUOTES) . '" data-tipetempo="' . htmlspecialchars($row['TIPE_TEMPO'], ENT_QUOTES) . '" data-mode="' . $modeAttr . '"'
                            . ' data-ipstatic="' . htmlspecialchars($row['IP_STATIC'] ?? '', ENT_QUOTES) . '" data-sales="' . $salesAttr . '" data-nik="' . $nikAttr . '"'
                            . ' data-provinsi="' . $provinsiAttr . '" data-kabupaten="' . $kabupatenAttr . '" data-kecamatan="' . $kecamatanAttr . '" data-kelurahan="' . $kelurahanAttr . '"'
                            . ' data-rw="' . $rwAttr . '" data-rt="' . $rtAttr . '">Edit Data</button>';
                        $staticipModalsBuffer .= '</div>';
                        $staticipModalsBuffer .= '</div>';
                        $staticipModalsBuffer .= '</div></div>';

                        $staticipModalsBuffer .= '<div class="acs-device-card border rounded p-3 mt-3" id="acsPanel-' . $idpelAttr . '" data-idpel="' . $idpelAttr . '" data-server-id="">';
                        $staticipModalsBuffer .= '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
                        $staticipModalsBuffer .= '<div class="acs-section-title fw-bold"><i class="fas fa-wifi"></i> Data ACS</div>';
                        $staticipModalsBuffer .= '<span id="acs-sync-info-' . $idpelAttr . '" class="rounded px-2 py-1"></span>';
                        $staticipModalsBuffer .= '</div>';
                        $staticipModalsBuffer .= '<div id="acsPanelBody-' . $idpelAttr . '"><span class="acs-empty-text">Memuat data ACS...</span></div>';
                        $staticipModalsBuffer .= '</div>';

                        $staticipModalsBuffer .= '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button></div>';
                        $staticipModalsBuffer .= '</div></div></div>';

                        // Saat Overview dibuka: muat data ACS + (kalau Mode Cepat aktif) status
                        // live on-demand utk pelanggan ini -- pola sama persis tables.php.
                        $staticipModalsBuffer .= '<script>
                        document.addEventListener("DOMContentLoaded", function() {
                            var ovModal = document.getElementById("exampleoverviewstatic' . $idpelAttr . '");
                            if (!ovModal) return;
                            ovModal.addEventListener("shown.bs.modal", function() {
                                if (typeof fetchStatusOnDemandIfFastMode === "function") {
                                    fetchStatusOnDemandIfFastMode("' . $idpelAttr . '");
                                }
                                if (typeof loadAcsDevicePanel === "function") {
                                    loadAcsDevicePanel("' . $idpelAttr . '");
                                }
                            });
                        });
                        </script>';

                        // Modal Live Chat terpisah (iframe) -- pola sama persis tables.php: src
                        // BARU di-set saat modal dibuka (show.bs.modal), BUKAN langsung di HTML,
                        // supaya tidak ada puluhan iframe chat ikut ke-load bareng saat halaman
                        // listing dibuka (tiap baris punya modal Live Chat sendiri).
                        $staticipAdminChatFor = ($AKSES === 'ADMIN') ? 'admin' : $pemilikAttr;
                        $staticipModalsBuffer .= '<div class="modal fade" id="examplelivechatstatic' . $idpelAttr . '" tabindex="-1" aria-hidden="true" data-bs-backdrop="false">';
                        $staticipModalsBuffer .= '<div class="modal-dialog modal-lg"><div class="modal-content" style="flex-direction:column !important; display:flex !important;">';
                        $staticipModalsBuffer .= '<div class="modal-header bg-primary text-white"><h6 class="modal-title fw-bold text-white"><i class="fas fa-comments"></i> Live Chat - ' . $namaAttr . '</h6>';
                        $staticipModalsBuffer .= '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>';
                        $staticipModalsBuffer .= '<div class="modal-body p-0" style="height:70vh;"><iframe id="iframeChatStatic' . $idpelAttr . '" style="width:100%;height:100%;border:0;display:none;"></iframe></div>';
                        $staticipModalsBuffer .= '</div></div></div>';
                        $staticipModalsBuffer .= '<script>
                        document.addEventListener("DOMContentLoaded", function() {
                            var chatModal = document.getElementById("examplelivechatstatic' . $idpelAttr . '");
                            var chatIframe = document.getElementById("iframeChatStatic' . $idpelAttr . '");
                            if (!chatModal || !chatIframe) return;
                            chatModal.addEventListener("show.bs.modal", function() {
                                chatIframe.src = "' . rtrim((string) ($config['URL'] ?? ''), '/') . '/crm/chat/index.php?admin=' . rawurlencode($staticipAdminChatFor) . '&pelanggan=' . rawurlencode($row['IDPEL']) . '&nowa=' . rawurlencode($hp) . '";
                                chatIframe.style.display = "block";
                            });
                            chatModal.addEventListener("hidden.bs.modal", function() {
                                chatIframe.src = "";
                                chatIframe.style.display = "none";
                            });
                        });
                        </script>';
                    }
                } else {
                    echo "<tr><td colspan='11' class='text-center'>Belum ada Customer Static IP</td></tr>";
                }
                ?>
              </tbody>
            </table>
          </div>
          <?php echo $staticipModalsBuffer; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="addCustomerStaticModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tambah Customer Static IP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/addcustomerstaticip.php" id="addStaticForm">
          <?php if ($customerid_suggestion_static !== ''): ?>
          <div class="alert alert-secondary py-2 small mb-3">ID Pelanggan ini belum dipakai, silahkan gunakan ID pelanggan berikut: <b><?php echo htmlspecialchars($customerid_suggestion_static); ?></b> (boleh diganti)</div>
          <?php endif; ?>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Customer ID (username PPPoE)</label>
              <input required type="text" class="form-control" name="customerID" id="addStaticCustomerID" value="<?php echo htmlspecialchars($customerid_suggestion_static); ?>">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Password PPPoE</label>
              <input required type="text" class="form-control" name="passwordPPPOE" id="addStaticPasswordPPPOE" value="<?php echo htmlspecialchars($customerid_suggestion_static); ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">NIK (Nomor Induk Kependudukan)</label>
            <input type="text" class="form-control" name="NIK" maxlength="20" placeholder="Masukkan NIK 16 digit">
          </div>
          <div class="mb-3">
            <label class="form-label">Nama Pelanggan</label>
            <input required type="text" class="form-control" name="customerName">
          </div>
          <div class="mb-3">
            <label class="form-label">Alamat</label>
            <input required type="text" class="form-control" name="address">
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Provinsi</label>
              <select name="provinsi" id="addStaticProvinsi" class="form-select" required><option value="">Pilih Provinsi</option></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Kabupaten/Kota</label>
              <select name="kabupaten" id="addStaticKabupaten" class="form-select" required></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Kecamatan</label>
              <select name="kecamatan" id="addStaticKecamatan" class="form-select" required></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Kelurahan</label>
              <select name="kelurahan" id="addStaticKelurahan" class="form-select" required></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">RW</label>
              <input type="text" name="rw" class="form-control" placeholder="RW" required oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">RT</label>
              <input type="text" name="rt" class="form-control" placeholder="RT" required oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">WhatsApp</label>
              <input required type="text" class="form-control" name="whatsapp" placeholder="62878xxxxxx">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="Email">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Coordinates</label>
            <div class="input-group">
              <input type="text" class="form-control" name="coordinates" id="addStaticTikor" placeholder="-6.476182,106.777992">
              <button type="button" class="btn btn-outline-secondary" onclick="openMapPicker('addStaticTikor')"><i class="fas fa-map-marked-alt"></i> Pilih di Peta</button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal mulai tagihan awal</label>
            <input required type="date" class="form-control" name="tanggalpasang" value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Sales</label>
            <select required class="form-control" name="sales">
              <option value="">-- Pilih sales --</option>
              <option value="-">TANPA SALES</option>
              <?php
              $qSales = mysqli_query($conn, "SELECT DISTINCT nama FROM mitra WHERE server='" . mysqli_real_escape_string($conn, $ceknama) . "'");
              while ($rowSales = mysqli_fetch_assoc($qSales)) {
                  $ns = htmlspecialchars($rowSales['nama'], ENT_QUOTES, 'UTF-8');
                  echo '<option value="' . $ns . '">' . $ns . '</option>';
              }
              ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Server Area</label>
            <select required class="form-select" name="server" id="addStaticServer">
              <option value="">-- Pilih Server Area --</option>
              <?php staticip_render_server_options($conn, $AKSES, $area_list ?? '', $current_user_id ?? 0); ?>
            </select>
            <input type="hidden" name="area" id="addStaticArea">
          </div>
          <div class="mb-3">
            <label class="form-label">ODP</label>
            <select required class="form-select" name="odp" id="addStaticOdp">
              <option value="">-- Pilih ODP --</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Paket Static IP</label>
            <select required class="form-select" name="packages" id="addStaticPackages">
              <option value="">-- Pilih Server Area dulu --</option>
            </select>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Tipe Bayar</label>
              <select required class="form-select" name="tipe_bayar">
                <option value="">-- Pilih --</option>
                <option value="prabayar">Prabayar</option>
                <option value="pascabayar">Pascabayar</option>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Tipe Tempo</label>
              <select required class="form-select" name="tipe_tempo">
                <option value="">-- Pilih --</option>
                <option value="mengikuti_tanggal_tempo">Fixed Due Date</option>
                <option value="mengikuti_tanggal_bayar">Rolling Due Date</option>
                <option value="monthversary">Monthversary Due Date</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Auth Mode</label>
            <select required class="form-select" name="authmode" id="addStaticAuthmode">
              <option value="API MODE">API MODE</option>
              <option value="RADIUS MODE">RADIUS MODE</option>
              <option value="MULTI MODE">MULTI MODE</option>
            </select>
            <small id="addStaticAuthmodeNote" class="text-danger d-none">Server ini RADIUS SAJA -- Auth Mode dikunci ke RADIUS MODE.</small>
          </div>
          <div class="mb-3">
            <label class="form-label">IP Static</label>
            <select required class="form-select" name="ip_static" id="addStaticIp">
              <option value="">-- Pilih Server Area dulu --</option>
            </select>
            <div class="form-text">Daftar diambil dari <a href="staticippool.php" target="_blank">IP Pool Static</a> Area terpilih, IP yang sudah dipakai pelanggan lain otomatis tidak ditampilkan.</div>
          </div>

          <?php staticip_render_olt_block('addStatic'); ?>

          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" id="addStaticSaveBtn" class="btn btn-primary">
              <span class="btn-label">Simpan</span>
              <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Menyimpan...</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editCustomerStaticModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Customer Static IP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/editcustomerstaticip.php" id="editStaticForm">
          <input type="hidden" name="customerID_old" id="editStaticIdpelOld">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Customer ID (username PPPoE)</label>
              <input required type="text" class="form-control" name="customerID" id="editStaticIdpel">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Password PPPoE <small class="text-muted">(kosongkan jika tidak ganti)</small></label>
              <input type="text" class="form-control" name="passwordPPPOE" id="editStaticPassword">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">NIK (Nomor Induk Kependudukan)</label>
            <input type="text" class="form-control" name="NIK" id="editStaticNik" maxlength="20" placeholder="Masukkan NIK 16 digit">
          </div>
          <div class="mb-3">
            <label class="form-label">Nama Pelanggan</label>
            <input required type="text" class="form-control" name="customerName" id="editStaticNama">
          </div>
          <div class="mb-3">
            <label class="form-label">Alamat</label>
            <input required type="text" class="form-control" name="address" id="editStaticAlamat">
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Provinsi</label>
              <select name="provinsi" id="editStaticProvinsi" class="form-select" required></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Kabupaten/Kota</label>
              <select name="kabupaten" id="editStaticKabupaten" class="form-select" required></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Kecamatan</label>
              <select name="kecamatan" id="editStaticKecamatan" class="form-select" required></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Kelurahan</label>
              <select name="kelurahan" id="editStaticKelurahan" class="form-select" required></select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">RW</label>
              <input type="text" name="rw" id="editStaticRw" class="form-control" placeholder="RW" required oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">RT</label>
              <input type="text" name="rt" id="editStaticRt" class="form-control" placeholder="RT" required oninput="this.value=this.value.replace(/\D/g,'').replace(/^0+(?=\d)/,'')">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">WhatsApp</label>
              <input required type="text" class="form-control" name="whatsapp" id="editStaticWa">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="Email" id="editStaticEmail">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Coordinates</label>
            <div class="input-group">
              <input type="text" class="form-control" name="coordinates" id="editStaticTikor">
              <button type="button" class="btn btn-outline-secondary" onclick="openMapPicker('editStaticTikor')"><i class="fas fa-map-marked-alt"></i> Pilih di Peta</button>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Sales</label>
            <select required class="form-control" name="sales" id="editStaticSales">
              <option value="">-- Pilih sales --</option>
              <option value="-">TANPA SALES</option>
              <?php
              $qSales2 = mysqli_query($conn, "SELECT DISTINCT nama FROM mitra WHERE server='" . mysqli_real_escape_string($conn, $ceknama) . "'");
              while ($rowSales2 = mysqli_fetch_assoc($qSales2)) {
                  $ns2 = htmlspecialchars($rowSales2['nama'], ENT_QUOTES, 'UTF-8');
                  echo '<option value="' . $ns2 . '">' . $ns2 . '</option>';
              }
              ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Server Area</label>
            <select required class="form-select" name="server" id="editStaticServer">
              <option value="">-- Pilih Server Area --</option>
              <?php staticip_render_server_options($conn, $AKSES, $area_list ?? '', $current_user_id ?? 0); ?>
            </select>
            <input type="hidden" name="area" id="editStaticArea">
            <input type="hidden" name="serverlama" id="editStaticServerLama">
            <input type="hidden" name="arealama" id="editStaticAreaLama">
          </div>
          <div class="mb-3">
            <label class="form-label">ODP</label>
            <select required class="form-select" name="odp" id="editStaticOdp">
              <option value="">-- Pilih ODP --</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Paket Static IP</label>
            <select required class="form-select" name="packages" id="editStaticPackages">
              <option value="">-- Pilih --</option>
            </select>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Tipe Bayar</label>
              <select required class="form-select" name="tipe_bayar" id="editStaticTipeBayar">
                <option value="prabayar">Prabayar</option>
                <option value="pascabayar">Pascabayar</option>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Tipe Tempo</label>
              <select required class="form-select" name="tipe_tempo" id="editStaticTipeTempo">
                <option value="mengikuti_tanggal_tempo">Fixed Due Date</option>
                <option value="mengikuti_tanggal_bayar">Rolling Due Date</option>
                <option value="monthversary">Monthversary Due Date</option>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Auth Mode</label>
            <select required class="form-select" name="authmode" id="editStaticAuthmode">
              <option value="API MODE">API MODE</option>
              <option value="RADIUS MODE">RADIUS MODE</option>
              <option value="MULTI MODE">MULTI MODE</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">IP Static</label>
            <select required class="form-select" name="ip_static" id="editStaticIp">
              <option value="">-- Pilih --</option>
            </select>
          </div>

          <?php staticip_render_olt_block('editStatic'); ?>

          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" id="editStaticSaveBtn" class="btn btn-primary">
              <span class="btn-label">Simpan Perubahan</span>
              <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Menyimpan...</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function staticipLoadOdp(serverSelectId, areaInputId, odpSelectId, selectedOdp) {
    var server = document.getElementById(serverSelectId).value;
    var area = document.getElementById(areaInputId).value;
    var odpDropdown = document.getElementById(odpSelectId);
    odpDropdown.innerHTML = '<option value="">Loading...</option>';
    if (server !== '' && area !== '') {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'getdata/get_odp.php?area=' + encodeURIComponent(area) + '&server=' + encodeURIComponent(server), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState == 4 && xhr.status == 200) {
                odpDropdown.innerHTML = xhr.responseText;
                if (selectedOdp) {
                    odpDropdown.value = selectedOdp;
                }
            }
        };
        xhr.send();
    }
}
function staticipLoadPackages(serverSelectId, areaInputId, pkgSelectId, selectedPaket) {
    var server = document.getElementById(serverSelectId).value;
    var area = document.getElementById(areaInputId).value;
    var pkgDropdown = document.getElementById(pkgSelectId);
    pkgDropdown.innerHTML = '<option value="">Loading...</option>';
    if (server !== '' && area !== '') {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'getdata/get_packages.php?tipe_layanan=PPPOE_STATIC&area=' + encodeURIComponent(area) + '&server=' + encodeURIComponent(server), true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState == 4 && xhr.status == 200) {
                pkgDropdown.innerHTML = xhr.responseText;
                if (selectedPaket) {
                    pkgDropdown.value = selectedPaket;
                }
            }
        };
        xhr.send();
    }
}
function staticipLoadIps(serverSelectId, areaInputId, ipSelectId, currentIp) {
    var server = document.getElementById(serverSelectId).value;
    var area = document.getElementById(areaInputId).value;
    var ipDropdown = document.getElementById(ipSelectId);
    ipDropdown.innerHTML = '<option value="">Loading...</option>';
    if (server !== '' && area !== '') {
        var xhr = new XMLHttpRequest();
        var url = 'getdata/get_static_ips.php?area=' + encodeURIComponent(area) + '&server=' + encodeURIComponent(server);
        if (currentIp) {
            url += '&current_ip=' + encodeURIComponent(currentIp);
        }
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState == 4 && xhr.status == 200) {
                ipDropdown.innerHTML = xhr.responseText;
            }
        };
        xhr.send();
    }
}
function staticipApplyAuthLock(serverSelectId, authmodeSelectId, noteId) {
    var serverSelect = document.getElementById(serverSelectId);
    var selected = serverSelect.options[serverSelect.selectedIndex];
    var connmode = selected ? selected.getAttribute('data-connmode') : '';
    var authmodeSelect = document.getElementById(authmodeSelectId);
    var note = noteId ? document.getElementById(noteId) : null;
    if (!authmodeSelect) return;
    var apiOpt = authmodeSelect.querySelector('option[value="API MODE"]');
    var multiOpt = authmodeSelect.querySelector('option[value="MULTI MODE"]');
    if (connmode === 'RADIUS_ONLY') {
        if (apiOpt) apiOpt.disabled = true;
        if (multiOpt) multiOpt.disabled = true;
        authmodeSelect.value = 'RADIUS MODE';
        if (note) note.classList.remove('d-none');
    } else {
        if (apiOpt) apiOpt.disabled = false;
        if (multiOpt) multiOpt.disabled = false;
        if (note) note.classList.add('d-none');
    }
}

// ==== OLT auto-registration (port dari addcustomerform.php / editcustomerform.php) ====
const STATICIP_ZTE_CONSOLE_PATH = '../olt/zte/index.php';
const STATICIP_OLT_TEMPLATE_ENDPOINT = 'getdata/get_olt_template.php';
const STATICIP_ZTE_ONU_TYPE_FALLBACK = [
    'HUAWEI-HG8245A', 'HUAWEI-HG8245H', 'HUAWEI-HG8245U', 'OPEN_FIBERHOME', 'OPEN_HUAWEI', 'OPEN_NOKIA', 'OPEN_ZTE',
    'ZTE-F609', 'ZTE-F660', 'ZTEG-9806H', 'ZTEG-F600', 'ZTEG-F609', 'ZTEG-F620', 'ZTEG-F625', 'ZTEG-F627',
    'ZTEG-F660', 'ZTEG-F670', 'ZTEG-F820', 'ZTEG-MSAG', 'ZXA10-F660'
];

function staticipEscapeHtml(text) {
    return String(text || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function staticipParseIpPort(ipData, defaultPort) {
    var ip = ipData || '';
    var port = defaultPort;
    if (ip.indexOf(':') !== -1) {
        var parts = ip.split(':');
        ip = parts[0];
        port = parseInt(parts[1], 10) || defaultPort;
    }
    return { ip: ip, port: port };
}

function staticipIsHiosoTwoPort(brand) {
    var value = String(brand || '').toUpperCase();
    return value === 'HIOSO EPON' || value === 'HIOSO EPON HA7302CST';
}

function staticipIsZteBrand(brand) {
    return /^ZTE GPON C/i.test(String(brand || '').toUpperCase());
}

async function staticipZteLogin(olt) {
    var parsed = staticipParseIpPort(olt.ipolt || '', 23);
    var fd = new FormData();
    fd.append('action', 'login');
    fd.append('ip', parsed.ip);
    fd.append('port', String(parsed.port));
    fd.append('username', olt.usernameolt || '');
    fd.append('password', olt.passwordolt || '');
    fd.append('devname', olt.oltname || parsed.ip);
    var response = await fetch(STATICIP_ZTE_CONSOLE_PATH, { method: 'POST', body: fd, credentials: 'same-origin' });
    var data = await response.json();
    if (!response.ok || data.error) {
        throw new Error(data.error || 'Login OLT gagal');
    }
    return data;
}

async function staticipZteRunAndPoll(command, onProgress) {
    var runBody = new URLSearchParams();
    runBody.append('action', 'run');
    runBody.append('command', command);
    var runResp = await fetch(STATICIP_ZTE_CONSOLE_PATH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: runBody.toString(),
        credentials: 'same-origin'
    });
    var runData = await runResp.json();
    if (!runResp.ok || runData.error || !runData.pid) {
        throw new Error(runData.error || 'Gagal memulai proses OLT');
    }

    var lastOutput = '';
    var lastMessage = '';
    for (var attempt = 0; attempt < 180; attempt++) {
        var statusResp = await fetch(STATICIP_ZTE_CONSOLE_PATH + '?action=status&pid=' + encodeURIComponent(runData.pid), { credentials: 'same-origin' });
        var statusData = await statusResp.json();
        if (typeof onProgress === 'function') {
            var diff = (statusData.output || '').slice(lastOutput.length);
            lastOutput = statusData.output || '';
            var nextStatus = Object.assign({}, statusData);
            if (nextStatus.message === lastMessage) {
                nextStatus.message = '';
            } else {
                lastMessage = nextStatus.message || '';
            }
            onProgress(nextStatus, diff);
        }
        if (statusData.status === 'done') {
            return statusData.output || '';
        }
        if (statusData.status === 'error') {
            throw new Error(statusData.message || 'Proses OLT gagal');
        }
        await new Promise(function (resolve) { setTimeout(resolve, 1200); });
    }
    throw new Error('Timeout menunggu respons OLT');
}

function staticipParseZteUncfg(raw) {
    var rows = [];
    var currentIntf = '';
    String(raw || '').split('\n').forEach(function (line) {
        var intfMatch = line.match(/interface\s+(gpon-olt_\S+)/i);
        if (intfMatch) {
            currentIntf = intfMatch[1];
            return;
        }
        var onuMatch = line.match(/onu\s+(\d+)\s+type\s+(\S+)\s+sn\s+(\S+)/i);
        if (onuMatch) {
            rows.push({ intf: currentIntf, onu: onuMatch[1], type: onuMatch[2], sn: onuMatch[3] });
        }
    });
    return rows;
}

function staticipParseZteVlanSum(raw) {
    var match = String(raw || '').match(/Details are following:\s*([\d ,]+)/s);
    if (!match) return [];
    return match[1].replace(/\s+/g, '').split(',').filter(Boolean);
}

function staticipParseZteTcont(raw) {
    var profiles = [];
    String(raw || '').split('\n').forEach(function (line) {
        var match = line.match(/Profile\s+name\s+:(\S+)/i);
        if (match && match[1]) profiles.push(match[1].trim());
    });
    return profiles;
}

function staticipParseZteOnuTypeNames(raw) {
    var set = new Set();
    String(raw || '').split('\n').forEach(function (line) {
        var clean = line.replace(/\s*--More--\s*/gi, '').trim();
        if (!clean) return;
        var match = clean.match(/^ONU\s+type\s+name\s*:\s*(.+)$/i);
        if (!match || !match[1]) return;
        set.add(match[1].trim());
    });
    return Array.from(set).filter(Boolean).sort(function (a, b) { return a.localeCompare(b); });
}

// Factory: 1x definisi dipakai 2x instance (modal Tambah & Edit).
function createStaticipOltController(prefix, serverSelectId, areaInputId, customerIdId, passwordId) {
    var ids = {
        wrap: prefix + 'OltWrap', select: prefix + 'OltSelect', empty: prefix + 'OltEmpty',
        info: prefix + 'OltInfo', unsupported: prefix + 'OltUnsupported', zteWrap: prefix + 'ZteWrap',
        zteLoading: prefix + 'ZteLoading', zteLoadingText: prefix + 'ZteLoadingText',
        refreshBtn: prefix + 'ZteRefreshBtn', onuSel: prefix + 'OnuSel', sn: prefix + 'Sn',
        intf: prefix + 'Intf', onuNo: prefix + 'OnuNo', typeSel: prefix + 'TypeSel',
        typeManual: prefix + 'TypeManual', tcont: prefix + 'TcontProfile', withCfg: prefix + 'WithCfg',
        cfgBtn: prefix + 'CfgBtn', cfgWrap: prefix + 'CfgWrap', vlanSel: prefix + 'VlanSel',
        vlan: prefix + 'Vlan', svc: prefix + 'Svc', vlanProfile: prefix + 'VlanProfile',
        gemport: prefix + 'Gemport', cos: prefix + 'Cos', oltUser: prefix + 'OltUser',
        oltPass: prefix + 'OltPass', ethuni: prefix + 'Ethuni', preview: prefix + 'Preview',
        processWrap: prefix + 'ProcessWrap', processToggle: prefix + 'ProcessToggle',
        processPanel: prefix + 'ProcessPanel', processLog: prefix + 'ProcessLog'
    };
    function el(key) { return document.getElementById(ids[key]); }

    var zteOnuTypeList = STATICIP_ZTE_ONU_TYPE_FALLBACK.slice();
    var currentOltList = [];
    var oltFetchController = null;
    var oltFetchSeq = 0;
    var zteRegisterContextCache = {};
    var processLogVisible = false;
    var pendingOltOntTemplate = null;

    function appendProcessLog(message) {
        var box = el('processLog');
        if (!box) return;
        var time = new Date().toLocaleTimeString('id-ID', { hour12: false });
        box.textContent += '[' + time + '] ' + message + '\n';
        box.scrollTop = box.scrollHeight;
    }

    function setProcessLogVisible(visible) {
        processLogVisible = !!visible;
        var panel = el('processPanel');
        var btn = el('processToggle');
        if (panel) panel.classList.toggle('d-none', !processLogVisible);
        if (btn) btn.textContent = processLogVisible ? 'Sembunyikan Log Proses' : 'Tampilkan Log Proses';
    }

    function toggleProcessLog() { setProcessLogVisible(!processLogVisible); }

    function clearProcessLog() {
        var box = el('processLog');
        if (box) box.textContent = '';
        setProcessLogVisible(false);
    }

    function setAutoRegLoading(isLoading, message) {
        var loadingEl = el('zteLoading');
        var loadingTextEl = el('zteLoadingText');
        var refreshBtn = el('refreshBtn');
        if (loadingEl) loadingEl.classList.toggle('d-none', !isLoading);
        if (loadingTextEl && message) loadingTextEl.textContent = message;
        if (refreshBtn) {
            refreshBtn.disabled = !!isLoading;
            refreshBtn.textContent = isLoading ? 'Memuat data...' : 'Refresh Data OLT';
        }
        ['onuSel', 'sn', 'intf', 'onuNo', 'typeSel', 'typeManual', 'tcont', 'vlanSel', 'vlan', 'svc', 'vlanProfile', 'gemport', 'cos', 'oltUser', 'oltPass', 'ethuni', 'cfgBtn'].forEach(function (key) {
            var fld = el(key);
            if (fld) fld.disabled = !!isLoading;
        });
        if (isLoading) {
            var preview = el('preview');
            if (preview) preview.textContent = '(Sedang memuat data OLT...)';
        }
    }

    function getSelectedOlt() {
        var index = (el('select') && el('select').value) || '';
        if (index === '') return null;
        return currentOltList[parseInt(index, 10)] || null;
    }

    function syncPppoeCredentialsFromCustomer() {
        var userEl = el('oltUser');
        var passEl = el('oltPass');
        var customerIdEl = document.getElementById(customerIdId);
        var passwordEl = document.getElementById(passwordId);
        var customerId = customerIdEl ? customerIdEl.value : '';
        var customerPass = passwordEl ? passwordEl.value : '';
        if (userEl) userEl.value = customerId.trim();
        if (passEl) passEl.value = customerPass.trim();
        updateAutoRegPreview();
    }

    function populateOltSelect(list) {
        var wrap = el('wrap');
        var select = el('select');
        var empty = el('empty');
        if (!wrap || !select || !empty) return;
        wrap.classList.remove('d-none');
        select.innerHTML = '<option value="">-- Pilih OLT --</option>';
        if (!Array.isArray(list) || !list.length) {
            empty.classList.remove('d-none');
            select.disabled = true;
            resetOltAutomationUi();
            return;
        }
        empty.classList.add('d-none');
        select.disabled = false;
        list.forEach(function (olt, idx) {
            var option = document.createElement('option');
            option.value = String(idx);
            option.textContent = olt.oltname + ' | ' + olt.brandolt + ' | ' + olt.ipolt;
            select.appendChild(option);
        });
    }

    function resetOltAutomationUi() {
        var info = el('info');
        var unsupported = el('unsupported');
        var zteWrap = el('zteWrap');
        if (info) { info.classList.add('d-none'); info.innerHTML = ''; }
        if (unsupported) unsupported.classList.add('d-none');
        if (zteWrap) zteWrap.classList.add('d-none');
    }

    function hideOltRemoteButtons() {
        var wrap = el('wrap');
        var select = el('select');
        if (wrap) wrap.classList.add('d-none');
        if (select) {
            select.innerHTML = '<option value="">-- Pilih OLT --</option>';
            select.value = '';
        }
        resetOltAutomationUi();
        clearProcessLog();
    }

    function populateAutoRegTypeOptions() {
        var sel = el('typeSel');
        if (!sel) return;
        var prev = sel.value;
        var manualEl = el('typeManual');
        var manualValue = manualEl ? manualEl.value : '';
        sel.innerHTML = '<option value="">— Pilih Type ONT —</option>';
        zteOnuTypeList.forEach(function (type) {
            var opt = document.createElement('option');
            opt.value = type;
            opt.textContent = type;
            sel.appendChild(opt);
        });
        var manualOpt = document.createElement('option');
        manualOpt.value = '__manual';
        manualOpt.textContent = 'Manual Input…';
        sel.appendChild(manualOpt);
        if (prev && Array.from(sel.options).some(function (o) { return o.value === prev; })) sel.value = prev;
        if (manualValue && manualEl) manualEl.value = manualValue;
        onAutoRegTypeChange();
    }

    function onAutoRegTypeChange() {
        var manual = el('typeManual');
        var sel = el('typeSel');
        if (!manual || !sel) return;
        manual.style.display = sel.value === '__manual' ? '' : 'none';
        updateAutoRegPreview();
    }

    function setAutoRegType(typeValue) {
        var sel = el('typeSel');
        var manual = el('typeManual');
        var value = String(typeValue || '').trim();
        if (!sel || !manual) return;
        if (value && zteOnuTypeList.indexOf(value) !== -1) {
            sel.value = value;
            manual.value = '';
        } else if (value) {
            sel.value = '__manual';
            manual.value = value;
        } else {
            sel.value = '';
            manual.value = '';
        }
        onAutoRegTypeChange();
    }

    function getAutoRegType() {
        var sel = el('typeSel');
        var value = sel ? sel.value : '';
        if (value === '__manual') {
            var manual = el('typeManual');
            return (manual ? manual.value : '').trim();
        }
        return value.trim();
    }

    function populateAutoRegUncfg(rows) {
        var sel = el('onuSel');
        if (!sel) return;
        var prev = sel.value;
        sel.innerHTML = '<option value="">— Pilih ONU Unconfig —</option>';
        if (!rows.length) {
            sel.disabled = true;
            return;
        }
        sel.disabled = false;
        rows.forEach(function (row) {
            var opt = document.createElement('option');
            opt.value = row.intf + '|' + row.onu;
            opt.dataset.intf = row.intf || '';
            opt.dataset.onu = row.onu || '';
            opt.dataset.type = row.type || '';
            opt.dataset.sn = row.sn || '';
            opt.textContent = row.intf + ' | onu ' + row.onu + ' | type ' + row.type + ' | SN:' + row.sn;
            sel.appendChild(opt);
        });
        if (prev && Array.from(sel.options).some(function (o) { return o.value === prev; })) {
            sel.value = prev;
        } else if (sel.options.length > 1) {
            sel.selectedIndex = 1;
        }
        syncAutoRegFromSelect();
    }

    function populateAutoRegTcont(profiles) {
        var sel = el('tcont');
        if (!sel) return;
        var prev = sel.value;
        sel.innerHTML = '<option value="">— Pilih Profile TCONT —</option>';
        sel.disabled = !profiles.length;
        profiles.forEach(function (profile) {
            var opt = document.createElement('option');
            opt.value = profile;
            opt.textContent = profile;
            sel.appendChild(opt);
        });
        if (prev && Array.from(sel.options).some(function (o) { return o.value === prev; })) sel.value = prev;
    }

    function populateAutoRegVlans(vlans) {
        var sel = el('vlanSel');
        if (!sel) return;
        var prev = sel.value;
        sel.innerHTML = '<option value="">— Pilih VLAN —</option>';
        sel.disabled = !vlans.length;
        vlans.forEach(function (vlan) {
            var opt = document.createElement('option');
            opt.value = vlan;
            opt.textContent = 'VLAN ' + vlan;
            sel.appendChild(opt);
        });
        if (prev && Array.from(sel.options).some(function (o) { return o.value === prev; })) sel.value = prev;
    }

    function syncAutoRegFromSelect() {
        var sel = el('onuSel');
        if (!sel || !sel.value) return;
        var opt = sel.options[sel.selectedIndex];
        el('intf').value = (opt && opt.dataset.intf) || '';
        el('onuNo').value = (opt && opt.dataset.onu) || '';
        el('sn').value = String((opt && opt.dataset.sn) || '').toUpperCase();
        setAutoRegType((opt && opt.dataset.type) || '');
        updateAutoRegPreview();
    }

    function syncAutoRegVlanText() {
        var vlanSel = el('vlanSel');
        var vlanInput = el('vlan');
        if (vlanSel && vlanInput && vlanSel.value) vlanInput.value = vlanSel.value;
        updateAutoRegPreview();
    }

    function toggleAutoRegConfig() {
        var chk = el('withCfg');
        if (!chk) return;
        chk.checked = !chk.checked;
        var wrap = el('cfgWrap');
        var btn = el('cfgBtn');
        if (wrap) wrap.style.display = chk.checked ? 'block' : 'none';
        if (btn) btn.textContent = chk.checked ? '✓ Config WAN Aktif' : '+ Aktifkan Config WAN Sekaligus';
        updateAutoRegPreview();
    }

    function getAutoRegOnuId() {
        var intfEl = el('intf');
        var onuNoEl = el('onuNo');
        var intf = intfEl ? intfEl.value.trim() : '';
        var onuNo = onuNoEl ? onuNoEl.value.trim() : '';
        if (!intf || !onuNo) return '';
        return 'gpon-onu_' + intf.replace(/^gpon-olt_/i, '') + ':' + onuNo;
    }

    function generateAutoRegServiceConfig(onuId) {
        var vlanEl = el('vlan');
        var userEl = el('oltUser');
        var passEl = el('oltPass');
        var svcEl = el('svc');
        var gemportEl = el('gemport');
        var cosEl = el('cos');
        var profileEl = el('vlanProfile');
        var ethuniEl = el('ethuni');
        var vlan = vlanEl ? vlanEl.value.trim() : '';
        var user = userEl ? userEl.value.trim() : '';
        var pass = passEl ? passEl.value.trim() : '';
        var svc = svcEl ? svcEl.value.trim() : 'HSI';
        var gemport = gemportEl ? gemportEl.value.trim() : '1';
        var cos = cosEl ? cosEl.value.trim() : '0';
        var profile = profileEl ? profileEl.value.trim() : 'PPPoE';
        var ethuni = ethuniEl ? ethuniEl.value.trim() : '1,2,3';
        if (!onuId || !vlan || !user || !pass) return '';
        return 'pon-onu-mng ' + onuId + '\n  service ' + svc + ' type internet gemport ' + gemport + ' cos ' + cos + ' vlan ' + vlan +
            '\n  wan-ip 1 mode pppoe username ' + user + ' password ' + pass + ' vlan-profile ' + profile + ' host 1' +
            '\n  wan-ip 1 ping-response enable traceroute-response enable' +
            '\n  wan 1 ethuni ' + ethuni + ' ssid 1 service internet host 1\nexit';
    }

    function buildAutoRegisterCommand() {
        var intfEl = el('intf');
        var onuNoEl = el('onuNo');
        var snEl = el('sn');
        var tcontEl = el('tcont');
        var intf = intfEl ? intfEl.value.trim() : '';
        var onuNo = onuNoEl ? onuNoEl.value.trim() : '';
        var type = getAutoRegType();
        var sn = (snEl ? snEl.value.trim() : '').toUpperCase();
        var tcont = tcontEl ? tcontEl.value.trim() : '';
        if (!intf || !onuNo || !type || !sn) return '';
        var onuCmd = 'onu ' + onuNo + ' type ' + type + ' sn ' + sn;
        if (tcont) onuCmd += ' tcont-profile ' + tcont;
        var base = 'config t\ninterface ' + intf + '\n  ' + onuCmd + '\nexit';
        var withCfgEl = el('withCfg');
        if (!withCfgEl || !withCfgEl.checked) return base;
        var onuId = getAutoRegOnuId();
        var serviceCfg = generateAutoRegServiceConfig(onuId);
        if (!serviceCfg) return '';
        return base + '\n' + serviceCfg;
    }

    function updateAutoRegPreview() {
        var preview = el('preview');
        if (!preview) return;
        var cmd = buildAutoRegisterCommand();
        var withCfgEl = el('withCfg');
        var withCfg = withCfgEl && withCfgEl.checked;
        preview.textContent = cmd || (withCfg ? '(Lengkapi ONU, Type, SN, VLAN, PPPoE user dan password)' : '(Lengkapi ONU, Type, dan SN)');
    }

    function setSelectValueIfExists(selectEl, value) {
        if (!selectEl) return false;
        var val = String(value || '').trim();
        if (!val) return false;
        var exists = Array.from(selectEl.options).some(function (opt) { return String(opt.value).trim() === val; });
        if (!exists) return false;
        selectEl.value = val;
        selectEl.dispatchEvent(new Event('change'));
        return true;
    }

    function setInputValueById(key, value) {
        var elm = el(key);
        if (!elm) return;
        elm.value = value == null ? '' : String(value);
        elm.dispatchEvent(new Event('input'));
    }

    function applyOltTemplate(template) {
        if (!template) return;
        pendingOltOntTemplate = template;
        if (!setSelectValueIfExists(el('tcont'), template.tcont_profile)) {
            setInputValueById('tcont', '');
        }
        if (setSelectValueIfExists(el('vlanSel'), template.vlan_id)) {
            syncAutoRegVlanText();
        }
        setInputValueById('svc', template.service_name || 'HSI');
        setInputValueById('vlanProfile', template.vlan_profile || 'PPPoE');
        setInputValueById('gemport', template.gemport || '1');
        setInputValueById('cos', template.cos || '0');
        setInputValueById('ethuni', template.ethuni || '1,2,3');
        setInputValueById('vlan', template.vlan_manual || template.vlan_id || '');
        if (template.ont_type) {
            setAutoRegType(template.ont_type);
        }
        updateAutoRegPreview();
    }

    function loadOltTemplate(oltId) {
        if (!oltId) return Promise.resolve();
        return fetch(STATICIP_OLT_TEMPLATE_ENDPOINT + '?olt_id=' + encodeURIComponent(oltId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp && resp.success && resp.data) {
                    applyOltTemplate(resp.data);
                } else {
                    pendingOltOntTemplate = null;
                }
            })
            .catch(function () {
                pendingOltOntTemplate = null;
            });
    }

    async function loadSelectedOltContext() {
        var olt = getSelectedOlt();
        var info = el('info');
        var unsupported = el('unsupported');
        var zteWrap = el('zteWrap');
        resetOltAutomationUi();
        clearProcessLog();
        if (!olt) return;

        await loadOltTemplate(olt.id);

        if (info) {
            info.classList.remove('d-none');
            info.innerHTML = '<strong>OLT:</strong> ' + staticipEscapeHtml(olt.oltname) + ' | <strong>Brand:</strong> ' + staticipEscapeHtml(olt.brandolt) + ' | <strong>IP:</strong> ' + staticipEscapeHtml(olt.ipolt);
        }

        if (staticipIsHiosoTwoPort(olt.brandolt)) {
            if (unsupported) {
                unsupported.classList.remove('d-none');
                unsupported.textContent = 'Brand HIOSO EPON 2 PON tidak memakai form register otomatis di halaman ini.';
            }
            return;
        }

        if (!staticipIsZteBrand(olt.brandolt)) {
            if (unsupported) {
                unsupported.classList.remove('d-none');
                unsupported.textContent = 'Otomasi register inline saat ini tersedia untuk ZTE. Simpan customer tetap bisa dijalankan untuk brand ini, tetapi register ONT masih manual.';
            }
            return;
        }

        if (zteWrap) zteWrap.classList.remove('d-none');
        setAutoRegLoading(true, 'Mengambil data OLT ' + olt.oltname + '...');
        zteOnuTypeList = STATICIP_ZTE_ONU_TYPE_FALLBACK.slice();
        populateAutoRegTypeOptions();
        syncPppoeCredentialsFromCustomer();
        appendProcessLog('Mengambil data register dari OLT ' + olt.oltname + '...');

        try {
            var cacheKey = String(olt.id || olt.ipolt || olt.oltname);
            var context = zteRegisterContextCache[cacheKey];
            if (!context) {
                await staticipZteLogin(olt);
                var raw = await staticipZteRunAndPoll('show gpon onu uncfg\nshow vlan sum\nshow gpon profile tcont\nshow onu-type gpon', function (status) {
                    if (status.message) appendProcessLog(status.message);
                });
                context = {
                    uncfg: staticipParseZteUncfg(raw),
                    vlans: staticipParseZteVlanSum(raw),
                    tcontProfiles: staticipParseZteTcont(raw),
                    onuTypes: staticipParseZteOnuTypeNames(raw)
                };
                zteRegisterContextCache[cacheKey] = context;
            }
            zteOnuTypeList = (context.onuTypes && context.onuTypes.length) ? context.onuTypes : STATICIP_ZTE_ONU_TYPE_FALLBACK.slice();
            populateAutoRegTypeOptions();
            populateAutoRegUncfg(context.uncfg || []);
            populateAutoRegVlans(context.vlans || []);
            populateAutoRegTcont(context.tcontProfiles || []);
            if (pendingOltOntTemplate) {
                applyOltTemplate(pendingOltOntTemplate);
            }
            updateAutoRegPreview();
            appendProcessLog('Data ONU unconfig, VLAN, dan profile TCONT berhasil dimuat.');
        } catch (error) {
            appendProcessLog('Gagal memuat data OLT: ' + error.message);
        } finally {
            setAutoRegLoading(false);
        }
    }

    function loadOltRemoteButtons() {
        var server = document.getElementById(serverSelectId).value;
        var area = document.getElementById(areaInputId).value;
        var reqSeq = ++oltFetchSeq;

        if (!server || !area) {
            if (oltFetchController) {
                oltFetchController.abort();
                oltFetchController = null;
            }
            currentOltList = [];
            hideOltRemoteButtons();
            return;
        }

        if (oltFetchController) {
            oltFetchController.abort();
        }
        oltFetchController = new AbortController();

        fetch('getdata/get_olt_by_server_area.php?server=' + encodeURIComponent(server) + '&area=' + encodeURIComponent(area), {
            signal: oltFetchController.signal
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (reqSeq !== oltFetchSeq) return;
                currentOltList = (res && res.success && Array.isArray(res.data)) ? res.data : [];
                populateOltSelect(currentOltList);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (reqSeq !== oltFetchSeq) return;
                currentOltList = [];
                hideOltRemoteButtons();
            });
    }

    if (el('select')) el('select').addEventListener('change', loadSelectedOltContext);
    if (el('onuSel')) el('onuSel').addEventListener('change', syncAutoRegFromSelect);
    if (el('vlanSel')) el('vlanSel').addEventListener('change', syncAutoRegVlanText);
    if (el('typeSel')) el('typeSel').addEventListener('change', onAutoRegTypeChange);
    if (el('refreshBtn')) el('refreshBtn').addEventListener('click', loadSelectedOltContext);
    if (el('cfgBtn')) el('cfgBtn').addEventListener('click', toggleAutoRegConfig);
    if (el('processToggle')) el('processToggle').addEventListener('click', toggleProcessLog);
    var customerIdEl = document.getElementById(customerIdId);
    var passwordEl = document.getElementById(passwordId);
    if (customerIdEl) customerIdEl.addEventListener('input', syncPppoeCredentialsFromCustomer);
    if (passwordEl) passwordEl.addEventListener('input', syncPppoeCredentialsFromCustomer);
    ['sn', 'intf', 'onuNo', 'typeManual', 'tcont', 'vlan', 'svc', 'vlanProfile', 'gemport', 'cos', 'oltUser', 'oltPass', 'ethuni'].forEach(function (key) {
        var fld = el(key);
        if (fld) fld.addEventListener('input', updateAutoRegPreview);
    });

    return {
        loadOltRemoteButtons: loadOltRemoteButtons,
        hideOltRemoteButtons: hideOltRemoteButtons,
        clearProcessLog: clearProcessLog,
        getSelectedOlt: getSelectedOlt,
        buildAutoRegisterCommand: buildAutoRegisterCommand,
        zteLogin: staticipZteLogin,
        zteRunAndPoll: staticipZteRunAndPoll,
        appendProcessLog: appendProcessLog,
        isZteRegisterPanelVisible: function () {
            var zteWrap = el('zteWrap');
            return !!(zteWrap && !zteWrap.classList.contains('d-none'));
        },
        isWithCfgChecked: function () {
            var chk = el('withCfg');
            return !!(chk && chk.checked);
        },
        hasIncompleteRequiredFields: function () {
            var intfEl = el('intf'), onuNoEl = el('onuNo'), snEl = el('sn');
            var intf = intfEl ? intfEl.value.trim() : '';
            var onuNo = onuNoEl ? onuNoEl.value.trim() : '';
            var sn = snEl ? snEl.value.trim() : '';
            var type = getAutoRegType();
            if (!intf || !onuNo || !type || !sn) return true;
            var chk = el('withCfg');
            if (chk && chk.checked) {
                var vlanEl = el('vlan'), userEl = el('oltUser'), passEl = el('oltPass');
                var vlan = vlanEl ? vlanEl.value.trim() : '';
                var user = userEl ? userEl.value.trim() : '';
                var pass = passEl ? passEl.value.trim() : '';
                if (!vlan || !user || !pass) return true;
            }
            return false;
        }
    };
}

var addStaticOltCtrl = createStaticipOltController('addStatic', 'addStaticServer', 'addStaticArea', 'addStaticCustomerID', 'addStaticPasswordPPPOE');
var editStaticOltCtrl = createStaticipOltController('editStatic', 'editStaticServer', 'editStaticArea', 'editStaticIdpel', 'editStaticPassword');

// ==== Wilayah (Provinsi/Kabupaten/Kecamatan/Kelurahan) -- port dari addcustomerform.php / editcustomerform.php ====
function createStaticipWilayahController(prefix) {
    var ids = {
        provinsi: prefix + 'Provinsi', kabupaten: prefix + 'Kabupaten',
        kecamatan: prefix + 'Kecamatan', kelurahan: prefix + 'Kelurahan'
    };
    function el(key) { return document.getElementById(ids[key]); }

    var provinsiDataGlobal = [];
    var kabupatenDataGlobal = [];
    var kecamatanDataGlobal = [];
    var pendingKabupaten = '';
    var pendingKecamatan = '';
    var pendingKelurahan = '';

    function optHtml(list, placeholder) {
        return '<option value="">' + placeholder + '</option>' + list.map(function (item) {
            var v = staticipEscapeHtml(item.name);
            return '<option value="' + v + '">' + v + '</option>';
        }).join('');
    }

    el('provinsi').addEventListener('change', function () {
        var provName = this.value;
        var prov = provinsiDataGlobal.find(function (p) { return p.name === provName; });
        if (!prov) return;
        fetch('https://www.emsifa.com/api-wilayah-indonesia/api/regencies/' + prov.id + '.json')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                kabupatenDataGlobal = data;
                el('kabupaten').innerHTML = optHtml(data, 'Pilih Kabupaten/Kota');
                el('kecamatan').innerHTML = optHtml([], 'Pilih Kecamatan');
                el('kelurahan').innerHTML = optHtml([], 'Pilih Kelurahan');
                if (pendingKabupaten) {
                    var wanted = pendingKabupaten;
                    pendingKabupaten = '';
                    var kabupatenSelect = el('kabupaten');
                    var opt = Array.from(kabupatenSelect.options).find(function (o) { return o.text === wanted; });
                    if (opt) {
                        kabupatenSelect.value = opt.value;
                        kabupatenSelect.dispatchEvent(new Event('change'));
                    }
                }
            });
    });

    el('kabupaten').addEventListener('change', function () {
        var kabName = this.value;
        var kab = kabupatenDataGlobal.find(function (k) { return k.name === kabName; });
        if (!kab) return;
        fetch('https://www.emsifa.com/api-wilayah-indonesia/api/districts/' + kab.id + '.json')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                kecamatanDataGlobal = data;
                el('kecamatan').innerHTML = optHtml(data, 'Pilih Kecamatan');
                el('kelurahan').innerHTML = optHtml([], 'Pilih Kelurahan');
                if (pendingKecamatan) {
                    var wanted = pendingKecamatan;
                    pendingKecamatan = '';
                    var kecSelect = el('kecamatan');
                    var opt = Array.from(kecSelect.options).find(function (o) { return o.text === wanted; });
                    if (opt) {
                        kecSelect.value = opt.value;
                        kecSelect.dispatchEvent(new Event('change'));
                    }
                }
            });
    });

    el('kecamatan').addEventListener('change', function () {
        var kecName = this.value;
        var kec = kecamatanDataGlobal.find(function (k) { return k.name === kecName; });
        if (!kec) return;
        fetch('https://www.emsifa.com/api-wilayah-indonesia/api/villages/' + kec.id + '.json')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                el('kelurahan').innerHTML = optHtml(data, 'Pilih Kelurahan');
                if (pendingKelurahan) {
                    var wanted = pendingKelurahan;
                    pendingKelurahan = '';
                    var kelSelect = el('kelurahan');
                    var opt = Array.from(kelSelect.options).find(function (o) { return o.text === wanted; });
                    if (opt) kelSelect.value = opt.value;
                }
            });
    });

    function ensureProvinsiLoaded() {
        if (provinsiDataGlobal.length) return Promise.resolve(provinsiDataGlobal);
        return fetch('https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                provinsiDataGlobal = data;
                el('provinsi').innerHTML = optHtml(data, 'Pilih Provinsi');
                return data;
            });
    }

    function reset() {
        el('provinsi').innerHTML = optHtml([], 'Pilih Provinsi');
        el('kabupaten').innerHTML = optHtml([], 'Pilih Kabupaten/Kota');
        el('kecamatan').innerHTML = optHtml([], 'Pilih Kecamatan');
        el('kelurahan').innerHTML = optHtml([], 'Pilih Kelurahan');
        provinsiDataGlobal = [];
        kabupatenDataGlobal = [];
        kecamatanDataGlobal = [];
        pendingKabupaten = '';
        pendingKecamatan = '';
        pendingKelurahan = '';
        return ensureProvinsiLoaded();
    }

    function prefill(provinsiVal, kabupatenVal, kecamatanVal, kelurahanVal) {
        pendingKabupaten = '';
        pendingKecamatan = kecamatanVal || '';
        pendingKelurahan = kelurahanVal || '';
        ensureProvinsiLoaded().then(function (provinsiData) {
            var provSelect = el('provinsi');
            if (provinsiVal) {
                provSelect.value = provinsiVal;
                if (provSelect.value === provinsiVal) {
                    provSelect.dispatchEvent(new Event('change'));
                    return;
                }
            }
            if (kabupatenVal) {
                pendingKabupaten = kabupatenVal;
                fetch('https://www.emsifa.com/api-wilayah-indonesia/api/regencies.json')
                    .then(function (r) { return r.json(); })
                    .then(function (allKab) {
                        kabupatenDataGlobal = allKab;
                        var kab = allKab.find(function (k) { return k.name === kabupatenVal; });
                        if (kab) {
                            var prov = provinsiData.find(function (p) { return p.id == kab.province_id; });
                            if (prov) {
                                provSelect.value = prov.name;
                                provSelect.dispatchEvent(new Event('change'));
                            }
                        }
                    });
            }
        });
    }

    return { ensureProvinsiLoaded: ensureProvinsiLoaded, reset: reset, prefill: prefill };
}

var addStaticWilayahCtrl = createStaticipWilayahController('addStatic');
var editStaticWilayahCtrl = createStaticipWilayahController('editStatic');

// ---- Modal Tambah ----
document.getElementById('addCustomerStaticModal').addEventListener('show.bs.modal', function () {
    addStaticWilayahCtrl.reset();
});
document.getElementById('addStaticServer').addEventListener('change', function () {
    var selected = this.options[this.selectedIndex];
    var area = selected ? (selected.getAttribute('data-area') || '') : '';
    document.getElementById('addStaticArea').value = area;
    staticipApplyAuthLock('addStaticServer', 'addStaticAuthmode', 'addStaticAuthmodeNote');
    staticipLoadOdp('addStaticServer', 'addStaticArea', 'addStaticOdp', '');
    staticipLoadPackages('addStaticServer', 'addStaticArea', 'addStaticPackages', '');
    staticipLoadIps('addStaticServer', 'addStaticArea', 'addStaticIp', '');
    addStaticOltCtrl.loadOltRemoteButtons();
});

// ---- Modal Edit ----
var editStaticModalEl = document.getElementById('editCustomerStaticModal');
editStaticModalEl.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    var idpel = btn.getAttribute('data-idpel') || '';
    document.getElementById('editStaticIdpelOld').value = idpel;
    document.getElementById('editStaticIdpel').value = idpel;
    document.getElementById('editStaticPassword').value = '';
    document.getElementById('editStaticNik').value = btn.getAttribute('data-nik') || '';
    document.getElementById('editStaticNama').value = btn.getAttribute('data-nama') || '';
    document.getElementById('editStaticAlamat').value = btn.getAttribute('data-alamat') || '';
    document.getElementById('editStaticWa').value = btn.getAttribute('data-wa') || '';
    document.getElementById('editStaticEmail').value = btn.getAttribute('data-email') || '';
    document.getElementById('editStaticTikor').value = btn.getAttribute('data-tikor') || '';
    document.getElementById('editStaticSales').value = btn.getAttribute('data-sales') || '';
    document.getElementById('editStaticTipeBayar').value = btn.getAttribute('data-tipebayar') || 'prabayar';
    document.getElementById('editStaticTipeTempo').value = btn.getAttribute('data-tipetempo') || 'mengikuti_tanggal_bayar';
    document.getElementById('editStaticAuthmode').value = btn.getAttribute('data-mode') || 'API MODE';
    document.getElementById('editStaticRw').value = btn.getAttribute('data-rw') || '';
    document.getElementById('editStaticRt').value = btn.getAttribute('data-rt') || '';

    editStaticWilayahCtrl.prefill(
        btn.getAttribute('data-provinsi') || '',
        btn.getAttribute('data-kabupaten') || '',
        btn.getAttribute('data-kecamatan') || '',
        btn.getAttribute('data-kelurahan') || ''
    );

    var pemilik = btn.getAttribute('data-pemilik') || '';
    var area = btn.getAttribute('data-area') || '';
    document.getElementById('editStaticServer').value = pemilik;
    document.getElementById('editStaticArea').value = area;
    document.getElementById('editStaticServerLama').value = pemilik;
    document.getElementById('editStaticAreaLama').value = area;

    var selectedOdp = btn.getAttribute('data-odp') || '';
    var selectedPaket = btn.getAttribute('data-paket') || '';
    var currentIp = btn.getAttribute('data-ipstatic') || '';

    staticipLoadOdp('editStaticServer', 'editStaticArea', 'editStaticOdp', selectedOdp);
    staticipLoadPackages('editStaticServer', 'editStaticArea', 'editStaticPackages', selectedPaket);
    staticipLoadIps('editStaticServer', 'editStaticArea', 'editStaticIp', currentIp);
    editStaticOltCtrl.loadOltRemoteButtons();
});
document.getElementById('editStaticServer').addEventListener('change', function () {
    var selected = this.options[this.selectedIndex];
    var area = selected ? (selected.getAttribute('data-area') || '') : '';
    document.getElementById('editStaticArea').value = area;
    staticipApplyAuthLock('editStaticServer', 'editStaticAuthmode', null);
    staticipLoadOdp('editStaticServer', 'editStaticArea', 'editStaticOdp', '');
    staticipLoadPackages('editStaticServer', 'editStaticArea', 'editStaticPackages', '');
    staticipLoadIps('editStaticServer', 'editStaticArea', 'editStaticIp', '');
    editStaticOltCtrl.loadOltRemoteButtons();
});

// ---- Submit modal Tambah/Edit: buat customer dulu (fetch JSON), baru registrasi ONU kalau ada ----
function staticipWireOltSubmit(formId, saveBtnId, oltCtrl) {
    var form = document.getElementById(formId);
    var saveBtn = document.getElementById(saveBtnId);
    if (!form || !saveBtn) return;
    var labelEl = saveBtn.querySelector('.btn-label');
    var loadingEl = saveBtn.querySelector('.btn-loading');

    function setSubmitting(isSubmitting) {
        saveBtn.disabled = isSubmitting;
        if (labelEl) labelEl.classList.toggle('d-none', isSubmitting);
        if (loadingEl) loadingEl.classList.toggle('d-none', !isSubmitting);
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (oltCtrl.isZteRegisterPanelVisible() && oltCtrl.hasIncompleteRequiredFields()) {
            alert('Lengkapi dulu data register ONT (ONU, Type, SN' + (oltCtrl.isWithCfgChecked() ? ', VLAN, PPPoE user & password' : '') + ') sebelum menyimpan, atau nonaktifkan panel register kalau memang belum mau registrasi ONT sekarang.');
            return;
        }

        var registerCommand = oltCtrl.isZteRegisterPanelVisible() ? oltCtrl.buildAutoRegisterCommand() : '';
        var selectedOlt = oltCtrl.getSelectedOlt();

        setSubmitting(true);
        try {
            var fd = new FormData(form);
            fd.append('response_mode', 'json');
            var resp = await fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' });
            var data = await resp.json();

            if (!data || !data.success) {
                alert((data && data.message) || 'Gagal menyimpan data customer.');
                setSubmitting(false);
                return;
            }

            if (registerCommand && selectedOlt) {
                oltCtrl.appendProcessLog('Customer berhasil disimpan, memulai registrasi ONU ke OLT...');
                try {
                    await oltCtrl.zteLogin(selectedOlt);
                    await oltCtrl.zteRunAndPoll(registerCommand, function (status) {
                        if (status.message) oltCtrl.appendProcessLog(status.message);
                    });
                    oltCtrl.appendProcessLog('Registrasi ONU ke OLT selesai.');
                } catch (oltError) {
                    oltCtrl.appendProcessLog('Registrasi ONU gagal: ' + oltError.message);
                }
            }

            window.location.href = data.redirect || 'tablesstaticip.php';
        } catch (err) {
            alert('Gagal menghubungi server: ' + err.message);
            setSubmitting(false);
        }
    });
}

staticipWireOltSubmit('addStaticForm', 'addStaticSaveBtn', addStaticOltCtrl);
staticipWireOltSubmit('editStaticForm', 'editStaticSaveBtn', editStaticOltCtrl);
</script>

<?php require 'footer.php'; ?>
