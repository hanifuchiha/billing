<?php
/**
 * Get RX/TX dBm dari OLT cache (onulist debug files)
 * Cari berdasarkan IDPEL (PPPoE username), nama ONT, atau MAC
 * Ambil OLT yang terkait dengan pemilik + area dari tabel olt
 *
 * Method: GET
 * Params: idpel, pemilik, area
 * Response: JSON { found, rx_dbm, tx_dbm, matched_by, source_file, olt_ip }
 */
include '../cek-sesi.php';

header('Content-Type: application/json');

$idpel  = trim((string)($_GET['idpel'] ?? ''));
$pemilik = trim((string)($_GET['pemilik'] ?? ''));
$area   = trim((string)($_GET['area'] ?? ''));

if ($idpel === '') {
    echo json_encode(['found' => false, 'rx_dbm' => 0, 'tx_dbm' => 0, 'error' => 'idpel required']);
    exit;
}

// Auto-lookup pemilik+area dari tabel pelanggan jika tidak diberikan
if (($pemilik === '' || $area === '') && $idpel !== '') {
    $idpelEsc = mysqli_real_escape_string($conn, $idpel);
    $res = mysqli_query($conn, "SELECT PEMILIK, AREA FROM pelanggan WHERE IDPEL = '$idpelEsc' LIMIT 1");
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        if ($pemilik === '') $pemilik = (string)($row['PEMILIK'] ?? '');
        if ($area === '')    $area    = (string)($row['AREA'] ?? '');
    }
}

$idpelLower  = strtolower($idpel);
$debugDir    = __DIR__ . '/debug';
$result      = ['found' => false, 'rx_dbm' => 0, 'tx_dbm' => 0, 'matched_by' => '', 'source_file' => '', 'olt_ip' => ''];

// ─────────────────────────────────────────────────────
// 1. Cari file onulist yang terkait dengan pemilik/area
// ─────────────────────────────────────────────────────
$targetPemiliks = [];
$targetOltIps   = [];

if ($pemilik !== '' || $area !== '') {
    $pemilikEsc = mysqli_real_escape_string($conn, $pemilik);
    $areaEsc    = mysqli_real_escape_string($conn, $area);

    $sql = "SELECT id, pemilik, area, ipolt FROM olt WHERE 1=1";
    if ($pemilik !== '') $sql .= " AND pemilik = '$pemilikEsc'";
    if ($area    !== '') $sql .= " AND area    = '$areaEsc'";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $p = (string)($row['pemilik'] ?? '');
            $a = (string)($row['area'] ?? '');
            $ipolt = (string)($row['ipolt'] ?? '');
            if ($p !== '') $targetPemiliks[] = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '_', $p));
            if ($ipolt !== '') $targetOltIps[] = $ipolt;
        }
    }
}

// Jika tidak ada data OLT, coba juga berdasarkan server table
if (empty($targetPemiliks) && $pemilik !== '') {
    $pemilikSafe = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '_', $pemilik));
    $areaSafe    = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '_', $area));
    $targetPemiliks[] = $pemilikSafe;
    $targetPemiliks[] = $areaSafe;
}

// ─────────────────────────────────────────────────────
// 2. Baca semua onulist_*.txt dan cari match
// ─────────────────────────────────────────────────────
function parseRxTxFromDataLine(string $line): array
{
    // Format: "... | Data: val1,val2,...,rx,tx"
    $dataPart = '';
    $parts = explode('|', $line);
    foreach ($parts as $part) {
        if (stripos(trim($part), 'Data:') === 0) {
            $dataPart = trim(substr(trim($part), 5));
            break;
        }
    }
    if ($dataPart === '') return [0, 0];

    $values = explode(',', $dataPart);
    $total  = count($values);
    if ($total < 2) return [0, 0];

    $rx = (float)($values[$total - 2]);
    $tx = (float)($values[$total - 1]);
    return [$rx, $tx];
}

function matchesIdpel(string $lineLower, string $idpelLower): bool
{
    // Patterns dari gettxrx.php output:
    // "| Nama: {idpel} |"
    // "| PPPoE: {idpel} |"
    // "| MAC: {idpel} |"
    // ",{idpel},"
    return (
        strpos($lineLower, '| nama: ' . $idpelLower . ' |')   !== false ||
        strpos($lineLower, '| pppoe: ' . $idpelLower . ' |')  !== false ||
        strpos($lineLower, 'pppoe: ' . $idpelLower)           !== false ||
        strpos($lineLower, '| mac: ' . $idpelLower . ' |')    !== false ||
        strpos($lineLower, ',' . $idpelLower . ',')            !== false ||
        strpos($lineLower, 'nama: ' . $idpelLower)             !== false
    );
}

if (is_dir($debugDir)) {
    $files = glob($debugDir . '/onulist_*.txt');

    // Prioritaskan file yang namanya mengandung pemilik/area
    if (!empty($targetPemiliks)) {
        usort($files, function($a, $b) use ($targetPemiliks) {
            $aName = strtolower(basename($a));
            $bName = strtolower(basename($b));
            $aScore = 0;
            $bScore = 0;
            foreach ($targetPemiliks as $p) {
                if (strpos($aName, $p) !== false) $aScore++;
                if (strpos($bName, $p) !== false) $bScore++;
            }
            return $bScore - $aScore;
        });
    }

    foreach ($files as $filePath) {
        $fileName = basename($filePath);

        // Filter: jika targetPemiliks ada, file harus mengandung salah satunya
        $fileMatches = empty($targetPemiliks);
        if (!$fileMatches) {
            $fileNameLower = strtolower($fileName);
            foreach ($targetPemiliks as $p) {
                if ($p !== '' && strpos($fileNameLower, $p) !== false) {
                    $fileMatches = true;
                    break;
                }
            }
        }
        if (!$fileMatches) continue;

        // Baca file dan cari baris yang match
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') continue;

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $lineLower = strtolower($line);
            if (!matchesIdpel($lineLower, $idpelLower)) continue;

            [$rx, $tx] = parseRxTxFromDataLine($line);

            // Tentukan matched_by
            $matchedBy = 'name';
            if (strpos($lineLower, 'pppoe: ' . $idpelLower) !== false) {
                $matchedBy = 'pppoe_username';
            } elseif (strpos($lineLower, ',' . $idpelLower . ',') !== false) {
                $matchedBy = 'csv_field';
            }

            // Extract OLT IP dari file jika mungkin
            // Format file: onulist_{id}_{area}_{pemilik}.txt
            $oltIp = '';
            if (!empty($targetOltIps)) {
                // Coba match by file index
                foreach ($targetOltIps as $ip) {
                    $ipSafe = preg_replace('/[^0-9.]/', '', $ip);
                    if ($ipSafe !== '') { $oltIp = $ip; break; }
                }
            }

            $result = [
                'found'       => true,
                'rx_dbm'      => $rx,
                'tx_dbm'      => $tx,
                'matched_by'  => $matchedBy,
                'source_file' => $fileName,
                'olt_ip'      => $oltIp,
                'cache_age'   => time() - (int)@filemtime($filePath),
            ];
            echo json_encode($result);
            exit;
        }
    }
}

// ─────────────────────────────────────────────────────
// 3. Fallback: cari di SEMUA file jika filter OLT tidak match
// ─────────────────────────────────────────────────────
if (!empty($targetPemiliks) && is_dir($debugDir)) {
    $files = glob($debugDir . '/onulist_*.txt') ?: [];
    foreach ($files as $filePath) {
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') continue;

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $lineLower = strtolower($line);
            if (!matchesIdpel($lineLower, $idpelLower)) continue;

            [$rx, $tx] = parseRxTxFromDataLine($line);
            if ($rx == 0 && $tx == 0) continue;

            $result = [
                'found'       => true,
                'rx_dbm'      => $rx,
                'tx_dbm'      => $tx,
                'matched_by'  => 'fallback_global',
                'source_file' => basename($filePath),
                'olt_ip'      => '',
                'cache_age'   => time() - (int)@filemtime($filePath),
            ];
            echo json_encode($result);
            exit;
        }
    }
}

// Tidak ditemukan
echo json_encode($result);
