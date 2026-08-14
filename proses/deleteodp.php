<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../cek-sesi.php';

function can_manage_odp_id($conn, $id) {
    global $AKSES, $current_user_id, $arealist;
    $id = (int)$id;
    if ($id <= 0) return false;

    if ($AKSES == 'ASSISTANT') {
        if (!isset($arealist) || !is_array($arealist) || empty($arealist)) return false;
        $areas = [];
        foreach ($arealist as $area) {
            $areas[] = "'" . mysqli_real_escape_string($conn, $area) . "'";
        }
        $areaListSql = implode(',', $areas);
        $q = mysqli_query($conn, "SELECT o.id FROM odp o WHERE o.id=$id AND (
            o.AREA IN ($areaListSql) OR EXISTS (
                SELECT 1 FROM odp_server os WHERE os.odp_kode=o.KODE AND os.area IN ($areaListSql)
            )
        ) LIMIT 1");
        return $q && mysqli_num_rows($q) > 0;
    }

    $uid = (int)$current_user_id;
    $q = mysqli_query($conn, "SELECT o.id FROM odp o WHERE o.id=$id AND (
        EXISTS (SELECT 1 FROM server s WHERE s.user_id=$uid AND s.PEMILIK=o.PEMILIK)
        OR EXISTS (
            SELECT 1 FROM odp_server os
            INNER JOIN server s ON s.PEMILIK=os.pemilik AND s.AREA=os.area
            WHERE os.odp_kode=o.KODE AND s.user_id=$uid
        )
    ) LIMIT 1");
    return $q && mysqli_num_rows($q) > 0;
}

    if (isset($_POST['id'])) {
        $id = mysqli_real_escape_string($conn, $_POST['id']);

        if (!can_manage_odp_id($conn, $id)) {
            header("Location: ../odp.php?status=error&msg=" . urlencode("ODP tidak sesuai dengan akses server akun ini."));
            exit();
        }

        // Ambil nama ODP sebelum hapus untuk log
        $sql_get = "SELECT NAME, KODE FROM odp WHERE id = '$id'";
        $result = mysqli_query($conn, $sql_get);
        if($result && mysqli_num_rows($result) > 0){
            $row = mysqli_fetch_assoc($result);
            $odp_name = $row['NAME'];
            $odp_kode = $row['KODE'];
        } else {
            echo "ODP tidak ditemukan.";
            exit();
        }

        // Cek apakah masih punya turunan hirarki (ODP/ODP-RASIO/ODP-JUMPER)
        $cek_turunan = mysqli_query($conn, "SELECT COUNT(*) as jumlah FROM odp WHERE hirarki_parent = '$odp_kode' AND id <> '$id'");
        $row_turunan = mysqli_fetch_assoc($cek_turunan);
        if (($row_turunan['jumlah'] ?? 0) > 0) {
            header("Location: ../odp.php?status=cannot_delete&msg=" . urlencode("ODC/ODP masih memiliki ODP turunan terkait. Hapus atau pindahkan turunan terlebih dahulu."));
            exit;
        }

        // Cek apakah ODP masih digunakan oleh pelanggan
        $cek_pelanggan = mysqli_query($conn, "SELECT COUNT(*) as jumlah FROM pelanggan WHERE ODP = '$odp_kode'");
        $row_cek = mysqli_fetch_assoc($cek_pelanggan);
        if ($row_cek['jumlah'] > 0) {
            header("Location: ../odp.php?status=cannot_delete&msg=" . urlencode("ODP masih digunakan oleh pelanggan, tidak dapat dihapus."));
            exit;
        }

        // Cek apakah ini ODC dan masih ada ODP terkait
        $cek_hirarki = mysqli_query($conn, "SELECT Hirarki FROM odp WHERE id = '$id'");
        $row_hirarki = mysqli_fetch_assoc($cek_hirarki);
        if ($row_hirarki['Hirarki'] == 'ODC') {
            $cek_odp = mysqli_query($conn, "SELECT COUNT(*) as jumlah FROM odp WHERE hirarki_parent = '$odp_kode'");
            $row_odp = mysqli_fetch_assoc($cek_odp);
            if ($row_odp['jumlah'] > 0) {
                header("Location: ../odp.php?status=cannot_delete&msg=" . urlencode("ODC masih memiliki ODP terkait, tidak dapat dihapus."));
                exit;
            }
        }

        // Hapus file foto jika ada
        $foto_path = '../../../dokumen/odp/odp_' . $id . '.jpg';
        if (file_exists($foto_path)) {
            unlink($foto_path);
        }

        $delete = mysqli_query($conn, "DELETE FROM odp WHERE id = '$id'");

        if ($delete) {
            // log history
            $history_file = "../notifbot/data/history-$ceknama.json";
            $history = [];
            if (file_exists($history_file)) {
                $history = json_decode(file_get_contents($history_file), true);
            }
            if (!is_array($history)) { $history = []; }
            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil menghapus ODP $odp_name";
            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
            header("Location: ../odp.php?status=success&msg=ODP%20berhasil%20dihapus");
            exit();
        } else {
            header("Location: ../odp.php?status=error&msg=Gagal%20menghapus%20ODP");
            exit();
        }
    }
