<?php
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Mapping_ODP', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Mapping ODP.</div></div>';
        require 'footer.php';
        exit;
    }
}


// Check if column 'Hirarki' exists in odp table
$result = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE 'Hirarki'");
if (mysqli_num_rows($result) == 0) {
    $alterQuery = "ALTER TABLE odp ADD COLUMN Hirarki VARCHAR(255) DEFAULT NULL";
    if (mysqli_query($conn, $alterQuery)) {
        echo "<script>alert('Kolom Hirarki berhasil ditambahkan ke tabel odp.');</script>";
    } else {
        echo "<script>alert('Gagal menambahkan kolom Hirarki: " . mysqli_error($conn) . "');</script>";
    }
}

// Check if column 'splitter' exists in odp table
$resultSplitter = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE 'splitter'");
if (mysqli_num_rows($resultSplitter) == 0) {
    $alterQuerySplitter = "ALTER TABLE odp ADD COLUMN splitter ENUM('1:2', '1:4', '1:8', '1:16', '1:32') DEFAULT NULL";
    if (mysqli_query($conn, $alterQuerySplitter)) {
        echo "<script>alert('Kolom splitter berhasil ditambahkan ke tabel odp.');</script>";
    } else {
        echo "<script>alert('Gagal menambahkan kolom splitter: " . mysqli_error($conn) . "');</script>";
    }
} else {
    $modifyQuerySplitter = "ALTER TABLE odp MODIFY COLUMN splitter ENUM('1:2', '1:4', '1:8', '1:16', '1:32') DEFAULT NULL";
    if (!mysqli_query($conn, $modifyQuerySplitter)) {
        echo "<script>alert('Gagal mengupdate kolom splitter: " . mysqli_error($conn) . "');</script>";
    }
}

// Check if column 'hirarki_parent' exists in odp table
$resultHirarkiParent = mysqli_query($conn, "SHOW COLUMNS FROM odp LIKE 'hirarki_parent'");
if (mysqli_num_rows($resultHirarkiParent) == 0) {
    $alterQueryHirarkiParent = "ALTER TABLE odp ADD COLUMN hirarki_parent VARCHAR(255) DEFAULT NULL";
    if (mysqli_query($conn, $alterQueryHirarkiParent)) {
        echo "<script>alert('Kolom hirarki_parent berhasil ditambahkan ke tabel odp.');</script>";
    } else {
        echo "<script>alert('Gagal menambahkan kolom hirarki_parent: " . mysqli_error($conn) . "');</script>";
    }
}

// Pastikan tabel relasi odp_server ada
$tblCheckOdpServer = mysqli_query($conn, "SHOW TABLES LIKE 'odp_server'");
if (!$tblCheckOdpServer || mysqli_num_rows($tblCheckOdpServer) == 0) {
    mysqli_query($conn, "CREATE TABLE odp_server (
        id INT AUTO_INCREMENT PRIMARY KEY,
        odp_kode VARCHAR(255) NOT NULL,
        pemilik VARCHAR(255) NOT NULL,
        area VARCHAR(255) NOT NULL,
        KEY idx_odp_kode (odp_kode),
        KEY idx_pemilik_area (pemilik, area),
        UNIQUE KEY uniq_odp_pemilik_area (odp_kode, pemilik, area)
    )");
} else {
    // Migrasi: buang baris duplikat lama (odp_kode+pemilik+area sama persis),
    // lalu pasang UNIQUE KEY supaya duplikat seperti ini tidak bisa terjadi lagi.
    $idxCheckOdpServer = mysqli_query($conn, "SHOW INDEX FROM odp_server WHERE Key_name='uniq_odp_pemilik_area'");
    if (!$idxCheckOdpServer || mysqli_num_rows($idxCheckOdpServer) == 0) {
        mysqli_query($conn, "DELETE t1 FROM odp_server t1 INNER JOIN odp_server t2
            ON t1.odp_kode = t2.odp_kode AND t1.pemilik = t2.pemilik AND t1.area = t2.area AND t1.id > t2.id");
        mysqli_query($conn, "ALTER TABLE odp_server ADD UNIQUE KEY uniq_odp_pemilik_area (odp_kode, pemilik, area)");
    }
}

// Ambil daftar Product (server+area) yang boleh dipakai user ini
$productOptions = [];
if ($current_user_id) {
    if ($AKSES == 'ASSISTANT') {
        $qProductOpt = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
    } else {
        $qProductOpt = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
    }
    if ($qProductOpt) {
        while ($rowProductOpt = mysqli_fetch_assoc($qProductOpt)) {
            $productOptions[] = [
                'pemilik' => $rowProductOpt['PEMILIK'],
                'area'    => $rowProductOpt['AREA'],
                'brand'   => $rowProductOpt['BRAND'],
            ];
        }
    }
}

// Ambil semua relasi odp_server, dikelompokkan per KODE ODP
$odpServerMap = [];
$qOdpServerAll = mysqli_query($conn, "SELECT odp_kode, pemilik, area FROM odp_server");
if ($qOdpServerAll) {
    while ($rowRel = mysqli_fetch_assoc($qOdpServerAll)) {
        $kodeRel = $rowRel['odp_kode'];
        if (!isset($odpServerMap[$kodeRel])) $odpServerMap[$kodeRel] = [];
        $odpServerMap[$kodeRel][$rowRel['pemilik'] . '|' . $rowRel['area']] = true;
    }
}

// Ambil semua relasi odp_server lengkap (dengan brand) untuk tampilan tabel
$odpServerDetails = []; // KODE => [['pemilik'=>,'area'=>,'brand'=>], ...]
$qOdpServerDetails = mysqli_query($conn, "SELECT os.odp_kode, os.pemilik, os.area, s.BRAND FROM odp_server os LEFT JOIN server s ON os.pemilik=s.PEMILIK AND os.area=s.AREA");
if ($qOdpServerDetails) {
    while ($rowD = mysqli_fetch_assoc($qOdpServerDetails)) {
        $kodeD = $rowD['odp_kode'];
        if (!isset($odpServerDetails[$kodeD])) $odpServerDetails[$kodeD] = [];
        $odpServerDetails[$kodeD][] = [
            'pemilik' => $rowD['pemilik'],
            'area'    => $rowD['area'],
            'brand'   => $rowD['BRAND'] ?? '',
        ];
    }
}

/**
 * Render checkbox list Product (server+area) untuk form Add/Edit ODP.
 */
function renderProductCheckboxes($productOptions, $checkedPairs, $idPrefix) {
    if (empty($productOptions)) {
        echo '<div class="text-muted small">Belum ada Product. Silakan buat server terlebih dahulu.</div>';
        return;
    }
    echo '<div class="product-checkbox-list" style="max-height:220px;overflow-y:auto;border:1px solid var(--border-color);border-radius:6px;padding:10px;">';
    foreach ($productOptions as $idx => $opt) {
        $pemilikEsc = htmlspecialchars($opt['pemilik'], ENT_QUOTES, 'UTF-8');
        $areaEsc    = htmlspecialchars($opt['area'],    ENT_QUOTES, 'UTF-8');
        $brandEsc   = htmlspecialchars($opt['brand'],   ENT_QUOTES, 'UTF-8');
        $pairKey    = $opt['pemilik'] . '|' . $opt['area'];
        $isChecked  = isset($checkedPairs[$pairKey]) ? 'checked' : '';
        $cbId       = 'product_cb_' . $idPrefix . '_' . $idx;
        echo '<div class="form-check">';
        echo '<input class="form-check-input product-assign-checkbox" type="checkbox" name="server[]" value="' . $pemilikEsc . '" data-area="' . $areaEsc . '" id="' . $cbId . '" ' . $isChecked . '>';
        echo '<label class="form-check-label" for="' . $cbId . '">' . $brandEsc . ' - ' . $areaEsc . '</label>';
        echo '</div>';
    }
    echo '</div>';
}
?>

<script>
// ============================================================
// Helper: sinkronkan hidden input area_map (JSON {PEMILIK: AREA})
// dari checkbox product yang dicentang di dalam sebuah form.
// Dipanggil sebelum submit form Add ODP / Edit ODP.
// ============================================================
function syncProductAreaMap(formEl) {
    if (!formEl) return 0;
    var checkboxes = formEl.querySelectorAll('.product-assign-checkbox:checked');
    var map = {};
    checkboxes.forEach(function(cb) {
        map[cb.value] = cb.getAttribute('data-area') || '';
    });
    var hiddenInput = formEl.querySelector('input[name="area_map"]');
    if (!hiddenInput) {
        hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'area_map';
        formEl.appendChild(hiddenInput);
    }
    hiddenInput.value = JSON.stringify(map);
    return checkboxes.length;
}
</script>

<br>

<?php
$status = $_GET['status'] ?? '';
$msg    = $_GET['msg']    ?? '';
if ($status == 'success'): ?>
    <script>alert('Berhasil! <?php echo addslashes(nl2br(htmlspecialchars(urldecode($msg)))); ?>');</script>
<?php elseif ($status == 'cannot_delete'): ?>
    <script>alert('Gagal! <?php echo addslashes(nl2br(htmlspecialchars(urldecode($msg ?: 'ODP masih digunakan atau masih memiliki turunan terkait, tidak dapat dihapus.')))); ?>');</script>
<?php elseif ($status == 'error'): ?>
    <script>alert('Gagal! <?php echo addslashes(nl2br(htmlspecialchars(urldecode($msg)))); ?>');</script>
<?php endif; ?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">

        <!-- Header with Stats -->
        <div class="d-flex justify-content-between align-items-center mb-4 px-4 pt-4">
          <div>
            <h4 class="mb-1" style="font-weight:700;"><i class="fas fa-network-wired me-2"></i>ODP Management</h4>
            <p class="text-muted mb-0" style="font-size:0.85rem;">Kelola data ODP dan ODC infrastruktur jaringan</p>
          </div>
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dataModal">
            <i class="fas fa-plus"></i> Tambah ODP
          </button>
        </div>

        <!-- Stats Row -->
        <div class="row g-3 px-4 mb-4">
          <?php
            $sqlStats = "SELECT COUNT(*) as total, SUM(CASE WHEN Hirarki='ODC' THEN 1 ELSE 0 END) as odc, SUM(CASE WHEN Hirarki='ODP' AND hirarki_parent IS NOT NULL THEN 1 ELSE 0 END) as terikat FROM odp";
            if ($AKSES != 'ASSISTANT') {
                $qr  = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id=$current_user_id");
                $ids = [];
                while ($r = mysqli_fetch_assoc($qr)) { $ids[] = "'" . $r['PEMILIK'] . "'"; }
                $l = count($ids) > 0 ? implode(",", $ids) : "''";
                $sqlStats .= " WHERE pemilik IN ($l)";
            } else {
                $sqlStats .= " WHERE AREA IN ($area_list)";
            }
            $rs  = mysqli_query($conn, $sqlStats);
            $st  = mysqli_fetch_assoc($rs);
            $tot = (int)($st['total']   ?? 0);
            $odc = (int)($st['odc']     ?? 0);
            $ter = (int)($st['terikat'] ?? 0);
            $ntier = $tot - $odc - $ter;

            $sqlHompas = "SELECT
              SUM(CASE WHEN splitter='1:2' THEN 2
                       WHEN splitter='1:4' THEN 4
                       WHEN splitter='1:8' THEN 8
                       WHEN splitter='1:16' THEN 16
                       WHEN splitter='1:32' THEN 32
                       ELSE 0 END) as total_ports
              FROM odp WHERE Hirarki='ODP'";
            if ($AKSES != 'ASSISTANT') { $sqlHompas .= " AND pemilik IN ($l)"; } else { $sqlHompas .= " AND AREA IN ($area_list)"; }
            $rs_hompas   = mysqli_query($conn, $sqlHompas);
            $hompas_data = mysqli_fetch_assoc($rs_hompas);
            $total_hompas = (int)($hompas_data['total_ports'] ?? 0);

            $sqlHompasIsi = "SELECT COUNT(*) as total_pelanggan FROM pelanggan p INNER JOIN odp o ON p.ODP = o.KODE";
            if ($AKSES != 'ASSISTANT') { $sqlHompasIsi .= " WHERE o.pemilik IN ($l)"; } else { $sqlHompasIsi .= " WHERE o.AREA IN ($area_list)"; }
            $rs_hompas_isi   = mysqli_query($conn, $sqlHompasIsi);
            $hompas_isi_data = mysqli_fetch_assoc($rs_hompas_isi);
            $total_hompas_terisi = (int)($hompas_isi_data['total_pelanggan'] ?? 0);
          ?>
          <div class="col-md-6 col-lg-3 mb-2">
            <div class="card stat-card border-0 shadow-sm" style="background:linear-gradient(135deg,var(--primary-color) 0%,var(--secondary-color) 100%);color:white;">
              <div class="card-body p-4">
                <h2 class="m-0" style="font-weight:700;font-size:1.8rem;"><?= $odc; ?>/<?= $tot; ?></h2>
                <small style="opacity:0.95;font-weight:500;">Total ODC / Total ODP</small>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-lg-3 mb-2">
            <div class="card stat-card border-0 shadow-sm" style="background:linear-gradient(135deg,var(--info) 0%,#0284c7 100%);color:white;">
              <div class="card-body p-4">
                <h2 class="m-0" style="font-weight:700;font-size:1.8rem;"><?= $ter; ?></h2>
                <small style="opacity:0.95;font-weight:500;">ODP Terhubung</small>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-lg-3 mb-2">
            <div class="card stat-card border-0 shadow-sm" style="background:linear-gradient(135deg,var(--warning) 0%,#d97706 100%);color:white;">
              <div class="card-body p-4">
                <h2 class="m-0" style="font-weight:700;font-size:1.8rem;"><?= $ntier; ?></h2>
                <small style="opacity:0.95;font-weight:500;">ODP Rasio</small>
              </div>
            </div>
          </div>
          <div class="col-md-6 col-lg-3 mb-2">
            <div class="card stat-card border-0 shadow-sm" style="background:linear-gradient(135deg,var(--danger) 0%,#dc2626 100%);color:white;">
              <div class="card-body p-4">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                  <small style="display:block;opacity:0.95;font-weight:500;">Total Hompas</small>
                  <div style="text-align:right;">
                    <h2 class="m-0" style="font-weight:700;font-size:1.8rem;"><?= $total_hompas_terisi; ?>/<?= $total_hompas; ?></h2>
                    <small style="display:block;opacity:0.8;font-size:0.75rem;margin-top:4px;">Terisi / Total</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /card -->

      <div class="card mb-4">
        <div class="card-body px-4 pt-4 pb-3">
          <div class="d-flex gap-2 mb-4 flex-wrap">
            <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#importModal"><i class="fas fa-upload"></i> Import</button>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importKmzModal"><i class="fas fa-map"></i> KMZ</button>
            <a href="proses/export_odp_excel.php" class="btn btn-success btn-sm" target="_blank"><i class="fas fa-download"></i> Excel</a>
            <a href="proses/export_odp_kml.php" class="btn btn-info btn-sm" target="_blank"><i class="fas fa-map"></i> KML</a>
          </div>

          <style>
/* ===== ODP PAGE STYLING ===== */
.filter-section {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  padding: 16px 18px;
  background-color: var(--bg-light);
  border-bottom: 1px solid var(--border-color);
  border-radius: 0 0 var(--radius-lg) var(--radius-lg);
}
.filter-section select, .filter-section input {
  font-size: 0.85em; padding: 10px 12px; border-radius: var(--radius);
  border: 1px solid var(--border-color); background-color: var(--white);
  color: var(--text-primary); transition: all 0.2s ease; width: 100%;
}
.filter-section select:focus, .filter-section input:focus {
  border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); outline: none;
}
.filter-section label {
  font-size: 0.75em; font-weight: 600; text-transform: uppercase;
  color: var(--text-muted); margin-bottom: 6px; display: block; letter-spacing: 0.4px;
}
.odp-table { font-size: 0.875em; color: var(--text-primary); }
.odp-table thead th {
  font-size: 0.75em; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px;
  padding: 14px 12px;
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
  border-bottom: 2px solid var(--primary-color); color: white;
}
.odp-table tbody td {
  padding: 16px 12px; vertical-align: middle;
  border-bottom: 1px solid var(--border-color); font-size: 0.9rem; color: var(--text-primary);
}
.odp-table tbody tr:hover { background-color: var(--bg-light); }
.odp-table tbody tr.group-header:hover { background-color: var(--primary-color) !important; }
.action-buttons { display: flex; gap: 6px; flex-wrap: nowrap; justify-content: center; }
.action-buttons .btn {
  padding: 8px 14px; font-size: 0.75rem; font-weight: 600;
  white-space: nowrap; border: none; border-radius: var(--radius); transition: all 0.2s ease;
}
.btn-xs { padding: 6px 12px; font-size: 0.75rem; font-weight: 600; border-radius: var(--radius); }
.btn-xs i { font-size: 0.8rem; margin-right: 4px; }
.row-odc { background-color: rgba(59,130,246,0.08); }
.tree-child-prefix { color: var(--text-muted); font-weight: 500; margin-right: 4px; }
.tree-child-name { padding-left: 8px; color: var(--text-primary); }
.group-separator td { height: 8px; padding: 0 !important; border: 0 !important; background: transparent !important; }
.group-header th, .group-header td {
  background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%) !important;
  color: white !important; font-weight: 700; letter-spacing: 0.3px;
  border-bottom: 1px solid var(--primary-color) !important;
  padding: 12px 14px !important; font-size: 0.9rem;
}
.group-header-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.group-header-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: white; }
.group-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.group-header-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.group-header-avatar {
  width: 36px; height: 36px; border-radius: 50%; object-fit: cover;
  border: 2px solid rgba(255,255,255,0.8); flex-shrink: 0;
}
.group-header-actions .btn { padding: 6px 12px; font-size: 0.7rem; line-height: 1.2; }
.port-badge { padding: 6px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85rem; display: inline-block; }
.port-badge-filled { background-color: rgba(59,130,246,0.2); color: var(--primary-color); }
.port-badge-empty  { background-color: rgba(16,185,129,0.2); color: var(--success); }
.product-pill {
  display: inline-block;
  background: rgba(59,130,246,0.12);
  color: var(--primary-color);
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 0.72rem;
  font-weight: 600;
  white-space: nowrap;
  margin: 1px 2px 1px 0;
}
@media (max-width: 576px) {
  .group-header-bar { flex-direction: column; align-items: flex-start; }
  .filter-section { grid-template-columns: 1fr; gap: 10px; }
}

/* Dark theme */
body.app-theme-dark .filter-section { background-color: #1a233a !important; border-bottom-color: rgba(59,130,246,0.2) !important; }
body.app-theme-dark .filter-section label { color: #cbd5e1 !important; }
body.app-theme-dark .filter-section select, body.app-theme-dark .filter-section input {
  background-color: #0f172a !important; border: 1px solid rgba(59,130,246,0.3) !important; color: #e2e8f0 !important;
}
body.app-theme-dark .filter-section select:focus, body.app-theme-dark .filter-section input:focus {
  border-color: #3b82f6 !important; box-shadow: 0 0 0 3px rgba(59,130,246,0.2) !important;
  background-color: #0f172a !important; color: #f1f5f9 !important;
}
body.app-theme-dark .odp-table { color: #e2e8f0 !important; }
body.app-theme-dark .odp-table tbody td { border-bottom-color: rgba(59,130,246,0.15) !important; color: #e2e8f0 !important; }
body.app-theme-dark .odp-table tbody tr:hover { background-color: rgba(59,130,246,0.1) !important; }
body.app-theme-dark .row-odc { background-color: rgba(59,130,246,0.15) !important; }
body.app-theme-dark .tree-child-prefix { color: #94a3b8 !important; }
body.app-theme-dark .tree-child-name { color: #e2e8f0 !important; }
body.app-theme-dark .port-badge-filled { background-color: rgba(59,130,246,0.3) !important; color: #60a5fa !important; }
body.app-theme-dark .port-badge-empty  { background-color: rgba(16,185,129,0.3) !important; color: #6ee7b7 !important; }
body.app-theme-dark .product-pill { background: rgba(59,130,246,0.25) !important; color: #93c5fd !important; }
body.app-theme-dark .card { background-color: #0f172a !important; border-color: rgba(59,130,246,0.2) !important; }
body.app-theme-dark .card-body { color: #e2e8f0 !important; }
body.app-theme-dark .odp-table thead th { background: linear-gradient(135deg,#1e40af 0%,#1e3a8a 100%) !important; color: #f1f5f9 !important; }
body.app-theme-dark .group-header th, body.app-theme-dark .group-header td {
  background: linear-gradient(135deg,#1e40af 0%,#1e3a8a 100%) !important; color: #f0f9ff !important;
}
body.app-theme-dark span[style*="background:#e0e7ff"] { background-color: #3b82f6 !important; color: white !important; }
body.app-theme-dark span[style*="background:#d4edda"] { background-color: #22c55e !important; color: white !important; }
body.app-theme-dark .modal-content { background-color: #0f172a !important; border-color: rgba(59,130,246,0.2) !important; color: #e2e8f0 !important; }
body.app-theme-dark .modal-header { border-bottom-color: rgba(59,130,246,0.2) !important; }
body.app-theme-dark .form-label { color: #cbd5e1 !important; }
body.app-theme-dark h4, body.app-theme-dark h5, body.app-theme-dark h6 { color: #e2e8f0 !important; }
body.app-theme-dark .text-muted { color: #94a3b8 !important; }

@media (max-width: 767.98px) {
  .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .odp-table { min-width: 980px; }
  .odp-table thead { display: table-header-group !important; }
  .odp-table .d-none.d-md-table-cell { display: table-cell !important; }
  .odp-table .d-md-none { display: none !important; }
}
#pelangganModalBody .table-responsive { overflow-x: scroll; overflow-y: visible; -webkit-overflow-scrolling: touch; max-width: 100%; }
#pelangganModalBody .pelanggan-table { min-width: 920px; table-layout: auto; }
          </style>

          <!-- Filter Section -->
          <div class="filter-section mb-0">
            <div>
              <label>Hirarki</label>
              <select id="filterHirarki" onchange="filterOdpTable()">
                <option value="">All Hirarki</option>
                <option value="ODC">ODC</option>
                <option value="ODP">ODP</option>
                <option value="ODP-RASIO">ODP-RASIO</option>
                <option value="ODP-JUMPER">ODP-JUMPER</option>
              </select>
            </div>
            <div>
              <label>Area</label>
              <select id="filterArea" onchange="filterOdpTable()">
                <option value="">All Area</option>
                <?php
                if ($AKSES == 'ASSISTANT') {
                    $queryAreas = mysqli_query($conn, "SELECT DISTINCT AREA FROM odp WHERE AREA IS NOT NULL AND AREA!='' AND AREA IN ($area_list) ORDER BY AREA");
                } else {
                    $queryAreas = mysqli_query($conn, "SELECT DISTINCT o.AREA FROM odp o INNER JOIN server s ON o.PEMILIK=s.PEMILIK WHERE o.AREA IS NOT NULL AND o.AREA!='' AND s.user_id=$current_user_id ORDER BY o.AREA");
                }
                while ($rowArea = mysqli_fetch_assoc($queryAreas)) {
                    echo '<option value="' . htmlspecialchars($rowArea['AREA']) . '">' . htmlspecialchars($rowArea['AREA']) . '</option>';
                }
                ?>
              </select>
            </div>
            <div>
              <label>Server Area</label>
              <select id="filterProduct" onchange="filterOdpTable()">
                <option value="">All Server Area</option>
                <?php
                if ($AKSES == 'ASSISTANT') {
                    $queryProducts = mysqli_query($conn, "SELECT DISTINCT BRAND FROM odp WHERE BRAND IS NOT NULL AND BRAND!='' AND AREA IN ($area_list) ORDER BY BRAND");
                } else {
                    $queryProducts = mysqli_query($conn, "SELECT DISTINCT o.BRAND FROM odp o INNER JOIN server s ON o.PEMILIK=s.PEMILIK WHERE o.BRAND IS NOT NULL AND o.BRAND!='' AND s.user_id=$current_user_id ORDER BY o.BRAND");
                }
                while ($rowProduct = mysqli_fetch_assoc($queryProducts)) {
                    echo '<option value="' . htmlspecialchars($rowProduct['BRAND']) . '">' . htmlspecialchars($rowProduct['BRAND']) . '</option>';
                }
                ?>
              </select>
            </div>
            <div>
              <label>Splitter</label>
              <select id="filterSplitter" onchange="filterOdpTable()">
                <option value="">All Splitter</option>
                <option value="1:2">1:2</option>
                <option value="1:4">1:4</option>
                <option value="1:8">1:8</option>
                <option value="1:16">1:16</option>
                <option value="1:32">1:32</option>
              </select>
            </div>
            <div>
              <label>Search</label>
              <input type="text" id="searchOdpInput" placeholder="Kode, Nama, Area..." onkeyup="filterOdpTable()">
            </div>
          </div>

          <div class="table-responsive p-0">
            <table class="table align-items-center mb-0 odp-table">
              <thead>
                <tr>
                  <th style="width:5%;text-align:center;">IMG</th>
                  <th style="width:22%;">NAMA ODP</th>
                  <th style="width:20%;">AREA / PRODUCT</th>
                  <th style="width:9%;text-align:center;">PORT TERISI</th>
                  <th style="width:9%;text-align:center;">PORT SISA</th>
                  <th style="width:12%;text-align:center;">ACTION</th>
                </tr>
              </thead>
              <tbody id="dataTableMain" class="odp-data-table">
                <?php
                // Lazy-load: kirim 20 baris per fetch supaya tabel ODP yang besar tidak
                // membuat browser lag/crash saat render awal. Self-POST ke halaman ini
                // sendiri (lihat script self-POST di bawah), page berikutnya di-append.
                $odpPageSize = 20;
                $odpPage = isset($_POST['page']) ? (int)$_POST['page'] : 1;
                if ($odpPage < 1) $odpPage = 1;

                if ($AKSES != 'ASSISTANT') {
                    $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
                    $userServerIds = [];
                    while ($row = mysqli_fetch_assoc($queryServerId)) { $userServerIds[] = "'" . $row['PEMILIK'] . "'"; }
                    $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
                    $odpWhere = "o.pemilik IN ($userServerList)";
                } else {
                    $odpWhere = "o.AREA IN ($area_list)";
                }
                $odpOrderBy = "ORDER BY
                              CASE WHEN o.Hirarki='ODC' THEN 0 WHEN o.Hirarki='ODP' THEN 1 WHEN o.Hirarki IN ('ODP-RASIO','ODP-JUMPER') THEN 2 ELSE 3 END,
                              COALESCE(o.hirarki_parent, o.KODE),
                              CASE WHEN o.Hirarki='ODC' THEN 0 ELSE 1 END,
                              o.KODE";

                $odpCountRow  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM odp o WHERE $odpWhere"));
                $odpTotalRows = (int)($odpCountRow['total'] ?? 0);
                $odpTotalPages = max(1, (int)ceil($odpTotalRows / $odpPageSize));
                $odpPage   = min($odpPage, $odpTotalPages);
                $odpOffset = ($odpPage - 1) * $odpPageSize;

                $sql = "SELECT o.* FROM odp o WHERE $odpWhere $odpOrderBy LIMIT $odpPageSize OFFSET $odpOffset";

                $query = mysqli_query($conn, $sql);
                $rowNo = 0;
                $editModalsHtml = [];
                while ($data = mysqli_fetch_array($query)):
                    $rowNo++;
                    $isOdc = ((string)($data['Hirarki'] ?? '') === 'ODC');
                    $kodeOdp = $data['KODE'];

                    // Ambil product list dari odp_server (sudah di-preload)
                    $prodList = isset($odpServerDetails[$kodeOdp]) ? $odpServerDetails[$kodeOdp] : [];
                    // Fallback ke kolom lama
                    if (empty($prodList)) {
                        $prodList = [['pemilik' => $data['PEMILIK'], 'area' => $data['AREA'], 'brand' => $data['BRAND'] ?? '']];
                    }

                    // data-area: area utama (kolom lama) untuk filter area
                    $dataArea = strtolower((string)($data['AREA'] ?? ''));
                    // data-product: semua brand yang terikat (spasi-separated untuk filter)
                    $allBrands = array_unique(array_map(function($p){ return strtolower($p['brand'] ?? $p['area']); }, $prodList));
                    $dataProduct = implode(' ', $allBrands);

                    // Port info
                    $totalPorts = 0;
                    if (!empty($data['splitter']) && strpos($data['splitter'], ':') !== false) {
                        $sp = explode(':', $data['splitter']);
                        $totalPorts = (int)$sp[1];
                    }
                    $countPelanggan = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM pelanggan WHERE ODP='" . mysqli_real_escape_string($conn, $kodeOdp) . "'"))['total'] ?? 0);
                    $portTerisi = $countPelanggan;
                    $portKosong = max(0, $totalPorts - $portTerisi);

                    // data-search: gabungan semua field + semua area & brand dari prodList
                    $allAreasBrands = implode(' ', array_map(function($p){ return ($p['area'] ?? '') . ' ' . ($p['brand'] ?? ''); }, $prodList));
                    $dataSearch = strtolower(trim(
                        ($data['KODE'] ?? '') . ' ' .
                        ($data['NAME'] ?? '') . ' ' .
                        ($data['TIKOR'] ?? '') . ' ' .
                        ($data['AREA'] ?? '') . ' ' .
                        ($data['BRAND'] ?? '') . ' ' .
                        ($data['Hirarki'] ?? '') . ' ' .
                        ($data['splitter'] ?? '') . ' ' .
                        ($data['hirarki_parent'] ?? '') . ' ' .
                        $allAreasBrands
                    ));
                ?>
                <tr class="<?php echo $isOdc ? 'row-odc' : ''; ?>"
                    data-kode="<?php echo htmlspecialchars((string)($data['KODE'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-name="<?php echo htmlspecialchars((string)($data['NAME'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-parent="<?php echo htmlspecialchars((string)($data['hirarki_parent'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    data-hirarki="<?php echo htmlspecialchars(strtolower((string)($data['Hirarki'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                    data-area="<?php echo htmlspecialchars($dataArea, ENT_QUOTES, 'UTF-8'); ?>"
                    data-product="<?php echo htmlspecialchars($dataProduct, ENT_QUOTES, 'UTF-8'); ?>"
                    data-splitter="<?php echo htmlspecialchars(strtolower((string)($data['splitter'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
                    data-pelanggan="<?php echo $countPelanggan; ?>"
                    data-search="<?php echo htmlspecialchars($dataSearch, ENT_QUOTES, 'UTF-8'); ?>"
                >
                  <!-- Mobile cell -->
                  <td class="d-md-none">
                    <div class="d-flex px-2 py-1">
                      <div>
                        <?php
                        $img_url = '../../dokumen/odp/odp_' . $data['id'] . '.jpg';
                        if (file_exists($img_url)) {
                            echo '<img src="' . $img_url . '" class="avatar me-3" alt="ODP" style="width:80px;height:80px;object-fit:cover;border-radius:8px;">';
                        } elseif ($data['Hirarki'] == 'ODC') {
                            echo '<img src="assets/img/ODC.png" class="avatar me-3" alt="ODC" style="width:80px;height:80px;object-fit:cover;border-radius:8px;">';
                        } else {
                            echo '<img src="odpmap2.png" class="avatar me-3" alt="ODP" style="width:80px;height:80px;object-fit:cover;border-radius:8px;">';
                        }
                        ?>
                      </div>
                      <div class="d-flex flex-column justify-content-center">
                        <h6 class="mb-0 text-sm">
                          <strong><?php echo htmlspecialchars($data['KODE']); ?></strong><br>
                          <small><?php echo htmlspecialchars($data['NAME']); ?><br>
                          <?php echo htmlspecialchars($data['TIKOR']); ?><br>
                          <?php echo htmlspecialchars($data['Hirarki']); ?></small><br>
                          <!-- Product pills mobile -->
                          <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:3px;">
                            <?php foreach ($prodList as $pr): ?>
                              <span class="product-pill"><?php echo htmlspecialchars(($pr['brand'] ?: $pr['area']) . ' � ' . $pr['area']); ?></span>
                            <?php endforeach; ?>
                          </div>
                          <i class="fas fa-users"></i> <small><?php echo $countPelanggan; ?> Plg</small>
                          <div id="data-odp-sla-mobile-<?php echo htmlspecialchars((string)$data['KODE'], ENT_QUOTES, 'UTF-8'); ?>" style="margin-top:4px;">
                            <span class="badge badge-sm bg-gradient-info">SLA ODP 0.00%</span>
                          </div>
                        </h6>
                        <div class="mt-3 d-flex gap-2">
                          <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $data['id']; ?>">
                            <i class="fas fa-edit fa-sm"></i> Edit
                          </button>
                          <form action="proses/deleteodp.php" method="POST" style="display:inline-block;" onsubmit="return confirm('Yakin ingin menghapus ODP ini?');">
                            <input type="hidden" name="id" value="<?php echo $data['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash fa-sm"></i> Hapus</button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </td>

                  <!-- Desktop: Image -->
                  <td class="d-none d-md-table-cell" style="width:60px;">
                    <?php
                    $img_url = '../../dokumen/odp/odp_' . $data['id'] . '.jpg';
                    if (file_exists($img_url)) {
                        echo '<img src="' . $img_url . '" class="avatar" alt="ODP" style="width:46px;height:46px;object-fit:cover;border-radius:5px;">';
                    } elseif ($data['Hirarki'] == 'ODC') {
                        echo '<img src="assets/img/ODC.png" class="avatar" alt="ODC" style="width:46px;height:46px;object-fit:cover;border-radius:5px;">';
                    } else {
                        echo '<img src="odpmap2.png" class="avatar" alt="ODP" style="width:46px;height:46px;object-fit:cover;border-radius:5px;">';
                    }
                    ?>
                  </td>

                  <!-- Desktop: Nama ODP -->
                  <td class="d-none d-md-table-cell">
                    <div style="font-size:0.9rem;font-weight:600;color:var(--text-primary);"><?php echo htmlspecialchars($data['KODE']); ?></div>
                    <div style="font-size:0.8rem;color:var(--text-muted);margin-top:2px;">
                      <div><?php echo htmlspecialchars($data['NAME']); ?></div>
                      <div style="color:var(--primary-color);font-size:0.75rem;margin-top:2px;"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($data['TIKOR']); ?></div>
                    </div>
                    <div id="data-odp-sla-desktop-<?php echo htmlspecialchars((string)$data['KODE'], ENT_QUOTES, 'UTF-8'); ?>" style="margin-top:4px;">
                      <span class="badge badge-sm bg-gradient-info">SLA ODP 0.00%</span>
                    </div>
                  </td>

                  <!-- Desktop: Area / Product (multi) -->
                  <td class="d-none d-md-table-cell">
                    <div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:3px;"><?php echo htmlspecialchars($data['AREA']); ?></div>
                    <div style="display:flex;flex-wrap:wrap;gap:3px;">
                      <?php foreach ($prodList as $pr): ?>
                        <span class="product-pill"><?php echo htmlspecialchars(($pr['brand'] ?: $pr['area'])); ?> <i class="fas fa-angle-right" style="font-size:0.65em;"></i> <?php echo htmlspecialchars($pr['area']); ?></span>
                      <?php endforeach; ?>
                    </div>
                  </td>

                  <!-- Desktop: Port Terisi -->
                  <td class="d-none d-md-table-cell text-center">
                    <button type="button" class="port-badge port-badge-filled"
                        style="border:none;cursor:pointer;background:inherit;font-family:inherit;"
                        data-bs-toggle="modal"
                        data-bs-target="#pelangganModal"
                        data-odp-code="<?php echo htmlspecialchars($data['KODE']); ?>"
                        data-odp-name="<?php echo htmlspecialchars($data['NAME']); ?>"
                        title="Klik untuk melihat daftar pelanggan">
                      <?php echo $portTerisi; ?> <i class="fas fa-chevron-right ms-1" style="font-size:0.7em;"></i>
                    </button>
                  </td>

                  <!-- Desktop: Port Sisa -->
                  <td class="d-none d-md-table-cell text-center">
                    <span class="port-badge port-badge-empty"><?php echo $portKosong; ?></span>
                  </td>

                  <!-- Desktop: Action -->
                  <td class="d-none d-md-table-cell text-center">
                    <div class="action-buttons">
                      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $data['id']; ?>">
                        <i class="fas fa-edit"></i>
                      </button>
                      <form action="proses/deleteodp.php" method="POST" style="display:inline-block;" onsubmit="return confirm('Yakin ingin menghapus ODP ini?');">
                        <input type="hidden" name="id" value="<?php echo $data['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                      </form>
                    </div>
                  </td>
                </tr>

                <?php ob_start(); ?>
                <!-- Edit Modal -->
                <div class="modal fade" id="editModal<?php echo $data['id']; ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel<?php echo $data['id']; ?>">Edit ODP</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                      </div>
                      <div class="modal-body">
                        <form action="proses/editodp.php" method="POST" id="editForm<?php echo $data['id']; ?>" enctype="multipart/form-data">
                          <input type="hidden" name="id" value="<?php echo $data['id']; ?>">

                          <div class="mb-3">
                            <label for="gambar_odp<?php echo $data['id']; ?>" class="form-label">Foto ODP</label>
                            <input type="file" class="form-control" id="gambar_odp<?php echo $data['id']; ?>" name="gambar_odp" accept="image/*">
                            <?php
                            $img_path = '../../dokumen/odp/odp_' . $data['id'] . '.jpg';
                            if (file_exists($img_path)) {
                                echo '<img src="' . $img_path . '" alt="Gambar ODP" style="max-width:100px;max-height:100px;margin-top:5px;border-radius:6px;border:1px solid #ccc;">';
                            }
                            ?>
                          </div>

                          <div class="mb-3">
                            <label for="hirarki<?php echo $data['id']; ?>" class="form-label">Hirarki</label>
                            <select class="form-control" id="hirarki<?php echo $data['id']; ?>" name="hirarki">
                              <option value="">-- Pilih Hirarki --</option>
                              <option value="ODC"       <?php echo ($data['Hirarki'] == 'ODC')       ? 'selected' : ''; ?>>ODC</option>
                              <option value="ODP"       <?php echo ($data['Hirarki'] == 'ODP')       ? 'selected' : ''; ?>>ODP</option>
                              <option value="ODP-RASIO" <?php echo ($data['Hirarki'] == 'ODP-RASIO') ? 'selected' : ''; ?>>ODP-RASIO</option>
                              <option value="ODP-JUMPER"<?php echo ($data['Hirarki'] == 'ODP-JUMPER')? 'selected' : ''; ?>>ODP-JUMPER</option>
                            </select>
                          </div>

                          <div class="mb-3">
                            <label for="kode<?php echo $data['id']; ?>" class="form-label">Kode ODP</label>
                            <input type="text" class="form-control" id="kode<?php echo $data['id']; ?>" name="kode" required value="<?php echo htmlspecialchars($data['KODE']); ?>">
                          </div>

                          <div class="mb-3">
                            <label for="name<?php echo $data['id']; ?>" class="form-label">Name ODP</label>
                            <input type="text" class="form-control" id="name<?php echo $data['id']; ?>" name="name" required value="<?php echo htmlspecialchars($data['NAME']); ?>">
                          </div>

                          <div class="mb-3">
                            <label for="tikor<?php echo $data['id']; ?>" class="form-label">Coordinates</label>
                            <div class="input-group">
                              <input type="text" class="form-control" id="tikor<?php echo $data['id']; ?>" name="tikor" required value="<?php echo htmlspecialchars($data['TIKOR']); ?>">
                              <button type="button" class="btn btn-outline-primary" onclick="openMapPicker('tikor<?php echo $data['id']; ?>')">
                                <i class="fas fa-map-marker-alt"></i> Pilih dari Map
                              </button>
                            </div>
                          </div>

                          <div class="mb-3">
                            <label for="splitter<?php echo $data['id']; ?>" class="form-label">Splitter</label>
                            <select class="form-control" id="splitter<?php echo $data['id']; ?>" name="splitter">
                              <option value="">-- Pilih Splitter --</option>
                              <option value="1:2"  <?php echo ($data['splitter'] == '1:2')  ? 'selected' : ''; ?>>1:2</option>
                              <option value="1:4"  <?php echo ($data['splitter'] == '1:4')  ? 'selected' : ''; ?>>1:4</option>
                              <option value="1:8"  <?php echo ($data['splitter'] == '1:8')  ? 'selected' : ''; ?>>1:8</option>
                              <option value="1:16" <?php echo ($data['splitter'] == '1:16') ? 'selected' : ''; ?>>1:16</option>
                              <option value="1:32" <?php echo ($data['splitter'] == '1:32') ? 'selected' : ''; ?>>1:32</option>
                            </select>
                          </div>

                          <div class="mb-3" id="parentField<?php echo $data['id']; ?>">
                            <label for="hirarki_parent<?php echo $data['id']; ?>" class="form-label">Hirarki Parent (ODC)</label>
                            <select class="form-control" id="hirarki_parent<?php echo $data['id']; ?>" name="hirarki_parent">
                              <option value="">-- Pilih ODC Parent --</option>
                              <?php
                              $queryODC2 = mysqli_query($conn, "SELECT DISTINCT o.KODE, o.NAME, o.PEMILIK, o.AREA, s.BRAND FROM odp o LEFT JOIN server s ON o.PEMILIK=s.PEMILIK WHERE o.Hirarki='ODC'");
                              while ($rowODC2 = mysqli_fetch_assoc($queryODC2)) {
                                  $sel2 = ($data['hirarki_parent'] == $rowODC2['KODE']) ? 'selected' : '';
                                  echo '<option value="' . htmlspecialchars($rowODC2['KODE']) . '" data-server="' . htmlspecialchars($rowODC2['PEMILIK']) . '" data-area="' . htmlspecialchars($rowODC2['AREA']) . '" data-brand="' . htmlspecialchars($rowODC2['BRAND']) . '" ' . $sel2 . '>' . htmlspecialchars($rowODC2['KODE']) . ' - ' . htmlspecialchars($rowODC2['NAME']) . '</option>';
                              }
                              ?>
                            </select>
                          </div>

                          <div class="mb-3">
                            <label class="form-label">Assign ke Server Area <small class="text-muted">(bisa lebih dari satu)</small></label>
                            <?php
                            $kodeForCheck = $data['KODE'];
                            if (isset($odpServerMap[$kodeForCheck]) && !empty($odpServerMap[$kodeForCheck])) {
                                $checkedPairsEdit = $odpServerMap[$kodeForCheck];
                            } else {
                                $checkedPairsEdit = [($data['PEMILIK'] . '|' . $data['AREA']) => true];
                            }
                            renderProductCheckboxes($productOptions, $checkedPairsEdit, $data['id']);
                            ?>
                          </div>

                          <input type="hidden" name="area_map" value="{}">

                          <div class="modal-footer px-0 pb-0">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                </div>
                <?php $editModalsHtml[] = ob_get_clean(); ?>
                <?php endwhile; ?>
              </tbody>
            </table>
            <div id="odpLazyMeta" class="d-none" data-page="<?php echo (int)$odpPage; ?>" data-total-pages="<?php echo (int)$odpTotalPages; ?>"></div>

            <?php if ($odpPage < $odpTotalPages): ?>
            <div class="text-center my-3" id="odpLazyLoadWrap">
              <div id="odpLazyLoadIndicator" class="d-none">
                <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
                <span class="ms-2 text-secondary">Memuat data ODP berikutnya...</span>
              </div>
              <div id="odpLazyLoadSentinel" style="height: 1px;"></div>
            </div>
            <?php endif; ?>

            <div id="odpModalsContainer"><?php echo implode('', $editModalsHtml); ?></div>

            <!-- ODP-RASIO / ODP-JUMPER table (populated by JS) -->
            <div class="mt-4 px-0">
              <table class="table align-items-center mb-0 odp-table">
                <thead>
                  <tr>
                    <th style="width:5%;">IMG</th>
                    <th style="width:22%;">NAMA ODP</th>
                    <th style="width:20%;">AREA / PRODUCT</th>
                    <th style="width:9%;text-align:center;">PORT TERISI</th>
                    <th style="width:9%;text-align:center;">PORT SISA</th>
                    <th style="width:12%;text-align:center;">ACTION</th>
                  </tr>
                </thead>
                <tbody id="dataTableSpecial" class="odp-data-table"></tbody>
              </table>
            </div>

            <?php if ($odpPage < $odpTotalPages): ?>
            <script>
            (function() {
                var currentPage  = <?php echo (int)$odpPage; ?>;
                var totalPages   = <?php echo (int)$odpTotalPages; ?>;
                var isLoading    = false;

                var mainBody       = document.getElementById('dataTableMain');
                var modalsContainer = document.getElementById('odpModalsContainer');
                var indicator      = document.getElementById('odpLazyLoadIndicator');
                var sentinel       = document.getElementById('odpLazyLoadSentinel');

                if (!mainBody || !indicator || !sentinel) return;

                function showIndicator(show) {
                    indicator.classList.toggle('d-none', !show);
                }

                function finishLoading() {
                    var wrap = document.getElementById('odpLazyLoadWrap');
                    if (wrap) wrap.innerHTML = '<span class="text-muted small">Semua data ODP sudah dimuat.</span>';
                    // Data lengkap sudah ada di DOM: baru sekarang bangun hierarchy
                    // ODC/ODP-RASIO/JUMPER + jalankan filter, supaya agregat port
                    // terisi/total per ODC selalu dihitung dari data yang lengkap
                    // (tidak parsial saat masih lazy-loading).
                    if (typeof separateHierarchyTables === 'function') separateHierarchyTables();
                    if (typeof organizeMainHierarchy === 'function') organizeMainHierarchy();
                    if (typeof restoreOdpFilterState === 'function') restoreOdpFilterState();
                    if (typeof filterOdpTable === 'function') filterOdpTable();
                    if (typeof loadOdpSlaSummary === 'function') loadOdpSlaSummary();
                }

                function executeScripts(container) {
                    container.querySelectorAll('script').forEach(function(oldScript) {
                        var newScript = document.createElement('script');
                        Array.from(oldScript.attributes).forEach(function(attr) {
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        newScript.textContent = oldScript.textContent;
                        if (oldScript.parentNode) oldScript.parentNode.replaceChild(newScript, oldScript);
                    });
                }

                function appendNextPage() {
                    if (isLoading || currentPage >= totalPages) return Promise.resolve();
                    isLoading = true;
                    showIndicator(true);

                    // Self-POST ke halaman ini sendiri (satu-satunya sumber query/render ODP).
                    return fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        credentials: 'same-origin',
                        body: 'page=' + (currentPage + 1)
                    })
                        .then(function(res) { return res.text(); })
                        .then(function(html) {
                            var doc = new DOMParser().parseFromString(html, 'text/html');
                            var newMainBody = doc.getElementById('dataTableMain');
                            var newModalsContainer = doc.getElementById('odpModalsContainer');
                            var newMeta = doc.getElementById('odpLazyMeta');
                            if (!newMainBody) throw new Error('Gagal memuat data ODP');

                            mainBody.insertAdjacentHTML('beforeend', newMainBody.innerHTML);

                            if (modalsContainer && newModalsContainer) {
                                modalsContainer.insertAdjacentHTML('beforeend', newModalsContainer.innerHTML);
                                executeScripts(modalsContainer);
                            }

                            var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                            currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);

                            if (currentPage >= totalPages) {
                                finishLoading();
                            }
                        })
                        .catch(function(err) {
                            console.error('Gagal lazy load ODP:', err);
                        })
                        .finally(function() {
                            isLoading = false;
                            showIndicator(false);
                        });
                }

                var observer = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) appendNextPage();
                    });
                }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
                observer.observe(sentinel);

                window.addEventListener('scroll', function() {
                    if (isLoading || currentPage >= totalPages) return;
                    var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
                    if (nearBottom) appendNextPage();
                }, { passive: true });
            })();
            </script>
            <?php endif; ?>
          </div><!-- /table-responsive -->
        </div><!-- /card-body -->
      </div><!-- /card -->
    </div>
  </div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2000;background:rgba(255,255,255,0.7);align-items:center;justify-content:center;">
  <div class="spinner-border text-primary" style="width:4rem;height:4rem;" role="status">
    <span class="visually-hidden">Loading...</span>
  </div>
</div>

<!-- ============================================================
     MODALS
     ============================================================ -->

<!-- Add ODP Modal -->
<div class="modal fade" id="dataModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="dataModalLabel">Add ODP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="dataForm" action="proses/addodp.php" method="POST" enctype="multipart/form-data">
          <div class="mb-3">
            <label for="gambar_odp" class="form-label">Gambar ODP</label>
            <input type="file" class="form-control" id="gambar_odp" name="gambar_odp" accept="image/*">
          </div>
          <div class="mb-3">
            <label for="hirarki" class="form-label">Hirarki</label>
            <select class="form-control" id="hirarki" name="hirarki">
              <option value="">-- Pilih Hirarki --</option>
              <option value="ODC">ODC</option>
              <option value="ODP">ODP</option>
              <option value="ODP-RASIO">ODP-RASIO</option>
              <option value="ODP-JUMPER">ODP-JUMPER</option>
            </select>
          </div>
          <div class="mb-3">
            <label for="kode" class="form-label">Kode ODP</label>
            <input type="text" class="form-control" id="kode" name="kode" required>
          </div>
          <div class="mb-3">
            <label for="name" class="form-label">Name ODP</label>
            <input type="text" class="form-control" id="name" name="name" required>
          </div>
          <div class="mb-3">
            <label for="coordinates" class="form-label">Coordinates</label>
            <div class="input-group">
              <input type="text" class="form-control" id="coordinates" name="coordinates" placeholder="-6.477644,106.778171" required>
              <button type="button" class="btn btn-outline-primary" onclick="openMapPicker('coordinates')">
                <i class="fas fa-map-marker-alt"></i> Pilih dari Map
              </button>
            </div>
          </div>
          <div class="mb-3">
            <label for="splitter" class="form-label">Splitter</label>
            <select class="form-control" id="splitter" name="splitter">
              <option value="">-- Pilih Splitter --</option>
              <option value="1:2">1:2</option>
              <option value="1:4">1:4</option>
              <option value="1:8">1:8</option>
              <option value="1:16">1:16</option>
              <option value="1:32">1:32</option>
            </select>
          </div>
          <div class="mb-3" id="parentFieldAdd">
            <label for="hirarki_parent" class="form-label">Hirarki Parent (ODC)</label>
            <select class="form-control" id="hirarki_parent" name="hirarki_parent">
              <option value="">-- Pilih ODC Parent --</option>
              <?php
              $queryODC = mysqli_query($conn, "SELECT DISTINCT o.KODE, o.NAME, o.PEMILIK, o.AREA, s.BRAND FROM odp o LEFT JOIN server s ON o.PEMILIK=s.PEMILIK WHERE o.Hirarki='ODC'");
              while ($rowODC = mysqli_fetch_assoc($queryODC)) {
                  echo '<option value="' . htmlspecialchars($rowODC['KODE']) . '" data-server="' . htmlspecialchars($rowODC['PEMILIK']) . '" data-area="' . htmlspecialchars($rowODC['AREA']) . '" data-brand="' . htmlspecialchars($rowODC['BRAND']) . '">' . htmlspecialchars($rowODC['KODE']) . ' - ' . htmlspecialchars($rowODC['NAME']) . '</option>';
              }
              ?>
            </select>
            <small class="text-muted">Memilih ODC parent akan otomatis mencentang Server Area yang sama.</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Assign ke Server Area <small class="text-muted">(bisa lebih dari satu)</small></label>
            <?php renderProductCheckboxes($productOptions, [], 'add'); ?>
          </div>
          <input type="hidden" name="area_map" value="{}">
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" form="dataForm">Simpan</button>
      </div>
    </div>
  </div>
</div>

<!-- Import Excel Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning text-white">
        <h5 class="modal-title"><i class="fas fa-file-excel"></i> Import ODP dari Excel</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info">
          <h6><i class="fas fa-info-circle"></i> Format File Excel:</h6>
          <ul class="mb-2">
            <li><strong>Kode ODP</strong></li><li><strong>Name ODP</strong></li>
            <li><strong>TIKOR</strong> - latitude,longitude</li><li><strong>PEMILIK</strong></li>
            <li><strong>AREA</strong></li><li><strong>HIRARKI</strong> - ODC / ODP / ODP-RASIO / ODP-JUMPER</li>
            <li><strong>SPLITTER</strong> - opsional</li><li><strong>HIRARKI_PARENT</strong> - opsional</li>
          </ul>
          <a href="proses/download_template_odp.php" class="btn btn-sm btn-success" target="_blank"><i class="fas fa-download"></i> Download Template</a>
        </div>
        <form id="importForm" action="proses/import_odp_excel.php" method="POST" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label">Pilih File Excel (.xlsx, .xls)</label>
            <input type="file" class="form-control" name="excel_file" accept=".xlsx,.xls" required>
          </div>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="skip_duplicates" name="skip_duplicates" checked>
              <label class="form-check-label" for="skip_duplicates">Skip data duplikat</label>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" form="importForm" class="btn btn-warning"><i class="fas fa-upload"></i> Import Data</button>
      </div>
    </div>
  </div>
</div>

<!-- Import KMZ Modal -->
<div class="modal fade" id="importKmzModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title"><i class="fas fa-map"></i> Import ODP dari KMZ/KML</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="importKmzForm" action="proses/import_odp_kmz.php" method="POST" enctype="multipart/form-data">
          <div class="mb-3">
            <label class="form-label">Pilih File KMZ/KML</label>
            <input type="file" class="form-control" name="kmz_file" accept=".kmz,.kml" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Server Area</label>
              <select class="form-control" id="default_server" name="default_server" required onchange="setAreaKMZ()">
                <option value="">-- Pilih Server Area --</option>
                <?php
                if ($AKSES == 'ASSISTANT') {
                    $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                } else {
                    $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                }
                while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                    echo '<option value="' . htmlspecialchars($rowServer['PEMILIK']) . '" data-area="' . htmlspecialchars($rowServer['AREA']) . '">' . htmlspecialchars($rowServer['BRAND']) . '-' . htmlspecialchars($rowServer['AREA']) . '</option>';
                }
                ?>
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Area Default</label>
              <input type="hidden" id="default_area" name="default_area">
              <input type="text" class="form-control" id="default_area_display" placeholder="Otomatis dari Server Area" readonly>
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Default Hirarki</label>
              <select class="form-control" name="default_hirarki">
                <option value="ODP" selected>ODP</option>
                <option value="ODC">ODC</option>
                <option value="ODP-RASIO">ODP-RASIO</option>
                <option value="ODP-JUMPER">ODP-JUMPER</option>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Default Splitter</label>
              <select class="form-control" name="default_splitter">
                <option value="">-- Optional --</option>
                <option value="1:2">1:2</option><option value="1:4">1:4</option>
                <option value="1:8">1:8</option><option value="1:16">1:16</option><option value="1:32">1:32</option>
              </select>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Default Hirarki Parent</label>
              <input type="text" class="form-control" name="default_hirarki_parent" placeholder="Kode ODC parent (opsional)">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Prefix Kode ODP</label>
            <input type="text" class="form-control" name="kode_prefix" value="ODP-" placeholder="Contoh: ODP-JKT-">
          </div>
          <div class="mb-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="skip_duplicates_kmz" name="skip_duplicates" checked>
              <label class="form-check-label" for="skip_duplicates_kmz">Skip koordinat duplikat</label>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" form="importKmzForm" class="btn btn-secondary"><i class="fas fa-map"></i> Import Data</button>
      </div>
    </div>
  </div>
</div>

<!-- Pelanggan Modal -->
<div class="modal fade" id="pelangganModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--primary-color) 0%,var(--secondary-color) 100%);color:white;">
        <div>
          <h5 class="modal-title" id="pelangganModalLabel" style="color:white;margin-bottom:0;"><i class="fas fa-users me-2"></i>Data Pelanggan ODP</h5>
          <small id="modalOdpInfo" style="color:rgba(255,255,255,0.8);">Loading...</small>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="pelangganModalBody" style="min-height:300px;">
        <div class="text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status"></div>
          <p class="text-muted">Memuat data pelanggan...</p>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<!-- Map Picker Modal -->
<div class="modal fade" id="mapPickerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--primary-color) 0%,var(--secondary-color) 100%);color:white;">
        <h5 class="modal-title" style="color:white;"><i class="fas fa-map-marked-alt me-2"></i>Pilih Lokasi di Map</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="px-3 pt-3 pb-2 d-flex gap-2 flex-wrap align-items-center" style="border-bottom:1px solid var(--border-color);">
          <input type="text" id="mapPickerSearchInput" class="form-control form-control-sm" style="max-width:260px;" placeholder="Cari alamat / tempat...">
          <button type="button" class="btn btn-sm btn-secondary" onclick="mapPickerSearch()"><i class="fas fa-search"></i> Cari</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="mapPickerUseMyLocation()"><i class="fas fa-location-arrow"></i> Lokasi Saya</button>
          <span class="ms-auto small text-muted">Klik di peta untuk menandai titik ODP</span>
        </div>
        <div id="mapPickerContainer" style="width:100%;height:420px;"></div>
        <div class="px-3 py-2" style="border-top:1px solid var(--border-color);background:var(--bg-light);">
          <strong>Koordinat terpilih:</strong>
          <span id="mapPickerCoordsDisplay" class="text-muted">Belum ada titik dipilih</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" id="mapPickerConfirmBtn" onclick="mapPickerConfirm()" disabled>
          <i class="fas fa-check"></i> Gunakan Koordinat Ini
        </button>
      </div>
    </div>
  </div>
</div>

<style>
#pelangganModal .modal-body { max-height: 70vh; overflow-y: auto; }
.pelanggan-table { font-size: 0.9rem; margin-bottom: 0; }
.pelanggan-table thead th {
  background: linear-gradient(135deg,rgba(37,99,235,0.1) 0%,rgba(59,130,246,0.1) 100%);
  color: var(--text-primary); font-weight: 700; border-bottom: 2px solid var(--primary-color); padding: 12px 8px; font-size: 0.8rem;
}
.pelanggan-table tbody td { padding: 10px 8px; border-bottom: 1px solid var(--border-color); vertical-align: middle; }
.pelanggan-table tbody tr:hover { background-color: var(--bg-light); }
.status-badge { display:inline-block; padding:4px 10px; border-radius:12px; font-size:0.75rem; font-weight:600; white-space:nowrap; }
.status-online  { background-color:rgba(16,185,129,0.15); color:#10b981; }
.status-offline { background-color:rgba(239,68,68,0.15);  color:#ef4444; }
.pelanggan-empty { text-align:center; padding:40px 20px; color:var(--text-muted); }
.pelanggan-empty i { font-size:3rem; opacity:0.3; display:block; margin-bottom:10px; }
body.app-theme-dark .pelanggan-table thead th { background:linear-gradient(135deg,rgba(37,99,235,0.15) 0%,rgba(59,130,246,0.15) 100%) !important; color:#f1f5f9; border-bottom-color:#3b82f6; }
body.app-theme-dark .pelanggan-table tbody td { border-bottom-color:rgba(59,130,246,0.2); color:#e2e8f0; }
body.app-theme-dark .status-online  { background-color:rgba(34,197,94,0.2); color:#22c55e; }
body.app-theme-dark .status-offline { background-color:rgba(248,113,113,0.2); color:#f87171; }

@media (max-width: 576px) {
  #dataModal .modal-dialog, #importModal .modal-dialog, #importKmzModal .modal-dialog, #mapPickerModal .modal-dialog { margin: 0.5rem; }
  #dataModal .modal-content, #importModal .modal-content, #importKmzModal .modal-content, #mapPickerModal .modal-content {
    max-height: calc(100vh - 1rem); display: flex; flex-direction: column;
  }
  #dataModal .modal-body, #importModal .modal-body, #importKmzModal .modal-body {
    overflow-y: auto; max-height: calc(100vh - 190px);
  }
  #dataModal .modal-footer, #importModal .modal-footer, #importKmzModal .modal-footer, #mapPickerModal .modal-footer {
    position: sticky; bottom: 0; z-index: 3; background: var(--white);
    border-top: 1px solid var(--border-color); padding-bottom: max(0.75rem, env(safe-area-inset-bottom));
  }
  #mapPickerContainer { height: 320px !important; }
}
#mapPickerContainer { position: relative; z-index: 1; }
.leaflet-pane, .leaflet-top, .leaflet-bottom { z-index: 2; }
</style>

<!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
<!-- ============================================================
     LEAFLET MAP PICKER
     ============================================================ -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js"></script>

<script>
// ============================================================
// Map Picker: pilih koordinat ODP/ODC lewat peta Leaflet
// Format output: "lat,lng" (contoh: -6.477644,106.778171)
// ============================================================
var mapPickerMap = null;
var mapPickerMarker = null;
var mapPickerTargetInputId = null;
var mapPickerDefaultCenter = [-6.200000, 106.816666]; // fallback: Jakarta

function mapPickerSetCoords(lat, lng) {
    var latF = parseFloat(lat).toFixed(6);
    var lngF = parseFloat(lng).toFixed(6);
    var disp = document.getElementById('mapPickerCoordsDisplay');
    var btn  = document.getElementById('mapPickerConfirmBtn');
    if (disp) disp.textContent = latF + ',' + lngF;
    if (btn)  btn.disabled = false;
    mapPickerMap._pickedLat = latF;
    mapPickerMap._pickedLng = lngF;
}

function mapPickerPlaceMarker(lat, lng) {
    if (mapPickerMarker) {
        mapPickerMarker.setLatLng([lat, lng]);
    } else {
        mapPickerMarker = L.marker([lat, lng], {
            draggable: true,
            icon: L.icon({
                iconUrl: 'odpmap2.png',
                iconSize: [80, 80],
                iconAnchor: [40, 70]
            })
        }).addTo(mapPickerMap);
        mapPickerMarker.on('dragend', function(e) {
            var pos = e.target.getLatLng();
            mapPickerSetCoords(pos.lat, pos.lng);
        });
    }
    mapPickerSetCoords(lat, lng);
}

function mapPickerInitMap() {
    if (mapPickerMap) return; // sudah pernah di-init, jangan diulang

    mapPickerMap = L.map('mapPickerContainer').setView(mapPickerDefaultCenter, 13);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(mapPickerMap);

    mapPickerMap.on('click', function(e) {
        mapPickerPlaceMarker(e.latlng.lat, e.latlng.lng);
    });
}

function openMapPicker(inputId) {
    mapPickerTargetInputId = inputId;

    var modalEl = document.getElementById('mapPickerModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    // Reset state setiap kali dibuka
    var disp = document.getElementById('mapPickerCoordsDisplay');
    var btn  = document.getElementById('mapPickerConfirmBtn');
    if (disp) disp.textContent = 'Belum ada titik dipilih';
    if (btn)  btn.disabled = true;
    var searchInput = document.getElementById('mapPickerSearchInput');
    if (searchInput) searchInput.value = '';

    // Jika input target sudah ada isinya (format "lat,lng"), pakai sebagai titik awal
    var existingVal = (document.getElementById(inputId) || {}).value || '';
    var startLat = null, startLng = null;
    var parts = existingVal.split(',');
    if (parts.length === 2) {
        var pLat = parseFloat(parts[0].trim());
        var pLng = parseFloat(parts[1].trim());
        if (!isNaN(pLat) && !isNaN(pLng)) { startLat = pLat; startLng = pLng; }
    }

    modalEl.addEventListener('shown.bs.modal', function onShown() {
        modalEl.removeEventListener('shown.bs.modal', onShown);
        mapPickerInitMap();
        // Perbaiki ukuran peta setelah modal benar-benar tampil
        setTimeout(function(){ mapPickerMap.invalidateSize(); }, 150);

        if (startLat !== null && startLng !== null) {
            mapPickerMap.setView([startLat, startLng], 17);
            mapPickerPlaceMarker(startLat, startLng);
        } else if (mapPickerMarker) {
            // Modal dipakai ulang tanpa nilai awal -> hilangkan marker sebelumnya
            mapPickerMap.removeLayer(mapPickerMarker);
            mapPickerMarker = null;
        }
    });

    bsModal.show();
}

function mapPickerConfirm() {
    if (!mapPickerMap || mapPickerMap._pickedLat === undefined) return;
    var targetInput = document.getElementById(mapPickerTargetInputId);
    if (targetInput) {
        targetInput.value = mapPickerMap._pickedLat + ',' + mapPickerMap._pickedLng;
        targetInput.dispatchEvent(new Event('change', { bubbles: true }));
    }
    var modalEl = document.getElementById('mapPickerModal');
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.hide();
}

function mapPickerUseMyLocation() {
    if (!navigator.geolocation) { alert('Browser tidak mendukung geolocation.'); return; }
    navigator.geolocation.getCurrentPosition(function(pos) {
        var lat = pos.coords.latitude, lng = pos.coords.longitude;
        mapPickerMap.setView([lat, lng], 17);
        mapPickerPlaceMarker(lat, lng);
    }, function() {
        alert('Gagal mendapatkan lokasi Anda. Pastikan izin lokasi diaktifkan.');
    });
}

function mapPickerSearch() {
    var q = (document.getElementById('mapPickerSearchInput') || {}).value || '';
    q = q.trim();
    if (!q) return;
    fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(results) {
            if (!results || !results.length) { alert('Lokasi tidak ditemukan.'); return; }
            var lat = parseFloat(results[0].lat), lng = parseFloat(results[0].lon);
            mapPickerMap.setView([lat, lng], 16);
            mapPickerPlaceMarker(lat, lng);
        })
        .catch(function() { alert('Gagal mencari lokasi. Periksa koneksi internet.'); });
}

document.getElementById('mapPickerSearchInput') && document.getElementById('mapPickerSearchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); mapPickerSearch(); }
});
</script>

<script>
var ODP_FILTER_STORAGE_KEY = 'odp_filter_state_v1';

// ---- KMZ area auto-fill ----
function setAreaKMZ() {
    var sel  = document.getElementById('default_server');
    var area = sel ? (sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].getAttribute('data-area') : '') : '';
    var inp  = document.getElementById('default_area');
    var disp = document.getElementById('default_area_display');
    if (inp)  inp.value  = area || '';
    if (disp) disp.value = area || '';
}

// ---- Filter persistence ----
function saveOdpFilterState() {
    try {
        localStorage.setItem(ODP_FILTER_STORAGE_KEY, JSON.stringify({
            hirarki : (document.getElementById('filterHirarki')  || {}).value || '',
            area    : (document.getElementById('filterArea')     || {}).value || '',
            product : (document.getElementById('filterProduct')  || {}).value || '',
            splitter: (document.getElementById('filterSplitter') || {}).value || '',
            search  : (document.getElementById('searchOdpInput') || {}).value || ''
        }));
    } catch(e) {}
}

function restoreOdpFilterState() {
    try {
        var raw = localStorage.getItem(ODP_FILTER_STORAGE_KEY);
        if (!raw) return;
        var s = JSON.parse(raw);
        if (!s || typeof s !== 'object') return;
        function set(id, val) {
            var el = document.getElementById(id);
            if (!el || typeof val !== 'string') return;
            if (el.tagName === 'INPUT') { el.value = val; return; }
            var ok = Array.from(el.options || []).some(function(o){ return o.value === val; });
            if (ok) el.value = val;
        }
        set('filterHirarki',  s.hirarki  || '');
        set('filterArea',     s.area     || '');
        set('filterProduct',  s.product  || '');
        set('filterSplitter', s.splitter || '');
        set('searchOdpInput', s.search   || '');
    } catch(e) {}
}

// ---- Toggle group collapse ----
function toggleGroup(button) {
    var headerRow = button.closest('tr');
    var groupKey  = headerRow.getAttribute('data-group');
    var rows      = document.querySelectorAll('tr[data-group="' + groupKey + '"][data-hirarki]');
    var isHidden  = rows[0] && rows[0].style.display === 'none';
    rows.forEach(function(r){ r.style.display = isHidden ? '' : 'none'; });
    var icon = button.querySelector('i');
    if (icon) { icon.classList.toggle('fa-chevron-down'); icon.classList.toggle('fa-chevron-up'); }
}

// ---- Main filter function ----
function filterOdpTable() {
    function norm(v)  { return (v||'').toString().toLowerCase().replace(/\s+/g,' ').trim(); }
    function normH(v) { return (v||'').toString().toLowerCase().replace(/\s+/g,'').trim(); }

    var searchVal   = norm((document.getElementById('searchOdpInput') || {}).value || '');
    var hirarkiVal  = normH((document.getElementById('filterHirarki')  || {}).value || '');
    var areaVal     = norm((document.getElementById('filterArea')     || {}).value || '');
    var productVal  = norm((document.getElementById('filterProduct')  || {}).value || '');
    var splitterVal = norm((document.getElementById('filterSplitter') || {}).value || '');

    // Filter semua baris data (tr yang punya data-hirarki)
    var allDataRows = document.querySelectorAll('tbody.odp-data-table tr[data-hirarki]');
    allDataRows.forEach(function(row) {
        var rH  = normH(row.getAttribute('data-hirarki')  || '');
        var rA  = norm(row.getAttribute('data-area')      || '');
        var rP  = norm(row.getAttribute('data-product')   || '');
        var rS  = norm(row.getAttribute('data-splitter')  || '');
        // Gabungkan data-search + textContent untuk full-text search
        var rSrch = norm((row.getAttribute('data-search') || '') + ' ' + (row.textContent || ''));

        var show = true;
        if (searchVal   && rSrch.indexOf(searchVal)   === -1) show = false;
        if (hirarkiVal  && rH   !== hirarkiVal)                show = false;
        if (areaVal     && rA   !== areaVal)                   show = false;
        // Product: data-product bisa berisi beberapa brand dipisah spasi
        if (productVal  && rP.indexOf(productVal) === -1)      show = false;
        if (splitterVal && rS   !== splitterVal)               show = false;

        row.style.display = show ? '' : 'none';
    });

    // Untuk filter ODC: row ODC sudah dipindah ke group-header, data-hirarki sudah dihapus
    // Cari ghost row via data-hirarki-odc
    document.querySelectorAll('tr.group-header[data-group]').forEach(function(hRow) {
        var gKey = hRow.getAttribute('data-group') || '';
        if (!gKey) { hRow.style.display = 'none'; return; }

        var sep = document.querySelector('tr.group-separator[data-group="' + gKey + '"]');

        if (hirarkiVal === 'odc') {
            // Cari ghost row ODC via data-hirarki-odc
            var odcGhostRow = document.querySelector('tr[data-hirarki-odc="odc"][data-group="' + gKey + '"]');
            var show = false;
            if (odcGhostRow) {
                var rA2    = norm(odcGhostRow.getAttribute('data-area')    || '');
                var rP2    = norm(odcGhostRow.getAttribute('data-product') || '');
                var rSrch2 = norm((odcGhostRow.getAttribute('data-search') || '') + ' ' + gKey);
                show = true;
                if (searchVal  && rSrch2.indexOf(searchVal) === -1) show = false;
                if (areaVal    && rA2 !== areaVal)                   show = false;
                if (productVal && rP2.indexOf(productVal) === -1)    show = false;
            } else {
                // Header tetap tampil jika tidak ada ghost row
                show = true;
            }
            // Sembunyikan child ODP saat filter ODC aktif
            document.querySelectorAll('tr[data-group="' + gKey + '"][data-hirarki="odp"]').forEach(function(r){ r.style.display = 'none'; });
            hRow.style.display = show ? '' : 'none';
            if (sep) sep.style.display = show ? '' : 'none';
            return;
        }

        // Filter lain: tampilkan header jika minimal 1 child row visible
        var children   = document.querySelectorAll('tr[data-group="' + gKey + '"][data-hirarki]');
        var hasVisible = false;
        children.forEach(function(r){ if (r.style.display !== 'none') hasVisible = true; });
        hRow.style.display = hasVisible ? '' : 'none';
        if (sep) sep.style.display = hasVisible ? '' : 'none';
    });

    // Sembunyikan group-header tanpa data-group
    document.querySelectorAll('tr.group-header:not([data-group])').forEach(function(h){
        h.style.display = 'none';
    });
}

// ---- Organize special table (ODP-RASIO / ODP-JUMPER) ----
function separateHierarchyTables() {
    var mainBody    = document.getElementById('dataTableMain');
    var specialBody = document.getElementById('dataTableSpecial');
    if (!mainBody || !specialBody) return;
    Array.from(mainBody.querySelectorAll('tr[data-hirarki]')).forEach(function(row) {
        var h = (row.getAttribute('data-hirarki') || '').toLowerCase();
        if (h === 'odp-rasio' || h === 'odp-jumper') specialBody.appendChild(row);
    });
    organizeSpecialHierarchy();
}

function organizeSpecialHierarchy() {
    var specialBody = document.getElementById('dataTableSpecial');
    if (!specialBody) return;
    var rows = Array.from(specialBody.querySelectorAll('tr[data-hirarki]'));
    if (!rows.length) return;
    var rasioGroup = [], jumperGroup = [];
    rows.forEach(function(row) {
        row.style.display = '';
        var h = (row.getAttribute('data-hirarki') || '').toLowerCase();
        if (h === 'odp-rasio')  rasioGroup.push(row);
        if (h === 'odp-jumper') jumperGroup.push(row);
    });
    specialBody.innerHTML = '';
    function calcStats(group) {
        var ports = 0, terisi = 0;
        group.forEach(function(r) {
            var sp = (r.getAttribute('data-splitter') || '').trim();
            var p  = sp === '1:2' ? 2 : sp === '1:4' ? 4 : sp === '1:8' ? 8 : sp === '1:16' ? 16 : sp === '1:32' ? 32 : 0;
            ports  += p;
            terisi += parseInt(r.getAttribute('data-pelanggan') || 0);
        });
        return { ports: ports, terisi: terisi };
    }
    function addGroupHeader(label, group) {
        if (!group.length) return;
        var st = calcStats(group);
        var h  = document.createElement('tr');
        h.className = 'group-header';
        h.innerHTML = '<td colspan="6" style="padding:0!important;"><div class="group-header-bar" style="padding:12px;">'
            + '<div class="group-header-left"><span style="font-size:0.85rem;"><i class="fas fa-cube me-1"></i><strong>' + label + '</strong></span></div>'
            + '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
            + '<span style="background:#e0e7ff;color:#4f46e5;padding:4px 10px;border-radius:4px;font-size:0.75rem;font-weight:600;">Total: ' + group.length + '</span>'
            + '<span style="background:#d4edda;color:#155724;padding:4px 10px;border-radius:4px;font-size:0.75rem;font-weight:600;">' + st.terisi + ' terisi / ' + st.ports + ' total</span>'
            + '</div></div></td>';
        specialBody.appendChild(h);
        group.forEach(function(r){ specialBody.appendChild(r); });
        var sep = document.createElement('tr');
        sep.className = 'group-separator';
        sep.innerHTML = '<td colspan="6"></td>';
        specialBody.appendChild(sep);
    }
    addGroupHeader('ODP-RASIO',  rasioGroup);
    addGroupHeader('ODP-JUMPER', jumperGroup);
}

// ---- Organize main table (ODC + children) ----
function organizeMainHierarchy() {
    var mainBody = document.getElementById('dataTableMain');
    if (!mainBody) return;
    var rows = Array.from(mainBody.querySelectorAll('tr[data-hirarki]'));
    if (!rows.length) return;

    var odcOrder = [], odcMap = {}, childMap = {}, standalone = [];
    rows.forEach(function(row) {
        var h      = (row.getAttribute('data-hirarki') || '').toLowerCase();
        var kode   = (row.getAttribute('data-kode')   || '').trim();
        var parent = (row.getAttribute('data-parent') || '').trim();
        if (h === 'odc' && kode) { odcOrder.push(kode); odcMap[kode] = row; return; }
        if (h === 'odp' && parent) { if (!childMap[parent]) childMap[parent] = []; childMap[parent].push(row); return; }
        standalone.push(row);
    });

    mainBody.innerHTML = '';

    odcOrder.forEach(function(kode) {
        var odcRow = odcMap[kode];
        if (!odcRow) return;

        var editBtn    = odcRow.querySelector('button[data-bs-target^="#editModal"]');
        var editTarget = editBtn ? (editBtn.getAttribute('data-bs-target') || '') : '';
        var delForm    = odcRow.querySelector('form[action="proses/deleteodp.php"]');
        var delId      = delForm ? (delForm.querySelector('input[name="id"]') || {}).value || '' : '';

        var actionHtml = '<div class="group-header-actions">';
        if (editTarget) actionHtml += '<button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="' + editTarget + '"><i class="fas fa-edit me-1"></i> Edit ODC</button>';
        if (delId)      actionHtml += '<form action="proses/deleteodp.php" method="POST" style="display:inline-block;" onsubmit="return confirm(\'Yakin hapus ODC ini?\');"><input type="hidden" name="id" value="' + delId + '"><button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash me-1"></i> Hapus</button></form>';
        actionHtml += '</div>';

        var children     = childMap[kode] || [];
        var childCount   = children.length;
        var totalHompas  = 0, totalTerisi = 0;
        children.forEach(function(cr) {
            var sp = (cr.getAttribute('data-splitter') || '').trim();
            var p  = sp === '1:2' ? 2 : sp === '1:4' ? 4 : sp === '1:8' ? 8 : sp === '1:16' ? 16 : sp === '1:32' ? 32 : 0;
            totalHompas  += p;
            totalTerisi  += parseInt(cr.getAttribute('data-pelanggan') || 0);
        });

        var header = document.createElement('tr');
        header.className = 'group-header';
        header.setAttribute('data-group', kode);
        header.innerHTML = '<td colspan="6" style="padding:0!important;"><div class="group-header-bar" style="padding:12px;">'
            + '<div class="group-header-left"><span style="font-size:0.85rem;"><i class="fas fa-cube me-1"></i><strong>' + kode + '</strong></span></div>'
            + '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">'
            + '<span style="background:#e0e7ff;color:#4f46e5;padding:4px 10px;border-radius:4px;font-size:0.75rem;font-weight:600;">ODP: ' + childCount + '</span>'
            + '<span style="background:#d4edda;color:#155724;padding:4px 10px;border-radius:4px;font-size:0.75rem;font-weight:600;">' + totalTerisi + ' terisi / ' + totalHompas + ' total</span>'
            + actionHtml
            + '<button onclick="toggleGroup(this)" style="background:none;border:none;color:white;cursor:pointer;font-size:0.85rem;padding:2px 6px;margin-left:4px;"><i class="fas fa-chevron-down"></i></button>'
            + '</div></div></td>';
        mainBody.appendChild(header);

        // ODC row asli disimpan di DOM (tersembunyi) agar filter bisa membacanya
        // Hapus data-hirarki agar tidak diproses ulang sebagai baris data biasa
        odcRow.style.display = 'none';
        odcRow.setAttribute('data-group', kode);
        odcRow.setAttribute('data-hirarki-odc', odcRow.getAttribute('data-hirarki')); // simpan backup
        odcRow.removeAttribute('data-hirarki'); // cegah duplikat saat filterOdpTable
        mainBody.appendChild(odcRow);

        children.forEach(function(cr) {
            cr.setAttribute('data-group', kode);
            mainBody.appendChild(cr);
        });

        var sep = document.createElement('tr');
        sep.className = 'group-separator';
        sep.setAttribute('data-group', kode);
        sep.innerHTML = '<td colspan="6"></td>';
        mainBody.appendChild(sep);
    });

    if (standalone.length) {
        var sh = document.createElement('tr');
        sh.className = 'group-header';
        sh.setAttribute('data-group', 'tanpa-odc');
        sh.innerHTML = '<td colspan="6"><strong>ODP TANPA ODC PARENT</strong></td>';
        mainBody.appendChild(sh);
        standalone.forEach(function(r) {
            r.setAttribute('data-group', 'tanpa-odc');
            mainBody.appendChild(r);
        });
    }
}

// ---- SLA Badge ----
function getOdpSlaBadgeClass(pct) {
    if (pct >= 99.5) return 'badge badge-sm bg-gradient-success';
    if (pct >= 95)   return 'badge badge-sm bg-gradient-warning';
    if (pct <= 0)    return 'badge badge-sm bg-gradient-info';
    return 'badge badge-sm bg-gradient-danger';
}
function renderOdpSlaBadge(kode) {
    var targets = [
        document.getElementById('data-odp-sla-mobile-'  + kode),
        document.getElementById('data-odp-sla-desktop-' + kode)
    ].filter(Boolean);
    if (!targets.length) return;
    var payload = window.customerSlaSummary && window.customerSlaSummary.odps ? window.customerSlaSummary.odps[kode] : null;
    var pct     = payload ? Number(payload.sla_percent || 0) : 0;
    var html    = '<span class="' + getOdpSlaBadgeClass(pct) + '">SLA ODP ' + pct.toFixed(2) + '%</span>';
    targets.forEach(function(t){ t.innerHTML = html; });
}
function loadOdpSlaSummary() {
    if (window.odpSlaSummaryLoading) return window.odpSlaSummaryPromise || Promise.resolve(null);
    window.odpSlaSummaryLoading = true;
    window.odpSlaSummaryPromise = fetch('getdata/get_customer_sla.php?_=' + Date.now(), { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(p) {
            if (!p || p.success === false) throw new Error(p && p.message ? p.message : 'Gagal memuat SLA');
            window.customerSlaSummary = p;
            Object.keys(p.odps || {}).forEach(function(k){ renderOdpSlaBadge(k); });
            return p;
        })
        .catch(function(e){ console.error('SLA error:', e); return null; })
        .finally(function(){ window.odpSlaSummaryLoading = false; });
    return window.odpSlaSummaryPromise;
}

// ---- Pelanggan Modal ----
document.getElementById('pelangganModal') && document.getElementById('pelangganModal').addEventListener('show.bs.modal', function(e) {
    var btn     = e.relatedTarget;
    var odpCode = btn ? (btn.getAttribute('data-odp-code') || '') : '';
    var odpName = btn ? (btn.getAttribute('data-odp-name') || '') : '';
    if (!odpCode) return;
    document.getElementById('modalOdpInfo').textContent = 'ODP: ' + odpCode + ' - ' + odpName;
    var bodyEl = document.getElementById('pelangganModalBody');
    bodyEl.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary mb-3" role="status"></div><p class="text-muted">Memuat data pelanggan...</p></div>';
    fetch('getdata/getPelangganByODP.php?odp=' + encodeURIComponent(odpCode))
        .then(function(r){ return r.text().then(function(t){ return { ok: r.ok, text: t }; }); })
        .then(function(d) {
            if (!d.text.trim()) throw new Error('Response kosong dari server');
            var parsed;
            try { parsed = JSON.parse(d.text); } catch(ex) { throw new Error('Response bukan JSON: ' + d.text.substring(0,120)); }
            if (!d.ok || !parsed.success) throw new Error(parsed.error || 'Gagal memuat data');
            var list = parsed.data || [];
            if (!list.length) { bodyEl.innerHTML = '<div class="pelanggan-empty"><i class="fas fa-inbox"></i><p>Tidak ada pelanggan untuk ODP ini</p></div>'; return; }
            var html = '<div class="table-responsive"><table class="table pelanggan-table align-middle"><thead><tr><th>No</th><th>ID</th><th>Nama</th><th>Paket</th><th>Alamat</th><th>No WA</th><th>Status</th></tr></thead><tbody>';
            list.forEach(function(p, i) {
                var cls  = p.is_online ? 'status-online' : 'status-offline';
                html += '<tr><td><strong>' + (i+1) + '</strong></td><td>' + (p.idpel||'-') + '</td><td><strong>' + (p.nama||'-') + '</strong></td><td>' + (p.paket||'-') + '</td><td>' + (p.alamat||'-') + '</td><td>' + (p.nowa||'-') + '</td><td><span class="status-badge ' + cls + '">' + p.status + '</span></td></tr>';
            });
            html += '</tbody></table></div>';
            bodyEl.innerHTML = html;
        })
        .catch(function(err) {
            bodyEl.innerHTML = '<div class="alert alert-danger m-3"><i class="fas fa-exclamation-circle me-2"></i><strong>Error:</strong> ' + err.message + '</div>';
        });
});

// ---- DOMContentLoaded ----
document.addEventListener('DOMContentLoaded', function() {
    // Kalau masih ada halaman ODP lain yang belum di-lazy-load, tunda pembentukan
    // hierarchy ODC/ODP-RASIO+JUMPER sampai semua data selesai dimuat (lihat
    // finishLoading() di script lazy-load ODP) supaya agregat port terisi/total
    // per ODC tidak dihitung dari data yang masih parsial. Filter & SLA badge
    // tetap jalan dari awal karena keduanya tidak bergantung pada hierarchy.
    if (!document.getElementById('odpLazyLoadSentinel')) {
        separateHierarchyTables();
        organizeMainHierarchy();
    }
    restoreOdpFilterState();
    filterOdpTable();
    loadOdpSlaSummary();

    // Filter event listeners
    ['filterHirarki','filterArea','filterProduct','filterSplitter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', function(){ saveOdpFilterState(); filterOdpTable(); });
    });
    var si = document.getElementById('searchOdpInput');
    if (si) ['input','keyup','change','paste','cut'].forEach(function(ev){
        si.addEventListener(ev, function(){ saveOdpFilterState(); filterOdpTable(); });
    });

    // Loading overlay on submit
    var addForm = document.getElementById('dataForm');
    if (addForm) addForm.addEventListener('submit', function(e) {
        // Validasi
        var hirarki        = document.getElementById('hirarki').value;
        var hirarki_parent = document.getElementById('hirarki_parent').value;
        if (hirarki === 'ODP' && !hirarki_parent) {
            alert('Untuk hirarki ODP, harus pilih ODC parent.');
            e.preventDefault(); return false;
        }
        var count = syncProductAreaMap(this);
        if (!count) { alert('Pilih minimal 1 Server Area untuk ODP ini.'); e.preventDefault(); return false; }
        document.getElementById('loadingOverlay').style.display = 'flex';
    });

    // Edit form submit � sync area_map lalu validasi
    document.querySelectorAll('form[action="proses/editodp.php"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var count = syncProductAreaMap(this);
            if (!count) { alert('Pilih minimal 1 Server Area untuk ODP ini.'); e.preventDefault(); return false; }
            document.getElementById('loadingOverlay').style.display = 'flex';
        });
    });

    // Add modal: toggle hirarki_parent + auto-check product dari ODC parent
    var hirarkiSel = document.getElementById('hirarki');
    if (hirarkiSel) {
        hirarkiSel.addEventListener('change', function() {
            var v = this.value;
            var pf = document.getElementById('parentFieldAdd');
            if (pf) pf.style.display = (v === 'ODC' || v === 'ODP-RASIO' || v === 'ODP-JUMPER') ? 'none' : '';
            if (v === 'ODC' || v === 'ODP-RASIO' || v === 'ODP-JUMPER') {
                var hp = document.getElementById('hirarki_parent');
                if (hp) hp.value = '';
            }
            updateLabelsAdd(v);
        });
    }
    var parentSel = document.getElementById('hirarki_parent');
    if (parentSel) {
        parentSel.addEventListener('change', function() {
            var sel  = this.options[this.selectedIndex];
            var srv  = sel ? sel.getAttribute('data-server') : '';
            var area = sel ? sel.getAttribute('data-area')   : '';
            if (!srv) return;
            document.getElementById('dataForm').querySelectorAll('.product-assign-checkbox').forEach(function(cb) {
                if (cb.value === srv && cb.getAttribute('data-area') === area) cb.checked = true;
            });
        });
    }

    // Edit modals: toggle hirarki_parent + auto-check product
    document.querySelectorAll('select[name="hirarki"]').forEach(function(sel) {
        if (sel.id === 'hirarki') return; // skip add modal, sudah dihandle
        var editId = sel.id.replace('hirarki', '');
        var pf = document.getElementById('parentField' + editId);
        if (pf) pf.style.display = shouldHideParent(sel.value) ? 'none' : '';
        updateLabelsEdit(editId, sel.value);
        sel.addEventListener('change', function() {
            var pf2 = document.getElementById('parentField' + editId);
            if (pf2) pf2.style.display = shouldHideParent(this.value) ? 'none' : '';
            if (shouldHideParent(this.value)) {
                var hp = document.getElementById('hirarki_parent' + editId);
                if (hp) hp.value = '';
            }
            updateLabelsEdit(editId, this.value);
        });
    });

    document.querySelectorAll('select[name="hirarki_parent"]').forEach(function(sel) {
        if (sel.id === 'hirarki_parent') return;
        var editId = sel.id.replace('hirarki_parent', '');
        sel.addEventListener('change', function() {
            var opt  = this.options[this.selectedIndex];
            var srv  = opt ? opt.getAttribute('data-server') : '';
            var area = opt ? opt.getAttribute('data-area')   : '';
            if (!srv) return;
            var form = document.getElementById('editForm' + editId);
            if (!form) return;
            form.querySelectorAll('.product-assign-checkbox').forEach(function(cb) {
                if (cb.value === srv && cb.getAttribute('data-area') === area) cb.checked = true;
            });
        });
    });
});

function shouldHideParent(v) { return v === 'ODC' || v === 'ODP-RASIO' || v === 'ODP-JUMPER'; }

function updateLabelsAdd(h) {
    var map = { gambar_odp: 'Foto', kode: 'Kode', name: 'Name', coordinates: 'Coordinates' };
    Object.keys(map).forEach(function(fieldId) {
        var lbl = document.querySelector('label[for="' + fieldId + '"]');
        if (lbl) lbl.textContent = h ? map[fieldId] + ' ' + h : (fieldId === 'gambar_odp' ? 'Gambar ODP' : (fieldId === 'kode' ? 'Kode ODP' : (fieldId === 'name' ? 'Name ODP' : 'Coordinates')));
    });
    var title = document.getElementById('dataModalLabel');
    if (title) title.textContent = h ? 'Add ' + h : 'Add ODP';
}

function updateLabelsEdit(id, h) {
    ['gambar_odp','kode','name','tikor'].forEach(function(f) {
        var lbl = document.querySelector('label[for="' + f + id + '"]');
        if (!lbl) return;
        var base = f === 'gambar_odp' ? 'Foto' : (f === 'kode' ? 'Kode' : (f === 'name' ? 'Name' : 'Coordinates'));
        lbl.textContent = h ? base + ' ' + h : base + ' ODP';
    });
    var title = document.getElementById('editModalLabel' + id);
    if (title) title.textContent = h ? 'Edit ' + h : 'Edit ODP';
}
</script>

<?php require 'footer.php'; ?>