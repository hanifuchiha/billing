<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';

// Pastikan tabel relasi odp_server ada (multi-product per ODP)
$tbl_check = mysqli_query($conn, "SHOW TABLES LIKE 'odp_server'");
if (!$tbl_check || mysqli_num_rows($tbl_check) == 0) {
    mysqli_query($conn, "CREATE TABLE odp_server (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odp_kode VARCHAR(255) NOT NULL,
        pemilik VARCHAR(255) NOT NULL,
        area VARCHAR(255) NOT NULL,
        KEY idx_odp_kode (odp_kode),
        KEY idx_pemilik_area (pemilik, area)
    )");
}

function normalize_product_pairs($pairs) {
    $normalized = [];
    foreach ($pairs as $pair) {
        $pemilik = trim((string)($pair['pemilik'] ?? ''));
        $area = trim((string)($pair['area'] ?? ''));
        if ($pemilik === '' || $area === '') continue;
        $normalized[$pemilik . '|' . $area] = true;
    }
    $keys = array_keys($normalized);
    sort($keys, SORT_STRING);
    return $keys;
}

function get_odp_relation_pairs($conn, $kode, $fallbackPemilik = '', $fallbackArea = '') {
    $pairs = [];
    $kodeEsc = mysqli_real_escape_string($conn, $kode);
    $qRel = mysqli_query($conn, "SELECT pemilik, area FROM odp_server WHERE odp_kode='$kodeEsc'");
    if ($qRel) {
        while ($rel = mysqli_fetch_assoc($qRel)) {
            $pairs[] = ['pemilik' => $rel['pemilik'], 'area' => $rel['area']];
        }
    }
    if (empty($pairs) && $fallbackPemilik !== '' && $fallbackArea !== '') {
        $pairs[] = ['pemilik' => $fallbackPemilik, 'area' => $fallbackArea];
    }
    return normalize_product_pairs($pairs);
}

function can_use_odp_product($conn, $pemilik, $area) {
    global $AKSES, $current_user_id, $arealist;
    $pemilik = trim((string)$pemilik);
    $area = trim((string)$area);
    if ($pemilik === '' || $area === '') return false;

    if ($AKSES == 'ASSISTANT') {
        return isset($arealist) && is_array($arealist) && in_array($area, $arealist, true);
    }

    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc = mysqli_real_escape_string($conn, $area);
    $uid = (int)$current_user_id;
    $q = mysqli_query($conn, "SELECT id FROM server WHERE user_id=$uid AND PEMILIK='$pemilikEsc' AND AREA='$areaEsc' LIMIT 1");
    return $q && mysqli_num_rows($q) > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id      = isset($_POST['id'])    ? intval($_POST['id'])                                  : 0;
    $kode    = isset($_POST['kode'])  ? mysqli_real_escape_string($conn, trim($_POST['kode'])) : '';
    $name    = isset($_POST['name'])  ? mysqli_real_escape_string($conn, trim($_POST['name'])) : '';
    $tikor   = isset($_POST['tikor']) ? mysqli_real_escape_string($conn, trim($_POST['tikor'])): '';
    $hirarki = isset($_POST['hirarki'])        ? mysqli_real_escape_string($conn, $_POST['hirarki'])        : '';
    $splitter        = isset($_POST['splitter'])        ? mysqli_real_escape_string($conn, $_POST['splitter'])        : '';
    $hirarki_parent  = isset($_POST['hirarki_parent'])  ? mysqli_real_escape_string($conn, $_POST['hirarki_parent'])  : '';

    // -------------------------------------------------------
    // Bangun product_pairs dari area_map (JSON) yang dikirim JS
    // Format area_map: {"PEMILIK1":"AREA1","PEMILIK2":"AREA2"}
    // -------------------------------------------------------
    $area_map_raw = isset($_POST['area_map']) ? trim((string)$_POST['area_map']) : '{}';

    // Fallback: jika area_map kosong/tidak valid, coba bangun dari server[] + data-area
    $area_map = json_decode($area_map_raw, true);
    if (!is_array($area_map) || empty($area_map)) {
        $area_map = [];
    }

    // Ambil daftar server[] yang dicentang
    $server_list_raw = isset($_POST['server']) ? (array)$_POST['server'] : [];

    // Susun pasangan unik (pemilik, area)
    $product_pairs = [];
    foreach ($server_list_raw as $pemilik_raw) {
        $pemilik_raw = trim((string)$pemilik_raw);
        if ($pemilik_raw === '') continue;
        $area_raw = isset($area_map[$pemilik_raw]) ? trim((string)$area_map[$pemilik_raw]) : '';
        // Jika area_map tidak memiliki entry untuk pemilik ini,
        // coba ambil dari DB (fallback agar ODP lama tidak error)
        if ($area_raw === '') {
            $q = mysqli_query($conn, "SELECT AREA FROM server WHERE PEMILIK='" . mysqli_real_escape_string($conn, $pemilik_raw) . "' LIMIT 1");
            if ($q && $r = mysqli_fetch_assoc($q)) {
                $area_raw = trim((string)$r['AREA']);
            }
        }
        if ($area_raw === '') continue;
        $key = $pemilik_raw . '|' . $area_raw;
        $product_pairs[$key] = ['pemilik' => $pemilik_raw, 'area' => $area_raw];
    }
    $product_pairs = array_values($product_pairs);

    // Validasi
    if ($id <= 0 || $kode === '' || $name === '' || $tikor === '' || empty($product_pairs)) {
        $missing = [];
        if ($id <= 0)              $missing[] = 'ID tidak valid';
        if ($kode === '')          $missing[] = 'Kode kosong';
        if ($name === '')          $missing[] = 'Nama kosong';
        if ($tikor === '')         $missing[] = 'Koordinat kosong';
        if (empty($product_pairs)) $missing[] = 'Minimal 1 Server Area harus dipilih (server[]=' . json_encode($server_list_raw) . ', area_map=' . $area_map_raw . ')';
        $msg = 'Data tidak lengkap: ' . implode('; ', $missing);
        header("Location: ../odp.php?status=error&msg=" . urlencode($msg));
        exit();
    }
    foreach ($product_pairs as $pair) {
        if (!can_use_odp_product($conn, $pair['pemilik'], $pair['area'])) {
            header("Location: ../odp.php?status=error&msg=" . urlencode("Server Area tidak sesuai dengan akses akun ini."));
            exit();
        }
    }

    // PEMILIK/AREA utama = pasangan pertama (kompatibilitas kolom lama)
    $server = mysqli_real_escape_string($conn, $product_pairs[0]['pemilik']);
    $area   = mysqli_real_escape_string($conn, $product_pairs[0]['area']);

    // Ambil BRAND dari server utama
    $brand = '';
    $q_brand = mysqli_query($conn, "SELECT BRAND FROM server WHERE PEMILIK='$server' LIMIT 1");
    if ($q_brand && $rb = mysqli_fetch_assoc($q_brand)) { $brand = mysqli_real_escape_string($conn, $rb['BRAND']); }

    // Ambil data lama (untuk update pelanggan & odp_server)
    $old_kode = '';
    $old_pemilik = '';
    $old_area = '';
    $q_old = mysqli_query($conn, "SELECT KODE, PEMILIK, AREA FROM odp WHERE id=$id");
    if ($q_old && $r_old = mysqli_fetch_assoc($q_old)) {
        $old_kode = (string)$r_old['KODE'];
        $old_pemilik = (string)$r_old['PEMILIK'];
        $old_area = (string)$r_old['AREA'];
    } else {
        header("Location: ../odp.php?status=error&msg=" . urlencode("Data ODP tidak ditemukan."));
        exit();
    }

    $submittedRelationKeys = normalize_product_pairs($product_pairs);
    $targetDuplicateCount = 0;
    $q_dup = mysqli_query($conn, "SELECT COUNT(*) AS total FROM odp WHERE KODE='$kode' AND id<>$id");
    if ($q_dup && $r_dup = mysqli_fetch_assoc($q_dup)) {
        $targetDuplicateCount = (int)($r_dup['total'] ?? 0);
    }

    if ($targetDuplicateCount > 0) {
        $existingTargetRelationKeys = get_odp_relation_pairs($conn, $kode, $old_pemilik, $old_area);
        if ($submittedRelationKeys !== $existingTargetRelationKeys) {
            $msg = "Kode ODP $kode dipakai lebih dari satu data. Server Area tidak bisa diubah dari form ini karena akan mempengaruhi data lain dengan kode sama. Ubah Kode ODP agar unik dulu.";
            header("Location: ../odp.php?status=error&msg=" . urlencode($msg));
            exit();
        }
    }

    $sql = "UPDATE odp SET
                KODE           = '$kode',
                NAME           = '$name',
                TIKOR          = '$tikor',
                PEMILIK        = '$server',
                AREA           = '$area',
                BRAND          = '$brand',
                Hirarki        = '$hirarki',
                splitter       = '$splitter',
                hirarki_parent = '$hirarki_parent'
            WHERE id = $id";

    if (mysqli_query($conn, $sql)) {
        // Update pelanggan jika kode berubah
        if ($old_kode !== '' && $old_kode !== $kode) {
            mysqli_query($conn, "UPDATE pelanggan SET ODP='$kode' WHERE ODP='" . mysqli_real_escape_string($conn, $old_kode) . "'");
        }

        // Sinkronkan tabel relasi odp_server. Relasi masih berbasis KODE, jadi jangan
        // hapus relasi kode yang masih dipakai baris ODP lain.
        $remainingOldKodeRows = 0;
        if ($old_kode !== '' && $old_kode !== $kode) {
            $oldKodeEsc = mysqli_real_escape_string($conn, $old_kode);
            $q_remaining = mysqli_query($conn, "SELECT COUNT(*) AS total FROM odp WHERE KODE='$oldKodeEsc'");
            if ($q_remaining && $r_remaining = mysqli_fetch_assoc($q_remaining)) {
                $remainingOldKodeRows = (int)($r_remaining['total'] ?? 0);
            }
            if ($remainingOldKodeRows === 0) {
                mysqli_query($conn, "DELETE FROM odp_server WHERE odp_kode='$oldKodeEsc'");
            }
        }

        if ($targetDuplicateCount === 0) {
            mysqli_query($conn, "DELETE FROM odp_server WHERE odp_kode='$kode'");
            foreach ($product_pairs as $pair) {
                $p_ins = mysqli_real_escape_string($conn, $pair['pemilik']);
                $a_ins = mysqli_real_escape_string($conn, $pair['area']);
                mysqli_query($conn, "INSERT IGNORE INTO odp_server (odp_kode, pemilik, area) VALUES ('$kode','$p_ins','$a_ins')");
            }
        }

        // Upload gambar jika ada
        if (isset($_FILES['gambar_odp']) && $_FILES['gambar_odp']['error'] == 0) {
            $target_dir = dirname(__DIR__) . '/../../dokumen/odp/';
            if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
            $ext = strtolower(pathinfo($_FILES['gambar_odp']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) $ext = 'jpg';
            $target_file = $target_dir . 'odp_' . $id . '.jpg';
            $tmp_name    = $_FILES['gambar_odp']['tmp_name'];
            if (!in_array($ext, ['jpg','jpeg'])) {
                $img = null;
                if ($ext === 'png')  $img = imagecreatefrompng($tmp_name);
                elseif ($ext === 'gif')  $img = imagecreatefromgif($tmp_name);
                elseif ($ext === 'webp') $img = imagecreatefromwebp($tmp_name);
                if ($img) { imagejpeg($img, $target_file, 90); imagedestroy($img); }
                else { move_uploaded_file($tmp_name, $target_file); }
            } else {
                move_uploaded_file($tmp_name, $target_file);
            }
            $foto_url = '../../dokumen/odp/odp_' . $id . '.jpg';
            mysqli_query($conn, "UPDATE odp SET FOTO='" . mysqli_real_escape_string($conn, $foto_url) . "' WHERE id=$id");
        }

        // Log history
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) { $history = json_decode(file_get_contents($history_file), true); }
        if (!is_array($history)) { $history = []; }
        $product_count = count($product_pairs);
        $display_name  = !empty($asistant_name) ? $asistant_name : $ceknama;
        $history[] = "[ $display_name - " . date('Y-m-d H:i:s') . " ] Berhasil edit ODP $name (terhubung ke $product_count product)";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        $msg = urlencode("ODP $name berhasil diperbarui.");
        header("Location: ../odp.php?status=success&msg=$msg");
        exit();
    } else {
        $err = mysqli_error($conn);
        header("Location: ../odp.php?status=error&msg=" . urlencode("Gagal update: $err"));
        exit();
    }
} else {
    header("Location: ../odp.php");
    exit();
}
