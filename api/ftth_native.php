<?php
// Sebelumnya cuma dukung session ATAU username+password -- tidak pernah baca
// param `key`/`api_key`, PADAHAL settingsapi.php mendokumentasikan resmi
// akses via API key. Diganti ke _bootstrap.php::api_authenticate() (session ->
// username+password -> API key dari tabel `apikey`) sama seperti api/odp.php dkk.
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

$conn->query("CREATE TABLE IF NOT EXISTS cables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255),
  geom TEXT,
  attributes TEXT,
  length FLOAT,
  owner VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_asset VARCHAR(255),
  type VARCHAR(255),
  geom TEXT,
  attributes TEXT,
  owner VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

switch ($method) {
    case 'GET':
        $ownerEsc = $conn->real_escape_string($pemilik);
        $search = trim($_GET['search'] ?? '');
        $searchClause = '';
        if ($search !== '') {
            $s = $conn->real_escape_string($search);
            $searchClause = " AND (name LIKE '%$s%' OR id_asset LIKE '%$s%' OR type LIKE '%$s%' OR attributes LIKE '%$s%')";
        }

        $assets = [];
        $aSql = "SELECT id, id_asset, type, geom, attributes, created_at FROM assets WHERE owner='$ownerEsc' $searchClause ORDER BY id DESC";
        $aRes = $conn->query($aSql);
        if ($aRes) {
            while ($row = $aRes->fetch_assoc()) {
                $attrs = json_decode((string)($row['attributes'] ?? '{}'), true);
                if (!is_array($attrs)) $attrs = [];
                $assets[] = [
                    'kind' => 'asset',
                    'id' => (string)$row['id'],
                    'name' => (string)($attrs['nama'] ?? $row['id_asset'] ?? ''),
                    'asset_type' => (string)($row['type'] ?? ''),
                    'geom' => (string)($row['geom'] ?? ''),
                    'attributes' => $attrs,
                    'created_at' => (string)($row['created_at'] ?? '')
                ];
            }
        }

        $cables = [];
        $cSql = "SELECT id, name, geom, attributes, length, created_at FROM cables WHERE owner='$ownerEsc' $searchClause ORDER BY id DESC";
        $cRes = $conn->query($cSql);
        if ($cRes) {
            while ($row = $cRes->fetch_assoc()) {
                $attrs = json_decode((string)($row['attributes'] ?? '{}'), true);
                if (!is_array($attrs)) $attrs = [];
                $cables[] = [
                    'kind' => 'cable',
                    'id' => (string)$row['id'],
                    'name' => (string)($row['name'] ?? ''),
                    'asset_type' => 'CABLE',
                    'geom' => (string)($row['geom'] ?? ''),
                    'attributes' => $attrs,
                    'length' => (float)($row['length'] ?? 0),
                    'created_at' => (string)($row['created_at'] ?? '')
                ];
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'assets' => $assets,
                'cables' => $cables
            ]
        ]);
        break;

    case 'POST':
        $kind = strtolower((string)($input['kind'] ?? 'asset'));
        $name = trim((string)($input['name'] ?? ''));
        $type = trim((string)($input['asset_type'] ?? 'ODP'));
        $geom = trim((string)($input['geom'] ?? ''));
        $attributes = $input['attributes'] ?? [];
        if (!is_array($attributes)) $attributes = [];

        if ($name === '') {
            echo json_encode(['success' => false, 'error' => 'Nama wajib diisi']);
            exit;
        }

        if ($kind === 'cable') {
            $length = (float)($input['length'] ?? 0);
            $stmt = $conn->prepare("INSERT INTO cables (name, geom, attributes, length, owner) VALUES (?, ?, ?, ?, ?)");
            $attrsJson = json_encode($attributes);
            $stmt->bind_param('sssds', $name, $geom, $attrsJson, $length, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
        } else {
            $idAsset = trim((string)($input['id_asset'] ?? $name));
            $stmt = $conn->prepare("INSERT INTO assets (id_asset, type, geom, attributes, owner) VALUES (?, ?, ?, ?, ?)");
            $attrsJson = json_encode($attributes);
            $stmt->bind_param('sssss', $idAsset, $type, $geom, $attrsJson, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
        }
        break;

    case 'PUT':
        $kind = strtolower((string)($input['kind'] ?? 'asset'));
        $id = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        $type = trim((string)($input['asset_type'] ?? 'ODP'));
        $geom = trim((string)($input['geom'] ?? ''));
        $attributes = $input['attributes'] ?? [];
        if (!is_array($attributes)) $attributes = [];

        if ($id <= 0 || $name === '') {
            echo json_encode(['success' => false, 'error' => 'Data tidak lengkap']);
            exit;
        }

        if ($kind === 'cable') {
            $length = (float)($input['length'] ?? 0);
            $stmt = $conn->prepare("UPDATE cables SET name=?, geom=?, attributes=?, length=? WHERE id=? AND owner=?");
            $attrsJson = json_encode($attributes);
            $stmt->bind_param('sssdis', $name, $geom, $attrsJson, $length, $id, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
        } else {
            $idAsset = trim((string)($input['id_asset'] ?? $name));
            $stmt = $conn->prepare("UPDATE assets SET id_asset=?, type=?, geom=?, attributes=? WHERE id=? AND owner=?");
            $attrsJson = json_encode($attributes);
            $stmt->bind_param('ssssis', $idAsset, $type, $geom, $attrsJson, $id, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
        }
        break;

    case 'DELETE':
        $kind = strtolower((string)($input['kind'] ?? 'asset'));
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID tidak valid']);
            exit;
        }

        if ($kind === 'cable') {
            $stmt = $conn->prepare("DELETE FROM cables WHERE id=? AND owner=?");
            $stmt->bind_param('is', $id, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
        } else {
            $stmt = $conn->prepare("DELETE FROM assets WHERE id=? AND owner=?");
            $stmt->bind_param('is', $id, $pemilik);
            $ok = $stmt->execute();
            echo json_encode(['success' => $ok]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Method tidak didukung']);
}
