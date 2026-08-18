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

/**
 * REWRITE 2026-08-07: implementasi lama TIDAK PUNYA cabang 'monthversary'
 * sama sekali (langsung fallthrough ke default $tampiltagihan='SHOW' di
 * awal fungsi) -- pelanggan Monthversary SELALU tampil "Belum bayar" walau
 * sudah lunas. Cabang Fixed Due Date & Rolling juga pakai heuristik sendiri
 * (hari>15, window 24-30 hari) yang tidak konsisten dgn mesin isolir
 * sesungguhnya (cron cek_tagihan_harian.php). Sekarang reuse
 * tagihanHitungStatus()/tagihanFallbackPeriodeLabel() (notifbot/notifphp/
 * tagihan_status_lib.php) -- satu sumber logika periode yang sama dgn
 * seluruh sistem, bukan reimplementasi terpisah yang gampang divergen lagi.
 */
function twk_compute_home_bill_status(mysqli $conn, array $pelanggan, string $idpel): array
{
    require_once __DIR__ . '/../../notifbot/notifphp/tagihan_status_lib.php';
    require_once __DIR__ . '/../../notifbot/reminder_settings_helper.php';

    $tipeTempo = trim((string)($pelanggan['TIPE_TEMPO'] ?? ''));
    $hariIni = date('Y-m-d');

    $ownerUsername = reminderSettingsResolveOwnerUsername($conn, (string)($pelanggan['PEMILIK'] ?? ''));
    $reminderSettings = reminderSettingsGet($conn, $ownerUsername);
    $jatuhTempoHari = (int)($reminderSettings['jatuh_tempo'] ?? 25);
    if ($jatuhTempoHari < 1 || $jatuhTempoHari > 28) {
        $jatuhTempoHari = 25;
    }
    $periodeTercatatMode = (string)($reminderSettings['periode_tercatat'] ?? 'berjalan');

    $lastPaymentMap = tagihanGetLastPaymentsBulk($conn, [$idpel]);
    $lastPaidUsageMap = tagihanGetLastPaidUsageMapBulk($conn, [$idpel]);

    $ctx = [
        'hari_ini' => $hariIni,
        'jatuh_tempo_hari' => $jatuhTempoHari,
        'lastPaymentMap' => $lastPaymentMap,
        'lastPaidUsageMap' => $lastPaidUsageMap,
        'prabayar_grace_period' => 0,
        'periode_tercatat_mode' => $periodeTercatatMode,
    ];

    // "Sudah bayar periode berjalan" = periode yg SEHARUSNYA ditagih sekarang
    // (label yang sama dipakai callback payment gateway/Manual Active) sudah
    // ada di riwayat pembayaran BERHASIL terakhir.
    $currentPeriodLabel = tagihanFallbackPeriodeLabel($conn, $pelanggan, $ctx);
    $lastPaidPeriod = trim((string)($lastPaidUsageMap[$idpel] ?? ''));
    $sudahBayarPeriodeIni = ($currentPeriodLabel !== '' && $lastPaidPeriod !== '' && $lastPaidPeriod === $currentPeriodLabel);

    $jatuhTempoBerikutnya = tagihanHitungJatuhTempoBerikutnya($conn, $pelanggan, $ctx);
    $tampilkanBelumBayar = !$sudahBayarPeriodeIni;

    // Fixed Due Date: JANGAN tampilkan "Belum bayar" jauh2 hari sebelum jatuh
    // tempo (dulu bug-nya malah kebalik -- heuristik "hari>15" yg tidak akurat).
    // Cuma tampilkan mulai H-7 sebelum jatuh tempo (atau sudah lewat) --
    // di luar window itu dianggap "Sudah bayar" utk tampilan ini walau
    // transaksi periode berjalan belum tercatat (memang belum waktunya ditagih).
    if ($tampilkanBelumBayar && $tipeTempo === 'mengikuti_tanggal_tempo' && !empty($jatuhTempoBerikutnya)) {
        $dueTs = strtotime($jatuhTempoBerikutnya);
        $todayTs = strtotime($hariIni);
        if ($dueTs !== false && $todayTs !== false) {
            $daysUntilDue = (int) floor(($dueTs - $todayTs) / 86400);
            if ($daysUntilDue > 7) {
                $tampilkanBelumBayar = false;
            }
        }
    }

    $tampiltagihan = $tampilkanBelumBayar ? 'SHOW' : 'HIDE';

    $sisaHariAktif = null;
    if ($tipeTempo === 'mengikuti_tanggal_bayar' && !empty($jatuhTempoBerikutnya)) {
        $dueTs = strtotime($jatuhTempoBerikutnya);
        $todayTs = strtotime($hariIni);
        if ($dueTs !== false && $todayTs !== false) {
            $sisaHariAktif = max(0, (int) floor(($dueTs - $todayTs) / 86400));
        }
    }

    return [
        'tampiltagihan' => $tampiltagihan,
        'display_status' => $tampiltagihan === 'SHOW' ? 'Belum bayar' : 'Sudah bayar',
        'info_tagihan' => $tampiltagihan === 'SHOW' ? 'Pembayaran dibuka' : 'Pembayaran ditutup',
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
