<?php require 'header.php'; ?>
<?php
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('VLAN', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu VLAN.</div></div>';
        require 'footer.php';
        exit;
    }
}
$createTableSql = "CREATE TABLE IF NOT EXISTS vlan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vlan_id INT UNSIGNED NOT NULL,
    keterangan VARCHAR(255) DEFAULT NULL,
    pool_id INT UNSIGNED DEFAULT NULL,
    server_id INT UNSIGNED NOT NULL,
    interface_name VARCHAR(120) NOT NULL,
    ip_gateway VARCHAR(120) DEFAULT NULL,
    vlan_interface_name VARCHAR(120) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    sync_source VARCHAR(30) DEFAULT 'manual_add',
    last_synced_at TIMESTAMP NULL DEFAULT NULL,
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
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN ip_gateway VARCHAR(120) DEFAULT NULL AFTER interface_name");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN vlan_interface_name VARCHAR(120) DEFAULT NULL AFTER ip_gateway");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN status VARCHAR(20) DEFAULT 'pending' AFTER vlan_interface_name");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN error_message TEXT DEFAULT NULL AFTER status");
mysqli_query($conn, "ALTER TABLE vlan MODIFY pool_id INT UNSIGNED NULL");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN sync_source VARCHAR(30) DEFAULT 'manual_add' AFTER status");
mysqli_query($conn, "ALTER TABLE vlan ADD COLUMN last_synced_at TIMESTAMP NULL DEFAULT NULL AFTER sync_source");

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
$currentUserId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
if ($AKSES === 'ASSISTANT') {
  $serverSql = "SELECT DISTINCT id, PEMILIK, BRAND, AREA, IP FROM server WHERE 1=1" . $whereArea . " ORDER BY BRAND ASC, AREA ASC";
} else {
  $serverSql = "SELECT DISTINCT id, PEMILIK, BRAND, AREA, IP FROM server WHERE user_id = $currentUserId ORDER BY BRAND ASC, AREA ASC";
}
$serverQuery = mysqli_query($conn, $serverSql);
if ($serverQuery) {
    while ($row = mysqli_fetch_assoc($serverQuery)) {
        $servers[] = $row;
    }
}
// Fallback untuk data lama yang belum punya user_id konsisten
if (empty($servers) && $AKSES !== 'ASSISTANT') {
  $serverSql2 = "SELECT DISTINCT id, PEMILIK, BRAND, AREA, IP FROM server WHERE PEMILIK='" . mysqli_real_escape_string($conn, $ceknama) . "' ORDER BY BRAND ASC, AREA ASC";
    $serverQuery2 = mysqli_query($conn, $serverSql2);
    if ($serverQuery2) {
        while ($row = mysqli_fetch_assoc($serverQuery2)) {
            $servers[] = $row;
        }
    }
}

$vlanRows = [];
$vlanSql = "SELECT v.id, v.vlan_id, v.keterangan, v.pool_id, v.interface_name, v.ip_gateway, v.vlan_interface_name, v.status, v.sync_source, v.last_synced_at, v.error_message, v.created_at,
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
<?php elseif ($status === 'success_sync'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil.</strong> Sinkron VLAN dari MikroTik selesai.
    <?php if ($text !== ''): ?>
      <div class="small mt-1"><?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed_sync'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal sinkron.</strong>
    <?php if ($text !== ''): ?>
      <div class="small mt-1"><?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'success_edit'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Berhasil.</strong> VLAN berhasil diperbarui.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'warning_edit'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    <strong>Peringatan.</strong> VLAN diperbarui sebagian.
    <?php if ($text !== ''): ?>
      <div class="small mt-1"><?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php elseif ($status === 'failed_edit'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Gagal.</strong> VLAN gagal diperbarui.
    <?php if ($text !== ''): ?>
      <div class="small mt-1"><?php echo htmlspecialchars($text); ?></div>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <span>Manajemen VLAN</span>
    <div class="d-flex gap-2">
      <a href="proses/sync_vlan.php" class="btn btn-warning btn-sm">
        <i class="fas fa-sync me-1"></i> Sync VLAN MikroTik
      </a>
      <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#addVlanModal">
        <i class="fas fa-plus me-1"></i> Tambah VLAN
      </button>
    </div>
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
            <th>Sumber</th>
            <th>Last Sync</th>
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
                  <?php if (($row['sync_source'] ?? '') === 'api_sync'): ?>
                    <span class="badge bg-primary">API Sync</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Manual Add</span>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($row['last_synced_at'] ?? '-'); ?></td>
                <td>
                  <div class="d-flex gap-1">
                    <button type="button" class="btn btn-warning btn-sm"
                            onclick='openEditVlanModal(<?php echo json_encode([
                              "id" => (int)$row["id"],
                              "vlan_id" => (int)$row["vlan_id"],
                              "keterangan" => (string)($row["keterangan"] ?? ""),
                              "pool_id" => (int)($row["pool_id"] ?? 0),
                              "interface_name" => (string)($row["interface_name"] ?? ""),
                              "brand" => (string)($row["BRAND"] ?? ""),
                              "area" => (string)($row["AREA"] ?? ""),
                              "has_ip" => !empty($row["ip_gateway"]),
                            ], JSON_UNESCAPED_UNICODE); ?>)'>
                      Edit
                    </button>
                    <a href="proses/delete_vlan.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Yakin ingin menghapus VLAN ini?');">
                      Hapus
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="12" class="text-center">Belum ada data VLAN</td>
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

          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" id="with_ip" name="with_ip" checked>
            <label class="form-check-label" for="with_ip">Aktifkan dengan IP Address</label>
            <div class="form-text">Kalau tidak dicentang, VLAN tetap dibuat &amp; diaktifkan di router tapi tanpa IP address (tanpa gateway).</div>
          </div>

          <div class="mb-3" id="pool_id_wrap">
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

<div class="modal fade" id="editVlanModal" tabindex="-1" aria-labelledby="editVlanModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editVlanModalLabel">Edit VLAN</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="proses/edit_vlan.php" method="POST">
        <div class="modal-body">
          <input type="hidden" name="id" id="edit_vlan_id_field">

          <div class="mb-3">
            <label class="form-label">VLAN / Interface / Server</label>
            <input type="text" class="form-control" id="edit_vlan_info" readonly disabled>
            <div class="form-text">VLAN, interface, dan server tidak bisa diubah di sini. Hapus lalu tambah ulang kalau perlu pindah interface/server.</div>
          </div>

          <div class="mb-3">
            <label for="edit_keterangan" class="form-label">Keterangan VLAN</label>
            <input type="text" class="form-control" id="edit_keterangan" name="keterangan" placeholder="Contoh: VLAN pelanggan area timur" required>
          </div>

          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" id="edit_with_ip" name="with_ip">
            <label class="form-check-label" for="edit_with_ip">Aktifkan dengan IP Address</label>
            <div class="form-text">Kalau tidak dicentang, IP address yang sudah ada di VLAN ini akan dilepas &mdash; VLAN tetap aktif tanpa IP.</div>
          </div>

          <div class="mb-3" id="edit_pool_id_wrap">
            <label for="edit_pool_id" class="form-label">IP Pool</label>
            <select class="form-select" id="edit_pool_id" name="pool_id">
              <option value="">-- Pilih Pool --</option>
              <?php foreach ($pools as $pool): ?>
                <option value="<?php echo (int)$pool['id']; ?>">
                  <?php echo htmlspecialchars($pool['pool_name'] . ' (' . $pool['ipawal'] . ' - ' . $pool['ipakhir'] . ')'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
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

// Toggle "Aktifkan dengan IP Address" -> tampilkan/wajibkan pilihan IP Pool hanya kalau dicentang
function bindWithIpToggle(checkboxEl, poolWrapEl, poolSelectEl) {
    if (!checkboxEl || !poolWrapEl || !poolSelectEl) return;
    function apply() {
        const on = checkboxEl.checked;
        poolWrapEl.classList.toggle('d-none', !on);
        poolSelectEl.required = on;
        if (!on) poolSelectEl.value = '';
    }
    checkboxEl.addEventListener('change', apply);
    apply();
}

bindWithIpToggle(
    document.getElementById('with_ip'),
    document.getElementById('pool_id_wrap'),
    document.getElementById('pool_id')
);
bindWithIpToggle(
    document.getElementById('edit_with_ip'),
    document.getElementById('edit_pool_id_wrap'),
    document.getElementById('edit_pool_id')
);

// Buka modal Edit VLAN + isi datanya
function openEditVlanModal(data) {
    document.getElementById('edit_vlan_id_field').value = data.id;
    document.getElementById('edit_vlan_info').value =
        'VLAN ' + data.vlan_id + ' - ' + data.interface_name + ' (' + (data.brand || '-') + '-' + (data.area || '-') + ')';
    document.getElementById('edit_keterangan').value = data.keterangan || '';

    const withIpCb = document.getElementById('edit_with_ip');
    const poolSelect = document.getElementById('edit_pool_id');
    withIpCb.checked = !!data.has_ip;
    poolSelect.value = data.pool_id ? String(data.pool_id) : '';
    withIpCb.dispatchEvent(new Event('change'));

    new bootstrap.Modal(document.getElementById('editVlanModal')).show();
}
</script>
