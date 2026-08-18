<?php require 'cek-sesi.php';
require_once __DIR__ . '/logo_color_helper.php';
$embed_mode = isset($_GET['embed']) && (string) $_GET['embed'] === '1';

function load_ui_visibility_settings($username)
{
    $defaults = [
        'cards_semua_halaman' => true,
        'buttons_semua_halaman' => true,
        'buttons_dashboard' => true,
        'buttons_customer' => true,
        'buttons_server' => true,
        'buttons_vpn' => true,
        'buttons_tiket' => true,
        'buttons_odp' => true,
        'buttons_olt' => true,
        'buttons_notification' => true,
        'btn_group_dashboard_quick' => true,
        'btn_group_dashboard_view' => true,
        'btn_group_dashboard_export' => true,
        'btn_group_dashboard_system' => true,
        'btn_group_server_manage' => true,
        'btn_group_server_tools' => true,
        'btn_group_server_export' => true,
        'btn_group_olt_manage' => true,
        'btn_group_olt_remote' => true,
        'btn_group_olt_export' => true,
        'btn_group_customer_toolbar' => true,
        'btn_group_customer_filter' => true,
        'btn_group_customer_actions' => true,
        'btn_dash_voucher' => true,
        'btn_dash_add_customer' => true,
        'btn_dash_export_excel' => true,
        'btn_dash_export_pdf' => true,
        'btn_dash_clear_cache' => true,
        'btn_dash_refresh' => true,
        'btn_server_show_interface' => true,
        'btn_server_add_data' => true,
        'btn_server_import' => true,
        'btn_server_export' => true,
        'btn_olt_add_data' => true,
        'btn_olt_import_data' => true,
        'btn_olt_download_template' => true,
        'btn_cust_add_customer' => true,
        'btn_cust_import_customer' => true,
        'btn_cust_scan_unregistered' => true,
        'btn_vpn_add_user' => true,
        'btn_vpn_delete_user' => true,
        'btn_vpn_modal_open' => true,
        'btn_odp_add' => true,
        'btn_odp_import' => true,
        'btn_odp_export_excel' => true,
        'btn_odp_delete' => true,
        'btn_tiket_filter' => true,
        'btn_tiket_update' => true,
        'btn_tiket_create' => true,
        'btn_notif_save_dynamic' => true,
        'btn_notif_save_invoice' => true,
        'btn_notif_modal_save' => true,
        // --- Perluasan cakupan hide-tombol (2026-07-26): 22 halaman yang sebelumnya
        // sama sekali tidak punya kontrol tombol, hanya bisa disembunyikan seluruh
        // halamannya lewat Hak Akses Menu. Lihat plan/audit terkait untuk daftar lengkap.
        'buttons_livechat' => true,
        'btn_livechat_tambah_bot' => true,
        'btn_cust_buat_tiket' => true,
        'buttons_nms' => true,
        'btn_nms_tambah_device' => true,
        'btn_nms_edit_device' => true,
        'btn_nms_hapus_device' => true,
        'btn_nms_toolbar' => true,
        'buttons_ftth' => true,
        'btn_ftth_tambah' => true,
        'btn_ftth_draw_cable' => true,
        'btn_ftth_sync_odp' => true,
        'btn_ftth_export' => true,
        'btn_ftth_import' => true,
        'btn_ftth_save' => true,
        'btn_ftth_delete' => true,
        'btn_ftth_update_feature' => true,
        'buttons_broadcast' => true,
        'btn_broadcast_kirim' => true,
        'btn_broadcast_stop' => true,
        'buttons_joblist' => true,
        'btn_joblist_import' => true,
        'btn_joblist_simpan' => true,
        'btn_joblist_filter' => true,
        'btn_joblist_export' => true,
        'btn_joblist_hapus_duplikat' => true,
        'btn_joblist_assign' => true,
        'btn_joblist_kirim_wa' => true,
        'buttons_provisioning' => true,
        'btn_prov_approve' => true,
        'btn_prov_reject' => true,
        'btn_prov_reaktivasi' => true,
        'buttons_packages' => true,
        'btn_pkg_tambah' => true,
        'btn_pkg_sync' => true,
        'btn_pkg_import' => true,
        'btn_pkg_export' => true,
        'btn_pkg_edit' => true,
        'btn_pkg_hapus' => true,
        'btn_pkg_pendaftaran_setting' => true,
        'buttons_packages_hotspot' => true,
        'btn_pkgh_tambah' => true,
        'btn_pkgh_sync' => true,
        'btn_pkgh_import' => true,
        'btn_pkgh_edit' => true,
        'btn_pkgh_hapus' => true,
        'buttons_menunggak' => true,
        'btn_menunggak_cron' => true,
        'btn_menunggak_export' => true,
        'btn_menunggak_broadcast' => true,
        'btn_menunggak_buat_tiket' => true,
        'btn_menunggak_diskon' => true,
        'buttons_berhenti' => true,
        'btn_berhenti_broadcast' => true,
        'btn_berhenti_regist_ulang' => true,
        'btn_berhenti_hapus_permanen' => true,
        'buttons_voucher_gen' => true,
        'btn_voucher_buat' => true,
        'buttons_voucher_bank' => true,
        'btn_voucherbank_hapus' => true,
        'btn_voucherbank_cetak' => true,
        'buttons_transaction' => true,
        'btn_trx_generate_invoice' => true,
        'btn_trx_export' => true,
        'btn_trx_print_struk' => true,
        'btn_trx_download_pdf' => true,
        'btn_trx_hapus' => true,
        'btn_trx_lihat_bukti' => true,
        'btn_trx_lihat_paket' => true,
        'buttons_diskon' => true,
        'btn_diskon_simpan' => true,
        'btn_diskon_nonaktifkan' => true,
        'buttons_biaya' => true,
        'btn_biaya_simpan' => true,
        'btn_biaya_nonaktifkan' => true,
        'buttons_payment_setting' => true,
        'buttons_struk_setting' => true,
        'btn_struk_simpan' => true,
        'btn_struk_logo' => true,
        'btn_logo_billing_sendiri' => true,
        'btn_linkanda_pendaftaran' => true,
        'btn_linkanda_pelanggan' => true,
        'btn_linkanda_login_billing' => true,
        'btn_linkanda_login_hotspot' => true,
        'btn_linkanda_corporate' => true,
        'buttons_mitra' => true,
        'btn_mitra_tambah' => true,
        'btn_mitra_edit' => true,
        'btn_mitra_topup' => true,
        'btn_mitra_hapus' => true,
        'buttons_commission' => true,
        'btn_komisi_simpan_pppoe' => true,
        'btn_komisi_simpan_hotspot' => true,
        'buttons_api_integrasi' => true,
        'btn_api_regenerate_key' => true,
        'btn_api_simpan_modul' => true,
        'buttons_backup_restore' => true,
        'btn_backup_download' => true,
        'btn_backup_struktur' => true,
        'btn_restore_sekarang' => true,
        'buttons_wabot' => true,
        'btn_wabot_tambah' => true,
        'btn_wabot_login' => true,
        'btn_wabot_reconnect' => true,
        'btn_wabot_logout' => true,
        'btn_wabot_nonaktifkan' => true,
        'btn_wabot_hapus' => true,
        'btn_wabot_aktifkan' => true,
        // --- Menu Customer Corporate (2026-08-07): corporate.php, corporate_kontrak.php,
        // corporate_layanan.php, transaksicorporate.php.
        'buttons_corporate' => true,
        'btn_corp_tambah' => true,
        'btn_corp_edit' => true,
        'btn_corp_kontrak' => true,
        'btn_corp_layanan' => true,
        'btn_corp_invoice' => true,
        'btn_corp_hapus' => true,
        'buttons_corporate_kontrak' => true,
        'btn_corpkontrak_tambah' => true,
        'btn_corpkontrak_hapus' => true,
        'buttons_corporate_layanan' => true,
        'btn_corplayanan_tambah' => true,
        'btn_corplayanan_edit' => true,
        'btn_corplayanan_isolir' => true,
        'btn_corplayanan_hapus' => true,
        'buttons_transaksicorporate' => true,
        'btn_trxcorp_tambah' => true,
        'btn_trxcorp_bayar' => true,
        'btn_trxcorp_cetak' => true,
        'btn_trxcorp_hapus' => true,
        'buttons_corporate_portal_setting' => true,
        'btn_corppl_simpan' => true,
    ];

    $safe_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$username);
    if ($safe_username === '') {
        return $defaults;
    }

    $settings_file = __DIR__ . '/settings/dashboard-cards-' . $safe_username . '.json';
    if (!is_file($settings_file)) {
        return $defaults;
    }

    $raw = @file_get_contents($settings_file);
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    foreach ($defaults as $key => $default_value) {
        if (array_key_exists($key, $decoded)) {
            $defaults[$key] = (bool)$decoded[$key];
        }
    }

    return $defaults;
}

$ui_settings_username = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;
$ui_visibility_settings = load_ui_visibility_settings($ui_settings_username);
// Simpan konfigurasi
$config_file = 'config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$default_logo_file = dirname(__DIR__, 2) . '/logobilling.png';
$default_logo_path = 'https://quenbytekniksejahtera.com/logobilling.png';

// === AUTOMATIC PAGE ACCESS LOGGING ===
// $username = pemilik akun (penentu file log notifbot/data/history-{username}.json,
// SATU file dipakai bersama oleh owner + semua assistant di bawahnya).
// $actor_name = nama yang benar-benar tercatat sbg pelaku di tiap baris log --
// nama assistant kalau yang login assistant, supaya semua pergerakan assistant
// bisa dibedakan dari owner, bukan ikut tercatat atas nama owner.
function log_page_access($username, $actor_name) {
    if (empty($username)) return;
    if (empty($actor_name)) $actor_name = $username;

    // Nama halaman yang mudah dibaca di log -- TANPA ekstensi .php, underscore
    // diganti spasi, huruf awal tiap kata dikapital. Contoh: "tables.php"
    // jadi "Tables", "promo_paket.php" jadi "Promo Paket".
    $current_page_raw = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $current_page_name = preg_replace('/\.php$/i', '', $current_page_raw);
    $current_page_label = ucwords(str_replace(['_', '-'], ' ', (string) $current_page_name));

    // Buat log message
    $log_message = "Membuka halaman: $current_page_label";
    if (!empty($_SERVER['QUERY_STRING'])) {
        $log_message .= " (parameter: " . $_SERVER['QUERY_STRING'] . ")";
    }
    
    // Path file history
    $history_file = "notifbot/data/history-$username.json";
    
    // Buat direktori jika belum ada
    $history_dir = dirname($history_file);
    if (!is_dir($history_dir)) {
        mkdir($history_dir, 0755, true);
    }
    
    // Load existing history
    $history = [];
    if (file_exists($history_file)) {
        $history = json_decode(file_get_contents($history_file), true);
    }
    if (!is_array($history)) $history = [];
    
    // Tambahkan log entry baru dengan format yang sama
    $history[] = "[ $actor_name - " . date('Y-m-d H:i:s') . " ] $log_message";

    // Simpan kembali ke file
    file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Panggil fungsi logging untuk mencatat akses halaman
if (isset($ceknama)) {
    log_page_access($ceknama, !empty($asistant_name) ? $asistant_name : $ceknama);
}
// === END AUTOMATIC PAGE ACCESS LOGGING ===

?>

<?php

// Ambil logo brand untuk semua halaman (termasuk subfolder) dengan path absolut.
// Assistant yang sudah upload logo sendiri (toggle btn_logo_billing_sendiri)
// dicoba LEBIH DULU -- kalau belum upload, otomatis fallback ke logo owner.
$brand_logo_candidates = [];
if ($AKSES === 'ASSISTANT' && !empty($asistant_name) && !empty($ui_visibility_settings['btn_logo_billing_sendiri'] ?? null)) {
    $brand_self = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$asistant_name);
    if ($brand_self !== '') {
        $brand_logo_candidates[] = $brand_self;
        $brand_logo_candidates[] = strtoupper($brand_self);
        $brand_logo_candidates[] = strtolower($brand_self);
    }
}
$brand_owner = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($ceknama ?? ''));
if ($brand_owner !== '') {
    $brand_logo_candidates[] = $brand_owner;
    $brand_logo_candidates[] = strtoupper($brand_owner);
    $brand_logo_candidates[] = strtolower($brand_owner);
}
$brand_logo_candidates = array_values(array_unique(array_filter($brand_logo_candidates)));

// Logo terpusat di dokumen/logo/ (sejajar crm/). $logo_path = URL web untuk
// <img>/<link>, $logo_file = path filesystem untuk file_exists()/filemtime().
$logo_path = '/dokumen/logo/logo.png';
$logo_file = __DIR__ . '/../../dokumen/logo/logo.png';

$logo_matched_candidate = null;
foreach ($brand_logo_candidates as $brand_candidate) {
    $candidate_file = __DIR__ . '/../../dokumen/logo/profile-' . $brand_candidate . '.png';
    if (file_exists($candidate_file)) {
        $logo_file = $candidate_file;
        $logo_path = '/dokumen/logo/profile-' . $brand_candidate . '.png';
        $logo_matched_candidate = $brand_candidate;
        break;
    }
}

// Warna tema per akun (--primary-color/--secondary-color), di-derive dari logo
// yang barusan ke-resolve di atas ($logo_matched_candidate), BUKAN dari
// config.json global lagi. Kalau belum pernah dihitung (logo lama, sebelum
// fitur ini ada), hitung & simpan sekali di sini (self-heal) supaya page-load
// berikutnya langsung baca dari cache -- tidak perlu ekstraksi ulang tiap load.
$logo_theme_colors = null;
if ($logo_matched_candidate !== null) {
    $logo_theme_colors = logoColorGetSaved($logo_matched_candidate);
    if ($logo_theme_colors === null) {
        logoColorExtractAndSave($logo_matched_candidate, $logo_file);
        $logo_theme_colors = logoColorGetSaved($logo_matched_candidate);
    }
}

$favicon_path = $logo_path;
$favicon_file = $logo_file;
if (!file_exists($favicon_file)) {
    $favicon_path = $default_logo_path;
    $favicon_file = $default_logo_file;
}

$favicon_version = file_exists($favicon_file) ? (string)filemtime($favicon_file) : (string)time();
$favicon_token = rawurlencode((string)($ceknama ?? 'global')) . '-' . (string)time();
$page_title = 'Billing system';
if (!empty($ceknama)) {
    $page_title .= ' - ' . $ceknama;
}

// Hidden input untuk simpan warna hasil extract (agar bisa dipakai PHP/JS).
// Diisi dari $logo_theme_colors (settings/logo-colors-{username}.json, dihitung
// server-side dgn GD dari logo AKUN INI SENDIRI di atas) -- BUKAN lagi dari
// config.json global yang dulu dipakai bersama owner + semua assistant-nya.
// Kalau belum ada logo/warna sama sekali, dibiarkan kosong: loadSavedColors()
// no-op dan CSS jatuh ke warna default netral, lalu extractColorsFromLogo()
// (live, di browser) tetap jalan sbg fallback terakhir & akan self-heal cache
// ini lewat proses/save_logo_colors.php begitu berhasil.
echo '<input type="hidden" id="extracted_primary_color" value="'.htmlspecialchars($logo_theme_colors['primary'] ?? '').'">';
echo '<input type="hidden" id="extracted_secondary_color" value="'.htmlspecialchars($logo_theme_colors['secondary'] ?? '').'">';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link id="appTouchIcon" rel="apple-touch-icon" sizes="76x76" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>&fv=<?= htmlspecialchars($favicon_token, ENT_QUOTES, 'UTF-8'); ?>">
    <link id="appFavicon32" rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>&fv=<?= htmlspecialchars($favicon_token, ENT_QUOTES, 'UTF-8'); ?>">
    <link id="appFavicon192" rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>&fv=<?= htmlspecialchars($favicon_token, ENT_QUOTES, 'UTF-8'); ?>">
    <link id="appShortcutIcon" rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>&fv=<?= htmlspecialchars($favicon_token, ENT_QUOTES, 'UTF-8'); ?>">
  <title>
   <?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?>
  </title>
  <!-- Cache Control Meta Tags for Performance -->
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        (function () {
            var iconHref = <?= json_encode(htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8') . '?v=' . htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8') . '&fv=' . htmlspecialchars($favicon_token, ENT_QUOTES, 'UTF-8'), JSON_UNESCAPED_UNICODE); ?>;
            ["appTouchIcon", "appFavicon32", "appFavicon192", "appShortcutIcon"].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) {
                    el.href = iconHref;
                }
            });
        })();
    </script>
    <script>
        if (!window.__billingUiVisibilityScriptInjected) {
            window.__billingUiVisibilityScriptInjected = true;
        }
        window.billingUiVisibility = <?php echo json_encode($ui_visibility_settings, JSON_UNESCAPED_UNICODE); ?>;

        function getCurrentPageName() {
            const pathname = window.location.pathname || '';
            const filename = pathname.split('/').pop() || 'unknown';
            return filename.replace('.php', '').toLowerCase();
        }

        function getPageButtonSettingKey(pageName) {
            const pageMap = {
                'dashboard': 'buttons_dashboard',
                'tables': 'buttons_customer',
                'tableshotspot': 'buttons_customer',
                'daftar_pelanggan': 'buttons_customer',
                'customer': 'buttons_customer',
                'server': 'buttons_server',
                'vpn': 'buttons_vpn',
                'vpnadmin': 'buttons_vpn',
                'tiket_manager': 'buttons_tiket',
                'odp': 'buttons_odp',
                'olt': 'buttons_olt',
                'mynetworkmap': 'buttons_nms',
                'notification': 'buttons_notification',
                'livechat': 'buttons_livechat',
                'ftth_maps': 'buttons_ftth',
                'broadcast': 'buttons_broadcast',
                'monitoring': 'buttons_joblist',
                'provisioning_approval': 'buttons_provisioning',
                'packages': 'buttons_packages',
                'packageshotspot': 'buttons_packages_hotspot',
                'pelanggan_menunggak': 'buttons_menunggak',
                'daftar_pelanggan_berhenti': 'buttons_berhenti',
                'vouchergenerator': 'buttons_voucher_gen',
                'voucherbank': 'buttons_voucher_bank',
                'transaction': 'buttons_transaction',
                'diskon': 'buttons_diskon',
                'biaya_tambahan': 'buttons_biaya',
                'paymentset': 'buttons_payment_setting',
                'struk_setting': 'buttons_struk_setting',
                'mitraadmin': 'buttons_mitra',
                'commissionsetting': 'buttons_commission',
                'settingsapi': 'buttons_api_integrasi',
                'backup_restore': 'buttons_backup_restore',
                'wabot': 'buttons_wabot',
                'pool': 'buttons_pool',
                'vlan': 'buttons_vlan',
                'acs_server_info': 'buttons_acs',
                'pengeluaran': 'buttons_pengeluaran',
                'statistics': 'buttons_statistik',
                'portal_setting': 'buttons_portal_setting',
                'rekappembayaranmitra': 'buttons_komisi_pembayaran',
                'system_setting': 'buttons_system_setting',
                'telegrambot': 'buttons_telegram',
                'corporate': 'buttons_corporate',
                'corporate_kontrak': 'buttons_corporate_kontrak',
                'corporate_layanan': 'buttons_corporate_layanan',
                'transaksicorporate': 'buttons_transaksicorporate',
                'corporate_portal_setting': 'buttons_corporate_portal_setting',
            };
            return pageMap[pageName] || null;
        }

        function hideButtonsBySelectors(root, selectorText) {
            if (!selectorText) return;
            root.querySelectorAll(selectorText).forEach(function(el) {
                if (el.closest('.modal')) return;
                el.style.display = 'none';
            });
        }

        function applyGroupedButtonVisibility(pageName, settings, mainContent) {
            const pageGroups = {
                'dashboard': [
                    { key: 'btn_group_dashboard_quick', selectors: '.dashboard-quick-action-btn, a[href="vouchergenerator.php"], button[data-bs-target="#addCustomerModal"]' },
                    { key: 'btn_group_dashboard_view', selectors: 'button[data-bs-target="#customerModal"], a[href*="daftar_pelanggan_berhenti.php"], a[href*="Transaction.php"], a[href*="pengeluaran.php"], a[href="statistics.php"]' },
                    { key: 'btn_group_dashboard_export', selectors: '#btnExportInternetExcel, #btnExportInternetPdf, a[href*="printdatapelanggan.php"]' },
                    { key: 'btn_group_dashboard_system', selectors: '#clearLogBtn, button[onclick*="clearBrowserCache"], button[onclick*="location.reload"]' }
                ],
                'server': [
                    { key: 'btn_group_server_manage', selectors: 'button[data-bs-target="#dataModal"], .btn.btn-success.mt-3' },
                    { key: 'btn_group_server_tools', selectors: 'a[href="vpn.php"], a[href="vpnadmin.php"], #showInterfaceForm button[type="submit"]' },
                    { key: 'btn_group_server_export', selectors: 'a[href="import_server.php"], a[href="export_server.php"]' }
                ],
                'olt': [
                    { key: 'btn_group_olt_manage', selectors: 'button[data-bs-target="#dataModal"], button[data-bs-target="#importModal"], button[data-bs-target^="#editModal"], #importBtn' },
                    { key: 'btn_group_olt_remote', selectors: '.open-remote, .open-olt-console, .open-remotegpon' },
                    { key: 'btn_group_olt_export', selectors: 'a[href="proses/download_template_olt.php"], a[href="olt_documentation.php"]' }
                ],
                'tables': [
                    { key: 'btn_group_customer_toolbar', selectors: '.customer-toolbar-btn, #btnScanUnregisteredPppoe, #btnScanActiveConnections, a[href="importcustomerpppoe.php"]' },
                    { key: 'btn_group_customer_filter', selectors: '#form-filter-pelanggan button[type="submit"], #form-carilos button[type="submit"], #btnSaveMCronInterval, #btnRunMCronNow, #btnShowMCronLog, #btnRefreshMLog' },
                    { key: 'btn_group_customer_actions', selectors: '.modal-action-btn, .send-invoice, .disable-customer, .active-customer, .btn-delete-unregistered-pppoe, .btn-kill-active-conn, a[href*="deletecustomer.php"], a[href*="editcustomerform.php"]' }
                ],
                'tableshotspot': [
                    { key: 'btn_group_customer_toolbar', selectors: 'a[href="vouchergenerator.php"]' },
                    { key: 'btn_group_customer_filter', selectors: 'button[type="submit"].btn-warning' },
                    { key: 'btn_group_customer_actions', selectors: '.modal-action-btn, a[href*="deletecustomer"], a[href*="editcustomer"]' }
                ]
            };

            const groups = pageGroups[pageName] || [];
            groups.forEach(function(group) {
                if (settings[group.key] === false) {
                    hideButtonsBySelectors(mainContent, group.selectors);
                }
            });
        }

        function applyIndividualButtonVisibility(pageName, settings, mainContent) {
            const pageButtons = {
                'dashboard': [
                    { key: 'btn_dash_voucher', selectors: 'a[href="vouchergenerator.php"]' },
                    { key: 'btn_dash_add_customer', selectors: 'button[data-bs-target="#addCustomerModal"]' },
                    { key: 'btn_dash_export_excel', selectors: '#btnExportInternetExcel' },
                    { key: 'btn_dash_export_pdf', selectors: '#btnExportInternetPdf' },
                    { key: 'btn_dash_clear_cache', selectors: 'button[onclick*="clearBrowserCache"]' },
                    { key: 'btn_dash_refresh', selectors: 'button[onclick*="location.reload"]' },
                    { key: 'btn_dash_ticket_edit', selectors: '[onclick^="openEditTiketModal("]' },
                    { key: 'btn_dash_ticket_hapus', selectors: '#btnHapusTerpilih, [onclick^="hapusTiketDashboard("]' }
                ],
                'server': [
                    { key: 'btn_server_show_interface', selectors: '#showInterfaceForm button[type="submit"]' },
                    { key: 'btn_server_add_data', selectors: 'button[data-bs-target="#dataModal"], .btn.btn-success.mt-3' },
                    { key: 'btn_server_import', selectors: 'a[href="import_server.php"]' },
                    { key: 'btn_server_export', selectors: 'a[href="export_server.php"]' },
                    { key: 'btn_server_logo', selectors: '[onclick="uploadServerLogo()"], #edit_logo_remove_btn' }
                ],
                'olt': [
                    { key: 'btn_olt_add_data', selectors: 'button[data-bs-target="#dataModal"]' },
                    { key: 'btn_olt_import_data', selectors: 'button[data-bs-target="#importModal"], #importBtn' },
                    { key: 'btn_olt_download_template', selectors: 'a[href="proses/download_template_olt.php"]' }
                ],
                'tables': [
                    { key: 'btn_cust_add_customer', selectors: 'button[data-bs-target="#addCustomerModal"]' },
                    { key: 'btn_cust_import_customer', selectors: 'a[href="importcustomerpppoe.php"]' },
                    { key: 'btn_cust_scan_unregistered', selectors: '#btnScanUnregisteredPppoe' },
                    { key: 'btn_cust_buat_tiket', selectors: '[data-perm="btn_cust_buat_tiket"]' },
                    { key: 'btn_cust_hapus_massal', selectors: '#btnDeleteSelectedPppoe' },
                    { key: 'btn_cust_acs_reboot', selectors: '[onclick^="rebootAcsDevice("]' },
                    { key: 'btn_cust_edit_jatuh_tempo', selectors: '[onclick*="openEditMonthversaryModal("]' }
                ],
                'vpn': [
                    { key: 'btn_vpn_add_user', selectors: 'button[name="add"] , button[type="submit"].btn-success' },
                    { key: 'btn_vpn_delete_user', selectors: 'button[type="submit"].btn-danger' },
                    { key: 'btn_vpn_modal_open', selectors: 'button[data-bs-target="#dataModal"]' }
                ],
                'odp': [
                    { key: 'btn_odp_add', selectors: 'button[data-bs-target="#dataModal"]' },
                    { key: 'btn_odp_import', selectors: 'button[data-bs-target="#importModal"], button[data-bs-target="#importKmzModal"]' },
                    { key: 'btn_odp_export_excel', selectors: 'a[href="proses/export_odp_excel.php"]' },
                    { key: 'btn_odp_delete', selectors: 'button[type="submit"].btn-danger, form[action*="deleteodp.php"] button[type="submit"]' }
                ],
                'tiket_manager': [
                    { key: 'btn_tiket_filter', selectors: 'button[type="submit"].w-100.btn-sm.btn-primary, a[href="tiket_manager.php"].btn' },
                    { key: 'btn_tiket_update', selectors: 'button[name="update_ticket"]' },
                    { key: 'btn_tiket_create', selectors: 'button[name="create_ticket"]' },
                    { key: 'btn_tiket_process', selectors: 'button[name="process_submit"], .js-open-process-link' }
                ],
                'notification': [
                    { key: 'btn_notif_save_dynamic', selectors: 'button[name="simpan_pengaturan_salam_dinamis"]' },
                    { key: 'btn_notif_save_invoice', selectors: 'button[name="simpan_invoice_generator"]' },
                    { key: 'btn_notif_modal_save', selectors: '.modal-save-form button[type="submit"].btn-primary' }
                ],
                'livechat': [
                    { key: 'btn_livechat_tambah_bot', selectors: 'a[href="wabot.php"]' }
                ],
                'mynetworkmap': [
                    { key: 'btn_nms_tambah_device', selectors: '[data-bs-target="#addDeviceModal"], button[name="add_device"]' },
                    { key: 'btn_nms_edit_device', selectors: 'button[data-bs-target^="#editDeviceModal_"], button[name="edit_device"]' },
                    { key: 'btn_nms_hapus_device', selectors: 'button[name="delete_device"]' },
                    { key: 'btn_nms_toolbar', selectors: '#pauseUpdatesBtn, #clearCacheBtn, #updateGrafikTrafikBtn, #updateGrafikAktifBtn' }
                ],
                'ftth_maps': [
                    { key: 'btn_ftth_tambah', selectors: '[onclick^="startAddingAsset("]' },
                    { key: 'btn_ftth_draw_cable', selectors: '[onclick="startDrawingCable()"]' },
                    { key: 'btn_ftth_sync_odp', selectors: '[onclick="syncODP()"]' },
                    { key: 'btn_ftth_export', selectors: '[onclick="exportGeoJSON()"], a[href="proses/export_ftth_maps.php"], [data-bs-target="#exportKmlModal"], button[form="export-kml-form"]' },
                    { key: 'btn_ftth_import', selectors: '[onclick="importGeoJSON()"], [onclick="doImport()"]' },
                    { key: 'btn_ftth_save', selectors: '[onclick="saveFeature()"], [onclick="submitAttributes()"]' },
                    { key: 'btn_ftth_delete', selectors: '[onclick="deleteFeature()"]' },
                    { key: 'btn_ftth_update_feature', selectors: '[onclick="updateFeature()"]' }
                ],
                'broadcast': [
                    { key: 'btn_broadcast_kirim', selectors: '#sendBtn' },
                    { key: 'btn_broadcast_stop', selectors: '#stopBtn' }
                ],
                'monitoring': [
                    { key: 'btn_joblist_import', selectors: 'input[name="import"]' },
                    { key: 'btn_joblist_simpan', selectors: 'input[name="simpan"]' },
                    { key: 'btn_joblist_filter', selectors: '[data-perm="btn_joblist_filter"]' },
                    { key: 'btn_joblist_export', selectors: '[data-bs-target="#exportModal"], [data-perm="btn_joblist_export"]' },
                    { key: 'btn_joblist_hapus_duplikat', selectors: 'a[href*="cari=HAPUSDUPLIKAT"]' },
                    { key: 'btn_joblist_assign', selectors: '#assignTicketModal button[name="submit"]' },
                    { key: 'btn_joblist_kirim_wa', selectors: '[data-perm="btn_joblist_kirim_wa"]' }
                ],
                'provisioning_approval': [
                    { key: 'btn_prov_approve', selectors: '[onclick^="approveProvisioning("], #approveReviewBtn' },
                    { key: 'btn_prov_reject', selectors: '[onclick^="rejectProvisioning("]' },
                    { key: 'btn_prov_reaktivasi', selectors: '[onclick^="reactivateProvisioning("]' }
                ],
                'packages': [
                    { key: 'btn_pkg_tambah', selectors: '[data-bs-target="#dataModal"], button[form="dataForm"]' },
                    { key: 'btn_pkg_sync', selectors: '[data-bs-target="#syncPilihServerModal"], #syncSubmitBtn' },
                    { key: 'btn_pkg_import', selectors: '[data-bs-target="#importModal"], [data-perm="btn_pkg_import"]' },
                    { key: 'btn_pkg_export', selectors: 'a[href="proses/export_packages.php"], a[href="proses/download_template_paket.php"]' },
                    { key: 'btn_pkg_edit', selectors: '.btn-edit-paket, [data-perm="btn_pkg_edit"]' },
                    { key: 'btn_pkg_hapus', selectors: '.btn-delete-paket, [data-perm="btn_pkg_hapus"]' },
                    { key: 'btn_pkg_pendaftaran_setting', selectors: '[data-bs-target="#pendaftaranSettingModal"], [data-perm="btn_pkg_pendaftaran_setting"]' }
                ],
                'packageshotspot': [
                    { key: 'btn_pkgh_tambah', selectors: '[data-bs-target="#dataModal"], button[form="dataForm"]' },
                    { key: 'btn_pkgh_sync', selectors: '[data-bs-target="#syncPilihServerHotspotModal"], #syncHotspotSubmitBtn' },
                    { key: 'btn_pkgh_import', selectors: '[data-bs-target="#importHotspotModal"], [data-perm="btn_pkgh_import"]' },
                    { key: 'btn_pkgh_edit', selectors: '.btn-edit-paket-hotspot, [data-perm="btn_pkgh_edit"], button[form="editForm"]' },
                    { key: 'btn_pkgh_hapus', selectors: '.btn-delete-paket-hotspot, [data-perm="btn_pkgh_hapus"]' }
                ],
                'pelanggan_menunggak': [
                    { key: 'btn_menunggak_cron', selectors: '#btnShowCronLog, #btnRunCronDismantleNow, #toggleCronDismantle, #btnSaveCronInterval' },
                    { key: 'btn_menunggak_export', selectors: '[onclick*="exportMenunggakExcel"], [onclick*="exportMenunggakPdf"]' },
                    { key: 'btn_menunggak_broadcast', selectors: '#menunggakSendBtn, #menunggakStopBtn' },
                    { key: 'btn_menunggak_buat_tiket', selectors: '#menunggakTicketBtn' },
                    { key: 'btn_menunggak_diskon', selectors: '#menunggakDiskonBtn' }
                ],
                'daftar_pelanggan_berhenti': [
                    { key: 'btn_berhenti_broadcast', selectors: '#formBroadcastBerhenti button[type="submit"]' },
                    { key: 'btn_berhenti_regist_ulang', selectors: '[onclick^="registUlang("], button[form="registUlangForm"]' },
                    { key: 'btn_berhenti_hapus_permanen', selectors: '[onclick^="hapusPermanen("]' }
                ],
                'vouchergenerator': [
                    { key: 'btn_voucher_buat', selectors: '[data-bs-target="#previewModal"]' },
                    { key: 'btn_voucher_template_builder', selectors: '[data-perm="btn_voucher_template_builder"]' }
                ],
                'voucherbank': [
                    { key: 'btn_voucherbank_hapus', selectors: 'button[name="delete_selected"], button[name="delete_user"]' },
                    { key: 'btn_voucherbank_cetak', selectors: '#btn-cetak-voucher, #btn-cetak-voucher-cetak' },
                    { key: 'btn_voucherbank_export', selectors: '[data-perm="btn_voucherbank_export"]' }
                ],
                'transaction': [
                    { key: 'btn_trx_generate_invoice', selectors: '#manualGenerateBtn' },
                    { key: 'btn_trx_export', selectors: 'a[href^="export_pdf.php"], a[href^="export_excel.php"]' },
                    { key: 'btn_trx_print_struk', selectors: 'a[href^="print_struk.php"]' },
                    { key: 'btn_trx_download_pdf', selectors: 'a[href^="print_card.php"]' },
                    { key: 'btn_trx_hapus', selectors: '[data-perm="btn_trx_hapus"]' },
                    { key: 'btn_trx_adjust_tanggal', selectors: '[data-perm="btn_trx_adjust_tanggal"]' }
                ],
                'diskon': [
                    { key: 'btn_diskon_simpan', selectors: '[name="save_discount"]' },
                    { key: 'btn_diskon_nonaktifkan', selectors: '#bulkDeactivateForm button[type="submit"], [data-perm="btn_diskon_nonaktifkan"]' }
                ],
                'biaya_tambahan': [
                    { key: 'btn_biaya_simpan', selectors: '[name="save_fee"]' },
                    { key: 'btn_biaya_nonaktifkan', selectors: '#bulkDeactivateFeeForm button[type="submit"], [data-perm="btn_biaya_nonaktifkan"]' }
                ],
                'struk_setting': [
                    { key: 'btn_struk_simpan', selectors: '[name="save_struk_settings"]' },
                    { key: 'btn_struk_logo', selectors: '#strukLogoInput, [data-perm="btn_struk_logo"]' }
                ],
                'mitraadmin': [
                    { key: 'btn_mitra_tambah', selectors: '[data-bs-target="#dataModal"]' },
                    { key: 'btn_mitra_edit', selectors: '[data-bs-target^="#editModal"]' },
                    { key: 'btn_mitra_topup', selectors: '[data-bs-target^="#topupModal"]' },
                    { key: 'btn_mitra_hapus', selectors: '[data-perm="btn_mitra_hapus"]' }
                ],
                'commissionsetting': [
                    { key: 'btn_komisi_simpan_pppoe', selectors: '[data-perm="btn_komisi_simpan_pppoe"]' },
                    { key: 'btn_komisi_simpan_hotspot', selectors: '[data-perm="btn_komisi_simpan_hotspot"]' }
                ],
                'settingsapi': [
                    { key: 'btn_api_regenerate_key', selectors: 'button[name="regenerate_key"]' },
                    { key: 'btn_api_simpan_modul', selectors: 'button[name="save_module_toggles"]' }
                ],
                'backup_restore': [
                    { key: 'btn_backup_download', selectors: '[data-perm="btn_backup_download"]' },
                    { key: 'btn_backup_struktur', selectors: '[data-perm="btn_backup_struktur"]' },
                    { key: 'btn_restore_sekarang', selectors: '[data-perm="btn_restore_sekarang"]' }
                ],
                'wabot': [
                    { key: 'btn_wabot_tambah', selectors: '[data-bs-target="#dataModal"]' },
                    { key: 'btn_wabot_login', selectors: '[onclick^="handleBotLogin("]' },
                    { key: 'btn_wabot_reconnect', selectors: '[onclick^="handleBotReconnect("]' },
                    { key: 'btn_wabot_logout', selectors: '[onclick^="handleBotLogout("]' },
                    { key: 'btn_wabot_nonaktifkan', selectors: 'button[name="deactivate_integrasi_unofficial"], button[name="deactivate_integrasi"]' },
                    { key: 'btn_wabot_hapus', selectors: 'button[name="delete_integrasi_unofficial"], button[name="delete_integrasi"]' },
                    { key: 'btn_wabot_aktifkan', selectors: 'button[name="activate_integrasi_unofficial"], button[name="activate_integrasi"]' },
                    { key: 'btn_wabot_integrasi_gateway', selectors: '[data-bs-target="#addUnofficialModal"], [data-bs-target="#addIntegrasiModal"]' },
                    { key: 'btn_wabot_advanced_settings', selectors: '[data-bs-target="#databaseSettingsModal"], [data-bs-target="#functionSettingsModal"], [data-bs-target="#technicalMenuDbModal"], [data-bs-target="#portRangeModal"]' }
                ],
                'pool': [
                    { key: 'btn_pool_sync', selectors: 'form[action="proses/sync_pool.php"] button[type="submit"]' },
                    { key: 'btn_pool_tambah', selectors: 'form[action="proses/apply_pool.php"] button[type="submit"]' },
                    { key: 'btn_pool_import', selectors: '[data-bs-target="#importPoolModal"]' },
                    { key: 'btn_pool_export', selectors: 'a[href="proses/export_pool.php"], a[href="proses/download_template_pool.php"]' },
                    { key: 'btn_pool_hapus', selectors: '#btnHapusTerpilih, a[href^="proses/delete_pool.php"]' }
                ],
                'vlan': [
                    { key: 'btn_vlan_tambah', selectors: '[data-bs-target="#addVlanModal"]' },
                    { key: 'btn_vlan_edit', selectors: '[onclick^="openEditVlanModal("]' },
                    { key: 'btn_vlan_hapus', selectors: 'a[href^="proses/delete_vlan.php"]' },
                    { key: 'btn_vlan_sync', selectors: 'a[href="proses/sync_vlan.php"]' }
                ],
                'pengeluaran': [
                    { key: 'btn_pengeluaran_simpan', selectors: '[data-perm="btn_pengeluaran_simpan"]' },
                    { key: 'btn_pengeluaran_kategori', selectors: 'button[name="tambah_kategori"]' },
                    { key: 'btn_pengeluaran_hapus', selectors: 'a[href^="?hapus="]' }
                ],
                'portal_setting': [
                    { key: 'btn_portal_simpan', selectors: 'button[name="save_portal_links"]' },
                    { key: 'btn_portal_logo', selectors: '[data-perm="btn_portal_logo"]' }
                ],
                'rekappembayaranmitra': [
                    { key: 'btn_komisi_cron', selectors: '[data-perm="btn_komisi_cron"]' },
                    { key: 'btn_komisi_buat_rekap', selectors: '[data-bs-target="#modalRekap"]' },
                    { key: 'btn_komisi_hapus', selectors: 'button[name="hapus_selected"], button[name="hapus_ids"]' },
                    { key: 'btn_komisi_acc', selectors: 'button[name="acc_ids"]' }
                ],
                'system_setting': [
                    { key: 'btn_system_cron_dismantle', selectors: '#btnShowCronLog, #btnRunCronDismantleNow, #btnSaveCronInterval, #btnRefreshLog' },
                    { key: 'btn_system_cron_maintenance', selectors: '#btnShowMCronLog, #btnRunMCronNow, #btnSaveMCronInterval, #btnRefreshMLog' },
                    { key: 'btn_system_cron_nms', selectors: '#toggleCronNms, #btnRunCronNmsNow, #btnSaveNmsCronInterval' }
                ],
                'telegrambot': [
                    { key: 'btn_telegram_tambah', selectors: '[data-bs-target="#addTelegramBotModal"]' },
                    { key: 'btn_telegram_test', selectors: '.telegram-test-btn' },
                    { key: 'btn_telegram_hapus', selectors: '.telegram-delete-btn' },
                    { key: 'btn_telegram_save_penerima', selectors: '.telegram-save-penerima-btn' }
                ],
                'corporate': [
                    { key: 'btn_corp_tambah', selectors: '[data-bs-target="#addCorporateModal"]' },
                    { key: 'btn_corp_edit', selectors: '[data-perm="btn_corp_edit"]' },
                    { key: 'btn_corp_kontrak', selectors: '[data-perm="btn_corp_kontrak"]' },
                    { key: 'btn_corp_layanan', selectors: '[data-perm="btn_corp_layanan"]' },
                    { key: 'btn_corp_invoice', selectors: '[data-perm="btn_corp_invoice"]' },
                    { key: 'btn_corp_hapus', selectors: '[data-perm="btn_corp_hapus"]' }
                ],
                'corporate_kontrak': [
                    { key: 'btn_corpkontrak_tambah', selectors: '[data-perm="btn_corpkontrak_tambah"]' },
                    { key: 'btn_corpkontrak_hapus', selectors: '[data-perm="btn_corpkontrak_hapus"]' }
                ],
                'corporate_layanan': [
                    { key: 'btn_corplayanan_tambah', selectors: '[data-bs-target="#addLayananModal"]' },
                    { key: 'btn_corplayanan_edit', selectors: '[data-perm="btn_corplayanan_edit"]' },
                    { key: 'btn_corplayanan_isolir', selectors: '[data-perm="btn_corplayanan_isolir"]' },
                    { key: 'btn_corplayanan_hapus', selectors: '[data-perm="btn_corplayanan_hapus"]' }
                ],
                'transaksicorporate': [
                    { key: 'btn_trxcorp_tambah', selectors: '[data-bs-target="#addInvoiceModal"]' },
                    { key: 'btn_trxcorp_bayar', selectors: '[data-perm="btn_trxcorp_bayar"]' },
                    { key: 'btn_trxcorp_cetak', selectors: '[data-perm="btn_trxcorp_cetak"]' },
                    { key: 'btn_trxcorp_hapus', selectors: '[data-perm="btn_trxcorp_hapus"]' }
                ],
                'corporate_portal_setting': [
                    { key: 'btn_corppl_simpan', selectors: 'button[name="save_corporate_portal"]' }
                ]
            };

            const buttons = pageButtons[pageName] || [];
            buttons.forEach(function(buttonCfg) {
                if (settings[buttonCfg.key] === false) {
                    hideButtonsBySelectors(mainContent, buttonCfg.selectors);
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (window.__billingUiVisibilityApplied) return;
            window.__billingUiVisibilityApplied = true;

            const settings = (window.billingUiVisibility && typeof window.billingUiVisibility === 'object')
                ? window.billingUiVisibility
                : {};

            const mainContent = document.querySelector('main.main-content') || document.body;
            if (!mainContent) return;

            if (settings.cards_semua_halaman === false) {
                mainContent.querySelectorAll('.card, .card-box').forEach(function(el) {
                    if (el.closest('.modal')) return;
                    el.style.display = 'none';
                });
            }

            if (settings.buttons_semua_halaman === false) {
                mainContent.querySelectorAll('.btn, button, input[type="button"], input[type="submit"], a.btn').forEach(function(el) {
                    if (el.closest('.modal')) return;
                    el.style.display = 'none';
                });
                return;
            }

            const currentPage = getCurrentPageName();
            const pageButtonKey = getPageButtonSettingKey(currentPage);
            if (pageButtonKey && settings[pageButtonKey] === false) {
                mainContent.querySelectorAll('.btn, button, input[type="button"], input[type="submit"], a.btn').forEach(function(el) {
                    if (el.closest('.modal')) return;
                    el.style.display = 'none';
                });
                return;
            }

            applyGroupedButtonVisibility(currentPage, settings, mainContent);
            applyIndividualButtonVisibility(currentPage, settings, mainContent);
        });
    </script>
  <!--     Fonts and icons     -->
  <link href="https://fonts.googleapis.com/css?family=Inter:300,400,500,600,700,800" rel="stylesheet" />
  <!-- Nucleo Icons -->
  <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-icons.css" rel="stylesheet" />
  <link href="https://demos.creative-tim.com/soft-ui-dashboard/assets/css/nucleo-svg.css" rel="stylesheet" />
  <!-- Font Awesome Icons -->
  <script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
  <!-- CSS Files -->
  <link id="pagestyle" href="../assets/css/soft-ui-dashboard.css?v=1.1.0" rel="stylesheet" />

</head>

<!-- Loading Screen -->
<!-- <div id="loading">
    <div class="loading-background-shapes">
       
        <div class="logo-blur-bg"></div>
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
        <div class="shape shape-5"></div>
    </div>
    <div class="loading-content">
        <div class="logo-container">
            <img src="<?php echo htmlspecialchars($logo_path); ?>?v=<?php echo time(); ?>" alt="Logo" class="loading-logo" id="main-logo-img" />
            <div class="logo-glow"></div>
        </div>
        <div class="spinner"></div>
        <div class="loading-progress">
            <div class="progress-bar"></div>
        </div>
    </div>
</div> -->

<style>
    /* ============================================
       ROOT CSS VARIABLES & DESIGN SYSTEM
       ============================================ */
    :root {
        /* Brand Colors - Dynamic from Logo */
        --primary-color: #2563eb;
        --secondary-color: #3b82f6;
        --accent-color: #1d4ed8;
        
        /* Neutral Colors */
        --white: #ffffff;
        --bg-light: #f8fafc;
        --bg-gray: #f1f5f9;
        --text-primary: #1e293b;
        --text-muted: #64748b;
        --border-color: #e2e8f0;
        
        /* Status Colors */
        --success: #10b981;
        --warning: #f59e0b;
        --danger: #ef4444;
        --info: #3b82f6;
        
        /* Design System */
        --radius: 8px;
        --radius-lg: 12px;
        --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.08);
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
    }

    /* ============================================
       LOADING SCREEN
       ============================================ */
   
    #loading.fade-out {
        opacity: 0;
        pointer-events: none;
    }

    .loading-background-shapes {
        position: absolute;
        inset: 0;
        overflow: hidden;
        z-index: 1;
    }

    .logo-blur-bg {
        position: absolute;
        inset: 0;
        background: rgba(0, 0, 0, 0.05);
        filter: blur(20px);
        z-index: 0;
    }

    .shape {
        position: absolute;
        border-radius: 50%;
        opacity: 0.05;
        animation: float 6s ease-in-out infinite;
    }

    .shape-1 { width: 80px; height: 80px; top: 10%; left: 10%; animation-delay: 0s; }
    .shape-2 { width: 120px; height: 120px; top: 20%; right: 15%; animation-delay: 1s; }
    .shape-3 { width: 60px; height: 60px; bottom: 25%; left: 20%; animation-delay: 2s; }
    .shape-4 { width: 100px; height: 100px; bottom: 15%; right: 25%; animation-delay: 3s; }
    .shape-5 { width: 90px; height: 90px; top: 50%; left: 5%; animation-delay: 4s; }

    @keyframes float {
        0%, 100% { transform: translateY(0) scale(1); }
        50% { transform: translateY(-20px) scale(1.05); }
    }

    .loading-content {
        text-align: center;
        color: var(--white);
        z-index: 2;
        position: relative;
    }

    .logo-container {
        margin-bottom: 30px;
        position: relative;
    }

    .loading-logo {
        width: 100px;
        height: 100px;
        object-fit: contain;
        filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.2));
        animation: logoFloat 2s ease-in-out infinite;
    }

    @keyframes logoFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }

    .spinner {
        width: 50px;
        height: 50px;
        border: 3px solid rgba(255, 255, 255, 0.2);
        border-top: 3px solid var(--white);
        border-radius: 50%;
        margin: 20px auto;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .loading-text {
        font-size: 16px;
        font-weight: 600;
        margin: 20px 0;
        letter-spacing: 0.5px;
    }

    .loading-progress {
        width: 200px;
        height: 4px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 2px;
        margin: 20px auto;
        overflow: hidden;
    }

    .progress-bar {
        height: 100%;
        background: var(--white);
        width: 30%;
        animation: progress 2s ease-in-out infinite;
    }

    @keyframes progress {
        0% { width: 10%; }
        50% { width: 80%; }
        100% { width: 100%; }
    }

    /* ============================================
       GLOBAL STYLES
       ============================================ */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        color: var(--text-primary);
        background: var(--bg-gray);
        line-height: 1.6;
        font-size: 14px;
    }

    /* Standardisasi heading sizes */
    h1 { font-size: 1.75em !important; }
    h2 { font-size: 1.35em !important; }
    h3 { font-size: 1.15em !important; }
    h4 { font-size: 1em !important; }
    h5 { font-size: 0.95em !important; }
    h6 { font-size: 0.90em !important; }
    
    /* Standardisasi form elements */
    .form-label { font-size: 0.85em !important; font-weight: 500; }
    .form-control, .form-select { font-size: 0.85em !important; }
    
    /* Standardisasi tables */
    .table { font-size: 0.85em !important; }
    .table thead th { font-size: 0.80em !important; }
    
    /* Standardisasi buttons */
    .btn { font-size: 0.85em !important; }
    .btn-sm { font-size: 0.80em !important; }
    
    /* Standardisasi text */
    p { font-size: 0.85em !important; }
    small { font-size: 0.80em !important; }

    /* ============================================
       THEME COLORS & UTILITIES
       ============================================ */
    .bg-primary { background-color: var(--primary-color) !important; }
    .bg-secondary { background-color: var(--secondary-color) !important; }
    .text-primary { color: var(--primary-color) !important; }
    .text-secondary { color: var(--secondary-color) !important; }
    .text-muted { color: var(--text-muted) !important; }

    /* ============================================
       CARD STYLES
       ============================================ */
    .card {
        background: var(--white);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-sm);
        transition: box-shadow 0.2s ease;
    }

    .card:hover {
        box-shadow: var(--shadow);
    }

    .card-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: var(--white);
        padding: 20px;
        border: none;
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
    }

    .card-header h1,
    .card-header h2,
    .card-header h3,
    .card-header h4,
    .card-header h5,
    .card-header h6,
    .card-header p,
    .card-header span,
    .card-header small,
    .card-header strong,
    .card-header label,
    .card-header i {
        color: inherit !important;
    }

    .card-body {
        padding: 20px;
        color: var(--text-primary);
    }

    .card-body h1,
    .card-body h2,
    .card-body h3,
    .card-body h4,
    .card-body h5,
    .card-body h6,
    .card-body p,
    .card-body span,
    .card-body small,
    .card-body strong,
    .card-body label,
    .card-body li,
    .card-footer,
    .card-footer h1,
    .card-footer h2,
    .card-footer h3,
    .card-footer h4,
    .card-footer h5,
    .card-footer h6,
    .card-footer p,
    .card-footer span,
    .card-footer small,
    .card-footer strong,
    .card-footer label,
    .card-footer li {
        color: inherit;
        opacity: 1 !important;
    }

    .modal-header {
        background: #f8fafc !important;
        color: #1e293b !important;
        border-bottom: 1px solid #e2e8f0 !important;
    }

    .modal-header .modal-title,
    .modal-header h1,
    .modal-header h2,
    .modal-header h3,
    .modal-header h4,
    .modal-header h5,
    .modal-header h6,
    .modal-header p,
    .modal-header span,
    .modal-header small,
    .modal-header strong,
    .modal-header label,
    .modal-header i {
        color: inherit !important;
        opacity: 1 !important;
    }

    .modal-body,
    .modal-body h1,
    .modal-body h2,
    .modal-body h3,
    .modal-body h4,
    .modal-body h5,
    .modal-body h6,
    .modal-body p,
    .modal-body span,
    .modal-body small,
    .modal-body strong,
    .modal-body label,
    .modal-body li {
        color: #1e293b;
        opacity: 1 !important;
    }

    /* ============================================
       BUTTON STYLES
       ============================================ */
    .btn {
        border-radius: var(--radius);
        font-weight: 600;
        padding: 8px 16px;
        font-size: 14px;
        transition: all 0.2s ease;
    }

    .btn-primary {
        background: var(--primary-color);
        border-color: var(--primary-color);
        color: var(--white);
    }

    .btn-primary:hover {
        background: var(--accent-color);
        border-color: var(--accent-color);
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }

    .btn-secondary {
        background: var(--secondary-color);
        border-color: var(--secondary-color);
        color: var(--white);
    }

    .btn-secondary:hover {
        background: var(--primary-color);
        border-color: var(--primary-color);
        transform: translateY(-2px);
    }

    /* ============================================
       NAVBAR & SIDEBAR
       ============================================ */
    .navbar {
        background: var(--white);
        border-bottom: 1px solid var(--border-color);
        box-shadow: var(--shadow-sm);
        padding: 12px 0;
    }

    .sidenav {
        background: var(--white);
        border-right: 1px solid var(--border-color);
    }

    .nav-link {
        color: var(--text-primary);
        padding: 10px 16px;
        border-radius: var(--radius);
        transition: all 0.2s ease;
        margin: 4px 8px;
    }

    .nav-link:hover {
        background: var(--bg-light);
        color: var(--primary-color);
    }

    .nav-link.active {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: var(--white);
    }

    /* ============================================
       FORM ELEMENTS
       ============================================ */
    .form-control,
    .form-select {
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        padding: 8px 12px;
        font-size: 14px;
        transition: border-color 0.2s ease;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        outline: none;
    }

    /* ============================================
       TABLE STYLES
       ============================================ */
    .table {
        color: var(--text-primary);
        font-size: 14px;
    }

    .table thead th {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: var(--white);
        border: none;
        padding: 12px;
        font-weight: 600;
    }

    .table tbody tr {
        border-bottom: 1px solid var(--border-color);
        transition: background 0.2s ease;
    }

    .table tbody tr:hover {
        background: var(--bg-light);
    }

    /* ============================================
       MODAL STYLES
       ============================================ */
    .modal-content {
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
    }

    .modal-header {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        color: var(--white);
        border: none;
        padding: 20px;
    }

    .modal-title {
        font-weight: 700;
        font-size: 0.95em;
    }

    /* ============================================
       BADGE & ALERT
       ============================================ */
    .badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }

    .badge-primary { background: var(--primary-color); color: var(--white); }
    .badge-success { background: var(--success); color: var(--white); }
    .badge-warning { background: var(--warning); color: var(--text-primary); }
    .badge-danger { background: var(--danger); color: var(--white); }

    .alert {
        border: 1px solid;
        border-radius: var(--radius);
        padding: 12px 16px;
        margin-bottom: 16px;
        font-size: 0.85em;
    }

    .alert-primary { background: rgba(37, 99, 235, 0.1); border-color: var(--primary-color); color: var(--text-primary); }
    .alert-success { background: rgba(16, 185, 129, 0.1); border-color: var(--success); color: var(--text-primary); }
    .alert-warning { background: rgba(245, 158, 11, 0.1); border-color: var(--warning); color: var(--text-primary); }
    .alert-danger { background: rgba(239, 68, 68, 0.1); border-color: var(--danger); color: var(--text-primary); }

    /* ============================================
       RESPONSIVE DESIGN
       ============================================ */
    @media (max-width: 768px) {
        .btn { padding: 6px 12px; font-size: 13px; }
        .card-body { padding: 15px; }
        .modal-header { padding: 15px; }
        .table { font-size: 12px; }
    }

    @media (max-width: 480px) {
        .loading-text { font-size: 14px; }
        .spinner { width: 40px; height: 40px; }
        .loading-logo { width: 80px; height: 80px; }
    }

    /* ============================================
       DARK THEME - COMPREHENSIVE VISIBILITY FIX
       ============================================ */
    body.app-theme-dark .form-control,
    body.app-theme-dark .form-select,
    body.app-theme-dark textarea {
        background-color: #1a233a !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .card {
        background: #0f172a !important;
        border-color: rgba(148, 163, 184, 0.22) !important;
    }

    body.app-theme-dark .card-header {
        background: #1f2937 !important;
        color: #e5e7eb !important;
        border-bottom: 1px solid rgba(148, 163, 184, 0.22) !important;
    }

    body.app-theme-dark .card-header h1,
    body.app-theme-dark .card-header h2,
    body.app-theme-dark .card-header h3,
    body.app-theme-dark .card-header h4,
    body.app-theme-dark .card-header h5,
    body.app-theme-dark .card-header h6,
    body.app-theme-dark .card-header p,
    body.app-theme-dark .card-header span,
    body.app-theme-dark .card-header small,
    body.app-theme-dark .card-header strong,
    body.app-theme-dark .card-header label,
    body.app-theme-dark .card-header i {
        color: #e5e7eb !important;
    }

    body.app-theme-dark .card-body,
    body.app-theme-dark .card-footer {
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .card-body h1,
    body.app-theme-dark .card-body h2,
    body.app-theme-dark .card-body h3,
    body.app-theme-dark .card-body h4,
    body.app-theme-dark .card-body h5,
    body.app-theme-dark .card-body h6,
    body.app-theme-dark .card-body p,
    body.app-theme-dark .card-body span,
    body.app-theme-dark .card-body small,
    body.app-theme-dark .card-body strong,
    body.app-theme-dark .card-body label,
    body.app-theme-dark .card-body li,
    body.app-theme-dark .card-footer h1,
    body.app-theme-dark .card-footer h2,
    body.app-theme-dark .card-footer h3,
    body.app-theme-dark .card-footer h4,
    body.app-theme-dark .card-footer h5,
    body.app-theme-dark .card-footer h6,
    body.app-theme-dark .card-footer p,
    body.app-theme-dark .card-footer span,
    body.app-theme-dark .card-footer small,
    body.app-theme-dark .card-footer strong,
    body.app-theme-dark .card-footer label,
    body.app-theme-dark .card-footer li {
        color: #e2e8f0 !important;
        opacity: 1 !important;
    }

    body.app-theme-dark .card-body .text-muted,
    body.app-theme-dark .card-footer .text-muted,
    body.app-theme-dark .modal-body .text-muted,
    body.app-theme-dark .modal-header .text-muted {
        color: #cbd5e1 !important;
    }

    body.app-theme-dark .modal-content {
        background-color: #0f172a !important;
        border-color: rgba(148, 163, 184, 0.22) !important;
    }

    body.app-theme-dark .modal-header {
        background-color: #1f2937 !important;
        color: #e5e7eb !important;
        border-bottom: 1px solid rgba(148, 163, 184, 0.22) !important;
    }

    body.app-theme-dark .modal-body,
    body.app-theme-dark .modal-body h1,
    body.app-theme-dark .modal-body h2,
    body.app-theme-dark .modal-body h3,
    body.app-theme-dark .modal-body h4,
    body.app-theme-dark .modal-body h5,
    body.app-theme-dark .modal-body h6,
    body.app-theme-dark .modal-body p,
    body.app-theme-dark .modal-body span,
    body.app-theme-dark .modal-body small,
    body.app-theme-dark .modal-body strong,
    body.app-theme-dark .modal-body label,
    body.app-theme-dark .modal-body li {
        color: #e2e8f0 !important;
        opacity: 1 !important;
    }

    body.app-theme-dark .modal-footer {
        background-color: #0f172a !important;
        border-top: 1px solid rgba(148, 163, 184, 0.22) !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .form-control:focus,
    body.app-theme-dark .form-select:focus,
    body.app-theme-dark textarea:focus {
        background-color: #1a233a !important;
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important;
        color: #f1f5f9 !important;
    }

    body.app-theme-dark .form-control::placeholder,
    body.app-theme-dark textarea::placeholder {
        color: #94a3b8 !important;
    }

    body.app-theme-dark .form-check-input {
        background-color: #1a233a !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
    }

    body.app-theme-dark .form-check-input:checked {
        background-color: #3b82f6 !important;
        border-color: #3b82f6 !important;
    }

    /* System Log Styling in Dark Mode */
    body.app-theme-dark .log-container .list-group-item {
        background-color: #1a233a !important;
        border: 1px solid rgba(59, 130, 246, 0.2) !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .log-container .list-group-item:hover {
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-color: #3b82f6 !important;
        color: #f1f5f9 !important;
    }

    body.app-theme-dark .log-container .list-group-item.text-muted {
        color: #cbd5e1 !important;
    }

    /* Alerts in Dark Mode */
    body.app-theme-dark .alert {
        background-color: #1a233a !important;
        border-color: rgba(59, 130, 246, 0.2) !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .alert-primary {
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-color: #3b82f6 !important;
        color: #e0f2fe !important;
    }

    body.app-theme-dark .alert-success {
        background-color: rgba(16, 185, 129, 0.15) !important;
        border-color: #10b981 !important;
        color: #d1fae5 !important;
    }

    body.app-theme-dark .alert-warning {
        background-color: rgba(245, 158, 11, 0.15) !important;
        border-color: #f59e0b !important;
        color: #fef3c7 !important;
    }

    body.app-theme-dark .alert-danger {
        background-color: rgba(239, 68, 68, 0.15) !important;
        border-color: #ef4444 !important;
        color: #fee2e2 !important;
    }

    /* Buttons in Dark Mode */
    body.app-theme-dark .btn-outline-secondary,
    body.app-theme-dark .btn-light {
        background-color: #1a233a !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .btn-outline-secondary:hover,
    body.app-theme-dark .btn-light:hover {
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-color: #3b82f6 !important;
        color: #f1f5f9 !important;
    }

    body.app-theme-dark .btn-secondary {
        background-color: #1a233a !important;
        border-color: #3b82f6 !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .btn-secondary:hover {
        background-color: rgba(59, 130, 246, 0.2) !important;
        color: #f1f5f9 !important;
    }

    /* Input Group in Dark Mode */
    body.app-theme-dark .input-group-text {
        background-color: #1a233a !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
        color: #e2e8f0 !important;
    }

    /* Dropdown Menu in Dark Mode */
    body.app-theme-dark .dropdown-menu {
        background-color: #0f172a !important;
        border-color: rgba(59, 130, 246, 0.2) !important;
    }

    body.app-theme-dark .dropdown-item {
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .dropdown-item:hover,
    body.app-theme-dark .dropdown-item:focus {
        background-color: rgba(59, 130, 246, 0.15) !important;
        color: #f1f5f9 !important;
    }

    /* Modal in Dark Mode */
    body.app-theme-dark .modal-header {
        background-color: #0f172a !important;
        border-color: rgba(59, 130, 246, 0.2) !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .modal-body {
        background-color: #0f172a !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .modal-footer {
        background-color: #0f172a !important;
        border-color: rgba(59, 130, 246, 0.2) !important;
    }

    /* Tab Navigation in Dark Mode */
    body.app-theme-dark .nav-tabs {
        border-color: rgba(59, 130, 246, 0.2) !important;
    }

    body.app-theme-dark .nav-tabs .nav-link {
        color: #cbd5e1 !important;
    }

    body.app-theme-dark .nav-tabs .nav-link:hover {
        color: #e2e8f0 !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
    }

    body.app-theme-dark .nav-tabs .nav-link.active {
        color: #f1f5f9 !important;
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-color: #3b82f6 !important;
    }

    /* Badge in Dark Mode */
    body.app-theme-dark .badge {
        color: #f1f5f9 !important;
    }

    body.app-theme-dark .badge-light {
        background-color: #1a233a !important;
        color: #e2e8f0 !important;
    }

    /* Label in Dark Mode */
    body.app-theme-dark .form-label {
        color: #e2e8f0 !important;
    }

    /* Select Options in Dark Mode */
    body.app-theme-dark select option {
        background-color: #0f172a !important;
        color: #e2e8f0 !important;
    }

    /* Placeholder Text in Dark Mode */
    body.app-theme-dark .form-control::-webkit-input-placeholder {
        color: #94a3b8 !important;
    }

    body.app-theme-dark .form-control::-moz-placeholder {
        color: #94a3b8 !important;
    }

    body.app-theme-dark .form-control:-ms-input-placeholder {
        color: #94a3b8 !important;
    }

    body.app-theme-dark .form-control::-ms-input-placeholder {
        color: #94a3b8 !important;
    }

    /* Link in Dark Mode */
    body.app-theme-dark a {
        color: #60a5fa !important;
    }

    body.app-theme-dark a:hover {
        color: #93c5fd !important;
    }

    /* Strong & Bold Text in Dark Mode */
    body.app-theme-dark strong,
    body.app-theme-dark b {
        color: #f1f5f9 !important;
    }

    /* Separator/Border in Dark Mode */
    body.app-theme-dark hr {
        border-color: rgba(59, 130, 246, 0.2) !important;
    }

    /* Code in Dark Mode */
    body.app-theme-dark code,
    body.app-theme-dark pre {
        background-color: #1a233a !important;
        color: #e2e8f0 !important;
        border-color: rgba(59, 130, 246, 0.2) !important;
    }

    /* ============================================
       CARD & CARD-HEADER IN DARK MODE
       ============================================ */
    /* Default card header with gradient */
    body.app-theme-dark .card-header {
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0.1) 100%) !important;
        border-bottom: 2px solid rgba(59, 130, 246, 0.3) !important;
        color: #f1f5f9 !important;
    }

    /* Card header with specific colors - ensure visibility */
    body.app-theme-dark .card-header.bg-primary {
        background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%) !important;
        color: #ffffff !important;
    }

    body.app-theme-dark .card-header.bg-secondary {
        background: linear-gradient(135deg, #64748b 0%, #94a3b8 100%) !important;
        color: #ffffff !important;
    }

    body.app-theme-dark .card-header.bg-info {
        background: linear-gradient(135deg, #0ea5e9 0%, #06b6d4 100%) !important;
        color: #ffffff !important;
    }

    body.app-theme-dark .card-header.bg-success {
        background: linear-gradient(135deg, #10b981 0%, #14b8a6 100%) !important;
        color: #ffffff !important;
    }

    body.app-theme-dark .card-header.bg-warning {
        background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%) !important;
        color: #1f2937 !important;
    }

    body.app-theme-dark .card-header.bg-danger {
        background: linear-gradient(135deg, #ef4444 0%, #f87171 100%) !important;
        color: #ffffff !important;
    }

    /* Card header with light background - make visible in dark mode */
    body.app-theme-dark .card-header.bg-light {
        background: linear-gradient(135deg, #1a233a 0%, #0f172a 100%) !important;
        border-bottom: 2px solid rgba(59, 130, 246, 0.3) !important;
        color: #e2e8f0 !important;
    }

    /* Text utilities in dark mode card headers */
    body.app-theme-dark .card-header.text-white {
        color: #ffffff !important;
    }

    body.app-theme-dark .card-header.text-dark {
        color: #1f2937 !important;
    }

    /* Card body in dark mode */
    body.app-theme-dark .card {
        background-color: #0f172a !important;
        border-color: rgba(59, 130, 246, 0.2) !important;
    }

    body.app-theme-dark .card-body {
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .card-footer {
        background-color: rgba(59, 130, 246, 0.08) !important;
        border-color: rgba(59, 130, 246, 0.2) !important;
        color: #e2e8f0 !important;
    }

    /* ============================================
       GLOBAL TEXT & BUTTON VISIBILITY - DARK MODE
       ============================================ */
    /* Text utilities */
    body.app-theme-dark .text-muted {
        color: #cbd5e1 !important;
    }

    body.app-theme-dark .text-secondary {
        color: #94a3b8 !important;
    }

    body.app-theme-dark .text-dark {
        color: #e2e8f0 !important;
    }

    /* Ensure all text is visible */
    body.app-theme-dark h1, body.app-theme-dark h2, body.app-theme-dark h3,
    body.app-theme-dark h4, body.app-theme-dark h5, body.app-theme-dark h6,
    body.app-theme-dark p, body.app-theme-dark span, body.app-theme-dark small,
    body.app-theme-dark label {
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .fw-bold, body.app-theme-dark .font-weight-bold {
        color: #f1f5f9 !important;
    }

    /* Button visibility */
    body.app-theme-dark .btn-primary {
        background-color: #2563eb !important;
        border-color: #2563eb !important;
    }

    body.app-theme-dark .btn-primary:hover {
        background-color: #1d4ed8 !important;
        border-color: #1d4ed8 !important;
    }

    body.app-theme-dark .btn-success {
        background-color: #10b981 !important;
        border-color: #10b981 !important;
    }

    body.app-theme-dark .btn-success:hover {
        background-color: #059669 !important;
        border-color: #059669 !important;
    }

    body.app-theme-dark .btn-warning {
        background-color: #f59e0b !important;
        border-color: #f59e0b !important;
        color: #1f2937 !important;
    }

    body.app-theme-dark .btn-warning:hover {
        background-color: #d97706 !important;
        border-color: #d97706 !important;
    }

    body.app-theme-dark .btn-danger {
        background-color: #ef4444 !important;
        border-color: #ef4444 !important;
    }

    body.app-theme-dark .btn-danger:hover {
        background-color: #dc2626 !important;
        border-color: #dc2626 !important;
    }

    body.app-theme-dark .btn-info {
        background-color: #0ea5e9 !important;
        border-color: #0ea5e9 !important;
    }

    body.app-theme-dark .btn-info:hover {
        background-color: #0284c7 !important;
        border-color: #0284c7 !important;
    }

    body.app-theme-dark .btn-light {
        background-color: #1a233a !important;
        border-color: rgba(59, 130, 246, 0.3) !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .btn-light:hover {
        background-color: rgba(59, 130, 246, 0.15) !important;
        border-color: #3b82f6 !important;
        color: #f1f5f9 !important;
    }

    /* Table visibility improvements */
    body.app-theme-dark .table-light {
        background-color: #1a233a !important;
        color: #e2e8f0 !important;
    }

    body.app-theme-dark .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(59, 130, 246, 0.05) !important;
    }

    body.app-theme-dark .table-hover tbody tr:hover {
        background-color: rgba(59, 130, 246, 0.1) !important;
    }

    /* Badges visibility */
    body.app-theme-dark .badge-primary {
        background-color: #2563eb !important;
    }

    body.app-theme-dark .badge-success {
        background-color: #10b981 !important;
    }

    body.app-theme-dark .badge-warning {
        background-color: #f59e0b !important;
        color: #1f2937 !important;
    }

    body.app-theme-dark .badge-danger {
        background-color: #ef4444 !important;
    }

    body.app-theme-dark .badge-info {
        background-color: #0ea5e9 !important;
    }

    body.app-theme-dark .badge-secondary {
        background-color: #64748b !important;
    }

    /* ============================================
       MODAL SCROLL CONTAINMENT
       ============================================ */
    /* Prevent body scroll when modal is open */
    body.modal-open {
        overflow: hidden !important;
    }

    /* Modal dialog scroll containment */
    .modal {
        overflow: hidden !important;
    }

    .modal.show {
        overflow: auto !important;
        overflow-y: auto !important;
    }

    .modal-dialog {
        max-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    .modal-content {
        display: flex;
        flex-direction: column;
        max-height: 100vh;
    }

    .modal-body {
        flex: 1;
        overflow-y: auto !important;
        overflow-x: clip;
        -webkit-overflow-scrolling: touch;
    }

    /* Allow horizontal scroll for table containers inside modal */
    .modal-body .table-responsive {
        overflow-x: scroll !important;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
    }

    .modal-header,
    .modal-footer {
        flex-shrink: 0;
    }

    /* Fix btn-close icon: Soft UI theme uses white SVG, invert for light modal headers */
    .modal .modal-header .btn-close {
        filter: invert(1) !important;
        opacity: 1 !important;
    }
    .modal .modal-header .btn-close.btn-close-white {
        filter: none !important;
        opacity: 1 !important;
    }
    body.app-theme-dark .modal .modal-header .btn-close {
        filter: none !important;
        opacity: 1 !important;
    }

    /* Ensure modal body scrolls independently */
    .modal-body::-webkit-scrollbar {
        width: 8px;
    }

    .modal-body::-webkit-scrollbar-track {
        background: transparent;
    }

    .modal-body::-webkit-scrollbar-thumb {
        background: var(--primary-color);
        border-radius: 4px;
    }

    .modal-body::-webkit-scrollbar-thumb:hover {
        background: var(--secondary-color);
    }

    /* Dark mode scrollbar */
    body.app-theme-dark .modal-body::-webkit-scrollbar-thumb {
        background: rgba(59, 130, 246, 0.5);
    }

    body.app-theme-dark .modal-body::-webkit-scrollbar-thumb:hover {
        background: rgba(59, 130, 246, 0.8);
    }

    /* Iframe within modal should have scroll */
    .modal-body iframe {
        overflow: auto !important;
        max-height: calc(100vh - 200px);
    }

    /* Custom overlay modal scroll containment */
    .qts-modal-overlay {
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .qts-modal-card {
        max-height: calc(100vh - 32px);
        overflow-y: auto;
        overscroll-behavior: contain;
    }
</style>


<body class="<?php echo $embed_mode ? 'bg-gray-100' : 'g-sidenav-show bg-gray-100'; ?>">
  <?php if (!$embed_mode): ?>
  <?php require 'sidebar.php'; ?>
  <?php
    // Widget global CS Call Center (heartbeat + panggilan masuk) -- HARUS
    // di-include di SINI (semua halaman billing yang sudah login), BUKAN
    // cuma di cs_call_center.php -- lihat docblock cs_call_center_agent_widget.php.
    // Sebelum ini tidak pernah benar-benar ke-wire ke header.php, jadi
    // heartbeat "agent aktif" cuma ke-update selama staff sedang membuka
    // halaman Call Center itu sendiri -- begitu pindah menu, heartbeat basi
    // dlm 45 detik dan tombol Call di sisi pelanggan ikut hilang-timbul
    // padahal toggle Agent Aktif-nya tetap ON (laporan user 2026-08-15).
    // Patokannya sekarang murni: sudah login sbg role agent (ADMIN/USER/
    // ASSISTANT) + fitur CS Call Center aktif utk owner ini -- BUKAN sedang
    // di halaman mana.
    $_csccIsAgentRole = in_array($AKSES ?? '', ['ASSISTANT', 'USER', 'ADMIN'], true);
    if ($_csccIsAgentRole) {
        require_once __DIR__ . '/cs_call_center_helper.php';
        $_csccWidgetOwnerKey = csCallCenterScopeKey($AKSES, $ceknama);
        if (csCallCenterIsFeatureEnabled($conn, $_csccWidgetOwnerKey)) {
            require __DIR__ . '/cs_call_center_agent_widget.php';
        }
    }
  ?>
  <?php endif; ?>

<!-- Loading Animation Script & Logo Color Extract -->
<script>
// Loading progress (tetap seperti semula)
document.addEventListener('DOMContentLoaded', function() {
    let progress = 0;
    const progressBar = document.querySelector('.progress-bar');
    const progressInterval = setInterval(() => {
        progress += Math.random() * 12;
        if (progress >= 100) {
            progress = 100;
            clearInterval(progressInterval);
            setTimeout(() => { hideLoadingScreen(); }, 800);
        }
        if (progressBar) progressBar.style.width = progress + '%';
    }, 250);
});
function hideLoadingScreen() {
    const loadingScreen = document.getElementById('loading');
    if (loadingScreen && !loadingScreen.classList.contains('fade-out')) {
        loadingScreen.classList.add('fade-out');
        setTimeout(() => {
            if (loadingScreen && loadingScreen.parentNode) {
                loadingScreen.parentNode.removeChild(loadingScreen);
            }
        }, 800);
    }
}
window.addEventListener('load', function() { setTimeout(() => { hideLoadingScreen(); }, 1000); });
setTimeout(() => { hideLoadingScreen(); }, 8000);
let forceHideTimeout = setTimeout(() => { forceRemoveLoadingScreen(); }, 10000);
function forceRemoveLoadingScreen() {
    const loadingScreen = document.getElementById('loading');
    if (loadingScreen && loadingScreen.parentNode) loadingScreen.parentNode.removeChild(loadingScreen);
}
document.addEventListener('DOMContentLoaded', function() {
    window.addEventListener('load', function() { clearTimeout(forceHideTimeout); });
});

// === Logo color extract (adaptasi panel) ===
function extractColorsFromLogo() {
    const logoImg = document.getElementById('main-logo-img');
    if (!logoImg || !logoImg.src) return;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    logoImg.crossOrigin = 'anonymous';
    logoImg.onload = function() {
        try {
            canvas.width = this.naturalWidth || this.width || 200;
            canvas.height = this.naturalHeight || this.height || 200;
            ctx.drawImage(this, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const colors = analyzeImageColors(imageData);
            if (colors && colors.length > 0) applyLogoColors(colors);
        } catch (e) { console.log('Logo color extract error', e); }
    };
    if (logoImg.complete && logoImg.naturalWidth !== 0) logoImg.onload();
}
function analyzeImageColors(imageData) {
    const data = imageData.data, colorCounts = {};
    for (let i = 0; i < data.length; i += 12) {
        const r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
        if (a < 128 || (r > 240 && g > 240 && b > 240) || (r < 15 && g < 15 && b < 15)) continue;
        const max = Math.max(r,g,b), min = Math.min(r,g,b), sat = max-min;
        if (sat > 30) {
            const color = `${r},${g},${b}`;
            colorCounts[color] = (colorCounts[color]||0)+1;
        }
    }
    return Object.entries(colorCounts).sort(([,a],[,b])=>b-a).slice(0,2).map(([c])=>{
        const [r,g,b]=c.split(',').map(Number);
        return {r,g,b,hex:rgbToHex(r,g,b)};
    });
}
function rgbToHex(r,g,b){return "#"+[r,g,b].map(x=>{const h=x.toString(16);return h.length==1?"0"+h:h}).join("");}
let logoColorAlreadyCached=false;
function applyLogoColors(colors){
    const root=document.documentElement;
    if(colors[0]){
        root.style.setProperty('--logo-primary',colors[0].hex);
        root.style.setProperty('--primary-color',colors[0].hex);
    }
    if(colors[1]){
        root.style.setProperty('--logo-secondary',colors[1].hex);
        root.style.setProperty('--secondary-color',colors[1].hex);
    }
    // Simpan ke hidden input jika ada
    const p=document.getElementById('extracted_primary_color');if(p)p.value=colors[0]?.hex||'';
    const s=document.getElementById('extracted_secondary_color');if(s)s.value=colors[1]?.hex||'';

    // Kalau server BELUM punya cache warna utk akun ini (logo lama, sebelum
    // fitur cache server-side ada, atau GD gagal decode saat upload), simpan
    // hasil ekstraksi browser ini ke server supaya page-load berikutnya tidak
    // perlu ekstraksi ulang. Kalau sudah ada cache, tidak perlu kirim lagi.
    if(!logoColorAlreadyCached && colors[0] && colors[1]){
        fetch('proses/save_logo_colors.php',{
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'primary='+encodeURIComponent(colors[0].hex)+'&secondary='+encodeURIComponent(colors[1].hex)
        }).catch(()=>{});
    }
}
function loadSavedColors(){
    const p=document.getElementById('extracted_primary_color');
    const s=document.getElementById('extracted_secondary_color');
    const root=document.documentElement;
    if(p&&p.value && s&&s.value){
        logoColorAlreadyCached=true;
    }
    if(p&&p.value){
        root.style.setProperty('--logo-primary',p.value);
        root.style.setProperty('--primary-color',p.value);
    }
    if(s&&s.value){
        root.style.setProperty('--logo-secondary',s.value);
        root.style.setProperty('--secondary-color',s.value);
    }
}
window.addEventListener('DOMContentLoaded',function(){
    loadSavedColors();
    setTimeout(extractColorsFromLogo,100);
    setTimeout(extractColorsFromLogo,500);
});
</script>

<!-- Modal Scroll Containment Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    let lockedScrollY = 0;
    let isBodyScrollLocked = false;

    function getActiveBootstrapModal() {
        const list = Array.from(document.querySelectorAll('.modal.show'));
        return list.length ? list[list.length - 1] : null;
    }

    function getActiveCustomOverlay() {
        const list = Array.from(document.querySelectorAll('.qts-modal-overlay'));
        const visible = list.filter(function(el) {
            return el && el.style.display !== 'none' && getComputedStyle(el).display !== 'none';
        });
        return visible.length ? visible[visible.length - 1] : null;
    }

    function hasAnyActiveModal() {
        return !!getActiveBootstrapModal() || !!getActiveCustomOverlay();
    }

    function lockBackgroundScroll() {
        if (isBodyScrollLocked) return;
        lockedScrollY = window.scrollY || window.pageYOffset || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = '-' + lockedScrollY + 'px';
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        document.body.style.overflow = 'hidden';
        document.body.classList.add('modal-open');
        isBodyScrollLocked = true;
    }

    function unlockBackgroundScroll() {
        if (!isBodyScrollLocked) return;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        document.body.style.overflow = '';
        document.body.classList.remove('modal-open');
        window.scrollTo(0, lockedScrollY);
        isBodyScrollLocked = false;
    }

    function syncBackgroundScrollLock() {
        if (hasAnyActiveModal()) {
            lockBackgroundScroll();
        } else {
            unlockBackgroundScroll();
        }
    }

    // Handle Bootstrap 5 modal events
    document.addEventListener('show.bs.modal', function() {
        syncBackgroundScrollLock();
    });

    document.addEventListener('shown.bs.modal', function() {
        syncBackgroundScrollLock();
    });

    document.addEventListener('hidden.bs.modal', function() {
        syncBackgroundScrollLock();
    });

    // Pantau modal kustom (qts-modal-overlay) yang dibuka/tutup via style.display
    let syncPending = false;
    const observer = new MutationObserver(function() {
        if (syncPending) return;
        syncPending = true;
        requestAnimationFrame(function() {
            syncPending = false;
            syncBackgroundScrollLock();
        });
    });
    observer.observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['class', 'style']
    });

    // initial sync
    syncBackgroundScrollLock();

    function isScrollable(el) {
        if (!el) return false;
        const style = getComputedStyle(el);
        const canScrollY = style.overflowY === 'auto' || style.overflowY === 'scroll' || style.overflowY === 'overlay';
        return canScrollY && el.scrollHeight > el.clientHeight + 1;
    }

    function findScrollableParent(fromEl, stopEl) {
        let el = fromEl;
        while (el) {
            if (isScrollable(el)) {
                return el;
            }
            if (el === stopEl) break;
            el = el.parentElement;
        }
        return null;
    }

    function getScrollContainer(target, bootstrapModal, customOverlay) {
        if (bootstrapModal) {
            const fallback = bootstrapModal.querySelector('.modal-body') || bootstrapModal.querySelector('.modal-content') || bootstrapModal;
            const scoped = bootstrapModal.contains(target) ? findScrollableParent(target, bootstrapModal) : null;
            return scoped || fallback;
        }

        if (customOverlay) {
            const card = customOverlay.querySelector('.qts-modal-card') || customOverlay;
            const scoped = customOverlay.contains(target) ? findScrollableParent(target, customOverlay) : null;
            return scoped || card;
        }

        return null;
    }

    // Saat modal terbuka: wheel hanya menggerakkan konten di dalam modal aktif
    document.addEventListener('wheel', function(e) {
        const openModal = getActiveBootstrapModal();
        const openOverlay = openModal ? null : getActiveCustomOverlay();
        if (!openModal && !openOverlay) return;

        const container = getScrollContainer(e.target, openModal, openOverlay);
        if (container) {
            container.scrollTop += e.deltaY;
        }

        e.stopPropagation();
        e.preventDefault();
    }, { passive: false, capture: true });

    // Handle touch scroll containment for mobile
    let touchStartY = 0;
    let touchStartX = 0;

    function findHorizontalScrollableParent(fromEl, stopEl) {
        let el = fromEl;
        while (el) {
            const style = getComputedStyle(el);
            const ox = style.overflowX;
            if ((ox === 'auto' || ox === 'scroll') && el.scrollWidth > el.clientWidth + 1) {
                return el;
            }
            if (el === stopEl) break;
            el = el.parentElement;
        }
        return null;
    }

    document.addEventListener('touchstart', function(e) {
        touchStartY = e.touches[0].clientY;
        touchStartX = e.touches[0].clientX;
    }, { passive: true });

    document.addEventListener('touchmove', function(e) {
        const openModal = getActiveBootstrapModal();
        const openOverlay = openModal ? null : getActiveCustomOverlay();
        if (!openModal && !openOverlay) return;

        const touchY = e.touches[0].clientY;
        const touchX = e.touches[0].clientX;
        const deltaY = touchStartY - touchY;
        const deltaX = touchStartX - touchX;
        touchStartY = touchY;
        touchStartX = touchX;

        const stopEl = openModal || openOverlay;

        // If primarily horizontal gesture, handle horizontal scroll
        if (Math.abs(deltaX) > Math.abs(deltaY)) {
            const hContainer = findHorizontalScrollableParent(e.target, stopEl);
            if (hContainer) {
                hContainer.scrollLeft += deltaX;
                e.stopPropagation();
                e.preventDefault();
                return;
            }
        }

        const container = getScrollContainer(e.target, openModal, openOverlay);
        if (container) {
            container.scrollTop += deltaY;
        }

        e.stopPropagation();
        e.preventDefault();
    }, { passive: false });

    // Trap focus within modal (accessibility)
    document.addEventListener('keydown', function(e) {
        if (e.key !== 'Tab') return;
        
        const openModal = document.querySelector('.modal.show');
        if (!openModal) return;

        const focusableElements = openModal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        
        if (focusableElements.length === 0) return;

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        const activeElement = document.activeElement;

        if (e.shiftKey) {
            if (activeElement === firstElement) {
                lastElement.focus();
                e.preventDefault();
            }
        } else {
            if (activeElement === lastElement) {
                firstElement.focus();
                e.preventDefault();
            }
        }
    });
});
</script>
