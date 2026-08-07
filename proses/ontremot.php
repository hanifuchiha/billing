<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);



// ambil konfigurasi
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

//server utama
$router_ip = $config['router_ip'];
$ippublicportapi = $config['ippublicportapi'];
$router_user = $config['router_user'];
$router_pass = $config['router_pass'];
$vpn_ip_local = $config['vpn_ip_local'];
//servertujuan 




// -- SETTING DEFAULT KONEKSI MIKROTIK (ubah bila perlu) --
 $SERVER_MAIN_IP = $router_ip;
 $SERVER_MAIN_API_PORT = $ippublicportapi;
 $SERVER_MAIN_USER = $router_user;
 $SERVER_MAIN_PASS = $router_pass;

$REMOTE_DEFAULT_USER = $router_user;
$REMOTE_DEFAULT_PASS = $router_pass;



function rand_secret_name($prefix = 'rmt'){
    return $prefix . '-' . bin2hex(random_bytes(3));
}
function rand_secret_password(){
    return bin2hex(random_bytes(4));
}

function pick_free_vpn_ip($API) {
    $used = [];
    $secrets = $API->comm('/ppp/secret/print');
    if (is_array($secrets)) {
        foreach ($secrets as $row) {
            if (!empty($row['remote-address'])) {
                $used[$row['remote-address']] = true;
            }
        }
    }
    $active = $API->comm('/ppp/active/print');
    if (is_array($active)) {
        foreach ($active as $row) {
            if (!empty($row['address'])) {
                $used[$row['address']] = true;
            }
        }
    }
    for ($i = 2; $i <= 254; $i++) {
        $ip = "10.10.10.$i";
        if (!isset($used[$ip])) {
            return $ip;
        }
    }
    return false;
}
function pick_free_public_port($API){
    $used = [];
    $list = $API->comm('/ip/firewall/nat/print');
    if (is_array($list)){
        foreach($list as $row){
            if (isset($row['dst-port'])){
                $ports = explode(',', $row['dst-port']);
                foreach($ports as $p) $used[trim($p)] = true;
            }
        }
    }
    for($p=4001;$p<=4999;$p++) if (!isset($used[$p])) return $p;
    return false;
}

// Parse format tanggal/jam MikroTik ("jul/18/2026" + "16:15:12") jadi DateTime PHP.
// Dipakai baik untuk baca jam router sekarang maupun untuk baca ulang start-date/
// start-time yang tersimpan di scheduler (buat tahu kapan persis suatu mapping expired).
function mikrotik_parse_datetime($date_str, $time_str){
    $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
    if (preg_match('#^([a-zA-Z]{3})/(\d{2})/(\d{4})$#', trim((string)$date_str), $m) && isset($months[strtolower($m[1])])) {
        $parts = explode(':', trim((string)$time_str));
        if (count($parts) === 3) {
            $dt = new DateTime('now');
            $dt->setDate((int)$m[3], $months[strtolower($m[1])], (int)$m[2]);
            $dt->setTime((int)$parts[0], (int)$parts[1], (int)$parts[2]);
            return $dt;
        }
    }
    return null;
}

// Jam & tanggal ROUTER saat ini (bukan jam server PHP, bisa beda). Fallback ke jam
// server PHP kalau /system/clock/print entah kenapa tidak terbaca.
function mikrotik_router_now($API){
    $clock = $API->comm('/system/clock/print');
    if (is_array($clock) && isset($clock[0]['date'], $clock[0]['time'])) {
        $dt = mikrotik_parse_datetime($clock[0]['date'], $clock[0]['time']);
        if ($dt) return $dt;
    }
    return new DateTime('now');
}

// Jam+tanggal router + $minutes ke depan, format siap pakai untuk start-date/start-time
// scheduler. Dipakai supaya scheduler MikroTik benar-benar mulai di masa depan.
function mikrotik_future_datetime($API, $minutes){
    $dt = mikrotik_router_now($API);
    $dt->modify("+{$minutes} minutes");
    $monthNames = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    $start_date = $monthNames[(int)$dt->format('n') - 1] . '/' . $dt->format('d') . '/' . $dt->format('Y');
    $start_time = $dt->format('H:i:s');
    return [$start_date, $start_time];
}

// Buat scheduler MikroTik yang jalan SEKALI, benar-benar $minutes menit dari sekarang
// (menggunakan jam router, bukan "00:00:00" yang sudah lewat sehingga langsung tereksekusi).
// Hasilnya dicek & ditampilkan sebagai debug juga - kalau scheduler-nya sendiri gagal
// dibuat, auto-hapus tidak akan pernah jalan, dan itu HARUS ketahuan, bukan diam-diam gagal.
function mikrotik_schedule_once($API, $name, $on_event, $minutes = 10, $comment = null){
    list($start_date, $start_time) = mikrotik_future_datetime($API, $minutes);
    $params = [
        'name' => $name,
        'on-event' => $on_event,
        'start-date' => $start_date,
        'start-time' => $start_time,
        'interval' => '00:00:00'
    ];
    if ($comment !== null) {
        $params['comment'] = $comment;
    }
    return mikrotik_debug_comm($API, '/system/scheduler/add', $params, "Scheduler auto-hapus ($name)");
}

// routeros_api mengembalikan array berisi key "!trap" kalau command gagal.
// Dipakai supaya kegagalan di tengah proses (mis. gagal bikin sstp-client / NAT / filter)
// tidak lagi diam-diam ketelan dan tetap ditampilkan sebagai "Berhasil" ke user.
function mikrotik_result_error($result){
    if (is_array($result) && isset($result['!trap'][0]['message'])) {
        return $result['!trap'][0]['message'];
    }
    if (is_array($result) && isset($result['!trap'])) {
        return json_encode($result['!trap']);
    }
    return null;
}

function mikrotik_debug_comm($API, $cmd, $params, $label){
    $result = $API->comm($cmd, $params);
    $err = mikrotik_result_error($result);
    if ($err) {
        echo "<span class='badge bg-danger'>DEBUG</span> $label: <b>GAGAL</b> - " . htmlspecialchars($err) . "<br>";
    } else {
        echo "<span class='badge bg-success'>DEBUG</span> $label: <b>OK</b><br>";
    }
    return $result;
}

// dst-nat saja tidak cukup - setelah tujuan ditranslasi, paket tetap harus lolos
// chain "forward". Kalau router itu punya rule drop-all forward, traffic yang baru
// di-NAT bisa didrop diam-diam (gejalanya: connection timed out, bukan refused).
// Rule accept ini ditaruh paling atas chain forward supaya tidak ketiban rule drop lain.
function mikrotik_forward_accept($API, $params, $label){
    $place_before = null;
    $existing_forward = $API->comm('/ip/firewall/filter/print', ['?chain' => 'forward']);
    if (is_array($existing_forward) && isset($existing_forward[0]['.id'])) {
        $place_before = $existing_forward[0]['.id'];
    }
    $params['chain'] = 'forward';
    if ($place_before) {
        $params['place-before'] = $place_before;
    }
    return mikrotik_debug_comm($API, '/ip/firewall/filter/add', $params, $label);
}

// Buang karakter yang bisa merusak script RouterOS ("on-event" scheduler dibangun
// sebagai satu string script, jadi tanda kutip/kurung siku/titik-koma dari input
// pelanggan tidak boleh ikut kebawa ke situ).
function mikrotik_safe($text){
    return str_replace(['"', '[', ']', ';'], '', (string)$text);
}

// Cari NAT publik yang masih ada untuk pelanggan yang sama (comment-nya selalu
// diawali "RemoteONT:<idPel> -" baik mode LOCAL maupun VPN). "Ketemu" di sini belum
// tentu berarti masih aktif - dicek lagi jam expired-nya di mikrotik_reuse_or_cleanup()
// karena scheduler auto-hapusnya bisa saja gagal jalan (mis. router sempat reboot).
function mikrotik_find_existing_port($API, $server_ip, $customer_prefix){
    $rules = $API->comm('/ip/firewall/nat/print', ['?chain' => 'dstnat', '?dst-address' => $server_ip]);
    if (is_array($rules)) {
        foreach ($rules as $row) {
            if (isset($row['comment'], $row['dst-port']) && strpos($row['comment'], $customer_prefix) === 0) {
                return $row['dst-port'];
            }
        }
    }
    return null;
}

// Ambil waktu auto-hapus (start-date + start-time) dari scheduler yang cocok, supaya
// mapping yang di-reuse bisa menunjukkan kapan persisnya akan terhapus, bukan cuma
// mengulang teks "60 menit lagi" yang sudah tidak akurat untuk sesi lama.
function mikrotik_find_existing_expiry($API, $customer_prefix){
    $schedulers = $API->comm('/system/scheduler/print');
    if (is_array($schedulers)) {
        foreach ($schedulers as $row) {
            if (isset($row['comment']) && strpos($row['comment'], $customer_prefix) === 0) {
                $date = $row['start-date'] ?? '';
                $time = $row['start-time'] ?? '';
                return trim("$date $time");
            }
        }
    }
    return null;
}

// Sapu bersih semua object (ppp secret, NAT, filter, scheduler) di router ini yang
// comment-nya diawali $prefix. Dipakai sebagai jaring pengaman kalau mapping lama
// ternyata sudah lewat jam expired-nya tapi scheduler auto-hapusnya gagal jalan
// (mis. router sempat reboot) - supaya tidak numpuk jadi sampah permanen.
function mikrotik_cleanup_by_prefix($API, $prefix){
    $tables = ['/ppp/secret', '/ip/firewall/nat', '/ip/firewall/filter', '/system/scheduler'];
    foreach ($tables as $path) {
        $rows = $API->comm($path . '/print');
        if (!is_array($rows)) continue;
        foreach ($rows as $row) {
            if (isset($row['comment'], $row['.id']) && strpos($row['comment'], $prefix) === 0) {
                $API->comm($path . '/remove', ['.id' => $row['.id']]);
            }
        }
    }
}

// Inti logika "kalau pelanggan sama masih dalam jendela waktu aktif, pakai yang sudah
// ada; kalau sudah lewat, hapus lalu buat baru": cari mapping existing di router utama,
// baca jam expired-nya yang sebenarnya (bukan cuma tebak-tebakan), lalu bandingkan
// dengan jam router SEKARANG. Return [port, teks_expiry] kalau masih aktif, atau null
// kalau tidak ada mapping / sudah lewat waktunya (yang basi langsung disapu di sini).
function mikrotik_reuse_or_cleanup($API, $server_ip, $customer_prefix){
    $port = mikrotik_find_existing_port($API, $server_ip, $customer_prefix);
    if (!$port) return null;

    $expiry_text = mikrotik_find_existing_expiry($API, $customer_prefix);
    $expiry_dt = null;
    if ($expiry_text) {
        $parts = explode(' ', $expiry_text, 2);
        if (count($parts) === 2) {
            $expiry_dt = mikrotik_parse_datetime($parts[0], $parts[1]);
        }
    }

    if ($expiry_dt && $expiry_dt <= mikrotik_router_now($API)) {
        mikrotik_cleanup_by_prefix($API, $customer_prefix);
        return null;
    }

    return [$port, $expiry_text];
}

// Kartu HTML "sudah ada mapping aktif" - dipakai bareng oleh mode LOCAL & VPN supaya
// tidak duplikat markup, dan supaya tetap konsisten kalau ada perubahan desain nanti.
// Ada tombol "Buat Baru (paksa)" untuk kasus mapping lama masih dalam jendela waktu
// aktif secara hitungan waktu, tapi sebenarnya sudah mati (tunnel putus, ONT tidak
// respon, dll) - operator bisa langsung minta dibuatkan yang baru tanpa nunggu expired.
function echo_existing_mapping_card($customer, $server_ip, $port, $expiry, $remote_ip, $ip_server, $user_server, $password_server){
    echo '
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
            <div class="card shadow-lg border-0">
                <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Remote Mapping Sudah Aktif</h5>
                </div>
                <div class="card-body">
                <p><strong>Customer:</strong> ' . htmlspecialchars($customer) . '</p>
                <p class="text-muted">Sesi remote untuk pelanggan ini sudah dibuat sebelumnya dan masih aktif, jadi tidak dibuatkan yang baru.</p>
                <div class="alert alert-info">
                    <strong>Public Access:</strong><br>
                    <a href="http://' . $server_ip . ':' . $port . '" target="_blank">
                    http://' . $server_ip . ':' . $port . '
                    </a>
                </div>
                <form method="POST" action="ontremot.php" class="mt-2">
                    <input type="hidden" name="idPel" value="' . htmlspecialchars($customer) . '">
                    <input type="hidden" name="remote_ip" value="' . htmlspecialchars($remote_ip) . '">
                    <input type="hidden" name="ipServer" value="' . htmlspecialchars($ip_server) . '">
                    <input type="hidden" name="userServer" value="' . htmlspecialchars($user_server) . '">
                    <input type="hidden" name="passwordServer" value="' . htmlspecialchars($password_server) . '">
                    <input type="hidden" name="force_new" value="1">
                    <button type="submit" class="btn btn-warning btn-sm">Buat Baru (paksa) - kalau yang lama sudah mati</button>
                </form>
                </div>
                <div class="card-footer text-muted">
                Auto-hapus pada: ' . htmlspecialchars($expiry ?: '-') . '
                </div>
            </div>
            </div>
        </div>
        </div>
        ';
}

// Pastikan SSTP-server di router utama aktif DAN jalan di port 4433 (konvensi yang
// dipakai fitur ini & fitur VPN lain seperti vpn.php). Beberapa router ternyata masih
// default di port 443 - daripada gagal connect diam-diam karena port beda, paksa
// samakan ke 4433 dulu sebelum client di router remote coba dial.
function mikrotik_ensure_sstp_server($API, $port = '4433'){
    $server = $API->comm('/interface/sstp-server/server/print');
    $current_port = (is_array($server) && isset($server[0]['port'])) ? $server[0]['port'] : null;
    $current_enabled = (is_array($server) && isset($server[0]['enabled'])) ? $server[0]['enabled'] : null;

    // Field "enabled" bisa balik sebagai "yes"/"no" atau "true"/"false" tergantung
    // versi RouterOS-nya, jadi terima dua-duanya.
    if ($current_port === $port && in_array($current_enabled, ['true', 'yes'], true)) {
        echo "<span class='badge bg-success'>DEBUG</span> SSTP server (utama): <b>sudah port $port</b><br>";
        return $port;
    }

    mikrotik_debug_comm($API, '/interface/sstp-server/server/set', [
        'port' => $port,
        'enabled' => 'yes'
    ], "SSTP server (utama) diubah ke port $port");

    return $port;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'){

                    // TEMP: dilonggarkan ke 60 menit selama debugging supaya mapping
                    // tidak keburu auto-hapus di tengah proses diagnosa. Kembalikan ke
                    // 10 setelah dikonfirmasi jalan normal.
                    $expire_minutes = 60;

                    $mode = $_POST['mode'] ?? 'VPN';
                    $customer = mikrotik_safe($_POST['idPel'] ?? '');
                    $remote_pppoe_ip =  $_POST['remote_ip'];
                    $pppoe_user = $_POST['idPel'];
                    $pppoe_pass = $_POST['idPel'];
                    $web_port_choice ='80';
                    $remote_mk_ip = $_POST['ipServer'];
                    $remote_api_user = $_POST['userServer'];
                    $remote_api_pass = $_POST['passwordServer'];
                    // Dari tombol "Buat Baru (paksa)" di kartu mapping-sudah-aktif - operator
                    // bisa langsung minta mapping baru meski yang lama belum expired (mis.
                    // karena tunnel-nya sudah mati duluan tapi timernya belum habis).
                    $force_new = !empty($_POST['force_new']);

                $ceklocal=$vpn_ip_local.":".$SERVER_MAIN_API_PORT;
                    if (empty($remote_mk_ip)){
                        echo '<div style="color:red">IP Mikrotik tujuan (remote) harus diisi.</div>';
                        exit;
                    }

                    // IP PPPoE pelanggan (ONT tujuan) HARUS dicek LANGSUNG ke Mikrotik di sini,
                    // BUKAN dipercaya dari nilai yang dikirim browser (yang asalnya dari cache
                    // status PPPoE di tables.php - di-refresh tiap ~1 menit lewat cron - atau
                    // dari hidden field form "Buat Baru (paksa)", dua-duanya bisa basi). Kalau
                    // live check ini tidak menemukan sesi aktif, dianggap OFFLINE SAAT INI -
                    // titik, tidak ada fallback balik ke nilai cache/POST, supaya tidak pernah
                    // lanjut pakai IP yang mungkin sudah tidak berlaku lagi.
                    $remote_pppoe_ip = '';
                    $live_check_error = null;
                    if (empty($remote_api_user) || $customer === '') {
                        $live_check_error = 'Data pelanggan/kredensial router tidak lengkap untuk cek langsung ke Mikrotik.';
                    } else {
                        $API_precheck = new RouterosAPI();
                        $API_precheck->timeout = 4;
                        $API_precheck->debug = false;
                        if ($API_precheck->connect($remote_mk_ip, $remote_api_user, $remote_api_pass)) {
                            $liveActive = $API_precheck->comm('/ppp/active/print', ['?name' => $customer]);
                            $API_precheck->disconnect();
                            if (is_array($liveActive) && isset($liveActive[0]['address']) && $liveActive[0]['address'] !== '') {
                                $remote_pppoe_ip = $liveActive[0]['address'];
                                echo "<span class='badge bg-success'>DEBUG</span> Cek langsung ke Mikrotik: sesi aktif ditemukan, IP live <code>" . htmlspecialchars($remote_pppoe_ip) . "</code>.<br>";
                            } else {
                                $live_check_error = 'Tidak ada sesi PPP aktif untuk pelanggan ini di router saat ini (dicek langsung, bukan dari cache).';
                            }
                        } else {
                            $live_check_error = 'Gagal connect ke Mikrotik pelanggan (' . htmlspecialchars($remote_mk_ip) . ') untuk cek sesi aktif secara langsung.';
                        }
                    }

                    if (!filter_var($remote_pppoe_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
                        echo '<div class="alert alert-danger m-3"><strong>Gagal:</strong> ' . htmlspecialchars($live_check_error ?? 'IP PPPoE pelanggan tidak valid/tidak diketahui.')
                            . ' Kemungkinan pelanggan sedang <b>offline</b>. '
                            . 'Remote ONT hanya bisa dipakai saat status pelanggan Online.</div>';
                        exit;
                    }


    if ($remote_mk_ip == $ceklocal){
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo "<div class='alert alert-secondary mb-2'><span class='badge bg-info'>DEBUG</span> Mode: <b>LOCAL</b><br>";
                                        $API = new RouterosAPI();
                                        $API->debug = false;
                                    echo "<span class='badge bg-info'>DEBUG</span> Koneksi ke Mikrotik utama: <code>$SERVER_MAIN_IP:$SERVER_MAIN_API_PORT</code><br>";
                                        if ($API->connect($SERVER_MAIN_IP.":".$SERVER_MAIN_API_PORT, $SERVER_MAIN_USER, $SERVER_MAIN_PASS)) {
                                            echo "<span class='badge bg-success'>DEBUG</span> Koneksi Mikrotik utama: <b>SUKSES</b><br>";

                                            // Kalau pelanggan yang sama masih punya mapping aktif (belum expired),
                                            // pakai yang sudah ada - jangan bikin duplikat NAT/port baru. Kalau
                                            // ternyata sudah lewat waktu expired-nya, mikrotik_reuse_or_cleanup()
                                            // otomatis membersihkannya dulu dan kita lanjut bikin yang baru di bawah.
                                            // "force_new" (dari tombol "Buat Baru (paksa)") langsung skip semua ini
                                            // dan bersihkan paksa mapping lama, tanpa peduli masih aktif atau tidak.
                                            if ($customer !== '') {
                                                $existing_prefix = "RemoteONT:$customer -";
                                                if ($force_new) {
                                                    echo "<span class='badge bg-warning text-dark'>DEBUG</span> Buat baru dipaksa, membersihkan mapping lama untuk '$customer' (kalau ada).<br>";
                                                    mikrotik_cleanup_by_prefix($API, $existing_prefix);
                                                } else {
                                                    $existing = mikrotik_reuse_or_cleanup($API, $SERVER_MAIN_IP, $existing_prefix);
                                                    if ($existing) {
                                                        list($existing_port, $existing_expiry) = $existing;
                                                        echo "<span class='badge bg-info'>DEBUG</span> Mapping aktif untuk '$customer' sudah ada (port <code>$existing_port</code>), memakai yang sudah ada.<br>";
                                                        $API->disconnect();
                                                        echo_existing_mapping_card($customer, $SERVER_MAIN_IP, $existing_port, $existing_expiry, $remote_pppoe_ip, $remote_mk_ip, $remote_api_user, $remote_api_pass);
                                                        exit;
                                                    }
                                                }
                                            }

                                            $vpn_ip = pick_free_vpn_ip($API);
                                            echo "<span class='badge bg-info'>DEBUG</span> IP VPN yang dipilih: <code>$vpn_ip</code><br>";
                                            if (!$vpn_ip){
                                                echo '<div style="color:red">VPN IP FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            $secret_name = rand_secret_name('secret');
                                            $secret_pass = rand_secret_password();
                                            $comment = $customer !== '' ? $customer : "auto-$secret_name";

                                       
                                            $public_port = pick_free_public_port($API);
                                            echo "<span class='badge bg-info'>DEBUG</span> Port publik yang dipilih: <code>$public_port</code><br>";
                                            if (!$public_port){
                                                echo '<div style="color:red">VPN PORT FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            // Comment selalu menyertakan ID pelanggan + arah rute biar jelas
                                            // tanpa perlu buka detail rule-nya satu-satu.
                                            $nat_comment = "RemoteONT:$comment - LOCAL Main($SERVER_MAIN_IP:$public_port) -> ONT($remote_pppoe_ip:80)";
                                            mikrotik_debug_comm($API, '/ip/firewall/nat/add', [
                                                'chain'=>'dstnat',
                                                'dst-address'=>$SERVER_MAIN_IP,
                                                'protocol'=>'tcp',
                                                'dst-port'=>(string)$public_port,
                                                'action'=>'dst-nat',
                                                'to-addresses'=>$remote_pppoe_ip,
                                                'to-ports'=>'80',
                                                'comment'=>$nat_comment
                                            ], 'NAT publik (lokal)');

                                            // Sama seperti mode VPN: dst-nat saja tidak menjamin paket lolos chain
                                            // "forward" setelah tujuannya ditranslasi ke $remote_pppoe_ip.
                                            mikrotik_forward_accept($API, [
                                                'dst-address' => $remote_pppoe_ip,
                                                'protocol'    => 'tcp',
                                                'dst-port'    => '80',
                                                'action'      => 'accept',
                                                'comment'     => $nat_comment
                                            ], 'Firewall forward accept (lokal)');

                                            // Nama scheduler pakai $secret_name (selalu unik per klik), BUKAN $comment
                                            // (ID pelanggan) - kalau dua mapping dibuat untuk pelanggan yang sama
                                            // sebelum yang pertama expired, nama scheduler akan bentrok dan gagal
                                            // dibuat, dan mapping kedua jadi tidak pernah kehapus otomatis.
                                            mikrotik_schedule_once($API, "expire-$secret_name",
                                                '/ip firewall nat remove [find comment="' . $nat_comment . '"]; /ip firewall filter remove [find comment="' . $nat_comment . '"]; /system scheduler remove [find name="expire-' . $secret_name . '"];',
                                                $expire_minutes,
                                                "RemoteONT:$comment - auto-hapus NAT & filter lokal $expire_minutes menit"
                                            );

                                            $history_file = "../notifbot/data/history-$ceknama.json";
                                            $history = [];
                                            if (file_exists($history_file)) {
                                                $history = json_decode(file_get_contents($history_file), true);
                                            }
                                            if (!is_array($history)) $history = [];
                                            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil membuat remote mapping untuk '$customer'";
                                            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                                            $API->disconnect();


                                            echo '
                                                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                                    </head>
                                    <div class="container mt-4">
                                    <div class="row justify-content-center">
                                        <div class="col-md-8">
                                        <div class="card shadow-lg border-0">
                                            <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0">VPN Remote Mapping Berhasil</h5>
                                            </div>
                                            <div class="card-body">
                                            <p><strong>Customer:</strong> ' . htmlspecialchars($customer) . '</p>
                                            <p class="text-danger"><i class="bi bi-clock-history"></i> Remote ini hanya berlaku <strong>' . $expire_minutes . ' menit</strong> kemudian akan otomatis terhapus.</p>
                                            <div class="alert alert-info">
                                                <strong>Public Access:</strong><br>
                                                <a href="http://' . $SERVER_MAIN_IP . ':' . $public_port . '" target="_blank">
                                                http://' . $SERVER_MAIN_IP . ':' . $public_port . '
                                                </a>
                                            </div>
                                            </div>
                                            <div class="card-footer text-muted">
                                            Dibuat pada: ' . date("d-m-Y H:i:s") . '
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                    </div>
                                    ';
                                    exit;

                                            } else {
                                                echo "<span class='badge bg-danger'>DEBUG</span> Koneksi Mikrotik utama: <b>GAGAL</b><br>";
                                                echo '<div style="color:red">Gagal konek ke Mikrotik Tujuan.</div>';
                                            }
      } else {

        //////VIA VPN


                                        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
                                        echo "<div class='alert alert-secondary mb-2'><span class='badge bg-info'>DEBUG</span> Mode: <b>REMOTE VPN</b><br>";
                                            $API = new RouterosAPI();
                                            $API->debug = false;
                                        echo "<span class='badge bg-info'>DEBUG</span> Koneksi ke Mikrotik utama: <code>$SERVER_MAIN_IP:$SERVER_MAIN_API_PORT</code><br>";
                                            if ($API->connect($SERVER_MAIN_IP.":".$SERVER_MAIN_API_PORT, $SERVER_MAIN_USER, $SERVER_MAIN_PASS)) {
                                                echo "<span class='badge bg-success'>DEBUG</span> Koneksi Mikrotik utama: <b>SUKSES</b><br>";

                                            // Kalau pelanggan yang sama masih punya mapping aktif (belum expired),
                                            // pakai yang sudah ada - jangan bikin secret/tunnel/port duplikat. Kalau
                                            // ternyata sudah lewat waktu expired-nya, mikrotik_reuse_or_cleanup()
                                            // otomatis membersihkannya dulu dan kita lanjut bikin yang baru di bawah.
                                            // "force_new" (dari tombol "Buat Baru (paksa)") langsung skip semua ini
                                            // dan bersihkan paksa mapping lama, tanpa peduli masih aktif atau tidak.
                                            if ($customer !== '') {
                                                $existing_prefix = "RemoteONT:$customer -";
                                                if ($force_new) {
                                                    echo "<span class='badge bg-warning text-dark'>DEBUG</span> Buat baru dipaksa, membersihkan mapping lama untuk '$customer' (kalau ada).<br>";
                                                    mikrotik_cleanup_by_prefix($API, $existing_prefix);
                                                } else {
                                                    $existing = mikrotik_reuse_or_cleanup($API, $SERVER_MAIN_IP, $existing_prefix);
                                                    if ($existing) {
                                                        list($existing_port, $existing_expiry) = $existing;
                                                        echo "<span class='badge bg-info'>DEBUG</span> Mapping aktif untuk '$customer' sudah ada (port <code>$existing_port</code>), memakai yang sudah ada.<br>";
                                                        $API->disconnect();
                                                        echo_existing_mapping_card($customer, $SERVER_MAIN_IP, $existing_port, $existing_expiry, $remote_pppoe_ip, $remote_mk_ip, $remote_api_user, $remote_api_pass);
                                                        exit;
                                                    }
                                                }
                                            }

                                            // Pastikan SSTP-server utama jalan di port 4433 (bukan asumsi/hardcode
                                            // tanpa cek) - kalau masih di port lain (mis. 443 default), diubah dulu.
                                            $sstp_server_port = mikrotik_ensure_sstp_server($API, '4433');
                                            $vpn_ip = pick_free_vpn_ip($API);
                                            echo "<span class='badge bg-info'>DEBUG</span> IP VPN yang dipilih: <code>$vpn_ip</code><br>";
                                            if (!$vpn_ip){
                                                echo '<div style="color:red">VPN IP FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            $secret_name = rand_secret_name('secret');
                                            $secret_pass = rand_secret_password();
                                            $comment = $customer !== '' ? $customer : "auto-$secret_name";

                                            // tambah secret (pakai SSTP, bukan L2TP, karena L2TP tidak stabil)
                                            mikrotik_debug_comm($API, '/ppp/secret/add', [
                                                'name' => $secret_name,
                                                'password' => $secret_pass,
                                                'local-address' => $vpn_ip_local,
                                                'remote-address' => $vpn_ip,
                                                'service' => 'sstp',
                                                'comment' => "RemoteONT:$comment - PPP secret SSTP (tunnel Remote -> Main)"
                                            ], 'PPP secret (utama)');

                                            $public_port = pick_free_public_port($API);
                                            echo "<span class='badge bg-info'>DEBUG</span> Port publik yang dipilih: <code>$public_port</code><br>";
                                            if (!$public_port){
                                                echo '<div style="color:red">VPN PORT FULL</div>';
                                                $API->disconnect();
                                                exit;
                                            }

                                            // Comment ini dipakai bareng oleh NAT di router utama, filter dan NAT
                                            // di router remote (semuanya bagian dari satu mapping yang sama), jadi
                                            // sekalian dipakai untuk nunjukin arah rute end-to-end-nya.
                                            $nat_comment = "RemoteONT:$comment - Main($SERVER_MAIN_IP:$public_port) -> VPN($vpn_ip:$public_port) -> ONT($remote_pppoe_ip:80) via SSTP";
                                            mikrotik_debug_comm($API, '/ip/firewall/nat/add', [
                                                'chain'=>'dstnat',
                                                'dst-address'=>$SERVER_MAIN_IP,
                                                'protocol'=>'tcp',
                                                'dst-port'=>(string)$public_port,
                                                'action'=>'dst-nat',
                                                'to-addresses'=>$vpn_ip,
                                                'to-ports'=>$public_port,
                                                'comment'=>$nat_comment
                                            ], 'NAT publik (utama)');

                                            // Dst-nat saja tidak cukup: setelah dst-address ditranslasi ke $vpn_ip,
                                            // paket harus lolos chain "forward" di router utama juga sebelum masuk
                                            // ke tunnel SSTP. Tanpa ini traffic bisa didrop diam-diam (connection timed out)
                                            // meskipun ping dari router sendiri ke $vpn_ip terlihat normal (ping router
                                            // sendiri lewat chain "output", bukan "forward").
                                            mikrotik_forward_accept($API, [
                                                'dst-address' => $vpn_ip,
                                                'protocol'    => 'tcp',
                                                'dst-port'    => (string)$public_port,
                                                'action'      => 'accept',
                                                'comment'     => $nat_comment
                                            ], 'Firewall forward accept (utama)');

                                            // Router remote dial keluar dengan add-default-route=no dan tidak tahu
                                            // cara balik ke IP publik client asli lewat tunnel - begitu balasan dari
                                            // ONT sampai di router remote dan di-un-NAT sepenuhnya (tujuannya berubah
                                            // balik jadi IP publik client asli), router remote cuma punya default
                                            // route ke internetnya sendiri, bukan lewat tunnel, jadi balasan nyasar.
                                            // Fix: masquerade traffic SEBELUM masuk tunnel supaya dari sudut pandang
                                            // router remote, semua request "seolah-olah" datang dari router utama
                                            // sendiri (alamat peer tunnel yang otomatis routable), bukan dari IP
                                            // publik acak - jadi balasannya selalu bisa dirutekan balik lewat tunnel.
                                            mikrotik_debug_comm($API, '/ip/firewall/nat/add', [
                                                'chain'=>'srcnat',
                                                'dst-address'=>$vpn_ip,
                                                'protocol'=>'tcp',
                                                'dst-port'=>(string)$public_port,
                                                'action'=>'masquerade',
                                                'comment'=>$nat_comment
                                            ], 'Masquerade masuk tunnel (utama)');

                                                // "/ppp active remove" ditaruh duluan supaya sesi tunnel yang masih
                                                // nyambung langsung diputus paksa di sini juga - tidak cuma mengandalkan
                                                // sstp-client di router remote yang menghapus dirinya sendiri (yang bisa
                                                // gagal kalau router remote kebetulan tidak reachable saat timernya jalan).
                                                // Nama scheduler pakai $secret_name (unik per klik), bukan $comment/ID
                                                // pelanggan - supaya klik ulang untuk pelanggan yang sama sebelum mapping
                                                // lama expired tidak bentrok nama & gagal dibuat (lihat mode LOCAL).
                                                mikrotik_schedule_once($API, "expire-$secret_name",
                                                    '/ppp active remove [find name="' . $secret_name . '"]; /ppp secret remove [find name="' . $secret_name . '"]; /ip firewall nat remove [find comment="' . $nat_comment . '"]; /ip firewall filter remove [find comment="' . $nat_comment . '"]; /system scheduler remove [find name="expire-' . $secret_name . '"];',
                                                    $expire_minutes,
                                                    "RemoteONT:$comment - auto-hapus sesi aktif, PPP secret, NAT & filter utama $expire_minutes menit"
                                                );


                                            // konek ke remote
                                            $API_remote = new RouterosAPI();
                                            $API_remote->debug = false;
                                            echo "<span class='badge bg-info'>DEBUG</span> Koneksi ke Mikrotik remote: <code>$remote_mk_ip</code><br>";
                                            if ($API_remote->connect($remote_mk_ip, $remote_api_user, $remote_api_pass)) {
                                                echo "<span class='badge bg-success'>DEBUG</span> Koneksi Mikrotik remote: <b>SUKSES</b><br>";
                                                // Pakai SSTP (bukan L2TP) karena L2TP tidak stabil. Port 4433 & profile
                                                // "default-encryption" mengikuti konvensi SSTP yang sudah dipakai di
                                                // fitur VPN lain (lihat vpn.php / api/vpn_list.php).
                                                $sstp_name = 'sstp-to-main-' . bin2hex(random_bytes(2));
                                                // "connect-to" cuma boleh berisi address/hostname - port harus
                                                // dikirim terpisah lewat parameter "port", tidak boleh digabung
                                                // "ip:port" (itu bikin API MikroTik menolak dengan "bad address or dns name").
                                                $sstp_add_result = mikrotik_debug_comm($API_remote, '/interface/sstp-client/add', [
                                                    'name'        => $sstp_name,
                                                    'connect-to'  => $SERVER_MAIN_IP,
                                                    'port'        => $sstp_server_port,
                                                    'user'        => $secret_name,
                                                    'password'    => $secret_pass,
                                                    'profile'     => 'default-encryption',
                                                    'verify-server-certificate' => 'no',
                                                    // "verify-server-certificate" dan "verify-server-address-from-certificate"
                                                    // adalah dua flag TERPISAH di MikroTik - matiin yang pertama saja tidak
                                                    // otomatis matiin yang kedua. Kalau server SSTP tidak (atau belum benar)
                                                    // punya certificate, flag kedua ini bisa bikin handshake gagal/disconnect
                                                    // meski verify-server-certificate sudah off.
                                                    'verify-server-address-from-certificate' => 'no',
                                                    'authentication' => 'mschap2',
                                                    'add-default-route' => 'no',
                                                    'dial-on-demand'    => 'no',
                                                    'disabled'   => 'no',
                                                    'comment'     => "RemoteONT:$comment - SSTP client (Remote -> Main tunnel)",
                                                ], 'SSTP client (remote)');

                                                // Kalau add-nya sendiri gagal (mis. "unknown parameter" - biasanya salah
                                                // satu flag di atas tidak dikenali versi RouterOS router remote ini),
                                                // interface $sstp_name TIDAK PERNAH ada. Melanjutkan ke forward-accept/
                                                // NAT/masquerade di bawah cuma menghasilkan error susulan yang membingungkan
                                                // ("input does not match any value of interface" dst) karena semuanya
                                                // mengacu ke interface yang tidak ada. Lebih baik berhenti di sini,
                                                // bersihkan state yang sudah terlanjur dibuat di router utama (secret,
                                                // NAT, filter, scheduler), dan kasih pesan yang jelas.
                                                if (mikrotik_result_error($sstp_add_result)) {
                                                    echo "<span class='badge bg-danger'>DEBUG</span> Dihentikan: SSTP client gagal dibuat, membersihkan konfigurasi di router utama yang sudah terlanjur dibuat.<br>";
                                                    $API_remote->disconnect();
                                                    mikrotik_cleanup_by_prefix($API, "RemoteONT:$comment -");
                                                    $API->disconnect();
                                                    echo '<div class="alert alert-danger m-3"><strong>Gagal membuat SSTP client di router remote.</strong> '
                                                        . 'Kemungkinan ada parameter yang tidak didukung versi RouterOS router remote ini '
                                                        . '(cek pesan GAGAL di atas). Tidak ada sisa konfigurasi yang tertinggal di router utama.</div>';
                                                    exit;
                                                }

                                                // Tunnel butuh beberapa detik untuk connect, tunggu lalu cek statusnya
                                                // supaya kalau gagal dial (password/host salah) langsung ketahuan di sini.
                                                $sstp_running = false;
                                                for ($try = 0; $try < 6; $try++) {
                                                    sleep(1);
                                                    $sstp_status = $API_remote->comm('/interface/sstp-client/print', ['?name' => $sstp_name]);
                                                    if (is_array($sstp_status) && isset($sstp_status[0]['running']) && $sstp_status[0]['running'] === 'true') {
                                                        $sstp_running = true;
                                                        break;
                                                    }
                                                }
                                                if ($sstp_running) {
                                                    echo "<span class='badge bg-success'>DEBUG</span> Tunnel SSTP: <b>CONNECTED</b><br>";
                                                } else {
                                                    echo "<span class='badge bg-danger'>DEBUG</span> Tunnel SSTP: <b>BELUM CONNECTED</b> setelah 6 detik - cek user/password, pastikan service SSTP aktif di router utama (port 4433) dan tidak diblokir firewall/ISP.<br>";
                                                }



                                            // Hitung network address untuk /24
                                            $octets = explode('.', $remote_pppoe_ip);
                                            $network = $octets[0] . '.' . $octets[1] . '.' . $octets[2] . '.0/24';





                                                // "srcnat" bukan chain filter yang valid (itu chain NAT), jadi rule ini
                                                // sebelumnya tidak pernah benar-benar jalan dan traffic ke ONT bisa
                                                // didrop oleh chain "forward".
                                                mikrotik_forward_accept($API_remote, [
                                                    'in-interface' => $sstp_name,
                                                    'dst-address'  => $network,
                                                    'action'       => 'accept',
                                                    'comment'      => $nat_comment
                                                ], 'Firewall forward accept (remote)');

                                                mikrotik_debug_comm($API_remote, '/ip/firewall/nat/add', [
                                                    'chain'=>'dstnat',
                                                    'dst-address'=>$vpn_ip,
                                                    'protocol'=>'tcp',
                                                    'dst-port'=>(string)$public_port,
                                                    'action'=>'dst-nat',
                                                    'to-addresses'=>$remote_pppoe_ip,
                                                    'to-ports'=>$web_port_choice,
                                                    'comment'=>$nat_comment
                                                ], 'NAT ke ONT (remote)');

                                                // sstp-client dipasang tanpa default-route, jadi router remote tidak
                                                // punya rute balik ke IP publik client asli lewat tunnel. Tanpa masquerade
                                                // ini, request dari luar sampai ke ONT (kelihatan di Torch), tapi balasannya
                                                // tidak pernah nyampe balik ke client (asymmetric routing) - koneksi keliatan
                                                // timeout padahal sebenarnya cuma balasannya yang nyasar/hilang.
                                                // Masquerade bikin ONT membalas ke router remote sendiri, dan conntrack yang
                                                // membawa balasan itu balik lewat tunnel SSTP secara otomatis.
                                                // "in-interface" tidak valid di chain srcnat/postrouting (RouterOS cuma
                                                // izinkan itu di prerouting/forward), jadi cukup dipersempit lewat
                                                // dst-address+dst-port saja - sudah cukup spesifik ke satu ONT ini.
                                                mikrotik_debug_comm($API_remote, '/ip/firewall/nat/add', [
                                                    'chain'=>'srcnat',
                                                    'dst-address'=>$remote_pppoe_ip,
                                                    'protocol'=>'tcp',
                                                    'dst-port'=>$web_port_choice,
                                                    'action'=>'masquerade',
                                                    'comment'=>$nat_comment
                                                ], 'Masquerade balik ke tunnel (remote)');

                                             mikrotik_schedule_once($API_remote, "expire-$sstp_name",
                                                '/interface sstp-client remove [find name="' . $sstp_name . '"]; /ip firewall nat remove [find comment="' . $nat_comment . '"]; /ip firewall filter remove [find comment="' . $nat_comment . '"]; /system scheduler remove [find name="expire-' . $sstp_name . '"];',
                                                $expire_minutes,
                                                "RemoteONT:$comment - auto-hapus SSTP client, NAT & filter remote $expire_minutes menit"
                                            );

                                            $history_file = "../notifbot/data/history-$ceknama.json";
                                            $history = [];
                                            if (file_exists($history_file)) {
                                                $history = json_decode(file_get_contents($history_file), true);
                                            }
                                            if (!is_array($history)) $history = [];
                                            $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Berhasil membuat remote mapping untuk '$customer'";
                                            file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

                                              
                                                $API_remote->disconnect();

                                            echo '
                                                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
                                    </head>
                                    <div class="container mt-4">
                                    <div class="row justify-content-center">
                                        <div class="col-md-8">
                                        <div class="card shadow-lg border-0">
                                            <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0">VPN Remote Mapping Berhasil</h5>
                                            </div>
                                            <div class="card-body">
                                            <p><strong>Customer:</strong> ' . htmlspecialchars($customer) . '</p>
                                            <p class="text-danger"><i class="bi bi-clock-history"></i> Remote ini hanya berlaku <strong>' . $expire_minutes . ' menit</strong> kemudian akan otomatis terhapus.</p>
                                            <div class="alert alert-info">
                                                <strong>Public Access:</strong><br>
                                                <a href="http://' . $SERVER_MAIN_IP . ':' . $public_port . '" target="_blank">
                                                http://' . $SERVER_MAIN_IP . ':' . $public_port . '
                                                </a>
                                            </div>
                                            </div>
                                            <div class="card-footer text-muted">
                                            Dibuat pada: ' . date("d-m-Y H:i:s") . '
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                    </div>
                                    ';
                                    exit;

                                            } else {
                                                echo "<span class='badge bg-danger'>DEBUG</span> Koneksi Mikrotik remote: <b>GAGAL</b><br>";
                                                echo '<div style="color:red">Gagal konek ke Mikrotik Tujuan.</div>';
                                            }
    }
    echo "</div>";
                                        
    }
    echo "</div>";
}
   

?>