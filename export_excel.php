<?php
require 'cek-sesi.php';

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=transaksi_export.xls");

// Transaction.php mengirim start_date/end_date (bukan start/end) -- terima
// dua-duanya supaya filter tanggal dari UI benar-benar diteruskan.
$start = $_GET['start_date'] ?? ($_GET['start'] ?? date('Y-m-01'));
$end = $_GET['end_date'] ?? ($_GET['end'] ?? date('Y-m-d'));
$cekidpel = $_GET['idpel'] ?? '';
$jenis = $_GET['jenis'] ?? 'pt'; // pt, umkm, pkppribadi

// Scoping kepemilikan server (pola sama dgn dashboard.php) -- WAJIB, sebelum
// fix ini file ini query SEMUA transaksi dari SEMUA tenant tanpa filter
// apapun (termasuk alamat/no WA/RT/RW pelanggan).
$exportOwnedPemilik = [];
if ($AKSES === 'ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list) !== '') {
        $qOwnScope = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        if ($qOwnScope) {
            while ($rOwnScope = mysqli_fetch_assoc($qOwnScope)) {
                $pOwnScope = trim((string)($rOwnScope['PEMILIK'] ?? ''));
                if ($pOwnScope !== '') {
                    $exportOwnedPemilik[] = "'" . mysqli_real_escape_string($conn, $pOwnScope) . "'";
                }
            }
        }
    }
} elseif ($AKSES !== 'ADMIN') {
    $qOwnScope = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = " . (int)($current_user_id ?? 0));
    if ($qOwnScope) {
        while ($rOwnScope = mysqli_fetch_assoc($qOwnScope)) {
            $pOwnScope = trim((string)($rOwnScope['PEMILIK'] ?? ''));
            if ($pOwnScope !== '') {
                $exportOwnedPemilik[] = "'" . mysqli_real_escape_string($conn, $pOwnScope) . "'";
            }
        }
    }
}
$exportOwnedPemilikList = count($exportOwnedPemilik) > 0 ? implode(',', $exportOwnedPemilik) : "''";
$pemilikScopeSql = ($AKSES === 'ADMIN') ? '1=1' : "transaksi.PEMILIK IN ($exportOwnedPemilikList)";

$tanggal_bayar_filter_sql = "COALESCE(
    DATE(transaksi.TANGGALBAYAR),
    STR_TO_DATE(transaksi.TANGGALBAYAR, '%Y-%m-%d'),
    STR_TO_DATE(
        CONCAT(
            TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                SUBSTRING_INDEX(transaksi.TANGGALBAYAR, ',', -1),
                'Januari', '01'
            ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12'))
        ),
        '%d %m %Y'
    )
)";

$sql = "SELECT transaksi.*,
    p.ALAMAT AS PEL_ALAMAT, p.NOWA AS PEL_NOWA, p.rt AS PEL_RT, p.rw AS PEL_RW,
    p.kelurahan AS PEL_KELURAHAN, p.kecamatan AS PEL_KECAMATAN
    FROM transaksi
    LEFT JOIN pelanggan p ON TRIM(transaksi.IDPEL) = TRIM(p.IDPEL)
    WHERE $tanggal_bayar_filter_sql BETWEEN ? AND ? AND transaksi.STATUS NOT LIKE 'PERMINTAAN KODE' AND $pemilikScopeSql";
if ($cekidpel !== '') {
    $sql .= " AND transaksi.IDPEL = ?";
}
$sql .= " ORDER BY transaksi.id DESC";

$stmt = mysqli_prepare($conn, $sql);
if ($cekidpel !== '') {
    mysqli_stmt_bind_param($stmt, 'sss', $start, $end, $cekidpel);
} else {
    mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
}
mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);

echo "<table border='1'>";
echo "<tr><th colspan='14'>Data Transaksi Periode: $start s/d $end</th></tr>";
echo "<tr>
<th>No</th><th>Tanggal Bayar</th><th>Nama</th><th>ID Pel</th><th>Penggunaan</th><th>Harga</th><th>Ref Tripay</th><th>Status</th><th>Alamat</th><th>RT</th><th>RW</th><th>Kelurahan</th><th>Kecamatan</th><th>No WA</th>
</tr>";

$no = 1;
$total = 0;
while ($data = mysqli_fetch_array($query)) {
    $harga = (float)$data['HARGA'];
    $total += $harga;
    echo "<tr>
        <td>{$no}</td>
        <td>{$data['TANGGALBAYAR']}</td>
        <td>{$data['NAMA']}</td>
        <td>{$data['IDPEL']}</td>
        <td>{$data['PENGUNAAN']}</td>
        <td>{$harga}</td>
        <td>{$data['BUKTI']}</td>
        <td>{$data['STATUS']}</td>
        <td>{$data['PEL_ALAMAT']}</td>
        <td>{$data['PEL_RT']}</td>
        <td>{$data['PEL_RW']}</td>
        <td>{$data['PEL_KELURAHAN']}</td>
        <td>{$data['PEL_KECAMATAN']}</td>
        <td>{$data['PEL_NOWA']}</td>
    </tr>";
    $no++;
}

$ppn = ($jenis == 'pt' || $jenis == 'pkppribadi') ? $total * 0.11 : 0;
$pph25 = ($jenis == 'pt') ? $total * 0.025 : 0;
$pphFinal = ($jenis == 'umkm' && $jenis != 'pt') ? $total * 0.005 : 0;
$pphBadan = ($jenis == 'pt') ? $total * 0.22 : 0;
$totalPajak = $ppn + $pph25 + $pphFinal;
$grand = $total + $totalPajak;

echo "<tr><th colspan='11'>Total Harga</th><td colspan='3'>Rp. " . number_format($total, 0, ',', '.') . "</td></tr>";
if ($ppn > 0) echo "<tr><th colspan='11'>PPN 11%</th><td colspan='3'>Rp. " . number_format($ppn, 0, ',', '.') . "</td></tr>";
if ($pph25 > 0) echo "<tr><th colspan='11'>PPh 25 (2.5%)</th><td colspan='3'>Rp. " . number_format($pph25, 0, ',', '.') . "</td></tr>";
if ($pphFinal > 0) echo "<tr><th colspan='11'>PPh Final UMKM (0.5%)</th><td colspan='3'>Rp. " . number_format($pphFinal, 0, ',', '.') . "</td></tr>";
if ($pphBadan > 0) echo "<tr><th colspan='11'>Simulasi PPh Badan (22%)</th><td colspan='3'>Rp. " . number_format($pphBadan, 0, ',', '.') . "</td></tr>";
echo "<tr><th colspan='11'>Total Pajak Dibayar</th><td colspan='3'><strong>Rp. " . number_format($totalPajak, 0, ',', '.') . "</strong></td></tr>";
echo "<tr><th colspan='11'>Grand Total</th><td colspan='3'><strong>Rp. " . number_format($grand, 0, ',', '.') . "</strong></td></tr>";
echo "</table>";
