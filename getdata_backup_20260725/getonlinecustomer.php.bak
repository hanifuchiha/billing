<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

require('routeros_api.class.php'); // Pastikan RouterosAPI.php tersedia

header('Content-Type: application/json');

// Helper: format byte count into a readable size (dipakai untuk kuota)
function format_bytes_readable($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes <= 0) return '';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($bytes, 1, '.', ''), '0'), '.') . ' ' . $units[$i];
}

// Helper: teks kuota dari limit-bytes-in/limit-bytes-out RouterOS
function format_kuota_text($limitIn, $limitOut)
{
    $inTxt = format_bytes_readable($limitIn);
    $outTxt = format_bytes_readable($limitOut);
    if ($inTxt === '' && $outTxt === '') return 'Unlimited';
    $parts = [];
    if ($inTxt !== '') $parts[] = 'In ' . $inTxt;
    if ($outTxt !== '') $parts[] = 'Out ' . $outTxt;
    return implode(' / ', $parts);
}

// Helper: teks total pemakaian data dari bytes-in/bytes-out sesi aktif RouterOS
function format_pemakaian_text($bytesIn, $bytesOut)
{
    $bytesIn = (float) $bytesIn;
    $bytesOut = (float) $bytesOut;
    if ($bytesIn <= 0 && $bytesOut <= 0) return 'N/A';
    $inTxt = $bytesIn > 0 ? format_bytes_readable($bytesIn) : '0 B';
    $outTxt = $bytesOut > 0 ? format_bytes_readable($bytesOut) : '0 B';
    return format_bytes_readable($bytesIn + $bytesOut) . ' (In ' . $inTxt . ' / Out ' . $outTxt . ')';
}

// Helper: parse uptime RouterOS (mis. "4w6d15:37:28", "1d2h3m4s") menjadi detik
function ros_uptime_to_seconds($uptime)
{
    $uptime = trim((string) $uptime);
    if ($uptime === '') return null;
    $seconds = 0;
    if (preg_match('/(\d+)w/', $uptime, $m)) $seconds += intval($m[1]) * 604800;
    if (preg_match('/(\d+)d/', $uptime, $m)) $seconds += intval($m[1]) * 86400;
    if (preg_match('/(\d+):(\d+):(\d+)/', $uptime, $m)) {
        $seconds += intval($m[1]) * 3600 + intval($m[2]) * 60 + intval($m[3]);
    } else {
        if (preg_match('/(\d+)h/', $uptime, $m)) $seconds += intval($m[1]) * 3600;
        if (preg_match('/(\d+)m(?!s)/', $uptime, $m)) $seconds += intval($m[1]) * 60;
        if (preg_match('/(\d+)s/', $uptime, $m)) $seconds += intval($m[1]);
    }
    return $seconds;
}

// Helper: uptime dalam format jam yang mudah dibaca, mis. "20:10:16" atau "1d 20:10:16"
function format_uptime_readable($uptime)
{
    $seconds = ros_uptime_to_seconds($uptime);
    if ($seconds === null) return 'N/A';

    $weeks = intdiv($seconds, 604800);
    $seconds %= 604800;
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $clock = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    if ($weeks > 0) return $weeks . 'w ' . $days . 'd ' . $clock;
    if ($days > 0) return $days . 'd ' . $clock;
    return $clock;
}

// Helper: format tanggal ke format Indonesia "1 Juli 2026" (+ jam jika ada), menerima
// timestamp Unix RouterOS (mis. "jul/19/2026 05:55:41") maupun format tanggal umum lainnya
function format_tanggal_indo($value, $withTime = true)
{
    $value = trim((string) $value);
    if ($value === '' || $value === '-' || strtoupper($value) === 'N/A') return $value;

    $bulanIndo = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $bulanEngMap = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7,
        'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12
    ];

    $timestamp = null;

    // Format RouterOS: mon/dd/yyyy hh:mm:ss (mis. jul/19/2026 05:55:41)
    if (preg_match('/^([a-zA-Z]{3,4})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)) {
        $monKey = strtolower($m[1]);
        if (isset($bulanEngMap[$monKey])) {
            $timestamp = mktime(
                isset($m[4]) ? (int) $m[4] : 0,
                isset($m[5]) ? (int) $m[5] : 0,
                isset($m[6]) ? (int) $m[6] : 0,
                $bulanEngMap[$monKey],
                (int) $m[2],
                (int) $m[3]
            );
        }
    }

    if ($timestamp === null) {
        $ts = strtotime($value);
        if ($ts !== false) $timestamp = $ts;
    }

    if ($timestamp === null) return $value;

    $result = (int) date('j', $timestamp) . ' ' . $bulanIndo[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    if ($withTime && date('H:i:s', $timestamp) !== '00:00:00') {
        $result .= ' ' . date('H:i', $timestamp);
    }

    return $result;
}

// Helper: hitung waktu "link up" (mulai sesi) dari durasi uptime saat ini
function format_last_link_up($uptime)
{
    $seconds = ros_uptime_to_seconds($uptime);
    if ($seconds === null) return 'N/A';
    return format_tanggal_indo(date('Y-m-d H:i:s', time() - $seconds));
}

// Pastikan tabel penyimpanan akumulasi pemakaian data pelanggan tersedia
function ensure_customer_usage_table($conn)
{
    static $ensured = false;
    if ($ensured) return;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS customer_data_usage (
        idpel VARCHAR(64) NOT NULL,
        accumulated_bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
        accumulated_bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_uptime_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (idpel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ensured = true;
}

// Lacak pemakaian data supaya total-nya terus berlanjut walau koneksi PPPoE terputus/reconnect
// (counter rx-byte/tx-byte interface RouterOS reset ke 0 setiap kali sesi baru dimulai).
// Mengembalikan total kumulatif [bytes_in, bytes_out] sejak pertama kali dipantau.
function track_customer_usage($conn, $idpel, $isOnline, $currentBytesIn, $currentBytesOut, $uptimeSeconds)
{
    ensure_customer_usage_table($conn);

    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $res = mysqli_query($conn, "SELECT accumulated_bytes_in, accumulated_bytes_out, last_bytes_in, last_bytes_out, last_uptime_seconds
        FROM customer_data_usage WHERE idpel = '$idpelEsc' LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;

    if (!$row) {
        if (!$isOnline) return [0, 0];
        mysqli_query($conn, "INSERT INTO customer_data_usage
            (idpel, accumulated_bytes_in, accumulated_bytes_out, last_bytes_in, last_bytes_out, last_uptime_seconds)
            VALUES ('$idpelEsc', 0, 0, " . (int) $currentBytesIn . ", " . (int) $currentBytesOut . ", " . (int) $uptimeSeconds . ")");
        return [(float) $currentBytesIn, (float) $currentBytesOut];
    }

    $accIn = (float) $row['accumulated_bytes_in'];
    $accOut = (float) $row['accumulated_bytes_out'];
    $lastIn = (float) $row['last_bytes_in'];
    $lastOut = (float) $row['last_bytes_out'];
    $lastUptime = (int) $row['last_uptime_seconds'];

    if ($isOnline) {
        // Sesi baru terdeteksi jika uptime atau counter byte "mundur" dibanding poll sebelumnya
        $resetDetected = ($uptimeSeconds < $lastUptime) || ($currentBytesIn < $lastIn) || ($currentBytesOut < $lastOut);

        if ($resetDetected) {
            $accIn += $lastIn;
            $accOut += $lastOut;
            $lastIn = (float) $currentBytesIn;
            $lastOut = (float) $currentBytesOut;
        } else {
            $lastIn = max($lastIn, (float) $currentBytesIn);
            $lastOut = max($lastOut, (float) $currentBytesOut);
        }
        $lastUptime = $uptimeSeconds;

        mysqli_query($conn, "UPDATE customer_data_usage SET
            accumulated_bytes_in = " . (int) $accIn . ",
            accumulated_bytes_out = " . (int) $accOut . ",
            last_bytes_in = " . (int) $lastIn . ",
            last_bytes_out = " . (int) $lastOut . ",
            last_uptime_seconds = " . (int) $lastUptime . "
            WHERE idpel = '$idpelEsc'");
    }

    // Total tampil = akumulasi sesi-sesi sebelumnya + sesi terakhir yang diketahui (aktif atau baru saja berakhir)
    return [$accIn + $lastIn, $accOut + $lastOut];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["error" => "Invalid request method"]);
    exit;
}

$ip = $_POST['ip'] ?? '';
$user = $_POST['us'] ?? '';
$password = $_POST['ps'] ?? '';
$IDPEL = $_POST['idpel'] ?? '';

if (empty($ip) || empty($user) || empty($password) || empty($IDPEL)) {
    echo json_encode(["error" => "Missing parameters"]);
    exit;
}

$API = new RouterosAPI();
if ($API->connect($ip, $user, $password)) {

    // Ambil PPP secret milik pelanggan ini saja — filter di router (?name=...)
    // alih-alih menarik seluruh daftar secret lalu array_search() di PHP.
    // Dengan banyak pelanggan di satu router, print penuh ini yang paling
    // memperlambat setiap polling (diulang per pelanggan, tiap 10 detik).
    $ARRAY = $API->comm('/ppp/secret/print', ["?name" => $IDPEL]);
    $cek = !empty($ARRAY) ? 0 : false;

    // Info secret
    $cekexpired = $cek !== false ? ($ARRAY[$cek]['profile'] ?? 'N/A') : 'N/A';
    $last_caller_secret = $cek !== false ? ($ARRAY[$cek]['last-caller-id'] ?? 'N/A') : 'N/A';
    $ceklastloggedout = $cek !== false ? ($ARRAY[$cek]['last-logged-out'] ?? 'N/A') : 'N/A';
    $ceklastdisconnect = $cek !== false ? ($ARRAY[$cek]['last-disconnect-reason'] ?? 'N/A') : 'N/A';
    $secretLimitIn = $cek !== false ? ($ARRAY[$cek]['limit-bytes-in'] ?? '') : '';
    $secretLimitOut = $cek !== false ? ($ARRAY[$cek]['limit-bytes-out'] ?? '') : '';

    // Cek PPP active
    $ppp_active = $API->comm('/ppp/active/print', [
        "?name" => $IDPEL
    ]);

    $status = "Offline";
    $download = 0;
    $upload = 0;
    $active_caller_id = 'N/A';
    $remote_ip = 'N/A';
    $login_source = 'unknown';
    $uptime_raw = '';
    $sessionBytesIn = 0;
    $sessionBytesOut = 0;

    if (!empty($ppp_active)) {
        $status = "Online";
        $active_caller_id = $ppp_active[0]['caller-id'] ?? $ppp_active[0]['mac-address'] ?? 'N/A';
        $remote_ip = $ppp_active[0]['address'] ?? 'N/A';
        $radius_flag = $ppp_active[0]['radius'] ?? 'false';
        $login_source = ($radius_flag === 'true') ? 'radius' : 'local';
        $uptime_raw = $ppp_active[0]['uptime'] ?? '';
        if (empty($secretLimitIn)) $secretLimitIn = $ppp_active[0]['limit-bytes-in'] ?? '';
        if (empty($secretLimitOut)) $secretLimitOut = $ppp_active[0]['limit-bytes-out'] ?? '';
    }

    // Kuota, uptime, dan waktu link up (dihitung dari durasi uptime)
    $kuota = format_kuota_text($secretLimitIn, $secretLimitOut);
    $uptime = ($uptime_raw !== '') ? format_uptime_readable($uptime_raw) : 'N/A';
    $lastLinkUp = ($uptime_raw !== '') ? format_last_link_up($uptime_raw) : 'N/A';

    $interface = "<pppoe-" . $IDPEL . ">";

    // Monitor traffic + pemakaian data (rx-byte/tx-byte kumulatif sesi ini) jika interface tersedia
    $interfaces = $API->comm("/interface/print", ["?name" => $interface]);
    if (!empty($interfaces)) {
        $sessionBytesIn = $interfaces[0]['rx-byte'] ?? 0;
        $sessionBytesOut = $interfaces[0]['tx-byte'] ?? 0;

        $traffic = $API->comm("/interface/monitor-traffic", [
            "interface" => $interface,
            "once" => ""
        ]);
        if (!empty($traffic[0]['tx-bits-per-second'])) {
            $download = round($traffic[0]['tx-bits-per-second'] / 1_000_000, 2); // Mbps
            $upload = round($traffic[0]['rx-bits-per-second'] / 1_000_000, 2);   // Mbps
        }
    }

    // Total pemakaian kumulatif - tetap berlanjut walau koneksi terputus/reconnect,
    // bukan hitung ulang dari nol setiap sesi PPPoE baru.
    $uptimeSecondsForUsage = ($status === 'Online') ? (ros_uptime_to_seconds($uptime_raw) ?? 0) : 0;
    list($totalBytesIn, $totalBytesOut) = track_customer_usage($conn, $IDPEL, $status === 'Online', $sessionBytesIn, $sessionBytesOut, $uptimeSecondsForUsage);

    $pemakaian = format_pemakaian_text($totalBytesIn, $totalBytesOut);
    $lastLinkDown = format_tanggal_indo($ceklastloggedout);

    echo json_encode([
        'cekexpired' => $cekexpired,
        'last_caller_secret' => $last_caller_secret, // MAC terakhir dari secret
        'active_caller_id' => $active_caller_id,     // MAC saat ini jika online
        'remote_ip' => $remote_ip,                   // IP user saat online
        'ceklastloggedout' => $ceklastloggedout,      // link down terakhir (timestamp)
        'ceklastdisconnect' => $ceklastdisconnect,
        'status' => $status,
        'interface' => $interface,
        'download' => $download,
        'upload' => $upload,
        'user' => $IDPEL,
        'login_via' => $login_source,
        'kuota' => $kuota,
        'uptime' => $uptime,
        'last_link_up' => $lastLinkUp,
        'last_link_down' => $lastLinkDown,
        'pemakaian' => $pemakaian
    ]);

} else {
    echo json_encode(["error" => "Failed to connect"]);
}
exit;
