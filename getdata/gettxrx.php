<?php
include '../cek-sesi.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);
include __DIR__ . '/../koneksibilling.php';

function echo_line($msg)
{
    echo nl2br(htmlspecialchars($msg)) . "<br>";
    flush();
}

function safe_name($value)
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $value);
    return trim($value, '_');
}

function save_debug($filename, $content)
{
    $folder = __DIR__ . '/debug';
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }

    $path = $folder . '/' . $filename;
    if (file_exists($path)) {
        unlink($path);
        echo_line("File lama dihapus: {$filename}");
    }
    file_put_contents($path, $content);
    echo_line("File baru dibuat: {$filename}");
}

function curl_request($url, $options = [])
{
    echo_line("Mengakses URL: {$url}");
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    if (!empty($options['basic_auth']) && !empty($options['basic_auth_user']) && isset($options['basic_auth_pass'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $options['basic_auth_user'] . ":" . $options['basic_auth_pass']);
    }

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    if ($err) {
        echo_line("CURL ERROR: " . $err);
    }
    curl_close($ch);
    return (string) $resp;
}

function normalize_brand($brand)
{
    return strtoupper(trim((string) $brand));
}

function is_hioso_web_brand($brand)
{
    $b = normalize_brand($brand);
    return strpos($b, 'HIOSO EPON') === 0;
}

function load_acs_cache_map()
{
    $path = __DIR__ . '/../notifdata/acs_devices_cache.json';
    if (!file_exists($path)) {
        return [];
    }

    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json) || empty($json['devices']) || !is_array($json['devices'])) {
        return [];
    }

    $map = [];
    foreach ($json['devices'] as $device) {
        if (!is_array($device)) {
            continue;
        }

        $userA = trim((string) ($device['pppoe_username'] ?? ''));
        $userB = trim((string) ($device['pppoe_username2'] ?? ''));
        $rx = trim((string) ($device['rx_power'] ?? ''));
        $tx = trim((string) ($device['tx_power'] ?? ''));

        if ($userA !== '') {
            $map[strtoupper($userA)] = ['rx' => $rx, 'tx' => $tx];
        }
        if ($userB !== '') {
            $map[strtoupper($userB)] = ['rx' => $rx, 'tx' => $tx];
        }
    }
    return $map;
}

function get_customer_list_by_scope($conn, $pemilik, $area)
{
    $pemilikEsc = $conn->real_escape_string((string) $pemilik);
    $areaEsc = $conn->real_escape_string((string) $area);
    $sql = "SELECT IDPEL, PASSWORD FROM pelanggan WHERE PEMILIK = '{$pemilikEsc}' AND AREA = '{$areaEsc}' ORDER BY IDPEL";
    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'idpel' => trim((string) ($r['IDPEL'] ?? '')),
            'pass' => trim((string) ($r['PASSWORD'] ?? '')),
        ];
    }
    return $rows;
}

function parse_host_port($hostPort)
{
    $hostPort = trim((string) $hostPort);
    $parts = explode(':', $hostPort);
    $host = trim((string) ($parts[0] ?? ''));
    $port = isset($parts[1]) ? (int) $parts[1] : 23;
    if ($port <= 0) {
        $port = 23;
    }
    return [$host, $port];
}

function parse_dbm_value($value)
{
    $value = trim((string) $value);
    if ($value === '' || stripos($value, 'no signal') !== false || strtoupper($value) === 'LOS') {
        return '0';
    }
    if (preg_match('/-?\d+(?:\.\d+)?/', $value, $m)) {
        return $m[0];
    }
    return '0';
}

function normalize_onulist_mac($mac)
{
    $mac = trim((string) $mac);
    if ($mac === '') return 'NA';

    if (preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $mac)) {
        return strtolower(str_replace('-', ':', $mac));
    }

    if (preg_match('/^[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}$/', $mac)) {
        $hex = strtolower(str_replace('.', '', $mac));
        return implode(':', str_split($hex, 2));
    }

    return strtolower($mac);
}

function normalize_onu_state($state)
{
    $s = strtolower(trim((string) $state));
    if ($s === '') return 'NA';
    if (strpos($s, 'work') !== false || strpos($s, 'online') !== false || $s === 'up') return 'Up';
    if (strpos($s, 'off') !== false || strpos($s, 'los') !== false || strpos($s, 'dying') !== false || $s === 'down') return 'Down';
    return ucfirst($state);
}

function build_onulist_line($pon, $nama, $mac, $state, $rx, $tx, array $middle = [])
{
    $pon = trim((string) $pon) !== '' ? trim((string) $pon) : 'NA';
    $nama = trim((string) $nama) !== '' ? trim((string) $nama) : 'NA';
    $macNorm = normalize_onulist_mac($mac);
    $stateNorm = normalize_onu_state($state);
    $rxVal = parse_dbm_value($rx);
    $txVal = parse_dbm_value($tx);

    $defaultMid = ['NA', 'NA', 'NA', 'NA', 'NA', 'NA', 'NA'];
    for ($i = 0; $i < 7; $i++) {
        if (isset($middle[$i]) && trim((string) $middle[$i]) !== '') {
            $defaultMid[$i] = (string) $middle[$i];
        }
    }

    $data = array_merge([$pon, $nama, $macNorm, $stateNorm], $defaultMid, [$rxVal, $txVal]);
    return "PON: {$pon} | Nama: {$nama} | MAC: {$macNorm} | Data: " . implode(',', $data) . "\n";
}

function telnet_read($sock, array $patterns, $timeoutSec = 6)
{
    $data = '';
    $start = time();

    while (!feof($sock)) {
        if ((time() - $start) >= $timeoutSec) {
            break;
        }

        $read = [$sock];
        $write = null;
        $except = null;
        $avail = @stream_select($read, $write, $except, 0, 100000);

        if ($avail === false || $avail === 0) {
            continue;
        }

        $char = @fread($sock, 1);
        if ($char === false || $char === '') {
            continue;
        }

        $byte = ord($char);
        if ($byte === 0xFF) {
            $cmdByte = @fread($sock, 1);
            if ($cmdByte === false) {
                continue;
            }
            $cmd = ord($cmdByte);
            if ($cmd >= 0xFB && $cmd <= 0xFE) {
                $optByte = @fread($sock, 1);
                if ($optByte === false) {
                    continue;
                }
                $opt = ord($optByte);
                if ($cmd === 0xFB) {
                    fwrite($sock, chr(0xFF) . chr(0xFE) . chr($opt));
                } elseif ($cmd === 0xFD) {
                    fwrite($sock, chr(0xFF) . chr(0xFC) . chr($opt));
                }
            }
            continue;
        }

        if ($byte < 0x20 && $byte !== 0x0A && $byte !== 0x0D && $byte !== 0x09) {
            continue;
        }

        $data .= $char;
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && strpos($data, $pattern) !== false) {
                return ['found' => true, 'data' => $data];
            }
        }
    }

    return ['found' => false, 'data' => $data];
}

function telnet_run_commands($host, $port, $username, $password, array $commands)
{
    $sock = @fsockopen($host, $port, $errno, $errstr, 8);
    if (!$sock) {
        return ['ok' => false, 'output' => "Gagal konek {$host}:{$port} - {$errstr} ({$errno})"];
    }
    stream_set_timeout($sock, 8);

    $output = '';
    $r = telnet_read($sock, ['login:', 'Login:', 'Username:', 'username:', 'User:'], 8);
    $output .= $r['data'];
    if (!$r['found']) {
        fclose($sock);
        return ['ok' => false, 'output' => $output . "\nLogin prompt tidak ditemukan"]; 
    }

    fwrite($sock, $username . "\r\n");
    usleep(80000);

    $r = telnet_read($sock, ['Password:', 'password:'], 6);
    $output .= $r['data'];
    if (!$r['found']) {
        fclose($sock);
        return ['ok' => false, 'output' => $output . "\nPassword prompt tidak ditemukan"]; 
    }

    fwrite($sock, $password . "\r\n");
    usleep(150000);

    $prompt = telnet_read($sock, ['#', '>', '$', ']'], 8);
    $output .= $prompt['data'];

    foreach ($commands as $cmd) {
        $cmd = trim((string) $cmd);
        if ($cmd === '') {
            continue;
        }
        fwrite($sock, $cmd . "\r\n");
        usleep(80000);
        $r = telnet_read($sock, ['#', '>', '$', ']'], 6);
        $output .= "\n===== CMD: {$cmd} =====\n";
        $output .= $r['data'];
    }

    fwrite($sock, "exit\r\n");
    fclose($sock);

    return ['ok' => true, 'output' => $output];
}

function parse_pppoe_rx_tx_from_telnet($raw, $pppoeUser, $pppoePass = '')
{
    $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
    $userNeedle = strtoupper(trim((string) $pppoeUser));
    $passNeedle = strtoupper(trim((string) $pppoePass));

    $best = ['rx' => '0', 'tx' => '0', 'signal' => ''];
    $window = [];

    foreach ($lines as $line) {
        $lineTrim = trim((string) $line);
        if ($lineTrim === '') {
            continue;
        }

        $window[] = $lineTrim;
        if (count($window) > 8) {
            array_shift($window);
        }

        $lineUp = strtoupper($lineTrim);
        $hasUser = $userNeedle !== '' && strpos($lineUp, $userNeedle) !== false;
        $hasPass = $passNeedle !== '' && strpos($lineUp, $passNeedle) !== false;

        if (!$hasUser && !$hasPass) {
            continue;
        }

        $chunk = implode("\n", $window);
        $rx = '0';
        $tx = '0';

        if (preg_match('/(?:up\s+rx|rx\s*[:=])\s*([-]?\d+(?:\.\d+)?)/i', $chunk, $mRx)) {
            $rx = parse_dbm_value($mRx[1]);
        }
        if (preg_match('/(?:down\s+rx|tx\s*[:=])\s*([-]?\d+(?:\.\d+)?)/i', $chunk, $mTx)) {
            $tx = parse_dbm_value($mTx[1]);
        }

        if ($rx !== '0' || $tx !== '0') {
            $best = ['rx' => $rx, 'tx' => $tx, 'signal' => $chunk];
            break;
        }
    }

    return $best;
}

function run_hioso_web_collector($olt, $ponFile, $onuFile)
{
    $ip = $olt['ipolt'];
    $username = $olt['usernameolt'];
    $password = $olt['passwordolt'];
    $id = $olt['id'];
    $area = $olt['area'];
    $pemilik = $olt['pemilik'];

    $ponRaw = curl_request("http://{$ip}/onuConfigPonList.asp", [
        'basic_auth' => true,
        'basic_auth_user' => $username,
        'basic_auth_pass' => $password,
    ]);
    save_debug($ponFile, $ponRaw);

    $ponList = [];
    if (preg_match('/var\s+ponListTable\s*=\s*new\s+Array\s*\((.*?)\);/is', $ponRaw, $m)) {
        if (preg_match_all("/'([^']*)'/", $m[1], $matches)) {
            $arr = $matches[1];
            $total = (int) (count($arr) / 2);
            for ($i = 0; $i < $total; $i++) {
                $ponList[$arr[$i * 2]] = $arr[$i * 2 + 1];
            }
            echo_line("Jumlah PON: " . count($ponList));
        }
    }

    $allOnuRows = [];
    foreach ($ponList as $ponNo => $ponName) {
        echo_line("Mengambil ONU PON: {$ponNo} ({$ponName})");
        $onuRaw = curl_request("http://{$ip}/onuConfigOnuList.asp?oltponno={$ponNo}", [
            'basic_auth' => true,
            'basic_auth_user' => $username,
            'basic_auth_pass' => $password,
        ]);
        save_debug("onulist_{$id}__" . safe_name($area) . "_" . safe_name($pemilik) . "_{$ponNo}.txt", $onuRaw);

        if (preg_match('/var\s+ponOnuTable\s*=\s*new\s+Array\s*\((.*?)\);/is', $onuRaw, $m2)) {
            $parts = [];
            if (preg_match_all("/'([^']*)'/", $m2[1], $matches2)) {
                $parts = $matches2[1];
            }
            $cols = 13;
            for ($i = 0; $i < count($parts); $i += $cols) {
                $allOnuRows[] = array_slice($parts, $i, $cols);
            }
        }
    }

    $content = '';
    foreach ($allOnuRows as $row) {
        $mac = '';
        foreach ($row as $col) {
            if (preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/i', (string) $col)) {
                $mac = $col;
                break;
            }
        }
        $pon = trim((string) ($row[0] ?? 'NA'));
        $nama = trim((string) ($row[1] ?? 'NA'));
        $state = trim((string) ($row[3] ?? 'NA'));
        $rx = parse_dbm_value($row[count($row) - 2] ?? '0');
        $tx = parse_dbm_value($row[count($row) - 1] ?? '0');
        $content .= build_onulist_line($pon, $nama, $mac, $state, $rx, $tx);
    }
    save_debug($onuFile, $content);
    echo_line("ONT list disimpan di {$onuFile} | Total ONU: " . count($allOnuRows));
}

// ─────────────────────────────────────────────────────────────────────────────
// ZTE Collector – MAC-based matching
// Langkah: (1) get ONU ID list → (2) get power + MAC per ONU → (3) tulis file
// ─────────────────────────────────────────────────────────────────────────────

function parse_zte_onu_state($raw)
{
    $ids = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
        if (preg_match('/\b(\d+\/\d+\/\d+:\d+)\b/', $line, $m)) {
            $ids[] = $m[1];
        }
    }
    return array_values(array_unique($ids));
}

function parse_zte_onu_state_map($raw)
{
    $map = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $raw) as $line) {
        if (preg_match('/^\s*(\d+\/\d+\/\d+:\d+)\s+\S+\s+\S+\s+(\S+)/i', $line, $m)) {
            $map[strtolower($m[1])] = $m[2];
        }
    }
    return $map;
}

function parse_zte_power_section($section)
{
    $rx = '0';
    $tx = '0';
    // Format klasik: "OLT RX Power ..." dan "ONU TX Power ..."
    if (preg_match('/OLT\s+RX\s+Power[^:]*:\s*([-\d.]+)/i', $section, $m)) {
        $rx = parse_dbm_value($m[1]);
    }
    if (preg_match('/ONU\s+TX\s+Power[^:]*:\s*([-\d.]+)/i', $section, $m)) {
        $tx = parse_dbm_value($m[1]);
    }
    // Format baru (sesuai tampilan di index.php):
    // up   Rx :no signal   Tx:N/A
    // down Tx :6.449(dbm)  Rx:N/A
    if ($rx === '0' || $tx === '0') {
        $upLine = '';
        $dnLine = '';
        foreach (preg_split('/\r\n|\r|\n/', (string) $section) as $line) {
            if ($upLine === '' && preg_match('/^\s*up\b/i', $line)) {
                $upLine = $line;
            }
            if ($dnLine === '' && preg_match('/^\s*down\b/i', $line)) {
                $dnLine = $line;
            }
        }

        if ($rx === '0' && preg_match('/Rx\s*:\s*([-]?\d+(?:\.\d+)?)/i', $upLine, $m)) {
            $rx = parse_dbm_value($m[1]);
        }
        if ($tx === '0' && preg_match('/Tx\s*:\s*([-]?\d+(?:\.\d+)?)/i', $dnLine, $m)) {
            $tx = parse_dbm_value($m[1]);
        }
    }
    return ['rx' => $rx, 'tx' => $tx];
}

function parse_zte_mac_from_section($section)
{
    // ZTE dot-notation: e86e.449e.d3d8
    if (preg_match('/\b([0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4})\b/i', $section, $m)) {
        $hex = str_replace('.', '', $m[1]);
        return implode(':', str_split(strtoupper($hex), 2));
    }
    // colon/hyphen notation
    if (preg_match('/\b([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/', $section, $m)) {
        return strtoupper($m[0]);
    }
    return '';
}

function parse_zte_pppoe_from_wan_section($section)
{
    $user = '';
    $pass = '';
    if (preg_match('/wan-ip\s+\d+\s+mode\s+\S+\s+username\s+(\S+)/i', (string) $section, $m)) {
        $user = trim((string) $m[1]);
    }
    if (preg_match('/wan-ip\s+\d+\s+mode\s+\S+(?:\s+username\s+\S+)?\s+password\s+(\S+)/i', (string) $section, $m)) {
        $pass = trim((string) $m[1]);
    }
    return ['user' => $user, 'pass' => $pass];
}

function parse_zte_detail_kv($section)
{
    $kv = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $section) as $line) {
        if (preg_match('/^\s*([^:]+?)\s*:\s*(.*?)\s*$/', $line, $m)) {
            $k = strtolower(trim((string) $m[1]));
            $v = trim((string) $m[2]);
            if ($k !== '' && $v !== '') {
                $kv[$k] = $v;
            }
        }
    }
    return $kv;
}

function run_zte_collector($olt, $ponFile, $onuFile, $acsMap)
{
    $id   = (int) $olt['id'];
    $area = safe_name($olt['area'] ?? 'UNKNOWN');
    $pemilik = safe_name($olt['pemilik'] ?? 'UNKNOWN');
    list($host, $port) = parse_host_port($olt['ipolt']);
    $user = (string) $olt['usernameolt'];
    $pass = (string) $olt['passwordolt'];

    // Fase 1: ambil state semua ONU (1 sesi saja)
    $tel1     = telnet_run_commands($host, $port, $user, $pass, ['terminal length 0', 'show gpon onu state']);
    $rawState = (string) ($tel1['output'] ?? '');
    save_debug($ponFile, "ZTE ONU STATE | HOST: {$host}:{$port}\n\n" . $rawState);

    $onuIds   = parse_zte_onu_state($rawState);
    $stateMap = parse_zte_onu_state_map($rawState);
    echo_line("ZTE ONU ditemukan: " . count($onuIds));

    if (empty($onuIds)) {
        save_debug($onuFile, "// ZTE: tidak ada ONU ID ditemukan dari show gpon onu state\n");
        echo_line("ZTE: tidak ada ONU. Cek koneksi atau output show gpon onu state.");
        return;
    }

    // Pisah working vs offline – offline tidak perlu query
    $onuWorking = [];
    $onuOffline = [];
    foreach ($onuIds as $onuIdx) {
        $s = strtolower($stateMap[strtolower($onuIdx)] ?? '');
        $isUp = strpos($s, 'work') !== false || strpos($s, 'online') !== false || $s === 'up';
        if ($isUp) {
            $onuWorking[] = $onuIdx;
        } else {
            $onuOffline[] = $onuIdx;
        }
    }
    echo_line("ZTE Working: " . count($onuWorking) . " | Offline: " . count($onuOffline));

    // Fase 2: power + MAC hanya ONU working – gabung dalam 1 sesi telnet
    $rawDetail = '';
    if (!empty($onuWorking)) {
        $cmds = ['terminal length 0'];
        foreach ($onuWorking as $onuIdx) {
            $onuCli = 'gpon-onu_' . $onuIdx;
            $cmds[] = "show pon power attenuation {$onuCli}";
            $cmds[] = "show mac gpon onu {$onuCli}";
        }
        $tel2      = telnet_run_commands($host, $port, $user, $pass, $cmds);
        $rawDetail = (string) ($tel2['output'] ?? '');
    }

    $zteDetailFile = "zte_detail_{$id}_{$area}_{$pemilik}.txt";
    $zteFullFile   = "zte_onu_full_{$id}_{$area}_{$pemilik}.txt";
    save_debug($zteDetailFile, "ZTE DETAIL | HOST: {$host}:{$port}\n\n" . $rawDetail);

    // Fase 3: parse output per section
    $sections = preg_split('/={3,}\s*CMD:\s*/i', $rawDetail);
    $powerMap = [];  // onuId_lower => ['rx'=>, 'tx'=>]
    $macMap   = [];  // onuId_lower => MAC
    $pppoeMap = [];  // onuId_lower => ['user'=>, 'pass'=>]
    $typeMap  = [];  // onuId_lower => ONU type/model
    $snMap    = [];  // onuId_lower => serial number

    foreach ($sections as $sec) {
        $sec = ltrim($sec);
        if (preg_match('/^show pon power attenuation\s+gpon-onu_([\d\/]+:\d+)/i', $sec, $m)) {
            $oid = strtolower($m[1]);
            $powerMap[$oid] = parse_zte_power_section($sec);
        } elseif (preg_match('/^show mac gpon onu\s+gpon-onu_([\d\/]+:\d+)/i', $sec, $m)) {
            $oid = strtolower($m[1]);
            $mac = parse_zte_mac_from_section($sec);
            if ($mac !== '') {
                $macMap[$oid] = $mac;
            }
        } elseif (preg_match('/^show onu running config\s+gpon-onu_([\d\/]+:\d+)/i', $sec, $m)) {
            $oid = strtolower($m[1]);
            $pppoeMap[$oid] = parse_zte_pppoe_from_wan_section($sec);
        } elseif (preg_match('/^show gpon remote-onu equip\s+gpon-onu_([\d\/]+:\d+)/i', $sec, $m)) {
            $oid = strtolower($m[1]);
            $kv = parse_zte_detail_kv($sec);
            if (!empty($kv['onu type'])) {
                $typeMap[$oid] = $kv['onu type'];
            } elseif (!empty($kv['type'])) {
                $typeMap[$oid] = $kv['type'];
            }
        } elseif (preg_match('/^show gpon onu detail-info\s+gpon-onu_([\d\/]+:\d+)/i', $sec, $m)) {
            $oid = strtolower($m[1]);
            $kv = parse_zte_detail_kv($sec);
            if (!empty($kv['serial number'])) {
                $snMap[$oid] = $kv['serial number'];
            }
            if (empty($typeMap[$oid])) {
                if (!empty($kv['type'])) {
                    $typeMap[$oid] = $kv['type'];
                } elseif (!empty($kv['onu type'])) {
                    $typeMap[$oid] = $kv['onu type'];
                }
            }
        }
    }

    // Fase 4: tulis file output – working dari data detail, offline langsung NA
    $content = '';

    // ONU offline: tulis langsung tanpa query
    foreach ($onuOffline as $onuIdx) {
        $state = $stateMap[strtolower($onuIdx)] ?? 'Down';
        $content .= build_onulist_line($onuIdx, 'NA', '', $state, '0', '0');
    }

    // ONU working: tulis dari hasil query
    foreach ($onuWorking as $onuIdx) {
        $oid  = strtolower($onuIdx);
        $mac  = $macMap[$oid] ?? '';
        $rx   = $powerMap[$oid]['rx'] ?? '0';
        $tx   = $powerMap[$oid]['tx'] ?? '0';
        $state = $stateMap[$oid] ?? 'Up';
        $content .= build_onulist_line($onuIdx, 'NA', $mac, $state, $rx, $tx);
    }

    save_debug($onuFile, $content);
    save_debug($zteFullFile, "ZTE ONU FULL DETAIL | HOST: {$host}:{$port}\nTOTAL ONU: " . count($onuIds) . "\n\n" . $rawDetail);
    echo_line(
        "ZTE ONU list disimpan | Working: " . count($onuWorking) .
        " | Offline: " . count($onuOffline) .
        " | MAC: " . count($macMap)
    );
    echo_line("ZTE full detail tersimpan: {$zteFullFile}");
}

// ─────────────────────────────────────────────────────────────────────────────
// Huawei Collector – optical-info bulk + MAC
// ─────────────────────────────────────────────────────────────────────────────

function run_huawei_collector($olt, $ponFile, $onuFile, $acsMap)
{
    list($host, $port) = parse_host_port($olt['ipolt']);
    $user = (string) $olt['usernameolt'];
    $pass = (string) $olt['passwordolt'];

    $cmds = [
        'scroll',
        'display ont optical-info 0 all',
        'display ont mac-address 0 all',
    ];

    $tel    = telnet_run_commands($host, $port, $user, $pass, $cmds);
    $rawAll = (string) ($tel['output'] ?? '');
    save_debug($ponFile, "HUAWEI | HOST: {$host}:{$port}\n\n" . $rawAll);

    // Parse optical-info section
    // Format baris: " 0/ 1/0   1     -21.50  ...  2.30  ..."
    $sections2 = preg_split('/={3,}\s*CMD:\s*/i', $rawAll);
    $optMap  = []; // "f/s/p:id" => ['rx'=>, 'tx'=>]
    $macMap2 = []; // "f/s/p:id" => MAC

    foreach ($sections2 as $sec) {
        $sec = ltrim($sec);
        if (preg_match('/^display ont optical-info/i', $sec)) {
            foreach (preg_split('/\r\n|\r|\n/', $sec) as $line) {
                // " 0/ 1/0   1     -21.50   ...   2.30 ..."
                if (preg_match('/^\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+([-\d.]+)\s+\S+\s+([-\d.]+)/i', $line, $m)) {
                    $key = "{$m[1]}/{$m[2]}/{$m[3]}:{$m[4]}";
                    $optMap[$key] = ['rx' => parse_dbm_value($m[5]), 'tx' => parse_dbm_value($m[6])];
                }
            }
        } elseif (preg_match('/^display ont mac-address/i', $sec)) {
            foreach (preg_split('/\r\n|\r|\n/', $sec) as $line) {
                if (preg_match('/^\s*(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+([0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2}[:-][0-9A-Fa-f]{2})/i', $line, $m)) {
                    $key = "{$m[1]}/{$m[2]}/{$m[3]}:{$m[4]}";
                    $macMap2[$key] = strtoupper($m[5]);
                }
            }
        }
    }

    $content  = '';
    $allKeys  = array_unique(array_merge(array_keys($optMap), array_keys($macMap2)));
    foreach ($allKeys as $key) {
        $mac = $macMap2[$key] ?? '';
        $rx  = $optMap[$key]['rx'] ?? '0';
        $tx  = $optMap[$key]['tx'] ?? '0';
        $state = ($rx !== '0' || $tx !== '0') ? 'Up' : 'Down';
        $content .= build_onulist_line($key, 'NA', $mac, $state, $rx, $tx);
    }

    save_debug($onuFile, $content);
    echo_line("HUAWEI ONT list disimpan | Total: " . count($allKeys) . " | MAC: " . count($macMap2));
}

// ─────────────────────────────────────────────────────────────────────────────
// CDATA / VSOL / HSGQ / Generic – parse show onu all → MAC + signal dBm
// ─────────────────────────────────────────────────────────────────────────────

function run_generic_onu_collector($olt, $ponFile, $onuFile, $acsMap, $brand, array $cmds)
{
    list($host, $port) = parse_host_port($olt['ipolt']);
    $user = (string) $olt['usernameolt'];
    $pass = (string) $olt['passwordolt'];

    $tel    = telnet_run_commands($host, $port, $user, $pass, $cmds);
    $rawAll = (string) ($tel['output'] ?? '');
    save_debug($ponFile, "{$brand} | HOST: {$host}:{$port}\n\n" . $rawAll);

    // Split per-ONU block lalu cari MAC + dBm
    $onuBlocks = preg_split('/(?=\bONU\s+\d+\b|\bONU-ID\s*:\s*\d+)/i', $rawAll);
    $content   = '';
    $count     = 0;

    foreach ($onuBlocks as $block) {
        $pon = 'NA';
        $state = 'NA';
        $mac = '';
        $rx  = '0';
        $tx  = '0';

        if (preg_match('/\b(\d+\/\d+\/\d+:\d+)\b/', $block, $mPon)) {
            $pon = $mPon[1];
        }
        if (preg_match('/\b(working|online|offline|los|dyinggasp|up|down)\b/i', $block, $mSt)) {
            $state = $mSt[1];
        }

        // Cari MAC
        if (preg_match('/([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}/', $block, $mMac)) {
            $mac = strtoupper($mMac[0]);
        } elseif (preg_match('/\b([0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4})\b/i', $block, $mMac)) {
            $hex = str_replace('.', '', $mMac[1]);
            $mac = implode(':', str_split(strtoupper($hex), 2));
        }

        if ($mac === '') continue;

        // Cari Rx
        if (preg_match('/(?:rx|receive(?:d)?)\s*(?:power|optical)?\s*[:\(]\s*([-\d.]+)/i', $block, $mRx)) {
            $rx = parse_dbm_value($mRx[1]);
        }
        // Cari Tx
        if (preg_match('/(?:tx|transmit(?:ted)?|sent)\s*(?:power|optical)?\s*[:\(]\s*([-\d.]+)/i', $block, $mTx)) {
            $tx = parse_dbm_value($mTx[1]);
        }
        // Fallback: ambil dua nilai dBm pertama (negatif = rx, positif/lain = tx)
        if ($rx === '0' && $tx === '0') {
            preg_match_all('/([-]?\d+\.\d+)\s*(?:dBm|dbm)?/', $block, $mDbm);
            if (!empty($mDbm[1])) {
                $rx = parse_dbm_value($mDbm[1][0]);
                $tx = isset($mDbm[1][1]) ? parse_dbm_value($mDbm[1][1]) : '0';
            }
        }

        $content .= build_onulist_line($pon, 'NA', $mac, $state, $rx, $tx);
        $count++;
    }

    save_debug($onuFile, $content);
    echo_line("{$brand} ONU list disimpan | Total MAC: {$count}");
}

// ─────────────────────────────────────────────────────────────────────────────
// CDATA GPON via SNMP (dipakai jika OLT sudah punya community_read di tabel olt).
// Telnet "show ont info all" pada model ini TIDAK punya kolom RX/TX sama sekali,
// jadi RX/redaman hanya bisa didapat lewat SNMP (OID enterprise 1.3.6.1.4.1.17409).
// ─────────────────────────────────────────────────────────────────────────────

const CDATA_SNMP_OID_DESC   = '1.3.6.1.4.1.17409.2.8.4.1.1.2';
const CDATA_SNMP_OID_VENDOR = '1.3.6.1.4.1.17409.2.8.4.1.1.5';
const CDATA_SNMP_OID_RX     = '1.3.6.1.4.1.17409.2.3.3.6.1.2';

function cdata_snmp_walk_grouped($host, $community, $baseOid, $timeout = 1200000, $retries = 2)
{
    // Wajib numeric, kalau tidak PHP mengembalikan OID simbolik MIB (mis. "iso.3.6...."
    // bukan "1.3.6...."), yang membuat pencocokan prefix di bawah selalu gagal.
    snmp_set_oid_output_format(SNMP_OID_OUTPUT_NUMERIC);
    snmp_set_valueretrieval(SNMP_VALUE_LIBRARY);

    $walk = @snmprealwalk($host, $community, $baseOid, $timeout, $retries);
    if ($walk === false || !is_array($walk)) {
        return null;
    }

    $prefix = ltrim($baseOid, '.') . '.';
    $out = [];
    foreach ($walk as $oid => $rawValue) {
        $oidClean = ltrim((string) $oid, '.');
        $suffix = (strpos($oidClean, $prefix) === 0) ? substr($oidClean, strlen($prefix)) : $oidClean;
        $cleanValue = preg_replace('/^(STRING|INTEGER|Hex-STRING|OID|Timeticks|Gauge32|Counter32|Counter64|IpAddress|BITS):\s*/', '', trim((string) $rawValue));
        $out[$suffix] = trim($cleanValue, "\" \t\n\r\0\x0B");
    }
    return $out;
}

function run_cdata_snmp_collector($conn, $olt, $ponFile, $onuFile)
{
    $ip = trim((string) ($olt['ipolt'] ?? ''));
    list($host) = parse_host_port($ip);
    $community = trim((string) ($olt['community_read'] ?? ''));
    $port = 161;

    if (!extension_loaded('snmp') || !function_exists('snmprealwalk')) {
        echo_line('SNMP extension tidak aktif, fallback ke Telnet untuk CDATA.');
        $cmds = ['terminal length 0', 'show pon onu-information all', 'display onu all'];
        run_generic_onu_collector($olt, $ponFile, $onuFile, [], (string) ($olt['brandolt'] ?? ''), $cmds);
        return;
    }

    $target = $host . ':' . $port;
    $descByIdx = cdata_snmp_walk_grouped($target, $community, CDATA_SNMP_OID_DESC);
    $vendByIdx = cdata_snmp_walk_grouped($target, $community, CDATA_SNMP_OID_VENDOR);
    $rxByIdx   = cdata_snmp_walk_grouped($target, $community, CDATA_SNMP_OID_RX);

    // Community tersimpan tidak merespons - coba fallback "public" sebelum menyerah.
    $usedCommunity = $community;
    if (empty($descByIdx) && empty($vendByIdx) && empty($rxByIdx) && strcasecmp($community, 'public') !== 0) {
        $usedCommunity = 'public';
        $descByIdx = cdata_snmp_walk_grouped($target, $usedCommunity, CDATA_SNMP_OID_DESC);
        $vendByIdx = cdata_snmp_walk_grouped($target, $usedCommunity, CDATA_SNMP_OID_VENDOR);
        $rxByIdx   = cdata_snmp_walk_grouped($target, $usedCommunity, CDATA_SNMP_OID_RX);
    }

    if (empty($descByIdx) && empty($vendByIdx) && empty($rxByIdx)) {
        echo_line("CDATA SNMP tidak merespons ({$target}, community: {$usedCommunity}) - fallback ke Telnet.");
        $cmds = ['terminal length 0', 'show pon onu-information all', 'display onu all'];
        run_generic_onu_collector($olt, $ponFile, $onuFile, [], (string) ($olt['brandolt'] ?? ''), $cmds);
        return;
    }

    save_debug($ponFile, "CDATA SNMP | HOST: {$target} | community: {$usedCommunity}\n\nDESC:\n" . print_r($descByIdx, true) . "\nRX:\n" . print_r($rxByIdx, true));

    // Cocokkan Desc SNMP ke IDPEL pelanggan di scope OLT ini. Dibandingkan setelah
    // dinormalisasi (buang semua karakter selain huruf/angka, lowercase) karena Desc
    // sering punya separator berbeda dari IDPEL, contoh:
    //   Desc "Dummy#1" vs IDPEL "dummy1"  -> normalisasi "dummy1" == "dummy1" (cocok)
    //   Desc "14120050608.0103.06.03@airlink.co.id" vs IDPEL "14120050608" -> tetap cocok,
    //   titik/@ dibuang tapi urutan digit IDPEL tetap berurutan di awal.
    $customers = get_customer_list_by_scope($conn, $olt['pemilik'] ?? '', $olt['area'] ?? '');
    foreach ($customers as &$custRef) {
        $custRef['idpel_norm'] = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $custRef['idpel']));
    }
    unset($custRef);

    $content = '';
    $matched = 0;
    $idxSet = array_unique(array_merge(array_keys($descByIdx ?: []), array_keys($vendByIdx ?: []), array_keys($rxByIdx ?: [])));

    foreach ($idxSet as $idx) {
        $desc = trim((string) ($descByIdx[$idx] ?? ''));
        if ($desc === '') {
            continue;
        }
        $descNorm = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $desc));

        $idpelMatch = '';
        foreach ($customers as $cust) {
            // Minimal 4 karakter supaya IDPEL pendek/generik tidak asal cocok ke banyak Desc.
            if ($cust['idpel_norm'] !== '' && strlen($cust['idpel_norm']) >= 4 && strpos($descNorm, $cust['idpel_norm']) !== false) {
                $idpelMatch = $cust['idpel'];
                break;
            }
        }
        if ($idpelMatch === '') {
            continue; // tidak ketemu IDPEL yang cocok, skip daripada salah pasang ke pelanggan lain
        }

        $rxRaw = $rxByIdx[$idx] ?? null;
        $rx = (is_numeric($rxRaw)) ? (string) round(((float) $rxRaw) / 100, 2) : '0';
        $state = (is_numeric($rxRaw) && (float) $rxRaw <= -3900) ? 'Down' : 'Up';

        $content .= build_onulist_line($idx, $idpelMatch, '', $state, $rx, '0');
        $matched++;
    }

    save_debug($onuFile, $content);
    echo_line("CDATA SNMP ONU list disimpan | Total index SNMP: " . count($idxSet) . " | Cocok ke IDPEL: {$matched}");
}

// ─────────────────────────────────────────────────────────────────────────────
// Dispatcher – pilih collector berdasarkan brand OLT
// ─────────────────────────────────────────────────────────────────────────────

function run_telnet_brand_collector($conn, $olt, $ponFile, $onuFile, $acsMap)
{
    $brand     = (string) ($olt['brandolt'] ?? '');
    $brandNorm = normalize_brand($brand);

    if (strpos($brandNorm, 'ZTE') !== false) {
        run_zte_collector($olt, $ponFile, $onuFile, $acsMap);
    } elseif (strpos($brandNorm, 'HUAWEI') !== false) {
        run_huawei_collector($olt, $ponFile, $onuFile, $acsMap);
    } elseif (strpos($brandNorm, 'CDATA') !== false) {
        if (trim((string) ($olt['community_read'] ?? '')) !== '') {
            // Community SNMP sudah dikonfigurasi di data OLT ini - pakai SNMP,
            // karena Telnet "show ont info all" tidak punya kolom RX/TX sama sekali.
            run_cdata_snmp_collector($conn, $olt, $ponFile, $onuFile);
        } else {
            // Belum ada community SNMP tersimpan - coba Telnet apa adanya (kemungkinan
            // besar tidak dapat RX untuk model ini, tapi tetap dapat status Online/Offline).
            $cmds = ['terminal length 0', 'show pon onu-information all', 'display onu all'];
            run_generic_onu_collector($olt, $ponFile, $onuFile, $acsMap, $brand, $cmds);
        }
    } elseif (strpos($brandNorm, 'VSOL') !== false) {
        // VSOL GPON: show pon onu all, VSOL EPON: show epon onu all
        $cmds = ['terminal length 0', 'show pon onu all', 'show epon onu all'];
        run_generic_onu_collector($olt, $ponFile, $onuFile, $acsMap, $brand, $cmds);
    } elseif (strpos($brandNorm, 'HSGQ') !== false) {
        // HSGQ GPON: show pon onu-information all, HSGQ EPON: show epon onu-information all
        $cmds = ['terminal length 0', 'show pon onu-information all', 'show epon onu-information all'];
        run_generic_onu_collector($olt, $ponFile, $onuFile, $acsMap, $brand, $cmds);
    } elseif (strpos($brandNorm, 'HIOSO') !== false) {
        // HIOSO EPON: show epon onu-information all
        $cmds = ['terminal length 0', 'show epon onu-information all', 'show epon onu uncfg all'];
        run_generic_onu_collector($olt, $ponFile, $onuFile, $acsMap, $brand, $cmds);
    } else {
        // brand tidak dikenal – coba command umum
        $cmds = ['terminal length 0', 'show pon onu-information all', 'show onu all'];
        run_generic_onu_collector($olt, $ponFile, $onuFile, $acsMap, $brand, $cmds);
    }
}

$acsMap = load_acs_cache_map();
echo_line('Cache ACS user terdeteksi: ' . count($acsMap));

$result = $conn->query('SELECT * FROM olt');
if (!$result) {
    die('Query gagal: ' . $conn->error);
}

foreach ($result as $olt) {
    $id = (int) $olt['id'];
    $area = safe_name($olt['area'] ?? 'UNKNOWN');
    $pemilik = safe_name($olt['pemilik'] ?? 'UNKNOWN');
    $brand = (string) ($olt['brandolt'] ?? 'UNKNOWN');

    echo_line("Proses OLT: {$id} | BRAND: {$brand} | AREA: {$area} | PEMILIK: {$pemilik}");

    $ponFile = "ponlist_{$id}_{$area}_{$pemilik}.txt";
    $onuFile = "onulist_{$id}_{$area}_{$pemilik}.txt";

    if (is_hioso_web_brand($brand)) {
        run_hioso_web_collector($olt, $ponFile, $onuFile);
    } else {
        run_telnet_brand_collector($conn, $olt, $ponFile, $onuFile, $acsMap);
    }
}

$conn->close();
echo_line('Proses selesai.');
?>
