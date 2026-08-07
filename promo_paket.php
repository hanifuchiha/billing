
<?php
require 'header.php';

// Pastikan kolom promo_durasi_type ada di tabel promo_paket
$cek_kolom = mysqli_query($conn, "SHOW COLUMNS FROM promo_paket LIKE 'promo_durasi_type'");
if (!$cek_kolom || mysqli_num_rows($cek_kolom) == 0) {
  // Tambahkan kolom enum jika belum ada
  mysqli_query($conn, "ALTER TABLE promo_paket ADD COLUMN promo_durasi_type ENUM('bulan','hari') NOT NULL DEFAULT 'bulan' AFTER promo_durasi");
}


$server_filter = [];
if ($user_id) {
  $sql_server = "SELECT PEMILIK, AREA, BRAND FROM server WHERE user_id = '" . mysqli_real_escape_string($conn, $user_id) . "'";
  $result_server = mysqli_query($conn, $sql_server);
  while ($row = mysqli_fetch_assoc($result_server)) {
    $server_filter[] = [
      'PEMILIK' => $row['PEMILIK'],
      'AREA' => $row['AREA'],
      'BRAND' => $row['BRAND']
    ];
  }
}

// Build filter for paket
$where_paket = [];
foreach ($server_filter as $sf) {
  $pemilik = mysqli_real_escape_string($conn, $sf['PEMILIK']);
  $area = mysqli_real_escape_string($conn, $sf['AREA']);
  $brand = mysqli_real_escape_string($conn, $sf['BRAND']);
  $where_paket[] = "(PEMILIK = '$pemilik' AND AREA = '$area' AND BRAND = '$brand')";
}
$sql = "SELECT * FROM paket";
if (count($where_paket)) {
  $sql .= " WHERE " . implode(' OR ', $where_paket);
}
$query = mysqli_query($conn, $sql);
// Untuk select pengganti, ambil ulang data paket
$query_pengganti = mysqli_query($conn, $sql);

// Proses form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $paket_id = $_POST['paket_id'] ?? '';
  $promo_durasi = $_POST['promo_durasi'] ?? '';
  $promo_durasi_type = $_POST['promo_durasi_type'] ?? '';
  $promo_mulai_type = $_POST['promo_mulai_type'] ?? '';
  $paket_pengganti = $_POST['paket_pengganti'] ?? '';
  $pemilik = '';
  $area = '';
  // Ambil data pemilik dan area dari paket yang dipilih
  if ($paket_id) {
    $sql_paket = "SELECT PEMILIK, AREA FROM paket WHERE id=? LIMIT 1";
    $stmt_paket = mysqli_prepare($conn, $sql_paket);
    mysqli_stmt_bind_param($stmt_paket, 'i', $paket_id);
    mysqli_stmt_execute($stmt_paket);
    mysqli_stmt_bind_result($stmt_paket, $pemilik, $area);
    mysqli_stmt_fetch($stmt_paket);
    mysqli_stmt_close($stmt_paket);
  }
  // Validasi sederhana
  if ($paket_id && $promo_durasi && $promo_durasi_type && $promo_mulai_type && $paket_pengganti && $pemilik && $area) {
    // Simpan ke tabel promo_paket (buat tabel jika belum ada)
    $sql_insert = "REPLACE INTO promo_paket (paket_id, pemilik, area, promo_durasi, promo_durasi_type, promo_mulai_type, paket_pengganti) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = mysqli_prepare($conn, $sql_insert);
    mysqli_stmt_bind_param($stmt, 'ississs', $paket_id, $ceknama, $area, $promo_durasi, $promo_durasi_type, $promo_mulai_type, $paket_pengganti);
    if (mysqli_stmt_execute($stmt)) {
      $notif = 'success';
    } else {
      $notif = 'failed';
    }
  } else {
    $notif = 'failed';
  }
}

// Handle delete
if (isset($_GET['hapus'])) {
    $id = intval($_GET['hapus']);
    $sql_delete = "DELETE FROM promo_paket WHERE id = ? AND pemilik = ?";
    $stmt = mysqli_prepare($conn, $sql_delete);
    mysqli_stmt_bind_param($stmt, 'is', $id, $ceknama);
    if (mysqli_stmt_execute($stmt)) {
        $notif = 'deleted';
    } else {
        $notif = 'delete_failed';
    }
    mysqli_stmt_close($stmt);
    // Redirect to avoid resubmit
    header("Location: promo_paket.php");
    
}
?>
<div class="container py-4">
  <div class="card shadow">
    <div class="card-header bg-primary text-white">
      <h4>Setting Promo Paket</h4>
    </div>
    <div class="card-body">
      <div class="alert alert-info">
        <b>Petunjuk:</b><br>
        Form ini digunakan untuk mengatur promo paket internet.<br>
        <ul class="mb-1">
          <li><b>Pilih Paket</b>: Pilih paket yang ingin diberikan promo.</li>
          <li><b>Durasi Promo</b>: Lama promo berlaku (dalam bulan).</li>
          <li><b>Mulai Promo</b>: Tentukan apakah promo dimulai dari tanggal pemasangan atau transaksi terakhir pelanggan.</li>
          <li><b>Paket Pengganti Setelah Promo</b>: Setelah promo berakhir, pelanggan otomatis akan pindah ke paket ini.</li>
        </ul>
        Data pemilik dan area paket akan otomatis terisi sesuai paket yang dipilih.
      </div>
      <?php if (!empty($notif) && $notif == 'success'): ?>
        <div class="alert alert-success">Promo berhasil disimpan!</div>
      <?php elseif (!empty($notif) && $notif == 'failed'): ?>
        <div class="alert alert-danger">Gagal menyimpan promo. Pastikan data sudah benar.</div>
      <?php elseif (!empty($notif) && $notif == 'deleted'): ?>
        <div class="alert alert-success">Promo berhasil dihapus!</div>
      <?php elseif (!empty($notif) && $notif == 'delete_failed'): ?>
        <div class="alert alert-danger">Gagal menghapus promo.</div>
      <?php endif; ?>
      <form method="post">
        <div class="mb-3">
          <label for="paket_id" class="form-label">Pilih Paket</label>
          <select name="paket_id" id="paket_id" class="form-control" required onchange="filterPaketPengganti()">
            <option value="">-- Pilih Paket --</option>
            <?php 
            $paket_data = [];
            while ($row = mysqli_fetch_assoc($query)) {
              $paket_data[] = [
                'id' => $row['id'],
                'area' => $row['AREA'],
                'nama' => $row['PAKET']
              ];
              echo '<option value="' . $row['id'] . '" data-area="' . htmlspecialchars($row['AREA']) . '">' . htmlspecialchars($row['PAKET']) . ' (' . htmlspecialchars($row['AREA']) . ')' . '</option>';
            }
            ?>
          </select>
        </div>
        <!-- Harga promo tidak perlu diinput manual -->
        <div class="mb-3">
          <label for="promo_durasi" class="form-label">Durasi Promo</label>
          <div class="input-group">
            <input type="number" name="promo_durasi" id="promo_durasi" class="form-control" required min="1">
            <select name="promo_durasi_type" id="promo_durasi_type" class="form-select" required>
              <option value="bulan">Bulan</option>
              <option value="hari">Hari</option>
            </select>
          </div>
        </div>
        <div class="mb-3">
          <label for="promo_mulai_type" class="form-label">Mulai Promo</label>
          <select name="promo_mulai_type" id="promo_mulai_type" class="form-control" required>
            <option value="">-- Pilih Mulai Promo --</option>
            <option value="tanggal_pasang">Tanggal Pasang</option>
            <option value="transaksi_akhir">Transaksi Akhir</option>
          </select>
        </div>
        <?php mysqli_data_seek($query_pengganti, 0); ?>
        <div class="mb-3">
          <label for="paket_pengganti" class="form-label">Paket Pengganti Setelah Promo</label>
          <select name="paket_pengganti" id="paket_pengganti" class="form-control" required>
            <option value="">-- Pilih Paket Pengganti --</option>
            <?php 
            $paket_pengganti_data = [];
            while ($rowp = mysqli_fetch_assoc($query_pengganti)) {
              $paket_pengganti_data[] = [
                'id' => $rowp['id'],
                'area' => $rowp['AREA'],
                'nama' => $rowp['PAKET']
              ];
              echo '<option value="' . $rowp['id'] . '" data-area="' . htmlspecialchars($rowp['AREA']) . '">' . htmlspecialchars($rowp['PAKET']) . ' (' . htmlspecialchars($rowp['AREA']) . ')' . '</option>';
            }
            ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Simpan Promo</button>
      </form>
      <hr>
      <h5 class="mt-4">Daftar Promo Paket Aktif</h5>
      <div class="table-responsive">
        <table class="table table-bordered table-striped" style="font-size:12px;">
          <thead class="table-light">
            <tr>
              <th>Paket Promo</th>
              <th>Area</th>
              <th>Pemilik</th>
              <th>Durasi</th>
              <th>Mulai Promo</th>
              <th>Paket Pengganti</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $sql_list = "SELECT pp.*, p1.PAKET as nama_promo, p2.PAKET as nama_pengganti FROM promo_paket pp
              LEFT JOIN paket p1 ON pp.paket_id = p1.id
              LEFT JOIN paket p2 ON pp.paket_pengganti = p2.id
              WHERE pp.pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "' ORDER BY pp.id DESC";
            $q_list = mysqli_query($conn, $sql_list);
            if (mysqli_num_rows($q_list) > 0) {
              while ($row = mysqli_fetch_assoc($q_list)) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['nama_promo']) . '</td>';
                echo '<td>' . htmlspecialchars($row['area']) . '</td>';
                echo '<td>' . htmlspecialchars($row['pemilik']) . '</td>';
                $durasi_label = ($row['promo_durasi_type'] == 'hari') ? 'hari' : 'bulan';
                echo '<td>' . (int)$row['promo_durasi'] . ' ' . $durasi_label . '</td>';
                echo '<td>' . ($row['promo_mulai_type'] == 'tanggal_pasang' ? 'Tanggal Pasang' : 'Transaksi Akhir') . '</td>';
                echo '<td>' . htmlspecialchars($row['nama_pengganti']) . '</td>';
                echo '<td>';
                echo '<a href="?edit=' . $row['id'] . '" class="btn btn-sm btn-info me-1">Edit</a>';
                echo '<a href="?hapus=' . $row['id'] . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Yakin hapus promo ini?\')">Hapus</a>';
                echo '</td>';
                echo '</tr>';
              }
            } else {
              echo '<tr><td colspan="7" class="text-center text-muted">Belum ada promo paket yang di-setting.</td></tr>';
            }
            ?>
          </tbody>
        </table>
      </div>
      <script>
        // Data paket untuk JS
        var paketData = <?php echo json_encode($paket_data); ?>;
        var paketPenggantiData = <?php echo json_encode($paket_pengganti_data); ?>;
        function filterPaketPengganti() {
          var paketSelect = document.getElementById('paket_id');
          var penggantiSelect = document.getElementById('paket_pengganti');
          var selectedId = paketSelect.value;
          var selectedArea = '';
          for (var i = 0; i < paketData.length; i++) {
            if (paketData[i].id == selectedId) {
              selectedArea = paketData[i].area;
              break;
            }
          }
          // Simpan value yang sudah dipilih sebelumnya
          var prevValue = penggantiSelect.value;
          penggantiSelect.innerHTML = '<option value="">-- Pilih Paket Pengganti --</option>';
          for (var i = 0; i < paketPenggantiData.length; i++) {
            if (paketPenggantiData[i].area == selectedArea && paketPenggantiData[i].id != selectedId) {
              var opt = document.createElement('option');
              opt.value = paketPenggantiData[i].id;
              opt.text = paketPenggantiData[i].nama;
              opt.setAttribute('data-area', paketPenggantiData[i].area);
              penggantiSelect.appendChild(opt);
            }
          }
          // Kembalikan value jika masih valid
          if (prevValue) penggantiSelect.value = prevValue;
        }
      </script>
    </div>
  </div>
</div>
<?php require 'footer.php'; ?>
