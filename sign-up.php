<?php
session_start();
include 'koneksidb.php';

if (!isset($conn)) {
  die('Koneksi database tidak tersedia.');
}

function check_signup_duplicates($conn, $username, $email, $nowa)
{
  $username = trim((string)$username);
  $email = trim((string)$email);
  $nowa = trim((string)$nowa);

  $exists = [
    'username' => false,
    'email' => false,
    'nowa' => false,
  ];

  // Cek username
  $stmt = $conn->prepare("SELECT 1 FROM user WHERE USERNAME = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    $exists['username'] = $stmt->num_rows > 0;
    $stmt->close();
  }

  // Cek email (kolom domain dipakai menyimpan email)
  $stmt = $conn->prepare("SELECT 1 FROM user WHERE LOWER(domain) = LOWER(?) LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    $exists['email'] = $stmt->num_rows > 0;
    $stmt->close();
  }

  // Cek nomor WhatsApp
  $stmt = $conn->prepare("SELECT 1 FROM user WHERE NOWA = ? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("s", $nowa);
    $stmt->execute();
    $stmt->store_result();
    $exists['nowa'] = $stmt->num_rows > 0;
    $stmt->close();
  }

  return $exists;
}

function create_signup_user($conn, $reg_data)
{
  $duplicate_status = check_signup_duplicates($conn, $reg_data['nama'] ?? '', $reg_data['email'] ?? '', $reg_data['nowa'] ?? '');
  if ($duplicate_status['username'] || $duplicate_status['email'] || $duplicate_status['nowa']) {
    if ($duplicate_status['username']) {
      return ['ok' => false, 'message' => '❌ Username sudah digunakan. Silakan pilih username lain.'];
    }
    if ($duplicate_status['email']) {
      return ['ok' => false, 'message' => '❌ Email sudah terdaftar. Gunakan email lain.'];
    }
    return ['ok' => false, 'message' => '❌ Nomor WhatsApp sudah terdaftar. Gunakan nomor lain.'];
  }

  $created_at = date('Y-m-d H:i:s');
  $expired_at = date('Y-m-d H:i:s', strtotime('+7 days'));

  $username = $conn->real_escape_string($reg_data['nama']);
  $words = explode(' ', strtoupper($username));
  $initials = '';
  foreach ($words as $word) {
    $initials .= substr($word, 0, 1);
    if (strlen($initials) >= 3) {
      break;
    }
  }
  if (strlen($initials) < 3) {
    $initials .= strtoupper(substr(str_replace(' ', '', $username), strlen($initials), 3 - strlen($initials)));
  }
  $initials = substr($initials, 0, 3);

  $password_hash = password_hash($reg_data['password'], PASSWORD_DEFAULT);
  $nowa = $conn->real_escape_string($reg_data['nowa']);
  $email = $conn->real_escape_string($reg_data['email']);

  $sql = "INSERT INTO user (
      USERNAME, PASWORD, STATUS, NOWA, saldo, server, payment_default, domain, inisial, akses, created_at, expired_at
  ) VALUES (
      '$username',
      '$password_hash',
      'USER',
      '$nowa',
      2000,
      NULL,
      'manual_bank',
      '$email',
      '$initials',
      '',
      '$created_at',
      '$expired_at'
  )";

  if ($conn->query($sql)) {
    return [
      'ok' => true,
      'username' => $username,
      'message' => ''
    ];
  }

  return [
    'ok' => false,
    'message' => '❌ Gagal menyimpan data: ' . $conn->error
  ];
}

function read_waapi_credentials($path)
{
  $result = ['waapi' => '', 'namebot' => '', 'password' => ''];
  if (!file_exists($path)) {
    return $result;
  }

  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    if (strpos($line, 'waapi=') === 0) {
      $result['waapi'] = trim(substr($line, 6));
    }
    if (strpos($line, 'namebot=') === 0) {
      $result['namebot'] = trim(substr($line, 8));
    }
    if (strpos($line, 'password=') === 0) {
      $result['password'] = trim(substr($line, 9));
    }
  }

  return $result;
}

$otp_signup_config_path = __DIR__ . '/otp_signup_config.json';
$otp_signup_waapi_path = __DIR__ . '/otp_signup_waapi.txt';
$legacy_waapi_path = __DIR__ . '/waapi.txt';

$otp_signup_mode = 'otp';
$otp_length = 6;
$otp_expiry_seconds = 120;
$otp_message_template = "*[INI ADALAH PESAN OTOMATIS]*\nKode OTP Anda: *{otp}*\nBerlaku {expired_minutes} menit";
$otp_random_greeting_enabled = false;
$otp_random_greetings = ['Halo', 'Hai', 'Assalamualaikum'];

if (file_exists($otp_signup_config_path)) {
  $otp_cfg = json_decode(file_get_contents($otp_signup_config_path), true);
  if (is_array($otp_cfg)) {
    $otp_signup_mode = ($otp_cfg['otp_mode'] ?? 'otp') === 'bypass' ? 'bypass' : 'otp';
    $otp_length = (int)($otp_cfg['otp_length'] ?? 6);
    $otp_expiry_seconds = (int)($otp_cfg['otp_expiry_seconds'] ?? 120);
    $otp_template_candidate = trim((string)($otp_cfg['otp_message_template'] ?? ''));
    if ($otp_template_candidate !== '') {
      $otp_message_template = $otp_template_candidate;
    }

    $otp_random_greeting_enabled = !empty($otp_cfg['otp_random_greeting_enabled']);
    if (isset($otp_cfg['otp_random_greetings']) && is_array($otp_cfg['otp_random_greetings'])) {
      $otp_greeting_candidates = [];
      foreach ($otp_cfg['otp_random_greetings'] as $otp_greeting_item) {
        $otp_greeting_item = trim((string)$otp_greeting_item);
        if ($otp_greeting_item !== '') {
          $otp_greeting_candidates[] = $otp_greeting_item;
        }
      }
      if (!empty($otp_greeting_candidates)) {
        $otp_random_greetings = $otp_greeting_candidates;
      }
    }
  }
}

$otp_length = max(4, min(8, $otp_length));
$otp_expiry_seconds = max(60, min(900, $otp_expiry_seconds));

$waapi_data = read_waapi_credentials($otp_signup_waapi_path);
if ($waapi_data['waapi'] === '' && $waapi_data['namebot'] === '' && $waapi_data['password'] === '') {
  $waapi_data = read_waapi_credentials($legacy_waapi_path);
}

$waapi = $waapi_data['waapi'];
$usernamebot = $waapi_data['namebot'];
$passwordbot = $waapi_data['password'];

$nama = $_POST['nama'] ?? '';
$nowa = $_POST['nowa'] ?? '';
$email = $_POST['email'] ?? '';
$password1 = $_POST['password'] ?? '';
$password2 = $_POST['password2'] ?? '';
$otpdefault = $_POST['otpdefault'] ?? '';
$otprespone = $_POST['otprespone'] ?? '';
$submit = isset($_POST['daftar']);

$pesan = "";

if ($submit) {
  // Username tidak boleh ada spasi
  if (preg_match('/\s/', $nama)) {
    $pesan = "❌ Username tidak boleh ada spasi!";
  } elseif (!preg_match('/^62\d+$/', $nowa)) {
    $pesan = "❌ Format nomor WhatsApp harus diawali 62 dan hanya berisi angka";
  } elseif ($password1 !== $password2) {
    $pesan = "❌ Password tidak cocok!";
  } elseif (strlen($password1) < 6) {
    $pesan = "❌ Password minimal 6 karakter!";
  } elseif (empty($otpdefault)) {
    $duplicate_status = check_signup_duplicates($conn, $nama, $email, $nowa);

    if ($duplicate_status['username']) {
      $pesan = "❌ Username sudah digunakan. Silakan pilih username lain.";
    } elseif ($duplicate_status['email']) {
      $pesan = "❌ Email sudah terdaftar. Gunakan email lain.";
    } elseif ($duplicate_status['nowa']) {
      $pesan = "❌ Nomor WhatsApp sudah terdaftar. Gunakan nomor lain.";
    } else {
      $reg_data = [
        'nama' => $nama,
        'nowa' => $nowa,
        'email' => $email,
        'password' => $password1
      ];

      if ($otp_signup_mode === 'bypass') {
        $create_result = create_signup_user($conn, $reg_data);
        $pesan = $create_result['message'];
        if ($create_result['ok']) {
          // Simpan logo upload
          $new_uname = preg_replace('/[^a-zA-Z0-9_-]/', '_', $create_result['username']);
          // Ditulis langsung ke folder terpusat dokumen/logo/ (sejajar crm/, diakses via URL /dokumen/logo/...)
          $up_dir = __DIR__ . '/../../dokumen/logo/';
          if (!is_dir($up_dir)) mkdir($up_dir, 0755, true);
          $logo_saved = false;
          if (isset($_FILES['logo_produk']) && $_FILES['logo_produk']['error'] === UPLOAD_ERR_OK) {
            $lf = $_FILES['logo_produk'];
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $lm = finfo_file($fi, $lf['tmp_name']);
            finfo_close($fi);
            $ok_mime = ['image/jpeg','image/png','image/pjpeg','image/x-png'];
            if (in_array($lm, $ok_mime) && $lf['size'] <= 2097152 && @getimagesize($lf['tmp_name']) !== false) {
              $ltgt = $up_dir . "profile-{$new_uname}.png";
              if (function_exists('imagecreatefromstring')) {
                $limg = @imagecreatefromstring(file_get_contents($lf['tmp_name']));
                if ($limg !== false) { imagesavealpha($limg, true); imagepng($limg, $ltgt); imagedestroy($limg); $logo_saved = true; }
              }
              if (!$logo_saved) { move_uploaded_file($lf['tmp_name'], $ltgt); }
            }
          }
          global $config;
          $login_url = rtrim($config['URL'] ?? ('https://' . ($config['domain'] ?? '')), '/') . '/crm/billing/?brand=' . rawurlencode($create_result['username']);
          $pesan = "✅ Registrasi berhasil!<br><div class='mt-2 p-2 border rounded bg-light'>🔗 <strong>URL Login Anda:</strong><br><a href='" . htmlspecialchars($login_url, ENT_QUOTES, 'UTF-8') . "' target='_blank' class='text-break fw-bold'>" . htmlspecialchars($login_url, ENT_QUOTES, 'UTF-8') . "</a></div><br><a href='index.php' class='btn btn-success btn-sm'>SIGN IN</a>";
        }
      } else {
        $otp_min = (int)pow(10, $otp_length - 1);
        $otp_max = (int)pow(10, $otp_length) - 1;
        $otp = rand($otp_min, $otp_max);

        $_SESSION['otpdefault'] = (string)$otp;
        $_SESSION['otp_expired'] = time() + $otp_expiry_seconds;
        $_SESSION['reg_data'] = $reg_data;

        // Simpan logo sementara selama proses OTP
        if (isset($_FILES['logo_produk']) && $_FILES['logo_produk']['error'] === UPLOAD_ERR_OK) {
          $lf_otp = $_FILES['logo_produk'];
          $fi_otp = finfo_open(FILEINFO_MIME_TYPE);
          $lm_otp = finfo_file($fi_otp, $lf_otp['tmp_name']);
          finfo_close($fi_otp);
          $ok_mime_otp = ['image/jpeg','image/png','image/pjpeg','image/x-png'];
          if (in_array($lm_otp, $ok_mime_otp) && $lf_otp['size'] <= 2097152 && @getimagesize($lf_otp['tmp_name']) !== false) {
            $tmp_logo = __DIR__ . '/../../dokumen/logo/tmp_signup_' . preg_replace('/[^a-zA-Z0-9]/', '', session_id()) . '.png';
            $tmpdir = __DIR__ . '/../../dokumen/logo/';
            if (!is_dir($tmpdir)) mkdir($tmpdir, 0755, true);
            if (function_exists('imagecreatefromstring')) {
              $timgd = @imagecreatefromstring(file_get_contents($lf_otp['tmp_name']));
              if ($timgd !== false) { imagesavealpha($timgd, true); imagepng($timgd, $tmp_logo); imagedestroy($timgd); $_SESSION['signup_tmp_logo'] = $tmp_logo; }
            } else {
              if (move_uploaded_file($lf_otp['tmp_name'], $tmp_logo)) { $_SESSION['signup_tmp_logo'] = $tmp_logo; }
            }
          }
        }

        // Kirim OTP via WA API
        $expired_minutes = max(1, (int)ceil($otp_expiry_seconds / 60));
        $selected_greeting = '';
        if ($otp_random_greeting_enabled && !empty($otp_random_greetings)) {
          $selected_greeting = $otp_random_greetings[array_rand($otp_random_greetings)];
        }

        $text = str_replace(
          ['{otp}', '{expired_minutes}', '{greeting}'],
          [(string)$otp, (string)$expired_minutes, (string)$selected_greeting],
          $otp_message_template
        );

        if ($selected_greeting !== '' && strpos($otp_message_template, '{greeting}') === false) {
          $text = $selected_greeting . "\n" . $text;
        }

        if ($waapi === '' || $usernamebot === '' || $passwordbot === '') {
          $pesan = '⚠️ Pengaturan OTP belum lengkap. Hubungi administrator.';
          unset($_SESSION['otpdefault'], $_SESSION['otp_expired'], $_SESSION['reg_data']);
        } else {
          $phone = "$nowa@s.whatsapp.net";
          $data = [
            'phone' => $phone,
            'message' => $text
          ];

          $ch = curl_init();
          curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($waapi, '/') . '/send/message',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERPWD => "$usernamebot:$passwordbot",
            CURLOPT_TIMEOUT => 10
          ]);

          curl_exec($ch);
          $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          curl_close($ch);

          if ($httpCode == 200) {
            $pesan = '✅ Kode OTP telah dikirim ke WhatsApp Anda.';
            $otpdefault = (string)$otp;
          } else {
            $pesan = '⚠️ Gagal mengirim OTP. Silakan coba lagi.';
            unset($_SESSION['otpdefault'], $_SESSION['otp_expired'], $_SESSION['reg_data']);
          }
        }
      }
    }
  } elseif (!empty($otpdefault) && !empty($otprespone)) {
    if (!isset($_SESSION['otp_expired'], $_SESSION['otpdefault'], $_SESSION['reg_data'])) {
      $pesan = '⚠️ Sesi OTP tidak ditemukan. Silakan daftar ulang.';
    } elseif (time() > $_SESSION['otp_expired']) {
      $pesan = "⏰ OTP kedaluwarsa. Silakan kirim ulang OTP.";
      unset($_SESSION['otpdefault'], $_SESSION['otp_expired'], $_SESSION['reg_data']);
    } elseif (hash_equals((string)$_SESSION['otpdefault'], (string)$otprespone)) {
      $session_reg_data = $_SESSION['reg_data'];
      $duplicate_status = check_signup_duplicates(
        $conn,
        $session_reg_data['nama'] ?? '',
        $session_reg_data['email'] ?? '',
        $session_reg_data['nowa'] ?? ''
      );

      if ($duplicate_status['username']) {
        $pesan = '❌ Username sudah digunakan. Silakan daftar ulang dengan username lain.';
        unset($_SESSION['otpdefault'], $_SESSION['otp_expired'], $_SESSION['reg_data']);
      } elseif ($duplicate_status['email']) {
        $pesan = '❌ Email sudah terdaftar. Silakan daftar ulang dengan email lain.';
        unset($_SESSION['otpdefault'], $_SESSION['otp_expired'], $_SESSION['reg_data']);
      } elseif ($duplicate_status['nowa']) {
        $pesan = '❌ Nomor WhatsApp sudah terdaftar. Silakan daftar ulang dengan nomor lain.';
        unset($_SESSION['otpdefault'], $_SESSION['otp_expired'], $_SESSION['reg_data']);
      } else {
        $create_result = create_signup_user($conn, $session_reg_data);
        $pesan = $create_result['message'];
        if ($create_result['ok']) {
          unset($_SESSION['otpdefault'], $_SESSION['otp_expired'], $_SESSION['reg_data']);
          // Pindahkan logo sementara ke nama permanen
          $new_uname_otp = preg_replace('/[^a-zA-Z0-9_-]/', '_', $create_result['username']);
          // Ditulis langsung ke folder terpusat dokumen/logo/ (sejajar crm/, diakses via URL /dokumen/logo/...)
          $up_dir_otp = __DIR__ . '/../../dokumen/logo/';
          if (!is_dir($up_dir_otp)) mkdir($up_dir_otp, 0755, true);
          if (!empty($_SESSION['signup_tmp_logo']) && file_exists($_SESSION['signup_tmp_logo'])) {
            $final_logo = $up_dir_otp . "profile-{$new_uname_otp}.png";
            rename($_SESSION['signup_tmp_logo'], $final_logo);
            unset($_SESSION['signup_tmp_logo']);
          }
          global $config;
          $login_url_otp = rtrim($config['URL'] ?? ('https://' . ($config['domain'] ?? '')), '/') . '/crm/billing/?brand=' . rawurlencode($create_result['username']);
          $pesan = "✅ Registrasi berhasil!<br><div class='mt-2 p-2 border rounded bg-light'>🔗 <strong>URL Login Anda:</strong><br><a href='" . htmlspecialchars($login_url_otp, ENT_QUOTES, 'UTF-8') . "' target='_blank' class='text-break fw-bold'>" . htmlspecialchars($login_url_otp, ENT_QUOTES, 'UTF-8') . "</a></div><br><a href='index.php' class='btn btn-success btn-sm'>SIGN IN</a>";
        }
      }
    } else {
      $pesan = "❌ OTP salah.";
    }
  }
}
?>

<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">





<!-- Modal Syarat & Ketentuan -->
<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="termsModalLabel">Syarat & Ketentuan Berlangganan — Aplikasi Billing Internet</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <!-- Ringkasan -->
        <p><strong>Ringkasan:</strong> Dengan menyetujui syarat ini, Anda setuju menggunakan layanan internet sesuai paket yang dipilih, tunduk pada kebijakan pembayaran, perpanjangan otomatis, pembatalan, dan aturan penggunaan yang ditetapkan penyedia.</p>

        <!-- Ketentuan lengkap -->
        <h6>1. Pendaftaran & Akun</h6>
        <ol>
          <li>Pengguna wajib mendaftarkan data yang benar dan valid.</li>
          <li>Pengguna bertanggung jawab menjaga kerahasiaan kredensial akun.</li>
          <li>Penyedia berhak menangguhkan atau menutup akun jika ditemukan pelanggaran data atau penyalahgunaan.</li>
        </ol>

        <h6>2. Layanan & Paket</h6>
        <ol>
          <li>Deskripsi paket (kecepatan, kuota, masa aktif) tertulis pada halaman produk. Kecepatan merupakan nilai estimasi dan dapat bervariasi.</li>
          <li>Perubahan paket dapat dikenakan biaya atau ketentuan prorata sesuai kebijakan penyedia.</li>
        </ol>

        <h6>3. Pembayaran & Tagihan</h6>
        <ol>
          <li>Pembayaran harus dilakukan sesuai metode yang tersedia. Transaksi dianggap sah setelah konfirmasi pembayaran diterima.</li>
          <li>Perpanjangan dapat bersifat manual atau otomatis (otomatis jika Anda mengaktifkan fitur auto-renewal).</li>
          <li>Keterlambatan pembayaran dapat mengakibatkan pembatasan layanan atau denda sesuai ketentuan.</li>
        </ol>

        <h6>4. Pembatalan & Pengembalian Dana</h6>
        <ol>
          <li>Permintaan pembatalan paket harus diajukan sesuai prosedur; kebijakan pengembalian dana bergantung jenis paket (mis. voucher non-refundable).</li>
          <li>Jika terdapat kesalahan sistem yang menyebabkan penagihan ganda, komplain dapat diajukan dalam jangka waktu yang ditentukan untuk proses koreksi.</li>
        </ol>

        <h6>5. Penggunaan yang Dilarang</h6>
        <ol>
          <li>Dilarang menggunakan layanan untuk aktivitas ilegal, spamming, serangan siber, atau pelanggaran hak cipta.</li>
          <li>Penyedia berhak memblokir akses jika terdeteksi aktivitas merugikan.</li>
        </ol>

        <h6>6. Privasi & Data</h6>
        <ol>
          <li>Penyedia mengumpulkan data sesuai kebijakan privasi. Data akan digunakan untuk proses layanan, analitik, dan penagihan.</li>
          <li>Untuk detail, lihat Kebijakan Privasi yang terpisah.</li>
        </ol>

        <h6>7. Gangguan & Pemeliharaan</h6>
        <ol>
          <li>Penyedia akan berusaha meminimalkan gangguan layanan. Pemeliharaan terjadwal akan diberitahukan sesuai kebijakan.</li>
          <li>Penyedia tidak bertanggung jawab atas kerugian tidak langsung akibat gangguan jaringan di luar kendali.</li>
        </ol>

        <h6>8. Batas Tanggung Jawab</h6>
        <ol>
          <li>Penyedia tidak bertanggung jawab atas kerugian tidak langsung, hilangnya data, atau kehilangan keuntungan akibat pemutusan layanan, kecuali ditentukan lain oleh hukum yang berlaku.</li>
        </ol>

        <h6>9. Perubahan Syarat</h6>
        <ol>
          <li>Penyedia dapat mengubah syarat sewaktu-waktu. Perubahan akan diberitahukan; melanjutkan penggunaan dianggap sebagai persetujuan atas perubahan tersebut.</li>
        </ol>

        <h6>10. Penyelesaian Sengketa</h6>
        <ol>
          <li>Segala sengketa akan diselesaikan secara musyawarah. Jika tidak tercapai, akan diselesaikan menurut hukum yang berlaku di Indonesia.</li>
        </ol>

        <hr>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <!-- Tombol ini mencentang checkbox dan menutup modal -->
        <button type="button" id="agreeBtn" class="btn btn-primary">Saya Setuju</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const termsCheckbox = document.getElementById('termsCheck');
  const termsModalEl = document.getElementById('termsModal');
  const termsModal = new bootstrap.Modal(termsModalEl);

  // Klik checkbox = buka modal
  termsCheckbox.addEventListener('click', function (e) {
    e.preventDefault(); // cegah langsung dicentang
    termsModal.show();  // tampilkan modal
  });

  // Klik tombol "Saya Setuju" di modal
  document.getElementById('agreeBtn').addEventListener('click', function () {
    termsCheckbox.checked = true; // centang checkbox
    termsModal.hide();            // tutup modal
  });
});
</script>

<style>
#termsModal .modal-body { font-size: 0.95rem; line-height: 1.6; }
#termsModal h6 { margin-top: 1rem; font-weight: 600; }
</style>
















  <style>
    /* Alternative Login Styles */
    .alternative-login {
      margin-top: 20px;
      text-align: center;
    }

    .hotspot-login-btn {
      display: inline-block;
      padding: 10px 15px;
      background: linear-gradient(to right, #0066ff, #0044cc);
      color: white;
      border-radius: 8px;
      text-decoration: none;
      margin: 10px 0;
      transition: all 0.3s;
    }

    .hotspot-login-btn:hover {
      background: linear-gradient(to right, #0044cc, #0066ff);
      box-shadow: 0 3px 10px rgba(0, 68, 204, 0.3);
    }

    .hotspot-login-btn i {
      margin-right: 8px;
    }

    .signup-link {
      margin-top: 15px;
      font-size: 14px;
      color: #666;
    }

  .signup-link a {
    color: var(--primary-color);
    font-weight: 600;
    text-decoration: none;
  }    .signup-link a:hover {
      text-decoration: underline;
    }

    /* Alert Message */
    .alert {
      padding: 10px 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
    }

    .alert-danger {
      background-color: #ffdddd;
      color: #ff0000;
      border: 1px solid #ffaaaa;
    }

    :root {
      --primary-color: <?php echo isset($config['extracted_primary_color']) ? $config['extracted_primary_color'] : '#f68013'; ?>;
      --secondary-color: <?php echo isset($config['extracted_secondary_color']) ? $config['extracted_secondary_color'] : '#f68012'; ?>;
      --accent-color: #FFA726;
    }
    body {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color), #ffffff, #4da1ff, #0066ff);
      background-size: 400% 400%;
      animation: gradient 15s ease infinite;
      height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      overflow: hidden;
    }

    @keyframes gradient {
      0% {
        background-position: 0% 50%;
      }

      50% {
        background-position: 100% 50%;
      }

      100% {
        background-position: 0% 50%;
      }
    }  .login-container {
    background-color: rgba(255, 255, 255, 0.9);
    width: 380px;
    padding: 40px 30px;
    border-radius: 15px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
    position: relative;
    overflow: hidden;
    z-index: 1;
  }

  .login-container::before {
    content: "";
    position: absolute;
    top: -50%;
    left: -50%;
    width: 200%;
    height: 200%;
    background:
      linear-gradient(45deg, transparent 65%, rgba(255, 140, 0, 0.1) 65%),
      linear-gradient(-45deg, transparent 65%, rgba(0, 102, 255, 0.1) 65%);
    background-size: 30px 30px;
    transform: rotate(10deg);
    z-index: -1;
  }

  /* Tambahkan ini di bagian CSS Anda */
  .logo-img {
    width: 300px;
    height: 80px;
    margin-bottom: 15px;
    object-fit: contain;
    filter: drop-shadow(0 2px 5px rgba(0, 0, 0, 0.1));
  }

  .logo h1 {
    color: #ff8c00;
    font-size: 28px;
    margin-bottom: 5px;
  }

  .logo p {
    color: #0066ff;
    font-size: 14px;
    font-weight: 500;
  }

  .input-group {
    margin-bottom: 20px;
    position: relative;
  }

  .input-group input {
    width: 100%;
    padding: 12px 15px;
    border: 2px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.3s;
    background-color: rgba(255, 255, 255, 0.8);
  }

  .input-group input:focus {
    border-color: var(--primary-color);
    outline: none;
    box-shadow: 0 0 0 3px rgba(246, 128, 19, 0.2);
  }

  .input-group label {
    position: absolute;
    left: 15px;
    top: 12px;
    color: #777;
    font-size: 14px;
    transition: all 0.3s;
    pointer-events: none;
    background-color: rgba(255, 255, 255, 0.8);
    padding: 0 5px;
  }

  .input-group input:focus+label,
  .input-group input:not(:placeholder-shown)+label {
    top: -10px;
    font-size: 12px;
    color: var(--primary-color);
  }

  .remember-forgot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    font-size: 13px;
  }

  .remember-me {
    display: flex;
    align-items: center;
  }

  .remember-me input {
    margin-right: 5px;
  }

  .forgot-password a {
    color: #0066ff;
    text-decoration: none;
  }

  .forgot-password a:hover {
    text-decoration: underline;
  }

  .login-btn {
    width: 100%;
    padding: 12px;
    background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
    border: none;
    border-radius: 8px;
    color: white;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
  }

  .login-btn:hover {
    background: linear-gradient(to right, var(--secondary-color), var(--primary-color));
    box-shadow: 0 5px 15px rgba(255, 107, 0, 0.4);
  }

  .hash-ornament {
    position: absolute;
    color: rgba(0, 0, 0, 0.05);
    font-size: 120px;
    font-weight: bold;
    z-index: -1;
    user-select: none;
  }

  .hash-1 {
    top: -30px;
    right: -30px;
    transform: rotate(15deg);
  }

  .hash-2 {
    bottom: -40px;
    left: -30px;
    transform: rotate(-15deg);
  }

  .footer {
    text-align: center;
    margin-top: 20px;
    font-size: 12px;
    color: #666;
  }
</style>


<div class="register-container-wide">
  <div class="register-grid">
    <!-- Branding/logo di kiri -->
    <div class="register-branding">
      <img src="../panel/<?php echo isset($config['logo_url']) ? $config['logo_url'] : 'logo.png'; ?>" alt="Logo" class="logo-img">
      <h1><?php echo isset($config['perusahaan']) ? $config['perusahaan'] : 'Billing Internet'; ?></h1>
      <p>Daftar akun baru untuk layanan internet Anda</p>
    </div>
    <!-- Form di kanan -->
    <div class="register-form-side">
      <div class="hash-ornament hash-1">#</div>
      <div class="hash-ornament hash-2">#</div>
      <h2 class="text-center mb-4">Daftar Akun Baru</h2>
      <?php if ($pesan): ?>
        <div class="alert alert-<?= strpos($pesan, '❌') !== false ? 'danger' : (strpos($pesan, '⏰') !== false ? 'warning' : 'success') ?>">
          <?= $pesan ?>
        </div>
      <?php endif; ?>
      <form method="POST" action="" id="registerForm" enctype="multipart/form-data">
        <div class="input-group mb-3">
          <label for="nama" class="form-label">Username</label>
          <input type="text" id="nama" name="nama" class="form-control" placeholder="Buat username tanpa spasi"
            value="<?= htmlspecialchars($nama) ?>" required pattern="[^\s]+" title="Username tidak boleh ada spasi">
           </div>
        <div class="input-group mb-3">
          <label for="password" class="form-label">Password</label>
          <input type="password" id="password" name="password" class="form-control" placeholder="Buat password" required>
          <small class="form-text text-muted">Minimal 8 karakter, huruf besar, huruf kecil, angka, dan simbol.</small>
          <div id="strengthMessage" style="margin-top:5px;font-weight:bold;"></div>
        </div>
        <div class="input-group mb-3">
          <label for="password2" class="form-label">Ulangi Password</label>
          <input type="password" id="password2" name="password2" class="form-control" placeholder="Ulangi Password" required>
        </div>
        <div class="input-group mb-3">
          <label for="nowa" class="form-label">Nomor WhatsApp</label>
          <input type="tel" id="nowa" name="nowa" class="form-control" placeholder="Contoh : 62876464351"
            value="<?= htmlspecialchars($nowa) ?>" required pattern="62[0-9]{8,}" title="Nomor WhatsApp harus diawali 62 dan hanya angka">
          <small class="form-text text-muted">Format: 62xxxxxxxxxxx (hanya angka, tanpa spasi/tanda lain)</small>
        </div>
        <div class="input-group mb-3">
          <label for="email" class="form-label">Email</label>
          <input type="email" id="email" name="email" class="form-control" placeholder="Email anda" required>
        </div>
        <!-- Upload Logo Produk -->
        <div class="mb-3">
          <label class="form-label"><i class="fas fa-image me-1"></i>Logo Produk <small class="text-muted">(opsional, PNG/JPG, maks 2MB)</small></label>
          <div class="d-flex align-items-center gap-3">
            <label for="logo_produk" style="cursor:pointer;" title="Klik untuk pilih logo">
              <div id="logoPreviewWrap" style="width:80px;height:80px;border-radius:50%;border:2px dashed #ccc;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f9f9f9;transition:border-color .2s;">
                <img id="logoPreviewImg" src="" alt="" style="width:76px;height:76px;object-fit:contain;display:none;">
                <span id="logoPreviewPlaceholder" style="font-size:28px;">📷</span>
              </div>
            </label>
            <div>
              <input type="file" name="logo_produk" id="logo_produk" accept="image/png,image/jpeg" style="display:none;" onchange="previewLogo(this)">
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('logo_produk').click()"><i class="fas fa-upload me-1"></i>Pilih Logo</button>
              <div id="logoFileName" class="text-muted mt-1" style="font-size:12px;"></div>
              <small class="text-muted d-block mt-1">Logo akan tampil di halaman login URL khusus Anda</small>
            </div>
          </div>
        </div>
        <!-- Checkbox (klik langsung buka modal) -->
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="termsCheck" name="terms" required>
          <label class="form-check-label" for="termsCheck">
            Saya menyetujui <a href="#" onclick="return false;">Syarat & Ketentuan</a>
          </label>
        </div>
        <button type="submit" class="login-btn mt-2" name="daftar" class="btn btn-primary w-100">
          <?= empty($otpdefault) ? 'Daftar' : 'Verifikasi OTP' ?>
        </button>
      </form>
      <div class="text-center mt-3">
        Sudah punya akun? <a href="index.php">Login disini</a>
      </div>
      <div class="text-center mt-3">
        Lupa akun anda ? <a href="forgot.php"> disini</a>
      </div>
    </div>
  </div>
</div>


<style>
  body {
    background: linear-gradient(135deg,
        #ff8c00,
        #ffaf4d,
        #ffffff,
        #4da1ff,
        #0066ff);
    background-size: 400% 400%;
    animation: gradient 15s ease infinite;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    overflow: auto;
  }
  *, *::before, *::after {
    box-sizing: border-box;
  }
  .register-container-wide {
    width: 100%;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 12px;
    background: none;
  }
  .register-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    background-color: rgba(255,255,255,0.95);
    border-radius: 18px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
    width: min(900px, 100%);
    min-height: 600px;
    overflow: hidden;
    position: relative;
  }
  .register-branding {
    background: linear-gradient(135deg, var(--primary-color) 60%, #4da1ff 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    border-top-left-radius: 18px;
    border-bottom-left-radius: 18px;
    min-height: 600px;
    color: #fff;
    text-align: center;
  }
  .register-branding .logo-img {
    width: 220px;
    height: 70px;
    margin-bottom: 20px;
    object-fit: contain;
    filter: drop-shadow(0 2px 5px rgba(0,0,0,0.1));
    background: #fff;
    border-radius: 8px;
    padding: 8px 0;
  }
  .register-branding h1 {
    font-size: 2.1rem;
    font-weight: 700;
    margin-bottom: 10px;
    color: #fff;
    letter-spacing: 1px;
    text-shadow: 0 2px 8px rgba(0,0,0,0.08);
  }
  .register-branding p {
    font-size: 1.1rem;
    color: #f7f7f7;
    font-weight: 400;
    margin-bottom: 0;
  }
  .register-form-side {
    padding: 40px 32px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 600px;
    position: relative;
  }
  @media (max-width: 900px) {
    .register-grid {
      grid-template-columns: 1fr;
      min-height: unset;
    }
    .register-branding {
      border-radius: 18px 18px 0 0;
      min-height: 190px;
      padding: 24px 16px;
    }
    .register-form-side {
      min-height: unset;
      padding: 24px 16px;
    }
    .register-branding .logo-img {
      width: 180px;
      height: 56px;
      margin-bottom: 12px;
    }
    .register-branding h1 {
      font-size: 1.5rem;
    }
    .register-branding p {
      font-size: 0.95rem;
    }
    .register-form-side h2 {
      font-size: 1.35rem;
      margin-bottom: 1rem !important;
    }
    .hash-ornament {
      display: none;
    }
  }

  @media (max-width: 576px) {
    body {
      align-items: flex-start;
    }
    .register-container-wide {
      min-height: auto;
      padding: 8px;
    }
    .register-grid {
      border-radius: 12px;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.14);
    }
    .register-branding {
      min-height: 150px;
      border-radius: 12px 12px 0 0;
      padding: 16px 12px;
    }
    .register-form-side {
      padding: 18px 12px;
    }
    .input-group {
      margin-bottom: 14px;
    }
    .input-group label {
      position: static;
      display: block;
      margin-bottom: 6px;
      padding: 0;
      background: transparent;
      font-size: 13px;
      color: #444;
    }
    .input-group input,
    .form-control {
      min-height: 44px;
      font-size: 14px;
      padding: 10px 12px;
    }
    .login-btn {
      min-height: 46px;
      font-size: 15px;
    }
    .form-check-label,
    .form-text,
    .text-center {
      font-size: 13px;
    }
    #termsModal .modal-dialog,
    #otpModal .modal-dialog {
      margin: 10px;
    }
  }
</style>
<script>
// Password strength status
const passwordInput = document.getElementById('password');
const strengthMessage = document.getElementById('strengthMessage');
passwordInput.addEventListener('input', () => {
    const val = passwordInput.value;
    let strength = 0;
    if (val.length >= 8) strength++;
    if (/[A-Z]/.test(val)) strength++;
    if (/[a-z]/.test(val)) strength++;
    if (/[0-9]/.test(val)) strength++;
    if (/[^A-Za-z0-9]/.test(val)) strength++; // simbol
    if (strength <= 2) {
        strengthMessage.textContent = 'Weak';
        strengthMessage.style.color = 'red';
    } else if (strength === 3 || strength === 4) {
        strengthMessage.textContent = 'Medium';
        strengthMessage.style.color = 'orange';
    } else if (strength === 5) {
        strengthMessage.textContent = 'Strong';
        strengthMessage.style.color = 'green';
    } else {
        strengthMessage.textContent = '';
    }
});

// JS validation for form
const registerForm = document.getElementById('registerForm');
registerForm.addEventListener('submit', function(e) {
  // Username: no spaces
  const username = document.getElementById('nama').value;
  if (/\s/.test(username)) {
    alert('Username tidak boleh ada spasi!');
    e.preventDefault();
    return false;
  }
  // WhatsApp: must start with 62 and only digits
  const nowa = document.getElementById('nowa').value;
  if (!/^62\d{8,}$/.test(nowa)) {
    alert('Nomor WhatsApp harus diawali 62 dan minimal 10 digit!');
    e.preventDefault();
    return false;
  }
  // Password: min 8, huruf besar, kecil, angka, simbol
  const password = passwordInput.value;
  let passStrength = 0;
  if (password.length >= 8) passStrength++;
  if (/[A-Z]/.test(password)) passStrength++;
  if (/[a-z]/.test(password)) passStrength++;
  if (/[0-9]/.test(password)) passStrength++;
  if (/[^A-Za-z0-9]/.test(password)) passStrength++;
  if (passStrength < 3) {
    alert('Password terlalu lemah! Minimal 8 karakter, kombinasi huruf besar, kecil, angka, dan simbol.');
    e.preventDefault();
    return false;
  }
  // Password match
  const password2 = document.getElementById('password2').value;
  if (password !== password2) {
    alert('Password tidak cocok!');
    e.preventDefault();
    return false;
  }
});
</script>










<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function previewLogo(input) {
  const wrap = document.getElementById('logoPreviewWrap');
  const img  = document.getElementById('logoPreviewImg');
  const ph   = document.getElementById('logoPreviewPlaceholder');
  const fn   = document.getElementById('logoFileName');
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      img.src = e.target.result;
      img.style.display = 'block';
      ph.style.display  = 'none';
      wrap.style.borderColor = '#0d6efd';
      wrap.style.borderStyle = 'solid';
    };
    reader.readAsDataURL(input.files[0]);
    fn.textContent = input.files[0].name;
  } else {
    img.style.display = 'none';
    ph.style.display  = 'block';
    wrap.style.borderColor = '#ccc';
    wrap.style.borderStyle = 'dashed';
    fn.textContent = '';
  }
}
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Modal OTP -->
<div class="modal fade" id="otpModal" tabindex="-1" aria-labelledby="otpModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="">
        <div class="modal-header">
          <h5 class="modal-title" id="otpModalLabel">Verifikasi OTP</h5>
        </div>
        <div class="modal-body">
          <p>Masukkan kode OTP yang dikirim ke WhatsApp Anda.</p>

          <input type="hidden" name="nama" value="<?= htmlspecialchars($nama) ?>">
          <input type="hidden" name="nowa" value="<?= htmlspecialchars($nowa) ?>">
          <input type="hidden" name="password" value="<?= htmlspecialchars($password1) ?>">
          <input type="hidden" name="password2" value="<?= htmlspecialchars($password2) ?>">
          <input type="hidden" name="otpdefault" value="<?= htmlspecialchars($otpdefault) ?>">

          <div class="form-group">
            <input type="number" class="form-control" name="otprespone" placeholder="Kode OTP" required>
          </div>

          <div class="text-center mt-2">
            <span id="timerOtp" class="text-danger fw-bold"></span><br>
            <button type="button" class="btn btn-link p-0 mt-2" onclick="location.reload()">Kirim Ulang OTP</button>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="daftar" class="btn btn-success">Verifikasi</button>
        </div>
      </form>
    </div>
  </div>
</div>



<?php if (!empty($otpdefault) && empty($otprespone)) : ?>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      var myModal = new bootstrap.Modal(document.getElementById('otpModal'));
      myModal.show();

      // Timer OTP
      let expiredAt = <?= $_SESSION['otp_expired'] ?? time() + $otp_expiry_seconds ?>;
      let interval = setInterval(function() {
        let now = Math.floor(Date.now() / 1000);
        let remaining = expiredAt - now;

        if (remaining > 0) {
          let minutes = Math.floor(remaining / 60);
          let seconds = remaining % 60;
          document.getElementById('timerOtp').innerText = "Kode OTP kedaluwarsa dalam " + minutes + "m " + seconds + "d";
        } else {
          document.getElementById('timerOtp').innerText = "Kode OTP telah kedaluwarsa!";
          clearInterval(interval);
        }
      }, 1000);
    });
  </script>
<?php endif; ?>