<?php
// api/wabot.php - WhatsApp Bot API: bot CRUD, service control, device actions, sender,
// access/technical-menu toggles, operational hours, prompt template.
//
// Replaces api/whatsapp_bot.php (dead - wrong table entirely), api/whatsappbot.php (ZERO
// authentication + raw string-concatenated INSERT into columns that don't even exist on `botwa`),
// and the bot-CRUD portion of api/wabot_operations.php (its add_bot/delete_bot never actually
// provisioned/tore down Docker containers or MikroTik NAT rules - just a plain INSERT/DELETE).
// This file ports the real logic from crm/billing/proses/addbot.php (create) and
// crm/billing/wabot.php (delete, service start/stop, device actions, sender, toggles, operational
// hours, prompt) with full functional parity, including the Docker/MikroTik/SSH side effects.
//
// SECURITY FIXES vs the web version (found during audit, fixed here rather than replicated):
// delete, sender edit, prompt read/write, and operational-hours save had NO ownership check in
// wabot.php - any authenticated session could act on any tenant's bot by guessing an id/botname.
// Every action here is scoped to bots whose `pemilik` is in this account's allowed PEMILIK list.
//
// Bot creation replicates the web version's synchronous Docker/MikroTik/SSH provisioning flow -
// same external calls, same order - per the explicit "same process as web" requirement. This means
// the request can legitimately take tens of seconds; set_time_limit() is raised accordingly, same
// tradeoff the web page already has (not solved here, just not made worse - see project plan).
header('Content-Type: application/json');
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();
set_time_limit(150);

$method = $_SERVER['REQUEST_METHOD'];
$input  = api_read_input();
$action = strtolower(trim((string)($input['action'] ?? ($_GET['action'] ?? ''))));

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $pemilik, 'wabot');

// Resolve the OWNER's own username (same $ceknama convention as cek-sesi.php/wabot.php) - for an
// ASSISTANT caller this is the owner they belong to, used when creating bots / writing history.
$ownerUsername = $pemilik;
if ($ctx['is_assistant']) {
    $ownerStmt = $conn->prepare('SELECT USERNAME FROM user WHERE id = ? LIMIT 1');
    $ownerStmt->bind_param('i', $ctx['owner_user_id']);
    $ownerStmt->execute();
    $ownerRow = $ownerStmt->get_result()->fetch_assoc();
    if ($ownerRow && !empty($ownerRow['USERNAME'])) {
        $ownerUsername = (string)$ownerRow['USERNAME'];
    }
}

$roleStmt = $conn->prepare('SELECT STATUS FROM user WHERE id = ? LIMIT 1');
$roleStmt->bind_param('i', $ctx['owner_user_id']);
$roleStmt->execute();
$roleRow = $roleStmt->get_result()->fetch_assoc();
$isAdmin = $roleRow && strtoupper(trim((string)$roleRow['STATUS'])) === 'ADMIN';

$allowedPemilik = api_allowed_pemilik_list($conn, $ctx);

// Defensive schema check - same columns wabot.php itself adds on every page load.
foreach ([
    'technical_menu_enabled'    => "technical_menu_enabled TINYINT(1) NOT NULL DEFAULT 1",
    'allow_read_server'         => "allow_read_server TINYINT(1) NOT NULL DEFAULT 1",
    'allow_read_customer'       => "allow_read_customer TINYINT(1) NOT NULL DEFAULT 1",
    'allow_create_payment_code' => "allow_create_payment_code TINYINT(1) NOT NULL DEFAULT 1",
] as $col => $ddl) {
    $chk = mysqli_query($conn, "SHOW COLUMNS FROM botwa LIKE '" . mysqli_real_escape_string($conn, $col) . "'");
    if ($chk && mysqli_num_rows($chk) === 0) {
        mysqli_query($conn, "ALTER TABLE botwa ADD COLUMN $ddl");
    }
}

function wb_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    $path = __DIR__ . '/../config.json';
    $config = file_exists($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
    return $config;
}

function wb_log_history($owner, $message) {
    $safe = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string)$owner);
    if ($safe === '') {
        return;
    }
    $file = __DIR__ . '/../notifbot/data/history-' . $safe . '.json';
    $history = [];
    if (is_file($file)) {
        $d = json_decode((string)file_get_contents($file), true);
        if (is_array($d)) {
            $history = $d;
        }
    }
    $history[] = '[ api - ' . date('Y-m-d H:i:s') . ' ] ' . $message;
    @file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT));
}

/** Load a bot row, only if its `pemilik` is in this account's allowed PEMILIK list. */
function wb_owned_bot($conn, $botId, array $allowedPemilik) {
    $botId = (int)$botId;
    if ($botId <= 0) {
        return null;
    }
    $res = mysqli_query($conn, "SELECT * FROM botwa WHERE id = $botId LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if (!$row) {
        return null;
    }
    if (!in_array((string)$row['pemilik'], $allowedPemilik, true)) {
        return null;
    }
    return $row;
}

function mikrotikPortExpressionMatches($expression, $targetPort) {
    $expression = trim((string)$expression);
    $targetPort = (int)$targetPort;
    if ($expression === '' || $targetPort <= 0) {
        return false;
    }
    foreach (explode(',', $expression) as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        if (strpos($segment, '-') !== false) {
            $range = explode('-', $segment, 2);
            $start = (int)trim($range[0]);
            $end = (int)trim($range[1]);
            if ($start > 0 && $end >= $start && $targetPort >= $start && $targetPort <= $end) {
                return true;
            }
            continue;
        }
        if ((int)$segment === $targetPort) {
            return true;
        }
    }
    return false;
}

// --- action=create: full Docker + MikroTik + webhook-file provisioning (mirrors proses/addbot.php) ---
function wb_handle_create($conn, $ctx, $ownerUsername, array $input) {
    $config = wb_config();

    $botname = trim((string)($input['botname'] ?? ''));
    $botpass = trim((string)($input['botpass'] ?? ''));
    $area    = trim((string)($input['area'] ?? ''));
    $server  = trim((string)($input['server'] ?? ''));
    $botversion = trim((string)($input['botversion'] ?? 'v8.4.0'));
    $deployMode = trim((string)($input['deploy_mode'] ?? 'inside'));
    $sshIp   = trim((string)($input['ssh_ip'] ?? ''));
    $sshUser = trim((string)($input['ssh_user'] ?? ''));
    $sshPass = trim((string)($input['ssh_pass'] ?? ''));

    if ($botname === '' || $botpass === '') {
        api_json(['success' => false, 'error' => 'botname dan botpass wajib diisi']);
    }

    $allowedVersions = ['v7.10.1', 'v8.3.5', 'v8.4.0', 'latest'];
    if (!in_array($botversion, $allowedVersions, true)) {
        $botversion = 'v8.4.0';
    }
    $allowedDeployModes = ['inside', 'outside'];
    if (!in_array($deployMode, $allowedDeployModes, true)) {
        $deployMode = 'inside';
    }
    if ($deployMode === 'outside' && ($sshIp === '' || $sshUser === '' || $sshPass === '')) {
        api_json(['success' => false, 'error' => 'Untuk deploy_mode=outside, ssh_ip/ssh_user/ssh_pass wajib diisi']);
    }

    $routerFile = realpath(__DIR__ . '/../routeros_api.class.php');
    if (!$routerFile) {
        api_json(['success' => false, 'error' => 'routeros_api.class.php tidak ditemukan']);
    }
    require_once $routerFile;

    $api = new RouterosAPI();
    $ip = (string)($config['router_ip'] ?? '');
    $ippublicportapi = (string)($config['ippublicportapi'] ?? '');
    $routerUser = (string)($config['router_user'] ?? '');
    $routerPass = (string)($config['router_pass'] ?? '');

    if (!@$api->connect($ip . ':' . $ippublicportapi, $routerUser, $routerPass)) {
        api_json(['success' => false, 'error' => "Gagal terhubung ke MikroTik ($ip)"]);
    }

    // --- Auto cleanup: duplicate ports / invalid rows / orphaned Docker containers ---
    $cleanupStatus = [];
    $allBotsDb = [];
    $dbResult = mysqli_query($conn, 'SELECT id, namebot, addressbot FROM botwa ORDER BY id DESC');
    while ($dbResult && ($row = mysqli_fetch_assoc($dbResult))) {
        $port = 0;
        $parsed = parse_url((string)$row['addressbot']);
        if (isset($parsed['port'])) {
            $port = (int)$parsed['port'];
        }
        $allBotsDb[] = ['id' => $row['id'], 'name' => $row['namebot'], 'port' => $port];
    }
    $portCounts = [];
    foreach ($allBotsDb as $bot) {
        if ($bot['port'] > 0) {
            $portCounts[$bot['port']][] = $bot;
        }
    }
    foreach ($portCounts as $port => $bots) {
        if (count($bots) > 1) {
            usort($bots, function ($a, $b) {
                return $b['id'] - $a['id'];
            });
            for ($i = 1; $i < count($bots); $i++) {
                $old = $bots[$i];
                mysqli_query($conn, 'DELETE FROM botwa WHERE id = ' . (int)$old['id']);
                $containerName = 'whatsapp_' . $old['port'];
                shell_exec("docker stop $containerName 2>/dev/null");
                shell_exec("docker rm $containerName 2>/dev/null");
                $cleanupStatus[] = "Cleanup: duplikat port {$old['port']} dihapus (Bot ID {$old['id']})";
            }
        }
    }
    $invalidRes = mysqli_query($conn, "SELECT id, namebot FROM botwa WHERE addressbot = '' OR addressbot IS NULL");
    while ($invalidRes && ($row = mysqli_fetch_assoc($invalidRes))) {
        mysqli_query($conn, 'DELETE FROM botwa WHERE id = ' . (int)$row['id']);
        $cleanupStatus[] = "Cleanup: Bot ID {$row['id']} ({$row['namebot']}) dihapus (invalid address)";
    }
    exec("docker ps -a --filter 'name=whatsapp_' --format '{{.Names}}\t{{.State}}' 2>/dev/null", $allDockerBots, $dockerACode);
    if ($dockerACode === 0 && !empty($allDockerBots)) {
        foreach ($allDockerBots as $botLine) {
            $parts = explode("\t", $botLine);
            if (count($parts) >= 2) {
                $container = trim($parts[0]);
                $state = trim($parts[1]);
                if (preg_match('/whatsapp_(\d+)/', $container, $m)) {
                    $port = (int)$m[1];
                    $checkDb = mysqli_query($conn, "SELECT id FROM botwa WHERE addressbot LIKE '%:$port%'");
                    $hasInDb = $checkDb && mysqli_num_rows($checkDb) > 0;
                    if ($state !== 'running' || !$hasInDb) {
                        shell_exec('docker rm -f ' . escapeshellarg($container) . ' 2>/dev/null');
                        $cleanupStatus[] = "Cleanup Docker: Container $container ($state) dihapus" . (!$hasInDb ? ' (tidak ada di DB)' : '');
                    }
                }
            }
        }
    }

    // --- Find a free port inside the configured range (hard-bounded 3000-3999) ---
    $configuredStart = isset($config['port_start_bot']) ? (int)$config['port_start_bot'] : 3000;
    $configuredEnd   = isset($config['port_end_bot']) ? (int)$config['port_end_bot'] : 3999;
    $PORT_MIN = max(3000, min(3999, $configuredStart));
    $PORT_MAX = max(3000, min(3999, $configuredEnd));
    if ($PORT_MIN > $PORT_MAX) {
        $PORT_MIN = 3000;
        $PORT_MAX = 3999;
    }

    $collectPorts = function ($expression) use ($PORT_MIN, $PORT_MAX) {
        $found = [];
        $expression = trim((string)$expression);
        if ($expression === '') {
            return $found;
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
                    for ($p = max($start, $PORT_MIN); $p <= min($end, $PORT_MAX); $p++) {
                        $found[$p] = true;
                    }
                }
            } else {
                $p = (int)$segment;
                if ($p >= $PORT_MIN && $p <= $PORT_MAX) {
                    $found[$p] = true;
                }
            }
        }
        return $found;
    };

    $api->write('/ip/firewall/nat/print', false);
    $api->write('?chain=dstnat');
    $natRules = $api->read();
    $usedPorts = [];
    foreach ($natRules as $rule) {
        if (isset($rule['dst-port'])) {
            foreach ($collectPorts($rule['dst-port']) as $p => $_) {
                $usedPorts[$p] = true;
            }
        }
        if (isset($rule['to-ports'])) {
            foreach ($collectPorts($rule['to-ports']) as $p => $_) {
                $usedPorts[$p] = true;
            }
        }
    }
    exec("docker ps --format '{{.Ports}}' 2>/dev/null", $dockerPorts, $dockerCode);
    if ($dockerCode === 0) {
        foreach ($dockerPorts as $portMapping) {
            if (preg_match_all('/0\.0\.0\.0:(\d+)->/', $portMapping, $matches)) {
                foreach ($matches[1] as $port) {
                    $p = (int)$port;
                    if ($p >= $PORT_MIN && $p <= $PORT_MAX) {
                        $usedPorts[$p] = true;
                    }
                }
            }
        }
    }
    $dbPortsRes = mysqli_query($conn, 'SELECT addressbot FROM botwa');
    while ($dbPortsRes && ($row = mysqli_fetch_assoc($dbPortsRes))) {
        $parsedAddr = parse_url((string)$row['addressbot']);
        $dbPort = isset($parsedAddr['port']) ? (int)$parsedAddr['port'] : 0;
        if ($dbPort >= $PORT_MIN && $dbPort <= $PORT_MAX) {
            $usedPorts[$dbPort] = true;
        }
    }

    $foundPort = null;
    for ($p = $PORT_MIN; $p <= $PORT_MAX; $p++) {
        if (!isset($usedPorts[$p])) {
            $foundPort = $p;
            break;
        }
    }

    if ($foundPort === null) {
        $api->disconnect();
        $usedList = array_keys($usedPorts);
        sort($usedList);
        api_json(['success' => false, 'error' => "Tidak ada port kosong tersedia di rentang {$PORT_MIN}-{$PORT_MAX}", 'used_ports' => $usedList]);
    }

    $volumeName = "whatsapp_$foundPort";
    shell_exec("docker volume create $volumeName");

    $webhookDir = (string)($config['webhook_dir'] ?? '');
    @copy($webhookDir . 'webhook.php', $webhookDir . "webhook_$volumeName.php");
    @chmod($webhookDir . "webhook_$volumeName.php", 0777);
    @copy($webhookDir . 'webhook_log.txt', $webhookDir . "webhook_log_$volumeName.txt");
    @chmod($webhookDir . "webhook_log_$volumeName.txt", 0777);
    @copy($webhookDir . 'botrespon.php', $webhookDir . "botrespon_$volumeName.php");
    @chmod($webhookDir . "botrespon_$volumeName.php", 0777);

    $webhook1 = (string)($config['webhook_url'] ?? '') . "webhook_$volumeName.php";
    $ippublic = (string)($config['ippublic'] ?? '');
    $lokalwebserver = (string)($config['webiplocal'] ?? '');
    $dockerImage = "aldinokemal2104/go-whatsapp-web-multidevice:$botversion";

    $serverDomainRaw = trim((string)($config['domain'] ?? ''));
    if (strpos($serverDomainRaw, '://') !== false) {
        $parsedHost = parse_url($serverDomainRaw, PHP_URL_HOST);
        if (!empty($parsedHost)) {
            $serverDomainRaw = (string)$parsedHost;
        }
    }
    $serverDomain = preg_replace('/[^a-zA-Z0-9.\-]/', '', $serverDomainRaw);
    $serverHostIp = preg_replace('/[^0-9a-fA-F:.]/', '', $lokalwebserver);
    $addHostArg = ($serverDomain !== '' && $serverHostIp !== '') ? "--add-host={$serverDomain}:{$serverHostIp} " : '';

    $runArgs = "docker run --detach --privileged " . $addHostArg
        . "--publish=$foundPort:3000 --name=whatsapp_$foundPort --restart=always "
        . "--volume=$volumeName:/app/storages $dockerImage rest "
        . "-b=$botname:$botpass -b=QTS:$botpass -b=userName:secretPassword "
        . "--port=3000 --os=Chrome --debug=true --account-validation=false "
        . "--webhook=$webhook1 --webhook-secret=$botpass";

    if ($deployMode === 'outside') {
        shell_exec('sshpass -p ' . escapeshellarg($sshPass)
            . ' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . $sshUser . '@' . $sshIp . ' ' . escapeshellarg("docker volume create $volumeName") . ' 2>&1');
        $dockerCommand = 'sshpass -p ' . escapeshellarg($sshPass)
            . ' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . $sshUser . '@' . $sshIp . ' ' . escapeshellarg($runArgs) . ' 2>&1';
        $ippublic = $sshIp;
    } else {
        $dockerCommand = $runArgs . ' 2>&1';
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'docker_');
    shell_exec($dockerCommand . " > $tempFile 2>&1; echo $? >> $tempFile");
    $output = (string)file_get_contents($tempFile);
    $lines = explode("\n", trim($output));
    $exitCode = (int)array_pop($lines);
    $containerId = trim(implode("\n", $lines));
    @unlink($tempFile);

    if ($exitCode !== 0 && strpos($output, 'port is already allocated') !== false) {
        $containerName = "whatsapp_$foundPort";
        shell_exec("docker stop $containerName 2>/dev/null");
        shell_exec("docker rm $containerName 2>/dev/null");
        mysqli_query($conn, "DELETE FROM botwa WHERE addressbot LIKE '%:$foundPort%'");
        sleep(2);
        $tempFile2 = tempnam(sys_get_temp_dir(), 'docker_retry_');
        shell_exec($dockerCommand . " > $tempFile2 2>&1; echo $? >> $tempFile2");
        $output = (string)file_get_contents($tempFile2);
        $lines2 = explode("\n", trim($output));
        $exitCode = (int)array_pop($lines2);
        $containerId = trim(implode("\n", $lines2));
        @unlink($tempFile2);
        $cleanupStatus[] = "Auto cleanup: Container lama di port $foundPort dihapus, retry docker run";
    }

    if ($exitCode !== 0 || $containerId === '') {
        $api->disconnect();
        api_json(['success' => false, 'error' => "Gagal menjalankan Docker (exit code $exitCode)", 'docker_output' => $containerId ?: $output]);
    }

    $comment = "Forward port $foundPort to $botname | $botpass | $server";
    $api->write('/ip/firewall/nat/add', false);
    $api->write('=chain=dstnat', false);
    $api->write('=dst-address=' . $ippublic, false);
    $api->write('=protocol=tcp', false);
    $api->write('=dst-port=' . $foundPort, false);
    $api->write('=action=dst-nat', false);
    $api->write('=to-addresses=' . $lokalwebserver, false);
    $api->write('=to-ports=' . $foundPort, false);
    $api->write('=comment=' . $comment);
    $api->read();
    $api->disconnect();

    $addressbot = "http://$lokalwebserver:$foundPort";
    $connect = "http://$ippublic:$foundPort";
    $webhookview = (string)($config['webhook_url'] ?? '') . "index.php?id=$volumeName";

    $stmt = $conn->prepare('INSERT INTO botwa (namebot, addressbot, webhook, password, pemilik, penerima) VALUES (?, ?, ?, ?, ?, ?)');
    api_bind($stmt, 'ssssss', [$botname, $addressbot, $webhookview, $botpass, $ownerUsername, $ownerUsername]);
    if (!$stmt->execute()) {
        api_json(['success' => false, 'error' => 'Bot ' . $botname . ' berjalan namun gagal tercatat di database: ' . $stmt->error]);
    }
    $newId = $conn->insert_id;

    wb_log_history($ownerUsername, "Bot baru bernama $botname berhasil dibuat");

    api_json([
        'success'      => true,
        'id'           => $newId,
        'container_id' => $containerId,
        'namebot'      => $botname,
        'botversion'   => $botversion,
        'deploy_mode'  => $deployMode,
        'addressbot'   => $connect,
        'port'         => $foundPort,
        'webhook'      => $webhookview,
        'cleanup'      => $cleanupStatus,
    ]);
}

// --- action=delete: Docker stop/rm, MikroTik NAT rule removal, webhook file cleanup, DB delete ---
function wb_handle_delete($conn, array $allowedPemilik, $ownerUsername, array $input) {
    $config = wb_config();
    $id = (int)($input['id'] ?? 0);
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }

    $namebot = (string)$bot['namebot'];
    $addressbot = (string)$bot['addressbot'];
    $portForwardRemovedCount = 0;
    $mikrotikCleanupAttempted = false;
    $mikrotikConnected = false;

    $parsed = parse_url($addressbot);
    $port = isset($parsed['port']) ? (int)$parsed['port'] : 0;

    if ($port >= 3000 && $port <= 3999) {
        $containerName = "whatsapp_$port";
        $volumeName = "whatsapp_$port";

        shell_exec("docker stop $containerName 2>&1");
        shell_exec("docker rm $containerName 2>&1");
        shell_exec("docker volume rm $volumeName 2>&1");

        $addressHost = isset($parsed['host']) ? $parsed['host'] : '';
        $localPublicIp = (string)($config['ippublic'] ?? '');
        $isInsideHost = ($addressHost === '' || $addressHost === $localPublicIp || $addressHost === 'localhost' || $addressHost === '127.0.0.1');
        if (!$isInsideHost) {
            $sshIp = $addressHost;
            $sshUser = (string)($config['docker_remote_user'] ?? '');
            $sshPass = (string)($config['docker_remote_pass'] ?? '');
            // Only attempt remote cleanup if credentials are actually configured - the web
            // version fell back to a hardcoded default password here, which we deliberately do
            // not replicate (avoid propagating a hardcoded credential into new code).
            if ($sshUser !== '' && $sshPass !== '') {
                shell_exec('sshpass -p ' . escapeshellarg($sshPass)
                    . ' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
                    . $sshUser . '@' . $sshIp . ' '
                    . escapeshellarg("docker stop $containerName && docker rm $containerName && docker volume rm $volumeName")
                    . ' 2>&1');
            }
        }

        $routerFile = realpath(__DIR__ . '/../routeros_api.class.php');
        if ($routerFile) {
            require_once $routerFile;
            $api = new RouterosAPI();
            $ip = trim((string)($config['router_ip'] ?? ''));
            $routerApiPort = trim((string)($config['ippublicportapi'] ?? ''));
            $routerUser = trim((string)($config['router_user'] ?? ''));
            $routerPass = (string)($config['router_pass'] ?? '');
            $routerEndpoint = ($ip !== '' && $routerApiPort !== '' && ctype_digit($routerApiPort)) ? "$ip:$routerApiPort" : $ip;

            if ($ip !== '' && $routerUser !== '') {
                $mikrotikCleanupAttempted = true;
            }
            if ($routerEndpoint !== '' && $routerUser !== '' && @$api->connect($routerEndpoint, $routerUser, $routerPass)) {
                $mikrotikConnected = true;
                $api->write('/ip/firewall/nat/print');
                $natRules = $api->read();
                foreach ($natRules as $rule) {
                    if (!isset($rule['.id'])) {
                        continue;
                    }
                    $dstPortExpr = $rule['dst-port'] ?? '';
                    $toPortsExpr = $rule['to-ports'] ?? '';
                    $comment = strtolower(trim((string)($rule['comment'] ?? '')));
                    $chain = strtolower(trim((string)($rule['chain'] ?? '')));
                    if ($chain !== '' && $chain !== 'dstnat') {
                        continue;
                    }
                    $matchByPort = mikrotikPortExpressionMatches($dstPortExpr, $port) || mikrotikPortExpressionMatches($toPortsExpr, $port);
                    $matchByComment = $comment !== '' && (
                        strpos($comment, (string)$port) !== false
                        || strpos($comment, strtolower($namebot)) !== false
                        || strpos($comment, strtolower($containerName)) !== false
                    );
                    if ($matchByPort || $matchByComment) {
                        $api->write('/ip/firewall/nat/remove', false);
                        $api->write('=.id=' . $rule['.id']);
                        $api->read();
                        $portForwardRemovedCount++;
                    }
                }
                $api->disconnect();
            }
        }

        $webhookDir = (string)($config['webhook_dir'] ?? '');
        foreach (["webhook_log_whatsapp_$volumeName.txt", "webhook_$volumeName.php", "botrespon_$volumeName.php"] as $f) {
            $p = $webhookDir . $f;
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    $stmt = $conn->prepare('DELETE FROM botwa WHERE id = ? LIMIT 1');
    api_bind($stmt, 'i', [$id]);
    if (!$stmt->execute()) {
        api_json(['success' => false, 'error' => 'Gagal menghapus data dari database: ' . $stmt->error]);
    }

    if (!$mikrotikCleanupAttempted) {
        $mikrotikInfo = 'mikrotik cleanup: dilewati (router config kosong)';
    } elseif (!$mikrotikConnected) {
        $mikrotikInfo = 'mikrotik cleanup: gagal konek router';
    } else {
        $mikrotikInfo = 'mikrotik dstnat terhapus: ' . $portForwardRemovedCount;
    }
    wb_log_history($ownerUsername, "Bot $namebot berhasil dihapus | $mikrotikInfo");

    api_json(['success' => true, 'message' => "Bot $namebot berhasil dihapus", 'mikrotik' => $mikrotikInfo]);
}

// --- action=start_service / stop_service: systemd unit for the per-bot auto-responder ---
function wb_handle_service($mode, array $allowedPemilik, array $input) {
    $config = wb_config();
    $id = (int)($input['id'] ?? 0);
    $bot = wb_owned_bot($GLOBALS['conn'], $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    $parsed = parse_url((string)$bot['addressbot']);
    $port = isset($parsed['port']) ? (int)$parsed['port'] : 0;
    if ($port <= 0) {
        api_json(['success' => false, 'error' => 'Bot tidak memiliki port yang valid']);
    }
    $volumeName = "whatsapp_$port";
    $serviceName = "botrespon_{$volumeName}.service";

    if ($mode === 'stop') {
        exec('sudo systemctl stop ' . escapeshellarg($serviceName), $output, $code);
        api_json(['success' => $code === 0, 'message' => $code === 0 ? "BOT $volumeName berhasil dihentikan" : "Gagal menghentikan BOT $volumeName"]);
    }

    // start: re-sync the botrespon script from the template, then (re)install + start the unit.
    $webhookDir = rtrim((string)($config['webhook_dir'] ?? ''), '/\\');
    if ($webhookDir === '' || !is_dir($webhookDir)) {
        $webhookDir = (string)realpath(__DIR__ . '/../../webhook');
    }
    $template = $webhookDir . DIRECTORY_SEPARATOR . 'botrespon.php';
    $target = $webhookDir . DIRECTORY_SEPARATOR . "botrespon_{$volumeName}.php";
    $syncWarning = '';
    if (!is_file($template)) {
        $syncWarning = ' Template botrespon.php tidak ditemukan.';
    } elseif (!@copy($template, $target)) {
        $syncWarning = " Gagal sinkron file botrespon untuk {$volumeName}.";
    } else {
        @chmod($target, 0777);
    }

    $curlURL = (string)($config['webhook_url'] ?? '') . "botrespon_$volumeName.php";
    $serviceContent = "[Unit]\nDescription=Bot Auto Reply CURL: {$volumeName}\nAfter=network.target\n\n[Service]\nUser=www-data\nExecStart=/bin/bash -c 'while true; do /usr/bin/curl -s \"{$curlURL}\"; sleep 3; done'\nExecStop=/bin/kill -s TERM \$MAINPID\nKillMode=process\nRestart=always\nRestartSec=2\nStandardOutput=journal\nStandardError=journal\n\n[Install]\nWantedBy=multi-user.target";

    $tmpService = "/tmp/$serviceName";
    file_put_contents($tmpService, $serviceContent);
    exec('sudo systemctl stop ' . escapeshellarg($serviceName));
    exec('sudo systemctl disable ' . escapeshellarg($serviceName));
    exec("sudo mv $tmpService /etc/systemd/system/$serviceName", $out1, $code1);
    exec('sudo systemctl daemon-reload', $out2, $code2);
    exec('sudo systemctl enable ' . escapeshellarg($serviceName), $out3, $code3);

    if ($code1 !== 0 || $code2 !== 0 || $code3 !== 0) {
        api_json(['success' => false, 'error' => "Gagal membuat service $serviceName (code: $code1, $code2, $code3)$syncWarning"]);
    }

    exec('sudo systemctl daemon-reload');
    exec('sudo systemctl reset-failed ' . escapeshellarg($serviceName));
    $startOutput = [];
    exec('sudo systemctl start ' . escapeshellarg($serviceName) . ' 2>&1', $startOutput, $startCode);

    api_json([
        'success' => $startCode === 0,
        'message' => $startCode === 0
            ? "BOT $volumeName berhasil dijalankan.$syncWarning"
            : "Gagal menjalankan BOT $volumeName. Detail: " . implode(' ', $startOutput) . $syncWarning,
    ]);
}

// --- action=device_action: CORS/mixed-content proxy for login/login-with-code/logout/reconnect ---
function wb_handle_device_action(array $allowedPemilik) {
    $allowedActions = [
        'login' => '/app/login',
        'login-with-code' => '/app/login-with-code',
        'logout' => '/app/logout',
        'reconnect' => '/app/reconnect',
    ];
    $deviceAction = strtolower(trim((string)($_GET['device_action'] ?? '')));
    $addressBot = trim((string)($_GET['addressbot'] ?? ''));
    $deviceId = trim((string)($_GET['device_id'] ?? ''));
    $phone = trim((string)($_GET['phone'] ?? ''));

    if (!isset($allowedActions[$deviceAction])) {
        api_json(['success' => false, 'error' => 'Aksi bot tidak valid'], 400);
    }
    if ($addressBot === '' || !filter_var($addressBot, FILTER_VALIDATE_URL)) {
        api_json(['success' => false, 'error' => 'Address bot tidak valid'], 400);
    }
    $scheme = strtolower((string)parse_url($addressBot, PHP_URL_SCHEME));
    if ($scheme !== 'http' && $scheme !== 'https') {
        api_json(['success' => false, 'error' => 'Scheme URL bot harus http atau https'], 400);
    }
    // Extra safety vs. the web version: only proxy to an address that actually belongs to one of
    // this account's own bots, so this endpoint can't be used as a blind fetch-any-URL proxy.
    $normalizedTarget = rtrim($addressBot, '/');
    $ownsThisAddress = false;
    foreach ($allowedPemilik as $p) {
        $chk = mysqli_query($GLOBALS['conn'], "SELECT id FROM botwa WHERE pemilik = '" . mysqli_real_escape_string($GLOBALS['conn'], $p) . "' AND addressbot = '" . mysqli_real_escape_string($GLOBALS['conn'], $normalizedTarget) . "' LIMIT 1");
        if ($chk && mysqli_num_rows($chk) > 0) {
            $ownsThisAddress = true;
            break;
        }
    }
    if (!$ownsThisAddress) {
        api_json(['success' => false, 'error' => 'addressbot tidak dikenali sebagai bot milik akun ini'], 403);
    }

    if ($deviceId === '') {
        try {
            $deviceId = 'device_' . bin2hex(random_bytes(6));
        } catch (Exception $e) {
            $deviceId = 'device_' . uniqid();
        }
    }

    $targetUrl = $normalizedTarget . $allowedActions[$deviceAction];
    if ($deviceAction === 'login-with-code') {
        $digitsPhone = preg_replace('/\D/', '', $phone);
        if ($digitsPhone === '' || !preg_match('/^\d{10,15}$/', $digitsPhone)) {
            api_json(['success' => false, 'error' => 'Nomor telepon tidak valid'], 400);
        }
        $targetUrl .= '?phone=' . urlencode($digitsPhone);
    }

    if (!function_exists('curl_init')) {
        api_json(['success' => false, 'error' => 'Ekstensi cURL tidak tersedia di server'], 500);
    }
    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Device-Id: ' . $deviceId],
    ]);
    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false) {
        api_json(['success' => false, 'error' => 'Gagal menghubungi bot server', 'detail' => $curlError, 'target_url' => $targetUrl], 502);
    }
    $decoded = json_decode($rawResponse, true);
    $isJson = json_last_error() === JSON_ERROR_NONE;
    api_json([
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'target_url' => $targetUrl,
        'device_id' => $deviceId,
        'upstream_json' => $isJson ? $decoded : null,
        'upstream_raw' => $isJson ? null : $rawResponse,
    ]);
}

function wb_handle_edit_sender($conn, array $allowedPemilik, array $input) {
    $id = (int)($input['id'] ?? 0);
    $sender = trim((string)($input['sender'] ?? ''));
    if ($sender === '') {
        api_json(['success' => false, 'error' => 'sender wajib diisi']);
    }
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    $stmt = $conn->prepare('UPDATE botwa SET sender = ? WHERE id = ? LIMIT 1');
    api_bind($stmt, 'si', [$sender, $id]);
    $ok = $stmt->execute();
    api_json(['success' => $ok, 'error' => $ok ? null : $stmt->error]);
}

function wb_handle_access_permissions($conn, array $allowedPemilik, $isAdmin, $ownerUsername, array $input) {
    $id = (int)($input['id'] ?? 0);
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    $allowReadServer = !empty($input['allow_read_server']) ? 1 : 0;
    $allowReadCustomer = !empty($input['allow_read_customer']) ? 1 : 0;
    $allowCreatePayment = !empty($input['allow_create_payment_code']) ? 1 : 0;

    $stmt = $conn->prepare('UPDATE botwa SET allow_read_server=?, allow_read_customer=?, allow_create_payment_code=? WHERE id=? LIMIT 1');
    api_bind($stmt, 'iiii', [$allowReadServer, $allowReadCustomer, $allowCreatePayment, $id]);
    $ok = $stmt->execute();
    if ($ok) {
        wb_log_history($ownerUsername, "Access permissions bot {$bot['namebot']} diperbarui");
    }
    api_json(['success' => $ok, 'error' => $ok ? null : $stmt->error]);
}

function wb_handle_technical_menu_toggle($conn, array $allowedPemilik, $isAdmin, $ownerUsername, array $input) {
    $id = (int)($input['id'] ?? 0);
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    $enabled = !empty($input['technical_menu_enabled']) ? 1 : 0;
    $stmt = $conn->prepare('UPDATE botwa SET technical_menu_enabled = ? WHERE id = ? LIMIT 1');
    api_bind($stmt, 'ii', [$enabled, $id]);
    $ok = $stmt->execute();
    if ($ok) {
        wb_log_history($ownerUsername, 'Technical menu ' . ($enabled ? 'enabled' : 'disabled') . " for bot: {$bot['namebot']}");
    }
    api_json(['success' => $ok, 'error' => $ok ? null : $stmt->error]);
}

function wb_handle_get_hours($conn, array $allowedPemilik, array $input) {
    $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    require_once __DIR__ . '/../../webhook/operational_hours_helper.php';
    $hours = loadOperationalHours((string)$bot['namebot']);
    api_json(['success' => true, 'data' => $hours]);
}

function wb_handle_save_hours($conn, array $allowedPemilik, $ownerUsername, array $input) {
    $id = (int)($input['id'] ?? 0);
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    require_once __DIR__ . '/../../webhook/operational_hours_helper.php';

    $hoursConfig = [
        'enabled' => !empty($input['enabled']),
        'start_time' => trim((string)($input['start_time'] ?? '')),
        'end_time' => trim((string)($input['end_time'] ?? '')),
        'timezone' => (string)($input['timezone'] ?? 'Asia/Jakarta'),
        'days' => is_array($input['days'] ?? null) ? $input['days'] : [],
        'message_outside_hours' => trim((string)($input['message_outside_hours'] ?? '')),
        'offline_mode' => !empty($input['offline_mode']),
    ];

    $ok = saveOperationalHours((string)$bot['namebot'], $hoursConfig);
    if ($ok) {
        wb_log_history($ownerUsername, "Operational hours updated for bot: {$bot['namebot']}");
    }
    api_json(['success' => (bool)$ok, 'error' => $ok ? null : ($GLOBALS['lastOpHoursError'] ?? 'Gagal menyimpan jam operasional')]);
}

function wb_handle_get_prompt($conn, array $allowedPemilik, array $input) {
    $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$bot['namebot']);
    $path = __DIR__ . '/../../webhook/' . $safeName . '.txt';
    $prompt = is_file($path) ? (string)file_get_contents($path) : '';
    api_json(['success' => true, 'data' => ['namebot' => $bot['namebot'], 'prompt' => $prompt]]);
}

function wb_handle_save_prompt($conn, array $allowedPemilik, $ownerUsername, array $input) {
    $id = (int)($input['id'] ?? 0);
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }
    $prompt = trim((string)($input['prompt'] ?? ''));
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$bot['namebot']);
    $ok = @file_put_contents(__DIR__ . '/../../webhook/' . $safeName . '.txt', $prompt) !== false;
    if ($ok) {
        wb_log_history($ownerUsername, "Prompt updated for bot: {$bot['namebot']}");
    }
    api_json(['success' => $ok, 'error' => $ok ? null : 'Gagal menyimpan prompt']);
}

function wb_handle_test_message($conn, array $allowedPemilik, array $input) {
    $id = (int)($input['id'] ?? 0);
    $phone = preg_replace('/[^0-9]/', '', (string)($input['phone'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));
    if (!preg_match('/^62\d{8,15}$/', $phone) || $message === '') {
        api_json(['success' => false, 'error' => 'phone (format 62xxxxxxxxxx) dan message wajib diisi']);
    }
    $bot = wb_owned_bot($conn, $id, $allowedPemilik);
    if (!$bot || empty($bot['addressbot'])) {
        api_json(['success' => false, 'error' => 'Bot tidak ditemukan atau tidak diizinkan'], 404);
    }

    $addressBot = rtrim((string)$bot['addressbot'], '/');
    $namebot = (string)$bot['namebot'];
    $password = (string)$bot['password'];
    $sender = (string)($bot['sender'] ?? '');

    // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
    // device_id query di /send/message) = isi kolom sender apa adanya.
    $deviceId = trim((string)$sender);

    $sendUrl = $addressBot . '/send/message?session=' . urlencode($namebot);
    if ($deviceId !== '') {
        $sendUrl .= '&device_id=' . urlencode($deviceId);
    }
    $payload = ['phone' => $phone, 'message' => $message, 'sender' => $sender];

    if (!function_exists('curl_init')) {
        api_json(['success' => false, 'error' => 'Ekstensi cURL tidak tersedia di server'], 500);
    }
    $sendHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    if ($deviceId !== '') {
        $sendHeaders[] = "X-Device-Id: $deviceId";
    }
    $ch = curl_init($sendUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $sendHeaders);
    curl_setopt($ch, CURLOPT_USERPWD, "$namebot:$password");
    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($rawResponse === false) {
        api_json(['success' => false, 'error' => "Gagal menghubungi bot server: $curlError"], 502);
    }
    $decoded = json_decode($rawResponse, true);
    $isJson = json_last_error() === JSON_ERROR_NONE;
    api_json([
        'success' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'message' => $isJson ? ($decoded['message'] ?? null) : null,
        'raw' => $isJson ? null : substr(strip_tags((string)$rawResponse), 0, 300),
    ]);
}

// action=cleanup_orphans: explicit ADMIN-triggered action - the web version ran this implicitly
// on every admin page load (exec()/systemctl calls on every GET); moved behind an explicit call.
function wb_handle_cleanup_orphans($conn, $isAdmin, $ownerUsername) {
    if (!$isAdmin) {
        api_json(['success' => false, 'error' => 'Hanya ADMIN yang dapat menjalankan cleanup'], 403);
    }
    $config = wb_config();
    $cleaned = [];

    exec("systemctl list-units --all --type=service --plain --no-legend | grep 'botrespon_' | awk '{print $1}'", $allServices);
    if (!empty($allServices)) {
        $dbBotNames = [];
        $res = mysqli_query($conn, 'SELECT addressbot FROM botwa');
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $parsed = parse_url((string)$row['addressbot']);
            $port = isset($parsed['port']) ? (int)$parsed['port'] : 0;
            if ($port > 0) {
                $dbBotNames[] = "botrespon_whatsapp_{$port}.service";
            }
        }
        $orphanedServices = array_diff($allServices, $dbBotNames);
        foreach ($orphanedServices as $orphanService) {
            if (preg_match('/^botrespon_(.+)\.service$/', $orphanService)) {
                exec('sudo systemctl stop ' . escapeshellarg($orphanService));
                exec('sudo systemctl disable ' . escapeshellarg($orphanService));
                exec('sudo rm -f /etc/systemd/system/' . escapeshellarg($orphanService));
                exec('sudo systemctl daemon-reload');
                $cleaned[] = "Service $orphanService dihapus (tidak ada di database)";
            }
        }
    }

    $dbBotResponseFiles = [];
    $resAll = mysqli_query($conn, 'SELECT addressbot FROM botwa');
    while ($resAll && ($row = mysqli_fetch_assoc($resAll))) {
        $parsed = parse_url((string)$row['addressbot']);
        $port = isset($parsed['port']) ? (int)$parsed['port'] : 0;
        if ($port > 0) {
            $dbBotResponseFiles["botrespon_whatsapp_{$port}.php"] = true;
        }
    }
    $webhookDir = isset($config['webhook_dir']) ? rtrim((string)$config['webhook_dir'], '/\\') : '';
    if ($webhookDir === '' || !is_dir($webhookDir)) {
        $webhookDir = (string)realpath(__DIR__ . '/../../webhook');
    }
    if ($webhookDir && is_dir($webhookDir)) {
        $files = glob($webhookDir . DIRECTORY_SEPARATOR . 'botrespon_whatsapp_*.php');
        foreach ((array)$files as $path) {
            $name = basename($path);
            if (!preg_match('/^botrespon_whatsapp_\d+\.php$/', $name)) {
                continue;
            }
            if (!isset($dbBotResponseFiles[$name]) && @unlink($path)) {
                $cleaned[] = "File $name dihapus (bot tidak ada di database)";
            }
        }
    }

    foreach ($cleaned as $line) {
        wb_log_history($ownerUsername, "Auto cleanup: $line");
    }
    api_json(['success' => true, 'cleaned' => $cleaned]);
}

switch ($method) {
    case 'GET':
        if ($action === 'device_action') {
            wb_handle_device_action($allowedPemilik);
            break;
        }
        if (empty($allowedPemilik)) {
            api_json(['success' => true, 'data' => []]);
        }
        $pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);
        $where = ["pemilik IN ($pemilikInSql)"];
        if (!empty($_GET['id'])) {
            $where[] = 'id = ' . (int)$_GET['id'];
        }
        $res = mysqli_query($conn, 'SELECT * FROM botwa WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC');
        $data = [];
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $addr = (string)($row['addressbot'] ?? '');
            $parsed = parse_url($addr);
            $port = isset($parsed['port']) ? (int)$parsed['port'] : 0;
            $row['port'] = $port;
            if ($port > 0) {
                exec('systemctl is-active ' . escapeshellarg("botrespon_whatsapp_{$port}.service") . ' 2>/dev/null', $svcOut, $svcCode);
                $row['service_status'] = trim(implode('', $svcOut ?: []));
                $svcOut = [];
            } else {
                $row['service_status'] = null;
            }
            $data[] = $row;
        }
        api_json(['success' => true, 'data' => $data]);
        break;

    case 'POST':
        switch ($action) {
            case 'create':
                wb_handle_create($conn, $ctx, $ownerUsername, $input);
                break;
            case 'delete':
                wb_handle_delete($conn, $allowedPemilik, $ownerUsername, $input);
                break;
            case 'start_service':
                wb_handle_service('start', $allowedPemilik, $input);
                break;
            case 'stop_service':
                wb_handle_service('stop', $allowedPemilik, $input);
                break;
            case 'edit_sender':
                wb_handle_edit_sender($conn, $allowedPemilik, $input);
                break;
            case 'save_access_permissions':
                wb_handle_access_permissions($conn, $allowedPemilik, $isAdmin, $ownerUsername, $input);
                break;
            case 'save_technical_menu_toggle':
                wb_handle_technical_menu_toggle($conn, $allowedPemilik, $isAdmin, $ownerUsername, $input);
                break;
            case 'get_operational_hours':
                wb_handle_get_hours($conn, $allowedPemilik, $input);
                break;
            case 'save_operational_hours':
                wb_handle_save_hours($conn, $allowedPemilik, $ownerUsername, $input);
                break;
            case 'get_prompt':
                wb_handle_get_prompt($conn, $allowedPemilik, $input);
                break;
            case 'save_prompt':
                wb_handle_save_prompt($conn, $allowedPemilik, $ownerUsername, $input);
                break;
            case 'test_message':
                wb_handle_test_message($conn, $allowedPemilik, $input);
                break;
            case 'cleanup_orphans':
                wb_handle_cleanup_orphans($conn, $isAdmin, $ownerUsername);
                break;
            default:
                api_json(['success' => false, 'error' => 'Action tidak dikenali'], 400);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
