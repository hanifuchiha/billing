<?php require 'header.php';

if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('IP_Pool', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu IP Pool.</div></div>';
        require 'footer.php';
        exit;
    }
}

require('routeros_api.class.php');
$API = new RouterosAPI();

?>
   <?php
// Notifikasi sukses atau gagal dari addpool.php / sync_pool.php / delete_pool_bulk.php
$status = $_GET['statusnotif'] ?? '';
?>
      <?php if ($status == 'success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Berhasil!</strong> IP Pool berhasil dibuat.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'duplicate'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>?? Gagal!</strong> Nama pool atau IP range sudah digunakan.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'failed'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>? Error!</strong> Terjadi kesalahan saat membuat IP Pool.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'cannot_delete'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>? Gagal!</strong> IP Pool masih digunakan oleh paket, tidak dapat dihapus.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'success_delete'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Berhasil!</strong> IP Pool berhasil dihapus.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'failed_delete'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>?? Gagal!</strong> Terjadi kesalahan saat menghapus IP Pool.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'import_success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Import selesai!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Data IP Pool berhasil diimport.'); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'import_failed'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>? Import gagal!</strong> <?php echo nl2br(htmlspecialchars($_GET['text'] ?? 'Terjadi kesalahan saat import IP Pool.')); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'sync_success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Sync selesai!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'IP Pool berhasil disinkronkan dari MikroTik.'); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'sync_partial'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>?? Sync sebagian berhasil!</strong> Beberapa baris pool gagal disinkronkan.<br>
          <small><?php echo htmlspecialchars($_GET['text'] ?? ''); ?></small>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'sync_failed'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>? Sync gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Gagal terhubung atau mengambil data pool dari MikroTik.'); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'bulk_delete_success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <strong>? Berhasil!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'IP Pool terpilih berhasil dihapus.'); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'bulk_delete_partial'): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>?? Sebagian berhasil!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Sebagian IP Pool terpilih gagal dihapus.'); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif ($status == 'bulk_delete_failed'): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <strong>? Gagal!</strong> <?php echo htmlspecialchars($_GET['text'] ?? 'Tidak ada IP Pool yang berhasil dihapus.'); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>


<!-- Card Sync Pool dari Server -->
<?php
// Ambil daftar server milik user yang login (pakai pola sama dgn server.php)
$servers_pool = [];
if (isset($current_user_id) && $current_user_id) {
  $sqlSrvPool = "SELECT * FROM server WHERE user_id = $current_user_id";
  $querySrvPool = mysqli_query($conn, $sqlSrvPool);
  while ($rowSrvPool = mysqli_fetch_array($querySrvPool)) {
    $servers_pool[] = $rowSrvPool;
  }
}
$selected_sync_ip = $_POST['sync_server'] ?? '';
?>
<div class="card mb-4 shadow">
  <div class="card-header bg-primary text-white">
    <h6 class="mb-2">Sync IP Pool dari Server</h6>
    <form method="POST" action="proses/sync_pool.php" class="d-flex flex-column flex-md-row gap-2" id="syncPoolForm" onsubmit="showSyncLoading();">
      <select name="sync_server" class="form-select" required>
        <option value="">-- Pilih Server --</option>
        <?php foreach ($servers_pool as $srvPool): ?>
          <option value="<?= htmlspecialchars($srvPool['IP']) ?>" <?= ($srvPool['IP'] == $selected_sync_ip) ? 'selected' : '' ?>>
            <?= htmlspecialchars($srvPool['IP']) ?> (<?= htmlspecialchars($srvPool['AREA']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-warning" type="submit">
        <i class="fas fa-sync me-1"></i> Sync Pool
      </button>
    </form>
    <div id="syncPoolLoadingSpinner" style="display:none; margin-top:10px;">
      <div class="spinner-border text-light" role="status">
        <span class="visually-hidden">Loading...</span>
      </div>
      <span class="ms-2">Mengambil data IP Pool dari MikroTik...</span>
    </div>
  </div>
</div>
<script>
function showSyncLoading() {
  var spinner = document.getElementById('syncPoolLoadingSpinner');
  if (spinner) spinner.style.display = '';
}
window.addEventListener('DOMContentLoaded', function() {
  var spinner = document.getElementById('syncPoolLoadingSpinner');
  if (spinner) spinner.style.display = 'none';
});
</script>

<!-- Card Form -->
<div class="card mb-4">
       <!-- ? Notifikasi -->

    
 <div class="card-header bg-primary text-white">
      Manajemen IP Pool
    </div>
  <div class="card-body border-bottom pb-3">
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importPoolModal">
      <i class="fas fa-file-excel me-1"></i> Import IP Pool dari Excel
    </button>
    <a href="proses/download_template_pool.php" class="btn btn-info">
      <i class="fas fa-download me-1"></i> Download Template Excel
    </a>
    <a href="proses/export_pool.php" class="btn btn-info">
      <i class="fas fa-file-excel me-1"></i> Export IP Pool ke XLSX
    </a>
  </div>
    Buat IP Pool Baru
    <div class="card-body">
        <form action="proses/apply_pool.php" method="POST">
            <div class="mb-3">
                <label for="pool_name" class="form-label">Nama Pool</label>
                <input type="text" class="form-control" id="pool_name" name="pool_name" placeholder="Misal: Pool1" required>
            </div>




            <div class="mb-3">
                <label for="range_start" class="form-label">IP Awal RANGE <span class="text-muted">atau Subnet</span></label>
                <input type="text" class="form-control" id="range_start" name="range_start" placeholder="192.168.1.2 atau 192.168.1.0/24" required oninput="autoFillRangeEnd()">
                <div class="form-text">Bisa diisi IP awal (misal: 192.168.1.2) <b>atau subnet</b> (misal: 192.168.1.0/24, otomatis hitung range).</div>
            </div>

            <div class="mb-3">
                <label for="range_end" class="form-label">IP Akhir <span class="text-muted">(kosongkan jika pakai subnet)</span></label>
                <input type="text" class="form-control" id="range_end" name="range_end" placeholder="192.168.1.254">
                <div class="form-text">Jika mengisi subnet di atas, kolom ini akan otomatis terisi.</div>
            </div>

            <div class="mb-3">
                <label for="local_ip" class="form-label">Local IP (otomatis dari subnet, bisa diubah)</label>
                <input type="text" class="form-control" id="local_ip" name="local_ip" placeholder="192.168.1.1">
                <div class="form-text">Akan otomatis terisi IP pertama dari subnet jika Anda mengisi subnet di atas, tapi tetap bisa diubah manual.</div>
            </div>

<script>
// Auto-fill IP Akhir jika input subnet di IP Awal RANGE
function autoFillRangeEnd() {
    const startInput = document.getElementById('range_start');
    const endInput = document.getElementById('range_end');
    const localIpInput = document.getElementById('local_ip');
    const val = startInput.value.trim();
    if (/^\d+\.\d+\.\d+\.\d+\/\d{1,2}$/.test(val)) {
        // Parse subnet
        const [ip, prefix] = val.split('/');
        const ipParts = ip.split('.').map(Number);
        if (ipParts.length !== 4) return;
        const ipLong = (ipParts[0]<<24) + (ipParts[1]<<16) + (ipParts[2]<<8) + ipParts[3];
        const mask = -1 << (32 - parseInt(prefix));
        const network = ipLong & mask;
        const broadcast = network | (~mask & 0xFFFFFFFF);
        let first = (parseInt(prefix) < 31) ? network + 1 : network;
        let last = (parseInt(prefix) < 31) ? broadcast - 1 : broadcast;
        // Convert last to IP
        const lastIp = [
            (last>>>24)&255,
            (last>>>16)&255,
            (last>>>8)&255,
            last&255
        ].join('.');
        endInput.value = lastIp;
        endInput.readOnly = true;
        endInput.classList.add('bg-light');
        // Auto-fill local IP (first usable IP), but allow edit
        const firstIp = [
            (first>>>24)&255,
            (first>>>16)&255,
            (first>>>8)&255,
            first&255
        ].join('.');
        localIpInput.value = firstIp;
        localIpInput.readOnly = false;
        localIpInput.classList.add('bg-light');
    } else {
        endInput.readOnly = false;
        endInput.classList.remove('bg-light');
        endInput.value = '';
        // Clear local IP if not subnet
        localIpInput.readOnly = false;
        localIpInput.classList.remove('bg-light');
        localIpInput.value = '';
    }
}
</script>

            <input type="hidden" name="pemilik" value="<?php echo $ceknama; ?>">

            <button type="submit" class="btn btn-primary">Buat IP Pool</button>
        </form>
    </div>
</div>

<!-- Modal Import IP Pool Excel -->
<div class="modal fade" id="importPoolModal" tabindex="-1" aria-labelledby="importPoolModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importPoolModalLabel">Import IP Pool dari Excel</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="proses/import_pool.php" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="alert alert-info small mb-2">
            <b>Petunjuk:</b> Download template, isi sesuai urutan kolom berikut:<br>
            <b>pool_name | ipawal | ipakhir | iplocal | pemilik</b><br>
            <span class="text-danger">* Format wajib .xlsx, baris pertama = judul kolom (boleh di-skip)</span><br>
            <span class="text-muted">* Untuk subnet, isi ipawal seperti 192.168.1.0/24, kolom ipakhir bisa dikosongkan.</span>
          </div>
          <div class="mb-3">
            <label for="excel_file" class="form-label">Pilih file Excel (.xlsx)</label>
            <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx" required>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" value="1" id="skip_first_row" name="skip_first_row" checked>
            <label class="form-check-label" for="skip_first_row">Lewati baris pertama (judul kolom)</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-success">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Card Tabel -->
<div class="card">
    <div class="card-header bg-success text-white d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
        <span>Daftar IP Pool</span>
        <button type="button" id="btnHapusTerpilih" class="btn btn-danger btn-sm" disabled form="formBulkDeletePool">
            <i class="fas fa-trash-alt me-1"></i> Hapus Terpilih (<span id="countTerpilih">0</span>)
        </button>
    </div>
    <div class="card-body">
        <form id="formBulkDeletePool" method="POST" action="proses/delete_pool_bulk.php" onsubmit="return confirmBulkDeletePool();">
        <div class="table-responsive">
            <table id="tabel_pool" class="table table-bordered table-striped table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width:40px;">
                            <input type="checkbox" id="checkAllPool" class="form-check-input" title="Pilih semua">
                        </th>
                        <th>No</th>
                        <th>Nama Pool</th>
                        <th>Local IP</th>
                        <th>Rentang IP</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT `id`,`pool_name`, `ipawal`, `ipakhir`, `iplocal`, `pemilik` 
                            FROM `pool` 
                            WHERE `pemilik` ='$ceknama'";
                    $query = mysqli_query($conn, $sql);

                    if(mysqli_num_rows($query) > 0){
                        $no = 1;
                        while($data = mysqli_fetch_assoc($query)){
                            echo "<tr>";
                            echo "<td><input type='checkbox' class='form-check-input chk-pool-row' name='id[]' value='".$data['id']."'></td>";
                            echo "<td>".$no++."</td>";
                            echo "<td>".htmlspecialchars($data['pool_name'])."</td>";
                            echo "<td>".htmlspecialchars($data['iplocal'])."</td>";
                            echo "<td>".htmlspecialchars($data['ipawal'])." - ".htmlspecialchars($data['ipakhir'])."</td>";
                            echo "<td>
                                    <a href='proses/delete_pool.php?id=".$data['id']."' 
                                       class='btn btn-danger btn-sm' 
                                       onclick='return confirm(\"Yakin ingin menghapus?\")'>
                                       Hapus
                                    </a>
                                  </td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' class='text-center'>Belum ada IP Pool</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const checkAll = document.getElementById('checkAllPool');
    const btnHapus = document.getElementById('btnHapusTerpilih');
    const countSpan = document.getElementById('countTerpilih');

    function getRowChecks() {
        return document.querySelectorAll('.chk-pool-row');
    }

    function updateBulkDeleteButton() {
        const checks = getRowChecks();
        const checkedCount = document.querySelectorAll('.chk-pool-row:checked').length;
        countSpan.textContent = checkedCount;
        btnHapus.disabled = checkedCount === 0;

        // Sinkronkan status "pilih semua"
        if (checks.length > 0 && checkedCount === checks.length) {
            checkAll.checked = true;
            checkAll.indeterminate = false;
        } else if (checkedCount === 0) {
            checkAll.checked = false;
            checkAll.indeterminate = false;
        } else {
            checkAll.indeterminate = true;
        }
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            getRowChecks().forEach(function (chk) {
                chk.checked = checkAll.checked;
            });
            updateBulkDeleteButton();
        });
    }

    getRowChecks().forEach(function (chk) {
        chk.addEventListener('change', updateBulkDeleteButton);
    });

    // Inisialisasi status awal tombol
    updateBulkDeleteButton();
});

function confirmBulkDeletePool() {
    const checkedCount = document.querySelectorAll('.chk-pool-row:checked').length;
    if (checkedCount === 0) {
        alert('Pilih minimal satu IP Pool untuk dihapus.');
        return false;
    }
    return confirm('Yakin ingin menghapus ' + checkedCount + ' IP Pool terpilih?');
}
</script>



<?php require 'footer.php'; ?>