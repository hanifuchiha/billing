<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Corporate_Paket', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Paket Corporate.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/corporate_helper.php';
corporateEnsureSchema($conn);

$status = $_GET['statusnotif'] ?? '';
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
$areaFilterCorp = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Paket Corporate berhasil disimpan.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'edited'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Paket Corporate berhasil diperbarui.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'deleted'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Paket Corporate berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">

      <div class="card mb-4">
        <div class="card-header bg-primary text-white">
          Buat Paket Corporate Baru
        </div>
        <div class="card-body">
          <p class="text-muted small">
            Katalog paket KHUSUS layanan Customer Corporate (Internet Dedicated/MPLS/dst) --
            SENGAJA terpisah dari Paket Customer PPPoE/Static IP biasa, supaya tidak
            tercampur saat memilih paket di menu Customer Corporate &gt; Layanan.
          </p>
          <form action="proses/addpaketcorporate.php" method="POST" class="row g-3">
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
            <div class="col-md-6">
              <label for="paket" class="form-label">Nama Paket</label>
              <input required type="text" class="form-control" id="paket" name="paket" placeholder="Mis. Corporate Dedicated 50Mbps">
            </div>
            <div class="col-md-3">
              <label for="kecepatan" class="form-label">Kecepatan</label>
              <input type="text" class="form-control" id="kecepatan" name="kecepatan" placeholder="Mis. 50M/50M">
            </div>
            <div class="col-md-3">
              <label for="harga" class="form-label">Harga (referensi)</label>
              <input type="number" min="0" step="1" class="form-control" id="harga" name="harga" placeholder="0">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary">Simpan Paket Corporate</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header bg-success text-white">Daftar Paket Corporate</div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>Server</th>
                  <th>Area</th>
                  <th>Nama Paket</th>
                  <th>Kecepatan</th>
                  <th>Harga</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $sqlPaket = "SELECT * FROM paket_corporate WHERE PEMILIK = '$ceknamaEsc'" . $areaFilterCorp . " ORDER BY id DESC";
                $qPaket = mysqli_query($conn, $sqlPaket);
                if ($qPaket && mysqli_num_rows($qPaket) > 0) {
                    $no = 1;
                    while ($rowPaket = mysqli_fetch_assoc($qPaket)) {
                        echo "<tr>";
                        echo "<td>" . $no++ . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['PEMILIK']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['AREA']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['PAKET']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['KECEPATAN']) . "</td>";
                        echo "<td>Rp" . number_format((float) $rowPaket['HARGA'], 0, ',', '.') . "</td>";
                        echo "<td class='text-nowrap'>";
                        echo "<button type='button' class='btn btn-warning btn-sm mb-1' data-bs-toggle='modal' data-bs-target='#editPaketCorporateModal'"
                            . " data-id='" . (int) $rowPaket['id'] . "'"
                            . " data-paket='" . htmlspecialchars($rowPaket['PAKET'], ENT_QUOTES) . "'"
                            . " data-kecepatan='" . htmlspecialchars($rowPaket['KECEPATAN'], ENT_QUOTES) . "'"
                            . " data-harga='" . (float) $rowPaket['HARGA'] . "'"
                            . ">Edit</button><br>";
                        echo "<a href='proses/deletepaketcorporate.php?id=" . (int) $rowPaket['id'] . "' class='btn btn-danger btn-sm' onclick='return confirm(\"Yakin ingin menghapus paket ini?\")'>Hapus</a>";
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-center'>Belum ada Paket Corporate</td></tr>";
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

<!-- Modal Edit -->
<div class="modal fade" id="editPaketCorporateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Paket Corporate</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/editpaketcorporate.php">
          <input type="hidden" name="id" id="editPaketId">
          <div class="mb-3">
            <label class="form-label">Nama Paket</label>
            <input required type="text" class="form-control" name="paket" id="editPaketNama">
          </div>
          <div class="mb-3">
            <label class="form-label">Kecepatan</label>
            <input type="text" class="form-control" name="kecepatan" id="editPaketKecepatan">
          </div>
          <div class="mb-3">
            <label class="form-label">Harga (referensi)</label>
            <input type="number" min="0" step="1" class="form-control" name="harga" id="editPaketHarga">
          </div>
          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
          </div>
        </form>
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

var editPaketCorporateModalEl = document.getElementById('editPaketCorporateModal');
editPaketCorporateModalEl.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('editPaketId').value = btn.getAttribute('data-id') || '';
    document.getElementById('editPaketNama').value = btn.getAttribute('data-paket') || '';
    document.getElementById('editPaketKecepatan').value = btn.getAttribute('data-kecepatan') || '';
    document.getElementById('editPaketHarga').value = btn.getAttribute('data-harga') || '';
});
</script>

<?php require 'footer.php'; ?>
