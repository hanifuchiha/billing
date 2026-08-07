<?php
session_start();
session_write_close();

$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$site_name = $config['site_name'] ?? 'ISP';

// Get live chat / CS contact info from botwa table if available
$cs_contact = '';
$cs_link = '';
if (!empty($config['db_host']) && !empty($config['db_name'])) {
    $connProv = @mysqli_connect($config['db_host'], $config['db_user'] ?? 'root', $config['db_pass'] ?? '', $config['db_name']);
    if ($connProv) {
        // Try to get livechat number from botwa
        $lcQ = mysqli_query($connProv, "SELECT penerima_livechat FROM botwa WHERE penerima_livechat IS NOT NULL AND penerima_livechat != '' LIMIT 1");
        if ($lcQ && mysqli_num_rows($lcQ) > 0) {
            $lcRow = mysqli_fetch_assoc($lcQ);
            $cs_contact = $lcRow['penerima_livechat'];
        }
        mysqli_close($connProv);
    }
}

if (!empty($cs_contact)) {
    $cs_digits = preg_replace('/\D/', '', $cs_contact);
    if (substr($cs_digits, 0, 1) === '0') {
        $cs_digits = '62' . substr($cs_digits, 1);
    }
    $cs_link = 'https://wa.me/' . $cs_digits;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Menunggu Aktivasi | <?= htmlspecialchars($site_name) ?></title>
  <link rel="icon" href="img/uang.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root {
      --primary: #0d6efd;
      --dark-primary: #0a58ca;
      --orange: #F7941D;
      --white: #ffffff;
      --border-color: #e9ecef;
    }
    body {
      background: linear-gradient(135deg, var(--primary), var(--dark-primary));
      font-family: 'Poppins', sans-serif;
      min-height: 100vh;
      margin: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .info-card {
      background: var(--white);
      border-radius: 20px;
      box-shadow: 0 14px 44px rgba(0,0,0,0.14);
      max-width: 500px;
      width: 94vw;
      overflow: hidden;
    }
    .info-header {
      background: var(--orange);
      color: white;
      padding: 24px 20px;
      text-align: center;
    }
    .info-header h4 {
      font-weight: 700;
      margin: 0;
      font-size: 1.3rem;
    }
    .info-body {
      padding: 30px 25px;
      text-align: center;
    }
    .info-icon {
      font-size: 64px;
      color: var(--orange);
      margin-bottom: 16px;
    }
    .info-text {
      font-size: 1rem;
      color: #333;
      line-height: 1.7;
      margin-bottom: 24px;
    }
    .btn-cs {
      background: var(--primary);
      color: white;
      border: none;
      padding: 12px 30px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 1rem;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
    }
    .btn-cs:hover {
      background: var(--dark-primary);
      color: white;
    }
    .btn-back {
      background: transparent;
      color: var(--primary);
      border: 2px solid var(--primary);
      padding: 10px 30px;
      border-radius: 10px;
      font-weight: 600;
      font-size: 0.95rem;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 0.2s;
      margin-top: 12px;
    }
    .btn-back:hover {
      background: var(--primary);
      color: white;
    }
  </style>
</head>
<body>
  <div class="info-card">
    <div class="info-header">
      <h4><i class="bi bi-hourglass-split"></i> MENUNGGU AKTIVASI</h4>
    </div>
    <div class="info-body">
      <div class="info-icon">
        <i class="bi bi-clock-history"></i>
      </div>
      <div class="info-text">
        <strong>Saat ini Anda belum bisa untuk melihat tagihan atau masuk ke menu pelanggan.</strong>
        <br><br>
        Data Anda sedang dalam proses aktivasi oleh penyedia layanan. Mohon tunggu hingga proses selesai.
        <br><br>
        Silahkan hubungi <strong>live chat</strong> atau <strong>customer service</strong> untuk informasi lebih lanjut.
      </div>
      <?php if (!empty($cs_link)): ?>
        <a href="<?= htmlspecialchars($cs_link) ?>" target="_blank" class="btn-cs">
          <i class="bi bi-whatsapp"></i> Hubungi Customer Service
        </a>
      <?php endif; ?>
      <br>
      <a href="portallogin.php" class="btn-back">
        <i class="bi bi-arrow-left"></i> Kembali ke Login
      </a>
    </div>
  </div>
</body>
</html>
