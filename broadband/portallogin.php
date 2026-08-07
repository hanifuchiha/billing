<?php
session_start();
// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
session_write_close();
// Cek apakah cookie sudah ada
if (isset($_COOKIE['idselect'])) {
  // Redirect otomatis ke portal.php
  header("Location: portal_baru.php?cari=".htmlspecialchars($_COOKIE['idselect'], ENT_QUOTES, 'UTF-8'));
  exit;
}

// Simpan konfigurasi
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$URL = isset($config['URL']) ? rtrim($config['URL'], '/') : '';
$iduser = isset($_SESSION['id']) ? (string) $_SESSION['id'] : '';
$company_name = isset($config['perusahaan']) && $config['perusahaan'] !== '' ? $config['perusahaan'] : 'Billing Internet';
$tagline = isset($config['tagline']) && $config['tagline'] !== '' ? $config['tagline'] : 'Akses portal pelanggan untuk login, tagihan, dan bantuan admin.';
$primary_color = isset($config['extracted_primary_color']) ? $config['extracted_primary_color'] : '#f68013';
$secondary_color = isset($config['extracted_secondary_color']) ? $config['extracted_secondary_color'] : '#f68012';

$brand_param = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
$brand_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $brand_param);
$brand_mode = false;
$brand_name = $company_name;
$brand_logo_url = '';
$brand_initials = 'BR';

if ($brand_username !== '') {
  $brand_db_file = __DIR__ . '/../koneksidb.php';
  if (is_file($brand_db_file)) {
    include_once $brand_db_file;
    if (isset($conn) && $conn instanceof mysqli) {
      $stmt_brand = $conn->prepare("SELECT USERNAME, inisial, domain FROM `user` WHERE USERNAME = ? LIMIT 1");
      if ($stmt_brand) {
        $stmt_brand->bind_param("s", $brand_username);
        $stmt_brand->execute();
        $res_brand = $stmt_brand->get_result();
        if ($res_brand && $res_brand->num_rows > 0) {
          $brand_user = $res_brand->fetch_assoc();
          $brand_mode = true;
          $brand_name = isset($brand_user['USERNAME']) && $brand_user['USERNAME'] !== '' ? (string)$brand_user['USERNAME'] : $brand_username;
          $brand_initials = isset($brand_user['inisial']) && (string)$brand_user['inisial'] !== ''
            ? strtoupper((string)$brand_user['inisial'])
            : strtoupper(substr($brand_name, 0, 2));
        }
        $stmt_brand->close();
      }
    }
  }

  if (!$brand_mode) {
    $brand_mode = true;
    $brand_name = strtoupper($brand_username);
    $brand_initials = strtoupper(substr($brand_name, 0, 2));
  }

  $brand_logo_abs = __DIR__ . '/../../../dokumen/logo/profile-' . $brand_username . '.png';
  if (is_file($brand_logo_abs)) {
    $brand_logo_url = '../../../dokumen/logo/profile-' . $brand_username . '.png';
  } else {
    $fallback_logo_abs = __DIR__ . '/../../../dokumen/logo/logo.png';
    if (is_file($fallback_logo_abs)) {
      $brand_logo_url = '../../../dokumen/logo/logo.png';
    }
  }
}

$brand_query_suffix = $brand_mode ? ('?brand=' . rawurlencode($brand_username)) : '';
$chat_target = $brand_mode ? $brand_username : 'admin';

// Isi Pengaturan Halaman Pelanggan (nama product, teks FAQ/Refund Policy/
// Syarat & Ketentuan/Kontak), diatur per akun di menu "Pengaturan Halaman
// Pelanggan" (lihat portal_links_helper.php).
$portal_links_helper_file = __DIR__ . '/../portal_links_helper.php';
$portal_links = [
  'product_name' => '', 'tagline' => '',
  'feature1_title' => '', 'feature1_text' => '',
  'feature2_title' => '', 'feature2_text' => '',
  'feature3_title' => '', 'feature3_text' => '',
  'faq_text' => '', 'refund_text' => '', 'terms_text' => '', 'contact_text' => '',
];
if (is_file($portal_links_helper_file)) {
  require_once $portal_links_helper_file;
  $portal_links = get_portal_links($chat_target);
}

// Nama product & tagline yang tampil ke pelanggan lebih diutamakan dari field
// ini (diatur admin per akun) -- $brand_name sebelumnya jatuh ke username CRM
// mentah (mis. "grasi-starlite_wallet"), $tagline sebelumnya SELALU global dari
// config.json (sama utk semua reseller apapun brand-nya).
if (trim((string)($portal_links['product_name'] ?? '')) !== '') {
  $brand_name = trim((string)$portal_links['product_name']);
}
if ($brand_mode && trim((string)($portal_links['tagline'] ?? '')) !== '') {
  $tagline = trim((string)$portal_links['tagline']);
}

// 3 kartu fitur panel kiri -- fallback ke teks default lama kalau admin belum
// mengisi (supaya akun yang belum pernah buka "Pengaturan Halaman Pelanggan"
// tetap tampil normal seperti sebelumnya, bukan kartu kosong).
$portal_feature1_title = trim((string)($portal_links['feature1_title'] ?? '')) !== '' ? trim((string)$portal_links['feature1_title']) : 'Login Cepat';
$portal_feature1_text  = trim((string)($portal_links['feature1_text'] ?? ''))  !== '' ? trim((string)$portal_links['feature1_text'])  : 'Pilih login dengan OTP WhatsApp atau password pelanggan tanpa alur yang rumit.';
$portal_feature2_title = trim((string)($portal_links['feature2_title'] ?? '')) !== '' ? trim((string)$portal_links['feature2_title']) : 'Akses Tagihan';
$portal_feature2_text  = trim((string)($portal_links['feature2_text'] ?? ''))  !== '' ? trim((string)$portal_links['feature2_text'])  : 'Masuk ke portal pelanggan untuk melihat status layanan dan informasi pembayaran terbaru.';
$portal_feature3_title = trim((string)($portal_links['feature3_title'] ?? '')) !== '' ? trim((string)$portal_links['feature3_title']) : 'Bantuan Admin';
$portal_feature3_text  = trim((string)($portal_links['feature3_text'] ?? ''))  !== '' ? trim((string)$portal_links['feature3_text'])  : 'Hubungi customer service langsung dari halaman ini jika mengalami kendala saat login.';

$brand_name_safe = htmlspecialchars($brand_name, ENT_QUOTES, 'UTF-8');
$brand_logo_safe = htmlspecialchars($brand_logo_url, ENT_QUOTES, 'UTF-8');
$portal_links_items = [
  ['label' => 'FAQ', 'icon' => 'bi-question-circle-fill', 'text' => $portal_links['faq_text']],
  ['label' => 'Kebijakan Refund', 'icon' => 'bi-arrow-counterclockwise', 'text' => $portal_links['refund_text']],
  ['label' => 'Syarat & Ketentuan', 'icon' => 'bi-file-earmark-text-fill', 'text' => $portal_links['terms_text']],
  ['label' => 'Kontak', 'icon' => 'bi-telephone-fill', 'text' => $portal_links['contact_text']],
];
$portal_links_items = array_values(array_filter($portal_links_items, function ($item) {
  return trim((string)$item['text']) !== '';
}));

$ui_visibility_defaults = [
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
];
$ui_user_candidates = [
  $_SESSION['PEMILIK'] ?? '',
  $_SESSION['username'] ?? '',
  $_SESSION['userlogin'] ?? '',
];
foreach ($ui_user_candidates as $candidate) {
  $safe_candidate = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$candidate);
  if ($safe_candidate === '') {
    continue;
  }
  $ui_settings_file = __DIR__ . '/../settings/dashboard-cards-' . $safe_candidate . '.json';
  if (!is_file($ui_settings_file)) {
    continue;
  }
  $ui_raw = @file_get_contents($ui_settings_file);
  $ui_decoded = json_decode((string)$ui_raw, true);
  if (!is_array($ui_decoded)) {
    continue;
  }
  foreach ($ui_visibility_defaults as $ui_key => $ui_default) {
    if (array_key_exists($ui_key, $ui_decoded)) {
      $ui_visibility_defaults[$ui_key] = (bool)$ui_decoded[$ui_key];
    }
  }
  break;
}








?>

<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if ($brand_logo_url !== ''): ?>
  <link rel="apple-touch-icon" sizes="76x76" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <link rel="shortcut icon" type="image/png" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <?php else: ?>
  <link rel="apple-touch-icon" sizes="76x76" href="../../../dokumen/logo/logo.png?v=<?= time() ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="../../../dokumen/logo/logo.png?v=<?= time() ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="../../../dokumen/logo/logo.png?v=<?= time() ?>">
  <link rel="shortcut icon" type="image/png" href="../../../dokumen/logo/logo.png?v=<?= time() ?>">
  <?php endif; ?>
  <title>Login Pelanggan | <?= $brand_mode ? $brand_name_safe : 'Quenby Teknik' ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <script>
    window.billingUiVisibility = <?php echo json_encode($ui_visibility_defaults, JSON_UNESCAPED_UNICODE); ?>;
    
    // Determine current page name for per-page button visibility
    function getCurrentPageName() {
        const pathname = window.location.pathname || '';
        const filename = pathname.split('/').pop() || 'unknown';
        return filename.replace('.php', '').toLowerCase();
    }

    // Map page filenames to setting keys
    function getPageButtonSettingKey(pageName) {
        const pageMap = {
            'dashboard': 'buttons_dashboard',
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

      // Apply global button visibility
      if (settings.buttons_semua_halaman === false) {
        mainContent.querySelectorAll('.btn, button, input[type="button"], input[type="submit"], a.btn').forEach(function(el) {
          if (el.closest('.modal')) return;
          el.style.display = 'none';
        });
      } else {
        // If global buttons are enabled, check per-page button visibility
        const currentPage = getCurrentPageName();
        const pageButtonKey = getPageButtonSettingKey(currentPage);
        
        if (pageButtonKey && settings[pageButtonKey] === false) {
          mainContent.querySelectorAll('.btn, button, input[type="button"], input[type="submit"], a.btn').forEach(function(el) {
            if (el.closest('.modal')) return;
            el.style.display = 'none';
          });
        }
      }
    });
  </script>
  <style>
    :root {
      --primary-green: <?= htmlspecialchars($primary_color, ENT_QUOTES, 'UTF-8') ?>;
      --dark-green: <?= htmlspecialchars($secondary_color, ENT_QUOTES, 'UTF-8') ?>;
      --orange: <?= htmlspecialchars($primary_color, ENT_QUOTES, 'UTF-8') ?>;
      --dark-orange: <?= htmlspecialchars($secondary_color, ENT_QUOTES, 'UTF-8') ?>;
      --white: #ffffff;
      --light-gray: #f8fafc;
      --border-color: #e2e8f0;
      --text-dark: #1e293b;
      --text-muted: #64748b;
      --panel-shadow: 0 20px 60px rgba(15, 23, 42, 0.14);
    }

    * {
      box-sizing: border-box;
    }


    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      min-height: 100vh;
      margin: 0;
      background: #f5f5f5;
      color: var(--text-dark);
    }

    .login-grid {
      display: grid;
      grid-template-columns: minmax(320px, 1fr) minmax(420px, 1fr);
      min-height: 100vh;
      width: 100%;
      background: #fff;
    }

    .login-branding {
      position: relative;
      overflow: hidden;
      padding: 72px 56px;
      background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
      color: #fff;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 24px;
    }

    .login-branding::before,
    .login-branding::after {
      content: "";
      position: absolute;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.09);
      pointer-events: none;
    }

    .login-branding::before {
      width: 360px;
      height: 360px;
      right: -140px;
      bottom: -140px;
    }

    .login-branding::after {
      width: 240px;
      height: 240px;
      left: -80px;
      top: -70px;
    }

    .login-branding > * {
      position: relative;
      z-index: 1;
    }

    .branding-copy h1 {
      margin: 0 0 12px;
      font-size: clamp(2.4rem, 4vw, 3.4rem);
      line-height: 1.06;
      font-weight: 700;
      letter-spacing: -0.03em;
    }

    .branding-copy p {
      margin: 0;
      max-width: 520px;
      font-size: 1rem;
      line-height: 1.7;
      color: rgba(255, 255, 255, 0.9);
    }

    .brand-identity {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 8px;
    }

    .brand-avatar {
      width: 84px;
      height: 84px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.15);
      border: 2px solid rgba(255, 255, 255, 0.35);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 1.2rem;
      overflow: hidden;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
      flex-shrink: 0;
    }

    .brand-avatar img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .brand-meta {
      display: flex;
      flex-direction: column;
    }

    .brand-meta strong {
      font-size: 1rem;
      line-height: 1.2;
      font-weight: 700;
      letter-spacing: 0.01em;
    }

    .branding-features {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
      margin-top: 8px;
    }

    .branding-feature {
      padding: 18px 16px;
      border-radius: 18px;
      background: rgba(255, 255, 255, 0.14);
      border: 1px solid rgba(255, 255, 255, 0.14);
      min-height: 124px;
    }

    .branding-feature i {
      font-size: 1.35rem;
      margin-bottom: 14px;
      display: inline-block;
    }

    .branding-feature strong {
      display: block;
      margin-bottom: 8px;
      font-size: 0.95rem;
      font-weight: 600;
    }

    .branding-feature span {
      display: block;
      font-size: 0.84rem;
      line-height: 1.55;
      color: rgba(255, 255, 255, 0.84);
    }

    .login-form-side {
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 64px 44px;
      background: #fff;
    }

    .form-shell {
      width: 100%;
      max-width: 470px;
    }

    .form-header {
      margin-bottom: 28px;
      text-align: center;
    }

    .form-header h2 {
      margin: 0 0 8px;
      color: #333;
      font-size: 2rem;
      font-weight: 700;
    }

    .form-header p {
      margin: 0;
      color: #888;
      font-size: 0.9rem;
      font-weight: 500;
    }

    .login-card {
      background: var(--white);
      border: 1px solid var(--border-color);
      border-radius: 24px;
      padding: 28px;
      box-shadow: var(--panel-shadow);
    }

    .login-header {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      text-align: center;
      margin-bottom: 24px;
    }

    .login-header h4 {
      margin: 0;
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text-dark);
    }

    .login-header p {
      margin: 0;
      color: var(--text-muted);
      font-size: 0.9rem;
    }

    .form-container {
      background: var(--white);
    }

    .form-control {
      border-radius: 10px;
      padding: 13px 15px;
      border: 1px solid #e0e0e0;
      font-size: 0.95rem;
      background-color: var(--white);
      transition: all 0.25s ease;
    }

    .form-control:focus {
      border-color: var(--primary-green);
      outline: none;
      box-shadow: 0 0 0 3px rgba(246, 128, 19, 0.08);
    }

    .btn-login {
      background-color: var(--primary-green);
      border: none;
      color: white;
      font-weight: 700;
      padding: 14px 18px;
      border-radius: 10px;
      font-size: 0.95rem;
      width: 100%;
      margin-top: 8px;
      transition: all 0.3s ease;
    }

    .btn-login:hover {
      background-color: var(--dark-green);
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 8px 18px rgba(246, 128, 19, 0.22);
    }

    .footer-note {
      text-align: center;
      margin-top: 22px;
      color: var(--text-muted);
      font-size: 0.9rem;
    }

    .legal-links-trigger {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      margin: 10px auto 0;
      background: none;
      border: none;
      color: var(--text-muted);
      font-size: 0.85rem;
      font-weight: 600;
      text-decoration: underline;
      text-underline-offset: 3px;
      cursor: pointer;
    }

    .legal-links-trigger:hover {
      color: var(--primary-green);
    }

    .legal-accordion .accordion-item {
      border: 1px solid var(--border-color);
      border-radius: 12px !important;
      overflow: hidden;
      margin-bottom: 10px;
      background: var(--white);
    }

    .legal-accordion .accordion-item:last-child {
      margin-bottom: 0;
    }

    .legal-accordion .accordion-button {
      font-weight: 700;
      color: var(--text-dark);
      background: var(--white);
      gap: 12px;
    }

    .legal-accordion .accordion-button:not(.collapsed) {
      color: var(--primary-green);
      background: #f8fafc;
      box-shadow: none;
    }

    .legal-accordion .accordion-button:focus {
      box-shadow: none;
      border-color: var(--border-color);
    }

    .legal-accordion .accordion-button .legal-link-icon {
      width: 32px;
      height: 32px;
      border-radius: 9px;
      background: #f1f5f9;
      color: var(--primary-green);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      font-size: 0.9rem;
    }

    .legal-accordion .accordion-body {
      color: var(--text-dark);
      font-size: 0.92rem;
      line-height: 1.6;
      white-space: pre-line;
    }

    .portal-actions {
      margin: 24px auto 0;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }

    .portal-action {
      border: none;
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 14px;
      padding: 14px;
      font-size: 0.92rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
      box-shadow: 0 10px 22px rgba(15, 23, 42, 0.1);
      text-align: left;
      min-height: 72px;
    }

    .portal-action i {
      margin-right: 10px;
      font-size: 1.2rem;
      flex-shrink: 0;
    }

    .portal-action-content {
      display: flex;
      flex-direction: column;
      line-height: 1.25;
    }

    .portal-action-title {
      font-size: 0.98rem;
      font-weight: 700;
    }

    .portal-action-subtitle {
      font-size: 0.82rem;
      opacity: 0.92;
      font-weight: 500;
    }

    .action-download i {
      font-size: 1.5rem;
    }

    .action-chat {
      background: linear-gradient(135deg, #0f172a, #334155);
      box-shadow: 0 10px 22px rgba(15, 23, 42, 0.22);
    }

    .action-chat:hover {
      color: white;
      transform: translateY(-2px);
    }

    .action-download {
      background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
    }

    .action-download:hover {
      color: white;
      transform: translateY(-2px);
    }

    .install-steps {
      margin: 0;
      padding-left: 0;
      list-style: none;
      color: var(--text-dark);
    }

    .install-steps li {
      margin-bottom: 0.85rem;
      line-height: 1.5;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      background: #f8fbff;
      border: 1px solid #dbe8ff;
      border-radius: 12px;
      padding: 12px 14px;
      box-shadow: 0 6px 18px rgba(13, 110, 253, 0.08);
    }

    .install-step-number {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
      color: white;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 0.9rem;
      flex-shrink: 0;
    }

    .install-step-text {
      flex: 1;
      padding-top: 2px;
    }

    .install-note {
      background: var(--light-gray);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 12px 14px;
      font-size: 0.9rem;
      color: var(--text-muted);
    }

    .install-modal-dialog {
      max-width: 620px;
    }

    .install-modal-content {
      overflow: hidden;
      box-shadow: 0 18px 42px rgba(0, 0, 0, 0.18);
    }

    .install-modal-header {
      background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
      border-bottom: 0;
      padding: 18px 20px;
    }

    .install-modal-body {
      padding: 22px 20px 18px;
    }

    .install-hero {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 16px;
      background: linear-gradient(180deg, #f8fbff, #eef5ff);
      border: 1px solid #d8e6ff;
      border-radius: 14px;
      margin-bottom: 16px;
    }

    .install-hero-icon {
      width: 54px;
      height: 54px;
      border-radius: 16px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
      color: white;
      font-size: 1.7rem;
      box-shadow: 0 10px 24px rgba(13, 110, 253, 0.22);
      flex-shrink: 0;
    }

    .install-hero-title {
      font-size: 1rem;
      font-weight: 700;
      color: var(--text-dark);
      margin-bottom: 0.2rem;
    }

    .install-hero-subtitle {
      font-size: 0.88rem;
      color: var(--text-muted);
      margin: 0;
    }

    .install-download-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
      color: white;
      text-decoration: none;
      padding: 10px 18px;
      border-radius: 8px;
      font-weight: 600;
    }

    .install-download-link:hover {
      color: white;
    }

    .install-modal-footer {
      border-top: 1px solid var(--border-color);
      padding: 16px 20px 20px;
      gap: 10px;
    }

    .install-close-btn {
      min-width: 110px;
      border-radius: 8px;
    }

    .modal-content {
      border-radius: 12px;
      border: 2px solid var(--border-color);
      background: var(--white);
    }

    .modal-header {
      background-color: var(--primary-green);
      color: white;
      border-bottom: 2px solid var(--border-color);
    }

    .form-label {
      color: var(--text-dark);
      font-weight: 500;
    }

    .text-muted {
      color: var(--text-muted) !important;
    }

    /* Toggle Switch Styles */
    .toggle-container {
      display: flex;
      justify-content: center;
      margin-bottom: 24px;
      background: #f8fafc;
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 6px;
      position: relative;
    }

    .toggle-option {
      flex: 1;
      text-align: center;
      padding: 11px 15px;
      border-radius: 9px;
      cursor: pointer;
      transition: all 0.3s ease;
      font-weight: 600;
      font-size: 0.9rem;
      z-index: 2;
      position: relative;
    }

    .toggle-option.active {
      background: var(--primary-green);
      color: white;
      box-shadow: 0 8px 16px rgba(246, 128, 19, 0.22);
    }

    .toggle-option:not(.active) {
      color: var(--text-muted);
    }

    .login-form {
      transition: all 0.3s ease;
    }

    .login-form.hidden {
      display: none;
    }

    .password-info {
      background: #fff7ed;
      border: 1px solid #fed7aa;
      border-radius: 12px;
      padding: 15px;
      margin-bottom: 20px;
    }

    .password-info .info-icon {
      color: var(--primary-green);
      font-size: 1.2rem;
      margin-right: 10px;
    }

    .position-relative .btn {
      color: var(--text-muted);
    }

    @media (max-width: 1200px) {
      .login-grid {
        grid-template-columns: 1fr;
      }

      .login-branding {
        min-height: 320px;
        padding: 56px 40px;
      }

      .branding-features {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }

      .login-form-side {
        padding: 48px 28px;
      }
    }

    @media (max-width: 767px) {
      .login-form-side {
        order: 1;
      }

      .login-branding {
        order: 2;
        min-height: auto;
        padding: 36px 24px;
      }

      .branding-features {
        grid-template-columns: 1fr;
      }

      .login-form-side {
        align-items: flex-start;
        padding: 28px 16px 36px;
      }

      .login-card {
        border-radius: 20px;
        padding: 22px 16px;
      }

      .form-header h2 {
        font-size: 1.65rem;
      }

      .portal-actions {
        grid-template-columns: 1fr;
      }

      .portal-action {
        justify-content: flex-start;
      }

      .install-hero {
        align-items: flex-start;
      }

      .install-modal-footer {
        flex-direction: column;
      }

      .install-close-btn,
      .install-download-link {
        width: 100%;
      }

      .toggle-option {
        font-size: 0.8rem;
        padding: 10px 8px;
      }

      .brand-identity {
        margin-bottom: 14px;
      }

      .brand-avatar {
        width: 68px;
        height: 68px;
        border-radius: 14px;
      }
    }
  </style>
</head>

<body>
  <div class="login-grid">
    <section class="login-branding">
      <div class="branding-copy">
        <?php if ($brand_mode): ?>
          <div class="brand-identity">
            <div class="brand-avatar">
              <?php if ($brand_logo_url !== ''): ?>
                <img src="<?= $brand_logo_safe ?>?v=<?= time() ?>" alt="<?= $brand_name_safe ?>" id="brandLogoImg" crossorigin="anonymous">
              <?php else: ?>
                <?= htmlspecialchars($brand_initials, ENT_QUOTES, 'UTF-8') ?>
              <?php endif; ?>
            </div>
            <div class="brand-meta">
              <strong><?= $brand_name_safe ?></strong>
            </div>
          </div>
        <?php endif; ?>

        <h1><?= $brand_mode ? ('Portal ' . $brand_name_safe) : 'Portal Billing Pelanggan' ?></h1>
        <p><?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <div class="branding-features">
        <div class="branding-feature">
          <i class="bi bi-lightning-charge-fill"></i>
          <strong><?= htmlspecialchars($portal_feature1_title, ENT_QUOTES, 'UTF-8') ?></strong>
          <span><?= htmlspecialchars($portal_feature1_text, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="branding-feature">
          <i class="bi bi-receipt-cutoff"></i>
          <strong><?= htmlspecialchars($portal_feature2_title, ENT_QUOTES, 'UTF-8') ?></strong>
          <span><?= htmlspecialchars($portal_feature2_text, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="branding-feature">
          <i class="bi bi-headset"></i>
          <strong><?= htmlspecialchars($portal_feature3_title, ENT_QUOTES, 'UTF-8') ?></strong>
          <span><?= htmlspecialchars($portal_feature3_text, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </section>

    <section class="login-form-side">
      <div class="form-shell">
       

        <div class="login-card">
          <div class="login-header">
            <h4>LOGIN PELANGGAN</h4>
            <p>Gunakan nomor WhatsApp terdaftar atau ID pelanggan Anda.</p>
          </div>

          <div class="form-container">
          <div class="toggle-container">
            <div class="toggle-option active" onclick="switchLoginMode('otp')">
              <i class="bi bi-send-fill me-1"></i>Login dengan OTP
            </div>
            <div class="toggle-option" onclick="switchLoginMode('password')">
              <i class="bi bi-key-fill me-1"></i>Login dengan Password
            </div>
          </div>

          <!-- OTP Login Form -->
          <form id="otpForm" class="login-form" action="proseslogin-portal.php<?= $brand_query_suffix ?>" method="post">
            <input type="hidden" name="login_mode" value="otp">
            <input type="hidden" name="brand" value="<?= htmlspecialchars($brand_username, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="otpreal" value="<?= rand(1000, 9999) ?>">

            <div class="mb-3">
              <label for="idselect_otp" class="form-label fw-medium">Nomor WhatsApp Pelanggan Terdaftar</label>
              <input type="number" name="idselect" id="idselect_otp" class="form-control" placeholder="Contoh: 6287838767625" required>
              <small class="text-muted">Gunakan nomor tanpa tanda strip atau karakter khusus</small>
            </div>

            <button type="submit" name="submit" class="btn btn-login">
              <i class="bi bi-send-fill me-2"></i>LOGIN
            </button>

            <input type="hidden" id="latitude1" name="latitude1">
            <input type="hidden" id="longitude1" name="longitude1">
          </form>

          <!-- Password Login Form -->
          <form id="passwordForm" class="login-form hidden" action="proseslogin-portal.php<?= $brand_query_suffix ?>" method="post">
            <input type="hidden" name="login_mode" value="password">
            <input type="hidden" name="brand" value="<?= htmlspecialchars($brand_username, ENT_QUOTES, 'UTF-8') ?>">

            <div class="password-info">
              <div class="d-flex align-items-start">
                <i class="bi bi-info-circle-fill info-icon"></i>
                <div>
                  <strong>Informasi Login:</strong>
                  <p class="mb-0 mt-1 small">Password default adalah nomor WhatsApp yang terdaftar di sistem kami.</p>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label for="customer_id" class="form-label fw-medium">ID Pelanggan</label>
              <input type="text" name="customer_id" id="customer_id" class="form-control" placeholder="Masukkan ID Pelanggan" required>
            </div>

            <div class="mb-3">
              <label for="password" class="form-label fw-medium">Password</label>
              <div class="position-relative">
                <input type="password" name="password" id="password" class="form-control" placeholder="Masukkan Password (Nomor WhatsApp)" required>
                <button type="button" class="btn position-absolute end-0 top-0 h-100 px-3" onclick="togglePassword()" style="border: none; background: none;">
                  <i class="bi bi-eye-fill" id="passwordToggleIcon"></i>
                </button>
              </div>
              <small class="text-muted">Password default: Nomor WhatsApp terdaftar</small>
            </div>

            <button type="submit" name="submit_password" class="btn btn-login">
              <i class="bi bi-box-arrow-in-right me-2"></i>LOGIN
            </button>

            <input type="hidden" id="latitude2" name="latitude2">
            <input type="hidden" id="longitude2" name="longitude2">
          </form>


          <div class="footer-note">
            Powered by <?php echo $config['perusahaan']?>
          </div>

          <?php if (!empty($portal_links_items)): ?>
          <button type="button" class="legal-links-trigger" data-bs-toggle="modal" data-bs-target="#legalLinksModal">
            <i class="bi bi-shield-check"></i> FAQ, Kebijakan Refund, Syarat &amp; Ketentuan, Kontak
          </button>
          <?php endif; ?>

          <div class="portal-actions">
            <button type="button" class="portal-action action-chat" data-bs-toggle="modal" data-bs-target="#liveChatModal">
              <i class="bi bi-chat-dots-fill"></i>
              <span class="portal-action-content">
                <span class="portal-action-title">Chat Customer Service</span>
                <span class="portal-action-subtitle">Hubungi admin untuk bantuan login</span>
              </span>
            </button>
            <button type="button" class="portal-action action-download" data-bs-toggle="modal" data-bs-target="#androidInstallModal" aria-label="Panduan install aplikasi Android">
              <i class="bi bi-android2" aria-hidden="true"></i>
              <span class="portal-action-content">
                <span class="portal-action-title">Download Aplikasi Android</span>
                <span class="portal-action-subtitle">Tagihanwifiku</span>
              </span>
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>

  <div class="modal fade" id="androidInstallModal" tabindex="-1" aria-labelledby="androidInstallModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered install-modal-dialog">
      <div class="modal-content install-modal-content">
        <div class="modal-header install-modal-header">
          <h5 class="modal-title" id="androidInstallModalLabel">Cara Install Aplikasi Android</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body install-modal-body">
          <div class="install-hero">
            <div class="install-hero-icon">
              <i class="bi bi-android2"></i>
            </div>
            <div>
              <div class="install-hero-title">Langkah izinkan instal dari Unknown Source</div>
              <p class="install-hero-subtitle">Tampilan menu bisa sedikit berbeda tergantung merek HP Android, tetapi alurnya tetap sama.</p>
            </div>
          </div>

          <ol class="install-steps">
            <li>
              <span class="install-step-number">1</span>
              <div class="install-step-text">Tekan tombol <strong>Download APK</strong> di bawah untuk mengunduh file aplikasi <strong>Tagihanwifiku</strong>.</div>
            </li>
            <li>
              <span class="install-step-number">2</span>
              <div class="install-step-text">Jika muncul peringatan, buka menu <strong>Pengaturan</strong> lalu masuk ke <strong>Keamanan</strong> atau <strong>Privasi</strong>.</div>
            </li>
            <li>
              <span class="install-step-number">3</span>
              <div class="install-step-text">Cari menu <strong>Install unknown apps</strong>, <strong>Sumber tidak dikenal</strong>, atau <strong>Izinkan dari sumber ini</strong>.</div>
            </li>
            <li>
              <span class="install-step-number">4</span>
              <div class="install-step-text">Pilih aplikasi yang dipakai untuk membuka file APK, biasanya <strong>Chrome</strong>, <strong>Browser</strong>, atau <strong>File Manager</strong>, lalu aktifkan izin instal.</div>
            </li>
            <li>
              <span class="install-step-number">5</span>
              <div class="install-step-text">Buka kembali file APK yang sudah terunduh, lalu tekan <strong>Install</strong> sampai proses selesai.</div>
            </li>
          </ol>

          <div class="install-note mt-3">
            Jika instalasi masih ditolak, tutup file APK lalu buka lagi setelah izin <strong>Unknown Source</strong> diaktifkan.
          </div>
        </div>
        <div class="modal-footer justify-content-between install-modal-footer">
          <button type="button" class="btn btn-outline-secondary install-close-btn" data-bs-dismiss="modal">Tutup</button>
          <a href="../../../file/APLIKASI/Tagihanwifiku.apk" class="install-download-link" download="Tagihanwifiku.apk">
            <i class="bi bi-download"></i>Download APK
          </a>
        </div>
      </div>
    </div>
  </div>
      
  <div class="modal fade" id="liveChatModal" tabindex="-1" aria-labelledby="liveChatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Live Chat Customer Service</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div id="iframe-container" style="position: relative; width: 100%; height: 500px;">
          <div id="loading-message" style="position: absolute; width: 100%; height: 100%; background: #f9f9f9; display: none; align-items: center; justify-content: center; z-index: 10; font-weight: bold; font-size: 18px;">
            Loading chat...
          </div>
          <iframe
            id="chatIframe"
            data-src="<?= htmlspecialchars($URL, ENT_QUOTES, 'UTF-8') ?>/crm/chat/index.php?to=<?= rawurlencode($chat_target) ?>"
            width="100%"
            height="100%"
            frameborder="0"
            style="border: none;"
            loading="lazy"
            onload="document.getElementById('loading-message').style.display='none';">
          </iframe>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($portal_links_items)): ?>
  <div class="modal fade" id="legalLinksModal" tabindex="-1" aria-labelledby="legalLinksModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="legalLinksModalLabel">Kebijakan &amp; Bantuan</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="accordion legal-accordion" id="legalLinksAccordion">
            <?php foreach ($portal_links_items as $portal_link_index => $portal_link_item): ?>
            <?php $portal_link_id = 'legalLinksItem' . $portal_link_index; ?>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button <?= $portal_link_index > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $portal_link_id ?>">
                  <span class="legal-link-icon"><i class="bi <?= htmlspecialchars($portal_link_item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></span>
                  <?= htmlspecialchars($portal_link_item['label'], ENT_QUOTES, 'UTF-8') ?>
                </button>
              </h2>
              <div id="<?= $portal_link_id ?>" class="accordion-collapse collapse <?= $portal_link_index === 0 ? 'show' : '' ?>" data-bs-parent="#legalLinksAccordion">
                <div class="accordion-body"><?= htmlspecialchars($portal_link_item['text'], ENT_QUOTES, 'UTF-8') ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($brand_mode && $brand_logo_url !== ''): ?>
  <canvas id="brandColorCanvas" style="display:none;"></canvas>
  <?php endif; ?>
  <script>
    // Request geolocation after first paint with short timeout so it does not feel blocking.
    function setGeoCoordinates(position) {
      document.getElementById('latitude1').value = position.coords.latitude;
      document.getElementById('longitude1').value = position.coords.longitude;
      document.getElementById('latitude2').value = position.coords.latitude;
      document.getElementById('longitude2').value = position.coords.longitude;
    }

    function requestGeolocation() {
      if (!navigator.geolocation) return;
      navigator.geolocation.getCurrentPosition(setGeoCoordinates, function() {}, {
        enableHighAccuracy: false,
        timeout: 3000,
        maximumAge: 600000
      });
    }

    // Switch between login modes
    function switchLoginMode(mode) {
      const otpForm = document.getElementById('otpForm');
      const passwordForm = document.getElementById('passwordForm');
      const toggleOptions = document.querySelectorAll('.toggle-option');

      // Remove active class from all toggle options
      toggleOptions.forEach(option => option.classList.remove('active'));

      if (mode === 'otp') {
        // Show OTP form, hide password form
        otpForm.classList.remove('hidden');
        passwordForm.classList.add('hidden');
        toggleOptions[0].classList.add('active');
      } else if (mode === 'password') {
        // Show password form, hide OTP form
        otpForm.classList.add('hidden');
        passwordForm.classList.remove('hidden');
        toggleOptions[1].classList.add('active');
      }
    }

    // Toggle password visibility
    function togglePassword() {
      const passwordField = document.getElementById('password');
      const toggleIcon = document.getElementById('passwordToggleIcon');

      if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.classList.remove('bi-eye-fill');
        toggleIcon.classList.add('bi-eye-slash-fill');
      } else {
        passwordField.type = 'password';
        toggleIcon.classList.remove('bi-eye-slash-fill');
        toggleIcon.classList.add('bi-eye-fill');
      }
    }

    // Auto-format WhatsApp number for OTP form
    document.getElementById('idselect_otp').addEventListener('input', function(e) {
      let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
      
      // Auto-add country code if not present
      if (value.length > 0 && !value.startsWith('62')) {
        if (value.startsWith('0')) {
          value = '62' + value.substring(1);
        } else if (value.length >= 10) {
          value = '62' + value;
        }
      }
      
      e.target.value = value;
    });

    // Wire mobile bottom chat button to open the same modal
    document.addEventListener('DOMContentLoaded', function() {
      setTimeout(requestGeolocation, 300);

      <?php if ($brand_mode && $brand_logo_url !== ''): ?>
      (function() {
        var img = document.getElementById('brandLogoImg');
        if (!img) return;

        function applyBrandColors() {
          try {
            var canvas = document.getElementById('brandColorCanvas');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            canvas.width = img.naturalWidth || 120;
            canvas.height = img.naturalHeight || 120;
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

            var sampleSize = Math.min(canvas.width, canvas.height, 60);
            var sx = Math.floor((canvas.width - sampleSize) / 2);
            var sy = Math.floor((canvas.height - sampleSize) / 2);
            var data = ctx.getImageData(sx, sy, sampleSize, sampleSize).data;

            var rSum = 0, gSum = 0, bSum = 0, count = 0;
            for (var i = 0; i < data.length; i += 4) {
              var r = data[i], g = data[i + 1], b = data[i + 2], a = data[i + 3];
              if (a < 128) continue;
              var brightness = (r + g + b) / 3;
              if (brightness > 240 || brightness < 15) continue;
              rSum += r;
              gSum += g;
              bSum += b;
              count++;
            }

            if (count > 0) {
              var rr = Math.round(rSum / count);
              var gg = Math.round(gSum / count);
              var bb = Math.round(bSum / count);
              var darken = function(v, ratio) { return Math.max(0, Math.round(v * (1 - ratio))); };

              var primary = 'rgb(' + rr + ',' + gg + ',' + bb + ')';
              var secondary = 'rgb(' + darken(rr, 0.15) + ',' + darken(gg, 0.15) + ',' + darken(bb, 0.15) + ')';

              document.documentElement.style.setProperty('--primary-green', primary);
              document.documentElement.style.setProperty('--dark-green', secondary);
              document.documentElement.style.setProperty('--orange', primary);
              document.documentElement.style.setProperty('--dark-orange', secondary);
            }
          } catch (e) {
            // Keep default colors when image color extraction fails.
          }
        }

        if (img.complete && img.naturalWidth > 0) {
          applyBrandColors();
        } else {
          img.addEventListener('load', applyBrandColors);
        }
      })();
      <?php endif; ?>

      var chatModalEl = document.getElementById('liveChatModal');
      if (chatModalEl) {
        chatModalEl.addEventListener('show.bs.modal', function() {
          var chatIframe = document.getElementById('chatIframe');
          var loadingMessage = document.getElementById('loading-message');
          if (chatIframe && !chatIframe.src) {
            if (loadingMessage) loadingMessage.style.display = 'flex';
            chatIframe.src = chatIframe.getAttribute('data-src');
          }
        });
      }

    });
  </script>
</body>

</html>