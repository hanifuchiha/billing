<?php 
require 'header.php';
session_start();

// === Inisialisasi variabel ===
$edit_id = $_GET['edit'] ?? null;

// === Handle form submit (Tambah atau Update) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_bank = mysqli_real_escape_string($conn, $_POST['nama_bank']);
    $nama_pemilik_bank = mysqli_real_escape_string($conn, $_POST['nama_pemilik_bank']);
    $rekening_bank = mysqli_real_escape_string($conn, $_POST['rekening_bank']);
    $server = mysqli_real_escape_string($conn, $_POST['server']);

    if (!empty($_POST['id'])) {
        // Update data
        $id = intval($_POST['id']);
        $sql = "UPDATE manualbank SET 
                    nama_bank='$nama_bank',
                    nama_pemilik_bank='$nama_pemilik_bank',
                    rekening_bank='$rekening_bank',
                    server='$server'
                WHERE id=$id AND pemilik='".mysqli_real_escape_string($conn, $username)."'";
        mysqli_query($conn, $sql);
        echo "<script>alert('Data berhasil diperbarui'); window.location='';</script>";
    } else {
        // Tambah data baru
        $sql = "INSERT INTO manualbank (nama_bank, nama_pemilik_bank, rekening_bank, pemilik, server)
                VALUES ('$nama_bank','$nama_pemilik_bank','$rekening_bank','$username','$server')";
        mysqli_query($conn, $sql);
        echo "<script>alert('Data berhasil ditambahkan'); window.location='';</script>";
    }
}

// === Handle hapus ===
if (isset($_GET['hapus'])) {
    $id_hapus = intval($_GET['hapus']);
    $del = "DELETE FROM manualbank WHERE id = $id_hapus AND pemilik = '".mysqli_real_escape_string($conn, $username)."'";
    mysqli_query($conn, $del);
    echo "<script>alert('Data berhasil dihapus'); window.location='';</script>";
}

// === Ambil data untuk edit jika ada ===
$edit_data = null;
if ($edit_id) {
    $q = mysqli_query($conn, "SELECT * FROM manualbank WHERE id = ".intval($edit_id)." AND pemilik='".mysqli_real_escape_string($conn, $username)."'");
    $edit_data = mysqli_fetch_assoc($q);
    echo "<script>alert('Data berhasil edit'); window.location='';</script>";
}

// === Ambil semua data pemilik ini ===
$sql = "SELECT * FROM manualbank WHERE pemilik='".mysqli_real_escape_string($conn, $username)."'";
$result = mysqli_query($conn, $sql);
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">

                <style>
                    table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                    th, td { padding: 8px 12px; border: 1px solid #ccc; text-align: left; }
                    th { background-color: #f2f2f2; }
                    tr:nth-child(even){ background-color: #f9f9f9; }
                    form { margin-top: 20px; }
                    input[type=text], input[type=hidden], select { padding: 6px; width: 250px; margin-right: 10px; }
                    input[type=submit] { padding: 6px 12px; }
                    a { text-decoration: none; margin: 0 5px; }
                </style>
<h2>Informasi bank penerima pembayaran pelanggan <br>bank Pemilik: <?php echo htmlspecialchars($username); ?>  ( JIKA TIDAK MENGAKTIFKAN TRIPAY ) </h2>
               
                <h3 style="color: red; font-weight: bold;">
                    &#9888; Pihak billing tidak bertanggung jawab atas kesalahan data informasi bank penerima, silakan isi dengan benar!
                </h3>

                <!-- FORM INPUT / EDIT -->
                <form method="post">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_data['id']; ?>">
                    <?php endif; ?>

                    <input type="text" name="nama_bank" placeholder="Nama Bank" required value="<?php echo $edit_data['nama_bank'] ?? ''; ?>">
                    <input type="text" name="nama_pemilik_bank" placeholder="Nama Pemilik Bank" required value="<?php echo $edit_data['nama_pemilik_bank'] ?? ''; ?>">
                    <input type="text" name="rekening_bank" placeholder="Rekening Bank" required value="<?php echo $edit_data['rekening_bank'] ?? ''; ?>">

                    <select required id="server" name="server">
                        <option value="">-- Pilih Server --</option>
                        <?php
                        $query = "SELECT DISTINCT PEMILIK FROM server WHERE pemilik IN ($server_list)";
                        $server_result = mysqli_query($conn, $query);
                        while ($row_server = mysqli_fetch_assoc($server_result)) {
                            $pemilik = htmlspecialchars($row_server['PEMILIK'], ENT_QUOTES, 'UTF-8');
                            $selected = ($edit_data && $edit_data['server'] == $pemilik) ? 'selected' : '';
                            echo '<option value="' . $pemilik . '" ' . $selected . '>' . $pemilik . '</option>';
                        }
                        ?>
                    </select>

                    <input type="submit" value="<?php echo $edit_data ? 'Update Data' : 'Tambah Data'; ?>">
                    <?php if ($edit_data): ?>
                        <a href="" style="color: gray;">Batal</a>
                    <?php endif; ?>
                </form>

                <!-- TABEL DATA -->
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nama Bank</th>
                            <th>Nama Pemilik Bank</th>
                            <th>Rekening Bank</th>
                            <th>Server</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (mysqli_num_rows($result) > 0) {
                            while ($row = mysqli_fetch_assoc($result)) {
                                echo "<tr>
                                    <td>{$row['id']}</td>
                                    <td>{$row['nama_bank']}</td>
                                    <td>{$row['nama_pemilik_bank']}</td>
                                    <td>{$row['rekening_bank']}</td>
                                    <td>{$row['server']}</td>
                                    <td>
                                        <a href='?edit={$row['id']}' style='color: blue;'>Edit</a> | 
                                        <a href='?hapus={$row['id']}' style='color: red;' onclick=\"return confirm('Yakin ingin menghapus data ini?')\">Hapus</a>
                                    </td>
                                </tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6'>Tidak ada data</td></tr>";
                        }
                        mysqli_close($conn);
                        ?>
                    </tbody>
                </table>

            </div>
        </div>
    </div>
</div>

<?php require 'footer.php'; ?>
