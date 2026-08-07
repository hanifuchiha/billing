<?php

session_start();
// Lepas session lock lebih awal — ini sering jadi penyebab queuing (semua request antri).
session_write_close();

// Batas waktu maksimum setiap proses eksternal agar tidak hang tak batas.
$dbConnectTimeout = 3;  // detik koneksi DB
$dbReadTimeout    = 4;  // detik baca query DB
$waConnectTimeout = 3;  // detik koneksi WA API
$waRequestTimeout = 6;  // detik total kirim OTP ke WA API

// Include helper function untuk greeting dinamis
require_once(__DIR__ . '/../notifbot/bot_selector_helper.php');

$loginError = '';
$to = '';
$response = '';
$cekbott = '';
$disableOtpSend = false;
$otpMode = 'bypass';
$otpMessageTemplate = "*[INI ADALAH PESAN OTOMATIS]*\nIni adalah kode otp anda : *{otp}*";

$otpPortalConfigPath = __DIR__ . '/otp_portal_config.json';
if (file_exists($otpPortalConfigPath)) {
  $otpPortalCfg = json_decode(file_get_contents($otpPortalConfigPath), true);
  if (is_array($otpPortalCfg)) {
    $otpMode = (($otpPortalCfg['otp_mode'] ?? 'bypass') === 'otp') ? 'otp' : 'bypass';
    $otpMessageTemplate = trim($otpPortalCfg['otp_message_template'] ?? $otpMessageTemplate);
    if (isset($otpPortalCfg['otp_bot_timeout_seconds'])) {
      $waRequestTimeout = max(1, (int)$otpPortalCfg['otp_bot_timeout_seconds']);
      $waConnectTimeout = $waRequestTimeout;
    }
    if ($otpMessageTemplate === '') {
      $otpMessageTemplate = "*[INI ADALAH PESAN OTOMATIS]*\nIni adalah kode otp anda : *{otp}*";
    }
  }
}

// ============================================================
// Helper: normalisasi nomor WA jadi varian umum (62xxx/0xxx/+62xxx/digit polos),
// dan cari SEMUA pelanggan yang NOWA-nya cocok (bukan cuma LIMIT 1) --
// dipakai supaya nomor WA yang dipakai di lebih dari satu akun pelanggan
// (IDPEL/password beda, data lain bisa beda alamat) tidak asal ambil satu
// baris secara acak, tapi ditanya dulu ke pelanggan akun mana yang dimaksud.
// ============================================================
function normalizeWaDigitsPortalLogin($rawInput)
{
    $digits = preg_replace('/\D/', '', (string) $rawInput);
    if ($digits === '') {
        return null;
    }
    if (substr($digits, 0, 2) === '62') {
        $wa62 = $digits;
        $wa0 = '0' . substr($digits, 2);
    } elseif (substr($digits, 0, 1) === '0') {
        $wa62 = '62' . substr($digits, 1);
        $wa0 = $digits;
    } else {
        $wa62 = '62' . $digits;
        $wa0 = '0' . $digits;
    }
    return ['wa62' => $wa62, 'wa0' => $wa0, 'waPlus' => '+' . $wa62, 'digits' => $digits];
}

function findAllPelangganByWaVariants($conn, $wa62, $wa0, $waPlus, $digits)
{
    $rows = [];
    $seen = [];

    // [CEPAT] exact match dulu -- bisa pakai index.
    $stmtFast = mysqli_prepare($conn, "SELECT IDPEL, NAMA, ALAMAT, NOWA FROM pelanggan WHERE NOWA IN (?,?,?)");
    if ($stmtFast) {
        mysqli_stmt_bind_param($stmtFast, 'sss', $wa62, $wa0, $waPlus);
        mysqli_stmt_execute($stmtFast);
        $res = mysqli_stmt_get_result($stmtFast);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                if (!isset($seen[$row['IDPEL']])) {
                    $seen[$row['IDPEL']] = true;
                    $rows[] = $row;
                }
            }
        }
        mysqli_stmt_close($stmtFast);
    }

    // [LAMBAT] Hanya kalau exact match kosong -- pakai REPLACE (full scan, dibatasi waktu).
    if (empty($rows)) {
        @mysqli_query($conn, 'SET SESSION MAX_EXECUTION_TIME=2500');
        $stmtSlow = mysqli_prepare(
            $conn,
            "SELECT IDPEL, NAMA, ALAMAT, NOWA FROM pelanggan
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NOWA,'+',''),' ',''),'-',''),'(',''),')','') IN (?,?,?)"
        );
        if ($stmtSlow) {
            mysqli_stmt_bind_param($stmtSlow, 'sss', $wa62, $wa0, $digits);
            mysqli_stmt_execute($stmtSlow);
            $res = mysqli_stmt_get_result($stmtSlow);
            if ($res) {
                while ($row = mysqli_fetch_assoc($res)) {
                    if (!isset($seen[$row['IDPEL']])) {
                        $seen[$row['IDPEL']] = true;
                        $rows[] = $row;
                    }
                }
            }
            mysqli_stmt_close($stmtSlow);
        }
    }

    return $rows;
}

$showAccountChooser = false;
$accountChoices = [];
$accountChooserVerifiedWa = '';

$cek = $_GET['submit'] ?? '';
$idselect = $_GET['idselect'] ?? '';
$KODEOTP = $_GET['otp'] ?? '';
$KODEOTPREAL = $_GET['otpreal'] ?? '';

$cek = filter_input(INPUT_GET, 'submit', FILTER_SANITIZE_STRING) ?? '';
$idselect = filter_input(INPUT_GET, 'idselect', FILTER_SANITIZE_STRING) ?? '';
$KODEOTP = filter_input(INPUT_GET, 'otp', FILTER_SANITIZE_STRING) ?? '';
$KODEOTPREAL = filter_input(INPUT_GET, 'otpreal', FILTER_SANITIZE_STRING) ?? '';

// Jika submit == Masuk (OTP asli sudah diverifikasi di form)
if ($cek === "Masuk") {
    if ($KODEOTP === $KODEOTPREAL) {
        $waVariants = normalizeWaDigitsPortalLogin($idselect);
        $konek_file = __DIR__ . '/../koneksidb.php';
        if ($waVariants && file_exists($konek_file)) {
            include_once $konek_file;
        }

        $matches = ($waVariants && isset($conn) && $conn)
            ? findAllPelangganByWaVariants($conn, $waVariants['wa62'], $waVariants['wa0'], $waVariants['waPlus'], $waVariants['digits'])
            : [];

        if (count($matches) === 1) {
            $dbIDPEL = $matches[0]['IDPEL'];
            setcookie("idselect", $dbIDPEL, 0, "/");
            header("Location: portal_baru.php?cari=" . urlencode($dbIDPEL));
            exit;
        } elseif (count($matches) > 1) {
            $showAccountChooser = true;
            $accountChoices = $matches;
            $accountChooserVerifiedWa = $waVariants['wa62'];
        } else {
            // Tidak ketemu by NOWA (mis. data belum lengkap) -- fallback ke perilaku lama
            // supaya tidak mengunci pelanggan yang datanya belum rapi.
            setcookie("idselect", $idselect, 0, "/");
            header("Location: portal_baru.php?cari=" . urlencode($idselect));
            exit;
        }
    }
}

// Jika submit == PilihAkun (pelanggan pilih salah satu akun di popup chooser)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (filter_input(INPUT_POST, 'login_mode', FILTER_SANITIZE_STRING) ?? '') === 'select_account') {
    $selectedIdpel = filter_input(INPUT_POST, 'selected_idpel', FILTER_SANITIZE_STRING) ?? '';
    $verifiedWa = filter_input(INPUT_POST, 'verified_wa', FILTER_SANITIZE_STRING) ?? '';

    $konek_file = __DIR__ . '/../koneksidb.php';
    if (file_exists($konek_file)) {
        include_once $konek_file;
    }

    $selectOk = false;
    if ($selectedIdpel !== '' && $verifiedWa !== '' && isset($conn) && $conn) {
        $stmtSel = mysqli_prepare($conn, "SELECT IDPEL, NOWA FROM pelanggan WHERE IDPEL = ? LIMIT 1");
        if ($stmtSel) {
            mysqli_stmt_bind_param($stmtSel, 's', $selectedIdpel);
            mysqli_stmt_execute($stmtSel);
            mysqli_stmt_bind_result($stmtSel, $selIDPEL, $selNOWA);
            if (mysqli_stmt_fetch($stmtSel)) {
                // Wajib cocok dengan nomor WA yang sudah terverifikasi (OTP/bypass) di
                // langkah sebelumnya -- mencegah orang lain mengetik IDPEL sembarangan
                // di request ini untuk membajak akun yang bukan miliknya.
                $selNowaDigits = preg_replace('/\D/', '', (string) $selNOWA);
                $verifiedDigits = preg_replace('/\D/', '', (string) $verifiedWa);
                if ($selNowaDigits !== '' && $selNowaDigits === $verifiedDigits) {
                    $selectOk = true;
                }
            }
            mysqli_stmt_close($stmtSel);
        }
    }

    if ($selectOk) {
        setcookie('idselect', $selIDPEL, 0, '/');
        header('Location: portal_baru.php?cari=' . urlencode($selIDPEL));
        exit;
    } else {
        $loginError = 'Pilihan akun tidak valid. Silakan login ulang.';
    }
}

$OTPREAL = $_POST['otpreal'];

$OTPREAL = filter_input(INPUT_POST, 'otpreal', FILTER_SANITIZE_STRING);

//////////////////////////////////////////////////////////////////////////////////

$nohp1 = $_POST['idselect'];

$nohp1 = filter_input(INPUT_POST, 'idselect', FILTER_SANITIZE_STRING);
if (preg_match('/^\d{10,15}$/', trim($nohp1))) {
  if (substr(trim($nohp1), 0, 2) == '62') {
    $to = trim($nohp1);
  } elseif (substr(trim($nohp1), 0, 3) == '+62') {
    $to = '62' . substr(trim($nohp1), 1);
  } elseif (substr(trim($nohp1), 0, 1) == '0') {
    $to = '62' . substr(trim($nohp1), 1);
  }
}

// SEMENTARA: bypass OTP, login langsung jika nomor WA cocok di database
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login_mode = filter_input(INPUT_POST, 'login_mode', FILTER_SANITIZE_STRING) ?? '';
  if ($login_mode === 'otp' && $otpMode === 'bypass') {
    $disableOtpSend = true;
    $OTPREAL = '';

    $digits = preg_replace('/\D/', '', $nohp1 ?? '');
    if ($digits !== '') {
      if (substr($digits, 0, 2) === '62') {
        $wa62 = $digits;
        $wa0 = '0' . substr($digits, 2);
      } elseif (substr($digits, 0, 1) === '0') {
        $wa62 = '62' . substr($digits, 1);
        $wa0 = $digits;
      } else {
        $wa62 = '62' . $digits;
        $wa0 = '0' . $digits;
      }

      $konek_file = __DIR__ . '/../koneksidb.php';
      if (file_exists($konek_file)) {
        include_once $konek_file;
      }

      if (isset($conn) && $conn) {
        $waPlus = '+' . $wa62;
        $matchesBypass = findAllPelangganByWaVariants($conn, $wa62, $wa0, $waPlus, $digits);

        if (count($matchesBypass) === 1) {
          $dbIDPEL = $matchesBypass[0]['IDPEL'];
          setcookie('idselect', $dbIDPEL, 0, '/');
          header('Location: portal_baru.php?cari=' . urlencode($dbIDPEL));
          exit;
        } elseif (count($matchesBypass) > 1) {
          $showAccountChooser = true;
          $accountChoices = $matchesBypass;
          $accountChooserVerifiedWa = $wa62;
        } else {
          $loginError = 'Nomor WhatsApp tidak terdaftar.';
        }
      } else {
        $loginError = 'Koneksi database gagal.';
      }
    } else {
      $loginError = 'Nomor WhatsApp tidak valid.';
    }
  }
}

$text = str_replace(
  ['{otp}', '{nomor}', '{nohp}', '{phone}'],
  [$OTPREAL, $to, $to, $to],
  $otpMessageTemplate
);
$file = "waapi.txt";
$waapi = $namebot = $password = "";
if (file_exists($file)) {
  $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    if (substr($line, 0, 6) === "waapi=") $waapi = substr($line, 6);
    if (substr($line, 0, 8) === "namebot=") $namebot = substr($line, 8);
    if (substr($line, 0, 9) === "password=") $password = substr($line, 9);
  }
}

if (!$disableOtpSend && $OTPREAL != "") {
  $waapi = htmlspecialchars($waapi);
  $phone = "$to@s.whatsapp.net";
  $message = $text;
  
  // Tambahkan salam pembuka dinamis untuk menghindari spam detection OTP
  $message = prependDynamicGreeting($message);

  $data = [
    "phone" => $phone,
    "message" => $message
  ];

  $username = htmlspecialchars($namebot);
  $password = htmlspecialchars($password);

  // NOTE: waapi.txt untuk file ini tidak memuat baris "sender=", jadi tidak ada
  // nilai sender/device_id yang bisa dikirim (device_id gowa multi-device sengaja
  // dilewati di sini; session tetap ditambahkan agar konsisten dengan endpoint lain).
  $url = "$waapi/send/message?session=" . urlencode($namebot);
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json"
  ]);
  curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $waConnectTimeout);
  curl_setopt($ch, CURLOPT_TIMEOUT, $waRequestTimeout);
  $response   = curl_exec($ch);
  $curlErrNo  = curl_errno($ch);
  $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  // Jika API WA timeout/tidak bisa dijangkau, tetap teruskan ke halaman OTP
  // (user masih bisa verifikasi manual lewat nomor HP).
  if ($curlErrNo === CURLE_OPERATION_TIMEDOUT || $curlErrNo === CURLE_COULDNT_CONNECT) {
    $cekbott = 'TIMEOUT';
  }
}

$arr = json_decode($response, true);
if (is_array($arr) && isset($arr["code"])) {
  $cekbott = $arr["code"];
}
// Handle password-based login (posted from portallogin.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login_mode = filter_input(INPUT_POST, 'login_mode', FILTER_SANITIZE_STRING) ?? '';
  if ($login_mode === 'password') {
    $customer_id = filter_input(INPUT_POST, 'customer_id', FILTER_SANITIZE_STRING) ?? '';
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING) ?? '';

    // Normalize password as Indonesian phone number (support 0, +62, or 62)
    $pw = preg_replace('/\D/', '', $password);
    $to = '';
    if (preg_match('/^\d{9,15}$/', $pw)) {
      if (substr($pw, 0, 2) === '62') {
        $to = $pw;
      } elseif (substr($pw, 0, 1) === '0') {
        $to = '62' . substr($pw, 1);
      } else {
        // assume missing country code, prepend 62
        $to = '62' . $pw;
      }
    }

    if ($to) {
      // If customer_id provided, validate against database
      $dbOk = false;
      if (!empty($customer_id)) {
        $config_file = __DIR__ . '/../config.json';
        $dbHost = 'localhost'; $dbUser = 'root'; $dbPass = ''; $dbName = '';
        if (file_exists($config_file)) {
          $cfg = json_decode(file_get_contents($config_file), true);
          if (is_array($cfg)) {
            // try common keys
            if (!empty($cfg['db_host'])) $dbHost = $cfg['db_host'];
            if (!empty($cfg['DB_HOST'])) $dbHost = $cfg['DB_HOST'];
            if (!empty($cfg['db_user'])) $dbUser = $cfg['db_user'];
            if (!empty($cfg['DB_USER'])) $dbUser = $cfg['DB_USER'];
            if (!empty($cfg['db_pass'])) $dbPass = $cfg['db_pass'];
            if (!empty($cfg['DB_PASS'])) $dbPass = $cfg['DB_PASS'];
            if (!empty($cfg['db_name'])) $dbName = $cfg['db_name'];
            if (!empty($cfg['DB_NAME'])) $dbName = $cfg['DB_NAME'];
            // also allow URL field pointing to base app; not used here
          }
        }

        if (!empty($dbName)) {
          // Buat koneksi DB dengan timeout singkat agar login tidak hang.
          $connPw = mysqli_init();
          if ($connPw) {
            mysqli_options($connPw, MYSQLI_OPT_CONNECT_TIMEOUT, $dbConnectTimeout);
            if (defined('MYSQLI_OPT_READ_TIMEOUT'))
              mysqli_options($connPw, MYSQLI_OPT_READ_TIMEOUT, $dbReadTimeout);
          }
          $connPwOk = $connPw && @mysqli_real_connect($connPw, $dbHost, $dbUser, $dbPass, $dbName);
          if (!$connPwOk) {
            // DB tidak bisa dijangkau dalam batas waktu.
            $loginError = 'Server sedang sibuk, coba lagi sebentar.';
          }
          if ($connPwOk) {
            @mysqli_query($connPw, 'SET SESSION MAX_EXECUTION_TIME=4000');
            $konek_file = null; // Tidak perlu file koneksi lain, sudah buka sendiri.
            $conn = $connPw;
            if (true) {
              $stmt = mysqli_prepare($conn, "SELECT `PASSWORD`,`IDPEL`,`NOWA` FROM `pelanggan` WHERE `IDPEL` = ? LIMIT 1");
              if ($stmt) {
                mysqli_stmt_bind_param($stmt, 's', $customer_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_bind_result($stmt, $dbPassword, $dbIDPEL, $dbNOWA);
                if (mysqli_stmt_fetch($stmt)) {
                  // Validate password: first try matching against NOWA (phone number stored)
                  $pwOk = false;
                  $submittedDigits = preg_replace('/\D/', '', $password);
                  if (!empty($dbNOWA)) {
                    $dbNowaDigits = preg_replace('/\D/', '', $dbNOWA);
                    if ($submittedDigits === $dbNowaDigits) {
                      $pwOk = true;
                    }
                  }

                  // If NOWA didn't match or is empty, fall back to PASSWORD column (hashed or plain)
                  if (!$pwOk && !empty($dbPassword)) {
                    if (password_verify($password, $dbPassword)) {
                      $pwOk = true;
                    } elseif ($password === $dbPassword) {
                      $pwOk = true;
                    } else {
                      // also compare normalized phone stored in PASSWORD
                      $norm = preg_replace('/\D/', '', $password);
                      if ($norm === preg_replace('/\D/', '', $dbPassword)) $pwOk = true;
                    }
                  }

                  if ($pwOk) {
                    // Valid credentials -- login password sudah spesifik per IDPEL yang
                    // diketik pelanggan sendiri (tidak ambigu), jadi cookie identitas
                    // dibuat dari IDPEL, bukan NOWA (supaya sesi berikutnya juga
                    // deterministik ke akun yang sama persis kalau nomor WA dipakai
                    // di lebih dari satu akun).
                    $loginError = '';
                    setcookie('idselect', $dbIDPEL, 0, '/');
                    // Redirect to portal with IDPEL as 'id' GET parameter
                    header('Location: portal_baru.php?cari=' . urlencode($dbIDPEL));
                    exit;
                  } else {
                    $loginError = 'ID Pelanggan atau password salah.';
                  }
                } else {
                  $loginError = 'ID Pelanggan tidak ditemukan.';
                }
                mysqli_stmt_close($stmt);
              } else {
                $loginError = 'Gagal memproses permintaan database.';
              }
              mysqli_close($conn);
            } // end if true
          } // end if connPwOk
        } else {
          // No DB configured; fall back to phone-based login
          setcookie('idselect', $to, 0, '/');
          header('Location: portal_baru.php?cari=' . urlencode($to));
          exit;
        }
      } else {
        // No customer_id provided; proceed with phone-based login
        setcookie('idselect', $to, 0, '/');
        header('Location: portal_baru.php?cari=' . urlencode($to));
        exit;
      }
    } else {
      $loginError = 'Nomor telepon / password tidak valid. Gunakan nomor WhatsApp sebagai password.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Verifikasi OTP | Quenby Teknik</title>
  <link rel="icon" href="img/uang.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary-green: #0d6efd; /* primary (blue) - kept variable name for compatibility */
      --dark-green: #0a58ca; /* dark primary (darker blue) */
      --orange: #F7941D; /* secondary (orange) */
      --white: #ffffff; /* background white */
      --light-gray: #f8f9fa;
      --border-color: #e9ecef;
    }

    body {
      background-color: var(--white);
      font-family: 'Poppins', sans-serif;
      color: #333;
      min-height: 100vh;
    }

    /* Link colors - white text with orange hover to match header.php */
    a {
      color: var(--white) !important;
    }

    a:hover {
      color: var(--orange) !important;
    }

    .otp-container {
      max-width: 450px;
      margin: 0 auto;
      padding: 20px;
    }

    .otp-card {
      background: var(--white);
      border: 2px solid var(--border-color);
      border-radius: 12px;
      overflow: hidden;
    }

    .otp-header {
      background: var(--primary-green);
      color: white;
      padding: 20px;
      text-align: center;
      border-bottom: 4px solid var(--orange);
    }

    .otp-header h4 {
      font-weight: 700;
      margin: 0;
      font-size: 1.5rem;
    }

    .otp-body {
      padding: 25px;
    }

    .form-control {
      border-radius: 8px;
      padding: 12px 15px;
      border: 2px solid var(--border-color);
      font-size: 1rem;
    }

    .form-control:focus {
      border-color: var(--orange);
      outline: none;
    }

    .btn-submit {
      background-color: var(--orange);
      border: none;
      color: white;
      font-weight: 600;
      padding: 12px;
      border-radius: 8px;
      font-size: 1rem;
      width: 100%;
      margin-top: 15px;
    }

    .btn-submit:hover {
      background-color: #e65100;
      color: white;
    }

    .btn-back {
      background-color: var(--primary-green);
      border: none;
      color: white;
      font-weight: 600;
      padding: 12px;
      border-radius: 8px;
      font-size: 1rem;
      width: 100%;
      margin-top: 10px;
      text-decoration: none;
      display: block;
      text-align: center;
    }

    .btn-back:hover {
      background-color: var(--dark-green);
      color: white;
    }

    .alert {
      border-radius: 8px;
      border: 2px solid transparent;
    }

    @media (max-width: 576px) {
      .otp-container {
        padding: 15px;
      }

      .otp-header {
        padding: 15px;
      }

      .otp-body {
        padding: 20px;
      }
    }
  </style>
</head>

<body>
  <div class="d-flex align-items-center justify-content-center" style="min-height: 100vh;">
    <div class="otp-container" <?= $showAccountChooser ? 'style="max-width: 520px;"' : '' ?>>
      <div class="otp-card">
        <?php if ($showAccountChooser): ?>
          <div class="otp-header">
            <h4>PILIH AKUN ANDA</h4>
          </div>
          <div class="otp-body">
            <div class="text-center mb-3">
              <img src="https://img.icons8.com/color/96/user-group-man-man.png" width="72" alt="Pilih Akun">
              <p class="mt-2 mb-0">Nomor WhatsApp ini terdaftar di <strong><?= count($accountChoices) ?> akun pelanggan</strong>. Pilih akun yang ingin dibuka supaya tidak salah masuk / tagihan tidak tertukar.</p>
            </div>

            <?php if ($loginError): ?>
              <div class="alert alert-danger text-center mb-3"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>

            <?php foreach ($accountChoices as $choice): ?>
              <form method="POST" class="mb-2">
                <input type="hidden" name="login_mode" value="select_account">
                <input type="hidden" name="selected_idpel" value="<?= htmlspecialchars($choice['IDPEL']) ?>">
                <input type="hidden" name="verified_wa" value="<?= htmlspecialchars($accountChooserVerifiedWa) ?>">
                <button type="submit" class="btn btn-outline-primary w-100 text-start" style="border-radius: 8px; padding: 12px 15px;">
                  <div class="fw-bold">ID Pelanggan: <?= htmlspecialchars($choice['IDPEL']) ?></div>
                  <div><?= htmlspecialchars($choice['NAMA'] ?? '-') ?></div>
                  <small class="text-muted"><?= htmlspecialchars($choice['ALAMAT'] ?? '-') ?></small>
                </button>
              </form>
            <?php endforeach; ?>

            <a href="portallogin.php" class="btn btn-back mt-2">
              <i class="bi bi-arrow-left me-2"></i>KEMBALI
            </a>
          </div>
        <?php else: ?>
          <div class="otp-header">
            <h4>VERIFIKASI OTP</h4>
          </div>

          <div class="otp-body">
            <div class="text-center mb-4">
              <img src="https://img.icons8.com/external-yogi-aprelliyanto-flat-yogi-aprelliyanto/100/external-customer-shopping-and-ecommerce-yogi-aprelliyanto-flat-yogi-aprelliyanto.png" width="100" alt="Customer Icon">
              <p class="mt-2 mb-0">Kode OTP telah dikirim ke nomor WhatsApp terdaftar</p>
            </div>

            <?php if ($cekbott === "SUCCESS"): ?>
              <div class="alert alert-success text-center mb-4">
                ✅ OTP berhasil dikirim<br>
                <small class="d-block mt-1">Timeout respons bot WhatsApp: <?= (int)$waRequestTimeout ?> detik</small>
                <button class="btn btn-sm btn-warning mt-2" onclick="window.location.reload();">Kirim OTP Baru</button>
              </div>
            <?php elseif ($cekbott === "TIMEOUT"): ?>
              <div class="alert alert-warning text-center mb-4">
                ⏳ Respons bot WhatsApp timeout (<?= (int)$waRequestTimeout ?> detik).<br>
                <small class="d-block mt-1">Silakan kirim ulang OTP atau gunakan login password.</small>
                <button class="btn btn-sm btn-warning mt-2" onclick="window.location.reload();">Kirim OTP Baru</button>
              </div>
            <?php elseif ($OTPREAL): ?>
              <div class="alert alert-danger text-center mb-4">
                ❌ Gagal mengirim OTP<br>
                <button class="btn btn-sm btn-warning mt-2" onclick="window.location.reload();">Coba Lagi</button>
              </div>
            <?php endif; ?>

            <form action="" method="get">
              <input type="hidden" name="idselect" value="<?= htmlspecialchars($to) ?>">
              <input type="hidden" name="otpreal" value="<?= htmlspecialchars($OTPREAL) ?>">

              <div class="mb-3">
                <label for="otp" class="form-label fw-medium">Masukkan OTP</label>
                <input type="number" name="otp" class="form-control" placeholder="Contoh: 1234" required>
              </div>

              <button type="submit" name="submit" value="Masuk" class="btn btn-submit">
                <i class="bi bi-box-arrow-in-right me-2"></i>MASUK
              </button>
              <a href="portallogin.php" class="btn btn-back mt-2">
                <i class="bi bi-arrow-left me-2"></i>KEMBALI
              </a>
            </form>

            <hr />
            <!-- Password login fallback -->
            <?php if ($loginError): ?>
              <div class="alert alert-danger text-center mb-3"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>

          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>