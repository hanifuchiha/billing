
<?php require 'header.php';
require 'routeros_api.class.php';
?>
<?php if (isset($_GET['statusnotif'])): ?>
  <?php if ($_GET['statusnotif'] == 'edited'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      ✅ Paket berhasil diperbarui!
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['statusnotif'] == 'edit_failed'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      ❌ Gagal memperbarui paket.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php elseif ($_GET['msg'] == 'deleted'): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      🗑️ Paket berhasil dihapus.
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>
<?php endif; ?>


    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">


                <div class="card">

                    <div class="card-body">

                        <label for="meta-tag" class="mt-4">Kode Redirect Otomatis (Meta Refresh):</label>
                        <textarea id="meta-tag" class="form-control" rows="3" readonly>
<meta http-equiv="refresh" content="5;url=<?php echo $domain; ?>/crm/login/">
    </textarea>




                        <button onclick="copyMetaTag()" class="btn btn-primary mt-2">Salin Kode</button>
                        <small id="copy-meta-success" class="form-text text-success mt-1" style="display:none;">Kode disalin!</small>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6>Packages table</h6>



                        <!-- Menambahkan Clipboard.js untuk salin domain -->
                        <script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.6/clipboard.min.js"></script>
                        <script>
                            // Inisialisasi clipboard.js
                            var clipboard = new ClipboardJS('#copyButton');

                            clipboard.on('success', function(e) {
                                alert('Domain berhasil disalin: ' + e.text);
                            });

                            clipboard.on('error', function(e) {
                                alert('Gagal menyalin domain.');
                            });
                        </script>









                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
                            Add Packages
                        </button>

                        <a href="import_hotspot.php" class="btn btn-success">
                            <i class="fas fa-file-excel me-1"></i> Import Paket Hotspot dari Excel
                        </a>

                        <a href="proses/export_hotspot.php" class="btn btn-info">
                            <i class="fas fa-file-excel me-1"></i> Export Paket Hotspot ke Excel
                        </a>




                        <!-- Modal -->
                        <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="dataModalLabel">Add Packages</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form id="dataForm" action="proses/addpackageshotspot.php" method="post">



                                            <div class="mb-3">
                                                <label for="profileName" class="form-label">Packages name</label>
                                                <input type="text" class="form-control" id="profileName" name="profileName" required>
                                                <small id="profileNameError" class="text-danger" style="display:none;">Nama paket tidak boleh mengandung spasi.</small>
                                            </div>

                                            <div class="mb-3">
                                                <label for="rateLimit" class="form-label">uptime</label>
                                                <input type="text" class="form-control" id="uptime" name="uptime" placeholder="">
                                                <!-- Penjelasan -->
                                                <div class="alert alert-info mt-2">
                                                    <strong>Format Uptime:</strong>
                                                    <ul class="mb-0 mt-2">
                                                        <li><b>1h</b> → 1 Jam</li>
                                                        <li><b>1d</b> → 1 Hari</li>
                                                        <li><b>1w</b> → 1 Minggu</li>
                                                        <li><b>1m</b> → 1 Bulan</li>
                                                    </ul>
                                                    <small class="text-muted">Format harus sesuai (huruf kecil) agar sistem dapat membacanya dengan benar.</small>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label for="rateLimit" class="form-label">Harga jual</label>
                                                <input type="number" class="form-control" id="harga" name="harga" placeholder="">
                                                <!-- Penjelasan -->
                                                <div class="alert alert-info mt-2">
                                                    <strong>Contoh Format Harga:</strong>
                                                    <ul class="mb-0 mt-2">
                                                        <li><b>Rp 10.000</b> → 10000</li>
                                                        <li><b>Rp 20.000</b> → 20000</li>
                                                    </ul>
                                                    <small class="text-muted">Masukkan angka tanpa titik atau koma (contoh: 10000 untuk Rp 10.000).</small>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label for="rateLimit" class="form-label">Komisi sales ( % )</label>
                                                <input type="number" class="form-control" id="komisi" name="komisi" placeholder="">
                                                <!-- Penjelasan -->
                                                <div class="alert alert-info mt-2">
                                                    <strong>Contoh Format Komisi:</strong>
                                                    <ul class="mb-0 mt-2">
                                                        <li><b>10%</b> → 10</li>
                                                        <li><b>20%</b> → 20</li>
                                                    </ul>
                                                    <small class="text-muted">Masukkan angka persen tanpa simbol % (contoh: 10 untuk 10%).</small>
                                                </div>
                                            </div>
                                            <div class="mb-3">
                                                <label for="rateLimit" class="form-label">Rate Limit speed</label>
                                                <input type="text" class="form-control" id="ratelimit" name="ratelimit" placeholder="e.g., 10M/10M">
                                            </div>

                                            <div class="mb-3">
                                                <label for="server" class="form-label">Server</label>
                                                   <select required class="form-control" id="server" name="server" onchange="loadArea()">
                                                                                                                <option value="">-- Pilih Server --</option>
                                                                                                               <?php
                                                        // Ambil semua brand dan area, gabungkan area per brand
                                                        $query = "SELECT BRAND, AREA, PEMILIK FROM server WHERE `pemilik` IN ($server_list)";
                                                        $result = mysqli_query($conn, $query);
                                                        $brand_area_map = [];
                                                        while ($row = mysqli_fetch_assoc($result)) {
                                                            $brand = trim($row['BRAND']);
                                                            $area = trim($row['AREA']);
                                                            $pemilik = trim($row['PEMILIK']);
                                                            if (!isset($brand_area_map[$brand])) {
                                                                $brand_area_map[$brand] = [
                                                                    'areas' => [],
                                                                    'pemilik' => $pemilik
                                                                ];
                                                            }
                                                            if (!in_array($area, $brand_area_map[$brand]['areas'])) {
                                                                $brand_area_map[$brand]['areas'][] = $area;
                                                            }
                                                        }
                                                        foreach ($brand_area_map as $brand => $data) {
                                                            echo '<option value="' . htmlspecialchars($data['pemilik'], ENT_QUOTES, 'UTF-8') . '" data-areas="' . htmlspecialchars(implode(',', $data['areas']), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '</option>';
                                                        }
                                                        ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <!-- <label for="area" class="form-label">AREA</label> -->
                                    <select  required class="form-control" id="area" name="area" onchange="loadODP()">
                                                                            <option value="">-- Pilih AREA --</option>
                                    </select>
                                </div>

  <script>
                                                            function loadArea() {
                                                                const serverSelect = document.getElementById("server");
                                                                const areaDropdown = document.getElementById("area");
                                                                const odpDropdown = document.getElementById("odp");
                                                                const packageDropdown = document.getElementById("packages");

                                                                // Reset dropdown isi
                                                                if (areaDropdown) areaDropdown.innerHTML = '<option value="">-- Pilih AREA --</option>';
                                                                if (odpDropdown) odpDropdown.innerHTML = '<option value="">Loading...</option>';
                                                                if (packageDropdown) packageDropdown.innerHTML = '<option value="">Loading...</option>';

                                                                const selectedOption = serverSelect.options[serverSelect.selectedIndex];
                                                                const areas = selectedOption.getAttribute('data-areas');
                                                                if (areas) {
                                                                    const areaArr = areas.split(',');
                                                                    areaArr.forEach(function(area) {
                                                                        const opt = document.createElement('option');
                                                                        opt.value = area;
                                                                        opt.textContent = area;
                                                                        areaDropdown.appendChild(opt);
                                                                    });
                                                                }
                                                            }
                            </script>




                                        </form>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                        <button type="submit" class="btn btn-primary" form="dataForm">Simpan</button>
                                    </div>
                                </div>
                            </div>
                        </div>









                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">


                            <style>
                                /* Menyembunyikan kolom tertentu saat tampilan mobile */
                                @media screen and (max-width: 600px) {

                                    td:nth-child(3),
                                    th:nth-child(3),
                                    td:nth-child(4),
                                    th:nth-child(4) {
                                        display: none;
                                    }
                                }

                                .small-text {
                                    font-size: 8px;
                                }

                                .alert-info ul li {
                                    font-weight: bold;
                                    color: #0c5460;
                                }

                                .alert-info small {
                                    font-style: italic;
                                    color: #6c757d;
                                }
                            </style>

                            <table id="tabel-hotspot" class="table align-items-center mb-0" style="font-size: 10px;">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Packages name</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Uptime</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Harga jual</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Area</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Server Area</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">limit speed</th>
                                    </tr>
                                </thead>

                                <tbody id="dataTable">
                                    <?php




                                    $API = new RouterosAPI();





                                    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
                                        $id = intval($_POST['id']);
                                        $pemilik = $_POST['pemilik'];
                                        $area = $_POST['area'];
                                        $paket = $_POST['paket'];


                                        // Cek apakah data ada
                                        $check = mysqli_query($conn, "SELECT * FROM paket_hotspot WHERE id = $id");
                                        if (mysqli_num_rows($check) > 0) {
                                            $delete = mysqli_query($conn, "DELETE FROM paket_hotspot WHERE id = $id");
                                            if ($delete) {



                                                $sql = "SELECT * FROM `server` WHERE  `pemilik`='$pemilik' and `area`='$area'";



                                                $query = mysqli_query($conn, $sql);
                                                while ($data = mysqli_fetch_array($query)) {
                                                    $username1 = $data['PEMILIK'];
                                                    $host1   = $data['IP'];
                                                    $password1 = $data['PASSWORD'];



                                                    if ($API->connect($host1, $username1, $password1)) {
                                                        // Ambil ID profile berdasarkan nama
                                                        $profiles = $API->comm("/ip/hotspot/user/profile/print", [
                                                            "?name" => $paket
                                                        ]);

                                                        if (!empty($profiles)) {
                                                            $profileId = $profiles[0][".id"];

                                                            // Hapus profile
                                                            $API->comm("/ip/hotspot/user/profile/remove", [
                                                                ".id" => $profileId
                                                            ]);
                                                        }

                                                        $API->disconnect();
                                                    }
                                                }





                                                header("Location: packageshotspot.php?msg=deleted"); // Ganti dengan halaman utama

                                            } else {
                                            }
                                        } else {
                                        }
                                    }



     if($AKSES !='ASSISTANT')
                  {
                      $sql = "SELECT * FROM paket_hotspot WHERE `pemilik` IN ($server_list)";
                  }
                  else
                    {
                      $sql = "SELECT * FROM paket_hotspot WHERE `pemilik` IN ($server_list) and `area` IN ($area_list)";
                    }


            
                                  
                                    $query = mysqli_query($conn, $sql);
                                    while ($data = mysqli_fetch_array($query)) {
                                        echo '<tr>';
                                        echo '<td class="align-middle text-center text-sm">
                                            <div>
                                                <img src="packages.png" class="avatar avatar-sm me-3" alt="Server">
                                            </div>
                                            <div class="d-flex flex-column justify-content-center">
                                                <h6 class="mb-0 text-sm">' . htmlspecialchars($data['paket']) . '</h6>
                                            </div>
                                        </td>';
                                        echo '<td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold">' . htmlspecialchars($data['uptime']) . '</span></td>';
                                        echo '<td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold">' . htmlspecialchars($data['harga']) . '</span></td>';
                                        echo '<td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold">' . htmlspecialchars($data['area']) . '</span></td>';
                                        echo '<td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold">' . htmlspecialchars($data['BRAND']) . '</span></td>';
                                        echo '<td class="align-middle text-center text-sm"><span class="text-xs font-weight-bold">' . htmlspecialchars($data['ratelimit']) . '</span>';
                                        // Action buttons (Edit + Delete) in one cell
                                        echo '<br><button class="btn btn-warning btn-sm" onclick=\'openEditModal(' . json_encode($data) . ')\'><i class="fas fa-edit"></i> Edit</button>';
                                        echo '<form method="POST" style="display:inline-block; margin-left:4px;" onsubmit="return confirm(\'Yakin ingin menghapus paket ini?\');">';
                                        echo '<input type="hidden" name="id" value="' . htmlspecialchars($data['id']) . '">';
                                        echo '<input type="hidden" name="paket" value="' . htmlspecialchars($data['paket']) . '">';
                                        echo '<input type="hidden" name="area" value="' . htmlspecialchars($data['area']) . '">';
                                        echo '<input type="hidden" name="pemilik" value="' . htmlspecialchars($data['pemilik']) . '">';
                                        echo '<button type="submit" class="btn btn-sm btn-danger">Delete</button>';
                                        echo '</form></td>';
                                        echo '</tr>';
                                        $ip = $data['id'];
                                        setcookie('id', $ip);
                                    }
                                    ?>
                                </tbody>
                            </table>



                        </div>
                    </div>
                </div>
            </div>
        </div>




<!-- Modal Edit -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="editModalLabel">Edit Packages</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <form id="editForm" action="proses/editpackageshotspot.php" method="post">
          <input type="hidden" id="edit_id" name="id">

          <div class="mb-3">
            <label for="profileName" class="form-label">Packages name</label>
                        <input type="text" class="form-control" id="edit_profileName" name="paket" required>
                        <small id="editProfileNameError" class="text-danger" style="display:none;">Nama paket tidak boleh mengandung spasi.</small>
          </div>

          <div class="mb-3">
            <label for="edit_uptime" class="form-label">Uptime</label>
            <input type="text" class="form-control" id="edit_uptime" name="uptime" placeholder="">
            <div class="alert alert-info mt-2">
              <strong>Format Waktu:</strong>
              <ul class="mb-0 mt-2">
                <li><b>1h</b> → 1 Jam</li>
                <li><b>1d</b> → 1 Hari</li>
                <li><b>1w</b> → 1 Minggu</li>
                <li><b>1m</b> → 1 Bulan</li>
              </ul>
              <small class="text-muted">Format harus sesuai (huruf kecil).</small>
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_harga" class="form-label">Harga jual</label>
            <input type="number" class="form-control" id="edit_harga" name="harga" placeholder="">
            <div class="alert alert-info mt-2">
              <strong>Contoh:</strong>
              <ul class="mb-0 mt-2">
                <li>Rp 10.000 → 10000</li>
                <li>Rp 20.000 → 20000</li>
              </ul>
              <small class="text-muted">Masukkan angka tanpa titik atau koma.</small>
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_komisi" class="form-label">Komisi sales (%)</label>
            <input type="number" class="form-control" id="edit_komisi" name="komisi" placeholder="">
            <div class="alert alert-info mt-2">
              <strong>Contoh:</strong>
              <ul class="mb-0 mt-2">
                <li>10% → 10</li>
                <li>20% → 20</li>
              </ul>
              <small class="text-muted">Masukkan angka persen tanpa simbol %.</small>
            </div>
          </div>

          <div class="mb-3">
            <label for="edit_ratelimit" class="form-label">Rate Limit speed</label>
            <input type="text" class="form-control" id="edit_ratelimit" name="ratelimit" placeholder="e.g., 10M/10M">
          </div>

          <div class="mb-3">
            <label for="edit_server" class="form-label">Server</label>
            <select required class="form-control" id="edit_server" name="pemilik" onchange="loadAreaEdit()">
                                                                                          <option value="">-- Pilih Server --</option>
                                                                                                               <?php
                                                        // Ambil semua brand dan area, gabungkan area per brand
                                                        $query = "SELECT BRAND, AREA, PEMILIK FROM server WHERE `pemilik` IN ($server_list)";
                                                        $result = mysqli_query($conn, $query);
                                                        $brand_area_map = [];
                                                        while ($row = mysqli_fetch_assoc($result)) {
                                                            $brand = trim($row['BRAND']);
                                                            $area = trim($row['AREA']);
                                                            $pemilik = trim($row['PEMILIK']);
                                                            if (!isset($brand_area_map[$brand])) {
                                                                $brand_area_map[$brand] = [
                                                                    'areas' => [],
                                                                    'pemilik' => $pemilik
                                                                ];
                                                            }
                                                            if (!in_array($area, $brand_area_map[$brand]['areas'])) {
                                                                $brand_area_map[$brand]['areas'][] = $area;
                                                            }
                                                        }
                                                        foreach ($brand_area_map as $brand => $data) {
                                                            echo '<option value="' . htmlspecialchars($data['pemilik'], ENT_QUOTES, 'UTF-8') . '" data-areas="' . htmlspecialchars(implode(',', $data['areas']), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '</option>';
                                                        }
                                                        ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="edit_area" class="form-label">AREA</label>
            <select required class="form-control" id="edit_area" name="area">
              <option value="">-- Pilih Area --</option>
            </select>
          </div>








          
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" form="editForm">Simpan</button>
      </div>
    </div>
  </div>
</div>

<script>
function loadAreaEdit() {
        const serverSelect = document.getElementById("edit_server");
        const areaDropdown = document.getElementById("edit_area");
        if (!areaDropdown) return;
        areaDropdown.innerHTML = '<option value="">-- Pilih Area --</option>';
        const selectedOption = serverSelect.options[serverSelect.selectedIndex];
        const areas = selectedOption.getAttribute('data-areas');
        if (areas) {
                const areaArr = areas.split(',');
                areaArr.forEach(function(area) {
                        const opt = document.createElement('option');
                        opt.value = area;
                        opt.textContent = area;
                        areaDropdown.appendChild(opt);
                });
        }
}

// fungsi buka modal + isi data
function openEditModal(data) {
  document.getElementById("edit_id").value = data.id;
  document.getElementById("edit_profileName").value = data.paket;
  document.getElementById("edit_uptime").value = data.uptime;
  document.getElementById("edit_harga").value = data.harga;
  document.getElementById("edit_komisi").value = data.komisi;
  document.getElementById("edit_ratelimit").value = data.ratelimit;
  document.getElementById("edit_server").value = data.pemilik;

  loadAreaEdit();
  setTimeout(() => {
    document.getElementById("edit_area").value = data.area;
  }, 500);

  new bootstrap.Modal(document.getElementById("editModal")).show();
}
</script>

<!-- Prevent spaces in package name inputs (add & edit) -->
<script>
    (function(){
        function preventSpaceKey(e){
            // prevent space key
            if (e.key === ' ' || e.keyCode === 32) {
                e.preventDefault();
                // briefly show error
                showErrorForInput(e.target);
            }
        }

        function sanitizeValue(el){
            if (!el) return;
            var old = el.value;
            var cleaned = old.replace(/\s+/g, '');
            if (old !== cleaned) {
                el.value = cleaned;
                showErrorForInput(el);
            }
        }

        function showErrorForInput(el){
            var id = el.id === 'profileName' ? 'profileNameError' : (el.id === 'edit_profileName' ? 'editProfileNameError' : null);
            if (!id) return;
            var msg = document.getElementById(id);
            if (!msg) return;
            msg.style.display = 'inline';
            clearTimeout(msg._hideTimeout);
            msg._hideTimeout = setTimeout(function(){ msg.style.display = 'none'; }, 2200);
        }

        function attachHandlers(id){
            var el = document.getElementById(id);
            if (!el) return;
            // Prevent space key
            el.addEventListener('keydown', function(e){
                preventSpaceKey(e);
            });
            // Sanitize when pasting
            el.addEventListener('paste', function(){
                setTimeout(function(){ sanitizeValue(el); }, 5);
            });
            // Sanitize on input (covers mobile IME, drag-drop etc.)
            el.addEventListener('input', function(){ sanitizeValue(el); });
        }

        attachHandlers('profileName');
        attachHandlers('edit_profileName');

        // Validate on submit for both forms
        var addForm = document.getElementById('dataForm');
        if (addForm){
            addForm.addEventListener('submit', function(e){
                var v = document.getElementById('profileName').value || '';
                if (/\s/.test(v) || v.length === 0) {
                    e.preventDefault();
                    showErrorForInput(document.getElementById('profileName'));
                    document.getElementById('profileName').focus();
                }
            });
        }

        var editForm = document.getElementById('editForm');
        if (editForm){
            editForm.addEventListener('submit', function(e){
                var v = document.getElementById('edit_profileName').value || '';
                if (/\s/.test(v) || v.length === 0) {
                    e.preventDefault();
                    showErrorForInput(document.getElementById('edit_profileName'));
                    document.getElementById('edit_profileName').focus();
                }
            });
        }

    })();
</script>








<!-- DataTables & jQuery CDN -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>

<style>
    /* DataTables paging style (match packages.php/tables.php) */
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        border-radius: 4px !important;
        border: 1px solid #ddd !important;
        background: #fff !important;
        color: #111 !important;
        font-weight: bold;
        margin: 0 2px !important;
        min-width: 32px;
        min-height: 32px;
        padding: 0 8px !important;
        box-shadow: none !important;
        transition: background 0.2s;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: orange !important;
        color: #111 !important;
        border-color: orange !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
        color: #aaa !important;
        background: #f8f8f8 !important;
        border-color: #eee !important;
    }
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current) {
        background: #eee !important;
        color: #111 !important;
    }
    .dataTables_wrapper .dataTables_paginate .ellipsis {
        padding: 0 8px;
        color: #888;
    }
    /* Hide previous/next buttons, show only numbers */
    .dataTables_wrapper .dataTables_paginate .paginate_button.previous,
    .dataTables_wrapper .dataTables_paginate .paginate_button.next {
        display: none !important;
    }
</style>

<script>
$(document).ready(function() {
    // Initialize DataTable for the main packages table by id
    $('#tabel-hotspot').DataTable({
        paging: true,
        searching: true,
        info: true,
        lengthChange: false,
        pageLength: 10,
        ordering: true,
        language: {
            paginate: {
                previous: '',
                next: ''
            },
            search: 'Cari:',
            info: 'Menampilkan _START_ - _END_ dari _TOTAL_ data',
            infoEmpty: 'Tidak ada data',
            zeroRecords: 'Tidak ditemukan',
        }
    });
});
</script>

<?php require 'footer.php'; ?>