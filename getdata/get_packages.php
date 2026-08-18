<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar
require_once __DIR__ . '/../staticip_helper.php';
staticipEnsureSchema($conn);

if (isset($_GET['server']) && isset($_GET['area'])) {
    $server = mysqli_real_escape_string($conn, $_GET['server']);
    $area = mysqli_real_escape_string($conn, $_GET['area']);

    // TIPE_LAYANAN default 'PPPOE' (kolom lama otomatis NULL/'PPPOE') -- endpoint
    // ini dipakai form Customer PPPoE biasa MAUPUN form Customer Static IP,
    // dibedakan lewat param tipe_layanan supaya paket Static IP tidak nyampur
    // ke dropdown PPPoE biasa (dan sebaliknya). tipe_layanan=ALL (Multi Layanan
    // Corporate, LAMA -- sebelum ada tabel paket_corporate sendiri) mengembalikan
    // KEDUA jenis dari tabel `paket` sekaligus, value-nya jadi `id` numerik
    // (bukan nama) krn corporate_layanan.paket_id butuh FK. tipe_layanan=CORPORATE
    // (SEKARANG dipakai corporate_layanan.php) query tabel TERPISAH
    // `paket_corporate` -- SENGAJA tidak reuse tabel `paket` residential sama
    // sekali (permintaan eksplisit: paket Corporate jangan tercampur pool paket
    // PPPoE/Static IP biasa). 2 cabang lama (PPPOE_STATIC/default) tetap pakai
    // value=nama demi backward-compat caller lama (addcustomerform.php/
    // tablesstaticip.php kirim nama paket).
    $tipeLayananParam = $_GET['tipe_layanan'] ?? '';
    if ($tipeLayananParam === 'CORPORATE') {
        $query = "SELECT * FROM paket_corporate WHERE `PEMILIK` = '$server' AND `AREA` = '$area'";
        $result = mysqli_query($conn, $query);
        $rows = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $rows[] = $row;
            }
        }
        $rows = paketVisibilityFilterRows($rows, $assistant_hidden_paket_corporate, 'PAKET');
        if (count($rows) > 0) {
            echo '<option value="">-- Pilih Packages --</option>';
            foreach ($rows as $row) {
                echo '<option value="' . (int) $row['id'] . '" data-nama="' . htmlspecialchars($row['PAKET'], ENT_QUOTES) . '" data-kecepatan="' . htmlspecialchars($row['KECEPATAN'], ENT_QUOTES) . '">' . htmlspecialchars($row['PAKET']) . ' (' . htmlspecialchars($row['KECEPATAN']) . ')</option>';
            }
        } else {
            echo "<option disabled value=''>Tidak ada Paket Corporate di $area  $server -- tambah dulu di menu Paket Corporate</option>";
        }
        exit;
    }
    if ($tipeLayananParam === 'PPPOE_STATIC') {
        $query = "SELECT * FROM paket WHERE `PEMILIK` = '$server' AND `AREA` = '$area' AND `TIPE_LAYANAN` = 'PPPOE_STATIC'";
    } elseif ($tipeLayananParam === 'ALL') {
        $query = "SELECT * FROM paket WHERE `PEMILIK` = '$server' AND `AREA` = '$area'";
    } else {
        $query = "SELECT * FROM paket WHERE `PEMILIK` = '$server' AND `AREA` = '$area' AND (`TIPE_LAYANAN` IS NULL OR `TIPE_LAYANAN` != 'PPPOE_STATIC')";
    }
    $result = mysqli_query($conn, $query);
    $rows = reseller_filter_rows($conn, reseller_collect_rows($result), 'broadband');
    $rows = paketVisibilityFilterRows($rows, $assistant_hidden_paket_broadband, 'PAKET');

    if (count($rows) > 0) {
        echo '<option value="">-- Pilih Packages --</option>';
        foreach ($rows as $row) {
            if ($tipeLayananParam === 'ALL') {
                echo '<option value="' . (int) $row['id'] . '" data-nama="' . htmlspecialchars($row['PAKET'], ENT_QUOTES) . '" data-kecepatan="' . htmlspecialchars($row['KECEPATAN'], ENT_QUOTES) . '">' . htmlspecialchars($row['PAKET']) . ' (' . htmlspecialchars($row['KECEPATAN']) . ')</option>';
            } else {
                echo '<option value="' . htmlspecialchars($row['PAKET']) . '">' . htmlspecialchars($row['PAKET']) . '</option>';
            }
        }
    } else {
        echo "<option disabled value=''>Tidak ada Packages di $area  $server tersedia</option>";
    }
}
exit;
