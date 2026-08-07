<?php
// api/nms.php
// Auth diganti ke _bootstrap.php::api_authenticate() (session -> username+password -> API key
// dari tabel `apikey`) -- sebelumnya endpoint ini tidak pernah cek API key.
require_once '../koneksibilling.php';
require_once '_bootstrap.php';
session_start();
api_cors();

$method = $_SERVER['REQUEST_METHOD'];
$input = api_read_input();

function nms_node_exists_for_owner($conn, $nodeId, $pemilik) {
    $stmt = $conn->prepare("SELECT ID FROM nms WHERE ID=? AND PEMILIK=? LIMIT 1");
    $stmt->bind_param('is', $nodeId, $pemilik);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

// --- Autentikasi universal ---
$auth = api_authenticate($conn, $input);
$pemilik = $auth['pemilik'];
if ($auth['method'] === 'apikey') {
    api_rate_limit($conn, $auth['api_key']);
}
// --- END Autentikasi universal ---

$conn->query("CREATE TABLE IF NOT EXISTS nms_layout (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nms_id INT NOT NULL,
    pemilik VARCHAR(191) NOT NULL,
    pos_x DOUBLE DEFAULT NULL,
    pos_y DOUBLE DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_nms_owner (nms_id, pemilik)
)");

$conn->query("CREATE TABLE IF NOT EXISTS nms_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_id INT NOT NULL,
    to_id INT NOT NULL,
    pemilik VARCHAR(191) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_link_owner (from_id, to_id, pemilik)
)");

switch ($method) {
    case 'GET':
        // Ambil data NMS milik pemilik dengan filter area dan search
        $where = [];
        $where[] = "PEMILIK='" . mysqli_real_escape_string($conn, $pemilik) . "'";
        
        // Filter area
        $area = trim($_GET['area'] ?? '');
        if ($area !== '' && $area !== 'Semua') {
            $where[] = "COALESCE(AREA, '') = '" . mysqli_real_escape_string($conn, $area) . "'";
        }
        
        // Search filter (nama, ip)
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $searchEsc = mysqli_real_escape_string($conn, $search);
            $where[] = "(COALESCE(NAMA, '') LIKE '%$searchEsc%' OR COALESCE(IP, '') LIKE '%$searchEsc%')";
        }
        
        $sql = "SELECT * FROM nms WHERE " . implode(" AND ", $where) . " ORDER BY ID DESC";
        $result = mysqli_query($conn, $sql);

        $layoutMap = [];
        $stmtLayout = $conn->prepare("SELECT nms_id, pos_x, pos_y FROM nms_layout WHERE pemilik=?");
        $stmtLayout->bind_param('s', $pemilik);
        $stmtLayout->execute();
        $resLayout = $stmtLayout->get_result();
        while ($lr = $resLayout->fetch_assoc()) {
            $layoutMap[(string)$lr['nms_id']] = [
                'x' => isset($lr['pos_x']) ? (float)$lr['pos_x'] : null,
                'y' => isset($lr['pos_y']) ? (float)$lr['pos_y'] : null,
            ];
        }

        $nms = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $idKey = (string)($row['ID'] ?? '');
            $layout = $layoutMap[$idKey] ?? ['x' => null, 'y' => null];
            $row['layout_x'] = $layout['x'];
            $row['layout_y'] = $layout['y'];
            $nms[] = $row;
        }

        $links = [];
        $stmtLinks = $conn->prepare("SELECT id, from_id, to_id FROM nms_links WHERE pemilik=? ORDER BY id DESC");
        $stmtLinks->bind_param('s', $pemilik);
        $stmtLinks->execute();
        $resLinks = $stmtLinks->get_result();
        while ($lr = $resLinks->fetch_assoc()) {
            $links[] = [
                'id' => (string)$lr['id'],
                'from_id' => (string)$lr['from_id'],
                'to_id' => (string)$lr['to_id']
            ];
        }

        echo json_encode(['success' => true, 'data' => $nms, 'links' => $links]);
        break;
    case 'POST':
        // Tambah data NMS
        $data = $input;
        $mode = strtolower(trim((string)($data['mode'] ?? '')));
        if ($mode === 'link') {
            $fromId = (int)($data['from_id'] ?? 0);
            $toId = (int)($data['to_id'] ?? 0);
            if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
                echo json_encode(['success' => false, 'error' => 'Data link tidak valid']);
                exit;
            }

            if (!nms_node_exists_for_owner($conn, $fromId, $pemilik) || !nms_node_exists_for_owner($conn, $toId, $pemilik)) {
                echo json_encode(['success' => false, 'error' => 'Node link tidak ditemukan atau bukan milik akun ini']);
                exit;
            }

            // Normalize direction so unique key avoids duplicate reverse links.
            if ($fromId > $toId) {
                $tmp = $fromId;
                $fromId = $toId;
                $toId = $tmp;
            }

            $stmt = $conn->prepare("INSERT INTO nms_links (from_id, to_id, pemilik) VALUES (?, ?, ?)");
            $stmt->bind_param('iis', $fromId, $toId, $pemilik);
            $ok = $stmt->execute();
            if (!$ok && $conn->errno === 1062) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => $ok]);
            }
            break;
        }

        $nama = $data['nama'] ?? '';
        $ip = $data['ip'] ?? '';
        $area = $data['area'] ?? '';
        if (!$nama || !$ip) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("INSERT INTO nms (NAMA, IP, AREA, PEMILIK) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $nama, $ip, $area, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'PUT':
        // Update data NMS
        $data = $input;
        $mode = strtolower(trim((string)($data['mode'] ?? '')));
        if ($mode === 'topology_batch') {
            $nodes = $data['nodes'] ?? [];
            $links = $data['links'] ?? [];
            $newLinks = $data['new_links'] ?? [];
            $deletedLinkIds = $data['deleted_link_ids'] ?? [];
            if (!is_array($nodes)) $nodes = [];
            if (!is_array($links)) $links = [];
            if (!is_array($newLinks)) $newLinks = [];
            if (!is_array($deletedLinkIds)) $deletedLinkIds = [];

            $conn->begin_transaction();
            try {
                if (!empty($deletedLinkIds)) {
                    $stmtDelete = $conn->prepare("DELETE FROM nms_links WHERE id=? AND pemilik=?");
                    foreach ($deletedLinkIds as $linkIdRaw) {
                        $linkId = (int)$linkIdRaw;
                        if ($linkId <= 0) continue;
                        $stmtDelete->bind_param('is', $linkId, $pemilik);
                        $stmtDelete->execute();
                    }
                }

                if (!empty($nodes)) {
                    $stmtLayout = $conn->prepare("INSERT INTO nms_layout (nms_id, pemilik, pos_x, pos_y)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE pos_x=VALUES(pos_x), pos_y=VALUES(pos_y)");

                    foreach ($nodes as $n) {
                        $id = (int)($n['id'] ?? 0);
                        $layoutX = isset($n['layout_x']) ? (float)$n['layout_x'] : null;
                        $layoutY = isset($n['layout_y']) ? (float)$n['layout_y'] : null;
                        if ($id <= 0 || $layoutX === null || $layoutY === null) {
                            continue;
                        }
                        if (!nms_node_exists_for_owner($conn, $id, $pemilik)) {
                            continue;
                        }

                        $layoutX = max(0.0, min(1.0, $layoutX));
                        $layoutY = max(0.0, min(1.0, $layoutY));
                        $stmtLayout->bind_param('isdd', $id, $pemilik, $layoutX, $layoutY);
                        $stmtLayout->execute();
                    }
                }

                if (!empty($links)) {
                    $stmtLink = $conn->prepare("UPDATE nms_links SET from_id=?, to_id=? WHERE id=? AND pemilik=?");
                    foreach ($links as $l) {
                        $id = (int)($l['id'] ?? 0);
                        $fromId = (int)($l['from_id'] ?? 0);
                        $toId = (int)($l['to_id'] ?? 0);
                        if ($id <= 0 || $fromId <= 0 || $toId <= 0 || $fromId === $toId) {
                            continue;
                        }
                        if (!nms_node_exists_for_owner($conn, $fromId, $pemilik) || !nms_node_exists_for_owner($conn, $toId, $pemilik)) {
                            continue;
                        }

                        if ($fromId > $toId) {
                            $tmp = $fromId;
                            $fromId = $toId;
                            $toId = $tmp;
                        }
                        $stmtLink->bind_param('iiis', $fromId, $toId, $id, $pemilik);
                        $stmtLink->execute();
                    }
                }

                if (!empty($newLinks)) {
                    $stmtInsert = $conn->prepare("INSERT INTO nms_links (from_id, to_id, pemilik) VALUES (?, ?, ?)");
                    foreach ($newLinks as $l) {
                        $fromId = (int)($l['from_id'] ?? 0);
                        $toId = (int)($l['to_id'] ?? 0);
                        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
                            continue;
                        }
                        if (!nms_node_exists_for_owner($conn, $fromId, $pemilik) || !nms_node_exists_for_owner($conn, $toId, $pemilik)) {
                            continue;
                        }

                        if ($fromId > $toId) {
                            $tmp = $fromId;
                            $fromId = $toId;
                            $toId = $tmp;
                        }

                        $stmtInsert->bind_param('iis', $fromId, $toId, $pemilik);
                        $okInsert = $stmtInsert->execute();
                        if (!$okInsert && $conn->errno !== 1062) {
                            throw new Exception('Gagal insert link baru');
                        }
                    }
                }

                $conn->commit();
                echo json_encode(['success' => true]);
            } catch (Throwable $e) {
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => 'Gagal menyimpan topology batch']);
            }
            break;
        }

        if ($mode === 'layout') {
            $id = (int)($data['id'] ?? 0);
            $layoutX = isset($data['layout_x']) ? (float)$data['layout_x'] : null;
            $layoutY = isset($data['layout_y']) ? (float)$data['layout_y'] : null;

            if ($id <= 0 || $layoutX === null || $layoutY === null) {
                echo json_encode(['success' => false, 'error' => 'Data posisi tidak lengkap']);
                exit;
            }

            $layoutX = max(0.0, min(1.0, $layoutX));
            $layoutY = max(0.0, min(1.0, $layoutY));

            $stmt = $conn->prepare("INSERT INTO nms_layout (nms_id, pemilik, pos_x, pos_y)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE pos_x=VALUES(pos_x), pos_y=VALUES(pos_y)");
            $stmt->bind_param('isdd', $id, $pemilik, $layoutX, $layoutY);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
            break;
        }

        if ($mode === 'link') {
            $id = (int)($data['id'] ?? 0);
            $fromId = (int)($data['from_id'] ?? 0);
            $toId = (int)($data['to_id'] ?? 0);
            if ($id <= 0 || $fromId <= 0 || $toId <= 0 || $fromId === $toId) {
                echo json_encode(['success' => false, 'error' => 'Data link tidak lengkap']);
                exit;
            }

            if (!nms_node_exists_for_owner($conn, $fromId, $pemilik) || !nms_node_exists_for_owner($conn, $toId, $pemilik)) {
                echo json_encode(['success' => false, 'error' => 'Node link tidak ditemukan atau bukan milik akun ini']);
                exit;
            }

            if ($fromId > $toId) {
                $tmp = $fromId;
                $fromId = $toId;
                $toId = $tmp;
            }

            $stmt = $conn->prepare("UPDATE nms_links SET from_id=?, to_id=? WHERE id=? AND pemilik=?");
            $stmt->bind_param('iiis', $fromId, $toId, $id, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
            break;
        }

        $id = $data['id'] ?? '';
        $nama = $data['nama'] ?? '';
        $ip = $data['ip'] ?? '';
        $area = $data['area'] ?? '';
        if (!$id || !$nama || !$ip) {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }
        $stmt = $conn->prepare("UPDATE nms SET NAMA=?, IP=?, AREA=? WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('sssis', $nama, $ip, $area, $id, $pemilik);
        $ok = $stmt->execute();
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // Dummy: Hapus data NMS
        $data = $input;
        $mode = strtolower(trim((string)($data['mode'] ?? '')));
        if ($mode === 'link') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'ID link tidak ditemukan']);
                exit;
            }
            $stmt = $conn->prepare("DELETE FROM nms_links WHERE id=? AND pemilik=?");
            $stmt->bind_param('is', $id, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
            break;
        }

        $id = $data['id'] ?? '';
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID tidak ditemukan']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM nms WHERE ID=? AND PEMILIK=?");
        $stmt->bind_param('is', $id, $pemilik);
        $ok = $stmt->execute();

        if ($ok) {
            $stmtLinkCleanup = $conn->prepare("DELETE FROM nms_links WHERE pemilik=? AND (from_id=? OR to_id=?)");
            $stmtLinkCleanup->bind_param('sii', $pemilik, $id, $id);
            $stmtLinkCleanup->execute();

            $stmtLayoutCleanup = $conn->prepare("DELETE FROM nms_layout WHERE pemilik=? AND nms_id=?");
            $stmtLayoutCleanup->bind_param('si', $pemilik, $id);
            $stmtLayoutCleanup->execute();
        }

        echo json_encode(['success' => $ok]);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
