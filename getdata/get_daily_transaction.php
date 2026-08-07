
<?php
include '../cek-sesi.php';
setlocale(LC_TIME, 'id_ID.UTF-8', 'Indonesian_indonesia.1252');
$tgl = strftime('%A, %d %B %Y', strtotime(date('Y-m-d')));
$today = date('Y-m-d');
$user_id = $_SESSION['id'];
$items = [];

function get_last_success_payment_map(mysqli $conn, array $idpelList): array
{
    $idpelList = array_values(array_filter(array_unique(array_map('strval', $idpelList))));
    if (empty($idpelList)) {
        return [];
    }

    $escaped = array_map(function ($idpel) use ($conn) {
        return "'" . mysqli_real_escape_string($conn, $idpel) . "'";
    }, $idpelList);
    $inClause = implode(',', $escaped);

    $tanggalExpr = "COALESCE(
        DATE(TANGGALBAYAR),
        STR_TO_DATE(TANGGALBAYAR, '%Y-%m-%d'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(TANGGALBAYAR, ',', -1)), '%d %M %Y'),
        STR_TO_DATE(TRIM(SUBSTRING_INDEX(TANGGALBAYAR, ',', -1)), '%d %b %Y'),
        STR_TO_DATE(
            TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                SUBSTRING_INDEX(TANGGALBAYAR, ',', -1),
                'Januari', '01'
            ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
            '%d %m %Y'
        )
    )";

    $sql = "SELECT id, IDPEL, TANGGALBAYAR, BUKTI, METODE_BAYAR, $tanggalExpr AS parsed_tanggal
            FROM transaksi
            WHERE UPPER(TRIM(COALESCE(STATUS, ''))) = 'BERHASIL'
              AND IDPEL IN ($inClause)
            ORDER BY IDPEL ASC, parsed_tanggal DESC, id DESC";

    $q = mysqli_query($conn, $sql);
    $map = [];
    while ($q && ($row = mysqli_fetch_assoc($q))) {
        $idpel = (string)($row['IDPEL'] ?? '');
        if ($idpel === '' || isset($map[$idpel])) {
            continue;
        }

        $map[$idpel] = [
            'last_bayar' => (string)($row['TANGGALBAYAR'] ?? ''),
            'last_bukti' => (string)($row['BUKTI'] ?? ''),
            'last_bukti_image_url' => resolve_bukti_image_url($row['BUKTI'] ?? '', $row['METODE_BAYAR'] ?? ''),
        ];
    }

    return $map;
}

function resolve_bukti_image_url($bukti_raw, $metode_bayar_raw)
{
    $bukti = trim((string)$bukti_raw);
    $metode = strtolower(trim((string)$metode_bayar_raw));

    if ($bukti === '') {
        return '';
    }

    // URL langsung
    if (preg_match('/^https?:\/\//i', $bukti)) {
        return $bukti;
    }

    // Simpanan default bukti manual
    if (strpos($bukti, 'manual_active/') === 0) {
        return 'uploads/' . $bukti;
    }

    // Bukti pembayaran baru disimpan di folder dokumen/buktibon (di luar folder crm)
    if (strpos($bukti, 'buktibon/') === 0) {
        return '/dokumen/' . $bukti;
    }

    // Jika hanya nama file gambar, arahkan ke uploads/
    if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $bukti)) {
        return 'uploads/' . ltrim($bukti, '/');
    }

    // Jika format path lain sudah termasuk uploads
    if (strpos($bukti, 'uploads/') === 0) {
        return $bukti;
    }

    // Untuk bukti non-gambar (misal ref payment gateway), biarkan kosong
    return '';
}

// Ambil semua server yang user_id-nya sama dengan id user login
$server_query = mysqli_query($conn, "SELECT pemilik, area FROM server WHERE user_id='$user_id'");
$server_data = [];
while ($row = mysqli_fetch_assoc($server_query)) {
    $server_data[] = $row;
}
if (!empty($server_data)) {
    // Buat daftar pemilik unik dan area (ambil area pertama saja jika ada duplikat)
    $pemilik_map = [];
    foreach ($server_data as $srv) {
        if (!isset($pemilik_map[$srv['pemilik']])) {
            $pemilik_map[$srv['pemilik']] = $srv['area'];
        }
    }
    foreach ($pemilik_map as $pemilik => $area) {
        $pemilik_esc = mysqli_real_escape_string($conn, $pemilik);
        $area_esc = mysqli_real_escape_string($conn, $area);

        // Parse TANGGALBAYAR dari berbagai format: Y-m-d, "Senin, 09 Maret 2026", "Monday, 09 March 2026"
        $tanggal_bayar_expr = "COALESCE(
            DATE(`TANGGALBAYAR`),
            STR_TO_DATE(`TANGGALBAYAR`, '%Y-%m-%d'),
            STR_TO_DATE(TRIM(SUBSTRING_INDEX(`TANGGALBAYAR`, ',', -1)), '%d %M %Y'),
            STR_TO_DATE(TRIM(SUBSTRING_INDEX(`TANGGALBAYAR`, ',', -1)), '%d %b %Y'),
            STR_TO_DATE(
                TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                    SUBSTRING_INDEX(`TANGGALBAYAR`, ',', -1),
                    'Januari', '01'
                ), 'Februari', '02'), 'Maret', '03'), 'April', '04'), 'Mei', '05'), 'Juni', '06'), 'Juli', '07'), 'Agustus', '08'), 'September', '09'), 'Oktober', '10'), 'November', '11'), 'Desember', '12')),
                '%d %m %Y'
            )
        )";

          // KONFIRMASI: tanpa filter tanggal
          // BERHASIL & PERMINTAAN KODE: tetap filter tanggal hari ini
          $sql = "SELECT * FROM `transaksi` 
                WHERE `pemilik`='$pemilik_esc' 
                AND (
                    UPPER(TRIM(COALESCE(`STATUS`, ''))) = 'KONFIRMASI'
                    OR (
                        UPPER(TRIM(COALESCE(`STATUS`, ''))) IN ('BERHASIL','PERMINTAAN KODE')
                        AND $tanggal_bayar_expr = '$today'
                    )
                )";
        // Debug: echo $sql . '<br><br>';
        $q = mysqli_query($conn, $sql);
        while ($d = mysqli_fetch_assoc($q)) {
            $status = strtolower($d['STATUS']) == 'berhasil' ? 'berhasil' : (strtolower($d['STATUS']) == 'konfirmasi' ? 'konfirmasi' : 'permintaan');
            $metode_bayar = isset($d['METODE_BAYAR']) ? $d['METODE_BAYAR'] : '';
            $bukti_image_url = resolve_bukti_image_url($d['BUKTI'] ?? '', $metode_bayar);
            $items[] = [
                'status' => $status,
                'nama' => $d['NAMA'],
                'harga' => $d['HARGA'],
                'idpel' => $d['IDPEL'],
                'bukti' => $d['BUKTI'],
                'metode_bayar' => $metode_bayar,
                'bukti_image_url' => $bukti_image_url,
                'tanggal' => $d['TANGGALBAYAR'],
                'area' => $area_esc,
                'pemilik' => $pemilik_esc,
                'id' => isset($d['id']) ? $d['id'] : null
            ];
        }
    }
}

$lastPaymentMap = get_last_success_payment_map($conn, array_column($items, 'idpel'));
foreach ($items as &$item) {
    $idpel = (string)($item['idpel'] ?? '');
    $lastInfo = $lastPaymentMap[$idpel] ?? null;
    $item['last_bayar'] = $lastInfo['last_bayar'] ?? '';
    $item['last_bukti'] = $lastInfo['last_bukti'] ?? '';
    $item['last_bukti_image_url'] = $lastInfo['last_bukti_image_url'] ?? '';
}
unset($item);

// Urutkan global: KONFIRMASI paling atas, lalu BERHASIL, lalu PERMINTAAN KODE
// Di dalam status yang sama, tampilkan data terbaru (id terbesar) lebih dulu
$statusPriority = [
    'konfirmasi' => 0,
    'berhasil' => 1,
    'permintaan' => 2
];

usort($items, function ($a, $b) use ($statusPriority) {
    $pa = $statusPriority[$a['status']] ?? 99;
    $pb = $statusPriority[$b['status']] ?? 99;

    if ($pa !== $pb) {
        return $pa <=> $pb;
    }

    $ida = isset($a['id']) ? (int)$a['id'] : 0;
    $idb = isset($b['id']) ? (int)$b['id'] : 0;

    return $idb <=> $ida;
});

header('Content-Type: application/json');
echo json_encode($items);
