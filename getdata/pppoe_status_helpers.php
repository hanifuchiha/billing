<?php
// Helper bersama untuk status PPPoE (dipakai oleh cron_scan_pppoe_status.php dan
// get_cached_pppoe_status.php). Isinya format angka/tanggal RouterOS + tracking
// pemakaian data kumulatif. Sengaja TIDAK dipakai oleh getonlinecustomer.php lama
// supaya endpoint lama itu tetap apa adanya (tidak ikut berubah/berisiko).

define('PPPOE_STATUS_CACHE_FILE', __DIR__ . '/../serverlog/pppoe_status_cache.json');
// Cron produksi jalan tiap 1 menit (lewat toggle di menu Notifikasi), jadi TTL
// dilebihkan sedikit di atas 60 detik supaya tetap dianggap fresh walau cron
// telat beberapa detik. Di atas ini dianggap basi -> fallback live per pelanggan.
define('PPPOE_STATUS_CACHE_FRESH_SECONDS', 75);

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

function format_pemakaian_text($bytesIn, $bytesOut)
{
    $bytesIn = (float) $bytesIn;
    $bytesOut = (float) $bytesOut;
    if ($bytesIn <= 0 && $bytesOut <= 0) return 'N/A';
    $inTxt = $bytesIn > 0 ? format_bytes_readable($bytesIn) : '0 B';
    $outTxt = $bytesOut > 0 ? format_bytes_readable($bytesOut) : '0 B';
    return format_bytes_readable($bytesIn + $bytesOut) . ' (In ' . $inTxt . ' / Out ' . $outTxt . ')';
}

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

function format_last_link_up($uptime)
{
    $seconds = ros_uptime_to_seconds($uptime);
    if ($seconds === null) return 'N/A';
    return format_tanggal_indo(date('Y-m-d H:i:s', time() - $seconds));
}

// Sama seperti format_uptime_readable(), tapi menerima jumlah detik mentah langsung
// (dipakai jalur RADIUS/radacct: acctstarttime cuma datetime, bukan string uptime
// ala RouterOS "1d02:03:04" yang dipahami ros_uptime_to_seconds()).
function format_uptime_from_seconds($seconds)
{
    $seconds = (int) $seconds;
    if ($seconds < 0) return 'N/A';

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

// Sama persis dengan logika di getonlinecustomer.php (supaya angka pemakaian tetap
// konsisten dipakai dari jalur cron ATAU jalur live-fallback).
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

    return [$accIn + $lastIn, $accOut + $lastOut];
}

// Versi batch dari track_customer_usage() - dipakai KHUSUS oleh cron_scan_pppoe_status.php.
// Dengan ribuan pelanggan, track_customer_usage() yang dipanggil satu-satu (1 SELECT +
// 1 UPDATE per pelanggan) adalah query N+1 klasik yang bikin scan jadi puluhan detik.
// Fungsi ini hanya melakukan 1 SELECT untuk SEMUA pelanggan + 1 (atau beberapa, di-chunk)
// query INSERT ... ON DUPLICATE KEY UPDATE untuk seluruh pelanggan yang online sekaligus.
//
// $rows: array of ['idpel'=>, 'isOnline'=>, 'bytesIn'=>, 'bytesOut'=>, 'uptimeSeconds'=>]
// Return: [idpel_lower => [totalBytesIn, totalBytesOut]]
function batch_track_customer_usage($conn, array $rows)
{
    ensure_customer_usage_table($conn);

    $existing = [];
    $res = mysqli_query($conn, "SELECT idpel, accumulated_bytes_in, accumulated_bytes_out, last_bytes_in, last_bytes_out, last_uptime_seconds FROM customer_data_usage");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $existing[strtolower($row['idpel'])] = $row;
        }
    }

    $results = [];
    $upsertValues = [];

    foreach ($rows as $r) {
        $idpel = (string) $r['idpel'];
        $key = strtolower($idpel);
        $isOnline = !empty($r['isOnline']);
        $currentBytesIn = (float) ($r['bytesIn'] ?? 0);
        $currentBytesOut = (float) ($r['bytesOut'] ?? 0);
        $uptimeSeconds = (int) ($r['uptimeSeconds'] ?? 0);

        $existingRow = $existing[$key] ?? null;

        if (!$existingRow) {
            if (!$isOnline) {
                $results[$key] = [0.0, 0.0];
                continue;
            }
            $results[$key] = [$currentBytesIn, $currentBytesOut];
            $upsertValues[] = sprintf(
                "('%s', 0, 0, %d, %d, %d)",
                mysqli_real_escape_string($conn, $idpel),
                (int) $currentBytesIn,
                (int) $currentBytesOut,
                $uptimeSeconds
            );
            continue;
        }

        $accIn = (float) $existingRow['accumulated_bytes_in'];
        $accOut = (float) $existingRow['accumulated_bytes_out'];
        $lastIn = (float) $existingRow['last_bytes_in'];
        $lastOut = (float) $existingRow['last_bytes_out'];
        $lastUptime = (int) $existingRow['last_uptime_seconds'];

        if ($isOnline) {
            $resetDetected = ($uptimeSeconds < $lastUptime) || ($currentBytesIn < $lastIn) || ($currentBytesOut < $lastOut);

            if ($resetDetected) {
                $accIn += $lastIn;
                $accOut += $lastOut;
                $lastIn = $currentBytesIn;
                $lastOut = $currentBytesOut;
            } else {
                $lastIn = max($lastIn, $currentBytesIn);
                $lastOut = max($lastOut, $currentBytesOut);
            }
            $lastUptime = $uptimeSeconds;

            $upsertValues[] = sprintf(
                "('%s', %d, %d, %d, %d, %d)",
                mysqli_real_escape_string($conn, $idpel),
                (int) $accIn,
                (int) $accOut,
                (int) $lastIn,
                (int) $lastOut,
                (int) $lastUptime
            );
        }

        $results[$key] = [$accIn + $lastIn, $accOut + $lastOut];
    }

    // Kirim upsert dalam potongan (chunk) supaya query tidak raksasa kalau pelanggan ribuan.
    foreach (array_chunk($upsertValues, 300) as $chunk) {
        if (empty($chunk)) continue;
        mysqli_query($conn, "INSERT INTO customer_data_usage
            (idpel, accumulated_bytes_in, accumulated_bytes_out, last_bytes_in, last_bytes_out, last_uptime_seconds)
            VALUES " . implode(',', $chunk) . "
            ON DUPLICATE KEY UPDATE
                accumulated_bytes_in = VALUES(accumulated_bytes_in),
                accumulated_bytes_out = VALUES(accumulated_bytes_out),
                last_bytes_in = VALUES(last_bytes_in),
                last_bytes_out = VALUES(last_bytes_out),
                last_uptime_seconds = VALUES(last_uptime_seconds)");
    }

    return $results;
}

// Baca 1 entri cache (hasil scan batch cron) untuk 1 IDPEL. Return null kalau file
// cache tidak ada, rusak, IDPEL tidak ditemukan, atau datanya sudah lewat TTL.
function read_pppoe_status_cache_entry($idpel)
{
    if (!file_exists(PPPOE_STATUS_CACHE_FILE)) return null;

    $raw = @file_get_contents(PPPOE_STATUS_CACHE_FILE);
    if ($raw === false || $raw === '') return null;

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['generated_at'], $decoded['data'])) return null;

    if ((time() - (int) $decoded['generated_at']) > PPPOE_STATUS_CACHE_FRESH_SECONDS) return null;

    $key = strtolower(trim((string) $idpel));
    if ($key === '' || !isset($decoded['data'][$key])) return null;

    return $decoded['data'][$key];
}
