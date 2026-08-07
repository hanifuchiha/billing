<?php
include 'koneksidb.php';
$idpel = $_GET['idpel'];
$sql = "SELECT `id`, `waktu`, `TANGGALBAYAR`, `PENGUNAAN`, `STATUS`, `IDPEL`, `NAMA`, `PAKET`, `HARGA`, `BUKTI`, `CEK`, `PEMILIK` FROM `transaksi` WHERE `IDPEL`='$idpel' ORDER BY `id` DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Bukti Pembayaran</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- jsPDF, html2canvas, dan autoTable -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

    <style>
        body {
            background: #f8f9fa;
        }

        .logo-kecil {
            max-width: 100px;
            max-height: 100px;
            display: block;
            margin: 0 auto 1rem auto;
        }

        .card-img-top {
            max-height: 200px;
            object-fit: cover;
        }
    </style>
</head>

<body>

    <div class="container py-4">
        <h3 class="mb-4 text-center text-primary">Daftar Bukti Pembayaran</h3>
        <div class="row g-4">
            <?php $i = 0;
            while ($row = $result->fetch_assoc()): $i++; ?>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm border-0" id="card-<?= $i ?>">
                        <!-- Logo kecil -->
                        <img src="https://quenbytekniksejahtera.com/dokumen/logo/profile-<?= $row['PEMILIK'] ?>.png" class="logo-kecil" alt="Logo">

                        <div class="card-body" id="card-<?= $row['IDPEL'] ?>">
                            <h5 class="card-title"><?= htmlspecialchars($row['NAMA']) ?> <small class="text-muted">(<?= $row['IDPEL'] ?>)</small></h5>
                            <ul class="list-group list-group-flush mb-3">
                                <li class="list-group-item">Package: <strong><?= $row['PAKET'] ?></strong></li>
                                <li class="list-group-item">
                                    Prize: Rp<?= number_format($row['HARGA'], 0, ',', '.') ?><br>
                                    <small class="text-muted">*Harga sudah termasuk pajak 12%</small>
                                </li>
                                <li class="list-group-item">Date: <?= $row['TANGGALBAYAR'] ?></li>
                                <li class="list-group-item">Status:
                                    <span class="badge bg-<?= $row['STATUS'] === 'LUNAS' ? 'success' : 'warning' ?>">
                                        <?= strtoupper($row['STATUS']) ?>
                                    </span>
                                </li>
                            </ul>
                            <button class="btn btn-outline-primary w-100"
                                onclick="generatePDF(
                                '<?= addslashes($row['NAMA']) ?>',
                                '<?= $row['IDPEL'] ?>',
                                '<?= $row['PAKET'] ?>',
                                '<?= $row['HARGA'] ?>',
                                '<?= $row['TANGGALBAYAR'] ?>',
                                '<?= $row['STATUS'] ?>',
                                '<?= $row['PEMILIK'] ?>'
                            )">
                                Download PDF
                            </button>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>


    <script>
        async function generatePDF(nama, idpel, paket, harga, tanggal, status, pemilik) {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF();

            const hargaInt = parseInt(harga);
            const ppn = Math.round(hargaInt / 1.12 * 0.12);
            const hargaAsli = hargaInt - ppn;

            // Gaya umum
            doc.setFontSize(12);
            doc.setTextColor(33, 33, 33);

            // Border card
            doc.roundedRect(15, 15, 180, 260, 5, 5);

            // Logo
            const logo = new Image();
            logo.src = "https://quenbytekniksejahtera.com/dokumen/logo/profile-" + pemilik + ".png";
            await new Promise(resolve => {
                logo.onload = () => {
                    doc.addImage(logo, 'PNG', 85, 20, 40, 20);
                    resolve();
                };
            });

            let y = 50;
            doc.setFontSize(13);
            doc.text(`${nama}`, 20, y);
            doc.setFontSize(11);
            doc.text(`(${idpel})`, 20, y + 7);

            y += 20;
            doc.setFont("Helvetica", "bold");
            doc.text(`Paket:`, 20, y);
            doc.setFont("Helvetica", "normal");
            doc.text(`${paket}`, 45, y);

            y += 10;
            doc.text(`Harga: Rp${hargaInt.toLocaleString('id-ID')}`, 20, y);
            doc.setFontSize(9);
            doc.setTextColor(120);
            doc.text(`*Harga sudah termasuk pajak 12%`, 20, y + 6);

            doc.setFontSize(11);
            doc.setTextColor(33, 33, 33);
            y += 16;
            doc.text(`Tanggal: ${tanggal}`, 20, y);

            y += 10;
            doc.text(`Status:`, 20, y);
            doc.setFillColor(255, 193, 7); // kuning (default)
            if (status.toLowerCase() === "lunas" || status.toLowerCase() === "berhasil") {
                doc.setFillColor(40, 167, 69); // hijau
            }
            doc.roundedRect(45, y - 6, 30, 8, 2, 2, 'F');
            doc.setTextColor(255);
            doc.text(status.toUpperCase(), 60, y, null, null, 'center');

            // Reset warna teks
            doc.setTextColor(33);

            // Tabel Harga + PPN
            y += 20;
            doc.autoTable({
                startY: y,
                head: [
                    ['Rincian', 'Jumlah']
                ],
                body: [
                    ['Harga Sebelum Pajak', 'Rp' + hargaAsli.toLocaleString('id-ID')],
                    ['PPN 12%', 'Rp' + ppn.toLocaleString('id-ID')],
                    ['Total Bayar', 'Rp' + hargaInt.toLocaleString('id-ID')]
                ],
                theme: 'grid',
                styles: {
                    fontSize: 10,
                    cellPadding: 4,
                    halign: 'right',
                },
                headStyles: {
                    fillColor: [0, 123, 255],
                    textColor: 255,
                    halign: 'center',
                },
                columnStyles: {
                    0: {
                        halign: 'left'
                    }
                },
                margin: {
                    left: 20,
                    right: 20
                }
            });

            // Footer
            doc.setFontSize(9);
            doc.setTextColor(120);
            doc.text("Dicetak otomatis dari sistem FiberQ", 105, 285, null, null, "center");

            doc.save(`bukti-${idpel}.pdf`);
        }
    </script>

</body>

</html>