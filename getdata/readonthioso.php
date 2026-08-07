<?php
include '../cek-sesi.php'; // Pastikan koneksi ke database sudah benar

session_start();

function curl_request($url,$options=[]){
    $ch=curl_init($url);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($ch,CURLOPT_FOLLOWLOCATION,true);
    curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,false);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,false);
    if(!empty($options['basic_auth'])&&!empty($options['basic_auth_user'])&&isset($options['basic_auth_pass'])){
        curl_setopt($ch,CURLOPT_USERPWD,$options['basic_auth_user'].":".$options['basic_auth_pass']);
    }
    $resp=curl_exec($ch);
    curl_close($ch);
    return $resp;
}

if(isset($_GET['logout'])){
    session_destroy();
    header("Location: ../olt.php");
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['login'])){
    $_SESSION['host']=trim($_POST['ip']??'');
    $_SESSION['user']=trim($_POST['user']??'');
    $_SESSION['pass']=trim($_POST['pass']??'');
}

$logged_in = !empty($_SESSION['host']) && !empty($_SESSION['user']) && !empty($_SESSION['pass']);

if(isset($_GET['fetch'])){
    header('Content-Type: application/json');
    if(!$logged_in){ echo json_encode(['error'=>'not_logged_in']); exit; }

    // ------ siapkan folder debug per session ------
    $session_id = session_id();
    $debug_dir = __DIR__."/debug_session/".$session_id;
    if(!is_dir($debug_dir)){
        mkdir($debug_dir, 0777, true);
    }

 // ------ fetch PON list ------
$r = curl_request($_SESSION['host'].'/onuConfigPonList.asp', [
    'basic_auth'=>true,
    'basic_auth_user'=>$_SESSION['user'],
    'basic_auth_pass'=>$_SESSION['pass']
]);
// hapus dan buat ulang ponlist.txt
@unlink($debug_dir.'/ponlist.txt');
file_put_contents($debug_dir.'/ponlist.txt',$r);
chmod($debug_dir.'/ponlist.txt', 0777);

$pon_list=[];
if(preg_match('/var\s+ponListTable\s*=\s*new\s+Array\s*\((.*?)\);/is',$r,$m)){
    $raw = $m[1];
    if(preg_match_all("/'([^']*)'/",$raw,$matches)){
        $arr = $matches[1];
        $total = count($arr)/2;
        for($i=0;$i<$total;$i++) $pon_list[$arr[$i*2]]=$arr[$i*2+1];
    }
}

// ------ fetch ONU list ------
$onu_rows=[];
$selectedPon = $_GET['pon'] ?? '';
if($selectedPon){
    $_SESSION['pon'] = $selectedPon;
    $raw2 = curl_request($_SESSION['host'].'/onuConfigOnuList.asp?oltponno='.$selectedPon, [
        'basic_auth'=>true,
        'basic_auth_user'=>$_SESSION['user'],
        'basic_auth_pass'=>$_SESSION['pass']
    ]);
    // hapus dan buat ulang onulist.txt
    @unlink($debug_dir.'/onulist.txt');
    file_put_contents($debug_dir.'/onulist.txt',$raw2);
    chmod($debug_dir.'/onulist.txt', 0777);

    if(preg_match('/var\s+ponOnuTable\s*=\s*new\s+Array\s*\((.*?)\);/is',$raw2,$m2)){
        $parts=[];
        if(preg_match_all("/'([^']*)'/",$m2[1],$matches2)) $parts=$matches2[1];
        $cols=13;
        for($i=0;$i<count($parts);$i+=$cols) $onu_rows[]=array_slice($parts,$i,$cols);
    }
}

    echo json_encode(['pon'=>$pon_list,'onu'=>$onu_rows,'selectedPon'=>$selectedPon]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>HIOSO ONU Auto Manager</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
body {
  font-family: Arial, sans-serif;
  background: #f0f0f0;
  margin: 0;
  padding: 0;
}
.container {
  max-width: 1200px;
  margin: 30px auto;
  background: #fff;
  padding: 20px;
  border-radius: 6px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
h1 {
  font-size: 18px;
  margin-bottom: 12px;
  text-align: center;
}
input,button,select {
  width: 100%;
  padding: 8px;
  margin: 4px 0;
  font-size: 14px;
}
button {
  background: #007bff;
  color: #fff;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}
button:hover { background: #0056b3; }

/* Tabel */
.table-container {
  width: 100%;
  overflow-x: auto;
}
#onu_table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 12px;
  min-width: 800px; /* agar tabel tetap terbaca di mobile */
}
#onu_table th, #onu_table td {
  border: 1px solid #e1e1e1;
  padding: 6px;
  text-align: center;
  font-size: 13px;
  white-space: nowrap;
}
#onu_table th {
  background: #007bff;
  color: #fff;
}
tr.up { background: #eaffea; }
tr.down { background: #ffeaea; }
tr:hover { background: #fff7d6; }

.logout {
  float: right;
  margin-top: -30px;
  font-size: 14px;
}

/* Spinner loading */
#loading {
  display: none;
  text-align: center;
  margin: 15px 0;
}
.spinner {
  border: 4px solid #f3f3f3;
  border-top: 4px solid #007bff;
  border-radius: 50%;
  width: 30px;
  height: 30px;
  animation: spin 1s linear infinite;
  margin: auto;
}
@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
</head>
<body>
<div class="container">
  <h1>HIOSO ONU Auto Manager</h1>

<?php if(!$logged_in): ?>
  <!-- Form Login -->
  <form method="post">
    <label>IP / Host</label>
    <input type="text" name="ip" required>
    <label>Username</label>
    <input type="text" name="user" required>
    <label>Password</label>
    <input type="password" name="pass" required>
    <button type="submit" name="login">Login</button>
  </form>
<?php else: ?>
  <!-- <div class="logout"><a href="?logout=1">Logout</a></div> -->

  <label>Pilih PON</label>
  <select id="pon_select"></select>

  <!-- Spinner Loading -->
  <div id="loading">
    <div class="spinner"></div>
    <p>Sedang memuat data...</p>
  </div>

  <!-- Table ONU Responsif -->
  <div class="table-container">
    <table id="onu_table">
      <thead>
        <tr>
          <th>#</th>
          <th>ONU No</th>
          <th>MAC</th>
          <th>Status</th>
          <th>MTU</th>
          <th>CHIP</th>
          <th>PORTNUM</th>
          <th>Distance</th>
          <th>Temperature</th>
          <th>Tx Power</th>
          <th>Rx Power</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>

  <script>
  let currentPon = '';

  function loadData(autoSelect=false){
    const loading = document.getElementById('loading');
    loading.style.display = 'block';

    const ponParam = currentPon ? '&pon='+encodeURIComponent(currentPon) : '';
    fetch('?fetch=1'+ponParam)
      .then(r => r.json())
      .then(data => {
        loading.style.display = 'none';
        if(data.error) return;

        // Update select PON
        const sel = document.getElementById('pon_select');
        let oldPon = sel.value;
        sel.innerHTML = '';
        let firstPon = null;
        for(let k in data.pon){
          let opt = document.createElement('option');
          opt.value = k;
          opt.text = k+' ('+data.pon[k]+')';
          if(k===data.selectedPon) opt.selected = true;
          sel.appendChild(opt);
          if(!firstPon) firstPon = k;
        }

        if(autoSelect && firstPon && !currentPon){
          currentPon = firstPon;
          loadData();
          return;
        }

        sel.onchange = function(){
          currentPon = this.value;
          loadData();
        }

        currentPon = sel.value;

        // Update table
        const tbody = document.querySelector('#onu_table tbody');
        tbody.innerHTML = '';
        data.onu.forEach((r,i) => {
          let tr = document.createElement('tr');
          let status = r[3] || '';
          let cls = status.toLowerCase().includes('up') ? 'up' : 'down';
          tr.className = cls;

          let dist = parseFloat(r[12]||0) * 1.6393;
          if(dist > 157) dist -= 157;

          tr.innerHTML = `
            <td>${i+1}</td>
            <td>${r[0]||''}</td>
            <td>${r[2]||''}</td>
            <td>${status}</td>
            <td>${r[4]||''}</td>
            <td>${r[5]||''}</td>
            <td>${r[6]||''}</td>
            <td>${Math.round(dist)}</td>
            <td>${r[7]||''}</td>
            <td>${r[10]||''}</td>
            <td>${r[11]||''}</td>
          `;
          tbody.appendChild(tr);
        });
      })
      .catch(() => {
        loading.style.display = 'none';
      });
  }

  // initial load + auto refresh
  loadData(true);
  setInterval(loadData, 10000);
  </script>
<?php endif; ?>
</div>
</body>
</html>
