<?php
require 'header.php';
if (!isset($AKSES) || !in_array($AKSES, ['ADMIN','ASSISTANT'], true)) {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Akses ditolak.</div></div>';
    require 'footer.php';
    exit;
}

$userServerIds=[];
if ($AKSES==='ASSISTANT') {
    if (isset($area_list) && trim((string)$area_list)!=='') {
        $q=mysqli_query($conn,"SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
        while($q && ($r=mysqli_fetch_assoc($q))) $userServerIds[]="'".mysqli_real_escape_string($conn,$r['PEMILIK'])."'";
    }
} else {
    $q=mysqli_query($conn,"SELECT PEMILIK FROM server WHERE user_id=".(int)$current_user_id);
    while($q && ($r=mysqli_fetch_assoc($q))) $userServerIds[]="'".mysqli_real_escape_string($conn,$r['PEMILIK'])."'";
}
$scope=$userServerIds?implode(',',$userServerIds):"''";
$message='';
$search=trim((string)($_REQUEST['q']??''));
$searchEsc=mysqli_real_escape_string($conn,$search);
mysqli_query($conn,"CREATE TABLE IF NOT EXISTS payment_bank_correction_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT NOT NULL, keuangan_payment_id BIGINT NOT NULL,
    old_bank_id INT NOT NULL, old_bank_name VARCHAR(255) NOT NULL,
    new_bank_id INT NOT NULL, new_bank_name VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) NOT NULL, corrected_by VARCHAR(100) NOT NULL,
    reason VARCHAR(500) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_bank_correction_tx (transaction_id), KEY idx_payment_bank_correction_payment (keuangan_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['assign_bank'])) {
    $txId=(int)($_POST['transaction_id']??0);
    $bankId=(int)($_POST['bank_id']??0);
    $conn->begin_transaction();
    try {
        $bankStmt=$conn->prepare("SELECT b.bank_nama,LOWER(k.billing_method) billing_method FROM project_keuangan.bank b INNER JOIN project_keuangan.keuangan_bank_billing_method k ON k.bank_id=b.bank_id AND k.is_active=1 WHERE b.bank_id=? AND UPPER(TRIM(b.bank_nama))<>'TRIPAY' LIMIT 1");
        $bankStmt->bind_param('i',$bankId);$bankStmt->execute();$bank=$bankStmt->get_result()->fetch_assoc();
        if(!$bank) throw new RuntimeException('Kas/bank tidak valid atau mapping Keuangan tidak aktif.');

        $tx=$conn->query("SELECT id,METODE_BAYAR,KEUANGAN_SYNCED FROM transaksi WHERE id=$txId AND PEMILIK IN ($scope) FOR UPDATE")->fetch_assoc();
        if(!$tx) throw new RuntimeException('Transaksi tidak ditemukan atau di luar akses.');
        $oldMethod=strtolower(trim((string)$tx['METODE_BAYAR']));
        if(!in_array($oldMethod,['cash','transfer'],true)) throw new RuntimeException('Transaksi sudah mempunyai kas/bank khusus.');
        if((int)$tx['KEUANGAN_SYNCED']===1) throw new RuntimeException('Transaksi sudah sinkron dan dikunci.');
        if($oldMethod!==$bank['billing_method']) throw new RuntimeException('Kategori kas/bank tidak sesuai metode transaksi.');

        $bankName=strtolower(trim((string)$bank['bank_nama']));
        $note='Review kas/bank: '.$oldMethod.' -> '.$bankName.' oleh '.($_SESSION['username']??$ceknama).' '.date('Y-m-d H:i:s');
        $update=$conn->prepare("UPDATE transaksi SET METODE_BAYAR=?,CEK=CONCAT_WS(' | ',NULLIF(CEK,''),?) WHERE id=? AND KEUANGAN_SYNCED=0");
        $update->bind_param('ssi',$bankName,$note,$txId);
        if(!$update->execute()||$update->affected_rows!==1) throw new RuntimeException('Transaksi berubah saat diproses; silakan muat ulang.');
        $conn->commit();
        $message='<div class="alert alert-success">Kas/bank transaksi berhasil diperbarui. Cron akan menyinkronkannya ke Keuangan.</div>';
    } catch(Throwable $e) {
        $conn->rollback();
        $message='<div class="alert alert-danger">'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</div>';
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['correct_synced_bank'])) {
    $txId=(int)($_POST['transaction_id']??0);$bankId=(int)($_POST['bank_id']??0);$reason=trim((string)($_POST['reason']??''));
    if($reason==='')$message='<div class="alert alert-danger">Alasan koreksi wajib diisi.</div>';
    else {
      $conn->begin_transaction();
      try {
        $tx=$conn->query("SELECT id,IDPEL,HARGA,METODE_BAYAR,KEUANGAN_SYNCED,KEUANGAN_PAYMENT_ID FROM transaksi WHERE id=$txId AND PEMILIK IN ($scope) FOR UPDATE")->fetch_assoc();
        if(!$tx||(int)$tx['KEUANGAN_SYNCED']!==1||(int)$tx['KEUANGAN_PAYMENT_ID']<=0)throw new RuntimeException('Transaksi belum sinkron atau tidak ditemukan.');
        $oldMethod=strtolower(trim((string)$tx['METODE_BAYAR']));if(!in_array($oldMethod,['cash','transfer'],true))throw new RuntimeException('Hanya metode cash/transfer generik yang dapat dikoreksi di sini.');
        $paymentId=(int)$tx['KEUANGAN_PAYMENT_ID'];
        $payment=$conn->query("SELECT id,idpel,bank_id,bank_nama,jumlah_bayar,transaksi_id FROM project_keuangan.pelanggan_pembayaran WHERE id=$paymentId FOR UPDATE")->fetch_assoc();
        if(!$payment||strcasecmp(trim((string)$payment['idpel']),trim((string)$tx['IDPEL']))!==0||(float)$payment['jumlah_bayar']!==(float)$tx['HARGA'])throw new RuntimeException('Data Billing dan pembayaran Keuangan tidak cocok.');
        $bankStmt=$conn->prepare("SELECT b.bank_id,b.bank_nama,b.bank_nomor,LOWER(k.billing_method) billing_method FROM project_keuangan.bank b INNER JOIN project_keuangan.keuangan_bank_billing_method k ON k.bank_id=b.bank_id AND k.is_active=1 WHERE b.bank_id=? AND UPPER(TRIM(b.bank_nama))<>'TRIPAY' LIMIT 1");
        $bankStmt->bind_param('i',$bankId);$bankStmt->execute();$newBank=$bankStmt->get_result()->fetch_assoc();if(!$newBank)throw new RuntimeException('Kas/bank tujuan tidak valid.');
        if($newBank['billing_method']!==$oldMethod)throw new RuntimeException('Kategori kas/bank tujuan tidak sesuai metode lama.');
        $oldBankId=(int)$payment['bank_id'];if($oldBankId<=0||$oldBankId===$bankId)throw new RuntimeException('Pilih kas/bank tujuan yang berbeda.');
        $lockIds=[$oldBankId,$bankId];sort($lockIds,SORT_NUMERIC);$lock=$conn->query('SELECT bank_id,bank_nama FROM project_keuangan.bank WHERE bank_id IN ('.implode(',',$lockIds).') ORDER BY bank_id FOR UPDATE');$locked=[];while($lock&&($lr=$lock->fetch_assoc()))$locked[(int)$lr['bank_id']]=$lr;if(count($locked)!==2)throw new RuntimeException('Rekening lama atau baru tidak ditemukan.');
        $amount=(float)$payment['jumlah_bayar'];$oldName=(string)$locked[$oldBankId]['bank_nama'];$newName=(string)$newBank['bank_nama'];$mainTxId=(int)$payment['transaksi_id'];if($mainTxId<=0)throw new RuntimeException('Jurnal Keuangan utama tidak ditemukan.');
        if(!$conn->query("UPDATE project_keuangan.bank SET bank_saldo=bank_saldo-".(float)$amount." WHERE bank_id=$oldBankId"))throw new RuntimeException($conn->error);
        if(!$conn->query("UPDATE project_keuangan.bank SET bank_saldo=bank_saldo+".(float)$amount." WHERE bank_id=$bankId"))throw new RuntimeException($conn->error);
        if(!$conn->query("UPDATE project_keuangan.transaksi SET transaksi_bank=$bankId WHERE transaksi_id=$mainTxId" )||$conn->affected_rows>1)throw new RuntimeException('Gagal memindahkan jurnal Keuangan.');
        $payUpdate=$conn->prepare('UPDATE project_keuangan.pelanggan_pembayaran SET bank_id=?,bank_nama=?,bank_nomor=? WHERE id=?');$payUpdate->bind_param('issi',$bankId,$newName,$newBank['bank_nomor'],$paymentId);if(!$payUpdate->execute()||$payUpdate->affected_rows!==1)throw new RuntimeException('Gagal memperbarui pembayaran Keuangan.');
        $billingMethod=strtolower(trim($newName));$note='Koreksi kas/bank tersinkron: '.$oldName.' -> '.$newName.' | '.$reason;$billingUpdate=$conn->prepare("UPDATE transaksi SET METODE_BAYAR=?,CEK=CONCAT_WS(' | ',NULLIF(CEK,''),?) WHERE id=?");$billingUpdate->bind_param('ssi',$billingMethod,$note,$txId);if(!$billingUpdate->execute()||$billingUpdate->affected_rows!==1)throw new RuntimeException('Gagal memperbarui Billing.');
        $by=(string)($_SESSION['username']??$ceknama);$log=$conn->prepare('INSERT INTO payment_bank_correction_log(transaction_id,keuangan_payment_id,old_bank_id,old_bank_name,new_bank_id,new_bank_name,amount,corrected_by,reason) VALUES(?,?,?,?,?,?,?,?,?)');$log->bind_param('iiisisdss',$txId,$paymentId,$oldBankId,$oldName,$bankId,$newName,$amount,$by,$reason);if(!$log->execute())throw new RuntimeException('Gagal mencatat audit koreksi.');
        $conn->commit();$message='<div class="alert alert-success">Kas/bank transaksi tersinkron berhasil dipindahkan di Billing dan Keuangan.</div>';
      }catch(Throwable $e){$conn->rollback();$message='<div class="alert alert-danger">'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</div>';}
    }
}

$banks=['cash'=>[],'transfer'=>[]];
$q=mysqli_query($conn,"SELECT b.bank_id,b.bank_nama,LOWER(k.billing_method) billing_method FROM project_keuangan.bank b INNER JOIN project_keuangan.keuangan_bank_billing_method k ON k.bank_id=b.bank_id AND k.is_active=1 WHERE UPPER(TRIM(b.bank_nama))<>'TRIPAY' ORDER BY b.bank_nama");
while($q && ($r=mysqli_fetch_assoc($q))) if(isset($banks[$r['billing_method']])) $banks[$r['billing_method']][]=$r;

$sql="SELECT id,waktu,TANGGALBAYAR,PENGUNAAN,IDPEL,NAMA,HARGA,METODE_BAYAR,BUKTI
      FROM transaksi WHERE STATUS='BERHASIL' AND KEUANGAN_SYNCED=0
      AND LOWER(TRIM(METODE_BAYAR)) IN ('cash','transfer') AND PEMILIK IN ($scope)";
$rows=[];
if($search!==''){
    $sql.=" AND (LOWER(NAMA) LIKE LOWER('%$searchEsc%') OR LOWER(IDPEL) LIKE LOWER('%$searchEsc%')) ORDER BY id DESC LIMIT 50";
    $q=mysqli_query($conn,$sql);while($q && ($r=mysqli_fetch_assoc($q)))$rows[]=$r;
}
$cashCount=count(array_filter($rows,function($r){return strtolower(trim($r['METODE_BAYAR']))==='cash';}));
$transferCount=count($rows)-$cashCount;
$syncedRows=[];
$syncedSql="SELECT t.id,t.TANGGALBAYAR,t.PENGUNAAN,t.IDPEL,t.NAMA,t.HARGA,t.METODE_BAYAR,t.KEUANGAN_PAYMENT_ID,
                   pp.bank_id current_bank_id,pp.bank_nama current_bank_name
            FROM transaksi t INNER JOIN project_keuangan.pelanggan_pembayaran pp ON pp.id=CAST(t.KEUANGAN_PAYMENT_ID AS UNSIGNED)
            WHERE t.STATUS='BERHASIL' AND t.KEUANGAN_SYNCED=1 AND TRIM(COALESCE(t.KEUANGAN_PAYMENT_ID,''))<>''
              AND LOWER(TRIM(t.METODE_BAYAR)) IN ('cash','transfer') AND t.PEMILIK IN ($scope)
              AND LOWER(TRIM(pp.idpel))=LOWER(TRIM(t.IDPEL))";
if($search!==''){
    $syncedSql.=" AND (LOWER(t.NAMA) LIKE LOWER('%$searchEsc%') OR LOWER(t.IDPEL) LIKE LOWER('%$searchEsc%')) ORDER BY t.id DESC LIMIT 50";
    $q=mysqli_query($conn,$syncedSql);while($q&&($r=mysqli_fetch_assoc($q)))$syncedRows[]=$r;
}
?>
<div class="container-fluid py-4 px-3 px-md-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div><h4 class="mb-1">Review Kas/Bank Pembayaran</h4><small class="text-muted">Khusus transaksi berhasil yang belum sinkron ke Keuangan.</small></div>
    <a href="Transaction.php" class="btn btn-outline-secondary btn-sm">Kembali ke Transaksi</a>
  </div>
  <?= $message ?>
  <form method="get" class="card card-body shadow-sm mb-3"><div class="row g-2 align-items-end"><div class="col-md-9"><label class="form-label">Cari pelanggan</label><input type="search" name="q" class="form-control" value="<?= htmlspecialchars($search,ENT_QUOTES,'UTF-8') ?>" placeholder="Masukkan nama atau ID pelanggan" required></div><div class="col-md-3 d-grid"><button class="btn btn-primary">Cari</button></div></div></form>
  <?php if($search===''): ?><div class="alert alert-info">Masukkan nama atau ID pelanggan. Data tidak ditampilkan sekaligus agar halaman tetap ringan dan mengurangi risiko salah edit.</div><?php else: ?><div class="alert alert-info">Hasil <b><?= htmlspecialchars($search,ENT_QUOTES,'UTF-8') ?></b>: belum sinkron <b><?= count($rows) ?></b> — cash <b><?= $cashCount ?></b>, transfer <b><?= $transferCount ?></b>.</div><?php endif; ?>
  <div class="card shadow-sm"><div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0">
      <thead><tr><th>ID</th><th>Pelanggan</th><th>Periode</th><th>Nominal</th><th>Metode Lama</th><th>Bukti</th><th>Kas/Bank Tujuan</th></tr></thead>
      <tbody>
      <?php foreach($rows as $row):
        $method=strtolower(trim((string)$row['METODE_BAYAR']));
        $proof=trim((string)$row['BUKTI']);
        $proofUrl='';
        if(preg_match('#^https?://#i',$proof))$proofUrl=$proof;
        elseif($proof!=='')$proofUrl='../../dokumen/'.ltrim($proof,'/');
      ?>
        <tr>
          <td><?= (int)$row['id'] ?></td>
          <td><b><?= htmlspecialchars($row['NAMA'],ENT_QUOTES,'UTF-8') ?></b><br><small><?= htmlspecialchars($row['IDPEL'],ENT_QUOTES,'UTF-8') ?></small></td>
          <td><?= htmlspecialchars($row['PENGUNAAN'],ENT_QUOTES,'UTF-8') ?><br><small><?= htmlspecialchars($row['TANGGALBAYAR'],ENT_QUOTES,'UTF-8') ?></small></td>
          <td>Rp<?= number_format((float)$row['HARGA'],0,',','.') ?></td>
          <td><span class="badge bg-<?= $method==='cash'?'success':'primary' ?>"><?= htmlspecialchars($method) ?></span></td>
          <td><?php if($proofUrl!==''): ?><a class="btn btn-outline-info btn-sm" target="_blank" rel="noopener" href="<?= htmlspecialchars($proofUrl,ENT_QUOTES,'UTF-8') ?>">Lihat</a><?php else: ?>-<?php endif; ?></td>
          <td>
            <form method="post" class="d-flex gap-2" onsubmit="return confirm('Simpan kas/bank tujuan transaksi ini?')">
              <input type="hidden" name="assign_bank" value="1"><input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($search,ENT_QUOTES,'UTF-8') ?>">
              <select name="bank_id" class="form-select form-select-sm" required><option value="">Pilih...</option>
                <?php foreach($banks[$method] as $bank): ?><option value="<?= (int)$bank['bank_id'] ?>"><?= htmlspecialchars($bank['bank_nama'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-warning btn-sm">Simpan</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if($search!==''&&!$rows): ?><tr><td colspan="7" class="text-center py-4 text-muted">Tidak ada transaksi belum sinkron untuk pelanggan ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
  <div class="card shadow-sm mt-4"><div class="card-header"><b>Koreksi Kas/Bank Transaksi Sudah Sinkron (<?= count($syncedRows) ?>)</b><br><small class="text-muted">Saldo dan jurnal Keuangan dipindahkan secara atomik. Alasan koreksi wajib diisi.</small></div><div class="table-responsive">
    <table class="table table-sm table-striped align-middle mb-0"><thead><tr><th>ID</th><th>Pelanggan</th><th>Periode/Nominal</th><th>Metode</th><th>Kas/Bank Saat Ini</th><th>Koreksi Tujuan</th></tr></thead><tbody>
    <?php foreach($syncedRows as $row):$method=strtolower(trim((string)$row['METODE_BAYAR'])); ?>
      <tr><td><?= (int)$row['id'] ?></td><td><b><?= htmlspecialchars($row['NAMA'],ENT_QUOTES,'UTF-8') ?></b><br><small><?= htmlspecialchars($row['IDPEL'],ENT_QUOTES,'UTF-8') ?></small></td><td><?= htmlspecialchars($row['PENGUNAAN'],ENT_QUOTES,'UTF-8') ?><br>Rp<?= number_format((float)$row['HARGA'],0,',','.') ?></td><td><?= htmlspecialchars($method) ?></td><td><?= htmlspecialchars($row['current_bank_name']?:'-',ENT_QUOTES,'UTF-8') ?></td><td><form method="post" onsubmit="return confirm('Pindahkan saldo dan jurnal ke kas/bank baru?')"><input type="hidden" name="correct_synced_bank" value="1"><input type="hidden" name="transaction_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="q" value="<?= htmlspecialchars($search,ENT_QUOTES,'UTF-8') ?>"><div class="d-flex gap-2"><select name="bank_id" class="form-select form-select-sm" required><option value="">Pilih...</option><?php foreach($banks[$method] as $bank):if((int)$bank['bank_id']===(int)$row['current_bank_id'])continue; ?><option value="<?= (int)$bank['bank_id'] ?>"><?= htmlspecialchars($bank['bank_nama'],ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?></select><input name="reason" class="form-control form-control-sm" required maxlength="500" placeholder="Alasan koreksi"><button class="btn btn-danger btn-sm">Koreksi</button></div></form></td></tr>
    <?php endforeach; ?><?php if($search!==''&&!$syncedRows): ?><tr><td colspan="6" class="text-center py-4 text-muted">Tidak ada transaksi tersinkron untuk pelanggan ini.</td></tr><?php endif; ?>
    </tbody></table>
  </div></div>
</div>
<?php require 'footer.php'; ?>

