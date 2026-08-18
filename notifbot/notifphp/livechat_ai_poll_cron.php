<?php
// livechat_ai_poll_cron.php - Jaring pengaman AI Bot Live Chat via polling
// berkala. DIBANGUN ULANG atas permintaan user (2026-08-09) karena trigger
// real-time di chat_send.php ternyata tidak selalu jalan di server ini
// (kemungkinan proses PHP terputus/timeout sebelum sempat panggil AI, atau
// sebab lain di sisi hosting) -- cron ini jadi mekanisme UTAMA (bukan cuma
// jaring pengaman) sampai penyebab trigger real-time ketemu.
//
// Dipanggil berkala (disarankan tiap 1 menit) via crontab server. SEKALI JALAN
// memproses SEMUA tenant yang AI-nya ON sekaligus (bukan per-PEMILIK spt cron
// lain di sistem ini), karena Live Chat AI aktif/tidaknya murni data (kolom
// ai_enabled), bukan sesuatu yg perlu didaftarkan manual per pemilik.
//
// Mereplikasi dispatch YANG SAMA seperti chat_send.php: keyword aksi (cek
// data/gangguan/kode bayar/menu/jawaban kustom) dicek dulu, baru fallback ke
// AI chat bebas. Bedanya: chat_send.php punya Bearer token dari request HTTP
// pelanggan itu sendiri, cron ini TIDAK -- jadi ambil token session AKTIF milik
// pelanggan itu dari tabel twk_mobile_sessions (session asli yg sudah ada,
// bukan token palsu) supaya aksi yg butuh API mobile (gangguan/kode bayar)
// tetap bisa jalan. Kalau tidak ada session aktif, keyword aksi itu di-skip,
// tapi AI chat bebas & menu/jawaban kustom (yang tidak butuh token) tetap jalan.

@set_time_limit(120);
include __DIR__ . '/../../koneksidb.php';
require_once __DIR__ . '/../../livechat_ai_helper.php';
require_once __DIR__ . '/../../../webhook/ai_provider_helper.php';

if (!$conn) {
    echo "DB connection gagal.\n";
    exit;
}

// koneksidb.php set charset ke latin1 (dipakai luas di banyak file lain, jadi
// TIDAK diubah global) -- balasan AI di sini sering berisi emoji/karakter
// unicode (📋💳dsb), kalau di-INSERT lewat koneksi latin1 ke kolom utf8mb4
// jadi mojibake. Override khusus koneksi ini saja.
mysqli_set_charset($conn, 'utf8mb4');

livechatAiEnsureTable($conn);

$cfg = file_exists(__DIR__ . '/../../config.json') ? json_decode(file_get_contents(__DIR__ . '/../../config.json'), true) : [];
$domain = trim((string)($cfg['domain'] ?? ''));

$enabledTenants = [];
$resTenant = mysqli_query($conn, "SELECT pemilik FROM livechat_ai_settings WHERE ai_enabled = 1");
while ($resTenant && ($row = mysqli_fetch_assoc($resTenant))) {
    $p = trim((string)($row['pemilik'] ?? ''));
    if ($p !== '') {
        $enabledTenants[] = $p;
    }
}

if (empty($enabledTenants)) {
    echo "Tidak ada tenant dengan AI Bot Live Chat aktif.\n";
    exit;
}

$totalReplied = 0;

// Cari token session AKTIF (belum revoked, belum expired) milik satu IDPEL,
// dari tabel session ASLI yg dibuat saat pelanggan login ke portal -- bukan
// bikin token baru/palsu. Kalau pelanggan belum pernah login/sesinya sudah
// mati, ya tidak ketemu (aksi yg butuh token di-skip, wajar).
function livechatAiCronFindActiveToken($conn, $idpel)
{
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $res = mysqli_query($conn, "SELECT token FROM twk_mobile_sessions WHERE idpel = '$idpelEsc' AND (revoked_at IS NULL) AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return $row ? (string)$row['token'] : '';
}

foreach ($enabledTenants as $pemilik) {
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);

    // Pesan pelanggan (sender != pemilik, receiver = pemilik) yang belum dijawab:
    // waktu pesan terakhir dari customer itu > waktu balasan terakhir pemilik ke
    // customer itu (atau belum pernah dibalas sama sekali).
    $sql = "SELECT c.customer_id
            FROM (
                SELECT sender_id AS customer_id, MAX(timestamp) AS last_time
                FROM messages
                WHERE receiver_id = '$pemilikEsc' AND sender_id <> '$pemilikEsc'
                GROUP BY sender_id
            ) c
            LEFT JOIN (
                SELECT receiver_id AS customer_id, MAX(timestamp) AS last_time
                FROM messages
                WHERE sender_id = '$pemilikEsc'
                GROUP BY receiver_id
            ) r ON c.customer_id = r.customer_id
            WHERE r.last_time IS NULL OR c.last_time > r.last_time";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        echo "[$pemilik] Query gagal: " . mysqli_error($conn) . "\n";
        continue;
    }

    while ($row = mysqli_fetch_assoc($res)) {
        $customerId = (string)$row['customer_id'];
        $customerIdEsc = mysqli_real_escape_string($conn, $customerId);

        // Ambil isi pesan TERAKHIR dari customer ini (yang belum dijawab).
        $msgRes = mysqli_query($conn, "SELECT message FROM messages WHERE sender_id = '$customerIdEsc' AND receiver_id = '$pemilikEsc' ORDER BY id DESC LIMIT 1");
        $msgRow = $msgRes ? mysqli_fetch_assoc($msgRes) : null;
        if (!$msgRow) {
            continue;
        }
        $lastMessage = (string)$msgRow['message'];

        // Ekstrak IDPEL & cari nama+session dari identity "<IDPEL> ( Whatsapp : <NOWA> )".
        $idpelGuess = '';
        $customerName = '';
        $pelangganRow = null;
        if (preg_match('/^(.*?)\s*\(\s*Whatsapp/i', $customerId, $mId)) {
            $idpelGuess = trim($mId[1]);
            $idpelGuessEsc = mysqli_real_escape_string($conn, $idpelGuess);
            $pelangganRes = mysqli_query($conn, "SELECT * FROM pelanggan WHERE TRIM(IDPEL) = '$idpelGuessEsc' LIMIT 1");
            $pelangganRow = $pelangganRes ? mysqli_fetch_assoc($pelangganRes) : null;
            if ($pelangganRow) {
                $customerName = (string)($pelangganRow['NAMA'] ?? '');
            }
        }

        $reply = null;

        // Coba keyword aksi dulu (cek data/gangguan/bayar/menu/jawaban kustom) --
        // butuh token session aktif utk aksi yg loopback ke API mobile.
        if ($pelangganRow && $idpelGuess !== '' && $domain !== '') {
            $token = livechatAiCronFindActiveToken($conn, $idpelGuess);
            if ($token !== '') {
                $reply = livechatAiHandleKeywordAction($conn, $pemilik, $customerId, $domain, $token, $pelangganRow, $idpelGuess, $lastMessage);
            }
        }

        // Kalau tidak ada keyword aksi yang cocok (atau tidak ada token), lanjut ke AI chat bebas.
        if ($reply === null) {
            $reply = livechatAiMaybeReply($conn, $pemilik, $customerId, $customerName, $lastMessage);
        } else {
            // livechatAiHandleKeywordAction() cuma return teks, belum nyimpan ke tabel
            // messages (itu tanggung jawab pemanggil, sama seperti pola di chat_send.php).
            $stmtReply = mysqli_prepare($conn, "INSERT INTO messages (sender_id, receiver_id, message, timestamp, is_read) VALUES (?, ?, ?, NOW(), 0)");
            if ($stmtReply) {
                mysqli_stmt_bind_param($stmtReply, 'sss', $pemilik, $customerId, $reply);
                mysqli_stmt_execute($stmtReply);
                mysqli_stmt_close($stmtReply);
            }
        }

        if ($reply !== null) {
            $totalReplied++;
            echo "[$pemilik] Balas $customerId: OK\n";
        } else {
            echo "[$pemilik] Balas $customerId: GAGAL/skip (lihat Log AI di halaman Pengaturan Live Chat)\n";
        }

        // Jeda kecil antar panggilan AI biar tidak membanjiri provider sekaligus.
        usleep(300000);
    }
}

echo "Selesai. Total dibalas: $totalReplied\n";
