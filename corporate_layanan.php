<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Corporate_Customer', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Customer Corporate.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/corporate_helper.php';
corporateEnsureSchema($conn);

$corporateId = (int) ($_GET['corporate_id'] ?? 0);
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
$corp = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM corporate WHERE id = $corporateId AND PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " LIMIT 1"));
if (!$corp) {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Customer Corporate tidak ditemukan.</div></div>';
    require 'footer.php';
    exit;
}

$status = $_GET['statusnotif'] ?? '';
$edit = $_GET['edit'] ?? '';
$deleted = $_GET['deleted'] ?? '';
$isolir = $_GET['isolir'] ?? '';

$jenisLayananOptions = [
    'Internet Dedicated', 'Broadband', 'Metro Ethernet', 'MPLS', 'IP Transit',
    'VPN', 'CCTV', 'VoIP', 'Data Center', 'Colocation', 'Cloud', 'Managed Service',
];
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Layanan berhasil ditambahkan.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($edit === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Layanan berhasil diperbarui.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($edit === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal memperbarui layanan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($deleted === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Layanan berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($deleted === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal menghapus layanan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($isolir === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Status koneksi berhasil diubah.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($isolir === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal mengubah status koneksi.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php
function corporate_layanan_render_server_options($conn, $AKSES, $area_list, $current_user_id) {
    if (!$current_user_id) {
        return;
    }
    if ($AKSES == 'ASSISTANT') {
        $q = mysqli_query($conn, "SELECT DISTINCT id, PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE AREA IN ($area_list)");
    } else {
        $q = mysqli_query($conn, "SELECT DISTINCT id, PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE user_id = $current_user_id");
    }
    while ($row = mysqli_fetch_assoc($q)) {
        $area = htmlspecialchars($row['AREA']);
        $connmode = htmlspecialchars($row['CONNECTION_MODE'] ?? 'API');
        echo '<option value="' . htmlspecialchars($row['PEMILIK']) . '" data-id="' . (int) $row['id'] . '" data-area="' . $area . '" data-connmode="' . $connmode . '">' . htmlspecialchars($row['BRAND']) . '-' . $area . '</option>';
    }
}

// VLAN & OLT -- tenant-scoped penuh (TIDAK difilter per-server dulu, lihat
// plan: dropdown penuh, admin yang cocokkan manual dgn Server yang dipilih).
$vlanOptions = [];
if ($current_user_id) {
    if ($AKSES == 'ASSISTANT') {
        $qVlan = mysqli_query($conn, "SELECT v.id, v.vlan_id, v.keterangan, s.AREA FROM vlan v JOIN server s ON s.id = v.server_id WHERE s.AREA IN ($area_list)");
    } else {
        $qVlan = mysqli_query($conn, "SELECT v.id, v.vlan_id, v.keterangan, s.AREA FROM vlan v JOIN server s ON s.id = v.server_id WHERE s.user_id = $current_user_id");
    }
    if ($qVlan) {
        while ($v = mysqli_fetch_assoc($qVlan)) {
            $vlanOptions[] = $v;
        }
    }
}
$oltOptions = [];
if ($current_user_id) {
    if ($AKSES == 'ASSISTANT') {
        $qOlt = mysqli_query($conn, "SELECT o.id, o.oltname, o.area FROM olt o WHERE EXISTS (SELECT 1 FROM server s WHERE s.PEMILIK = o.pemilik AND s.AREA IN ($area_list))");
    } else {
        $qOlt = mysqli_query($conn, "SELECT o.id, o.oltname, o.area FROM olt o WHERE EXISTS (SELECT 1 FROM server s WHERE s.PEMILIK = o.pemilik AND s.user_id = $current_user_id)");
    }
    if ($qOlt) {
        while ($o = mysqli_fetch_assoc($qOlt)) {
            $oltOptions[] = $o;
        }
    }
}
?>

<style>
    /* ==== Status live (Online/Offline/EXPIRED) + panel ACS -- disalin dari
       tables.php (Customer PPPoE)/tablesstaticip.php, versi TAMPILAN (tanpa
       peta/grafik/tombol Edit SSID-WAN/Restart). Cuma berlaku utk layanan
       provisioning_aktif=1 (yang punya PPPoE secret sungguhan). Lihat memory
       project_corporate_status_overview.md. ==== */
    .status-action-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 4px; margin-bottom: 4px; }
    .status-action-row > div[id^="remoteContainer-"] { display: inline-flex; }
    .customer-action-btn { display: inline-flex; align-items: center; justify-content: center; width: auto; min-height: 0; padding: 0.2rem 0.5rem; font-size: 10px; font-weight: 600; line-height: 1.2; text-align: center; white-space: nowrap; border-radius: 999px; }
    .modal-action-btn { --bs-btn-color: var(--white, #ffffff); --bs-btn-hover-color: var(--white, #ffffff); --bs-btn-active-color: var(--white, #ffffff); --bs-btn-disabled-color: var(--white, #ffffff); color: var(--white, #ffffff) !important; text-decoration: none !important; font-weight: 700 !important; opacity: 1 !important; text-shadow: none !important; -webkit-text-fill-color: var(--white, #ffffff) !important; background-clip: border-box !important; -webkit-background-clip: border-box !important; }
    .modal-action-btn:hover, .modal-action-btn:focus, .modal-action-btn:active { color: var(--white, #ffffff) !important; text-decoration: none !important; opacity: 1 !important; -webkit-text-fill-color: var(--white, #ffffff) !important; }
    .modal-action-btn, div[id^="remoteContainerModal-"] .modal-action-btn { width: 100% !important; display: inline-flex; align-items: center; justify-content: center; }
    .modal-action-btn *, div[id^="remoteContainerModal-"] .modal-action-btn * { color: var(--white, #ffffff) !important; -webkit-text-fill-color: var(--white, #ffffff) !important; opacity: 1 !important; text-shadow: none !important; }
    .modal-action-btn.btn-secondary { background-color: var(--logo-secondary, #3b82f6) !important; border-color: var(--logo-secondary, #3b82f6) !important; }
    .modal-action-btn.btn-secondary:hover, .modal-action-btn.btn-secondary:focus, .modal-action-btn.btn-secondary:active { background-color: var(--logo-primary, #2563eb) !important; border-color: var(--logo-primary, #2563eb) !important; }
    .overview-modal-dialog { width: 100vw; max-width: 100vw; height: 100vh; margin: 0; }
    .modal[id^="exampleoverviewcorp"] { --bs-modal-width: 100vw; padding: 0 !important; }
    .modal[id^="exampleoverviewcorp"] .overview-modal-dialog, .modal[id^="exampleoverviewcorp"] .modal-dialog { width: 100vw !important; max-width: 100vw !important; min-width: 100vw !important; height: 100vh !important; margin: 0 !important; }
    .modal[id^="exampleoverviewcorp"] .overview-modal-content, .modal[id^="exampleoverviewcorp"] .modal-content { width: 100vw !important; max-width: 100vw !important; height: 100vh !important; max-height: 100vh !important; border-radius: 0 !important; }
    .overview-modal-content { flex-direction: column !important; display: flex !important; width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
    .overview-modal-body { font-size: 13.5px; padding: 16px; flex: 1 1 auto; overflow-y: auto; }
    .modal[id^="exampleoverviewcorp"] .overview-main-row { align-items: flex-start; }
    .modal[id^="exampleoverviewcorp"] .overview-formdata-col .form-label { font-size: 13px; margin-bottom: 0.25rem; color: #334155; font-weight: 700; }
    .modal[id^="exampleoverviewcorp"] .overview-formdata-col .form-control { font-size: 14px; padding: 0.45rem 0.65rem; }
    .modal[id^="exampleoverviewcorp"] .overview-formdata-col .mb-1 { margin-bottom: 0.75rem !important; }
    body.app-theme-dark .modal[id^="exampleoverviewcorp"] .overview-formdata-col .form-label { color: #cbd5e1 !important; }
    .modal[id^="exampleoverviewcorp"] .overview-health-col .overview-meta-item { margin-bottom: 0.25rem !important; line-height: 1.35; }
    .modal[id^="exampleoverviewcorp"] .overview-health-stack { margin-top: 0.45rem; }
    body.app-theme-dark .modal[id^="exampleoverviewcorp"] [id^="data-info-"] { background: #0f172a; border-color: #334155; color: #e2e8f0 !important; }
    [id^="data-status2-"], [id^="data-status-"], [id^="data-paket-aktif-"], [id^="data-paket-aktif-modal-"], [id^="data-realtime-"], [id^="data-info-"] { color: #1e293b !important; font-weight: 700; font-size: 11px !important; line-height: 1.6; }
    body.app-theme-dark [id^="data-status2-"], body.app-theme-dark [id^="data-status-"], body.app-theme-dark [id^="data-paket-aktif-"], body.app-theme-dark [id^="data-paket-aktif-modal-"], body.app-theme-dark [id^="data-realtime-"], body.app-theme-dark [id^="data-info-"] { color: #e2e8f0 !important; }
    [id^="data-status2-"] .badge, [id^="data-status-"] .badge, [id^="data-paket-aktif-"] .badge, [id^="data-paket-aktif-modal-"] .badge, [id^="data-realtime-"] .badge, [id^="data-info-"] .badge { color: #ffffff !important; text-shadow: none !important; border: 1px solid rgba(15, 23, 42, 0.22); opacity: 1 !important; font-size: 10px !important; }
    .status-top-badges { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 4px; margin-bottom: 4px; }
    .status-top-badges > * { margin: 0 !important; display: inline-flex !important; }
    [id^="data-realtime-"], [id^="data-info-"] { display: grid !important; grid-template-columns: 1fr 1fr; column-gap: 10px; row-gap: 0; }
    .status-detail-row { display: flex; justify-content: space-between; align-items: center; gap: 6px; font-size: 10.5px !important; font-weight: 700; line-height: 1.5; text-align: left; }
    .status-detail-label { color: #8898aa !important; font-weight: 500; white-space: nowrap; }
    .status-detail-value { text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100px; }
    body.app-theme-dark .status-detail-label { color: #94a3b8 !important; }
    [id^="data-status2-"] .badge.bg-secondary, [id^="data-status-"] .badge.bg-secondary, [id^="data-paket-aktif-"] .badge.bg-secondary, [id^="data-paket-aktif-modal-"] .badge.bg-secondary, [id^="data-realtime-"] .badge.bg-secondary, [id^="data-info-"] .badge.bg-secondary { background-color: #334155 !important; border-color: #1e293b !important; color: #f8fafc !important; }
    body.corp-fast-status-mode-active #corpLayananTable thead th.corp-status-col, body.corp-fast-status-mode-active #corpLayananTable tbody td.corp-status-col { display: none; }

    .acs-device-card { background: #fff; color: #27272a; border-color: #d4d4d8; }
    .acs-device-card, .acs-device-card * { color: #27272a; }
    .acs-device-info-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 6px; }
    .acs-device-info-item { background: #f4f4f5; border: 1px solid #d4d4d8; border-radius: 8px; padding: 8px 10px; min-width: 0; }
    .acs-device-info-label { display: block; font-size: 11px; font-weight: 700; color: #f68013; margin-bottom: 3px; }
    .acs-device-info-value { display: block; font-size: 12px; font-weight: 600; color: #27272a; word-break: break-word; }
    .acs-ssid-section { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #d4d4d8; }
    .acs-ssid-title { font-weight: 700; margin-bottom: 8px; color: #27272a; }
    .acs-ssid-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .acs-ssid-row { background: #f4f4f5; border: 1px solid #d4d4d8; border-radius: 8px; padding: 8px 10px; min-width: 0; }
    .acs-ssid-card-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 6px; }
    .acs-ssid-name { font-size: 12px; font-weight: 700; color: #f68013; }
    .acs-ssid-value { display: block; font-size: 12px; font-weight: 600; color: #27272a; word-break: break-word; }
    .acs-param-key { color: #f68013 !important; font-weight: 700; }
    .acs-param-value { color: #27272a !important; font-weight: 600; }
    .acs-empty-text { color: #71717a !important; }
    .acs-wan-section { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #d4d4d8; }
    .acs-wan-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
    .acs-wan-title { font-weight: 700; color: #27272a; }
    .acs-wan-grid { display: grid; grid-template-columns: 1fr; gap: 8px; }
    .acs-wan-card { background: #f4f4f5; border: 1px solid #d4d4d8; border-radius: 8px; padding: 10px 12px; }
    .acs-wan-card-header { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
    .acs-wan-card-title { font-weight: 700; color: #27272a; }
    .acs-wan-param-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 12px; }
    .acs-wan-summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 12px; margin-bottom: 8px; }
    .acs-wan-param-item { min-width: 0; }
    .acs-wan-param-key { display: block; font-size: 11px; font-weight: 700; color: #f68013; margin-bottom: 2px; word-break: break-word; }
    .acs-wan-param-value { font-size: 12px; font-weight: 600; color: #27272a; word-break: break-word; }
    .acs-wan-empty { color: #71717a; font-size: 12px; }
    .acs-local-hosts-wrap { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #d4d4d8; }
    .acs-local-hosts-title { font-weight: 700; margin-bottom: 8px; color: #27272a; }
    .acs-local-hosts-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
    .acs-local-host-card { background: #f4f4f5; border: 1px solid #d4d4d8; border-radius: 8px; padding: 8px 10px; line-height: 1.45; font-size: 12px; }
    .acs-local-host-name { font-weight: 700; margin-bottom: 4px; color: #f68013; }
    .acs-local-host-item { margin: 2px 0; color: #27272a; word-break: break-word; }
    .acs-raw-toggle-wrap { margin-top: 12px; padding-top: 12px; border-top: 1px dashed #d4d4d8; }
    .acs-raw-params-list { max-height: 260px; overflow-y: auto; background: #f4f4f5; border: 1px solid #d4d4d8; border-radius: 8px; padding: 8px; margin-top: 8px; font-size: 11px; }
    .acs-raw-param-row { display: flex; gap: 8px; padding: 3px 0; border-bottom: 1px dashed #e4e4e7; word-break: break-all; }
    .acs-raw-param-row .acs-param-key { flex: 0 0 55%; }
    body.app-theme-dark .acs-local-host-card { background: #1f2937 !important; border-color: rgba(255, 255, 255, 0.12) !important; }
    body.app-theme-dark .acs-local-host-name { color: #f68013 !important; }
    body.app-theme-dark .acs-local-host-item { color: #f3f4f6 !important; }
    body.app-theme-dark .acs-local-hosts-title { color: #f8fafc !important; }
    body.app-theme-dark .acs-local-hosts-wrap, body.app-theme-dark .acs-raw-toggle-wrap { border-top-color: rgba(255, 255, 255, 0.12) !important; }
    body.app-theme-dark .acs-raw-params-list { background: #1f2937 !important; border-color: rgba(255, 255, 255, 0.12) !important; }
    body.app-theme-dark .acs-raw-param-row { border-bottom-color: rgba(255, 255, 255, 0.08) !important; }
    .acs-section-title { color: #27272a; }
    body.app-theme-dark .acs-device-card { background: #111827 !important; color: #e5e7eb !important; border-color: rgba(255, 255, 255, 0.1) !important; }
    body.app-theme-dark .acs-device-card, body.app-theme-dark .acs-device-card * { color: #e5e7eb !important; }
    body.app-theme-dark .acs-device-info-item, body.app-theme-dark .acs-ssid-row, body.app-theme-dark .acs-wan-card { background: #1f2937 !important; border-color: rgba(255, 255, 255, 0.12) !important; }
    body.app-theme-dark .acs-device-info-label, body.app-theme-dark .acs-ssid-name, body.app-theme-dark .acs-wan-param-key, body.app-theme-dark .acs-param-key { color: #f68013 !important; }
    body.app-theme-dark .acs-device-info-value, body.app-theme-dark .acs-ssid-value, body.app-theme-dark .acs-wan-param-value, body.app-theme-dark .acs-param-value { color: #f3f4f6 !important; }
    body.app-theme-dark .acs-ssid-title, body.app-theme-dark .acs-wan-title, body.app-theme-dark .acs-wan-card-title { color: #f8fafc !important; }
    body.app-theme-dark .acs-ssid-section, body.app-theme-dark .acs-wan-section { border-top-color: rgba(255, 255, 255, 0.12) !important; }
    body.app-theme-dark .acs-wan-empty, body.app-theme-dark .acs-empty-text { color: #cbd5e1 !important; }
    body.app-theme-dark .acs-section-title { color: #f8fafc !important; }
    [id^="acs-sync-info-"] { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; border: 1px solid transparent; line-height: 1.2; }
    .acs-sync-badge-fresh { background: #dcfce7 !important; color: #166534 !important; border-color: #86efac !important; }
    .acs-sync-badge-stale { background: #fef9c3 !important; color: #854d0e !important; border-color: #fde047 !important; }
    .acs-sync-badge-expired { background: #fee2e2 !important; color: #991b1b !important; border-color: #fecaca !important; }
    body.app-theme-dark .acs-sync-badge-fresh { background: #14532d !important; color: #dcfce7 !important; border-color: #22c55e !important; }
    body.app-theme-dark .acs-sync-badge-stale { background: #713f12 !important; color: #fef9c3 !important; border-color: #eab308 !important; }
    body.app-theme-dark .acs-sync-badge-expired { background: #7f1d1d !important; color: #fee2e2 !important; border-color: #f87171 !important; }
</style>

<script>
/* ==== Mesin status-fetch + panel ACS -- disalin VERBATIM (tanpa ubah logic)
   dari tables.php/tablesstaticip.php. Ditaruh SEBELUM tabel krn tiap baris
   layanan (provisioning_aktif=1) punya tag "script" (startFetching) inline
   yang harus jalan SETELAH fungsi ini terdefinisi. ==== */
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
                } catch(e) { console.error(`Error reading ${fileName}:`, e); }
            }
        }
    } catch(e) { console.error(e); }
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
    if (!trafficHistory[idPel]) trafficHistory[idPel] = { labels: [], download: [], upload: [] };
    const history = trafficHistory[idPel];
    if (history.labels.length >= 10) { history.labels.shift(); history.download.shift(); history.upload.shift(); }
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
            if (existingChart) { try { existingChart.destroy(); } catch (destroyErr) {} }
            if (typeof Chart === 'undefined') return;
            window[`trafficChartInstance${idPel}`] = new Chart(ctx, { type: 'line', data: { labels: history.labels, datasets: [ { label: 'Download (Mbps)', data: history.download, borderColor: 'blue', backgroundColor: 'rgba(0,0,255,0.2)', fill: true }, { label: 'Upload (Mbps)', data: history.upload, borderColor: 'green', backgroundColor: 'rgba(0,128,0,0.2)', fill: true } ] }, options: { responsive: true, scales: { y: { beginAtZero: true } } } });
        }
    } catch (chartErr) { console.error(`Chart update error for ${idPel}:`, chartErr); }
}

const liveTrafficState = {};

async function refreshLiveTraffic(idPel, ipServer, userServer, passwordServer) {
    if (liveTrafficState[idPel]) return;
    liveTrafficState[idPel] = true;
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 8000);
        const response = await fetch('getdata/get_live_traffic.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ ip: ipServer, idpel: idPel, us: userServer || '', ps: passwordServer || '' }), signal: controller.signal });
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
    } catch (e) { console.warn(`Live traffic error for ${idPel}:`, e); } finally { liveTrafficState[idPel] = false; }
}

function fetchData(idPel, ipServer, userServer, passwordServer, customerMode) {
    try {
        if (!idPel || !ipServer) { console.error('Missing required parameters'); return Promise.resolve(); }
        const fetchController = new AbortController();
        const fetchTimeoutId = setTimeout(() => fetchController.abort(), 15000);
        return fetch('getdata/get_cached_pppoe_status.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ ip: ipServer, idpel: idPel, us: userServer || '', ps: passwordServer || '' }), signal: fetchController.signal })
        .then(response => { clearTimeout(fetchTimeoutId); if (!response) throw new Error('No response'); return response.json().catch(() => null); })
        .then(async data => {
            try {
                if (!data) throw new Error('No data received');
                let macToCheck = data.status === "Online" ? (data.active_caller_id || 'N/A') : (data.last_caller_secret || 'N/A');
                let serverListStr = <?php echo json_encode(isset($server_list) ? (string)$server_list : ''); ?>;
                let rxTxDbm = null;
                try { rxTxDbm = await getDbmFromOnulist(macToCheck, serverListStr, idPel); } catch (e) { console.warn(`DBM error for ${idPel}:`, e); rxTxDbm = { rxDbm: 0, txDbm: 0, file: null }; }
                if (!rxTxDbm) rxTxDbm = { rxDbm: 0, txDbm: 0, file: null };
                let rxBadge = 'bg-secondary';
                let rxDisplay = 'Null';
                try { if (rxTxDbm && rxTxDbm.rxDbm && rxTxDbm.rxDbm !== 0) { rxBadge = rxTxDbm.rxDbm < -27 ? 'badge-sm bg-gradient-danger' : 'badge-sm bg-gradient-success'; rxDisplay = rxTxDbm.rxDbm; } } catch (e) { console.warn('RX display error:', e); rxDisplay = 'Null'; }
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
                    if (rxRedaman !== 'Null') { const rxRedamanVal = parseFloat(rxRedaman); if (!isNaN(rxRedamanVal)) { rxRedamanBadge = rxRedamanVal < -27 ? 'badge-sm bg-gradient-danger' : 'badge-sm bg-gradient-success'; } }
                } catch (e) { console.error('ACS error:', e); rxRedaman = 'Null'; }
                let paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                const loginViaForProfile = (data.login_via || '').toLowerCase();
                const isRadiusConfigured = (customerMode || '').toUpperCase().indexOf('RADIUS') !== -1;
                const localProfileEmpty = ['', 'N/A', 'NULL'].includes(paketAktifRaw.toUpperCase());
                if (loginViaForProfile === 'local' && !isRadiusConfigured && !localProfileEmpty) {
                    paketAktifRaw = (data.cekexpired || "Null").toString().trim();
                } else {
                    try {
                        let radiusResponse = await fetch('getdata/getpackagefromradius.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ idpel: idPel }) });
                        if (radiusResponse && radiusResponse.ok) {
                            let radiusData = await radiusResponse.json().catch(() => null);
                            if (radiusData && radiusData.package && radiusData.package !== 'Null') { paketAktifRaw = radiusData.package; } else { paketAktifRaw = (data.cekexpired || "Null").toString().trim(); }
                        }
                    } catch (e) { console.warn(`Could not fetch package from RADIUS for ${idPel}:`, e); paketAktifRaw = (data.cekexpired || "Null").toString().trim(); }
                }
                const isPaketExpired = String(paketAktifRaw).trim().toUpperCase() === "EXPIRED";
                const paketAktifBadgeClass = isPaketExpired ? 'badge badge-sm bg-gradient-danger' : 'badge badge-sm bg-gradient-success';
                const paketAktifHtml = `<span class="${paketAktifBadgeClass}">${paketAktifRaw}</span>`;
                try {
                    const paketAktifEl = document.getElementById(`data-paket-aktif-${idPel}`);
                    if (paketAktifEl) paketAktifEl.innerHTML = paketAktifHtml;
                    const paketAktifModalEl = document.getElementById(`data-paket-aktif-modal-${idPel}`);
                    if (paketAktifModalEl) paketAktifModalEl.innerHTML = paketAktifHtml;
                } catch (e) { console.warn(`Paket aktif display error for ${idPel}:`, e); }
                let statusElement2 = null;
                let realtimeElement = null;
                try { statusElement2 = document.getElementById(`data-status2-${idPel}`) || null; realtimeElement = document.getElementById(`data-realtime-${idPel}`) || null; } catch (e) { console.warn(`Element access error for ${idPel}:`, e); }
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
                        if (statusElement2) statusElement2.innerHTML = `<span class="badge badge-sm bg-gradient-success">Online (${loginVia})</span>`;
                        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
                        if (modalStatusEl) modalStatusEl.innerHTML = `<span class="badge badge-sm bg-gradient-success">Online (${loginVia})</span>`;
                        if (realtimeElement) realtimeElement.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Down/Up</span><span class="status-detail-value" id="data-downup-${idPel}">${download} / ${upload} Mbps</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">IP</span><span class="status-detail-value" id="data-ip-${idPel}">${remoteIp}</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">MAC</span><span class="status-detail-value">${mac}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, false)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
                        if (modalInfoEl) modalInfoEl.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Down/Up</span><span class="status-detail-value" id="data-downup-modal-${idPel}">${download} / ${upload} Mbps</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">IP</span><span class="status-detail-value" id="data-ip-modal-${idPel}">${remoteIp}</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">MAC</span><span class="status-detail-value">${mac}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian, idPel, true)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        refreshLiveTraffic(idPel, ipServer, userServer, passwordServer);
                    } catch (onlineErr) { console.error(`Online status error: ${onlineErr}`); }
                } else {
                    try {
                        const lastDisconnect = data.ceklastdisconnect || 'Null';
                        const kuota = data.kuota || 'N/A';
                        const uptime = data.uptime || 'N/A';
                        const linkUp = data.last_link_up || 'N/A';
                        const linkDown = data.last_link_down || 'N/A';
                        const pemakaian = data.pemakaian || 'N/A';
                        if (statusElement2) statusElement2.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (LOS)</span>';
                        const modalStatusEl = document.getElementById(`data-status-${idPel}`);
                        if (modalStatusEl) modalStatusEl.innerHTML = '<span class="badge badge-sm bg-gradient-danger">Offline (LOS)</span>';
                        if (realtimeElement) realtimeElement.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Status</span><span class="status-detail-value">Offline</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">Last Disconnect</span><span class="status-detail-value">${lastDisconnect}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                        const modalInfoEl = document.getElementById(`data-info-${idPel}`);
                        if (modalInfoEl) modalInfoEl.innerHTML = `
                                <div class="status-detail-row"><span class="status-detail-label">Status</span><span class="status-detail-value">Offline</span></div>
                                <div class="status-detail-row"><span class="status-detail-label">Last Disconnect</span><span class="status-detail-value">${lastDisconnect}</span></div>
                                ${buildStatusDetailRows(kuota, uptime, linkUp, linkDown, pemakaian)}
                                <div class="status-detail-row"><span class="status-detail-label">RX OLT</span><span class="status-detail-value"><span class="badge badge-sm ${rxBadge}">${rxDisplay}</span></span></div>
                                <div class="status-detail-row"><span class="status-detail-label">RX ACS</span><span class="status-detail-value"><span class="badge badge-sm ${rxRedamanBadge}">${rxRedaman}</span></span></div>`;
                    } catch (offlineErr) { console.error(`Offline status error: ${offlineErr}`); }
                }
            } catch (processErr) { console.error(`Data processing error for ${idPel}:`, processErr); renderFetchErrorUI(idPel, 'Data processing error'); }
        })
        .catch(error => {
            clearTimeout(fetchTimeoutId);
            console.error('Fetch error:', error);
            const isRadiusConfiguredMode = (customerMode || '').toUpperCase().indexOf('RADIUS') !== -1;
            if (isRadiusConfiguredMode) { renderOfflineRadiusUI(idPel); } else { renderFetchErrorUI(idPel, error && error.name === 'AbortError' ? 'Timeout - server tidak merespon' : 'Fetch Error'); }
        });
    } catch (outerErr) { console.error('FetchData outer error:', outerErr); return Promise.resolve(); }
}

window.__customerFetchOrder = window.__customerFetchOrder || [];
window.__customerFetchQueueStarted = window.__customerFetchQueueStarted || false;
window.__customerVisibleRows = window.__customerVisibleRows || new Set();
window.__customerRowObserver = window.__customerRowObserver || null;
const CUSTOMER_FETCH_GAP_MS = 350;
const CUSTOMER_FETCH_CYCLE_DELAY_MS = 8000;
const CUSTOMER_FETCH_IDLE_RETRY_MS = 500;

function customerFetchDelay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }
function isSecondaryQtsModalOpen() { return Array.prototype.some.call(document.querySelectorAll('.qts-modal-overlay'), function(el) { return el.style.display === 'flex'; }); }

function getCustomerRowObserver() {
    if (!window.__customerRowObserver) {
        window.__customerRowObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                const idPel = entry.target.getAttribute('data-fetch-idpel');
                if (!idPel) return;
                if (entry.isIntersecting) { window.__customerVisibleRows.add(idPel); } else { window.__customerVisibleRows.delete(idPel); }
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
            if (!window.__customerVisibleRows.has(idPel)) continue;
            while (isSecondaryQtsModalOpen()) { await customerFetchDelay(300); }
            didFetch = true;
            try { await fetchData(idPel, p.ip, p.us, p.ps, p.mode); } catch (e) { console.warn(`Queue fetch error for ${idPel}:`, e); }
            await customerFetchDelay(CUSTOMER_FETCH_GAP_MS);
        }
        await customerFetchDelay(didFetch ? CUSTOMER_FETCH_CYCLE_DELAY_MS : CUSTOMER_FETCH_IDLE_RETRY_MS);
    }
}

function startFetching(idPel, ipServer, userServer, passwordServer, customerMode) {
    window.__customerFetchParams[idPel] = { ip: ipServer, us: userServer, ps: passwordServer, mode: customerMode || '' };
    if (window.__corpFastStatusMode) {
        const statusEl = document.getElementById(`data-status2-${idPel}`);
        if (statusEl) statusEl.innerHTML = '<span class="badge badge-sm bg-gradient-secondary">Buka Overview utk cek status</span>';
        return;
    }
    if (!window.__customerFetchOrder.includes(idPel)) window.__customerFetchOrder.push(idPel);
    const rowEl = document.getElementById(`customerRow-${idPel}`);
    if (rowEl) { rowEl.setAttribute('data-fetch-idpel', idPel); getCustomerRowObserver().observe(rowEl); } else { window.__customerVisibleRows.add(idPel); }
    if (!window.__customerFetchQueueStarted) { window.__customerFetchQueueStarted = true; runCustomerFetchQueue(); }
}

function fetchStatusOnDemandIfFastMode(idPel) {
    if (!window.__corpFastStatusMode) return;
    const p = window.__customerFetchParams[idPel];
    if (!p) return;
    fetchData(idPel, p.ip, p.us, p.ps, p.mode).catch(function(e) { console.warn(`On-demand fetch error for ${idPel}:`, e); });
}

function openMonitorModal(element, idPel, ip, user, password) {
    try { return true; } catch(error) { console.error(`Error in openMonitorModal:`, error); return false; }
}

function acsHtmlEscape(str) {
    return String(str === null || str === undefined ? '' : str).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
}

function acsCleanParams(rawParams) {
    const cleaned = {};
    Object.keys(rawParams || {}).forEach(function(key) {
        if (/\.(_object|_writable|_timestamp|_type|_attributes)$/.test(key)) return;
        const cleanKey = key.replace(/\._value$/, '');
        cleaned[cleanKey] = rawParams[key];
    });
    return cleaned;
}

function formatAcsSyncBadge(cacheAge, cacheExpired) {
    if (cacheAge === null || cacheAge === undefined) return { label: 'SYNC: -', cls: 'acs-sync-badge-stale' };
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
        .catch(function(err) { console.error('ACS panel load error:', err); body.innerHTML = '<span class="acs-empty-text">Gagal memuat data ACS.</span>'; });
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
    if (!data || data.error) { body.innerHTML = '<span class="acs-empty-text">' + acsHtmlEscape((data && data.error) || 'Data ACS tidak tersedia.') + '</span>'; return; }
    const devices = Array.isArray(data.devices) ? data.devices : [];
    if (devices.length === 0) { body.innerHTML = '<span class="acs-empty-text">Perangkat ACS untuk pelanggan ini belum ditemukan di cache.</span>'; return; }
    const device = devices[0];
    if (panelEl) { panelEl.dataset.serverId = device.server_id || ''; panelEl.dataset.serial = device.serial_raw || device.serial || ''; }
    let html = '<div class="acs-device-info-grid">';
    const isOnline = String(device.status || '').toUpperCase() === 'ONLINE';
    const infoItems = [
        ['Serial', acsHtmlEscape(device.serial || '-')], ['Brand', acsHtmlEscape(device.manufacturer || '-')],
        ['PPPoE Username', acsHtmlEscape(device.pppoe_username || '-')], ['PPPoE Username 2', acsHtmlEscape(device.pppoe_username2 || '-')],
        ['PPPoE IP', acsHtmlEscape(device.pppoe_ip || '-')], ['RX redaman', acsHtmlEscape(device.rx_power || '-')],
        ['TX', acsHtmlEscape(device.tx_power || '-')], ['Status', '<span class="badge ' + (isOnline ? 'bg-success' : 'bg-secondary') + '">' + acsHtmlEscape(device.status || 'UNKNOWN') + '</span>'],
        ['Server', acsHtmlEscape(device.server_name || '-')], ['Last Inform', acsHtmlEscape(device.last_inform || '-')]
    ];
    infoItems.forEach(function(item) { html += '<div class="acs-device-info-item"><span class="acs-device-info-label">' + item[0] + '</span><span class="acs-device-info-value">' + item[1] + '</span></div>'; });
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
        if (match) { const wanId = 'WANConnectionDevice.' + match[1]; if (!wanKeys[wanId]) wanKeys[wanId] = {}; wanKeys[wanId][key] = params[key]; }
    });
    function acsFindParam(paramSet, suffixes) {
        const keys = Object.keys(paramSet);
        for (let s = 0; s < suffixes.length; s++) { for (let k = 0; k < keys.length; k++) { if (keys[k].indexOf(suffixes[s]) !== -1) return { key: keys[k], value: paramSet[keys[k]] }; } }
        return null;
    }
    function acsDetectWanSummary(wanParams) {
        const hasPpp = Object.keys(wanParams).some(function(k) { return k.indexOf('WANPPPConnection') !== -1; });
        const hasIp = Object.keys(wanParams).some(function(k) { return k.indexOf('WANIPConnection') !== -1; });
        let serviceType = 'Tidak diketahui';
        if (hasPpp) { serviceType = 'PPPoE'; } else if (hasIp) { const addressing = acsFindParam(wanParams, ['.AddressingType']); const addressingVal = addressing ? String(addressing.value).toLowerCase() : ''; serviceType = addressingVal.indexOf('static') !== -1 ? 'IP Static' : 'DHCP'; }
        const ipParam = acsFindParam(wanParams, ['.ExternalIPAddress']);
        const statusParam = acsFindParam(wanParams, ['.ConnectionStatus']);
        const usernameParam = hasPpp ? acsFindParam(wanParams, ['.Username']) : null;
        return { serviceType: serviceType, wanIp: ipParam ? ipParam.value : '-', status: statusParam ? statusParam.value : '-', username: usernameParam ? usernameParam.value : '' };
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
            if (summary.username) html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">Username</span><span class="acs-wan-param-value">' + acsHtmlEscape(summary.username) + '</span></div>';
            html += '</div>';
            const paramEntries = Object.keys(wanParams).slice(0, 6);
            if (paramEntries.length > 0) {
                html += '<div class="acs-wan-param-list mt-2">';
                paramEntries.forEach(function(pKey) { const shortKey = pKey.split('.').slice(-1)[0]; html += '<div class="acs-wan-param-item"><span class="acs-wan-param-key">' + acsHtmlEscape(shortKey) + '</span><span class="acs-wan-param-value">' + acsHtmlEscape(wanParams[pKey]) + '</span></div>'; });
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
        const hostNum = match[1]; const attr = match[2];
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
    rawParamKeys.sort().forEach(function(pKey) { html += '<div class="acs-raw-param-row"><span class="acs-param-key">' + acsHtmlEscape(pKey) + '</span><span class="acs-param-value">' + acsHtmlEscape(params[pKey]) + '</span></div>'; });
    html += '</div>';
    html += '</div>';
    body.innerHTML = html;
}

function toggleAcsRawData(idpel) {
    const list = document.getElementById('acsRawParamsList-' + idpel);
    const btn = document.getElementById('acsRawToggleBtn-' + idpel);
    if (!list) return;
    const isHidden = list.classList.contains('d-none');
    if (isHidden) { list.classList.remove('d-none'); if (btn) btn.textContent = btn.textContent.replace('Tampilkan', 'Sembunyikan'); }
    else { list.classList.add('d-none'); if (btn) btn.textContent = btn.textContent.replace('Sembunyikan', 'Tampilkan'); }
}
</script>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <h6>Layanan -- <?php echo htmlspecialchars($corp['NAMA_PERUSAHAAN']); ?></h6>
          <p class="text-muted small mt-2">
            Satu perusahaan bisa punya banyak layanan (Internet Dedicated, MPLS, VoIP, Data
            Center, dst). "Provisioning Otomatis" cuma relevan utk layanan berbasis PPPoE
            (koneksi internet) -- layanan lain (CCTV, Colocation, dst) cukup dicatat sbg
            inventori tanpa menyentuh Mikrotik/RADIUS.
          </p>
          <a href="corporate.php" class="btn btn-sm btn-secondary mb-3"><i class="fas fa-arrow-left me-1"></i> Kembali ke Customer Corporate</a>
          <div class="btn-group-custom mb-3">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addLayananModal">
              <i class="fas fa-plus me-1"></i> Tambah Layanan
            </button>
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="toggleCorpFastStatusMode" style="width:3em;height:1.5em;cursor:pointer;">
            <label class="form-check-label" for="toggleCorpFastStatusMode">
              <span id="corpFastStatusModeLabel">Mode Cepat: OFF</span>
              <i class="fas fa-info-circle text-muted ms-1"
                 title="Kalau ON: kolom Live disembunyikan total dari tabel (loading halaman lebih cepat kalau layanan banyak). Status detail tetap bisa diakses lewat modal Overview (klik Nama Layanan). Perubahan berlaku setelah halaman di-refresh."></i>
            </label>
          </div>
          <script>
          (function() {
              var FAST_MODE_KEY = 'corp_fast_status_mode';
              var toggle = document.getElementById('toggleCorpFastStatusMode');
              var label = document.getElementById('corpFastStatusModeLabel');
              if (!toggle || !label) return;
              window.__corpFastStatusMode = localStorage.getItem(FAST_MODE_KEY) === '1';
              toggle.checked = window.__corpFastStatusMode;
              label.textContent = 'Mode Cepat: ' + (window.__corpFastStatusMode ? 'ON' : 'OFF');
              document.body.classList.toggle('corp-fast-status-mode-active', window.__corpFastStatusMode);
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
            <table id="corpLayananTable" class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>Nama Layanan</th>
                  <th>Jenis</th>
                  <th>Server</th>
                  <th>Paket</th>
                  <th>IP</th>
                  <th>VLAN</th>
                  <th>OLT</th>
                  <th>Provisioning</th>
                  <th class="corp-status-col">Live</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $qL = mysqli_query($conn, "SELECT cl.*, p.PAKET, p.KECEPATAN, s.PEMILIK AS server_pemilik, s.BRAND AS server_brand, s.AREA AS server_area, s.IP AS server_ip, s.PASSWORD AS server_password, v.vlan_id AS vlan_number, o.oltname
                    FROM corporate_layanan cl
                    LEFT JOIN paket_corporate p ON p.id = cl.paket_id
                    LEFT JOIN server s ON s.id = cl.server_id
                    LEFT JOIN vlan v ON v.id = cl.vlan_id
                    LEFT JOIN olt o ON o.id = cl.olt_id
                    WHERE cl.corporate_id = $corporateId ORDER BY cl.id DESC");
                $corpModalsBuffer = '';
                if ($qL && mysqli_num_rows($qL) > 0) {
                    $no = 1;
                    while ($l = mysqli_fetch_assoc($qL)) {
                        $layananIdAttr = 'CORPLAYANAN' . (int) $l['id'];
                        $isProvisioned = ((int) $l['provisioning_aktif'] === 1) && trim((string) ($l['pppoe_username'] ?? '')) !== '';
                        $namaLayananAttr = htmlspecialchars($l['nama_layanan'] ?: $l['jenis_layanan'], ENT_QUOTES);

                        echo "<tr>";
                        echo "<td>" . $no++ . "</td>";
                        if ($isProvisioned) {
                            echo '<td><div style="cursor:pointer;color:#0d6efd;text-decoration:underline;" data-bs-toggle="modal" data-bs-target="#exampleoverviewcorp' . $layananIdAttr . '" onclick="openMonitorModal(this, \'' . $layananIdAttr . '\', \'' . htmlspecialchars((string) ($l['server_ip'] ?? ''), ENT_QUOTES) . '\', \'' . htmlspecialchars((string) ($l['server_pemilik'] ?? ''), ENT_QUOTES) . '\', \'' . htmlspecialchars((string) ($l['server_password'] ?? ''), ENT_QUOTES) . '\')">' . htmlspecialchars($l['nama_layanan'] ?: '-') . '</div></td>';
                        } else {
                            echo "<td>" . htmlspecialchars($l['nama_layanan'] ?: '-') . "</td>";
                        }
                        echo "<td>" . htmlspecialchars($l['jenis_layanan']) . "</td>";
                        echo "<td>" . htmlspecialchars($l['server_brand'] ? $l['server_brand'] . '-' . $l['server_area'] : '-') . "</td>";
                        echo "<td>" . htmlspecialchars($l['PAKET'] ? $l['PAKET'] . ' (' . $l['KECEPATAN'] . ')' : '-') . "</td>";
                        echo "<td>" . htmlspecialchars($l['ip_address'] ?: '-') . "</td>";
                        echo "<td>" . htmlspecialchars($l['vlan_number'] ?: '-') . "</td>";
                        echo "<td>" . htmlspecialchars($l['oltname'] ?: '-') . "</td>";
                        if ((int) $l['provisioning_aktif'] === 1) {
                            $konBadge = ($l['status_koneksi'] === 'AKTIF') ? "<span class='badge bg-success'>AKTIF</span>" : "<span class='badge bg-danger'>ISOLIR</span>";
                            echo "<td>Ya ({$l['auth_mode']})<br>$konBadge</td>";
                        } else {
                            echo "<td><span class='text-muted'>Tidak</span></td>";
                        }

                        echo '<td class="align-middle text-center text-sm corp-status-col">';
                        if ($isProvisioned) {
                            echo '<div class="status-top-badges">';
                            echo '<span id="data-status2-' . $layananIdAttr . '"><span class="badge badge-sm bg-gradient-warning">Loading...</span></span>';
                            echo '<div id="data-paket-aktif-' . $layananIdAttr . '"></div>';
                            echo '</div>';
                            echo '<span id="data-realtime-' . $layananIdAttr . '" class="text-secondary text-xs font-weight-bold">Memuat...</span>';
                            echo '<script>startFetching("' . $layananIdAttr . '", "' . htmlspecialchars((string) ($l['server_ip'] ?? ''), ENT_QUOTES) . '", "' . htmlspecialchars((string) ($l['server_pemilik'] ?? ''), ENT_QUOTES) . '", "' . htmlspecialchars((string) ($l['server_password'] ?? ''), ENT_QUOTES) . '", "' . htmlspecialchars((string) ($l['auth_mode'] ?? ''), ENT_QUOTES) . '");</script>';
                        } else {
                            echo '<span class="text-muted small">-</span>';
                        }
                        echo '</td>';

                        $statusBadge = ($l['status'] === 'AKTIF') ? "<span class='badge bg-success'>AKTIF</span>" : "<span class='badge bg-secondary'>NONAKTIF</span>";
                        echo "<td>" . $statusBadge . "</td>";
                        echo "<td class='text-nowrap'>";
                        echo "<button type='button' class='btn btn-warning btn-sm mb-1' data-bs-toggle='modal' data-bs-target='#editLayananModal' data-perm='btn_corplayanan_edit'"
                            . " data-id='" . (int) $l['id'] . "'"
                            . " data-jenis='" . htmlspecialchars($l['jenis_layanan'], ENT_QUOTES) . "'"
                            . " data-nama='" . htmlspecialchars($l['nama_layanan'], ENT_QUOTES) . "'"
                            . " data-serverpemilik='" . htmlspecialchars($l['server_pemilik'] ?? '', ENT_QUOTES) . "'"
                            . " data-paketid='" . (int) $l['paket_id'] . "'"
                            . " data-paketnama='" . htmlspecialchars($l['PAKET'] ?? '', ENT_QUOTES) . "'"
                            . " data-ip='" . htmlspecialchars($l['ip_address'], ENT_QUOTES) . "'"
                            . " data-vlanid='" . (int) $l['vlan_id'] . "'"
                            . " data-oltid='" . (int) $l['olt_id'] . "'"
                            . " data-provisioning='" . (int) $l['provisioning_aktif'] . "'"
                            . " data-authmode='" . htmlspecialchars($l['auth_mode'], ENT_QUOTES) . "'"
                            . " data-username='" . htmlspecialchars($l['pppoe_username'], ENT_QUOTES) . "'"
                            . " data-tanggalaktif='" . htmlspecialchars((string) $l['tanggal_aktif'], ENT_QUOTES) . "'"
                            . " data-catatan='" . htmlspecialchars($l['catatan'], ENT_QUOTES) . "'"
                            . " data-status='" . htmlspecialchars($l['status'], ENT_QUOTES) . "'"
                            . ">Edit</button><br>";
                        if ((int) $l['provisioning_aktif'] === 1) {
                            $nextIsolir = ($l['status_koneksi'] === 'AKTIF') ? '1' : '0';
                            $labelBtn = ($l['status_koneksi'] === 'AKTIF') ? 'Isolir' : 'Aktifkan';
                            $btnClass = ($l['status_koneksi'] === 'AKTIF') ? 'btn-dark' : 'btn-success';
                            echo "<form method='post' action='proses/toggle_isolir_layanan.php' data-perm='btn_corplayanan_isolir' style='display:inline' onsubmit='return confirm(\"Yakin ingin $labelBtn layanan ini?\")'>"
                                . "<input type='hidden' name='id' value='" . (int) $l['id'] . "'>"
                                . "<input type='hidden' name='isolir' value='$nextIsolir'>"
                                . "<button type='submit' class='btn $btnClass btn-sm mb-1'>$labelBtn</button></form><br>";
                        }
                        echo "<form method='post' action='proses/deletecorporatelayanan.php' data-perm='btn_corplayanan_hapus' style='display:inline' onsubmit='return confirm(\"Yakin ingin menghapus layanan ini? Koneksi PPPoE-nya (jika ada) ikut dihapus dari router/RADIUS.\")'>"
                            . "<input type='hidden' name='id' value='" . (int) $l['id'] . "'>"
                            . "<button type='submit' class='btn btn-danger btn-sm'>Hapus</button></form>";
                        echo "</td>";
                        echo "</tr>";

                        // ==== Modal Overview (versi ringkas -- fokus monitoring, sama pola
                        // tablesstaticip.php) -- HANYA utk layanan yg beneran punya PPPoE
                        // secret (provisioning_aktif=1 + pppoe_username terisi). ====
                        if ($isProvisioned) {
                            $hpCorp = '';
                            $nohpCorpRaw = trim((string) ($corp['WHATSAPP'] ?? ''));
                            if (!preg_match('/[^+0-9]/', $nohpCorpRaw)) {
                                if (substr($nohpCorpRaw, 0, 2) === '62') { $hpCorp = $nohpCorpRaw; }
                                elseif (substr($nohpCorpRaw, 0, 3) === '+62') { $hpCorp = '62' . substr($nohpCorpRaw, 1); }
                                elseif (substr($nohpCorpRaw, 0, 1) === '0') { $hpCorp = '62' . substr($nohpCorpRaw, 1); }
                                else { $hpCorp = $nohpCorpRaw; }
                            }
                            $hpCorpAttr = htmlspecialchars($hpCorp, ENT_QUOTES);

                            $corpModalsBuffer .= '<div class="modal fade" id="exampleoverviewcorp' . $layananIdAttr . '" tabindex="-1" aria-hidden="true" data-bs-backdrop="false">';
                            $corpModalsBuffer .= '<div class="modal-dialog modal-dialog-centered overview-modal-dialog"><div class="modal-content overview-modal-content">';
                            $corpModalsBuffer .= '<div class="modal-header"><h5 class="modal-title">Overview Layanan Corporate</h5>';
                            $corpModalsBuffer .= '<button type="button" class="btn-close overview-close-btn" data-bs-dismiss="modal" aria-label="Close"></button></div>';
                            $corpModalsBuffer .= '<div class="modal-body overview-modal-body"><div class="row overview-main-row">';

                            $corpModalsBuffer .= '<div class="col-12 col-lg-6 overview-formdata-col">';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Perusahaan</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($corp['NAMA_PERUSAHAAN'] ?? '', ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Nama Layanan</label><input type="text" class="form-control form-control-sm" value="' . $namaLayananAttr . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Jenis Layanan</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['jenis_layanan'], ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">PPPoE Username</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['pppoe_username'], ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Auth Mode</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['auth_mode'], ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Server</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['server_brand'] ? $l['server_brand'] . '-' . $l['server_area'] : '-', ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Paket</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['PAKET'] ? $l['PAKET'] . ' (' . $l['KECEPATAN'] . ')' : '-', ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">IP</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['ip_address'] ?: '-', ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">VLAN</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['vlan_number'] ?: '-', ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">OLT</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['oltname'] ?: '-', ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Tanggal Aktif</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars((string) $l['tanggal_aktif'], ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '<div class="mb-1"><label class="form-label">Catatan</label><input type="text" class="form-control form-control-sm" value="' . htmlspecialchars($l['catatan'] ?? '', ENT_QUOTES) . '" readonly></div>';
                            $corpModalsBuffer .= '</div>';

                            $corpModalsBuffer .= '<div class="col-12 col-lg-6 overview-health-col">';
                            $corpModalsBuffer .= '<span style="font-size:15px;" id="data-status-' . $layananIdAttr . '"><span class="badge badge-sm bg-gradient-warning">Loading...</span></span>';
                            $corpModalsBuffer .= '<div id="data-paket-aktif-modal-' . $layananIdAttr . '" class="overview-meta-item"></div>';
                            $corpModalsBuffer .= '<div class="d-flex flex-column gap-2 overview-health-stack">';
                            $corpModalsBuffer .= '<span id="data-info-' . $layananIdAttr . '" class="text-secondary text-xs font-weight-bold">Memuat...</span>';
                            if ($hpCorpAttr !== '') {
                                $corpModalsBuffer .= '<a href="https://wa.me/' . $hpCorpAttr . '" target="_blank" class="btn btn-success btn-sm">WhatsApp PIC ' . $hpCorpAttr . '</a>';
                            }
                            $corpModalsBuffer .= '</div>';
                            $corpModalsBuffer .= '</div></div>';

                            $corpModalsBuffer .= '<div class="acs-device-card border rounded p-3 mt-3" id="acsPanel-' . $layananIdAttr . '" data-idpel="' . $layananIdAttr . '" data-server-id="">';
                            $corpModalsBuffer .= '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
                            $corpModalsBuffer .= '<div class="acs-section-title fw-bold"><i class="fas fa-wifi"></i> Data ACS</div>';
                            $corpModalsBuffer .= '<span id="acs-sync-info-' . $layananIdAttr . '" class="rounded px-2 py-1"></span>';
                            $corpModalsBuffer .= '</div>';
                            $corpModalsBuffer .= '<div id="acsPanelBody-' . $layananIdAttr . '"><span class="acs-empty-text">Memuat data ACS...</span></div>';
                            $corpModalsBuffer .= '</div>';

                            $corpModalsBuffer .= '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button></div>';
                            $corpModalsBuffer .= '</div></div></div>';

                            $corpModalsBuffer .= '<script>
                            document.addEventListener("DOMContentLoaded", function() {
                                var ovModal = document.getElementById("exampleoverviewcorp' . $layananIdAttr . '");
                                if (!ovModal) return;
                                ovModal.addEventListener("shown.bs.modal", function() {
                                    if (typeof fetchStatusOnDemandIfFastMode === "function") {
                                        fetchStatusOnDemandIfFastMode("' . $layananIdAttr . '");
                                    }
                                    if (typeof loadAcsDevicePanel === "function") {
                                        loadAcsDevicePanel("' . $layananIdAttr . '");
                                    }
                                });
                            });
                            </script>';
                        }
                    }
                } else {
                    echo "<tr><td colspan='12' class='text-center'>Belum ada layanan</td></tr>";
                }
                ?>
              </tbody>
            </table>
          </div>
          <?php echo $corpModalsBuffer; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Tambah -->
<div class="modal fade" id="addLayananModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tambah Layanan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/addcorporatelayanan.php">
          <input type="hidden" name="corporate_id" value="<?php echo $corporateId; ?>">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Jenis Layanan</label>
              <input required type="text" class="form-control" name="jenis_layanan" list="jenisLayananList" value="Internet Dedicated">
              <datalist id="jenisLayananList">
                <?php foreach ($jenisLayananOptions as $j): ?><option value="<?php echo htmlspecialchars($j); ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Nama Layanan <small class="text-muted">(label bebas)</small></label>
              <input type="text" class="form-control" name="nama_layanan" placeholder="Mis. Internet Dedicated Kantor Pusat">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Server / Router <small class="text-muted">(opsional)</small></label>
              <select class="form-select" name="server" id="addLayananServer">
                <option value="">-- Tidak terkait server --</option>
                <?php corporate_layanan_render_server_options($conn, $AKSES, $area_list ?? '', $current_user_id ?? 0); ?>
              </select>
              <input type="hidden" name="area" id="addLayananArea">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Paket <small class="text-muted">(wajib kalau Provisioning Otomatis aktif)</small></label>
              <select class="form-select" name="paket_id" id="addLayananPaket">
                <option value="">-- Pilih Server dulu --</option>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">IP Address <small class="text-muted">(opsional)</small></label>
              <input type="text" class="form-control" name="ip_address">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">VLAN <small class="text-muted">(opsional)</small></label>
              <select class="form-select" name="vlan_id">
                <option value="">-- Tidak ada --</option>
                <?php foreach ($vlanOptions as $v): ?>
                  <option value="<?php echo (int) $v['id']; ?>">VLAN <?php echo htmlspecialchars($v['vlan_id']); ?> -- <?php echo htmlspecialchars($v['keterangan']); ?> (<?php echo htmlspecialchars($v['AREA']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">OLT <small class="text-muted">(opsional)</small></label>
              <select class="form-select" name="olt_id">
                <option value="">-- Tidak ada --</option>
                <?php foreach ($oltOptions as $o): ?>
                  <option value="<?php echo (int) $o['id']; ?>"><?php echo htmlspecialchars($o['oltname']); ?> (<?php echo htmlspecialchars($o['area']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal Aktif</label>
            <input type="date" class="form-control" name="tanggal_aktif" value="<?php echo date('Y-m-d'); ?>">
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" value="1" name="provisioning_aktif" id="addProvisioningToggle">
            <label class="form-check-label" for="addProvisioningToggle">Provisioning Otomatis (buat koneksi PPPoE nyata di Mikrotik/RADIUS)</label>
          </div>
          <div id="addProvisioningFields" class="d-none border rounded p-3 mb-3">
            <div class="mb-3">
              <label class="form-label">Auth Mode</label>
              <select class="form-select" name="auth_mode" id="addLayananAuthmode">
                <option value="API MODE">API MODE</option>
                <option value="RADIUS MODE">RADIUS MODE</option>
                <option value="MULTI MODE">MULTI MODE</option>
              </select>
              <small id="addAuthmodeNote" class="text-danger d-none">Server ini RADIUS SAJA -- Auth Mode dikunci ke RADIUS MODE.</small>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Username PPPoE</label>
                <input type="text" class="form-control" name="pppoe_username">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Password PPPoE</label>
                <input type="text" class="form-control" name="pppoe_password">
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Catatan</label>
            <textarea class="form-control" name="catatan" rows="2"></textarea>
          </div>
          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editLayananModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Layanan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/editcorporatelayanan.php">
          <input type="hidden" name="id" id="editLayananId">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Jenis Layanan</label>
              <input required type="text" class="form-control" name="jenis_layanan" id="editLayananJenis" list="jenisLayananListEdit">
              <datalist id="jenisLayananListEdit">
                <?php foreach ($jenisLayananOptions as $j): ?><option value="<?php echo htmlspecialchars($j); ?>"><?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Nama Layanan</label>
              <input type="text" class="form-control" name="nama_layanan" id="editLayananNama">
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Server / Router</label>
              <select class="form-select" name="server" id="editLayananServer">
                <option value="">-- Tidak terkait server --</option>
                <?php corporate_layanan_render_server_options($conn, $AKSES, $area_list ?? '', $current_user_id ?? 0); ?>
              </select>
              <input type="hidden" name="area" id="editLayananArea">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Paket</label>
              <select class="form-select" name="paket_id" id="editLayananPaket">
                <option value="">-- Pilih Server dulu --</option>
              </select>
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">IP Address</label>
              <input type="text" class="form-control" name="ip_address" id="editLayananIp">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">VLAN</label>
              <select class="form-select" name="vlan_id" id="editLayananVlan">
                <option value="">-- Tidak ada --</option>
                <?php foreach ($vlanOptions as $v): ?>
                  <option value="<?php echo (int) $v['id']; ?>">VLAN <?php echo htmlspecialchars($v['vlan_id']); ?> -- <?php echo htmlspecialchars($v['keterangan']); ?> (<?php echo htmlspecialchars($v['AREA']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">OLT</label>
              <select class="form-select" name="olt_id" id="editLayananOlt">
                <option value="">-- Tidak ada --</option>
                <?php foreach ($oltOptions as $o): ?>
                  <option value="<?php echo (int) $o['id']; ?>"><?php echo htmlspecialchars($o['oltname']); ?> (<?php echo htmlspecialchars($o['area']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal Aktif</label>
            <input type="date" class="form-control" name="tanggal_aktif" id="editLayananTanggalAktif">
          </div>
          <div class="mb-3">
            <label class="form-label">Status Layanan</label>
            <select class="form-select" name="status" id="editLayananStatus">
              <option value="AKTIF">AKTIF</option>
              <option value="NONAKTIF">NONAKTIF</option>
            </select>
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" value="1" name="provisioning_aktif" id="editProvisioningToggle">
            <label class="form-check-label" for="editProvisioningToggle">Provisioning Otomatis</label>
          </div>
          <div id="editProvisioningFields" class="d-none border rounded p-3 mb-3">
            <div class="mb-3">
              <label class="form-label">Auth Mode</label>
              <select class="form-select" name="auth_mode" id="editLayananAuthmode">
                <option value="API MODE">API MODE</option>
                <option value="RADIUS MODE">RADIUS MODE</option>
                <option value="MULTI MODE">MULTI MODE</option>
              </select>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Username PPPoE</label>
                <input type="text" class="form-control" name="pppoe_username" id="editLayananUsername">
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Password PPPoE <small class="text-muted">(kosongkan jika tidak ganti)</small></label>
                <input type="text" class="form-control" name="pppoe_password" id="editLayananPassword">
              </div>
            </div>
            <div class="alert alert-secondary small mb-0">
              Ganti Server/Paket/IP/Username/Password pada layanan yang provisioning-nya
              sudah aktif akan memicu re-provisioning otomatis ke router. Mengubah
              <b>Status Layanan</b> di bawah ke NONAKTIF juga otomatis memutus koneksi
              (sama seperti tombol Isolir) -- balikkan ke AKTIF utk menyambungkan lagi.
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Catatan</label>
            <textarea class="form-control" name="catatan" id="editLayananCatatan" rows="2"></textarea>
          </div>
          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function corpLayananApplyAuthLock(serverSelectId, authmodeSelectId, noteId) {
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
function corpLayananLoadPaket(serverSelectId, areaInputId, paketSelectId, selectedPaketId) {
    var serverSelect = document.getElementById(serverSelectId);
    var server = serverSelect.value;
    var area = document.getElementById(areaInputId).value;
    var paketSelect = document.getElementById(paketSelectId);
    if (server === '' || area === '') {
        paketSelect.innerHTML = '<option value="">-- Pilih Server dulu --</option>';
        return;
    }
    paketSelect.innerHTML = '<option value="">Loading...</option>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'getdata/get_packages.php?tipe_layanan=CORPORATE&area=' + encodeURIComponent(area) + '&server=' + encodeURIComponent(server), true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState == 4 && xhr.status == 200) {
            paketSelect.innerHTML = xhr.responseText;
            if (selectedPaketId) {
                paketSelect.value = selectedPaketId;
            }
        }
    };
    xhr.send();
}

// ---- Modal Tambah ----
document.getElementById('addLayananServer').addEventListener('change', function () {
    var selected = this.options[this.selectedIndex];
    document.getElementById('addLayananArea').value = selected ? (selected.getAttribute('data-area') || '') : '';
    corpLayananApplyAuthLock('addLayananServer', 'addLayananAuthmode', 'addAuthmodeNote');
    corpLayananLoadPaket('addLayananServer', 'addLayananArea', 'addLayananPaket', '');
});
document.getElementById('addProvisioningToggle').addEventListener('change', function () {
    document.getElementById('addProvisioningFields').classList.toggle('d-none', !this.checked);
});

// ---- Modal Edit ----
document.getElementById('editLayananServer').addEventListener('change', function () {
    var selected = this.options[this.selectedIndex];
    document.getElementById('editLayananArea').value = selected ? (selected.getAttribute('data-area') || '') : '';
    corpLayananApplyAuthLock('editLayananServer', 'editLayananAuthmode', null);
    corpLayananLoadPaket('editLayananServer', 'editLayananArea', 'editLayananPaket', '');
});
document.getElementById('editProvisioningToggle').addEventListener('change', function () {
    document.getElementById('editProvisioningFields').classList.toggle('d-none', !this.checked);
});

document.getElementById('editLayananModal').addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('editLayananId').value = btn.getAttribute('data-id') || '';
    document.getElementById('editLayananJenis').value = btn.getAttribute('data-jenis') || '';
    document.getElementById('editLayananNama').value = btn.getAttribute('data-nama') || '';
    document.getElementById('editLayananIp').value = btn.getAttribute('data-ip') || '';
    document.getElementById('editLayananTanggalAktif').value = btn.getAttribute('data-tanggalaktif') || '';
    document.getElementById('editLayananCatatan').value = btn.getAttribute('data-catatan') || '';
    document.getElementById('editLayananStatus').value = btn.getAttribute('data-status') || 'AKTIF';
    document.getElementById('editLayananVlan').value = btn.getAttribute('data-vlanid') || '';
    document.getElementById('editLayananOlt').value = btn.getAttribute('data-oltid') || '';
    document.getElementById('editLayananUsername').value = btn.getAttribute('data-username') || '';
    document.getElementById('editLayananPassword').value = '';
    document.getElementById('editLayananAuthmode').value = btn.getAttribute('data-authmode') || 'API MODE';

    var provisioning = btn.getAttribute('data-provisioning') === '1';
    document.getElementById('editProvisioningToggle').checked = provisioning;
    document.getElementById('editProvisioningFields').classList.toggle('d-none', !provisioning);

    var pemilik = btn.getAttribute('data-serverpemilik') || '';
    document.getElementById('editLayananServer').value = pemilik;
    var selected = document.getElementById('editLayananServer').options[document.getElementById('editLayananServer').selectedIndex];
    document.getElementById('editLayananArea').value = selected ? (selected.getAttribute('data-area') || '') : '';

    var paketId = btn.getAttribute('data-paketid') || '';
    corpLayananLoadPaket('editLayananServer', 'editLayananArea', 'editLayananPaket', paketId);
});
</script>

<?php require 'footer.php'; ?>
