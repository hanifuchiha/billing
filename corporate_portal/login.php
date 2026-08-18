<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['corp_portal_id'])) {
    header('Location: dashboard.php');
    exit;
}

$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$company_name = (!empty($config['perusahaan'])) ? $config['perusahaan'] : 'Billing Internet';
$tagline = (!empty($config['tagline'])) ? $config['tagline'] : 'Portal khusus pelanggan Corporate -- lihat data perusahaan, layanan, dan invoice Anda.';
$primary_color = $config['extracted_primary_color'] ?? '#f68013';

// Brand mode -- pola SAMA persis broadband/portallogin.php, supaya tiap
// tenant/reseller bisa bagikan link Portal Corporate dgn identitas brand
// mereka sendiri (?brand=<username akun CRM>), lihat juga sidebar.php
// (modal "LINK ANDA") & corporate_portal_setting.php.
$brand_param = isset($_GET['brand']) ? trim((string) $_GET['brand']) : '';
$brand_username = preg_replace('/[^a-zA-Z0-9_\-]/', '', $brand_param);
$brand_mode = false;
$brand_name = $company_name;
$brand_logo_url = '';

if ($brand_username !== '') {
    require_once __DIR__ . '/../koneksidb.php';
    if (isset($conn) && $conn instanceof mysqli) {
        $stmt_brand = $conn->prepare("SELECT USERNAME FROM `user` WHERE USERNAME = ? LIMIT 1");
        if ($stmt_brand) {
            $stmt_brand->bind_param('s', $brand_username);
            $stmt_brand->execute();
            $res_brand = $stmt_brand->get_result();
            if ($res_brand && $res_brand->num_rows > 0) {
                $brand_mode = true;
                $brand_name = $brand_username;
            }
            $stmt_brand->close();
        }
    }
    if (!$brand_mode) {
        $brand_mode = true;
        $brand_name = $brand_username;
    }

    $brand_logo_abs = __DIR__ . '/../../../dokumen/logo/profile-' . $brand_username . '.png';
    if (is_file($brand_logo_abs)) {
        $brand_logo_url = '../../../dokumen/logo/profile-' . $brand_username . '.png';
    }
}

$chat_target = $brand_mode ? $brand_username : 'admin';

require_once __DIR__ . '/../corporate_helper.php';
$portal_links = corporatePortalGet($chat_target);

if (trim((string) $portal_links['product_name']) !== '') {
    $brand_name = trim((string) $portal_links['product_name']);
}
if ($brand_mode && trim((string) $portal_links['tagline']) !== '') {
    $tagline = trim((string) $portal_links['tagline']);
}

$feature1_title = $portal_links['feature1_title'] !== '' ? $portal_links['feature1_title'] : 'Data Perusahaan Terpusat';
$feature1_text = $portal_links['feature1_text'] !== '' ? $portal_links['feature1_text'] : 'Lihat data perusahaan, PIC, dan kontrak Anda dalam satu halaman.';
$feature2_title = $portal_links['feature2_title'] !== '' ? $portal_links['feature2_title'] : 'Pantau Layanan';
$feature2_text = $portal_links['feature2_text'] !== '' ? $portal_links['feature2_text'] : 'Cek status koneksi tiap layanan Anda secara real-time.';
$feature3_title = $portal_links['feature3_title'] !== '' ? $portal_links['feature3_title'] : 'Invoice & Pembayaran';
$feature3_text = $portal_links['feature3_text'] !== '' ? $portal_links['feature3_text'] : 'Lihat riwayat invoice, status pembayaran, dan cetak invoice langsung dari portal.';

$kebijakan_items = [
    ['label' => 'FAQ', 'icon' => 'bi-question-circle-fill', 'text' => $portal_links['faq_text']],
    ['label' => 'Kebijakan Refund', 'icon' => 'bi-arrow-counterclockwise', 'text' => $portal_links['refund_text']],
    ['label' => 'Syarat & Ketentuan', 'icon' => 'bi-file-earmark-text-fill', 'text' => $portal_links['terms_text']],
    ['label' => 'Kontak', 'icon' => 'bi-telephone-fill', 'text' => $portal_links['contact_text']],
];
$kebijakan_items = array_values(array_filter($kebijakan_items, function ($item) {
    return trim((string) $item['text']) !== '';
}));

$error = $_GET['error'] ?? '';
$suspended = ($_GET['suspended'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portal Corporate -- <?php echo htmlspecialchars($brand_name); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background: #f1f5f9; font-family: 'Segoe UI', Arial, sans-serif; min-height: 100vh; }
.side-panel {
    background: linear-gradient(135deg, <?php echo htmlspecialchars($primary_color); ?> 0%, #1f2937 100%);
    color: #fff; min-height: 100vh; padding: 48px 40px;
}
.side-panel .logo-box img { max-height: 56px; margin-bottom: 12px; }
.feature-item { background: rgba(255,255,255,.08); border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
.login-panel { display: flex; align-items: center; min-height: 100vh; }
.login-card { max-width: 400px; margin: auto; border: none; }
</style>
</head>
<body>
<div class="container-fluid">
  <div class="row">
    <div class="col-lg-6 d-none d-lg-block side-panel">
      <div class="logo-box">
        <?php if ($brand_logo_url !== ''): ?><img src="<?php echo htmlspecialchars($brand_logo_url); ?>" alt="logo"><?php endif; ?>
        <h3 class="mb-1"><?php echo htmlspecialchars($brand_name); ?></h3>
        <p class="opacity-75"><?php echo htmlspecialchars($tagline); ?></p>
      </div>
      <div class="mt-4">
        <div class="feature-item"><i class="bi bi-building me-2"></i><b><?php echo htmlspecialchars($feature1_title); ?></b><div class="small opacity-75 mt-1"><?php echo htmlspecialchars($feature1_text); ?></div></div>
        <div class="feature-item"><i class="bi bi-diagram-3-fill me-2"></i><b><?php echo htmlspecialchars($feature2_title); ?></b><div class="small opacity-75 mt-1"><?php echo htmlspecialchars($feature2_text); ?></div></div>
        <div class="feature-item"><i class="bi bi-receipt me-2"></i><b><?php echo htmlspecialchars($feature3_title); ?></b><div class="small opacity-75 mt-1"><?php echo htmlspecialchars($feature3_text); ?></div></div>
      </div>
      <?php if (!empty($kebijakan_items)): ?>
        <button type="button" class="btn btn-sm btn-outline-light mt-3" data-bs-toggle="modal" data-bs-target="#kebijakanModal">
          <i class="bi bi-info-circle me-1"></i>Kebijakan &amp; Bantuan
        </button>
      <?php endif; ?>
    </div>

    <div class="col-lg-6 login-panel">
      <div class="card login-card">
        <div class="card-body p-4">
          <div class="text-center mb-3 d-lg-none">
            <?php if ($brand_logo_url !== ''): ?><img src="<?php echo htmlspecialchars($brand_logo_url); ?>" style="max-height:48px;" alt="logo"><?php endif; ?>
            <h5 class="mt-2 mb-0"><?php echo htmlspecialchars($brand_name); ?></h5>
          </div>
          <h5 class="mb-3">Masuk Portal Corporate</h5>

          <?php if ($suspended): ?>
            <div class="alert alert-warning small">Akun perusahaan Anda sedang dinonaktifkan. Hubungi admin.</div>
          <?php elseif ($error === '1'): ?>
            <div class="alert alert-danger small">Username atau password salah.</div>
          <?php elseif ($error === '2'): ?>
            <div class="alert alert-danger small">Sesi login kedaluwarsa, silakan coba lagi.</div>
          <?php endif; ?>

          <form method="post" action="proses_login.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="mb-3">
              <label class="form-label">Username Portal</label>
              <input required type="text" class="form-control" name="username" autofocus>
            </div>
            <div class="mb-3">
              <label class="form-label">Password</label>
              <input required type="password" class="form-control" name="password">
            </div>
            <button type="submit" class="btn btn-primary w-100">Masuk</button>
          </form>
          <p class="text-center text-muted small mt-3 mb-0">
            Belum punya akses portal? Hubungi admin <?php echo htmlspecialchars($brand_name); ?> Anda.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($kebijakan_items)): ?>
<div class="modal fade" id="kebijakanModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Kebijakan &amp; Bantuan</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="accordion" id="kebijakanAccordion">
          <?php foreach ($kebijakan_items as $idx => $item): ?>
            <div class="accordion-item">
              <h2 class="accordion-header">
                <button class="accordion-button <?php echo $idx > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#kebijakanItem<?php echo $idx; ?>">
                  <i class="bi <?php echo htmlspecialchars($item['icon']); ?> me-2"></i><?php echo htmlspecialchars($item['label']); ?>
                </button>
              </h2>
              <div id="kebijakanItem<?php echo $idx; ?>" class="accordion-collapse collapse <?php echo $idx === 0 ? 'show' : ''; ?>" data-bs-parent="#kebijakanAccordion">
                <div class="accordion-body" style="white-space: pre-wrap;"><?php echo htmlspecialchars($item['text']); ?></div>
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
</body>
</html>
