<?php
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

$pesan = "";
$success = false;

// Ambil token dari URL
$token = $_GET['token'] ?? '';

if (!$token) {
    $pesan = "❌ Token tidak ditemukan.";
} else {
    $sql = "SELECT user_id, expires_at FROM reset_tokens WHERE token = '" . mysqli_real_escape_string($conn, $token) . "' LIMIT 1";
    $res = mysqli_query($conn, $sql);

    if ($res && mysqli_num_rows($res) > 0) {
        $data = mysqli_fetch_assoc($res);
        $user_id = $data['user_id'];
        $expires_at = strtotime($data['expires_at']);

        // Ambil USERNAME dari tabel user
        $userData = mysqli_query($conn, "SELECT USERNAME FROM user WHERE id = '$user_id' LIMIT 1");
        $username = ($userData && mysqli_num_rows($userData) > 0)
            ? mysqli_fetch_assoc($userData)['USERNAME']
            : 'Tidak ditemukan';

        if ($expires_at < time()) {
            $pesan = "⏰ Token sudah kadaluarsa. Silakan minta reset ulang.";
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm'] ?? '';

            // Validasi kekuatan password
            if (strlen($password) < 8) {
                $pesan = "❌ Password minimal 8 karakter.";
            } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || 
                      !preg_match('/[0-9]/', $password) || !preg_match('/[\W]/', $password)) {
                $pesan = "⚠️ Password harus mengandung huruf besar, huruf kecil, angka, dan simbol.";
            } elseif ($password !== $confirm) {
                $pesan = "❌ Konfirmasi password tidak cocok.";
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $update = "UPDATE user SET PASWORD='$hashed' WHERE id='$user_id'";
                mysqli_query($conn, $update);
                mysqli_query($conn, "DELETE FROM reset_tokens WHERE token='" . mysqli_real_escape_string($conn, $token) . "'");
                $success = true;
                $pesan = "✅ Password untuk user <b>$username</b> berhasil direset. Silakan login kembali.";
            }
        }
    } else {
        $pesan = "❌ Token tidak valid.";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password</title>
<style>
  * { box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
  :root {
    --primary-color: <?= htmlspecialchars($config['extracted_primary_color'] ?? '#f68013') ?>;
    --secondary-color: <?= htmlspecialchars($config['extracted_secondary_color'] ?? '#f68012') ?>;
    --accent-color: <?= htmlspecialchars($config['extracted_accent_color'] ?? '#FFA726') ?>;
  }
  body {
    background: linear-gradient(135deg, var(--primary-color), var(--accent-color), #ffffff, color-mix(in srgb, var(--secondary-color) 65%, #4da1ff 35%), var(--secondary-color));
    background-size: 400% 400%;
    animation: gradient 15s ease infinite;
    height: 100vh; display: flex; justify-content: center; align-items: center;
  }
  @keyframes gradient { 0%{background-position:0% 50%} 50%{background-position:100% 50%} 100%{background-position:0% 50%} }
  .login-container {
    background: rgba(255,255,255,0.9); width: 380px; padding: 40px 30px;
    border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
  }
  h2 { text-align: center; margin-bottom: 20px; }
  .input-group { margin-bottom: 20px; position: relative; }
  .input-group input {
    width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px;
    font-size: 14px; transition: 0.3s;
  }
  .input-group input:focus { border-color: var(--primary-color); outline: none; }
  .strength {
    margin-top: 5px; font-size: 13px; font-weight: 600;
  }
  .strength.weak { color: red; }
  .strength.medium { color: orange; }
  .strength.strong { color: green; }
  .strength.very-strong { color: #0080ff; }
  .login-btn {
    width: 100%; padding: 12px;
    background: linear-gradient(to right, var(--primary-color), var(--accent-color));
    border: none; border-radius: 8px; color: white; font-size: 16px; font-weight: 600;
    cursor: pointer; transition: 0.3s;
  }
  .login-btn:hover { background: linear-gradient(to right, var(--accent-color), var(--primary-color)); }
  .alert { padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
  .alert-danger { background-color: #ffdddd; color: #ff0000; border: 1px solid #ffaaaa; }
  .alert-success { background-color: #ddffdd; color: #008000; border: 1px solid #aaffaa; }
  .hotspot-login-btn {
    display: inline-block; background: linear-gradient(to right, var(--secondary-color), var(--primary-color));
    color: white; padding: 10px 15px; border-radius: 8px; text-decoration: none;
  }
  .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
</style>
</head>
<body>
<div class="login-container">
  <h2>Reset Password</h2>

  <?php if ($pesan): ?>
    <div class="alert <?= $success ? 'alert-success' : 'alert-danger'; ?>"><?= $pesan; ?></div>
  <?php endif; ?>

  <?php if (!$success && $token && !preg_match('/kadaluarsa|tidak ditemukan|tidak valid/i', $pesan)): ?>
  <form method="POST">
    <!-- Kolom tampilkan username -->
    <div class="input-group">
      Username : 
      <input type="text" value="<?= htmlspecialchars($username ?? ''); ?>" readonly>
    </div>

    <div class="input-group">
      <input type="password" name="password" id="password" placeholder="Password Baru" required>
      <div id="strengthMessage" class="strength"></div>
    </div>
    <div class="input-group">
      <input type="password" name="confirm" placeholder="Konfirmasi Password" required>
    </div>
    <button type="submit" name="reset" class="login-btn">🔒 Simpan Password</button>
  </form>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alternative-login" style="text-align:center;">
      <a href="index.php" class="hotspot-login-btn">🔑 Kembali ke Login</a>
    </div>
  <?php endif; ?>

  <div class="footer">&copy; <?= date('Y'); ?> FiberQ / Quenby Technical Sejahtera</div>
</div>

<script>
const passwordInput = document.getElementById("password");
const strengthMessage = document.getElementById("strengthMessage");

passwordInput.addEventListener("input", function() {
  const val = passwordInput.value;
  let strength = 0;

  if (val.length >= 8) strength++;
  if (/[A-Z]/.test(val)) strength++;
  if (/[a-z]/.test(val)) strength++;
  if (/[0-9]/.test(val)) strength++;
  if (/[\W]/.test(val)) strength++;

  if (strength <= 2) {
    strengthMessage.textContent = "🔴 Password Lemah";
    strengthMessage.className = "strength weak";
  } else if (strength === 3) {
    strengthMessage.textContent = "🟠 Password Sedang";
    strengthMessage.className = "strength medium";
  } else if (strength === 4) {
    strengthMessage.textContent = "🟢 Password Kuat";
    strengthMessage.className = "strength strong";
  } else if (strength === 5) {
    strengthMessage.textContent = "🔵 Password Sangat Kuat";
    strengthMessage.className = "strength very-strong";
  } else {
    strengthMessage.textContent = "";
  }
});
</script>
</body>
</html>
