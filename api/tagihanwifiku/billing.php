<?php
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    twk_response(405, ['success' => false, 'message' => 'Method tidak diizinkan.']);
}

$conn = twk_db_connect();
$session = twk_require_auth($conn);
$idpel = (string)$session['idpel'];

$pelanggan = twk_find_customer($conn, $idpel);
if (!$pelanggan) {
    twk_response(404, ['success' => false, 'message' => 'Data pelanggan tidak ditemukan.']);
}

function twk_month_name_id(int $month, int $year): string
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
    if ($month > 12) {
        $month = 1;
        $year++;
    }
    return ($months[$month] ?? '') . ' ' . $year;
}

function twk_get_last_success_transaction(mysqli $conn, string $idpel): ?array
{
    $sql = "SELECT * FROM transaksi
            WHERE IDPEL = ? AND STATUS = 'BERHASIL'
            ORDER BY
                RIGHT(PENGUNAAN, 4) DESC,
                FIELD(LEFT(PENGUNAAN, LOCATE(' ', PENGUNAAN) - 1),
                    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember') DESC
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return null;
    }
    mysqli_stmt_bind_param($stmt, 's', $idpel);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return is_array($row) ? $row : null;
}

function twk_compute_home_bill_status(mysqli $conn, array $pelanggan, string $idpel): array
{
    $tampiltagihan = 'SHOW';
    $infotagihan = 'Menuggu pembayaran';
    $sisaHariAktif = null;

    $tipeTempo = trim((string)($pelanggan['TIPE_TEMPO'] ?? ''));
    $tipeBayar = trim((string)($pelanggan['TIPE_BAYAR'] ?? ''));
    $lastTransaction = twk_get_last_success_transaction($conn, $idpel);

    if ($tipeTempo === 'mengikuti_tanggal_tempo') {
        if (!empty($lastTransaction['waktu'])) {
            $lastDate = new DateTime((string)$lastTransaction['waktu']);
            $today = new DateTime();

            if ($lastDate->format('Y-m') === $today->format('Y-m')) {
                if ((int)$today->format('d') > 15) {
                    $currentMonth = (int)$today->format('n');
                    $currentYear = (int)$today->format('Y');
                    $nextMonth = twk_month_name_id($currentMonth + 1, $currentYear);

                    $queryCheck = mysqli_prepare($conn, "SELECT COUNT(*) AS count FROM transaksi WHERE IDPEL = ? AND STATUS = 'BERHASIL' AND PENGUNAAN = ?");
                    $count = 0;
                    if ($queryCheck) {
                        mysqli_stmt_bind_param($queryCheck, 'ss', $idpel, $nextMonth);
                        mysqli_stmt_execute($queryCheck);
                        $resCheck = mysqli_stmt_get_result($queryCheck);
                        if ($resCheck && ($rowCheck = mysqli_fetch_assoc($resCheck))) {
                            $count = (int)($rowCheck['count'] ?? 0);
                        }
                        mysqli_stmt_close($queryCheck);
                    }

                    if ($count > 0) {
                        $tampiltagihan = 'HIDE';
                        $infotagihan = 'Pembayaran ditutup - Sudah ada tagihan bulan depan';
                    } else {
                        $tampiltagihan = 'SHOW';
                        $infotagihan = 'Pembayaran dibuka';
                    }
                } else {
                    $tampiltagihan = 'HIDE';
                    $infotagihan = 'Pembayaran ditutup';
                }
            } elseif ($lastDate->format('Y-m') < $today->format('Y-m')) {
                $tampiltagihan = 'SHOW';
                $infotagihan = 'Pembayaran dibuka';
            }
        } else {
            $tampiltagihan = 'SHOW';
            $infotagihan = 'Menuggu pembayaran';
        }
    }

    if ($tipeTempo === 'mengikuti_tanggal_bayar') {
        if (!empty($lastTransaction['waktu'])) {
            $lastDate = new DateTime((string)$lastTransaction['waktu']);
            $today = new DateTime();
            $daysPassed = (int)$lastDate->diff($today)->days;

            if ($daysPassed >= 24) {
                $tampiltagihan = 'SHOW';
                $infotagihan = 'Pembayaran dibuka';
            } else {
                $tampiltagihan = 'HIDE';
                $infotagihan = 'Pembayaran ditutup';
            }

            $sisaHariAktif = max(0, 30 - $daysPassed);
        } else {
            $tampiltagihan = 'SHOW';
            $infotagihan = 'Menuggu pembayaran';

            if (strtolower($tipeBayar) === 'pascabayar' && !empty($pelanggan['TANGGALPASANG'])) {
                $installDate = new DateTime((string)$pelanggan['TANGGALPASANG']);
                $today = new DateTime();
                $daysPassed = (int)$installDate->diff($today)->days;
                $sisaHariAktif = max(0, 30 - $daysPassed);
            }
        }
    }

    return [
        'tampiltagihan' => $tampiltagihan,
        'display_status' => $tampiltagihan === 'SHOW' ? 'Belum bayar' : 'Sudah bayar',
        'info_tagihan' => $infotagihan,
        'sisa_hari_aktif' => $sisaHariAktif
    ];
}

$pemilik = (string)($pelanggan['PEMILIK'] ?? '');
$taxCfg = twk_get_payment_tax_bhp($conn, $pemilik);
$pajakRate = (float)($taxCfg['pajak'] ?? 11.0);
$defaultBhpUso = (float)($taxCfg['bhp_uso'] ?? 0.0);
$defaultHargaPaket = twk_find_package_price($conn, $pelanggan);
$homeStatus = twk_compute_home_bill_status($conn, $pelanggan, $idpel);

$dateOrderExpr = "COALESCE(NULLIF(tanggal_invoice,''), NULLIF(TANGGAL_INVOICE,''), NULLIF(jatuh_tempo,''), NULLIF(JATUH_TEMPO,''), created_at, NOW())";

$sqlOutstanding = "
    SELECT *
    FROM invoice
    WHERE id_pelanggan = ?
      AND (
        TRIM(UPPER(COALESCE(status, ''))) IN ('BELUM BAYAR', 'KONFIRMASI')
        OR TRIM(COALESCE(status, '')) = ''
      )
    ORDER BY $dateOrderExpr DESC
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sqlOutstanding);
$invoice = null;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $idpel);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $invoice = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
}

if (!$invoice) {
    // fallback persis seperti web: ambil invoice terakhir jika semua sudah lunas
    $sqlLast = "SELECT * FROM invoice WHERE id_pelanggan = ? ORDER BY $dateOrderExpr DESC LIMIT 1";
    $stmtLast = mysqli_prepare($conn, $sqlLast);
    if ($stmtLast) {
        mysqli_stmt_bind_param($stmtLast, 's', $idpel);
        mysqli_stmt_execute($stmtLast);
        $resLast = mysqli_stmt_get_result($stmtLast);
        $invoice = $resLast ? mysqli_fetch_assoc($resLast) : null;
        mysqli_stmt_close($stmtLast);
    }
}

$unpaidInvoices = [];
$sqlUnpaid = "
    SELECT *
    FROM invoice
    WHERE id_pelanggan = ?
            AND (
                TRIM(UPPER(COALESCE(status, ''))) IN ('BELUM BAYAR', 'KONFIRMASI')
                OR TRIM(COALESCE(status, '')) = ''
            )
    ORDER BY $dateOrderExpr DESC
";
$stmtUnpaid = mysqli_prepare($conn, $sqlUnpaid);
if ($stmtUnpaid) {
    mysqli_stmt_bind_param($stmtUnpaid, 's', $idpel);
    mysqli_stmt_execute($stmtUnpaid);
    $resUnpaid = mysqli_stmt_get_result($stmtUnpaid);
    while ($resUnpaid && ($row = mysqli_fetch_assoc($resUnpaid))) {
        $rowHarga = twk_row_amount($row, ['harga', 'HARGA']);
        if ($rowHarga <= 0) {
            $rowHarga = $defaultHargaPaket;
        }
        $rowPpnRaw = twk_row_amount($row, ['ppn', 'PPN'], -1);
        $rowPpn = $rowPpnRaw > 0 ? $rowPpnRaw : round($rowHarga * ($pajakRate / 100), 2);
        $rowBhpUso = twk_row_amount($row, ['bhp_uso', 'bhps_uso', 'BHP_USO', 'BHPS_USO']);
        if ($rowBhpUso <= 0) {
            $rowBhpUso = $defaultBhpUso;
        }
        $rowTotalRaw = twk_row_amount($row, ['total', 'TOTAL'], -1);
        $rowTotal = $rowTotalRaw >= 0 ? $rowTotalRaw : ($rowHarga + $rowPpn + $rowBhpUso);
        $unpaidInvoices[] = [
            'id' => (string)twk_row_value($row, ['id', 'ID'], ''),
            'nomor_invoice' => (string)twk_row_value($row, ['nomor_invoice', 'NOMOR_INVOICE'], ''),
            'paket' => (string)twk_row_value($row, ['paket', 'PAKET'], (($pelanggan['PAKET'] ?? '') ?: '-')),
            'periode' => (string)twk_row_value($row, ['periode', 'PERIODE'], ''),
            'status' => (string)twk_row_value($row, ['status', 'STATUS'], 'BELUM BAYAR'),
            'tanggal_invoice' => (string)twk_row_value($row, ['tanggal_invoice', 'TANGGAL_INVOICE'], ''),
            'harga' => $rowHarga,
            'ppn_rate' => $pajakRate,
            'ppn' => $rowPpn,
            'bhp_uso' => $rowBhpUso,
            'total' => $rowTotal,
            'keterangan' => (string)twk_row_value($row, ['keterangan', 'KETERANGAN'], ('Tagihan Bulan ' . (string)twk_row_value($row, ['periode', 'PERIODE'], '')))
        ];
    }
    mysqli_stmt_close($stmtUnpaid);
}

$fallbackHarga = max(0.0, $defaultHargaPaket);
$fallbackPpn = round($fallbackHarga * ($pajakRate / 100), 2);
$fallbackBhpUso = max(0.0, $defaultBhpUso);
$fallbackTotal = $fallbackHarga + $fallbackPpn + $fallbackBhpUso;

if (!$invoice && empty($unpaidInvoices) && $fallbackTotal > 0) {
    $periodeFallback = date('F Y');
    $unpaidInvoices[] = [
        'id' => '',
        'nomor_invoice' => '',
        'paket' => (string)(($pelanggan['PAKET'] ?? '') ?: '-'),
        'periode' => $periodeFallback,
        'status' => 'BELUM BAYAR',
        'tanggal_invoice' => date('Y-m-d'),
        'harga' => $fallbackHarga,
        'ppn_rate' => $pajakRate,
        'ppn' => $fallbackPpn,
        'bhp_uso' => $fallbackBhpUso,
        'total' => $fallbackTotal,
        'keterangan' => 'Tagihan Penggunaan ' . strtoupper((string)($pelanggan['TIPE_BAYAR'] ?? 'INTERNET')) . ' Bulan ' . $periodeFallback
    ];
}

if (!$invoice && !empty($unpaidInvoices)) {
    $first = $unpaidInvoices[0];
    $invoice = [
        'id' => $first['id'],
        'periode' => $first['periode'],
        'status' => $first['status'],
        'tanggal_invoice' => $first['tanggal_invoice'],
        'jatuh_tempo' => '',
        'keterangan' => $first['keterangan'],
        'harga' => $first['harga'],
        'ppn' => $first['ppn'],
        'bhp_uso' => $first['bhp_uso'],
        'total' => $first['total']
    ];
}

if (!$invoice) {
    twk_response(200, [
        'success' => true,
        'message' => 'Belum ada data invoice.',
        'data' => [
            'customer' => twk_customer_payload($pelanggan),
            'invoice' => null,
            'unpaid_invoices' => [],
            'summary' => [
                'total_unpaid_items' => 0,
                'total_unpaid_amount' => 0
            ]
        ]
    ]);
}

$harga = twk_row_amount($invoice, ['harga', 'HARGA']);
if ($harga <= 0) {
    $harga = $defaultHargaPaket;
}
$ppnRaw = twk_row_amount($invoice, ['ppn', 'PPN'], -1);
$ppn = $ppnRaw > 0 ? $ppnRaw : round($harga * ($pajakRate / 100), 2);
$bhpUso = twk_row_amount($invoice, ['bhp_uso', 'bhps_uso', 'BHP_USO', 'BHPS_USO']);
if ($bhpUso <= 0) {
    $bhpUso = $defaultBhpUso;
}
$totalRaw = twk_row_amount($invoice, ['total', 'TOTAL'], -1);
$total = $totalRaw >= 0 ? $totalRaw : ($harga + $ppn + $bhpUso);

$invoicePayload = [
    'id' => (string)twk_row_value($invoice, ['id', 'ID'], ''),
    'periode' => (string)twk_row_value($invoice, ['periode', 'PERIODE'], ''),
    'status' => (string)twk_row_value($invoice, ['status', 'STATUS'], 'BELUM BAYAR'),
    'tanggal_invoice' => (string)twk_row_value($invoice, ['tanggal_invoice', 'TANGGAL_INVOICE'], ''),
    'jatuh_tempo' => (string)twk_row_value($invoice, ['jatuh_tempo', 'JATUH_TEMPO'], ''),
    'keterangan' => (string)twk_row_value($invoice, ['keterangan', 'KETERANGAN'], ('Tagihan Bulan ' . (string)twk_row_value($invoice, ['periode', 'PERIODE'], ''))),
    'harga' => $harga,
    'ppn_rate' => $pajakRate,
    'ppn' => $ppn,
    'bhp_uso' => $bhpUso,
    'total' => $total
];

twk_response(200, [
    'success' => true,
    'message' => 'Data tagihan berhasil diambil.',
    'data' => [
        'customer' => twk_customer_payload($pelanggan),
        'invoice' => $invoicePayload,
        'unpaid_invoices' => $unpaidInvoices,
        'home_status' => $homeStatus,
        'summary' => [
            'total_unpaid_items' => count($unpaidInvoices),
            'total_unpaid_amount' => array_reduce($unpaidInvoices, function ($acc, $item) {
                return $acc + (float)($item['total'] ?? 0);
            }, 0.0)
        ]
    ]
]);
