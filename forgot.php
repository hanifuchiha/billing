<?php
session_start();
include 'koneksidb.php';

$config = [];
$config_path = __DIR__ . '/config.json';
if (file_exists($config_path)) {
  $config_raw = @file_get_contents($config_path);
  $config_arr = json_decode((string)$config_raw, true);
  if (is_array($config_arr)) {
    $config = $config_arr;
  }
}

$otp_signup_config_path = __DIR__ . '/otp_signup_config.json';
$otp_signup_waapi_path = __DIR__ . '/otp_signup_waapi.txt';
$legacy_waapi_path = __DIR__ . '/waapi.txt';

$forgot_message_template = "Halo {name}, permintaan reset kata sandi diterima. Klik link berikut untuk reset password:\n{reset_link}\nToken berlaku 1 jam.";
$forgot_random_greeting_enabled = false;
$forgot_random_greetings = ['Halo', 'Hai', 'Assalamualaikum'];

if (file_exists($otp_signup_config_path)) {
  $otp_cfg_raw = @file_get_contents($otp_signup_config_path);
  $otp_cfg = json_decode((string)$otp_cfg_raw, true);
  if (is_array($otp_cfg)) {
    $forgot_template_candidate = trim((string)($otp_cfg['forgot_message_template'] ?? ''));
    if ($forgot_template_candidate !== '') {
      $forgot_message_template = $forgot_template_candidate;
    }

    $forgot_random_greeting_enabled = !empty($otp_cfg['forgot_random_greeting_enabled']);
    if (isset($otp_cfg['forgot_random_greetings']) && is_array($otp_cfg['forgot_random_greetings'])) {
      $forgot_greeting_candidates = [];
      foreach ($otp_cfg['forgot_random_greetings'] as $forgot_greeting_item) {
        $forgot_greeting_item = trim((string)$forgot_greeting_item);
        if ($forgot_greeting_item !== '') {
          $forgot_greeting_candidates[] = $forgot_greeting_item;
        }
      }
      if (!empty($forgot_greeting_candidates)) {
        $forgot_random_greetings = $forgot_greeting_candidates;
      }
    }
  }
}

function read_waapi_credentials_from_file($path) {
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

$forgot_waapi_data = read_waapi_credentials_from_file($otp_signup_waapi_path);
if ($forgot_waapi_data['waapi'] === '' && $forgot_waapi_data['namebot'] === '' && $forgot_waapi_data['password'] === '') {
  $forgot_waapi_data = read_waapi_credentials_from_file($legacy_waapi_path);
}

$forgot_waapi = $forgot_waapi_data['waapi'];
$forgot_namebot = $forgot_waapi_data['namebot'];
$forgot_passwordbot = $forgot_waapi_data['password'];

/**
 * Validasi input email atau WA
 */
function validate_contact($method, $contact) {
    $contact = trim($contact);
    if ($method === 'email') {
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            return "❌ Format email tidak valid.";
        }
    } else {
        $contact = preg_replace('/\D+/', '', $contact);
        if (strlen($contact) < 8) {
            return "❌ Masukkan nomor WA yang valid (contoh: 62812xxxx).";
        }
    }
    return ''; // valid
}

/**
 * Cari user berdasarkan email atau WA
 */
function find_user($conn, $method, $contact) {
    $contact = mysqli_real_escape_string($conn, $contact);
    $sql = $method === 'email' 
        ? "SELECT id, USERNAME, domain AS email, NOWA FROM user WHERE domain='$contact'"
        : "SELECT id, USERNAME, domain AS email, NOWA FROM user WHERE NOWA='$contact'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0) ? mysqli_fetch_assoc($res) : null;
}

/**
 * Buat token reset password dan simpan ke database
 */
function create_reset_token($conn, $user_id) {
    $token = bin2hex(random_bytes(16));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $insert_token = "INSERT INTO reset_tokens (user_id, token, expires_at) VALUES ('$user_id', '$token', '$expires_at')";
    mysqli_query($conn, $insert_token);
    return $token;
}

/**
 * Kirim instruksi reset via email
 */
function send_reset_email($user_email, $user_name, $reset_link, &$error_info = '') {
    $to = $user_email;
    $subject = "Reset Kata Sandi - Permintaan";
  $display_name = trim((string)$user_name) !== '' ? $user_name : 'Pelanggan';
  $message = "Halo {$display_name},<br><br>Kami menerima permintaan reset kata sandi.<br>Silakan klik link berikut untuk mereset password kamu:<br><br><a href=\"{$reset_link}\">{$reset_link}</a><br><br>Token berlaku 1 jam.";

  include_once "notifbot/phpmailer/classes/class.phpmailer.php";
  include_once "notifbot/phpmailer/classes/class.smtp.php";

  $smtp_user = "helpdesk@quenbytekniksejahtera.com";
  $smtp_pass = "helpdeskqts";

  $smtp_attempts = [
    ['host' => 'mail.quenbytekniksejahtera.com', 'port' => 25,  'secure' => '',    'auth' => false],
    ['host' => 'mail.quenbytekniksejahtera.com', 'port' => 587, 'secure' => 'tls', 'auth' => true],
    ['host' => 'mail.quenbytekniksejahtera.com', 'port' => 465, 'secure' => 'ssl', 'auth' => true],
    ['host' => '127.0.0.1',                     'port' => 25,  'secure' => '',    'auth' => false],
    ['host' => 'localhost',                     'port' => 25,  'secure' => '',    'auth' => false],
  ];

  $error_logs = [];
  $last_error_raw = '';
  foreach ($smtp_attempts as $cfg) {
    $mail = new PHPMailer;
    $mail->IsSMTP();
    $mail->Host = $cfg['host'];
    $mail->Port = (int)$cfg['port'];
    $mail->SMTPAuth = (bool)$cfg['auth'];
    $mail->SMTPSecure = $cfg['secure'];
    $mail->SMTPDebug = 0;
    $mail->Timeout = 15;

    if ($mail->SMTPAuth) {
      $mail->Username = $smtp_user;
      $mail->Password = $smtp_pass;
    }

    $mail->SetFrom($smtp_user, "QTS");
    $mail->Subject = $subject;
    $mail->AddAddress($to, $display_name);
    $mail->MsgHTML($message);

    $smtp_raw_echo = '';
    ob_start();
    $send_ok = $mail->Send();
    $smtp_raw_echo = trim((string)ob_get_clean());

    if ($send_ok) {
      return true;
    }

    $combined_error = trim(trim((string)$mail->ErrorInfo) . ' ' . $smtp_raw_echo);
    $last_error_raw = $combined_error;
    $error_logs[] = date('Y-m-d H:i:s')
      . " | host={$cfg['host']}:{$cfg['port']} secure={$cfg['secure']} auth=" . ($cfg['auth'] ? '1' : '0')
      . " | error=" . ($combined_error !== '' ? $combined_error : 'unknown smtp error');
  }

  if (!empty($error_logs)) {
    @file_put_contents(__DIR__ . '/smtp_forgot_error.log', implode("\n", $error_logs) . "\n", FILE_APPEND);
  }

  $error_info = 'Sistem email sementara tidak dapat dihubungi.';
  if ($last_error_raw !== '') {
    $err_lc = strtolower(strip_tags($last_error_raw));
    if (strpos($err_lc, 'could not connect to smtp host') !== false || strpos($err_lc, 'connect host') !== false) {
      $error_info = 'Koneksi ke server SMTP gagal. Coba lagi beberapa menit, atau gunakan metode WhatsApp.';
    } elseif (strpos($err_lc, 'authenticate') !== false || strpos($err_lc, 'auth') !== false) {
      $error_info = 'Autentikasi SMTP gagal. Hubungi admin untuk cek akun email pengirim.';
    } elseif (strpos($err_lc, 'timed out') !== false || strpos($err_lc, 'timeout') !== false) {
      $error_info = 'Koneksi SMTP timeout. Pastikan jaringan server stabil lalu coba lagi.';
    }
  }

  return false;

}

/**
 * Kirim instruksi reset via WhatsApp (menggunakan WA API)
 */
function send_reset_whatsapp($nowa, $user_name, $reset_link) {
    global $forgot_message_template, $forgot_waapi, $forgot_namebot, $forgot_passwordbot;
    global $forgot_random_greeting_enabled, $forgot_random_greetings;

    $selected_greeting = '';
    if ($forgot_random_greeting_enabled && !empty($forgot_random_greetings)) {
      $selected_greeting = $forgot_random_greetings[array_rand($forgot_random_greetings)];
    }

    $text = str_replace(
      ['{name}', '{reset_link}', '{greeting}'],
      [$user_name, $reset_link, (string)$selected_greeting],
      $forgot_message_template
    );

    if ($selected_greeting !== '' && strpos($forgot_message_template, '{greeting}') === false) {
      $text = $selected_greeting . "\n" . $text;
    }

    // Jika ada fungsi send_whatsapp bawaan
    if (is_callable('send_whatsapp')) {
      $sent = call_user_func('send_whatsapp', $nowa, $text);
        if ($sent) return true;
    }

    // fallback log & API
    file_put_contents(__DIR__ . "/wa_log.txt", date('Y-m-d H:i:s') . " -> WA to {$nowa}: {$text}\n", FILE_APPEND);

    if ($forgot_waapi === '' || $forgot_namebot === '' || $forgot_passwordbot === '') {
      file_put_contents(
        __DIR__ . "/wa_log.txt",
        date('Y-m-d H:i:s') . " -> WA config empty for forgot flow\n",
        FILE_APPEND
      );
      return false;
    }

    $phone = "$nowa@s.whatsapp.net";

    $data = ["phone" => $phone, "message" => $text];
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => rtrim($forgot_waapi, '/') . "/send/message",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
      CURLOPT_USERPWD => "$forgot_namebot:$forgot_passwordbot",
        CURLOPT_TIMEOUT => 10
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

// ==================== HANDLE FORM ====================
$pesan_lupa = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot'])) {
    $method = $_POST['method'] ?? 'email';
    $contact = $_POST['contact'] ?? '';

    $pesan_lupa = validate_contact($method, $contact);

    if (!$pesan_lupa) {
        $user = find_user($conn, $method, $contact);
        if ($user) {
            $token = create_reset_token($conn, $user['id']);
            $reset_link = "https://{$_SERVER['HTTP_HOST']}/crm/billing/reset_password.php?token=$token";
          $user_name = $user['USERNAME'] ?? 'Pelanggan';
          $user_nowa = $user['NOWA'] ?? $contact;

            if ($method === 'email') {
              $smtp_error_info = '';
              $pesan_lupa = send_reset_email($user['email'], $user_name, $reset_link, $smtp_error_info)
                    ? "✅ Instruksi reset telah dikirim ke email: {$user['email']}."
                : "❌ Gagal mengirim email reset. " . ($smtp_error_info !== '' ? $smtp_error_info : 'Hubungi admin.');
            } else {
            $pesan_lupa = send_reset_whatsapp($user_nowa, $user_name, $reset_link)
              ? "✅ Instruksi reset telah dikirim ke WhatsApp: {$user_nowa}."
                    : "❌ Gagal mengirim WA. Hubungi admin.";
            }

        } else {
            $pesan_lupa = "⏰ Data tidak ditemukan. Pastikan email atau nomor WA yang kamu masukkan benar.";
        }
    }
}
?>

<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">



<style>
  :root {
    --primary-color: <?= htmlspecialchars($config['extracted_primary_color'] ?? '#f68013') ?>;
    --secondary-color: <?= htmlspecialchars($config['extracted_secondary_color'] ?? '#f68012') ?>;
    --accent-color: <?= htmlspecialchars($config['extracted_accent_color'] ?? '#FFA726') ?>;
  }

  /* Alternative Login Styles */
  .alternative-login {
    margin-top: 20px;
    text-align: center;
  }

  .hotspot-login-btn {
    display: inline-block;
    padding: 10px 15px;
    background: linear-gradient(to right, var(--secondary-color), var(--primary-color));
    color: white;
    border-radius: 8px;
    text-decoration: none;
    margin: 10px 0;
    transition: all 0.3s;
  }

  .hotspot-login-btn:hover {
    background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
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
  }

  .signup-link a:hover {
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

  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  }

  body {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color), #ffffff, color-mix(in srgb, var(--secondary-color) 65%, #4da1ff 35%), var(--secondary-color));
    background-size: 400% 400%;
    animation: gradient 15s ease infinite;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    overflow-x: hidden;
    overflow-y: auto;
    padding: 12px;
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
  }

  .login-container {
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
    color: var(--primary-color);
    font-size: 28px;
    margin-bottom: 5px;
  }

  .logo p {
    color: var(--secondary-color);
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
    box-shadow: 0 0 0 3px rgba(255, 140, 0, 0.2);
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
    color: var(--secondary-color);
    text-decoration: none;
  }

  .forgot-password a:hover {
    text-decoration: underline;
  }

  .login-btn {
    width: 100%;
    padding: 12px;
    background: linear-gradient(to right, var(--primary-color), var(--accent-color));
    border: none;
    border-radius: 8px;
    color: white;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
  }

  .login-btn:hover {
    background: linear-gradient(to right, var(--accent-color), var(--primary-color));
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
<style>
  body {
    background: linear-gradient(135deg,
        var(--primary-color),
        var(--accent-color),
        #ffffff,
        color-mix(in srgb, var(--secondary-color) 65%, #4da1ff 35%),
        var(--secondary-color));
    background-size: 400% 400%;
    animation: gradient 15s ease infinite;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    overflow-x: hidden;
    overflow-y: auto;
    padding: 12px;
  }
</style>


<style>
.forgot-card {
  max-width: 420px;
  width: min(420px, 100%);
  margin: 18px auto;
  background: linear-gradient(180deg, #ffffff 0%, #f7f9ff 100%);
  border-radius: 12px;
  padding: 18px;
  box-shadow: 0 6px 18px rgba(12, 20, 40, 0.08);
  font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
}
.forgot-card h3 { margin-bottom: 12px; text-align:center; }
.forgot-card .small { font-size: 13px; color: #666; margin-bottom:10px; text-align:center;}
.forgot-card .input-group { margin-bottom:10px; }
.forgot-card input.form-control { padding:10px 12px; border-radius:8px; border:1px solid #e3e6ef; width:100%;}
.forgot-card .method-toggle { display:flex; gap:8px; justify-content:center; margin-bottom:10px; }
.forgot-card .method-toggle button { padding:8px 12px; border-radius:8px; border:1px solid #dbe1f0; background:#fff; cursor:pointer;}
.forgot-card .method-toggle button.active { background: linear-gradient(90deg,#6f7dff,#6fd1ff); color:#fff; border: none; }
.forgot-card .note { font-size:12px; color:#777; text-align:center; margin-top:8px; }
.forgot-card .method-toggle button.active { background: linear-gradient(90deg,var(--primary-color),var(--secondary-color)); color:#fff; border: none; }
.forgot-card .btn { width:100%; padding:10px 12px; border-radius:8px; border:none; cursor:pointer; background:linear-gradient(90deg,var(--primary-color),var(--secondary-color)); color:#fff; font-weight:600; }
.alert-inline {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  margin-bottom: 12px;
  font-size: 14px;
  line-height: 1.45;
  border: 1px solid transparent;
}
.alert-inline .alert-icon {
  font-size: 16px;
  line-height: 1;
  margin-top: 2px;
}
.alert-inline .alert-text {
  flex: 1;
}
.alert-inline .alert-title {
  font-weight: 700;
  margin-bottom: 2px;
}
.alert-inline.alert-success {
  background: #e8f9ef;
  color: #14532d;
  border-color: #bfe9ce;
}
.alert-inline.alert-danger {
  background: #ffecec;
  color: #7f1d1d;
  border-color: #ffb8b8;
}

@media (max-width: 768px) {
  body {
    align-items: flex-start;
    padding: 10px;
  }

  .forgot-card {
    margin: 10px auto;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 5px 16px rgba(12, 20, 40, 0.1);
  }

  .forgot-card h3 {
    font-size: 1.25rem;
  }

  .forgot-card .small {
    font-size: 12px;
  }

  .forgot-card .method-toggle {
    gap: 6px;
  }

  .forgot-card .method-toggle button {
    flex: 1;
    min-height: 42px;
    padding: 9px 10px;
    font-size: 14px;
  }

  .forgot-card input.form-control,
  .forgot-card .btn {
    min-height: 44px;
    font-size: 14px;
  }
}

@media (max-width: 480px) {
  body {
    padding: 8px;
  }

  .forgot-card {
    margin: 8px auto;
    padding: 14px 12px;
    border-radius: 9px;
  }

  .forgot-card h3 {
    margin-bottom: 10px;
    font-size: 1.15rem;
  }

  .forgot-card .small,
  .forgot-card .note {
    font-size: 12px;
  }

  .alert-inline {
    padding: 10px 11px;
    gap: 8px;
    font-size: 13px;
  }
}
</style>

<div class="forgot-card">
  <h3>Lupa Password</h3>
  <div class="small">Masukkan <strong>Email</strong> atau <strong>No. WhatsApp</strong> untuk menerima tautan reset.</div>

  <?php if (!empty($pesan_lupa)): ?>
    <?php
      $is_error_msg = (strpos($pesan_lupa, '❌') !== false) || (strpos($pesan_lupa, '⏰') !== false);
      $alert_class = $is_error_msg ? 'alert-danger' : 'alert-success';
      $alert_icon = $is_error_msg ? '⚠️' : '✅';
      $alert_title = $is_error_msg ? 'Permintaan Belum Berhasil' : 'Permintaan Berhasil';
      $alert_text = trim(str_replace(['✅','❌','⏰'], '', $pesan_lupa));
    ?>
    <div class="alert-inline <?= $alert_class ?>">
      <div class="alert-icon"><?= $alert_icon ?></div>
      <div class="alert-text">
        <div class="alert-title"><?= $alert_title ?></div>
        <div><?= htmlspecialchars($alert_text) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="method-toggle" role="tablist">
    <button type="button" id="btn-email" class="active">Email</button>
    <button type="button" id="btn-wa">WhatsApp</button>
  </div>

  <form method="POST" action="">
    <input type="hidden" name="method" id="method" value="email">

    <div class="input-group">
      <input type="text" name="contact" id="contact" class="form-control" placeholder="email@contoh.com" required>
    </div>

    <div class="note">Kami akan mengirim tautan reset yang valid selama 1 jam.</div>

    <button type="submit" name="forgot" class="btn">Kirim Tautan Reset</button>
  </form>
</div>

<script>
document.getElementById('btn-email').addEventListener('click', function(){
  document.getElementById('method').value = 'email';
  document.getElementById('contact').placeholder = 'email@contoh.com';
  this.classList.add('active'); document.getElementById('btn-wa').classList.remove('active');
});
document.getElementById('btn-wa').addEventListener('click', function(){
  document.getElementById('method').value = 'wa';
  document.getElementById('contact').placeholder = '62xxxxxx (contoh: 62812...)';
  this.classList.add('active'); document.getElementById('btn-email').classList.remove('active');
});
</script>










<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

