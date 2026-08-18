<?php
/**
 * Provisioning Approval - Billing owner approves/rejects provisioning from joblist
 */
require 'cek-sesi.php';
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Provisioning_Joblist', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Provisioning Joblist.</div></div>';
        require 'footer.php';
        exit;
    }
}


// Auto-install provisioning table
$check_table = mysqli_query($conn, "SHOW TABLES LIKE 'provisioning'");
if (mysqli_num_rows($check_table) == 0) {
    $create_sql = "CREATE TABLE `provisioning` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `idpel` VARCHAR(100) NOT NULL,
        `password_pppoe` VARCHAR(100) NOT NULL,
        `nik` VARCHAR(20) DEFAULT '',
        `nama` VARCHAR(200) NOT NULL,
        `alamat` TEXT,
        `provinsi` VARCHAR(100) DEFAULT '',
        `kabupaten` VARCHAR(100) DEFAULT '',
        `kecamatan` VARCHAR(100) DEFAULT '',
        `kelurahan` VARCHAR(100) DEFAULT '',
        `rw` VARCHAR(10) DEFAULT '',
        `rt` VARCHAR(10) DEFAULT '',
        `nowa` VARCHAR(50) DEFAULT '',
        `email` VARCHAR(200) DEFAULT '',
        `tikor` VARCHAR(100) DEFAULT '',
        `paket` VARCHAR(100) DEFAULT '',
        `harga` VARCHAR(50) DEFAULT '0',
        `server_pemilik` VARCHAR(100) NOT NULL,
        `server_brand` VARCHAR(100) DEFAULT '',
        `area` VARCHAR(100) DEFAULT '',
        `odp` VARCHAR(100) DEFAULT '',
        `auth_mode` VARCHAR(50) DEFAULT 'MULTI MODE',
        `tipe_bayar` VARCHAR(50) DEFAULT 'prabayar',
        `tipe_tempo` VARCHAR(100) DEFAULT 'mengikuti_tanggal_bayar',
        `sales` VARCHAR(100) DEFAULT '',
        `tanggal_pasang` DATE,
        `tiket_id` VARCHAR(100) DEFAULT '',
        `project_joblist` VARCHAR(100) DEFAULT '',
        `teknisi` VARCHAR(200) DEFAULT '',
        `status` ENUM('PENDING','APPROVED','REJECTED','EXPIRED') DEFAULT 'PENDING',
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `expired_at` DATETIME,
        `approved_at` DATETIME DEFAULT NULL,
        `approved_by` VARCHAR(100) DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_server (server_pemilik),
        INDEX idx_expired (expired_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    mysqli_query($conn, $create_sql);
}

// Auto-migrate kolom 'nik' utk tabel provisioning yg sudah ada sebelumnya.
$check_nik_col = mysqli_query($conn, "SHOW COLUMNS FROM provisioning LIKE 'nik'");
if ($check_nik_col && mysqli_num_rows($check_nik_col) == 0) {
    mysqli_query($conn, "ALTER TABLE provisioning ADD COLUMN `nik` VARCHAR(20) DEFAULT '' AFTER `password_pppoe`");
}

// Auto-expire provisioning older than 3 days
$expire_sql = "UPDATE provisioning SET status='EXPIRED' WHERE status='PENDING' AND expired_at < NOW()";
mysqli_query($conn, $expire_sql);

// Get filter status
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'PENDING';
$valid_statuses = ['PENDING', 'APPROVED', 'REJECTED', 'EXPIRED', 'ALL'];
if (!in_array($filter_status, $valid_statuses)) $filter_status = 'PENDING';

// Count per status for this owner
$status_counts = ['PENDING' => 0, 'APPROVED' => 0, 'REJECTED' => 0, 'EXPIRED' => 0];
$count_sql = "SELECT status, COUNT(*) as cnt FROM provisioning WHERE server_pemilik IN (SELECT PEMILIK FROM server WHERE user_id = " . intval($USER_ID) . ") GROUP BY status";
$count_result = mysqli_query($conn, $count_sql);
if ($count_result) {
    while ($crow = mysqli_fetch_assoc($count_result)) {
        if (isset($status_counts[$crow['status']])) {
            $status_counts[$crow['status']] = (int)$crow['cnt'];
        }
    }
}
$total_all = array_sum($status_counts);

// Fetch provisioning records
if ($filter_status == 'ALL') {
    $sql = "SELECT * FROM provisioning WHERE server_pemilik IN (SELECT PEMILIK FROM server WHERE user_id = " . intval($USER_ID) . ") ORDER BY created_at DESC";
} else {
    $sql = "SELECT * FROM provisioning WHERE server_pemilik IN (SELECT PEMILIK FROM server WHERE user_id = " . intval($USER_ID) . ") AND status = '" . mysqli_real_escape_string($conn, $filter_status) . "' ORDER BY created_at DESC";
}
$result = mysqli_query($conn, $sql);
?>

<style>
    .provisioning-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        justify-content: flex-end;
    }
    .provisioning-toolbar .provisioning-btn {
        border: 1px solid var(--bs-border-color);
        background: var(--bs-body-bg);
        color: var(--bs-body-color);
    }
    .provisioning-toolbar .provisioning-btn:hover,
    .provisioning-toolbar .provisioning-btn:focus {
        background: var(--bs-tertiary-bg, rgba(127, 127, 127, 0.12));
        color: var(--bs-body-color);
        border-color: var(--bs-primary);
    }
    .provisioning-toolbar .provisioning-btn.active {
        background: var(--bs-primary);
        border-color: var(--bs-primary);
        color: #fff;
    }
</style>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <!-- Status Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-sm-6 mb-3">
                    <a href="?status=PENDING" class="text-decoration-none">
                        <div class="card <?php echo $filter_status=='PENDING'?'border border-warning border-2':''; ?>">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Pending</p>
                                        <h5 class="font-weight-bolder mb-0"><?php echo $status_counts['PENDING']; ?></h5>
                                    </div>
                                    <div class="icon icon-shape bg-warning shadow text-center border-radius-md">
                                        <i class="fas fa-clock text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6 mb-3">
                    <a href="?status=APPROVED" class="text-decoration-none">
                        <div class="card <?php echo $filter_status=='APPROVED'?'border border-success border-2':''; ?>">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Approved</p>
                                        <h5 class="font-weight-bolder mb-0"><?php echo $status_counts['APPROVED']; ?></h5>
                                    </div>
                                    <div class="icon icon-shape bg-success shadow text-center border-radius-md">
                                        <i class="fas fa-check text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6 mb-3">
                    <a href="?status=REJECTED" class="text-decoration-none">
                        <div class="card <?php echo $filter_status=='REJECTED'?'border border-danger border-2':''; ?>">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Rejected</p>
                                        <h5 class="font-weight-bolder mb-0"><?php echo $status_counts['REJECTED']; ?></h5>
                                    </div>
                                    <div class="icon icon-shape bg-danger shadow text-center border-radius-md">
                                        <i class="fas fa-times text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
                <div class="col-xl-3 col-sm-6 mb-3">
                    <a href="?status=EXPIRED" class="text-decoration-none">
                        <div class="card <?php echo $filter_status=='EXPIRED'?'border border-secondary border-2':''; ?>">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <p class="text-sm mb-0 text-uppercase font-weight-bold">Expired</p>
                                        <h5 class="font-weight-bolder mb-0"><?php echo $status_counts['EXPIRED']; ?></h5>
                                    </div>
                                    <div class="icon icon-shape bg-secondary shadow text-center border-radius-md">
                                        <i class="fas fa-hourglass-end text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Main Table -->
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6><i class="fas fa-clipboard-check"></i> Provisioning Joblist 
                            <span class="badge bg-<?php echo $filter_status=='PENDING'?'warning text-dark':($filter_status=='APPROVED'?'success':($filter_status=='REJECTED'?'danger':'secondary')); ?>">
                                <?php echo $filter_status; ?>
                            </span>
                        </h6>
                    </div>
                    <div class="provisioning-toolbar">
                        <a href="?status=ALL" class="btn btn-sm provisioning-btn <?php echo $filter_status=='ALL'?'active':''; ?>">Semua (<?php echo $total_all; ?>)</a>
                        <a href="provisioning_settings.php" class="btn btn-sm provisioning-btn"><i class="fas fa-cog"></i> Pengaturan</a>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID Pelanggan</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Nama</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Server/Brand</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Paket</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Teknisi</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Tanggal</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Expired</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($result && mysqli_num_rows($result) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                <tr>
                                    <td class="ps-4">
                                        <span class="text-xs font-weight-bold"><?php echo htmlspecialchars($row['idpel']); ?></span>
                                    </td>
                                    <td>
                                        <span class="text-xs"><?php echo htmlspecialchars($row['nama']); ?></span>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['nowa']); ?></small>
                                    </td>
                                    <td>
                                        <span class="text-xs"><?php echo htmlspecialchars($row['server_brand']); ?></span>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['server_pemilik']); ?> - <?php echo htmlspecialchars($row['area']); ?></small>
                                    </td>
                                    <td><span class="text-xs"><?php echo htmlspecialchars($row['paket']); ?></span></td>
                                    <td><span class="text-xs"><?php echo htmlspecialchars($row['teknisi']); ?></span></td>
                                    <td><span class="text-xs"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></span></td>
                                    <td>
                                        
                                        <?php
                                        $exp = strtotime($row['expired_at']);
                                        $now = time();
                                        $remaining = $exp - $now;
                                        if ($row['status'] == 'PENDING' && $remaining > 0) {
                                            $days = floor($remaining / 86400);
                                            $hours = floor(($remaining % 86400) / 3600);
                                            echo "<span class='text-xs text-warning'>{$days}h {$hours}j</span>";
                                        } else {
                                            echo "<span class='text-xs text-muted'>" . date('d/m/Y', $exp) . "</span>";
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $badge_class = ['PENDING'=>'warning text-dark','APPROVED'=>'success','REJECTED'=>'danger','EXPIRED'=>'secondary'];
                                        $bc = $badge_class[$row['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $bc; ?>"><?php echo $row['status']; ?></span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="showDetail(<?php echo $row['id']; ?>)" title="Detail">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($row['status'] == 'PENDING'): ?>
                                        <button class="btn btn-sm btn-success" onclick="approveProvisioning(<?php echo $row['id']; ?>, '<?php echo addslashes($row['idpel']); ?>')" title="Approve">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button class="btn btn-sm btn-danger" onclick="rejectProvisioning(<?php echo $row['id']; ?>, '<?php echo addslashes($row['idpel']); ?>')" title="Tolak">
                                            <i class="fas fa-times"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($row['status'] == 'EXPIRED'): ?>
                                        <button class="btn btn-sm btn-warning" onclick="reactivateProvisioning(<?php echo $row['id']; ?>, '<?php echo addslashes($row['idpel']); ?>')" title="Aktifkan Ulang">
                                            <i class="fas fa-redo"></i> Aktifkan
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                <?php else: ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">Tidak ada data provisioning <?php echo $filter_status != 'ALL' ? "dengan status $filter_status" : ''; ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Approve Review Modal -->
<div class="modal fade" id="approveReviewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle"></i> Cek Data Sebelum Approve</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="approveReviewBody">
                <div class="text-center py-4"><div class="spinner-border"></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" id="approveReviewBtn" onclick="submitApproveWithReview()">
                    <i class="fas fa-check"></i> Approve Sekarang
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" style="max-width: 900px;">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detail Provisioning & Tiket Joblist</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailModalBody" style="max-height: 70vh;">
                <div class="text-center py-4"><div class="spinner-border"></div></div>
            </div>
            <div class="modal-footer" id="detailModalFooter"></div>
        </div>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" id="confirmHeader">
                <h5 class="modal-title" id="confirmTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirmBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn" id="confirmBtn" onclick="executeAction()">Konfirmasi</button>
            </div>
        </div>
    </div>
</div>

<script>
var pendingAction = null;

function escHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// detailModal punya tombol Approve/Tolak/Aktifkan Ulang di footer-nya yang membuka
// modal lain (approveReviewModal/confirmModal) tanpa menutup detailModal dulu.
// Bootstrap tidak menumpuk backdrop modal dengan rapi kalau dua modal terbuka
// bersamaan - hasilnya modal tampak tumpang tindih. Tutup detailModal dulu di sini
// sebelum modal berikutnya dibuka.
function hideModalIfShown(id) {
    var el = document.getElementById(id);
    var instance = bootstrap.Modal.getInstance(el);
    if (instance) instance.hide();
}

function showDetail(id) {
    var modal = new bootstrap.Modal(document.getElementById('detailModal'));
    document.getElementById('detailModalBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border"></div></div>';
    document.getElementById('detailModalFooter').innerHTML = '';
    modal.show();
    
    fetch('proses_provisioning_action.php?action=detail&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                var d = data.data;
                var html = '';
                
                // Evidence photos section
                if (d.evidence_photos && d.evidence_photos.length > 0) {
                    html += '<div class="mb-4">';
                    html += '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-images me-2 text-info"></i>Evidence Foto (dari Tiket Joblist)</h6>';
                    html += '<div class="row g-2">';
                    d.evidence_photos.forEach(function(photo) {
                        html += '<div class="col-md-4 col-sm-6">';
                        html += '<a href="' + escHtml(photo.url) + '" target="_blank" class="d-block">';
                        html += '<img src="' + escHtml(photo.url) + '" class="img-fluid border rounded" style="max-height: 150px; object-fit: cover;" alt="' + escHtml(photo.filename) + '" onerror="this.style.display=\'none\'">';
                        html += '</a>';
                        html += '<small class="text-muted d-block mt-1 text-center">' + escHtml(photo.filename) + '</small>';
                        html += '</div>';
                    });
                    html += '</div>';
                    html += '</div>';
                }
                
                // Joblist technical data section
                if (d.joblist_ticket) {
                    var jt = d.joblist_ticket;
                    html += '<div class="mb-4">';
                    html += '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-file-alt me-2 text-warning"></i>Data Teknis (dari Tiket Joblist)</h6>';
                    html += '<div class="card-body p-0">';
                    html += '<table class="table table-sm mb-0">';
                    
                    var joblist_fields = [
                        ['No. Tiket Joblist', jt.id],
                        ['Project', jt.project],
                        ['Tipe', jt.tipe],
                        ['Status', '<span class="badge bg-' + (jt.status === 'DONE' ? 'success' : jt.status === 'PENDING' ? 'warning' : jt.status === 'BARU' ? 'info' : 'secondary') + '">' + escHtml(jt.status) + '</span>'],
                        ['Team Teknis', jt.team],
                        ['Waktu Update', jt.waktu],
                        ['Deskripsi Teknis', jt.data]
                    ];
                    
                    joblist_fields.forEach(function(f) {
                        if (f[0] === 'Deskripsi Teknis') {
                            html += '<tr><td class="fw-bold align-top" style="width:30%">' + f[0] + '</td><td><small class="text-muted" style="white-space: pre-wrap; word-break: break-word;">' + escHtml(f[1]) + '</small></td></tr>';
                        } else {
                            html += '<tr><td class="fw-bold" style="width:30%">' + f[0] + '</td><td>' + f[1] + '</td></tr>';
                        }
                    });
                    
                    // Add report if exists
                    if (jt.report) {
                        html += '<tr><td class="fw-bold align-top">Laporan Teknis</td><td><small class="text-muted" style="white-space: pre-wrap; word-break: break-word;">' + escHtml(jt.report) + '</small></td></tr>';
                    }
                    
                    html += '</table>';
                    html += '</div>';
                    html += '</div>';
                }
                
                // Provisioning data section
                html += '<div class="mb-4">';
                html += '<h6 class="border-bottom pb-2 mb-3"><i class="fas fa-clipboard me-2 text-primary"></i>Data Provisioning</h6>';
                html += '<table class="table table-sm table-bordered">';
                var fields = [
                    ['ID Pelanggan', d.idpel], ['Password PPPoE', d.password_pppoe],
                    ['Nama', d.nama], ['Alamat', d.alamat],
                    ['Provinsi', d.provinsi], ['Kabupaten', d.kabupaten],
                    ['Kecamatan', d.kecamatan], ['Kelurahan', d.kelurahan],
                    ['RW', d.rw], ['RT', d.rt],
                    ['No WA', d.nowa], ['Email', d.email],
                    ['Koordinat', d.tikor], ['Paket', d.paket],
                    ['Harga', d.harga], ['Server', d.server_pemilik + ' (' + d.server_brand + ')'],
                    ['Area', d.area], ['ODP', d.odp],
                    ['Auth Mode', d.auth_mode], ['Tipe Bayar', d.tipe_bayar],
                    ['Tipe Tempo', d.tipe_tempo], ['Sales', d.sales],
                    ['Tanggal Pasang', d.tanggal_pasang], ['Tiket ID', d.tiket_id],
                    ['Project', d.project_joblist], ['Teknisi', d.teknisi],
                    ['Status', d.status], ['Dibuat', d.created_at],
                    ['Expired', d.expired_at]
                ];
                fields.forEach(function(f) {
                    html += '<tr><td class="fw-bold" style="width:35%">' + f[0] + '</td><td>' + (f[1] || '-') + '</td></tr>';
                });
                html += '</table>';
                html += '</div>';
                
                document.getElementById('detailModalBody').innerHTML = html;
                
                if (d.status === 'PENDING') {
                    document.getElementById('detailModalFooter').innerHTML = 
                        '<button class="btn btn-success" onclick="openApproveReview(' + d.id + ')"><i class="fas fa-check"></i> Approve</button>' +
                        '<button class="btn btn-danger" onclick="rejectProvisioning(' + d.id + ', \'' + escHtml(d.idpel) + '\')"><i class="fas fa-times"></i> Tolak</button>';
                } else if (d.status === 'EXPIRED') {
                    document.getElementById('detailModalFooter').innerHTML = 
                        '<button class="btn btn-warning" onclick="reactivateProvisioning(' + d.id + ', \'' + escHtml(d.idpel) + '\')"><i class="fas fa-redo"></i> Aktifkan Ulang</button>';
                }
            }
        });
}

function openApproveReview(id) {
    hideModalIfShown('detailModal');
    var modal = new bootstrap.Modal(document.getElementById('approveReviewModal'));
    var bodyEl = document.getElementById('approveReviewBody');
    var btn = document.getElementById('approveReviewBtn');
    bodyEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border"></div></div>';
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyiapkan...';
    modal.show();

    fetch('proses_provisioning_action.php?action=detail&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.data) {
                bodyEl.innerHTML = '<div class="alert alert-danger mb-0">Data tidak ditemukan.</div>';
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-check"></i> Approve Sekarang';
                return;
            }

            var d = data.data;
            var paketOptions = Array.isArray(d.paket_options) ? d.paket_options : [];
            var paketSelectHtml = '<option value="">Pilih paket</option>';
            paketOptions.forEach(function(paketName) {
                var selected = String(d.paket || '') === String(paketName) ? ' selected' : '';
                paketSelectHtml += '<option value="' + escHtml(paketName) + '"' + selected + '>' + escHtml(paketName) + '</option>';
            });
            if (!paketOptions.length && d.paket) {
                paketSelectHtml += '<option value="' + escHtml(d.paket) + '" selected>' + escHtml(d.paket) + '</option>';
            }

            bodyEl.innerHTML =
                '<form id="approveReviewForm">' +
                    '<input type="hidden" name="id" value="' + escHtml(d.id) + '">' +
                    '<input type="hidden" name="idpel" value="' + escHtml(d.idpel) + '">' +
                    '<div class="alert alert-light border text-sm">Silakan cek dan ubah data jika diperlukan, lalu klik <strong>Approve Sekarang</strong>.</div>' +
                    '<div class="mb-3">' +
                        '<div><strong>ID Pelanggan:</strong> ' + escHtml(d.idpel) + '</div>' +
                        '<div class="text-muted small"><strong>Server:</strong> ' + escHtml(d.server_pemilik || '-') + ' | <strong>Area:</strong> ' + escHtml(d.area || '-') + '</div>' +
                    '</div>' +
                    '<div class="row g-3">' +
                        '<div class="col-md-6"><label class="form-label">Nama</label><input type="text" class="form-control" name="nama" value="' + escHtml(d.nama) + '"></div>' +
                        '<div class="col-md-6"><label class="form-label">No WA</label><input type="text" class="form-control" name="nowa" value="' + escHtml(d.nowa) + '"></div>' +
                        '<div class="col-md-6"><label class="form-label">Email</label><input type="text" class="form-control" name="email" value="' + escHtml(d.email) + '"></div>' +
                        '<div class="col-md-6"><label class="form-label">Tanggal Pasang</label><input type="date" class="form-control" name="tanggal_pasang" value="' + escHtml(d.tanggal_pasang) + '"></div>' +
                        '<div class="col-md-6"><label class="form-label">Tipe Bayar</label><select class="form-select" name="tipe_bayar"><option value="prabayar"' + (d.tipe_bayar === 'prabayar' ? ' selected' : '') + '>prabayar</option><option value="pascabayar"' + (d.tipe_bayar === 'pascabayar' ? ' selected' : '') + '>pascabayar</option></select></div>' +
                        '<div class="col-md-6"><label class="form-label">Tipe Tempo</label><select class="form-select" name="tipe_tempo"><option value="mengikuti_tanggal_bayar"' + (d.tipe_tempo === 'mengikuti_tanggal_bayar' ? ' selected' : '') + '>mengikuti_tanggal_bayar</option><option value="mengikuti_tanggal_tempo"' + (d.tipe_tempo === 'mengikuti_tanggal_tempo' ? ' selected' : '') + '>mengikuti_tanggal_tempo</option></select></div>' +
                        '<div class="col-md-6"><label class="form-label">Koordinat</label><input type="text" class="form-control" name="tikor" value="' + escHtml(d.tikor) + '"></div>' +
                        '<div class="col-md-6"><label class="form-label">Paket</label><select class="form-select" name="paket">' + paketSelectHtml + '</select></div>' +
                        '<div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="alamat" rows="2">' + escHtml(d.alamat) + '</textarea></div>' +
                    '</div>' +
                '</form>';

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Approve Sekarang';
        })
        .catch(() => {
            bodyEl.innerHTML = '<div class="alert alert-danger mb-0">Gagal mengambil data provisioning.</div>';
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-check"></i> Approve Sekarang';
        });
}

function submitApproveWithReview() {
    var form = document.getElementById('approveReviewForm');
    if (!form) return;

    var btn = document.getElementById('approveReviewBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Memproses...';

    var fd = new FormData(form);
    pendingAction = {
        action: 'approve',
        id: fd.get('id') || '',
        idpel: fd.get('idpel') || '',
        updates: {
            nama: fd.get('nama') || '',
            nowa: fd.get('nowa') || '',
            email: fd.get('email') || '',
            tanggal_pasang: fd.get('tanggal_pasang') || '',
            paket: fd.get('paket') || '',
            tipe_bayar: fd.get('tipe_bayar') || '',
            tipe_tempo: fd.get('tipe_tempo') || '',
            tikor: fd.get('tikor') || '',
            alamat: fd.get('alamat') || ''
        }
    };

    executeAction(btn);
}

function approveProvisioning(id, idpel) {
    openApproveReview(id);
}

function rejectProvisioning(id, idpel) {
    hideModalIfShown('detailModal');
    pendingAction = {action: 'reject', id: id, idpel: idpel};
    document.getElementById('confirmHeader').className = 'modal-header bg-danger text-white';
    document.getElementById('confirmTitle').textContent = 'Tolak Provisioning';
    document.getElementById('confirmBody').innerHTML = 'Anda yakin ingin menolak provisioning <strong>' + idpel + '</strong>?<br><small class="text-muted">Secret PPPoE akan dihapus dari MikroTik/RADIUS.</small>';
    document.getElementById('confirmBtn').className = 'btn btn-danger';
    document.getElementById('confirmBtn').textContent = 'Ya, Tolak';
    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
}

function reactivateProvisioning(id, idpel) {
    hideModalIfShown('detailModal');
    pendingAction = {action: 'reactivate', id: id, idpel: idpel};
    document.getElementById('confirmHeader').className = 'modal-header bg-warning text-dark';
    document.getElementById('confirmTitle').textContent = 'Aktifkan Ulang Provisioning';
    document.getElementById('confirmBody').innerHTML = 'Aktifkan ulang provisioning <strong>' + idpel + '</strong>?<br><small class="text-muted">Secret PPPoE akan dibuat kembali di MikroTik/RADIUS dan masa berlaku diperpanjang 3 hari.</small>';
    document.getElementById('confirmBtn').className = 'btn btn-warning';
    document.getElementById('confirmBtn').textContent = 'Ya, Aktifkan Ulang';
    var modal = new bootstrap.Modal(document.getElementById('confirmModal'));
    modal.show();
}

function executeAction(customButton) {
    if (!pendingAction) return;
    var btn = customButton || document.getElementById('confirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Memproses...';
    
    var formData = new FormData();
    formData.append('action', pendingAction.action);
    formData.append('id', pendingAction.id);
    if (pendingAction.action === 'approve' && pendingAction.updates) {
        Object.keys(pendingAction.updates).forEach(function(key) {
            formData.append(key, pendingAction.updates[key]);
        });
    }
    
    fetch('proses_provisioning_action.php', {method: 'POST', body: formData})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                var msg = data.message;
                // Tampilkan juga status kirim notif WA registrasi (berhasil/
                // gagal + alasannya) -- SEBELUMNYA admin sama sekali tidak
                // tahu apakah notif ke pelanggan terkirim atau tidak saat approve.
                if (data.notif_registrasi && data.notif_registrasi.attempted) {
                    msg += data.notif_registrasi.success
                        ? '\n\n✅ Notif WA registrasi berhasil dikirim ke ' + (data.notif_registrasi.nowa || 'pelanggan') + '.'
                        : '\n\n⚠️ Notif WA registrasi GAGAL terkirim: ' + (data.notif_registrasi.message || 'tidak diketahui penyebabnya');
                }
                alert(msg);
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Gagal memproses'));
                btn.disabled = false;
                btn.innerHTML = customButton ? '<i class="fas fa-check"></i> Approve Sekarang' : 'Konfirmasi';
            }
        })
        .catch(err => {
            alert('Error koneksi');
            btn.disabled = false;
            btn.innerHTML = customButton ? '<i class="fas fa-check"></i> Approve Sekarang' : 'Konfirmasi';
        });
}

// Styling untuk modal dan evidence foto
document.addEventListener('DOMContentLoaded', function() {
    var style = document.createElement('style');
    style.textContent = `
        #detailModalBody .card {
            border: 1px solid #e9ecef;
            background-color: #f8f9fa;
        }
        
        #detailModalBody .card-body {
            padding: 0;
        }
        
        #detailModalBody h6 {
            font-weight: 600;
            font-size: 0.95rem;
            color: #2c3e50;
            margin-bottom: 1rem;
        }
        
        #detailModalBody .row.g-2 {
            margin-bottom: 1rem;
        }
        
        #detailModalBody .col-md-4 {
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        #detailModalBody .col-md-4:hover img {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        #detailModalBody .img-fluid {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        #detailModalBody .table.table-sm {
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }
        
        #detailModalBody .table tbody tr:hover {
            background-color: #f1f5f9;
        }
        
        #detailModalBody .fw-bold {
            color: #2c3e50;
            font-weight: 600;
        }
        
        #detailModalBody .text-muted {
            color: #6c757d !important;
            font-size: 0.85rem;
            line-height: 1.5;
        }
        
        #detailModalBody .badge {
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
        }
    `;
    document.head.appendChild(style);
});
</script>

<?php require 'footer.php'; ?>
