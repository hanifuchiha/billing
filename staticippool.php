<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Pool_StaticIP', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu IP Pool Static.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/staticip_helper.php';
staticipEnsureSchema($conn);

$status = $_GET['statusnotif'] ?? '';
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Range IP Pool Static berhasil dibuat.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'deleted'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Range IP Pool Static berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">

      <div class="card mb-4">
        <div class="card-header bg-primary text-white">
          Buat Range IP Pool Static Baru
        </div>
        <div class="card-body">
          <p class="text-muted small">
            Range IP yang didaftarkan di sini dipakai sebagai sumber pilihan "IP Static" saat
            menambah Customer Static IP (menu Customer Static IP) -- IP yang sudah dipakai
            pelanggan otomatis tidak ditawarkan lagi.
          </p>
          <form action="proses/addstaticippool.php" method="POST" class="row g-3">
            <div class="col-md-6">
              <label for="server" class="form-label">Server Area</label>
              <select required class="form-select" id="server" name="server">
                <option value="">-- Pilih Server Area --</option>
                <?php
                if ($current_user_id) {
                    if ($AKSES == 'ASSISTANT') {
                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE AREA IN ($area_list)");
                    } else {
                        $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA FROM server WHERE user_id = $current_user_id");
                    }
                    while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                        $areaEsc = htmlspecialchars($rowServer['AREA']);
                        echo '<option value="' . htmlspecialchars($rowServer['PEMILIK']) . '" data-area="' . $areaEsc . '">' . htmlspecialchars($rowServer['BRAND']) . '-' . $areaEsc . '</option>';
                    }
                }
                ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">AREA (otomatis dari Server Area)</label>
              <input type="text" class="form-control" id="area_display" readonly>
              <input type="hidden" id="area" name="area">
            </div>
            <div class="col-md-4">
              <label for="ip_awal" class="form-label">IP Awal</label>
              <input required type="text" class="form-control" id="ip_awal" name="ip_awal" placeholder="192.168.10.2">
            </div>
            <div class="col-md-4">
              <label for="ip_akhir" class="form-label">IP Akhir</label>
              <input required type="text" class="form-control" id="ip_akhir" name="ip_akhir" placeholder="192.168.10.254">
            </div>
            <div class="col-md-4">
              <label for="gateway" class="form-label">Gateway</label>
              <input type="text" class="form-control" id="gateway" name="gateway" placeholder="192.168.10.1">
            </div>
            <div class="col-md-6">
              <label for="subnet" class="form-label">Subnet Mask</label>
              <input type="text" class="form-control" id="subnet" name="subnet" placeholder="255.255.255.0">
            </div>
            <div class="col-md-6">
              <label for="keterangan" class="form-label">Keterangan</label>
              <input type="text" class="form-control" id="keterangan" name="keterangan" placeholder="Opsional">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary">Simpan Range IP Pool</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-success text-white">Daftar IP Pool Static</div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>Server</th>
                  <th>Area</th>
                  <th>Range IP</th>
                  <th>Gateway</th>
                  <th>Subnet</th>
                  <th>Terpakai / Tersedia</th>
                  <th>Keterangan</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $areaFilter = staticipAreaFilterSql('AREA', $AKSES, $area_list ?? '');
                $ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
                $sqlPool = "SELECT * FROM pool_staticip WHERE PEMILIK = '$ceknamaEsc'" . $areaFilter . " ORDER BY id DESC";
                $qPool = mysqli_query($conn, $sqlPool);
                if ($qPool && mysqli_num_rows($qPool) > 0) {
                    $no = 1;
                    while ($rowPool = mysqli_fetch_assoc($qPool)) {
                        $startL = staticipIpToLong((string) $rowPool['ip_awal']);
                        $endL = staticipIpToLong((string) $rowPool['ip_akhir']);
                        $total = ($startL !== false && $endL !== false && $endL >= $startL) ? ($endL - $startL + 1) : 0;
                        $availCount = count(staticipListAvailableIps($conn, $rowPool, 100000));
                        $terpakai = $total - $availCount;
                        echo "<tr>";
                        echo "<td>" . $no++ . "</td>";
                        echo "<td>" . htmlspecialchars($rowPool['PEMILIK']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPool['AREA']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPool['ip_awal']) . " - " . htmlspecialchars($rowPool['ip_akhir']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPool['gateway']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPool['subnet']) . "</td>";
                        echo "<td>$terpakai / $total (tersedia $availCount)</td>";
                        echo "<td>" . htmlspecialchars($rowPool['keterangan']) . "</td>";
                        echo "<td><a href='proses/deletestaticippool.php?id=" . (int) $rowPool['id'] . "' class='btn btn-danger btn-sm' onclick='return confirm(\"Yakin ingin menghapus range ini?\")'>Hapus</a></td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='9' class='text-center'>Belum ada IP Pool Static</td></tr>";
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

<script>
document.getElementById('server').addEventListener('change', function () {
    var selected = this.options[this.selectedIndex];
    var area = selected ? selected.getAttribute('data-area') : '';
    document.getElementById('area').value = area || '';
    document.getElementById('area_display').value = area || '';
});
</script>

<?php require 'footer.php'; ?>
