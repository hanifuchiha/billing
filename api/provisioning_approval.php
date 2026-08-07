<?php
// api/provisioning_approval.php
// Mobile-friendly provisioning approval API
// Supports GET (list+count), PUT (approve/reject/reactivate), POST (detail)
// Diganti ke _bootstrap.php::api_authenticate() (session -> username+password ->
// API key dari tabel `apikey`) -- sebelumnya tidak pernah baca param `key`/`api_key`.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
// --- END Autentikasi universal ---

switch ($method) {
    case 'GET':
        // Ambil provisioning dengan filter status dan search
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $status = strtoupper(trim((string)($_GET['status'] ?? 'PENDING')));
        $q = trim((string)($_GET['q'] ?? ''));
        $valid_statuses = ['PENDING', 'APPROVED', 'REJECTED', 'EXPIRED', 'ALL'];
        if (!in_array($status, $valid_statuses)) $status = 'PENDING';

        // Count per status
        $counts = ['PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0, 'EXPIRED' => 0];
        $count_sql = "SELECT status, COUNT(*) as cnt FROM provisioning WHERE server_pemilik = '$pem' GROUP BY status";
        $count_res = mysqli_query($conn, $count_sql);
        while ($crow = mysqli_fetch_assoc($count_res)) {
            if (isset($counts[$crow['status']])) {
                $counts[$crow['status']] = (int)$crow['cnt'];
            }
        }

        // Auto-expire pending older than 3 days
        $exp_sql = "UPDATE provisioning SET status='EXPIRED' WHERE server_pemilik='$pem' AND status='PENDING' AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)";
        mysqli_query($conn, $exp_sql);

        // Fetch records
        $where = ["server_pemilik = '$pem'"];
        if ($status !== 'ALL') {
            $s = mysqli_real_escape_string($conn, $status);
            $where[] = "status = '$s'";
        }
        if ($q !== '') {
            $qe = mysqli_real_escape_string($conn, $q);
            $where[] = "(idpel LIKE '%$qe%' OR nama LIKE '%$qe%' OR paket LIKE '%$qe%' OR nowa LIKE '%$qe%')";
        }
        $sql = "SELECT * FROM provisioning WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT 500";
        $result = mysqli_query($conn, $sql);
        $data = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        echo json_encode(['success' => true, 'counts' => $counts, 'data' => $data]);
        break;

    case 'POST':
        // Ambil detail provisioning (untuk dialog detail)
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID tidak valid']);
            exit;
        }
        $pem = mysqli_real_escape_string($conn, $pemilik);
        $sql = "SELECT * FROM provisioning WHERE id=$id AND server_pemilik='$pem'";
        $result = mysqli_query($conn, $sql);
        if (mysqli_num_rows($result) === 0) {
            echo json_encode(['success' => false, 'error' => 'Record tidak ditemukan']);
            exit;
        }
        $detail = mysqli_fetch_assoc($result);
        echo json_encode(['success' => true, 'detail' => $detail]);
        break;

    case 'PUT':
        // Approve / Reject / Reactivate
        $id = (int)($input['id'] ?? 0);
        $action = strtoupper(trim((string)($input['action'] ?? '')));
        $valid_actions = ['APPROVE', 'REJECT', 'REACTIVATE'];
        if ($id <= 0 || !in_array($action, $valid_actions)) {
            echo json_encode(['success' => false, 'error' => 'ID atau aksi tidak valid']);
            exit;
        }
        $pem = mysqli_real_escape_string($conn, $pemilik);
        
        // Verify ownership
        $check = mysqli_query($conn, "SELECT id FROM provisioning WHERE id=$id AND server_pemilik='$pem'");
        if (mysqli_num_rows($check) === 0) {
            echo json_encode(['success' => false, 'error' => 'Akses ditolak']);
            exit;
        }

        if ($action === 'APPROVE') {
            $stmt = $conn->prepare("UPDATE provisioning SET status='APPROVED', approved_at=NOW(), approved_by=? WHERE id=? AND server_pemilik=?");
            $stmt->bind_param('sis', $pemilik, $id, $pemilik);
        } elseif ($action === 'REJECT') {
            $stmt = $conn->prepare("UPDATE provisioning SET status='REJECTED' WHERE id=? AND server_pemilik=?");
            $stmt->bind_param('is', $id, $pemilik);
        } elseif ($action === 'REACTIVATE') {
            // Reactivate REJECTED back to PENDING and extend expiry
            $stmt = $conn->prepare("UPDATE provisioning SET status='PENDING', expired_at=DATE_ADD(NOW(), INTERVAL 3 DAY) WHERE id=? AND server_pemilik=?");
            $stmt->bind_param('is', $id, $pemilik);
        }
        
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
?>
