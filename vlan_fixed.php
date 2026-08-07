<?php require 'header.php'; ?>
<?php
$createTableSql = "CREATE TABLE IF NOT EXISTS vlan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vlan_id INT UNSIGNED NOT NULL,
    keterangan VARCHAR(255) DEFAULT NULL,
    pool_id INT UNSIGNED NOT NULL,
    server_id INT UNSIGNED NOT NULL,
    interface_name VARCHAR(120) NOT NULL,
    ip_gateway VARCHAR(120) DEFAULT NULL,
    vlan_interface_name VARCHAR(120) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    pemilik VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vlan_owner_server_interface (vlan_id, pemilik, server_id, interface_name),
    KEY idx_vlan_pemilik (pemilik),
    KEY idx_vlan_pool (pool_id),
    KEY idx_vlan_server (server_id),
    KEY idx_vlan_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
mysqli_query($conn, $createTableSql);

$status = $_GET['statusnotif'] ?? '';
$text = $_GET['text'] ?? '';

$whereArea = '';
if ($AKSES === 'ASSISTANT') {
    if (!empty($area_list)) {
        $whereArea = " AND AREA IN ($area_list)";
    } else {
        $whereArea = ' AND 1=0';
    }
}

$pools = [];
$poolSql = "SELECT id, pool_name, ipawal, ipakhir, iplocal FROM pool WHERE pemilik='" . mysqli_real_escape_string($conn, $ceknama) . "' ORDER BY pool_name ASC";
$poolQuery = mysqli_query($conn, $poolSql);
if ($poolQuery) {
    while ($row = mysqli_fetch_assoc($poolQuery)) {
        $pools[] = $row;
    }
}

$servers = [];
$serverSql = "SELECT DISTINCT id, PEMILIK, BRAND, AREA, IP FROM server WHERE PEMILIK='" . mysqli_real_escape_string($conn, $ceknama) . "'" . $whereArea . " ORDER BY BRAND ASC, AREA ASC";
$serverQuery = mysqli_query($conn, $serverSql);
if ($serverQuery) {
    while ($row = mysqli_fetch_assoc($serverQuery)) {
        $servers[] = $row;
    }
}
// Jika tidak ada server dari query, cek apakah ada masalah dengan data
if (empty($servers) && $AKSES !== 'ASSISTANT') {
    // Query ulang tanpa AREA filter untuk debug
    $serverSql2 = "SELECT DISTINCT id, PEMILIK, BRAND, AREA, IP FROM server WHERE PEMILIK='" . mysqli_real_escape_string($conn, $ceknama) . "' ORDER BY BRAND ASC, AREA ASC";
    $serverQuery2 = mysqli_query($conn, $serverSql2);
    if ($serverQuery2) {
        while ($row = mysqli_fetch_assoc($serverQuery2)) {
            $servers[] = $row;
        }
    }
}

$vlanRows = [];
$vlanSql = "SELECT v.id, v.vlan_id, v.keterangan, v.interface_name, v.ip_gateway, v.vlan_interface_name, v.status, v.error_message, v.created_at,
                   p.pool_name, p.ipawal, p.ipakhir,
                   s.AREA, s.IP, s.BRAND
            FROM vlan v
            LEFT JOIN pool p ON p.id = v.pool_id
            LEFT JOIN server s ON s.id = v.server_id
            WHERE v.pemilik='" . mysqli_real_escape_string($conn, $ceknama) . "'";
if ($AKSES === 'ASSISTANT') {
    if (!empty($area_list)) {
        $vlanSql .= " AND s.AREA IN ($area_list)";
    } else {
        $vlanSql .= ' AND 1=0';
    }
}
$vlanSql .= ' ORDER BY v.id DESC';

$vlanQuery = mysqli_query($conn, $vlanSql);
if ($vlanQuery) {
    while ($row = mysqli_fetch_assoc($vlanQuery)) {
        $vlanRows[] = $row;
    }
}
?>

<?php if ($status === 'success'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil.</strong> VLAN berhasil ditambahkan.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'duplicate'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal.</strong> VLAN sudah ada pada server dan interface tersebut.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Error.</strong> Gagal menyimpan VLAN.
    <?php if ($text !== ''): ?>
      <div class="small mt-1"><?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'warning'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Peringatan.</strong> VLAN dibuat sebagian.
    <?php if ($text !== ''): ?>
      <div class="small mt-1"><?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'success_delete'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil.</strong> VLAN berhasil dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed_delete'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Gagal.</strong> VLAN tidak dapat dihapus.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <span>Manajemen VLAN</span>
    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addVlanModal">
      <i class="fas fa-plus me-1"></i> Tambah VLAN
    </button>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="small text-muted">Total VLAN</div>
          <div class="h4 mb-0"><?php echo count($vlanRows); ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="small text-muted">Total Pool Tersedia</div>
          <div class="h4 mb-0"><?php echo count($pools); ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="border rounded p-3 h-100">
          <div class="small text-muted">Total Server Tersedia</div>
          <div class="h4 mb-0"><?php echo count($servers); ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header bg-success text-white">Daftar VLAN</div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-bordered table-striped table-hover align-middle">
        <thead class="table-dark">
          <tr>
            <th>No</th>
            <th>VLAN</th>
            <th>Keterangan</th>
            <th>IP Pool</th>
            <th>IP Gateway</th>
            <th>Server Area</th>
            <th>Server IP</th>
            <th>Interface</th>
            <th>Status</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($vlanRows)): ?>
            <?php $no = 1; ?>
            <?php foreach ($vlanRows as $row): ?>
              <tr>
                <td><?php echo $no++; ?></td>
                <td><?php echo (int)$row['vlan_id']; ?></td>
                <td><?php echo htmlspecialchars($row['keterangan'] ?? '-'); ?></td>
                <td>
                  <div><?php echo htmlspecialchars($row['pool_name'] ?? '-'); ?></div>
                  <small class="text-muted"><?php echo htmlspecialchars(($row['ipawal'] ?? '-') . ' - ' . ($row['ipakhir'] ?? '-')); ?></small>
                </td>
                <td><?php echo htmlspecialchars($row['ip_gateway'] ?? '-'); ?></td>
                <td>
                  <div><?php echo htmlspecialchars($row['BRAND'] ?? '-'); ?></div>
                  <small class="text-muted"><?php echo htmlspecialchars($row['AREA'] ?? '-'); ?></small>
                </td>
                <td><?php echo htmlspecialchars($row['IP'] ?? '-'); ?></td>
                <td>
                  <div><?php echo htmlspecialchars($row['interface_name'] ?? '-'); ?></div>
                  <?php if (!empty($row['vlan_interface_name'])): ?>
                    <small class="text-success">→ <?php echo htmlspecialchars($row['vlan_interface_name']); ?></small>
                  <?php endif; ?>
                </td>
                <td>
                  <?php 
                    $status = $row['status'] ?? 'pending';
                    if ($status === 'active') {
                        $badgeClass = 'bg-success';
                        $statusText = 'Aktif';
                    } elseif ($status === 'active_partial') {
                        $badgeClass = 'bg-warning';
                        $statusText = 'Aktif Sebagian';
                    } elseif ($status === 'pending') {
                        $badgeClass = 'bg-info';
                        $statusText = 'Pending';
                    } else {
                        $badgeClass = 'bg-danger';
                        $statusText = 'Gagal';
                    }
                  ?>
                  <span class="badge <?php echo $badgeClass; ?>" title="<?php echo htmlspecialchars($row['error_message'] ?? ''); ?>">
                    <?php echo $statusText; ?>
                  </span>
                </td>
                <td>
                  <a href="proses/delete_vlan.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Yakin ingin menghapus VLAN ini?');">
                    Hapus
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="10" class="text-center">Belum ada data VLAN</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="addVlanModal" tabindex="-1" aria-labelledby="addVlanModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="addVlanModalLabel">Tambah VLAN</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="proses/add_vlan.php" method="POST">
        <div class="modal-body">
          <div class="mb-3">
            <label for="vlan_id" class="form-label">VLAN</label>
            <input type="number" min="1" max="4094" class="form-control" id="vlan_id" name="vlan_id" placeholder="Contoh: 100" required>
          </div>

          <div class="mb-3">
            <label for="keterangan" class="form-label">Keterangan VLAN</label>
            <input type="text" class="form-control" id="keterangan" name="keterangan" placeholder="Contoh: VLAN pelanggan area timur" required>
          </div>

          <div class="mb-3">
            <label for="pool_id" class="form-label">IP Pool</label>
            <select class="form-select" id="pool_id" name="pool_id" required>
              <option value="">-- Pilih Pool --</option>
              <?php foreach ($pools as $pool): ?>
                <option value="<?php echo (int)$pool['id']; ?>">
                  <?php echo htmlspecialchars($pool['pool_name'] . ' (' . $pool['ipawal'] . ' - ' . $pool['ipakhir'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="server_id" class="form-label">Server Area</label>
            <select class="form-select" id="server_id" name="server_id" required>
              <option value="">-- Pilih Server --</option>
              <?php foreach ($servers as $server): ?>
                <option value="<?php echo (int)$server['id']; ?>">
                  <?php 
                    $brand = !empty($server['BRAND']) ? $server['BRAND'] : 'No Brand';
                    $area = !empty($server['AREA']) ? $server['AREA'] : 'No Area';
                    echo htmlspecialchars($brand . '-' . $area);
                  ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="interface_name" class="form-label">Interface Server</label>
            <select class="form-select" id="interface_name" name="interface_name" required>
              <option value="">-- Pilih server dulu --</option>
            </select>
            <div class="form-text" id="interface_help">Interface akan diambil dari API server yang dipilih.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan VLAN</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require 'footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serverSelect = document.getElementById('server_id');
    const interfaceSelect = document.getElementById('interface_name');

    serverSelect.addEventListener('change', function() {
        const serverId = this.value;
        
        if (!serverId) {
            interfaceSelect.innerHTML = '<option value="">-- Pilih server dulu --</option>';
            return;
        }

        // Fetch interfaces dari server
        fetch('api/get_server_interfaces.php?server_id=' + encodeURIComponent(serverId))
            .then(response => response.json())
            .then(data => {
                interfaceSelect.innerHTML = '<option value="">-- Pilih Interface --</option>';
                
                if (data.success && Array.isArray(data.data)) {
                    data.data.forEach(intf => {
                        const option = document.createElement('option');
                        option.value = intf.name;
                        option.textContent = intf.name + ' (' + (intf.type || 'unknown') + ')';
                        interfaceSelect.appendChild(option);
                    });
                } else {
                    interfaceSelect.innerHTML = '<option value="">Gagal memuat interface</option>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                interfaceSelect.innerHTML = '<option value="">Error memuat interface</option>';
            });
    });
});
</script>
