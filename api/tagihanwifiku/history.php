<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

function twk_penggunaan_sort_parts(string $pengunaan): array
{
    $months = [
        'januari' => 1,
        'februari' => 2,
        'maret' => 3,
        'april' => 4,
        'mei' => 5,
        'juni' => 6,
        'juli' => 7,
        'agustus' => 8,
        'september' => 9,
        'oktober' => 10,
        'november' => 11,
        'desember' => 12
    ];

    $text = strtolower(trim($pengunaan));
    if ($text === '') {
        return [0, 0];
    }

    if (preg_match('/(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)\s+(\d{4})/i', $text, $m)) {
        $month = $months[strtolower($m[1])] ?? 0;
        $year = (int)$m[2];
        return [$year, $month];
    }

    return [0, 0];
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit <= 0 || $limit > 200) {
    $limit = 200;
}

$items = [];
$skipCols = ['id', 'data'];
$sql = "
    SELECT *
    FROM transaksi
    WHERE IDPEL = ?
    ORDER BY waktu DESC
    LIMIT ?
";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'si', $idpel, $limit);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $tanggal = (string)($row['waktu'] ?? '');
        if ($tanggal === '') {
            $tanggal = (string)($row['TANGGAL'] ?? ($row['TANGGALBAYAR'] ?? ($row['created_at'] ?? '')));
        }

        $details = [];
        foreach ($row as $col => $val) {
            $colUp = strtoupper((string)$col);
            if (in_array((string)$col, $skipCols, true)) {
                continue;
            }
            if (in_array($colUp, ['WAKTU', 'STATUS', 'TIPE'], true)) {
                continue;
            }
            if ($val === null || $val === '') {
                continue;
            }

            $isHarga = in_array($colUp, ['HARGA', 'NOMINAL', 'TOTAL', 'JUMLAH'], true);
            $displayVal = $isHarga
                ? 'Rp ' . number_format((float)$val, 0, ',', '.')
                : (string)$val;

            $details[(string)$col] = $displayVal;
        }

        $items[] = [
            'idpel' => (string)($row['IDPEL'] ?? ''),
            'nama' => (string)($row['NAMA'] ?? ''),
            'tanggal' => $tanggal,
            'status' => (string)($row['STATUS'] ?? ($row['status'] ?? '')),
            'keterangan' => (string)($row['KETERANGAN'] ?? ''),
            'pengunaan' => (string)($row['PENGUNAAN'] ?? ($row['PENGGUNAAN'] ?? '')),
            'harga' => (float)($row['HARGA'] ?? 0),
            'metode' => (string)($row['METODE_BAYAR'] ?? ($row['METODE'] ?? '')),
            'bukti' => (string)($row['BUKTI'] ?? ''),
            'paket' => (string)($row['PAKET'] ?? ''),
            'cek' => (string)($row['CEK'] ?? ''),
            'pemilik' => (string)($row['PEMILIK'] ?? ''),
            'tanggal_bayar' => (string)($row['TANGGALBAYAR'] ?? ($row['TANGGAL'] ?? '')),
            'details' => $details
        ];
    }
    mysqli_stmt_close($stmt);
}

usort($items, function (array $a, array $b): int {
    [$yearA, $monthA] = twk_penggunaan_sort_parts((string)($a['pengunaan'] ?? ''));
    [$yearB, $monthB] = twk_penggunaan_sort_parts((string)($b['pengunaan'] ?? ''));

    if ($yearA !== $yearB) {
        return $yearB <=> $yearA;
    }
    if ($monthA !== $monthB) {
        return $monthB <=> $monthA;
    }

    $timeA = strtotime((string)($a['tanggal'] ?? '')) ?: 0;
    $timeB = strtotime((string)($b['tanggal'] ?? '')) ?: 0;
    return $timeB <=> $timeA;
});

twk_response(200, [
    'success' => true,
    'message' => 'Riwayat transaksi berhasil diambil.',
    'data' => [
        'items' => $items,
        'count' => count($items)
    ]
]);
