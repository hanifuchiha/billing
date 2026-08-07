<?php
include '../cek-sesi.php';
header('Content-Type: application/json; charset=UTF-8');

$server = trim($_GET['server'] ?? '');
$area = trim($_GET['area'] ?? '');

if ($server === '' || $area === '') {
    echo json_encode(['success' => false, 'message' => 'Parameter server/area wajib diisi', 'data' => []]);
    exit;
}

$current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;

function fetch_olt_rows($conn, $query, $types, ...$params) {
    $stmt = mysqli_prepare($conn, $query);
    if (!$stmt) {
        return null;
    }
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = [
                'id' => (int)($row['id'] ?? 0),
                'oltname' => (string)($row['oltname'] ?? ''),
                'ipolt' => (string)($row['ipolt'] ?? ''),
                'brandolt' => (string)($row['brandolt'] ?? ''),
                'usernameolt' => (string)($row['usernameolt'] ?? ''),
                'passwordolt' => (string)($row['passwordolt'] ?? ''),
                'community_read' => (string)($row['community_read'] ?? ''),
                'community_write' => (string)($row['community_write'] ?? ''),
                'pemilik' => (string)($row['pemilik'] ?? ''),
                'area' => (string)($row['area'] ?? ''),
            ];
        }
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

// 1) Utama: server + area dengan normalisasi (trim/lower/remove-space) dan partial area match dua arah
if ($AKSES == 'ASSISTANT') {
    $mainQuery = "SELECT id, oltname, ipolt, brandolt, usernameolt, passwordolt, community_read, community_write, pemilik, area
                  FROM olt
                  WHERE LOWER(REPLACE(TRIM(pemilik), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))
                    AND (
                        LOWER(REPLACE(TRIM(area), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))
                        OR LOWER(REPLACE(TRIM(area), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(?), ' ', '')), '%')
                        OR LOWER(REPLACE(TRIM(?), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(area), ' ', '')), '%')
                    )
                    AND area IN ($area_list)
                  ORDER BY brandolt ASC, oltname ASC";
    $data = fetch_olt_rows($conn, $mainQuery, 'ssss', $server, $area, $area, $area);
} else {
    $mainQuery = "SELECT o.id, o.oltname, o.ipolt, o.brandolt, o.usernameolt, o.passwordolt, o.community_read, o.community_write, o.pemilik, o.area
                  FROM olt o
                  WHERE LOWER(REPLACE(TRIM(o.pemilik), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))
                    AND (
                        LOWER(REPLACE(TRIM(o.area), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))
                        OR LOWER(REPLACE(TRIM(o.area), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(?), ' ', '')), '%')
                        OR LOWER(REPLACE(TRIM(?), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(o.area), ' ', '')), '%')
                    )";

    if ($current_user_id > 0) {
        $mainQuery .= " AND EXISTS (
                            SELECT 1
                            FROM server s
                            WHERE s.PEMILIK = o.pemilik
                              AND s.user_id = ?
                        )";
        $mainQuery .= " ORDER BY o.brandolt ASC, o.oltname ASC";
        $data = fetch_olt_rows($conn, $mainQuery, 'ssssi', $server, $area, $area, $area, $current_user_id);
    } else {
        $mainQuery .= " ORDER BY o.brandolt ASC, o.oltname ASC";
        $data = fetch_olt_rows($conn, $mainQuery, 'ssss', $server, $area, $area, $area);
    }
}

if ($data === null) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query', 'data' => []]);
    exit;
}

// 2) Fallback: server only (tetap jaga akses)
if (count($data) === 0) {
    if ($AKSES == 'ASSISTANT') {
        $fallbackQueryServer = "SELECT id, oltname, ipolt, brandolt, usernameolt, passwordolt, community_read, community_write, pemilik, area
                                FROM olt
                                WHERE LOWER(REPLACE(TRIM(pemilik), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))
                                  AND area IN ($area_list)
                                ORDER BY brandolt ASC, oltname ASC";
        $data = fetch_olt_rows($conn, $fallbackQueryServer, 's', $server);
    } else {
        $fallbackQueryServer = "SELECT o.id, o.oltname, o.ipolt, o.brandolt, o.usernameolt, o.passwordolt, o.community_read, o.community_write, o.pemilik, o.area
                                FROM olt o
                                WHERE LOWER(REPLACE(TRIM(o.pemilik), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))";

        if ($current_user_id > 0) {
            $fallbackQueryServer .= " AND EXISTS (
                                        SELECT 1
                                        FROM server s
                                        WHERE s.PEMILIK = o.pemilik
                                          AND s.user_id = ?
                                    )";
            $fallbackQueryServer .= " ORDER BY o.brandolt ASC, o.oltname ASC";
            $data = fetch_olt_rows($conn, $fallbackQueryServer, 'si', $server, $current_user_id);
        } else {
            $fallbackQueryServer .= " ORDER BY o.brandolt ASC, o.oltname ASC";
            $data = fetch_olt_rows($conn, $fallbackQueryServer, 's', $server);
        }
    }
}

// 3) Fallback akhir: area only (untuk data pemilik yang tidak konsisten tapi area benar)
if (count($data) === 0) {
    if ($AKSES == 'ASSISTANT') {
        $fallbackQueryArea = "SELECT id, oltname, ipolt, brandolt, usernameolt, passwordolt, community_read, community_write, pemilik, area
                              FROM olt
                              WHERE (
                                  LOWER(REPLACE(TRIM(area), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))
                                  OR LOWER(REPLACE(TRIM(area), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(?), ' ', '')), '%')
                                  OR LOWER(REPLACE(TRIM(?), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(area), ' ', '')), '%')
                              )
                                AND area IN ($area_list)
                              ORDER BY brandolt ASC, oltname ASC";
        $data = fetch_olt_rows($conn, $fallbackQueryArea, 'sss', $area, $area, $area);
    } else {
        $fallbackQueryArea = "SELECT o.id, o.oltname, o.ipolt, o.brandolt, o.usernameolt, o.passwordolt, o.community_read, o.community_write, o.pemilik, o.area
                              FROM olt o
                              WHERE (
                                  LOWER(REPLACE(TRIM(o.area), ' ', '')) = LOWER(REPLACE(TRIM(?), ' ', ''))
                                  OR LOWER(REPLACE(TRIM(o.area), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(?), ' ', '')), '%')
                                  OR LOWER(REPLACE(TRIM(?), ' ', '')) LIKE CONCAT('%', LOWER(REPLACE(TRIM(o.area), ' ', '')), '%')
                              )";

        if ($current_user_id > 0) {
            $fallbackQueryArea .= " AND EXISTS (
                                      SELECT 1
                                      FROM server s
                                      WHERE s.PEMILIK = o.pemilik
                                        AND s.user_id = ?
                                   )";
            $fallbackQueryArea .= " ORDER BY o.brandolt ASC, o.oltname ASC";
            $data = fetch_olt_rows($conn, $fallbackQueryArea, 'sssi', $area, $area, $area, $current_user_id);
        } else {
            $fallbackQueryArea .= " ORDER BY o.brandolt ASC, o.oltname ASC";
            $data = fetch_olt_rows($conn, $fallbackQueryArea, 'sss', $area, $area, $area);
        }
    }
}

echo json_encode([
    'success' => true,
    'count' => count($data),
    'server' => $server,
    'area' => $area,
    'data' => $data
]);
