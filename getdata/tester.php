<?php
/**
 * tester.php
 *
 * Tester koneksi server (versi WEB / diakses via browser).
 * Taruh file ini di folder getdata/ lalu akses lewat URL, contoh:
 *
 *   https://broadbandairlink.com/crm/billing/getdata/tester.php?host=103.46.187.242&port=8787
 *
 * Kalau parameter tidak diisi, default host=103.46.187.242 port=8787.
 *
 * Tujuan: memastikan apakah IP:PORT bisa dijangkau dari SERVER
 * (bukan dari laptop/komputer Anda), sebelum logikanya dipakai
 * di cron job yang mengisi tabel server_sla_logs.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: text/html; charset=utf-8');

$host = isset($_GET['host']) ? trim((string)$_GET['host']) : '103.46.187.242';
$port = isset($_GET['port']) ? (int)$_GET['port'] : 8787;
$timeoutSec = isset($_GET['timeout']) ? (float)$_GET['timeout'] : 5.0;

if ($timeoutSec <= 0) {
    $timeoutSec = 5.0;
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$results = [];

// 1. Resolve DNS
$isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
$resolvedIp = $isIp ? $host : gethostbyname($host);
$resolveOk = $isIp || ($resolvedIp !== $host);

// 2. fsockopen
$startFs = microtime(true);
$errnoFs = 0;
$errstrFs = '';
$fp = @fsockopen($host, $port, $errnoFs, $errstrFs, $timeoutSec);
$elapsedFsMs = round((microtime(true) - $startFs) * 1000, 2);
$fsockOk = (bool)$fp;
if ($fp) {
    fclose($fp);
}

// 3. stream_socket_client
$startSs = microtime(true);
$errnoSs = 0;
$errstrSs = '';
$address = "tcp://{$host}:{$port}";
$client = @stream_socket_client(
    $address,
    $errnoSs,
    $errstrSs,
    $timeoutSec,
    STREAM_CLIENT_CONNECT
);
$elapsedSsMs = round((microtime(true) - $startSs) * 1000, 2);
$streamOk = (bool)$client;
if ($client) {
    fclose($client);
}

// 4. ICMP ping (kalau exec diizinkan)
$pingOutput = [];
$pingReturnVar = null;
$execAvailable = function_exists('exec');
if ($execAvailable) {
    $pingHostEscaped = escapeshellarg($host);
    @exec("ping -c 3 -W 2 $pingHostEscaped 2>&1", $pingOutput, $pingReturnVar);
}

// Ringkasan
$isOnline = $fsockOk || $streamOk;
$responseMs = $fsockOk ? (int)round($elapsedFsMs) : ($streamOk ? (int)round($elapsedSsMs) : null);

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Tester Konektivitas Server SLA</title>
<style>
  body { font-family: Consolas, Monaco, monospace; background:#0f172a; color:#e2e8f0; padding: 24px; max-width: 900px; margin: 0 auto; }
  h1 { font-size: 1.3em; color:#f1f5f9; }
  .box { background:#111827; border:1px solid #334155; border-radius:8px; padding:16px; margin-bottom:16px; }
  .ok { color:#4ade80; font-weight:bold; }
  .fail { color:#f87171; font-weight:bold; }
  table { width:100%; border-collapse: collapse; margin-top:8px; }
  td, th { border:1px solid #334155; padding:6px 10px; text-align:left; font-size: 0.92em; }
  th { background:#1e293b; }
  pre { background:#0b1220; padding:10px; border-radius:6px; overflow-x:auto; font-size:0.85em; }
  form { margin-bottom:20px; }
  input { background:#1e293b; border:1px solid #334155; color:#e2e8f0; padding:6px 10px; border-radius:6px; margin-right:8px; }
  button { background:#2563eb; color:#fff; border:none; padding:6px 14px; border-radius:6px; cursor:pointer; }
  button:hover { background:#1d4ed8; }
  .summary-badge { display:inline-block; padding:3px 10px; border-radius:999px; font-weight:bold; }
  .badge-online { background:#064e3b; color:#4ade80; }
  .badge-offline { background:#450a0a; color:#f87171; }
</style>
</head>
<body>

<h1>?? Tester Konektivitas Server SLA</h1>

<form method="get">
  <input type="text" name="host" value="<?= h($host) ?>" placeholder="Host / IP">
  <input type="number" name="port" value="<?= h($port) ?>" placeholder="Port">
  <input type="number" name="timeout" value="<?= h($timeoutSec) ?>" placeholder="Timeout (detik)" step="0.5">
  <button type="submit">Test</button>
</form>

<div class="box">
  <strong>Target:</strong> <?= h($host) ?>:<?= h($port) ?>
  &nbsp;|&nbsp; <strong>Timeout:</strong> <?= h($timeoutSec) ?>s
  &nbsp;|&nbsp; <strong>Waktu:</strong> <?= h(date('Y-m-d H:i:s')) ?>
</div>

<div class="box">
  <h3>Ringkasan</h3>
  <span class="summary-badge <?= $isOnline ? 'badge-online' : 'badge-offline' ?>">
    <?= $isOnline ? 'ONLINE' : 'OFFLINE' ?>
  </span>
  <table>
    <tr><th>is_online</th><td><?= $isOnline ? '1 (ONLINE)' : '0 (OFFLINE)' ?></td></tr>
    <tr><th>response_ms</th><td><?= $responseMs === null ? 'NULL' : h($responseMs) ?></td></tr>
    <tr><th>method</th><td>tcp</td></tr>
    <tr><th>checked_at</th><td><?= h(date('Y-m-d H:i:s')) ?></td></tr>
  </table>
</div>

<div class="box">
  <h3>[1] Resolve DNS</h3>
  <table>
    <tr><th>Host</th><td><?= h($host) ?></td></tr>
    <tr><th>Adalah IP langsung?</th><td><?= $isIp ? 'Ya' : 'Tidak' ?></td></tr>
    <?php if (!$isIp): ?>
    <tr><th>Hasil resolve</th><td class="<?= $resolveOk ? 'ok' : 'fail' ?>"><?= h($resolvedIp) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<div class="box">
  <h3>[2] TCP Connect via fsockopen</h3>
  <table>
    <tr><th>Status</th><td class="<?= $fsockOk ? 'ok' : 'fail' ?>"><?= $fsockOk ? 'BERHASIL' : 'GAGAL' ?></td></tr>
    <tr><th>Waktu</th><td><?= h($elapsedFsMs) ?> ms</td></tr>
    <?php if (!$fsockOk): ?>
    <tr><th>errno</th><td><?= h($errnoFs) ?></td></tr>
    <tr><th>errstr</th><td><?= h($errstrFs) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<div class="box">
  <h3>[3] TCP Connect via stream_socket_client</h3>
  <table>
    <tr><th>Status</th><td class="<?= $streamOk ? 'ok' : 'fail' ?>"><?= $streamOk ? 'BERHASIL' : 'GAGAL' ?></td></tr>
    <tr><th>Waktu</th><td><?= h($elapsedSsMs) ?> ms</td></tr>
    <?php if (!$streamOk): ?>
    <tr><th>errno</th><td><?= h($errnoSs) ?></td></tr>
    <tr><th>errstr</th><td><?= h($errstrSs) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<div class="box">
  <h3>[4] ICMP Ping (exec)</h3>
  <?php if (!$execAvailable): ?>
    <p class="fail">Fungsi exec() tidak tersedia / diblokir di server PHP ini.</p>
  <?php elseif (empty($pingOutput)): ?>
    <p class="fail">Tidak ada output ping (kemungkinan exec() diblokir oleh konfigurasi hosting / disable_functions).</p>
  <?php else: ?>
    <pre><?= h(implode("\n", $pingOutput)) ?></pre>
    <p>Exit code: <?= h($pingReturnVar) ?> (<?= $pingReturnVar === 0 ? '<span class="ok">sukses</span>' : '<span class="fail">gagal</span>' ?>)</p>
  <?php endif; ?>
</div>

<?php if (!$isOnline): ?>
<div class="box">
  <h3>?? Catatan kalau status GAGAL/OFFLINE</h3>
  <p>Kemungkinan penyebab:</p>
  <ol>
    <li>Firewall server <strong>ini</strong> (yang menjalankan PHP) memblokir koneksi outbound ke port <?= h($port) ?></li>
    <li>Port <?= h($port) ?> di <?= h($host) ?> memang tidak listen atau di-filter firewall di sisi tujuan</li>
    <li><code>allow_url_fopen</code> dinonaktifkan di <code>php.ini</code> (cek dengan <code>php -i | grep allow_url_fopen</code>)</li>
    <li>Hosting/ISP server ini membatasi koneksi outbound ke IP/port tertentu</li>
  </ol>
  <p>Coba juga lewat SSH ke server ini langsung (bukan lewat browser):</p>
  <pre>telnet <?= h($host) ?> <?= h($port) ?>
# atau
nc -zv <?= h($host) ?> <?= h($port) ?></pre>
</div>
<?php endif; ?>

<p style="color:#64748b; font-size:0.85em;">
  Akses dengan parameter custom: <code>?host=IP&port=PORT&timeout=DETIK</code>
</p>

</body>
</html>