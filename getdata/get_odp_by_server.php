<?php
include '../cek-sesi.php';

if (!isset($_GET['server']) || $_GET['server'] === '') {
    echo "<option value=''>Pilih KODE ODP</option>";
    exit;
}

$server = $_GET['server'];

$query = "SELECT p.ODP, p.AREA, MAX(o.NAME) AS ODP_NAME
                    FROM pelanggan p
                    LEFT JOIN odp o ON o.KODE = p.ODP AND o.PEMILIK = p.PEMILIK
                    WHERE p.PEMILIK = ?
                        AND p.ODP IS NOT NULL
                        AND p.ODP != ''
                    GROUP BY p.ODP, p.AREA
                    ORDER BY p.ODP ASC";
if ($AKSES == 'ASSISTANT') {
        $query = "SELECT p.ODP, p.AREA, MAX(o.NAME) AS ODP_NAME
                            FROM pelanggan p
                            LEFT JOIN odp o ON o.KODE = p.ODP AND o.PEMILIK = p.PEMILIK
                            WHERE p.PEMILIK = ?
                                AND p.ODP IS NOT NULL
                                AND p.ODP != ''
                                AND p.AREA IN ($area_list)
                            GROUP BY p.ODP, p.AREA
                            ORDER BY p.ODP ASC";
}

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $server);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result && mysqli_num_rows($result) > 0) {
    echo '<option value="">-- Pilih KODE ODP --</option>';
    while ($row = mysqli_fetch_assoc($result)) {
        $odp = htmlspecialchars($row['ODP'], ENT_QUOTES, 'UTF-8');
        $area = htmlspecialchars($row['AREA'] ?? '', ENT_QUOTES, 'UTF-8');
        $odpName = htmlspecialchars($row['ODP_NAME'] ?? '', ENT_QUOTES, 'UTF-8');
        $display = $odp;
        if ($odpName !== '') {
            $display .= ' - ' . $odpName;
        }
        if ($area !== '') {
            $display .= ' (' . $area . ')';
        }
        echo '<option value="' . $odp . '">' . $display . '</option>';
    }
} else {
    echo '<option disabled value="">Tidak ada ODP untuk server ini</option>';
}

mysqli_stmt_close($stmt);
exit;

?>
