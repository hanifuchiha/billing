<?php

require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Insfrastruktur_maps', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Infrastructure Maps.</div></div>';
        require 'footer.php';
        exit;
    }
}


$ceknama = isset($ceknama) ? (string)$ceknama : 'unknown';
$AKSES = isset($AKSES) ? (string)$AKSES : '';
$current_user_id = isset($current_user_id) ? (int)$current_user_id : 0;
$area_list = isset($area_list) && $area_list !== '' ? $area_list : "''";

$runtime_errors = [];
$runSchemaQuery = function($sql) use ($conn, &$runtime_errors) {
  try {
    if (!$conn->query($sql)) {
      $runtime_errors[] = 'DB schema error: ' . $conn->error;
    }
  } catch (Throwable $e) {
    $runtime_errors[] = 'DB schema exception: ' . $e->getMessage();
  }
};

// Create tables if not exist
$runSchemaQuery("CREATE TABLE IF NOT EXISTS cables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255),
  geom TEXT,
  attributes TEXT,
  length FLOAT,
  owner VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$runSchemaQuery("CREATE TABLE IF NOT EXISTS assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_asset VARCHAR(255),
  type VARCHAR(255),
  geom TEXT,
  attributes TEXT,
  owner VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Add owner column with compatibility for older MySQL/MariaDB
$ensureOwnerColumn = function($tableName) use ($conn, &$runtime_errors) {
  try {
    $checkSql = "SHOW COLUMNS FROM `" . $tableName . "` LIKE 'owner'";
    $checkRes = $conn->query($checkSql);
    if (!$checkRes) {
      $runtime_errors[] = 'DB schema check failed (' . $tableName . '): ' . $conn->error;
      return;
    }

    if ($checkRes->num_rows === 0) {
      $alterSql = "ALTER TABLE `" . $tableName . "` ADD COLUMN `owner` VARCHAR(255) DEFAULT ''";
      if (!$conn->query($alterSql)) {
        $runtime_errors[] = 'DB schema alter failed (' . $tableName . '): ' . $conn->error;
      }
    }
  } catch (Throwable $e) {
    $runtime_errors[] = 'DB schema ensure owner failed (' . $tableName . '): ' . $e->getMessage();
  }
};

$ensureOwnerColumn('cables');
$ensureOwnerColumn('assets');





// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($ceknama)) $ceknama = 'unknown';
  if (!$conn) {
    echo "<script>alert('DB connection failed');</script>";
    exit;
  }
  if ($_POST['action'] === 'save_cable') {
    $geom = $_POST['geom'];
    $attributes = json_decode($_POST['attributes'], true);
    $length = $_POST['length'];
    $name = $_POST['name'];

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
      $uploadDir = '../../dokumen/infra/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
      $fileName = uniqid() . '_' . basename($_FILES['photo']['name']);
      $uploadFile = $uploadDir . $fileName;
      if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadFile)) {
        $attributes['photo'] = $uploadFile;
      }
    }

    $stmt = $conn->prepare("INSERT INTO cables (name, geom, attributes, length, owner) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssds", $name, $geom, json_encode($attributes), $length, $ceknama);
    if ($stmt->execute()) {
      // Success, page will reload with updated data
    } else {
      echo "<script>alert('Save failed: " . addslashes($stmt->error) . "');</script>";
    }
   
  } elseif ($_POST['action'] === 'save_asset') {
    $geom = $_POST['geom'];
    $attributes = json_decode($_POST['attributes'], true);
    $type = $_POST['type'];

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
      $uploadDir = '../../dokumen/infra/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
      $fileName = uniqid() . '_' . basename($_FILES['photo']['name']);
      $uploadFile = $uploadDir . $fileName;
      if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadFile)) {
        $attributes['photo'] = $uploadFile;
      }
    }

    $stmt = $conn->prepare("INSERT INTO assets (id_asset, type, geom, attributes, owner) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $attributes['id_asset'], $type, $geom, json_encode($attributes), $ceknama);
    if ($stmt->execute()) {
      // Success
    } else {
      echo "<script>alert('Save failed: " . addslashes($stmt->error) . "');</script>";
    }
   
  } elseif ($_POST['action'] === 'update_cable') {
    $id = $_POST['id'];
    $geom = $_POST['geom'];
    $stmt = $conn->prepare("UPDATE cables SET geom = ? WHERE id = ? AND owner = ?");
    $stmt->bind_param("sis", $geom, $id, $ceknama);
    if ($stmt->execute()) {
      // Success
    } else {
      echo "<script>alert('Update failed: " . addslashes($stmt->error) . "');</script>";
    }
  } elseif ($_POST['action'] === 'update_asset_attr') {
    $id = $_POST['id'];
    $attributes = json_decode($_POST['attributes'], true);

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
      $uploadDir = '../../dokumen/infra/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
      $fileName = uniqid() . '_' . basename($_FILES['photo']['name']);
      $uploadFile = $uploadDir . $fileName;
      if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadFile)) {
        $attributes['photo'] = $uploadFile;
      }
    }

    $stmt = $conn->prepare("UPDATE assets SET attributes = ? WHERE id = ? AND owner = ?");
    $stmt->bind_param("sis", json_encode($attributes), $id, $ceknama);
    if ($stmt->execute()) {
      // Success
    } else {
      echo "<script>alert('Update failed: " . addslashes($stmt->error) . "');</script>";
    }
  } elseif ($_POST['action'] === 'delete_asset') {
    $id = $_POST['id'];
    $stmt = $conn->prepare("DELETE FROM assets WHERE id = ? AND owner = ?");
    $stmt->bind_param("is", $id, $ceknama);
    if ($stmt->execute()) {
      if (isset($_POST['kode'])) {
        $kode = $_POST['kode'];
        $conn->query("DELETE FROM odp WHERE KODE = '" . $conn->real_escape_string($kode) . "'");
      }
    } else {
      echo "<script>alert('Delete failed: " . addslashes($stmt->error) . "');</script>";
    }
  } elseif ($_POST['action'] === 'delete_cable') {
    $id = $_POST['id'];
    $stmt = $conn->prepare("DELETE FROM cables WHERE id = ? AND owner = ?");
    $stmt->bind_param("is", $id, $ceknama);
    if ($stmt->execute()) {
      // Success
    } else {
      echo "<script>alert('Delete failed: " . addslashes($stmt->error) . "');</script>";
    }
  } elseif ($_POST['action'] === 'update_cable_attr') {
    $id = $_POST['id'];
    $attributes = json_decode($_POST['attributes'], true);

    // Handle photo upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
      $uploadDir = '../../dokumen/infra/';
      if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
      $fileName = uniqid() . '_' . basename($_FILES['photo']['name']);
      $uploadFile = $uploadDir . $fileName;
      if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadFile)) {
        $attributes['photo'] = $uploadFile;
      }
    }

    $stmt = $conn->prepare("UPDATE cables SET attributes = ? WHERE id = ? AND owner = ?");
    $stmt->bind_param("sis", json_encode($attributes), $id, $ceknama);
    if ($stmt->execute()) {
      // Success
    } else {
      echo "<script>alert('Update failed: " . addslashes($stmt->error) . "');</script>";
    }
  } elseif ($_POST['action'] === 'update_odp') {
    $kode = $_POST['kode'];
    $name = $_POST['name'];
    $tikor = $_POST['tikor'];
    $server = $_POST['server'];
    $area = $_POST['area'];
    $stmt = $conn->prepare("UPDATE odp SET NAME = ?, TIKOR = ?, PEMILIK = ?, AREA = ? WHERE KODE = ?");
    $stmt->bind_param("sssss", $name, $tikor, $server, $area, $kode);
    if ($stmt->execute()) {
      // Parse TIKOR as lat,lng
      $coords = explode(',', $tikor);
      if (count($coords) == 2) {
        $lat = trim($coords[0]);
        $lng = trim($coords[1]);
        $geom = json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]]);
      } else {
        $geom = null;
      }
      // Get brand from server
      $brand_query = $conn->prepare("SELECT BRAND FROM server WHERE PEMILIK = ? LIMIT 1");
      $brand_query->bind_param("s", $server);
      $brand_query->execute();
      $brand_result = $brand_query->get_result();
      $brand = '';
      if ($brand_row = $brand_result->fetch_assoc()) {
        $brand = $brand_row['BRAND'];
      }
      // Get Hirarki from odp
      $hirarki_query = $conn->prepare("SELECT Hirarki FROM odp WHERE KODE = ? LIMIT 1");
      $hirarki_query->bind_param("s", $kode);
      $hirarki_query->execute();
      $hirarki_result = $hirarki_query->get_result();
      $hirarki = '';
      if ($hirarki_row = $hirarki_result->fetch_assoc()) {
        $hirarki = $hirarki_row['Hirarki'];
      }
      // Determine icon based on Hirarki
      $icon = ($hirarki == 'ODC') ? 'odc' : 'odp';
      // Also update assets
      $attributes = json_encode([
        'id_asset' => $kode,
        'type' => 'ODP',
        'name' => $name,
        'area' => $area,
        'brand' => $brand,
        'pemilik' => $server,
        'color' => '#ff0000',
        'icon' => $icon,
        'hirarki' => $hirarki
      ]);
      $update_sql = "UPDATE assets SET attributes = '" . $conn->real_escape_string($attributes) . "'";
      if ($geom) {
        $update_sql .= ", geom = '" . $conn->real_escape_string($geom) . "'";
      }
      $update_sql .= " WHERE id_asset = '" . $conn->real_escape_string($kode) . "' AND owner = '" . $conn->real_escape_string($ceknama) . "'";
      $conn->query($update_sql);
    } else {
      echo "<script>alert('Update failed: " . addslashes($stmt->error) . "');</script>";
    }
  } elseif ($_POST['action'] === 'sync_odp') {
    // Sync ODP data from odp table to assets table
    if ($AKSES == 'ASSISTANT') {
      $sql = "SELECT * FROM odp WHERE AREA IN ($area_list)";
    } else {
      $sql = "SELECT * FROM odp WHERE pemilik IN (SELECT PEMILIK FROM server WHERE user_id = $current_user_id)";
    }
    $result = mysqli_query($conn, $sql);
    if (!$result) {
      echo "<script>alert('Query failed: " . addslashes(mysqli_error($conn)) . "');</script>";
      exit;
    }
    $synced = 0;
    while ($row = mysqli_fetch_assoc($result)) {
      // Check if already exists
      $check_sql = "SELECT id FROM assets WHERE id_asset = '" . mysqli_real_escape_string($conn, $row['KODE']) . "' AND owner = '" . mysqli_real_escape_string($conn, $ceknama) . "'";
      $check_result = mysqli_query($conn, $check_sql);
      if (mysqli_num_rows($check_result) == 0) {
        // Parse TIKOR as lat,lng
        $coords = explode(',', $row['TIKOR']);
        if (count($coords) == 2) {
          $lat = trim($coords[0]);
          $lng = trim($coords[1]);
          $geom = json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]]);
          // Determine icon based on Hirarki
          $icon = ($row['Hirarki'] == 'ODC') ? 'odc' : 'odp';
          $attributes = json_encode([
            'id_asset' => $row['KODE'],
            'type' => 'ODP',
            'name' => $row['NAME'],
            'area' => $row['AREA'],
            'brand' => $row['BRAND'],
            'pemilik' => $row['PEMILIK'],
            'color' => '#ff0000',
            'icon' => $icon,
            'hirarki' => $row['Hirarki'],
            'photo' => isset($row['FOTO']) && !empty($row['FOTO']) ? '/dokumen/odp/' . basename($row['FOTO']) : null
          ]);
          $insert_sql = "INSERT INTO assets (id_asset, type, geom, attributes, owner) VALUES ('" . mysqli_real_escape_string($conn, $row['KODE']) . "', 'ODP', '" . mysqli_real_escape_string($conn, $geom) . "', '" . mysqli_real_escape_string($conn, $attributes) . "', '" . mysqli_real_escape_string($conn, $ceknama) . "')";
          if (mysqli_query($conn, $insert_sql)) {
            $synced++;
          } else {
            echo "<script>alert('Insert failed: " . addslashes(mysqli_error($conn)) . "');</script>";
          }
        }
      } else {
        // Update existing
        $coords = explode(',', $row['TIKOR']);
        if (count($coords) == 2) {
          $lat = trim($coords[0]);
          $lng = trim($coords[1]);
          $geom = json_encode(['type' => 'Point', 'coordinates' => [$lng, $lat]]);
          // Determine icon based on Hirarki
          $icon = ($row['Hirarki'] == 'ODC') ? 'odc' : 'odp';
          $attributes = json_encode([
            'id_asset' => $row['KODE'],
            'type' => 'ODP',
            'name' => $row['NAME'],
            'area' => $row['AREA'],
            'brand' => $row['BRAND'],
            'pemilik' => $row['PEMILIK'],
            'color' => '#ff0000',
            'icon' => $icon,
            'hirarki' => $row['Hirarki'],
            'photo' => isset($row['FOTO']) && !empty($row['FOTO']) ? '/dokumen/odp/' . basename($row['FOTO']) : null
          ]);
          $update_sql = "UPDATE assets SET geom = '" . mysqli_real_escape_string($conn, $geom) . "', attributes = '" . mysqli_real_escape_string($conn, $attributes) . "' WHERE id_asset = '" . mysqli_real_escape_string($conn, $row['KODE']) . "' AND owner = '" . mysqli_real_escape_string($conn, $ceknama) . "'";
          if (mysqli_query($conn, $update_sql)) {
            $synced++;
          } else {
            echo "<script>alert('Update failed: " . addslashes(mysqli_error($conn)) . "');</script>";
          }
        }
      }
    }
    echo "<script>alert('Synced $synced ODP records');</script>";
  }
}

// Load data
$cables = [];
$result = $conn->query("SELECT * FROM cables WHERE owner = '" . $conn->real_escape_string($ceknama) . "'");
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $cables[] = $row;
  }
} else {
  $runtime_errors[] = 'Load cables failed: ' . $conn->error;
}

$assets = [];
$result = $conn->query("SELECT * FROM assets WHERE owner = '" . $conn->real_escape_string($ceknama) . "'");
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $assets[] = $row;
  }
} else {
  $runtime_errors[] = 'Load assets failed: ' . $conn->error;
}

// Cek apakah ada data sumber ODP untuk user ini, dipakai untuk menentukan perlu tidaknya auto-sync
if ($AKSES == 'ASSISTANT') {
  $odpCountSql = "SELECT COUNT(*) AS cnt FROM odp WHERE AREA IN ($area_list)";
} else {
  $odpCountSql = "SELECT COUNT(*) AS cnt FROM odp WHERE pemilik IN (SELECT PEMILIK FROM server WHERE user_id = $current_user_id)";
}
$odpCountResult = $conn->query($odpCountSql);
$odp_source_count = 0;
if ($odpCountResult) {
  $odp_source_count = (int)($odpCountResult->fetch_assoc()['cnt'] ?? 0);
} else {
  $runtime_errors[] = 'Load odp count failed: ' . $conn->error;
}
$has_odp_source_data = $odp_source_count > 0;

$customers = [];
$sql = "SELECT p.IDPEL, p.NAMA, p.ALAMAT, p.TIKOR, p.PAKET, p.ODP, p.TANGGALPASANG,
        CASE WHEN t.IDPEL IS NOT NULL THEN 'online' ELSE 'offline' END AS status
        FROM pelanggan p
        LEFT JOIN (SELECT DISTINCT IDPEL FROM transaksi WHERE STATUS = 'BERHASIL' AND MONTH(waktu) = MONTH(CURDATE()) AND YEAR(waktu) = YEAR(CURDATE())) t ON p.IDPEL = t.IDPEL
        WHERE p.PEMILIK IN (SELECT PEMILIK FROM server WHERE user_id = $current_user_id) AND p.TIKOR IS NOT NULL AND p.TIKOR != ''";
$result = $conn->query($sql);

$normalizeUtf8 = function($value) use (&$normalizeUtf8) {
  if (is_array($value)) {
    foreach ($value as $key => $item) {
      $value[$key] = $normalizeUtf8($item);
    }
    return $value;
  }

  if (!is_string($value)) {
    return $value;
  }

  if (preg_match('//u', $value)) {
    return $value;
  }

  if (function_exists('mb_convert_encoding')) {
    return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII');
  }

  if (function_exists('iconv')) {
    $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
    if ($converted !== false) {
      return $converted;
    }
  }

  return utf8_encode($value);
};

if ($result) {
  while ($row = $result->fetch_assoc()) {
    $customers[] = $normalizeUtf8($row);
  }
} else {
  $runtime_errors[] = 'Load customers failed: ' . $conn->error;
}

$encodeForJs = function($value, $label) use (&$runtime_errors) {
  $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  if ($json === false) {
    $runtime_errors[] = 'JSON encode failed for ' . $label . ': ' . json_last_error_msg();
    return '[]';
  }
  return $json;
};

$cables_json = $encodeForJs($cables, 'cables');
$assets_json = $encodeForJs($assets, 'assets');
$customers_json = $encodeForJs($customers, 'customers');
$serverlog_url_json = json_encode('serverlog/' . rawurlencode($ceknama) . '_online_client.txt', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$has_odp_source_data_json = $has_odp_source_data ? 'true' : 'false';
?>


      <!-- Full-screen map container -->
      <div class="map-outer-container" style="position: relative; width: 100%; height: 100vh; overflow: hidden;">
        <div id="map" style="width: 100%; height: 100%;"></div>
        
        <!-- Error Panels (floating at top) -->
        <?php if (!empty($runtime_errors)): ?>
        <div class="alert alert-danger" id="phpErrorPanel" style="position: absolute; top: 10px; left: 10px; right: 10px; margin: 0; z-index: 1000; max-width: 500px;">
          <strong>Detected server errors:</strong>
          <ul style="margin:8px 0 0 18px;">
            <?php foreach ($runtime_errors as $err): ?>
              <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <div class="alert alert-danger" id="jsErrorPanel" style="display:none; position: absolute; top: 10px; left: 10px; right: 10px; margin: 0; z-index: 1000; max-width: 500px;"></div>
        
        <!-- Sidebar toggle button (mobile only) -->
        <button class="sidebar-toggle-btn" id="sidebarToggleBtn" onclick="toggleSidebar()" title="Layers">
          <i class="fas fa-layer-group"></i>
          <span>Layers</span>
        </button>

        <!-- Toolbar toggle button (mobile only) -->
        <button class="toolbar-toggle-btn" id="toolbarToggleBtn" onclick="toggleToolbar()" title="Tools">
          <i class="fas fa-tools"></i>
          <span>Tools</span>
        </button>

        <!-- Floating Toolbar (top-left) -->
        <div class="floating-toolbar">
          <div class="btn-group-vertical w-100" role="group">
            <button class="btn btn-primary btn-sm" onclick="startAddingAsset('')" title="Add Marking"><i class="bi bi-pin-fill"></i><span class="btn-label"> Add Marking</span></button>
            <button class="btn btn-primary btn-sm" onclick="startDrawingCable()" title="Draw Cable"><i class="bi bi-diagram-3"></i><span class="btn-label"> Draw Cable</span></button>
            <button class="btn btn-secondary btn-sm" onclick="startAddingAsset('OLT')" title="Add OLT"><i class="bi bi-box"></i><span class="btn-label"> Add OLT</span></button>
            <a href="odp.php" class="btn btn-secondary btn-sm" title="Add ODP / ODC"><i class="bi bi-plus-circle"></i><span class="btn-label"> Add ODP/ODC</span></a>
            <button class="btn btn-secondary btn-sm" onclick="startAddingAsset('JC')" title="Add JC"><i class="bi bi-diagram-2"></i><span class="btn-label"> Add JC</span></button>
            <button class="btn btn-info btn-sm" onclick="syncODP()" title="Sync ODP Data"><i class="bi bi-arrow-repeat"></i><span class="btn-label"> Sync ODP</span></button>
            <button class="btn btn-success btn-sm" onclick="exportGeoJSON()" title="Export GeoJSON"><i class="bi bi-download"></i><span class="btn-label"> Export GeoJSON</span></button>
            <a href="proses/export_ftth_maps.php" class="btn btn-success btn-sm" title="Export XLSX"><i class="bi bi-file-earmark-excel"></i><span class="btn-label"> Export XLSX</span></a>
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#exportKmlModal" title="Export KML/KMZ"><i class="bi bi-geo-alt"></i><span class="btn-label"> Export KML</span></button>
            <button class="btn btn-warning btn-sm" onclick="importGeoJSON()" title="Import GeoJSON"><i class="bi bi-upload"></i><span class="btn-label"> Import GeoJSON</span></button>
          </div>
        </div>
        
        <!-- Floating Sidebar (right side) -->
        <div class="floating-sidebar">
          <div class="sidebar-header">
            <h5>Tools &amp; Layers</h5>
            <button class="sidebar-close-btn" onclick="toggleSidebar()" title="Tutup">&#x2715;</button>
          </div>
          <div class="sidebar-content">
            <div class="mb-3">
              <label for="search-input" class="form-label">Search Maps</label>
              <input type="text" class="form-control form-control-sm" id="search-input" placeholder="Search by name...">
            </div>
            
            <div id="attributes-form" style="display:none;">
              <h6>Attributes</h6>
              <form id="attr-form">
                <div id="dynamic-fields"></div>
                <button type="button" class="btn btn-primary btn-sm" onclick="saveFeature()">Save</button>
              </form>
            </div>
            
            <div id="layer-control" class="mt-3">
              <h6 class="mb-2">Layers</h6>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="cables-layer" checked>
                <label class="form-check-label" for="cables-layer">Cables</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="assets-layer" checked>
                <label class="form-check-label" for="assets-layer">Assets</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="customers-layer">
                <label class="form-check-label" for="customers-layer">Customers</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="disable-dash" checked>
                <label class="form-check-label" for="disable-dash">Disable Customer Lines</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="disable-animation">
                <label class="form-check-label" for="disable-animation">Disable Animation</label>
              </div>
              
              <div class="mt-3">
                <label for="map-mode" class="form-label">Map Mode:</label>
                <select class="form-select form-select-sm" id="map-mode">
                  <option value="street">Street</option>
                  <option value="satellite">Satellite</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>



<!-- Modal for attributes -->
<div class="modal fade" id="attrModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Enter Attributes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="modal-attr-form">
          <div id="modal-dynamic-fields"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="submitAttributes()">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Import GeoJSON</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="file" id="import-file" accept=".geojson">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="doImport()">Import</button>
      </div>
    </div>
  </div>
</div>

<!-- Export KML/KMZ Modal -->
<div class="modal fade" id="exportKmlModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Export KML / KMZ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="export-kml-form" method="GET" action="proses/export_ftth_maps_kml.php">
          <div class="mb-3">
            <label for="export-format" class="form-label">Format</label>
            <select class="form-control" id="export-format" name="format">
              <option value="kml">KML</option>
              <option value="kmz">KMZ</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="export-filter" class="form-label">Filter Data</label>
            <select class="form-control" id="export-filter" name="filter">
              <option value="all">Semua</option>
              <option value="odp">Hanya ODP</option>
              <option value="odc">Hanya ODC</option>
              <option value="cable">Hanya Kabel</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success" form="export-kml-form">Export</button>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Feature</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="edit-form">
          <div id="edit-dynamic-fields"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger" onclick="deleteFeature()">Delete</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="updateFeature()">Update</button>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css" />

<style>
.leaflet-control-zoom a {
    color: black !important;
}

/* ===== FLOATING TOOLBAR ===== */
.floating-toolbar {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 650;
  background: white;
  border-radius: 10px;
  padding: 6px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.18);
  width: 130px;
  display: none;
}

.floating-toolbar.toolbar-open {
  display: block !important;
}

.floating-toolbar .btn-group-vertical {
  display: flex;
  flex-direction: column;
  gap: 3px;
  width: 100%;
}

.floating-toolbar .btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0.38rem 0.6rem;
  font-size: 0.73rem;
  white-space: nowrap;
  border-radius: 6px;
  border: none;
  font-weight: 500;
  transition: opacity 0.15s ease;
  width: 100%;
  text-decoration: none;
}

.floating-toolbar .btn:hover { opacity: 0.85; }
.floating-toolbar .btn i { font-size: 0.85rem; flex-shrink: 0; }
.floating-toolbar .btn-label { overflow: hidden; text-overflow: ellipsis; }

.floating-toolbar .btn-primary   { background-color: #0d6efd; color: #fff; }
.floating-toolbar .btn-secondary { background-color: #6c757d; color: #fff; }
.floating-toolbar .btn-info      { background-color: #0dcaf0; color: #fff; }
.floating-toolbar .btn-success   { background-color: #198754; color: #fff; }
.floating-toolbar .btn-warning   { background-color: #ffc107; color: #000; }

/* ===== FLOATING SIDEBAR ===== */
.floating-sidebar {
  position: fixed;
  top: 50%;
  left: 50%;
  right: auto;
  transform: translate(-50%, -50%);
  width: 300px;
  max-height: 85vh;
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 15px rgba(0,0,0,0.2);
  z-index: 700;
  display: none;
  flex-direction: column;
  overflow: hidden;
}

.floating-sidebar.sidebar-open {
  display: flex !important;
}

.sidebar-header {
  background: #f8f9fa;
  padding: 12px 15px;
  border-bottom: 1px solid #dee2e6;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.sidebar-header h5 {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  color: #333;
}

.sidebar-close-btn {
  display: none;
  background: none;
  border: none;
  font-size: 1.2rem;
  cursor: pointer;
  color: #666;
  padding: 0;
  line-height: 1;
}

.sidebar-content {
  padding: 14px;
  overflow-y: auto;
  flex-grow: 1;
  font-size: 0.88rem;
}

.sidebar-content .form-label   { font-weight: 500; margin-bottom: 0.4rem; font-size: 0.83rem; }
.sidebar-content .form-control,
.sidebar-content .form-select  { font-size: 0.83rem; }
.sidebar-content .form-check   { margin-bottom: 0.45rem; }
.sidebar-content .form-check-label { margin-bottom: 0; font-size: 0.83rem; margin-left: 0.3rem; }
.sidebar-content h6 { font-size: 0.88rem; font-weight: 600; color: #333; margin-bottom: 0.6rem; }

/* ===== SIDEBAR/TOOLBAR TOGGLE BUTTON ===== */
.sidebar-toggle-btn,
.toolbar-toggle-btn {
  display: flex;
  position: fixed;
  z-index: 700;
  width: 48px;
  height: 48px;
  border: none;
  border-radius: 12px;
  box-shadow: 0 3px 12px rgba(0,0,0,0.3);
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-direction: column;
  gap: 2px;
}

/* Tools toggle - bottom left */
.toolbar-toggle-btn {
  bottom: 16px;
  left: 16px;
  background: #198754;
  color: #fff;
}

.toolbar-toggle-btn i { font-size: 1.3rem; }
.toolbar-toggle-btn span { font-size: 0.55rem; font-weight: 700; letter-spacing: 0.02em; line-height: 1; }

/* Layers toggle - bottom left, above tools */
.sidebar-toggle-btn {
  bottom: 76px;
  left: 16px;
  background: #0d6efd;
  color: #fff;
}

.sidebar-toggle-btn i { font-size: 1.3rem; }
.sidebar-toggle-btn span { font-size: 0.55rem; font-weight: 700; letter-spacing: 0.02em; line-height: 1; }

/* Desktop: keep toggles at bottom-left inside map area */
@media (min-width: 769px) {
  .toolbar-toggle-btn,
  .sidebar-toggle-btn {
    display: flex !important;
    position: absolute !important;
    left: 12px !important;
  }

  .toolbar-toggle-btn {
    bottom: 12px !important;
  }

  .sidebar-toggle-btn {
    bottom: 72px !important;
  }

  /* Desktop: opened panels should appear on edges, not centered */
  .floating-toolbar {
    position: absolute !important;
    top: auto !important;
    bottom: 128px !important;
    left: 12px !important;
    right: auto !important;
    transform: none !important;
    width: 150px !important;
    max-height: calc(100vh - 170px) !important;
    overflow-y: auto !important;
    z-index: 1200 !important;
    display: none !important;
  }

  .floating-toolbar.toolbar-open {
    display: block !important;
  }

  .floating-sidebar {
    top: 88px !important;
    left: auto !important;
    right: 12px !important;
    transform: none !important;
    width: 320px !important;
    max-height: calc(100vh - 100px) !important;
    border-radius: 10px !important;
  }
}

/* ===== MOBILE RESPONSIVE ===== */
@media (max-width: 768px) {
  :root {
    --mobile-map-top-offset: 78px;
  }

  /* Toolbar: vertical floating panel above bottom-left FAB */
  .floating-toolbar {
    position: fixed !important;
    top: 50% !important;
    bottom: auto;
    left: 50%;
    right: auto;
    transform: translate(-50%, -50%);
    width: 150px !important;
    border-radius: 10px;
    padding: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.22);
    z-index: 650;
    background: #fff;
    display: none;
  }

  .floating-toolbar.toolbar-open {
    display: block !important;
  }

  /* Keep buttons vertical */
  .floating-toolbar .btn-group-vertical {
    flex-direction: column !important;
    overflow-x: visible;
    overflow-y: auto;
    max-height: calc(100dvh - var(--mobile-map-top-offset) - 140px);
    gap: 3px;
    padding-bottom: 0;
    scrollbar-width: none;
  }

  .floating-toolbar .btn-group-vertical::-webkit-scrollbar { display: none; }

  .floating-toolbar .btn {
    flex-direction: row !important;
    justify-content: flex-start !important;
    align-items: center !important;
    width: 100% !important;
    height: auto !important;
    border-radius: 6px;
    padding: 0.38rem 0.55rem;
    gap: 6px;
    font-size: 0.72rem;
    font-weight: 500;
  }

  .floating-toolbar .btn i { font-size: 0.9rem; flex-shrink: 0; }

  .floating-toolbar .btn-label {
    display: inline !important;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  /* Sidebar hidden by default on mobile */
  .floating-sidebar {
    display: none !important;
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    right: auto !important;
    transform: translate(-50%, -50%) !important;
    width: min(92vw, 360px) !important;
    max-height: calc(100dvh - var(--mobile-map-top-offset) - 24px) !important;
    border-radius: 12px !important;
    z-index: 1001 !important;
    box-shadow: -4px 0 20px rgba(0,0,0,0.2) !important;
  }

  .floating-sidebar.sidebar-open { display: flex !important; }

  /* Show FAB buttons */
  .sidebar-toggle-btn,
  .toolbar-toggle-btn {
    display: flex !important;
  }

  .sidebar-close-btn { display: block !important; }

  #map { padding-bottom: 0; }
}
/* ===== END MOBILE RESPONSIVE ===== */

/* ===== FTTH MAPS FULL-SCREEN FIX (mobile) ===== */
@media (max-width: 768px) {
  html, body {
    overflow: hidden !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
  }

  /* Hide footer */
  footer.footer,
  footer {
    display: none !important;
  }

  /* Mobile sidenav offcanvas: closed by default, open when body has g-sidenav-show */
  #sidenav-main.sidenav {
    display: block !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    z-index: 9999 !important;
    height: 100vh !important;
    width: 260px !important;
    overflow-y: auto !important;
    transform: translateX(-110%) !important;
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
    transition: transform 0.22s ease, opacity 0.2s ease !important;
  }

  body.g-sidenav-show #sidenav-main.sidenav {
    transform: translateX(0) !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
  }

  /* Navbar: reset margin so it's flush */
  nav.navbar-main,
  .navbar-main {
    margin: 0 !important;
    border-radius: 0 !important;
    position: sticky !important;
    top: 0 !important;
    z-index: 500 !important;
    width: 100% !important;
  }

  /* Reset main-content wrapper */
  main.main-content {
    margin: 0 !important;
    padding: 0 !important;
    border-radius: 0 !important;
    max-height: none !important;
    height: 100% !important;
    width: 100% !important;
    overflow: hidden !important;
  }

  /* Map container: sit below navbar (~64px) */
  .map-outer-container,
  div[style*="100vh"] {
    position: fixed !important;
    top: var(--mobile-map-top-offset) !important;
    left: 0 !important;
    width: 100vw !important;
    height: calc(100dvh - var(--mobile-map-top-offset)) !important;
    height: calc(100vh - var(--mobile-map-top-offset)) !important;
    overflow: hidden !important;
    z-index: 1 !important;
  }

  /* Leaflet map itself */
  #map {
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
    touch-action: none !important;
  }
}
/* ===== END FTTH MAPS FULL-SCREEN FIX ===== */

/* Force maximum contrast popup - override all theme styles */
.leaflet-popup {
  z-index: 9999 !important;
}

.leaflet-popup-content-wrapper,
.leaflet-popup-content-wrapper * {
  background-color: #ffffff !important;
  color: #000000 !important;
  opacity: 1 !important;
  filter: none !important;
  -webkit-text-fill-color: #000000 !important;
}

.leaflet-popup-content-wrapper {
  border: 2px solid #333333 !important;
  border-radius: 10px !important;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5) !important;
  padding: 2px !important;
}

.leaflet-popup-content {
  font-size: 14px !important;
  line-height: 1.6 !important;
  margin: 14px 16px !important;
  background: transparent !important;
  font-weight: 500 !important;
  text-shadow: 0 0 1px rgba(255,255,255,0.8) !important;
  -webkit-font-smoothing: antialiased !important;
  -moz-osx-font-smoothing: grayscale !important;
}

.leaflet-popup-content b,
.leaflet-popup-content strong {
  color: #000000 !important;
  background: transparent !important;
  font-weight: 700 !important;
  -webkit-text-fill-color: #000000 !important;
}

.leaflet-popup-content div,
.leaflet-popup-content span,
.leaflet-popup-content p,
.leaflet-popup-content td,
.leaflet-popup-content th {
  color: #000000 !important;
  background: transparent !important;
  -webkit-text-fill-color: #000000 !important;
}

.leaflet-popup-content a {
  color: #0066cc !important;
  background: transparent !important;
  text-decoration: underline !important;
  font-weight: 600 !important;
  -webkit-text-fill-color: #0066cc !important;
}

.leaflet-popup-tip {
  background: #ffffff !important;
  border: 2px solid #333333 !important;
  opacity: 1 !important;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3) !important;
}

.leaflet-container a.leaflet-popup-close-button {
  color: #000000 !important;
  background: transparent !important;
  font-size: 22px !important;
  font-weight: 900 !important;
  text-shadow: none !important;
  -webkit-text-fill-color: #000000 !important;
  padding: 4px 8px !important;
}

.leaflet-container a.leaflet-popup-close-button:hover {
  color: #000000 !important;
  background: rgba(0,0,0,0.1) !important;
  border-radius: 3px !important;
}
</style>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
<script src="https://unpkg.com/leaflet-ant-path@1.3.0/dist/leaflet-ant-path.js"></script>
<script>
function toggleSidebar() {
  var sidebar = document.querySelector('.floating-sidebar');
  var btn = document.getElementById('sidebarToggleBtn');
  if (!sidebar) return;
  var isOpen = sidebar.classList.toggle('sidebar-open');
  if (btn) {
    btn.innerHTML = isOpen
      ? '<i class="fas fa-times"></i><span>Tutup</span>'
      : '<i class="fas fa-layer-group"></i><span>Layers</span>';
  }
}

function toggleToolbar() {
  var toolbar = document.querySelector('.floating-toolbar');
  var btn = document.getElementById('toolbarToggleBtn');
  if (!toolbar) return;
  var isOpen = toolbar.classList.toggle('toolbar-open');
  if (btn) {
    btn.innerHTML = isOpen
      ? '<i class="fas fa-times"></i><span>Tutup</span>'
      : '<i class="fas fa-tools"></i><span>Tools</span>';
  }
}

document.addEventListener('DOMContentLoaded', function() {
  if (window.innerWidth <= 768) {
    document.body.classList.remove('g-sidenav-show');
  }
});

let map;
let drawControl;
let cablesLayer;
let assetsLayer;
let customersLayer;
let cables = <?php echo $cables_json; ?>;
let assets = <?php echo $assets_json; ?>;
let customers = <?php echo $customers_json; ?>;
let currentAction = null;
let pendingFeature = null;
let currentEditId = null;
let currentEditType = null;
let antPaths = [];
let disableDash = true;
let disableAnimation = true;
let baseLayers;
let isTabVisible = true; // Track tab visibility
let cacheClearInterval; // Interval for auto cache clearing
const serverlogUrl = <?php echo $serverlog_url_json; ?>;
const hasOdpSourceData = <?php echo $has_odp_source_data_json; ?>;
let streetTileErrorCount = 0;
let streetTileFallbackApplied = false;
let mapHasAutoFitted = false;
let hasStoredView = false;

document.addEventListener('DOMContentLoaded', async function() {
  try {
  const leafletReady = await ensureLeafletLoaded();
  if (!leafletReady || typeof L === 'undefined') {
    showJsError('Leaflet gagal dimuat dari CDN utama maupun fallback.');
    setMapStatus('gagal: library Leaflet tidak tersedia');
    return;
  }
  setMapStatus('Leaflet terdeteksi');

  // Add visibility change listener to pause animations when tab is not visible
  document.addEventListener('visibilitychange', function() {
    isTabVisible = !document.hidden;
    if (isTabVisible) {
      // Resume animations
      antPaths.forEach(path => {
        if (path.resume) path.resume();
      });
    } else {
      // Pause animations
      antPaths.forEach(path => {
        if (path.pause) path.pause();
      });
    }
  });

  // Auto clear browser cache every hour
  cacheClearInterval = setInterval(clearBrowserCache, 60 * 60 * 1000);
  // Initial cache clear
  clearBrowserCache();
  // Check for stored map view
  let storedCenter = localStorage.getItem('mapCenter');
  let storedZoom = localStorage.getItem('mapZoom');
  let storedMapMode = localStorage.getItem('mapMode') || 'street';
  let initialView = [-6.2, 106.8];
  let initialZoom = 13;
  if (storedCenter && storedZoom) {
    initialView = JSON.parse(storedCenter);
    initialZoom = parseInt(storedZoom);
    hasStoredView = true;
  }
  map = L.map('map').setView(initialView, initialZoom);
  setMapStatus('map object berhasil dibuat');
  setTimeout(function() {
    map.invalidateSize();
  }, 300);
  window.addEventListener('resize', function() {
    map.invalidateSize();
  });
  map.on('moveend zoomend', function() {
    saveCurrentMapView();
  });

  // Disable auto close popup on map click
  map.closePopupOnClick = false;

  // Define base layers
  baseLayers = {
    "Street": L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '© OpenStreetMap contributors'
    }),
    "StreetFallback": L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
    }),
    "Satellite": L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
    })
  };

  baseLayers["Street"].on('tileerror', function() {
    streetTileErrorCount += 1;
    if (streetTileErrorCount >= 2 && !streetTileFallbackApplied) {
      streetTileFallbackApplied = true;
      if (map.hasLayer(baseLayers["Street"])) {
        map.removeLayer(baseLayers["Street"]);
      }
      baseLayers["StreetFallback"].addTo(map);
      setMapStatus('Street tile gagal, beralih ke fallback CARTO');
      return;
    }
    showJsError('Gagal memuat tile Street map. Koneksi/CDN mungkin diblokir.');
    setMapStatus('gagal load tile Street');
  });
  baseLayers["Street"].on('load', function() {
    setMapStatus('tile Street berhasil dimuat');
  });
  baseLayers["StreetFallback"].on('load', function() {
    setMapStatus('tile fallback Street berhasil dimuat');
  });
  baseLayers["StreetFallback"].on('tileerror', function() {
    showJsError('Fallback tile Street juga gagal dimuat. Ada kemungkinan akses ke penyedia peta diblokir jaringan.');
    setMapStatus('fallback Street gagal dimuat');
  });
  baseLayers["Satellite"].on('tileerror', function() {
    showJsError('Gagal memuat tile Satellite map. Koneksi/CDN mungkin diblokir.');
    setMapStatus('gagal load tile Satellite');
  });

  // Add default layer based on stored mode
  if (storedMapMode === 'satellite') {
    baseLayers["Satellite"].addTo(map);
  } else {
    baseLayers["Street"].addTo(map);
  }

  // Add layer control (optional, since we have dropdown)
  // L.control.layers(baseLayers).addTo(map);

  // Save map mode on layer change (if using control)
  // map.on('baselayerchange', function(e) {
  //   if (e.name === 'Satellite') {
  //     localStorage.setItem('mapMode', 'satellite');
  //   } else {
  //     localStorage.setItem('mapMode', 'street');
  //   }
  // });

  // Layers
  cablesLayer = L.layerGroup().addTo(map);
  assetsLayer = L.layerGroup().addTo(map);
  customersLayer = L.layerGroup();

  // Load data
  loadCables();
  loadAssets();
  await loadCustomers();
  setMapStatus('data layer berhasil dimuat');
  if (!hasStoredView) {
    fitMapToAllData();
  } else {
    setMapStatus('view terakhir berhasil dipulihkan');
  }

  // Auto sync ODP on load - hanya jika memang ada data ODP sumber yang belum di-sync,
  // dan belum pernah dicoba di session ini (mencegah reload berulang/looping saat data odp kosong)
  const odpAssetCount = assets.filter(a => {
    const parsed = safeJsonParse(a.attributes, {});
    return parsed.type === 'ODP';
  }).length;
  if (hasOdpSourceData && odpAssetCount === 0 && !sessionStorage.getItem('odpAutoSyncDone')) {
    sessionStorage.setItem('odpAutoSyncDone', '1');
    syncODP(true);
  }

  // Search listener
  document.getElementById('search-input').addEventListener('input', function() {
    filterLayers(this.value.toLowerCase());
  });

  // Draw control
  drawControl = new L.Control.Draw({
    draw: {
      polyline: true,
      polygon: true,
      marker: true,
      circle: false,
      circlemarker: false
    }
  });
  // map.addControl(drawControl);

  map.on(L.Draw.Event.CREATED, function (e) {
    pendingFeature = e;
    showAttributesModal(e.layerType);
  });

  // Layer controls
  document.getElementById('cables-layer').addEventListener('change', function() {
    if (this.checked) {
      map.addLayer(cablesLayer);
    } else {
      map.removeLayer(cablesLayer);
    }
    fitMapToAllData();
  });

  document.getElementById('assets-layer').addEventListener('change', function() {
    if (this.checked) {
      map.addLayer(assetsLayer);
    } else {
      map.removeLayer(assetsLayer);
    }
    fitMapToAllData();
  });

  document.getElementById('customers-layer').addEventListener('change', function() {
    if (this.checked) {
      map.addLayer(customersLayer);
    } else {
      map.removeLayer(customersLayer);
    }
    fitMapToAllData();
  });

  document.getElementById('disable-dash').addEventListener('change', function() {
    disableDash = this.checked;
    updateDashArray();
  });

  document.getElementById('disable-animation').addEventListener('change', async function() {
    disableAnimation = this.checked;
    // Reload cables and customers to update dashArray
    cablesLayer.clearLayers();
    customersLayer.clearLayers();
    antPaths = antPaths.filter(path => !cablesLayer.hasLayer(path) && !customersLayer.hasLayer(path));
    loadCables();
    await loadCustomers();
  });

  // Set initial map mode
  document.getElementById('map-mode').value = storedMapMode;

  // Set initial disable animation
  document.getElementById('disable-animation').checked = disableAnimation;

  // Map mode change listener
  document.getElementById('map-mode').addEventListener('change', function() {
    let mode = this.value;
    localStorage.setItem('mapMode', mode);
    // Remove current base layer
    map.eachLayer(function(layer) {
      if (layer instanceof L.TileLayer) {
        map.removeLayer(layer);
      }
    });
    // Add new layer
    if (mode === 'satellite') {
      baseLayers["Satellite"].addTo(map);
    } else {
      baseLayers["Street"].addTo(map);
    }
  });

  // Right click to show coordinates
  map.on('contextmenu', function(e) {
    L.DomEvent.preventDefault(e); // Prevent default context menu
    let latlng = e.latlng;
    L.popup({closeOnClick: false})
      .setLatLng(latlng)
      .setContent('TIKOR: ' + latlng.lat.toFixed(6) + ', ' + latlng.lng.toFixed(6))
      .openOn(map);
  });
  setMapStatus('siap digunakan');
  } catch (e) {
    showJsError('Map init error: ' + (e && e.message ? e.message : e));
    setMapStatus('gagal saat inisialisasi');
  }
});

function showEditModal(id, type) {
  currentEditId = id;
  currentEditType = type;
  let data = type === 'asset' ? assets.find(a => a.id == id) : cables.find(c => c.id == id);
  if (!data) {
    alert('Data feature tidak ditemukan');
    return;
  }
  let attributes = safeJsonParse(data.attributes, {});
  let fields = '';
  if (type === 'asset') {
    if (attributes.type === 'ODP') {
      // Special edit for ODP from odp table
      let geom = safeJsonParse(data.geom);
      if (!geom || !Array.isArray(geom.coordinates) || geom.coordinates.length < 2) {
        alert('Koordinat ODP tidak valid');
        return;
      }
      let coords = geom.coordinates; // [lng, lat]
      let tikor = coords[1] + ',' + coords[0]; // lat,lng
      let odpData = null;
      // Assume we need to fetch from odp table, but since it's PHP, we need to pass or query
      // For simplicity, use attributes, but to match odp.php, we need more
      // Since data is from sync, attributes have id_asset as KODE
      fields = `
        <div class="mb-3">
          <label>Kode ODP</label>
          <input type="text" class="form-control" id="edit-odp-kode" value="${attributes.id_asset}" required>
        </div>
        <div class="mb-3">
          <label>Name ODP</label>
          <input type="text" class="form-control" id="edit-odp-name" value="${attributes.name}" required>
        </div>
        <div class="mb-3">
          <label>Coordinates</label>
          <input type="text" class="form-control" id="edit-odp-tikor" value="${tikor}" required>
        </div>
        <div class="mb-3">
          <label>Server Area</label>
          <select class="form-control" id="edit-odp-server" onchange="setAreaEdit()">
            <option value="">-- Pilih Server Area --</option>
            <?php
            if ($current_user_id) {
              if ($AKSES == 'ASSISTANT') {
                $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
              } else {
                $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
              }
              while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                $area = htmlspecialchars($rowServer['AREA']);
                echo '<option value="'.$rowServer['PEMILIK'].'" data-area="'.$area.'">'.$rowServer['BRAND'].'-'.$area.'</option>';
              }
            }
            ?>
          </select>
        </div>
        <input type="hidden" id="edit-odp-area" value="${attributes.area}">
        <div hidden class="mb-3">
          <label>Area</label>
          <input type="text" class="form-control" id="edit-odp-area-display" value="${attributes.area}" readonly>
        </div>
      `;
    } else {
      fields = `
        <div class="mb-3">
          <label>ID Asset</label>
          <input type="text" class="form-control" id="edit-asset-id" value="${attributes.id_asset}" required>
        </div>
        <div class="mb-3">
          <label>Type</label>
          <input type="text" class="form-control" id="edit-asset-type" value="${attributes.type}" readonly>
        </div>
        <div class="mb-3">
          <label>Capacity</label>
          <input type="number" class="form-control" id="edit-capacity" value="${attributes.capacity || ''}">
        </div>
        <div class="mb-3">
          <label>Serial</label>
          <input type="text" class="form-control" id="edit-serial" value="${attributes.serial || ''}">
        </div>
        <div class="mb-3">
          <label>Notes</label>
          <textarea class="form-control" id="edit-notes">${attributes.notes || ''}</textarea>
        </div>
        <div class="mb-3">
          <label>Color</label>
          <input type="color" class="form-control" id="edit-asset-color" value="${attributes.color || '#ff0000'}">
        </div>
        <div class="mb-3">
          <label>Icon</label>
          <select class="form-control" id="edit-asset-icon">
            
            <option value="" ${attributes.icon === '' ? 'selected' : ''}>Default (Circle)</option>
            <option value="odp" ${attributes.icon === 'odp' ? 'selected' : ''}>ODP</option>
            <option value="tiang" ${attributes.icon === 'tiang' ? 'selected' : ''}>Tiang</option>
            <option value="jc" ${attributes.icon === 'jc' ? 'selected' : ''}>JC</option>
            <option value="home" ${attributes.icon === 'home' ? 'selected' : ''}>Home</option>
            <option value="datacenter" ${attributes.icon === 'datacenter' ? 'selected' : ''}>Data Center</option>
            <option value="odc" ${attributes.icon === 'odc' ? 'selected' : ''}>ODC</option>
            <option value="olt" ${attributes.icon === 'olt' ? 'selected' : ''}>OLT</option>
            <option value="office" ${attributes.icon === 'office' ? 'selected' : ''}>Office</option>
            <option value="building" ${attributes.icon === 'building' ? 'selected' : ''}>Building</option>
            <option value="mainhole" ${attributes.icon === 'mainhole' ? 'selected' : ''}>Main Hole</option>
            <option disabled value="">=== basic icon ===</option>
            <option value="fa-map-marker" ${attributes.icon === 'fa-map-marker' ? 'selected' : ''}>Marking Icon</option>
          </select>
        </div>
        <div class="mb-3">
          <label>Photo</label>
          <input type="file" class="form-control" id="edit-asset-photo" accept="image/*">
          ${attributes.photo ? '<p>Current: <a href="' + attributes.photo + '" target="_blank">View</a></p>' : ''}
        </div>
      `;
    }
  } else if (type === 'cable') {
    fields = `
      <div class="mb-3">
        <label>Name</label>
        <input type="text" class="form-control" id="edit-cable-name" value="${attributes.name}" required>
      </div>
      <div class="mb-3">
        <label>Type</label>
        <select class="form-control" id="edit-cable-type">
          <option ${attributes.type === 'feeder' ? 'selected' : ''}>feeder</option>
          <option ${attributes.type === 'distribution' ? 'selected' : ''}>distribution</option>
          <option ${attributes.type === 'drop' ? 'selected' : ''}>drop</option>
        </select>
      </div>
      <div class="mb-3">
        <label>Core Count</label>
        <input type="number" class="form-control" id="edit-core-count" value="${attributes.core_count}" required>
      </div>
      <div class="mb-3">
        <label>Fiber Type</label>
        <input type="text" class="form-control" id="edit-fiber-type" value="${attributes.fiber_type || ''}">
      </div>
      <div class="mb-3">
        <label>Status</label>
        <select class="form-control" id="edit-status">
          <option ${attributes.status === 'planned' ? 'selected' : ''}>planned</option>
          <option ${attributes.status === 'installed' ? 'selected' : ''}>installed</option>
          <option ${attributes.status === 'maintenance' ? 'selected' : ''}>maintenance</option>
        </select>
      </div>
      <div class="mb-3">
        <label>Color</label>
        <input type="color" class="form-control" id="edit-cable-color" value="${attributes.color || '#3388ff'}">
      </div>
      <div class="mb-3">
        <label>Photo</label>
        <input type="file" class="form-control" id="edit-cable-photo" accept="image/*">
        ${attributes.photo ? '<p>Current: <a href="' + attributes.photo + '" target="_blank">View</a></p>' : ''}
      </div>
    `;
  }
  document.getElementById('edit-dynamic-fields').innerHTML = fields;
  if (type === 'asset' && attributes.type === 'ODP') {
    const serverSelect = document.getElementById('edit-odp-server');
    if (serverSelect && attributes.pemilik) {
      serverSelect.value = attributes.pemilik;
    }
    setAreaEdit();
  }
  new bootstrap.Modal(document.getElementById('editModal')).show();
}

function setAreaEdit() {
  const serverSelect = document.getElementById('edit-odp-server');
  const areaInput = document.getElementById('edit-odp-area');
  const areaDisplay = document.getElementById('edit-odp-area-display');
  if (!serverSelect || !areaInput || !areaDisplay) {
    return;
  }
  const selected = serverSelect.options[serverSelect.selectedIndex];
  const area = selected ? (selected.getAttribute('data-area') || '') : '';
  areaInput.value = area;
  areaDisplay.value = area;
}

function showJsError(message) {
  const panel = document.getElementById('jsErrorPanel');
  if (!panel) return;
  panel.style.display = 'block';
  panel.textContent = message;
}

function setMapStatus(message) {
  return;
}

function safeJsonParse(value, fallback = null) {
  if (value === null || value === undefined) {
    return fallback;
  }

  if (typeof value === 'object') {
    return value;
  }

  if (typeof value !== 'string') {
    return fallback;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return fallback;
  }

  try {
    return JSON.parse(trimmed);
  } catch (e) {
    console.warn('Invalid JSON data skipped:', e, value);
    return fallback;
  }
}

function loadScriptOnce(src) {
  return new Promise(function(resolve, reject) {
    const existing = Array.from(document.scripts).find(function(script) {
      return script.src === src;
    });
    if (existing) {
      if (typeof L !== 'undefined') {
        resolve();
      } else {
        existing.addEventListener('load', function() { resolve(); }, { once: true });
        existing.addEventListener('error', function() { reject(new Error('Failed to load ' + src)); }, { once: true });
      }
      return;
    }

    const script = document.createElement('script');
    script.src = src;
    script.onload = function() { resolve(); };
    script.onerror = function() { reject(new Error('Failed to load ' + src)); };
    document.head.appendChild(script);
  });
}

function loadStyleOnce(href) {
  return new Promise(function(resolve, reject) {
    const existing = Array.from(document.styleSheets || []).find(function(sheet) {
      return sheet.href === href;
    });
    if (existing) {
      resolve();
      return;
    }

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.onload = function() { resolve(); };
    link.onerror = function() { reject(new Error('Failed to load ' + href)); };
    document.head.appendChild(link);
  });
}

async function ensureLeafletLoaded() {
  if (typeof L !== 'undefined') {
    return true;
  }

  setMapStatus('Leaflet tidak ditemukan, mencoba fallback CDN...');

  const cssFallbacks = [
    'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css',
    'https://cdn.jsdelivr.net/npm/leaflet-draw@1.0.4/dist/leaflet.draw.css'
  ];
  const jsFallbacks = [
    'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
    'https://cdn.jsdelivr.net/npm/leaflet-draw@1.0.4/dist/leaflet.draw.js',
    'https://cdn.jsdelivr.net/npm/leaflet-ant-path@1.3.0/dist/leaflet-ant-path.min.js'
  ];

  for (const href of cssFallbacks) {
    try {
      await loadStyleOnce(href);
    } catch (e) {
      console.warn(e);
    }
  }

  for (const src of jsFallbacks) {
    try {
      await loadScriptOnce(src);
    } catch (e) {
      console.warn(e);
    }
  }

  return typeof L !== 'undefined';
}

window.addEventListener('error', function(event) {
  const msg = 'JS Error: ' + (event.message || 'Unknown error') +
    (event.filename ? (' | ' + event.filename + ':' + event.lineno) : '');
  showJsError(msg);
});

window.addEventListener('unhandledrejection', function(event) {
  const reason = event.reason && event.reason.message ? event.reason.message : String(event.reason || 'Unknown promise error');
  showJsError('JS Promise Error: ' + reason);
});

function updateFeature() {
  // Save current map view
  localStorage.setItem('mapCenter', JSON.stringify(map.getCenter()));
  localStorage.setItem('mapZoom', map.getZoom());

  let data = currentEditType === 'asset' ? assets.find(a => a.id == currentEditId) : cables.find(c => c.id == currentEditId);
  if (!data) {
    alert('Data feature tidak ditemukan');
    return;
  }
  let formData = new FormData();

  let newAttributes = {};
  if (currentEditType === 'asset') {
    newAttributes = {
      id_asset: document.getElementById('edit-asset-id').value,
      type: document.getElementById('edit-asset-type').value,
      capacity: document.getElementById('edit-capacity').value,
      serial: document.getElementById('edit-serial').value,
      notes: document.getElementById('edit-notes').value,
      color: document.getElementById('edit-asset-color').value,
      icon: document.getElementById('edit-asset-icon').value
    };
  } else if (currentEditType === 'cable') {
    newAttributes = {
      name: document.getElementById('edit-cable-name').value,
      type: document.getElementById('edit-cable-type').value,
      core_count: document.getElementById('edit-core-count').value,
      fiber_type: document.getElementById('edit-fiber-type').value,
      status: document.getElementById('edit-status').value,
      color: document.getElementById('edit-cable-color').value
    };
  }

  formData.append('action', 'update_' + currentEditType + '_attr');
  formData.append('id', currentEditId);
  formData.append('attributes', JSON.stringify(newAttributes));

  let photoInput = document.getElementById('edit-' + currentEditType + '-photo');
  if (photoInput && photoInput.files.length > 0) {
    formData.append('photo', photoInput.files[0]);
  }

  fetch('', {
    method: 'POST',
    body: formData
  }).then(response => {
    if (response.ok) {
      window.location.reload();
    } else {
      alert('Update failed');
    }
  }).catch(error => {
    console.error('Error:', error);
    alert('Update failed');
  });
}

function deleteFeature() {
  if (confirm('Are you sure you want to delete this feature?')) {
    // Save current map view
    localStorage.setItem('mapCenter', JSON.stringify(map.getCenter()));
    localStorage.setItem('mapZoom', map.getZoom());

    let data = currentEditType === 'asset' ? assets.find(a => a.id == currentEditId) : cables.find(c => c.id == currentEditId);
    if (!data) {
      alert('Data feature tidak ditemukan');
      return;
    }
    let attributes = safeJsonParse(data.attributes, {});
    let form = document.createElement('form');
    form.method = 'POST';
    form.action = '';

    let inputAction = document.createElement('input');
    inputAction.type = 'hidden';
    inputAction.name = 'action';
    inputAction.value = 'delete_' + currentEditType;
    form.appendChild(inputAction);

    let inputId = document.createElement('input');
    inputId.type = 'hidden';
    inputId.name = 'id';
    inputId.value = currentEditId;
    form.appendChild(inputId);

    if (currentEditType === 'asset' && attributes.type === 'ODP') {
      // Also delete from odp table
      let inputKode = document.createElement('input');
      inputKode.type = 'hidden';
      inputKode.name = 'kode';
      inputKode.value = attributes.id_asset;
      form.appendChild(inputKode);
    }

    document.body.appendChild(form);
    form.submit();
  }
}

function startAddingAsset(type) {
  window.assetType = type;
  new L.Draw.Marker(map).enable();
}

function startDrawingCable() {
  new L.Draw.Polyline(map).enable();
}

function syncODP(silent = false) {
  if (!silent && !confirm('Are you sure you want to sync ODP data from odp.php? This will add new ODP assets to the map.')) {
    return;
  }
  // Save current map view
  localStorage.setItem('mapCenter', JSON.stringify(map.getCenter()));
  localStorage.setItem('mapZoom', map.getZoom());

  let form = document.createElement('form');
  form.method = 'POST';
  form.action = '';

  let inputAction = document.createElement('input');
  inputAction.type = 'hidden';
  inputAction.name = 'action';
  inputAction.value = 'sync_odp';
  form.appendChild(inputAction);

  document.body.appendChild(form);
  form.submit();
}

function showAttributesModal(layerType) {
  let iconOptions = '';
  switch (window.assetType) {
    case 'OLT':
      iconOptions += '<option value="olt">OLT</option>';
      break;
    case 'ODC':
      iconOptions += '<option value="odc">ODC</option>';
      break;
    case 'JC':
      iconOptions += '<option value="jc">JC</option>';
      break;
    default:
      iconOptions += '<option value="tiang">Tiang</option>';
      iconOptions += '<option value="home">Home</option>';
      iconOptions += '<option value="datacenter">Data Center</option>';
      iconOptions += '<option value="office">Office</option>';
      iconOptions += '<option value="building">Building</option>';
      iconOptions += '<option value="mainhole">Main Hole</option>';
      iconOptions += '<option disabled value="">=== basic icon ===</option>';
      iconOptions += '<option value="fa-map-marker">Marking Icon</option>';
      break;
  }

  let fields = '';
  if (layerType === 'polyline') {
    fields = `
      <div class="mb-3">
        <label>Name</label>
        <input type="text" class="form-control" id="cable-name" required>
      </div>
      <div class="mb-3">
        <label>Type</label>
        <select class="form-control" id="cable-type">
          <option>feeder</option>
          <option>distribution</option>
          <option>drop</option>
        </select>
      </div>
      <div class="mb-3">
        <label>Core Count</label>
        <input type="number" class="form-control" id="core-count" required>
      </div>
      <div class="mb-3">
        <label>Fiber Type</label>
        <input type="text" class="form-control" id="fiber-type">
      </div>
      <div class="mb-3">
        <label>Status</label>
        <select class="form-control" id="status">
          <option>planned</option>
          <option>installed</option>
          <option>maintenance</option>
        </select>
      </div>
      <div class="mb-3">
        <label>Color</label>
        <input type="color" class="form-control" id="cable-color" value="#3388ff">
      </div>
      <div class="mb-3">
        <label>Photo</label>
        <input type="file" class="form-control" id="cable-photo" accept="image/*">
      </div>
    `;
  } else if (layerType === 'marker') {
    fields = `
      <div class="mb-3">
        <label>ID Asset</label>
        <input type="text" class="form-control" id="asset-id" required>
      </div>
      <div class="mb-3">
        <label>Type</label>
        <input type="text" class="form-control" id="asset-type" value="${window.assetType || ''}" ${window.assetType ? 'readonly' : ''}>
      </div>
      <div class="mb-3">
        <label>Capacity</label>
        <input type="number" class="form-control" id="capacity">
      </div>
      <div class="mb-3">
        <label>Serial</label>
        <input type="text" class="form-control" id="serial">
      </div>
      <div class="mb-3">
        <label>Notes</label>
        <textarea class="form-control" id="notes"></textarea>
      </div>
      <div class="mb-3">
        <label>Color</label>
        <input type="color" class="form-control" id="asset-color" value="#ff0000">
      </div>
      <div class="mb-3">
        <label>Icon</label>
        <select class="form-control" id="asset-icon">
          ${iconOptions}
        </select>
      </div>
      <div class="mb-3">
        <label>Photo</label>
        <input type="file" class="form-control" id="asset-photo" accept="image/*">
      </div>
    `;
  }
  document.getElementById('modal-dynamic-fields').innerHTML = fields;
  // Set default icon based on type
  if (layerType === 'marker') {
    if (window.assetType === 'JC') {
      document.getElementById('asset-icon').value = 'jc';
    } else if (window.assetType === 'Tiang') {
      document.getElementById('asset-icon').value = 'tiang';
    } else if (window.assetType === 'ODP') {
      document.getElementById('asset-icon').value = 'odp';
    } else if (window.assetType === 'Home') {
      document.getElementById('asset-icon').value = 'home';
    } else if (window.assetType === 'DATA CENTER') {
      document.getElementById('asset-icon').value = 'fa-server';
    } else if (window.assetType === 'ODC') {
      document.getElementById('asset-icon').value = 'odc';
    } else if (window.assetType === 'OLT') {
      document.getElementById('asset-icon').value = 'olt';
    } else if (window.assetType === 'Office') {
      document.getElementById('asset-icon').value = 'office';
    } else if (window.assetType === 'Building') {
      document.getElementById('asset-icon').value = 'building';
    } else if (window.assetType === 'Main Hole') {
      document.getElementById('asset-icon').value = 'mainhole';
    } else if (window.assetType === 'Custom') {
      document.getElementById('asset-icon').value = 'fa-map-marker';
    }
  }
  new bootstrap.Modal(document.getElementById('attrModal')).show();
}

function submitAttributes() {
  console.log('Submitting attributes for', pendingFeature.layerType);
  // Save current map view
  localStorage.setItem('mapCenter', JSON.stringify(map.getCenter()));
  localStorage.setItem('mapZoom', map.getZoom());

  let attributes = {};
  let formData = new FormData();

  if (pendingFeature.layerType === 'polyline') {
    attributes = {
      name: document.getElementById('cable-name').value,
      type: document.getElementById('cable-type').value,
      core_count: document.getElementById('core-count').value,
      fiber_type: document.getElementById('fiber-type').value,
      status: document.getElementById('status').value,
      color: document.getElementById('cable-color').value
    };
    formData.append('action', 'save_cable');
    formData.append('geom', JSON.stringify(pendingFeature.layer.toGeoJSON().geometry));
    formData.append('attributes', JSON.stringify(attributes));
    formData.append('length', calculateLength(pendingFeature.layer));
    formData.append('name', attributes.name);

    let photoInput = document.getElementById('cable-photo');
    if (photoInput.files.length > 0) {
      formData.append('photo', photoInput.files[0]);
    }

  } else if (pendingFeature.layerType === 'marker') {
    attributes = {
      id_asset: document.getElementById('asset-id').value,
      type: document.getElementById('asset-type').value,
      capacity: document.getElementById('capacity').value,
      serial: document.getElementById('serial').value,
      notes: document.getElementById('notes').value,
      color: document.getElementById('asset-color').value,
      icon: document.getElementById('asset-icon').value
    };
    formData.append('action', 'save_asset');
    formData.append('geom', JSON.stringify(pendingFeature.layer.toGeoJSON().geometry));
    formData.append('attributes', JSON.stringify(attributes));
    formData.append('type', attributes.type);

    let photoInput = document.getElementById('asset-photo');
    if (photoInput.files.length > 0) {
      formData.append('photo', photoInput.files[0]);
    }
  }

  fetch('', {
    method: 'POST',
    body: formData
  }).then(response => {
    if (response.ok) {
      window.location.reload();
    } else {
      alert('Save failed');
    }
  }).catch(error => {
    console.error('Error:', error);
    alert('Save failed');
  });
}

function calculateLength(layer) {
  let latlngs = layer.getLatLngs();
  let length = 0;
  for (let i = 1; i < latlngs.length; i++) {
    length += map.distance(latlngs[i-1], latlngs[i]);
  }
  return length;
}

function extendBoundsWithLayer(bounds, layer) {
  if (!layer) {
    return;
  }

  if (typeof layer.eachLayer === 'function') {
    layer.eachLayer(function(childLayer) {
      extendBoundsWithLayer(bounds, childLayer);
    });
    return;
  }

  if (typeof layer.getBounds === 'function') {
    const layerBounds = layer.getBounds();
    if (layerBounds && layerBounds.isValid && layerBounds.isValid()) {
      bounds.extend(layerBounds);
    }
    return;
  }

  if (typeof layer.getLatLng === 'function') {
    const latlng = layer.getLatLng();
    if (latlng) {
      bounds.extend(latlng);
    }
  }
}

function fitMapToAllData(force = false) {
  if (!map) {
    return;
  }

  if (hasStoredView && !force) {
    setMapStatus('menggunakan view terakhir yang tersimpan');
    return;
  }

  if (mapHasAutoFitted && !force) {
    return;
  }

  const bounds = L.latLngBounds();
  extendBoundsWithLayer(bounds, cablesLayer);
  extendBoundsWithLayer(bounds, assetsLayer);
  extendBoundsWithLayer(bounds, customersLayer);
if (bounds.isValid()) {
    map.fitBounds(bounds, {
      padding: [30, 30],
      maxZoom: 17
    });
    return;
}

// Zoom seluruh Indonesia
const indonesiaBounds = [
    [-11.0, 94.0],   // Barat Daya
    [6.5, 141.5]     // Timur Laut
];

map.fitBounds(indonesiaBounds);
  saveCurrentMapView();
  setMapStatus('tidak ada titik valid, memakai center default');
}

function saveCurrentMapView() {
  if (!map) {
    return;
  }

  const center = map.getCenter();
  if (!center) {
    return;
  }

  localStorage.setItem('mapCenter', JSON.stringify([center.lat, center.lng]));
  localStorage.setItem('mapZoom', String(map.getZoom()));
}

function loadCables() {
  console.log('Loading', cables.length, 'cables');
  cables.forEach(cable => {
    let geom = safeJsonParse(cable.geom);
    let attributes = safeJsonParse(cable.attributes, {});
    if (!geom || !Array.isArray(geom.coordinates)) {
      return;
    }
    let latlngs = geom.coordinates.map(coord => [coord[1], coord[0]]); // GeoJSON is [lng, lat], Leaflet [lat, lng]
    let popupContent = `<b>Cable</b><br>Name: ${attributes.name}<br>Type: ${attributes.type}<br>Core Count: ${attributes.core_count}<br>Fiber Type: ${attributes.fiber_type || ''}<br>Status: ${attributes.status}<br>Color: ${attributes.color || '#00aaff'}${attributes.photo ? '<br><img src="' + attributes.photo + '" alt="Photo" style="max-width: 200px;">' : ''}`;
    if (disableAnimation) {
      let polyline = L.polyline(latlngs, {
        color: attributes.color || '#00aaff',
        weight: 6,
        opacity: 1.0
      });
      polyline.bindPopup(popupContent, {closeOnClick: false});
      polyline.on('click', function() {
        showEditModal(cable.id, 'cable');
      });
      polyline.addTo(cablesLayer);
    } else {
      let options = {
        "delay": 400,
        "weight": 6,
        "color": attributes.color || '#00aaff',
        "opacity": 1.0,
        "pulseColor": "#FFFFFF",
        "paused": false,
        "reverse": false,
        "hardwareAccelerated": true,
        "interactive": true,
        "dashArray": [10, 20]
      };
      let antPath = L.polyline.antPath(latlngs, options);
      antPath.bindPopup(popupContent, {closeOnClick: false});
      antPath.on('click', function() {
        showEditModal(cable.id, 'cable');
      });
      antPath.addTo(cablesLayer);
      antPaths.push(antPath);
    }
  });
}

function loadAssets() {
  console.log('Loading', assets.length, 'assets');
  assets.forEach(asset => {
    let geom = safeJsonParse(asset.geom);
    let attributes = safeJsonParse(asset.attributes, {});
    if (!geom) {
      return;
    }
    let geojson = {
      type: 'Feature',
      geometry: geom,
      properties: { id: asset.id, type: 'asset', attributes: asset.attributes }
    };
    L.geoJSON(geojson, {
      pointToLayer: function(feature, latlng) {
        if (attributes.icon === 'odp') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'odpmap2.png',
              iconSize: [80, 80],
              iconAnchor: [40, 70]
            })
          });
        } else if (attributes.icon === 'tiang') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/tiang.png',
               iconSize: [40, 40],
              iconAnchor: [20, 20]
            })
          });
        } else if (attributes.icon === 'jc') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/JC.png',
              iconSize: [40, 40],
              iconAnchor: [20, 20]
            })
          });
        } else if (attributes.icon === 'home') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/home.png',
           iconSize: [40, 60],
               iconAnchor: [20, 30]
            })
          });
        } else if (attributes.icon === 'datacenter') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/datacenter.png',
               iconSize: [80, 80],
               iconAnchor: [40, 40]
            })
          });
        } else if (attributes.icon === 'odc') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/ODC.png',
            iconSize: [40, 40],
               iconAnchor: [20, 30]
            })
          });
        } else if (attributes.icon === 'olt') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/olt.png',
                iconSize: [40, 40],
             iconAnchor: [20, 20]
            })
          });
        } else if (attributes.icon === 'office') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/office.png',
              iconSize: [80, 80],
                iconAnchor: [40, 40]
            })
          });
        } else if (attributes.icon === 'building') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/building.png',
               iconSize: [80, 80],
               iconAnchor: [40, 40]
            })
          });
        } else if (attributes.icon === 'mainhole') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/mainhole.png',
             iconSize: [80, 80],
             iconAnchor: [20, 20]
            })
          });
        } else if (attributes.icon === 'fa-map-marker') {
          return L.marker(latlng, {
            icon: L.icon({
              iconUrl: 'assets/img/marking.png',
              iconSize: [40, 40],
              iconAnchor: [20, 20]
            })
          });
        } else if (attributes.icon) {
          return L.marker(latlng, {
            icon: L.divIcon({
              html: '<i class="fa ' + attributes.icon + '" style="color: ' + (attributes.color || '#ff0000') + '; font-size: 120px;"></i>',
              className: 'custom-div-icon',
            iconSize: [80, 80],
              iconAnchor: [40, 40]
            })
          });
        } else {
          return L.circleMarker(latlng, {
            color: attributes.color || '#ff0000',
            fillColor: attributes.color || '#ff0000',
            fillOpacity: 0.8,
            radius: 20
          });
        }
      },
      onEachFeature: function(feature, layer) {
        layer.on('click', function() {
          showEditModal(feature.properties.id, 'asset');
        });
        let attributes = safeJsonParse(feature.properties.attributes, {});
        console.log('Asset photo:', attributes.photo);
        let popupContent = `<b>${attributes.type}</b><br>ID: ${attributes.id_asset}<br>Name: ${attributes.name || ''}<br>Area: ${attributes.area || ''}${attributes.hirarki ? '<br>Hirarki: ' + attributes.hirarki : ''}${attributes.photo ? '<br><img src="' + attributes.photo + '" alt="Photo" style="max-width: 200px;">' : ''}`;
        layer.bindPopup(popupContent, {closeOnClick: false});
        layer.on('mouseover', function() { this.openPopup(); });
        layer.on('mouseout', function() { this.closePopup(); });
      }
    }).addTo(assetsLayer);
  });
}

function updateDashArray() {
  // Remove existing ant paths for all
  antPaths.forEach(path => {
    if (cablesLayer.hasLayer(path)) cablesLayer.removeLayer(path);
    if (customersLayer.hasLayer(path)) customersLayer.removeLayer(path);
  });
  antPaths = [];
  // Clear customers layer and reload to update lines
  customersLayer.clearLayers();
  // Reload cables and customers with new dash setting
  loadCables();
  loadCustomers();
}

function exportGeoJSON() {
  let geojson = {
    type: 'FeatureCollection',
    features: []
  };
  cablesLayer.eachLayer(layer => {
    if (layer.toGeoJSON) geojson.features.push(layer.toGeoJSON());
  });
  assetsLayer.eachLayer(layer => {
    if (layer.toGeoJSON) geojson.features.push(layer.toGeoJSON());
  });
  customersLayer.eachLayer(layer => {
    if (layer.toGeoJSON) geojson.features.push(layer.toGeoJSON());
  });
  let dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(geojson));
  let downloadAnchorNode = document.createElement('a');
  downloadAnchorNode.setAttribute('href', dataStr);
  downloadAnchorNode.setAttribute('download', 'ftth_project.geojson');
  document.body.appendChild(downloadAnchorNode);
  downloadAnchorNode.click();
  downloadAnchorNode.remove();
}

function importGeoJSON() {
  new bootstrap.Modal(document.getElementById('importModal')).show();
}

function loadCustomers() {
  return fetch(serverlogUrl)
    .then(res => res.json())
    .then(data => {
      const pelangganMarkers = data.pelangganMarkers || [];
      console.log('Loading', pelangganMarkers.length, 'customers from serverlog');
      pelangganMarkers.forEach(p => {
        const iconUrl = p.status === 'online' ? 'customer-green2.png' : 'customer-red2.png';
        const marker = L.marker([p.lat, p.lng], {
          icon: L.icon({
            iconUrl: iconUrl,
            iconSize: [75, 75],
            iconAnchor: [37.5, 60]
          })
        }).bindPopup(p.popup + '<br>Status: ' + p.status);
        marker.addTo(customersLayer);

        // Draw animated line to ODP if exists
        if (p.odp && !disableDash) {
          const odp = assets.find(a => {
            const parsed = safeJsonParse(a.attributes, {});
            return parsed.id_asset === p.odp;
          });
          if (odp) {
            const odpGeom = safeJsonParse(odp.geom);
            if (!odpGeom || !Array.isArray(odpGeom.coordinates)) {
              return;
            }
            const odpCoords = odpGeom.coordinates; // [lng, lat]
            const lineColor = p.status === 'online' ? 'green' : 'red';
            const popupContent = `<b>Customer Line</b><br>To ODP: ${p.odp}<br>Status: ${p.status}<br>Color: ${lineColor}`;
            if (disableAnimation) {
              const polyline = L.polyline([[p.lat, p.lng], [odpCoords[1], odpCoords[0]]], {
                color: lineColor,
                weight: 4,
                opacity: 1.0
              });
              polyline.bindPopup(popupContent);
              polyline.on('mouseover', function() { this.openPopup(); });
              polyline.on('mouseout', function() { this.closePopup(); });
              polyline.addTo(customersLayer);
            } else {
              let options = {
                "delay": 1200,
                "weight": 4,
                "color": lineColor,
                "opacity": 1.0,
                "pulseColor": "#FFFFFF",
                "paused": false,
                "reverse": false,
                "hardwareAccelerated": true,
                "interactive": true,
                "dashArray": [10, 20]
              };
              const antPath = L.polyline.antPath([[p.lat, p.lng], [odpCoords[1], odpCoords[0]]], options);
              antPath.bindPopup(popupContent, {closeOnClick: false});
              antPath.on('mouseover', function() { this.openPopup(); });
              antPath.on('mouseout', function() { this.closePopup(); });
              antPath.addTo(customersLayer);
              antPaths.push(antPath);
            }
          }
        }
      });
      fitMapToAllData(true);
      return Promise.resolve();
    })
    .catch(err => {
      console.error('Failed to load customers from serverlog:', err);
      // Fallback to PHP data if needed
      console.log('Falling back to PHP data');
      customers.forEach(customer => {
        if (customer.TIKOR && customer.TIKOR.includes(',')) {
          const coords = customer.TIKOR.split(',');
          const lat = parseFloat(coords[0]);
          const lng = parseFloat(coords[1]);
          const iconUrl = customer.status === 'online' ? 'customer-green2.png' : 'customer-red2.png';
          const marker = L.marker([lat, lng], {
            icon: L.icon({
              iconUrl: iconUrl,
              iconSize: [75, 75],
              iconAnchor: [37.5, 60]
            })
          }).bindPopup(`<b>${customer.NAMA}</b><br>ID: ${customer.IDPEL}<br>Alamat: ${customer.ALAMAT}<br>Paket: ${customer.PAKET}<br>ODP: ${customer.ODP}<br>Status: ${customer.status}`);
          marker.addTo(customersLayer);

          // Draw animated line to ODP if exists
          if (customer.ODP && !disableDash) {
            const odp = assets.find(a => {
              const parsed = safeJsonParse(a.attributes, {});
              return parsed.id_asset === customer.ODP;
            });
            if (odp) {
              const odpGeom = safeJsonParse(odp.geom);
              if (!odpGeom || !Array.isArray(odpGeom.coordinates)) {
                return;
              }
              const odpCoords = odpGeom.coordinates; // [lng, lat]
              const lineColor = customer.status === 'online' ? 'green' : 'red';
              const popupContent = `<b>Customer Line</b><br>To ODP: ${customer.ODP}<br>Status: ${customer.status}<br>Color: ${lineColor}`;
              if (disableAnimation) {
                const polyline = L.polyline([[lat, lng], [odpCoords[1], odpCoords[0]]], {
                  color: lineColor,
                  weight: 4,
                  opacity: 1.0
                });
                polyline.bindPopup(popupContent);
                polyline.on('mouseover', function() { this.openPopup(); });
                polyline.on('mouseout', function() { this.closePopup(); });
                polyline.addTo(customersLayer);
              } else {
              let options = {
                "delay": 1200,
                "weight": 4,
                "color": lineColor,
                "opacity": 1.0,
                "pulseColor": "#FFFFFF",
                "paused": !isTabVisible, // Pause if tab not visible
                "reverse": false,
                "hardwareAccelerated": true,
                "interactive": true,
                "dashArray": [10, 20]
              };
                const antPath = L.polyline.antPath([[lat, lng], [odpCoords[1], odpCoords[0]]], options);
                antPath.bindPopup(popupContent, {closeOnClick: false});
                antPath.on('mouseover', function() { this.openPopup(); });
                antPath.on('mouseout', function() { this.closePopup(); });
                antPath.addTo(customersLayer);
                antPaths.push(antPath);
              }
            }
          }
        }
      });
      fitMapToAllData(true);
      return Promise.resolve();
    });
}


function filterLayers(query) {
  // Clear layers
  cablesLayer.clearLayers();
  assetsLayer.clearLayers();
  customersLayer.clearLayers();

  const bounds = L.latLngBounds();

  // Filter and add cables
  cables.forEach(cable => {
    const attributes = safeJsonParse(cable.attributes, {});
    const cableName = String(attributes.name || '').toLowerCase();
    const cableType = String(attributes.type || '').toLowerCase();
    if (!query || cableName.includes(query) || cableType.includes(query)) {
      // Add cable
      const geom = safeJsonParse(cable.geom);
      if (!geom || !Array.isArray(geom.coordinates)) {
        return;
      }
      const latlngs = geom.coordinates.map(coord => [coord[1], coord[0]]);
      const popupContent = `<b>Cable</b><br>Name: ${attributes.name}<br>Type: ${attributes.type}<br>Core Count: ${attributes.core_count}<br>Fiber Type: ${attributes.fiber_type || ''}<br>Status: ${attributes.status}<br>Color: ${attributes.color || '#00aaff'}${attributes.photo ? '<br><img src="' + attributes.photo + '" alt="Photo" style="max-width: 200px;">' : ''}`;
      if (disableAnimation) {
        const polyline = L.polyline(latlngs, {
          color: attributes.color || '#00aaff',
          weight: 6,
          opacity: 1.0
        });
        polyline.bindPopup(popupContent);
        polyline.on('click', function() {
          showEditModal(cable.id, 'cable');
        });
        polyline.on('mouseover', function() { this.openPopup(); });
        polyline.on('mouseout', function() { this.closePopup(); });
        polyline.addTo(cablesLayer);
        bounds.extend(polyline.getBounds());
      } else {
      let options = {
        "delay": 400,
        "weight": 6,
        "color": attributes.color || '#00aaff',
        "opacity": 1.0,
        "pulseColor": "#FFFFFF",
        "paused": !isTabVisible, // Pause if tab not visible
        "reverse": false,
        "hardwareAccelerated": true,
        "interactive": true,
        "dashArray": [10, 20]
      };
        const antPath = L.polyline.antPath(latlngs, options);
        antPath.bindPopup(popupContent, {closeOnClick: false});
        antPath.on('click', function() {
          showEditModal(cable.id, 'cable');
        });
        antPath.on('mouseover', function() { this.openPopup(); });
        antPath.on('mouseout', function() { this.closePopup(); });
        antPath.addTo(cablesLayer);
        antPaths.push(antPath);
        bounds.extend(antPath.getBounds());
      }
    }
  });

  // Filter and add assets
  assets.forEach(asset => {
    const attributes = safeJsonParse(asset.attributes, {});
    const assetId = String(attributes.id_asset || '').toLowerCase();
    const assetType = String(attributes.type || '').toLowerCase();
    const assetName = String(attributes.name || '').toLowerCase();
    if (!query || assetId.includes(query) || assetType.includes(query) || assetName.includes(query)) {
      // Add asset
      const geom = safeJsonParse(asset.geom);
      if (!geom) {
        return;
      }
      const geojson = {
        type: 'Feature',
        geometry: geom,
        properties: { id: asset.id, type: 'asset', attributes: asset.attributes }
      };
      L.geoJSON(geojson, {
        pointToLayer: function(feature, latlng) {
          if (attributes.icon === 'odp') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'odpmap2.png',
                iconSize: [80, 80],
                iconAnchor: [40, 70]
              })
            });
          } else if (attributes.icon === 'tiang') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/tiang.png',
               iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'jc') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/JC.png',
             iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'home') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/home.png',
             iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'datacenter') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/datacenter.png',
                iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'odc') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/ODC.png',
             iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'olt') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/olt.png',
                iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'office') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/office.png',
             iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'building') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/building.png',
             iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'mainhole') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/mainhole.png',
            iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon === 'fa-map-marker') {
            return L.marker(latlng, {
              icon: L.icon({
                iconUrl: 'assets/img/marking.png',
                iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else if (attributes.icon) {
            return L.marker(latlng, {
              icon: L.divIcon({
                html: '<i class="fa ' + attributes.icon + '" style="color: ' + (attributes.color || '#ff0000') + '; font-size: 120px;"></i>',
                className: 'custom-div-icon',
              iconSize: [40, 40],
                iconAnchor: [20, 20]
              })
            });
          } else {
            return L.circleMarker(latlng, {
              color: attributes.color || '#ff0000',
              fillColor: attributes.color || '#ff0000',
              fillOpacity: 0.8,
              radius: 20
            });
          }
        },
        onEachFeature: function(feature, layer) {
          layer.on('click', function() {
            showEditModal(feature.properties.id, 'asset');
          });
          const popupContent = `<b>${attributes.type}</b><br>ID: ${attributes.id_asset}<br>Name: ${attributes.name || ''}<br>Area: ${attributes.area || ''}${attributes.hirarki ? '<br>Hirarki: ' + attributes.hirarki : ''}${attributes.photo ? '<br><img src="' + attributes.photo + '" alt="Photo" style="max-width: 200px;">' : ''}`;
          layer.bindPopup(popupContent, {closeOnClick: false});
          bounds.extend(layer.getLatLng());
        }
      }).addTo(assetsLayer);
    }
  });

  // Filter and add customers (PHP data)
  customers.forEach(customer => {
    if (!query || customer.NAMA.toLowerCase().includes(query) || customer.IDPEL.toLowerCase().includes(query) || customer.ALAMAT.toLowerCase().includes(query)) {
      if (customer.TIKOR && customer.TIKOR.includes(',')) {
        const coords = customer.TIKOR.split(',');
        const lat = parseFloat(coords[0]);
        const lng = parseFloat(coords[1]);
        const iconUrl = customer.status === 'online' ? 'customer-green2.png' : 'customer-red2.png';
        const marker = L.marker([lat, lng], {
          icon: L.icon({
            iconUrl: iconUrl,
            iconSize: [75, 75],
            iconAnchor: [37.5, 60]
          })
        });
        marker.bindPopup(`<b>${customer.NAMA}</b><br>IDxxxxxxxxxxxx: ${customer.IDPEL}<br>Alamat: ${customer.ALAMAT}<br>Paket: ${customer.PAKET}<br>ODP: ${customer.ODP}<br>Status: ${customer.status}`);
        marker.addTo(customersLayer);
        marker.on('mouseover', function() { this.openPopup(); });
        marker.on('mouseout', function() { this.closePopup(); });
        bounds.extend(marker.getLatLng());

        // Animated line if needed
        if (customer.ODP && !disableDash) {
          const odp = assets.find(a => {
            const parsed = safeJsonParse(a.attributes, {});
            return parsed.id_asset === customer.ODP;
          });
          if (odp) {
            const odpGeom = safeJsonParse(odp.geom);
            if (!odpGeom || !Array.isArray(odpGeom.coordinates)) {
              return;
            }
            const odpCoords = odpGeom.coordinates;
            const lineColor = customer.status === 'online' ? 'green' : 'red';
            const popupContent = `<b>Customer Line</b><br>To ODP: ${customer.ODP}<br>Status: ${customer.status}<br>Color: ${lineColor}`;
            if (disableAnimation) {
              const polyline = L.polyline([[lat, lng], [odpCoords[1], odpCoords[0]]], {
                color: lineColor,
                weight: 4,
                opacity: 1.0
              });
              polyline.bindPopup(popupContent);
              polyline.on('mouseover', function() { this.openPopup(); });
              polyline.on('mouseout', function() { this.closePopup(); });
              polyline.addTo(customersLayer);
              bounds.extend(polyline.getBounds());
            } else {
              let options = {
                "delay": 1200,
                "weight": 4,
                "color": lineColor,
                "opacity": 1.0,
                "pulseColor": "#FFFFFF",
                "paused": !isTabVisible, // Pause if tab not visible
                "reverse": false,
                "hardwareAccelerated": true,
                "interactive": true,
                "dashArray": [10, 20]
              };
              const antPath = L.polyline.antPath([[lat, lng], [odpCoords[1], odpCoords[0]]], options);
              antPath.bindPopup(popupContent, {closeOnClick: false});
              antPath.on('mouseover', function() { this.openPopup(); });
              antPath.on('mouseout', function() { this.closePopup(); });
              antPath.addTo(customersLayer);
              antPaths.push(antPath);
              bounds.extend(antPath.getBounds());
            }
          }
        }
      }
    }
    
  });

  // Fit bounds if valid
  if (bounds.isValid()) {
    map.fitBounds(bounds, { padding: [20, 20] });
    mapHasAutoFitted = true;
  }

  // Note: Serverlog customers are not filtered for simplicity
}

function clearBrowserCache() {
  // Clear localStorage except map view
  const mapCenter = localStorage.getItem('mapCenter');
  const mapZoom = localStorage.getItem('mapZoom');
  const mapMode = localStorage.getItem('mapMode');
  localStorage.clear();
  if (mapCenter) localStorage.setItem('mapCenter', mapCenter);
  if (mapZoom) localStorage.setItem('mapZoom', mapZoom);
  if (mapMode) localStorage.setItem('mapMode', mapMode);

  // Clear sessionStorage
  sessionStorage.clear();

  // Force reload of dynamic content if needed
  console.log('Browser cache cleared');
}

</script>

<?php
require 'footer.php';
?>
