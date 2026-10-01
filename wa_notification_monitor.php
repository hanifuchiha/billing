<?php
// Header aplikasi diperlukan untuk sesi/otorisasi dan tampilan. Buffer membuat
// respons aksi AJAX tetap berupa JSON murni (tanpa HTML header yang sudah dirender).
ob_start();
require 'header.php';

if ($AKSES !== 'ADMIN' || !empty($_SESSION['IS_DEMO'])) {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Menu ini khusus Administrator.</div></div>';
    require 'footer.php';
    exit;
}

require_once __DIR__ . '/notifbot/notifphp/whatsapp_notification_log_helper.php';
require_once __DIR__ . '/notifbot/notifphp/tagihan_status_lib.php';
require_once __DIR__ . '/notifbot/notif_template_helper.php';
waNotifEnsureSchema($conn);

date_default_timezone_set('Asia/Jakarta');

function wamJson(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function wamPeriod(DateTimeInterface $date): string
{
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    return $bulan[(int)$date->format('n')] . ' ' . $date->format('Y');
}

function wamOwnerSettings(string $owner): array
{
    $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $owner);
    $defaults = ['jatuh_tempo' => 25, 'hari_sebelum' => 3, 'tanggal_reminder' => 0, 'jam_reminder' => 7, 'menit_reminder' => 15, 'botname' => 'RANDOM'];
    $file = __DIR__ . '/notifbot/data/reminder-' . $safe . '.json';
    $rows = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) {
        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $rows[0])) $defaults[$key] = $rows[0][$key];
        }
    }
    return $defaults;
}

function wamNormalizePhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone));
    if (strpos($digits, '0') === 0) $digits = '62' . substr($digits, 1);
    return $digits;
}

function wamCsrfToken(): string
{
    return hash('sha256', session_id() . '|wa-notification-monitor|' . (string)($_SESSION['PEMILIK'] ?? ''));
}

function wamSend(mysqli $conn, string $owner, string $idpel, string $period, string $type, string $dueDate): array
{
    $stmt = $conn->prepare("SELECT p.* FROM pelanggan p JOIN server s ON s.PEMILIK=p.PEMILIK AND s.AREA=p.AREA JOIN user u ON u.id=s.user_id WHERE p.IDPEL=? AND u.USERNAME=? LIMIT 1");
    $stmt->bind_param('ss', $idpel, $owner);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$customer) return ['ok' => false, 'message' => 'Pelanggan tidak ditemukan pada akun yang dipilih.'];

    $phone = wamNormalizePhone((string)$customer['NOWA']);
    if (!preg_match('/^62[0-9]{8,15}$/', $phone)) return ['ok' => false, 'message' => 'Format nomor WhatsApp pelanggan tidak valid.'];

    $settings = wamOwnerSettings($owner);
    $botPref = trim((string)$settings['botname']);
    $ownerEsc = $conn->real_escape_string($owner);
    $botWhere = ($botPref !== '' && strtoupper($botPref) !== 'RANDOM')
        ? " AND namebot='" . $conn->real_escape_string($botPref) . "'"
        : '';
    $botRes = $conn->query("SELECT namebot,addressbot,password,COALESCE(sender,'') sender FROM botwa WHERE pemilik='$ownerEsc' $botWhere AND TRIM(namebot)<>'' AND TRIM(addressbot)<>'' ORDER BY RAND() LIMIT 1");
    if (!$botRes || !($bot = $botRes->fetch_assoc())) return ['ok' => false, 'message' => 'Bot WhatsApp akun ini tidak tersedia.'];

    $config = is_file(__DIR__ . '/config.json') ? json_decode((string)file_get_contents(__DIR__ . '/config.json'), true) : [];
    $domain = trim((string)($config['domain'] ?? 'broadbandairlink.com'));
    $dueLabel = $dueDate !== '' && strtotime($dueDate) ? date('d-m-Y', strtotime($dueDate)) : (string)$settings['jatuh_tempo'];
    $template = notifTemplateExtractSection(notifTemplateGetContent($owner), 'REMAINDER');
    $message = strtr($template, [
        '$IDPEL' => (string)$customer['IDPEL'], '$NAMA' => (string)$customer['NAMA'],
        '$PAKET' => (string)$customer['PAKET'], '$NOWA' => $phone,
        '$EMAIL' => (string)$customer['EMAIL'], '$ALAMAT' => (string)$customer['ALAMAT'],
        '$BRAND' => (string)$customer['BRAND'], '$jatuh_tempo_pelanggan' => $dueLabel,
        '$URL' => $domain,
    ]);
    if (trim($message) === '') return ['ok' => false, 'message' => 'Template reminder kosong.'];

    $job = waNotifQueueAndClaim($conn, [
        'pemilik' => $owner, 'idpel' => $idpel, 'nomor_wa' => $phone,
        'periode' => $period, 'jenis_notifikasi' => $type,
        'message' => $message, 'bot_name' => (string)$bot['namebot'],
    ]);
    if (!$job['claimed']) {
        return ['ok' => $job['status'] === 'sent', 'message' => $job['status'] === 'sent' ? 'Notifikasi ini sudah pernah terkirim.' : 'Notifikasi sedang diproses.', 'status' => $job['status']];
    }

    $url = rtrim((string)$bot['addressbot'], '/') . '/send/message?session=' . rawurlencode((string)$bot['namebot']);
    $headers = ['Content-Type: application/json'];
    $sender = trim((string)$bot['sender']);
    if ($sender !== '') { $url .= '&device_id=' . rawurlencode($sender); $headers[] = 'X-Device-Id: ' . $sender; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['phone' => $phone . '@s.whatsapp.net', 'message' => $message]), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERPWD => $bot['namebot'] . ':' . $bot['password'], CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
    $response = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $error = curl_error($ch); curl_close($ch);
    $ok = $error === '' && $http >= 200 && $http < 300;
    waNotifFinish($conn, (int)$job['id'], $ok, $http, $error !== '' ? 'cURL: ' . $error : (string)$response);
    return ['ok' => $ok, 'message' => $ok ? 'Notifikasi berhasil dikirim.' : 'Pengiriman gagal (HTTP ' . $http . ').', 'status' => $ok ? 'sent' : 'failed'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
    if (!hash_equals(wamCsrfToken(), (string)($_POST['csrf'] ?? ''))) wamJson(['ok' => false, 'message' => 'Token keamanan tidak valid.'], 403);
    wamJson(wamSend($conn, trim((string)$_POST['owner']), trim((string)$_POST['idpel']), trim((string)$_POST['period']), trim((string)$_POST['type']), trim((string)($_POST['due_date'] ?? ''))));
}

$csrf = wamCsrfToken();
$owners = [];
$ownerQuery = $conn->query("SELECT USERNAME FROM user WHERE STATUS IN ('ADMIN','USER') ORDER BY USERNAME");
while ($ownerQuery && ($row = $ownerQuery->fetch_assoc())) $owners[] = (string)$row['USERNAME'];
$selectedOwner = trim((string)($_GET['owner'] ?? $ceknama));
if (!in_array($selectedOwner, $owners, true)) $selectedOwner = (string)$ceknama;
$settings = wamOwnerSettings($selectedOwner);
$monthStart = date('Y-m-01 00:00:00');
$monthEnd = date('Y-m-t 23:59:59');
$periodNow = wamPeriod(new DateTimeImmutable('today'));

$customers = [];
$stmt = $conn->prepare("SELECT DISTINCT p.IDPEL,p.NAMA,p.NOWA,p.PAKET,p.TIPE_TEMPO,p.TIPE_BAYAR,p.TANGGALPASANG,p.TEMPO,p.TANGGAL_MONTHVERSARY,p.MV_NEXT_DUE_CACHE FROM pelanggan p JOIN server s ON s.PEMILIK=p.PEMILIK AND s.AREA=p.AREA JOIN user u ON u.id=s.user_id WHERE u.USERNAME=? ORDER BY p.NAMA");
$stmt->bind_param('s', $selectedOwner); $stmt->execute(); $res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $customers[$row['IDPEL']] = $row;
$stmt->close();

// Hanya pelanggan yang masih mempunyai invoice PENAGIHAN yang boleh muncul
// sebagai "belum dikirim". Tanpa pagar ini pelanggan yang sudah lunas akan
// tampak seolah-olah masih perlu diingatkan hanya karena tidak punya row log.
$pendingInvoices = [];
$pendingRes = $conn->query("SELECT IDPEL,TRIM(PENGUNAAN) periode FROM transaksi WHERE TRIM(UPPER(COALESCE(STATUS,'')))='PENAGIHAN'");
while ($pendingRes && ($pendingRow = $pendingRes->fetch_assoc())) {
    $pendingInvoices[trim((string)$pendingRow['IDPEL']) . '|' . trim((string)$pendingRow['periode'])] = true;
}

$logs = [];
$stmt = $conn->prepare("SELECT l.*,p.NAMA,p.PAKET,p.TIPE_TEMPO FROM whatsapp_notification_log l LEFT JOIN pelanggan p ON p.IDPEL=l.idpel WHERE l.pemilik=? AND l.created_at BETWEEN ? AND ? ORDER BY l.updated_at DESC");
$stmt->bind_param('sss', $selectedOwner, $monthStart, $monthEnd); $stmt->execute(); $res = $stmt->get_result();
while ($row = $res->fetch_assoc()) { $logs[] = $row; }
$stmt->close();

$existing = [];
// Indeks tambahan dipakai agar tahap lama (mis. H-9) tidak dianggap pending
// bila tahap yang lebih baru (H-2) sudah berhasil, dan nomor duplikat yang
// sudah menerima reminder hari ini tidak dihitung sebagai belum dikirim.
$sentH2 = [];
$sentPhonesToday = [];
foreach ($logs as $row) {
    $existing[$row['idpel'] . '|' . $row['periode'] . '|' . $row['jenis_notifikasi']] = true;
    if ($row['status'] === 'sent' && preg_match('/_h2$/', (string)$row['jenis_notifikasi'])) {
        $sentH2[$row['idpel'] . '|' . $row['periode']] = true;
    }
    if ($row['status'] === 'sent' && substr((string)$row['sent_at'], 0, 10) === date('Y-m-d')) {
        $sentPhonesToday[wamNormalizePhone((string)$row['nomor_wa'])] = true;
    }
}
$expected = [];
$today = new DateTimeImmutable('today');
foreach ($customers as $customer) {
    if (stripos((string)$customer['PAKET'], 'FREE') !== false) continue;
    $tempo = strtolower(trim((string)$customer['TIPE_TEMPO']));
    if ($tempo === '' || !in_array($tempo, ['mengikuti_tanggal_bayar','monthversary'], true)) $tempo = 'mengikuti_tanggal_tempo';
    if ($tempo === 'mengikuti_tanggal_tempo') {
        $schedule = sprintf('%s-%02d %02d:%02d:00', date('Y-m'), max(1, (int)$settings['tanggal_reminder']), (int)$settings['jam_reminder'], (int)$settings['menit_reminder']);
        $due = sprintf('%s-%02d', date('Y-m'), max(1, (int)$settings['jatuh_tempo']));
        $type = 'payment_reminder_fixed';
        $normalizedPhone = wamNormalizePhone((string)$customer['NOWA']);
        $validPhone = (bool)preg_match('/^62[0-9]{8,15}$/', $normalizedPhone);
        // Reminder fixed memakai satu pesan per nomor per hari. Pelanggan dummy
        // yang berbagi nomor bukan pending bila nomor itu sudah berhasil dikirimi.
        if ($validPhone && !isset($sentPhonesToday[$normalizedPhone]) && isset($pendingInvoices[$customer['IDPEL'] . '|' . $periodNow]) && !isset($existing[$customer['IDPEL'] . '|' . $periodNow . '|' . $type])) {
            $expected[] = compact('customer','schedule','due','type') + ['period' => $periodNow];
        }
        continue;
    }
    $dueRaw = trim((string)($customer['MV_NEXT_DUE_CACHE'] ?: $customer['TANGGAL_MONTHVERSARY']));
    if ($dueRaw === '' || !strtotime($dueRaw)) continue;
    $dueObj = new DateTimeImmutable($dueRaw); $period = wamPeriod($dueObj);
    foreach ([(int)$settings['hari_sebelum'], 2] as $days) {
        if ($days < 0) continue;
        $stage = 'h' . $days; $type = 'payment_reminder_' . ($tempo === 'monthversary' ? 'monthversary_' : 'rolling_') . $stage;
        $schedule = $dueObj->modify('-' . $days . ' days')->format('Y-m-d') . sprintf(' %02d:%02d:00', (int)$settings['jam_reminder'], (int)$settings['menit_reminder']);
        $due = $dueObj->format('Y-m-d');
        // H-9 yang terlewat tidak perlu dikejar lagi setelah H-2 berhasil.
        if ($days > 2 && isset($sentH2[$customer['IDPEL'] . '|' . $period])) continue;
        if (isset($pendingInvoices[$customer['IDPEL'] . '|' . $period]) && !isset($existing[$customer['IDPEL'] . '|' . $period . '|' . $type])) $expected[] = compact('customer','schedule','due','type','period');
    }
}

$counts = ['sent'=>0,'failed'=>0,'pending'=>0,'future'=>0,'skipped'=>0];
foreach ($logs as $row) if (isset($counts[$row['status']])) $counts[$row['status']]++;
foreach ($expected as &$expectedRow) {
    $expectedRow['display_status'] = strtotime($expectedRow['schedule']) > time() ? 'future' : 'pending';
    $counts[$expectedRow['display_status']]++;
}
unset($expectedRow);
?>
<style>
.wam-card{border:0;border-radius:16px;box-shadow:0 4px 18px rgba(0,0,0,.07)}.wam-stat{font-size:1.8rem;font-weight:800}.wam-table th{white-space:nowrap}.wam-table td{vertical-align:middle}.wam-badge{font-size:.72rem}.wam-response{max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.wam-sticky{position:sticky;top:0;background:#fff;z-index:2}
</style>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><div><h4 class="mb-1">Monitor Notifikasi WhatsApp</h4><div class="text-muted small">Reminder Fixed Due Date, Rolling, dan Monthversary</div></div><button class="btn btn-outline-primary" onclick="location.reload()"><i class="fas fa-sync"></i> Refresh</button></div>
  <div class="row g-3 mb-4">
    <div class="col-6 col-lg"><div class="card wam-card p-3"><span class="text-muted">Terkirim</span><span class="wam-stat text-success"><?= $counts['sent'] ?></span></div></div>
    <div class="col-6 col-lg"><div class="card wam-card p-3"><span class="text-muted">Gagal</span><span class="wam-stat text-danger"><?= $counts['failed'] ?></span></div></div>
    <div class="col-6 col-lg"><div class="card wam-card p-3"><span class="text-muted">Belum dikirim</span><span class="wam-stat text-warning"><?= $counts['pending'] ?></span></div></div>
    <div class="col-6 col-lg"><div class="card wam-card p-3"><span class="text-muted">Belum waktunya</span><span class="wam-stat text-info"><?= $counts['future'] ?></span></div></div>
    <div class="col-6 col-lg"><div class="card wam-card p-3"><span class="text-muted">Dilewati</span><span class="wam-stat text-secondary"><?= $counts['skipped'] ?></span></div></div>
  </div>
  <div class="card wam-card mb-4"><div class="card-body"><form method="get" class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Akun Billing</label><select name="owner" class="form-select" onchange="this.form.submit()"><?php foreach($owners as $owner): ?><option value="<?= htmlspecialchars($owner) ?>" <?= $owner===$selectedOwner?'selected':'' ?>><?= htmlspecialchars($owner) ?></option><?php endforeach ?></select></div>
    <div class="col-md-3"><label class="form-label">Status</label><select id="filterStatus" class="form-select"><option value="">Semua status</option><option value="sent">Terkirim</option><option value="failed">Gagal</option><option value="pending">Belum dikirim</option><option value="future">Belum waktunya</option><option value="skipped">Dilewati</option></select></div>
    <div class="col-md-3"><label class="form-label">Tipe tempo</label><select id="filterType" class="form-select"><option value="">Semua tipe</option><option value="fixed">Fixed Due Date</option><option value="rolling">Rolling</option><option value="monthversary">Monthversary</option></select></div>
    <div class="col-md-3"><label class="form-label">Cari pelanggan</label><input id="filterSearch" class="form-control" placeholder="Nama, IDPEL, nomor WA"></div>
  </form></div></div>
  <div class="card wam-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover wam-table mb-0" id="wamTable"><thead class="wam-sticky"><tr><th>Pelanggan</th><th>Tipe</th><th>Periode</th><th>Status</th><th>Jadwal / Waktu kirim</th><th>Percobaan</th><th>Respons</th><th>Aksi</th></tr></thead><tbody>
  <?php foreach ($expected as $row): $c=$row['customer']; $future=$row['display_status']==='future'; ?>
    <tr data-status="<?= $row['display_status'] ?>" data-type="<?= htmlspecialchars(strpos($row['type'],'monthversary')!==false?'monthversary':(strpos($row['type'],'rolling')!==false?'rolling':'fixed')) ?>"><td><strong><?= htmlspecialchars($c['NAMA']) ?></strong><div class="small text-muted"><?= htmlspecialchars($c['IDPEL']) ?> · <?= htmlspecialchars($c['NOWA']) ?></div></td><td><?= htmlspecialchars($c['TIPE_TEMPO'] ?: 'mengikuti_tanggal_tempo') ?><div class="small text-muted"><?= htmlspecialchars($row['type']) ?></div></td><td><?= htmlspecialchars($row['period']) ?><div class="small text-muted">JT <?= htmlspecialchars($row['due']) ?></div></td><td><span class="badge <?= $future?'bg-info':'bg-warning text-dark' ?> wam-badge"><?= $future?'BELUM WAKTUNYA':'BELUM DIKIRIM' ?></span></td><td><?= htmlspecialchars($row['schedule']) ?></td><td>0</td><td class="text-muted">Belum ada catatan</td><td><button class="btn btn-sm btn-primary wam-send" data-owner="<?= htmlspecialchars($selectedOwner) ?>" data-idpel="<?= htmlspecialchars($c['IDPEL']) ?>" data-period="<?= htmlspecialchars($row['period']) ?>" data-type="<?= htmlspecialchars($row['type']) ?>" data-due="<?= htmlspecialchars($row['due']) ?>">Kirim sekarang</button></td></tr>
  <?php endforeach ?>
  <?php foreach ($logs as $row): $typeLabel=strpos($row['jenis_notifikasi'],'monthversary')!==false?'monthversary':(strpos($row['jenis_notifikasi'],'rolling')!==false?'rolling':'fixed'); ?>
    <tr data-status="<?= htmlspecialchars($row['status']) ?>" data-type="<?= $typeLabel ?>"><td><strong><?= htmlspecialchars($row['NAMA'] ?: $row['idpel']) ?></strong><div class="small text-muted"><?= htmlspecialchars($row['idpel']) ?> · <?= htmlspecialchars($row['nomor_wa']) ?></div></td><td><?= htmlspecialchars($row['TIPE_TEMPO'] ?: '-') ?><div class="small text-muted"><?= htmlspecialchars($row['jenis_notifikasi']) ?></div></td><td><?= htmlspecialchars($row['periode']) ?></td><td><span class="badge <?= $row['status']==='sent'?'bg-success':($row['status']==='failed'?'bg-danger':($row['status']==='skipped'?'bg-secondary':'bg-warning text-dark')) ?> wam-badge"><?= strtoupper(htmlspecialchars($row['status'])) ?></span></td><td><?= $row['status']==='sent'?'Dikirim: '.htmlspecialchars($row['sent_at']):'Jadwal: '.htmlspecialchars($row['scheduled_at'] ?: '-') ?><div class="small text-muted">Update <?= htmlspecialchars($row['updated_at']) ?></div></td><td><?= (int)$row['attempts'] ?></td><td class="wam-response" title="<?= htmlspecialchars($row['response_message'] ?: $row['skip_reason'] ?: '') ?>"><?= htmlspecialchars($row['response_message'] ?: $row['skip_reason'] ?: '-') ?></td><td><?php if($row['status']!=='sent'): ?><button class="btn btn-sm btn-primary wam-send" data-owner="<?= htmlspecialchars($selectedOwner) ?>" data-idpel="<?= htmlspecialchars($row['idpel']) ?>" data-period="<?= htmlspecialchars($row['periode']) ?>" data-type="<?= htmlspecialchars($row['jenis_notifikasi']) ?>" data-due="">Kirim ulang</button><?php else: ?><span class="text-success small"><i class="fas fa-check"></i> Selesai</span><?php endif ?></td></tr>
  <?php endforeach ?>
  </tbody></table></div></div></div>
</div>
<script>
const csrf=<?= json_encode($csrf) ?>;function applyWamFilter(){const s=document.getElementById('filterStatus').value,t=document.getElementById('filterType').value,q=document.getElementById('filterSearch').value.toLowerCase();document.querySelectorAll('#wamTable tbody tr').forEach(r=>r.style.display=(!s||r.dataset.status===s)&&(!t||r.dataset.type===t)&&(!q||r.innerText.toLowerCase().includes(q))?'':'none')};['filterStatus','filterType'].forEach(id=>document.getElementById(id).addEventListener('change',applyWamFilter));document.getElementById('filterSearch').addEventListener('input',applyWamFilter);
document.querySelectorAll('.wam-send').forEach(btn=>btn.addEventListener('click',async()=>{if(!confirm('Kirim notifikasi WhatsApp ke pelanggan ini sekarang?'))return;const old=btn.innerHTML;btn.disabled=true;btn.innerHTML='Mengirim...';const body=new URLSearchParams({action:'send',csrf,owner:btn.dataset.owner,idpel:btn.dataset.idpel,period:btn.dataset.period,type:btn.dataset.type,due_date:btn.dataset.due});try{const res=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const data=await res.json();alert(data.message||'Selesai');if(data.ok)location.reload()}catch(e){alert('Gagal menghubungi server.')}finally{btn.disabled=false;btn.innerHTML=old}}));
</script>
<?php require 'footer.php'; ?>
