<?php
// Detect current PHP file for sidebar highlighting
$current_file = basename($_SERVER['PHP_SELF']);
?>
<?php

require 'cek-sesi.php';
date_default_timezone_set('Asia/Jakarta');
// Teknisi-only: ASSISTANT with exactly ['Ticket_manager'] access
$is_teknisi_only = ($AKSES === 'ASSISTANT' && is_array($akses_menu) && count($akses_menu) === 1 && in_array('Ticket_manager', $akses_menu));
$can_assistant_dashboard = ($AKSES !== 'ASSISTANT') || (is_array($akses_menu) && in_array('Dasbor', $akses_menu));
$can_assistant_livechat = ($AKSES !== 'ASSISTANT') || (is_array($akses_menu) && in_array('Live_Chat', $akses_menu));
$sidebar_ticket_source = isset($ticket_management_source) ? strtolower(trim((string)$ticket_management_source)) : 'tiket_manager';
if (!in_array($sidebar_ticket_source, ['tiket_manager', 'joblist'], true)) {
  $sidebar_ticket_source = 'tiket_manager';
}
$show_ticket_manager_menu = $sidebar_ticket_source === 'tiket_manager';
$show_joblist_menu = $sidebar_ticket_source === 'joblist';
$theme_storage_key = 'billing_sidebar_theme_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$ceknama);

$url_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$url_host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
$brand_owner = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$ceknama);
$brand_query = $brand_owner !== '' ? ('?brand=' . rawurlencode($brand_owner)) : '';

if ($url_host !== '') {
  $base_public_url = $url_scheme . '://' . $url_host;
  $link_pendaftaran_anda = $base_public_url . '/crm/mitra/pendaftaran_bot.php' . $brand_query;
  $link_pelanggan_anda = $base_public_url . '/crm/billing/broadband/portallogin.php' . $brand_query;
  $link_login_anda = $base_public_url . '/crm/billing/index.php' . $brand_query;
  $link_login_hotspot_anda = $base_public_url . '/crm/login/index.html?server=' . rawurlencode($brand_owner !== '' ? $brand_owner : 'FIBERQ');
} else {
  $link_pendaftaran_anda = '../mitra/pendaftaran_bot.php' . $brand_query;
  $link_pelanggan_anda = '../billing/broadband/portallogin.php' . $brand_query;
  $link_login_anda = '../billing/index.php' . $brand_query;
  $link_login_hotspot_anda = '../login/index.html?server=' . rawurlencode($brand_owner !== '' ? $brand_owner : 'FIBERQ');
}

if (!function_exists('load_ui_visibility_settings')) {
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
}

$ui_settings_username = ($AKSES == 'ASSISTANT' && !empty($asistant_name)) ? $asistant_name : $ceknama;
$ui_visibility_settings = load_ui_visibility_settings($ui_settings_username);

// Define $today jika belum didefinisikan
if (!isset($today)) {
  $today = date('Y-m-d');
}

// Hitung sisa hari menuju expired
$sisa_hari = null;
if ($expired_at) {
  $sisa_hari = (strtotime($expired_at) - strtotime($today)) / (60 * 60 * 24);
  $sisa_hari = $sisa_hari > 0 ? ceil($sisa_hari) : 0;
}
?>
<script>
  (function() {
    if (window.__billingUiVisibilityScriptInjected) return;
    window.__billingUiVisibilityScriptInjected = true;
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
            'mynetworkmap': 'buttons_notification',
            'notification': 'buttons_notification',
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
          { key: 'btn_dash_refresh', selectors: 'button[onclick*="location.reload"]' }
        ],
        'server': [
          { key: 'btn_server_show_interface', selectors: '#showInterfaceForm button[type="submit"]' },
          { key: 'btn_server_add_data', selectors: 'button[data-bs-target="#dataModal"], .btn.btn-success.mt-3' },
          { key: 'btn_server_import', selectors: 'a[href="import_server.php"]' },
          { key: 'btn_server_export', selectors: 'a[href="export_server.php"]' }
        ],
        'olt': [
          { key: 'btn_olt_add_data', selectors: 'button[data-bs-target="#dataModal"]' },
          { key: 'btn_olt_import_data', selectors: 'button[data-bs-target="#importModal"], #importBtn' },
          { key: 'btn_olt_download_template', selectors: 'a[href="proses/download_template_olt.php"]' }
        ],
        'tables': [
          { key: 'btn_cust_add_customer', selectors: 'button[data-bs-target="#addCustomerModal"]' },
          { key: 'btn_cust_import_customer', selectors: 'a[href="importcustomerpppoe.php"]' },
          { key: 'btn_cust_scan_unregistered', selectors: '#btnScanUnregisteredPppoe' }
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
          { key: 'btn_tiket_create', selectors: 'button[name="create_ticket"]' }
        ],
        'notification': [
          { key: 'btn_notif_save_dynamic', selectors: 'button[name="simpan_pengaturan_salam_dinamis"]' },
          { key: 'btn_notif_save_invoice', selectors: 'button[name="simpan_invoice_generator"]' },
          { key: 'btn_notif_modal_save', selectors: '.modal-save-form button[type="submit"].btn-primary' }
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
  })();
</script>
<!-- Font Awesome 6 (CDN) -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">






<!-- Modal Popup tanpa backdrop -->
<div class="modal fade" id="iframeModal" tabindex="-1" aria-labelledby="iframeModalLabel" aria-hidden="true"
     data-bs-backdrop="false" data-bs-keyboard="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content border-0">
      
      <div class="modal-header">
        <h5 class="modal-title" id="iframeModalLabel"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>

      <div class="modal-body p-0" style="height:100%;">
        <iframe src="" id="iframeContent" frameborder="0" style="width:100%; height:100%;"></iframe>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fas fa-times me-2"></i>Tutup
        </button>
      </div>

    </div>
  </div>
</div>


<!-- Modal LINK ANDA -->
<div class="modal fade" id="linkAndaModal" tabindex="-1" aria-labelledby="linkAndaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-md">
    <div class="modal-content border-0">
      <div class="modal-header">
        <h5 class="modal-title" id="linkAndaModalLabel">LINK ANDA</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div class="d-grid gap-2">
          <a class="btn btn-outline-primary text-start" href="<?= htmlspecialchars($link_pendaftaran_anda, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
            <i class="fas fa-link me-2"></i>Link untuk Pendaftaran pelanggan Baru
          </a>
          <button type="button" class="btn btn-sm btn-light border quick-link-copy-btn link-anda-copy-btn" data-copy-url="<?= htmlspecialchars($link_pendaftaran_anda, ENT_QUOTES, 'UTF-8'); ?>">Copy URL Pendaftaran</button>

          <a class="btn btn-outline-primary text-start" href="<?= htmlspecialchars($link_pelanggan_anda, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
            <i class="fas fa-users me-2"></i>Link untuk Login menu Pelanggan
          </a>
          <button type="button" class="btn btn-sm btn-light border quick-link-copy-btn link-anda-copy-btn" data-copy-url="<?= htmlspecialchars($link_pelanggan_anda, ENT_QUOTES, 'UTF-8'); ?>">Copy URL Pelanggan</button>

          <a class="btn btn-outline-primary text-start" href="<?= htmlspecialchars($link_login_anda, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
            <i class="fas fa-sign-in-alt me-2"></i>Link LOGIN Billing anda
          </a>
          <button type="button" class="btn btn-sm btn-light border quick-link-copy-btn link-anda-copy-btn" data-copy-url="<?= htmlspecialchars($link_login_anda, ENT_QUOTES, 'UTF-8'); ?>">Copy URL LOGIN Billing</button>

          <a class="btn btn-outline-primary text-start" href="<?= htmlspecialchars($link_login_hotspot_anda, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
            <i class="fas fa-wifi me-2"></i>Link LOGIN Hotspot pelanggan anda
          </a>
          <button type="button" class="btn btn-sm btn-light border quick-link-copy-btn link-anda-copy-btn" data-copy-url="<?= htmlspecialchars($link_login_hotspot_anda, ENT_QUOTES, 'UTF-8'); ?>">Copy URL LOGIN Hotspot</button>
        </div>
      </div>
    </div>
  </div>
</div>









<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3 " id="sidenav-main">
  <div class="sidenav-header">
    <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>







    


<?php
// Logo terpusat di dokumen/logo/ (sejajar crm/). $logo_file = path filesystem
// untuk file_exists(), $logo_path = URL web untuk <img src>.
$logo_file = __DIR__ . "/../../dokumen/logo/profile-$ceknama.png";
$logo_path = "/dokumen/logo/profile-$ceknama.png";

// Kalau logo tidak ada, pakai default
if (!file_exists($logo_file)) {
  $logo_path = "/dokumen/logo/logo.png";
}
?>

<!-- Bagian Header Logo -->
<div class="sidenav-header text-center pb-2">
  <a class="navbar-brand" href="#">
    <img src="<?= $logo_path ?>?v=<?= time() ?>" 
         class="img-fluid sidebar-logo-img" 
         alt="Logo" 
         style="max-height: 80px;">
  </a>
</div>
<br>
<hr class="horizontal dark mt-2 mb-3">




<!-- Jam Digital 8-Segment + Tanggal -->
<div class="digital-container">
  <div id="dateDisplay" class="date-display"></div>
  <div id="digitalClock" class="digital-clock"></div>
</div>
<style>


.digital-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    background: var(--white);
    border-radius: 12px;
    padding: 15px;
    margin: 10px 0;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    border: 2px solid var(--logo-secondary);
}

/* Tanggal - TULISAN HIJAU */
.date-display {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: var(--green);
    font-weight: 600;
    text-align: center;
}

/* Jam digital 8-segment */
.digital-clock {
    display: flex;
    gap: 4px;
    justify-content: center;
    align-items: center;
}

.digit {
    position: relative;
    width: 20px;
    height: 24px;
}

.segment {
    position: absolute;
    background: rgba(37, 99, 235, 0.25); /* segmen mati - biru samar */
    border-radius: 2px;
    transition: all 0.3s ease;
}

.segment.on {
    background: var(--logo-secondary); /* segmen aktif - biru terang */
    box-shadow: 0 0 12px rgba(59, 130, 246, 0.6);
}

/* Posisi segmen */
.a { top: 0; left: 3px; right: 3px; height: 3px; }
.b { top: 3px; right: 0; width: 3px; height: 9px; }
.c { bottom: 3px; right: 0; width: 3px; height: 9px; }
.d { bottom: 0; left: 3px; right: 3px; height: 3px; }
.e { bottom: 3px; left: 0; width: 3px; height: 9px; }
.f { top: 3px; left: 0; width: 3px; height: 9px; }
.g { top: 11px; left: 3px; right: 3px; height: 3px; }

.colon {
    width: 6px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 5px;
}

.colon div {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: var(--logo-secondary); /* COLON BIRU */
    box-shadow: 0 0 8px rgba(59, 130, 246, 0.6);
}

.sidebar-logo-img {
  transition: filter .25s ease, drop-shadow .25s ease;
}

.app-theme-dark .sidebar-logo-img {
  filter: brightness(0) invert(1) contrast(1.08) drop-shadow(0 0 8px rgba(255,255,255,0.22));
}
</style>
<script>
  // Jam mengikuti waktu server (Ubuntu), bukan jam lokal browser client.
  // Ambil timestamp server sekali saat halaman dimuat, lalu jalan sendiri
  // per detik disinkronkan ke selisih waktu sejak halaman dimuat.
  window.__serverTimeMs = <?php echo (int) (microtime(true) * 1000); ?>;
  window.__clientLoadMs = Date.now();
  function getServerNow() {
    return new Date(window.__serverTimeMs + (Date.now() - window.__clientLoadMs));
  }

  const digitMap = {
    0: ['a','b','c','d','e','f'],
    1: ['b','c'],
    2: ['a','b','d','e','g'],
    3: ['a','b','c','d','g'],
    4: ['b','c','f','g'],
    5: ['a','c','d','f','g'],
    6: ['a','c','d','e','f','g'],
    7: ['a','b','c'],
    8: ['a','b','c','d','e','f','g'],
    9: ['a','b','c','d','f','g']
  };

  const clock = document.getElementById('digitalClock');
  const dateDisplay = document.getElementById('dateDisplay');

  function createDigit() {
    const d = document.createElement('div');
    d.className = 'digit';
    ['a','b','c','d','e','f','g'].forEach(seg => {
      const s = document.createElement('div');
      s.className = 'segment ' + seg;
      d.appendChild(s);
    });
    return d;
  }

  const digits = [];
  for (let i = 0; i < 8; i++) {
    if (i === 2 || i === 5) {
      const colon = document.createElement('div');
      colon.className = 'colon';
      colon.innerHTML = '<div></div><div></div>';
      clock.appendChild(colon);
    } else {
      const digit = createDigit();
      digits.push(digit);
      clock.appendChild(digit);
    }
  }

  function updateClock() {
    const now = getServerNow();

    // Update tanggal
    const dd = String(now.getDate()).padStart(2,'0');
    const mm = String(now.getMonth()+1).padStart(2,'0');
    const yyyy = now.getFullYear();
    dateDisplay.textContent = `${dd}/${mm}/${yyyy}`;

    // Update jam digital
    const timeStr = now.toTimeString().split(' ')[0]; // HH:MM:SS
    const numbers = timeStr.replace(/:/g, '').split('');
    digits.forEach((digit, i) => {
      const num = numbers[i];
      const active = digitMap[num];
      digit.querySelectorAll('.segment').forEach(seg => {
        seg.classList.toggle('on', active.includes(seg.classList[1]));
      });
    });
  }

  updateClock();
  setInterval(updateClock, 1000);
</script>




<!-- Search Menu -->
<div class="menu-search-container" style="padding: 10px;">
  <input type="text" id="menuSearch" placeholder="Cari menu..." class="form-control" style="width: 100%; border-radius: 8px; border: 1px solid #ddd; padding: 8px 12px;">
</div>

<!-- Theme Slider -->
<div class="theme-slider-container px-3 pb-2">
  <label class="theme-slider-title">Tema</label>
  <div class="theme-button-grid" id="sidebarThemeButtons" role="group" aria-label="Pilihan tema sidebar">
    <button type="button" class="theme-square-btn" data-theme-value="default">Default</button>
    <button type="button" class="theme-square-btn" data-theme-value="terang">Terang</button>
    <button type="button" class="theme-square-btn" data-theme-value="dark">Dark</button>
  </div>
</div>

<script>
  // Sembunyikan header grup yang memang tidak punya item sama sekali
  // (mis. grup yang kosong karena hak akses), terlepas dari status collapse.
  function hideEmptyMenuGroups() {
    const navbar = document.querySelector('.navbar-nav');
    if (!navbar) return;

    const allItems = navbar.querySelectorAll('li.nav-item');

    allItems.forEach(item => {
      const header = item.querySelector('h6');
      if (!header) return;

      let hasItems = false;
      let nextItem = item.nextElementSibling;

      while (nextItem) {
        if (nextItem.classList.contains('nav-item')) {
          const innerHeader = nextItem.querySelector('h6');
          if (innerHeader) break;
          hasItems = true;
          break;
        }
        nextItem = nextItem.nextElementSibling;
      }

      item.style.display = hasItems ? '' : 'none';
    });
  }

  // Sembunyikan header grup yang tidak punya item VISIBLE (dipakai saat searching)
  function updateGroupVisibilityForSearch() {
    const navbar = document.querySelector('.navbar-nav');
    if (!navbar) return;

    const allItems = navbar.querySelectorAll('li.nav-item');

    allItems.forEach(item => {
      const header = item.querySelector('h6');
      if (!header) return;

      let hasVisibleItems = false;
      let nextItem = item.nextElementSibling;

      while (nextItem) {
        if (nextItem.classList.contains('nav-item')) {
          const innerHeader = nextItem.querySelector('h6');
          if (innerHeader) break;
          if (nextItem.style.display !== 'none') {
            hasVisibleItems = true;
            break;
          }
        }
        nextItem = nextItem.nextElementSibling;
      }

      item.style.display = hasVisibleItems ? '' : 'none';
    });
  }

  // Accordion: tiap header grup menu (h6) bisa diklik untuk collapse/expand
  // agar sidebar tidak terlalu panjang. Status collapse disimpan per grup di localStorage.
  function initSidebarAccordion() {
    const navbar = document.querySelector('.navbar-nav');
    if (!navbar) return;

    const headerItems = Array.from(navbar.children).filter(function(li) {
      return li.classList && li.classList.contains('nav-item') &&
        li.style.display !== 'none' && li.querySelector(':scope > h6');
    });

    const groups = headerItems.map(function(headerLi) {
      const h6 = headerLi.querySelector(':scope > h6');
      const items = [];
      let next = headerLi.nextElementSibling;
      while (next && next.classList.contains('nav-item') && !next.querySelector(':scope > h6')) {
        // Item pinned (mis. tombol Log out) tidak ikut dikumpulkan supaya selalu tampil,
        // tidak pernah ikut ter-collapse bersama grup di atasnya.
        if (!next.classList.contains('sidebar-pinned-item')) {
          items.push(next);
        }
        next = next.nextElementSibling;
      }
      return { headerLi: headerLi, h6: h6, items: items };
    }).filter(function(group) { return group.items.length > 0; });

    function storageKey(h6) {
      return 'billing_sidebar_group_' + h6.textContent.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_');
    }

    function setCollapsed(group, collapsed) {
      group.items.forEach(function(li) { li.style.display = collapsed ? 'none' : ''; });
      group.headerLi.classList.toggle('sidebar-group-collapsed', collapsed);
      if (group.chevron) {
        group.chevron.classList.toggle('fa-chevron-down', !collapsed);
        group.chevron.classList.toggle('fa-chevron-right', collapsed);
      }
    }

    function defaultCollapsed(group) {
      const key = storageKey(group.h6);
      const saved = localStorage.getItem(key);
      if (saved !== null) return saved === '1';
      const hasActive = group.items.some(function(li) { return li.querySelector('.nav-link.active'); });
      return !hasActive;
    }

    groups.forEach(function(group) {
      group.h6.classList.add('sidebar-group-toggle');

      const chevron = document.createElement('i');
      chevron.className = 'fas fa-chevron-down sidebar-group-chevron';
      group.h6.appendChild(chevron);
      group.chevron = chevron;

      setCollapsed(group, defaultCollapsed(group));

      group.h6.addEventListener('click', function() {
        const nowCollapsed = !group.headerLi.classList.contains('sidebar-group-collapsed');
        setCollapsed(group, nowCollapsed);
        localStorage.setItem(storageKey(group.h6), nowCollapsed ? '1' : '0');
      });
    });

    // Dipanggil saat kotak pencarian dikosongkan, supaya grup kembali
    // ke status collapse/expand terakhir yang dipilih user.
    window.__sidebarAccordionRestore = function() {
      groups.forEach(function(group) {
        setCollapsed(group, defaultCollapsed(group));
      });
    };
  }

  // Jalankan fungsi saat DOM siap
  document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
      hideEmptyMenuGroups();
      initSidebarAccordion();
    }, 100);
  });

  // Jalankan ulang saat search dilakukan
  const menuSearchInput = document.getElementById('menuSearch');
  if (menuSearchInput) {
    menuSearchInput.addEventListener('input', function() {
      const query = this.value.toLowerCase();

      if (query === '') {
        // Kosongkan search -> kembalikan ke status collapse terakhir
        if (typeof window.__sidebarAccordionRestore === 'function') {
          window.__sidebarAccordionRestore();
        }
        hideEmptyMenuGroups();
        return;
      }

      const navItems = document.querySelectorAll('.navbar-nav .nav-item');

      navItems.forEach(item => {
        const header = item.querySelector('h6');

        // Skip header items saat filtering
        if (header) return;

        const text = item.textContent.toLowerCase();
        if (text.includes(query)) {
          item.style.display = '';
        } else {
          item.style.display = 'none';
        }
      });

      // Panggil fungsi untuk hide empty groups setelah search
      setTimeout(updateGroupVisibilityForSearch, 50);
    });
  }

  (function() {
    const themeValues = ['default', 'terang', 'dark'];
    const storageKey = <?php echo json_encode($theme_storage_key); ?>;
    const sidebar = document.getElementById('sidenav-main');
    const htmlEl = document.documentElement;
    const themeButtons = document.querySelectorAll('.theme-square-btn[data-theme-value]');
    if (!themeButtons.length) return;

    function applyGlobalThemeClass(theme) {
      const globalClasses = ['app-theme-default', 'app-theme-terang', 'app-theme-dark'];
      const bodyEl = document.body;
      if (htmlEl) htmlEl.classList.remove.apply(htmlEl.classList, globalClasses);
      if (bodyEl) bodyEl.classList.remove.apply(bodyEl.classList, globalClasses);

      const className = theme === 'terang'
        ? 'app-theme-terang'
        : (theme === 'dark' ? 'app-theme-dark' : 'app-theme-default');

      if (htmlEl) htmlEl.classList.add(className);
      if (bodyEl) bodyEl.classList.add(className);

      if (!bodyEl) {
        document.addEventListener('DOMContentLoaded', function() {
          if (document.body) {
            document.body.classList.remove.apply(document.body.classList, globalClasses);
            document.body.classList.add(className);
          }
        }, { once: true });
      }
    }

    function applyTheme(theme) {
      if (!sidebar) return;
      sidebar.classList.remove('theme-default', 'theme-terang', 'theme-dark');
      if (theme === 'terang') {
        sidebar.classList.add('theme-terang');
      } else if (theme === 'dark') {
        sidebar.classList.add('theme-dark');
      } else {
        sidebar.classList.add('theme-default');
      }
      applyGlobalThemeClass(theme);

      themeButtons.forEach(function(btn) {
        const isActive = btn.getAttribute('data-theme-value') === theme;
        btn.classList.toggle('active', isActive);
      });
    }

    const savedTheme = localStorage.getItem(storageKey) || 'default';
    const initialTheme = themeValues.includes(savedTheme) ? savedTheme : 'default';
    applyTheme(initialTheme);

    themeButtons.forEach(function(btn) {
      btn.addEventListener('click', function() {
        const theme = this.getAttribute('data-theme-value');
        if (!themeValues.includes(theme)) return;
        applyTheme(theme);
        localStorage.setItem(storageKey, theme);
      });
    });
  })();
</script>


<!-- Tombol Aksi (Simetris di Tengah) -->
<div class="text-center mt-3 d-flex justify-content-center gap-3">


<?php if ($AKSES != "ADMIN" && $AKSES != "ASSISTANT" && !$is_teknisi_only) { ?>
  <a href="#" 
     class="btn btn-success px-4 d-flex align-items-center" 
     data-bs-toggle="modal" 
     data-bs-target="#iframeModal" 
     data-url="topup.php">
    <i class="fas fa-sync-alt me-2"></i> Perpanjang / Topup
  </a>
<?php }?>


  <?php if ($AKSES != "ASSISTANT") { ?>
    <a href="#" 
       class="btn btn-warning px-4 d-flex align-items-center" 
       data-bs-toggle="modal" 
       data-bs-target="#iframeModal" 
       data-url="panduan.php">
      <i class="fas fa-book me-2"></i> Panduan
    </a>
  <?php } else { 
    if (is_array($akses_menu) && in_array('Panduan', $akses_menu)) { ?>
      <a href="panduan.php" 
         class="btn btn-warning px-4 d-flex align-items-center">
        <i class="fas fa-book me-2"></i> Panduan
      </a>
  <?php } } ?>

  <button type="button"
          class="btn btn-info px-4 d-flex align-items-center"
          data-bs-toggle="modal"
          data-bs-target="#linkAndaModal">
    <i class="fas fa-link me-2"></i> LINK ANDA
  </button>
</div>




<script>
  const iframeModal = document.getElementById('iframeModal');
  const iframeContent = document.getElementById('iframeContent');

  iframeModal.addEventListener('show.bs.modal', function (event) {
    const button = event.relatedTarget;
    const url = button.getAttribute('data-url');
    iframeContent.src = url;
  });

  iframeModal.addEventListener('hidden.bs.modal', function () {
    iframeContent.src = ""; // reset iframe saat ditutup
  });

  function copyTextFromValue(value) {
    if (!value) return;

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(value)
        .then(function() { alert('URL berhasil disalin!'); })
        .catch(function() { fallbackCopyText(value); });
      return;
    }

    fallbackCopyText(value);
  }

  function fallbackCopyText(value) {
    var ta = document.createElement('textarea');
    ta.value = value;
    ta.setAttribute('readonly', '');
    ta.style.position = 'absolute';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, 99999);
    var ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (e) {
      ok = false;
    }
    document.body.removeChild(ta);
    alert(ok ? 'URL berhasil disalin!' : 'Gagal menyalin URL.');
  }

  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.link-anda-copy-btn');
    if (!btn) return;
    copyTextFromValue(btn.getAttribute('data-copy-url'));
  });
</script>

<style>
  /* Efek shadow agar modal terlihat menonjol */
  .modal-content {
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3) !important;
    background: #fff;
  }

  .modal.fade.show {
    z-index: 9999 !important;
  }

  /* Shadow untuk Sidebar agar tidak samar dengan halaman utama */
  .sidenav {
    box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15) !important;
    border-right: 1px solid rgba(0, 0, 0, 0.05);
  }

  #sidenav-main {
    overflow-y: auto;
    -ms-overflow-style: none;
    scrollbar-width: none;
  }

  #sidenav-main::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }

  /* Styling untuk Sidebar */
  .sidenav .navbar-nav .nav-item {
    background-color: rgba(255, 255, 255, 0.8);
    margin: 1px 4px;
    border-radius: 6px;
    border: 1px solid rgba(0, 0, 0, 0.05);
  }

  .sidenav .navbar-nav .nav-item .nav-link {
    padding: 8px 10px;
    margin: 0;
    border-radius: 6px;
    transition: all 0.3s ease;
    font-size: 12.5px;
    font-weight: 500;
    position: relative;
    overflow: hidden;
  }

  .sidenav .navbar-nav .nav-item .nav-link .icon.icon-shape {
    width: 24px;
    height: 24px;
    min-width: 24px;
  }

  .sidenav .navbar-nav .nav-item .nav-link .icon.icon-shape i {
    font-size: 12px;
  }

  .sidenav .navbar-nav .nav-item .nav-link-text {
    font-size: 12.5px;
    line-height: 1.3;
  }

  .sidenav .navbar-nav .nav-item .nav-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(14, 149, 137, 0.1), transparent);
    transition: left 0.5s;
  }

  .sidenav .navbar-nav .nav-item .nav-link:hover::before {
    left: 100%;
  }

  .sidenav .navbar-nav .nav-item .nav-link:hover {
    background-color: rgba(14, 149, 137, 0.1);
    color: #0E9589;
    transform: translateX(5px);
    box-shadow: 0 4px 8px rgba(14, 149, 137, 0.2);
  }

  .sidenav .navbar-nav .nav-item .nav-link.active {
    background-color: #0E9589;
    color: white;
    box-shadow: 0 4px 12px rgba(14, 149, 137, 0.3);
  }

  .sidenav .navbar-nav h6 {
    padding: 16px 16px 8px;
    margin: 16px 8px 8px;
    color: #0E9589;
    font-weight: 700;
    border-bottom: 2px solid rgba(14, 149, 137, 0.2);
    position: relative;
    background: rgba(14, 149, 137, 0.05);
    border-radius: 6px;
  }

  .sidenav .navbar-nav h6::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 30px;
    height: 2px;
    background: #0E9589;
    border-radius: 1px;
  }

  /* Grouping sections with subtle backgrounds */
  .sidenav .navbar-nav > li.nav-item:first-child ~ li.nav-item:has(h6:contains("Dasbor")) + li,
  .sidenav .navbar-nav > li.nav-item:has(h6:contains("Infrastruktur")) ~ li:not(:has(h6)),
  .sidenav .navbar-nav > li.nav-item:has(h6:contains("Manajemen")) ~ li:not(:has(h6)),
  .sidenav .navbar-nav > li.nav-item:has(h6:contains("PPP")) ~ li:not(:has(h6)),
  .sidenav .navbar-nav > li.nav-item:has(h6:contains("Hotspot")) ~ li:not(:has(h6)),
  .sidenav .navbar-nav > li.nav-item:has(h6:contains("Kemitraan")) ~ li:not(:has(h6)),
  .sidenav .navbar-nav > li.nav-item:has(h6:contains("Menu")) ~ li:not(:has(h6)),
  .sidenav .navbar-nav > li.nav-item:has(h6:contains("Akun")) ~ li:not(:has(h6)) {
    background: rgba(248, 249, 250, 0.5);
  }

  /* Search Menu Styling */
  .menu-search-container {
    padding: 16px;
    background: linear-gradient(135deg, var(--bg-dark-1) 0%, rgba(59, 130, 246, 0.05) 100%);
    border-bottom: 1px solid var(--border-color);
    position: relative;
  }

  .menu-search-container::before {
    content: '\f002';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    position: absolute;
    right: 30px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--logo-primary);
    font-size: 14px;
    pointer-events: none;
  }

  .menu-search-container input {
    border: 2px solid var(--logo-secondary) !important;
    border-radius: 25px !important;
    padding: 10px 40px 10px 20px !important;
    font-size: 14px;
    transition: all 0.3s ease;
    background: var(--white);
    color: var(--text-primary);
  }

  .menu-search-container input::placeholder {
    color: var(--text-secondary);
  }

  .menu-search-container input:focus {
    border-color: var(--logo-primary) !important;
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.25) !important;
    outline: none;
    transform: scale(1.02);
  }

  .theme-slider-container {
    background: linear-gradient(135deg, var(--bg-dark-1) 0%, rgba(59, 130, 246, 0.05) 100%);
    border-bottom: 1px solid var(--border-color);
    padding: 12px !important;
  }

  .theme-slider-title {
    display: block;
    color: var(--logo-primary);
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .theme-button-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
  }

  .theme-square-btn {
    border: 2px solid var(--logo-secondary);
    background: var(--white);
    color: var(--logo-primary);
    border-radius: 8px;
    padding: 10px 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
  }

  .theme-square-btn:hover {
    transform: translateY(-2px);
    background: var(--logo-light);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
  }

  .theme-square-btn.active {
    background: var(--logo-primary);
    color: var(--white);
    border-color: var(--logo-primary);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
    font-weight: 700;
  }

  #sidenav-main.theme-terang {
    background: linear-gradient(180deg, var(--white) 0%, var(--bg-dark-1) 100%) !important;
  }

  #sidenav-main.theme-terang .navbar-nav .nav-item {
    background-color: var(--white);
    border-color: rgba(37, 99, 235, 0.08);
  }

  #sidenav-main.theme-terang .navbar-nav .nav-item .nav-link {
    color: var(--text-primary);
  }

  #sidenav-main.theme-terang .navbar-nav h6 {
    color: var(--logo-primary);
    background: rgba(37, 99, 235, 0.08);
  }

  #sidenav-main.theme-dark {
    background: linear-gradient(180deg, #0f172a 0%, #0a0e27 100%) !important;
    border-right: 1px solid rgba(37, 99, 235, 0.15);
  }

  #sidenav-main.theme-dark .digital-container {
    background: rgba(37, 99, 235, 0.1);
    border-color: rgba(37, 99, 235, 0.25);
  }

  #sidenav-main.theme-dark .date-display {
    color: rgba(219, 234, 254, 0.95);
  }

  #sidenav-main.theme-dark .segment {
    background: rgba(59, 130, 246, 0.3);
  }

  #sidenav-main.theme-dark .segment.on,
  #sidenav-main.theme-dark .colon div {
    background: var(--logo-secondary);
    box-shadow: 0 0 12px rgba(59, 130, 246, 0.5);
  }

  #sidenav-main.theme-dark .menu-search-container,
  #sidenav-main.theme-dark .theme-slider-container {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.08) 0%, rgba(29, 78, 216, 0.06) 100%);
    border-bottom-color: rgba(37, 99, 235, 0.2);
  }

  #sidenav-main.theme-dark .theme-slider-title {
    color: rgba(219, 234, 254, 0.95);
  }

  #sidenav-main.theme-dark .theme-square-btn {
    background: rgba(37, 99, 235, 0.15);
    color: rgba(219, 234, 254, 0.8);
    border-color: rgba(59, 130, 246, 0.35);
  }

  #sidenav-main.theme-dark .theme-square-btn.active {
    background: var(--logo-secondary);
    color: var(--white);
    border-color: var(--logo-secondary);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
  }

  #sidenav-main.theme-dark .menu-search-container::before {
    color: var(--logo-secondary);
  }

  #sidenav-main.theme-dark .menu-search-container input {
    background: rgba(30, 41, 59, 0.6);
    color: rgba(241, 245, 249, 0.95);
    border-color: var(--logo-secondary) !important;
  }

  #sidenav-main.theme-dark .menu-search-container input::placeholder {
    color: rgba(148, 163, 184, 0.7);
  }

  #sidenav-main.theme-dark .navbar-nav .nav-item {
    background-color: rgba(37, 99, 235, 0.08);
    border-color: rgba(59, 130, 246, 0.15);
  }

  #sidenav-main.theme-dark .navbar-nav .nav-item .nav-link {
    color: rgba(219, 234, 254, 0.9);
  }

  #sidenav-main.theme-dark .navbar-nav .nav-item .nav-link:hover {
    background-color: rgba(37, 99, 235, 0.2);
    color: var(--logo-secondary);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.35);
  }

  #sidenav-main.theme-dark .navbar-nav .nav-item .nav-link.active {
    background-color: var(--logo-secondary);
    color: var(--white);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
  }

  #sidenav-main.theme-dark .navbar-nav h6 {
    color: rgba(219, 234, 254, 0.9);
    background: rgba(37, 99, 235, 0.1);
    border-bottom-color: rgba(59, 130, 246, 0.25);
  }

  #sidenav-main.theme-dark .navbar-nav h6::after {
    background: var(--logo-secondary);
  }

  html.app-theme-terang,
  body.app-theme-terang {
    background: #f8fafc !important;
    color: var(--text-primary) !important;
  }

  body.app-theme-terang .main-content,
  body.app-theme-terang .container,
  body.app-theme-terang .container-fluid {
    background: transparent !important;
    color: var(--text-primary) !important;
  }

  body.app-theme-terang .navbar-main {
    background: var(--white) !important;
    border: 1px solid rgba(37, 99, 235, 0.15) !important;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.08) !important;
  }

  body.app-theme-terang .card,
  body.app-theme-terang .modal-content,
  body.app-theme-terang .dropdown-menu {
    background: var(--white) !important;
    color: var(--text-primary) !important;
    border-color: var(--border-color) !important;
  }

  body.app-theme-terang .card-header:not(.bg-primary):not(.bg-success):not(.bg-danger):not(.bg-warning):not(.bg-info) {
    background: rgba(37, 99, 235, 0.05) !important;
    color: var(--text-primary) !important;
  }

  body.app-theme-terang .table,
  body.app-theme-terang .table td,
  body.app-theme-terang .table th,
  body.app-theme-terang .form-control,
  body.app-theme-terang .form-select {
    color: var(--text-primary) !important;
  }

  body.app-theme-terang .sidenav .icon.icon-shape {
    background: rgba(37, 99, 235, 0.1) !important;
    border: 1px solid rgba(37, 99, 235, 0.2) !important;
  }

  body.app-theme-terang .sidenav .icon.icon-shape i,
  body.app-theme-terang .sidenav .icon.icon-shape .text-dark {
    color: var(--logo-primary) !important;
  }

  body.app-theme-terang .card,
  body.app-theme-terang .card *:not(.btn):not(.badge),
  body.app-theme-terang .table,
  body.app-theme-terang .table td,
  body.app-theme-terang .table th,
  body.app-theme-terang .modal-content,
  body.app-theme-terang .modal-content *:not(.btn):not(.badge),
  body.app-theme-terang .list-group-item {
    color: var(--text-primary) !important;
  }

  body.app-theme-terang .text-muted,
  body.app-theme-terang small,
  body.app-theme-terang .small {
    color: var(--text-secondary) !important;
  }

  body.app-theme-terang .table thead th {
    background: rgba(37, 99, 235, 0.08) !important;
    color: var(--text-primary) !important;
    border-bottom-color: rgba(37, 99, 235, 0.14) !important;
  }

  html.app-theme-dark,
  body.app-theme-dark {
    background: #0a0e27 !important;
    color: #cbd5e1 !important;
    --bs-body-bg: #0a0e27;
    --bs-body-color: #cbd5e1;
    --bs-table-color: #cbd5e1;
    --bs-table-bg: #0f172a;
    --bs-table-striped-color: #cbd5e1;
    --bs-table-striped-bg: rgba(37, 99, 235, 0.08);
    --bs-table-hover-color: #f1f5f9;
    --bs-table-hover-bg: rgba(37, 99, 235, 0.12);
  }

  body.app-theme-dark .main-content,
  body.app-theme-dark .container,
  body.app-theme-dark .container-fluid {
    background: transparent !important;
    color: #cbd5e1 !important;
  }

  body.app-theme-dark .navbar-main {
    background: #0f172a !important;
    border: 1px solid rgba(37, 99, 235, 0.2) !important;
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.5) !important;
  }

  body.app-theme-dark .card,
  body.app-theme-dark .modal-content,
  body.app-theme-dark .dropdown-menu,
  body.app-theme-dark .list-group-item,
  body.app-theme-dark .timeline,
  body.app-theme-dark .timeline-one-side {
    background: #0f172a !important;
    color: #cbd5e1 !important;
    border-color: rgba(37, 99, 235, 0.2) !important;
  }

  body.app-theme-dark .bg-white,
  body.app-theme-dark .bg-light,
  body.app-theme-dark .table-light,
  body.app-theme-dark .thead-light,
  body.app-theme-dark .card.bg-white,
  body.app-theme-dark .card.bg-light,
  body.app-theme-dark .modal-content.bg-white,
  body.app-theme-dark .modal-content.bg-light {
    background: #1e2d3d !important;
    color: #e2e8f0 !important;
  }

  body.app-theme-dark .card-header:not(.bg-primary):not(.bg-success):not(.bg-danger):not(.bg-warning):not(.bg-info) {
    background: #1e2d3d !important;
    color: #e2e8f0 !important;
  }

  body.app-theme-dark .table,
  body.app-theme-dark .table td,
  body.app-theme-dark .table th,
  body.app-theme-dark .text-dark,
  body.app-theme-dark .nav-link,
  body.app-theme-dark .nav-link-text,
  body.app-theme-dark .text-muted,
  body.app-theme-dark p,
  body.app-theme-dark span,
  body.app-theme-dark label {
    color: #cbd5e1 !important;
  }

  body.app-theme-dark .table-striped tbody tr:nth-of-type(odd),
  body.app-theme-dark .table-hover tbody tr:hover {
    background-color: rgba(37, 99, 235, 0.1) !important;
  }

  body.app-theme-dark .card,
  body.app-theme-dark .card *:not(.btn):not(.badge),
  body.app-theme-dark .table,
  body.app-theme-dark .table td,
  body.app-theme-dark .table th,
  body.app-theme-dark .modal-content,
  body.app-theme-dark .modal-content *:not(.btn):not(.badge),
  body.app-theme-dark .list-group-item,
  body.app-theme-dark .dropdown-menu,
  body.app-theme-dark .dropdown-item {
    color: #cbd5e1 !important;
  }

  body.app-theme-dark h1,
  body.app-theme-dark h2,
  body.app-theme-dark h3,
  body.app-theme-dark h4,
  body.app-theme-dark h5,
  body.app-theme-dark h6 {
    color: #f1f5f9 !important;
  }

  body.app-theme-dark .text-muted,
  body.app-theme-dark small,
  body.app-theme-dark .small {
    color: #94a3b8 !important;
  }

  body.app-theme-dark .table thead th {
    background: rgba(37, 99, 235, 0.15) !important;
    color: #f1f5f9 !important;
    border-bottom-color: rgba(59, 130, 246, 0.3) !important;
  }

  body.app-theme-dark .table-responsive,
  body.app-theme-dark .card .table,
  body.app-theme-dark .card .table tbody tr,
  body.app-theme-dark .card .table tbody tr td,
  body.app-theme-dark .card .table tbody tr th {
    background-color: #111827 !important;
    color: #e5e7eb !important;
  }

  body.app-theme-dark .card .table tbody tr:nth-child(even) td,
  body.app-theme-dark .card .table tbody tr:nth-child(even) th {
    background-color: #0f172a !important;
  }

  body.app-theme-dark .table-bordered,
  body.app-theme-dark .table-bordered td,
  body.app-theme-dark .table-bordered th {
    border-color: rgba(255, 255, 255, 0.12) !important;
  }

  body.app-theme-dark .badge:not([class*='bg-']) {
    background: #334155 !important;
    color: #f8fafc !important;
  }

  body.app-theme-dark .alert {
    color: #f8fafc !important;
    border-color: rgba(255, 255, 255, 0.14) !important;
  }

  body.app-theme-dark .alert-warning {
    background: rgba(217, 119, 6, 0.18) !important;
  }

  body.app-theme-dark .alert-info {
    background: rgba(37, 99, 235, 0.18) !important;
  }

  body.app-theme-dark .alert-success {
    background: rgba(5, 150, 105, 0.18) !important;
  }

  body.app-theme-dark .alert-danger {
    background: rgba(220, 38, 38, 0.18) !important;
  }

  .app-theme-dark .main-content .card,
  .app-theme-dark .main-content .card-body,
  .app-theme-dark .main-content .table,
  .app-theme-dark .main-content .table td,
  .app-theme-dark .main-content .table th,
  .app-theme-dark .main-content .table-responsive,
  .app-theme-dark .main-content .list-group-item {
    background-color: #111827 !important;
    color: #e5e7eb !important;
  }

  .app-theme-dark .main-content .card .fw-bold,
  .app-theme-dark .main-content .card .display-6,
  .app-theme-dark .main-content .card p,
  .app-theme-dark .main-content .card span,
  .app-theme-dark .main-content .card small,
  .app-theme-dark .main-content .card label {
    color: #f3f4f6 !important;
    opacity: 1 !important;
  }

  .app-theme-dark .main-content .text-secondary,
  .app-theme-dark .main-content .text-muted,
  .app-theme-dark .main-content .text-xs,
  .app-theme-dark .main-content .small,
  .app-theme-dark .main-content small {
    color: #cbd5e1 !important;
    opacity: 1 !important;
  }

  .app-theme-dark .main-content .opacity-25,
  .app-theme-dark .main-content .opacity-50,
  .app-theme-dark .main-content .opacity-75,
  .app-theme-dark .main-content .opacity-5,
  .app-theme-dark .main-content .opacity-6,
  .app-theme-dark .main-content .opacity-7,
  .app-theme-dark .main-content .opacity-8 {
    opacity: 1 !important;
  }

  .app-theme-dark .main-content .table thead th {
    background: #1f2937 !important;
    color: #f8fafc !important;
  }

  .app-theme-dark .main-content .bg-white,
  .app-theme-dark .main-content .bg-light,
  .app-theme-dark .main-content .table-light,
  .app-theme-dark .main-content .thead-light {
    background: #1f2937 !important;
    color: #f3f4f6 !important;
  }

  body.app-theme-dark .text-primary { color: #93c5fd !important; }
  body.app-theme-dark .text-success { color: #6ee7b7 !important; }
  body.app-theme-dark .text-danger { color: #fca5a5 !important; }
  body.app-theme-dark .text-warning { color: #fcd34d !important; }
  body.app-theme-dark .text-info { color: #67e8f9 !important; }

  body.app-theme-dark a {
    color: #93c5fd !important;
  }

  body.app-theme-dark a:hover {
    color: #bfdbfe !important;
  }

  body.app-theme-dark .form-control,
  body.app-theme-dark .form-select,
  body.app-theme-dark input,
  body.app-theme-dark select,
  body.app-theme-dark textarea {
    background: #0f172a !important;
    color: #f3f4f6 !important;
    border-color: rgba(255, 255, 255, 0.18) !important;
  }

  body.app-theme-dark .form-control::placeholder,
  body.app-theme-dark input::placeholder,
  body.app-theme-dark textarea::placeholder {
    color: rgba(229, 231, 235, 0.65) !important;
  }

  body.app-theme-dark .btn-primary {
    background-color: #2563eb !important;
    border-color: #2563eb !important;
    color: #ffffff !important;
  }

  body.app-theme-dark .btn-success {
    background-color: #059669 !important;
    border-color: #059669 !important;
    color: #ffffff !important;
  }

  body.app-theme-dark .btn-warning {
    background-color: #d97706 !important;
    border-color: #d97706 !important;
    color: #ffffff !important;
  }

  body.app-theme-dark .sidenav .icon.icon-shape {
    background: #0f172a !important;
    border: 1px solid rgba(52, 211, 153, 0.35) !important;
    box-shadow: none !important;
  }

  body.app-theme-dark .sidenav .icon.icon-shape i,
  body.app-theme-dark .sidenav .icon.icon-shape .text-dark {
    color: #34d399 !important;
  }

  body.app-theme-dark .sidenav .nav-link.active .icon.icon-shape {
    background: rgba(5, 46, 43, 0.55) !important;
    border-color: rgba(5, 46, 43, 0.75) !important;
  }

  body.app-theme-default .sidenav .icon.icon-shape {
    background: #ffffff !important;
    border: 1px solid rgba(0, 0, 0, 0.08) !important;
  }

  body.app-theme-default .sidenav .icon.icon-shape i,
  body.app-theme-default .sidenav .icon.icon-shape .text-dark {
    color: #0f172a !important;
  }

  .quick-link-section-title {
    color: #0E9589;
    font-weight: 800;
    font-size: 11px;
    margin: 10px 10px 6px;
    padding: 8px 10px;
    border-radius: 8px;
    background: rgba(14, 149, 137, 0.1);
    border: 1px solid rgba(14, 149, 137, 0.2);
    letter-spacing: 0.4px;
  }

  .nav-link-pinned {
    border-left: 4px solid #0E9589;
  }

  /* Sidebar group collapse/accordion */
  .sidenav .navbar-nav h6.sidebar-group-toggle {
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    user-select: none;
  }

  .sidenav .navbar-nav h6.sidebar-group-toggle:hover {
    opacity: 0.85;
  }

  .sidebar-group-chevron {
    font-size: 11px;
    margin-left: 8px;
    flex-shrink: 0;
    transition: transform 0.2s ease;
  }

  .sidenav .navbar-nav li.nav-item.sidebar-group-collapsed {
    margin-top: 2px !important;
    margin-bottom: 2px !important;
  }

  .sidenav .navbar-nav li.nav-item.sidebar-group-collapsed h6.quick-link-section-title,
  .sidenav .navbar-nav li.nav-item.sidebar-group-collapsed h6 {
    margin: 2px 4px;
    padding: 10px 12px;
  }

  .quick-link-nav .nav-link {
    border-left: 4px solid #0E9589;
    background: linear-gradient(90deg, rgba(14, 149, 137, 0.12), rgba(14, 149, 137, 0.03));
    margin: 2px 8px;
    border-radius: 8px;
  }

  .quick-link-nav .nav-link:hover {
    transform: translateX(3px);
    box-shadow: 0 4px 10px rgba(14, 149, 137, 0.2);
  }

  .quick-link-nav .nav-link-text {
    font-weight: 700;
  }

  .quick-link-copy-btn {
    margin: 4px 8px 6px;
    width: calc(100% - 16px);
    border: 1px solid rgba(14, 149, 137, 0.28);
    background: rgba(14, 149, 137, 0.08);
    color: #0E9589;
    font-weight: 700;
    border-radius: 8px;
    transition: all 0.2s ease;
  }

  .quick-link-copy-btn:hover {
    background: rgba(14, 149, 137, 0.16);
    color: #0b6f67;
  }

  /* Responsivitas untuk Mobile */
  @media (max-width: 768px) {
    .sidenav {
      width: 280px !important;
    }

    .sidenav .navbar-nav .nav-item .nav-link {
      padding: 10px 12px;
      margin: 2px 4px;
      font-size: 13px;
    }

    .sidenav .navbar-nav h6 {
      padding: 12px 12px 6px;
      margin: 12px 4px 6px;
      font-size: 11px;
    }

    .menu-search-container {
      padding: 12px;
    }

    .menu-search-container input {
      padding: 8px 16px !important;
      font-size: 13px;
    }

    .digital-container {
      padding: 10px;
      margin: 5px 0;
    }

    .digital-clock {
      font-size: 14px;
    }

    .date-display {
      font-size: 10px;
    }

    /* Tombol Aksi pada Mobile */
    .text-center.mt-3.d-flex.justify-content-center.gap-3 {
      flex-direction: column;
      gap: 2px;
    }

    .text-center.mt-3.d-flex.justify-content-center.gap-3 .btn {
      width: 100%;
      margin-bottom: 4px;
    }
  }

  @media (max-width: 576px) {
    .sidenav {
      width: 260px !important;
    }

    .sidenav .navbar-nav .nav-item .nav-link {
      padding: 8px 10px;
      font-size: 12px;
    }

    .sidenav .navbar-nav h6 {
      font-size: 10px;
    }

    .menu-search-container input {
      font-size: 12px;
    }
  }
</style>










  
  <ul class="navbar-nav">

<?php if (!$is_teknisi_only && $can_assistant_dashboard): ?>
    <li class="nav-item">
      <a class="nav-link nav-link-pinned <?php if($current_file=='dashboard.php') echo 'active'; ?>" href="../billing/dashboard.php">
        <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
          <i class="fas fa-tachometer-alt text-dark"></i>
        </div>
        <span class="nav-link-text ms-1">Dasbor</span>
      </a>
    </li>
<?php endif; ?>

    <li class="nav-item">
      <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">Jobdesk</h6>
    </li>

<?php if (($AKSES == "USER" || $AKSES == "ADMIN") || ($AKSES == "ASSISTANT" && $can_assistant_livechat)) { ?>
    <li class="nav-item">
      <a class="nav-link <?php if($current_file=='livechat.php') echo 'active'; ?>" href="../billing/livechat.php">
        <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
          <i class="fas fa-comments text-dark"></i>
        </div>
        <span class="nav-link-text ms-1">Live Chat</span>
      </a>
    </li>
<?php } ?>





<?php if ($show_ticket_manager_menu && ($AKSES != "ASSISTANT" || (is_array($akses_menu) && in_array('Ticket_manager', $akses_menu)))) { ?>

     
        <li class="nav-item">
          <a class="nav-link <?php if($current_file=='tiket_manager.php') echo 'active'; ?>" href="../billing/tiket_manager.php" >
        <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
          <i class="fas fa-tachometer-alt text-dark"></i>
        </div>
        <span class="nav-link-text ms-1">Manajer Tiket</span>
      </a>
    </li>


<?php } ?>









<?php if ($show_joblist_menu && ($AKSES != "ASSISTANT" || (is_array($akses_menu) && in_array('Ticket_monitoring', $akses_menu)))) { ?>

      
         <li class="nav-item">
      <a class="nav-link <?php if($current_file=='monitoring.php') echo 'active'; ?>" href="../joblist/monitoring.php">
        <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
          <i class="fas fa-tachometer-alt text-dark"></i>
        </div>
        <span class="nav-link-text ms-1">Pemantauan Tiket</span>
      </a>
    </li>


<?php } ?>














<?php if (!$is_teknisi_only): ?>
      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">Infrastruktur Jaringan</h6>
      </li>













<?php 


if ($AKSES != "ASSISTANT") { ?>

      <?php if ($AKSES == "USER") { ?>
        <li class="nav-item">
          <a class="nav-link <?php if($current_file=='vpn.php') echo 'active'; ?>" href="../billing/vpn.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-shield-alt text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">Koneksi VPN</span>
          </a>
        </li>
 <?php }
 else
  {
    ?>
     <li class="nav-item">
    <a class="nav-link <?php if($current_file=='vpnadmin.php') echo 'active'; ?>" href="../billing/vpnadmin.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-shield-alt text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">Koneksi VPN</span>
          </a>
        </li>
      <?php
  } 
 
}
else
{
if(is_array($akses_menu) && in_array('Vpn_Connection', $akses_menu)){
?>
<li class="nav-item">
          <a class="nav-link  " href="../billing/vpn.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-shield-alt text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">Koneksi VPN</span>
          </a>
        </li>
            <?php
          }
          
}
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
   


      
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='server.php') echo 'active'; ?>" href="../billing/server.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-server text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Area Server</span>
        </a>
      </li>

      <li class="nav-item">
        <li class="nav-item">
          <a class="nav-link <?php if($current_file=='pool.php') echo 'active'; ?>" href="../billing/pool.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-network-wired text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">IP Pool</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?php if($current_file=='vlan.php') echo 'active'; ?>" href="../billing/vlan.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-ethernet text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">VLAN</span>
          </a>
        </li>
 <?php }
      else
      {
          if(is_array($akses_menu) && in_array('Server_Area', $akses_menu)){
            ?>

      <li class="nav-item">
        <a class="nav-link  " href="../billing/server.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-server text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Area Server</span>
        </a>
      </li>
            <?php
          }
          if(is_array($akses_menu) && in_array('IP_Pool', $akses_menu)){
            ?>
        <li class="nav-item">
          <a class="nav-link  " href="../billing/pool.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-network-wired text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">IP Pool</span>
          </a>
        </li>
            <?php
          }
          if(is_array($akses_menu) && in_array('VLAN', $akses_menu)){
            ?>
        <li class="nav-item">
          <a class="nav-link  " href="../billing/vlan.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-ethernet text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">VLAN</span>
          </a>
        </li>
            <?php
          }

      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='odp.php') echo 'active'; ?>" href="../billing/odp.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-map-marked-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">ODP</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Mapping_ODP', $akses_menu)){
            ?>

        <li class="nav-item">
        <a class="nav-link  " href="../billing/odp.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-map-marked-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">ODP</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='olt.php') echo 'active'; ?>" href="../billing/olt.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-hdd text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">OLT</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('OLT', $akses_menu)){
            ?>
    <li class="nav-item">
        <a class="nav-link  " href="../billing/olt.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-hdd text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">OLT</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
    <li class="nav-item">
        <a class="nav-link <?php if($current_file=='mynetworkmap.php') echo 'active'; ?>" href="../billing/mynetworkmap.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-project-diagram text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">NMS</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('NMS', $akses_menu)){
            ?>

      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='mynetworkmap.php') echo 'active'; ?>" href="../billing/mynetworkmap.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-project-diagram text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">NMS</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>



<?php if ($AKSES != "ASSISTANT") { ?>
    <li class="nav-item">
        <a class="nav-link <?php if($current_file=='ftth_maps.php') echo 'active'; ?>" href="../billing/ftth_maps.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-project-diagram text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Insfrastruktur maps</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Insfrastruktur_maps', $akses_menu)){
            ?>

      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='ftth_maps.php') echo 'active'; ?>" href="../billing/ftth_maps.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-project-diagram text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Insfrastruktur maps</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>













<?php if ($AKSES == "ADMIN") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='isolir_forwarding.php') echo 'active'; ?>" href="../billing/isolir_forwarding.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-shield-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Isolir Forwarding</span>
        </a>
      </li>
<?php } ?>

      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">PPP menu</h6>
      </li>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='tables.php') echo 'active'; ?>" href="../billing/tables.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-users text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Customer PPPOE</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Customer_PPPOE', $akses_menu)){
            ?>
              <li class="nav-item">
        <a class="nav-link  " href="../billing/tables.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-users text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Customer PPPOE</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

<?php if ($AKSES == "ADMIN") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='provisioning_approval.php') echo 'active'; ?>" href="../billing/provisioning_approval.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-clipboard-check text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Provisioning Joblist</span>
          <?php
          // Count pending provisioning
          $prov_count_sql = "SELECT COUNT(*) as cnt FROM provisioning WHERE status='PENDING' AND server_pemilik IN (SELECT PEMILIK FROM server WHERE PEMILIK='$ceknama')";
          $prov_count_result = mysqli_query($conn, $prov_count_sql);
          $prov_pending = 0;
          if ($prov_count_result) {
              $prov_row = mysqli_fetch_assoc($prov_count_result);
              $prov_pending = (int)($prov_row['cnt'] ?? 0);
          }
          if ($prov_pending > 0) { ?>
            <span class="badge bg-danger ms-2"><?= $prov_pending ?></span>
          <?php } ?>
        </a>
      </li>
<?php } else {
      if(is_array($akses_menu) && in_array('Provisioning_Joblist', $akses_menu)){
      ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='provisioning_approval.php') echo 'active'; ?>" href="../billing/provisioning_approval.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-clipboard-check text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Provisioning Joblist</span>
        </a>
      </li>
      <?php }
      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='packages.php') echo 'active'; ?>" href="../billing/packages.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-box-open text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Paket PPPOE</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Packages_Broadband', $akses_menu)){
            ?>
         <li class="nav-item">
        <a class="nav-link  " href="../billing/packages.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-box-open text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Paket PPPOE</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">Hotspot menu</h6>
      </li>

      <?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='tableshotspot.php') echo 'active'; ?>" href="../billing/tableshotspot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-wifi text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Customer Hostpot</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Customer_Hotspot', $akses_menu)){
            ?>
        <li class="nav-item">
        <a class="nav-link  " href="../billing/tableshotspot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-wifi text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Customer Hostpot</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

 <?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='packageshotspot.php') echo 'active'; ?>" href="../billing/packageshotspot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-hotjar text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Paket Hotspot</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Packages_Hotspot', $akses_menu)){
            ?>
          <li class="nav-item">
        <a class="nav-link  " href="../billing/packageshotspot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-hotjar text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Paket Hotspot</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

 <?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='vouchergenerator.php') echo 'active'; ?>" href="../billing/vouchergenerator.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-ticket-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Voucher Genertor</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='voucher_template_builder.php') echo 'active'; ?>" href="../billing/voucher_template_builder.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-object-group text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Editor Template Voucher</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Voucher_Generator', $akses_menu)){
            ?>
               <li class="nav-item">
        <a class="nav-link  " href="../billing/vouchergenerator.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-ticket-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Voucher Genertor</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link  " href="../billing/voucher_template_builder.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-object-group text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Editor Template Voucher</span>
        </a>
      </li>
            <?php
          }

      }
      ?>

 <?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='voucherbank.php') echo 'active'; ?>" href="../billing/voucherbank.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-piggy-bank text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Voucher Bank</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Voucher_Bank', $akses_menu)){
            ?>
           <li class="nav-item">
        <a class="nav-link  " href="../billing/voucherbank.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-piggy-bank text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Voucher Bank</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">Sales & Komisi</h6>
      </li>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='mitraadmin.php') echo 'active'; ?>" href="../billing/mitraadmin.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-handshake text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Sales accounts</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Mitra_accounts', $akses_menu)){
            ?>
         <li class="nav-item">
        <a class="nav-link  " href="../billing/mitraadmin.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-handshake text-dar/k"></i>
          </div>
          <span class="nav-link-text ms-1">Sales accounts</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='commissionsetting.php') echo 'active'; ?>" href="../billing/commissionsetting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-percentage text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Komisi paket setting</span>
        </a>
      </li>
     
            <li class="nav-item">
        <a class="nav-link <?php if($current_file=='rekappembayaranmitra.php') echo 'active'; ?>" href="../billing/rekappembayaranmitra.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-percentage text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pembayaran komisi</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Commission_setting', $akses_menu)){
            ?>
           <li class="nav-item">
        <a class="nav-link  " href="../billing/commissionsetting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-percentage text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Komisi paket setting</span>
        </a>
      </li>
            <?php
          }
          if(is_array($akses_menu) && in_array('Pembayaran_Komisi', $akses_menu)){
            ?>
         <li class="nav-item">
        <a class="nav-link  " href="../billing/rekappembayaranmitra.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-percentage text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pembayaran komisi</span>
        </a>
      </li>
            <?php
          }

      }
      ?>

      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">INTEGRASI ACS</h6>
      </li>
<?php if ($AKSES != "ASSISTANT" || (is_array($akses_menu) && in_array('Informasi_Server_ACS', $akses_menu))) { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='acs_server_info.php') echo 'active'; ?>" href="../billing/acs_server_info.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-info-circle text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Informasi Server ACS</span>
        </a>
      </li>
<?php } ?>
    
    
 

      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">KEUANGAN</h6>
      </li>

 <?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='Transaction.php') echo 'active'; ?>" href="../billing/Transaction.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-exchange-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Transaksi</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='diskon.php') echo 'active'; ?>" href="../billing/diskon.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-tags text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Diskon</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='biaya_tambahan.php') echo 'active'; ?>" href="../billing/biaya_tambahan.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-plus-circle text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Tambahan Biaya</span>
        </a>
      </li>
       <li class="nav-item">
        <a class="nav-link <?php if($current_file=='pengeluaran.php') echo 'active'; ?>" href="../billing/pengeluaran.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-chart-bar text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Laporan pengeluaran</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='statistics.php') echo 'active'; ?>" href="../billing/statistics.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-chart-bar text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Statistik dan Laporan</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Transaction', $akses_menu)){
            ?>
          <li class="nav-item">
        <a class="nav-link  " href="../billing/Transaction.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-exchange-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Transaction</span>
        </a>
      </li>
      <?php } ?>
      <?php if(is_array($akses_menu) && in_array('diskon', $akses_menu)){ ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='diskon.php') echo 'active'; ?>" href="../billing/diskon.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-tags text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Diskon</span>
        </a>
      </li>
      <?php } ?>
      <?php if(is_array($akses_menu) && in_array('Tambahan_Biaya', $akses_menu)){ ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='biaya_tambahan.php') echo 'active'; ?>" href="../billing/biaya_tambahan.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-plus-circle text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Tambahan Biaya</span>
        </a>
      </li>
      <?php } ?>
      <?php if(is_array($akses_menu) && in_array('Laporan_pengeluaran', $akses_menu)){ ?>
      <li class="nav-item">
        <a class="nav-link  " href="../billing/pengeluaran.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-chart-bar text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Laporan pengeluaran</span>
        </a>
      </li>
      <?php } ?>
      <?php if(is_array($akses_menu) && in_array('Statistik', $akses_menu)){ ?>
      <li class="nav-item">
        <a class="nav-link  " href="../billing/statistics.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-chart-bar text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Statistik dan Laporan</span>
        </a>
      </li>
      <?php } ?>
            <?php
          
      }
      ?>

      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">Menu Pelanggan</h6>
      </li>


<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='broadcast.php') echo 'active'; ?>" href="../billing/broadcast.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-bullhorn text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">broadcast info</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='pelanggan_menunggak.php') echo 'active'; ?>" href="../billing/pelanggan_menunggak.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-user-clock text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pelanggan menunggak</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('broadcast_info', $akses_menu)){
            ?>
        <li class="nav-item">
        <a class="nav-link  " href="../billing/broadcast.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-bullhorn text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">broadcast info</span>
        </a>
      </li>
            <?php
          }

          if(is_array($akses_menu) && (in_array('Pelanggan_menunggak', $akses_menu) || in_array('Transaction', $akses_menu))){
            ?>
        <li class="nav-item">
          <a class="nav-link <?php if($current_file=='pelanggan_menunggak.php') echo 'active'; ?>" href="../billing/pelanggan_menunggak.php">
            <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
              <i class="fas fa-user-clock text-dark"></i>
            </div>
            <span class="nav-link-text ms-1">Pelanggan menunggak</span>
          </a>
        </li>
            <?php
          }
          
      }
      ?>


<?php if ($AKSES != "ASSISTANT") { ?>
   <li class="nav-item">
        <a class="nav-link <?php if($current_file=='daftar_pelanggan_berhenti.php') echo 'active'; ?>" href="../billing/daftar_pelanggan_berhenti.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-bullhorn text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pelanggan berhenti</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Pelanggan_berhenti', $akses_menu)){
            ?>
   <li class="nav-item">
        <a class="nav-link <?php if($current_file=='daftar_pelanggan_berhenti.php') echo 'active'; ?>" href="../billing/daftar_pelanggan_berhenti.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-bullhorn text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pelanggan berhenti</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>








      <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">Akun & Pengaturan</h6>
      </li>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='notification.php') echo 'active'; ?>" href="../billing/notification.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-bell text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Notifaction settings</span>
        </a>
      </li>

    
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Notification_settings', $akses_menu)){
            ?>
            <li class="nav-item">
        <a class="nav-link  " href="../billing/notification.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-bell text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Notifaction settings</span>
        </a>
      </li>

            <?php
          }

      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='system_setting.php') echo 'active'; ?>" href="../billing/system_setting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-cogs text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">System Setting</span>
        </a>
      </li>

<?php }
      else
      {
          if(is_array($akses_menu) && in_array('System_setting', $akses_menu)){
            ?>
            <li class="nav-item">
        <a class="nav-link  " href="../billing/system_setting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-cogs text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">System Setting</span>
        </a>
      </li>

            <?php
          }
      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='struk_setting.php') echo 'active'; ?>" href="../billing/struk_setting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-receipt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pengaturan Struk</span>
        </a>
      </li>

<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Struk_setting', $akses_menu)){
            ?>
            <li class="nav-item">
        <a class="nav-link  " href="../billing/struk_setting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-receipt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pengaturan Struk</span>
        </a>
      </li>

            <?php
          }
      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='portal_setting.php') echo 'active'; ?>" href="../billing/portal_setting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-file-contract text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pengaturan Halaman Pelanggan</span>
        </a>
      </li>

<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Portal_setting', $akses_menu)){
            ?>
            <li class="nav-item">
        <a class="nav-link <?php if($current_file=='portal_setting.php') echo 'active'; ?>" href="../billing/portal_setting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-file-contract text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Pengaturan Halaman Pelanggan</span>
        </a>
      </li>

            <?php
          }
      }
      ?>


<?php if ($AKSES != "ASSISTANT") { ?>
 

  <li class="nav-item">
        <a class="nav-link <?php if($current_file=='log_history.php') echo 'active'; ?>" href="../billing/log_history.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-history text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Log History</span>
        </a>
      </li>
    
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('log', $akses_menu)){
            ?>
   

  <li class="nav-item">
        <a class="nav-link <?php if($current_file=='log_history.php') echo 'active'; ?>" href="../billing/log_history.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-history text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Log History</span>
        </a>
      </li>
     
            <?php
          }
          
      }
      ?>



<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='backup_restore.php') echo 'active'; ?>" href="../billing/backup_restore.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-database text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Backup &amp; Restore</span>
        </a>
      </li>
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Backup_Restore', $akses_menu)){
            ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='backup_restore.php') echo 'active'; ?>" href="../billing/backup_restore.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-database text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Backup &amp; Restore</span>
        </a>
      </li>
            <?php
          }
          
      }
      ?>












<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='wabot.php') echo 'active'; ?>" href="../billing/wabot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fab fa-whatsapp text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Whatsapp BOT</span>
        </a>
      </li>

<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Whatsapp_BOT', $akses_menu)){
            ?>
          <li class="nav-item">
        <a class="nav-link  " href="../billing/wabot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fab fa-whatsapp text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Whatsapp BOT</span>
        </a>
      </li>

            <?php
          }

      }
      ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='telegrambot.php') echo 'active'; ?>" href="../billing/telegrambot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fab fa-telegram text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Telegram Bot</span>
        </a>
      </li>

<?php }
      else
      {
          if(is_array($akses_menu) && in_array('Telegram_BOT', $akses_menu)){
            ?>
          <li class="nav-item">
        <a class="nav-link  " href="../billing/telegrambot.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fab fa-telegram text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Telegram Bot</span>
        </a>
      </li>

            <?php
          }

      }
      ?>







<?php if ($AKSES != "ASSISTANT") { ?>
  

   <li class="nav-item">
        <a class="nav-link  " href="../billing/settingsapi.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-comments text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">API Intergrasi </span>
        </a>
      </li>
        
<?php }
      else
      {
          if(is_array($akses_menu) && in_array('API_Intergrasi', $akses_menu)){
            ?>
     

   <li class="nav-item">
        <a class="nav-link  " href="../billing/settingsapi.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-comments text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">API Intergrasi </span>
        </a>
      </li>
      
            <?php
          }
          
      }
      ?>















<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='paymentset.php') echo 'active'; ?>" href="../billing/paymentset.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-credit-card text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Payment Setting</span>
        </a>
      </li>
<?php }
      // Payment Setting SENGAJA tidak ada cabang utk ASSISTANT sama sekali
      // (bukan lagi diatur via checkbox "Hak Akses Menu") -- halaman ini urus
      // kredensial payment gateway/API key sensitif, dikunci khusus akun
      // utama di level halaman (paymentset.php) juga, jadi menu ini WAJIB
      // tidak pernah tampil ke assistant apapun isi $akses_menu-nya.
      ?>

<?php endif; // !$is_teknisi_only ?>

<?php if ($AKSES != "ASSISTANT") { ?>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='email_setting.php') echo 'active'; ?>" href="../billing/email_setting.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-envelope text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Email SMTP</span>
        </a>
      </li>
<?php }
      // Email SMTP SENGAJA tidak ada cabang utk ASSISTANT sama sekali (pola
      // sama Payment Setting di atas) -- halaman ini urus kredensial SMTP
      // sensitif, dikunci khusus akun utama di level halaman
      // (email_setting.php) juga, jadi menu ini WAJIB tidak pernah tampil
      // ke assistant apapun isi $akses_menu-nya.
      ?>

      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='user.php') echo 'active'; ?>" href="../billing/user.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-user-cog text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Profile and Account</span>
        </a>
      </li>

      <li class="nav-item sidebar-pinned-item">
        <a class="nav-link nav-link-pinned <?php if($current_file=='logout.php') echo 'active'; ?>" href="logout.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-sign-out-alt text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Log out</span>
        </a>
      </li>



    <?php if ($AKSES == "ADMIN") { ?>

       <li class="nav-item mt-3">
        <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder opacity-6">ADMINISTRATOR PANEL</h6>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='livechatadmin.php') echo 'active'; ?>" href="../billing/livechatadmin.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-comments text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Live chat super admin</span>
        </a>
      </li>
   
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='crmadmin.php') echo 'active'; ?>" href="../billing/crmadmin.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-users text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">User sewa billing</span>
        </a>
      </li>

   

     
         <li class="nav-item">
        <a class="nav-link <?php if($current_file=='crmglobalmap.php') echo 'active'; ?>" href="../billing/crmglobalmap.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-users text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">User Global maps</span>
        </a>
      </li>

          <!-- Separator -->
      <li class="nav-item">
        <hr class="horizontal dark mt-2 mb-2" />
      </li>

      <!-- ACS Menu Items for ADMIN -->
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='acs_servers_list.php') echo 'active'; ?>" href="../billing/acs_servers_list.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-server text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Daftar Server ACS</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='acs_add_server.php') echo 'active'; ?>" href="../billing/acs_add_server.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-plus-circle text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Tambah Server ACS</span>
        </a>
      </li>

      <!-- Separator -->
      <li class="nav-item">
        <hr class="horizontal dark mt-2 mb-2" />
      </li>

      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='radius.php') echo 'active'; ?>" href="../billing/radius.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-cog text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Radius setting</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link <?php if($current_file=='otp_signup_settings.php') echo 'active'; ?>" href="../billing/otp_signup_settings.php">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-key text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">OTP Sign Up Billing</span>
        </a>
      </li>
    <li class="nav-item">
        <a class="nav-link <?php if($current_file=='index.php' && strpos($_SERVER['REQUEST_URI'], '/panel/') !== false) echo 'active'; ?>" href="../panel/">
          <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
            <i class="fas fa-cog text-dark"></i>
          </div>
          <span class="nav-link-text ms-1">Panel setting</span>
        </a>
      </li>
    

    

    <?php } ?>
    </ul>

<script>
// Scroll sidebar to active menu on load
document.addEventListener('DOMContentLoaded', function() {
  var active = document.querySelector('.sidenav .nav-link.active');
  if (active) {
    var sidebar = document.getElementById('sidenav-main');
    if (sidebar && typeof sidebar.scrollTo === 'function') {
      var rect = active.getBoundingClientRect();
      var sidebarRect = sidebar.getBoundingClientRect();
      var offset = rect.top - sidebarRect.top - sidebar.clientHeight/2 + rect.height/2;
      sidebar.scrollTo({ top: sidebar.scrollTop + offset, behavior: 'smooth' });
    }
  }
});
</script>

</aside>

<main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg  ">




  <!-- Navbar -->
  <nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl sticky-top" id="navbarBlur" navbar-scroll="true" style="background: white; border-bottom: 1px solid #e2e8f0;">
    <div class="container-fluid py-2 px-3 d-flex justify-content-between align-items-center">
      <!-- Left content (empty) -->
      <div></div>
      
      <!-- Center: Logo and Clock (Horizontal) -->
      <div class="navbar-center d-flex align-items-center gap-3">
        <img src="<?= $logo_path ?>?v=<?= time() ?>" class="navbar-logo" alt="Logo" style="max-height: 45px; object-fit: contain;">
        <div id="navbarClock" class="navbar-clock">00:00:00</div>
      </div>
      
      <!-- Right: Hamburger button for mobile -->
      <div class="d-xl-none">
        <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
          <button id="toggleBtn" class="border-0 bg-white">
            <span class="bar"></span>
            <span class="bar"></span>
            <span class="bar"></span>
          </button>
        </a>
      </div>
    </div>
  </nav>










  <style>
.navbar-center {
  flex: 1;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 15px;
}

.navbar-logo {
  height: 45px;
  object-fit: contain;
  transition: filter 0.25s ease;
}

.navbar-clock {
  font-family: 'Courier New', monospace;
  font-size: 16px;
  font-weight: 600;
  color: #2563eb;
  letter-spacing: 1px;
  min-width: 100px;
  text-align: center;
}

.app-theme-dark .navbar-clock {
  color: #60a5fa;
}

.app-theme-dark .navbar-logo {
  filter: brightness(0) invert(1) contrast(1.08);
}

#toggleBtn {
  background: #fff;
  border: 2px solid #2563eb !important;
  border-radius: 8px;
  padding: 6px 8px;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 40px;
  min-height: 40px;
  align-items: center;
  justify-content: center;
}

#toggleBtn:hover {
  background: #f8fafc;
  border-color: #3b82f6;
  box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
}

#toggleBtn .bar {
  width: 20px;
  height: 2.5px;
  background: #2563eb;
  border-radius: 2px;
  transition: all 0.3s ease;
  display: block;
}

#toggleBtn:active .bar {
  background: #3b82f6;
}

.app-theme-dark #toggleBtn {
  background: #1e293b !important;
  border-color: #3b82f6 !important;
}

.app-theme-dark #toggleBtn:hover {
  background: #334155 !important;
}

.app-theme-dark #toggleBtn .bar {
  background: #60a5fa !important;
}

/* Responsive design for navbar center */
@media (max-width: 1024px) {
  .navbar-center {
    gap: 10px;
  }
  
  .navbar-logo {
    height: 40px;
  }
  
  .navbar-clock {
    font-size: 14px;
  }
}

@media (max-width: 768px) {
  .navbar-center {
    gap: 8px;
  }
  
  .navbar-logo {
    height: 35px;
  }
  
  .navbar-clock {
    font-size: 12px;
    min-width: 85px;
  }
}

@media (max-width: 576px) {
  .navbar-center {
    gap: 6px;
  }
  
  .navbar-logo {
    height: 30px;
  }
  
  .navbar-clock {
    font-size: 11px;
    min-width: 75px;
  }
}

/* Hide navbar on desktop view (>1024px) */
@media (min-width: 1025px) {
  #navbarBlur {
    display: none !important;
  }
}
</style>

<!-- Sidebar Toggle Handler -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  const toggleBtn = document.getElementById('iconNavbarSidenav');
  const body = document.body;
  const sidenav = document.getElementById('sidenav-main');
  
  if (toggleBtn) {
    toggleBtn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      
      // Toggle sidebar visibility
      body.classList.toggle('g-sidenav-show');
      
      // Optional: Close sidebar when clicking a menu item on mobile
      const isOpen = body.classList.contains('g-sidenav-show');
      
      // Log for debugging
      console.log('Sidebar toggled:', isOpen ? 'opened' : 'closed');
    });
  }
  
  // Close sidebar when clicking on a nav link on mobile (automatic behavior)
  const navLinks = document.querySelectorAll('.sidenav .nav-link');
  navLinks.forEach(link => {
    link.addEventListener('click', function() {
      const isMobile = window.innerWidth < 1200;
      if (isMobile) {
        body.classList.remove('g-sidenav-show');
      }
    });
  });
  
  // Update clock in navbar
  function updateNavbarClock() {
    const clockEl = document.getElementById('navbarClock');
    if (clockEl) {
      const now = getServerNow();
      const hours = String(now.getHours()).padStart(2, '0');
      const minutes = String(now.getMinutes()).padStart(2, '0');
      const seconds = String(now.getSeconds()).padStart(2, '0');
      clockEl.textContent = `${hours}:${minutes}:${seconds}`;
    }
  }
  
  // Update clock immediately and then every second
  updateNavbarClock();
  setInterval(updateNavbarClock, 1000);
});
</script>
  <!-- End Navbar -->

  <!-- Floating Live Chat Button -->
  <button class="floating-chat-btn" id="floatingChatBtn" title="Open Live Chat">
    <i class="fas fa-comments"></i>
    <span class="floating-chat-unread" id="floatingChatUnreadBadge" style="display:none;">0</span>
  </button>
  <?php 
                // Fetch bots for modal (same logic as livechat.php)
                $modal_bots = [];
                if ($AKSES == 'USER' || $AKSES == 'ADMIN') {
                    if ($AKSES == 'ADMIN') {
                        $sql_modal_bots = "SELECT id, namebot, addressbot, webhook FROM botwa ORDER BY id DESC";
                        $result_modal_bots = mysqli_query($conn, $sql_modal_bots);
                    } else {
                        $sql_modal_bots = "SELECT id, namebot, addressbot, webhook FROM botwa WHERE pemilik = ? ORDER BY id DESC";
                        $stmt_modal_bots = $conn->prepare($sql_modal_bots);
                        $stmt_modal_bots->bind_param('s', $ceknama);
                        $stmt_modal_bots->execute();
                        $result_modal_bots = $stmt_modal_bots->get_result();
                    }
                    
                    if ($result_modal_bots && mysqli_num_rows($result_modal_bots) > 0) {
                        while ($bot = mysqli_fetch_assoc($result_modal_bots)) {
                            // Default key (used when webhook URL doesn't carry a usable id= param)
                            $bot['webhookKey'] = 'bot' . $bot['id'];
                            $bot['webhookLogUrl'] = null;
                            if (!empty($bot['webhook'])) {
                                $parsedWebhook = parse_url($bot['webhook']);
                                if ($parsedWebhook && !empty($parsedWebhook['path'])) {
                                    $queryParams = [];
                                    if (!empty($parsedWebhook['query'])) {
                                        parse_str($parsedWebhook['query'], $queryParams);
                                    }
                                    if (!empty($queryParams['id'])) {
                                        // Real, unique bot key shared with the log file name on disk
                                        $bot['webhookKey'] = $queryParams['id'];
                                        $dirPath = rtrim(str_replace(basename($parsedWebhook['path']), '', $parsedWebhook['path']), '/');
                                        $logScheme = $parsedWebhook['scheme'] ?? $url_scheme;
                                        $logHost = $parsedWebhook['host'] ?? $url_host;
                                        $logPort = isset($parsedWebhook['port']) ? ':' . $parsedWebhook['port'] : '';
                                        // Same-origin static log file the bot's chat panel itself polls for new messages
                                        $bot['webhookLogUrl'] = $logScheme . '://' . $logHost . $logPort . $dirPath . '/webhook_log_' . rawurlencode($bot['webhookKey']) . '.txt';
                                    }
                                }
                            }
                            $modal_bots[] = $bot;
                        }
                    }
                }

                $live_chat_admin_param = ($AKSES == 'ADMIN') ? 'admin' : (string)$ceknama;
                $wabot_log_sources = [];
                foreach ($modal_bots as $mb) {
                    if (!empty($mb['webhookLogUrl'])) {
                        $wabot_log_sources[] = ['key' => $mb['webhookKey'], 'url' => $mb['webhookLogUrl']];
                    }
                }
              ?>
  <!-- Live Chat Modal -->
  <div class="modal fade" id="liveChatModal" tabindex="-1" aria-labelledby="liveChatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center gap-2">
            <i class="fas fa-comments"></i>
            <h5 class="modal-title" id="liveChatModalLabel">Live Chat & Bot Webhooks</h5>
          </div>
       <button type="button"
        class="livechat-close-btn"
        data-bs-dismiss="modal"
        aria-label="Close">
    <i class="fas fa-times"></i>
</button></div>
        <div class="modal-body p-0">
       

          <!-- Tab Content -->
          <div class="tab-content" id="liveChatTabContent">
            <!-- Live Chat Panel -->
            <div class="tab-pane fade show active" id="modal-livechat-panel" role="tabpanel" aria-labelledby="modal-livechat-tab">
              <iframe src="<?php 
                if ($AKSES == 'ADMIN') {
                    echo $config['URL'] . '/crm/chat/index.php?admin=admin';
                } else {
                    echo $config['URL'] . '/crm/chat/index.php?admin=' . urlencode($ceknama);
                }
              ?>"></iframe>
            </div>

            <!-- Bot Webhook Panels -->
            <?php foreach ($modal_bots as $bot): ?>
            <div class="tab-pane fade" id="modal-webhook-panel-<?php echo htmlspecialchars($bot['webhookKey']); ?>" role="tabpanel" aria-labelledby="modal-webhook-tab-<?php echo htmlspecialchars($bot['webhookKey']); ?>">
              <div>
                <?php 
                  $webhookUrl = $bot['webhook'];
                  if (!empty($webhookUrl)) {
                    $tokenData = [
                      'namebot' => $bot['namebot'],
                      'timestamp' => time(),
                      'user' => $ceknama
                    ];
                    $token = base64_encode(json_encode($tokenData));
                    $webhookUrlWithToken = $webhookUrl . (strpos($webhookUrl, '?') !== false ? '&' : '?') . 'auth_token=' . urlencode($token);
                    echo '<iframe src="' . htmlspecialchars($webhookUrlWithToken) . '"></iframe>';
                  } else {
                    echo '<div class="alert alert-warning m-3">Webhook URL tidak tersedia untuk bot ini.</div>';
                  }
                ?>
              </div>
            </div>
            <?php endforeach; ?>




            <!-- Empty State if No Bots -->
            <?php if (count($modal_bots) == 0): ?>
            <div class="tab-pane fade" role="tabpanel">
              <div class="text-center d-flex flex-column align-items-center justify-content-center" style="height: 100%; padding: 3rem 1rem;">
                <div class="mb-3">
                  <i class="fas fa-robot" style="font-size: 48px; color: #ccc;"></i>
                </div>
                <h5 class="text-muted">Belum ada Bot WhatsApp</h5>
                <p class="text-muted mb-3">Anda belum membuat bot WhatsApp. Klik tombol di atas untuk menambahkan bot pertama Anda.</p>
                <a href="wabot.php" class="btn btn-primary btn-sm">
                  <i class="fas fa-plus"></i> Buat Bot Sekarang
                </a>
              </div>
            </div>
            <?php endif; ?>
          </div>
   <!-- Tabs Navigation -->
          <div class="border-bottom">
            <ul class="nav nav-tabs mb-0" role="tablist" id="liveChatTabs" style="display: flex; flex-wrap: nowrap; overflow-x: auto;">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="modal-livechat-tab" data-tab-target="#modal-livechat-panel" type="button" role="tab" aria-controls="modal-livechat-panel" aria-selected="true">
                  <i class="fas fa-headset"></i> Live Chat
                  <span class="badge badge-unread badge-livechat" style="display:none; margin-left: 6px; background-color: #dc3545;">0</span>
                </button>
              </li>
            
              <?php foreach ($modal_bots as $bot): ?>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="modal-webhook-tab-<?php echo htmlspecialchars($bot['webhookKey']); ?>" data-tab-target="#modal-webhook-panel-<?php echo htmlspecialchars($bot['webhookKey']); ?>" type="button" role="tab" aria-controls="modal-webhook-panel-<?php echo htmlspecialchars($bot['webhookKey']); ?>" aria-selected="false">
                  <i class="fas fa-webhook"></i> <?php echo htmlspecialchars($bot['namebot']); ?>
                  <span class="badge badge-unread badge-webhook-<?php echo htmlspecialchars($bot['webhookKey']); ?>" style="display:none; margin-left: 6px; background-color: #dc3545;">0</span>
                </button>
              </li>
              <?php endforeach; ?>
              <li class="nav-item ms-auto" role="presentation" style="margin-left:auto;">
                <a href="wabot.php" class="nav-link tab-add-bot" style="color:#fff; background:#22c55e; border-radius:0 0.5rem 0 0; font-weight:700; display:flex; align-items:center; gap:6px;">
                  <i class="fas fa-plus"></i> Tambah WA Bot
                </a>
              </li>
            </ul>
          </div>

        </div>
      </div>
    </div>
  </div>

  <!-- Floating Chat Button Styling & Script -->
  <style>
    /* ============================
   Live Chat Close Button
============================ */

#liveChatModal .livechat-close-btn{
    width:42px;
    height:42px;
    border:none;
    border-radius:50%;
    background:#ef4444;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:18px;
    transition:all .25s ease;
    box-shadow:0 4px 12px rgba(239,68,68,.35);
    flex-shrink:0;
}

#liveChatModal .livechat-close-btn:hover{
    background:#dc2626;
    transform:scale(1.08) rotate(90deg);
    box-shadow:0 8px 18px rgba(220,38,38,.45);
}

#liveChatModal .livechat-close-btn:active{
    transform:scale(.95);
}

#liveChatModal .livechat-close-btn:focus{
    outline:none;
    box-shadow:
        0 0 0 4px rgba(239,68,68,.25),
        0 8px 18px rgba(220,38,38,.45);
}

#liveChatModal .livechat-close-btn i,
#liveChatModal .livechat-close-btn svg{
    font-size:18px;
    line-height:1;
}

@media (max-width:768px){
    #liveChatModal .livechat-close-btn{
        width:38px;
        height:38px;
        font-size:16px;
    }
}
/* =========================
   FIX GAP DI ATAS TAB
   (Tambahkan paling bawah)
========================= */
/* Tengah horizontal */
#liveChatModal .nav-tabs{
    display:flex !important;
    justify-content:center !important;
    align-items:center !important;
    width:100%;
    gap:20px;
}

#liveChatModal .nav-tabs .nav-item{
    flex:0 0 auto;
}

#liveChatModal .nav-tabs .nav-link{
    white-space:nowrap;
}
#liveChatModal .modal-body{
    display:flex !important;
    flex-direction:column !important;
    padding:0 !important;
    margin:0 !important;
    overflow:hidden !important;
}

#liveChatModal #liveChatTabContent{
    flex:1 1 auto !important;
    min-height:0 !important;
    margin:0 !important;
    padding:0 !important;
    overflow:hidden !important;
}

#liveChatModal .tab-content{
    flex:1 1 auto !important;
    margin:0 !important;
    padding:0 !important;
}

#liveChatModal .tab-pane{
    margin:0 !important;
    padding:0 !important;
    min-height:0 !important;
    overflow:hidden !important;
}

#liveChatModal .tab-pane.active{
    display:flex !important;
    flex-direction:column !important;
}

#liveChatModal .tab-pane > div{
    flex:1 !important;
    min-height:0 !important;
}

#liveChatModal .tab-pane iframe{
    display:block !important;
    width:100% !important;
    height:100% !important;
    border:0 !important;
}

#liveChatModal .border-bottom{
    margin:0 !important;
    padding:0 !important;
    flex-shrink:0 !important;
}

#liveChatModal .nav-tabs{
    margin:0 !important;
}
    /* Floating Chat Button */
    .floating-chat-btn {
      position: fixed;
      bottom: 30px;
      right: 30px;
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
      color: white;
      border: none;
      font-size: 36px;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(249, 115, 22, 0.4);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 999;
      transition: all 0.3s ease;
      animation: float-in 0.5s ease-out;
    }

    .floating-chat-btn:hover {
      transform: scale(1.1);
      box-shadow: 0 6px 20px rgba(249, 115, 22, 0.6);
      background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
    }

    .floating-chat-btn:active {
      transform: scale(0.95);
    }

    .floating-chat-unread {
      position: absolute;
      top: 6px;
      right: 4px;
      min-width: 26px;
      height: 26px;
      padding: 0 7px;
      border-radius: 999px;
      background: #dc2626;
      color: #fff;
      font-size: 12px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 2px solid rgba(255, 255, 255, 0.95);
      box-shadow: 0 6px 16px rgba(220, 38, 38, 0.35);
      line-height: 1;
    }

    @keyframes float-in {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
      .floating-chat-btn {
        bottom: 20px;
        right: 20px;
        width: 65px;
        height: 65px;
        font-size: 28px;
      }

      .floating-chat-unread {
        top: 3px;
        right: 1px;
        min-width: 22px;
        height: 22px;
        font-size: 11px;
      }
    }

    @media (max-width: 576px) {
      .floating-chat-btn {
        bottom: 15px;
        right: 15px;
        width: 55px;
        height: 55px;
        font-size: 24px;
      }

      .floating-chat-unread {
        min-width: 20px;
        height: 20px;
        font-size: 10px;
      }
    }


    /* Remove modal padding on all mobile sizes */
    @media (max-width: 992px) {
      .modal.show {
        padding: 0 !important;
      }

      .modal-backdrop {
        z-index: 999 !important;
      }
    }

    @media (max-width: 768px) {
      #liveChatModal {
        --bs-modal-margin: 0;
        --bs-modal-padding: 0;
      }

      #liveChatModal.modal {
        padding: 0 !important;
        overflow: hidden;
      }

      .modal-open {
        overflow: hidden;
      }

      #liveChatModal .modal-dialog {
        margin: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
        height: 100vh !important;
      }

      #liveChatModal .modal-content {
        border-radius: 0;
        height: 100vh;
        display: flex;
        flex-direction: column;
        border: none;
        overflow: hidden;
      }

      #liveChatModal .modal-header {
        flex-shrink: 0;
        border-bottom: 1px solid #e2e8f0;
        padding: 12px 16px;
      }

      #liveChatModal .modal-body {
        flex: 1;
        overflow: hidden !important;
        padding: 0 !important;
        border: none;
        display: flex;
        flex-direction: column;
      }

      #liveChatModal .border-bottom {
        flex-shrink: 0;
        border-bottom: 1px solid #e2e8f0;
      }

      #liveChatModal .nav-tabs {
        flex-shrink: 0;
        margin: 0;
      }

      #liveChatModal #liveChatTabContent {
        flex: 1;
        height: 100%;
        margin: 0;
        padding: 0;
        overflow-y: auto;
      }

      #liveChatModal .tab-pane {
        height: 100% !important;
        overflow: hidden !important;
      }
    }

    @media (max-width: 576px) {
      #liveChatModal .modal-dialog {
        height: 100vh !important;
      }

      #liveChatModal .modal-content {
        height: 100vh;
        overflow: hidden;
      }

      #liveChatModal #liveChatTabContent {
        flex: 1;
        height: 100%;
        overflow-y: auto;
      }
    }

    #liveChatModal .nav-tabs .nav-link {
      color: var(--bs-body-color, #71717a);
      border: none;
      border-bottom: 3px solid transparent;
      transition: all 0.3s ease;
      padding: 12px 16px !important;
      position: relative;
      background: transparent;
      font-weight: 500;
    }

    #liveChatModal .nav-tabs .nav-link.active {
      border-bottom: 3px solid #f97316;
      color: #f97316 !important;
      font-weight: 700;
      background: var(--bs-body-bg, #fff);
    }

    #liveChatModal .nav-tabs .nav-link:hover {
      color: #f97316;
      border-bottom: 3px solid #fed7aa;
    }

    #liveChatModal .nav-tabs .nav-link i {
      margin-right: 6px;
    }
    

    #liveChatModal .tab-pane {
      animation: fadeIn 0.3s ease-in;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    #liveChatModal .badge-unread {
      font-size: 11px;
      padding: 3px 6px;
      border-radius: 10px;
      font-weight: bold;
      animation: pulse-badge 1.5s infinite;
    }

    @keyframes pulse-badge {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.6; }
    }

    #liveChatModal .tab-add-bot {
      color: #fff !important;
      background: #22c55e !important;
      border: none !important;
      font-weight: 700;
      border-radius: 0 0.5rem 0 0 !important;
      margin-left: 8px;
      transition: none !important;
    }

    #liveChatModal .tab-add-bot:hover,
    #liveChatModal .tab-add-bot:focus,
    #liveChatModal .tab-add-bot:active {
      color: #fff !important;
      background: #16a34a !important;
      text-decoration: none;
    }

    /* Force deterministic tab visibility */
    #liveChatModal .tab-content > .tab-pane {
      display: none;
      height: 100%;
    }

    #liveChatModal .tab-content > .tab-pane.active {
      display: flex !important;
      flex-direction: column;
      min-height: 0;
    }

    /* Dark theme support */
    [data-bs-theme="dark"] .floating-chat-btn {
      background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
      box-shadow: 0 4px 12px rgba(249, 115, 22, 0.4);
    }

    [data-bs-theme="dark"] .floating-chat-btn:hover {
      box-shadow: 0 6px 20px rgba(249, 115, 22, 0.6);
      background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      // Hide floating button if inside iframe
      if (window.self !== window.top) {
        const floatingBtn = document.getElementById('floatingChatBtn');
        if (floatingBtn) {
          floatingBtn.style.display = 'none';
        }
        return; // Exit early, don't initialize modal
      }

      const floatingBtn = document.getElementById('floatingChatBtn');
      const floatingUnreadBadge = document.getElementById('floatingChatUnreadBadge');
      const modal = new bootstrap.Modal(document.getElementById('liveChatModal'), {
        keyboard: false,
        backdrop: 'static'
      });

      // Real unread sources: Live Chat unread is authoritative from the `messages` table
      // (is_read flag, already maintained by crm/chat/load_chat.php). Wabot unread is read
      // straight from each bot's own webhook log file. Polling both directly means the badge
      // reflects real data and survives page reloads/navigation instead of resetting to 0.
      const LIVECHAT_ADMIN_PARAM = <?php echo json_encode($live_chat_admin_param, JSON_UNESCAPED_SLASHES); ?>;
      const WABOT_LOG_SOURCES = <?php echo json_encode($wabot_log_sources, JSON_UNESCAPED_SLASHES); ?>;
      const POLL_INTERVAL_MS = 15000;

      function setBadgeCount(badgeEl, tabButton, count) {
        if (!badgeEl) return;
        if (count > 0) {
          badgeEl.textContent = count > 99 ? '99+' : String(count);
          badgeEl.style.display = 'inline-block';
          if (tabButton) tabButton.classList.add('has-unread');
        } else {
          badgeEl.textContent = '0';
          badgeEl.style.display = 'none';
          if (tabButton) tabButton.classList.remove('has-unread');
        }
        updateFloatingUnreadBadge();
      }

      function updateFloatingUnreadBadge() {
        if (!floatingUnreadBadge) return;

        let totalUnread = 0;
        document.querySelectorAll('#liveChatModal .badge-unread').forEach((badge) => {
          const count = parseInt(badge.textContent || '0', 10) || 0;
          if (badge.style.display !== 'none' && count > 0) {
            totalUnread += count;
          }
        });

        if (totalUnread > 0) {
          floatingUnreadBadge.textContent = totalUnread > 99 ? '99+' : String(totalUnread);
          floatingUnreadBadge.style.display = 'flex';
        } else {
          floatingUnreadBadge.textContent = '0';
          floatingUnreadBadge.style.display = 'none';
        }
      }

      // Live Chat: total unread across ALL contacts, straight from the database.
      function pollLiveChatUnread() {
        fetch('api/chat_contacts.php?admin=' + encodeURIComponent(LIVECHAT_ADMIN_PARAM), {
            credentials: 'same-origin'
          })
          .then((res) => res.json())
          .then((res) => {
            if (!res || !res.success || !Array.isArray(res.data)) return;
            const total = res.data.reduce((sum, c) => sum + (parseInt(c.unread_count, 10) || 0), 0);
            setBadgeCount(
              document.querySelector('#liveChatModal .badge-livechat'),
              document.getElementById('modal-livechat-tab'),
              total
            );
          })
          .catch(() => {});
      }

      // Wabot: count incoming lines (from != me) appended since the admin last opened that bot's tab.
      function pollWabotUnread(source) {
        const storageKey = 'wabotLastSeen_' + source.key;
        let lastSeen = parseInt(localStorage.getItem(storageKey) || '0', 10);
        if (!lastSeen) {
          // First time we ever see this bot: baseline to "now" so old history isn't counted.
          lastSeen = Date.now();
          localStorage.setItem(storageKey, String(lastSeen));
          return;
        }

        fetch(source.url + (source.url.indexOf('?') === -1 ? '?' : '&') + 'ts=' + Date.now())
          .then((res) => res.text())
          .then((text) => {
            let count = 0;
            text.split('\n').forEach((line) => {
              const idx = line.indexOf('{');
              if (idx === -1) return;
              try {
                const obj = JSON.parse(line.substring(idx));
                const from = obj.from || '';
                if (!from || from.indexOf('@newsletter') !== -1) return;
                const isFromMe = from === 'me' || from.indexOf('me in ') === 0;
                if (isFromMe) return;
                const ts = new Date(obj.timestamp || 0).getTime();
                if (ts > lastSeen) count++;
              } catch (e) {}
            });
            setBadgeCount(
              document.querySelector('.badge-webhook-' + source.key),
              document.getElementById('modal-webhook-tab-' + source.key),
              count
            );
          })
          .catch(() => {});
      }

      function pollAllWabotUnread() {
        WABOT_LOG_SOURCES.forEach(pollWabotUnread);
      }

      // Open modal when floating button clicked
      if (floatingBtn) {
        floatingBtn.addEventListener('click', function(e) {
          e.preventDefault();
          modal.show();
        });
      }

      // Tab switching in modal
      const tabButtons = document.querySelectorAll('#liveChatTabs [data-tab-target]');

      function clearUnreadForTab(tabButton) {
        if (!tabButton || !tabButton.id) return;
        const tabId = tabButton.id;

        if (tabId === 'modal-livechat-tab') {
          // Don't zero optimistically: the DB may still have unread messages from other
          // contacts. Re-poll immediately so the badge reflects the real current state.
          pollLiveChatUnread();
        } else if (tabId.startsWith('modal-webhook-tab-')) {
          const key = tabId.replace('modal-webhook-tab-', '');
          localStorage.setItem('wabotLastSeen_' + key, String(Date.now()));
          const badge = document.querySelector('.badge-webhook-' + key);
          setBadgeCount(badge, tabButton, 0);
        }

        updateFloatingUnreadBadge();
      }

      function activateTabManually(tabButton) {
        if (!tabButton) return;
        const targetSelector = tabButton.getAttribute('data-tab-target');
        if (!targetSelector) return;
        const targetPane = document.querySelector(targetSelector);
        if (!targetPane) return;

        tabButtons.forEach((btn) => {
          btn.classList.remove('active');
          btn.setAttribute('aria-selected', 'false');
        });

        document.querySelectorAll('#liveChatModal .tab-content .tab-pane').forEach((pane) => {
          pane.classList.remove('show', 'active');
        });

        tabButton.classList.add('active');
        tabButton.setAttribute('aria-selected', 'true');
        targetPane.classList.add('show', 'active');

        clearUnreadForTab(tabButton);
        console.log('✅ Live Chat Modal tab switched to:', tabButton.id);
      }

      tabButtons.forEach((button) => {
        button.addEventListener('click', function(e) {
          e.preventDefault();
          activateTabManually(this);
        });
      });

      document.getElementById('liveChatModal').addEventListener('shown.bs.modal', function() {
        const activeTab = document.querySelector('#liveChatTabs [data-tab-target].active');
        if (activeTab) {
          clearUnreadForTab(activeTab);
        }
      });

      // Listen for messages from iframes in modal — used as an early nudge to re-poll
      // sooner than the next interval tick. The poll functions above remain the single
      // source of truth for the actual count, so there's no double counting/drift.
      window.addEventListener('message', function(event) {
        if (!event.data || !event.data.type) return;

        if (event.data.type === 'NEW_MESSAGE') {
          const source = event.data.source || 'livechat';

          if (source === 'livechat') {
            pollLiveChatUnread();
          } else if (source.startsWith('webhook-')) {
            const key = source.replace('webhook-', '');
            const match = WABOT_LOG_SOURCES.find((s) => s.key === key);
            if (match) pollWabotUnread(match);
          }

          playNotificationSound();
        }
      }, false);

      // Play notification sound
      function playNotificationSound() {
        const audio = new Audio('data:audio/wav;base64,UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAAB9AAACABAAZGF0YQIAAAAAAA==');
        audio.play().catch(() => {});
      }

      updateFloatingUnreadBadge();
      pollLiveChatUnread();
      pollAllWabotUnread();
      setInterval(pollLiveChatUnread, POLL_INTERVAL_MS);
      setInterval(pollAllWabotUnread, POLL_INTERVAL_MS);
      console.log('✅ Floating Live Chat Button initialized');
    });
  </script>