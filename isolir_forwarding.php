<?php
/* =========================================================
   DOWNLOAD LANDING PAGE -- harus ditangani SEBELUM header.php di-require.
   header.php langsung mencetak HTML (tidak pakai ob_start()), jadi begitu ada
   output apa pun, header('Content-Disposition: attachment...') di bawah jadi
   tidak berpengaruh lagi (PHP tidak bisa kirim HTTP header setelah ada output) --
   akibatnya browser cuma menampilkan HTML-nya langsung, bukan men-download file.
   Makanya request download di-intercept di sini, pakai cek-sesi.php (bootstrap
   ringan tanpa output HTML) seperti endpoint AJAX lain di aplikasi ini, BUKAN
   header.php.
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_landing'])) {
    require __DIR__ . '/cek-sesi.php';

    if (empty($_SESSION['id']) || $AKSES !== 'ADMIN') {
        http_response_code(403);
        die('Access denied.');
    }

    require_once __DIR__ . '/proses/isolir_helpers.php';

    $appConfigFileEarly = __DIR__ . '/config.json';
    $appConfigEarly = file_exists($appConfigFileEarly) ? (json_decode(file_get_contents($appConfigFileEarly), true) ?: []) : [];
    $autoSchemeEarly = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $autoHostEarly = $_SERVER['HTTP_HOST'] ?? ($appConfigEarly['domain'] ?? 'quenbytekniksejahtera.com');
    $autoBayarSekarangUrlEarly = $autoSchemeEarly . '://' . $autoHostEarly . '/crm/billing/broadband/portallogin.php';

    ensureIsolirForwardingTables($conn);
    $settingsNow = getIsolirForwardingSettings($conn, $current_user_id, [
        'billing_server_ip' => $appConfigEarly['ippublic'] ?? ($appConfigEarly['router_ip'] ?? '31.57.178.141'),
        'billing_domain' => $autoHostEarly,
        'landing_url' => $autoBayarSekarangUrlEarly,
    ]);

    $landingUrlNow = trim($_POST['landing_url'] ?? $settingsNow['landing_url']);
    $landingHtmlNow = $_POST['landing_html'] ?? $settingsNow['landing_html'];
    $finalHtml = str_replace('{{LANDING_URL}}', $landingUrlNow, $landingHtmlNow);

    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="landing-isolir.html"');
    header('Content-Length: ' . strlen($finalHtml));
    echo $finalHtml;
    exit;
}

require 'header.php';
require 'routeros_api.class.php';
require __DIR__ . '/proses/isolir_helpers.php';

if ($AKSES !== 'ADMIN') {
    die('Access denied. Only ADMIN can access this page.');
}

/* =========================================================
   AUTO-DETECT: IP server billing, domain billing, URL bayar sekarang
   Diambil dari config.json aplikasi ini (bukan MikroTik) + request saat ini,
   supaya tidak perlu diketik manual pertama kali (masih bisa diedit/ditimpa admin).
   ========================================================= */

$appConfigFile = __DIR__ . '/config.json';
$appConfig = file_exists($appConfigFile) ? (json_decode(file_get_contents($appConfigFile), true) ?: []) : [];

$autoBillingServerIp = $appConfig['ippublic'] ?? ($appConfig['router_ip'] ?? '31.57.178.141');

$autoScheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$autoHost = $_SERVER['HTTP_HOST'] ?? ($appConfig['domain'] ?? 'quenbytekniksejahtera.com');
$autoBillingDomain = $autoHost;
$autoBayarSekarangUrl = $autoScheme . '://' . $autoHost . '/crm/billing/broadband/portallogin.php';

/* =========================================================
   TABLE BOOTSTRAP
   ========================================================= */

function ensureIsolirForwardingTables($conn)
{
    // Pengaturan GLOBAL (landing url, domain billing, landing IP, landing page HTML)
    $sql_settings = "CREATE TABLE IF NOT EXISTS `isolir_forwarding_settings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `billing_server_ip` VARCHAR(50) NOT NULL DEFAULT '31.57.178.141',
        `billing_domain` VARCHAR(255) NOT NULL DEFAULT 'quenbytekniksejahtera.com',
        `landing_ip` VARCHAR(50) NOT NULL DEFAULT '10.6.0.1',
        `landing_port` VARCHAR(10) NOT NULL DEFAULT '8080',
        `landing_url` VARCHAR(255) NOT NULL DEFAULT 'https://quenbytekniksejahtera.com/crm/billing/broadband/portallogin.php',
        `landing_html` MEDIUMTEXT DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    // Pengaturan PER SERVER: sekarang menyimpan RANGE IP dari PPP Profile "EXPIRED"
    // (bukan lagi daftar IP manual) + status apakah rule sudah diterapkan ke router.
    $sql_server_settings = "CREATE TABLE IF NOT EXISTS `isolir_forwarding_server_settings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `server_id` INT(11) NOT NULL,
        `expired_ips` TEXT DEFAULT NULL,
        `expired_pool_range` VARCHAR(255) DEFAULT NULL,
        `expired_local_ip` VARCHAR(50) DEFAULT NULL,
        `is_applied` TINYINT(1) NOT NULL DEFAULT 0,
        `applied_at` TIMESTAMP NULL DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_server` (`server_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $sql_logs = "CREATE TABLE IF NOT EXISTS `isolir_forwarding_logs` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `server_id` INT(11) NOT NULL,
        `server_ip` VARCHAR(100) NOT NULL,
        `status` ENUM('SUCCESS', 'FAILED') NOT NULL,
        `message` TEXT DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_server_id` (`server_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    mysqli_query($conn, $sql_settings);
    mysqli_query($conn, $sql_server_settings);
    mysqli_query($conn, $sql_logs);

    // Auto-migrate kolom baru untuk tabel yang sudah ada dari versi sebelumnya.
    // CREATE TABLE IF NOT EXISTS di atas TIDAK mengubah tabel yang sudah ada (mis. dari
    // versi lama fitur ini dengan skema berbeda) -- makanya kolom yang mungkin belum ada
    // tetap harus ditambah manual lewat ALTER TABLE di sini. Query di-@ (silent) supaya
    // aman dijalankan berulang kali: kalau kolom sudah ada, cuma gagal diam-diam.
    // Tanpa "AFTER <kolom>" dengan sengaja -- kalau kolom acuannya sendiri ternyata tidak
    // ada di skema lama, ALTER dengan AFTER akan gagal dan merusak urutan alter berikutnya
    // yang saling bergantung. Posisi kolom tidak penting secara fungsional.
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD COLUMN `user_id` INT(11) NOT NULL DEFAULT 0");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD COLUMN `billing_server_ip` VARCHAR(50) NOT NULL DEFAULT '31.57.178.141'");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD COLUMN `billing_domain` VARCHAR(255) NOT NULL DEFAULT 'quenbytekniksejahtera.com'");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD COLUMN `landing_ip` VARCHAR(50) NOT NULL DEFAULT '10.6.0.1'");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD COLUMN `landing_port` VARCHAR(10) NOT NULL DEFAULT '8080'");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD COLUMN `landing_url` VARCHAR(255) NOT NULL DEFAULT 'https://quenbytekniksejahtera.com/crm/billing/broadband/portallogin.php'");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD COLUMN `landing_html` MEDIUMTEXT DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_settings` ADD UNIQUE KEY `uniq_user` (`user_id`)");

    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_server_settings` ADD COLUMN `expired_pool_range` VARCHAR(255) DEFAULT NULL AFTER `expired_ips`");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_server_settings` ADD COLUMN `expired_local_ip` VARCHAR(50) DEFAULT NULL AFTER `expired_pool_range`");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_server_settings` ADD COLUMN `is_applied` TINYINT(1) NOT NULL DEFAULT 0 AFTER `expired_local_ip`");
    @mysqli_query($conn, "ALTER TABLE `isolir_forwarding_server_settings` ADD COLUMN `applied_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_applied`");
}

/* =========================================================
   DEFAULT LANDING PAGE HTML (dipakai kalau belum pernah diisi)
   ========================================================= */

function defaultLandingHtml()
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Internet Terisolir</title>
  <style>
    *{
      margin:0;
      padding:0;
      box-sizing:border-box;
      font-family:'Segoe UI',sans-serif;
    }
    body{
      height:100vh;
      display:flex;
      justify-content:center;
      align-items:center;
      background:
      linear-gradient(135deg,#0f172a,#1e1b4b,#312e81,#2563eb);
      overflow:hidden;
      color:white;
      padding:20px;
    }
    body::before{
      content:'';
      position:absolute;
      width:500px;
      height:500px;
      background:rgba(59,130,246,0.25);
      border-radius:50%;
      top:-150px;
      right:-150px;
      filter:blur(80px);
    }
    body::after{
      content:'';
      position:absolute;
      width:400px;
      height:400px;
      background:rgba(139,92,246,0.25);
      border-radius:50%;
      bottom:-120px;
      left:-120px;
      filter:blur(80px);
    }
    .card{
      position:relative;
      z-index:2;
      width:100%;
      max-width:460px;
      background:rgba(255,255,255,0.08);
      border:1px solid rgba(255,255,255,0.15);
      backdrop-filter:blur(15px);
      border-radius:25px;
      padding:40px 35px;
      text-align:center;
      box-shadow:0 10px 40px rgba(0,0,0,0.35);
    }
    .icon{
      font-size:75px;
      margin-bottom:15px;
    }
    h1{
      font-size:32px;
      margin-bottom:15px;
      font-weight:700;
    }
    p{
      font-size:16px;
      line-height:1.7;
      color:rgba(255,255,255,0.88);
      margin-bottom:30px;
    }
    .btn{
      display:inline-block;
      width:100%;
      padding:16px;
      border-radius:15px;
      background:linear-gradient(135deg,#06b6d4,#3b82f6);
      color:white;
      text-decoration:none;
      font-size:18px;
      font-weight:bold;
      letter-spacing:1px;
      transition:0.3s;
      box-shadow:0 5px 20px rgba(59,130,246,0.4);
    }
    .btn:hover{
      transform:translateY(-3px);
      box-shadow:0 10px 25px rgba(59,130,246,0.6);
    }
    .info{
      margin-top:25px;
      font-size:14px;
      color:rgba(255,255,255,0.7);
    }
    @media(max-width:500px){
      .card{
        padding:30px 25px;
      }
      h1{
        font-size:26px;
      }
      p{
        font-size:15px;
      }
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">??</div>
    <h1>Koneksi Internet Terisolir</h1>
    <p>
      Layanan internet Anda saat ini sedang dibatasi karena terdapat tagihan yang belum diselesaikan.
      Silakan lakukan pembayaran agar layanan kembali aktif secara otomatis.
    </p>
    <a href="{{LANDING_URL}}" class="btn">
      BAYAR SEKARANG
    </a>
    <div class="info">
      QUENBY TECHNICAL &bull; INTERNET SERVICE
    </div>
  </div>
</body>
</html>
HTML;
}

/* =========================================================
   SETTINGS (GLOBAL) CRUD
   ========================================================= */

function upsertIsolirForwardingSettings($conn, $userId, $billingServerIp, $billingDomain, $landingIp, $landingPort, $landingUrl, $landingHtml)
{
    $userId = (int)$userId;
    $billingServerIp = mysqli_real_escape_string($conn, $billingServerIp);
    $billingDomain = mysqli_real_escape_string($conn, $billingDomain);
    $landingIp = mysqli_real_escape_string($conn, $landingIp);
    $landingPort = mysqli_real_escape_string($conn, $landingPort);
    $landingUrl = mysqli_real_escape_string($conn, $landingUrl);
    $landingHtml = mysqli_real_escape_string($conn, $landingHtml);

    $sql = "INSERT INTO isolir_forwarding_settings
                (user_id, billing_server_ip, billing_domain, landing_ip, landing_port, landing_url, landing_html)
            VALUES
                ($userId, '$billingServerIp', '$billingDomain', '$landingIp', '$landingPort', '$landingUrl', '$landingHtml')
            ON DUPLICATE KEY UPDATE
                billing_server_ip = VALUES(billing_server_ip),
                billing_domain = VALUES(billing_domain),
                landing_ip = VALUES(landing_ip),
                landing_port = VALUES(landing_port),
                landing_url = VALUES(landing_url),
                landing_html = VALUES(landing_html)";

    return mysqli_query($conn, $sql);
}

function getIsolirForwardingSettings($conn, $userId, $autoDefaults)
{
    $userId = (int)$userId;
    $default = [
        'billing_server_ip' => $autoDefaults['billing_server_ip'],
        'billing_domain' => $autoDefaults['billing_domain'],
        'landing_ip' => '',
        'landing_port' => '8080',
        'landing_url' => $autoDefaults['landing_url'],
        'landing_html' => defaultLandingHtml()
    ];

    $query = mysqli_query($conn, "SELECT * FROM isolir_forwarding_settings WHERE user_id = $userId LIMIT 1");
    if ($query && mysqli_num_rows($query) > 0) {
        $row = mysqli_fetch_assoc($query);
        return [
            'billing_server_ip' => $row['billing_server_ip'],
            'billing_domain' => $row['billing_domain'],
            'landing_ip' => $row['landing_ip'],
            'landing_port' => $row['landing_port'],
            'landing_url' => $row['landing_url'],
            'landing_html' => $row['landing_html'] !== null && $row['landing_html'] !== ''
                ? $row['landing_html']
                : defaultLandingHtml()
        ];
    }

    return $default;
}

/* =========================================================
   PER-SERVER ISOLIR STATUS (pool range dari PPP Profile EXPIRED)
   ========================================================= */

function upsertServerIsolirStatus($conn, $userId, $serverId, $poolRange, $localIp, $isApplied)
{
    $userId = (int)$userId;
    $serverId = (int)$serverId;
    $poolRange = mysqli_real_escape_string($conn, $poolRange);
    $localIp = mysqli_real_escape_string($conn, $localIp);
    $isApplied = $isApplied ? 1 : 0;
    $appliedAtSql = $isApplied ? 'NOW()' : 'NULL';

    $sql = "INSERT INTO isolir_forwarding_server_settings (user_id, server_id, expired_pool_range, expired_local_ip, is_applied, applied_at)
            VALUES ($userId, $serverId, '$poolRange', '$localIp', $isApplied, $appliedAtSql)
            ON DUPLICATE KEY UPDATE
                expired_pool_range = VALUES(expired_pool_range),
                expired_local_ip = VALUES(expired_local_ip),
                is_applied = VALUES(is_applied),
                applied_at = " . ($isApplied ? 'NOW()' : 'applied_at');

    return mysqli_query($conn, $sql);
}

function getAppliedIsolirServers($conn, $userId)
{
    $userId = (int)$userId;
    $sql = "SELECT s.id, s.IP, s.PEMILIK, s.AREA, s.BRAND,
                   ifs.expired_pool_range, ifs.expired_local_ip, ifs.applied_at
            FROM isolir_forwarding_server_settings ifs
            JOIN server s ON s.id = ifs.server_id
            WHERE ifs.user_id = $userId AND ifs.is_applied = 1
            ORDER BY ifs.applied_at DESC";
    $rows = [];
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/* =========================================================
   ROUTEROS HELPERS (rule management, generik -- dipakai untuk filter/nat/proxy access)
   ========================================================= */

function findAddressListEntry($API, $listName, $address)
{
    $rows = $API->comm('/ip/firewall/address-list/print', [
        '?list' => $listName,
        '?address' => $address
    ]);
    if (!empty($rows) && isset($rows[0]['.id'])) {
        return $rows[0];
    }
    return null;
}

function ensureAddressListEntry($API, $listName, $address, $comment)
{
    $existing = findAddressListEntry($API, $listName, $address);
    if ($existing) {
        return 'exists';
    }

    $API->comm('/ip/firewall/address-list/add', [
        'list' => $listName,
        'address' => $address,
        'comment' => $comment
    ]);
    return 'created';
}

function findFilterRuleByComment($API, $comment)
{
    $rows = $API->comm('/ip/firewall/filter/print', ['?comment' => $comment]);
    if (!empty($rows) && isset($rows[0]['.id'])) {
        return $rows[0];
    }
    return null;
}

function findNatRuleByComment($API, $comment)
{
    $rows = $API->comm('/ip/firewall/nat/print', ['?comment' => $comment]);
    if (!empty($rows) && isset($rows[0]['.id'])) {
        return $rows[0];
    }
    return null;
}

function findWebProxyRuleByComment($API, $comment)
{
    $rows = $API->comm('/ip/proxy/access/print', ['?comment' => $comment]);
    if (!empty($rows) && isset($rows[0]['.id'])) {
        return $rows[0];
    }
    return null;
}

/**
 * Tambah / update rule filter berdasarkan comment (idempotent).
 * Jika rule sudah ada tapi parameternya berubah, di-set ulang.
 */
function ensureFilterRule($API, $comment, $params)
{
    $existing = findFilterRuleByComment($API, $comment);
    if ($existing) {
        $setParams = $params;
        $setParams['.id'] = $existing['.id'];
        $API->comm('/ip/firewall/filter/set', $setParams);
        return $existing['.id'];
    }

    $params['comment'] = $comment;
    $API->comm('/ip/firewall/filter/add', $params);

    $created = findFilterRuleByComment($API, $comment);
    return $created ? $created['.id'] : null;
}

function ensureNatRule($API, $comment, $params)
{
    $existing = findNatRuleByComment($API, $comment);
    if ($existing) {
        $setParams = $params;
        $setParams['.id'] = $existing['.id'];
        $API->comm('/ip/firewall/nat/set', $setParams);
        return $existing['.id'];
    }

    $params['comment'] = $comment;
    $API->comm('/ip/firewall/nat/add', $params);

    $created = findNatRuleByComment($API, $comment);
    return $created ? $created['.id'] : null;
}

function ensureWebProxyAccessRule($API, $comment, $params)
{
    $existing = findWebProxyRuleByComment($API, $comment);
    if ($existing) {
        $setParams = $params;
        $setParams['.id'] = $existing['.id'];
        $API->comm('/ip/proxy/access/set', $setParams);
        return $existing['.id'];
    }

    $params['comment'] = $comment;
    $API->comm('/ip/proxy/access/add', $params);

    $created = findWebProxyRuleByComment($API, $comment);
    return $created ? $created['.id'] : null;
}

function moveFilterRule($API, $ruleId, $destination)
{
    if (!$ruleId) {
        return;
    }
    $API->comm('/ip/firewall/filter/move', [
        '.id' => $ruleId,
        'destination' => (string)$destination
    ]);
}

/**
 * /ip proxy access dievaluasi berurutan dari atas (rule pertama yang match yang dipakai),
 * sama seperti /ip firewall filter -- jadi urutan rule "allow domain" HARUS di atas rule
 * "redirect" catch-all, kalau tidak semua domain allow jadi percuma (keburu di-redirect).
 */
function moveWebProxyAccessRule($API, $ruleId, $destination)
{
    if (!$ruleId) {
        return;
    }
    $API->comm('/ip/proxy/access/move', [
        '.id' => $ruleId,
        'destination' => (string)$destination
    ]);
}

/* =========================================================
   MAIN APPLY LOGIC PER SERVER
   Range IP Pool "EXPIRED" (dari PPP Profile EXPIRED) otomatis disinkronkan sebagai
   entry di address-list "EXPIRED" (comment "Expired Customer") -- bukan diketik manual
   lagi. Rule firewall/NAT/web-proxy tetap pakai src-address-list=EXPIRED, sama seperti
   pola yang sudah terbukti berjalan.
   ========================================================= */

function applyIsolirForwardingRulesToServer($server, $settings)
{
    $API = new RouterosAPI();
    $API->timeout = 10;

    $serverIp = $server['IP'];
    $serverUser = $server['PEMILIK'];
    $serverPass = $server['PASSWORD'];

    if (!$API->connect($serverIp, $serverUser, $serverPass)) {
        return [
            'success' => false,
            'message' => 'Gagal connect ke MikroTik API',
            'steps' => [],
            'pool_range' => ''
        ];
    }

    $steps = [];

    try {
        $expiredProfile = findExpiredProfileAndPoolRange($API);
        if (!$expiredProfile['exists'] || $expiredProfile['pool_range'] === '') {
            $API->disconnect();
            return [
                'success' => false,
                'message' => 'PPP Profile "EXPIRED" belum ada atau pool-nya belum bisa dibaca di router ini. Buat dulu lewat tombol "Buat Profile EXPIRED".',
                'steps' => [],
                'pool_range' => ''
            ];
        }
        $expiredRange = $expiredProfile['pool_range'];

        // Sinkronkan range pool EXPIRED ke address-list "EXPIRED" (idempotent -- tidak
        // menghapus entry lain yang mungkin sudah ada dari pool/profile EXPIRED lainnya).
        // Ini pola YANG SAMA dengan setup yang sudah terbukti jalan (lihat address-list
        // "EXPIRED" comment "Expired Customer" berisi beberapa range).
        $steps[] = 'Address List EXPIRED ' . $expiredRange . ': ' . ensureAddressListEntry($API, 'EXPIRED', $expiredRange, 'Expired Customer');

        $billingServerIp = $settings['billing_server_ip'];
        $billingDomain = $settings['billing_domain'];
        $landingIp = $settings['landing_ip'];
        $landingPort = $settings['landing_port'];

        // 1. Address list CDN / payment gateway (global, sama untuk semua server)
        $cdnRanges = [
            '104.16.0.0/12' => 'Cloudflare CDN',
            '172.64.0.0/13' => 'Cloudflare CDN',
            '131.0.72.0/22' => 'Cloudflare CDN',
            '151.101.0.0/16' => 'jsdelivr CDN'
        ];
        foreach ($cdnRanges as $range => $rangeComment) {
            $steps[] = 'Address List ALLOW-CDN ' . $range . ': ' . ensureAddressListEntry($API, 'ALLOW-CDN', $range, $rangeComment);
        }

        // 2. Filter rules (forward chain) -- src-address-list = EXPIRED
        $allowBillingId = ensureFilterRule($API, 'Allow billing server', [
            'chain' => 'forward',
            'src-address-list' => 'EXPIRED',
            'dst-address' => $billingServerIp,
            'action' => 'accept'
        ]);

        $allowCdnId = ensureFilterRule($API, 'Allow CDN and payment gateway', [
            'chain' => 'forward',
            'src-address-list' => 'EXPIRED',
            'dst-address-list' => 'ALLOW-CDN',
            'action' => 'accept'
        ]);

        $dropExpiredId = ensureFilterRule($API, 'Block expired internet', [
            'chain' => 'forward',
            'src-address-list' => 'EXPIRED',
            'dst-address' => '!' . $billingServerIp,
            'action' => 'drop'
        ]);

        moveFilterRule($API, $allowBillingId, 0);
        moveFilterRule($API, $allowCdnId, 1);
        moveFilterRule($API, $dropExpiredId, 2);
        $steps[] = 'Urutan filter diatur: Allow Billing -> Allow CDN -> Drop (kecuali billing)';

        // 3. NAT (chain dstnat):
        //    a. Bypass langsung ke IP billing (accept, tidak disentuh redirect sama sekali).
        //    b. Redirect SEMUA traffic HTTP/HTTPS lain dari EXPIRED ke Web Proxy router ini
        //       sendiri (action=redirect WAJIB menuju router itu sendiri di MikroTik --
        //       makanya cuma butuh to-ports, bukan tujuan IP tertentu). Tanpa rule redirect
        //       ini, traffic EXPIRED tidak akan pernah masuk ke Web Proxy sama sekali.
        ensureNatRule($API, 'Bypass billing server (isolir)', [
            'chain' => 'dstnat',
            'src-address-list' => 'EXPIRED',
            'dst-address' => $billingServerIp,
            'protocol' => 'tcp',
            'dst-port' => '80,443',
            'action' => 'accept'
        ]);

        ensureNatRule($API, 'Redirect expired user', [
            'chain' => 'dstnat',
            'src-address-list' => 'EXPIRED',
            'protocol' => 'tcp',
            'dst-port' => '80,443',
            'action' => 'redirect',
            'to-ports' => $landingPort
        ]);
        $steps[] = "NAT: bypass langsung ke $billingServerIp, redirect sisanya ke Web Proxy port $landingPort";

        // 4. Web Proxy enable + access rules
        ensureWebProxyEnabledDefault($API, $landingPort);

        // PENTING: /ip proxy access (Web Proxy Access List) TIDAK mendukung src-address-list
        // sama sekali -- cuma src-address (satu IP/CIDR/range literal). Beda dengan
        // /ip firewall filter & /ip firewall nat di atas yang MEMANG mendukung
        // src-address-list=EXPIRED (makanya dibiarkan seperti itu). Jadi di sini WAJIB pakai
        // src-address per range, dan kalau address-list EXPIRED punya lebih dari satu range
        // (misalnya dari beberapa pool/profile EXPIRED berbeda yang terkumpul dari waktu ke
        // waktu), SEMUA range itu harus dapat rule access-nya masing-masing.
        $expiredListRows = $API->comm('/ip/firewall/address-list/print', ['?list' => 'EXPIRED']);
        $expiredRanges = [];
        if (is_array($expiredListRows)) {
            foreach ($expiredListRows as $row) {
                if (!empty($row['address'])) {
                    $expiredRanges[] = $row['address'];
                }
            }
        }
        $expiredRanges = array_values(array_unique($expiredRanges));
        if (empty($expiredRanges)) {
            $expiredRanges = [$expiredRange]; // fallback, seharusnya tidak pernah terjadi
        }

        // Bersihkan rule dengan nama comment LAMA (dari versi sebelum dukungan multi-range /
        // sebelum multi-domain allow ditambahkan), supaya tidak ada rule lama yang nyangkut
        // dobel dengan rule baru di bawah.
        foreach (['Allow billing domain (isolir)', 'Redirect expired to landing page'] as $legacyComment) {
            $legacyRule = findWebProxyRuleByComment($API, $legacyComment);
            if ($legacyRule) {
                $API->comm('/ip/proxy/access/remove', ['.id' => $legacyRule['.id']]);
            }
        }

        // Domain yang HARUS tetap bisa diakses langsung (tidak diredirect): domain billing
        // sendiri + payment gateway yang dipakai untuk pembayaran (Midtrans, Tripay, Xendit).
        // Urutan rule /ip proxy access dicek dari atas ke bawah -- semua "allow" untuk satu
        // range WAJIB ada di atas rule "redirect" catch-all range itu, makanya tiap range
        // diproses sebagai satu blok berurutan (allow x N, lalu redirect), blok per blok.
        $allowDomains = array_values(array_unique(array_filter([
            $billingDomain,
            'midtrans.com',
            'api.midtrans.com',
            'app.midtrans.com',
            'tripay.co.id',
            'xendit.co',
            'checkout.xendit.co',
        ])));

        $orderedRuleIds = [];
        foreach ($expiredRanges as $range) {
            foreach ($allowDomains as $domain) {
                $orderedRuleIds[] = ensureWebProxyAccessRule($API, 'Allow domain (isolir): ' . $domain . ' [' . $range . ']', [
                    'src-address' => $range,
                    'dst-port' => '80,443',
                    'dst-host' => $domain,
                    'action' => 'allow'
                ]);
            }

            // PENTING: /ip proxy access TIDAK punya action="redirect" -- yang benar
            // action="deny" plus field terpisah "redirect-to" (dikonfirmasi dari WinBox:
            // Action=deny, Redirect To=<ip:port>). Beda dengan /ip firewall nat yang memang
            // punya action=redirect sendiri (dipakai di atas, ke $landingPort router ini sendiri).
            $orderedRuleIds[] = ensureWebProxyAccessRule($API, 'Redirect expired to landing page [' . $range . ']', [
                'src-address' => $range,
                'dst-port' => '80,443',
                'action' => 'deny',
                'redirect-to' => $landingIp . ':' . $landingPort
            ]);
        }

        foreach ($orderedRuleIds as $idx => $ruleId) {
            moveWebProxyAccessRule($API, $ruleId, $idx);
        }

        $steps[] = 'Web Proxy access rule dipasang untuk ' . count($expiredRanges) . ' range EXPIRED (' . implode(', ', $expiredRanges)
            . '), masing-masing allow ' . implode(', ', $allowDomains) . " + redirect ke $landingIp:$landingPort";

        $API->disconnect();

        return [
            'success' => true,
            'message' => 'Rule isolir-forwarding berhasil diterapkan',
            'steps' => $steps,
            'pool_range' => $expiredRange
        ];
    } catch (Exception $e) {
        $API->disconnect();

        return [
            'success' => false,
            'message' => 'Error RouterOS API: ' . $e->getMessage(),
            'steps' => $steps,
            'pool_range' => ''
        ];
    }
}

function saveApplyLog($conn, $userId, $serverId, $serverIp, $status, $message)
{
    $userId = (int)$userId;
    $serverId = (int)$serverId;
    $serverIp = mysqli_real_escape_string($conn, $serverIp);
    $status = mysqli_real_escape_string($conn, $status);
    $message = mysqli_real_escape_string($conn, $message);

    $sql = "INSERT INTO isolir_forwarding_logs (user_id, server_id, server_ip, status, message)
            VALUES ($userId, $serverId, '$serverIp', '$status', '$message')";

    mysqli_query($conn, $sql);
}

/* =========================================================
   REQUEST HANDLING
   ========================================================= */

ensureIsolirForwardingTables($conn);

$success = '';
$error = '';

// --- Notif dari proses/delete_isolir_forwarding.php ---
if (isset($_GET['del'])) {
    if ($_GET['del'] === 'success') {
        $success = 'Rule isolir-forwarding berhasil dihapus dari router.' . (!empty($_GET['text']) ? ' (' . $_GET['text'] . ')' : '');
    } elseif ($_GET['del'] === 'failed') {
        $error = 'Gagal menghapus rule isolir-forwarding.' . (!empty($_GET['text']) ? ' ' . $_GET['text'] : '');
    }
}

// --- Download landing page HTML ditangani di paling atas file (sebelum header.php),
//     lihat blok awal berkomentar "DOWNLOAD LANDING PAGE" -- request dengan
//     download_landing tidak akan pernah sampai ke sini (sudah exit duluan).

// --- Simpan pengaturan global + apply ke server yang sedang dicek ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $billingServerIp = trim($_POST['billing_server_ip'] ?? $autoBillingServerIp);
    $billingDomain = trim($_POST['billing_domain'] ?? $autoBillingDomain);
    $landingIp = trim($_POST['landing_ip'] ?? '');
    $landingPort = trim($_POST['landing_port'] ?? '8080');
    $landingUrl = trim($_POST['landing_url'] ?? $autoBayarSekarangUrl);
    $landingHtml = $_POST['landing_html'] ?? defaultLandingHtml();
    $targetServerId = (int)($_POST['target_server_id'] ?? 0);

    if ($billingServerIp === '' || $billingDomain === '' || $landingIp === '' || $landingPort === '' || $landingUrl === '') {
        $error = 'Semua pengaturan wajib diisi (termasuk IP Landing Page dari hasil Cek Server).';
    } elseif (!upsertIsolirForwardingSettings($conn, $current_user_id, $billingServerIp, $billingDomain, $landingIp, $landingPort, $landingUrl, $landingHtml)) {
        $error = 'Gagal menyimpan pengaturan: ' . mysqli_error($conn);
    } elseif ($targetServerId <= 0) {
        $success = 'Pengaturan global berhasil disimpan (tanpa apply -- server belum dipilih).';
    } else {
        $srvRes = mysqli_query($conn, "SELECT * FROM server WHERE user_id = " . (int)$current_user_id . " AND id = $targetServerId");
        if (!$srvRes || mysqli_num_rows($srvRes) === 0) {
            $error = 'Server tidak ditemukan.';
        } else {
            $server = mysqli_fetch_assoc($srvRes);
            $settingsNow = getIsolirForwardingSettings($conn, $current_user_id, [
                'billing_server_ip' => $autoBillingServerIp,
                'billing_domain' => $autoBillingDomain,
                'landing_url' => $autoBayarSekarangUrl,
            ]);

            $apply = applyIsolirForwardingRulesToServer($server, $settingsNow);
            $status = $apply['success'] ? 'SUCCESS' : 'FAILED';
            $details = $apply['message'];
            if (!empty($apply['steps'])) {
                $details .= ' | ' . implode(' | ', $apply['steps']);
            }

            saveApplyLog($conn, $current_user_id, (int)$server['id'], $server['IP'], $status, $details);

            if ($apply['success']) {
                upsertServerIsolirStatus($conn, $current_user_id, $targetServerId, $apply['pool_range'], '', true);
                $success = 'Pengaturan disimpan & rule berhasil diterapkan ke server ' . htmlspecialchars($server['IP']) . '.';
            } else {
                $error = 'Pengaturan disimpan, tapi apply ke server ' . htmlspecialchars($server['IP']) . ' gagal: ' . htmlspecialchars($apply['message']);
            }
        }
    }
}

$settings = getIsolirForwardingSettings($conn, $current_user_id, [
    'billing_server_ip' => $autoBillingServerIp,
    'billing_domain' => $autoBillingDomain,
    'landing_url' => $autoBayarSekarangUrl,
]);

/* =========================================================
   LOAD SERVER LIST (untuk dropdown "Pilih Server") + server yang sudah diterapkan
   ========================================================= */

$serverList = [];
$serverQuery = mysqli_query($conn, "SELECT id, IP, PEMILIK, AREA, BRAND FROM server WHERE user_id = " . (int)$current_user_id . " ORDER BY AREA, IP");
while ($serverQuery && $row = mysqli_fetch_assoc($serverQuery)) {
    $serverList[] = $row;
}

$appliedServers = getAppliedIsolirServers($conn, $current_user_id);
?>

<style>
  .page-card {
    border-radius: 12px;
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.08);
  }
  .section-title {
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 12px;
  }
  .cmd-block {
    background: #0f172a;
    color: #e2e8f0;
    border-radius: 10px;
    padding: 14px;
    font-family: Consolas, monospace;
    font-size: 12px;
    white-space: pre-wrap;
  }
  .status-success {
    color: #198754;
    font-weight: 700;
  }
  .status-failed {
    color: #dc3545;
    font-weight: 700;
  }
  textarea.mono-area {
    font-family: Consolas, monospace;
    font-size: 13px;
  }
  body.app-theme-dark .page-card,
  body.app-theme-dark .card,
  body.app-theme-dark .table,
  body.app-theme-dark .table td,
  body.app-theme-dark .table th,
  body.app-theme-dark .form-control,
  body.app-theme-dark .form-select {
    background: #0f172a !important;
    color: #e5e7eb !important;
    border-color: rgba(148, 163, 184, 0.35) !important;
  }
</style>

<div class="container-fluid py-4">
  <div class="row g-4">

    <?php if ($success !== ''): ?>
      <div class="col-12"><div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="col-12"><div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div></div>
    <?php endif; ?>

    <!-- ============ STEP 1: PILIH SERVER ============ -->
    <div class="col-12">
      <div class="card page-card">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0">Isolir Forwarding Manager (ADMIN)</h5>
          <small>Pilih server MikroTik yang mau dipasang isolir forwarding, lalu klik Cek Server</small>
        </div>
        <div class="card-body">
          <div class="row g-2 align-items-end">
            <div class="col-md-6">
              <label class="form-label">Server</label>
              <select class="form-select" id="serverPickSelect">
                <option value="">-- Pilih Server --</option>
                <?php foreach ($serverList as $srv): ?>
                  <option value="<?php echo (int)$srv['id']; ?>">
                    <?php echo htmlspecialchars(($srv['BRAND'] ?: 'No Brand') . ' - ' . $srv['AREA'] . ' (' . $srv['IP'] . ')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <button type="button" class="btn btn-warning w-100" id="btnCekServer" onclick="cekServer()">
                <span id="cekServerSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                Cek Server
              </button>
            </div>
          </div>
          <div id="cekServerError" class="alert alert-danger mt-3 d-none"></div>
        </div>
      </div>
    </div>

    <!-- ============ STEP 2: FORM (muncul setelah Cek Server) ============ -->
    <div class="col-12 d-none" id="isolirFormWrap">
      <div class="card page-card">
        <div class="card-header bg-primary text-white">
          <h6 class="mb-0" id="isolirFormServerLabel">Pengaturan untuk server terpilih</h6>
        </div>
        <div class="card-body">
          <div class="row g-4">
            <div class="col-lg-6">
              <div class="section-title">Pengaturan Isolir Forwarding</div>
              <form method="POST" class="card p-3 mb-3" id="isolirSettingsForm">
                <input type="hidden" name="target_server_id" id="target_server_id" value="0">

                <div class="mb-3">
                  <label class="form-label">IP Server Billing</label>
                  <input type="text" class="form-control" name="billing_server_ip" id="billing_server_ip" value="<?php echo htmlspecialchars($settings['billing_server_ip']); ?>" required>
                  <div class="form-text">Otomatis diambil dari IP public server billing ini. Dipakai di rule "Allow billing server" &amp; dikecualikan dari rule drop.</div>
                </div>
                <div class="mb-3">
                  <label class="form-label">Domain Billing / Payment Gateway</label>
                  <input type="text" class="form-control" name="billing_domain" id="billing_domain" value="<?php echo htmlspecialchars($settings['billing_domain']); ?>" required>
                  <div class="form-text">Otomatis diambil dari domain yang sedang diakses saat ini.</div>
                </div>
                <div class="row">
                  <div class="col-7 mb-3">
                    <label class="form-label">IP Landing Page</label>
                    <input type="text" class="form-control" name="landing_ip" id="landing_ip_input" value="" readonly required>
                    <div class="form-text">
                      Otomatis sama dengan IP server MikroTik ini. Upload file <code>landing-isolir.html</code>
                      (lihat langkah Download &amp; Upload di panduan bawah) ke server ini; Web Proxy akan redirect
                      ke <code>IP:Port</code> ini.
                    </div>
                  </div>
                  <div class="col-5 mb-3">
                    <label class="form-label">Port Web Proxy</label>
                    <input type="text" class="form-control" name="landing_port" id="landing_port" value="" readonly>
                    <div class="form-text">Otomatis dari Web Proxy MikroTik (diaktifkan ke 8080 kalau belum aktif).</div>
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">URL Tombol "Bayar Sekarang"</label>
                  <input type="url" class="form-control" id="landing_url_input" name="landing_url" value="<?php echo htmlspecialchars($settings['landing_url']); ?>" required>
                  <div class="form-text">Otomatis dari domain saat ini + <code>/crm/billing/broadband/portallogin.php</code>. Menggantikan placeholder <code>{{LANDING_URL}}</code> di landing page saat di-download.</div>
                </div>

                <div class="mb-3">
                  <label class="form-label">Editor Landing Page (HTML)</label>
                  <textarea class="form-control mono-area" id="landing_html_input" name="landing_html" rows="10"><?php echo htmlspecialchars($settings['landing_html']); ?></textarea>
                  <div class="form-text">
                    Gunakan placeholder <code>{{LANDING_URL}}</code> di dalam HTML pada bagian tombol bayar; otomatis diganti dengan URL di atas.
                  </div>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                  <button type="submit" name="save_settings" class="btn btn-primary">Simpan &amp; Apply ke Server Ini</button>
                  <button type="submit" name="download_landing" class="btn btn-outline-secondary" formtarget="_blank">Download Landing Page (.html)</button>
                  <button type="button" class="btn btn-outline-info" onclick="previewLandingPage()">Preview</button>
                </div>
              </form>
            </div>

            <div class="col-lg-6">
              <div class="section-title">Status Profile EXPIRED</div>
              <div id="expiredProfileStatus" class="alert alert-secondary">Cek Server dulu untuk melihat status profile EXPIRED.</div>

              <div class="section-title mt-4">Template Rule yang Diterapkan (address-list EXPIRED disinkron dari pool)</div>
              <div class="cmd-block" id="rulePreviewBlock">Cek Server dulu untuk melihat preview rule.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ SERVER YANG SUDAH DIPASANG ISOLIR FORWARDING ============ -->
    <div class="col-12">
      <div class="card page-card">
        <div class="card-header">
          <h6 class="mb-0">Server yang Sudah Dipasang Isolir Forwarding</h6>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table align-items-center mb-0">
              <thead>
                <tr>
                  <th>Server</th>
                  <th>Range IP EXPIRED</th>
                  <th>Terakhir Diterapkan</th>
                  <th>Hits Akses (Isolir)</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($appliedServers)): ?>
                  <tr>
                    <td colspan="5" class="text-center text-muted">Belum ada server yang dipasang isolir forwarding.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($appliedServers as $as): ?>
                    <tr class="isolir-applied-row" data-server-id="<?php echo (int)$as['id']; ?>">
                      <td><?php echo htmlspecialchars(($as['BRAND'] ?: 'No Brand') . ' - ' . $as['AREA'] . ' (' . $as['IP'] . ')'); ?></td>
                      <td><code><?php echo htmlspecialchars($as['expired_pool_range']); ?></code></td>
                      <td><?php echo htmlspecialchars($as['applied_at']); ?></td>
                      <td class="isolir-hits-cell"><span class="text-muted small">Memuat...</span></td>
                      <td>
                        <form method="POST" action="proses/delete_isolir_forwarding.php" onsubmit="return confirm('Yakin ingin menghapus mekanisme landing page dari server ini?\n\nYang dihapus: rule /ip proxy access, address-list ALLOW-CDN, filter \'Allow billing server\' dan \'Allow CDN and payment gateway\'.\n\nYang TETAP dibiarkan: filter \'Block expired internet\' (drop) dan rule NAT -- pelanggan EXPIRED tetap terblokir dari internet.');" style="display:inline;">
                          <input type="hidden" name="server_id" value="<?php echo (int)$as['id']; ?>">
                          <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ DEBUG SEMENTARA: isi tabel tracking apa adanya (gated ADMIN, sama seperti halaman ini) ============ -->
    <div class="col-12">
      <details class="card page-card p-3">
        <summary style="cursor:pointer;">Debug: Isi tabel tracking isolir (sementara, buat cari kenapa tabel di atas kosong)</summary>
        <div class="mt-3">
          <?php
          $debugRawRows = [];
          $debugRes = mysqli_query($conn, "SELECT ifs.*, s.IP, s.PEMILIK, s.AREA, s.BRAND, s.user_id AS server_user_id
                                            FROM isolir_forwarding_server_settings ifs
                                            LEFT JOIN server s ON s.id = ifs.server_id
                                            WHERE ifs.user_id = " . (int)$current_user_id);
          if ($debugRes) {
              while ($drow = mysqli_fetch_assoc($debugRes)) {
                  $debugRawRows[] = $drow;
              }
          }
          ?>
          <p class="small text-muted">current_user_id yang dipakai halaman ini: <code><?php echo (int)$current_user_id; ?></code></p>
          <?php if (empty($debugRawRows)): ?>
            <p class="text-danger">Tidak ada baris SAMA SEKALI di isolir_forwarding_server_settings untuk user_id ini &mdash; artinya "Simpan &amp; Apply" belum pernah berhasil tersimpan ke database untuk akun ini (server yang dikonfigurasi manual lewat WinBox langsung TIDAK akan otomatis muncul di sini, karena tabel ini cuma mencatat apa yang di-apply LEWAT halaman ini).</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead><tr><th>server_id</th><th>IP</th><th>PEMILIK</th><th>server.user_id</th><th>expired_pool_range</th><th>is_applied</th><th>applied_at</th></tr></thead>
                <tbody>
                  <?php foreach ($debugRawRows as $drow): ?>
                    <tr>
                      <td><?php echo htmlspecialchars((string)$drow['server_id']); ?></td>
                      <td><?php echo htmlspecialchars((string)($drow['IP'] ?? 'SERVER TIDAK KETEMU (JOIN gagal)')); ?></td>
                      <td><?php echo htmlspecialchars((string)($drow['PEMILIK'] ?? '-')); ?></td>
                      <td><?php echo htmlspecialchars((string)($drow['server_user_id'] ?? '-')); ?></td>
                      <td><?php echo htmlspecialchars((string)$drow['expired_pool_range']); ?></td>
                      <td><?php echo htmlspecialchars((string)$drow['is_applied']); ?></td>
                      <td><?php echo htmlspecialchars((string)$drow['applied_at']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </details>
    </div>

    <!-- ============ PANDUAN LENGKAP & MEKANISME KERJA ============ -->
    <div class="col-12">
      <div class="card page-card">
        <div class="card-header bg-dark text-white">
          <h6 class="mb-0">Panduan Lengkap &amp; Mekanisme Kerja Sistem Isolir Forwarding</h6>
          <small class="text-white-50">Baca bagian ini sebelum apply ke server production</small>
        </div>
        <div class="card-body">

          <h6 class="fw-bold mt-2">A. Konsep Dasar</h6>
          <p>
            Sistem ini bekerja dengan memanfaatkan <strong>PPP Profile bernama EXPIRED</strong> di MikroTik.
            Pelanggan yang menunggak di-downgrade ke profile EXPIRED (di luar aplikasi ini, lewat mekanisme
            billing/RADIUS yang sudah berjalan), sehingga otomatis mendapat IP dari <strong>IP Pool EXPIRED</strong>.
            Halaman ini memasang rule firewall &amp; web proxy yang membatasi traffic dari <strong>range IP pool
            tersebut</strong> (bukan daftar IP manual): diblokir total ke internet, kecuali ke
            <strong>server billing/payment gateway</strong> dan <strong>CDN tertentu</strong>. Saat pelanggan expired
            membuka browser, traffic HTTP/HTTPS-nya otomatis dialihkan ke <strong>halaman landing "Bayar Sekarang"</strong>
            lewat Web Proxy MikroTik, mirip mekanisme captive portal.
          </p>

          <h6 class="fw-bold mt-4">B. Komponen yang Dipasang di MikroTik &amp; Fungsinya</h6>
          <ol>
            <li class="mb-2">
              <strong>PPP Profile &amp; IP Pool <code>EXPIRED</code></strong><br>
              Kalau belum ada di router, halaman ini akan menawarkan untuk membuatnya (Local IP &amp; Remote IP
              dipilih dari menu IP Pool, sama seperti menambah paket PPPoE biasa). Range IP pool inilah yang
              otomatis ditambahkan sebagai entry ke <strong>address-list <code>EXPIRED</code></strong> (comment
              "Expired Customer"), lalu dipakai sebagai <code>src-address-list=EXPIRED</code> di semua rule di bawah.
            </li>
            <li class="mb-2">
              <strong>Address List <code>ALLOW-CDN</code></strong><br>
              Berisi range IP Cloudflare &amp; jsdelivr, supaya landing page/payment gateway yang memuat resource
              dari CDN ini tidak ikut diblokir dan tampil rusak/blank.
            </li>
            <li class="mb-2">
              <strong>Firewall Filter &mdash; chain <code>forward</code></strong> (diterapkan berurutan dari atas):
              <ul>
                <li><code>Allow billing server</code> (urutan ke-0): izinkan IP EXPIRED mengakses langsung IP server billing.</li>
                <li><code>Allow CDN and payment gateway</code> (urutan ke-1): izinkan IP EXPIRED mengakses <code>ALLOW-CDN</code>.</li>
                <li><code>Block expired internet</code> (urutan ke-2): blokir semua traffic dari IP EXPIRED kecuali ke IP billing.</li>
              </ul>
              Ketiga rule selalu dipindah (<code>move</code>) ke posisi 0, 1, 2 setiap kali apply supaya urutannya konsisten.
            </li>
            <li class="mb-2">
              <strong>NAT &mdash; chain <code>dstnat</code></strong> (2 rule):
              <ul>
                <li><code>Bypass billing server (isolir)</code>: <code>action=accept</code> khusus traffic ke IP billing, tidak disentuh redirect sama sekali.</li>
                <li><code>Redirect expired user</code>: <code>action=redirect to-ports=&lt;port Web Proxy&gt;</code> untuk traffic TCP 80/443 lain dari IP EXPIRED &mdash; MikroTik selalu mengarahkan <code>action=redirect</code> ke router itu sendiri, jadi rule ini yang benar-benar "menangkap" traffic masuk ke Web Proxy. Tanpa rule ini, Web Proxy tidak pernah kebagian traffic apa pun.</li>
              </ul>
            </li>
            <li class="mb-2">
              <strong>Web Proxy (<code>/ip/proxy</code>)</strong> &mdash; diaktifkan pada port sesuai pengaturan (default 8080 kalau belum aktif).
            </li>
            <li class="mb-2">
              <strong>Web Proxy Access Rules (<code>/ip/proxy/access</code>)</strong> &mdash; dicek dari atas ke bawah:
              <ul>
                <li><code>Allow domain (isolir): &lt;domain&gt;</code> (<code>action=allow</code>): domain billing &amp; payment gateway (Midtrans, Tripay, Xendit) diizinkan lewat apa adanya.</li>
                <li><code>Redirect expired to landing page</code> (<code>action=deny</code> + field <code>redirect-to</code>): request lain di-deny sekaligus diredirect ke landing page (IP:Port terpisah dari router, sesuai pengaturan "IP Landing Page"). Catatan: <code>/ip proxy access</code> tidak punya action bernama "redirect" &mdash; mekanismenya <code>deny</code> dengan <code>redirect-to</code>, beda dengan NAT di atas yang memang punya <code>action=redirect</code> sendiri.</li>
              </ul>
            </li>
          </ol>

          <h6 class="fw-bold mt-4">C. Langkah-Langkah Penggunaan Halaman Ini</h6>
          <ol class="mb-0">
            <li class="mb-2"><strong>Pilih Server</strong> lalu klik <em>Cek Server</em> &mdash; sistem akan connect ke MikroTik, ambil IP ethernet fisik, cek/aktifkan Web Proxy, dan cari PPP Profile EXPIRED.</li>
            <li class="mb-2">Kalau profile EXPIRED <strong>belum ada</strong>, modal "Buat Profile EXPIRED" akan muncul &mdash; pilih Local IP &amp; Remote IP dari IP Pool (sama seperti Tambah Paket PPPoE).</li>
            <li class="mb-2">Form pengaturan akan tampil, sebagian besar sudah terisi otomatis. Pilih <em>IP Landing Page</em> dari dropdown, sesuaikan editor landing page kalau perlu.</li>
            <li class="mb-2">
              <strong>Download &amp; upload landing page</strong> &mdash; klik <em>Download Landing Page (.html)</em>, lalu upload file
              <code>landing-isolir.html</code> tersebut ke perangkat web server yang tersambung ke MikroTik ini (IP-nya sama dengan
              yang dipilih di dropdown <em>IP Landing Page</em> di atas, port sesuai <em>Port Web Proxy</em>). Kalau perangkat itu
              adalah MikroTik itu sendiri, upload lewat WinBox &gt; Files lalu arahkan layanan web-nya (mis. Hotspot) ke file tersebut;
              kalau perangkat terpisah (mis. mini PC/Raspberry Pi khusus web server), upload lewat cara akses perangkat itu masing-masing.
              Proses upload ini <strong>manual</strong>, dilakukan sekali di luar aplikasi ini.
            </li>
            <li class="mb-2">Klik <em>Simpan &amp; Apply ke Server Ini</em> &mdash; rule langsung dipasang ke router memakai range IP pool EXPIRED yang terdeteksi.</li>
            <li class="mb-0">
              Server yang sudah dipasang akan muncul di tabel "Server yang Sudah Dipasang Isolir Forwarding" di bawah, lengkap dengan
              tombol <em>Hapus</em>. <strong>Catatan cakupan Hapus:</strong>
              <ul class="mt-1">
                <li>Dihapus: semua rule <code>/ip proxy access</code>, address-list <code>ALLOW-CDN</code>, filter
                  <code>Allow billing server</code>, dan filter <code>Allow CDN and payment gateway</code>.</li>
                <li>Tetap dibiarkan (tidak dihapus): filter <code>Block expired internet</code> (drop) dan rule NAT
                  (<code>Bypass billing server</code>, <code>Redirect expired user</code>) &mdash; supaya pelanggan EXPIRED tetap
                  terblokir dari internet meskipun mekanisme "landing page experience"-nya dicabut.</li>
              </ul>
            </li>
          </ol>

        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal Preview Landing Page -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title mb-0">Preview Landing Page</h6>
        <div class="btn-group btn-group-sm ms-3" role="group">
          <button type="button" class="btn btn-outline-secondary active" id="btnViewDesktop" onclick="setPreviewSize('desktop')">Desktop</button>
          <button type="button" class="btn btn-outline-secondary" id="btnViewMobile" onclick="setPreviewSize('mobile')">Mobile</button>
        </div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0 d-flex justify-content-center align-items-stretch" style="background:#e9ecef; overflow:auto;">
        <iframe id="previewFrame" style="border:0; width:100%; height:100%; background:#fff; transition: width .2s ease;"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- Modal Buat Profile EXPIRED -->
<div class="modal fade" id="createExpiredModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning">
        <h6 class="modal-title mb-0">Buat Profile EXPIRED</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info small">PPP Profile "EXPIRED" belum ditemukan di server ini. Pilih Local IP &amp; Remote IP dari IP Pool untuk membuatnya (sama seperti menambah paket PPPoE).</div>
        <div class="mb-3">
          <label class="form-label">Nama Profile</label>
          <input type="text" class="form-control" value="EXPIRED" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">Local IP</label>
          <select class="form-select" id="expiredLocalIpSelect" onchange="updateExpiredRemoteIp()">
            <option value="">-- Pilih Local IP --</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Remote IP (range pool EXPIRED)</label>
          <input type="text" class="form-control" id="expiredRemoteIpDisplay" readonly placeholder="Otomatis dari Local IP">
        </div>
        <div id="createExpiredError" class="alert alert-danger d-none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-warning" id="btnCreateExpiredProfile" onclick="submitCreateExpiredProfile()">Buat Profile</button>
      </div>
    </div>
  </div>
</div>

<script>
let currentServerId = 0;
let currentServerLabel = '';
let currentExpiredPoolRange = '';
let expiredPoolMap = {}; // { localIp: remoteRange }

function cekServer() {
  const select = document.getElementById('serverPickSelect');
  const serverId = select.value;
  const errorBox = document.getElementById('cekServerError');
  errorBox.classList.add('d-none');

  if (!serverId) {
    errorBox.textContent = 'Pilih server dulu.';
    errorBox.classList.remove('d-none');
    return;
  }

  currentServerId = serverId;
  currentServerLabel = select.options[select.selectedIndex].textContent;

  document.getElementById('btnCekServer').disabled = true;
  document.getElementById('cekServerSpinner').classList.remove('d-none');

  fetch('getdata/check_server_isolir.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'server_id=' + encodeURIComponent(serverId)
  })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.success) {
        throw new Error(data.error || 'Gagal cek server');
      }
      applyCheckServerResult(data);
    })
    .catch(function(err) {
      errorBox.textContent = err.message;
      errorBox.classList.remove('d-none');
    })
    .finally(function() {
      document.getElementById('btnCekServer').disabled = false;
      document.getElementById('cekServerSpinner').classList.add('d-none');
    });
}

function applyCheckServerResult(data) {
  document.getElementById('target_server_id').value = currentServerId;
  document.getElementById('isolirFormServerLabel').textContent = 'Pengaturan untuk: ' + currentServerLabel;
  document.getElementById('isolirFormWrap').classList.remove('d-none');

  // IP Landing Page = IP server MikroTik ini langsung (bukan dari interface ethernet fisik)
  document.getElementById('landing_ip_input').value = data.server_ip || '';

  // Port Web Proxy
  document.getElementById('landing_port').value = data.proxy_port || '8080';

  // Pool options untuk modal create EXPIRED
  expiredPoolMap = {};
  const localSelect = document.getElementById('expiredLocalIpSelect');
  localSelect.innerHTML = '<option value="">-- Pilih Local IP --</option>';
  (data.pool_options || []).forEach(function(p) {
    expiredPoolMap[p.iplocal] = p.range;
    const opt = document.createElement('option');
    opt.value = p.iplocal;
    opt.textContent = p.pool_name + ' (' + p.iplocal + ' -> ' + p.range + ')';
    localSelect.appendChild(opt);
  });

  // Status profile EXPIRED
  const statusBox = document.getElementById('expiredProfileStatus');
  if (data.expired_profile && data.expired_profile.exists && data.expired_profile.pool_range) {
    currentExpiredPoolRange = data.expired_profile.pool_range;
    statusBox.className = 'alert alert-success';
    statusBox.textContent = 'Profile EXPIRED ditemukan. Range IP: ' + currentExpiredPoolRange;
  } else {
    currentExpiredPoolRange = '';
    statusBox.className = 'alert alert-warning';
    statusBox.textContent = 'Profile EXPIRED belum ada di server ini.';
    const modalEl = document.getElementById('createExpiredModal');
    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
  }

  renderRulePreview();
}

function updateExpiredRemoteIp() {
  const localSelect = document.getElementById('expiredLocalIpSelect');
  const remoteDisplay = document.getElementById('expiredRemoteIpDisplay');
  remoteDisplay.value = expiredPoolMap[localSelect.value] || '';
}

function submitCreateExpiredProfile() {
  const localIp = document.getElementById('expiredLocalIpSelect').value;
  const remoteIp = document.getElementById('expiredRemoteIpDisplay').value;
  const errorBox = document.getElementById('createExpiredError');
  errorBox.classList.add('d-none');

  if (!localIp || !remoteIp) {
    errorBox.textContent = 'Pilih Local IP dulu.';
    errorBox.classList.remove('d-none');
    return;
  }

  document.getElementById('btnCreateExpiredProfile').disabled = true;

  fetch('proses/create_expired_profile.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'server_id=' + encodeURIComponent(currentServerId) + '&local_ip=' + encodeURIComponent(localIp) + '&remote_ip=' + encodeURIComponent(remoteIp)
  })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.success) {
        throw new Error(data.error || 'Gagal membuat profile EXPIRED');
      }
      currentExpiredPoolRange = data.pool_range;
      const statusBox = document.getElementById('expiredProfileStatus');
      statusBox.className = 'alert alert-success';
      statusBox.textContent = 'Profile EXPIRED berhasil dibuat. Range IP: ' + currentExpiredPoolRange;
      renderRulePreview();

      const modalEl = document.getElementById('createExpiredModal');
      if (window.bootstrap && window.bootstrap.Modal) {
        window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
      }
    })
    .catch(function(err) {
      errorBox.textContent = err.message;
      errorBox.classList.remove('d-none');
    })
    .finally(function() {
      document.getElementById('btnCreateExpiredProfile').disabled = false;
    });
}

function renderRulePreview() {
  const range = currentExpiredPoolRange || '<belum-ada-profile-EXPIRED>';
  const billingIp = document.getElementById('billing_server_ip').value || '<ip-billing>';
  const billingDomain = document.getElementById('billing_domain').value || '<domain-billing>';
  const landingIp = document.getElementById('landing_ip_input').value || '<ip-landing>';
  const landingPort = document.getElementById('landing_port').value || '8080';

  const preview = '/ip firewall address-list\n' +
    ';; Range pool EXPIRED otomatis ditambahkan (bukan diketik manual):\n' +
    'add list=EXPIRED address=' + range + ' comment="Expired Customer"\n' +
    'add list=ALLOW-CDN address=104.16.0.0/12 comment="Cloudflare CDN"\n' +
    'add list=ALLOW-CDN address=172.64.0.0/13 comment="Cloudflare CDN"\n' +
    'add list=ALLOW-CDN address=131.0.72.0/22 comment="Cloudflare CDN"\n' +
    'add list=ALLOW-CDN address=151.101.0.0/16 comment="jsdelivr CDN"\n\n' +
    '/ip firewall filter\n' +
    'add chain=forward src-address-list=EXPIRED dst-address=' + billingIp + ' action=accept comment="Allow billing server"\n' +
    'add chain=forward src-address-list=EXPIRED dst-address-list=ALLOW-CDN action=accept comment="Allow CDN and payment gateway"\n' +
    'add chain=forward src-address-list=EXPIRED dst-address=!' + billingIp + ' action=drop comment="Block expired internet"\n\n' +
    '/ip firewall nat\n' +
    'add chain=dstnat src-address-list=EXPIRED dst-address=' + billingIp + ' protocol=tcp dst-port=80,443 action=accept comment="Bypass billing server (isolir)"\n' +
    'add chain=dstnat src-address-list=EXPIRED protocol=tcp dst-port=80,443 action=redirect to-ports=' + landingPort + ' comment="Redirect expired user"\n\n' +
    '/ip proxy\n' +
    'set enabled=yes port=' + landingPort + '\n\n' +
    ';; /ip proxy access TIDAK mendukung src-address-list -- wajib src-address per range.\n' +
    ';; Kalau address-list EXPIRED punya lebih dari 1 range (dari beberapa pool EXPIRED),\n' +
    ';; SEMUA range dapat blok rule allow+redirect sendiri-sendiri (bukan cuma 1 range ini):\n' +
    '/ip proxy access\n' +
    'add src-address=' + range + ' dst-port=80,443 dst-host=' + billingDomain + ' action=allow comment="Allow domain (isolir): ' + billingDomain + ' [' + range + ']"\n' +
    '...(rule allow yang sama untuk tiap payment gateway: midtrans.com, tripay.co.id, xendit.co, dst.)\n' +
    'add src-address=' + range + ' dst-port=80,443 action=deny redirect-to=' + landingIp + ':' + landingPort + ' comment="Redirect expired to landing page [' + range + ']"';

  document.getElementById('rulePreviewBlock').textContent = preview;
}

['billing_server_ip', 'billing_domain', 'landing_ip_input', 'landing_port'].forEach(function(id) {
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', renderRulePreview);
  if (el) el.addEventListener('change', renderRulePreview);
});

function previewLandingPage() {
  const html = document.getElementById('landing_html_input').value;
  const url = document.getElementById('landing_url_input').value;
  const finalHtml = html.replaceAll('{{LANDING_URL}}', url);

  const frame = document.getElementById('previewFrame');
  frame.srcdoc = finalHtml;
  setPreviewSize('desktop');

  const modalEl = document.getElementById('previewModal');
  if (window.bootstrap && window.bootstrap.Modal) {
    const modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
  } else {
    modalEl.style.display = 'block';
  }
}

function setPreviewSize(mode) {
  const frame = document.getElementById('previewFrame');
  const btnDesktop = document.getElementById('btnViewDesktop');
  const btnMobile = document.getElementById('btnViewMobile');

  if (mode === 'mobile') {
    frame.style.width = '390px';
    frame.style.maxWidth = '390px';
    btnMobile.classList.add('active');
    btnDesktop.classList.remove('active');
  } else {
    frame.style.width = '100%';
    frame.style.maxWidth = '100%';
    btnDesktop.classList.add('active');
    btnMobile.classList.remove('active');
  }
}

function loadIsolirHitsForRow(row) {
  const serverId = row.getAttribute('data-server-id');
  const cell = row.querySelector('.isolir-hits-cell');
  if (!serverId || !cell) return;

  fetch('getdata/get_isolir_access_hits.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'server_id=' + encodeURIComponent(serverId)
  })
    .then(res => res.json())
    .then(data => {
      if (data && data.success) {
        cell.innerHTML = '<span class="badge bg-primary">' + data.total_hits + ' hits</span>';
      } else {
        cell.innerHTML = '<span class="text-danger small" title="' + (data && data.error ? data.error.replace(/"/g, '&quot;') : 'Gagal memuat') + '">Gagal memuat</span>';
      }
    })
    .catch(() => {
      cell.innerHTML = '<span class="text-danger small">Gagal memuat</span>';
    });
}

document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.isolir-applied-row').forEach(function (row) {
    loadIsolirHitsForRow(row);
  });
});
</script>

<?php require 'footer.php'; ?>
