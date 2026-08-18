<?php
require('../koneksidb.php');
$idserver = $_GET['idserver'];
$kodeodp = $_GET['kodeodp'];
$idpel = $_GET['idpel'];




$sql = 'SELECT * FROM server WHERE id=
' . $idserver . ' ';
$query = mysqli_query($conn, $sql);
while ($data = mysqli_fetch_array($query)) {
    $server = $data['AREA'];
    $user = $data['PEMILIK'];
    $ip = $data['IP'];
    $password = $data['PASSWORD'];
    $map = $data['MAP'];
    $waapi = $data['BOTWA'];
    $olt = $data['OLT'];
}
require('../routeros_api.class.php');
$API = new RouterosAPI();
if ($API->connect($ip, $user, $password)) {
    $data =  $API->write('/ppp/active/print');
    $READ = $API->read(false);
    $ARRAY = $API->parseResponse($READ);
    $encode = json_encode($ARRAY);
}
$API2 = new RouterosAPI();
if ($API2->connect($ip, $user, $password)) {
    $data2 =  $API2->write('/ppp/secret/print');
    $READ2 = $API2->read(false);
    $ARRAY2 = $API2->parseResponse($READ2);
    $encode2 = json_encode($ARRAY2);
}
$ARRAY = (isset($ARRAY) && is_array($ARRAY)) ? $ARRAY : [];
$ARRAY2 = (isset($ARRAY2) && is_array($ARRAY2)) ? $ARRAY2 : [];

$data1 = count($ARRAY);
$data2 = count($ARRAY2);

// Inject small inline styles — hardcoded colors, NOT affected by portal theme
echo "<style>\n";
echo ".btn-status-online-working,.btn-status-expired,.btn-status-online-dhcp,.btn-status-offline{background:none!important;border:none!important;font-weight:700!important;font-size:15px!important;padding:0!important;letter-spacing:0.3px;text-shadow:none!important;box-shadow:none!important;}\n";
echo ".btn-status-online-working{color:#16a34a!important;}\n";
echo ".btn-status-expired{color:#b45309!important;}\n";
echo ".btn-status-online-dhcp{color:#16a34a!important;}\n";
echo ".btn-status-offline{color:#dc2626!important;}\n";
echo ".status-detail-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:4px 12px;margin-top:8px;width:100%;}\n";
echo ".status-detail-item{display:flex;justify-content:space-between;align-items:center;font-size:12px;line-height:1.4;gap:8px;min-width:0;}\n";
echo ".status-detail-item span{color:#888;font-weight:400;white-space:nowrap;flex-shrink:0;}\n";
echo ".status-detail-item b{color:inherit;font-weight:600;text-align:right;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1;}\n";
echo "</style>\n";

// Debug mode: ?debug=1 or ?debug=all
$debugMode = isset($_GET['debug']) && ($_GET['debug'] === '1' || $_GET['debug'] === 'all');
$debugLogs = [];
function D($msg)
{
    global $debugMode, $debugLogs;
    if ($debugMode) {
        if (is_array($msg) || is_object($msg)) $msg = json_encode($msg);
        $debugLogs[] = '[' . date('H:i:s') . '] ' . $msg;
    }
}

D("ARRAY count={$data1}");
D("ARRAY2 count={$data2}");
D('ARRAY sample: ' . json_encode(array_slice($ARRAY, 0, 10)));
D('ARRAY2 sample: ' . json_encode(array_slice($ARRAY2, 0, 10)));

// Debug helper: call fetch_data2.php?debug=1&idpel=IDPEL to see RouterOS arrays and matched element
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    header('Content-Type: application/json');
    $dump = ['ARRAY_count' => $data1, 'ARRAY2_count' => $data2, 'ARRAY_sample' => array_slice($ARRAY,0,10), 'ARRAY2_sample' => array_slice($ARRAY2,0,20)];
    if (!empty($_GET['idpel'])) {
        $dbgIdpel = $_GET['idpel'];
        $idx = array_search($dbgIdpel, array_column($ARRAY2, 'name'));
        $dump['idpel'] = $dbgIdpel;
        $dump['match_index'] = ($idx === false) ? null : $idx;
        $dump['match_element'] = ($idx !== false && isset($ARRAY2[$idx])) ? $ARRAY2[$idx] : null;
    }
    echo json_encode($dump, JSON_PRETTY_PRINT);
    exit;
}

// Helper: robust index lookup by name (normalize whitespace and case)
function find_index_by_name(array $arr, string $needle)
{
    $needleNorm = strtolower(trim($needle));
    // first pass: exact match after normalization
    foreach ($arr as $k => $v) {
        $candidate = isset($v['name']) ? strtolower(trim($v['name'])) : '';
        if ($candidate === $needleNorm) return $k;
    }
    // second pass: substring match (needle inside candidate or vice versa)
    foreach ($arr as $k => $v) {
        $candidate = isset($v['name']) ? strtolower(trim($v['name'])) : '';
        if ($candidate !== '' && (strpos($candidate, $needleNorm) !== false || strpos($needleNorm, $candidate) !== false)) return $k;
    }
    return false;
}

// Helper: format byte count into a readable size (used for kuota/quota display)
function format_bytes_readable($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes <= 0) return '';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($bytes, 1, '.', ''), '0'), '.') . ' ' . $units[$i];
}

// Helper: kuota text from RouterOS limit-bytes-in/limit-bytes-out
function format_kuota_text($limitIn, $limitOut)
{
    $inTxt = format_bytes_readable($limitIn);
    $outTxt = format_bytes_readable($limitOut);
    if ($inTxt === '' && $outTxt === '') return 'Unlimited';
    $parts = [];
    if ($inTxt !== '') $parts[] = 'In ' . $inTxt;
    if ($outTxt !== '') $parts[] = 'Out ' . $outTxt;
    return implode(' / ', $parts);
}

// Helper: parse RouterOS uptime string (e.g. "4w6d15:37:28", "1d2h3m4s") into seconds
function ros_uptime_to_seconds($uptime)
{
    $uptime = trim((string) $uptime);
    if ($uptime === '') return null;
    $seconds = 0;
    if (preg_match('/(\d+)w/', $uptime, $m)) $seconds += intval($m[1]) * 604800;
    if (preg_match('/(\d+)d/', $uptime, $m)) $seconds += intval($m[1]) * 86400;
    if (preg_match('/(\d+):(\d+):(\d+)/', $uptime, $m)) {
        $seconds += intval($m[1]) * 3600 + intval($m[2]) * 60 + intval($m[3]);
    } else {
        if (preg_match('/(\d+)h/', $uptime, $m)) $seconds += intval($m[1]) * 3600;
        if (preg_match('/(\d+)m(?!s)/', $uptime, $m)) $seconds += intval($m[1]) * 60;
        if (preg_match('/(\d+)s/', $uptime, $m)) $seconds += intval($m[1]);
    }
    return $seconds;
}

// Helper: uptime dalam format jam yang mudah dibaca, mis. "20:10:16" atau "1d 20:10:16"
function format_uptime_readable($uptime)
{
    $seconds = ros_uptime_to_seconds($uptime);
    if ($seconds === null) return '-';

    $weeks = intdiv($seconds, 604800);
    $seconds %= 604800;
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $clock = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    if ($weeks > 0) return $weeks . 'w ' . $days . 'd ' . $clock;
    if ($days > 0) return $days . 'd ' . $clock;
    return $clock;
}

// Helper: format tanggal ke format Indonesia "1 Juli 2026" (+ jam jika ada), menerima
// timestamp Unix RouterOS (mis. "jul/19/2026 05:55:41") maupun format tanggal umum lainnya
function format_tanggal_indo($value, $withTime = true)
{
    $value = trim((string) $value);
    if ($value === '' || $value === '-' || strtoupper($value) === 'N/A') return $value;

    $bulanIndo = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $bulanEngMap = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7,
        'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12
    ];

    $timestamp = null;

    // Format RouterOS: mon/dd/yyyy hh:mm:ss (mis. jul/19/2026 05:55:41)
    if (preg_match('/^([a-zA-Z]{3,4})\/(\d{1,2})\/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m)) {
        $monKey = strtolower($m[1]);
        if (isset($bulanEngMap[$monKey])) {
            $timestamp = mktime(
                isset($m[4]) ? (int) $m[4] : 0,
                isset($m[5]) ? (int) $m[5] : 0,
                isset($m[6]) ? (int) $m[6] : 0,
                $bulanEngMap[$monKey],
                (int) $m[2],
                (int) $m[3]
            );
        }
    }

    if ($timestamp === null) {
        $ts = strtotime($value);
        if ($ts !== false) $timestamp = $ts;
    }

    if ($timestamp === null) return $value;

    $result = (int) date('j', $timestamp) . ' ' . $bulanIndo[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    if ($withTime && date('H:i:s', $timestamp) !== '00:00:00') {
        $result .= ' ' . date('H:i', $timestamp);
    }

    return $result;
}

// Helper: derive "last link up" (session start time) from the current uptime duration
function format_last_link_up($uptime)
{
    $seconds = ros_uptime_to_seconds($uptime);
    if ($seconds === null) return '-';
    return format_tanggal_indo(date('Y-m-d H:i:s', time() - $seconds));
}

// Helper: format a RouterOS timestamp (e.g. last-logged-out) for display
function format_ros_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '' || $value === 'jan/01/1970 00:00:00') return '-';
    return format_tanggal_indo($value);
}

// Helper: render the small status detail grid (kuota, uptime, last link up/down)
function render_status_detail_grid($kuota, $uptime, $lastLinkUp, $lastLinkDown)
{
    ?>
    <div class="status-detail-grid">
        <div class="status-detail-item"><span>Kuota</span><b><?php echo htmlspecialchars($kuota); ?></b></div>
        <div class="status-detail-item"><span>Uptime</span><b><?php echo htmlspecialchars($uptime); ?></b></div>
        <div class="status-detail-item"><span>Link Up Terakhir</span><b><?php echo htmlspecialchars($lastLinkUp); ?></b></div>
        <div class="status-detail-item"><span>Link Down Terakhir</span><b><?php echo htmlspecialchars($lastLinkDown); ?></b></div>
    </div>
    <?php
}
$awaldhcp = 0;

if (empty($ARRAY2)) {
    // echo "<tr>";
    // echo "<td>LOAD DATA</td>";
    // echo "</tr>";
}


for ($i = 0; $i < count($ARRAY2); $i++) {

    /////////////////////////////////////cek mode DHCP ///////////////////////////
    $ceknama = $ARRAY2[$i]['name'] ?? '';
    $sql3 = "SELECT * FROM `pelanggan` WHERE `IDPEL` = '" . mysqli_real_escape_string($conn, $ceknama) . "'";
    $query3 = mysqli_query($conn, $sql3);
    while ($data3 = mysqli_fetch_array($query3)) {
        $cekmode =  $data3['MODE'];
        if ($cekmode == "DHCP") {
            $dhcp = 1;
            $awaldhcp = $awaldhcp + $dhcp;
        }
    }

}


$offl = $data2 - $data1 - $awaldhcp;


$nomor = 0;
$sql1 = "SELECT * from `pelanggan` WHERE `PEMILIK` = '$user' and `AREA`='$server' and `ODP`='$kodeodp' and `IDPEL`='$idpel' ";
$query1 = mysqli_query($conn, $sql1);
////////////seting penomoran urut table /////////       
while ($data1 = mysqli_fetch_array($query1)) {

    $nomor++;
    ////////////////////////cek online warna kolom ////////////////////
    if ($data1['MODE'] != "DHCP") {
        $interface = "<pppoe-" . $data1['IDPEL'] . ">";
        $getinterfacetraffic = $API->comm("/interface/monitor-traffic", array(
            "interface" => "$interface",
            "once" => "",
        ));
        if ($getinterfacetraffic[0]['tx-bits-per-second'] != "") {
            // online working (no-op here)
        } else {
            // offline / no traffic (no-op here)
        }

        // lookup in ARRAY for IP/address
        $cek2 = find_index_by_name($ARRAY, $data1['IDPEL']);
    } else {
        if ($data1['MODE'] == "DHCP") {
            // DHCP mode handling (no-op here)
        } elseif ($data1['MODE'] == "PPPOE") {
            $cek = find_index_by_name($ARRAY2, $data1['IDPEL']);
            if ($cek !== false && isset($ARRAY2[$cek])) {
                // profile from RouterOS represents the active package
                $cekexpired = trim($ARRAY2[$cek]['profile'] ?? '');
                $remote = $ARRAY2[$cek]['remote_address'] ?? '';
                // Inline debug output (visible only when ?debug=1)
                if (isset($_GET['debug']) && $_GET['debug'] == '1') {
                    echo "<!-- DEBUG: ARRAY2 matched index={$cek} -->\n";
                    echo "<!-- DEBUG ELEMENT: " . htmlspecialchars(json_encode($ARRAY2[$cek])) . " -->\n";
                    echo "<!-- DEBUG PROFILE: " . htmlspecialchars($cekexpired) . " -->\n";
                }
                error_log("fetch_data2: matched ARRAY2 index={$cek} for IDPEL={$data1['IDPEL']}, profile='" . $cekexpired . "', element=" . json_encode($ARRAY2[$cek]));
            } else {
                // not found: set safe defaults
                $cekexpired = '';
                $remote = '';
            }
            $tambah = 1;
            if (strcasecmp($cekexpired, "EXPIRED") === 0) {
                // expired handling (no-op)
            }
        }
    }
    //////////////////////////////////////////////////////////////////
    $idselect = $data1['id'];

    //////////////////////////////////////////////////////////////////
    $cek = find_index_by_name($ARRAY2, $data1['IDPEL']);
    if ($cek !== false && isset($ARRAY2[$cek])) {
        $cekexpired = trim($ARRAY2[$cek]['profile'] ?? '');
        $remote = $ARRAY2[$cek]['last-caller-id'] ?? '';
        error_log("fetch_data2: matched ARRAY2 index={$cek} for IDPEL={$data1['IDPEL']} (second check), profile='" . $cekexpired . "', element=" . json_encode($ARRAY2[$cek]));
    } else {
        $cekexpired = '';
        $remote = '';
    }
    $tambah = 1;
    if (strcasecmp($cekexpired, "EXPIRED") !== 0 && is_numeric($cek)) {
        $cek = $cek + $tambah;
    }

    ////////////////////////cek data mikrotik dan database////////////////////
    $cek2 = find_index_by_name($ARRAY, $data1['IDPEL']);
    $ipaddress = (isset($ARRAY[$cek2]) && isset($ARRAY[$cek2]['address'])) ? $ARRAY[$cek2]['address'] : '';

    // Data tambahan untuk kolom status: kuota, uptime, last link up/down
    $secretIdx = find_index_by_name($ARRAY2, $data1['IDPEL']);
    $activeUptime = isset($ARRAY[$cek2]['uptime']) ? $ARRAY[$cek2]['uptime'] : '';
    $secretLimitIn = ($secretIdx !== false) ? ($ARRAY2[$secretIdx]['limit-bytes-in'] ?? '') : '';
    $secretLimitOut = ($secretIdx !== false) ? ($ARRAY2[$secretIdx]['limit-bytes-out'] ?? '') : '';
    $secretLastLoggedOut = ($secretIdx !== false) ? ($ARRAY2[$secretIdx]['last-logged-out'] ?? '') : '';

    $kuotaText = format_kuota_text($secretLimitIn, $secretLimitOut);
    $uptimeText = ($activeUptime !== '') ? format_uptime_readable($activeUptime) : '-';
    $lastLinkUpText = ($activeUptime !== '') ? format_last_link_up($activeUptime) : '-';
    $lastLinkDownText = format_ros_datetime($secretLastLoggedOut);

    // echo "<td>" . $data1['IDPEL'] . "<br>(" . $data1['NAMA'] . "</b>)</td>";
    // echo "<td>" . $cekexpired . "<br>";


    ////////////////////////cek online////////////////////
    $interface = "<pppoe-" . $data1['IDPEL'] . ">";
    $getinterfacetraffic = $API->comm("/interface/monitor-traffic", array(
        "interface" => "$interface",
        "once" => "",
    ));
    if ($getinterfacetraffic[0]['tx-bits-per-second'] != "") {
        if ($cekexpired !== "EXPIRED") {
?>
            <button type="button" class="btn btn-status-online-working" disabled>ONLINE WORKING</button><br>
        <?php
            render_status_detail_grid($kuotaText, $uptimeText, $lastLinkUpText, $lastLinkDownText);
            // echo 'AKTIF : ' . htmlspecialchars($cekexpired ?? '') . '<br>';
            // echo 'PAKET : ' . htmlspecialchars($data1['PAKET'] ?? '');
        } else {
        ?>
            <button type="button" class="btn btn-status-expired" disabled>EXPIRED</button><br>
        <?php
            render_status_detail_grid($kuotaText, $uptimeText, $lastLinkUpText, $lastLinkDownText);
            // echo  'AKTIF : ' . $cekexpired . '';
        }
    } else {
        if ($data1['MODE'] == "DHCP") {
        ?>
            <button type="button" class="btn btn-status-online-dhcp" disabled>ONLINE DHCP</button><br>
        <?php
            render_status_detail_grid($kuotaText, $uptimeText, $lastLinkUpText, $lastLinkDownText);
            // echo 'AKTIF : ' . htmlspecialchars($cekexpired ?? '') . '<br>';
            // echo 'PAKET : ' . htmlspecialchars($data1['PAKET'] ?? '');
        } else {
            $cek1 = find_index_by_name($ARRAY2, $data1['IDPEL']);
            if ($cek1 !== false && isset($ARRAY2[$cek1])) {
                $ceklastloggedout = $ARRAY2[$cek1]['last-logged-out'] ?? '';
                $ceklastdisconnect = $ARRAY2[$cek1]['last-disconnect-reason'] ?? '';
            } else {
                // Debug: log missing match to help diagnose why profile isn't found
                error_log("fetch_data2: no ARRAY2 entry for IDPEL={$data1['IDPEL']} (cek1={$cek1})");
                $ceklastloggedout = '';
                $ceklastdisconnect = '';
            }

        ?>
            <button type="button" class="btn btn-status-offline btn-lg btn-block" disabled>OFFLINE / LOS</button><br>
<?php
            render_status_detail_grid($kuotaText, $uptimeText, $lastLinkUpText, $lastLinkDownText);
            // echo '<button  class="btn btn-primary">AKTIF :' . $cekexpired . '</button><br>';
            // echo "Terakhir online : <b>$ceklastloggedout<br> $ceklastdisconnect</b>";
        }
    }
    // echo "</tr>";
}


$conn->close();

// If debug mode, print the accumulated debug logs for full trace
if (!empty($debugMode) && !empty($debugLogs)) {
    echo "<pre style=\"background:#111;color:#fff;padding:10px;white-space:pre-wrap;\">\n";
    foreach ($debugLogs as $line) {
        echo htmlspecialchars($line) . "\n";
    }
    echo "</pre>\n";
}

?>