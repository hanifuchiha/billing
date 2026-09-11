<?php
declare(strict_types=1);

require '/var/www/html/crm/billing/koneksidb.php';

$apply = !in_array('--dry-run', $argv, true);
$lock = fopen(sys_get_temp_dir() . '/cron_reconcile_tripay_paid.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
$credential = mysqli_fetch_assoc(mysqli_query($conn, "SELECT apikey FROM tripay WHERE pemilik='airlink' LIMIT 1"));
$apiKey = (string)($credential['apikey'] ?? '');
if ($apiKey === '') throw new RuntimeException('API key Tripay Airlink tidak tersedia');

// Tripay kadang tidak mengirim callback. Ambil ulang transaksi PAID tujuh hari
// terakhir; berhenti saat halaman sudah mencapai transaksi yang lebih lama.
$cutoff = strtotime('-7 days');
$candidates = [];
for ($page = 1; $page <= 20; $page++) {
    $params = ['page'=>$page, 'per_page'=>50, 'sort'=>'desc', 'status'=>'PAID'];
    $ch = curl_init('https://tripay.co.id/api/merchant/transactions?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
    $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http !== 200) break;
    $json = json_decode((string)$body, true);
    $items = $json['data']['data'] ?? $json['data'] ?? [];
    if (!is_array($items) || !$items) break;
    $reachedCutoff = false;
    foreach ($items as $item) {
        $paidAt = (int)($item['paid_at'] ?? 0);
        if ($paidAt > 0 && $paidAt < $cutoff) { $reachedCutoff = true; continue; }
        if (($item['status'] ?? '') === 'PAID' && !empty($item['reference'])) {
            $candidates[] = ['reference'=>(string)$item['reference']];
        }
    }
    if ($reachedCutoff || count($items) < 50) break;
}
$audit = ['unresolved'=>$candidates];

$findCustomer = $conn->prepare('SELECT IDPEL,NAMA,PAKET,HARGA,PEMILIK FROM pelanggan WHERE LOWER(IDPEL)=LOWER(?) LIMIT 1');
$findReference = $conn->prepare('SELECT id,STATUS FROM transaksi WHERE BUKTI=? LIMIT 1');
$findPeriod = $conn->prepare("SELECT id,HARGA,METODE_BAYAR,BUKTI,CEK,payment_method FROM transaksi WHERE LOWER(IDPEL)=LOWER(?) AND PENGUNAAN=? AND STATUS='BERHASIL' ORDER BY id DESC LIMIT 1");
$insert = $conn->prepare("INSERT INTO transaksi (waktu,TANGGALBAYAR,PENGUNAAN,STATUS,IDPEL,NAMA,PAKET,HARGA,METODE_BAYAR,BUKTI,CEK,PEMILIK,PAY_DETAIL,fee_merchant,fee_customer,payment_method,harga_gross,KEUANGAN_SYNCED,ORIGIN_SYSTEM) VALUES (FROM_UNIXTIME(?),?,?,'BERHASIL',?,?,?,?, 'tripay',?,'Rekonsiliasi otomatis Tripay PAID',?,?,?,?,?,?,0,'tripay_reconciliation')");
$convertManual = $conn->prepare("UPDATE transaksi SET waktu=FROM_UNIXTIME(?),TANGGALBAYAR=?,METODE_BAYAR='tripay',BUKTI=?,CEK='Dikonversi otomatis dari manual active ke Tripay PAID terverifikasi',PAY_DETAIL=?,fee_merchant=?,fee_customer=?,payment_method=?,harga_gross=?,MANUAL_ACTIVE_BY=NULL,MANUAL_ACTIVE_SESSION=NULL,ORIGIN_SYSTEM='tripay_reconciliation' WHERE id=? AND STATUS='BERHASIL'");
$deleteInvoice = $conn->prepare("DELETE FROM transaksi WHERE LOWER(IDPEL)=LOWER(?) AND PENGUNAAN=? AND STATUS='PENAGIHAN'");

$result = ['mode'=>$apply?'apply':'dry-run','inserted'=>0,'converted_manual'=>0,'would_convert_manual'=>0,'skip_reference'=>0,'skip_period_paid'=>0,'review_period_paid'=>[],'skip_invalid'=>0,'errors'=>[],'rows'=>[]];
foreach (($audit['unresolved'] ?? []) as $candidate) {
    $reference = (string)($candidate['reference'] ?? '');
    if ($reference === '') { $result['skip_invalid']++; continue; }

    // Referensi yang sudah tercatat tidak perlu memanggil endpoint detail lagi.
    $findReference->bind_param('s', $reference); $findReference->execute();
    if ($findReference->get_result()->fetch_assoc()) { $result['skip_reference']++; continue; }

    $ch = curl_init('https://tripay.co.id/api/transaction/detail?reference=' . rawurlencode($reference));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
    $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
    $detail = json_decode((string)$body, true)['data'] ?? null;
    if ($http !== 200 || !is_array($detail) || strtoupper((string)($detail['status'] ?? '')) !== 'PAID') {
        $result['skip_invalid']++; $result['errors'][] = "$reference detail invalid HTTP=$http $curlError"; continue;
    }

    $merchantRef = (string)($detail['merchant_ref'] ?? '');
    $idpel = preg_replace('/-[0-9]{9,}$/', '', $merchantRef);
    $findCustomer->bind_param('s', $idpel); $findCustomer->execute();
    $customer = $findCustomer->get_result()->fetch_assoc();
    if (!$customer) { $result['skip_invalid']++; $result['errors'][]="$reference pelanggan $idpel tidak ditemukan"; continue; }

    $paidAt = (int)($detail['paid_at'] ?? 0);
    if ($paidAt <= 0) { $result['skip_invalid']++; continue; }
    $monthNames = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $period = $monthNames[(int)date('n',$paidAt)] . ' ' . date('Y',$paidAt);
    $baseAmount = (float)($detail['amount_received'] ?? 0);
    if ($baseAmount <= 0) $baseAmount = max(0, (float)($detail['amount'] ?? 0) - (float)($detail['fee_customer'] ?? 0));
    if ($baseAmount <= 0) $baseAmount = (float)$customer['HARGA'];
    $datePaid = date('Y-m-d', $paidAt);
    $payDetail = json_encode(['source'=>'tripay_reconciliation','merchant_ref'=>$merchantRef,'total_amount'=>(float)($detail['amount']??0)], JSON_UNESCAPED_UNICODE);
    $feeMerchant=(float)($detail['fee_merchant']??0); $feeCustomer=(float)($detail['fee_customer']??0); $channel=(string)($detail['payment_method']??'');
    $row=['reference'=>$reference,'idpel'=>$idpel,'nama'=>$customer['NAMA'],'period'=>$period,'paid_at'=>date('Y-m-d H:i:s',$paidAt),'base_amount'=>$baseAmount,'fee_customer'=>$feeCustomer,'channel'=>$channel];
    $result['rows'][]=$row;

    $findPeriod->bind_param('ss', $idpel, $period); $findPeriod->execute();
    $periodPaid = $findPeriod->get_result()->fetch_assoc();
    if ($periodPaid) {
        $method = strtolower(trim((string)$periodPaid['METODE_BAYAR']));
        $note = strtolower(trim((string)$periodPaid['CEK']));
        $paymentMethod = strtolower(trim((string)$periodPaid['payment_method']));
        $isManualGateway = $method === 'gagal payment gateway'
            || strpos($note, 'manual admin (gagal payment gateway)') !== false
            || strpos($paymentMethod, 'manual / gagal payment gateway') !== false;
        $sameAmount = abs((float)$periodPaid['HARGA'] - $baseAmount) < 0.01;

        if ($isManualGateway && $sameAmount) {
            if (!$apply) { $result['would_convert_manual']++; continue; }
            $conn->begin_transaction();
            try {
                $transactionId = (int)$periodPaid['id'];
                $convertManual->bind_param('isssddsdi', $paidAt,$datePaid,$reference,$payDetail,$feeMerchant,$feeCustomer,$channel,$baseAmount,$transactionId);
                if (!$convertManual->execute() || $convertManual->affected_rows !== 1) throw new RuntimeException($convertManual->error ?: 'baris manual tidak berubah');
                $deleteInvoice->bind_param('ss',$idpel,$period);
                if (!$deleteInvoice->execute()) throw new RuntimeException($deleteInvoice->error);
                $conn->commit(); $result['converted_manual']++;
            } catch (Throwable $e) {
                $conn->rollback(); $result['errors'][]="$reference konversi manual: {$e->getMessage()}";
            }
            continue;
        }

        $result['skip_period_paid']++;
        $result['review_period_paid'][] = ['reference'=>$reference,'idpel'=>$idpel,'transaction_id'=>(int)$periodPaid['id'],'method'=>$periodPaid['METODE_BAYAR'],'same_amount'=>$sameAmount];
        continue;
    }

    if (!$apply) continue;

    $conn->begin_transaction();
    try {
        $insert->bind_param('isssssdsssddsd', $paidAt,$datePaid,$period,$idpel,$customer['NAMA'],$customer['PAKET'],$baseAmount,$reference,$customer['PEMILIK'],$payDetail,$feeMerchant,$feeCustomer,$channel,$baseAmount);
        if (!$insert->execute()) throw new RuntimeException($insert->error);
        $deleteInvoice->bind_param('ss',$idpel,$period);
        if (!$deleteInvoice->execute()) throw new RuntimeException($deleteInvoice->error);
        $conn->commit(); $result['inserted']++;
    } catch (Throwable $e) {
        $conn->rollback(); $result['errors'][]="$reference: {$e->getMessage()}";
    }
}

echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
