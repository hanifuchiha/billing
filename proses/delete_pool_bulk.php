<?php
ob_start();
session_start();
require '../header.php';

/**
 * proses/delete_pool_bulk.php
 * Menghapus beberapa baris IP Pool sekaligus berdasarkan checkbox terpilih.
 * Hanya menghapus pool yang memang milik user yang login ($ceknama).
 * Pool yang masih dipakai oleh paket (FK constraint / referensi) akan dilewati.
 */

/**
 * Helper redirect: bersihkan semua output buffer sebelum kirim header Location,
 * supaya redirect tidak gagal jadi halaman putih.
 */
function redirectTo($url) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Location: $url");
    exit;
}

$ids = $_POST['id'] ?? [];

if (!is_array($ids) || count($ids) === 0) {
    redirectTo("../pool.php?statusnotif=bulk_delete_failed&text=" . urlencode("Tidak ada IP Pool yang dipilih."));
}

// Pastikan semua id berupa angka (sanitasi)
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($v) {
    return $v > 0;
})));

if (count($ids) === 0) {
    redirectTo("../pool.php?statusnotif=bulk_delete_failed&text=" . urlencode("ID IP Pool tidak valid."));
}

$pemilik_esc = mysqli_real_escape_string($conn, $ceknama);

$deleted = 0;
$skipped = 0;
$errors  = [];

foreach ($ids as $id) {
    // Pastikan pool ini memang milik user yang login
    $sqlCheck = "SELECT `id`, `pool_name` FROM `pool` WHERE `id` = $id AND `pemilik` = '$pemilik_esc' LIMIT 1";
    $resCheck = mysqli_query($conn, $sqlCheck);

    if (!$resCheck || mysqli_num_rows($resCheck) === 0) {
        $skipped++;
        $errors[] = "Pool ID $id tidak ditemukan atau bukan milik Anda.";
        continue;
    }

    $rowPool = mysqli_fetch_assoc($resCheck);
    $poolName = $rowPool['pool_name'];

    // Cek apakah pool masih dipakai oleh paket (sesuaikan nama tabel/kolom referensi jika berbeda)
    $sqlUsage = "SELECT COUNT(*) AS total FROM `paket` WHERE `pool_name` = '" . mysqli_real_escape_string($conn, $poolName) . "'";
    $resUsage = @mysqli_query($conn, $sqlUsage);

    if ($resUsage) {
        $usageRow = mysqli_fetch_assoc($resUsage);
        if ((int)$usageRow['total'] > 0) {
            $skipped++;
            $errors[] = "Pool '$poolName' masih digunakan oleh paket, tidak dapat dihapus.";
            continue;
        }
    }
    // Jika tabel `paket` atau kolom `pool_name` tidak ada, query di atas akan gagal (resUsage === false)
    // dan kita anggap tidak ada pengecekan ketergantungan tambahan untuk pool ini (lanjut hapus).

    $sqlDelete = "DELETE FROM `pool` WHERE `id` = $id AND `pemilik` = '$pemilik_esc'";
    if (mysqli_query($conn, $sqlDelete)) {
        $deleted++;
    } else {
        $skipped++;
        $errors[] = "Gagal menghapus pool '$poolName': " . mysqli_error($conn);
    }
}

$summaryBase = "Berhasil dihapus: $deleted, Dilewati/gagal: $skipped.";

if (count($errors) > 0) {
    $errorDetail = implode(' | ', array_slice($errors, 0, 10));
    if (count($errors) > 10) {
        $errorDetail .= ' | ... (' . (count($errors) - 10) . ' error lainnya tidak ditampilkan)';
    }

    if ($deleted === 0) {
        redirectTo("../pool.php?statusnotif=bulk_delete_failed&text=" . urlencode("$summaryBase Detail: $errorDetail"));
    } else {
        redirectTo("../pool.php?statusnotif=bulk_delete_partial&text=" . urlencode("$summaryBase Detail: $errorDetail"));
    }
}

redirectTo("../pool.php?statusnotif=bulk_delete_success&text=" . urlencode($summaryBase));