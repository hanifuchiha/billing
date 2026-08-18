<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Packages_StaticIP', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Paket Static IP.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/staticip_helper.php';
staticipEnsureSchema($conn);
require_once __DIR__ . '/radius_sync_lib.php';
radiusEnsurePaketProfileSourceColumn($conn);

$status = $_GET['statusnotif'] ?? '';
$edit = $_GET['edit'] ?? '';
$deleted = $_GET['deleted'] ?? '';
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Paket Static IP berhasil dibuat.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($edit === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Paket Static IP berhasil diedit.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($edit === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Paket gagal diedit.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($deleted === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Paket Static IP berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($deleted === '2'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> Paket masih digunakan oleh pelanggan, tidak dapat dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($deleted === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> Paket gagal dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <h6>Paket Static IP</h6>
          <p class="text-muted small mt-2">
            Paket ini tetap PPPoE biasa (rate-limit dari PPP Profile Mikrotik seperti paket
            broadband biasa) -- yang beda cuma pelanggannya nanti dapat 1 IP TETAP dari kolom
            IP Static (lihat menu <a href="staticippool.php">IP Pool Static</a>), bukan dari
            PPP Pool dinamis. Local/Remote IP di bawah OPSIONAL: boleh dikosongkan kalau Anda
            mau atur profile Mikrotik-nya manual sendiri di router.
          </p>
          <div class="btn-group-custom mb-3">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addPaketStaticModal">
              <i class="fas fa-plus me-1"></i> Tambah Paket Static IP
            </button>
          </div>
        </div>

        <!-- Modal Tambah -->
        <div class="modal fade" id="addPaketStaticModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title">Tambah Paket Static IP</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <form method="post" action="proses/addpackagestaticip.php">
                  <div class="mb-3">
                    <label class="form-label">Server Area</label>
                    <select required class="form-select" name="server" id="addServerStatic">
                      <option value="">-- Pilih Server Area --</option>
                      <?php
                      if ($current_user_id) {
                          if ($AKSES == 'ASSISTANT') {
                              $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE AREA IN ($area_list)");
                          } else {
                              $queryServer = mysqli_query($conn, "SELECT DISTINCT PEMILIK, BRAND, AREA, CONNECTION_MODE FROM server WHERE user_id = $current_user_id");
                          }
                          while ($rowServer = mysqli_fetch_assoc($queryServer)) {
                              $areaEsc = htmlspecialchars($rowServer['AREA']);
                              $brandEsc = htmlspecialchars($rowServer['BRAND']);
                              $connmode = htmlspecialchars($rowServer['CONNECTION_MODE'] ?? 'API');
                              echo '<option value="' . htmlspecialchars($rowServer['PEMILIK']) . '" data-area="' . $areaEsc . '" data-brand="' . $brandEsc . '" data-connmode="' . $connmode . '">' . $brandEsc . '-' . $areaEsc . '</option>';
                          }
                      }
                      ?>
                    </select>
                    <input type="hidden" name="area" id="addAreaStatic">
                    <input type="hidden" name="brand" id="addBrandStatic">
                  </div>
                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Nama Paket</label>
                      <input required type="text" class="form-control" name="profileName" placeholder="Misal: Static 20M">
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Kecepatan</label>
                      <input required type="text" class="form-control" name="ratelimit" placeholder="Misal: 20M/20M">
                    </div>
                  </div>
                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Harga dasar</label>
                      <input required type="text" inputmode="numeric" class="form-control" name="harga" placeholder="Rp. 0" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Komisi sales (%)</label>
                      <input type="number" class="form-control" name="komisi" value="0">
                    </div>
                  </div>
                  <div class="alert alert-secondary small">
                    Local/Remote IP Pool (opsional) -- kalau diisi, PPP Profile + IP Pool otomatis
                    dibuat di Mikrotik (kosongkan kalau server RADIUS SAJA atau ingin atur manual).
                  </div>
                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Local IP</label>
                      <input type="text" class="form-control" name="local" placeholder="Opsional">
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Remote IP Pool Range</label>
                      <input type="text" class="form-control" name="remot" placeholder="Opsional, misal 192.168.10.2-192.168.10.254">
                    </div>
                  </div>
                  <div class="modal-footer px-0 pb-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>

        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>Nama Paket</th>
                  <th>Kecepatan</th>
                  <th>Harga</th>
                  <th>Server</th>
                  <th>Area</th>
                  <th>Local/Remote</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $areaFilter = staticipAreaFilterSql('AREA', $AKSES, $area_list ?? '');
                $ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);
                $sqlPaket = "SELECT * FROM paket WHERE PEMILIK = '$ceknamaEsc' AND TIPE_LAYANAN = 'PPPOE_STATIC'" . $areaFilter . " ORDER BY id DESC";
                $qPaket = mysqli_query($conn, $sqlPaket);
                if ($qPaket && mysqli_num_rows($qPaket) > 0) {
                    $no = 1;
                    while ($rowPaket = mysqli_fetch_assoc($qPaket)) {
                        echo "<tr>";
                        echo "<td>" . $no++ . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['PAKET']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['KECEPATAN']) . "</td>";
                        echo "<td>" . number_format((float) $rowPaket['HARGA'], 0, ',', '.') . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['PEMILIK']) . "</td>";
                        echo "<td>" . htmlspecialchars($rowPaket['AREA']) . "</td>";
                        $localRemote = trim((string) ($rowPaket['LOCAL'] ?? '')) !== '' ? htmlspecialchars($rowPaket['LOCAL']) . ' / ' . htmlspecialchars($rowPaket['REMOTE']) : '<span class="text-muted">-</span>';
                        echo "<td>" . $localRemote . "</td>";
                        echo "<td>";
                        echo "<button type='button' class='btn btn-warning btn-sm' data-bs-toggle='modal' data-bs-target='#editPaketStaticModal'"
                            . " data-id='" . (int) $rowPaket['id'] . "'"
                            . " data-paket='" . htmlspecialchars($rowPaket['PAKET'], ENT_QUOTES) . "'"
                            . " data-kecepatan='" . htmlspecialchars($rowPaket['KECEPATAN'], ENT_QUOTES) . "'"
                            . " data-harga='" . (int) $rowPaket['HARGA'] . "'"
                            . " data-komisi='" . htmlspecialchars((string) ($rowPaket['komisi'] ?? '0'), ENT_QUOTES) . "'"
                            . " data-local='" . htmlspecialchars((string) ($rowPaket['LOCAL'] ?? ''), ENT_QUOTES) . "'"
                            . " data-remote='" . htmlspecialchars((string) ($rowPaket['REMOTE'] ?? ''), ENT_QUOTES) . "'"
                            . ">Edit</button> ";
                        echo "<form method='post' action='proses/deletepackagestaticip.php' style='display:inline' onsubmit='return confirm(\"Yakin ingin menghapus paket ini?\")'>"
                            . "<input type='hidden' name='id' value='" . (int) $rowPaket['id'] . "'>"
                            . "<input type='hidden' name='paket' value='" . htmlspecialchars($rowPaket['PAKET'], ENT_QUOTES) . "'>"
                            . "<input type='hidden' name='area' value='" . htmlspecialchars($rowPaket['AREA'], ENT_QUOTES) . "'>"
                            . "<input type='hidden' name='pemilik' value='" . htmlspecialchars($rowPaket['PEMILIK'], ENT_QUOTES) . "'>"
                            . "<button type='submit' class='btn btn-danger btn-sm'>Hapus</button></form>";
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='8' class='text-center'>Belum ada Paket Static IP</td></tr>";
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
<div class="modal fade" id="editPaketStaticModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Paket Static IP</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/editpackagestaticip.php">
          <input type="hidden" name="id" id="editIdStatic">
          <div class="mb-3">
            <label class="form-label">Nama Paket</label>
            <input required type="text" class="form-control" name="profileName" id="editPaketStatic">
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Kecepatan</label>
              <input required type="text" class="form-control" name="ratelimit" id="editKecepatanStatic">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Harga dasar</label>
              <input required type="text" inputmode="numeric" class="form-control" name="harga" id="editHargaStatic" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Komisi sales (%)</label>
            <input type="number" class="form-control" name="komisi" id="editKomisiStatic">
          </div>
          <div class="alert alert-secondary small">
            Local/Remote IP Pool (opsional) -- kosongkan kalau tidak ingin membuat/mengubah
            PPP Profile di Mikrotik.
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Local IP</label>
              <input type="text" class="form-control" name="local" id="editLocalStatic">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Remote IP Pool Range</label>
              <input type="text" class="form-control" name="remot" id="editRemoteStatic">
            </div>
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
document.getElementById('addServerStatic').addEventListener('change', function () {
    var selected = this.options[this.selectedIndex];
    document.getElementById('addAreaStatic').value = selected ? (selected.getAttribute('data-area') || '') : '';
    document.getElementById('addBrandStatic').value = selected ? (selected.getAttribute('data-brand') || '') : '';
});

document.getElementById('editPaketStaticModal').addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('editIdStatic').value = btn.getAttribute('data-id') || '';
    document.getElementById('editPaketStatic').value = btn.getAttribute('data-paket') || '';
    document.getElementById('editKecepatanStatic').value = btn.getAttribute('data-kecepatan') || '';
    document.getElementById('editHargaStatic').value = btn.getAttribute('data-harga') || '';
    document.getElementById('editKomisiStatic').value = btn.getAttribute('data-komisi') || '0';
    document.getElementById('editLocalStatic').value = btn.getAttribute('data-local') || '';
    document.getElementById('editRemoteStatic').value = btn.getAttribute('data-remote') || '';
});
</script>

<?php require 'footer.php'; ?>
