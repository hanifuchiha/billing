<?php
/**
 * fix_mojibake_messages_one_time.php
 *
 * Skrip perbaikan SATU KALI (bukan cron, jangan dijadwalkan) utk pesan lama
 * di tabel `messages` (Live Chat) yang sudah kepalang tersimpan rusak
 * (mojibake) akibat livechat_ai_poll_cron.php dulu menulis lewat koneksi
 * DB latin1 (koneksidb.php) padahal kolomnya utf8mb4 -- sudah diperbaiki di
 * cron-nya (mysqli_set_charset utf8mb4), tapi perbaikan itu cuma mencegah
 * korupsi BARU, tidak membetulkan pesan LAMA yang sudah terlanjur rusak.
 *
 * Cara pakai: buka sekali lewat browser
 *   https://<domain>/crm/billing/fix_mojibake_messages_one_time.php?confirm=1
 * Setelah selesai jalan & hasilnya sesuai, HAPUS file ini dari server (tidak
 * perlu disimpan permanen, ini bukan bagian dari sistem yang jalan rutin).
 */

require_once __DIR__ . '/koneksidb.php';
mysqli_set_charset($conn, 'utf8mb4');

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['confirm'] ?? '') !== '1') {
    echo "Tambahkan ?confirm=1 di URL utk menjalankan perbaikan.\n";
    exit;
}

// Heuristik deteksi mojibake: karakter penanda hasil UTF-8 yang ditulis ulang
// lewat koneksi latin1 (Ã/Â/â€ dkk) -- sangat tidak wajar muncul di teks
// Indonesia asli, jadi aman dipakai sbg filter awal.
$sql = "SELECT id, message FROM messages
        WHERE message LIKE '%Ã%' OR message LIKE '%â€%' OR message LIKE '%Â%'
        ORDER BY id ASC";
$result = mysqli_query($conn, $sql);

if (!$result) {
    echo "Query gagal: " . mysqli_error($conn) . "\n";
    exit;
}

$totalCandidate = 0;
$totalFixed = 0;
$totalSkipped = 0;

$updateStmt = mysqli_prepare($conn, "UPDATE messages SET message = ? WHERE id = ?");

while ($row = mysqli_fetch_assoc($result)) {
    $totalCandidate++;
    $id = (int)$row['id'];
    $original = (string)$row['message'];

    // Balikkan korupsi: teks tersimpan (UTF-8 hasil double-encode lewat
    // latin1) di-encode ulang sbg ISO-8859-1 -- byte hasilnya = UTF-8 asli
    // sebelum rusak.
    $fixed = @mb_convert_encoding($original, 'ISO-8859-1', 'UTF-8');

    if ($fixed === false || $fixed === $original || !mb_check_encoding($fixed, 'UTF-8')) {
        $totalSkipped++;
        echo "[SKIP] id=$id (hasil konversi tidak valid/tidak berubah)\n";
        continue;
    }

    mysqli_stmt_bind_param($updateStmt, 'si', $fixed, $id);
    if (mysqli_stmt_execute($updateStmt)) {
        $totalFixed++;
        echo "[FIXED] id=$id\n";
        echo "  before: " . substr($original, 0, 80) . "\n";
        echo "  after : " . substr($fixed, 0, 80) . "\n";
    } else {
        $totalSkipped++;
        echo "[GAGAL UPDATE] id=$id: " . mysqli_stmt_error($updateStmt) . "\n";
    }
}

mysqli_stmt_close($updateStmt);

echo "\n=== Selesai ===\n";
echo "Kandidat ditemukan : $totalCandidate\n";
echo "Berhasil diperbaiki: $totalFixed\n";
echo "Dilewati/gagal     : $totalSkipped\n";
