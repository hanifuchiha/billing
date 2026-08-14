<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';


// Pastikan kolom FOTO ada di tabel ODP
$foto_column_check = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE 'FOTO'");
if ($foto_column_check && mysqli_num_rows($foto_column_check) == 0) {
    mysqli_query($conn, "ALTER TABLE odp ADD COLUMN FOTO VARCHAR(255) DEFAULT NULL");
}

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
    $kode       = $_POST['kode'];
    $name       = $_POST['name'];
    $coordinates = $_POST['coordinates'];
    $hirarki    = $_POST['hirarki'];
    $splitter   = $_POST['splitter'];
    $hirarki_parent = $_POST['hirarki_parent'];

    // server[] = daftar PEMILIK yang dicentang (checkbox multi-product)
    // area_map[PEMILIK] = AREA yang sesuai (dikirim via hidden input dari JS)
    $server_list_raw = isset($_POST['server']) ? (array)$_POST['server'] : [];
    $area_map_raw    = isset($_POST['area_map']) ? $_POST['area_map'] : '{}';
    $area_map = json_decode($area_map_raw, true);
    if (!is_array($area_map)) { $area_map = []; }

    // Bersihkan & susun pasangan (pemilik, area) unik
    $product_pairs = [];
    foreach ($server_list_raw as $pemilik_raw) {
        $pemilik_raw = trim((string)$pemilik_raw);
        if ($pemilik_raw === '') continue;
        $area_raw = isset($area_map[$pemilik_raw]) ? trim((string)$area_map[$pemilik_raw]) : '';
        if ($area_raw === '') continue;
        $key = $pemilik_raw . '|' . $area_raw;
        $product_pairs[$key] = ['pemilik' => $pemilik_raw, 'area' => $area_raw];
    }
    $product_pairs = array_values($product_pairs);

    if (empty($product_pairs)) {
        header("Location: ../odp.php?status=error&msg=" . urlencode("Pilih minimal 1 Server Area."));
        exit();
    }
    foreach ($product_pairs as $pair) {
        if (!can_use_odp_product($conn, $pair['pemilik'], $pair['area'])) {
            header("Location: ../odp.php?status=error&msg=" . urlencode("Server Area tidak sesuai dengan akses akun ini."));
            exit();
        }
    }

    // PEMILIK/AREA utama (untuk kompatibilitas kolom lama) = pasangan pertama
    $server = $product_pairs[0]['pemilik'];
    $area   = $product_pairs[0]['area'];

    // Validasi berdasarkan hirarki
    if ($hirarki == 'ODP') {
        if (empty($hirarki_parent)) {
            header("Location: ../odp.php?status=error&msg=" . urlencode("Untuk hirarki ODP, harus pilih ODC parent."));
            exit();
        }
        // Cek splitter ODC dan kapasitas
        $odc_query = "SELECT splitter FROM odp WHERE KODE = '" . mysqli_real_escape_string($conn, $hirarki_parent) . "' AND Hirarki = 'ODC'";
        $odc_result = mysqli_query($conn, $odc_query);
        if ($odc_result && mysqli_num_rows($odc_result) > 0) {
            $odc_data = mysqli_fetch_assoc($odc_result);
            $odc_splitter = $odc_data['splitter'];
            // Kapasitas berdasarkan splitter
            $kapasitas = 0;
            switch ($odc_splitter) {
                case '1:2': $kapasitas = 2; break;
                case '1:8': $kapasitas = 8; break;
                case '1:16': $kapasitas = 16; break;
                case '1:32': $kapasitas = 32; break;
            }
            // Hitung ODP yang sudah terhubung
            $count_query = "SELECT COUNT(*) as count FROM odp WHERE hirarki_parent = '" . mysqli_real_escape_string($conn, $hirarki_parent) . "' AND Hirarki = 'ODP'";
            $count_result = mysqli_query($conn, $count_query);
            $count = 0;
            if ($count_result) {
                $count_data = mysqli_fetch_assoc($count_result);
                $count = $count_data['count'];
            }
            if ($count >= $kapasitas) {
                header("Location: ../odp.php?status=error&msg=" . urlencode("ODC $hirarki_parent dengan splitter $odc_splitter sudah FULL ($count/$kapasitas). Mohon update data ODC sesuai aktual lapangan."));
                exit();
            }
        } else {
            header("Location: ../odp.php?status=error&msg=" . urlencode("ODC parent tidak ditemukan atau bukan ODC."));
            exit();
        }
    }
    // Untuk ODP-RASIO dan ODP-JUMPER, tidak perlu validasi ODC

    // PORT default 0 jika belum diisi
    $port = 0;

    // Get brand dari product utama (pasangan pertama)
    $brand = '';
    $server_query = "SELECT BRAND FROM server WHERE PEMILIK = '" . mysqli_real_escape_string($conn, $server) . "' AND AREA = '" . mysqli_real_escape_string($conn, $area) . "' LIMIT 1";
    $server_result = mysqli_query($conn, $server_query);

    if ($server_result && mysqli_num_rows($server_result) > 0) {
        $server_data = mysqli_fetch_assoc($server_result);
        $brand = $server_data['BRAND'];
    }

    // Siapkan variabel untuk path foto
    $foto_url = '';

    // Simpan ke database dengan brand (sementara tanpa foto)
    // PEMILIK/AREA diisi dari product utama (pasangan pertama) demi kompatibilitas kode lama.
    $query = "INSERT INTO odp (NAME, KODE, PORT, PEMILIK, AREA, TIKOR, BRAND, Hirarki, splitter, hirarki_parent) 
              VALUES ('" . mysqli_real_escape_string($conn, $name) . "', 
                      '" . mysqli_real_escape_string($conn, $kode) . "', 
                      '$port', 
                      '" . mysqli_real_escape_string($conn, $server) . "', 
                      '" . mysqli_real_escape_string($conn, $area) . "', 
                      '" . mysqli_real_escape_string($conn, $coordinates) . "',
                      '" . mysqli_real_escape_string($conn, $brand) . "',
                      '" . mysqli_real_escape_string($conn, $hirarki) . "',
                      '" . mysqli_real_escape_string($conn, $splitter) . "',
                      '" . mysqli_real_escape_string($conn, $hirarki_parent) . "')";

    if (mysqli_query($conn, $query)) {
        $last_id = mysqli_insert_id($conn);

        // Simpan semua relasi Product (multi) ke tabel odp_server
        foreach ($product_pairs as $pair) {
            $ins_rel = "INSERT IGNORE INTO odp_server (odp_kode, pemilik, area) VALUES (
                '" . mysqli_real_escape_string($conn, $kode) . "',
                '" . mysqli_real_escape_string($conn, $pair['pemilik']) . "',
                '" . mysqli_real_escape_string($conn, $pair['area']) . "'
            )";
            mysqli_query($conn, $ins_rel);
        }

        // Upload gambar jika ada
        if (isset($_FILES['gambar_odp']) && $_FILES['gambar_odp']['error'] == 0) {
            $target_dir = dirname(__DIR__) . '/../../dokumen/odp/';
            if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
            $ext = pathinfo($_FILES['gambar_odp']['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) $ext = 'jpg';
            $target_file = $target_dir . 'odp_' . $last_id . '.jpg';
            $tmp_name = $_FILES['gambar_odp']['tmp_name'];
            // Convert to jpg if not jpg
            if ($ext !== 'jpg' && $ext !== 'jpeg') {
                $img = null;
                if ($ext === 'png') $img = imagecreatefrompng($tmp_name);
                elseif ($ext === 'gif') $img = imagecreatefromgif($tmp_name);
                elseif ($ext === 'webp') $img = imagecreatefromwebp($tmp_name);
                if ($img) {
                    imagejpeg($img, $target_file, 90);
                    imagedestroy($img);
                } else {
                    move_uploaded_file($tmp_name, $target_file);
                }
            } else {
                move_uploaded_file($tmp_name, $target_file);
            }
            // Simpan url relatif ke kolom foto
            $foto_url = '../../dokumen/odp/odp_' . $last_id . '.jpg';
        }
        // Update kolom FOTO jika ada url
        if ($foto_url) {
            mysqli_query($conn, "UPDATE odp SET FOTO = '" . mysqli_real_escape_string($conn, $foto_url) . "' WHERE id = $last_id");
        }
        ////////////catatat di log////////////////////
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }
        if (!is_array($history)) {
            $history = [];
        }
        $product_count = count($product_pairs);
        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menambahkan odp baru '$name', '$kode', '$port', '$server', '$area', '$coordinates' (terhubung ke $product_count product)";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
        //////////////////////////////////////////////
        header("Location: ../odp.php?status=success&msg=".rawurlencode("Data ODP berhasil disimpan."));
        exit();
    } else {
        echo "Gagal menyimpan data: " . mysqli_error($conn);
    }
} else {
    echo "Invalid request.";
}
