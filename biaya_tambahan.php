<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Tambahan_Biaya', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Tambahan Biaya.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>

<?php
$bulan_penggunaan = [
  'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
  'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

$currentYear = (int)date('Y');
$message = '';
$messageType = 'success';

// Scoping kepemilikan server (pola sama dgn dashboard.php) -- $current_user_id
// utk ASSISTANT berisi id akun OWNER (lihat cek-sesi.php), BUKAN id assistant
// itu sendiri, jadi "WHERE user_id = $current_user_id" polos SELALU balik
// SEMUA server milik owner (bocor lintas-area).
$ownedPemilik = [];
if ($AKSES === 'ASSISTANT') {
  if (isset($area_list) && trim((string)$area_list) !== '') {
    $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE AREA IN ($area_list)");
    if ($queryServerId) {
      while ($row = mysqli_fetch_assoc($queryServerId)) {
        $ownedPemilik[] = (string)$row['PEMILIK'];
      }
    }
  }
} else {
  $queryServerId = mysqli_query($conn, "SELECT PEMILIK FROM server WHERE user_id = $current_user_id");
  while ($row = mysqli_fetch_assoc($queryServerId)) {
    $ownedPemilik[] = (string)$row['PEMILIK'];
  }
}
$ownedPemilik = array_values(array_unique(array_filter($ownedPemilik, static function ($value) {
  return $value !== '';
})));

$ownedPemilikEscaped = [];
foreach ($ownedPemilik as $pemilikItem) {
  $ownedPemilikEscaped[] = "'" . mysqli_real_escape_string($conn, $pemilikItem) . "'";
}
$server_list = count($ownedPemilikEscaped) > 0 ? implode(',', $ownedPemilikEscaped) : "''";

$createTableSql = "CREATE TABLE IF NOT EXISTS biaya_tambahan_pelanggan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  MODE ENUM('global','per_pelanggan') NOT NULL DEFAULT 'global',
  GLOBAL_AREA VARCHAR(120) NULL,
  GLOBAL_PAKET VARCHAR(150) NULL,
  NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal',
  IDPEL VARCHAR(120) NULL,
  PEMILIK VARCHAR(150) NOT NULL,
  PERIODE VARCHAR(40) NOT NULL,
  NOMINAL DECIMAL(18,2) NOT NULL DEFAULT 0,
  KETERANGAN TEXT NULL,
  ACTIVE TINYINT(1) NOT NULL DEFAULT 1,
  CREATED_BY VARCHAR(120) NULL,
  CREATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UPDATED_AT TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_biaya_lookup (ACTIVE, MODE, IDPEL, PEMILIK, PERIODE),
  INDEX idx_biaya_scope (ACTIVE, MODE, PEMILIK, GLOBAL_AREA, PERIODE),
  INDEX idx_biaya_periode (PERIODE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createTableSql);

$existingColumns = [];
$columnQuery = mysqli_query($conn, "SHOW COLUMNS FROM biaya_tambahan_pelanggan");
if ($columnQuery) {
  while ($col = mysqli_fetch_assoc($columnQuery)) {
    $existingColumns[] = (string)$col['Field'];
  }
}
if (!in_array('GLOBAL_AREA', $existingColumns, true)) {
  mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN GLOBAL_AREA VARCHAR(120) NULL AFTER MODE");
}
if (!in_array('GLOBAL_PAKET', $existingColumns, true)) {
  mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN GLOBAL_PAKET VARCHAR(150) NULL AFTER GLOBAL_AREA");
}
if (!in_array('NOMINAL_TYPE', $existingColumns, true)) {
  mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal' AFTER GLOBAL_PAKET");
}
if (!in_array('PERIODE_TYPE', $existingColumns, true)) {
  mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_TYPE ENUM('bulanan','rentang','permanen') NOT NULL DEFAULT 'bulanan' AFTER PERIODE");
}
if (!in_array('PERIODE_MULAI', $existingColumns, true)) {
  mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_MULAI VARCHAR(40) NULL AFTER PERIODE_TYPE");
}
if (!in_array('PERIODE_SELESAI', $existingColumns, true)) {
  mysqli_query($conn, "ALTER TABLE biaya_tambahan_pelanggan ADD COLUMN PERIODE_SELESAI VARCHAR(40) NULL AFTER PERIODE_MULAI");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_fee_id'])) {
  $deleteId = (int)($_POST['delete_fee_id'] ?? 0);
  if ($deleteId > 0) {
    $deleteSql = "UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE id = ? AND PEMILIK IN ($server_list)";
    $stmtDelete = $conn->prepare($deleteSql);
    if ($stmtDelete) {
      $stmtDelete->bind_param('i', $deleteId);
      $stmtDelete->execute();
      $stmtDelete->close();
      $message = 'Tambahan biaya berhasil dinonaktifkan.';
      $messageType = 'success';
    } else {
      $message = 'Gagal menonaktifkan tambahan biaya.';
      $messageType = 'danger';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_fee_ids'])) {
  $rawBulkIds = trim((string)($_POST['bulk_fee_ids'] ?? ''));
  $partsBulkIds = preg_split('/[,;\s]+/', $rawBulkIds);
  $bulkIds = [];

  if (is_array($partsBulkIds)) {
    foreach ($partsBulkIds as $bulkId) {
      $bulkId = (int)$bulkId;
      if ($bulkId > 0) {
        $bulkIds[$bulkId] = true;
      }
    }
  }

  $bulkIds = array_keys($bulkIds);
  if (count($bulkIds) === 0) {
    $message = 'Pilih minimal satu tambahan biaya untuk dinonaktifkan.';
    $messageType = 'warning';
  } else {
    $stmtBulkDelete = $conn->prepare("UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE id = ? AND PEMILIK IN ($server_list)");
    if ($stmtBulkDelete) {
      $totalAffected = 0;
      foreach ($bulkIds as $bulkId) {
        $stmtBulkDelete->bind_param('i', $bulkId);
        $stmtBulkDelete->execute();
        $totalAffected += (int)$stmtBulkDelete->affected_rows;
      }
      $stmtBulkDelete->close();

      if ($totalAffected > 0) {
        $message = 'Berhasil menonaktifkan ' . $totalAffected . ' tambahan biaya.';
        $messageType = 'success';
      } else {
        $message = 'Tidak ada tambahan biaya yang dinonaktifkan (cek akses atau status sudah nonaktif).';
        $messageType = 'warning';
      }
    } else {
      $message = 'Gagal menonaktifkan tambahan biaya massal.';
      $messageType = 'danger';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_fee'])) {
  $mode = strtolower(trim($_POST['mode'] ?? 'global'));
  $globalServer = trim($_POST['global_server'] ?? '');
  $globalArea = trim($_POST['global_area'] ?? '');
  $globalPackage = trim($_POST['global_package'] ?? '');
  $idpel = trim($_POST['idpel'] ?? '');
  $nominalType = strtolower(trim($_POST['nominal_type'] ?? 'nominal'));
  $nominal = (float)($_POST['nominal'] ?? 0);
  $periodeType = strtolower(trim($_POST['periode_type'] ?? 'bulanan'));
  $periodeMonth = trim($_POST['periode_month'] ?? '');
  $periodeYear = (int)($_POST['periode_year'] ?? 0);
  $periodeStartMonth = trim($_POST['periode_start_month'] ?? '');
  $periodeStartYear = (int)($_POST['periode_start_year'] ?? 0);
  $periodeEndMonth = trim($_POST['periode_end_month'] ?? '');
  $periodeEndYear = (int)($_POST['periode_end_year'] ?? 0);
  $keterangan = trim($_POST['keterangan'] ?? '');

  $periode = '';
  $periodeMulai = null;
  $periodeSelesai = null;
  $periodeError = '';

  if (!in_array($periodeType, ['bulanan', 'rentang', 'permanen'], true)) {
    $periodeError = 'Jenis periode tambahan biaya tidak valid.';
  } elseif ($periodeType === 'bulanan') {
    if (!in_array($periodeMonth, $bulan_penggunaan, true) || $periodeYear < 2000 || $periodeYear > 2100) {
      $periodeError = 'Periode tambahan biaya tidak valid.';
    } else {
      $periode = $periodeMonth . ' ' . $periodeYear;
      $periodeMulai = $periode;
      $periodeSelesai = $periode;
    }
  } elseif ($periodeType === 'rentang') {
    if (!in_array($periodeStartMonth, $bulan_penggunaan, true) || $periodeStartYear < 2000 || $periodeStartYear > 2100) {
      $periodeError = 'Periode mulai tidak valid.';
    } elseif (!in_array($periodeEndMonth, $bulan_penggunaan, true) || $periodeEndYear < 2000 || $periodeEndYear > 2100) {
      $periodeError = 'Periode selesai tidak valid.';
    } else {
      $startIndex = $periodeStartYear * 12 + array_search($periodeStartMonth, $bulan_penggunaan, true);
      $endIndex = $periodeEndYear * 12 + array_search($periodeEndMonth, $bulan_penggunaan, true);
      if ($endIndex < $startIndex) {
        $periodeError = 'Periode selesai tidak boleh sebelum periode mulai.';
      } else {
        $periodeMulai = $periodeStartMonth . ' ' . $periodeStartYear;
        $periodeSelesai = $periodeEndMonth . ' ' . $periodeEndYear;
        $periode = $periodeMulai . ' s/d ' . $periodeSelesai;
      }
    }
  } else {
    $periode = 'Permanen';
  }

  if (!in_array($mode, ['global', 'per_pelanggan'], true)) {
    $message = 'Mode tambahan biaya tidak valid.';
    $messageType = 'danger';
  } elseif (!in_array($nominalType, ['nominal', 'persentase'], true)) {
    $message = 'Jenis nilai tambahan biaya tidak valid.';
    $messageType = 'danger';
  } elseif ($periodeError !== '') {
    $message = $periodeError;
    $messageType = 'danger';
  } elseif ($nominal <= 0) {
    $message = 'Nilai tambahan biaya harus lebih dari 0.';
    $messageType = 'danger';
  } elseif ($nominalType === 'persentase' && $nominal > 100) {
    $message = 'Nilai persentase maksimal 100%.';
    $messageType = 'danger';
  } elseif ($mode === 'global' && ($globalServer === '' || !in_array($globalServer, $ownedPemilik, true))) {
    $message = 'Server global tidak valid.';
    $messageType = 'danger';
  } elseif ($mode === 'per_pelanggan' && $idpel === '') {
    $message = 'ID pelanggan wajib dipilih untuk mode per pelanggan.';
    $messageType = 'danger';
  } else {
    $createdBy = (string)($ceknama ?? 'system');

    if ($mode === 'global') {
      if ($globalArea !== '') {
        $stmtAreaCheck = $conn->prepare("SELECT ID FROM pelanggan WHERE PEMILIK = ? AND AREA = ? LIMIT 1");
        $areaValid = false;
        if ($stmtAreaCheck) {
          $stmtAreaCheck->bind_param('ss', $globalServer, $globalArea);
          $stmtAreaCheck->execute();
          $resArea = $stmtAreaCheck->get_result();
          $areaValid = (bool)($resArea && $resArea->fetch_assoc());
          $stmtAreaCheck->close();
        }
        if (!$areaValid) {
          $message = 'Area tidak ditemukan pada server yang dipilih.';
          $messageType = 'danger';
        }
      }

      if ($messageType !== 'danger' && $globalPackage !== '') {
        $stmtPaketCheck = $conn->prepare("SELECT ID FROM pelanggan WHERE PEMILIK = ? AND PAKET = ? LIMIT 1");
        $paketValid = false;
        if ($stmtPaketCheck) {
          $stmtPaketCheck->bind_param('ss', $globalServer, $globalPackage);
          $stmtPaketCheck->execute();
          $resPaket = $stmtPaketCheck->get_result();
          $paketValid = (bool)($resPaket && $resPaket->fetch_assoc());
          $stmtPaketCheck->close();
        }
        if (!$paketValid) {
          $message = 'Paket tidak ditemukan pada server yang dipilih.';
          $messageType = 'danger';
        }
      }

      if ($messageType !== 'danger') {
        if ($periodeType === 'permanen') {
          $stmtDisable = $conn->prepare("UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'global' AND PEMILIK = ? AND PERIODE_TYPE = 'permanen' AND COALESCE(GLOBAL_AREA, '') = ? AND COALESCE(GLOBAL_PAKET, '') = ?");
          if ($stmtDisable) {
            $stmtDisable->bind_param('sss', $globalServer, $globalArea, $globalPackage);
            $stmtDisable->execute();
            $stmtDisable->close();
          }
        } else {
          $stmtDisable = $conn->prepare("UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'global' AND PEMILIK = ? AND PERIODE = ? AND COALESCE(GLOBAL_AREA, '') = ? AND COALESCE(GLOBAL_PAKET, '') = ?");
          if ($stmtDisable) {
            $stmtDisable->bind_param('ssss', $globalServer, $periode, $globalArea, $globalPackage);
            $stmtDisable->execute();
            $stmtDisable->close();
          }
        }

        $stmtInsert = $conn->prepare("INSERT INTO biaya_tambahan_pelanggan (MODE, GLOBAL_AREA, GLOBAL_PAKET, NOMINAL_TYPE, IDPEL, PEMILIK, PERIODE, PERIODE_TYPE, PERIODE_MULAI, PERIODE_SELESAI, NOMINAL, KETERANGAN, ACTIVE, CREATED_BY) VALUES ('global', ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        if ($stmtInsert) {
          $stmtInsert->bind_param('ssssssssdss', $globalArea, $globalPackage, $nominalType, $globalServer, $periode, $periodeType, $periodeMulai, $periodeSelesai, $nominal, $keterangan, $createdBy);
          $okInsert = $stmtInsert->execute();
          $stmtInsert->close();

          if ($okInsert) {
            $message = 'Tambahan biaya global berhasil disimpan.';
            $messageType = 'success';
          } else {
            $message = 'Gagal menyimpan tambahan biaya global.';
            $messageType = 'danger';
          }
        } else {
          $message = 'Gagal menyiapkan query tambahan biaya global.';
          $messageType = 'danger';
        }
      }
    } else {
      $stmtPelanggan = $conn->prepare("SELECT IDPEL, PEMILIK FROM pelanggan WHERE IDPEL = ? AND PEMILIK IN ($server_list) LIMIT 1");
      $pelangganRow = null;
      if ($stmtPelanggan) {
        $stmtPelanggan->bind_param('s', $idpel);
        $stmtPelanggan->execute();
        $resPelanggan = $stmtPelanggan->get_result();
        $pelangganRow = $resPelanggan ? $resPelanggan->fetch_assoc() : null;
        $stmtPelanggan->close();
      }

      if (!$pelangganRow) {
        $message = 'ID pelanggan tidak ditemukan atau tidak termasuk server Anda.';
        $messageType = 'danger';
      } else {
        $pemilikPelanggan = (string)$pelangganRow['PEMILIK'];

        if ($periodeType === 'permanen') {
          $stmtDisable = $conn->prepare("UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE_TYPE = 'permanen'");
          if ($stmtDisable) {
            $stmtDisable->bind_param('ss', $idpel, $pemilikPelanggan);
            $stmtDisable->execute();
            $stmtDisable->close();
          }
        } else {
          $stmtDisable = $conn->prepare("UPDATE biaya_tambahan_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE = ?");
          if ($stmtDisable) {
            $stmtDisable->bind_param('sss', $idpel, $pemilikPelanggan, $periode);
            $stmtDisable->execute();
            $stmtDisable->close();
          }
        }

        $stmtInsert = $conn->prepare("INSERT INTO biaya_tambahan_pelanggan (MODE, GLOBAL_AREA, NOMINAL_TYPE, IDPEL, PEMILIK, PERIODE, PERIODE_TYPE, PERIODE_MULAI, PERIODE_SELESAI, NOMINAL, KETERANGAN, ACTIVE, CREATED_BY) VALUES ('per_pelanggan', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        if ($stmtInsert) {
          $stmtInsert->bind_param('sssssssdss', $nominalType, $idpel, $pemilikPelanggan, $periode, $periodeType, $periodeMulai, $periodeSelesai, $nominal, $keterangan, $createdBy);
          $okInsert = $stmtInsert->execute();
          $stmtInsert->close();

          if ($okInsert) {
            $message = 'Tambahan biaya per pelanggan berhasil disimpan.';
            $messageType = 'success';
          } else {
            $message = 'Gagal menyimpan tambahan biaya per pelanggan.';
            $messageType = 'danger';
          }
        } else {
          $message = 'Gagal menyiapkan query simpan tambahan biaya.';
          $messageType = 'danger';
        }
      }
    }
  }
}

$selectedMode = $_POST['mode'] ?? 'global';
$selectedGlobalServer = trim($_POST['global_server'] ?? '');
$selectedGlobalArea = trim($_POST['global_area'] ?? '');
$selectedGlobalPackage = trim($_POST['global_package'] ?? '');
$selectedOdpFilter = trim($_POST['odp_filter'] ?? '');
$selectedNominalType = $_POST['nominal_type'] ?? 'nominal';
$selectedIdpel = trim($_POST['idpel'] ?? '');
$selectedMonth = $_POST['periode_month'] ?? $bulan_penggunaan[(int)date('n') - 1];
$selectedYear = (int)($_POST['periode_year'] ?? $currentYear);
$selectedPeriodeType = $_POST['periode_type'] ?? 'bulanan';
$selectedStartMonth = $_POST['periode_start_month'] ?? $bulan_penggunaan[(int)date('n') - 1];
$selectedStartYear = (int)($_POST['periode_start_year'] ?? $currentYear);
$selectedEndMonth = $_POST['periode_end_month'] ?? $bulan_penggunaan[(int)date('n') - 1];
$selectedEndYear = (int)($_POST['periode_end_year'] ?? $currentYear);

$pelangganList = [];
$pelangganQuery = mysqli_query($conn, "SELECT IDPEL, NAMA, PAKET, AREA, PEMILIK, ODP FROM pelanggan WHERE IDPEL <> '' AND PEMILIK IN ($server_list) ORDER BY NAMA ASC");
while ($pel = mysqli_fetch_assoc($pelangganQuery)) {
  $pelangganList[] = $pel;
}

$paketFilterList = [];
$paketFilterQuery = mysqli_query($conn, "SELECT DISTINCT PAKET FROM pelanggan WHERE PAKET IS NOT NULL AND PAKET != '' AND PEMILIK IN ($server_list) ORDER BY PAKET ASC");
if ($paketFilterQuery) {
  while ($paketRow = mysqli_fetch_assoc($paketFilterQuery)) {
    $paketFilterList[] = (string)$paketRow['PAKET'];
  }
}

$odpFilterList = [];
$odpFilterQuery = mysqli_query($conn, "SELECT DISTINCT ODP FROM pelanggan WHERE ODP IS NOT NULL AND ODP != '' AND PEMILIK IN ($server_list) ORDER BY ODP ASC");
if ($odpFilterQuery) {
  while ($odpRow = mysqli_fetch_assoc($odpFilterQuery)) {
    $odpFilterList[] = (string)$odpRow['ODP'];
  }
}

$serverAreaMap = [];
$serverAreaQuery = mysqli_query($conn, "SELECT DISTINCT PEMILIK, AREA FROM pelanggan WHERE AREA IS NOT NULL AND AREA != '' AND PEMILIK IN ($server_list) ORDER BY PEMILIK ASC, AREA ASC");
if ($serverAreaQuery) {
  while ($serverAreaRow = mysqli_fetch_assoc($serverAreaQuery)) {
    $mapPemilik = (string)$serverAreaRow['PEMILIK'];
    $mapArea = (string)$serverAreaRow['AREA'];
    if (!isset($serverAreaMap[$mapPemilik])) {
      $serverAreaMap[$mapPemilik] = [];
    }
    if (!in_array($mapArea, $serverAreaMap[$mapPemilik], true)) {
      $serverAreaMap[$mapPemilik][] = $mapArea;
    }
  }
}

$serverPackageMap = [];
$serverPackageQuery = mysqli_query($conn, "SELECT DISTINCT PEMILIK, PAKET FROM pelanggan WHERE PAKET IS NOT NULL AND PAKET != '' AND PEMILIK IN ($server_list) ORDER BY PEMILIK ASC, PAKET ASC");
if ($serverPackageQuery) {
  while ($serverPackageRow = mysqli_fetch_assoc($serverPackageQuery)) {
    $mapPemilik = (string)$serverPackageRow['PEMILIK'];
    $mapPaket = (string)$serverPackageRow['PAKET'];
    if (!isset($serverPackageMap[$mapPemilik])) {
      $serverPackageMap[$mapPemilik] = [];
    }
    if (!in_array($mapPaket, $serverPackageMap[$mapPemilik], true)) {
      $serverPackageMap[$mapPemilik][] = $mapPaket;
    }
  }
}

$activeFees = [];
$feeQuery = mysqli_query(
  $conn,
  "SELECT b.*, p.NAMA AS pelanggan_nama
   FROM biaya_tambahan_pelanggan b
   LEFT JOIN pelanggan p ON p.IDPEL = b.IDPEL
   WHERE b.ACTIVE = 1 AND b.PEMILIK IN ($server_list)
   ORDER BY b.CREATED_AT DESC, b.id DESC
   LIMIT 300"
);
if ($feeQuery) {
  while ($row = mysqli_fetch_assoc($feeQuery)) {
    $activeFees[] = $row;
  }
}
?>

<div class="container-fluid py-4 px-3 px-md-4" id="extraFeePage">
  <div class="card shadow p-4 mb-4">
    <h5 class="mb-3">Pengaturan Tambahan Biaya</h5>

    <?php if ($message !== ''): ?>
      <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3" id="feeForm">
      <div class="col-md-3">
        <label for="mode" class="form-label">Mode Biaya</label>
        <select class="form-control" id="mode" name="mode" required>
          <option value="global" <?= $selectedMode === 'global' ? 'selected' : ''; ?>>Global</option>
          <option value="per_pelanggan" <?= $selectedMode === 'per_pelanggan' ? 'selected' : ''; ?>>Per Pelanggan</option>
        </select>
      </div>

      <div class="col-md-4" id="globalServerWrapper">
        <label for="global_server" class="form-label">Pilih Server</label>
        <select class="form-control" id="global_server" name="global_server">
          <option value="">-- Pilih Server --</option>
          <?php foreach ($ownedPemilik as $pemilikItem): ?>
            <option value="<?= htmlspecialchars($pemilikItem); ?>" <?= $selectedGlobalServer === $pemilikItem ? 'selected' : ''; ?>>
              <?= htmlspecialchars($pemilikItem); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4" id="globalAreaWrapper">
        <label for="global_area" class="form-label">Pilih Area (Opsional)</label>
        <select class="form-control" id="global_area" name="global_area">
          <option value="">Semua Area</option>
        </select>
      </div>

      <div class="col-md-4" id="globalPackageWrapper">
        <label for="global_package" class="form-label">Pilih Paket (Opsional)</label>
        <select class="form-control" id="global_package" name="global_package">
          <option value="">Semua Paket</option>
          <?php foreach ($paketFilterList as $paketFilter): ?>
            <option value="<?= htmlspecialchars($paketFilter); ?>" <?= $selectedGlobalPackage === $paketFilter ? 'selected' : ''; ?>>
              <?= htmlspecialchars($paketFilter); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4" id="odpFilterWrapper">
        <label for="odp_filter" class="form-label">Filter ODP</label>
        <select class="form-control" id="odp_filter" name="odp_filter">
          <option value="">Semua ODP</option>
          <?php foreach ($odpFilterList as $odpFilter): ?>
            <option value="<?= htmlspecialchars($odpFilter); ?>" <?= $selectedOdpFilter === $odpFilter ? 'selected' : ''; ?>>
              <?= htmlspecialchars($odpFilter); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4" id="idpelWrapper">
        <label for="idpel" class="form-label">Pilih ID Pelanggan</label>
        <select class="form-control" id="idpel" name="idpel">
          <option value="">-- Pilih IDPEL --</option>
          <?php foreach ($pelangganList as $pel): ?>
            <option
              value="<?= htmlspecialchars($pel['IDPEL']); ?>"
              data-nama="<?= htmlspecialchars($pel['NAMA']); ?>"
              data-paket="<?= htmlspecialchars($pel['PAKET']); ?>"
              data-area="<?= htmlspecialchars($pel['AREA']); ?>"
              data-pemilik="<?= htmlspecialchars($pel['PEMILIK']); ?>"
              data-odp="<?= htmlspecialchars((string)($pel['ODP'] ?? '')); ?>"
              <?= $selectedIdpel === $pel['IDPEL'] ? 'selected' : ''; ?>
            >
              <?= htmlspecialchars($pel['IDPEL'] . ' - ' . $pel['NAMA'] . ' - ODP: ' . (string)($pel['ODP'] ?? '-')); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label for="periode_type" class="form-label">Jenis Periode Biaya</label>
        <select class="form-control" id="periode_type" name="periode_type" required>
          <option value="bulanan" <?= $selectedPeriodeType === 'bulanan' ? 'selected' : ''; ?>>Satu Bulan Tertentu</option>
          <option value="rentang" <?= $selectedPeriodeType === 'rentang' ? 'selected' : ''; ?>>Rentang Periode (Dari - Sampai)</option>
          <option value="permanen" <?= $selectedPeriodeType === 'permanen' ? 'selected' : ''; ?>>Permanen (Tanpa Batas Waktu)</option>
        </select>
      </div>

      <div class="col-md-2" id="periodeBulananWrapper">
        <label for="periode_month" class="form-label">Periode Bulan</label>
        <select class="form-control" id="periode_month" name="periode_month">
          <?php foreach ($bulan_penggunaan as $bln): ?>
            <option value="<?= htmlspecialchars($bln); ?>" <?= $selectedMonth === $bln ? 'selected' : ''; ?>><?= htmlspecialchars($bln); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2" id="periodeBulananYearWrapper">
        <label for="periode_year" class="form-label">Tahun</label>
        <select class="form-control" id="periode_year" name="periode_year">
          <?php for ($y = $currentYear - 1; $y <= $currentYear + 3; $y++): ?>
            <option value="<?= $y; ?>" <?= $selectedYear === $y ? 'selected' : ''; ?>><?= $y; ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="col-md-2" id="periodeRentangStartWrapper" style="display:none;">
        <label for="periode_start_month" class="form-label">Dari Bulan</label>
        <select class="form-control" id="periode_start_month" name="periode_start_month">
          <?php foreach ($bulan_penggunaan as $bln): ?>
            <option value="<?= htmlspecialchars($bln); ?>" <?= $selectedStartMonth === $bln ? 'selected' : ''; ?>><?= htmlspecialchars($bln); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2" id="periodeRentangStartYearWrapper" style="display:none;">
        <label for="periode_start_year" class="form-label">Tahun Mulai</label>
        <select class="form-control" id="periode_start_year" name="periode_start_year">
          <?php for ($y = $currentYear - 1; $y <= $currentYear + 3; $y++): ?>
            <option value="<?= $y; ?>" <?= $selectedStartYear === $y ? 'selected' : ''; ?>><?= $y; ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="col-md-2" id="periodeRentangEndWrapper" style="display:none;">
        <label for="periode_end_month" class="form-label">Sampai Bulan</label>
        <select class="form-control" id="periode_end_month" name="periode_end_month">
          <?php foreach ($bulan_penggunaan as $bln): ?>
            <option value="<?= htmlspecialchars($bln); ?>" <?= $selectedEndMonth === $bln ? 'selected' : ''; ?>><?= htmlspecialchars($bln); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2" id="periodeRentangEndYearWrapper" style="display:none;">
        <label for="periode_end_year" class="form-label">Tahun Selesai</label>
        <select class="form-control" id="periode_end_year" name="periode_end_year">
          <?php for ($y = $currentYear - 1; $y <= $currentYear + 3; $y++): ?>
            <option value="<?= $y; ?>" <?= $selectedEndYear === $y ? 'selected' : ''; ?>><?= $y; ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div class="col-md-12 alert alert-info py-2 px-3 mb-0" id="periodePermanenInfo" style="display:none; font-size: 12px;">
        Tambahan biaya akan berlaku terus-menerus setiap periode tagihan sampai dinonaktifkan manual.
      </div>
      </div>

      <div class="col-md-3">
        <label for="nominal_type" class="form-label">Jenis Nilai Biaya</label>
        <select class="form-control" id="nominal_type" name="nominal_type" required>
          <option value="nominal" <?= $selectedNominalType === 'nominal' ? 'selected' : ''; ?>>Nominal Tetap (Rp)</option>
          <option value="persentase" <?= $selectedNominalType === 'persentase' ? 'selected' : ''; ?>>Persentase (%)</option>
        </select>
      </div>

      <div class="col-md-3">
        <label for="nominal" class="form-label" id="nominalLabel">Nominal Tambahan</label>
        <input type="number" min="1" step="0.01" class="form-control" id="nominal" name="nominal" placeholder="Contoh: 50000" value="<?= htmlspecialchars((string)($_POST['nominal'] ?? '')); ?>" required>
      </div>

      <div class="col-md-9">
        <label for="keterangan" class="form-label">Keterangan Tambahan Biaya</label>
        <input type="text" class="form-control" id="keterangan" name="keterangan" placeholder="Contoh: Biaya maintenance" value="<?= htmlspecialchars((string)($_POST['keterangan'] ?? '')); ?>">
      </div>

      <div class="col-md-12" id="detailPelangganBox" style="display:none;">
        <div class="alert alert-info mb-0">
          <strong>Detail Pelanggan:</strong><br>
          IDPEL: <span id="detailIdpel">-</span><br>
          Nama: <span id="detailNama">-</span><br>
          Paket: <span id="detailPaket">-</span><br>
          Area: <span id="detailArea">-</span><br>
          Pemilik: <span id="detailPemilik">-</span>
        </div>
      </div>

      <div class="col-md-3 d-grid">
        <button type="submit" name="save_fee" value="1" class="btn btn-primary">Simpan Tambahan Biaya</button>
      </div>
    </form>
  </div>

  <div class="card shadow p-4">
    <h6 class="mb-3">Daftar Tambahan Biaya Aktif</h6>
    <form method="POST" id="bulkDeactivateFeeForm" onsubmit="return submitBulkDeactivateFee();" class="mb-3 d-flex gap-2 align-items-center">
      <input type="hidden" name="bulk_delete_fee_ids" value="1">
      <input type="hidden" name="bulk_fee_ids" id="bulkFeeIds" value="">
      <button type="submit" class="btn btn-danger btn-sm">Nonaktifkan Terpilih</button>
      <small class="text-muted">Centang beberapa baris biaya lalu klik tombol ini.</small>
    </form>
    <div class="table-responsive">
      <table class="table table-striped table-bordered">
        <thead>
          <tr>
            <th style="width:36px;"><input type="checkbox" id="checkAllFees"></th>
            <th>Mode</th>
            <th>Cakupan</th>
            <th>IDPEL</th>
            <th>Nama</th>
            <th>Periode</th>
            <th>Nominal</th>
            <th>Keterangan</th>
            <th>Pemilik</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($activeFees) === 0): ?>
            <tr>
              <td colspan="10" class="text-center">Belum ada tambahan biaya aktif.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($activeFees as $fee): ?>
              <?php
                $modeLabel = $fee['MODE'] === 'global' ? 'Global' : 'Per Pelanggan';
                $scopeLabel = '-';
                if ($fee['MODE'] === 'global') {
                  $scopeLabel = 'Server: ' . (string)$fee['PEMILIK'];
                  $globalAreaText = trim((string)($fee['GLOBAL_AREA'] ?? ''));
                  $globalPaketText = trim((string)($fee['GLOBAL_PAKET'] ?? ''));
                  if ($globalAreaText !== '') {
                    $scopeLabel .= ' (Area: ' . $globalAreaText . ')';
                  }
                  if ($globalPaketText !== '') {
                    $scopeLabel .= ' (Paket: ' . $globalPaketText . ')';
                  }
                }
              ?>
              <tr>
                <td class="text-center">
                  <input type="checkbox" class="fee-row-check" value="<?= (int)$fee['id']; ?>">
                </td>
                <td><?= htmlspecialchars($modeLabel); ?></td>
                <td><?= htmlspecialchars($scopeLabel); ?></td>
                <td><?= htmlspecialchars((string)($fee['IDPEL'] ?? '-')); ?></td>
                <td><?= htmlspecialchars((string)($fee['pelanggan_nama'] ?? '-')); ?></td>
                <td><?= htmlspecialchars((string)$fee['PERIODE']); ?></td>
                <td>
                  <?php if ((string)($fee['NOMINAL_TYPE'] ?? 'nominal') === 'persentase'): ?>
                    <?= number_format((float)$fee['NOMINAL'], 2, ',', '.'); ?>%
                  <?php else: ?>
                    Rp <?= number_format((float)$fee['NOMINAL'], 0, ',', '.'); ?>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string)($fee['KETERANGAN'] ?? '')); ?></td>
                <td><?= htmlspecialchars((string)$fee['PEMILIK']); ?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('Nonaktifkan tambahan biaya ini?');" style="display:inline;">
                    <input type="hidden" name="delete_fee_id" value="<?= (int)$fee['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger" data-perm="btn_biaya_nonaktifkan">Nonaktifkan</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
<style>
  #extraFeePage .card {
    background: var(--bs-body-bg, #ffffff);
    color: var(--bs-body-color, #1f2937);
    border: 1px solid var(--bs-border-color, #d1d5db);
  }

  #extraFeePage .card h5,
  #extraFeePage .card h6,
  #extraFeePage .form-label,
  #extraFeePage .small,
  #extraFeePage .table,
  #extraFeePage .table thead th,
  #extraFeePage .table tbody td {
    color: var(--bs-body-color, #1f2937);
  }

  #extraFeePage .text-muted {
    color: rgba(148, 163, 184, 0.9) !important;
  }

  #extraFeePage .form-control,
  #extraFeePage .form-select,
  #extraFeePage textarea {
    background: var(--bs-body-bg, #ffffff);
    color: var(--bs-body-color, #111827);
    border: 1px solid var(--bs-border-color, #cbd5e1);
  }

  #extraFeePage .form-control::placeholder,
  #extraFeePage textarea::placeholder {
    color: rgba(148, 163, 184, 0.95);
    opacity: 1;
  }

  #extraFeePage .choices {
    margin-bottom: 0;
  }

  #extraFeePage .choices__inner {
    min-height: 42px;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    border: 1px solid var(--bs-border-color, #cbd5e1) !important;
    background: var(--bs-body-bg, #ffffff) !important;
    color: var(--bs-body-color, #111827) !important;
    font-size: 0.875rem;
  }

  #extraFeePage .choices__list--single {
    padding: 0;
  }

  #extraFeePage .choices__list--single .choices__item,
  #extraFeePage .choices__input,
  #extraFeePage .choices__input::placeholder,
  #extraFeePage .choices__placeholder {
    color: var(--bs-body-color, #111827) !important;
    opacity: 1 !important;
  }

  #extraFeePage .choices[data-type*=select-one]::after {
    border-color: var(--bs-body-color, #64748b) transparent transparent;
    right: 12px;
  }

  #extraFeePage .choices.is-open[data-type*=select-one]::after {
    border-color: transparent transparent var(--bs-body-color, #64748b);
  }

  #extraFeePage .choices__list--dropdown,
  #extraFeePage .choices__list[aria-expanded] {
    background: var(--bs-body-bg, #ffffff) !important;
    border: 1px solid var(--bs-border-color, #cbd5e1) !important;
    color: var(--bs-body-color, #111827) !important;
    z-index: 1050;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.22);
  }

  #extraFeePage .choices__list--dropdown .choices__item,
  #extraFeePage .choices__list[aria-expanded] .choices__item {
    color: var(--bs-body-color, #111827);
  }

  #extraFeePage .choices__list--dropdown .choices__item--selectable.is-highlighted,
  #extraFeePage .choices__list[aria-expanded] .choices__item--selectable.is-highlighted {
    background-color: rgba(37, 99, 235, 0.2) !important;
    color: var(--bs-body-color, #111827);
  }

  #extraFeePage .choices.is-focused .choices__inner,
  #extraFeePage .choices.is-open .choices__inner,
  #extraFeePage .form-control:focus,
  #extraFeePage .form-select:focus,
  #extraFeePage textarea:focus {
    border-color: var(--bs-primary, #2563eb) !important;
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.2);
  }

  #extraFeePage .choices__input {
    background: transparent !important;
    margin-bottom: 0;
  }
</style>

<script>
(function() {
  var modeEl = document.getElementById('mode');
  var globalServerWrapper = document.getElementById('globalServerWrapper');
  var globalServerEl = document.getElementById('global_server');
  var globalAreaWrapper = document.getElementById('globalAreaWrapper');
  var globalAreaEl = document.getElementById('global_area');
  var globalPackageWrapper = document.getElementById('globalPackageWrapper');
  var globalPackageEl = document.getElementById('global_package');
  var odpFilterWrapper = document.getElementById('odpFilterWrapper');
  var odpFilterEl = document.getElementById('odp_filter');
  var idpelWrapper = document.getElementById('idpelWrapper');
  var idpelEl = document.getElementById('idpel');
  var detailBox = document.getElementById('detailPelangganBox');
  var nominalTypeEl = document.getElementById('nominal_type');
  var nominalInputEl = document.getElementById('nominal');
  var nominalLabelEl = document.getElementById('nominalLabel');

  var globalServerChoices = null;
  var globalAreaChoices = null;
  var globalPackageChoices = null;
  var odpFilterChoices = null;
  var idpelChoices = null;
  var idpelOptionsMaster = Array.prototype.slice.call(idpelEl.options);

  var serverAreaMap = <?= json_encode($serverAreaMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var serverPackageMap = <?= json_encode($serverPackageMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var selectedGlobalArea = <?= json_encode($selectedGlobalArea, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var selectedGlobalPackage = <?= json_encode($selectedGlobalPackage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
  var selectedOdpFilter = <?= json_encode($selectedOdpFilter, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

  function createChoices(selectEl, placeholder) {
    if (!window.Choices) return null;
    return new Choices(selectEl, {
      searchEnabled: true,
      shouldSort: false,
      itemSelectText: '',
      searchPlaceholderValue: placeholder
    });
  }

  function rebuildChoices(instance, selectEl, placeholder) {
    if (instance) {
      instance.destroy();
    }
    return createChoices(selectEl, placeholder);
  }

  function toggleMode() {
    var isGlobal = modeEl.value === 'global';
    var isPerPelanggan = modeEl.value === 'per_pelanggan';

    globalServerWrapper.style.display = isGlobal ? '' : 'none';
    globalAreaWrapper.style.display = isGlobal ? '' : 'none';
    globalPackageWrapper.style.display = isGlobal ? '' : 'none';
    globalServerEl.required = isGlobal;

    idpelWrapper.style.display = isPerPelanggan ? '' : 'none';
    idpelEl.required = isPerPelanggan;
    odpFilterEl.required = false;
    odpFilterWrapper.style.display = isPerPelanggan ? '' : 'none';

    if (!isPerPelanggan) {
      detailBox.style.display = 'none';
    } else {
      showDetail();
    }

    if (!isGlobal) {
      globalAreaEl.value = '';
      globalPackageEl.value = '';
    }
  }

  function showDetail() {
    var selected = idpelEl.options[idpelEl.selectedIndex];
    var idpel = selected ? selected.value : '';
    if (!idpel) {
      detailBox.style.display = 'none';
      return;
    }

    document.getElementById('detailIdpel').textContent = idpel;
    document.getElementById('detailNama').textContent = selected.getAttribute('data-nama') || '-';
    document.getElementById('detailPaket').textContent = selected.getAttribute('data-paket') || '-';
    document.getElementById('detailArea').textContent = selected.getAttribute('data-area') || '-';
    document.getElementById('detailPemilik').textContent = selected.getAttribute('data-pemilik') || '-';
    detailBox.style.display = '';
  }

  function filterIdpelByOdp() {
    var selectedOdp = (odpFilterEl.value || '').toLowerCase();
    var keepSelected = idpelEl.value;

    idpelEl.innerHTML = '';
    for (var i = 0; i < idpelOptionsMaster.length; i++) {
      var opt = idpelOptionsMaster[i];
      var value = opt.value || '';
      var odp = (opt.getAttribute('data-odp') || '').toLowerCase();
      var pass = value === '' || selectedOdp === '' || odp === selectedOdp;
      if (pass) {
        idpelEl.appendChild(opt.cloneNode(true));
      }
    }

    if (keepSelected) {
      idpelEl.value = keepSelected;
      if (idpelEl.value !== keepSelected) {
        idpelEl.value = '';
      }
    }

    idpelChoices = rebuildChoices(idpelChoices, idpelEl, 'Cari IDPEL / nama pelanggan...');
    showDetail();
  }

  function toggleNominalType() {
    var isPersentase = nominalTypeEl.value === 'persentase';
    nominalLabelEl.textContent = isPersentase ? 'Nilai Tambahan (%)' : 'Nominal Tambahan (Rp)';
    nominalInputEl.placeholder = isPersentase ? 'Contoh: 10' : 'Contoh: 50000';
    nominalInputEl.max = isPersentase ? '100' : '';
    nominalInputEl.step = isPersentase ? '0.01' : '1';
  }

  var periodeTypeEl = document.getElementById('periode_type');
  var periodeBulananWrapper = document.getElementById('periodeBulananWrapper');
  var periodeBulananYearWrapper = document.getElementById('periodeBulananYearWrapper');
  var periodeRentangStartWrapper = document.getElementById('periodeRentangStartWrapper');
  var periodeRentangStartYearWrapper = document.getElementById('periodeRentangStartYearWrapper');
  var periodeRentangEndWrapper = document.getElementById('periodeRentangEndWrapper');
  var periodeRentangEndYearWrapper = document.getElementById('periodeRentangEndYearWrapper');
  var periodePermanenInfo = document.getElementById('periodePermanenInfo');
  var periodeMonthEl = document.getElementById('periode_month');
  var periodeYearEl = document.getElementById('periode_year');
  var periodeStartMonthEl = document.getElementById('periode_start_month');
  var periodeStartYearEl = document.getElementById('periode_start_year');
  var periodeEndMonthEl = document.getElementById('periode_end_month');
  var periodeEndYearEl = document.getElementById('periode_end_year');

  function togglePeriodeType() {
    var type = periodeTypeEl.value;
    var isBulanan = type === 'bulanan';
    var isRentang = type === 'rentang';
    var isPermanen = type === 'permanen';

    periodeBulananWrapper.style.display = isBulanan ? '' : 'none';
    periodeBulananYearWrapper.style.display = isBulanan ? '' : 'none';
    periodeRentangStartWrapper.style.display = isRentang ? '' : 'none';
    periodeRentangStartYearWrapper.style.display = isRentang ? '' : 'none';
    periodeRentangEndWrapper.style.display = isRentang ? '' : 'none';
    periodeRentangEndYearWrapper.style.display = isRentang ? '' : 'none';
    periodePermanenInfo.style.display = isPermanen ? '' : 'none';

    periodeMonthEl.required = isBulanan;
    periodeYearEl.required = isBulanan;
    periodeStartMonthEl.required = isRentang;
    periodeStartYearEl.required = isRentang;
    periodeEndMonthEl.required = isRentang;
    periodeEndYearEl.required = isRentang;
  }

  function renderAreaOptions(selectedValue) {
    var currentServer = globalServerEl.value || '';
    var areas = serverAreaMap[currentServer] || [];

    globalAreaEl.innerHTML = '';
    var defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Semua Area';
    globalAreaEl.appendChild(defaultOption);

    for (var i = 0; i < areas.length; i++) {
      var areaOption = document.createElement('option');
      areaOption.value = areas[i];
      areaOption.textContent = areas[i];
      globalAreaEl.appendChild(areaOption);
    }

    if (selectedValue && areas.indexOf(selectedValue) !== -1) {
      globalAreaEl.value = selectedValue;
    } else {
      globalAreaEl.value = '';
    }

    globalAreaChoices = rebuildChoices(globalAreaChoices, globalAreaEl, 'Cari area...');
  }

  function renderPackageOptions(selectedValue) {
    var currentServer = globalServerEl.value || '';
    var paketList = serverPackageMap[currentServer] || [];

    globalPackageEl.innerHTML = '';
    var defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Semua Paket';
    globalPackageEl.appendChild(defaultOption);

    for (var i = 0; i < paketList.length; i++) {
      var paketOption = document.createElement('option');
      paketOption.value = paketList[i];
      paketOption.textContent = paketList[i];
      globalPackageEl.appendChild(paketOption);
    }

    if (selectedValue && paketList.indexOf(selectedValue) !== -1) {
      globalPackageEl.value = selectedValue;
    } else {
      globalPackageEl.value = '';
    }

    globalPackageChoices = rebuildChoices(globalPackageChoices, globalPackageEl, 'Cari paket...');
  }

  modeEl.addEventListener('change', toggleMode);
  globalServerEl.addEventListener('change', function() {
    renderAreaOptions('');
    renderPackageOptions('');
    toggleMode();
  });
  odpFilterEl.addEventListener('change', filterIdpelByOdp);
  idpelEl.addEventListener('change', showDetail);
  nominalTypeEl.addEventListener('change', toggleNominalType);
  periodeTypeEl.addEventListener('change', togglePeriodeType);

  globalServerChoices = createChoices(globalServerEl, 'Cari server...');
  odpFilterChoices = createChoices(odpFilterEl, 'Cari ODP...');
  idpelChoices = createChoices(idpelEl, 'Cari IDPEL / nama pelanggan...');

  renderAreaOptions(selectedGlobalArea);
  renderPackageOptions(selectedGlobalPackage);
  if (selectedOdpFilter) {
    odpFilterEl.value = selectedOdpFilter;
  }
  filterIdpelByOdp();
  toggleMode();
  toggleNominalType();
  togglePeriodeType();

  var checkAllFees = document.getElementById('checkAllFees');
  var feeRowChecks = document.querySelectorAll('.fee-row-check');

  function syncFeeMasterCheckbox() {
    if (!checkAllFees) {
      return;
    }

    var total = feeRowChecks.length;
    var checked = 0;
    for (var i = 0; i < feeRowChecks.length; i++) {
      if (feeRowChecks[i].checked) {
        checked++;
      }
    }

    checkAllFees.checked = total > 0 && checked === total;
    checkAllFees.indeterminate = checked > 0 && checked < total;
  }

  if (checkAllFees) {
    checkAllFees.addEventListener('change', function() {
      for (var i = 0; i < feeRowChecks.length; i++) {
        feeRowChecks[i].checked = !!checkAllFees.checked;
      }
      syncFeeMasterCheckbox();
    });
  }

  for (var i = 0; i < feeRowChecks.length; i++) {
    feeRowChecks[i].addEventListener('change', syncFeeMasterCheckbox);
  }
  syncFeeMasterCheckbox();
})();

function submitBulkDeactivateFee() {
  var checks = document.querySelectorAll('.fee-row-check:checked');
  if (!checks || checks.length === 0) {
    alert('Pilih minimal satu tambahan biaya untuk dinonaktifkan.');
    return false;
  }

  var ids = [];
  for (var i = 0; i < checks.length; i++) {
    ids.push(checks[i].value);
  }

  var hidden = document.getElementById('bulkFeeIds');
  if (hidden) {
    hidden.value = ids.join(',');
  }

  return confirm('Nonaktifkan ' + ids.length + ' tambahan biaya terpilih?');
}
</script>

<?php require 'footer.php'; ?>
