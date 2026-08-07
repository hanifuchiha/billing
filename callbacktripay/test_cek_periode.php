<?php
/**
 * Test Cek Periode (mengikuti logika callback_tripay_FIBERQ.php)
 * URL contoh:
 * - test_cek_periode.php
 * - test_cek_periode.php?tanggal=2026-03-26&jatuh_tempo=28&awal=20&akhir=28
 */

date_default_timezone_set('Asia/Jakarta');

function getMonthName($month, $year)
{
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    while ($month > 12) {
        $month -= 12;
        $year++;
    }

    while ($month < 1) {
        $month += 12;
        $year--;
    }

    return $months[(int)$month] . ' ' . $year;
}

function hitungPeriodeSepertiCallback($tanggalStr, $jatuhTempo, $tanggalAwalTutupBuku, $tanggalAkhirTutupBuku)
{
    $ts = strtotime($tanggalStr);
    if ($ts === false) {
        $ts = time();
    }

    $currentDay = (int)date('d', $ts);
    $currentMonth = (int)date('m', $ts);
    $currentYear = (int)date('Y', $ts);

    $jatuhTempo = (int)$jatuhTempo;
    $tanggalAwalTutupBuku = (int)$tanggalAwalTutupBuku;
    $tanggalAkhirTutupBuku = (int)$tanggalAkhirTutupBuku;

    $ptanggalskg = getMonthName($currentMonth, $currentYear);
    $ptanggaberikut = getMonthName($currentMonth + 1, $currentYear);
    $ptanggalsebelum = getMonthName($currentMonth - 1, $currentYear);

    if ($jatuhTempo <= $tanggalAkhirTutupBuku) {
        $periodeBulanBerikut = $ptanggaberikut;
    } else {
        $periodeBulanBerikut = getMonthName($currentMonth + 2, $currentYear);
    }

    if ($currentDay < $tanggalAwalTutupBuku) {
        $periode = $ptanggalskg;
        $rule = 'currentDay < tanggalAwalTutupBuku';
    } elseif ($currentDay >= $tanggalAwalTutupBuku && $currentDay <= $tanggalAkhirTutupBuku) {
        $periode = $periodeBulanBerikut;
        $rule = 'tanggalAwalTutupBuku <= currentDay <= tanggalAkhirTutupBuku';
    } else {
        // Perbaikan: setelah lewat akhir tutup buku tetap gunakan periodeBulanBerikut.
        // Kasus Maret 2026 dengan jatuh_tempo=28 harus tetap April 2026, bukan Mei 2026.
        $periode = $periodeBulanBerikut;
        $rule = 'currentDay > tanggalAkhirTutupBuku (pakai periodeBulanBerikut)';
    }

    return [
        'tanggal_input' => date('Y-m-d', $ts),
        'current_day' => $currentDay,
        'current_month' => $currentMonth,
        'current_year' => $currentYear,
        'jatuh_tempo' => $jatuhTempo,
        'tanggal_awal_tutup_buku' => $tanggalAwalTutupBuku,
        'tanggal_akhir_tutup_buku' => $tanggalAkhirTutupBuku,
        'ptanggalskg' => $ptanggalskg,
        'ptanggaberikut' => $ptanggaberikut,
        'ptanggalsebelum' => $ptanggalsebelum,
        'periode_bulan_berikut' => $periodeBulanBerikut,
        'periode' => $periode,
        'rule' => $rule
    ];
}

$tanggal = isset($_GET['tanggal']) ? trim((string)$_GET['tanggal']) : date('Y-m-d');
$jatuhTempo = isset($_GET['jatuh_tempo']) ? (int)$_GET['jatuh_tempo'] : 28;
$awal = isset($_GET['awal']) ? (int)$_GET['awal'] : 20;
$akhir = isset($_GET['akhir']) ? (int)$_GET['akhir'] : 28;

$hasil = hitungPeriodeSepertiCallback($tanggal, $jatuhTempo, $awal, $akhir);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test Cek Periode Callback Tripay</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; background: #f7f7f7; color: #222; }
        .card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        h1 { margin-top: 0; }
        label { display: block; margin: 8px 0 4px; }
        input { width: 100%; max-width: 300px; padding: 8px; border: 1px solid #bbb; border-radius: 6px; }
        button { margin-top: 12px; padding: 10px 14px; border: 0; border-radius: 6px; background: #1565c0; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f1f1f1; }
        .periode { font-size: 20px; font-weight: bold; color: #0d47a1; }
        code { background: #f3f3f3; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Test Cek Periode</h1>
        <p>Logika di file ini mengikuti bagian perhitungan periode pada callback Tripay.</p>
        <form method="get">
            <label for="tanggal">Tanggal Simulasi</label>
            <input type="date" id="tanggal" name="tanggal" value="<?php echo htmlspecialchars($hasil['tanggal_input']); ?>">

            <label for="jatuh_tempo">Jatuh Tempo</label>
            <input type="number" id="jatuh_tempo" name="jatuh_tempo" min="1" max="31" value="<?php echo (int)$hasil['jatuh_tempo']; ?>">

            <label for="awal">Tanggal Awal Tutup Buku</label>
            <input type="number" id="awal" name="awal" min="1" max="31" value="<?php echo (int)$hasil['tanggal_awal_tutup_buku']; ?>">

            <label for="akhir">Tanggal Akhir Tutup Buku</label>
            <input type="number" id="akhir" name="akhir" min="1" max="31" value="<?php echo (int)$hasil['tanggal_akhir_tutup_buku']; ?>">

            <button type="submit">Cek Periode</button>
        </form>
    </div>

    <div class="card">
        <div>Periode Hasil:</div>
        <div class="periode"><?php echo htmlspecialchars($hasil['periode']); ?></div>
        <div>Rule yang kena: <code><?php echo htmlspecialchars($hasil['rule']); ?></code></div>
    </div>

    <div class="card">
        <h3>Detail Variabel</h3>
        <table>
            <tr><th>Variabel</th><th>Nilai</th></tr>
            <tr><td>tanggal_input</td><td><?php echo htmlspecialchars($hasil['tanggal_input']); ?></td></tr>
            <tr><td>current_day</td><td><?php echo (int)$hasil['current_day']; ?></td></tr>
            <tr><td>current_month</td><td><?php echo (int)$hasil['current_month']; ?></td></tr>
            <tr><td>current_year</td><td><?php echo (int)$hasil['current_year']; ?></td></tr>
            <tr><td>jatuh_tempo</td><td><?php echo (int)$hasil['jatuh_tempo']; ?></td></tr>
            <tr><td>tanggal_awal_tutup_buku</td><td><?php echo (int)$hasil['tanggal_awal_tutup_buku']; ?></td></tr>
            <tr><td>tanggal_akhir_tutup_buku</td><td><?php echo (int)$hasil['tanggal_akhir_tutup_buku']; ?></td></tr>
            <tr><td>ptanggalskg</td><td><?php echo htmlspecialchars($hasil['ptanggalskg']); ?></td></tr>
            <tr><td>ptanggaberikut</td><td><?php echo htmlspecialchars($hasil['ptanggaberikut']); ?></td></tr>
            <tr><td>ptanggalsebelum</td><td><?php echo htmlspecialchars($hasil['ptanggalsebelum']); ?></td></tr>
            <tr><td>periode_bulan_berikut</td><td><?php echo htmlspecialchars($hasil['periode_bulan_berikut']); ?></td></tr>
            <tr><td>periode</td><td><strong><?php echo htmlspecialchars($hasil['periode']); ?></strong></td></tr>
        </table>
    </div>
</body>
</html>
