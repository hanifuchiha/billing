<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Corporate_Customer', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Customer Corporate.</div></div>';
        require 'footer.php';
        exit;
    }
}

require_once __DIR__ . '/corporate_helper.php';
corporateEnsureSchema($conn);

$status = $_GET['statusnotif'] ?? '';
$edit = $_GET['edit'] ?? '';
$deleted = $_GET['deleted'] ?? '';
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Customer Corporate berhasil ditambahkan.
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
    <strong>Berhasil!</strong> Data Customer Corporate berhasil diperbarui.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($edit === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal memperbarui data.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>
<?php if ($deleted === '1'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil!</strong> Customer Corporate berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($deleted === '2'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> Masih ada invoice yang belum LUNAS, lunasi/hapus dulu invoice-nya sebelum menghapus perusahaan ini.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($deleted === '0'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal menghapus data.'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<?php
$ceknamaEsc = mysqli_real_escape_string($conn, $ceknama);

// Dropdown AREA -- murni label utk scoping ASSISTANT, tidak terikat ke satu
// server/PEMILIK tertentu (Corporate tidak punya provisioning Mikrotik).
$areaOptions = [];
if ($current_user_id) {
    if ($AKSES == 'ASSISTANT') {
        $qArea = mysqli_query($conn, "SELECT DISTINCT AREA FROM server WHERE AREA IN ($area_list)");
    } else {
        $qArea = mysqli_query($conn, "SELECT DISTINCT AREA FROM server WHERE user_id = $current_user_id");
    }
    if ($qArea) {
        while ($rowArea = mysqli_fetch_assoc($qArea)) {
            $a = trim((string) $rowArea['AREA']);
            if ($a !== '') {
                $areaOptions[] = $a;
            }
        }
    }
}
?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">
          <h6>Customer Corporate (B2B)</h6>
          <p class="text-muted small mt-2">
            Pencatatan perusahaan/instansi (kantor, sekolah, hotel, rumah sakit, dst) beserta
            PIC dan kontrak. Fitur ini TERPISAH dari Customer PPPoE/Static IP -- kalau
            perusahaan ini juga butuh koneksi internet fisik, daftarkan terpisah lewat menu
            Customer PPPoE/Static IP. Invoice/billing Corporate dikelola di menu
            <a href="transaksicorporate.php">Transaksi Corporate</a>.
          </p>
          <div class="btn-group-custom mb-3">
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCorporateModal">
              <i class="fas fa-plus me-1"></i> Tambah Customer Corporate
            </button>
          </div>
        </div>

        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover align-middle">
              <thead class="table-dark">
                <tr>
                  <th>No</th>
                  <th>Logo</th>
                  <th>Nama Perusahaan</th>
                  <th>Penanggung Jawab</th>
                  <th>Area</th>
                  <th>Telepon/WA</th>
                  <th>Jumlah PIC</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $areaFilter = corporateAreaFilterSql('AREA', $AKSES, $area_list ?? '');
                $sqlCorp = "SELECT * FROM corporate WHERE PEMILIK = '$ceknamaEsc'" . $areaFilter . " ORDER BY id DESC";
                $qCorp = mysqli_query($conn, $sqlCorp);
                if ($qCorp && mysqli_num_rows($qCorp) > 0) {
                    $no = 1;
                    while ($row = mysqli_fetch_assoc($qCorp)) {
                        $picCountRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM corporate_pic WHERE corporate_id = " . (int) $row['id']));
                        $picCount = $picCountRow ? (int) $picCountRow['c'] : 0;
                        $logoUrl = corporateDokumenUrl((string) ($row['LOGO'] ?? ''));

                        echo "<tr>";
                        echo "<td>" . $no++ . "</td>";
                        echo "<td>" . ($logoUrl !== '' ? "<img src='" . htmlspecialchars($logoUrl) . "' style='max-width:48px;max-height:48px;object-fit:contain'>" : "<span class='text-muted'>-</span>") . "</td>";
                        echo "<td>" . htmlspecialchars($row['NAMA_PERUSAHAAN']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['PJ_NAMA']) . ($row['PJ_JABATAN'] !== '' ? ' (' . htmlspecialchars($row['PJ_JABATAN']) . ')' : '') . "</td>";
                        echo "<td>" . htmlspecialchars($row['AREA'] ?: '-') . "</td>";
                        echo "<td>" . htmlspecialchars($row['TELEPON']) . "<br>" . htmlspecialchars($row['WHATSAPP']) . "</td>";
                        echo "<td>" . $picCount . " PIC</td>";
                        $statusBadge = ($row['STATUS'] === 'AKTIF') ? "<span class='badge bg-success'>AKTIF</span>" : "<span class='badge bg-secondary'>NONAKTIF</span>";
                        echo "<td>" . $statusBadge . "</td>";
                        echo "<td class='text-nowrap'>";
                        echo "<button type='button' class='btn btn-warning btn-sm mb-1' data-bs-toggle='modal' data-bs-target='#editCorporateModal' data-perm='btn_corp_edit'"
                            . " data-id='" . (int) $row['id'] . "'"
                            . " data-nama='" . htmlspecialchars($row['NAMA_PERUSAHAAN'], ENT_QUOTES) . "'"
                            . " data-pjnama='" . htmlspecialchars($row['PJ_NAMA'], ENT_QUOTES) . "'"
                            . " data-pjjabatan='" . htmlspecialchars($row['PJ_JABATAN'], ENT_QUOTES) . "'"
                            . " data-npwp='" . htmlspecialchars($row['NPWP'], ENT_QUOTES) . "'"
                            . " data-nib='" . htmlspecialchars($row['NIB'], ENT_QUOTES) . "'"
                            . " data-siup='" . htmlspecialchars($row['SIUP'], ENT_QUOTES) . "'"
                            . " data-alamat='" . htmlspecialchars($row['ALAMAT_KANTOR'], ENT_QUOTES) . "'"
                            . " data-emailfinance='" . htmlspecialchars($row['EMAIL_FINANCE'], ENT_QUOTES) . "'"
                            . " data-emailit='" . htmlspecialchars($row['EMAIL_IT'], ENT_QUOTES) . "'"
                            . " data-telepon='" . htmlspecialchars($row['TELEPON'], ENT_QUOTES) . "'"
                            . " data-whatsapp='" . htmlspecialchars($row['WHATSAPP'], ENT_QUOTES) . "'"
                            . " data-website='" . htmlspecialchars($row['WEBSITE'], ENT_QUOTES) . "'"
                            . " data-catatan='" . htmlspecialchars($row['CATATAN'], ENT_QUOTES) . "'"
                            . " data-area='" . htmlspecialchars($row['AREA'], ENT_QUOTES) . "'"
                            . " data-status='" . htmlspecialchars($row['STATUS'], ENT_QUOTES) . "'"
                            . " data-portalusername='" . htmlspecialchars((string) ($row['PORTAL_USERNAME'] ?? ''), ENT_QUOTES) . "'"
                            . ">Edit</button><br>";
                        echo "<a href='corporate_kontrak.php?corporate_id=" . (int) $row['id'] . "' class='btn btn-info btn-sm mb-1' data-perm='btn_corp_kontrak'>Kontrak</a><br>";
                        echo "<a href='corporate_layanan.php?corporate_id=" . (int) $row['id'] . "' class='btn btn-dark btn-sm mb-1' data-perm='btn_corp_layanan'>Layanan</a><br>";
                        echo "<a href='transaksicorporate.php?corporate_id=" . (int) $row['id'] . "' class='btn btn-primary btn-sm mb-1' data-perm='btn_corp_invoice'>Invoice</a><br>";
                        echo "<form method='post' action='proses/deletecorporate.php' data-perm='btn_corp_hapus' style='display:inline' onsubmit='return confirm(\"Yakin ingin menghapus perusahaan ini? Semua PIC dan kontrak ikut terhapus.\")'>"
                            . "<input type='hidden' name='id' value='" . (int) $row['id'] . "'>"
                            . "<button type='submit' class='btn btn-danger btn-sm'>Hapus</button></form>";
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='9' class='text-center'>Belum ada Customer Corporate</td></tr>";
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

<?php
// Template subform PIC dipakai bareng modal Tambah & Edit (JS clone).
function corporate_render_pic_row_template($idx, $vals = []) {
    $nama = htmlspecialchars($vals['nama'] ?? '', ENT_QUOTES);
    $jabatan = htmlspecialchars($vals['jabatan'] ?? '', ENT_QUOTES);
    $email = htmlspecialchars($vals['email'] ?? '', ENT_QUOTES);
    $whatsapp = htmlspecialchars($vals['whatsapp'] ?? '', ENT_QUOTES);
    $telepon = htmlspecialchars($vals['telepon'] ?? '', ENT_QUOTES);
    return '<div class="row pic-row border rounded p-2 mb-2 mx-0">
        <div class="col-md-3 mb-2"><input type="text" class="form-control form-control-sm" name="pic_nama[]" placeholder="Nama PIC" value="' . $nama . '"></div>
        <div class="col-md-2 mb-2"><input type="text" class="form-control form-control-sm" name="pic_jabatan[]" placeholder="Jabatan (Finance/IT/dst)" value="' . $jabatan . '"></div>
        <div class="col-md-3 mb-2"><input type="email" class="form-control form-control-sm" name="pic_email[]" placeholder="Email" value="' . $email . '"></div>
        <div class="col-md-2 mb-2"><input type="text" class="form-control form-control-sm" name="pic_whatsapp[]" placeholder="WhatsApp" value="' . $whatsapp . '"></div>
        <div class="col-md-1 mb-2"><input type="text" class="form-control form-control-sm" name="pic_telepon[]" placeholder="Telepon" value="' . $telepon . '"></div>
        <div class="col-md-1 mb-2"><button type="button" class="btn btn-outline-danger btn-sm remove-pic-row">&times;</button></div>
    </div>';
}
?>

<!-- Modal Tambah -->
<div class="modal fade" id="addCorporateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tambah Customer Corporate</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/addcorporate.php" enctype="multipart/form-data">
          <h6 class="text-uppercase text-xs">Data Perusahaan</h6>
          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label">Nama Perusahaan</label>
              <input required type="text" class="form-control" name="nama_perusahaan">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Area</label>
              <input type="text" class="form-control" name="area" list="areaOptionsAdd" placeholder="Opsional">
              <datalist id="areaOptionsAdd">
                <?php foreach ($areaOptions as $a): ?><option value="<?php echo htmlspecialchars($a); ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Nama Penanggung Jawab</label>
              <input type="text" class="form-control" name="pj_nama">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Jabatan</label>
              <input type="text" class="form-control" name="pj_jabatan">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">NPWP</label>
              <input type="text" class="form-control" name="npwp">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">NIB</label>
              <input type="text" class="form-control" name="nib">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Nomor SIUP <small class="text-muted">(opsional)</small></label>
              <input type="text" class="form-control" name="siup">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Alamat Kantor</label>
            <textarea class="form-control" name="alamat_kantor" rows="2"></textarea>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Email Finance</label>
              <input type="email" class="form-control" name="email_finance">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Email IT</label>
              <input type="email" class="form-control" name="email_it">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Nomor Telepon</label>
              <input type="text" class="form-control" name="telepon">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Nomor WhatsApp</label>
              <input type="text" class="form-control" name="whatsapp" placeholder="62878xxxxxx">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Website</label>
              <input type="text" class="form-control" name="website">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Kontak / Catatan</label>
            <textarea class="form-control" name="catatan" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Logo Perusahaan <small class="text-muted">(opsional, JPG/PNG maks 5MB)</small></label>
            <input type="file" class="form-control" name="logo" accept=".jpg,.jpeg,.png">
          </div>

          <hr>
          <h6 class="text-uppercase text-xs">Portal Login Corporate <small class="text-muted normal-case">(opsional -- kosongkan kalau perusahaan ini belum perlu akses portal)</small></h6>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Username Portal</label>
              <input type="text" class="form-control" name="portal_username" placeholder="Mis. ptabc">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Password Portal</label>
              <input type="text" class="form-control" name="portal_password">
            </div>
          </div>
          <p class="text-muted small">Link portal: <code>corporate_portal/login.php</code> (di domain billing Anda) -- berikan username &amp; password di atas ke PIC perusahaan.</p>

          <hr>
          <h6 class="text-uppercase text-xs d-flex justify-content-between align-items-center">
            PIC (Person In Charge)
            <button type="button" class="btn btn-outline-primary btn-sm" id="addPicRowBtnAdd">+ Tambah PIC</button>
          </h6>
          <div id="picRowsAdd"><?php echo corporate_render_pic_row_template(0); ?></div>

          <div class="modal-footer px-0 pb-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="editCorporateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Customer Corporate</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post" action="proses/editcorporate.php" enctype="multipart/form-data">
          <input type="hidden" name="id" id="editCorpId">
          <h6 class="text-uppercase text-xs">Data Perusahaan</h6>
          <div class="row">
            <div class="col-md-8 mb-3">
              <label class="form-label">Nama Perusahaan</label>
              <input required type="text" class="form-control" name="nama_perusahaan" id="editCorpNama">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Area</label>
              <input type="text" class="form-control" name="area" id="editCorpArea" list="areaOptionsEdit" placeholder="Opsional">
              <datalist id="areaOptionsEdit">
                <?php foreach ($areaOptions as $a): ?><option value="<?php echo htmlspecialchars($a); ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Nama Penanggung Jawab</label>
              <input type="text" class="form-control" name="pj_nama" id="editCorpPjNama">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Jabatan</label>
              <input type="text" class="form-control" name="pj_jabatan" id="editCorpPjJabatan">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">NPWP</label>
              <input type="text" class="form-control" name="npwp" id="editCorpNpwp">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">NIB</label>
              <input type="text" class="form-control" name="nib" id="editCorpNib">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Nomor SIUP <small class="text-muted">(opsional)</small></label>
              <input type="text" class="form-control" name="siup" id="editCorpSiup">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Alamat Kantor</label>
            <textarea class="form-control" name="alamat_kantor" id="editCorpAlamat" rows="2"></textarea>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Email Finance</label>
              <input type="email" class="form-control" name="email_finance" id="editCorpEmailFinance">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Email IT</label>
              <input type="email" class="form-control" name="email_it" id="editCorpEmailIt">
            </div>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Nomor Telepon</label>
              <input type="text" class="form-control" name="telepon" id="editCorpTelepon">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Nomor WhatsApp</label>
              <input type="text" class="form-control" name="whatsapp" id="editCorpWhatsapp">
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Website</label>
              <input type="text" class="form-control" name="website" id="editCorpWebsite">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Kontak / Catatan</label>
            <textarea class="form-control" name="catatan" id="editCorpCatatan" rows="2"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status" id="editCorpStatus">
              <option value="AKTIF">AKTIF</option>
              <option value="NONAKTIF">NONAKTIF</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Ganti Logo Perusahaan <small class="text-muted">(opsional, kosongkan jika tidak ganti)</small></label>
            <input type="file" class="form-control" name="logo" accept=".jpg,.jpeg,.png">
          </div>

          <hr>
          <h6 class="text-uppercase text-xs">Portal Login Corporate <small class="text-muted normal-case">(opsional)</small></h6>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Username Portal</label>
              <input type="text" class="form-control" name="portal_username" id="editCorpPortalUsername">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Password Portal <small class="text-muted">(kosongkan jika tidak ganti)</small></label>
              <input type="text" class="form-control" name="portal_password">
            </div>
          </div>

          <hr>
          <h6 class="text-uppercase text-xs d-flex justify-content-between align-items-center">
            PIC (Person In Charge)
            <button type="button" class="btn btn-outline-primary btn-sm" id="addPicRowBtnEdit">+ Tambah PIC</button>
          </h6>
          <div id="picRowsEdit"></div>

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
function corporateAddPicRow(containerId, vals) {
    vals = vals || {};
    var wrap = document.createElement('div');
    wrap.innerHTML = (<?php echo json_encode(corporate_render_pic_row_template(0)); ?>).trim();
    var row = wrap.firstElementChild;
    if (vals.nama) row.querySelector('[name="pic_nama[]"]').value = vals.nama;
    if (vals.jabatan) row.querySelector('[name="pic_jabatan[]"]').value = vals.jabatan;
    if (vals.email) row.querySelector('[name="pic_email[]"]').value = vals.email;
    if (vals.whatsapp) row.querySelector('[name="pic_whatsapp[]"]').value = vals.whatsapp;
    if (vals.telepon) row.querySelector('[name="pic_telepon[]"]').value = vals.telepon;
    document.getElementById(containerId).appendChild(row);
}
document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('remove-pic-row')) {
        var row = e.target.closest('.pic-row');
        var container = row.parentElement;
        if (container.querySelectorAll('.pic-row').length > 1) {
            row.remove();
        } else {
            row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
        }
    }
});
document.getElementById('addPicRowBtnAdd').addEventListener('click', function () {
    corporateAddPicRow('picRowsAdd', {});
});
document.getElementById('addPicRowBtnEdit').addEventListener('click', function () {
    corporateAddPicRow('picRowsEdit', {});
});

var editCorpModalEl = document.getElementById('editCorporateModal');
editCorpModalEl.addEventListener('show.bs.modal', function (event) {
    var btn = event.relatedTarget;
    document.getElementById('editCorpId').value = btn.getAttribute('data-id') || '';
    document.getElementById('editCorpNama').value = btn.getAttribute('data-nama') || '';
    document.getElementById('editCorpArea').value = btn.getAttribute('data-area') || '';
    document.getElementById('editCorpPjNama').value = btn.getAttribute('data-pjnama') || '';
    document.getElementById('editCorpPjJabatan').value = btn.getAttribute('data-pjjabatan') || '';
    document.getElementById('editCorpNpwp').value = btn.getAttribute('data-npwp') || '';
    document.getElementById('editCorpNib').value = btn.getAttribute('data-nib') || '';
    document.getElementById('editCorpSiup').value = btn.getAttribute('data-siup') || '';
    document.getElementById('editCorpAlamat').value = btn.getAttribute('data-alamat') || '';
    document.getElementById('editCorpEmailFinance').value = btn.getAttribute('data-emailfinance') || '';
    document.getElementById('editCorpEmailIt').value = btn.getAttribute('data-emailit') || '';
    document.getElementById('editCorpTelepon').value = btn.getAttribute('data-telepon') || '';
    document.getElementById('editCorpWhatsapp').value = btn.getAttribute('data-whatsapp') || '';
    document.getElementById('editCorpWebsite').value = btn.getAttribute('data-website') || '';
    document.getElementById('editCorpCatatan').value = btn.getAttribute('data-catatan') || '';
    document.getElementById('editCorpStatus').value = btn.getAttribute('data-status') || 'AKTIF';
    document.getElementById('editCorpPortalUsername').value = btn.getAttribute('data-portalusername') || '';

    var corpId = btn.getAttribute('data-id');
    var picContainer = document.getElementById('picRowsEdit');
    picContainer.innerHTML = '<div class="text-muted small">Memuat PIC...</div>';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'getdata/get_corporate_pic.php?corporate_id=' + encodeURIComponent(corpId), true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState == 4 && xhr.status == 200) {
            picContainer.innerHTML = '';
            var data = [];
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = []; }
            if (!data.length) {
                corporateAddPicRow('picRowsEdit', {});
            } else {
                data.forEach(function (p) { corporateAddPicRow('picRowsEdit', p); });
            }
        }
    };
    xhr.send();
});
</script>

<?php require 'footer.php'; ?>
