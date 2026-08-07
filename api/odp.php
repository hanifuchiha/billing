<?php
// api/odp.php - ODP (Optical Distribution Point) API, full parity with crm/billing/odp.php +
// proses/addodp.php / editodp.php / deleteodp.php.
//
// Columns actually used by the web UI (several added via defensive runtime ALTER TABLE, same
// pattern as api/tiket_manager.php's tm_ensure_column()):
//   id, NAME, KODE, PORT, PEMILIK, AREA, TIKOR, BRAND, Hirarki, splitter, hirarki_parent, FOTO
// Plus the odp_server relation table (multi-"Product" assignment per ODP: one ODP can be
// attached to several PEMILIK+AREA "products").
//
// Scoping: odp has no server_id FK, only PEMILIK/AREA columns, so access is scoped via
// api_allowed_pemilik_list()/api_pemilik_in_sql() (PEMILIK IN (...)), mirroring how
// crm/billing/odp.php scopes its own queries by pemilik IN (...) / AREA IN ($area_list).
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

$ctx = api_resolve_owner($conn, $pemilik);
if (!$ctx) {
    api_json(['success' => false, 'error' => 'User tidak ditemukan'], 401);
}
api_require_module_enabled($conn, $pemilik, 'odp');
$allowedPemilik = api_allowed_pemilik_list($conn, $ctx);
$pemilikInSql = api_pemilik_in_sql($conn, $allowedPemilik);

// ---------------------------------------------------------------------------
// Defensive schema migration (same pattern as api/tiket_manager.php::tm_ensure_column()).
// ---------------------------------------------------------------------------
function odp_ensure_column($conn, $column_name, $definition_sql) {
    $safe_col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column_name);
    if ($safe_col === '') {
        return;
    }
    $check = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE '" . mysqli_real_escape_string($conn, $safe_col) . "'");
    if ($check && mysqli_num_rows($check) > 0) {
        return;
    }
    mysqli_query($conn, "ALTER TABLE odp ADD COLUMN " . $definition_sql);
}
odp_ensure_column($conn, 'Hirarki', "Hirarki VARCHAR(255) DEFAULT NULL");
odp_ensure_column($conn, 'splitter', "splitter ENUM('1:2', '1:4', '1:8', '1:16', '1:32') DEFAULT NULL");
odp_ensure_column($conn, 'hirarki_parent', "hirarki_parent VARCHAR(255) DEFAULT NULL");
odp_ensure_column($conn, 'FOTO', "FOTO VARCHAR(255) DEFAULT NULL");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS odp_server (
    id INT AUTO_INCREMENT PRIMARY KEY,
    odp_kode VARCHAR(255) NOT NULL,
    pemilik VARCHAR(255) NOT NULL,
    area VARCHAR(255) NOT NULL,
    KEY idx_odp_kode (odp_kode),
    KEY idx_pemilik_area (pemilik, area)
)");

$VALID_HIRARKI = ['ODC', 'ODP', 'ODP-RASIO', 'ODP-JUMPER'];
$VALID_SPLITTER = ['1:2', '1:4', '1:8', '1:16', '1:32'];
// Splitter -> port capacity. NOTE: the original proses/addodp.php's switch statement was
// missing the '1:4' case, which silently fell through to a capacity of 0 - meaning ANY ODC
// provisioned with a 1:4 splitter could never accept a child ODP (always reported "FULL").
// Fixed here.
$ODP_CAPACITY = ['1:2' => 2, '1:4' => 4, '1:8' => 8, '1:16' => 16, '1:32' => 32];

/** Products (odp_server rows) currently attached to an ODP kode, brand-enriched via server. */
function odp_products_for($conn, $kode) {
    $stmt = $conn->prepare("SELECT os.pemilik, os.area, s.BRAND FROM odp_server os
        LEFT JOIN server s ON os.pemilik = s.PEMILIK AND os.area = s.AREA
        WHERE os.odp_kode = ?");
    api_bind($stmt, 's', [$kode]);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = ['pemilik' => $row['pemilik'], 'area' => $row['area'], 'brand' => $row['BRAND'] ?? ''];
    }
    return $out;
}

/**
 * Parse the "products" (multi-Product assignment) input. Accepts, in order of preference:
 *  - products: [{pemilik, area}, ...]  (or product_list, same shape)
 *  - products: ["areaA", "areaB", ...] (plain area strings; pemilik defaults to the caller)
 *  - top-level pemilik + area (legacy single-product shape, back-compat with the old 4-column API)
 * Returns a de-duplicated, trimmed list of ['pemilik' => ..., 'area' => ...] pairs.
 */
function odp_parse_products(array $input, $callerPemilik) {
    $pairs = [];
    $raw = null;
    if (isset($input['products']) && is_array($input['products'])) {
        $raw = $input['products'];
    } elseif (isset($input['product_list']) && is_array($input['product_list'])) {
        $raw = $input['product_list'];
    }
    if (is_array($raw)) {
        foreach ($raw as $item) {
            if (is_array($item)) {
                $itemPemilik = trim((string)($item['pemilik'] ?? $item['PEMILIK'] ?? ''));
                $itemArea = trim((string)($item['area'] ?? $item['AREA'] ?? ''));
            } else {
                $itemPemilik = '';
                $itemArea = trim((string)$item);
            }
            if ($itemPemilik === '') {
                $itemPemilik = (string)$callerPemilik;
            }
            if ($itemArea === '') {
                continue;
            }
            $pairs[$itemPemilik . '|' . $itemArea] = ['pemilik' => $itemPemilik, 'area' => $itemArea];
        }
    }
    if (empty($pairs)) {
        $pemilikSingle = trim((string)($input['pemilik'] ?? $callerPemilik));
        $areaSingle = trim((string)($input['area'] ?? ''));
        if ($areaSingle !== '') {
            $pairs[$pemilikSingle . '|' . $areaSingle] = ['pemilik' => $pemilikSingle, 'area' => $areaSingle];
        }
    }
    return array_values($pairs);
}

/** Validate each product pair: PEMILIK must be one this caller owns AND a matching server row must exist. */
function odp_validate_products($conn, array $pairs, array $allowedPemilik) {
    foreach ($pairs as $p) {
        if (!in_array($p['pemilik'], $allowedPemilik, true)) {
            return "Server Area tidak diizinkan untuk akun ini: {$p['pemilik']} / {$p['area']}";
        }
        $stmt = $conn->prepare("SELECT id FROM server WHERE PEMILIK = ? AND AREA = ? LIMIT 1");
        api_bind($stmt, 'ss', [$p['pemilik'], $p['area']]);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return "Server Area tidak ditemukan: {$p['pemilik']} / {$p['area']}";
        }
    }
    return null;
}

switch ($method) {

    // GET: list (with real filters) or single-row detail via ?id=. Each row is enriched with
    // its odp_server "products" list, mirroring the Product pills shown on the web page.
    case 'GET':
        if (!empty($_GET['id'])) {
            $id = (int)$_GET['id'];
            $stmt = $conn->prepare("SELECT * FROM odp WHERE id = ? AND PEMILIK IN ($pemilikInSql) LIMIT 1");
            api_bind($stmt, 'i', [$id]);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                api_json(['success' => false, 'error' => 'ODP tidak ditemukan atau tidak diizinkan'], 404);
            }
            $row['products'] = odp_products_for($conn, $row['KODE']);
            api_json(['success' => true, 'data' => $row]);
        }

        $where = ["PEMILIK IN ($pemilikInSql)"];
        $types = '';
        $params = [];

        // Filter hirarki
        $hirarki = trim($_GET['hirarki'] ?? '');
        if ($hirarki !== '' && $hirarki !== 'Semua') {
            $where[] = "COALESCE(Hirarki, '') = ?";
            $types .= 's';
            $params[] = $hirarki;
        }

        // Filter area
        $area = trim($_GET['area'] ?? '');
        if ($area !== '' && $area !== 'Semua') {
            $where[] = "COALESCE(AREA, '') = ?";
            $types .= 's';
            $params[] = $area;
        }

        // Filter splitter (real column - unlike the old PRODUCT filter this one actually exists)
        $splitter = trim($_GET['splitter'] ?? '');
        if ($splitter !== '' && $splitter !== 'Semua') {
            $where[] = "COALESCE(splitter, '') = ?";
            $types .= 's';
            $params[] = $splitter;
        }

        // Search filter (kode, nama)
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = "(COALESCE(KODE, '') LIKE ? OR COALESCE(NAME, '') LIKE ?)";
            $types .= 'ss';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT * FROM odp WHERE " . implode(" AND ", $where) . " ORDER BY id DESC";
        $stmt = $conn->prepare($sql);
        api_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        $odp = [];
        while ($row = $result->fetch_assoc()) {
            $row['products'] = odp_products_for($conn, $row['KODE']);
            $odp[] = $row;
        }
        api_json(['success' => true, 'data' => $odp, 'total' => count($odp)]);
        break;

    // POST: create a new ODP. Requires kode/name/tikor plus at least one Product
    // (pemilik+area pair), mirroring proses/addodp.php's product_pairs requirement.
    case 'POST':
        $kode = trim((string)($input['kode'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $tikor = trim((string)($input['tikor'] ?? ($input['coordinates'] ?? '')));
        if ($kode === '' || $name === '' || $tikor === '') {
            api_json(['success' => false, 'error' => 'Data tidak lengkap: kode, name, dan tikor/coordinates wajib diisi']);
        }

        $hirarki = strtoupper(trim((string)($input['hirarki'] ?? '')));
        if ($hirarki !== '' && !in_array($hirarki, $VALID_HIRARKI, true)) {
            api_json(['success' => false, 'error' => 'Hirarki tidak valid. Pilihan: ' . implode(', ', $VALID_HIRARKI)]);
        }

        $splitter = trim((string)($input['splitter'] ?? ''));
        if ($splitter !== '' && !in_array($splitter, $VALID_SPLITTER, true)) {
            api_json(['success' => false, 'error' => 'Splitter tidak valid. Pilihan: ' . implode(', ', $VALID_SPLITTER)]);
        }

        $hirarki_parent = trim((string)($input['hirarki_parent'] ?? ''));

        $productPairs = odp_parse_products($input, $pemilik);
        if (empty($productPairs)) {
            api_json(['success' => false, 'error' => 'Pilih minimal 1 Server Area (products: [{pemilik, area}, ...])']);
        }
        $productErr = odp_validate_products($conn, $productPairs, $allowedPemilik);
        if ($productErr) {
            api_json(['success' => false, 'error' => $productErr]);
        }

        // PEMILIK/AREA columns = first product pair, for compatibility with legacy single-product code.
        $mainPemilik = $productPairs[0]['pemilik'];
        $mainArea = $productPairs[0]['area'];

        // Validation for hirarki=ODP: must pick an ODC parent with spare capacity.
        if ($hirarki === 'ODP') {
            if ($hirarki_parent === '') {
                api_json(['success' => false, 'error' => 'Untuk hirarki ODP, harus pilih hirarki_parent (ODC).']);
            }
            // Scoped to this caller's own ODP data - the original web query had no PEMILIK
            // restriction at all, letting any account reference another tenant's ODC code.
            $stmt = $conn->prepare("SELECT splitter FROM odp WHERE KODE = ? AND Hirarki = 'ODC' AND PEMILIK IN ($pemilikInSql) LIMIT 1");
            api_bind($stmt, 's', [$hirarki_parent]);
            $stmt->execute();
            $odc = $stmt->get_result()->fetch_assoc();
            if (!$odc) {
                api_json(['success' => false, 'error' => 'ODC parent tidak ditemukan, bukan ODC, atau tidak diizinkan.']);
            }
            $kapasitas = $ODP_CAPACITY[$odc['splitter']] ?? 0;
            $stmt = $conn->prepare("SELECT COUNT(*) AS jumlah FROM odp WHERE hirarki_parent = ? AND Hirarki = 'ODP'");
            api_bind($stmt, 's', [$hirarki_parent]);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['jumlah'] ?? 0);
            if ($count >= $kapasitas) {
                api_json(['success' => false, 'error' => "ODC $hirarki_parent dengan splitter {$odc['splitter']} sudah FULL ($count/$kapasitas)."]);
            }
        }

        // BRAND derived from the main product's server row (matches proses/addodp.php).
        $brand = '';
        $stmt = $conn->prepare("SELECT BRAND FROM server WHERE PEMILIK = ? AND AREA = ? LIMIT 1");
        api_bind($stmt, 'ss', [$mainPemilik, $mainArea]);
        $stmt->execute();
        $srow = $stmt->get_result()->fetch_assoc();
        if ($srow) {
            $brand = (string)$srow['BRAND'];
        }

        $foto = trim((string)($input['foto'] ?? '')); // optional URL passthrough - API has no file upload
        $port = '0'; // PORT is NOT NULL with no default; the web UI always inserts 0 too.

        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO odp (NAME, KODE, PORT, PEMILIK, AREA, TIKOR, BRAND, Hirarki, splitter, hirarki_parent, FOTO)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        api_bind($stmt, 'sssssssssss', [$name, $kode, $port, $mainPemilik, $mainArea, $tikor, $brand, $hirarki, $splitter, $hirarki_parent, $foto]);
        $ok = $stmt->execute();
        if (!$ok) {
            $conn->rollback();
            api_json(['success' => false, 'error' => 'Gagal menyimpan data: ' . $stmt->error]);
        }
        $newId = $conn->insert_id;

        foreach ($productPairs as $pair) {
            $relStmt = $conn->prepare("INSERT INTO odp_server (odp_kode, pemilik, area) VALUES (?, ?, ?)");
            api_bind($relStmt, 'sss', [$kode, $pair['pemilik'], $pair['area']]);
            $relStmt->execute();
        }
        $conn->commit();

        $row = mysqli_query($conn, "SELECT * FROM odp WHERE id = $newId")->fetch_assoc();
        $row['products'] = odp_products_for($conn, $kode);
        api_json(['success' => true, 'id' => $newId, 'data' => $row]);
        break;

    // PUT: update an ODP owned by this account. Fields omitted from the request body keep
    // their existing value. On KODE change, cascades pelanggan.ODP the same way
    // proses/editodp.php does. Products (if provided) are synced into odp_server via
    // DELETE+re-INSERT, matching proses/editodp.php exactly.
    case 'PUT':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        $stmt = $conn->prepare("SELECT * FROM odp WHERE id = ? AND PEMILIK IN ($pemilikInSql) LIMIT 1");
        api_bind($stmt, 'i', [$id]);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        if (!$existing) {
            api_json(['success' => false, 'error' => 'ODP tidak ditemukan atau tidak diizinkan'], 404);
        }

        $kode = array_key_exists('kode', $input) ? trim((string)$input['kode']) : (string)$existing['KODE'];
        $name = array_key_exists('name', $input) ? trim((string)$input['name']) : (string)$existing['NAME'];
        $tikor = trim((string)($input['tikor'] ?? ($input['coordinates'] ?? $existing['TIKOR'])));
        if ($kode === '' || $name === '' || $tikor === '') {
            api_json(['success' => false, 'error' => 'Data tidak lengkap: kode, name, dan tikor/coordinates wajib diisi']);
        }

        $hirarki = array_key_exists('hirarki', $input) ? strtoupper(trim((string)$input['hirarki'])) : (string)($existing['Hirarki'] ?? '');
        if ($hirarki !== '' && !in_array($hirarki, $VALID_HIRARKI, true)) {
            api_json(['success' => false, 'error' => 'Hirarki tidak valid. Pilihan: ' . implode(', ', $VALID_HIRARKI)]);
        }
        $splitter = array_key_exists('splitter', $input) ? trim((string)$input['splitter']) : (string)($existing['splitter'] ?? '');
        if ($splitter !== '' && !in_array($splitter, $VALID_SPLITTER, true)) {
            api_json(['success' => false, 'error' => 'Splitter tidak valid. Pilihan: ' . implode(', ', $VALID_SPLITTER)]);
        }
        $hirarki_parent = array_key_exists('hirarki_parent', $input) ? trim((string)$input['hirarki_parent']) : (string)($existing['hirarki_parent'] ?? '');

        // Products: use the request's list if provided, otherwise keep what's already assigned.
        if (array_key_exists('products', $input) || array_key_exists('product_list', $input)) {
            $productPairs = odp_parse_products($input, $pemilik);
            if (empty($productPairs)) {
                api_json(['success' => false, 'error' => 'Minimal 1 Server Area harus dipilih']);
            }
            $productErr = odp_validate_products($conn, $productPairs, $allowedPemilik);
            if ($productErr) {
                api_json(['success' => false, 'error' => $productErr]);
            }
        } else {
            $productPairs = odp_products_for($conn, $existing['KODE']);
            if (empty($productPairs)) {
                $productPairs = [['pemilik' => $existing['PEMILIK'], 'area' => $existing['AREA']]];
            }
        }

        $mainPemilik = $productPairs[0]['pemilik'];
        $mainArea = $productPairs[0]['area'];

        // BRAND lookup by PEMILIK only, matching proses/editodp.php exactly (it doesn't filter by AREA).
        $brand = '';
        $stmt = $conn->prepare("SELECT BRAND FROM server WHERE PEMILIK = ? LIMIT 1");
        api_bind($stmt, 's', [$mainPemilik]);
        $stmt->execute();
        $srow = $stmt->get_result()->fetch_assoc();
        if ($srow) {
            $brand = (string)$srow['BRAND'];
        }

        $old_kode = (string)$existing['KODE'];

        $conn->begin_transaction();
        $stmt = $conn->prepare("UPDATE odp SET KODE=?, NAME=?, TIKOR=?, PEMILIK=?, AREA=?, BRAND=?, Hirarki=?, splitter=?, hirarki_parent=? WHERE id=?");
        api_bind($stmt, 'sssssssssi', [$kode, $name, $tikor, $mainPemilik, $mainArea, $brand, $hirarki, $splitter, $hirarki_parent, $id]);
        $ok = $stmt->execute();
        if (!$ok) {
            $conn->rollback();
            api_json(['success' => false, 'error' => 'Gagal update: ' . $stmt->error]);
        }

        // Cascade: if KODE changed, re-point every pelanggan that referenced the old KODE.
        if ($old_kode !== '' && $old_kode !== $kode) {
            $cascStmt = $conn->prepare("UPDATE pelanggan SET ODP = ? WHERE ODP = ?");
            api_bind($cascStmt, 'ss', [$kode, $old_kode]);
            $cascStmt->execute();
        }

        // Sync odp_server: delete rows under the old kode (and the new kode too, if it changed),
        // then re-insert the current product list - identical to proses/editodp.php.
        $delStmt = $conn->prepare("DELETE FROM odp_server WHERE odp_kode = ?");
        api_bind($delStmt, 's', [$old_kode !== '' ? $old_kode : $kode]);
        $delStmt->execute();
        if ($old_kode !== $kode) {
            $delStmt2 = $conn->prepare("DELETE FROM odp_server WHERE odp_kode = ?");
            api_bind($delStmt2, 's', [$kode]);
            $delStmt2->execute();
        }
        foreach ($productPairs as $pair) {
            $insStmt = $conn->prepare("INSERT INTO odp_server (odp_kode, pemilik, area) VALUES (?, ?, ?)");
            api_bind($insStmt, 'sss', [$kode, $pair['pemilik'], $pair['area']]);
            $insStmt->execute();
        }

        if (array_key_exists('foto', $input)) {
            $foto = trim((string)$input['foto']);
            $fotoStmt = $conn->prepare("UPDATE odp SET FOTO = ? WHERE id = ?");
            api_bind($fotoStmt, 'si', [$foto, $id]);
            $fotoStmt->execute();
        }
        $conn->commit();

        $row = mysqli_query($conn, "SELECT * FROM odp WHERE id = $id")->fetch_assoc();
        $row['products'] = odp_products_for($conn, $kode);
        api_json(['success' => true, 'data' => $row]);
        break;

    // DELETE: remove an ODP, guarded exactly like proses/deleteodp.php - refuse if it still has
    // child ODPs (hirarki_parent pointing at it) or any pelanggan still referencing it. Also
    // cleans up its odp_server rows (a gap in the original web flow - it deleted the odp row but
    // left dangling odp_server relation rows behind) and removes the FOTO file if present.
    case 'DELETE':
        $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
        if (!$id) {
            api_json(['success' => false, 'error' => 'ID diperlukan']);
        }
        $stmt = $conn->prepare("SELECT * FROM odp WHERE id = ? AND PEMILIK IN ($pemilikInSql) LIMIT 1");
        api_bind($stmt, 'i', [$id]);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            api_json(['success' => false, 'error' => 'ODP tidak ditemukan atau tidak diizinkan'], 404);
        }
        $kode = (string)$row['KODE'];

        // Guard: still has child ODP/ODP-RASIO/ODP-JUMPER hanging off it (covers the ODC case too).
        $stmt = $conn->prepare("SELECT COUNT(*) AS jumlah FROM odp WHERE hirarki_parent = ? AND id <> ?");
        api_bind($stmt, 'si', [$kode, $id]);
        $stmt->execute();
        $childCount = (int)($stmt->get_result()->fetch_assoc()['jumlah'] ?? 0);
        if ($childCount > 0) {
            api_json(['success' => false, 'error' => 'ODP masih memiliki turunan (ODP/ODC) terkait, tidak dapat dihapus.']);
        }

        // Guard: still referenced by a pelanggan.
        $stmt = $conn->prepare("SELECT COUNT(*) AS jumlah FROM pelanggan WHERE ODP = ?");
        api_bind($stmt, 's', [$kode]);
        $stmt->execute();
        $plgCount = (int)($stmt->get_result()->fetch_assoc()['jumlah'] ?? 0);
        if ($plgCount > 0) {
            api_json(['success' => false, 'error' => 'ODP masih digunakan oleh pelanggan, tidak dapat dihapus.']);
        }

        // Remove the FOTO file, if any (matches proses/deleteodp.php's cleanup).
        $foto_path = __DIR__ . '/../../../dokumen/odp/odp_' . $id . '.jpg';
        if (file_exists($foto_path)) {
            @unlink($foto_path);
        }

        $conn->begin_transaction();
        $stmt = $conn->prepare("DELETE FROM odp WHERE id = ? AND PEMILIK IN ($pemilikInSql)");
        api_bind($stmt, 'i', [$id]);
        $ok = $stmt->execute();
        if (!$ok) {
            $conn->rollback();
            api_json(['success' => false, 'error' => 'Gagal menghapus ODP: ' . $stmt->error]);
        }
        $delRel = $conn->prepare("DELETE FROM odp_server WHERE odp_kode = ?");
        api_bind($delRel, 's', [$kode]);
        $delRel->execute();
        $conn->commit();

        api_json(['success' => true]);
        break;

    default:
        api_json(['success' => false, 'error' => 'Method tidak didukung'], 405);
}
