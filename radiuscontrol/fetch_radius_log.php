<?php
// Guard akses: halaman ini polling tiap 20 detik dari radius.php dan
// menampilkan isi log request RADIUS (bisa memuat username/IP pelanggan) --
// harus login sebagai ADMIN, sama seperti proses.php di folder ini.
require_once __DIR__ . '/../cek-sesi.php';
if (($AKSES ?? '') !== 'ADMIN') {
    http_response_code(403);
    die('Akses ditolak: pengaturan FreeRADIUS khusus untuk ADMIN.');
}

// Setiap kali FreeRADIUS di-restart (terjadi di HAMPIR semua aksi di halaman
// ini -- add/edit customer, add/delete user, save config, sync radius, dst),
// file debug ini SENGAJA dikosongkan ulang (`rm -f` + `touch`) oleh
// restartFreeradius() supaya sesi debug baru mulai bersih. Efeknya: tabel
// "FreeRADIUS Request Log" yang cuma membaca file debug langsung akan
// kehilangan semua baris log setiap kali ada restart -- padahal restart itu
// sering terjadi (bisa beberapa kali semenit kalau admin sedang aktif).
//
// Supaya data lama tidak hilang, hasil parsing di sini disimpan permanen ke
// $history_file (tidak pernah dikosongkan oleh proses restart manapun), dan
// yang ditampilkan adalah GABUNGAN riwayat lama + hasil parsing terbaru dari
// file debug yang sedang berjalan. Entri baru dideteksi lewat hash isi baris
// supaya tidak dobel setiap kali halaman poll ulang tiap 3 detik.

$log_file = '/var/log/freeradius/debug-radius-web.log';
$history_file = '/var/log/freeradius/request-log-history.json';
$max_history = 2000;

function readFileWithSudoFallback(string $path): string
{
    $content = @file_get_contents($path);
    if ($content === false) {
        $content = (string) shell_exec('sudo /bin/cat ' . escapeshellarg($path) . ' 2>/dev/null');
    }
    return (string) $content;
}

function writeHistoryFile(string $path, array $data): void
{
    $json = json_encode($data);
    if (@file_put_contents($path, $json) !== false) {
        return;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'radlog');
    file_put_contents($tmp, $json);
    shell_exec('sudo /bin/mkdir -p ' . escapeshellarg(dirname($path)) . ' 2>/dev/null');
    shell_exec('sudo /bin/cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($path) . ' 2>&1');
    shell_exec('sudo /bin/chmod 666 ' . escapeshellarg($path) . ' 2>&1');
    @unlink($tmp);
}

// ================= Terjemahan alasan reject FreeRADIUS ke Bahasa Indonesia =====
// FreeRADIUS cuma nulis pesan teknis mentah (Bahasa Inggris, style debug internal
// modul) yang susah dibaca operator non-teknis. Fungsi ini scan SELURUH baris
// dalam satu blok request (bukan cuma 1 baris terakhir yang match kata kunci
// seperti sebelumnya) dan cocokkan ke pola-pola penyebab reject yang paling umum
// terjadi di server ini, supaya kolom Detail langsung kasih tahu APA yang harus
// dicek operator, bukan sekadar salin-tempel log mentah.
function explainRadiusReject(array $blockLines): string
{
    $text = implode("\n", $blockLines);

    // 1) User tidak ditemukan sama sekali di file/DB RADIUS ("[files] = noop"
    //    dibarengi peringatan pap tidak menemukan password) - penyebab PALING
    //    umum: username salah ketik, atau pelanggan belum/gagal disinkronkan ke
    //    FreeRADIUS (lihat radius_sync_lib.php / sync_freeradius_users.php).
    if (stripos($text, 'No "known good" password found') !== false
        || preg_match('/\[files\]\s*=\s*noop/i', $text)) {
        return 'DITOLAK: Username tidak ditemukan di FreeRADIUS. '
            . 'Kemungkinan penyebab: (a) username salah ketik di modem/router pelanggan, '
            . '(b) pelanggan belum/gagal disinkronkan ke FreeRADIUS (cek menu Sinkronisasi RADIUS), '
            . 'atau (c) pelanggan memang sudah diisolir/dihapus dari FreeRADIUS.';
    }

    // 2) Username ketemu, tapi password yang dikirim pelanggan tidak cocok.
    if (stripos($text, "Passwords don't match") !== false
        || stripos($text, 'password comparison failed') !== false
        || stripos($text, 'FAILURE: MS-CHAP2 Response is incorrect') !== false) {
        return 'DITOLAK: Password salah. Username ditemukan di FreeRADIUS, tapi password yang '
            . 'dikirim dari modem/router pelanggan tidak cocok dengan password yang tersimpan di sistem. '
            . 'Cek ulang password PPPoE yang di-setting di perangkat pelanggan.';
    }

    // 3) Sesi aktif sudah melebihi batas maksimal (Simultaneous-Use).
    if (stripos($text, 'Simultaneous-Use') !== false || stripos($text, 'Multiple logins') !== false) {
        return 'DITOLAK: Batas jumlah sesi login bersamaan (Simultaneous-Use) untuk username ini sudah '
            . 'terlampaui - kemungkinan user ini masih tercatat "online" di sesi sebelumnya yang belum '
            . 'benar-benar terputus. Cek dan bersihkan sesi aktif lama (radutmp) untuk user ini.';
    }

    // 4) Router (NAS) belum/tidak terdaftar sebagai client RADIUS yang sah.
    if (stripos($text, 'Ignoring request to auth address') !== false
        || stripos($text, 'unknown client') !== false
        || stripos($text, 'Failed to find a client') !== false) {
        return 'DITOLAK: Router (NAS) pengirim request ini belum terdaftar sebagai client RADIUS yang sah '
            . 'di FreeRADIUS (clients.conf). Cek pengaturan RADIUS Client / Mikrotik-nya.';
    }

    // 5) Fallback umum: tidak ada Auth-Type yang cocok sama sekali (variasi dari
    //    kasus #1, tapi pesannya berbeda tergantung versi FreeRADIUS).
    if (stripos($text, 'No Auth-Type found') !== false) {
        return 'DITOLAK: FreeRADIUS tidak menemukan metode autentikasi (Auth-Type) yang cocok untuk '
            . 'username ini - hampir selalu berarti username tidak terdaftar/tidak ditemukan di FreeRADIUS. '
            . 'Cek kembali apakah pelanggan ini sudah tersinkron.';
    }

    // Tidak ada pola yang cocok - tetap tampilkan baris teknis terakhir yang
    // memuat kata kunci reject/error, tapi diberi label supaya jelas ini
    // detail mentah, bukan kesimpulan.
    $lastTechnical = '';
    foreach ($blockLines as $l) {
        if (stripos($l, 'reject') !== false || stripos($l, 'failed') !== false || stripos($l, 'error') !== false) {
            $lastTechnical = $l;
        }
    }
    return 'DITOLAK: Alasan spesifik belum dikenali sistem. Detail teknis (mentah): '
        . ($lastTechnical !== '' ? $lastTechnical : '(tidak ada detail tambahan di log)');
}

// ================= Parse isi file debug yang sedang berjalan =================
$rawContent = readFileWithSudoFallback($log_file);
$lines = $rawContent === '' ? [] : preg_split('/\r\n|\r|\n/', $rawContent);
$lines = array_slice($lines, -1000);

$requests = [];
$current = [];
$block_lines = [];

foreach ($lines as $line) {
    $line = trim($line);

    // Request ID - mulai blok baru, buang akumulasi baris blok sebelumnya.
    if (preg_match('/Received Access-Request Id (\d+) from ([\d\.]+):(\d+)/', $line, $m)) {
        $current = [];
        $block_lines = [];
        $current['id'] = $m[1];
        $current['client_ip'] = $m[2];
        $current['client_port'] = $m[3];
    }

    // Kumpulkan SEMUA baris milik request yang sedang diproses (supaya alasan
    // reject bisa dianalisis dari keseluruhan konteks, bukan cuma 1 baris).
    $block_lines[] = $line;

    if (preg_match('/User-Name\s*=\s*"([^"]+)"/', $line, $m)) {
        $current['user'] = $m[1];
    }
    if (preg_match('/Calling-Station-Id\s*=\s*"([^"]+)"/', $line, $m)) {
        $current['mac'] = $m[1];
    }
    if (preg_match('/NAS-IP-Address\s*=\s*([\d\.]+)/', $line, $m)) {
        $current['nas_ip'] = $m[1];
    }
    if (preg_match('/Service-Type\s*=\s*(\S+)/', $line, $m)) {
        $current['service'] = $m[1];
    }
    if (preg_match('/NAS-Port\s*=\s*(\d+)/', $line, $m)) {
        $current['nas_port'] = $m[1];
    }
    if (preg_match('/Acct-Session-Id\s*=\s*"([^"]+)"/', $line, $m)) {
        $current['session_id'] = $m[1];
    }

    if (strpos($line, 'Access-Accept') !== false) {
        $current['status'] = 'ACCEPT';
        $current['status_detail'] = 'DITERIMA: Username dan password cocok, login berhasil.';
    }
    if (strpos($line, 'Access-Reject') !== false) {
        $current['status'] = 'REJECT';
        $current['status_detail'] = explainRadiusReject($block_lines);
    }

    if (isset($current['user'], $current['status'])) {
        $current['raw'] = $line;
        $requests[] = $current;
        $current = [];
        $block_lines = [];
    }
}

// ================= Gabungkan dengan riwayat yang sudah tersimpan =================
$historyRaw = readFileWithSudoFallback($history_file);
$history = $historyRaw !== '' ? json_decode($historyRaw, true) : [];
if (!is_array($history)) {
    $history = [];
}

$seenHashes = [];
foreach ($history as $row) {
    if (isset($row['_hash'])) {
        $seenHashes[$row['_hash']] = true;
    }
}

$changed = false;
foreach ($requests as $req) {
    $hash = md5(json_encode($req));
    if (isset($seenHashes[$hash])) {
        continue;
    }
    $req['_hash'] = $hash;
    $history[] = $req;
    $seenHashes[$hash] = true;
    $changed = true;
}

if (count($history) > $max_history) {
    $history = array_slice($history, -$max_history);
}

if ($changed) {
    writeHistoryFile($history_file, $history);
}

// ================= Output table rows dari riwayat gabungan =================
$no = 1;
if (count($history) == 0) {
    echo "<tr><td colspan='9' class='text-center'>Tidak ada log request ditemukan</td></tr>";
} else {
    foreach ($history as $req) {
        $user = htmlspecialchars($req['user'] ?? '');
        $nasIp = htmlspecialchars($req['nas_ip'] ?? '');
        $clientIp = htmlspecialchars($req['client_ip'] ?? '');
        $mac = htmlspecialchars($req['mac'] ?? '');
        $service = htmlspecialchars($req['service'] ?? '');
        $nasPort = htmlspecialchars($req['nas_port'] ?? '');
        $status = htmlspecialchars($req['status'] ?? '');
        $statusDetail = htmlspecialchars($req['status_detail'] ?? '');
        echo "<tr>
            <td>{$no}</td>
            <td>{$user}</td>
            <td>{$nasIp}</td>
            <td>{$clientIp}</td>
            <td>{$mac}</td>
            <td>{$service}</td>
            <td>{$nasPort}</td>
            <td>{$status}</td>
            <td><pre style='margin:0'>{$statusDetail}</pre></td>
        </tr>";
        $no++;
    }
}
