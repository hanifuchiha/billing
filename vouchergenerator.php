<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Voucher_Generator', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Voucher Generator.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>

        <div class="container">
            <div class="row">


    <div class="card shadow p-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h2 class="mb-0">Generator Voucher Hotspot</h2>
          <a href="voucher_template_builder.php" class="btn btn-outline-primary btn-sm" data-perm="btn_voucher_template_builder">
            <i class="fas fa-object-group me-1"></i>Buat Template Voucher Sendiri
          </a>
        </div>
       <form id="preview-form" action="voucherhotspot/preview-load.php" method="post" enctype="multipart/form-data">

    <div class="mb-3">
        <label for="modeSelect" class="form-label">Mode AUTH</label>
        <select required class="form-control" id="modeSelect" name="radius" onchange="toggleFields(); fillForm(); loadLimitUptime(); loadratelimit();">
            <option disabled value="">-- Select mode --</option>
            <option selected value="yes">RADIUS MODE ( SEMUA SERVER )</option>
            <option value="no">API MODE (Langsung ke MikroTik, tanpa RADIUS)</option>
        </select>
    </div>

    <div class="mb-3">
        <label for="packages" class="form-label">Packages speed</label>
        <select required class="form-control" id="packages" name="packages" onchange="fillForm(); loadLimitUptime(); loadratelimit();">
            <option disabled selected value="">-- Pilih Packages --</option>
            <?php
          

          
            // Ambil server milik user ini -- $current_user_id utk ASSISTANT
            // berisi id akun OWNER (lihat cek-sesi.php), BUKAN id assistant
            // itu sendiri, jadi "WHERE user_id = ..." polos SELALU balik SEMUA
            // server milik owner (bocor lintas-area, termasuk IP/PASSWORD
            // server yang ikut ke-embed di dropdown value di bawah).
            if ($AKSES === 'ASSISTANT') {
                $sql_server = (isset($area_list) && trim((string)$area_list) !== '')
                    ? "SELECT * FROM server WHERE AREA IN ($area_list)"
                    : "SELECT * FROM server WHERE 1=0";
            } else {
                $sql_server = "SELECT * FROM server WHERE user_id = '" . mysqli_real_escape_string($conn, $current_user_id) . "'";
            }
            $query_server = mysqli_query($conn, $sql_server);
            while ($srv = mysqli_fetch_assoc($query_server)) {
                $server = $srv['PEMILIK'];
                $area = $srv['AREA'];
                $brand = $srv['BRAND'] ?? '';
                $ip = $srv['IP'];
                $password = $srv['PASSWORD'];
                // Cari paket yang sesuai dengan server ini
                $sql_paket = "SELECT * FROM paket_hotspot WHERE pemilik = '" . mysqli_real_escape_string($conn, $server) . "' AND area = '" . mysqli_real_escape_string($conn, $area) . "'";
                $query_paket = mysqli_query($conn, $sql_paket);
                while ($data = mysqli_fetch_array($query_paket)) {
                    $paket = $data['paket'];
                    $harga = $data['harga'];
                    echo "<option value='$ip|$server|$password|$area|$paket|$harga'>$paket (Area: $area)</option>";
                }
            }
            ?>
        </select>
    </div>

    <div class="mb-3">
        <label for="uptime" class="form-label">Limit Uptime</label>
        <select required class="form-control" id="uptime" name="uptime">
            <option disabled selected value="">-- uptime --</option>
        </select>
    </div>

    <div class="mb-3">
        <label for="ratelimit" class="form-label">Rate Limit</label>
        <select required class="form-control" id="ratelimit" name="ratelimit">
            <option disabled selected value="">-- Rate Limit --</option>
        </select>
    </div>

    <label>Pilih jenis random:</label>
    <select class="form-control mb-2" name="mode">
        <option value="mixedbesar">🔠 ABCD1234</option>
        <option value="mixedkecil">🔠 abcd123</option>
        <option value="mixedbesarkecil">🔠 AaBcD1234</option>
        <option value="number">🔢 123456 </option>
        <option value="letterbesar">🔡 ABCDFG</option>
        <option value="letterkecil">🔡 abcdf</option>
        <option value="letterbesarkecil">🔡 AbCdEfG</option>
    </select>

    <label>Panjang:</label>
    <input class="form-control mb-2" type="number" id="length" name="length" value="8" min="1" max="10">

    <label>Prefix:</label>
    <input class="form-control mb-2" type="text" id="prefix" name="prefix" placeholder="Prefix">

    <!-- INPUT TAMBAHAN UNTUK API MODE -->
    <div id="apiFields" style="display:none;">
        <label>Area:</label>
        <input class="form-control mb-2" type="text" id="area" name="area" required placeholder="Pilih server dulu">

        <label>IP MikroTik:</label>
        <input class="form-control mb-2" type="text" id="ip" name="ip" placeholder="Pilih server dulu">

        <label>Username MikroTik:</label>
        <input class="form-control mb-2" type="text" id="us" name="us" placeholder="Masukkan Username">

        <label>Password MikroTik:</label>
        <input class="form-control mb-2" type="password" id="ps" name="ps" placeholder="Masukkan Password">
    </div>

    <!-- <label>Upload Logo (Opsional):</label>
    <input class="form-control mb-2" type="file" name="logo">

    <label>Upload Background (Opsional):</label>
    <input class="form-control mb-2" type="file" name="bg"> -->

    <!-- <label>URL Login Hotspot:</label>
    <input class="form-control mb-2" type="text" name="login" required placeholder="Contoh: http://hotspot/login">

    <label>No CS :</label>
    <input class="form-control mb-2" type="text" id="nocs" name="nocs" placeholder="NO whatsapp"> -->

    <label>Jumlah Voucher :</label>
    <input class="form-control mb-2" required type="number" name="jumlah" min="1" value="1">

    <label>Masa Aktif / Expired (opsional):</label>
    <input class="form-control mb-2" type="date" id="expired_at" name="expired_at" min="<?= date('Y-m-d') ?>">
    <small class="text-muted d-block mb-2">Kosongkan kalau voucher tidak punya batas tanggal aktif (cuma dibatasi limit uptime di atas).</small>

    <label>Catatan (opsional):</label>
    <input class="form-control mb-2" type="text" id="catatan" name="catatan" maxlength="255" placeholder="Contoh: Batch promo Agustus, event bazar, dll">

    <!-- Tombol Preview (Modal) -->
    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#previewModal">Buat Voucher</button>
</form>

<!-- Modal Preview -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewModalLabel">Preview Voucher</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="preview-iframe" name="preview-iframe" style="width:100%; height:600px; border:none;"></iframe>
        <div id="iframe-url-info" style="padding:8px 0 0 0; font-size:13px; color:#555;"></div>
      </div>
    </div>
  </div>
</div>

<script>
function toggleFields() {
    var mode = document.getElementById("modeSelect").value;
    var apiFields = document.getElementById("apiFields");
    apiFields.style.display = (mode === "no") ? "block" : "none";
}

// Submit form ke iframe saat modal terbuka
document.querySelector('[data-bs-target="#previewModal"]').addEventListener('click', function(){
    const form = document.getElementById('preview-form');
    form.setAttribute('action','voucherhotspot/preview.php');
    form.setAttribute('target','preview-iframe');
    form.submit();
});

// Tampilkan URL iframe setelah load
document.getElementById('preview-iframe').addEventListener('load', function() {
    var url = this.contentWindow.location.href;
    // Jika sandbox/blank, fallback ke src
    if (!url || url === 'about:blank') url = this.src;
    document.getElementById('iframe-url-info').innerHTML = 'URL Preview: <a href="'+url+'" target="_blank">'+url+'</a>';
});
</script>

<!-- 
        <table>
            <tr>
                <th>No</th>
                <th>Nama File</th>
                <th>Download</th>
            </tr>
            <?php
            $folder = __DIR__ . '/voucherhotspot/voucher/' . $ceknama . '/';
            if (file_exists($folder)) {
                $files = array_diff(scandir($folder, SCANDIR_SORT_DESCENDING), ['.', '..']);
                $files = array_slice($files, 0, 5); // Ambil 5 file terbaru

                if (count($files) > 0) {
                    $no = 1;
                    foreach ($files as $file) {
                        echo "<tr>
                    <td>{$no}</td>
                    <td>{$file}</td>
                    <td><a href='voucherhotspot/voucher/$ceknama/{$file}' target='_blank'>Unduh</a></td>
                  </tr>";
                        $no++;
                    }
                } else {
                    echo "<tr><td colspan='3'>Belum ada voucher yang dibuat.</td></tr>";
                }
            } else {
                echo "<tr><td colspan='3'>Folder voucher tidak ditemukan.</td></tr>";
            }
            ?>
        </table> -->

        <script>
            // function loadPackages() {
            //     let selectedServer = document.getElementById("serverList").value;
            //     let packagesDropdown = document.getElementById("packages");

            //     packagesDropdown.innerHTML = '<option value="">Loading...</option>';

            //     if (selectedServer) {
            //         let [ip, pemilik, password, area] = selectedServer.split('|');

            //         let formData = new FormData();
            //         formData.append("area", area);
            //         formData.append("server", pemilik);

            //         fetch("getdata/get_packages_hotspot.php", {
            //                 method: "POST",
            //                 body: formData,
            //             })
            //             .then(response => response.text())
            //             .then(data => {
            //                 packagesDropdown.innerHTML = data;
            //             })
            //             .catch(error => {
            //                 console.error("Error fetching packages:", error);
            //                 packagesDropdown.innerHTML = '<option value="">Gagal memuat data</option>';
            //             });
            //     }
            // }

            function loadLimitUptime() {
                let selectedServer = document.getElementById("packages").value;
                let uptimeDropdown = document.getElementById("uptime");
                // let packages = document.getElementById("packages").value;
                uptimeDropdown.innerHTML = '<option value="">Loading...</option>';

                if (selectedServer) {
                    let [ip, pemilik, password, area, packages] = selectedServer.split('|');

                    let formData = new FormData();
                    formData.append("area", area);
                    formData.append("server", pemilik);
                    formData.append("packages", packages);
                    fetch("getdata/get_packages_uptime.php", {
                            method: "POST",
                            body: formData,
                        })
                        .then(response => response.text())
                        .then(data => {
                            uptimeDropdown.innerHTML = data;
                        })
                        .catch(error => {
                            console.error("Error fetching packages:", error);
                            uptimeDropdown.innerHTML = '<option value="">Gagal memuat data</option>';
                        });
                }
            }

            function loadratelimit() {
                let selectedServer = document.getElementById("packages").value;
                let uptimeDropdown = document.getElementById("uptime");
                let ratelimitDropdown = document.getElementById("ratelimit");
                // let packages = document.getElementById("packages").value;
                ratelimitDropdown.innerHTML = '<option value="">Loading...</option>';

                if (selectedServer) {
                    let [ip, pemilik, password, area, packages] = selectedServer.split('|');

                    let formData = new FormData();
                    formData.append("area", area);
                    formData.append("server", pemilik);
                    formData.append("packages", packages);
                    fetch("getdata/get_packages_ratelimit.php", {
                            method: "POST",
                            body: formData,
                        })
                        .then(response => response.text())
                        .then(data => {
                            ratelimitDropdown.innerHTML = data;
                        })
                        .catch(error => {
                            console.error("Error fetching packages:", error);
                            ratelimitDropdown.innerHTML = '<option value="">Gagal memuat data</option>';
                        });
                }
            }

            function fillForm() {
                let serverData = document.getElementById("packages").value;
                if (serverData) {
                    let [ip, user, pass, area, packages] = serverData.split("|");
                    document.getElementById("ip").value = ip;
                    document.getElementById("us").value = user;
                    document.getElementById("ps").value = pass ? pass : "";
                    document.getElementById("area").value = area;
                } else {
                    document.getElementById("ip").value = "";
                    document.getElementById("us").value = "";
                    document.getElementById("ps").value = "";
                    document.getElementById("area").value = "";
                }
            }
        </script>





</div>

 </div>





<?php require 'footer.php'; ?>


