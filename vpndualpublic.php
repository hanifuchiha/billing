<?php require 'header.php'; ?>















    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6>Forwading server VPN</h6>
</div>

<br>
                        <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#dataModal">
                            Add VPN koneksi
                        </button>


                        <!-- Modal -->
                        <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="dataModalLabel">Tambah Data</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <form method="POST" action="proses/belivpn.php">
                                            <div class="mb-3">
                                                <label class="form-label">Username VPN</label>
                                                <input type="text" name="username" class="form-control" required>
                                            </div>
                                            <div class="mb-3">
                                                <br>
                                                default port MikroTik<br>
                                                - 80 => webfig <br>
                                                - 8291 => winbox<br>
                                                - 8728 => api<br>
                                                - 8729 => api-ssl<br>
                                                <label class="form-label">Port ( sesuaikan port yang akan di remot )</label>

                                                <input type="text" name="port" class="form-control" required>
                                            </div>


                                            <div class="mb-3">
                                                <label class="form-label">Paket bulanan (OPEN ALL PORT)</label>
                                                <select class="form-select" id="exampleFirstName"
                                                    name="paket" required>
                                                    <option disabled>Pilih paket</option>
                                                     <option value='FREE VPN'>FREE VPN</option>
                                                    <?php
                                                    $sql1 = "SELECT * from `paket` WHERE `PEMILIK` = 'VPNQ'";
                                                    $query1 = mysqli_query($conn, $sql1);
                                                    while ($data1 = mysqli_fetch_array($query1)) {
                                                    ?>
                                                        <option value="<?= $data1['PAKET'] ?>|<?= $data1['HARGA'] ?>"><?= $data1['PAKET'] ?> - <?= "Rp " . number_format($data1['HARGA'], 0, ',', '.'); ?></option>
                                                    <?php
                                                    }
                                                    ?>
                                                </select>
                                            </div>

                                        <div class="mb-3">
                                                <label class="form-label">Auto Perpanjang</label>
                                                <select name="tempo" class="form-select" required>
                                                    <option value="AUTO" selected>ya</option>

                                                    <option value="">tidak</option>

                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Service</label>
                                                <select name="service" class="form-select" required>
                                                    <option value="l2tp" selected>L2TP</option>

                                                    <option value="pptp">PPTP</option>

                                                </select>
                                            </div>
                                            <button type="submit" class="btn btn-success">Tambah User</button>
                                            <br>Patikan benar ini tidak dapat di ubah saat sudah di beli
                                        </form>

                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>


<body>
<?php 
// ambil konfigurasi
$config_file = 'config.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
// server utama
$ippublic = $config['ippublic'];
$webiplocal = $config['webiplocal'];
$router_ip= $config['router_ip'];

// Function to parse remote address formats
function parse_remote($s) {
    $s = trim($s);
    $res = ['ip' => null, 'port_in' => null, 'port_out' => null, 'raw' => $s];

    if ($s === '') return $res;

    // Split on ":"; mapping can be in part 2 or part 3 depending on format
    $parts = explode(':', $s);

    // IP is always the first part
    $res['ip'] = $parts[0];

    // Find the port mapping (contains "=>")
    $mapping = null;
    if (count($parts) >= 3) {
        // Format: IP:unused:4001=>8728 -> mapping in last part
        $mapping = $parts[count($parts) - 1];
    } elseif (count($parts) === 2) {
        // Format: IP:4001=>8728 -> mapping in second part
        $mapping = $parts[1];
    }

    if ($mapping !== null && $mapping !== '') {
        $mapping = trim($mapping);
        
        if (strpos($mapping, '=>') !== false) {
            list($in, $out) = explode('=>', $mapping, 2);
            $res['port_in']  = trim($in) !== '' ? trim($in) : null;
            $res['port_out'] = trim($out) !== '' ? trim($out) : null;
        } else {
            // Single port given (no =>)
            $res['port_in'] = $mapping;
        }
    }

    return $res;
}

// Query SQL
$sql = "SELECT * FROM pelanggan WHERE ODP LIKE 'VPNQ'";
$result = $conn->query($sql);

if ($result->num_rows > 0): ?>
  <div class="row">
    <?php while ($row = $result->fetch_assoc()):
      $id     = htmlspecialchars($row['id']);
      $mode   = htmlspecialchars($row['MODE']);
      $IPREMOT = $row['ALAMAT'];

      // Parse the remote address format using robust function
      // Handles both: IP:port1=>port2 and IP:unused:port1=>port2
      $parsed = parse_remote($IPREMOT);
      $ip_address = $parsed['ip'];      // e.g. 113.192.1.7
      $port_in    = $parsed['port_in']; // e.g. 4001 
      $port_out   = $parsed['port_out']; // e.g. 8728

      
      $user   = htmlspecialchars($row['IDPEL']);
      $pass   = htmlspecialchars($row['IDPEL']);
      $paket  = htmlspecialchars($row['PAKET']);
      $server_ip = $ippublic;
      $vpn_ip  = htmlspecialchars($row['TIKOR']);
      $tanggalPasang = htmlspecialchars($row['TANGGALPASANG']);

      $date = new DateTime($tanggalPasang);
      $date->modify('+30 days');
      $expired = $date->format('Y-m-d');
$domainaccept=$config['domain'];
      $script = "/interface $mode-client add name=$user connect-to=$ip_address user=$user password=$pass keepalive-timeout=60 use-peer-dns=no add-default-route=no dial-on-demand=no allow=mschap2,mschap1,chap,pap disabled=no comment=expired_$expired




/ip hotspot walled-garden
add dst-host=$domainaccept action=accept
add dst-host=www.$domainaccept action=accept
add dst-host=*.$domainaccept action=accept
";

      $script2 = '/ip/firewall/nat/add chain=dstnat action=dst-nat protocol=tcp dst-address=' . $vpn_ip . ' dst-port=' . $portremot . ' to-addresses=[GANTI IP PERANGKAT ANDA] to-ports=[PORT TUJUAN PERANGKAT ANDA] comment=REMOT TO LOKAL
';
    ?>

      <div class="col-12 col-md-6 mb-3">
        <div class="card dark-card">
          <div class="card-body">
            <h6 class="card-title mb-2 text-light">ID: <?= $id ?> | <?= $mode ?></h6>
            <!-- <p class="mb-1 text-light"><strong>IP VPN Client:</strong> <?= $vpn_ip ?></p> -->
            <p class="mb-1 text-light"><strong>VPN IP : </strong><?= $ip_address ?></p>
            <p class="mb-1 text-light"><strong>VPN PORT : </strong><?= $port_in ?></p>
            <p class="mb-1 text-light"><strong>REMOT PORT TO : </strong><?= $port_out ?></p>
            <p class="mb-1 text-light"><strong>User:</strong> <?= $user ?></p>
            <p class="mb-1 text-light"><strong>Pass:</strong> <?= $pass ?></p>
            <p class="mb-1 text-light"><strong>Paket:</strong> <?= $paket ?></p>
            <p class="mb-1 text-light"><strong>Expired:</strong> <?= $expired ?></p>
            <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modal<?= $id ?>">
              Lihat Script
            </button> 
            <form method="POST" action='proses/hapusvpn.php' onsubmit="return confirm('Yakin ingin menghapus data ini?');" class="d-inline">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="remot" value="<?= $IPREMOT ?>">
              <input type="hidden" name="user" value="<?= $user ?>">
              <input type="hidden" name="tipe" value="delete">
              <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal -->
      <div class="modal fade" id="modal<?= $id ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
              <h5 class="text-white">Script VPN - ID <?= $id ?></h5>
              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <p>COPY DAN PASTE KAN DI MIKROTIK VIA TERMINAL</p>
              <p>Script 1 (Untuk koneksikan VPN ke mikrotik anda)</p>
              <textarea class="form-control custom-textarea" rows="12" readonly><?= $script ?></textarea>
              <br>
              <p>Script 2 (Untuk forwarding ke lokal anda, contoh: OLT / NVR / dll — opsional)</p>
              <p class="text-danger">[GANTI IP PERANGKAT ANDA] = IP lokal yang ingin di-remote</p>
              <p class="text-danger">[PORT TUJUAN PERANGKAT ANDA] = PORT perangkat lokal</p>
              <textarea class="form-control custom-textarea" rows="12" readonly><?= $script2 ?></textarea>
            </div>
            <div class="modal-footer border-secondary">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
          </div>
        </div>
      </div>

    <?php endwhile; ?>
  </div>

<?php else: ?>
  <div class="alert alert-warning text-center">Tidak ada data ditemukan.</div>
<?php endif; ?>


<!-- Tambahkan style -->
<style>
/* CARD GELAP */


.dark-card {
  background: #fff !important;
  color: #0d6efd !important;
  border: 2px solid #0d6efd;
  border-radius: 12px;
  box-shadow: 0 6px 15px rgba(13, 110, 253, 0.15);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.dark-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px rgba(13, 110, 253, 0.25);
}

/* TEXTAREA GAYA TERMINAL */
.custom-textarea {
  background-color: #0d1117 !important; /* latar gelap */
  color: #00ff66 !important;            /* teks hijau */
  font-family: monospace;               /* gaya terminal */
  font-size: 14px;
  border: 1px solid #222;
  border-radius: 8px;
  padding: 10px;
  resize: vertical;
}
.custom-textarea:focus {
  outline: none;
  box-shadow: 0 0 10px rgba(0,255,100,0.5);
}

/* MODAL DARK */
.modal-content.bg-dark {
  background-color: #121417 !important;
}

/* Semua teks di dalam .dark-card biru tegas (header style) */
.dark-card .card-title,
.dark-card p,
.dark-card .mb-1,
.dark-card .card-body > .text-light,
.dark-card .card-body > p,
.dark-card .card-body .form-control[readonly],
.dark-card .text-danger,
.dark-card small,
.dark-card .small,
.dark-card label,
.dark-card strong,
.dark-card span,
.dark-card h6,
.dark-card h5,
.dark-card h4,
.dark-card h3,
.dark-card h2,
.dark-card h1 {
  color: #0d6efd !important;
  font-weight: 600;
}
</style>





                    </div>
                </div>
            </div>
        </div>
    </div>


<?php require 'footer.php'; ?>