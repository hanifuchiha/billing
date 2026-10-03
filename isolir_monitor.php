<?php
ob_start();
require 'header.php';
if ($AKSES !== 'ADMIN' || !empty($_SESSION['IS_DEMO'])) {
    echo '<div class="container-fluid py-4"><div class="alert alert-danger">Menu ini khusus Administrator.</div></div>';
    require 'footer.php'; exit;
}
require_once __DIR__ . '/routeros_api.class.php';
require_once __DIR__ . '/radius_sync_lib.php';
require_once __DIR__ . '/notifbot/notifphp/tagihan_status_lib.php';
require_once __DIR__ . '/notifbot/reminder_settings_helper.php';
date_default_timezone_set('Asia/Jakarta');

function imJson(array $data, int $code=200): void { while(ob_get_level()) ob_end_clean(); http_response_code($code); header('Content-Type: application/json'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function imCsrf(): string { return hash('sha256',session_id().'|isolir-monitor|'.($_SESSION['PEMILIK']??'')); }
function imRadiusExpired(): array {
    $file='/etc/freeradius/3.0/users'; $out=[]; $raw=@file_get_contents($file); if($raw===false)return $out;
    foreach(preg_split('/\R\s*\R/',$raw) as $block){ if(!preg_match('/^([^\s#]+)\s+/m',$block,$m))continue; if(preg_match('/Mikrotik-Group\s*:=\s*"EXPIRED"/i',$block))$out[strtolower(trim($m[1]))]=true; }
    return $out;
}
function imCache(): array {
    $file=__DIR__.'/serverlog/pppoe_status_cache.json'; $raw=is_file($file)?json_decode((string)file_get_contents($file),true):[];
    return ['generated_at'=>(int)($raw['generated_at']??0),'data'=>is_array($raw['data']??null)?$raw['data']:[]];
}
function imRestore(mysqli $conn,string $idpel): array {
    $s=$conn->prepare("SELECT p.*,s.IP,s.PASSWORD AS SERVER_PASSWORD,u.USERNAME AS BILLING_OWNER FROM pelanggan p JOIN server s ON s.PEMILIK=p.PEMILIK AND s.AREA=p.AREA JOIN user u ON u.id=s.user_id WHERE p.IDPEL=? LIMIT 1");
    $s->bind_param('s',$idpel);$s->execute();$p=$s->get_result()->fetch_assoc(); if(!$p)return ['ok'=>false,'message'=>'Pelanggan/server tidak ditemukan.'];
    $settings=reminderSettingsGet($conn,(string)$p['BILLING_OWNER']);$lp=tagihanGetLastPaymentsBulk($conn,[$idpel]);$lu=tagihanGetLastPaidUsageMapBulk($conn,[$idpel]);
    $graceFile=__DIR__.'/notifbot/data/prabayar_grace_period-'.preg_replace('/[^A-Za-z0-9_.-]/','_',(string)$p['BILLING_OWNER']).'.json';$grace=2;if(is_file($graceFile)){$g=json_decode((string)file_get_contents($graceFile),true);$grace=(int)($g['prabayar_grace_period']??2);}
    $verdict=tagihanHitungStatus($conn,$p,['hari_ini'=>date('Y-m-d'),'jatuh_tempo_hari'=>(int)$settings['jatuh_tempo'],'lastPaymentMap'=>$lp,'lastPaidUsageMap'=>$lu,'prabayar_grace_period'=>$grace,'tutup_buku_awal'=>(int)$settings['tanggal_awal_tutup_buku'],'tutup_buku_akhir'=>(int)$settings['tanggal_akhir_tutup_buku']]);
    if(empty($verdict['sudah_bayar']))return ['ok'=>false,'message'=>'Pemulihan ditolak: kalkulator billing menyatakan pelanggan masih menunggak. '.$verdict['keterangan']];
    $mode=strtoupper(trim((string)$p['MODE']));$logs=[];$changed=false;
    if(in_array($mode,['API MODE','MULTI MODE'],true)){
        $api=new RouterosAPI();$api->timeout=5;$api->attempts=1;
        if(!$api->connect($p['IP'],$p['PEMILIK'],$p['SERVER_PASSWORD']))$logs[]='MikroTik tidak dapat dihubungi.';
        else {
            $sec=$api->comm('/ppp/secret/print',['?name'=>$idpel]);
            if(!empty($sec[0]['.id'])&&strtoupper(trim((string)($sec[0]['profile']??'')))==='EXPIRED'){
                $profiles=$api->comm('/ppp/profile/print',['?name'=>$p['PAKET']]);
                if(empty($profiles))$logs[]='Profile paket tidak ditemukan di MikroTik.';
                else {$api->comm('/ppp/secret/set',['.id'=>$sec[0]['.id'],'profile'=>$profiles[0]['name'],'comment'=>'AKTIF dipulihkan Monitor Isolir '.date('Y-m-d H:i:s')]);$changed=true;$logs[]='Secret MikroTik dipulihkan ke '.$profiles[0]['name'].'.';}
            } else $logs[]='Secret MikroTik sudah bukan EXPIRED.';
            $act=$api->comm('/ppp/active/print',['?name'=>$idpel]); if($changed&&!empty($act[0]['.id'])){$api->comm('/ppp/active/remove',['.id'=>$act[0]['.id']]);$logs[]='Sesi lama diputus untuk login ulang.';}
            $api->disconnect();
        }
    }
    if(in_array($mode,['RADIUS MODE','MULTI MODE'],true)){
        $q=$conn->prepare('SELECT * FROM paket WHERE PAKET=? AND PEMILIK=? ORDER BY id DESC LIMIT 1');$q->bind_param('ss',$p['PAKET'],$p['PEMILIK']);$q->execute();$paket=$q->get_result()->fetch_assoc()?:['PAKET'=>$p['PAKET'],'KECEPATAN'=>''];
        $rr=radiusSyncSingleCustomerNow($idpel,(string)$p['PASSWORD'],$paket,true,radiusGetGlobalSettings($conn),(string)($p['IP_STATIC']??'')); if(!empty($rr['changed']))$changed=true;$logs[]='FreeRADIUS disinkronkan ke paket normal.';
    }
    return ['ok'=>true,'changed'=>$changed,'message'=>implode(' ',$logs)];
}
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='restore'){
    if(!hash_equals(imCsrf(),(string)($_POST['csrf']??'')))imJson(['ok'=>false,'message'=>'Token keamanan tidak valid.'],403);
    $idpel=trim((string)($_POST['idpel']??'')); if($idpel==='')imJson(['ok'=>false,'message'=>'ID pelanggan kosong.'],422);
    $result=imRestore($conn,$idpel);imJson($result,$result['ok']?200:422);
}

$owners=[];$q=$conn->query("SELECT USERNAME FROM user WHERE STATUS IN ('ADMIN','USER','AKTIF') ORDER BY USERNAME");while($q&&$r=$q->fetch_assoc())$owners[]=(string)$r['USERNAME'];
$defaultOwner=in_array('airlink',$owners,true)?'airlink':(string)$ceknama;
$owner=trim((string)($_GET['owner']??$defaultOwner));if(!in_array($owner,$owners,true))$owner=$defaultOwner;
$stmt=$conn->prepare("SELECT p.*,s.IP,u.USERNAME AS BILLING_OWNER FROM pelanggan p JOIN server s ON s.PEMILIK=p.PEMILIK AND s.AREA=p.AREA JOIN user u ON u.id=s.user_id WHERE u.USERNAME=? ORDER BY p.NAMA");$stmt->bind_param('s',$owner);$stmt->execute();$res=$stmt->get_result();$customers=[];$ids=[];while($r=$res->fetch_assoc()){$customers[strtolower(trim($r['IDPEL']))]=$r;$ids[]=$r['IDPEL'];}
$cache=imCache();$radiusExpired=imRadiusExpired();$lastPay=tagihanGetLastPaymentsBulk($conn,$ids);$lastUsage=tagihanGetLastPaidUsageMapBulk($conn,$ids);$settings=reminderSettingsGet($conn,$owner);
$graceFile=__DIR__.'/notifbot/data/prabayar_grace_period-'.preg_replace('/[^A-Za-z0-9_.-]/','_',$owner).'.json';$grace=2;if(is_file($graceFile)){$g=json_decode((string)file_get_contents($graceFile),true);$grace=(int)($g['prabayar_grace_period']??2);}
$rows=[];$counts=['total'=>0,'online'=>0,'offline'=>0,'valid'=>0,'wrong'=>0];
foreach($customers as $key=>$p){$st=$cache['data'][$key]??[];$localExpired=strtoupper((string)($st['cekexpired']??''))==='EXPIRED';$radExpired=!empty($radiusExpired[$key]);if(!$localExpired&&!$radExpired)continue;
    $v=tagihanHitungStatus($conn,$p,['hari_ini'=>date('Y-m-d'),'jatuh_tempo_hari'=>(int)$settings['jatuh_tempo'],'lastPaymentMap'=>$lastPay,'lastPaidUsageMap'=>$lastUsage,'prabayar_grace_period'=>$grace,'tutup_buku_awal'=>(int)$settings['tanggal_awal_tutup_buku'],'tutup_buku_akhir'=>(int)$settings['tanggal_akhir_tutup_buku']]);
    $wrong=!empty($v['sudah_bayar']);$online=strtolower((string)($st['status']??''))==='online';$source=implode(' + ',array_filter([$localExpired?'MikroTik':'',$radExpired?'RADIUS':'']));$sourceKey=$localExpired&&$radExpired?'both':($localExpired?'mikrotik':'radius');
    $rows[]=['p'=>$p,'st'=>$st,'v'=>$v,'wrong'=>$wrong,'online'=>$online,'source'=>$source,'source_key'=>$sourceKey,'last_pay'=>$lastPay[$p['IDPEL']]??'','last_usage'=>$lastUsage[$p['IDPEL']]??''];$counts['total']++;$counts[$online?'online':'offline']++;$counts[$wrong?'wrong':'valid']++;
}
usort($rows,fn($a,$b)=>($b['wrong']<=>$a['wrong'])?:strcmp($a['p']['NAMA'],$b['p']['NAMA']));
$areas=[];$modes=[];foreach($rows as $r){$areas[(string)$r['p']['AREA']]=true;$modes[(string)$r['p']['MODE']]=true;}ksort($areas,SORT_NATURAL|SORT_FLAG_CASE);ksort($modes,SORT_NATURAL|SORT_FLAG_CASE);$csrf=imCsrf();
?>
<style>.im-card{border:0;border-radius:16px;box-shadow:0 4px 18px rgba(0,0,0,.07)}.im-stat{font-size:1.8rem;font-weight:800}.im-table td{vertical-align:middle}.im-table th{white-space:nowrap}.im-sticky{position:sticky;top:0;background:#fff;z-index:2}.im-detail{line-height:1.55;min-width:210px}.im-customer{min-width:260px}.im-filter-label{font-size:.78rem;font-weight:700;color:#67748e}.im-nowrap{white-space:nowrap}</style>
<div class="container-fluid py-4">
 <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><div><h4 class="mb-1">Monitor Pelanggan Isolir</h4><div class="text-muted small">Status MikroTik dan FreeRADIUS · cache <?= $cache['generated_at']?date('d-m-Y H:i:s',$cache['generated_at']):'tidak tersedia' ?> · menampilkan <strong id="imVisible"><?= count($rows) ?></strong> pelanggan</div></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary" id="imReset"><i class="fas fa-undo"></i> Reset Filter</button><button class="btn btn-outline-primary" onclick="location.reload()"><i class="fas fa-sync"></i> Refresh</button></div></div>
 <div class="row g-3 mb-4"><?php foreach([['Total isolir','total','dark'],['Online','online','warning'],['Offline','offline','secondary'],['Sesuai tagihan','valid','danger'],['Perlu dipulihkan','wrong','success']] as $c): ?><div class="col-6 col-lg"><div class="card im-card p-3"><span class="text-muted"><?= $c[0] ?></span><span class="im-stat text-<?= $c[2] ?>"><?= $counts[$c[1]] ?></span></div></div><?php endforeach ?></div>
 <div class="card im-card mb-4"><div class="card-body"><form method="get" class="row g-2 align-items-end" id="imFilters">
  <div class="col-md-3 col-xl-2"><label class="im-filter-label">AKUN BILLING</label><select name="owner" class="form-select" onchange="this.form.submit()"><?php foreach($owners as $o): ?><option value="<?= htmlspecialchars($o) ?>" <?= $o===$owner?'selected':'' ?>><?= htmlspecialchars($o) ?></option><?php endforeach ?></select></div>
  <div class="col-md-3 col-xl-3"><label class="im-filter-label">AREA / POP</label><select id="imArea" class="form-select"><option value="">Semua area (<?= count($areas) ?>)</option><?php foreach(array_keys($areas) as $a): ?><option value="<?= htmlspecialchars(strtolower($a)) ?>"><?= htmlspecialchars($a) ?></option><?php endforeach ?></select></div>
  <div class="col-md-3 col-xl-2"><label class="im-filter-label">STATUS ISOLIR</label><select id="imBilling" class="form-select"><option value="">Semua status</option><option value="wrong">Perlu dipulihkan</option><option value="valid">Sesuai tagihan</option></select></div>
  <div class="col-md-3 col-xl-1"><label class="im-filter-label">KONEKSI</label><select id="imConnection" class="form-select"><option value="">Semua</option><option value="online">Online</option><option value="offline">Offline</option></select></div>
  <div class="col-md-3 col-xl-1"><label class="im-filter-label">MODE</label><select id="imMode" class="form-select"><option value="">Semua</option><?php foreach(array_keys($modes) as $m): ?><option value="<?= htmlspecialchars(strtolower($m)) ?>"><?= htmlspecialchars($m) ?></option><?php endforeach ?></select></div>
  <div class="col-md-3 col-xl-1"><label class="im-filter-label">SUMBER</label><select id="imSource" class="form-select"><option value="">Semua</option><option value="mikrotik">MikroTik</option><option value="radius">RADIUS</option><option value="both">Keduanya</option></select></div>
  <div class="col-md-9 col-xl-2"><label class="im-filter-label">CARI</label><input id="imSearch" class="form-control" placeholder="Nama / IDPEL / WA"></div>
 </form><div class="mt-2 d-flex flex-wrap gap-3 small text-muted"><span><i class="fas fa-circle text-danger"></i> Isolir sesuai: memang menunggak</span><span><i class="fas fa-circle text-success"></i> Perlu dipulihkan: sudah lunas/belum jatuh tempo</span><span>Cache diperbarui otomatis setiap ±1 menit</span></div></div></div>
 <div class="card im-card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover im-table mb-0" id="imTable"><thead class="im-sticky"><tr><th>Pelanggan</th><th>Area / Server</th><th>Sumber & Mode</th><th>Koneksi / Perangkat</th><th>Status Tagihan</th><th>Pembayaran & Jatuh Tempo</th><th>Alasan / Riwayat Link</th><th>Aksi</th></tr></thead><tbody>
 <?php foreach($rows as $r):$p=$r['p'];$st=$r['st'];$v=$r['v']; ?><tr data-billing="<?= $r['wrong']?'wrong':'valid' ?>" data-connection="<?= $r['online']?'online':'offline' ?>" data-area="<?= htmlspecialchars(strtolower((string)$p['AREA'])) ?>" data-mode="<?= htmlspecialchars(strtolower((string)$p['MODE'])) ?>" data-source="<?= htmlspecialchars($r['source_key']) ?>"><td class="im-customer"><strong><?= htmlspecialchars($p['NAMA']) ?></strong><div class="small text-muted"><?= htmlspecialchars($p['IDPEL']) ?></div><div class="small"><i class="fab fa-whatsapp text-success"></i> <?= htmlspecialchars($p['NOWA']?:'-') ?></div><div class="small"><?= htmlspecialchars($p['PAKET']) ?> · Rp <?= number_format((float)$p['HARGA'],0,',','.') ?></div></td><td class="small"><strong><?= htmlspecialchars($p['AREA']) ?></strong><div class="text-muted" title="<?= htmlspecialchars($p['PEMILIK']) ?>"><?= htmlspecialchars($p['BRAND']?:$p['PEMILIK']) ?></div><div class="text-muted"><?= htmlspecialchars($p['ODP']?:'-') ?></div></td><td><span class="badge bg-dark"><?= htmlspecialchars($r['source']) ?></span><div class="small text-muted mt-1"><?= htmlspecialchars($p['MODE']) ?></div></td><td class="small im-nowrap"><span class="badge <?= $r['online']?'bg-warning text-dark':'bg-secondary' ?>"><?= $r['online']?'ONLINE':'OFFLINE' ?></span><div>IP: <?= htmlspecialchars($st['remote_ip']??'-') ?></div><div>MAC: <?= htmlspecialchars(($st['active_caller_id']??'N/A')!=='N/A'?$st['active_caller_id']:($st['last_caller_secret']??'-')) ?></div><div class="text-muted">Via <?= htmlspecialchars($st['login_via']??'-') ?> · <?= htmlspecialchars($st['uptime']??'-') ?></div></td><td><span class="badge <?= $r['wrong']?'bg-success':'bg-danger' ?>"><?= $r['wrong']?'SEHARUSNYA AKTIF':'ISOLIR SESUAI' ?></span><div class="small text-muted mt-1"><?= htmlspecialchars($p['TIPE_BAYAR']) ?></div></td><td class="small im-nowrap"><div>Bayar terakhir: <strong><?= htmlspecialchars($r['last_pay']?:'Belum ada') ?></strong></div><div>Periode lunas: <?= htmlspecialchars($r['last_usage']?:'-') ?></div><div>Jatuh tempo: <strong><?= htmlspecialchars($v['jatuh_tempo']?:'-') ?></strong></div><div class="text-muted"><?= htmlspecialchars($p['TIPE_TEMPO']?:'fixed due date') ?></div></td><td class="small im-detail"><?= htmlspecialchars($v['keterangan']?:'Belum jatuh tempo / sudah lunas') ?><div class="text-muted mt-1">Link up: <?= htmlspecialchars($st['last_link_up']??'-') ?></div><div class="text-muted">Link down: <?= htmlspecialchars($st['last_link_down']??'-') ?></div><div class="text-muted">Disconnect: <?= htmlspecialchars($st['ceklastdisconnect']??'-') ?></div></td><td><?php if($r['wrong']): ?><button class="btn btn-sm btn-success im-restore" data-idpel="<?= htmlspecialchars($p['IDPEL']) ?>"><i class="fas fa-unlock"></i> Pulihkan</button><?php else: ?><span class="text-muted small">Tidak perlu</span><?php endif ?></td></tr><?php endforeach ?>
 <?php if(!$rows): ?><tr><td colspan="8" class="text-center text-muted py-5">Tidak ada pelanggan berstatus EXPIRED pada akun ini.</td></tr><?php endif ?></tbody></table></div></div></div>
</div>
<script>const imCsrf=<?= json_encode($csrf) ?>,imFilterIds=['imArea','imBilling','imConnection','imMode','imSource'];function imFilter(){const area=document.getElementById('imArea').value,billing=document.getElementById('imBilling').value,connection=document.getElementById('imConnection').value,mode=document.getElementById('imMode').value,source=document.getElementById('imSource').value,q=document.getElementById('imSearch').value.trim().toLowerCase();let visible=0;document.querySelectorAll('#imTable tbody tr[data-billing]').forEach(r=>{const ok=(!area||r.dataset.area===area)&&(!billing||r.dataset.billing===billing)&&(!connection||r.dataset.connection===connection)&&(!mode||r.dataset.mode===mode)&&(!source||r.dataset.source===source)&&(!q||r.innerText.toLowerCase().includes(q));r.style.display=ok?'':'none';if(ok)visible++});document.getElementById('imVisible').textContent=visible}imFilterIds.forEach(id=>document.getElementById(id).addEventListener('change',imFilter));document.getElementById('imSearch').addEventListener('input',imFilter);document.getElementById('imReset').addEventListener('click',()=>{imFilterIds.forEach(id=>document.getElementById(id).value='');document.getElementById('imSearch').value='';imFilter()});document.querySelectorAll('.im-restore').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Pulihkan pelanggan ini dari EXPIRED dan putus sesi lama agar login ulang?'))return;const old=b.innerHTML;b.disabled=true;b.innerHTML='Memulihkan...';try{const x=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'restore',csrf:imCsrf,idpel:b.dataset.idpel})});const d=await x.json();alert(d.message||'Selesai');if(d.ok)location.reload()}catch(e){alert('Gagal menghubungi server.')}finally{b.disabled=false;b.innerHTML=old}}));</script>
<?php require 'footer.php'; ?>
