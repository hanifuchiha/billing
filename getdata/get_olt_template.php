<?php
include '../cek-sesi.php';
header('Content-Type: application/json; charset=UTF-8');

$createTemplateTableSql = "CREATE TABLE IF NOT EXISTS olt_input_templates (
    olt_id INT NOT NULL PRIMARY KEY,
    auth_mode VARCHAR(50) DEFAULT NULL,
    customer_name VARCHAR(200) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    provinsi VARCHAR(120) DEFAULT NULL,
    kabupaten VARCHAR(120) DEFAULT NULL,
    kecamatan VARCHAR(120) DEFAULT NULL,
    kelurahan VARCHAR(120) DEFAULT NULL,
    rw VARCHAR(10) DEFAULT NULL,
    rt VARCHAR(10) DEFAULT NULL,
    whatsapp VARCHAR(50) DEFAULT NULL,
    email VARCHAR(200) DEFAULT NULL,
    coordinates VARCHAR(120) DEFAULT NULL,
    sales VARCHAR(120) DEFAULT NULL,
    tipe_bayar VARCHAR(30) DEFAULT NULL,
    tipe_tempo VARCHAR(60) DEFAULT NULL,
    tanggal_pasang DATE DEFAULT NULL,
    package_name VARCHAR(120) DEFAULT NULL,
    odp VARCHAR(120) DEFAULT NULL,
    auto_register_enabled TINYINT(1) DEFAULT 1,
    with_wan_config TINYINT(1) DEFAULT 1,
    ont_type VARCHAR(120) DEFAULT NULL,
    ont_interface VARCHAR(80) DEFAULT NULL,
    onu_number VARCHAR(20) DEFAULT NULL,
    ont_sn VARCHAR(120) DEFAULT NULL,
    tcont_profile VARCHAR(120) DEFAULT NULL,
    vlan_id VARCHAR(20) DEFAULT NULL,
    service_name VARCHAR(60) DEFAULT NULL,
    vlan_profile VARCHAR(60) DEFAULT NULL,
    gemport VARCHAR(20) DEFAULT NULL,
    cos VARCHAR(20) DEFAULT NULL,
    ethuni VARCHAR(50) DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by VARCHAR(120) DEFAULT NULL,
    CONSTRAINT fk_olt_input_templates_olt FOREIGN KEY (olt_id) REFERENCES olt(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createTemplateTableSql);

$oltId = isset($_GET['olt_id']) ? (int)$_GET['olt_id'] : 0;
if ($oltId <= 0) {
    echo json_encode(['success' => false, 'message' => 'olt_id tidak valid', 'data' => null]);
    exit;
}

$current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;

if ($AKSES == 'ASSISTANT') {
    $scopeSql = "SELECT o.id
        FROM olt o
        WHERE o.id = ?
          AND o.area IN ($area_list)
        LIMIT 1";
    $scopeStmt = mysqli_prepare($conn, $scopeSql);
    if (!$scopeStmt) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan validasi akses', 'data' => null]);
        exit;
    }
    mysqli_stmt_bind_param($scopeStmt, 'i', $oltId);
} else {
    $scopeSql = "SELECT o.id
        FROM olt o
        WHERE o.id = ?
          AND EXISTS (
            SELECT 1 FROM server s
            WHERE s.PEMILIK = o.pemilik
              AND s.user_id = ?
          )
        LIMIT 1";
    $scopeStmt = mysqli_prepare($conn, $scopeSql);
    if (!$scopeStmt) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan validasi akses', 'data' => null]);
        exit;
    }
    mysqli_stmt_bind_param($scopeStmt, 'ii', $oltId, $current_user_id);
}

mysqli_stmt_execute($scopeStmt);
$scopeRes = mysqli_stmt_get_result($scopeStmt);
$hasAccess = $scopeRes && mysqli_num_rows($scopeRes) > 0;
mysqli_stmt_close($scopeStmt);

if (!$hasAccess) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak untuk OLT ini', 'data' => null]);
    exit;
}

$sql = "SELECT
    ont_type,
    tcont_profile, vlan_id, service_name, vlan_profile,
    gemport, cos, ethuni,
    ont_sn AS vlan_manual
    FROM olt_input_templates
    WHERE olt_id = ?
    LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyiapkan query template', 'data' => null]);
    exit;
}
mysqli_stmt_bind_param($stmt, 'i', $oltId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$row) {
    echo json_encode(['success' => true, 'message' => 'Template belum diatur', 'data' => null]);
    exit;
}

echo json_encode(['success' => true, 'message' => 'OK', 'data' => $row]);
