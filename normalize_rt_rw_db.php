<?php
session_start();

if (!isset($_SESSION['status']) || $_SESSION['status'] !== 'login' || empty($_SESSION['PEMILIK'])) {
    http_response_code(403);
    echo 'Akses ditolak. Silakan login terlebih dahulu.';
    exit;
}

require __DIR__ . '/koneksidb.php';
@include __DIR__ . '/koneksidbabsensi2.php';

$connections = [];
if (isset($conn) && $conn instanceof mysqli) {
    $connections['billing'] = $conn;
}
if (isset($conn2) && $conn2 instanceof mysqli) {
    $connections['absensi'] = $conn2;
}

if (empty($connections)) {
    http_response_code(500);
    echo 'Koneksi database tidak tersedia.';
    exit;
}

$runMode = (isset($_GET['preview']) && $_GET['preview'] === '1') ? 'preview' : 'update';
$summary = [];
$errors = [];

foreach ($connections as $connName => $dbConn) {
    $dbNameRes = mysqli_query($dbConn, 'SELECT DATABASE() AS dbname');
    if (!$dbNameRes) {
        $errors[] = "[$connName] Gagal membaca nama database: " . mysqli_error($dbConn);
        continue;
    }

    $dbNameRow = mysqli_fetch_assoc($dbNameRes);
    $dbName = isset($dbNameRow['dbname']) ? (string)$dbNameRow['dbname'] : '';
    if ($dbName === '') {
        $errors[] = "[$connName] Nama database kosong.";
        continue;
    }

    $dbNameEsc = mysqli_real_escape_string($dbConn, $dbName);
    $columnsSql = "
        SELECT TABLE_NAME, COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = '{$dbNameEsc}'
          AND COLUMN_NAME IN ('rt', 'rw')
        ORDER BY TABLE_NAME, COLUMN_NAME
    ";

    $columnsRes = mysqli_query($dbConn, $columnsSql);
    if (!$columnsRes) {
        $errors[] = "[$connName] Gagal membaca struktur kolom: " . mysqli_error($dbConn);
        continue;
    }

    while ($row = mysqli_fetch_assoc($columnsRes)) {
        $table = $row['TABLE_NAME'];
        $column = $row['COLUMN_NAME'];

        $tableSafe = '`' . str_replace('`', '``', $table) . '`';
        $columnSafe = '`' . str_replace('`', '``', $column) . '`';

        $where = "TRIM($columnSafe) REGEXP '^[0-9]+$' AND TRIM($columnSafe) <> CAST(CAST(TRIM($columnSafe) AS UNSIGNED) AS CHAR)";
        $countSql = "SELECT COUNT(*) AS total FROM $tableSafe WHERE $where";
        $countRes = mysqli_query($dbConn, $countSql);

        if (!$countRes) {
            $errors[] = "[$connName] Gagal hitung kandidat di {$table}.{$column}: " . mysqli_error($dbConn);
            continue;
        }

        $countRow = mysqli_fetch_assoc($countRes);
        $candidate = isset($countRow['total']) ? (int)$countRow['total'] : 0;
        $affected = 0;

        if ($candidate > 0 && $runMode === 'update') {
            $updateSql = "
                UPDATE $tableSafe
                SET $columnSafe = CAST(CAST(TRIM($columnSafe) AS UNSIGNED) AS CHAR)
                WHERE $where
            ";
            $updateRes = mysqli_query($dbConn, $updateSql);
            if (!$updateRes) {
                $errors[] = "[$connName] Gagal update {$table}.{$column}: " . mysqli_error($dbConn);
            } else {
                $affected = (int)mysqli_affected_rows($dbConn);
            }
        }

        $summary[] = [
            'connection' => $connName,
            'database' => $dbName,
            'table' => $table,
            'column' => $column,
            'candidate' => $candidate,
            'affected' => $affected,
        ];
    }
}

$totalCandidate = 0;
$totalAffected = 0;
foreach ($summary as $item) {
    $totalCandidate += (int)$item['candidate'];
    $totalAffected += (int)$item['affected'];
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Normalisasi RT/RW Database</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #222; }
        .ok { color: #0a7f2e; font-weight: 700; }
        .warn { color: #a15b00; font-weight: 700; }
        .err { color: #b00020; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 14px; }
        th { background: #f2f2f2; }
        .muted { color: #666; font-size: 13px; }
        .top-actions a { margin-right: 10px; }
    </style>
</head>
<body>
    <h2>Normalisasi Data RT/RW di Database</h2>

    <p class="muted">Mode: <strong><?php echo htmlspecialchars($runMode); ?></strong></p>
    <div class="top-actions">
        <a href="?preview=1">Preview saja</a>
        <a href="?">Jalankan update</a>
    </div>

    <p>
        Total kandidat: <strong><?php echo (int)$totalCandidate; ?></strong><br>
        Total ter-update: <strong><?php echo (int)$totalAffected; ?></strong>
    </p>

    <?php if ($runMode === 'preview'): ?>
        <p class="warn">Preview aktif: belum ada data yang diubah.</p>
    <?php else: ?>
        <p class="ok">Update selesai dijalankan.</p>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <h3>Catatan Error</h3>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li class="err"><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Koneksi</th>
                <th>Database</th>
                <th>Tabel</th>
                <th>Kolom</th>
                <th>Kandidat</th>
                <th>Ter-update</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($summary)): ?>
                <tr>
                    <td colspan="6">Tidak ada kolom rt/rw ditemukan.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($summary as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['connection']); ?></td>
                        <td><?php echo htmlspecialchars($item['database']); ?></td>
                        <td><?php echo htmlspecialchars($item['table']); ?></td>
                        <td><?php echo htmlspecialchars($item['column']); ?></td>
                        <td><?php echo (int)$item['candidate']; ?></td>
                        <td><?php echo (int)$item['affected']; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
