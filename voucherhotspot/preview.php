<?php
require '../cek-sesi.php';

// ================= Ambil data POST =================
$host = $_POST['ip'] ?? '';
$user = $_POST['us'] ?? 'user123';
$pass = $_POST['ps'] ?? 'pass123';
$login = $_POST['login'] ?? 'http://192.168.88.1/login';
$jumlah = intval($_POST['jumlah'] ?? 3);
$packages = $_POST['packages'] ?? 'Paket|1|desc|10000|2000|Unlimited';
$uptime = $_POST['uptime'] ?? '1d';
$ratelimit = $_POST['ratelimit'] ?? 'Unlimited';
$prefix = $_POST['prefix'] ?? '';
$radius = $_POST['radius'] ?? '';
$mode = $_POST['mode'] ?? 'number';
$panjang = intval($_POST['length'] ?? 8);
$desain = $_POST['desain'] ?? 'default';

// ================= Fungsi =================


function generate_random($length = 8, $mode = 'mixed')
{
    $chars = '';
    if ($mode === 'number') $chars = '0123456789';
    elseif ($mode === 'letter') $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'mixedbesar') $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'mixedkecil') $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
    elseif ($mode === 'mixedbesarkecil') $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'letterbesar') $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    elseif ($mode === 'letterkecil') $chars = 'abcdefghijklmnopqrstuvwxyz';
    elseif ($mode === 'letterbesarkecil') $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    $result = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, $max)];
    }

    return $result;
}

// ================= Tampilkan Loading Card =================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Loading Voucher</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.card-loading { max-width: 400px; width: 100%; }
body {
  background-color: #f1f5f9;
  color: #0f172a;
}
.card-loading .card-header {
  background: #0ea5e9 !important;
  color: #ffffff !important;
}
.card-loading .card-header h5,
.card-loading .card-body,
.card-loading .card-body p,
.card-loading .card-footer {
  opacity: 1 !important;
}

@media (prefers-color-scheme: dark) {
  body {
    background-color: #020617 !important;
    color: #e2e8f0 !important;
  }
  .card-loading {
    background-color: #0f172a !important;
    border-color: rgba(148, 163, 184, 0.25) !important;
  }
  .card-loading .card-header {
    background: #1e293b !important;
    color: #e2e8f0 !important;
  }
  .card-loading .card-header h5,
  .card-loading .card-body,
  .card-loading .card-body p,
  .card-loading .card-footer {
    color: #e2e8f0 !important;
  }
  .card-loading .card-footer.text-muted {
    color: #cbd5e1 !important;
  }
}
</style>
</head>
<body>

<div class="d-flex justify-content-center align-items-center vh-100">
  <div class="card text-center shadow-sm border-info card-loading">
    <div class="card-header bg-info text-white">
      <h5 class="card-title mb-0">Memuat...</h5>
    </div>
    <div class="card-body">
      <div class="spinner-border text-info mb-3" role="status">
        <span class="visually-hidden">Loading...</span>
      </div>
      <p class="card-text">Harap tunggu, sedang memproses voucher Anda.</p>
    </div>
    <div class="card-footer text-muted">Loading otomatis...</div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// ================= Proses PHP setelah HTML ditampilkan =================

// Generate voucher
$list_voucher = [];
for ($i = 0; $i < $jumlah; $i++) {
    $usernamevc = generate_random($panjang, $mode);
    if ($prefix != '') $usernamevc = $prefix . $usernamevc;
    $password = $usernamevc;
    $list_voucher[] = ['user' => $usernamevc, 'pass' => $password];
}

// Simpan data voucher
$data = $_POST;
$data['timestamp'] = date('Y-m-d H:i:s');
$data['vouchers'] = $list_voucher;

// Nama file voucher
$random_seq = mt_rand(1000, 9999);
$namafile = "voucher-{$random_seq}-data.json";
if (!is_dir('voucher')) mkdir('voucher', 0777, true);
file_put_contents("voucher/$namafile", json_encode($data, JSON_PRETTY_PRINT));

// ================= Redirect otomatis ke halaman cetak =================
echo "<script>window.location.href='preview-done.php?file=$namafile';</script>";
exit;
?>
