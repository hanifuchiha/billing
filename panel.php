<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
$file = "waapi.txt";

// Proses simpan
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $waapi = trim($_POST['waapi']);
    $namebot = trim($_POST['namebot']);
    $password = trim($_POST['password']);

    // Simpan semua ke file (dipisah newline)
    $data = "waapi=$waapi\nnamebot=$namebot\npassword=$password";
    file_put_contents($file, $data);
    $message = "✅ Data berhasil disimpan!";
}

// Ambil data jika file ada
$waapi = $namebot = $password = "";
if (file_exists($file)) {
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (substr($line, 0, 6) === "waapi=") $waapi = substr($line, 6);
        if (substr($line, 0, 8) === "namebot=") $namebot = substr($line, 8);
        if (substr($line, 0, 9) === "password=") $password = substr($line, 9);
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Panel Konfigurasi BOT WA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container mt-5">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                ⚙️ Panel Konfigurasi BOT WhatsApp
            </div>
            <div class="card-body">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>

                <form method="post">
                    <div class="mb-3">
                        <label for="waapi" class="form-label">WA API</label>
                        <input type="text" class="form-control" id="waapi" name="waapi" value="<?= htmlspecialchars($waapi) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="namebot" class="form-label">Nama Bot</label>
                        <input type="text" class="form-control" id="namebot" name="namebot" value="<?= htmlspecialchars($namebot) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="text" class="form-control" id="password" name="password" value="<?= htmlspecialchars($password) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-success">💾 Simpan Konfigurasi</button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>