
<?php require 'header.php';



require 'koneksipromosi.php';  // koneksi target: $conn
require 'koneksidbabsensi2.php';  // koneksi sumber: $conn2


// Ambil semua dari joblist
$joblist = $conn2->query("SELECT nowa, data FROM joblist ORDER BY nowa");

$seen = []; // untuk menyaring yang unik
$uniqueData = [];

while ($row = $joblist->fetch_assoc()) {
  $nowa = preg_replace('/[^0-9]/', '', $row['nowa']); // bersihkan nomor jadi hanya angka
  $data = $row['data'];

  if (!empty($nowa) && !in_array($nowa, $seen)) {
    $seen[] = $nowa;
    $uniqueData[] = [
      'nowa' => $nowa,
      'data' => $data
    ];
  }
}

// Masukkan ke database jika belum ada
$inserted = 0;
foreach ($uniqueData as $row) {
  $nowa = $conn->real_escape_string($row['nowa']);
  $data = $conn->real_escape_string($row['data']);

  $cek = $conn->query("SELECT 1 FROM pelanggan WHERE NOWA = '$nowa' LIMIT 1");
  if ($cek->num_rows == 0) {
    $conn->query("INSERT INTO pelanggan (NOWA, NAMA) VALUES ('$nowa', '$data')");
    $inserted++;
  }
}



// =======================
// Hapus Duplikat di Tabel Pelanggan (sisakan 1)
// =======================


$result = $conn->query("SELECT id, NOWA FROM pelanggan ORDER BY NOWA ASC");

$nomorMap = [];
while ($row = $result->fetch_assoc()) {
  $id   = $row['id'];
  $nowa = preg_replace('/[^0-9]/', '', $row['NOWA']); // bersihkan lagi, untuk jaga-jaga

  if (!isset($nomorMap[$nowa])) {
    $nomorMap[$nowa] = [];
  }
  $nomorMap[$nowa][] = $id;
}

$totalDuplikat = 0;
$totalDihapus = 0;

foreach ($nomorMap as $nowa => $listID) {
  if (count($listID) > 1) {
    $totalDuplikat++;

    sort($listID);
    array_shift($listID); // buang 1 ID paling awal

    $idToDelete = implode(",", $listID);
    $conn->query("DELETE FROM pelanggan WHERE id IN ($idToDelete)");
    $totalDihapus += count($listID);

    echo "🗑️ Hapus " . count($listID) . " duplikat untuk nomor: $nowa<br>";
  }
}




// ✅ PROSES TAMBAH PELANGGAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tambah'])) {
  $nama = $_POST['nama'] ?? '';
  $nowa = $_POST['nowa'] ?? '';
  $pemilik = $_POST['pemilik'] ?? '';

  if ($nama && $nowa && $pemilik) {
    $stmt = $conn->prepare("INSERT INTO pelanggan (NAMA, NOWA, PEMILIK) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $nama, $nowa, $pemilik);
    $stmt->execute();
    header("Location: broadcast.php?pemilik=" . urlencode($pemilik));
    exit;
  }
}

if (isset($_POST['upload']) && isset($_FILES['vcf_file'])) {
  $file_tmp = $_FILES['vcf_file']['tmp_name'];

  if (!file_exists($file_tmp)) {
    die("❌ File tidak ditemukan.");
  }

  // Koneksi ke DB
  $conn = new mysqli("localhost", "root", "", "nama_database_kamu");
  if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
  }

  $vcf_lines = file($file_tmp);
  $nama = $nowa = "";
  $total = 0;

  foreach ($vcf_lines as $line) {
    $line = trim($line);

    if (stripos($line, "FN:") === 0) {
      $nama = substr($line, 3); // ambil nama
    }

    if (stripos($line, "TEL") !== false && strpos($line, ":") !== false) {
      $nowa = explode(":", $line)[1];

      // Normalisasi nomor HP
      $nowa = preg_replace('/[^0-9]/', '', $nowa);
      if (substr($nowa, 0, 2) == "62") {
        $nowa = "0" . substr($nowa, 2);
      }

      // Set kolom lain otomatis
      $idpel         = $conn->real_escape_string($nama);
      $password      = password_hash($nama, PASSWORD_DEFAULT);
      $pemilik       = "UPLOAD";
      $paket         = $idpel;
      $harga         = 0;
      $tanggalpasang = date('Y-m-d');
      $email         = "";
      $alamat        = "ksong";
      $tempo         = "kosong";
      $mode          = "";
      $odp           = "KOSONG";
      $area          = "KOSONG";

      // Cegah duplikat berdasarkan nowa
      $cek = $conn->query("SELECT * FROM pelanggan WHERE nowa='$nowa'");
      if ($cek->num_rows == 0) {
        $sql = "INSERT INTO pelanggan 
                        (idpel, nama, nowa, pemilik, password, paket, harga, tanggalpasang, email, alamat, tempo, mode, odp, area)
                        VALUES 
                        ('$idpel', '$nama', '$nowa', '$pemilik', '$password', '$paket', '$harga', '$tanggalpasang', '$email', '$alamat', '$tempo', '$mode', '$odp', '$area')";

        if ($conn->query($sql) === TRUE) {
          $total++;
        }
      }

      // reset nama & nowa untuk VCard berikutnya
      $nama = $nowa = "";
    }
  }

  echo "✅ Berhasil menyimpan $total kontak dari file VCF.";
  $conn->close();
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $nama    = $conn->real_escape_string($_POST['nama']);
  $nowa    = $conn->real_escape_string($_POST['nowa']);
  $pemilik = $conn->real_escape_string($_POST['pemilik']);

  // Isi otomatis kolom yang wajib
  $idpel         = $nama;
  $password      = password_hash($nama, PASSWORD_DEFAULT);
  $paket         = $nama;
  $harga         = 0;
  $tanggalpasang = date('Y-m-d');
  $email         = "kosong";
  $alamat        = "kosong";
  $tempo         = "kosong";
  $mode          = "kosong";
  $odp           = "kosong"; // penting: ini untuk atasi error


  if (!empty($nama) && !empty($nowa) && !empty($pemilik)) {
    $sql = "INSERT INTO pelanggan 
                (idpel, nama, nowa, pemilik, password, paket, harga, tanggalpasang, email, alamat, tempo, mode, odp)
                VALUES 
                ('$idpel', '$nama', '$nowa', '$pemilik', '$password', '$paket', '$harga', '$tanggalpasang', '$email', '$alamat', '$tempo', '$mode', '$odp')";

    if ($conn->query($sql) === TRUE) {
      echo "✅ Data berhasil disimpan!";
    } else {
      echo "❌ Error saat menyimpan: " . $conn->error;
    }
  } else {
    echo "❗ Semua field wajib diisi.";
  }
}

// Ambil daftar PEMILIK untuk filter
$pemilik_list = $conn->query("SELECT DISTINCT PEMILIK FROM pelanggan ORDER BY PEMILIK");
$filter_pemilik = $_GET['pemilik'] ?? '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$start_index = ($page - 1) * $limit;

// Ambil data pelanggan sesuai filter
$query = "SELECT NAMA, NOWA FROM pelanggan";
if ($filter_pemilik !== '') {
  $safe_pemilik = $conn->real_escape_string($filter_pemilik);
  $query .= " WHERE PEMILIK = '$safe_pemilik'";
}
$result = $conn->query($query);

// Format nomor WA
function format_wa($no)
{
  $no = preg_replace('/[^0-9+]/', '', $no);
  if (substr($no, 0, 1) == '+') $no = substr($no, 1);
  if (substr($no, 0, 2) == '62') return $no;
  if (substr($no, 0, 1) == '0') return '62' . substr($no, 1);
  if (substr($no, 0, 1) == '8') return '628' . substr($no, 1);
  return '';
}

function extract_whatsapp_numbers($string)
{
  $string = str_replace([';', ',', ' '], "\n", $string);
  $lines = explode("\n", $string);
  $numbers = [];
  foreach ($lines as $line) {
    $formatted = format_wa(trim($line));
    if (preg_match('/^62[0-9]{9,13}$/', $formatted)) {
      $numbers[] = $formatted;
    }
  }
  return array_unique($numbers);
}

// Ambil data valid
$all_data = [];
foreach ($result as $row) {
  $nama = $row['NAMA'];
  $list = extract_whatsapp_numbers($row['NOWA']);
  foreach ($list as $wa) {
    $all_data[] = ['NAMA' => $nama, 'NOWA' => $wa];
  }
}
$total_data = count($all_data);
$total_pages = ceil($total_data / $limit);
$paged_data = array_slice($all_data, $start_index, $limit);





  ?>



  <div class="container-fluid py-4">
    <div class="row">
      <div class="col-12">
        <div class="card mb-4">
          <div class="card-header pb-0">
            <h6>User crm billing</h6>



            <!-- Tombol Tambah Kontak -->
            <button type="button" class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#dataModal">
              ➕ Add Contact
            </button>

            <!-- Modal Tambah Kontak -->
            <div class="modal fade" id="dataModal" tabindex="-1" aria-labelledby="dataModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <form method="post">
                  <input type="hidden" name="tambah_kontak" value="1">
                  <div class="modal-content">
                    <div class="modal-header">
                      <h5 class="modal-title" id="dataModalLabel">Tambah Kontak Baru</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <div class="mb-3">
                        <label for="nama" class="form-label">Name</label>
                        <input type="text" class="form-control" name="nama" required>
                      </div>
                      <div class="mb-3">
                        <label for="nowa" class="form-label">Whatsapp number</label>
                        <input type="text" class="form-control" name="nowa" required>
                      </div>

                      <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <textarea class="form-control" name="email"></textarea>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                      <button type="submit" class="btn btn-primary">Simpan Kontak</button>
                    </div>
                  </div>
                </form>
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
              </style>






              <div class="container">
             <!-- === FULLSCREEN LOADING OVERLAY === -->
  <div style="display: none;" id="loadingOverlay" class="d-flex text-center" style="
  display: none;
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(255,255,255,0.9);
  backdrop-filter: blur(4px);
  z-index: 9999;
  justify-content: center;
  align-items: center;
  flex-direction: column;
  font-size: 1rem;
">
    <div style="display: none;" id="textloading" class="spinner-border text-primary mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
    <strong style="display: none;" id="textloading2">⏳ Mengirim broadcast... Mohon tunggu</strong>
  </div>

  <div class="container mt-3" style="font-size: 0.85rem;">
    <!-- SWITCH TOGGLE -->
    <div class="text-center mb-3">
      <div class="btn-group btn-group-sm">
        <button id="btnTambah" class="btn btn-outline-primary" onclick="showSection('form')">➕ Tambah Kontak</button>
        <button id="btnBroadcast" class="btn btn-outline-secondary active" onclick="showSection('broadcast')">📢 Broadcast</button>
      </div>
    </div>

    <!-- === FORM TAMBAH KONTAK === -->
    <div id="formSection" style="display: none;">
      <div class="card mb-3">
        <div class="card-header py-2 px-3">➕ Tambah Kontak</div>
        <div class="card-body py-2 px-3">
          <form method="post">
            <div class="row">
              <div class="form-group col-md-4 mb-2">
                <label>Nama</label>
                <input type="text" name="nama" class="form-control form-control-sm" required>
              </div>
              <div class="form-group col-md-4 mb-2">
                <label>Nomor WhatsApp</label>
                <input type="text" name="nowa" class="form-control form-control-sm" required>
              </div>
              <div class="form-group col-md-4 mb-2">
                <label>Pemilik</label>
                <input type="text" name="pemilik" class="form-control form-control-sm" required>
              </div>
            </div>
            <button type="submit" class="btn btn-success btn-sm">✅ Simpan</button>
          </form>
        </div>
      </div>

      <!-- UPLOAD VCF -->
      <div class="card mb-3">
        <div class="card-header py-2 px-3">📎 Upload Kontak .VCF</div>
        <div class="card-body py-2 px-3">
          <form method="post" enctype="multipart/form-data">
            <div class="form-group mb-2">
              <input type="file" name="vcf_file" accept=".vcf" required class="form-control-file form-control-sm">
            </div>
            <button type="submit" name="upload" class="btn btn-success btn-sm">📥 Upload</button>
          </form>
        </div>
      </div>
    </div>

    <!-- === BROADCAST SECTION === -->
    <div id="broadcastSection">
      <div class="card mb-3">
        <div class="card-header py-2 px-3">📢 Broadcast WhatsApp</div>
        <div class="card-body py-2 px-3">

          <!-- FILTER PEMILIK -->
          <form method="get" class="form-inline mb-2">
            <label class="mr-2">Pemilik:</label>
            <select name="pemilik" class="form-control form-control-sm mr-2" onchange="this.form.submit()">
              <option value="">-- Semua --</option>
              <?php while ($p = $pemilik_list->fetch_assoc()): ?>
                <option value="<?= htmlspecialchars($p['PEMILIK']) ?>" <?= $p['PEMILIK'] == $filter_pemilik ? 'selected' : '' ?>>
                  <?= htmlspecialchars($p['PEMILIK']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </form>

          <!-- TABEL DATA -->
          <div class="table-responsive">
            <table class="table table-bordered table-sm table-striped mb-2">
              <thead class="thead-light">
                <tr style="font-size: 0.8rem;">
                  <th>No</th>
                  <th>Nama</th>
                  <th>Nomor</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($paged_data as $i => $row): ?>
                  <tr>
                    <td><?= $start_index + $i + 1 ?></td>
                    <td><?= htmlspecialchars($row['NAMA']) ?></td>
                    <td><?= $row['NOWA'] ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (count($paged_data) === 0): ?>
                  <tr>
                    <td colspan="3" class="text-center text-muted">Tidak ada data</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- PAGINATION -->
          <nav class="mt-2">
            <ul class="pagination pagination-sm justify-content-center mb-0">
              <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                  <a class="page-link" href="?page=<?= $i ?>&pemilik=<?= urlencode($filter_pemilik) ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
            </ul>
          </nav>

          <!-- FORM KIRIM BROADCAST -->
          <form method="post" action="proses_broadcast_semua.php" onsubmit="return showLoading();">
            <select required class="form-control" id="botname" name="botname" onchange="loadArea()">
              <option value="">-- Pilih BOT --</option>
              <?php
              require 'koneksibilling.php';

              $query = "SELECT DISTINCT namebot FROM `botwa`  WHERE 1";
              $result = mysqli_query($conn, $query);
              while ($row = mysqli_fetch_assoc($result)) {
                $namebot = htmlspecialchars($row['namebot'], ENT_QUOTES, 'UTF-8');
                echo '<option value="' . $namebot . '">' . $namebot . '</option>';
              }
              ?>
            </select>
            <input type="hidden" name="pemilik" value="<?= htmlspecialchars($filter_pemilik) ?>">
            <div class="form-group mb-2">
              <label>Isi Pesan (<?= (int)$total_data ?> nomor):</label>
              <textarea id="pesanInput" name="pesan" class="form-control form-control-sm" rows="3" placeholder="Tulis pesan..." required></textarea>
            </div>
            <button id="submitBtn" type="submit" class="btn btn-danger btn-sm">🚀 Kirim</button>
          </form>

        </div>
      </div>
    </div>
  </div>

  <!-- === JAVASCRIPT === -->
  <script>
    function showSection(section) {
      const formSection = document.getElementById('formSection');
      const broadcastSection = document.getElementById('broadcastSection');
      const btnTambah = document.getElementById('btnTambah');
      const btnBroadcast = document.getElementById('btnBroadcast');

      if (section === 'form') {
        formSection.style.display = 'block';
        broadcastSection.style.display = 'none';
        btnTambah.classList.add('active');
        btnBroadcast.classList.remove('active');
      } else {
        formSection.style.display = 'none';
        broadcastSection.style.display = 'block';
        btnTambah.classList.remove('active');
        btnBroadcast.classList.add('active');
      }
    }

    function showLoading() {
      const pesan = document.getElementById("pesanInput").value.trim();
      if (pesan === "") {
        alert("Isi pesan tidak boleh kosong.");
        return false;
      }

      document.getElementById("loadingOverlay").style.display = "block";
      document.getElementById("textloading").style.display = "block";
      document.getElementById("textloading2").style.display = "block";
      const btn = document.getElementById("submitBtn");
      btn.disabled = true;
      btn.innerHTML = "⏳ Mengirim...";
      return true;
    }
  </script>




            </div>
          </div>
        </div>
      </div>
    </div>



<?php require 'footer.php'; ?>