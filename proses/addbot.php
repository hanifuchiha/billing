<?php
require '../cek-sesi.php';
require '../routeros_api.class.php';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proses Tambah Bot</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container-notif {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 50px;
            max-width: 600px;
            width: 100%;
            animation: slideIn 0.5s ease-out;
            text-align: center;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .success-container {
            border-top: 5px solid #10b981;
        }
        
        .error-container {
            border-top: 5px solid #ef4444;
        }
        
        .success-icon, .error-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: bounce 0.6s ease-out;
        }
        
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        h2 {
            color: #1f2937;
            margin-bottom: 25px;
            font-size: 28px;
            font-weight: 600;
        }
        
        .info-box {
            background: #f3f4f6;
            border-radius: 15px;
            padding: 25px;
            margin: 25px 0;
            text-align: left;
        }

        /* Vertical layout variant for success/info boxes */
        .info-box.vertical .info-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .info-box.vertical .info-item:last-child {
            border-bottom: none;
        }

        .info-box.vertical .label {
            font-weight: 700;
            color: #374151;
            min-width: 0;
            margin-bottom: 4px;
        }

        .info-box.vertical .value {
            width: 100%;
            text-align: left;
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .label {
            font-weight: 600;
            color: #6b7280;
            min-width: 120px;
        }
        
        .value {
            color: #1f2937;
            font-family: 'Courier New', monospace;
            background: white;
            padding: 8px 12px;
            border-radius: 8px;
            word-break: break-all;
            flex: 1;
            text-align: right;
        }
        
        .error-text {
            color: #6b7280;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        
        .error-detail {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 15px;
            color: #991b1b;
            text-align: left;
            font-size: 14px;
            margin-top: 15px;
        }
        
        code {
            display: block;
            background: #f9fafb;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 12px;
        }
        
        .button-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn-custom {
            display: inline-block;
            padding: 12px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .btn-next {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-next:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        .btn-back {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }
        
        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
        
        @media (max-width: 600px) {
            .container-notif {
                padding: 30px;
            }
            
            h2 {
                font-size: 24px;
            }
            
            .success-icon, .error-icon {
                font-size: 60px;
            }
            
            .info-item {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .value {
                width: 100%;
                text-align: left;
                margin-top: 8px;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .btn-custom {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<?php

// Simpan konfigurasi
$config_file = '../config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$lokalwebserver=$config['webiplocal'];

$servername = $config['db_host'];
$username_db = $config['db_user'];
$password_db = $config['db_pass'];
$database = $config['db_name'];

// Buat koneksi MySQLi
$mysqli = new mysqli($servername, $username_db, $password_db, $database);
if ($mysqli->connect_errno) {
    die("Gagal koneksi ke database: " . $mysqli->connect_error);
}

// Ambil input dari POST
$botpass = filter_var(trim($_POST["botpass"]), FILTER_SANITIZE_STRING);
$botname = filter_var(trim($_POST["botname"]), FILTER_SANITIZE_STRING);
$area = filter_var(trim($_POST["area"]), FILTER_SANITIZE_STRING);
$server = filter_var(trim($_POST["server"]), FILTER_SANITIZE_STRING);
$botversion = isset($_POST["botversion"]) ? trim($_POST["botversion"]) : 'v8.4.0';
$deployMode = isset($_POST["deploy_mode"]) ? trim($_POST["deploy_mode"]) : 'inside';
$sshIp = isset($_POST["ssh_ip"]) ? trim($_POST["ssh_ip"]) : '';
$sshUser = isset($_POST["ssh_user"]) ? trim($_POST["ssh_user"]) : '';
$sshPass = isset($_POST["ssh_pass"]) ? trim($_POST["ssh_pass"]) : '';

$allowedVersions = ['v7.10.1', 'v8.3.5', 'v8.4.0', 'latest'];
if (!in_array($botversion, $allowedVersions, true)) {
    $botversion = 'v8.4.0';
}

$allowedDeployModes = ['inside', 'outside'];
if (!in_array($deployMode, $allowedDeployModes, true)) {
    $deployMode = 'inside';
}

if ($deployMode === 'outside' && ($sshIp === '' || $sshUser === '' || $sshPass === '')) {
    echo "<div class='container-notif error-container'>";
    echo "<div class='error-icon'>⚠️</div>";
    echo "<h2>Data SSH Belum Lengkap</h2>";
    echo "<p class='error-text'>Untuk mode DOCKER DI LUAR HOST, kolom IP, User, dan Pass wajib diisi.</p>";
    echo "</div>";
    echo "<div class='button-group'><a href='javascript:history.back()' class='btn-custom btn-back'>← Kembali</a></div>";
    exit;
}

$API = new RouterosAPI();
$ip = $config['router_ip'];
$ippublicportapi = $config['ippublicportapi'];  
$username = $config['router_user'];
$password = $config['router_pass'];



if ($API->connect($ip . ":" . $ippublicportapi, $username, $password)) {

    // ===== AUTO CLEANUP: Hapus bot orphaned/duplikat sebelum membuat yang baru =====
    $cleanupStatus = [];
    
    // Get all bots dari database
    $allBotsDb = [];
    $dbResult = $mysqli->query("SELECT id, namebot, addressbot FROM botwa ORDER BY id DESC");
    if ($dbResult) {
        while ($row = $dbResult->fetch_assoc()) {
            $port = 0;
            $parsed = parse_url($row['addressbot']);
            if (isset($parsed['port'])) {
                $port = (int)$parsed['port'];
            }
            $allBotsDb[] = ['id' => $row['id'], 'name' => $row['namebot'], 'port' => $port];
        }
    }
    
    // Detect duplicate ports di database
    $portCounts = [];
    foreach ($allBotsDb as $bot) {
        if ($bot['port'] > 0) {
            if (!isset($portCounts[$bot['port']])) {
                $portCounts[$bot['port']] = [];
            }
            $portCounts[$bot['port']][] = $bot;
        }
    }
    
    // Hapus duplikat (keep yang ID terbesar = paling baru)
    foreach ($portCounts as $port => $bots) {
        if (count($bots) > 1) {
            // Sort by ID desc, keep first (ID terbesar), delete sisanya
            usort($bots, function($a, $b) { return $b['id'] - $a['id']; });
            for ($i = 1; $i < count($bots); $i++) {
                $oldBot = $bots[$i];
                // Delete dari database
                $mysqli->query("DELETE FROM botwa WHERE id = " . (int)$oldBot['id']);
                
                // Stop docker container jika masih ada
                $containerName = "whatsapp_" . $oldBot['port'];
                shell_exec("docker stop $containerName 2>/dev/null");
                shell_exec("docker rm $containerName 2>/dev/null");
                
                $cleanupStatus[] = "🗑️  Cleanup: Duplikat port {$oldBot['port']} dihapus (Bot ID {$oldBot['id']})";
            }
        }
    }
    
    // Cek port invalid atau hilang (addressbot kosong / invalid format)
    $invalidResult = $mysqli->query("SELECT id, namebot FROM botwa WHERE addressbot = '' OR addressbot IS NULL");
    if ($invalidResult && $invalidResult->num_rows > 0) {
        while ($row = $invalidResult->fetch_assoc()) {
            $mysqli->query("DELETE FROM botwa WHERE id = " . (int)$row['id']);
            $cleanupStatus[] = "🗑️  Cleanup: Bot ID {$row['id']} ({$row['namebot']}) dihapus (invalid address)";
        }
    }
    
    // CLEANUP ORPHANED/STOPPED WHATSAPP CONTAINERS DI DOCKER
    // Cek Docker ps -a untuk find whatsapp containers yang sudah stopped/exited tapi still exist
    exec("docker ps -a --filter 'name=whatsapp_' --format '{{.Names}}\t{{.State}}' 2>/dev/null", $allDockerBots, $dockerACode);
    if ($dockerACode === 0 && !empty($allDockerBots)) {
        foreach ($allDockerBots as $botLine) {
            $parts = explode("\t", $botLine);
            if (count($parts) >= 2) {
                $container = trim($parts[0]);
                $state = trim($parts[1]);
                
                // Extract port dari container name: whatsapp_3000 -> 3000
                if (preg_match('/whatsapp_(\d+)/', $container, $m)) {
                    $port = (int)$m[1];
                    
                    // Cek di database ada port ini atau tidak
                    $checkDb = $mysqli->query("SELECT id FROM botwa WHERE addressbot LIKE '%:$port%'");
                    $hasInDb = $checkDb && $checkDb->num_rows > 0;
                    
                    // Jika state != "running" atau tidak ada di database -> remove
                    if ($state !== 'running' || !$hasInDb) {
                        shell_exec("docker rm -f " . escapeshellarg($container) . " 2>/dev/null");
                        $cleanupStatus[] = "🗑️  Cleanup Docker: Container $container ($state) dihapus" . (!$hasInDb ? " (tidak ada di DB)" : "");
                    }
                }
            }
        }
    }
    
    // ===== END AUTO CLEANUP =====

    $configuredStart = isset($config['port_start_bot']) ? (int)$config['port_start_bot'] : 3000;
    $configuredEnd = isset($config['port_end_bot']) ? (int)$config['port_end_bot'] : 3999;

    // Hard bounds: only allow bot ports inside 3000-3999
    $PORT_MIN = max(3000, min(3999, $configuredStart));
    $PORT_MAX = max(3000, min(3999, $configuredEnd));
    if ($PORT_MIN > $PORT_MAX) {
        $PORT_MIN = 3000;
        $PORT_MAX = 3999;
    }

    $collectPortsFromExpression = function ($expression) use ($PORT_MIN, $PORT_MAX) {
        $portsFound = [];
        $expression = trim((string)$expression);
        if ($expression === '') {
            return $portsFound;
        }

        foreach (explode(',', $expression) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            if (strpos($segment, '-') !== false) {
                $parts = explode('-', $segment, 2);
                $start = (int)trim($parts[0]);
                $end = (int)trim($parts[1]);
                if ($start > 0 && $end >= $start) {
                    $from = max($start, $PORT_MIN);
                    $to = min($end, $PORT_MAX);
                    for ($p = $from; $p <= $to; $p++) {
                        $portsFound[$p] = true;
                    }
                }
            } else {
                $p = (int)$segment;
                if ($p >= $PORT_MIN && $p <= $PORT_MAX) {
                    $portsFound[$p] = true;
                }
            }
        }

        return $portsFound;
    };

    $API->write('/ip/firewall/nat/print', false);
    $API->write('?chain=dstnat');
    $natRules = $API->read();

    $usedPorts = [];
    foreach ($natRules as $rule) {
        if (isset($rule['dst-port'])) {
            foreach ($collectPortsFromExpression($rule['dst-port']) as $p => $_) {
                $usedPorts[$p] = true;
            }
        }
        if (isset($rule['to-ports'])) {
            foreach ($collectPortsFromExpression($rule['to-ports']) as $p => $_) {
                $usedPorts[$p] = true;
            }
        }
    }
    
    // ALSO CHECK DOCKER RUNNING CONTAINERS untuk port yang sudah reserved
    // Ini penting untuk detect container lain seperti genieacs di port 3000
    exec("docker ps --format '{{.Ports}}' 2>/dev/null", $dockerPorts, $dockerCode);
    if ($dockerCode === 0) {
        foreach ($dockerPorts as $portMapping) {
            // Format: "0.0.0.0:3000->3000/tcp" atau "0.0.0.0:3011->3000/tcp"
            // Kita ambil port yang external (sebelum ->)
            if (preg_match_all('/0\.0\.0\.0:(\d+)->', $portMapping, $matches)) {
                foreach ($matches[1] as $port) {
                    $p = (int)$port;
                    if ($p >= $PORT_MIN && $p <= $PORT_MAX) {
                        $usedPorts[$p] = true;
                    }
                }
            }
        }
    }

    // Cek juga port dari database botwa agar tidak bentrok jika NAT belum sinkron
    $dbResult = $mysqli->query("SELECT addressbot FROM botwa");
    if ($dbResult) {
        while ($dbRow = $dbResult->fetch_assoc()) {
            $addr = isset($dbRow['addressbot']) ? (string)$dbRow['addressbot'] : '';
            $parsedAddr = parse_url($addr);
            $dbPort = isset($parsedAddr['port']) ? (int)$parsedAddr['port'] : 0;
            if ($dbPort >= $PORT_MIN && $dbPort <= $PORT_MAX) {
                $usedPorts[$dbPort] = true;
            }
        }
    }

    $foundPort = null;
    for ($candidatePort = $PORT_MIN; $candidatePort <= $PORT_MAX; $candidatePort++) {
        if (!isset($usedPorts[$candidatePort])) {
            $foundPort = $candidatePort;
            break;
        }
    }

    if ($foundPort !== null) {
        // Port kosong ditemukan
        $volumeName = "whatsapp_$foundPort";
        shell_exec("docker volume create $volumeName");





        $asal = "{$config['webhook_dir']}webhook.php";
        $tujuan = "{$config['webhook_dir']}webhook_$volumeName.php";

        // Salin file
        if (copy($asal, $tujuan)) {
            // echo "✅ File berhasil disalin ke: $tujuan<br>";

            // Atur permission ke 0777 (tidak disarankan untuk produksi)
            if (chmod($tujuan, 0777)) {
                // echo "✅ Permission file tujuan diatur ke 0777 (rwxrwxrwx)<br>";
            } else {
                // echo "⚠️ Gagal mengatur permission file tujuan<br>";
            }
        } else {
            // echo "❌ Gagal menyalin file dari $asal ke $tujuan<br>";
        }

        $asal1 = "{$config['webhook_dir']}webhook_log.txt";
        $tujuan1 = "{$config['webhook_dir']}webhook_log_$volumeName.txt";

        // Salin file
        if (copy($asal1, $tujuan1)) {
            // echo "✅ File berhasil disalin ke: $tujuan<br>";

            // Atur permission ke 0777 (tidak disarankan untuk produksi)
            if (chmod($tujuan1, 0777)) {
                // echo "✅ Permission file tujuan diatur ke 0777 (rwxrwxrwx)<br>";
            } else {
                // echo "⚠️ Gagal mengatur permission file tujuan<br>";
            }
        } else {
            // echo "❌ Gagal menyalin file dari $asal ke $tujuan<br>";
        }

        $asal2 = "{$config['webhook_dir']}botrespon.php";
        $tujuan2 = "{$config['webhook_dir']}botrespon_$volumeName.php";

        // Salin file
        if (copy($asal2, $tujuan2)) {
            // echo "✅ File berhasil disalin ke: $tujuan<br>";

            // Atur permission ke 0777 (tidak disarankan untuk produksi)
            if (chmod($tujuan2, 0777)) {
                // echo "✅ Permission file tujuan diatur ke 0777 (rwxrwxrwx)<br>";
            } else {
                // echo "⚠️ Gagal mengatur permission file tujuan<br>";
            }
        } else {
            // echo "❌ Gagal menyalin file dari $asal ke $tujuan<br>";
        }

              $webhook1 = "{$config['webhook_url']}webhook_$volumeName.php";

  
              
 // MODE DOCKER

$ippublic = $config['ippublic'];
$dockerImage = "aldinokemal2104/go-whatsapp-web-multidevice:$botversion";

$serverDomainRaw = trim((string)($config['domain'] ?? ''));
if (strpos($serverDomainRaw, '://') !== false) {
    $parsedHost = parse_url($serverDomainRaw, PHP_URL_HOST);
    if (!empty($parsedHost)) {
        $serverDomainRaw = (string)$parsedHost;
    }
}
$serverDomain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $serverDomainRaw);
$serverHostIp = preg_replace('/[^0-9a-fA-F:.]/', '', trim((string)$lokalwebserver));
$addHostArg = ($serverDomain !== '' && $serverHostIp !== '')
    ? "--add-host={$serverDomain}:{$serverHostIp} "
    : "";

$runArgs = "docker run --detach "
    . "--privileged "
    . $addHostArg
    . "--publish=$foundPort:3000 "
    . "--name=whatsapp_$foundPort "
    . "--restart=always "
    . "--volume=$volumeName:/app/storages "
    . "$dockerImage rest "
    . "-b=$botname:$botpass "
    . "-b=QTS:$botpass "
    . "-b=userName:secretPassword "
    . "--port=3000 "
    . "--os=Chrome "
    . "--debug=true "
    . "--account-validation=false "
    . "--webhook=$webhook1 "
    . "--webhook-secret=$botpass";

if ($deployMode === 'outside') {
    $remoteVolumeCommand = "sshpass -p " . escapeshellarg($sshPass)
        . " ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "
        . $sshUser . "@" . $sshIp . " "
        . escapeshellarg("docker volume create $volumeName") . " 2>&1";
    shell_exec($remoteVolumeCommand);

    $dockerCommand = "sshpass -p " . escapeshellarg($sshPass)
        . " ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null "
        . $sshUser . "@" . $sshIp . " "
        . escapeshellarg($runArgs)
        . " 2>&1";
    $ippublic = $sshIp;
} else {
    $dockerCommand = $runArgs . " 2>&1";
}










        


        // Tampilkan perintah (dengan password disamarkan) untuk debugging sebelum dieksekusi
        $displayCmd = preg_replace("/sshpass\s+-p\s*'[^']+'/i", "sshpass -p '***'", $dockerCommand);
      
        // Jalankan docker dengan penanganan error dan auto-cleanup
        $tempFile = tempnam(sys_get_temp_dir(), 'docker_');
        $fullCmd = $dockerCommand . " > $tempFile 2>&1; echo $? >> $tempFile";
        
        shell_exec($fullCmd);
        $output = file_get_contents($tempFile);
        $lines = explode("\n", trim($output));
        $exitCode = (int)array_pop($lines);
        $containerId = trim(implode("\n", $lines));
        @unlink($tempFile);

        // Jika port already allocated, coba cleanup container lama dan retry
        if ($exitCode !== 0 && strpos($output, 'port is already allocated') !== false) {
            // Auto cleanup container lama di port ini
            $containerName = "whatsapp_$foundPort";
            shell_exec("docker stop $containerName 2>/dev/null");
            shell_exec("docker rm $containerName 2>/dev/null");
            
            // Hapus entry bot lama dari database dengan port yang sama
            $mysqli->query("DELETE FROM botwa WHERE addressbot LIKE '%:$foundPort%'");
            
            // Retry docker run
            sleep(2); // Wait sebentar sebelum retry
            $tempFile2 = tempnam(sys_get_temp_dir(), 'docker_retry_');
            $fullCmd2 = $dockerCommand . " > $tempFile2 2>&1; echo $? >> $tempFile2";
            shell_exec($fullCmd2);
            $output2 = file_get_contents($tempFile2);
            $lines2 = explode("\n", trim($output2));
            $exitCode = (int)array_pop($lines2);
            $containerId = trim(implode("\n", $lines2));
            @unlink($tempFile2);
            $output = $output2;
            
            $cleanupStatus[] = "🗑️  Auto cleanup: Container lama di port $foundPort dihapus, retry docker run";
        }

        if ($exitCode !== 0 || empty($containerId)) {
            echo "<div class='container-notif error-container'>";
            echo "<div class='error-icon'>🐳</div>";
            echo "<h2>Gagal Menjalankan Docker</h2>";
            echo "<p class='error-text'>Container WhatsApp tidak dapat dijalankan (Exit Code: " . $exitCode . ")</p>";
            echo "<div class='error-detail'>";
            echo "<strong>Output Docker:</strong><br>";
            echo "<code style='color:#666; max-height:200px; overflow-y:auto;'>" . htmlspecialchars($containerId ?: $output) . "</code><br><br>";
            
            echo "<strong>Docker Command (mask password):</strong><br>";
            echo "<code>" . htmlspecialchars($displayCmd) . "</code><br><br>";
            
            echo "<strong>Troubleshooting:</strong><br>";
            echo "1. Cek apakah Docker daemon running: <code>docker ps</code><br>";
            echo "2. Cek image tersedia: <code>docker images | grep aldinokemal</code><br>";
            echo "3. Cek port $foundPort tidak terpakai: <code>netstat -tlnp | grep $foundPort</code><br>";
            echo "4. Cek permission Docker: <code>docker ps</code> tanpa sudo<br>";
            echo "5. Cek log lebih detail di server: <code>docker logs whatsapp_$foundPort</code><br>";
            echo "</div>";
            echo "</div>";
            echo "<div class='button-group'>";
            echo "<a href='javascript:history.back()' class='btn-custom btn-back'>← Kembali</a>";
            echo "<a href='../wabot.php?' class='btn-custom btn-secondary'>Ke Dashboard</a>";
            echo "</div>";
            exit;
        }

       


        // Container ID will be shown inside the success card below (above Nama Bot)



        $comment = "Forward port $foundPort to $botname | $botpass | $server";
        $API->write('/ip/firewall/nat/add', false);
        $API->write('=chain=dstnat', false);
        $API->write('=dst-address='.$ippublic, false);
        $API->write('=protocol=tcp', false);
        $API->write('=dst-port=' . $foundPort, false);
        $API->write('=action=dst-nat', false);
        $API->write('=to-addresses='.$lokalwebserver, false);
        $API->write('=to-ports=' . $foundPort, false);
        $API->write('=comment=' . $comment);
        $API->read();

        // echo "<p style='text-align: center; color: #10b981; font-weight: 600; margin: 15px 0;'>🔗 Rule NAT ditambahkan ke MikroTik</p><br>";

        $addressbot = "http://$lokalwebserver:" . $foundPort;
         $connect = "http://$ippublic:" . $foundPort;
        $webhookview = "{$config['webhook_url']}index.php?id=$volumeName";
        // Bot yang dibuat oleh ASSISTANT (bukan owner) ditandai created_by_assistant
        // -- otomatis jadi PRIVATE (cuma assistant itu sendiri yang bisa lihat/pakai,
        // lihat notifbot/bot_access_helper.php), assistant lain di akun yang sama
        // TIDAK ikut melihat bot ini walau tidak pernah di-assign apapun oleh owner.
        require_once __DIR__ . '/../notifbot/bot_access_helper.php';
        botAccessEnsureColumns($mysqli);
        $botCreatedByAssistant = ($AKSES === 'ASSISTANT') ? (string)($asistant_name ?? '') : '';

        $stmt = $mysqli->prepare("INSERT INTO botwa (namebot, addressbot, webhook, password, pemilik, penerima, created_by_assistant) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            echo "<div class='container-notif error-container'>";
            echo "<div class='error-icon'>⚠️</div>";
            echo "<h2>Gagal Persiapan Database</h2>";
            echo "<p class='error-text'>" . htmlspecialchars($mysqli->error) . "</p>";
            echo "<small class='error-detail'>Hubungi administrator jika masalah berlanjut</small>";
            echo "</div>";
            echo "<div class='button-group'><a href='javascript:history.back()' class='btn-custom btn-back'>← Kembali</a></div>";
            exit;
        }
        $stmt->bind_param("sssssss", $botname, $addressbot, $webhookview, $botpass, $ceknama, $ceknama, $botCreatedByAssistant);

        if ($stmt->execute()) {
            echo "<div class='container-notif success-container'>";
            echo "<div class='success-icon'>✨</div>";
            echo "<h2>Selamat! Bot Berhasil Dibuat</h2>";
            echo "<div class='info-box vertical'>";
            echo "<div class='info-item'><div class='label'>🆔 Container ID:</div><div class='value'>" . htmlspecialchars(trim($containerId)) . "</div></div>";
            echo "<div class='info-item'><div class='label'>📱 Nama Bot:</div><div class='value'>" . htmlspecialchars($botname) . "</div></div>";
            echo "<div class='info-item'><div class='label'>🏷️ Versi GoWA:</div><div class='value'>" . htmlspecialchars($botversion) . "</div></div>";
            echo "<div class='info-item'><div class='label'>🖥️ Mode Docker:</div><div class='value'>" . htmlspecialchars($deployMode === 'outside' ? 'DOCKER DI LUAR HOST' : 'DOCKER DI DALAM HOST') . "</div></div>";
            echo "<div class='info-item'><div class='label'>🌐 Alamat Bot:</div><div class='value'>" . htmlspecialchars($connect) . "</div></div>";
            echo "<div class='info-item'><div class='label'>🔌 Port:</div><div class='value'>" . htmlspecialchars($foundPort) . "</div></div>";
            echo "<div class='info-item'><div class='label'>📡 Webhook URL:</div><div class='value'>" . htmlspecialchars($webhookview) . "</div></div>";
            echo "</div>";
            
            // ===== DISPLAY CLEANUP STATUS =====
            if (!empty($cleanupStatus)) {
                echo "<div class='info-box' style='margin-top:30px; background:#fffbeb; border-left:4px solid #f59e0b;'>";
                echo "<h3 style='color:#92400e;margin-bottom:15px;'>🧹 Proses Cleanup Otomatis</h3>";
                echo "<div style='background:white;padding:15px;border-radius:8px;font-size:13px;line-height:1.8;text-align:left;'>";
                foreach ($cleanupStatus as $status) {
                    echo htmlspecialchars($status) . "<br>";
                }
                echo "</div>";
                echo "</div>";
            }
            
            // ===== DISPLAY BOTRESPON CONFIG =====
            echo "<div class='info-box' style='margin-top:30px;'>";
            echo "<h3 style='color:#1f2937;margin-bottom:15px;'>⚙️ Konfigurasi BOTRESPON</h3>";
            echo "<div style='background:white;padding:15px;border-radius:8px;font-family:monospace;font-size:13px;overflow-x:auto;text-align:left;'>";
            echo "// File: botrespon_" . htmlspecialchars($volumeName) . ".php<br>";
            echo "\$waapi = \"" . htmlspecialchars($addressbot) . "\";<br>";
            echo "\$session = \"" . htmlspecialchars($botname) . "\";<br>";
            echo "\$namebot = \"" . htmlspecialchars($botname) . "\";<br>";
            echo "\$password = \"" . htmlspecialchars($botpass) . "\";<br>";
            echo "\$db_host = \"" . htmlspecialchars($config['db_host']) . "\";<br>";
            echo "\$db_user = \"" . htmlspecialchars($config['db_user']) . "\";<br>";
            echo "\$db_name = \"" . htmlspecialchars($config['db_name']) . "\";<br>";
            echo "\$filename = \"webhook_log_" . htmlspecialchars($volumeName) . ".txt\";<br>";
            echo "</div>";
            echo "</div>";
            
            // ===== DISPLAY DOCKER RUN COMMAND =====
            echo "<div class='info-box' style='margin-top:30px;'>";
            echo "<h3 style='color:#1f2937;margin-bottom:15px;'>🐳 Docker Run Command</h3>";
            echo "<div style='background:white;padding:15px;border-radius:8px;font-family:monospace;font-size:12px;overflow-x:auto;text-align:left;word-break:break-all;'>";
            echo htmlspecialchars($displayCmd);
            echo "</div>";
            echo "<small style='color:#6b7280;margin-top:10px;display:block;'>SSH password disamarkan untuk keamanan</small>";
            echo "</div>";
            
            echo "<div class='button-group'><a href='../wabot.php?' class='btn-custom btn-next'>Lanjut ke Daftar Bot →</a></div>";
            echo "</div>";
        } else {
            echo "<div class='container-notif error-container'>";
            echo "<div class='error-icon'>❌</div>";
            echo "<h2>Gagal Menyimpan ke Database</h2>";
            echo "<p class='error-text'>Bot <strong>" . htmlspecialchars($botname) . "</strong> sudah berjalan namun gagal tercatat di database.</p>";
            echo "<div class='error-detail'>";
            echo "<strong>Pesan Error:</strong><br>";
            echo "<code>" . htmlspecialchars($stmt->error) . "</code>";
            echo "</div>";
            echo "</div>";
            echo "<div class='button-group'>";
            echo "<a href='javascript:history.back()' class='btn-custom btn-back'>← Coba Lagi</a>";
            echo "<a href='../wabot.php?' class='btn-custom btn-secondary'>Ke Dashboard</a>";
            echo "</div>";
        }

        $stmt->close();
        $mysqli->close();




        // Cek apakah sudah pernah dikirim
        $history_file = "../notifbot/data/history-$ceknama.json";
        $history = [];

        if (file_exists($history_file)) {
            $history = json_decode(file_get_contents($history_file), true);
        }

        // Pastikan format history adalah array
        if (!is_array($history)) {
            $history = [];
        }




        $history[] = "[ " . (!empty($asistant_name) ? $asistant_name : $ceknama) . " - " . date('Y-m-d H:i:s') . " ] Bot baru bernama $botname berhasil dibuat";
        // Simpan ke file history
        file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));

        /////////////////////////////////////////////////////////////////////////////////

        // header('Location: ../wabot.php?');
    } else {
        // Port tidak tersedia - show detail tentang port yang sudah dipakai
        $usedPortsList = array_keys($usedPorts);
        sort($usedPortsList);
        
        echo "<div class='container-notif error-container'>";
        echo "<div class='error-icon'>⚠️</div>";
        echo "<h2>Port Tidak Tersedia</h2>";
        echo "<p class='error-text'>Tidak ada port kosong tersedia di rentang {$PORT_MIN}–{$PORT_MAX}.</p>";
        echo "<div class='error-detail'>";
        echo "<strong>Port yang sedang dipakai:</strong><br>";
        echo "<code style='display:block; background:#f5f5f5; padding:10px; margin:10px 0; border-radius:5px;'>";
        echo implode(", ", $usedPortsList);
        echo "</code>";
        echo "<br><strong>Kemungkinan solusi:</strong><br>";
        echo "• Port 3000 sering dipakai oleh aplikasi lain (GEnieACS, etc)<br>";
        echo "• Hubungi administrator untuk extend port range<br>";
        echo "• Atau matikan container lain yang tidak perlu (genieacs, motioneye)<br>";
        echo "• Check di dashboard: 'Cleanup Inactive Bots' untuk hapus bot lama<br>";
        echo "</div>";
        echo "</div>";
        echo "<div class='button-group'><a href='javascript:history.back()' class='btn-custom btn-back'>← Kembali</a></div>";
    }
//   header('Location: ../wabot.php?');
    $API->disconnect();
} else {
    echo "<div class='container-notif error-container'>";
    echo "<div class='error-icon'>🔌</div>";
    echo "<h2>Gagal Terhubung ke MikroTik</h2>";
    echo "<div class='error-detail'>";
    echo "<strong>Periksa konfigurasi berikut:</strong><br><br>";
    echo "✗ IP: <code>$ip</code><br>";
    echo "✗ Username: <code>" . htmlspecialchars($username) . "</code><br>";
    echo "✗ Pastikan API MikroTik diaktifkan<br>";
    echo "✗ Cek firewall MikroTik untuk allow koneksi dari server ini<br>";
    echo "</div>";
    echo "</div>";
    echo "<div class='button-group'>";
    echo "<a href='javascript:history.back()' class='btn-custom btn-back'>← Kembali</a>";
    echo "<a href='../wabot.php?' class='btn-custom btn-secondary'>Ke Dashboard</a>";
    echo "</div>";
}
?>
</body>
</html>

