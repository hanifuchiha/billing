<?php

require 'header.php'; ?>




  <?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check database connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

  // --- Hapus data jika ada request delete ---
  if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    // Cek status user
    $check_sql = "SELECT STATUS, USERNAME FROM `user` WHERE id = ?";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        die("Prepare failed: " . $conn->error);
    }
    $check_stmt->bind_param("i", $delete_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    if ($result->num_rows > 0) {
      $user_data = $result->fetch_assoc();
      $status = strtoupper($user_data['STATUS']);
      $username = $user_data['USERNAME'];
      if ($status === 'ADMIN') {
        echo "<div class='alert alert-danger'>Tidak dapat menghapus user dengan status ADMIN</div>";
      } elseif ($status === 'USER') {
        // Hapus cascading untuk USER
        // 1. Ambil data server terkait
        $server_sql = "SELECT pemilik, area, brand FROM server WHERE user_id = ?";
        $server_stmt = $conn->prepare($server_sql);
        $server_stmt->bind_param("i", $delete_id);
        $server_stmt->execute();
        $server_result = $server_stmt->get_result();
        $servers = [];
        while ($srv = $server_result->fetch_assoc()) {
          $servers[] = $srv;
        }
        $server_stmt->close();

        // 2. Hapus data terkait berdasarkan server
        foreach ($servers as $srv) {
          $pemilik = $srv['pemilik'];
          $area = $srv['area'];
          $brand = $srv['brand'];

          // Ambil id pelanggan terkait untuk hapus transaksi SEBELUM hapus pelanggan
          $pelanggan_sql = "SELECT id FROM pelanggan WHERE pemilik = ? AND area = ? AND brand = ?";
          $pelanggan_stmt = $conn->prepare($pelanggan_sql);
          if (!$pelanggan_stmt) {
              die("Prepare failed: " . $conn->error);
          }
          $pelanggan_stmt->bind_param("sss", $pemilik, $area, $brand);
          $pelanggan_stmt->execute();
          $pelanggan_result = $pelanggan_stmt->get_result();
          $id_eps = [];
          while ($p = $pelanggan_result->fetch_assoc()) {
            $id_eps[] = $p['id'];
          }
          $pelanggan_stmt->close();

          // Hapus transaksi berdasarkan id pelanggan
          foreach ($id_eps as $id_ep) {
            $del_transaksi_ep = $conn->prepare("DELETE FROM transaksi WHERE id = ?");
            if (!$del_transaksi_ep) {
                die("Prepare failed: " . $conn->error);
            }
            $del_transaksi_ep->bind_param("i", $id_ep);
            $del_transaksi_ep->execute();
            $del_transaksi_ep->close();
          }

          // Hapus pelanggan
          $del_pelanggan = $conn->prepare("DELETE FROM pelanggan WHERE pemilik = ? AND area = ? AND brand = ?");
          if (!$del_pelanggan) {
              die("Prepare failed: " . $conn->error);
          }
          $del_pelanggan->bind_param("sss", $pemilik, $area, $brand);
          $del_pelanggan->execute();
          $del_pelanggan->close();

        
          // Hapus odp
          $del_odp = $conn->prepare("DELETE FROM odp WHERE pemilik = ? AND area = ? AND brand = ?");
          if (!$del_odp) {
              die("Prepare failed: " . $conn->error);
          }
          $del_odp->bind_param("sss", $pemilik, $area, $brand);
          $del_odp->execute();
          $del_odp->close();

          // Hapus olt
          $del_olt = $conn->prepare("DELETE FROM olt WHERE pemilik = ? AND area = ? ");
          if (!$del_olt) {
              die("Prepare failed: " . $conn->error);
          }
          $del_olt->bind_param("ss", $pemilik, $area);
          $del_olt->execute();
          $del_olt->close();

          // Hapus paket
          $del_paket = $conn->prepare("DELETE FROM paket WHERE pemilik = ? AND area = ? AND brand = ?");
          if (!$del_paket) {
              die("Prepare failed: " . $conn->error);
          }
          $del_paket->bind_param("sss", $pemilik, $area, $brand);
          $del_paket->execute();
          $del_paket->close();

          // Hapus paket_hotspot
          $del_paket_hs = $conn->prepare("DELETE FROM paket_hotspot WHERE pemilik = ? AND area = ? AND brand = ?");
          if (!$del_paket_hs) {
              die("Prepare failed: " . $conn->error);
          }
          $del_paket_hs->bind_param("sss", $pemilik, $area, $brand);
          $del_paket_hs->execute();
          $del_paket_hs->close();

          // Hapus pelanggan_berhenti
          $del_berhenti = $conn->prepare("DELETE FROM pelanggan_berhenti WHERE pemilik = ? ");
          if (!$del_berhenti) {
              die("Prepare failed: " . $conn->error);
          }
          $del_berhenti->bind_param("s", $pemilik);
          $del_berhenti->execute();
          $del_berhenti->close();


     
         // Hapus promo_paket
        $del_promo_paket = $conn->prepare("DELETE FROM promo_paket WHERE pemilik = ? AND area = ?");
        if (!$del_promo_paket) {
            die("Prepare failed: " . $conn->error);
        }
        $del_promo_paket->bind_param("ss", $pemilik, $area);
        $del_promo_paket->execute();
        $del_promo_paket->close();
          // Hapus voucher
          $del_voucher = $conn->prepare("DELETE FROM voucher WHERE pemilik = ?");
          if (!$del_voucher) {
              die("Prepare failed: " . $conn->error);
          }
          $del_voucher->bind_param("s", $pemilik);
          $del_voucher->execute();
          $del_voucher->close();
        }













 // Hapus voucher
          $del_voucher2 = $conn->prepare("DELETE FROM voucher WHERE pemilik = ?");
          if (!$del_voucher2) {
              die("Prepare failed: " . $conn->error);
          }
          $del_voucher2->bind_param("s", $username);
          $del_voucher2->execute();
          $del_voucher2->close();
     // Hapus notif_khusus
          $del_notif = $conn->prepare("DELETE FROM notif_khusus WHERE pemilik = ?");
          if (!$del_notif) {
              die("Prepare failed: " . $conn->error);
          }
          $del_notif->bind_param("s", $username);
          $del_notif->execute();
          $del_notif->close();
  // Hapus manual_bank
          $del_manual_bank = $conn->prepare("DELETE FROM manualbank WHERE pemilik = ?");
          if (!$del_manual_bank) {
              die("Prepare failed: " . $conn->error);
          }
          $del_manual_bank->bind_param("s", $username);
          $del_manual_bank->execute();
          $del_manual_bank->close();

        // 3. Hapus data payment terkait USERNAME
        $del_tripay = $conn->prepare("DELETE FROM tripay WHERE pemilik = ?");
        if (!$del_tripay) {
            die("Prepare failed: " . $conn->error);
        }
        $del_tripay->bind_param("s", $username);
        $del_tripay->execute();
        $del_tripay->close();

        $del_duitku = $conn->prepare("DELETE FROM duitku WHERE pemilik = ?");
        if (!$del_duitku) {
            die("Prepare failed: " . $conn->error);
        }
        $del_duitku->bind_param("s", $username);
        $del_duitku->execute();
        $del_duitku->close();

     

        $del_xendit = $conn->prepare("DELETE FROM xendit WHERE pemilik = ?");
        if (!$del_xendit) {
            die("Prepare failed: " . $conn->error);
        }
        $del_xendit->bind_param("s", $username);
        $del_xendit->execute();
        $del_xendit->close();

        // Hapus pool
        $del_pool = $conn->prepare("DELETE FROM pool WHERE pemilik = ?");
        if (!$del_pool) {
            die("Prepare failed: " . $conn->error);
        }
        $del_pool->bind_param("s", $username);
        $del_pool->execute();
        $del_pool->close();

        // Hapus cables
        $del_cables = $conn->prepare("DELETE FROM cables WHERE owner = ?");
        if (!$del_cables) {
            die("Prepare failed: " . $conn->error);
        }
        $del_cables->bind_param("s", $username);
        $del_cables->execute();
        $del_cables->close();

        // Hapus assets
        $del_assets = $conn->prepare("DELETE FROM assets WHERE owner = ?");
        if (!$del_assets) {
            die("Prepare failed: " . $conn->error);
        }
        $del_assets->bind_param("s", $username);
        $del_assets->execute();
        $del_assets->close();

        // Hapus pengeluaran
        $del_pengeluaran = $conn->prepare("DELETE FROM pengeluaran WHERE pemilik = ?");
        if (!$del_pengeluaran) {
            die("Prepare failed: " . $conn->error);
        }
        $del_pengeluaran->bind_param("s", $username);
        $del_pengeluaran->execute();
        $del_pengeluaran->close();

        // Hapus apikey
        $del_apikey = $conn->prepare("DELETE FROM apikey WHERE user_name = ?");
        if (!$del_apikey) {
            die("Prepare failed: " . $conn->error);
        }
        $del_apikey->bind_param("s", $username);
        $del_apikey->execute();
        $del_apikey->close();

        // Hapus belanja
        $del_belanja = $conn->prepare("DELETE FROM belanja WHERE pemilik = ?");
        if (!$del_belanja) {
            die("Prepare failed: " . $conn->error);
        }
        $del_belanja->bind_param("s", $username);
        $del_belanja->execute();
        $del_belanja->close();

        // Hapus mitra
        $del_mitra = $conn->prepare("DELETE FROM mitra WHERE server = ?");
        if (!$del_mitra) {
            die("Prepare failed: " . $conn->error);
        }
        $del_mitra->bind_param("s", $username);
        $del_mitra->execute();
        $del_mitra->close();

        // Ambil nama mitra terkait untuk hapus transaksi_komisi
        $mitra_sql = "SELECT nama FROM mitra WHERE server = ?";
        $mitra_stmt = $conn->prepare($mitra_sql);
        if (!$mitra_stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $mitra_stmt->bind_param("s", $username);
        $mitra_stmt->execute();
        $mitra_result = $mitra_stmt->get_result();
        $mitra_names = [];
        while ($m = $mitra_result->fetch_assoc()) {
          $mitra_names[] = $m['nama'];
        }
        $mitra_stmt->close();

        // Hapus transaksi_komisi berdasarkan nama mitra
        foreach ($mitra_names as $nama_mitra) {
          $del_transaksi_komisi = $conn->prepare("DELETE FROM transaksi_komisi WHERE nama = ?");
          if (!$del_transaksi_komisi) {
              die("Prepare failed: " . $conn->error);
          }
          $del_transaksi_komisi->bind_param("s", $nama_mitra);
          $del_transaksi_komisi->execute();
          $del_transaksi_komisi->close();
        }
   $del_mitrans = $conn->prepare("DELETE FROM midtrans WHERE pemilik = ?");
        if (!$del_mitrans) {
            die("Prepare failed: " . $conn->error);
        }
        $del_mitrans->bind_param("s", $username);
        $del_mitrans->execute();
        $del_mitrans->close();


        // Hapus network_device
        $del_network_device = $conn->prepare("DELETE FROM network_devices WHERE user_id = ?");
        if (!$del_network_device) {
            die("Prepare failed: " . $conn->error);
        }
        $del_network_device->bind_param("i", $delete_id);
        $del_network_device->execute();
        $del_network_device->close();

        // Hapus botwa
        $del_botwa = $conn->prepare("DELETE FROM botwa WHERE pemilik = ?");
        if (!$del_botwa) {
            die("Prepare failed: " . $conn->error);
        }
        $del_botwa->bind_param("s", $username);
        $del_botwa->execute();
        $del_botwa->close();

        // Hapus file di notifbot/notifphp yang mengandung USERNAME
        $notif_dir ='notifbot/notifphp/';
        if (is_dir($notif_dir)) {
            $files = glob($notif_dir . '*' . $username . '*.php');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

       

        // 4. Hapus server terkait user_id
        $del_server = $conn->prepare("DELETE FROM server WHERE user_id = ?");
        if (!$del_server) {
            die("Prepare failed: " . $conn->error);
        }
        $del_server->bind_param("i", $delete_id);
        $del_server->execute();
        $del_server->close();

        // 5. Hapus user
        $delete_sql = "DELETE FROM `user` WHERE id = ?";
        $stmt = $conn->prepare($delete_sql);
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
          echo "<div class='alert alert-success'>User dan semua data terkait berhasil dihapus</div>";
        } else {
          echo "<div class='alert alert-danger'>Gagal menghapus user</div>";
        }
      } else {
        // Untuk ASSISTANT, hapus user saja
        $delete_sql = "DELETE FROM `user` WHERE id = ?";
        $stmt = $conn->prepare($delete_sql);
        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
          echo "<div class='alert alert-success'>User berhasil dihapus</div>";
        } else {
          echo "<div class='alert alert-danger'>Gagal menghapus user</div>";
        }
      }
    } else {
      echo "<div class='alert alert-danger'>User tidak ditemukan</div>";
    }
  }












 if (isset($_POST['update_user'])) {

    // Ambil data dari form
    $id         = intval($_POST['id']);
    $username   = mysqli_real_escape_string($conn, $_POST['USERNAME']);
    $password   = mysqli_real_escape_string($conn, $_POST['PASWORD']);
    $status     = mysqli_real_escape_string($conn, $_POST['STATUS']);
    $nowa       = mysqli_real_escape_string($conn, $_POST['NOWA']);
    $saldo      = mysqli_real_escape_string($conn, $_POST['saldo']);
    $domain     = mysqli_real_escape_string($conn, $_POST['domain']);
    $expired_at = mysqli_real_escape_string($conn, $_POST['expired_at']);

    // Konversi nilai server menjadi JSON array
    $server     = trim($_POST['server']);
    $array      = explode(",", $server);
    $serverlist = json_encode($array, JSON_PRETTY_PRINT);

    // Query update
    $sqlupdate = "
        UPDATE `user` 
        SET 
            `USERNAME`='$username',
            `PASWORD`='$password',
            `STATUS`='$status',
            `NOWA`='$nowa',
            `saldo`='$saldo',
            `domain`='$domain',
            `expired_at`='$expired_at',
            `server`='$serverlist'
        WHERE `id`='$id'
    ";

    if (mysqli_query($conn, $sqlupdate)) {
        echo "<div class='alert alert-success'>✅ User berhasil diupdate</div>";
    } else {
        echo "<div class='alert alert-danger'>❌ Gagal mengupdate user: " . mysqli_error($conn) . "</div>";
    }
}

  // --- Simpan data jika ada POST ---
  if (isset($_POST['add'])) {
    if ($conn->connect_error) {
      die("Koneksi gagal: " . $conn->connect_error);
    }

    $username = $_POST['username'];
    $password = $_POST['password'];
    $status   = $_POST['status'];
    $nowa     = $_POST['nowa'];
    $saldo    = $_POST['saldo'];
    $server   = "NULL";
    $inisial  = "";

    $sql = "INSERT INTO `user`(`USERNAME`, `PASWORD`, `STATUS`, `NOWA`, `saldo`, `server`, `inisial`)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssss", $username, $password, $status, $nowa, $saldo, $server, $inisial);

    if ($stmt->execute()) {
      echo "<div class='alert alert-success'>Data berhasil disimpan!</div>";
    } else {
      echo "<div class='alert alert-danger'>Gagal menyimpan data: " . $stmt->error . "</div>";
    }
  }
  ?>












  <div class="container-fluid py-4">
    <div class="row">
      <div class="col-12">
        <div class="card mb-4">
          <div class="card-header pb-0">
            <h6>User crm billing</h6>



            <!-- Modal -->
            <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    
                    <h5 class="modal-title" id="dataModalLabel">Tambah Data</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <form method="post">
                      <div class="mb-3">
                        <label for="username" class="form-label">USERNAME</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                      </div>

                      <div class="mb-3">
                        <label for="password" class="form-label">PASSWORD</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                      </div>

                      <div class="mb-3">
                        <label for="status" class="form-label">STATUS</label>
                        <select class="form-select" id="status" name="status" required>
                                    <option value="USER">USER</option>
                                    <option value="ADMIN">SUPER ADMIN</option>
                                  
                        </select>
                      </div>

                      <div class="mb-3">
                        <label for="nowa" class="form-label">NOWA</label>
                        <input type="text" class="form-control" id="nowa" name="nowa" placeholder="62xxxx">
                      </div>

                      <div class="mb-3">
                        <label for="saldo" class="form-label">Saldo</label>
                        <input type="number" class="form-control" id="saldo" name="saldo" value="0" placeholder="0">
                      </div>

 <input type="hiddin" class="form-control" id="add" name="add" >

                      <button type="submit"  class="btn btn-primary w-100">Simpan</button>
                    </form>

                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                  </div>
                </div>
              </div>
            </div>

          </div>
          <div class="card-body px-0 pt-0 pb-2">
            <div class="table-responsive p-0">


              <style>
                /* Menyembunyikan kolom tertentu saat tampilan mobile - dihapus agar semua terlihat */
                /* @media screen and (max-width: 600px) {
                  td:nth-child(3),
                  th:nth-child(3),
                  td:nth-child(4),
                  th:nth-child(4) {
                    display: none;
                  }
                } */

                .small-text {
                  font-size: 8px;
                }

                /* Card untuk mobile view */
                .user-card {
                  margin-bottom: 15px;
                  border: 1px solid #ddd;
                  border-radius: 8px;
                  padding: 15px;
                  background: #fff;
                }
                .user-card h6 {
                  margin-bottom: 10px;
                  color: #333;
                }
                .user-card p {
                  margin: 5px 0;
                  font-size: 14px;
                }
                .user-card .server-tags {
                  display: flex;
                  flex-wrap: wrap;
                  gap: 5px;
                }
                .user-card .server-tag {
                  background: #f0f0f0;
                  padding: 2px 6px;
                  border-radius: 4px;
                  font-size: 12px;
                }
                .user-card .actions {
                  margin-top: 10px;
                  display: flex;
                  gap: 5px;
                }
              </style>
      



            <!-- <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
              Add user
            </button> -->




<!-- Tabel untuk desktop -->
<div class="d-none d-md-block">
<div class="table-responsive">
    <table class="table align-items-center mb-0 table-hover">
        <thead>
            <tr class="text-center">
                <th>ID</th>
                <th>Akun</th>
               
               
                <!-- <th>Created At</th> -->
                <th>Last Login</th>
                <th>Expired At</th>
               
            </tr>
        </thead>
        <tbody id="crmUserTableBody">
            <?php
            // Lazy-load: 20 akun user per fetch (self-POST ke halaman ini sendiri)
            // supaya daftar akun tidak dirender sekaligus. Tabel desktop, kartu
            // mobile, dan modal edit sebelumnya masing-masing loop terpisah lewat
            // mysqli_data_seek (3x iterasi penuh) - sekarang digabung jadi SATU
            // loop yang menghasilkan ketiga buffer sekaligus, supaya tetap sinkron
            // saat lazy-load menambah baris baru.
            $crmPageSize = 20;
            $crmPage = isset($_POST['page']) ? (int)$_POST['page'] : 1;
            if ($crmPage < 1) $crmPage = 1;

            $crmCountRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM `user`"));
            $crmTotalRows = (int)($crmCountRow['total'] ?? 0);
            $crmTotalPages = max(1, (int)ceil($crmTotalRows / $crmPageSize));
            $crmPage = min($crmPage, $crmTotalPages);
            $crmOffset = ($crmPage - 1) * $crmPageSize;

            $sql = "SELECT * FROM `user` ORDER BY `id` DESC LIMIT $crmPageSize OFFSET $crmOffset";
            $query = mysqli_query($conn, $sql);
            $crmMobileCardsHtml = [];
            $crmModalsHtml = [];
            while ($data = mysqli_fetch_assoc($query)) {
                $servers = json_decode($data['server'], true);
                if (!$servers) $servers = [];
            ?>
            <tr class="text-center">
                <td><?php echo $data['id']; ?></td>
                <td>Status : <?php echo htmlspecialchars($data['STATUS']); ?><br>Username : <?php echo htmlspecialchars($data['USERNAME']); ?><br>Pass : <?php echo htmlspecialchars($data['PASWORD']); ?><br>Saldo :<?php echo number_format($data['saldo'],0,',','.'); ?><br><?php echo htmlspecialchars($data['NOWA']); ?><br><?php echo htmlspecialchars($data['domain']); ?><br><div class="d-flex flex-wrap justify-content-center gap-2">
                        <?php foreach ($servers as $srv): ?>
                        <div class="card p-1 px-2 border shadow-sm" style="font-size: 10px;">
                            <?php echo htmlspecialchars($srv); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                
              </td>
              
              
                
            
               
                <!-- <td><?php echo $data['created_at']; ?></td> -->
                <td><?php echo $data['last_login'] ? date('d/m/Y H:i', strtotime($data['last_login'])) : 'Belum login'; ?></td>
                <td>
    <?php 
    $today = new DateTime();
    $expired = new DateTime($data['expired_at']);
    $interval = $today->diff($expired);

    if ($interval->invert) {
        // Expired -> merah
        echo '<span style="color:red;">' . $data['expired_at'] . ' <br> Expired ' . $interval->days . ' hari lalu</span>';
    } else {
        // Masih aktif -> normal
        echo '<span style="color:green;">' . $data['expired_at'] . ' <br> Sisa ' . $interval->days . ' hari</span>';
    }
   ?><br>  <button class="btn btn-primary btn-sm" data-bs-toggle="modal"
                   data-bs-target="#editUserModal<?php echo $data['id']; ?>">Edit</button>
              <?php if (strtoupper($data['STATUS']) !== 'ADMIN'): ?>
              <a href="?delete_id=<?php echo $data['id']; ?>" 
                onclick="return confirmDeleteUser('<?php echo $data['id']; ?>', '<?php echo $data['STATUS']; ?>')"
                class="btn btn-danger btn-sm">Hapus</a>
              <?php endif; ?>
</td>


            </tr>

            <?php
            // Kartu mobile & modal edit ditampung di sini (dalam loop yang sama,
            // pakai $data/$servers yang sudah dihitung di atas) - menggantikan 2
            // loop terpisah dengan mysqli_data_seek yang scan ulang seluruh hasil
            // query dari awal. Sekaligus supaya lazy-load bisa menambah ketiga
            // tampilan (desktop/mobile/modal) secara sinkron per halaman.
            $expired_text = $interval->invert ? '<span style="color:red;">Expired ' . $interval->days . ' hari lalu</span>' : '<span style="color:green;">Sisa ' . $interval->days . ' hari</span>';
            ob_start();
            ?>
            <div class="user-card">
                <h6>ID: <?php echo $data['id']; ?> - <?php echo htmlspecialchars($data['USERNAME']); ?></h6>
                <p><strong>Status:</strong> <?php echo htmlspecialchars($data['STATUS']); ?></p>
                <p><strong>Password:</strong> <?php echo htmlspecialchars($data['PASWORD']); ?></p>
                <p><strong>Saldo:</strong> <?php echo number_format($data['saldo'],0,',','.'); ?></p>
                <p><strong>Kontak:</strong> <?php echo htmlspecialchars($data['NOWA']); ?> | <?php echo htmlspecialchars($data['domain']); ?></p>
                <p><strong>Server:</strong></p>
                <div class="server-tags">
                    <?php foreach ($servers as $srv): ?>
                    <span class="server-tag"><?php echo htmlspecialchars($srv); ?></span>
                    <?php endforeach; ?>
                </div>
                <p><strong>Expired At:</strong> <?php echo $data['expired_at']; ?> (<?php echo $expired_text; ?>)</p>
                <p><strong>Last Login:</strong> <?php echo $data['last_login'] ? date('d/m/Y H:i', strtotime($data['last_login'])) : 'Belum login'; ?></p>
            <div class="actions">
              <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editUserModal<?php echo $data['id']; ?>">Edit</button>
              <?php if (strtoupper($data['STATUS']) !== 'ADMIN'): ?>
              <a href="?delete_id=<?php echo $data['id']; ?>" onclick="return confirmDeleteUser('<?php echo $data['id']; ?>', '<?php echo $data['STATUS']; ?>')" class="btn btn-danger btn-sm">Hapus</a>
              <?php endif; ?>
            </div>
            </div>
            <?php
            $crmMobileCardsHtml[] = ob_get_clean();

            ob_start();
            ?>
            <div class="modal fade" id="editUserModal<?php echo $data['id']; ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Edit User: <?php echo htmlspecialchars($data['USERNAME']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="id" value="<?php echo $data['id']; ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Username</label>
                                <input type="text" name="USERNAME" class="form-control" value="<?php echo htmlspecialchars($data['USERNAME']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Password</label>
                                <input type="text" name="PASWORD" class="form-control" value="<?php echo htmlspecialchars($data['PASWORD']); ?>" required>
                            </div>
                        </div>



                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Status</label>
                                 <select class="form-select" id="status"  name="STATUS" required>
                                    <option  value="<?php echo htmlspecialchars($data['STATUS']); ?>" ><?php echo htmlspecialchars($data['STATUS']); ?></option>
                                    <option value="USER">USER</option>
                                    <option value="ADMIN">SUPER ADMIN</option>
                                    <option value="ASSISTANT">ASSISTANT</option>
                                  </select>
                                </div>
                            <div class="col-md-6 mb-3">
                                <label>No WA</label>
                                <input type="text" name="NOWA" class="form-control" value="<?php echo htmlspecialchars($data['NOWA']); ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label>Saldo</label>
                                <input type="number" name="saldo" class="form-control" value="<?php echo $data['saldo']; ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label>Email</label>
                                <input type="text" name="domain" class="form-control" value="<?php echo htmlspecialchars($data['domain']); ?>">
                            </div>
                            <!-- <div class="col-md-4 mb-3">
                                <label>Inisial</label>
                                <input type="text" name="inisial" class="form-control" value="<?php echo htmlspecialchars($data['inisial']); ?>">
                            </div> -->
                        </div>

                        <div class="mb-3">
                            <label>Server (pisahkan dengan koma)</label>
                            <input type="text" name="server" class="form-control"
                                   value="<?php echo htmlspecialchars(implode(',', $servers)); ?>">
                             </div>

                        <!-- <div class="mb-3">
                            <label>Akses</label>
                            <textarea name="akses" class="form-control" rows="3"><?php echo htmlspecialchars($data['akses']); ?></textarea>
                        </div> -->

                        <div class="row">

                            <div class="col-md-6 mb-3">
                                <label>Expired At</label>
                                <input type="datetime-local" name="expired_at" class="form-control"
                                       value="<?php echo $data['expired_at'] ? date('Y-m-d\TH:i', strtotime($data['expired_at'])) : ''; ?>">
                            </div>
                        </div>

                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                      <button type="submit" name="update_user" class="btn btn-primary">Simpan</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php
            $crmModalsHtml[] = ob_get_clean();
            ?>

            <?php } ?>
            <tr id="crmUserLazySentinel" style="height:1px;"><td colspan="4" style="padding:0;border:0;"></td></tr>
        </tbody>
    </table>
</div>
</div>

<!-- Card untuk mobile view -->
<div class="d-block d-md-none" id="crmUserMobileList">
    <?php echo implode('', $crmMobileCardsHtml); ?>
    <div id="crmUserMobileLazySentinel" style="height:1px;"></div>
</div>

<div id="crmUserLazyLoadWrap" class="text-center py-3 <?php echo $crmPage >= $crmTotalPages ? 'd-none' : ''; ?>">
  <div id="crmUserLazyLoadIndicator" class="spinner-border spinner-border-sm text-primary d-none" role="status"><span class="visually-hidden">Loading...</span></div>
  <span id="crmUserLazyLoadStatusText" class="text-secondary text-xs"></span>
</div>
<div id="crmUserLazyMeta" class="d-none" data-page="<?php echo (int)$crmPage; ?>" data-total-pages="<?php echo (int)$crmTotalPages; ?>"></div>

<!-- Modal Edit User -->
<div id="crmUserModalsContainer"><?php echo implode('', $crmModalsHtml); ?></div>

<?php if ($crmPage < $crmTotalPages): ?>
<script>
(function() {
    var currentPage = <?php echo (int)$crmPage; ?>;
    var totalPages  = <?php echo (int)$crmTotalPages; ?>;
    var isLoading   = false;

    var tableBody   = document.getElementById('crmUserTableBody');
    var mobileList  = document.getElementById('crmUserMobileList');
    var modalsContainer = document.getElementById('crmUserModalsContainer');
    var sentinelDesktop = document.getElementById('crmUserLazySentinel');
    var sentinelMobile  = document.getElementById('crmUserMobileLazySentinel');
    var lazyWrap = document.getElementById('crmUserLazyLoadWrap');
    var lazyIndicator = document.getElementById('crmUserLazyLoadIndicator');
    var lazyStatusText = document.getElementById('crmUserLazyLoadStatusText');

    if (!tableBody || !sentinelDesktop) return;

    function updateStatusText() {
        if (!lazyStatusText) return;
        lazyStatusText.textContent = (currentPage >= totalPages) ? 'Semua akun sudah dimuat.' : '';
    }

    function executeScripts(container) {
        container.querySelectorAll('script').forEach(function(oldScript) {
            var newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(function(attr) {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.textContent = oldScript.textContent;
            if (oldScript.parentNode) oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    function appendNextPage() {
        if (isLoading || currentPage >= totalPages) return Promise.resolve();
        isLoading = true;
        if (lazyWrap) lazyWrap.classList.remove('d-none');
        if (lazyIndicator) lazyIndicator.classList.remove('d-none');

        // Self-POST ke halaman ini sendiri (satu-satunya sumber query/render user).
        return fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            credentials: 'same-origin',
            body: 'page=' + (currentPage + 1)
        })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newTableBody = doc.getElementById('crmUserTableBody');
                var newMobileList = doc.getElementById('crmUserMobileList');
                var newModalsContainer = doc.getElementById('crmUserModalsContainer');
                var newMeta = doc.getElementById('crmUserLazyMeta');
                if (!newTableBody) throw new Error('Gagal memuat data user');

                var newSentinelDesktop = newTableBody.querySelector('#crmUserLazySentinel');
                if (newSentinelDesktop) newSentinelDesktop.remove();
                tableBody.insertAdjacentHTML('beforeend', newTableBody.innerHTML);
                tableBody.appendChild(sentinelDesktop);

                if (mobileList && newMobileList) {
                    var newSentinelMobile = newMobileList.querySelector('#crmUserMobileLazySentinel');
                    if (newSentinelMobile) newSentinelMobile.remove();
                    mobileList.insertAdjacentHTML('beforeend', newMobileList.innerHTML);
                    if (sentinelMobile) mobileList.appendChild(sentinelMobile);
                }

                if (modalsContainer && newModalsContainer) {
                    modalsContainer.insertAdjacentHTML('beforeend', newModalsContainer.innerHTML);
                    executeScripts(modalsContainer);
                }

                var parsedPage = newMeta ? parseInt(newMeta.getAttribute('data-page'), 10) : NaN;
                currentPage = !isNaN(parsedPage) ? parsedPage : (currentPage + 1);
                updateStatusText();
                if (currentPage >= totalPages && lazyWrap) lazyWrap.classList.add('d-none');
            })
            .catch(function(err) { console.error('Gagal lazy load user:', err); })
            .finally(function() {
                isLoading = false;
                if (lazyIndicator) lazyIndicator.classList.add('d-none');
            });
    }

    [sentinelDesktop, sentinelMobile].forEach(function(sentinel) {
        if (sentinel && 'IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) appendNextPage();
                });
            }, { root: null, rootMargin: '0px 0px 300px 0px', threshold: 0 });
            observer.observe(sentinel);
        }
    });

    window.addEventListener('scroll', function() {
        if (isLoading || currentPage >= totalPages) return;
        var nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;
        if (nearBottom) appendNextPage();
    }, { passive: true });

    updateStatusText();
})();
</script>
<?php endif; ?>







              <script>
                // Daftar IP awal kosong
                let ipList = [];

                // Fungsi untuk ping server dan ambil IP jika online
                function pingHost(ipserver) {
                  fetch(`ping.php?host=${ipserver}`)
                    .then(response => response.json())
                    .then(data => {
                      const statusElem = document.getElementById("status-" + ipserver);
                      if (statusElem) {
                        statusElem.textContent = `Status: ${data.status}`;
                        statusElem.style.color = data.status === "Online" ? "green" : "red";
                      }

                      // Jika IP online dan belum ada di ipList, tambahkan ke dalam daftar
                      if (data.status === "Online" && !ipList.includes(ipserver)) {
                        ipList.push(ipserver);
                        console.log(`IP ${ipserver} ditambahkan ke dalam daftar.`);
                      }
                    })
                    .catch(error => console.error("Error:", error));
                }

                // Jalankan saat halaman selesai dimuat
                window.onload = function() {
                  // Ambil semua elemen yang memiliki id format "status-IP"
                  document.querySelectorAll("[id^='status-']").forEach(elem => {
                    let ip = elem.id.replace("status-", ""); // Ambil IP dari id elemen
                    pingHost(ip); // Jalankan ping untuk setiap IP yang ditemukan di HTML
                  });
                };

                function openEditModal(button) {
                  var row = button.parentElement.parentElement;

                  fetchTrafficData(userId, ip, user, password);
                  var modal = new bootstrap.Modal(document.getElementById('editModal'));
                  modal.show();
                }



                let monitorUserId = ""; // Default user ID
                let ipServer = "";
                let userServer = "";
                let passwordServer = "";



                function openMonitorModal(button, userId, ip, user, password) {
                  monitorUserId = userId;
                  ipServer = ip;
                  userServer = user;
                  passwordServer = password;
                  var row = button.parentElement.parentElement;
                  document.getElementById("monitorNameID").textContent = row.cells[0].textContent;
                  document.getElementById("monitorProduct").textContent = row.cells[1].textContent;
                  document.getElementById("monitorPackages").textContent = row.cells[2].textContent;
                  document.getElementById("monitorStatus").textContent = row.cells[4].textContent;
                  document.getElementById("monitorAddress").textContent = row.cells[5].textContent;

                  fetchTrafficData(userId, ip, user, password);

                  var modal = new bootstrap.Modal(document.getElementById('monitorModal'));
                  modal.show();
                }

                function fetchTrafficData(userId, ipServer, userServer, passwordServer, idPel) {
                  fetch(`get_mikrotik_traffic.php?user=${userId}&ipserver=${ipServer}&userserver=${userServer}&passwordserver=${passwordServer}`)
                    .then(response => response.json())
                    .then(data => {
                      const ctx = document.getElementById(`trafficChart${idPel}`).getContext('2d');

                      if (window[`trafficChartInstance${idPel}`]) {
                        window[`trafficChartInstance${idPel}`].destroy();
                      }

                      console.log(data);
                      document.getElementById(`monitorTrafficResponse${idPel}`).textContent = JSON.stringify(data, null, 2);

                      window[`trafficChartInstance${idPel}`] = new Chart(ctx, {
                        type: 'bar',
                        data: {
                          labels: ['Download', 'Upload'],
                          datasets: [{
                            label: 'Mbps',
                            data: [data.download, data.upload],
                            backgroundColor: ['blue', 'green']
                          }]
                        },
                        options: {
                          responsive: true,
                          scales: {
                            y: {
                              beginAtZero: true
                            }
                          }
                        }
                      });
                    })
                    .catch(error => console.error('Error:', error));
                }

                setInterval(() => {
                  document.querySelectorAll('.modal.show').forEach(modal => {
                    const idPel = modal.id.replace('exampleoverview', '');
                    fetchTrafficData(monitorUserId, ipServer, userServer, passwordServer, idPel);
                  });
                }, 2000);



                function confirmDeleteUser(userId, status) {
                  let tables = [];
                  if (status.toUpperCase() === 'USER') {
                    tables = [
                      'pelanggan',
                      'transaksi (berdasarkan id_ep pelanggan)',
                      'odp',
                      'olt',
                      'paket',
                      'paket_hotspot',
                      'pelanggan_berhenti',
                      'promo_paket',
                      'voucher',
                      'notif_khusus',
                      'manual_bank',
                      'tripay',
                      'duitku',
                      'mitrans',
                      'xendit',
                      'pool',
                      'cables',
                      'assets',
                      'pengeluaran',
                      'apikey',
                      'belanja',
                      'mitra',
                      'transaksi_komisi (berdasarkan nama mitra)',
                      'network_device',
                      'botwa',
                      'server',
                      'user'
                    ];
                  } else {
                    tables = ['user'];
                  }
                  const message = 'Hapus user ini akan menghapus data dari tabel berikut:\n\n' + tables.join('\n') + '\n\nYakin ingin melanjutkan?';
                  return confirm(message);
                }
              </script>

            </div>
          </div>
        </div>
      </div>
    </div>




<?php require 'footer.php'; ?>