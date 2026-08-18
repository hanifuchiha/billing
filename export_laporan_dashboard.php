<?php
// export_laporan_dashboard.php - tombol "Export Laporan" di dashboard.php.
// Reuse SQL ringkasan yang SAMA persis dengan yang dipakai dashboard.php
// (statistik_pembayaran) supaya angka di laporan selalu sinkron dgn yang
// tampil di layar -- bukan hitung ulang dgn logika sendiri.
require 'cek-sesi.php';

if ($AKSES === 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Dasbor', $akses_menu, true)) {
        http_response_code(403);
        echo 'Anda tidak memiliki akses ke menu Dasbor.';
        exit;
    }
}

$filter_bulan = (isset($_GET['bulan']) && $_GET['bulan'] !== '') ? (int)$_GET['bulan'] : (int)date('m');
$filter_tahun = (isset($_GET['tahun']) && $_GET['tahun'] !== '') ? (int)$_GET['tahun'] : (int)date('Y');
if ($filter_bulan < 1 || $filter_bulan > 12) $filter_bulan = (int)date('m');
if ($filter_tahun < 2000 || $filter_tahun > 2100) $filter_tahun = (int)date('Y');
$bulan_ini = str_pad((string)$filter_bulan, 2, '0', STR_PAD_LEFT);
$tahun_ini = $filter_tahun;

$nama_bulan = array(1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember');

function query_or_die_export($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        die("Query error: " . mysqli_error($conn) . "<br>SQL: $sql");
    }
    return $result;
}

// Scoping kepemilikan server, identik dgn dashboard.php.
$userServers = [];
$userAreas = [];
$queryServer = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = $current_user_id");
while ($row = mysqli_fetch_assoc($queryServer)) {
    $userServers[] = $row['PEMILIK'];
    if (!empty($row['AREA'])) $userAreas[] = $row['AREA'];
}
$userServerList = count($userServers) > 0 ? "'" . implode("','", array_map('addslashes', $userServers)) . "'" : "''";
$userAreaList = count($userAreas) > 0 ? "'" . implode("','", array_map('addslashes', $userAreas)) . "'" : "''";
$area_filter = (!empty($asistant_name)) ? $area_list : $userAreaList;

// --- Total Pelanggan ---
$data_aktif = mysqli_fetch_assoc(query_or_die_export($conn, "SELECT COUNT(*) as total FROM pelanggan WHERE PEMILIK IN ($userServerList) AND AREA IN ($area_filter)"));

// --- Periode invoice (sama dgn dashboard.php, tanggal_indonesia2() cuma
// pemetaan nomor bulan -> nama bulan -- pakai $nama_bulan yang sudah ada) ---
$periode_penggunaan_invoice = $nama_bulan[$filter_bulan] . ' ' . $filter_tahun;
$periode_sql = mysqli_real_escape_string($conn, $periode_penggunaan_invoice);

$data_invoice = mysqli_fetch_assoc(query_or_die_export($conn, "SELECT COUNT(*) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.PENGUNAAN = '$periode_sql' AND t.HARGA != '0' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)"));
$data_sudah_bayar = mysqli_fetch_assoc(query_or_die_export($conn, "SELECT COUNT(*) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND t.HARGA != '0' AND t.PENGUNAAN = '$periode_sql' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)"));
$data_belum_bayar = mysqli_fetch_assoc(query_or_die_export($conn, "SELECT COUNT(*) as total FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'PENAGIHAN' AND t.HARGA != '0' AND t.PENGUNAAN = '$periode_sql' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)"));
// pelanggan_berhenti tidak punya kolom AREA, jadi tidak bisa langsung
// "AND AREA IN ($area_filter)" seperti tabel lain -- $userServerList di atas
// TIDAK di-scope AREA (selalu semua server milik owner), jadi derive ulang
// pemilik yang AREA-nya ada di $area_filter (utk ASSISTANT = $area_list-nya sendiri).
$userServerIdsBerhentiExport = [];
$area_filter_trimmed_export = trim((string)$area_filter);
if ($area_filter_trimmed_export !== '' && $area_filter_trimmed_export !== "''") {
    $queryServerBerhentiExport = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_filter_trimmed_export)");
    while ($queryServerBerhentiExport && ($rowServerBerhentiExport = mysqli_fetch_assoc($queryServerBerhentiExport))) {
        $userServerIdsBerhentiExport[] = "'" . addslashes($rowServerBerhentiExport['PEMILIK']) . "'";
    }
}
$userServerListBerhentiExport = count($userServerIdsBerhentiExport) > 0 ? implode(",", $userServerIdsBerhentiExport) : "''";

$data_berhenti = mysqli_fetch_assoc(query_or_die_export($conn, "SELECT COUNT(*) as total FROM pelanggan_berhenti WHERE pemilik IN ($userServerListBerhentiExport) AND MONTH(tanggal_berhenti) = '$bulan_ini' AND YEAR(tanggal_berhenti) = '$tahun_ini'"));

// --- Pemasukan (sama dgn dashboard.php) ---
$tanggal_bayar_filter_sql = "COALESCE(
    DATE(t.TANGGALBAYAR),
    STR_TO_DATE(t.TANGGALBAYAR, '%Y-%m-%d'),
    STR_TO_DATE(
        CONCAT(
            TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                SUBSTRING_INDEX(t.TANGGALBAYAR, ',', -1),
                'Januari', '01'
            ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12'))
        ),
        '%d %m %Y'
    )
)";
$today = date('Y-m-d');
$senin_ini = date('Y-m-d', strtotime('monday this week'));
$minggu_ini = date('Y-m-d', strtotime('sunday this week'));
$awal_bulan_ini = date('Y-m-01');
$akhir_bulan_ini = date('Y-m-t');
$awal_tahun_ini = date('Y-01-01');
$akhir_tahun_ini = date('Y-12-31');

// Reseller/mitra ISP dengan filter harga aktif: total pemasukan & rincian yang
// di-export harus ikut harga custom mereka, bukan HARGA tersimpan mentah --
// jadi dihitung per-baris (bukan SUM SQL langsung) supaya tiap baris bisa
// ditimpa reseller_effective_harga(), sama pola dgn Transaction.php ~734-739.
function export_laporan_sum_harga_efektif($conn, $sql, $is_reseller, $reseller_price_filter_enabled)
{
    $total = 0.0;
    $res = query_or_die_export($conn, $sql);
    while ($row = mysqli_fetch_assoc($res)) {
        $harga = (float)$row['HARGA'];
        if (!empty($is_reseller) && !empty($reseller_price_filter_enabled) && !empty($row['PAKET'])) {
            $effectiveHarga = reseller_effective_harga($conn, $row['PAKET'], $row['PEMILIK'] ?? '');
            if ($effectiveHarga > 0) {
                $harga = $effectiveHarga;
            }
        }
        $total += $harga;
    }
    return $total;
}

$total_minggu = export_laporan_sum_harga_efektif($conn, "SELECT t.HARGA, t.PAKET, p.PEMILIK FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql >= '$senin_ini' AND $tanggal_bayar_filter_sql <= '$minggu_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)", $is_reseller, $reseller_price_filter_enabled);
$total_bulan = export_laporan_sum_harga_efektif($conn, "SELECT t.HARGA, t.PAKET, p.PEMILIK FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql >= '$awal_bulan_ini' AND $tanggal_bayar_filter_sql <= '$akhir_bulan_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)", $is_reseller, $reseller_price_filter_enabled);
$total_hari = export_laporan_sum_harga_efektif($conn, "SELECT t.HARGA, t.PAKET, p.PEMILIK FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql = '$today' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)", $is_reseller, $reseller_price_filter_enabled);
$total_tahun = export_laporan_sum_harga_efektif($conn, "SELECT t.HARGA, t.PAKET, p.PEMILIK FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.STATUS = 'BERHASIL' AND $tanggal_bayar_filter_sql >= '$awal_tahun_ini' AND $tanggal_bayar_filter_sql <= '$akhir_tahun_ini' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter)", $is_reseller, $reseller_price_filter_enabled);

// --- Rincian transaksi periode terpilih (untuk lampiran detail) ---
$rincian = [];
$resRincian = query_or_die_export($conn, "SELECT t.TANGGALBAYAR, t.NAMA, t.IDPEL, t.PENGUNAAN, t.HARGA, t.STATUS, t.PAKET, p.PEMILIK FROM transaksi t INNER JOIN pelanggan p ON t.IDPEL = p.IDPEL WHERE t.PENGUNAAN = '$periode_sql' AND t.HARGA != '0' AND p.PEMILIK IN ($userServerList) AND p.AREA IN ($area_filter) ORDER BY t.id DESC");
while ($r = mysqli_fetch_assoc($resRincian)) {
    $r['HARGA_EFEKTIF'] = (float)$r['HARGA'];
    if (!empty($is_reseller) && !empty($reseller_price_filter_enabled) && !empty($r['PAKET'])) {
        $effectiveHarga = reseller_effective_harga($conn, $r['PAKET'], $r['PEMILIK'] ?? '');
        if ($effectiveHarga > 0) {
            $r['HARGA_EFEKTIF'] = $effectiveHarga;
        }
    }
    $rincian[] = $r;
}

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=laporan_dashboard_" . $tahun_ini . "-" . $bulan_ini . ".xls");

echo "<table border='1'>";
echo "<tr><th colspan='6'>Laporan Ringkasan Dashboard - Periode " . $nama_bulan[$filter_bulan] . " " . $filter_tahun . "</th></tr>";
echo "<tr><th colspan='6'>Dicetak: " . date('d-m-Y H:i') . "</th></tr>";
echo "<tr><td colspan='6'></td></tr>";

echo "<tr><th colspan='2'>Ringkasan Pelanggan</th></tr>";
echo "<tr><td>Total Pelanggan</td><td>" . (int)($data_aktif['total'] ?? 0) . "</td></tr>";
echo "<tr><td>Berhenti Berlangganan (periode ini)</td><td>" . (int)($data_berhenti['total'] ?? 0) . "</td></tr>";
echo "<tr><td colspan='6'></td></tr>";

echo "<tr><th colspan='2'>Ringkasan Pembayaran - Periode " . $nama_bulan[$filter_bulan] . " " . $filter_tahun . "</th></tr>";
echo "<tr><td>Invoice Terkirim</td><td>" . (int)($data_invoice['total'] ?? 0) . "</td></tr>";
echo "<tr><td>Sudah Bayar</td><td>" . (int)($data_sudah_bayar['total'] ?? 0) . "</td></tr>";
echo "<tr><td>Belum Bayar</td><td>" . (int)($data_belum_bayar['total'] ?? 0) . "</td></tr>";
echo "<tr><td colspan='6'></td></tr>";

echo "<tr><th colspan='2'>Ringkasan Pemasukan</th></tr>";
echo "<tr><td>Hari Ini</td><td>Rp. " . number_format($total_hari, 0, ',', '.') . "</td></tr>";
echo "<tr><td>Minggu Ini</td><td>Rp. " . number_format($total_minggu, 0, ',', '.') . "</td></tr>";
echo "<tr><td>Bulan Ini</td><td>Rp. " . number_format($total_bulan, 0, ',', '.') . "</td></tr>";
echo "<tr><td>Tahun Ini</td><td>Rp. " . number_format($total_tahun, 0, ',', '.') . "</td></tr>";
echo "<tr><td colspan='6'></td></tr>";

echo "<tr><td colspan='6'>Catatan: untuk detail pelanggan menunggak per baris, lihat menu \"Pelanggan Menunggak\".</td></tr>";
echo "<tr><td colspan='6'></td></tr>";

echo "<tr><th colspan='6'>Rincian Transaksi Periode " . $nama_bulan[$filter_bulan] . " " . $filter_tahun . "</th></tr>";
echo "<tr><th>No</th><th>Tanggal Bayar</th><th>Nama</th><th>ID Pel</th><th>Harga</th><th>Status</th></tr>";
$no = 1;
foreach ($rincian as $r) {
    echo "<tr>
        <td>{$no}</td>
        <td>{$r['TANGGALBAYAR']}</td>
        <td>{$r['NAMA']}</td>
        <td>{$r['IDPEL']}</td>
        <td>" . number_format((float)$r['HARGA_EFEKTIF'], 0, ',', '.') . "</td>
        <td>{$r['STATUS']}</td>
    </tr>";
    $no++;
}

echo "</table>";
