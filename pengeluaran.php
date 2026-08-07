<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Laporan_pengeluaran', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Laporan Pengeluaran.</div></div>';
        require 'footer.php';
        exit;
    }
}

// Handle tambah kategori pengeluaran
if (isset($_POST['tambah_kategori']) && isset($_POST['kategori_baru'])) {
  $kategori_baru = trim(mysqli_real_escape_string($conn, $_POST['kategori_baru']));
  $pemilik = isset($ceknama) ? mysqli_real_escape_string($conn, $ceknama) : '';
  if ($kategori_baru !== '') {
    // Cek apakah kategori sudah ada untuk user ini
    $cek = mysqli_query($conn, "SELECT 1 FROM kategori_pengeluaran WHERE nama='$kategori_baru' AND pemilik='$pemilik'");
    if (mysqli_num_rows($cek) == 0) {
      mysqli_query($conn, "INSERT INTO kategori_pengeluaran (nama, pemilik) VALUES ('$kategori_baru', '$pemilik')");
    }
  }
  echo '<script>location.href="pengeluaran.php";</script>';
  exit;
}
// Handle tambah/edit pengeluaran
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tanggal = mysqli_real_escape_string($conn, $_POST['tanggal']);
  $kategori = mysqli_real_escape_string($conn, $_POST['kategori']);
  $keterangan = mysqli_real_escape_string($conn, $_POST['keterangan']);
  $jumlah = (int)$_POST['jumlah'];
  // Ambil pemilik dari session user login
  $pemilik = isset($ceknama) ? mysqli_real_escape_string($conn, $ceknama) : '';
  if (isset($_POST['id']) && $_POST['id'] !== '') {
    // Edit
    $id = (int)$_POST['id'];
    $sql_upd = "UPDATE pengeluaran SET tanggal='$tanggal', kategori='$kategori', keterangan='$keterangan', jumlah=$jumlah, pemilik='$ceknama' WHERE id=$id";
    mysqli_query($conn, $sql_upd);
  } else {
    // Tambah
    $sql_ins = "INSERT INTO pengeluaran (tanggal, kategori, keterangan, jumlah, pemilik) VALUES ('$tanggal', '$kategori', '$keterangan', $jumlah, '$ceknama')";
    mysqli_query($conn, $sql_ins);
  }
  echo '<script>location.href="pengeluaran.php";</script>';
  exit;
}
// Handle hapus
if (isset($_GET['hapus']) && is_numeric($_GET['hapus'])) {
  $id = (int)$_GET['hapus'];
  mysqli_query($conn, "DELETE FROM pengeluaran WHERE id=$id");
  echo '<script>location.href="pengeluaran.php";</script>';
  exit;
}
// Handle edit: fetch data if ?edit=id
$edit_data = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
  $id = (int)$_GET['edit'];
  $q = mysqli_query($conn, "SELECT * FROM pengeluaran WHERE id=$id");
  if ($q && $row = mysqli_fetch_assoc($q)) {
    $edit_data = $row;
  }
}
// Ambil filter bulan & tahun dari GET, default ke bulan/tahun sekarang
$filter_bulan = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
$filter_tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');

// Query daftar pengeluaran berdasarkan pemilik dan kategori (jika difilter)
$where = [];
if ($filter_bulan !== 'all') {
  $where[] = "MONTH(tanggal) = '" . intval($filter_bulan) . "'";
}
if ($filter_tahun !== 'all') {
  $where[] = "YEAR(tanggal) = '" . intval($filter_tahun) . "'";
}
if (isset($_GET['kategori']) && $_GET['kategori'] !== '') {
  $filter_kategori = mysqli_real_escape_string($conn, $_GET['kategori']);
  $where[] = "kategori = '" . $filter_kategori . "'";
} else {
  $filter_kategori = '';
}
$where[] = isset($ceknama) ? ("pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'") : "1=0";
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$sql = "SELECT id, tanggal, kategori, keterangan, jumlah, pemilik FROM pengeluaran $where_sql ORDER BY tanggal DESC";
$result = mysqli_query($conn, $sql);

// Statistik total pengeluaran per kategori
$sql_stat = "SELECT kategori, SUM(jumlah) as total FROM pengeluaran $where_sql GROUP BY kategori ORDER BY total DESC";
$result_stat = mysqli_query($conn, $sql_stat);
?>
<div class="container-fluid py-4 px-3 px-md-4">
  <div class="row">
    <div class="col-12">
      <!-- Card Tambah/Edit Pengeluaran (sekaligus tambah kategori) -->
      <div class="card shadow mb-4" style="min-width:320px;">
    <div class="card-header bg-success text-white">
      <h5 class="mb-0"><?php echo $edit_data ? 'Edit' : 'Tambah'; ?> Pengeluaran</h5>
    </div>
    <div class="card-body">
      <form method="post">
        <?php if ($edit_data) { ?><input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>"><?php } ?>
        <div class="row g-3 align-items-end">
          <div class="col-md-3">
            <label for="tanggal" class="form-label">Tanggal</label>
            <input type="date" name="tanggal" id="tanggal" class="form-control" required value="<?php echo $edit_data ? htmlspecialchars($edit_data['tanggal']) : date('Y-m-d'); ?>">
          </div>
          <div class="col-md-3">
            <label for="kategori" class="form-label">Kategori</label>
            <div class="input-group">
              <select name="kategori" id="kategori" class="form-select" required>
                <?php
                // Ambil kategori dari tabel kategori_pengeluaran (khusus user ini dan global)
                $pemilik = isset($ceknama) ? mysqli_real_escape_string($conn, $ceknama) : '';
                $kategori_selected = $edit_data ? $edit_data['kategori'] : '';
                $qkat = mysqli_query($conn, "SELECT nama FROM kategori_pengeluaran WHERE pemilik IS NULL OR pemilik='$pemilik' ORDER BY nama ASC");
                $kategori_opsi_db = [];
                if ($qkat) {
                  while ($rowkat = mysqli_fetch_assoc($qkat)) {
                    $kategori_opsi_db[] = $rowkat['nama'];
                    $sel = ($rowkat['nama'] == $kategori_selected) ? 'selected' : '';
                    echo "<option value='" . htmlspecialchars($rowkat['nama']) . "' $sel>" . htmlspecialchars($rowkat['nama']) . "</option>";
                  }
                }
                // Jika sedang edit dan kategori tidak ada di tabel, tetap tampilkan
                if ($kategori_selected && !in_array($kategori_selected, $kategori_opsi_db)) {
                  echo "<option value='" . htmlspecialchars($kategori_selected) . "' selected>" . htmlspecialchars($kategori_selected) . "</option>";
                }
                ?>
              </select>
              <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formTambahKategori" aria-expanded="false" aria-controls="formTambahKategori">+</button>
            </div>
          </div>
          <div class="col-md-3">
            <label for="keterangan" class="form-label">Keterangan</label>
            <input type="text" name="keterangan" id="keterangan" class="form-control" value="<?php echo $edit_data ? htmlspecialchars($edit_data['keterangan']) : ''; ?>">
          </div>
          <div class="col-md-2">
            <label for="jumlah" class="form-label">Jumlah (Rp)</label>
            <input type="number" name="jumlah" id="jumlah" class="form-control" required min="0" value="<?php echo $edit_data ? htmlspecialchars($edit_data['jumlah']) : ''; ?>">
          </div>
          <div class="col-md-1 d-grid">
            <button type="submit" class="btn btn-success" data-perm="btn_pengeluaran_simpan"><?php echo $edit_data ? 'Update' : 'Tambah'; ?></button>
          </div>
          <?php if ($edit_data) { ?>
          <div class="col-md-1 d-grid">
            <a href="pengeluaran.php" class="btn btn-secondary">Batal</a>
          </div>
          <?php } ?>
        </div>
      </form>
      <!-- Form tambah kategori pengeluaran (collapse) -->
      <div class="collapse mt-3" id="formTambahKategori">
        <form method="post" class="row g-3 align-items-end justify-content-center">
          <div class="col-md-8 col-12">
            <label for="kategori_baru" class="form-label">Nama Kategori Baru</label>
            <input type="text" name="kategori_baru" id="kategori_baru" class="form-control" required>
          </div>
          <div class="col-md-4 col-12 d-grid">
            <button type="submit" name="tambah_kategori" value="1" class="btn btn-primary">Tambah Kategori</button>
          </div>
        </form>
        <div class="text-muted small mt-3 text-center">Kategori hanya akan ditambahkan jika belum ada untuk user ini.</div>
      </div>
    </div>
  </div>
      <!-- Card Daftar Pengeluaran -->
      <div class="card shadow mb-4">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
          <h2 class="mb-0">Daftar Pengeluaran</h2>
          <button class="btn btn-light btn-sm" onclick="location.reload()"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
        </div>
        <div class="card-body">
      <!-- Filter Bulan & Tahun -->
      <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-auto">
          <label for="bulan" class="form-label mb-0">Bulan</label>
          <select name="bulan" id="bulan" class="form-select">
            <option value="all" <?php if($filter_bulan==='all') echo 'selected'; ?>>Semua</option>
            <?php for ($b = 1; $b <= 12; $b++) { $selected = ($b == $filter_bulan) ? 'selected' : ''; printf('<option value="%02d" %s>%s</option>', $b, $selected, date('F', mktime(0,0,0,$b,1))); } ?>
          </select>
        </div>
        <div class="col-auto">
          <label for="tahun" class="form-label mb-0">Tahun</label>
          <select name="tahun" id="tahun" class="form-select">
            <option value="all" <?php if($filter_tahun==='all') echo 'selected'; ?>>Semua</option>
            <?php $tahun_sekarang = (int)date('Y'); for ($t = $tahun_sekarang-5; $t <= $tahun_sekarang+1; $t++) { $selected = ($t == $filter_tahun) ? 'selected' : ''; echo "<option value='$t' $selected>$t</option>"; } ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-danger">Tampilkan</button>
        </div>
      </form>
      <div class="table-responsive mb-4">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Kategori</th>
              <th>Keterangan</th>
              <th>Jumlah</th>
              <th>Pemilik</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if ($result && mysqli_num_rows($result) > 0) {
              while ($row = mysqli_fetch_assoc($result)) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['tanggal']) . '</td>';
                echo '<td>' . htmlspecialchars($row['kategori']) . '</td>';
                echo '<td>' . htmlspecialchars($row['keterangan']) . '</td>';
                echo '<td>Rp. ' . number_format($row['jumlah'] ?? 0, 0, ',', '.') . '</td>';
                echo '<td>' . htmlspecialchars($row['pemilik']) . '</td>';
                echo '<td>';
                echo '<a href="?edit=' . $row['id'] . '" class="btn btn-warning btn-sm me-1">Edit</a>';
                echo '<a href="?hapus=' . $row['id'] . '" class="btn btn-danger btn-sm" onclick="return confirm(\'Hapus data ini?\')">Hapus</a>';
                echo '</td>';
                echo '</tr>';
              }
            } else {
              echo '<tr><td colspan="5" class="text-center text-muted">Tidak ada data pengeluaran untuk periode ini.</td></tr>';
            }
            ?>
          </tbody>
        </table>
      </div>
        </div>
      </div>
      <!-- Card Neraca OPEX, CAPEX, dan Summary Finance -->
      <div class="card border-info shadow-sm mb-4">
        <div class="card-header bg-info text-white text-center">
          <h6 class="mb-0"><i class="fas fa-balance-scale me-2"></i>Neraca OPEX, CAPEX, dan Summary Finance</h6>
        </div>
        <div class="card-body">
      <?php
        // OPEX dan CAPEX dari pengeluaran (kategori)
        $where_pengeluaran_bulan = [];
        if ($filter_bulan !== 'all') {
          $where_pengeluaran_bulan[] = "MONTH(tanggal) = '" . intval($filter_bulan) . "'";
        }
        if ($filter_tahun !== 'all') {
          $where_pengeluaran_bulan[] = "YEAR(tanggal) = '" . intval($filter_tahun) . "'";
        }
        $where_pengeluaran_bulan[] = "pemilik IN ($server_list)";
        $where_sql_pengeluaran_bulan = $where_pengeluaran_bulan ? ('WHERE ' . implode(' AND ', $where_pengeluaran_bulan)) : '';
        $sql_opex = "SELECT SUM(jumlah) as total FROM pengeluaran $where_sql_pengeluaran_bulan AND kategori = 'OPEX'";
        $sql_capex = "SELECT SUM(jumlah) as total FROM pengeluaran $where_sql_pengeluaran_bulan AND kategori = 'CAPEX'";
        $result_opex = mysqli_query($conn, $sql_opex);
        $result_capex = mysqli_query($conn, $sql_capex);
        $total_opex = ($result_opex && ($row = mysqli_fetch_assoc($result_opex))) ? $row['total'] : 0;
        $total_capex = ($result_capex && ($row = mysqli_fetch_assoc($result_capex))) ? $row['total'] : 0;
        // Pemasukan (omzet) dan total pengeluaran
        $sql_pemasukan = "SELECT SUM(HARGA) as total FROM transaksi WHERE STATUS = 'BERHASIL' AND " .
          ($filter_bulan !== 'all' ? "MONTH(waktu) = '" . intval($filter_bulan) . "' AND " : "") .
          ($filter_tahun !== 'all' ? "YEAR(waktu) = '" . intval($filter_tahun) . "' AND " : "") .
          "PEMILIK IN ($server_list)";
        $result_pemasukan = mysqli_query($conn, $sql_pemasukan);
        $total_pemasukan = ($result_pemasukan && ($row = mysqli_fetch_assoc($result_pemasukan))) ? $row['total'] : 0;
        $sql_pengeluaran = "SELECT SUM(jumlah) as total FROM pengeluaran $where_sql_pengeluaran_bulan";
        $result_pengeluaran = mysqli_query($conn, $sql_pengeluaran);
        $total_pengeluaran = ($result_pengeluaran && ($row = mysqli_fetch_assoc($result_pengeluaran))) ? $row['total'] : 0;
        $laba_bersih = $total_pemasukan - $total_pengeluaran;
      ?>
          <div class="row justify-content-center mb-2">
            <div class="col-md-4 col-12 mb-3">
              <div class="card h-100 border-secondary">
                <div class="card-header bg-secondary text-white py-2 text-center"><b>OPEX (Operational Expenditure)</b></div>
                <div class="card-body text-center">
                  <div class="fs-6">Total OPEX</div>
                  <div class="fs-4 fw-bold text-danger">Rp. <?php echo number_format($total_opex, 0, ',', '.'); ?></div>
                </div>
              </div>
            </div>
            <div class="col-md-4 col-12 mb-3">
              <div class="card h-100 border-primary">
                <div class="card-header bg-primary text-white py-2 text-center"><b>CAPEX (Capital Expenditure)</b></div>
                <div class="card-body text-center">
                  <div class="fs-6">Total CAPEX</div>
                  <div class="fs-4 fw-bold text-info">Rp. <?php echo number_format($total_capex, 0, ',', '.'); ?></div>
                </div>
              </div>
            </div>
            <div class="col-md-4 col-12 mb-3">
              <div class="card h-100 border-success">
                <div class="card-header bg-success text-white py-2 text-center"><b>Summary Finance</b></div>
                <div class="card-body text-center">
                  <table class="table table-bordered mb-0">
                    <tr><th class="text-start">Total Pemasukan</th><td class="text-end">Rp. <?php echo number_format($total_pemasukan, 0, ',', '.'); ?></td></tr>
                    <tr><th class="text-start">Total Pengeluaran</th><td class="text-end">Rp. <?php echo number_format($total_pengeluaran, 0, ',', '.'); ?></td></tr>
                    <tr><th class="text-start">Laba Bersih</th><td class="text-end fw-bold text-success">Rp. <?php echo number_format($laba_bersih, 0, ',', '.'); ?></td></tr>
                  </table>
                </div>
              </div>
            </div>
          </div>
      <div class="text-muted small mt-2 text-center">
        *OPEX dan CAPEX diambil dari kategori pengeluaran. Pastikan kategori sudah sesuai.<br>
        *Summary finance: pemasukan - pengeluaran (semua kategori).<br>
      </div>
   
        </div>
      </div>
      <!-- Card Statistik Total Pengeluaran per Kategori -->
      <div class="card shadow mb-4">
        <div class="card-header bg-secondary text-white text-center">
          <b>Statistik Total Pengeluaran per Kategori</b>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered mb-0">
              <thead>
                <tr>
                  <th>Kategori</th>
                  <th>Total Pengeluaran</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $grand_total = 0;
                if ($result_stat && mysqli_num_rows($result_stat) > 0) {
                  while ($row = mysqli_fetch_assoc($result_stat)) {
                    $grand_total += $row['total'];
                    $url = '?bulan=' . urlencode($filter_bulan) . '&tahun=' . urlencode($filter_tahun) . '&kategori=' . urlencode($row['kategori']);
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($row['kategori']) . '</td>';
                    echo '<td>Rp. ' . number_format($row['total'] ?? 0, 0, ',', '.') . '</td>';
                    echo '<td><a href="' . $url . '" class="btn btn-primary btn-sm">Lihat</a></td>';
                    echo '</tr>';
                  }
                  echo '<tr class="fw-bold bg-light">';
                  echo '<td>Total Semua Kategori</td>';
                  echo '<td colspan="2">Rp. ' . number_format($grand_total, 0, ',', '.') . '</td>';
                  echo '</tr>';
                } else {
                  echo '<tr><td colspan="3" class="text-center text-muted">Tidak ada data statistik pengeluaran.</td></tr>';
                }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
   

    </div>
  </div>
</div>
<?php require 'footer.php'; ?>
