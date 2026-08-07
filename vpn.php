<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Vpn_Connection', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Koneksi VPN.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>

<?php
// Notifikasi sukses atau gagal
$status = $_GET['statusnotif'] ?? '';
if ($status == 'cannot_delete'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>❌ Gagal!</strong> IP masih digunakan oleh server, tidak dapat dihapus.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php elseif ($status == 'success_delete'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <strong>✅ Berhasil!</strong> VPN berhasil dihapus.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php elseif ($status == 'sstp_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <strong>✅ Berhasil!</strong> SSTP server di router utama sudah diaktifkan/diset ke port 4433.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php elseif ($status == 'sstp_already'): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <strong>ℹ️ Info!</strong> SSTP server di router utama sudah aktif di port 4433.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php elseif ($status == 'sstp_failed'): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <strong>❌ Gagal!</strong> Tidak bisa konek ke router utama untuk cek/aktifkan SSTP server.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6>Forwading server VPN</h6>
                        <p class="text-muted small mb-0">Semua koneksi VPN di sini menggunakan protokol <strong>SSTP</strong> (port 4433) ke router utama. VPN ini dipakai untuk remote/manage perangkat MikroTik Anda dari jarak jauh lewat tunnel terenkripsi, dengan hingga 5 port layanan (4 port standar + 1 port custom untuk kebutuhan khusus) yang di-forward otomatis sesuai isian Anda saat membeli.</p>
                        </div>
                        <a type="button" class="btn btn-success" href="user.php">
                            Saldo
                            <?php
                            echo  $saldo = "Rp " . number_format($saldo, 0, ',', '.');
                            ?>
                        </a>
                        <a type="button" class="btn btn-warning" href="tambahsaldo.php">
                            Tambah saldo

                        </a>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dataModal">
                            Beli VPN koneksi
                        </button>
                        <?php if (($AKSES ?? '') === 'ADMIN'): ?>
                        <form method="POST" action="proses/cek_sstp_server.php" class="d-inline">
                            <input type="hidden" name="page" value="vpn">
                            <button type="submit" class="btn btn-outline-primary">Cek &amp; Aktifkan SSTP Server (Port 4433)</button>
                        </form>
                        <?php endif; ?>


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
                                                <input type="text" name="username" class="form-control" pattern="[A-Za-z0-9_-]+" title="Hanya huruf, angka, - dan _ (tanpa spasi)" required>
                                                <div class="form-text">Tanpa spasi/simbol lain, contoh: <code>cabang-bekasi</code></div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label">Port Layanan (isi manual, kosongkan yang tidak dipakai)</label>
                                                <div class="form-text mb-2">Port-port ini akan di-forward otomatis dari server ke MikroTik Anda sesuai isian di bawah. 4 port pertama default mengikuti port bawaan MikroTik (boleh diubah), ditambah 1 port custom opsional untuk kebutuhan khusus lain kalau diperlukan.</div>
                                                <div class="row g-2">
                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label small mb-1">Port 1 (webfig)</label>
                                                        <input type="number" name="port1" class="form-control form-control-sm" value="80" required>
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label small mb-1">Port 2 (winbox)</label>
                                                        <input type="number" name="port2" class="form-control form-control-sm" value="8291">
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label small mb-1">Port 3 (api)</label>
                                                        <input type="number" name="port3" class="form-control form-control-sm" value="8728">
                                                    </div>
                                                    <div class="col-6 col-md-3">
                                                        <label class="form-label small mb-1">Port 4 (api-ssl)</label>
                                                        <input type="number" name="port4" class="form-control form-control-sm" value="8729">
                                                    </div>
                                                </div>
                                                <div class="row g-2 mt-1">
                                                    <div class="col-12 col-md-6">
                                                        <label class="form-label small mb-1">Port Custom (opsional, isi hanya jika ada kebutuhan khusus lain)</label>
                                                        <input type="number" name="port5" class="form-control form-control-sm" placeholder="Kosongkan jika tidak perlu">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Keterangan (VPN ini dipakai untuk apa)</label>
                                                <textarea name="keterangan" class="form-control" rows="2" placeholder="Contoh: Remote MikroTik cabang Bekasi" required></textarea>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">Paket bulanan (OPEN ALL PORT)</label>
                                                <select class="form-select" id="exampleFirstName"
                                                    name="paket" required>
                                                    <option disabled>Pilih paket</option>
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
                                                <p class="text-muted small mb-0">Service: <strong>SSTP</strong> (port 4433) — otomatis, tidak bisa dipilih.</p>
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

// Parse mapping port dari kolom ALAMAT. Sekarang bisa berisi lebih dari 1 pasangan
// port sekaligus (sampai 4 port layanan), format: IP:pub1=>dst1,pub2=>dst2,...
// Tetap kompatibel dengan format lama: IP:port1=>port2 dan IP:unused:port1=>port2
function parse_remote_multi($s) {
    $s = trim($s);
    $res = ['ip' => null, 'pairs' => [], 'raw' => $s];

    if ($s === '') return $res;

    $colonPos = strpos($s, ':');
    if ($colonPos === false) {
        $res['ip'] = $s;
        return $res;
    }

    $res['ip'] = substr($s, 0, $colonPos);
    $mappingPart = substr($s, $colonPos + 1);

    // Format lama 3 bagian: IP:unused:port1=>port2 -> ambil bagian setelah ":" terakhir
    if (strpos($mappingPart, ':') !== false) {
        $segments = explode(':', $mappingPart);
        $mappingPart = end($segments);
    }

    foreach (explode(',', $mappingPart) as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;

        if (strpos($pair, '=>') !== false) {
            list($in, $out) = explode('=>', $pair, 2);
            $res['pairs'][] = ['in' => trim($in), 'out' => trim($out)];
        } else {
            $res['pairs'][] = ['in' => $pair, 'out' => null];
        }
    }

    return $res;
}

// Query SQL
$sql = "SELECT * FROM pelanggan WHERE BRAND LIKE 'VPN' and NAMA ='$ceknama'";
$result = $conn->query($sql);

if ($result->num_rows > 0): ?>
  <div class="row">
    <?php while ($row = $result->fetch_assoc()):
      $id     = htmlspecialchars($row['id']);
      $IPREMOT = $row['ALAMAT'];

      // Parse mapping port (bisa sampai 4 pasang: publik => tujuan)
      $parsed = parse_remote_multi($IPREMOT);
      $ip_address = $parsed['ip'];   // e.g. 113.192.1.7
      $pairs      = $parsed['pairs']; // [['in'=>4001,'out'=>8728], ...]

      $user   = htmlspecialchars($row['IDPEL']);
      $pass   = htmlspecialchars($row['IDPEL']);
      $paket  = htmlspecialchars($row['PAKET']);
      $server_ip = $ippublic;
      $vpn_ip  = htmlspecialchars($row['TIKOR']);
      $keterangan = htmlspecialchars($row['KETERANGAN'] ?? '');
      $tanggalPasang = htmlspecialchars($row['TANGGALPASANG']);

      $date = new DateTime($tanggalPasang);
      $date->modify('+30 days');
      $expired = $date->format('Y-m-d');
$domainaccept=$config['domain'];

$radius_ip=$config['radius_ip'];
$radius_password=$config['radius_password'];
$radius_source=$config['radius_source'];

// Semua koneksi VPN sekarang pakai SSTP saja (port 4433).
$scriptSstp = "/interface sstp-client add name=\"$user\" connect-to=$ip_address port=4433 user=\"$user\" password=\"$pass\" profile=default-encryption verify-server-certificate=no verify-server-address-from-certificate=no authentication=mschap2 add-default-route=no dial-on-demand=no disabled=no comment=\"expired_$expired\"\n\n";

// Tambahkan route jika radius_source local
if (($config['radius_source'] ?? 'local') === 'local') {
  $scriptSstp .= "/ip route add dst-address=$radius_ip gateway=\"$user\"\n\n";
}

$scriptSstp .= "/ip hotspot walled-garden\n";
$scriptSstp .= "add dst-host=$domainaccept action=allow\n";
$scriptSstp .= "add dst-host=www.$domainaccept action=allow\n";
$scriptSstp .= "add dst-host=*.$domainaccept action=allow\n";

      // Script forward tambahan (opsional) - 1 baris per port layanan yang dibeli,
      // dijalankan sendiri oleh customer di MikroTik-nya kalau mau diteruskan lagi
      // ke perangkat lokal (mis. OLT / NVR).
      $script2 = '';
      foreach ($pairs as $pair) {
          if ($pair['out'] === null) continue;
          $script2 .= '/ip/firewall/nat/add chain=dstnat action=dst-nat protocol=tcp dst-address=' . $vpn_ip . ' dst-port=' . $pair['out'] . ' to-addresses=[GANTI IP PERANGKAT ANDA] to-ports=[PORT TUJUAN PERANGKAT ANDA] comment="REMOT TO LOKAL - PORT ' . $pair['out'] . "\"\n";
      }
    ?>

      <div class="col-12 col-md-6 mb-3">
        <div class="card dark-card">
          <div class="card-body">
            <h6 class="card-title mb-2 text-light">ID: <?= $id ?> | SSTP</h6>
            <p class="mb-1 text-light"><strong>VPN IP : </strong><?= $ip_address ?>  ( Gunakan ini untuk add server kolom IP, port SSTP 4433 ) </p>
            <p class="mb-1 text-light"><strong>User:</strong> <?= $user ?></p>
            <p class="mb-1 text-light"><strong>Pass:</strong> <?= $pass ?></p>
            <p class="mb-1 text-light"><strong>Paket:</strong> <?= $paket ?></p>
            <p class="mb-1 text-light"><strong>Expired:</strong> <?= $expired ?></p>
            <p class="mb-1 text-light"><strong>Keterangan:</strong> <?= $keterangan !== '' ? $keterangan : '-' ?></p>
            <?php if (!empty($pairs)): ?>
            <p class="mb-1 text-light"><strong>Port Layanan (forward otomatis):</strong></p>
            <ul class="mb-1 text-light ps-3">
              <?php foreach ($pairs as $pair): ?>
                <li>Port publik <strong><?= htmlspecialchars($pair['in']) ?></strong> &rarr; Port <?= htmlspecialchars($pair['out'] ?? '-') ?> di MikroTik Anda</li>
              <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm mt-2" data-bs-toggle="modal" data-bs-target="#modal<?= $id ?>">
              Lihat Script
            </button>
            <form method="POST" action='proses/hapusvpn.php' onsubmit="return confirm('Yakin ingin menghapus data ini?');" class="d-inline">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="remot" value="<?= $IPREMOT ?>">
              <input type="hidden" name="user" value="<?= $user ?>">
              <input type="hidden" name="tipe" value="delete">
              <input type="hidden" name="page" value="vpn">
              <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
            </form>
          </div>
        </div>
      </div>


      <!-- Modal -->
      <div class="modal fade vpn-code-modal" id="modal<?= $id ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Script VPN - ID <?= $id ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <p class="text-muted mb-2"><strong>Keterangan:</strong> <?= $keterangan !== '' ? $keterangan : 'Tidak ada keterangan' ?></p>
              <p>Copy dan paste script di bawah ini di MikroTik Anda via Terminal.</p>
              <p class="text-muted small mb-2">Script sama dan kompatibel untuk RouterOS <strong>v6</strong> maupun <strong>v7</strong>.</p>

              <?php
              ob_start();
              ?>
              <h6>Script 1 &mdash; Sambungkan MikroTik Anda ke VPN (SSTP, port 4433)</h6>
              <p class="text-muted">Jalankan di MikroTik yang ingin disambungkan ke server VPN pusat.</p>
              <div class="code-label code-label-request">SSTP Client</div>
              <pre><code><?= htmlspecialchars($scriptSstp) ?></code></pre>

              <?php if ($script2 !== ''): ?>
              <h6>Script 2 &mdash; Forward port layanan ke perangkat lokal Anda (opsional)</h6>
              <p class="text-muted">Jalankan sendiri di MikroTik Anda kalau ingin meneruskan salah satu port di atas ke perangkat lain di jaringan lokal (contoh: OLT / NVR). Ganti <code>[GANTI IP PERANGKAT ANDA]</code> dan <code>[PORT TUJUAN PERANGKAT ANDA]</code> sesuai perangkat tujuan.</p>
              <div class="code-label code-label-response">Port Forward</div>
              <pre><code><?= htmlspecialchars($script2) ?></code></pre>
              <?php endif; ?>
              <?php $scriptBlocksHtml = ob_get_clean(); ?>

              <ul class="nav nav-pills ros-version-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active" id="ros-v6-tab-<?= $id ?>" data-bs-toggle="pill" data-bs-target="#ros-v6-<?= $id ?>" type="button" role="tab">RouterOS v6</button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link" id="ros-v7-tab-<?= $id ?>" data-bs-toggle="pill" data-bs-target="#ros-v7-<?= $id ?>" type="button" role="tab">RouterOS v7</button>
                </li>
              </ul>
              <div class="tab-content">
                <div class="tab-pane fade show active" id="ros-v6-<?= $id ?>" role="tabpanel"><?= $scriptBlocksHtml ?></div>
                <div class="tab-pane fade" id="ros-v7-<?= $id ?>" role="tabpanel"><?= $scriptBlocksHtml ?></div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
          </div>
        </div>
      </div>

      <?php endwhile; ?>
    </div>

  <?php else: ?>
    <div class="alert alert-warning text-center shadow-sm rounded-3">Tidak ada data ditemukan.</div>
  <?php endif; ?>
</div>

<!-- Tambahkan style -->
<style>
/* CARD RINGKASAN VPN */
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

/* Semua teks di dalam .dark-card hitam tegas (header style) */
.dark-card .card-title,
.dark-card p,
.dark-card .mb-1,
.dark-card li,
.dark-card .card-body > .text-light,
.dark-card .card-body > p,
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
  color: #000000 !important;
  font-weight: 600;
}

/* CARD CODE untuk modal script (bg biasa/terang, bukan hitam + teks hijau) - gaya sama seperti menu Pengaturan API */
.vpn-code-modal pre {
  position: relative;
  background: #1e1e2e;
  color: #cdd6f4;
  padding: 34px 16px 16px;
  border-radius: 10px;
  overflow-x: auto;
  margin-bottom: 1.25rem;
  font-size: 13px;
  line-height: 1.6;
  border: 1px solid #313244;
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.18);
}
.vpn-code-modal pre::before {
  content: "";
  position: absolute;
  top: 12px;
  left: 14px;
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: #ff5f56;
  box-shadow: 18px 0 0 #ffbd2e, 36px 0 0 #27c93f;
}
.vpn-code-modal pre code {
  background: none;
  color: inherit;
  padding: 0;
  font-size: inherit;
  white-space: pre-wrap;
  word-break: break-word;
}
.vpn-code-modal .code-label {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 3px 10px;
  border-radius: 5px 5px 0 0;
  margin: 0 0 -6px 4px;
  position: relative;
  z-index: 1;
}
.vpn-code-modal .code-label-request { background: #2563eb; color: #fff; }
.vpn-code-modal .code-label-response { background: #16a34a; color: #fff; }
</style>




                    </div>
                </div>
            </div>
        </div>
    </div>



<?php require 'footer.php'; ?>
