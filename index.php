<?php
require_once __DIR__ . '/../dokumen_folder_helper.php';
include 'koneksidb.php';
session_start();
$favicon_file = dirname(__DIR__, 2) . '/logobilling.png';
$favicon_path = 'https://quenbytekniksejahtera.com/logobilling.png';
$favicon_version = file_exists($favicon_file) ? (string)filemtime($favicon_file) : (string)time();
$theme_config_file = __DIR__ . '/config.json';
$theme_config = file_exists($theme_config_file) ? json_decode((string)file_get_contents($theme_config_file), true) : [];
$theme_primary_color = isset($theme_config['extracted_primary_color']) && $theme_config['extracted_primary_color'] !== ''
  ? (string)$theme_config['extracted_primary_color']
  : '#f68013';
$theme_secondary_color = isset($theme_config['extracted_secondary_color']) && $theme_config['extracted_secondary_color'] !== ''
  ? (string)$theme_config['extracted_secondary_color']
  : '#f68012';

// === Halaman login branded per ISP (contoh: ?brand=JAYANET) ===
$brand_param = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
if (!empty($brand_param)) {
  $brand_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $brand_param);
  // Ambil user tanpa filter STATUS — semua user boleh punya halaman branded
  // (termasuk ASSISTANT/reseller, supaya masing-masing bisa punya link login
  // sendiri dengan logo sendiri kalau sudah upload -- lihat "grup" di bawah).
  $stmt_brand = $conn->prepare("SELECT USERNAME, inisial, domain, STATUS, grup FROM `user` WHERE USERNAME = ? LIMIT 1");
  $stmt_brand->bind_param("s", $brand_username);
  $stmt_brand->execute();
  $res_brand = $stmt_brand->get_result();

  if ($res_brand->num_rows > 0) {
    $brand_user = $res_brand->fetch_assoc();
    // Gunakan __DIR__ agar file_exists() tidak tertipu CWD server.
    // Logo sekarang disimpan terpusat di dokumen/logo/ (sejajar crm/); untuk
    // <img>/<link> harus pakai URL web /dokumen/logo/..., bukan path filesystem.
    $brand_logo_abs = __DIR__ . '/../../dokumen/logo/profile-' . $brand_user['USERNAME'] . '.png';
    $brand_logo_rel = file_exists($brand_logo_abs) ? ('/dokumen/logo/profile-' . $brand_user['USERNAME'] . '.png') : '';
    // Kalau yang dibuka link ASSISTANT dan dia belum pernah upload logo sendiri,
    // coba dulu logo OWNER-nya sebelum jatuh ke logo.png generik.
    if (empty($brand_logo_rel) && (string)$brand_user['STATUS'] === 'ASSISTANT' && !empty($brand_user['grup'])) {
      $stmt_owner = $conn->prepare("SELECT USERNAME FROM `user` WHERE id = ? LIMIT 1");
      $stmt_owner->bind_param("i", $brand_user['grup']);
      $stmt_owner->execute();
      $res_owner = $stmt_owner->get_result();
      if ($res_owner->num_rows > 0) {
        $brand_owner_row = $res_owner->fetch_assoc();
        $owner_logo_abs = __DIR__ . '/../../dokumen/logo/profile-' . $brand_owner_row['USERNAME'] . '.png';
        $brand_logo_rel = file_exists($owner_logo_abs) ? ('/dokumen/logo/profile-' . $brand_owner_row['USERNAME'] . '.png') : '';
      }
    }
    if (empty($brand_logo_rel)) {
      $fallback_abs = __DIR__ . '/../../dokumen/logo/logo.png';
      $brand_logo_rel = file_exists($fallback_abs) ? '/dokumen/logo/logo.png' : '';
    }
    $brand_logo_url  = $brand_logo_rel;
    $brand_name      = htmlspecialchars($brand_user['USERNAME'], ENT_QUOTES, 'UTF-8');
    $brand_logo_safe = htmlspecialchars($brand_logo_url, ENT_QUOTES, 'UTF-8');

    // Handle login POST for branded page (same logic)
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_type']) && $_POST['login_type'] === 'regular') {
      $uname = $_POST['username'] ?? '';
      $upass = $_POST['password'] ?? '';
      $stmt2 = $conn->prepare("SELECT * FROM `user` WHERE USERNAME = ?");
      $stmt2->bind_param("s", $uname);
      $stmt2->execute();
      $res2 = $stmt2->get_result();
      if ($res2->num_rows > 0) {
        $row2 = $res2->fetch_assoc();
        if (password_verify($upass, $row2['PASWORD'])) {
          $upd = $conn->prepare("UPDATE `user` SET last_login = NOW() WHERE id = ?");
          $upd->bind_param("i", $row2['id']);
          $upd->execute();
          $_SESSION['USERNAME'] = $row2['USERNAME'];
          $_SESSION['id']       = $row2['id'];
          $_SESSION['PEMILIK']  = $row2['USERNAME'];
          $_SESSION['NOWA']     = $row2['NOWA'];
          $_SESSION['server']   = $row2['server'];
          $_SESSION['status']   = "login";

          // Generate/perbarui file cron per-akun (cek_tagihan_harian_<akun>.php dkk)
          // begitu login berhasil -- sebelumnya cuma tergenerate kalau admin pernah
          // buka menu Notifikasi minimal sekali, jadi cron system_setting.php bisa
          // menunjuk ke file yang belum pernah ada. Halaman branded ini hanya utk
          // login owner (bukan assistant), jadi langsung pakai USERNAME-nya sendiri.
          if ((string)($row2['STATUS'] ?? '') !== 'ASSISTANT') {
            require_once __DIR__ . '/notifbot/notif_cron_files_helper.php';
            notifCronFilesGenerate($row2['USERNAME']);
          }

          header("Location: dashboard.php");
          exit;
        } else {
          $error = "Username atau password salah";
        }
      } else {
        $error = "Username tidak ditemukan";
      }
    }
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if ($brand_logo_url): ?>
  <link rel="apple-touch-icon" sizes="76x76" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <link rel="shortcut icon" type="image/png" href="<?= $brand_logo_safe ?>?v=<?= time() ?>">
  <?php endif; ?>
  <title><?= $brand_name ?> - Login</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <style>
    :root {
      --primary-color: <?= htmlspecialchars($theme_primary_color, ENT_QUOTES, 'UTF-8') ?>;
      --secondary-color: <?= htmlspecialchars($theme_secondary_color, ENT_QUOTES, 'UTF-8') ?>;
      --accent-color: <?= htmlspecialchars($theme_primary_color, ENT_QUOTES, 'UTF-8') ?>;
    }
    * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
    body { min-height:100vh; background:#f5f5f5; }

    .brand-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      min-height: 100vh;
      position: fixed;
      inset: 0;
    }
    .brand-side {
      background: linear-gradient(145deg, var(--primary-color) 0%, var(--secondary-color) 100%);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 50px;
      position: relative;
      overflow: hidden;
      color: #fff;
    }
    .brand-side::before {
      content:""; position:absolute; bottom:-100px; right:-120px;
      width:380px; height:380px; border-radius:50%;
      background:rgba(255,255,255,0.08); z-index:0;
    }
    .brand-side::after {
      content:""; position:absolute; top:-60px; left:-60px;
      width:280px; height:280px; border-radius:50%;
      background:rgba(255,255,255,0.05); z-index:0;
    }
    .brand-inner { position:relative; z-index:1; text-align:center; }
    .brand-logo-wrap {
      width:160px; height:160px; border-radius:50%;
      background:rgba(255,255,255,0.15);
      backdrop-filter:blur(8px);
      display:flex; align-items:center; justify-content:center;
      margin:0 auto 28px;
      box-shadow:0 8px 32px rgba(0,0,0,0.2);
      border:3px solid rgba(255,255,255,0.3);
      overflow:hidden;
    }
    .brand-logo-wrap img { width:130px; height:130px; object-fit:contain; }
    .brand-logo-wrap .brand-initials {
      font-size:56px; font-weight:900; color:#fff; letter-spacing:-2px;
    }
    .brand-name {
      font-size:32px; font-weight:800; letter-spacing:-0.5px;
      text-shadow:0 2px 8px rgba(0,0,0,0.15); margin-bottom:8px;
    }
    .brand-domain { font-size:14px; opacity:0.8; font-weight:500; }
    .brand-tagline { font-size:16px; opacity:0.9; margin-top:16px; font-weight:500; }

    .form-side {
      padding:80px 60px; display:flex; flex-direction:column;
      justify-content:center; align-items:center; background:#fff;
    }
    .form-side form { width:100%; max-width:420px; }

    .alert { padding:12px 16px; border-radius:6px; margin-bottom:24px;
      display:flex; align-items:center; gap:10px; font-size:13px; font-weight:500; }
    .alert-danger { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
    .alert-danger::before { content:"??"; font-size:16px; flex-shrink:0; }

    .input-group { margin-bottom:24px; position:relative; }
    .input-group input {
      width:100%; padding:14px 16px; border:1px solid #e0e0e0;
      border-radius:6px; font-size:14px; transition:all 0.3s; background:#fff;
    }
    .input-group input:focus {
      border-color:var(--primary-color); outline:none;
      box-shadow:0 0 0 3px rgba(37,99,235,0.1);
    }
    .input-group input::placeholder { color:transparent; }
    .input-group label {
      position:absolute; left:16px; top:14px; color:#999; font-size:13px;
      transition:all 0.3s; pointer-events:none; background:#fff; padding:0 4px; font-weight:500;
    }
    .input-group input:focus + label,
    .input-group input:not(:placeholder-shown) + label {
      top:-8px; left:12px; font-size:11px; color:var(--primary-color); font-weight:600;
    }
    .remember-forgot {
      display:flex; justify-content:space-between; align-items:center;
      margin-bottom:28px; font-size:13px;
    }
    .remember-me { display:flex; align-items:center; gap:6px; cursor:pointer; }
    .remember-me input { width:16px; height:16px; accent-color:var(--primary-color); }
    .remember-me label { cursor:pointer; color:#666; font-weight:500; }
    .forgot-password a { color:var(--primary-color); text-decoration:none; font-weight:600; font-size:13px; }
    .login-btn {
      width:100%; padding:14px 24px; background:var(--primary-color);
      border:none; border-radius:6px; color:#fff; font-size:15px;
      font-weight:700; cursor:pointer; transition:all 0.3s; margin-top:4px;
    }
    .login-btn:hover { background:var(--secondary-color); transform:translateY(-2px); box-shadow:0 6px 16px rgba(0,0,0,0.12); }

    .back-link { margin-top:20px; text-align:center; font-size:13px; color:#888; }
    .back-link a { color:var(--primary-color); font-weight:600; text-decoration:none; }

    .powered-label { display:block; font-size:11px; color:#999; margin:24px 0 10px;
      text-align:center; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
    .gateway-logos {
      display:flex; justify-content:center; align-items:center; gap:12px; flex-wrap:wrap;
      padding:12px; background:#f5f5f5; border-radius:6px; border:1px solid #e0e0e0;
    }
    .gateway-logos img { height:24px; max-width:60px; object-fit:contain; opacity:0.65; transition:opacity 0.2s; }
    .gateway-logos img:hover { opacity:1; }

    @media (max-width:900px) {
      .brand-grid { grid-template-columns:1fr; position:relative; inset:auto; min-height:100vh; }
      .brand-side { min-height:260px; padding:40px 24px; }
      .form-side { padding:40px 24px; }
      .form-side form { max-width:100%; }
    }


    /* ===== Loading Overlay ===== */
.loading-overlay{
    position:fixed;
    inset:0;
    background:rgba(255,255,255,.9);
    backdrop-filter:blur(4px);
    display:flex;
    justify-content:center;
    align-items:center;
    flex-direction:column;
    z-index:99999;
    opacity:0;
    visibility:hidden;
    transition:.3s;
}

.loading-overlay.show{
    opacity:1;
    visibility:visible;
}

.loader{
    width:65px;
    height:65px;
    border:6px solid #eee;
    border-top:6px solid var(--primary-color);
    border-radius:50%;
    animation:spin .8s linear infinite;
    margin-bottom:18px;
}

.loading-text{
    font-size:15px;
    font-weight:600;
    color:#555;
    letter-spacing:.5px;
}

@keyframes spin{
    to{
        transform:rotate(360deg);
    }
}

.login-btn.loading{
    pointer-events:none;
    opacity:.8;
}

.login-btn.loading::after{
    content:'';
    width:18px;
    height:18px;
    margin-left:10px;
    border:2px solid rgba(255,255,255,.4);
    border-top:2px solid #fff;
    border-radius:50%;
    display:inline-block;
    animation:spin .7s linear infinite;
    vertical-align:middle;
}
  </style>
</head>
<div id="loadingOverlay" class="loading-overlay">
    <div class="loader"></div>
    <div class="loading-text">
        Sedang Login...
    </div>
</div>
<body>
  <div class="brand-grid">
    <!-- Sisi kiri: branding ISP -->
    <div class="brand-side" id="brandSide">
      <div class="brand-inner">
        <div class="brand-logo-wrap" id="brandLogoWrap">
          <?php if ($brand_logo_url): ?>
            <img src="<?= $brand_logo_safe ?>?v=<?= time() ?>" alt="<?= $brand_name ?>" id="brandLogoImg" crossorigin="anonymous">
          <?php else: ?>
            <span class="brand-initials"><?= mb_substr($brand_name, 0, 2) ?></span>
          <?php endif; ?>
        </div>
        <div class="brand-name"><?= $brand_name ?></div>
        <div class="brand-tagline">APLIKASI TAGIHAN PELANGGAN</div>
      </div>
    </div>

    <!-- Sisi kanan: form login -->
    <div class="form-side">
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form action="?brand=<?= htmlspecialchars($brand_param, ENT_QUOTES, 'UTF-8') ?>" method="POST" autocomplete="off">
        <input type="hidden" name="login_type" value="regular">
        <h2 style="text-align:center;color:#333;margin-bottom:8px;font-size:28px;font-weight:700;">Sign in</h2>
        <p style="text-align:center;color:#888;margin-bottom:32px;font-size:13px;font-weight:500;">Login dengan akun <?= $brand_name ?></p>

        <div class="input-group">
          <input type="text" id="username" name="username" placeholder=" " required>
          <label for="username">User Name</label>
        </div>
        <div class="input-group">
          <input type="password" id="password" name="password" placeholder=" " required>
          <label for="password">Password</label>
        </div>

        <div class="remember-forgot">
          <div class="remember-me">
            <input type="checkbox" id="remember" name="remember">
            <label for="remember">Ingat saya</label>
          </div>
          <div class="forgot-password"><a href="forgot.php">Lupa password?</a></div>
        </div>

        <button type="submit" class="login-btn">SIGN IN</button>

        <div class="back-link">
          <a href="index.php">? Kembali ke login utama</a>
        </div>

        <span class="powered-label">Payment Gateway</span>
        <div class="gateway-logos">
          <img src="assets/img/tripay.jpg" alt="Tripay">
          <img src="assets/img/duitku.png" alt="Duitku">
          <img src="assets/img/xendit.png" alt="Xendit">
          <img src="assets/img/mitrans.png" alt="Mitrans">
        </div>
      </form>
    </div>
  </div>

  <?php if ($brand_logo_url): ?>
  <canvas id="colorCanvas" style="display:none;"></canvas>
  <script>
    (function() {
      const img = document.getElementById('brandLogoImg');
      if (!img) return;

      function extractAndApply() {
        try {
          const canvas = document.getElementById('colorCanvas');
          const ctx = canvas.getContext('2d');
          canvas.width = img.naturalWidth || 100;
          canvas.height = img.naturalHeight || 100;
          ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

          // Ambil sampel warna dari area tengah gambar
          const sampleSize = Math.min(canvas.width, canvas.height, 60);
          const sx = Math.floor((canvas.width - sampleSize) / 2);
          const sy = Math.floor((canvas.height - sampleSize) / 2);
          const data = ctx.getImageData(sx, sy, sampleSize, sampleSize).data;

          let rSum = 0, gSum = 0, bSum = 0, count = 0;
          for (let i = 0; i < data.length; i += 4) {
            const r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
            // Lewati piksel transparan atau terlalu terang/gelap
            if (a < 128) continue;
            const brightness = (r + g + b) / 3;
            if (brightness > 240 || brightness < 15) continue;
            rSum += r; gSum += g; bSum += b; count++;
          }

          if (count > 0) {
            const r = Math.round(rSum / count);
            const g = Math.round(gSum / count);
            const b = Math.round(bSum / count);
            // Buat warna lebih dalam untuk secondary
            const darken = (v, amt) => Math.max(0, Math.round(v * (1 - amt)));
            const primary   = `rgb(${r},${g},${b})`;
            const secondary = `rgb(${darken(r,0.15)},${darken(g,0.15)},${darken(b,0.15)})`;
            const root = document.documentElement;
            root.style.setProperty('--primary-color', primary);
            root.style.setProperty('--secondary-color', secondary);
            root.style.setProperty('--accent-color', primary);
          }
        } catch(e) {
          // Gagal ekstrak warna (CORS) — tetap pakai default
        }
      }

      if (img.complete && img.naturalWidth > 0) {
        extractAndApply();
      } else {
        img.addEventListener('load', extractAndApply);
      }
    })();
  </script>
  <?php endif; ?>
</body>
</html>
    <?php
    exit;
  }
}
// === End branded login ===


// Cek dan tambah kolom last_login jika belum ada
$result = $conn->query("SHOW COLUMNS FROM `user` LIKE 'last_login'");
if ($result->num_rows == 0) {
  $conn->query("ALTER TABLE `user` ADD COLUMN `last_login` DATETIME DEFAULT NULL");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['login_type']) && $_POST['login_type'] == 'regular') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM user WHERE USERNAME = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
      $row = $result->fetch_assoc();

      // Verifikasi password
      if (password_verify($password, $row['PASWORD'])) {
        $akses_menu_login = [];
        if (!empty($row['akses'])) {
          $decoded_akses = json_decode((string)$row['akses'], true);
          if (is_array($decoded_akses)) {
            $akses_menu_login = array_values(array_unique(array_filter($decoded_akses)));
          }
        }

    $landing_page = 'dashboard.php';

if ((string)$row['STATUS'] === 'ASSISTANT') {

    // ASSISTANT TEKNISI:
    // Hanya memiliki akses Ticket_manager
    $is_assistant_teknisi =
        count($akses_menu_login) === 1 &&
        in_array('Ticket_manager', $akses_menu_login, true);

    if ($is_assistant_teknisi) {

        // Khusus teknisi
        $landing_page = 'tiket_manager.php';

    } elseif (!in_array('Dasbor', $akses_menu_login, true)) {

        // ASSISTANT biasa
        $menu_landing_map = [
            'Ticket_monitoring' => '../joblist/monitoring.php',
            'Buat_Tiket' => 'buat_tiket.php',
            'Customer_PPPOE' => 'tables.php',
            'Packages_Broadband' => 'packages.php',
            'Customer_Hotspot' => 'tableshotspot.php',
            'Packages_Hotspot' => 'packageshotspot.php',
            'Provisioning_Joblist' => 'provisioning_approval.php',
            'Transaction' => 'Transaction.php',
            'Notification_settings' => 'notification.php',
            'log' => 'log_history.php',
            'broadband_login' => 'broadband/portal.php',
            'Login_hotspot_billing' => 'buyvoucher/index.php'
        ];

        foreach ($akses_menu_login as $menu_key) {

            if (isset($menu_landing_map[$menu_key])) {

                $landing_page = $menu_landing_map[$menu_key];
                break;
            }
        }
    }
}
        // Update last_login
        $update_stmt = $conn->prepare("UPDATE `user` SET last_login = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $row['id']);
        $update_stmt->execute();
        $update_stmt->close();

        $_SESSION['USERNAME'] = $row['USERNAME'];
        $_SESSION['id'] = $row['id'];
        $_SESSION['PEMILIK'] = $row['USERNAME'];
        $_SESSION['NOWA'] = $row['NOWA'];
        $_SESSION['server'] = $row['server'];
        $_SESSION['status'] = "login";

        // Generate/perbarui file cron per-akun (cek_tagihan_harian_<akun>.php dkk)
        // begitu login berhasil -- sebelumnya cuma tergenerate kalau admin pernah
        // buka menu Notifikasi minimal sekali, jadi cron system_setting.php bisa
        // menunjuk ke file yang belum pernah ada. File cron ini per OWNER (bukan
        // per assistant), jadi kalau yang login assistant, resolve dulu ke
        // username owner-nya (kolom grup -> id owner) sebelum generate.
        require_once __DIR__ . '/notifbot/notif_cron_files_helper.php';
        $cronFilesUsername = $row['USERNAME'];
        $cronFilesActor = $row['USERNAME'];
        if ((string)$row['STATUS'] === 'ASSISTANT' && !empty($row['grup'])) {
            $ownerRowStmt = $conn->prepare("SELECT USERNAME FROM user WHERE id = ?");
            $ownerRowStmt->bind_param("i", $row['grup']);
            $ownerRowStmt->execute();
            $ownerRowResult = $ownerRowStmt->get_result();
            if ($ownerRowResult && ($ownerRow = $ownerRowResult->fetch_assoc())) {
                $cronFilesUsername = $ownerRow['USERNAME'];
            }
            $ownerRowStmt->close();
        }
        notifCronFilesGenerate($cronFilesUsername, $cronFilesActor);

        header("Location: " . $landing_page);
        exit();
      } else {
        $error = "Username atau password salah";
      }
    } else {
      $error = "Username tidak ditemukan";
    }
  }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="apple-touch-icon" sizes="76x76" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="shortcut icon" type="image/png" href="<?= htmlspecialchars($favicon_path, ENT_QUOTES, 'UTF-8'); ?>?v=<?= htmlspecialchars($favicon_version, ENT_QUOTES, 'UTF-8'); ?>">
  <title>Login - Billing Internet</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <style>
    :root {
      --primary-color: <?php echo isset($config['extracted_primary_color']) ? $config['extracted_primary_color'] : '#f68013'; ?>;
      --secondary-color: <?php echo isset($config['extracted_secondary_color']) ? $config['extracted_secondary_color'] : '#f68012'; ?>;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    body {
      min-height: 100vh;
      display: block;
      background: #f5f5f5;
      padding: 0;
      margin: 0;
    }

    .alert {
      padding: 12px 16px;
      border-radius: 6px;
      margin-bottom: 24px;
      text-align: left;
      font-size: 13px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .alert-danger {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fca5a5;
    }

    .alert-danger::before {
      content: "??";
      font-size: 16px;
      flex-shrink: 0;
    }

    .input-group {
      margin-bottom: 24px;
      position: relative;
    }

    .input-group input {
      width: 100%;
      padding: 14px 16px;
      border: 1px solid #e0e0e0;
      border-radius: 6px;
      font-size: 14px;
      transition: all 0.3s;
      background-color: #fff;
      font-family: inherit;
    }

    .input-group input:focus {
      border-color: var(--primary-color);
      outline: none;
      box-shadow: 0 0 0 3px rgba(246, 128, 19, 0.08);
    }

    .input-group input::placeholder {
      color: transparent;
    }

    .input-group label {
      position: absolute;
      left: 16px;
      top: 14px;
      color: #999;
      font-size: 13px;
      transition: all 0.3s;
      pointer-events: none;
      background-color: #fff;
      padding: 0 4px;
      font-weight: 500;
    }

    .input-group input:focus + label,
    .input-group input:not(:placeholder-shown) + label {
      top: -8px;
      left: 12px;
      font-size: 11px;
      color: var(--primary-color);
      background: #fff;
      font-weight: 600;
    }

    .remember-forgot {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 28px;
      font-size: 13px;
    }

    .remember-me {
      display: flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      user-select: none;
    }

    .remember-me input {
      width: 16px;
      height: 16px;
      cursor: pointer;
      accent-color: var(--primary-color);
    }

    .remember-me label {
      cursor: pointer;
      color: #666;
      font-weight: 500;
    }

    .forgot-password a {
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 600;
      transition: color 0.2s;
      font-size: 13px;
    }

    .forgot-password a:hover {
      color: var(--secondary-color);
    }

    .login-btn {
      width: 100%;
      padding: 14px 24px;
      background: var(--primary-color);
      border: none;
      border-radius: 6px;
      color: white;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.3s;
      font-family: inherit;
      margin-top: 4px;
    }

    .login-btn:hover {
      background: var(--secondary-color);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    }

    .login-btn:active {
      transform: translateY(0);
    }

    .signup-link {
      margin-top: 24px;
      font-size: 13px;
      color: #666;
      font-weight: 500;
    }

    .signup-link a {
      color: var(--primary-color);
      font-weight: 700;
      text-decoration: none;
      transition: color 0.2s;
    }

    .signup-link a:hover {
      color: var(--secondary-color);
    }

    .alternative-login {
      margin-top: 32px;
      text-align: center;
    }

    .login-btn-row {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin-top: 20px;
      flex-wrap: wrap;
    }

    .hotspot-login-btn, 
    .btn-download,
    .btn-main-site {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 16px;
      font-weight: 600;
      font-size: 12px;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.3s;
      user-select: none;
      min-width: auto;
      justify-content: center;
      font-family: inherit;
    }

    .hotspot-login-btn {
      background: #0066ff;
      color: #fff;
    }

    .hotspot-login-btn:hover {
      background: #0055dd;
      transform: translateY(-1px);
    }

    .btn-download {
      background: #10b981;
      color: #fff;
    }

    .btn-download:hover {
      background: #059669;
      transform: translateY(-1px);
    }

    .btn-main-site {
      background: #64748b;
      color: #fff;
    }

    .btn-main-site:hover {
      background: #475569;
      transform: translateY(-1px);
    }

    .powered-gateway {
      margin-top: 32px;
      text-align: center;
    }

    .powered-label {
      display: block;
      font-size: 11px;
      color: #999;
      margin-bottom: 12px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .gateway-logos {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 0;
      padding: 12px;
      background: #f5f5f5;
      border-radius: 6px;
      border: 1px solid #e0e0e0;
    }

    .gateway-logos img {
      height: 26px;
      max-width: 65px;
      width: auto;
      object-fit: contain;
      opacity: 0.65;
      transition: opacity 0.3s;
    }

    .gateway-logos img:hover {
      opacity: 1;
    }

    @media (max-width: 600px) {
      .login-grid {
        grid-template-columns: 1fr;
        min-height: auto;
        position: relative;
        top: auto;
        left: auto;
        right: auto;
        bottom: auto;
        box-shadow: none;
      }

      .login-btn-row {
        flex-direction: column;
        gap: 8px;
      }

      .hotspot-login-btn, 
      .btn-download,
      .btn-main-site {
        width: 100%;
        min-width: unset;
        font-size: 12px;
        padding: 10px 16px;
      }

      .gateway-logos {
        gap: 10px;
        padding: 10px;
      }

      .gateway-logos img {
        height: 22px;
        max-width: 55px;
      }
    }

    /* Login Grid Layout */
    .login-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 0;
      background: #fff;
      border-radius: 0;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
      overflow: hidden;
      width: 100%;
      max-width: none;
      min-height: 100vh;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
    }

    .login-branding {
      background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
      padding: 80px 60px;
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      color: #fff;
      text-align: left;
      position: relative;
      overflow: hidden;
    }

    .login-branding::before {
      content: "";
      position: absolute;
      bottom: -100px;
      right: -150px;
      width: 400px;
      height: 400px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 50%;
      z-index: 0;
    }

    .login-branding::after {
      content: "";
      position: absolute;
      top: -50px;
      left: -50px;
      width: 300px;
      height: 300px;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 50%;
      z-index: 0;
    }

    .login-branding > * {
      position: relative;
      z-index: 1;
    }

    .logo {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 16px;
      width: 100%;
    }

    .logo-card {
      display: none;
    }

    .logo-img {
      display: none;
    }

    .login-branding h1 {
      font-size: 48px;
      font-weight: 800;
      margin: 0;
      text-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
      letter-spacing: -1px;
    }

    .login-branding p {
      font-size: 18px;
      font-weight: 500;
      margin: 0;
      opacity: 0.95;
      text-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
      letter-spacing: 0.5px;
    }

    .login-form-side {
      padding: 80px 60px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      background: #fff;
    }

    .login-form-side form {
      width: 100%;
      max-width: 420px;
    }

    @media (max-width: 1200px) {
      .login-grid {
        grid-template-columns: 1fr;
        min-height: auto;
        position: relative;
        top: auto;
        left: auto;
        right: auto;
        bottom: auto;
      }

      .login-branding {
        padding: 60px 40px;
        min-height: 300px;
        text-align: center;
        align-items: center;
      }

      .logo {
        align-items: center;
      }

      .login-form-side {
        padding: 60px 40px;
      }

      .login-branding h1 {
        font-size: 36px;
      }

      .login-branding p {
        font-size: 16px;
      }
    }

    @media (max-width: 600px) {
      .login-grid {
        border-radius: 0;
        box-shadow: none;
      }

      .login-branding {
        padding: 40px 24px;
        min-height: 240px;
      }

      .login-form-side {
        padding: 40px 24px;
      }

      .login-form-side form {
        max-width: 100%;
      }

      .login-branding h1 {
        font-size: 28px;
      }

      .login-branding p {
        font-size: 14px;
      }
    }
  </style>
</head>

<body>
  <div class="login-grid">
    <div class="login-branding">
      <div class="logo">
        <h1><?php echo isset($config['perusahaan']) ? $config['perusahaan'] : 'WELCOME'; ?></h1>
        <p><?php echo isset($config['tagline']) ? $config['tagline'] : 'Manajemen Tagihan Pelanggan'; ?></p>
      </div>
    </div>

    <div class="login-form-side">
      <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
      <?php endif; ?>

  <form id="loginForm" action="#" method="POST" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="login_type" value="regular">
        
        <h2 style="text-align: center; color: #333; margin-bottom: 8px; font-size: 28px; font-weight: 700;">Sign in</h2>
        <p style="text-align: center; color: #888; margin-bottom: 32px; font-size: 13px; font-weight: 500;">Login dengan akun Anda</p>

        <div class="input-group">
          <input type="text" id="username" name="username" placeholder=" " required>
          <label for="username">User Name</label>
        </div>

        <div class="input-group">
          <input type="password" id="password" name="password" placeholder=" " required>
          <label for="password">Password</label>
        </div>

        <div class="remember-forgot">
          <div class="remember-me">
            <input type="checkbox" id="remember" name="remember">
            <label for="remember">Ingat saya</label>
          </div>
          <div class="forgot-password">
            <a href="forgot.php">Lupa password?</a>
          </div>
        </div>

        <button type="submit" class="login-btn" id="loginBtn">
    SIGN IN
</button>

        <div class="alternative-login">
          <div class="signup-link">
            Belum punya akun? <a href="sign-up.php">Daftar di sini</a>
          </div>

          <div class="login-btn-row">
            <a href="../login" class="hotspot-login-btn">
              <i class="fas fa-wifi"></i> Hotspot
            </a>
            <a href="https://quenbytekniksejahtera.com/file/CRM%20BILLING_1_1.0.apk" class="btn-download">
              <i class="bi bi-android2"></i> Android
            </a>
            <a href="../../index.php" class="btn-main-site">
              <i class="bi bi-house-door-fill"></i> Home
            </a>
          </div>

          <div class="powered-gateway">
            <span class="powered-label">Payment Gateway</span>
            <div class="gateway-logos">
              <img src="assets/img/tripay.jpg" alt="Tripay" title="Tripay">
              <img src="assets/img/duitku.png" alt="Duitku" title="Duitku">
              <img src="assets/img/xendit.png" alt="Xendit" title="Xendit">
              <img src="assets/img/mitrans.png" alt="Mitrans" title="Mitrans">
            </div>
          </div>
        </div>
      </form>
    </div>
  </div>
<script>
document.querySelectorAll("form").forEach(function(form){

    form.addEventListener("submit",function(e){

        // Validasi browser
        if(!form.checkValidity()){
            return;
        }

        const btn=form.querySelector(".login-btn");

        if(btn){
            btn.classList.add("loading");
            btn.innerHTML="Signing In...";
            btn.disabled=true;
        }

        document.getElementById("loadingOverlay").classList.add("show");
    });

});
</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</body>

</html>