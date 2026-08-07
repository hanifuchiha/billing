<?php
include 'cek_sesi.php';
// Simpan konfigurasi
$config_file ='../config.json'; // Lokasi file di folder yang sama
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

$domain=$config['domain'];
$brand_param = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
$brand_from_owner = isset($pelanggan['PEMILIK']) ? (string)$pelanggan['PEMILIK'] : '';
$chat_target_raw = $brand_param !== '' ? $brand_param : $brand_from_owner;
$chat_target = preg_replace('/[^a-zA-Z0-9_\-]/', '', $chat_target_raw);
if ($chat_target === '') {
    $chat_target = 'admin';
}
$brand_query_suffix = '&brand=' . rawurlencode($chat_target);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pelanggan['PEMILIK']); ?> - Internet Service</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-green: #0d6efd;
            --dark-green: #0a58ca;
            --orange: #F7941D;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --border-color: #e9ecef;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            background-color: var(--white);
            color: #333;
            padding-bottom: 70px;
            height: 100vh;
            overflow: hidden;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            height: calc(100vh - 70px);
            display: flex;
            flex-direction: column;
        }
        /* ...existing code... */
        .navbar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--primary-green);
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            z-index: 100;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: white;
            font-size: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            padding: 5px 8px;
            border-radius: 8px;
            min-width: 60px;
            outline: none;
        }
        .nav-item:hover,
        .nav-item:focus {
            background-color: var(--orange);
            color: white !important;
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0,0,0,0.10);
            border: 2px solid var(--orange);
            z-index: 2;
        }
        .nav-icon {
            margin-bottom: 5px;
            font-size: 18px;
            transition: all 0.3s ease;
        }
        .nav-item.active,
        .nav-item.active:focus {
            color: white !important;
            background: linear-gradient(135deg, var(--orange) 60%, var(--primary-green) 100%);
            border: 2px solid var(--orange);
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0,0,0,0.10);
            font-weight: bold;
            z-index: 3;
        }
        .nav-item.active .nav-icon {
            transform: scale(1.1);
        }
        /* ...existing code... */
    </style>

    <script>
    // Extract dominant color from logo (profile-[useraccount].png if exists, else logo.png) and set CSS variables (logo not displayed)
    document.addEventListener('DOMContentLoaded', function() {
        var logoUrl = '';
        <?php
        // $logo_path sudah dihitung (server-per-area aware) di cek_sesi.php
        echo "logoUrl = '" . addslashes($logo_path) . "?v=" . time() . "';\n";
        ?>
        if (!logoUrl) return;
        var img = document.createElement('img');
        img.crossOrigin = 'Anonymous';
        img.src = logoUrl;
        img.style.display = 'none';
        document.body.appendChild(img);
        img.onload = function() {
            try {
                var canvas = document.createElement('canvas');
                canvas.width = img.naturalWidth || 120;
                canvas.height = img.naturalHeight || 120;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                var colorCounts = {};
                for (var i = 0; i < data.length; i += 12) {
                    var r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
                    if (a < 128 || (r > 240 && g > 240 && b > 240) || (r < 15 && g < 15 && b < 15)) continue;
                    var color = r+','+g+','+b;
                    colorCounts[color] = (colorCounts[color]||0)+1;
                }
                var sorted = Object.entries(colorCounts).sort(function(a,b){return b[1]-a[1];});
                if (sorted.length) {
                    var primary = sorted[0][0];
                    var secondary = sorted[1] ? sorted[1][0] : primary;
                    var accent = sorted[2] ? sorted[2][0] : primary;
                    document.documentElement.style.setProperty('--primary-green', 'rgb('+primary+')');
                    document.documentElement.style.setProperty('--orange', 'rgb('+secondary+')');
                    document.documentElement.style.setProperty('--dark-green', 'rgb('+accent+')');
                }
            } catch(e) {}
            img.remove();
        };
    });

    </script>
    <style>
        .payment-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 15px;
            font-weight: bold;
            font-size: 14px;
        }
        .status-paid {
            background-color: #4caf50;
            color: white;
        }
        .status-unpaid {
            background-color: var(--orange);
            color: white;
        }
        .status-expired {
            background-color: #f44336;
            color: white;
        }
        .payment-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        .payment-button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
        }

   
        .btn-success {
            background-color: var(--dark-green);
            color: white;
        }

        .btn-danger {
            background-color: #f44336;
            color: white;
        }

        /* Bill Details Section */
        .bill-details {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .bill-details-header {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .bill-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        .bill-table th, .bill-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .bill-table th {
            color: var(--primary-green);
            font-weight: bold;
        }

        .bill-total {
            background-color: #e8f5e9;
            font-weight: bold;
        }

        /* Payment Method Section */
        .payment-method {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .payment-method-header {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .method-select {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .submit-button {
            background-color: var(--orange);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }

        /* Manual Payment Section */
        .manual-payment {
            background-color: var(--light-gray);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .manual-payment-header {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .bank-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .bank-table th, .bank-table td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .bank-table th {
            background-color: #e3f2fd;
            color: var(--primary-green);
            font-weight: bold;
        }

        .upload-form {
            margin-top: 20px;
        }

        .file-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .upload-button {
            background-color: var(--dark-green);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
        }

        /* Alert Styles */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #4caf50;
        }

        .alert-info {
            background-color: #e3f2fd;
            color: #1565c0;
            border: 1px solid #2196f3;
        }

        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #f44336;
        }

        /* Ads Section - Full Width */
        .ads-section {
            margin-bottom: 20px;
        }

        .ads-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 15px;
            font-size: 18px;
        }

        .ad-card {
            background-color: var(--light-gray);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            width: 100%;
        }

        .ad-image {
            height: 150px;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            position: relative;
        }

        .ad-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: var(--orange);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }

        .ad-content {
            padding: 15px;
        }

        .ad-title {
            font-weight: bold;
            color: var(--primary-green);
            margin-bottom: 8px;
            font-size: 16px;
        }

        .ad-desc {
            font-size: 14px;
            color: #666;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .ad-features {
            margin-bottom: 15px;
        }

        .ad-feature {
            display: flex;
            align-items: center;
            margin-bottom: 5px;
            font-size: 13px;
            color: #555;
        }

        .ad-feature:before {
            content: "✓";
            color: #4caf50;
            margin-right: 8px;
            font-weight: bold;
        }

        .ad-button {
            background-color: var(--orange);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            font-size: 14px;
        }

        /* Navigation Bar */
        .navbar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: var(--primary-green);
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
            z-index: 100;
        }

        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: white;
            font-size: 12px;
            text-decoration: none;
        }

        .nav-icon {
            margin-bottom: 5px;
            font-size: 18px;
        }

        .nav-item.active {
            color: var(--orange);
        }

        /* Iframe Container */
        .iframe-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .iframe-wrapper {
            flex: 1;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .full-iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }

        /* Loading Message */
        .loading-message {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            background-color: var(--light-gray);
            color: #666;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Iframe Container -->
        <div class="iframe-container">
            <div class="iframe-wrapper">
                <div id="loading-message" class="loading-message">
                    Memuat chat...
                </div>
                <iframe
                    src="https://<?php echo htmlspecialchars((string)$domain, ENT_QUOTES, 'UTF-8'); ?>/crm/chat/index.php?idpel=<?= htmlspecialchars($pelanggan['IDPEL'], ENT_QUOTES, 'UTF-8'); ?>&to=<?= rawurlencode($chat_target) ?>"
                    class="full-iframe"
                    frameborder="0"
                    onload="document.getElementById('loading-message').style.display='none';">
                </iframe>
            </div>
        </div>
    </div>
    <!-- Navigation Bar -->
  <div class="navbar">
        <a href="portal_baru.php?cari=<?= rawurlencode((string)$idpel); ?><?= $brand_query_suffix; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-house-door-fill"></i></div>
      <div>Beranda</div>
    </a>
        <a href="portal_bayar.php?cari=<?= rawurlencode((string)$idpel); ?><?= $brand_query_suffix; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-credit-card"></i></div>
      <div>Bayar</div>
    </a>
        <a href="portal_chat.php?cari=<?= rawurlencode((string)$idpel); ?><?= $brand_query_suffix; ?>" class="nav-item active">
      <div class="nav-icon"><i class="bi bi-chat-dots"></i></div>
      <div>Chat</div>
    </a>
                <a href="portal_mywifi.php?cari=<?= rawurlencode((string)$idpel); ?><?= $brand_query_suffix; ?>" class="nav-item">
            <div class="nav-icon"><i class="bi bi-wifi"></i></div>
            <div>WiFi Saya</div>
        </a>
         <a href="portal_riwayat.php?cari=<?= rawurlencode((string)$idpel); ?><?= $brand_query_suffix; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-clock-history"></i></div>
      <div>Riwayat</div>
    </a>
        <a href="portal_profile.php?cari=<?= rawurlencode((string)$idpel); ?><?= $brand_query_suffix; ?>" class="nav-item ">
      <div class="nav-icon"><i class="bi bi-person-circle"></i></div>
      <div>Profile</div>
    </a>
  </div>
</body>
</html>