<?php
/**
 * export_transaksi_filter.php
 *
 * Bangun ulang WHERE clause listing Transaction.php SECARA IDENTIK, dipakai
 * bersama oleh export_pdf.php dan export_excel.php -- sebelumnya kedua file
 * export itu masing-masing punya query sendiri yang cuma filter tanggal+idpel
 * (plus exclude PERMINTAAN KODE hardcode), TIDAK PERNAH ikut filter
 * status/area/sales/paket/periode/metode_bayar/payment_method/bukti/nama
 * yang sudah dipilih user di Transaction.php -- jadi hasil export selalu
 * "semua data" walau di layar sudah difilter. Logikanya di sini SENGAJA
 * disalin persis dari Transaction.php (bukan direfactor jadi shared function
 * dipakai Transaction.php juga) supaya listing utama yang sudah terbukti
 * jalan tidak ikut berisiko berubah -- kalau nanti filter Transaction.php
 * berubah, sesuaikan juga di sini.
 */

if (!function_exists('exportTransaksiBuildFilter')) {
    function exportTransaksiBuildFilter($conn, $AKSES, $area_list, $current_user_id, array $get)
    {
        $bulan_penggunaan = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];

        // Scoping kepemilikan server -- SAMA PERSIS dgn Transaction.php (bukan
        // pola "ADMIN = 1=1" yg dipakai file export lain) supaya hasil export
        // selalu subset dari apa yg tampil di layar akun yg login, termasuk
        // utk ADMIN (Transaction.php sendiri TIDAK memberi ADMIN akses tanpa
        // batas -- ADMIN tetap dibatasi ke server miliknya sendiri).
        $userServerIds = [];
        if ($AKSES === 'ASSISTANT') {
            if (trim((string)$area_list) !== '') {
                $q = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
                if ($q) {
                    while ($r = mysqli_fetch_assoc($q)) {
                        $userServerIds[] = "'" . mysqli_real_escape_string($conn, (string)$r['PEMILIK']) . "'";
                    }
                }
            }
        } else {
            $q = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)$current_user_id);
            if ($q) {
                while ($r = mysqli_fetch_assoc($q)) {
                    $userServerIds[] = "'" . mysqli_real_escape_string($conn, (string)$r['PEMILIK']) . "'";
                }
            }
        }
        $server_list = count($userServerIds) > 0 ? implode(',', $userServerIds) : "''";

        $cekidpel = trim((string)($get['idpel'] ?? ''));
        $is_idpel_search = $cekidpel !== '';
        $start = trim((string)($get['start_date'] ?? ($get['start'] ?? '')));
        $end = trim((string)($get['end_date'] ?? ($get['end'] ?? '')));
        $filter_area = trim((string)($get['area'] ?? ''));
        $filter_sales = trim((string)($get['sales'] ?? ''));
        $filter_paket = trim((string)($get['paket'] ?? ''));
        $filter_nama = trim((string)($get['nama'] ?? ''));
        $filter_periode_bulan = trim((string)($get['periode_bulan'] ?? ''));
        $filter_periode_tahun = trim((string)($get['periode_tahun'] ?? ''));
        $filter_metode_bayar = strtolower(trim((string)($get['metode_bayar'] ?? '')));
        $filter_payment_method = trim((string)($get['payment_method'] ?? ''));
        $filter_bukti = trim((string)($get['bukti'] ?? ''));
        $filter_status = strtoupper(trim((string)($get['status'] ?? '')));
        $allowed_statuses = ['PENAGIHAN', 'PERMINTAAN KODE', 'KONFIRMASI', 'BERHASIL'];
        if (!in_array($filter_status, $allowed_statuses, true)) {
            $filter_status = '';
        }

        $join_pelanggan = "LEFT JOIN pelanggan p ON TRIM(transaksi.IDPEL) = TRIM(p.IDPEL)";
        $where = [];
        $where[] = "transaksi.pemilik IN ($server_list)";
        if ($is_idpel_search) {
            $where[] = "TRIM(transaksi.IDPEL) = '" . mysqli_real_escape_string($conn, $cekidpel) . "'";
        }

        $tanggal_bayar_filter_sql = "COALESCE(
            DATE(transaksi.TANGGALBAYAR),
            STR_TO_DATE(transaksi.TANGGALBAYAR, '%Y-%m-%d'),
            STR_TO_DATE(TRIM(SUBSTRING_INDEX(transaksi.TANGGALBAYAR, ',', -1)), '%d %M %Y'),
            STR_TO_DATE(TRIM(SUBSTRING_INDEX(transaksi.TANGGALBAYAR, ',', -1)), '%d %b %Y'),
            STR_TO_DATE(
                TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                    SUBSTRING_INDEX(transaksi.TANGGALBAYAR, ',', -1),
                    'Januari', '01'
                ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
                '%d %m %Y'
            )
        )";
        if (!$is_idpel_search && $start !== '' && $end !== '') {
            $where[] = "$tanggal_bayar_filter_sql BETWEEN '" . mysqli_real_escape_string($conn, $start) . "' AND '" . mysqli_real_escape_string($conn, $end) . "'";
        }

        if ($filter_status !== '') {
            $statusSafe = mysqli_real_escape_string($conn, $filter_status);
            $where[] = "(TRIM(UPPER(COALESCE(transaksi.STATUS, ''))) = '$statusSafe' OR TRIM(UPPER(COALESCE(transaksi.STATUS, ''))) LIKE '$statusSafe %')";
        } elseif (!$is_idpel_search) {
            $where[] = "transaksi.STATUS NOT LIKE 'PERMINTAAN KODE'";
        }
        if ($filter_area !== '') {
            $where[] = "p.AREA = '" . mysqli_real_escape_string($conn, $filter_area) . "'";
        }
        if ($filter_sales !== '') {
            $where[] = "p.sales = '" . mysqli_real_escape_string($conn, $filter_sales) . "'";
        }
        if ($filter_paket !== '') {
            $where[] = "p.PAKET = '" . mysqli_real_escape_string($conn, $filter_paket) . "'";
        }
        if ($filter_nama !== '') {
            $where[] = "transaksi.NAMA LIKE '%" . mysqli_real_escape_string($conn, $filter_nama) . "%'";
        }
        if ($filter_periode_bulan !== '' && in_array($filter_periode_bulan, $bulan_penggunaan, true) && $filter_periode_tahun !== '' && preg_match('/^\d{4}$/', $filter_periode_tahun)) {
            $periode_penggunaan_filter = $filter_periode_bulan . ' ' . $filter_periode_tahun;
            $where[] = "TRIM(COALESCE(transaksi.PENGUNAAN, '')) LIKE '%" . mysqli_real_escape_string($conn, $periode_penggunaan_filter) . "'";
        } elseif ($filter_periode_bulan !== '' && in_array($filter_periode_bulan, $bulan_penggunaan, true)) {
            $where[] = "TRIM(COALESCE(transaksi.PENGUNAAN, '')) LIKE '" . mysqli_real_escape_string($conn, $filter_periode_bulan) . " %'";
        } elseif ($filter_periode_tahun !== '' && preg_match('/^\d{4}$/', $filter_periode_tahun)) {
            $where[] = "TRIM(COALESCE(transaksi.PENGUNAAN, '')) LIKE '% " . mysqli_real_escape_string($conn, $filter_periode_tahun) . "'";
        }
        if ($filter_metode_bayar === 'cash') {
            $where[] = "LOWER(COALESCE(transaksi.METODE_BAYAR, '')) = 'cash'";
        } elseif ($filter_metode_bayar === 'transfer') {
            $where[] = "LOWER(COALESCE(transaksi.METODE_BAYAR, '')) = 'transfer'";
        } elseif ($filter_metode_bayar === 'payment_gateway') {
            $where[] = "LOWER(COALESCE(transaksi.METODE_BAYAR, '')) NOT IN ('cash', 'transfer')";
        }
        if ($filter_payment_method !== '') {
            $where[] = "LOWER(COALESCE(transaksi.payment_method, '')) LIKE '%" . mysqli_real_escape_string($conn, strtolower($filter_payment_method)) . "%'";
        }
        if ($filter_bukti !== '') {
            $where[] = "transaksi.BUKTI LIKE '%" . mysqli_real_escape_string($conn, $filter_bukti) . "%'";
        }

        return [
            'join' => $join_pelanggan,
            'where_sql' => implode(' AND ', $where),
        ];
    }
}
