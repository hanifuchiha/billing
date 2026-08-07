
<?php
require 'header.php';

// --- AUTO ALTER TABLE: Tambah field komisi_nominal jika belum ada ---
function ensure_column_exists($conn, $table, $column, $type) {
    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    if (mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "ALTER TABLE `$table` ADD `$column` $type DEFAULT 0");
    }
}
ensure_column_exists($conn, 'paket', 'komisi_nominal', 'DOUBLE(18,2)');
ensure_column_exists($conn, 'paket_hotspot', 'komisi_nominal', 'DOUBLE(18,2)');
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <!-- Tab Navigation -->
            <style>
                .nav-tabs .nav-link {
                    color: #212529 !important;
                    font-weight: 600;
                    background: #f8f9fa;
                    border: 1px solid #dee2e6;
                }
                .nav-tabs .nav-link.active {
                    color: #0d6efd !important;
                    background: #fff !important;
                    border-bottom: 2px solid #0d6efd;
                }
            </style>
            <ul class="nav nav-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link active d-flex align-items-center gap-2" aria-current="page" href="commissionsetting_nominal.php">
                        <span style="font-size:1.2em;">💰</span>
                        <span>Komisi Nominal<br><small class="text-muted">(Rupiah tetap per transaksi)</small></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link d-flex align-items-center gap-2" href="commissionsetting.php">
                        <span style="font-size:1.2em;">📊</span>
                        <span>Komisi Persen<br><small class="text-muted">(Persentase dari harga jual)</small></span>
                    </a>
                </li>
            </ul>
            <!-- Filter Search Card -->
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>Pencarian Data Paket (Komisi Nominal)</h6>
                </div>
                <div class="card-body py-2">
                    <input type="text" id="searchInputNominal" class="form-control form-control-sm" placeholder="🔍 Cari paket, area, product, harga ..." onkeyup="filterTableRowsNominal()">
                </div>
            </div>
            <!-- Card PPPoE Nominal -->
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>Packages PPPoE (Komisi Nominal)</h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <form method="POST">
                            <input type="hidden" name="mode" value="pppoe_nominal_all">
                            <table class="table align-items-center mb-0" style="font-size: 10px;">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Name Packages</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Komisi (Nominal)</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Harga Jual</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Product (Area)</th>
                                    </tr>
                                </thead>
                                <tbody id="dataTableNominal">
                                <?php
                                if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['mode'] === 'pppoe_nominal_all') {
                                    $ids = $_POST['id_paket'];
                                    $komisis = $_POST['komisi_nominal'];
                                    foreach ($ids as $index => $id) {
                                        $komisi = floatval($komisis[$index]);
                                        mysqli_query($conn, "UPDATE paket SET komisi_nominal = '$komisi' WHERE id = '$id'");
                                    }
                                    header("Location: commissionsetting_nominal.php?komisi=pppoe_saved");
                                    exit;
                                }
                                if($AKSES !='ASSISTANT') {
                                    $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                                    $queryServerId = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = $current_user_id");
                                    $userServerIds = [];
                                    $userAreaList = [];
                                    while($row = mysqli_fetch_assoc($queryServerId)) {
                                        $userServerIds[] = "'".$row['PEMILIK']."'";
                                        $userAreaList[] = "'".$row['AREA']."'";
                                    }
                                    $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
                                    $userAreaListStr = count($userAreaList) > 0 ? implode(",", $userAreaList) : "''";
                                    $sql = "SELECT * FROM paket WHERE PEMILIK IN ($userServerList) AND AREA IN ($userAreaListStr)";
                                } else {
                                    $sql = "SELECT * FROM paket WHERE PEMILIK IN ($server_list) and AREA IN ($area_list)";
                                }
                                $query = mysqli_query($conn, $sql);
                                if (mysqli_num_rows($query) == 0) {
                                    echo "<tr><td colspan='4' class='text-center'>Tidak ada data ditemukan</td></tr>";
                                }
                                while ($data = mysqli_fetch_array($query)) {
                                    echo '<tr>';
                                    echo '<td class="align-middle text-center text-sm" data-label="Name Packages">';
                                    echo '<div><img src="packages.png" class="avatar avatar-sm me-3" alt="Server"></div>';
                                    echo '<div class="d-flex flex-column justify-content-center"><h6 class="mb-0 text-sm">'.htmlspecialchars($data['PAKET']).'</h6></div>';
                                    echo '</td>';
                                    echo '<td class="align-middle text-center" data-label="Komisi (Nominal)">';
                                    echo '<input type="hidden" name="id_paket[]" value="'.htmlspecialchars($data['id']).'">';
                                    echo '<input type="number" step="0.01" min="0" name="komisi_nominal[]" value="'.htmlspecialchars($data['komisi_nominal'] ?? '').'" class="form-control form-control-sm w-100" required>';
                                    echo '</td>';
                                    echo '<td class="align-middle text-center text-sm" data-label="Harga Jual"><span class="text-xs font-weight-bold">'.htmlspecialchars($data['HARGA']).'</span></td>';
                                    echo '<td class="align-middle text-center text-sm" data-label="Product (Area)"><span class="text-xs font-weight-bold">'.htmlspecialchars($data['PEMILIK']).' ('.htmlspecialchars($data['AREA']).')</span></td>';
                                    echo '</tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                            <div class="mt-3 text-center">
                                <button type="submit" class="btn btn-primary btn-sm">💾 Simpan Semua</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Card Hotspot Nominal -->
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>Packages Hotspot (Komisi Nominal)</h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <form method="POST">
                            <input type="hidden" name="mode" value="hotspot_nominal_all">
                            <table class="table align-items-center mb-0" style="font-size: 10px;">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Packages name</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Komisi (Nominal)</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Prize</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Product (Area)</th>
                                    </tr>
                                </thead>
                                <tbody id="dataTableNominal">
                                <?php
                                if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['mode'] === 'hotspot_nominal_all') {
                                    $ids = $_POST['idhs'];
                                    $komisis = $_POST['komisi_nominal'];
                                    foreach ($ids as $index => $id) {
                                        $komisi = floatval($komisis[$index]);
                                        $update = mysqli_query($conn, "UPDATE paket_hotspot SET komisi_nominal = '$komisi' WHERE id = '$id'");
                                    }
                                    header("Location: commissionsetting_nominal.php?komisi=all_saved");
                                }
                                if($AKSES !='ASSISTANT') {
                                    $current_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
                                    $queryServerId = mysqli_query($conn, "SELECT PEMILIK, AREA FROM server WHERE user_id = $current_user_id");
                                    $userServerIds = [];
                                    $userAreaList = [];
                                    while($row = mysqli_fetch_assoc($queryServerId)) {
                                        $userServerIds[] = "'".$row['PEMILIK']."'";
                                        $userAreaList[] = "'".$row['AREA']."'";
                                    }
                                    $userServerList = count($userServerIds) > 0 ? implode(",", $userServerIds) : "''";
                                    $userAreaListStr = count($userAreaList) > 0 ? implode(",", $userAreaList) : "''";
                                    $sql = "SELECT * FROM paket_hotspot WHERE pemilik IN ($userServerList) AND area IN ($userAreaListStr)";
                                } else {
                                    $sql = "SELECT * FROM paket_hotspot WHERE pemilik IN ($server_list) and area IN ($area_list)";
                                }
                                $query = mysqli_query($conn, $sql);
                                if (mysqli_num_rows($query) == 0) {
                                    echo "<tr><td colspan='4' class='text-center'>Tidak ada data ditemukan</td></tr>";
                                }
                                while ($data = mysqli_fetch_array($query)) {
                                    echo '<tr>';
                                    echo '<td class="align-middle text-center text-sm" data-label="Packages name">';
                                    echo '<div><img src="packages.png" class="avatar avatar-sm me-3" alt="Server"></div>';
                                    echo '<div class="d-flex flex-column justify-content-center"><h6 class="mb-0 text-sm">'.htmlspecialchars($data['paket']).'</h6></div>';
                                    echo '</td>';
                                    echo '<td class="align-middle text-center" data-label="Komisi (Nominal)">';
                                    echo '<input type="hidden" name="idhs[]" value="'.htmlspecialchars($data['id']).'">';
                                    echo '<input type="number" step="0.01" min="0" name="komisi_nominal[]" value="'.htmlspecialchars($data['komisi_nominal'] ?? '').'" class="form-control form-control-sm w-100" required>';
                                    echo '</td>';
                                    echo '<td class="align-middle text-center text-sm" data-label="Prize"><span class="text-xs font-weight-bold">'.htmlspecialchars($data['harga']).'</span></td>';
                                    echo '<td class="align-middle text-center text-sm" data-label="Product (Area)"><span class="text-xs font-weight-bold">'.htmlspecialchars($data['pemilik']).' ('.htmlspecialchars($data['area']).')</span></td>';
                                    echo '</tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                            <div class="mt-3 text-center">
                                <button type="submit" class="btn btn-primary btn-sm">💾 Simpan Semua</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
function filterTableRowsNominal() {
    var input = document.getElementById("searchInputNominal");
    var filter = input.value.toUpperCase();
    var tables = document.querySelectorAll("#dataTableNominal");
    tables.forEach(function(table) {
        var tr = table.getElementsByTagName("tr");
        for (var i = 0; i < tr.length; i++) {
            var td = tr[i].getElementsByTagName("td");
            var found = false;
            for (var j = 0; j < td.length; j++) {
                if (td[j]) {
                    var txtValue = td[j].textContent || td[j].innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
            }
            tr[i].style.display = found ? "" : "none";
        }
    });
}
// Prevent non-numeric character in komisi_nominal input
document.addEventListener('DOMContentLoaded', function() {
    var komisiInputs = document.querySelectorAll("input[name='komisi_nominal[]']");
    komisiInputs.forEach(function(input) {
        input.addEventListener('keypress', function(e) {
            // Allow: backspace, delete, tab, escape, enter, dot, and numbers
            if (
                [8,9,13,27,46].indexOf(e.keyCode) !== -1 || // special keys
                (e.key >= '0' && e.key <= '9') ||
                e.key === '.'
            ) {
                return;
            }
            e.preventDefault();
        });
        input.addEventListener('input', function(e) {
            // Only allow numbers and dot
            this.value = this.value.replace(/[^0-9.]/g, '');
        });
        input.addEventListener('paste', function(e) {
            var paste = (e.clipboardData || window.clipboardData).getData('text');
            if (/[^0-9.]/.test(paste)) {
                e.preventDefault();
            }
        });
    });
});
</script>
                    komisiInputs.forEach(function(input) {
                        input.addEventListener('keypress', function(e) {
                            if (e.key === '%') {
                                e.preventDefault();
                            }
                        });
                        input.addEventListener('input', function(e) {
                            this.value = this.value.replace(/%/g, '');
                        });
                    });
                });
                </script>
