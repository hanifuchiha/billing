<?php
require 'cek-sesi.php';

// Hanya admin yang bisa akses
$isAdmin = isset($AKSES) && strtoupper(trim((string)$AKSES)) === 'ADMIN';
if (!$isAdmin) {
    die("❌ Akses ditolak. Hanya ADMIN yang bisa menjalankan cleanup.");
}

// Load config
$config_file = 'config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];

$servername = $config['db_host'];
$username_db = $config['db_user'];
$password_db = $config['db_pass'];
$database = $config['db_name'];

// Koneksi database
$mysqli = new mysqli($servername, $username_db, $password_db, $database);
if ($mysqli->connect_errno) {
    die("❌ Gagal koneksi database: " . $mysqli->connect_error);
}

echo "<pre style='background:#f5f5f5; padding:20px; font-size:13px; line-height:1.6;'>";
echo "=== CLEANUP BOT TIDAK AKTIF ===\n\n";

// 1. Ambil semua bot dari database
$query = "SELECT id, namebot, addressbot FROM botwa ORDER BY id DESC";
$result = $mysqli->query($query);

if (!$result) {
    die("❌ Query error: " . $mysqli->error);
}

$bots = [];
while ($row = $result->fetch_assoc()) {
    $bots[] = [
        'id' => $row['id'],
        'name' => $row['namebot'],
        'address' => $row['addressbot']
    ];
}

echo "📋 Total bot di database: " . count($bots) . "\n\n";

// 2. Parse port dari setiap bot
$botsWithPorts = [];
$inactiveBots = [];

foreach ($bots as $bot) {
    $parsedUrl = parse_url($bot['address']);
    $port = isset($parsedUrl['port']) ? (int)$parsedUrl['port'] : 0;
    
    if ($port > 0) {
        $botsWithPorts[$port] = $bot;
        echo "✓ Bot: " . str_pad($bot['name'], 20) . " | Port: " . str_pad($port, 5) . " | Address: " . $bot['address'] . "\n";
    } else {
        echo "⚠ Bot: " . $bot['name'] . " | INVALID ADDRESS: " . $bot['address'] . "\n";
        $inactiveBots[] = $bot;
    }
}

echo "\n=== CEK STATUS DOCKER ===\n\n";

// 3. Cek mana yang benar-benar running di docker
// Gunakan docker ps untuk outdoor mode, atau anggap semua running untuk inside mode
exec("docker ps --format 'table {{.Names}}\t{{.Status}}' 2>/dev/null", $dockerOutput, $dockerCode);

if ($dockerCode === 0 && !empty($dockerOutput)) {
    echo "🐳 Docker containers yang aktif:\n";
    foreach ($dockerOutput as $line) {
        echo "   " . $line . "\n";
    }
    echo "\n";
} else {
    echo "⚠ Docker tidak tersedia atau tidak berjalan\n\n";
}

// 4. Identifikasi bot tidak aktif (port tidak ada duplicate, tapi mari check lebih detail)
echo "=== ANALISIS PORT DUPLIKAT ===\n\n";

// Cek port mana yang duplikat di database (tidak boleh ada)
$portCounts = array_count_values(array_keys($botsWithPorts));
$duplicatePorts = array_filter($portCounts, function($count) { return $count > 1; });

if (!empty($duplicatePorts)) {
    echo "⚠️  DITEMUKAN PORT DUPLIKAT (INI MASALAH!):\n";
    foreach ($duplicatePorts as $port => $count) {
        echo "   Port $port digunakan oleh " . $count . " bot:\n";
        foreach ($botsWithPorts as $p => $bot) {
            if ($p === $port) {
                echo "      - " . $bot['name'] . " (ID: " . $bot['id'] . ")\n";
            }
        }
    }
    echo "\n   💡 SOLUSI: Delete bot yang lebih lama (keep yang terbaru)\n\n";
} else {
    echo "✓ Tidak ada port duplikat\n\n";
}

// 5. Cek port yang sudah di-reserve tapi bot record ada
echo "=== BOT YANG BISA DIHAPUS ===\n\n";

// Tanya user action
echo "OPSI:\n";
echo "  1. Hapus bot dengan address INVALID\n";
echo "  2. Hapus semua bot PORT DUPLIKAT (keep yang ID-nya paling besar)\n";
echo "  3. Manual delete - mau hapus ID berapa? (ketik ID-nya)\n";
echo "  0. CANCEL\n\n";

// Jika ada parameter POST/GET, lakukan action
$action = isset($_REQUEST['action']) ? filter_var($_REQUEST['action'], FILTER_SANITIZE_STRING) : '';
$targetId = isset($_REQUEST['target_id']) ? (int)$_REQUEST['target_id'] : 0;

if ($action === 'delete_invalid') {
    echo "🗑️  Menghapus bot dengan address INVALID...\n";
    foreach ($inactiveBots as $bot) {
        $stmt = $mysqli->prepare("DELETE FROM botwa WHERE id = ?");
        $stmt->bind_param("i", $bot['id']);
        if ($stmt->execute()) {
            echo "   ✓ DELETED: Bot ID " . $bot['id'] . " (" . $bot['name'] . ")\n";
        } else {
            echo "   ✗ FAILED: Bot ID " . $bot['id'] . " (" . $bot['name'] . ") - " . $stmt->error . "\n";
        }
    }
    echo "\n✅ Selesai!\n";
} elseif ($action === 'delete_duplicates') {
    echo "🗑️  Menghapus bot PORT DUPLIKAT (keep yang terbaru)...\n";
    foreach ($duplicatePorts as $port => $count) {
        // Ambil semua bot di port ini, sort by ID DESC (terbaru = ID terbesar)
        $botsAtPort = [];
        foreach ($botsWithPorts as $p => $bot) {
            if ($p === $port) {
                $botsAtPort[] = $bot;
            }
        }
        usort($botsAtPort, function($a, $b) { return $b['id'] - $a['id']; });
        
        // Keep yang pertama (ID terbesar), delete yang lain
        for ($i = 1; $i < count($botsAtPort); $i++) {
            $botToDelete = $botsAtPort[$i];
            $stmt = $mysqli->prepare("DELETE FROM botwa WHERE id = ?");
            $stmt->bind_param("i", $botToDelete['id']);
            if ($stmt->execute()) {
                echo "   ✓ DELETED: Bot ID " . $botToDelete['id'] . " (" . $botToDelete['name'] . ") - Port $port\n";
            } else {
                echo "   ✗ FAILED: Bot ID " . $botToDelete['id'] . " (" . $botToDelete['name'] . ")\n";
            }
        }
    }
    echo "\n✅ Selesai!\n";
} elseif ($action === 'delete_manual' && $targetId > 0) {
    echo "🗑️  Menghapus bot dengan ID $targetId...\n";
    // Cek bot exists
    $checkStmt = $mysqli->prepare("SELECT namebot FROM botwa WHERE id = ?");
    $checkStmt->bind_param("i", $targetId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows > 0) {
        $checkRow = $checkResult->fetch_assoc();
        $deleteStmt = $mysqli->prepare("DELETE FROM botwa WHERE id = ?");
        $deleteStmt->bind_param("i", $targetId);
        if ($deleteStmt->execute()) {
            echo "   ✓ DELETED: Bot ID $targetId (" . $checkRow['namebot'] . ")\n";
        } else {
            echo "   ✗ FAILED: " . $deleteStmt->error . "\n";
        }
    } else {
        echo "   ✗ Bot ID $targetId tidak ditemukan!\n";
    }
    echo "\n";
}

echo "\n=== SELESAI ===\n";
echo "Kembali ke: <a href='wabot.php'>Dashboard Bot</a>\n";
echo "</pre>";

$mysqli->close();
?>
