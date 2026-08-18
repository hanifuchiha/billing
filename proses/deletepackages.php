<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';
require('../routeros_api.class.php');
require_once __DIR__ . '/paket_profile_helpers.php';

$API = new RouterosAPI();

// --- Ambil parameter dari POST ---
$id      = trim($_POST['id'] ?? '');
$paket   = trim($_POST['paket'] ?? '');
$area    = trim($_POST['area'] ?? '');
$pemilik = trim($_POST['pemilik'] ?? '');

// --- Validasi wajib ---
if (!$id || !$paket || !$area || !$pemilik) {
  header("Location: ../packages.php?deleted=0");
}

// --- Escape untuk keamanan SQL ---
$id_esc      = mysqli_real_escape_string($conn, $id);
$paket_esc   = mysqli_real_escape_string($conn, $paket);
$area_esc    = mysqli_real_escape_string($conn, $area);
$pemilik_esc = mysqli_real_escape_string($conn, $pemilik);

// --- Cek apakah paket masih digunakan oleh pelanggan DI SERVER INI ---
// Dibatasi PEMILIK + AREA (bukan cuma nama paket) supaya penghapusan satu server
// tidak ikut diblokir gara-gara pelanggan di server lain memakai paket dengan nama sama
// (paket dengan nama sama bisa terpasang di banyak server sekaligus).
$cek_pelanggan = mysqli_query($conn, "SELECT COUNT(*) as jumlah FROM pelanggan WHERE PAKET = '$paket_esc' AND PEMILIK = '$pemilik_esc' AND AREA = '$area_esc'");
$row_cek = mysqli_fetch_assoc($cek_pelanggan);
if ($row_cek['jumlah'] > 0) {
    header("Location: ../packages.php?deleted=2"); // 2 untuk paket masih digunakan
    exit;
}

// --- Ambil data server sesuai area & pemilik ---
if (strtoupper($area) === 'ALL') {
    $sql = "SELECT * FROM server WHERE PEMILIK = '$pemilik_esc'";
} else {
    $sql = "SELECT * FROM server WHERE AREA = '$area_esc' AND PEMILIK = '$pemilik_esc'";
}

$q = mysqli_query($conn, $sql);
if (!$q) {
  header("Location: ../packages.php?deleted=0");
}

// --- Loop tiap router target dan hapus profile/pool ---
while ($row = mysqli_fetch_assoc($q)) {
    $usernameip = $row['PEMILIK'];
    $hostip     = $row['IP'];
    $passwordip = $row['PASSWORD'];

    if ($API->connect($hostip, $usernameip, $passwordip)) {

        // Pagar kedua, langsung di router. Cek tabel `pelanggan` di atas tidak
        // melihat secret yang tidak (lagi) tercatat di database -- mis. pelanggan
        // berhenti yang secret-nya masih tertinggal, atau yang kolom PAKET-nya
        // sudah menyimpang. Menghapus PPP Profile yang masih direferensikan
        // secret meninggalkan referensi menggantung: kolom profile secret itu
        // berubah jadi "*15"/unknown di Winbox maupun saat dibaca lewat API.
        $stillUsed = mikrotikCountSecretsUsingProfile($API, $paket);
        if ($stillUsed > 0) {
            $API->disconnect();
            header("Location: ../packages.php?deleted=2");
            exit;
        }

        // Hapus PPP Profile jika ada
        $profiles = $API->comm("/ppp/profile/print", ["?name" => $paket]);
        if (!empty($profiles)) {
            foreach ($profiles as $p) {
                if (isset($p['.id'])) {
                    $API->comm("/ppp/profile/remove", [".id" => $p['.id']]);
                }
            }
        }

        // Hapus IP Pool jika ada
        $pools = $API->comm("/ip/pool/print", ["?name" => $paket]);
        if (!empty($pools)) {
            foreach ($pools as $pp) {
                if (isset($pp['.id'])) {
                    $API->comm("/ip/pool/remove", [".id" => $pp['.id']]);
                }
            }
        }

        // Catat log ke file history
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) $history = [];
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus paket '$paket'";
        @file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        $API->disconnect();
    } else {
       header("Location: ../packages.php?deleted=0");
    }
}

// --- Hapus dari database ---
$del = mysqli_query($conn, "DELETE FROM paket WHERE id = '$id_esc'");
if (!$del) {
  header("Location: ../packages.php?deleted=0");
}

// --- Redirect setelah sukses ---
header("Location: ../packages.php?deleted=1");
exit;
?>
