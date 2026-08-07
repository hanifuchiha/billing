<?php
// ==== KONFIGURASI DATABASE ====
$config_file = __DIR__ . '/../config.json';
if (!file_exists($config_file)) {
    die("❌ File konfigurasi tidak ditemukan: $config_file");
}

$config = json_decode(file_get_contents($config_file), true);
if (!$config) {
    die("❌ Gagal membaca isi config.json");
}

$conn = mysqli_connect($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
if (!$conn) {
    die("❌ Gagal konek database: " . mysqli_connect_error());
}
mysqli_set_charset($conn, "utf8mb4");

// ==== AMBIL USER DARI DATABASE ====
$users = [];
$query = "SELECT USERNAME FROM user LIMIT 50"; // ambil maksimal 50 user
$result = mysqli_query($conn, $query);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row['USERNAME'];
    }
} else {
    die("Query error: " . mysqli_error($conn));
}

// ==== FILE CHAT ====
$chat_file = __DIR__ . '/chat_history.txt';
if (!file_exists($chat_file)) {
    file_put_contents($chat_file, ""); // buat otomatis jika belum ada
}

// ==== SIMPAN PESAN ====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $message  = trim($_POST['message'] ?? '');
    if ($username && $message) {
        $time = date('[Y-m-d H:i:s]');
        $line = "$time $username: $message" . PHP_EOL;
        file_put_contents($chat_file, $line, FILE_APPEND | LOCK_EX);
    }
    exit;
}

// ==== BACA PESAN ====
$messages = file($chat_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>💬 Community Chat</title>
<style>
html, body {
  height: 100%;
  width: 100%;
  margin: 0;
  padding: 0;
  background: #f0f2f5;
  font-family: Arial, sans-serif;
  overflow: hidden;
}

.chat-container {
  display: flex;
  flex-direction: column;
  height: 100vh;
  width: 100%;
  background: #fff;
}

.chat-header {
  background: linear-gradient(45deg, #6a11cb, #2575fc);
  color: white;
  padding: 12px;
  text-align: center;
  font-weight: bold;
}

.chat-box {
  flex: 1;
  overflow-y: auto;
  padding: 10px;
  display: flex;
  flex-direction: column;
  background: #f7f8fa;
}

.message {
  padding: 8px 12px;
  border-radius: 10px;
  margin: 5px 0;
  width: fit-content;
  max-width: 80%;
  word-wrap: break-word;
  animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(5px); }
  to { opacity: 1; transform: translateY(0); }
}

.message.self {
  background: #d1f1d1;
  align-self: flex-end;
  border-bottom-right-radius: 0;
}

.message.other {
  background: #e9e9eb;
  align-self: flex-start;
  border-bottom-left-radius: 0;
}

.chat-input {
  display: flex;
  border-top: 1px solid #ddd;
}

.chat-input select, 
.chat-input input {
  border: none;
  padding: 10px;
  outline: none;
  font-size: 14px;
}

.chat-input select {
  width: 30%;
  border-right: 1px solid #ccc;
  background: #fafafa;
}

.chat-input input[type="text"] {
  flex: 1;
  background: #fafafa;
}

.chat-input button {
  background: #2575fc;
  color: white;
  border: none;
  padding: 10px 15px;
  cursor: pointer;
}

.chat-input button:hover {
  background: #1b5ed9;
}

small {
  font-size: 10px;
  color: gray;
}
</style>
</head>
<body>

<div class="chat-container">
  

  <div class="chat-box" id="chat-box">
    <?php if (empty($messages)): ?>
      <p style="text-align:center;color:gray;">Belum ada pesan.</p>
    <?php endif; ?>

    <?php foreach ($messages as $line): ?>
      <?php
        preg_match('/\[(.*?)\]\s*(.*?):\s*(.*)/', $line, $m);
        $time = $m[1] ?? '';
        $user = $m[2] ?? 'Anon';
        $text = $m[3] ?? $line;
        $isSelf = isset($_COOKIE['chat_user']) && $_COOKIE['chat_user'] === $user;
      ?>
      <div class="message <?= $isSelf ? 'self' : 'other' ?>">
        <b><?= htmlspecialchars($user) ?></b><br>
        <?= htmlspecialchars($text) ?><br>
        <small><?= htmlspecialchars($time) ?></small>
      </div>
    <?php endforeach; ?>
  </div>

  <form class="chat-input" id="chat-form" method="post">
    <select hidden id="username" name="username" required>
      <option value="<?php echo $_GET['user'] ?>"><?php echo $GET['user'] ?></option>
  
     
    </select>
    <input type="text" name="message" id="message" placeholder="Ketik pesan..." required>
    <button type="submit">Kirim</button>
  </form>
</div>

<script>
const chatBox = document.getElementById('chat-box');
chatBox.scrollTop = chatBox.scrollHeight;

// Simpan user terakhir ke cookie
const select = document.getElementById('username');
if (document.cookie.includes('chat_user=')) {
  const savedUser = document.cookie.split('chat_user=')[1].split(';')[0];
  select.value = savedUser;
}

select.addEventListener('change', () => {
  document.cookie = "chat_user=" + select.value + "; path=/; max-age=31536000";
});

// Auto-refresh setiap 5 detik
setInterval(() => {
  fetch(window.location.href)
    .then(r => r.text())
    .then(html => {
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, 'text/html');
      document.getElementById('chat-box').innerHTML =
        doc.getElementById('chat-box').innerHTML;
      chatBox.scrollTop = chatBox.scrollHeight;
    });
}, 5000);

// Kirim pesan via AJAX
document.getElementById('chat-form').addEventListener('submit', e => {
  e.preventDefault();
  const formData = new FormData(e.target);
  const user = formData.get('username');
  document.cookie = "chat_user=" + user + "; path=/; max-age=31536000";

  fetch(window.location.href, { method: 'POST', body: formData })
    .then(() => {
      e.target.message.value = '';
      fetch(window.location.href)
        .then(r => r.text())
        .then(html => {
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, 'text/html');
          document.getElementById('chat-box').innerHTML =
            doc.getElementById('chat-box').innerHTML;
          chatBox.scrollTop = chatBox.scrollHeight;
        });
    });
});
</script>

</body>
</html>
