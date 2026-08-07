<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('diskon', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Diskon.</div></div>';
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

$createTableSql = "CREATE TABLE IF NOT EXISTS diskon_pelanggan (
  id INT AUTO_INCREMENT PRIMARY KEY,
  MODE ENUM('global','per_pelanggan') NOT NULL DEFAULT 'global',
  GLOBAL_SCOPE ENUM('server','odp') NULL,
  SCOPE_VALUE VARCHAR(190) NULL,
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
  INDEX idx_diskon_lookup (ACTIVE, MODE, IDPEL, PEMILIK, PERIODE),
  INDEX idx_diskon_scope (ACTIVE, MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, GLOBAL_PAKET, PERIODE),
  INDEX idx_diskon_periode (PERIODE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createTableSql);

$existingDiskonColumns = [];
$columnQuery = mysqli_query($conn, "SHOW COLUMNS FROM diskon_pelanggan");
if ($columnQuery) {
  while ($col = mysqli_fetch_assoc($columnQuery)) {
    $existingDiskonColumns[] = (string)$col['Field'];
  }
}

if (!in_array('GLOBAL_SCOPE', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_SCOPE ENUM('server','odp') NULL AFTER MODE");
}
if (!in_array('SCOPE_VALUE', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN SCOPE_VALUE VARCHAR(190) NULL AFTER GLOBAL_SCOPE");
}
if (!in_array('GLOBAL_AREA', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_AREA VARCHAR(120) NULL AFTER SCOPE_VALUE");
}
if (!in_array('GLOBAL_PAKET', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN GLOBAL_PAKET VARCHAR(150) NULL AFTER GLOBAL_AREA");
}
if (!in_array('NOMINAL_TYPE', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN NOMINAL_TYPE ENUM('nominal','persentase') NOT NULL DEFAULT 'nominal' AFTER GLOBAL_PAKET");
}
if (!in_array('PERIODE_TYPE', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN PERIODE_TYPE ENUM('bulanan','rentang','permanen') NOT NULL DEFAULT 'bulanan' AFTER PERIODE");
}
if (!in_array('PERIODE_MULAI', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN PERIODE_MULAI VARCHAR(40) NULL AFTER PERIODE_TYPE");
}
if (!in_array('PERIODE_SELESAI', $existingDiskonColumns, true)) {
  mysqli_query($conn, "ALTER TABLE diskon_pelanggan ADD COLUMN PERIODE_SELESAI VARCHAR(40) NULL AFTER PERIODE_MULAI");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_discount_id'])) {
  $deleteId = (int)($_POST['delete_discount_id'] ?? 0);
  if ($deleteId > 0) {
    $deleteSql = "UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE id = ? AND PEMILIK IN ($server_list)";
    $stmtDelete = $conn->prepare($deleteSql);
    if ($stmtDelete) {
      $stmtDelete->bind_param('i', $deleteId);
      $stmtDelete->execute();
      $stmtDelete->close();
      $message = 'Diskon berhasil dinonaktifkan.';
      $messageType = 'success';
    } else {
      $message = 'Gagal menonaktifkan diskon.';
      $messageType = 'danger';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_discount_ids'])) {
  $rawBulkIds = trim((string)($_POST['bulk_discount_ids'] ?? ''));
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
    $message = 'Pilih minimal satu diskon untuk dinonaktifkan.';
    $messageType = 'warning';
  } else {
    $stmtBulkDelete = $conn->prepare("UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE id = ? AND PEMILIK IN ($server_list)");
    if ($stmtBulkDelete) {
      $totalAffected = 0;
      foreach ($bulkIds as $bulkId) {
        $stmtBulkDelete->bind_param('i', $bulkId);
        $stmtBulkDelete->execute();
        $totalAffected += (int)$stmtBulkDelete->affected_rows;
      }
      $stmtBulkDelete->close();

      if ($totalAffected > 0) {
        $message = 'Berhasil menonaktifkan ' . $totalAffected . ' diskon.';
        $messageType = 'success';
      } else {
        $message = 'Tidak ada diskon yang dinonaktifkan (cek akses atau status sudah nonaktif).';
        $messageType = 'warning';
      }
    } else {
      $message = 'Gagal menonaktifkan diskon massal.';
      $messageType = 'danger';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_discount'])) {
  $mode = strtolower(trim($_POST['mode'] ?? 'global'));
  $globalScope = strtolower(trim($_POST['global_scope'] ?? 'server'));
  $globalServer = trim($_POST['global_server'] ?? '');
  $globalArea = trim($_POST['global_area'] ?? '');
  $globalPackage = trim($_POST['global_package'] ?? '');
  $globalOdp = trim($_POST['global_odp'] ?? '');
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
    $periodeError = 'Jenis periode diskon tidak valid.';
  } elseif ($periodeType === 'bulanan') {
    if (!in_array($periodeMonth, $bulan_penggunaan, true) || $periodeYear < 2000 || $periodeYear > 2100) {
      $periodeError = 'Periode diskon tidak valid.';
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
    $message = 'Mode diskon tidak valid.';
    $messageType = 'danger';
  } elseif ($mode === 'global' && !in_array($globalScope, ['server', 'odp'], true)) {
    $message = 'Cakupan global tidak valid.';
    $messageType = 'danger';
  } elseif (!in_array($nominalType, ['nominal', 'persentase'], true)) {
    $message = 'Jenis nilai diskon tidak valid.';
    $messageType = 'danger';
  } elseif ($periodeError !== '') {
    $message = $periodeError;
    $messageType = 'danger';
  } elseif ($nominal <= 0) {
    $message = 'Nilai diskon harus lebih dari 0.';
    $messageType = 'danger';
  } elseif ($nominalType === 'persentase' && $nominal > 100) {
    $message = 'Nilai persentase maksimal 100%.';
    $messageType = 'danger';
  } elseif ($mode === 'per_pelanggan' && $idpel === '') {
    $message = 'ID pelanggan wajib dipilih untuk mode per pelanggan.';
    $messageType = 'danger';
  } else {
    $createdBy = (string)($ceknama ?? 'system');

    if ($mode === 'global') {
      if (count($ownedPemilik) === 0) {
        $message = 'Tidak ada data server/pemilik untuk user ini.';
        $messageType = 'danger';
      } else {
        $targetPemilik = '';
        $targetScopeValue = '';

        if ($globalScope === 'server') {
          if ($globalServer === '' || !in_array($globalServer, $ownedPemilik, true)) {
            $message = 'Server global tidak valid.';
            $messageType = 'danger';
          } else {
            $globalAreaIsValid = true;
            if ($globalArea !== '') {
              $stmtAreaCheck = $conn->prepare("SELECT ID FROM pelanggan WHERE PEMILIK = ? AND AREA = ? LIMIT 1");
              if ($stmtAreaCheck) {
                $stmtAreaCheck->bind_param('ss', $globalServer, $globalArea);
                $stmtAreaCheck->execute();
                $resultAreaCheck = $stmtAreaCheck->get_result();
                $globalAreaIsValid = (bool)($resultAreaCheck && $resultAreaCheck->fetch_assoc());
                $stmtAreaCheck->close();
              }
            }

            if (!$globalAreaIsValid) {
              $message = 'Area tidak ditemukan pada server yang dipilih.';
              $messageType = 'danger';
            }

            if ($messageType !== 'danger' && $globalPackage !== '') {
              $stmtPaketCheck = $conn->prepare("SELECT ID FROM pelanggan WHERE PEMILIK = ? AND PAKET = ? LIMIT 1");
              $paketValid = false;
              if ($stmtPaketCheck) {
                $stmtPaketCheck->bind_param('ss', $globalServer, $globalPackage);
                $stmtPaketCheck->execute();
                $resultPaketCheck = $stmtPaketCheck->get_result();
                $paketValid = (bool)($resultPaketCheck && $resultPaketCheck->fetch_assoc());
                $stmtPaketCheck->close();
              }
              if (!$paketValid) {
                $message = 'Paket tidak ditemukan pada server yang dipilih.';
                $messageType = 'danger';
              }
            }

            $targetPemilik = $globalServer;
            $targetScopeValue = $globalServer;
          }
        } else {
          if ($globalOdp === '' || strpos($globalOdp, '||') === false) {
            $message = 'ODP global wajib dipilih.';
            $messageType = 'danger';
          } else {
            [$selectedPemilik, $selectedOdp] = array_map('trim', explode('||', $globalOdp, 2));
            if ($selectedPemilik === '' || $selectedOdp === '' || !in_array($selectedPemilik, $ownedPemilik, true)) {
              $message = 'Data ODP global tidak valid.';
              $messageType = 'danger';
            } else {
              $stmtOdpCheck = $conn->prepare("SELECT ID FROM pelanggan WHERE PEMILIK = ? AND ODP = ? LIMIT 1");
              $odpValid = false;
              if ($stmtOdpCheck) {
                $stmtOdpCheck->bind_param('ss', $selectedPemilik, $selectedOdp);
                $stmtOdpCheck->execute();
                $resultOdpCheck = $stmtOdpCheck->get_result();
                $odpValid = (bool)($resultOdpCheck && $resultOdpCheck->fetch_assoc());
                $stmtOdpCheck->close();
              }

              if (!$odpValid) {
                $message = 'ODP tidak ditemukan pada server yang dipilih.';
                $messageType = 'danger';
              } else {
                $targetPemilik = $selectedPemilik;
                $targetScopeValue = $selectedOdp;
              }
            }
          }
        }

        if ($messageType !== 'danger') {
          $globalAreaForInsert = ($globalScope === 'server') ? $globalArea : '';
          $globalPackageForInsert = ($globalScope === 'server') ? $globalPackage : '';

          if ($periodeType === 'permanen') {
            $stmtDisable = $conn->prepare("UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'global' AND PEMILIK = ? AND PERIODE_TYPE = 'permanen' AND COALESCE(GLOBAL_SCOPE, 'server') = ? AND COALESCE(SCOPE_VALUE, PEMILIK) = ? AND COALESCE(GLOBAL_AREA, '') = ? AND COALESCE(GLOBAL_PAKET, '') = ?");
            if ($stmtDisable) {
              $stmtDisable->bind_param('sssss', $targetPemilik, $globalScope, $targetScopeValue, $globalAreaForInsert, $globalPackageForInsert);
              $stmtDisable->execute();
              $stmtDisable->close();
            }
          } else {
            $stmtDisable = $conn->prepare("UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'global' AND PEMILIK = ? AND PERIODE = ? AND COALESCE(GLOBAL_SCOPE, 'server') = ? AND COALESCE(SCOPE_VALUE, PEMILIK) = ? AND COALESCE(GLOBAL_AREA, '') = ? AND COALESCE(GLOBAL_PAKET, '') = ?");
            if ($stmtDisable) {
              $stmtDisable->bind_param('ssssss', $targetPemilik, $periode, $globalScope, $targetScopeValue, $globalAreaForInsert, $globalPackageForInsert);
              $stmtDisable->execute();
              $stmtDisable->close();
            }
          }

          $stmtInsert = $conn->prepare("INSERT INTO diskon_pelanggan (MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, GLOBAL_PAKET, IDPEL, PEMILIK, PERIODE, PERIODE_TYPE, PERIODE_MULAI, PERIODE_SELESAI, NOMINAL_TYPE, NOMINAL, KETERANGAN, ACTIVE, CREATED_BY) VALUES ('global', ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
          if ($stmtInsert) {
            $stmtInsert->bind_param('ssssssssssdss', $globalScope, $targetScopeValue, $globalAreaForInsert, $globalPackageForInsert, $targetPemilik, $periode, $periodeType, $periodeMulai, $periodeSelesai, $nominalType, $nominal, $keterangan, $createdBy);
            $okInsert = $stmtInsert->execute();
            $stmtInsert->close();

            if ($okInsert) {
              $message = 'Diskon global berhasil disimpan.';
              $messageType = 'success';
            } else {
              $message = 'Gagal menyimpan diskon global.';
              $messageType = 'danger';
            }
          } else {
            $message = 'Gagal menyiapkan query diskon global.';
            $messageType = 'danger';
          }
        }
      }
    } else {
      $pelangganSql = "SELECT IDPEL, NAMA, PAKET, AREA, PEMILIK FROM pelanggan WHERE IDPEL = ? AND PEMILIK IN ($server_list) LIMIT 1";
      $stmtPelanggan = $conn->prepare($pelangganSql);
      $pelangganRow = null;
      if ($stmtPelanggan) {
        $stmtPelanggan->bind_param('s', $idpel);
        $stmtPelanggan->execute();
        $resultPelanggan = $stmtPelanggan->get_result();
        $pelangganRow = $resultPelanggan ? $resultPelanggan->fetch_assoc() : null;
        $stmtPelanggan->close();
      }

      if (!$pelangganRow) {
        $message = 'ID pelanggan tidak ditemukan atau tidak termasuk server Anda.';
        $messageType = 'danger';
      } else {
        $pemilikPelanggan = (string)$pelangganRow['PEMILIK'];

        if ($periodeType === 'permanen') {
          $stmtDisable = $conn->prepare("UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE_TYPE = 'permanen'");
          if ($stmtDisable) {
            $stmtDisable->bind_param('ss', $idpel, $pemilikPelanggan);
            $stmtDisable->execute();
            $stmtDisable->close();
          }
        } else {
          $stmtDisable = $conn->prepare("UPDATE diskon_pelanggan SET ACTIVE = 0 WHERE ACTIVE = 1 AND MODE = 'per_pelanggan' AND IDPEL = ? AND PEMILIK = ? AND PERIODE = ?");
          if ($stmtDisable) {
            $stmtDisable->bind_param('sss', $idpel, $pemilikPelanggan, $periode);
            $stmtDisable->execute();
            $stmtDisable->close();
          }
        }

        $stmtInsert = $conn->prepare("INSERT INTO diskon_pelanggan (MODE, GLOBAL_SCOPE, SCOPE_VALUE, GLOBAL_AREA, IDPEL, PEMILIK, PERIODE, PERIODE_TYPE, PERIODE_MULAI, PERIODE_SELESAI, NOMINAL_TYPE, NOMINAL, KETERANGAN, ACTIVE, CREATED_BY) VALUES ('per_pelanggan', NULL, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
        if ($stmtInsert) {
          $stmtInsert->bind_param('sssssssdss', $idpel, $pemilikPelanggan, $periode, $periodeType, $periodeMulai, $periodeSelesai, $nominalType, $nominal, $keterangan, $createdBy);
          $okInsert = $stmtInsert->execute();
          $stmtInsert->close();

          if ($okInsert) {
            $message = 'Diskon per pelanggan berhasil disimpan.';
            $messageType = 'success';
          } else {
            $message = 'Gagal menyimpan diskon per pelanggan.';
            $messageType = 'danger';
          }
        } else {
          $message = 'Gagal menyiapkan query simpan diskon.';
          $messageType = 'danger';
        }
      }
    }
  }
}

$selectedMode = $_POST['mode'] ?? 'global';
$selectedGlobalScope = $_POST['global_scope'] ?? 'server';
$selectedGlobalServer = trim($_POST['global_server'] ?? '');
$selectedGlobalArea = trim($_POST['global_area'] ?? '');
$selectedGlobalPackage = trim($_POST['global_package'] ?? '');
$selectedGlobalOdp = trim($_POST['global_odp'] ?? '');
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
$pelangganQuery = mysqli_query($conn, "SELECT IDPEL, NAMA, PAKET, AREA, PEMILIK FROM pelanggan WHERE IDPEL <> '' AND PEMILIK IN ($server_list) ORDER BY NAMA ASC");
while ($pel = mysqli_fetch_assoc($pelangganQuery)) {
  $pelangganList[] = $pel;
}

$odpList = [];
$odpQuery = mysqli_query($conn, "SELECT DISTINCT PEMILIK, ODP FROM pelanggan WHERE ODP IS NOT NULL AND ODP != '' AND PEMILIK IN ($server_list) ORDER BY PEMILIK ASC, ODP ASC");
if ($odpQuery) {
  while ($odpRow = mysqli_fetch_assoc($odpQuery)) {
    $odpList[] = [
      'PEMILIK' => (string)$odpRow['PEMILIK'],
      'ODP' => (string)$odpRow['ODP'],
      'VALUE' => (string)$odpRow['PEMILIK'] . '||' . (string)$odpRow['ODP']
    ];
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

$activeDiscounts = [];
$discountQuery = mysqli_query(
  $conn,
  "SELECT d.*, p.NAMA AS pelanggan_nama, p.PAKET AS pelanggan_paket
   FROM diskon_pelanggan d
   LEFT JOIN pelanggan p ON p.IDPEL = d.IDPEL
   WHERE d.ACTIVE = 1 AND d.PEMILIK IN ($server_list)
   ORDER BY d.CREATED_AT DESC, d.id DESC
   LIMIT 300"
);
if ($discountQuery) {
  while ($row = mysqli_fetch_assoc($discountQuery)) {
    $activeDiscounts[] = $row;
  }
}
?>

<div class="container-fluid py-4 px-3 px-md-4" id="discountPage">
  <div class="card shadow p-4 mb-4">
    <h5 class="mb-3">Pengaturan Diskon</h5>

    <?php if ($message !== ''): ?>
      <div class="alert alert-<?= htmlspecialchars($messageType); ?>"><?= htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form method="POST" class="row g-3" id="discountForm">
      <div class="col-md-3">
        <label for="mode" class="form-label">Mode Diskon</label>
        <select class="form-control" id="mode" name="mode" required>
          <option value="global" <?= $selectedMode === 'global' ? 'selected' : ''; ?>>Global</option>
          <option value="per_pelanggan" <?= $selectedMode === 'per_pelanggan' ? 'selected' : ''; ?>>Per Pelanggan</option>
        </select>
      </div>

      <div class="col-md-3" id="globalScopeWrapper">
        <label for="global_scope" class="form-label">Global Berdasarkan</label>
        <select class="form-control" id="global_scope" name="global_scope">
          <option value="server" <?= $selectedGlobalScope === 'server' ? 'selected' : ''; ?>>Per Server</option>
          <option value="odp" <?= $selectedGlobalScope === 'odp' ? 'selected' : ''; ?>>Per ODP</option>
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
        </select>
      </div>

      <div class="col-md-4" id="globalOdpWrapper">
        <label for="global_odp" class="form-label">Pilih ODP</label>
        <select class="form-control" id="global_odp" name="global_odp">
          <option value="">-- Pilih ODP --</option>
          <?php foreach ($odpList as $odpItem): ?>
            <option value="<?= htmlspecialchars($odpItem['VALUE']); ?>" <?= $selectedGlobalOdp === $odpItem['VALUE'] ? 'selected' : ''; ?>>
              <?= htmlspecialchars($odpItem['ODP'] . ' (Server: ' . $odpItem['PEMILIK'] . ')'); ?>
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
              <?= $selectedIdpel === $pel['IDPEL'] ? 'selected' : ''; ?>
            >
              <?= htmlspecialchars($pel['IDPEL'] . ' - ' . $pel['NAMA']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label for="periode_type" class="form-label">Jenis Periode Diskon</label>
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
        Diskon akan berlaku terus-menerus setiap periode tagihan sampai dinonaktifkan manual.
      </div>

      <div class="col-md-3">
        <label for="nominal_type" class="form-label">Jenis Nilai Diskon</label>
        <select class="form-control" id="nominal_type" name="nominal_type" required>
          <option value="nominal" <?= $selectedNominalType === 'nominal' ? 'selected' : ''; ?>>Nominal Tetap (Rp)</option>
          <option value="persentase" <?= $selectedNominalType === 'persentase' ? 'selected' : ''; ?>>Persentase (%)</option>
        </select>
      </div>

      <div class="col-md-3">
        <label for="nominal" class="form-label" id="nominalLabel">Nominal Diskon</label>
        <input type="number" min="1" step="0.01" class="form-control" id="nominal" name="nominal" placeholder="Contoh: 50000" value="<?= htmlspecialchars((string)($_POST['nominal'] ?? '')); ?>" required>
      </div>

      <div class="col-md-9">
        <label for="keterangan" class="form-label">Keterangan Diskon</label>
        <input type="text" class="form-control" id="keterangan" name="keterangan" placeholder="Contoh: Promo pelanggan loyal" value="<?= htmlspecialchars((string)($_POST['keterangan'] ?? '')); ?>">
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
        <button type="submit" name="save_discount" value="1" class="btn btn-primary">Simpan Diskon</button>
      </div>
    </form>
  </div>

  <div class="card shadow p-4">
    <h6 class="mb-3">Daftar Diskon Aktif</h6>
    <form method="POST" id="bulkDeactivateForm" onsubmit="return submitBulkDeactivate();" class="mb-3 d-flex gap-2 align-items-center">
      <input type="hidden" name="bulk_delete_discount_ids" value="1">
      <input type="hidden" name="bulk_discount_ids" id="bulkDiscountIds" value="">
      <button type="submit" class="btn btn-danger btn-sm">Nonaktifkan Terpilih</button>
      <small class="text-muted">Centang beberapa baris diskon lalu klik tombol ini.</small>
    </form>
    <div class="table-responsive">
      <table class="table table-striped table-bordered">
        <thead>
          <tr>
            <th style="width:36px;"><input type="checkbox" id="checkAllDiscounts"></th>
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
          <?php if (count($activeDiscounts) === 0): ?>
            <tr>
              <td colspan="10" class="text-center">Belum ada diskon aktif.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($activeDiscounts as $disc): ?>
              <?php
                $modeLabel = $disc['MODE'] === 'global' ? 'Global' : 'Per Pelanggan';
                $scopeLabel = '-';
                if ($disc['MODE'] === 'global') {
                  $scopeType = strtolower((string)($disc['GLOBAL_SCOPE'] ?? 'server'));
                  if ($scopeType === 'odp') {
                    $scopeLabel = 'ODP: ' . (string)($disc['SCOPE_VALUE'] ?? '-');
                  } else {
                    $scopeLabel = 'Server: ' . (string)$disc['PEMILIK'];
                    $globalAreaText = trim((string)($disc['GLOBAL_AREA'] ?? ''));
                    $globalPaketText = trim((string)($disc['GLOBAL_PAKET'] ?? ''));
                    if ($globalAreaText !== '') {
                      $scopeLabel .= ' (Area: ' . $globalAreaText . ')';
                    }
                    if ($globalPaketText !== '') {
                      $scopeLabel .= ' (Paket: ' . $globalPaketText . ')';
                    }
                  }
                }
              ?>
              <tr>
                <td class="text-center">
                  <input type="checkbox" class="discount-row-check" value="<?= (int)$disc['id']; ?>">
                </td>
                <td><?= htmlspecialchars($modeLabel); ?></td>
                <td><?= htmlspecialchars($scopeLabel); ?></td>
                <td><?= htmlspecialchars((string)($disc['IDPEL'] ?? '-')); ?></td>
                <td><?= htmlspecialchars((string)($disc['pelanggan_nama'] ?? '-')); ?></td>
                <td><?= htmlspecialchars((string)$disc['PERIODE']); ?></td>
                <td>
                  <?php if ((string)($disc['NOMINAL_TYPE'] ?? 'nominal') === 'persentase'): ?>
                    <?= number_format((float)$disc['NOMINAL'], 2, ',', '.'); ?>%
                  <?php else: ?>
                    Rp <?= number_format((float)$disc['NOMINAL'], 0, ',', '.'); ?>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string)($disc['KETERANGAN'] ?? '')); ?></td>
                <td><?= htmlspecialchars((string)$disc['PEMILIK']); ?></td>
                <td>
                  <form method="POST" onsubmit="return confirm('Nonaktifkan diskon ini?');" style="display:inline;">
                    <input type="hidden" name="delete_discount_id" value="<?= (int)$disc['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger" data-perm="btn_diskon_nonaktifkan">Nonaktifkan</button>
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
  #discountPage .card {
    background: var(--bs-body-bg, #ffffff);
    color: var(--bs-body-color, #1f2937);
    border: 1px solid var(--bs-border-color, #d1d5db);
  }

  #discountPage .card h5,
  #discountPage .card h6,
  #discountPage .form-label,
  #discountPage .small,
  #discountPage .table,
  #discountPage .table thead th,
  #discountPage .table tbody td {
    color: var(--bs-body-color, #1f2937);
  }

  #discountPage .text-muted {
    color: rgba(148, 163, 184, 0.9) !important;
  }

  #discountPage .form-control,
  #discountPage .form-select,
  #discountPage textarea {
    background: var(--bs-body-bg, #ffffff);
    color: var(--bs-body-color, #111827);
    border: 1px solid var(--bs-border-color, #cbd5e1);
  }

  #discountPage .form-control::placeholder,
  #discountPage textarea::placeholder {
    color: rgba(148, 163, 184, 0.95);
    opacity: 1;
  }

  #discountPage .choices {
    margin-bottom: 0;
  }

  #discountPage .choices__inner {
    min-height: 42px;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    border: 1px solid var(--bs-border-color, #cbd5e1) !important;
    background: var(--bs-body-bg, #ffffff) !important;
    color: var(--bs-body-color, #111827) !important;
    font-size: 0.875rem;
  }

  #discountPage .choices__list--single {
    padding: 0;
  }

  #discountPage .choices__list--single .choices__item,
  #discountPage .choices__input,
  #discountPage .choices__input::placeholder,
  #discountPage .choices__placeholder {
    color: var(--bs-body-color, #111827) !important;
    opacity: 1 !important;
  }

  #discountPage .choices[data-type*=select-one]::after {
    border-color: var(--bs-body-color, #64748b) transparent transparent;
    right: 12px;
  }

  #discountPage .choices.is-open[data-type*=select-one]::after {
    border-color: transparent transparent var(--bs-body-color, #64748b);
  }

  #discountPage .choices__list--dropdown,
  #discountPage .choices__list[aria-expanded] {
    background: var(--bs-body-bg, #ffffff) !important;
    border: 1px solid var(--bs-border-color, #cbd5e1) !important;
    color: var(--bs-body-color, #111827) !important;
    z-index: 1050;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.22);
  }

  #discountPage .choices__list--dropdown .choices__item,
  #discountPage .choices__list[aria-expanded] .choices__item {
    color: var(--bs-body-color, #111827);
  }

  #discountPage .choices__list--dropdown .choices__item--selectable.is-highlighted,
  #discountPage .choices__list[aria-expanded] .choices__item--selectable.is-highlighted {
    background-color: rgba(37, 99, 235, 0.2) !important;
    color: var(--bs-body-color, #111827);
  }

  #discountPage .choices.is-focused .choices__inner,
  #discountPage .choices.is-open .choices__inner,
  #discountPage .form-control:focus,
  #discountPage .form-select:focus,
  #discountPage textarea:focus {
    border-color: var(--bs-primary, #2563eb) !important;
    box-shadow: 0 0 0 0.2rem rgba(37, 99, 235, 0.2);
  }

  #discountPage .choices__input {
    background: transparent !important;
    margin-bottom: 0;
  }
</style>

<script>
  (function() {
    var modeEl = document.getElementById('mode');
    var globalScopeWrapper = document.getElementById('globalScopeWrapper');
    var globalScopeEl = document.getElementById('global_scope');
    var globalServerWrapper = document.getElementById('globalServerWrapper');
    var globalServerEl = document.getElementById('global_server');
    var globalAreaWrapper = document.getElementById('globalAreaWrapper');
    var globalAreaEl = document.getElementById('global_area');
    var globalPackageWrapper = document.getElementById('globalPackageWrapper');
    var globalPackageEl = document.getElementById('global_package');
    var globalOdpWrapper = document.getElementById('globalOdpWrapper');
    var globalOdpEl = document.getElementById('global_odp');
    var nominalTypeEl = document.getElementById('nominal_type');
    var nominalInputEl = document.getElementById('nominal');
    var nominalLabelEl = document.getElementById('nominalLabel');
    var idpelWrapper = document.getElementById('idpelWrapper');
    var idpelEl = document.getElementById('idpel');
    var detailBox = document.getElementById('detailPelangganBox');

    var idpelChoices = null;
    var globalOdpChoices = null;
    var globalPackageChoices = null;
    var serverAreaMap = <?= json_encode($serverAreaMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var serverPackageMap = <?= json_encode($serverPackageMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var selectedGlobalArea = <?= json_encode($selectedGlobalArea, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    var selectedGlobalPackage = <?= json_encode($selectedGlobalPackage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    if (window.Choices) {
      idpelChoices = new Choices(idpelEl, {
        searchEnabled: true,
        shouldSort: false,
        itemSelectText: '',
        searchPlaceholderValue: 'Cari IDPEL / nama pelanggan...'
      });

      globalOdpChoices = new Choices(globalOdpEl, {
        searchEnabled: true,
        shouldSort: false,
        itemSelectText: '',
        searchPlaceholderValue: 'Cari ODP / server...'
      });
    }

    function toggleMode() {
      var isGlobal = modeEl.value === 'global';
      var isPerPelanggan = modeEl.value === 'per_pelanggan';

      globalScopeWrapper.style.display = isGlobal ? '' : 'none';
      globalScopeEl.required = isGlobal;

      var isScopeServer = isGlobal && globalScopeEl.value === 'server';
      var isScopeOdp = isGlobal && globalScopeEl.value === 'odp';

      globalServerWrapper.style.display = isScopeServer ? '' : 'none';
      globalServerEl.required = isScopeServer;
      globalAreaWrapper.style.display = isScopeServer ? '' : 'none';
      globalPackageWrapper.style.display = isScopeServer ? '' : 'none';

      globalOdpWrapper.style.display = isScopeOdp ? '' : 'none';
      globalOdpEl.required = isScopeOdp;

      idpelWrapper.style.display = isPerPelanggan ? '' : 'none';
      idpelEl.required = isPerPelanggan;

      if (idpelChoices) {
        if (isPerPelanggan) {
          idpelChoices.enable();
        } else {
          idpelChoices.disable();
        }
      }

      if (globalOdpChoices) {
        if (isScopeOdp) {
          globalOdpChoices.enable();
        } else {
          globalOdpChoices.disable();
        }
      }

      if (!isScopeServer) {
        globalAreaEl.value = '';
        globalPackageEl.value = '';
      }

      if (!isPerPelanggan) {
        detailBox.style.display = 'none';
      } else {
        showDetail();
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

    function toggleNominalType() {
      var isPersentase = nominalTypeEl.value === 'persentase';
      nominalLabelEl.textContent = isPersentase ? 'Nilai Diskon (%)' : 'Nominal Diskon (Rp)';
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

      if (window.Choices) {
        if (globalPackageChoices) {
          globalPackageChoices.destroy();
        }
        globalPackageChoices = new Choices(globalPackageEl, {
          searchEnabled: true,
          shouldSort: false,
          itemSelectText: '',
          searchPlaceholderValue: 'Cari paket...'
        });
      }
    }

    modeEl.addEventListener('change', toggleMode);
    globalScopeEl.addEventListener('change', toggleMode);
    globalServerEl.addEventListener('change', function() {
      renderAreaOptions('');
      renderPackageOptions('');
      toggleMode();
    });
    nominalTypeEl.addEventListener('change', toggleNominalType);
    idpelEl.addEventListener('change', showDetail);
    periodeTypeEl.addEventListener('change', togglePeriodeType);
    renderAreaOptions(selectedGlobalArea);
    renderPackageOptions(selectedGlobalPackage);
    toggleMode();
    toggleNominalType();
    togglePeriodeType();

    var checkAllDiscounts = document.getElementById('checkAllDiscounts');
    var discountRowChecks = document.querySelectorAll('.discount-row-check');

    function syncDiscountMasterCheckbox() {
      if (!checkAllDiscounts) {
        return;
      }

      var total = discountRowChecks.length;
      var checked = 0;
      for (var i = 0; i < discountRowChecks.length; i++) {
        if (discountRowChecks[i].checked) {
          checked++;
        }
      }

      checkAllDiscounts.checked = total > 0 && checked === total;
      checkAllDiscounts.indeterminate = checked > 0 && checked < total;
    }

    if (checkAllDiscounts) {
      checkAllDiscounts.addEventListener('change', function() {
        for (var i = 0; i < discountRowChecks.length; i++) {
          discountRowChecks[i].checked = !!checkAllDiscounts.checked;
        }
        syncDiscountMasterCheckbox();
      });
    }

    for (var i = 0; i < discountRowChecks.length; i++) {
      discountRowChecks[i].addEventListener('change', syncDiscountMasterCheckbox);
    }
    syncDiscountMasterCheckbox();
  })();

  function submitBulkDeactivate() {
    var checks = document.querySelectorAll('.discount-row-check:checked');
    if (!checks || checks.length === 0) {
      alert('Pilih minimal satu diskon untuk dinonaktifkan.');
      return false;
    }

    var ids = [];
    for (var i = 0; i < checks.length; i++) {
      ids.push(checks[i].value);
    }

    var hidden = document.getElementById('bulkDiscountIds');
    if (hidden) {
      hidden.value = ids.join(',');
    }

    return confirm('Nonaktifkan ' + ids.length + ' diskon terpilih?');
  }
</script>

<?php require 'footer.php'; ?>