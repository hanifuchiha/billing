<?php
// crontab_promo.php
// Jalankan via crontab: php /path/to/crontab_promo.php
include '../../koneksidb.php';// koneksi ke database

echo "<br>===============================<br>";
echo "[INFO] Mulai proses crontab promo...<br>";
echo "Tanggal: " . date('Y-m-d H:i:s') . "<br>";
echo "===============================<br>";
// Ambil semua pelanggan
$sql_pelanggan = "SELECT * FROM pelanggan";
$result_pelanggan = mysqli_query($conn, $sql_pelanggan);

while ($pel = mysqli_fetch_assoc($result_pelanggan)) {
    $idpel = $pel['IDPEL'];
    $paket_pelanggan = $pel['PAKET'];
    $tanggal_pasang = $pel['TANGGALPASANG'];
    echo "<br>-----------------------------------<br>";
    echo "[PELANGGAN] ID: $idpel<br>";
    echo "  Paket Aktif   : $paket_pelanggan<br>";
    echo "  Tgl Pasang    : $tanggal_pasang<br>";

    // Cek apakah paket ini ada di promo_paket
    $sql_promo = "SELECT * FROM promo_paket WHERE paket_id = (SELECT id FROM paket WHERE PAKET = ?) LIMIT 1";
    $stmt_promo = mysqli_prepare($conn, $sql_promo);
    mysqli_stmt_bind_param($stmt_promo, 's', $paket_pelanggan);
    mysqli_stmt_execute($stmt_promo);
    $promo = mysqli_stmt_get_result($stmt_promo);
    if ($row_promo = mysqli_fetch_assoc($promo)) {
        echo "  [PROMO] Paket promo ditemukan<br>";
        $promo_durasi = (int)$row_promo['promo_durasi'];
        $promo_durasi_type = isset($row_promo['promo_durasi_type']) ? $row_promo['promo_durasi_type'] : 'bulan';
        $promo_mulai_type = $row_promo['promo_mulai_type'];
        $paket_pengganti = $row_promo['paket_pengganti'];
        $paket_pengganti_id = (int)$paket_pengganti;

        // Hitung tanggal mulai promo
        if ($promo_mulai_type == 'tanggal_pasang') {
            $start_date = $tanggal_pasang;
            echo "    Mulai Promo   : Tanggal Pasang ($start_date)<br>";
        } else if ($promo_mulai_type == 'transaksi_akhir') {
            // Ambil transaksi terakhir pelanggan
            $sql_trx = "SELECT MAX(DATE(waktu)) as last_trx FROM transaksi WHERE IDPEL = ? AND STATUS = 'BERHASIL'";
            $stmt_trx = mysqli_prepare($conn, $sql_trx);
            mysqli_stmt_bind_param($stmt_trx, 's', $idpel);
            mysqli_stmt_execute($stmt_trx);
            mysqli_stmt_bind_result($stmt_trx, $last_trx);
            mysqli_stmt_fetch($stmt_trx);
            mysqli_stmt_close($stmt_trx);
            $start_date = $last_trx;
            echo "    Mulai Promo   : Transaksi Terakhir ($start_date)<br>";
        } else {
            $start_date = null;
            echo "    [PROMO] Tidak diketahui tipe mulai promo<br>";
        }

        if ($start_date) {
            if ($promo_durasi_type == 'hari') {
                $promo_end = date('Y-m-d', strtotime("$start_date +$promo_durasi days"));
                $durasi_label = 'hari';
            } else {
                $promo_end = date('Y-m-d', strtotime("$start_date +$promo_durasi month"));
                $durasi_label = 'bulan';
            }
            $today = date('Y-m-d');
            echo "    Durasi Promo  : $promo_durasi $durasi_label<br>";
            echo "    Promo Berakhir: $promo_end<br>";
            // Jika promo sudah selesai dan paket pelanggan masih promo, update ke paket pengganti
            if ($today >= $promo_end && $paket_pelanggan == $pel['PAKET']) {
                // Ambil nama paket pengganti
                $sql_nama = "SELECT PAKET FROM paket WHERE id = ? LIMIT 1";
                $stmt_nama = mysqli_prepare($conn, $sql_nama);
                mysqli_stmt_bind_param($stmt_nama, 'i', $paket_pengganti_id);
                mysqli_stmt_execute($stmt_nama);
                mysqli_stmt_bind_result($stmt_nama, $nama_paket_pengganti);
                mysqli_stmt_fetch($stmt_nama);
                mysqli_stmt_close($stmt_nama);
                if ($nama_paket_pengganti) {
                    // Update paket pelanggan ke database
                    $sql_update = "UPDATE pelanggan SET PAKET = ? WHERE IDPEL = ?";
                    $stmt_update = mysqli_prepare($conn, $sql_update);
                    mysqli_stmt_bind_param($stmt_update, 'ss', $nama_paket_pengganti, $idpel);
                    if (mysqli_stmt_execute($stmt_update)) {
                        echo "    [UPDATE] Paket pelanggan $idpel diubah ke: $nama_paket_pengganti<br>";
                    } else {
                        echo "    [ERROR] Gagal update paket pelanggan $idpel<br>";
                    }
                    mysqli_stmt_close($stmt_update);
                } else {
                    echo "    [ERROR] Paket pengganti tidak ditemukan<br>";
                }
            } else {
                if ($today < $promo_end) {
                    $sisa = (strtotime($promo_end) - strtotime($today)) / 86400;
                    echo "    [INFO] Promo masih aktif, sisa $sisa hari lagi.<br>";
                } else {
                    echo "    [INFO] Paket pelanggan sudah bukan paket promo.<br>";
                }
            }
        } else {
            echo "    [INFO] Tidak ada tanggal mulai promo yang valid<br>";
        }
    } else {
        echo "  [INFO] Tidak ada promo untuk paket ini<br>";
    }

    // --- Catat log ke history pemilik ---
    // Cari USERNAME dari tabel user berdasarkan server yang PEMILIK dan AREA sama dengan pelanggan
    $sql_username = "SELECT u.USERNAME FROM `user` u JOIN server s ON u.id = s.user_id WHERE s.PEMILIK = ? AND s.AREA = ? LIMIT 1";
    $stmt_username = mysqli_prepare($conn, $sql_username);
    mysqli_stmt_bind_param($stmt_username, 'ss', $pel['PEMILIK'], $pel['AREA']);
    mysqli_stmt_execute($stmt_username);
    mysqli_stmt_bind_result($stmt_username, $username);
    mysqli_stmt_fetch($stmt_username);
    mysqli_stmt_close($stmt_username);

    if ($username) {
        $history_file = "../../notifbot/data/history-$username.json";
        if (!is_dir(dirname($history_file))) {
            mkdir(dirname($history_file), 0777, true);
        }
        $history = [];
        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true) ?: [];
        }
        $history[] = "[ system billing - " . date('Y-m-d H:i:s') . " ] Processed promo for customer $idpel";
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
    }
}
echo "<br>===============================<br>";
echo "[SELESAI] Proses crontab promo selesai.<br>";
echo "===============================<br>";
