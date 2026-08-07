<?php
include '../koneksidbabsensi.php';


$tgl = date("Y-m-d");
$cek1 = $_GET['KIRIM'] ?? '';
$dari = $_GET['dari'] ?? '';
$idcs = $_GET['idselect'] ?? '';

if ($cek1 == "KIRIM") {
  $nama = $_GET['Nama'] ?? '';
  $idpel = $_GET['IDPEL'] ?? '';
  $alamat = $_GET['Alamat'] ?? '';
  $nowa = preg_replace('/[^\dxX]/', '', $_GET['nowa'] ?? '');
  $keluhan = $_GET['Keluhan'] ?? '';
  $tikor = $_GET['TIKOR'] ?? '';
  $provider = $_GET['Provider'] ?? '';
  $status = "BARU";
  $tipe = "MAINTENANCE";
  $data = "[Pengaduan dari MOBILE APPS ] MAINTENANCE\n\nNAMA:$nama\nIDPEL:$idpel\nAlamat:$alamat\nKeluhan:$keluhan\nWHATSAPP:$nowa\nTIKOR:$tikor";

  if ($keluhan ) {
    $sql = "INSERT INTO joblist (tgl, status, tipe, nowa, data, project,report,team) VALUES ('$tgl', '$status', '$tipe', '$nowa', '$data', '$provider','','')";
    if (mysqli_query($conn, $sql)) {





            $file = "../waapi.txt";
            $waapi = $namebot = $password = $grup = $grup_tiket = $sender = "";
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (substr($line, 0, 6) === "waapi=") $waapi = substr($line, 6);
                    if (substr($line, 0, 8) === "namebot=") $namebot = substr($line, 8);
                    if (substr($line, 0, 9) === "password=") $password = substr($line, 9);
                    if (substr($line, 0, 5) === "grup=") $grup = substr($line, 5);
                    if (substr($line, 0, 11) === "grup_tiket=") $grup_tiket = substr($line, 11);
                    if (substr($line, 0, 7) === "sender=") $sender = substr($line, 7);
                }
            }


      $text = "*[NOTIF SYSTEM BOT QTS]*\n===================\nTIKET BARU MASUK DI APP\n===================\n\nPROJECT : $provider \nTIPE : $tipe\nDATA :\n$data\n\n===================\n";




      $waapi = htmlspecialchars($waapi); // Ganti dengan URL server Anda
      $session = 'QTS'; // Nama sesi yang telah Anda buat

      // Nomor tujuan dan pesan
    $phone = htmlspecialchars($grup_tiket !== '' ? $grup_tiket : $grup); // Format: nomor@s.whatsapp.net


      // Data JSON sesuai dengan dokumentasi API
            $data = [
                "phone" => $phone,
                "message" => $text,
                "sender" => $sender
                // "reply_message_id" => "optional" // Opsional: ID pesan yang ingin dibalas
            ];
      // Autentikasi Basic Auth
      $username = htmlspecialchars($namebot);
      $password = htmlspecialchars($password);

      // device_id gowa multi-device (build gowa terbaru wajib X-Device-Id header /
      // device_id query di /send/message) = isi kolom sender apa adanya.
      $deviceId = trim((string)$sender);

      // Inisialisasi cURL
      $url = "$waapi/send/message?session=$session"; // Endpoint dengan parameter sesi
      if ($deviceId !== '') {
          $url .= '&device_id=' . urlencode($deviceId);
      }
      $headers = [
        "Content-Type: application/json"
      ];
      if ($deviceId !== '') {
          $headers[] = "X-Device-Id: $deviceId";
      }
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

      // Tambahkan Basic Auth
      curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");

      // Eksekusi dan tangani respons
      $response = curl_exec($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);













      header("Location: pengaduan.php?idselect=$idpel&notif=berhasil buat pengaduan silahkan tunggu sampai teknisi datang ke lokasi");
    } else {
      $notif = "Gagal menyimpan tiket.";
    }
  } else {
    $notif = "Semua data wajib diisi.";
  }
}

$notif2 = $_GET['notif'] ?? '';

include '../koneksibilling.php';
// Ambil data pelanggan
$query = mysqli_query($conn, "SELECT * FROM pelanggan WHERE IDPEL='$idcs' OR NOWA='$idcs'");
$data1 = mysqli_fetch_assoc($query);
$namafil = $data1['NAMA'] ?? '';
$idfil = $data1['IDPEL'] ?? '';
$alamatfil = $data1['ALAMAT'] ?? '';
$tikorfil = $data1['TIKOR'] ?? '';
$brandfil = $data1['BRAND'] ?? '';
$nowafil = $data1['NOWA'] ?? '';
$showWarning = empty($idfil);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<?php
    $logo_path = "../../../dokumen/logo/profile-$useraccount.png";
    if (!file_exists($logo_path)) {
        $logo_path = "../../../dokumen/logo/logo.png";
    }
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaduan Gangguan | QTS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-green: #0d6efd;
            --dark-green: #0a58ca;
            --orange: #F7941D;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --border-color: #e9ecef;
            --text-dark: #2c3e50;
            --border-radius: 12px;
            --box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--white);
            color: var(--text-dark);
            line-height: 1.6;
            min-height: 100vh;
            padding: 0;
            overflow-x: hidden;
        }

        .container-fluid {
            padding: 0;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: white;
            padding: 2rem 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="1" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.1;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .main-content {
            padding: 2rem 0;
        }

        .form-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .alert {
            border: none;
            border-radius: var(--border-radius);
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            border-left: 4px solid var(--warning-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
            display: block;
        }

        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 0.875rem 1rem;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--white);
            width: 100%;
        }

        .form-control:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
            outline: none;
        }

        .form-control:hover {
            border-color: var(--primary-green);
        }

        .btn {
            border: none;
            border-radius: var(--border-radius);
            padding: 0.875rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--dark-green) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.4);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.4);
            color: white;
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
            color: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }

        .form-header h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 120px;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 2rem;
            }
            
            .form-container {
                padding: 1.5rem;
                margin: 1rem;
            }
            
            .main-content {
                padding: 1rem 0;
            }
        }

        /* Loading animations */
        .btn.loading {
            pointer-events: none;
            opacity: 0.8;
            position: relative;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-left: 8px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Success overlay animation */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(13, 110, 253, 0.95);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }

        .success-overlay.show {
            display: flex;
            animation: fadeIn 0.5s ease-out;
        }

        .success-content {
            text-align: center;
            color: white;
            animation: scaleUp 0.6s ease-out;
        }

        .success-icon-large {
            font-size: 4rem;
            margin-bottom: 1rem;
            animation: bounceIn 0.8s ease-out;
        }

        .success-text {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .success-subtext {
            font-size: 1rem;
            opacity: 0.9;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes scaleUp {
            from {
                transform: scale(0.8);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        @keyframes bounceIn {
            0% {
                transform: scale(0.3);
                opacity: 0;
            }
            50% {
                transform: scale(1.1);
            }
            70% {
                transform: scale(0.9);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        /* Form validation states */
        .form-control.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .form-control.is-valid {
            border-color: var(--success-color);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        /* Pulse animation for required fields */
        .required-pulse {
            animation: pulse-red 1s ease-in-out infinite;
        }

        @keyframes pulse-red {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }
  </style>
</head>
<body>
    <img src="<?= $logo_path ?>?v=<?= time() ?>" id="logoDynamic" alt="Logo" style="display:none;" />
    <!-- Dynamic color extraction from logo -->
    <script>
        // Extract dominant color from logo and set CSS variables
        function extractLogoColors(img) {
            if (!img || !img.complete) return;
            try {
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                canvas.width = img.naturalWidth || 120;
                canvas.height = img.naturalHeight || 120;
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                var data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                var colorCounts = {};
                for (var i = 0; i < data.length; i += 12) {
                    var r = data[i], g = data[i+1], b = data[i+2], a = data[i+3];
                    if (a < 128 || (r > 240 && g > 240 && b > 240) || (r < 15 && g < 15 && b < 15)) continue;
                    var color = r+','+g+','+b;
                    colorCounts[color] = (colorCounts[color]||0)+1;
                }
                var sorted = Object.entries(colorCounts).sort(function(a,b){return b[1]-a[1];});
                if (sorted.length) {
                    var primary = sorted[0][0];
                    var secondary = sorted[1] ? sorted[1][0] : primary;
                    var accent = sorted[2] ? sorted[2][0] : primary;
                    document.documentElement.style.setProperty('--primary-green', 'rgb('+primary+')');
                    document.documentElement.style.setProperty('--orange', 'rgb('+secondary+')');
                    document.documentElement.style.setProperty('--dark-green', 'rgb('+accent+')');
                }
            } catch(e) {console.log('Logo color extraction failed',e);}
        }
        window.addEventListener('DOMContentLoaded', function() {
            var img = document.getElementById('logoDynamic');
            if (img && img.complete) extractLogoColors(img);
            else if (img) img.onload = function(){extractLogoColors(img);};
        });
    </script>
</head>

<body>
    <!-- Success Overlay -->
    <div class="success-overlay" id="successOverlay">
        <div class="success-content">
            <div class="success-icon-large">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <div class="success-text">Pengaduan Berhasil Dikirim!</div>
            <div class="success-subtext">Terima kasih, tiket Anda sedang diproses...</div>
        </div>
    </div>

    <div class="container-fluid">
     

        <!-- Main Content -->
        <div class="main-content">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-8 col-xl-6">
                        
                        <!-- Success Alert -->
                        <?php if (isset($notif2) && $notif2): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle-fill success-icon"></i>
                            <div><?php echo htmlspecialchars($notif2); ?></div>
                        </div>
                        <?php endif; ?>

                        <!-- Error Alert -->
                        <?php if (isset($notif) && $notif): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <div><?php echo htmlspecialchars($notif); ?></div>
                        </div>
                        <?php endif; ?>

                        <!-- Warning Alert -->
                        <?php if ($showWarning): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-info-circle-fill"></i>
                            <div>Data pelanggan tidak ditemukan. Silakan isi form secara manual.</div>
                        </div>
                        <?php endif; ?>

                        <!-- Form Container -->
                        <div class="form-container">
                            <form method="GET" action="pengaduan.php" id="pengaduanForm">
                                <input type="hidden" name="KIRIM" value="KIRIM">
                                <input type="hidden" name="dari" value="<?php echo $dari; ?>">
                                <input type="hidden" name="idselect" value="<?php echo $idfil; ?>">
                                
                                <!-- Nama -->
                                <div class="form-group">
                                    <label for="Nama" class="form-label">
                                        <i class="bi bi-person-fill"></i>
                                        Nama Lengkap <span class="required">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="Nama" name="Nama"
                                           value="<?php echo htmlspecialchars($namafil); ?>"
                                           placeholder="Masukkan nama lengkap Anda" required>
                                </div>

                                <!-- ID Pelanggan -->
                                <div class="form-group">
                                    <label for="IDPEL" class="form-label">
                                        <i class="bi bi-card-text"></i>
                                        ID Pelanggan
                                    </label>
                                    <input type="text" class="form-control" id="IDPEL" name="IDPEL"
                                           value="<?php echo htmlspecialchars($idfil); ?>"
                                           placeholder="ID Pelanggan (jika ada)">
                                </div>

                                <!-- Alamat -->
                                <div class="form-group">
                                    <label for="Alamat" class="form-label">
                                        <i class="bi bi-geo-alt-fill"></i>
                                        Alamat Lengkap <span class="required">*</span>
                                    </label>
                                    <textarea class="form-control" id="Alamat" name="Alamat"
                                              placeholder="Masukkan alamat lengkap termasuk RT/RW" required><?php echo htmlspecialchars($alamatfil); ?></textarea>
                                </div>

                                <!-- No WhatsApp -->
                                <div class="form-group">
                                    <label for="nowa" class="form-label">
                                        <i class="bi bi-whatsapp"></i>
                                        Nomor WhatsApp <span class="required">*</span>
                                    </label>
                                    <input type="tel" class="form-control" id="nowa" name="nowa"
                                           value="<?php echo htmlspecialchars($nowafil); ?>"
                                           placeholder="08123456789" required>
                                </div>

                                <!-- Provider -->
                                <div class="form-group">
                                    <label for="Provider" class="form-label">
                                        <i class="bi bi-wifi"></i>
                                        Provider/ISP <span class="required">*</span>
                                    </label>
                                    <select class="form-control" id="Provider" name="Provider" required>
                                        <option value="<?php echo htmlspecialchars($brandfil); ?>"><?php echo htmlspecialchars($brandfil); ?></option>
                                       
                                    </select>
                                </div>

                                <!-- TIKOR -->
                                <div class="form-group">
                                    <label for="TIKOR" class="form-label">
                                        <i class="bi bi-telephone-fill"></i>
                                        Titik Koordinat / No. Telepon
                                    </label>
                                    <input type="text" class="form-control" id="TIKOR" name="TIKOR"
                                           value="<?php echo htmlspecialchars($tikorfil); ?>"
                                           placeholder="Koordinat lokasi atau nomor telepon alternatif">
                                </div>

                                <!-- Keluhan -->
                                <div class="form-group">
                                    <label for="Keluhan" class="form-label">
                                        <i class="bi bi-chat-square-text-fill"></i>
                                        Detail Keluhan <span class="required">*</span>
                                    </label>
                                    <textarea class="form-control" id="Keluhan" name="Keluhan"
                                              placeholder="Jelaskan detail masalah yang Anda alami, kapan terjadi, dan sudah berapa lama..." required></textarea>
                                </div>

                                <!-- Submit Button -->
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="bi bi-send-fill"></i>
                                        Kirim Pengaduan
                                    </button>
                                </div>
                                
                                <!-- Processing indicator -->
                                <div class="text-center mt-3" id="processingText" style="display: none;">
                                    <small class="text-muted">
                                        <i class="bi bi-hourglass-split"></i>
                                        Sedang memproses pengaduan Anda...
                                    </small>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>





















    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Form submission handling with advanced animations
        document.getElementById('pengaduanForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const form = this;
            
            // Validate required fields with animation
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid', 'required-pulse');
                    isValid = false;
                    
                    // Remove pulse animation after 2 seconds
                    setTimeout(() => {
                        field.classList.remove('required-pulse');
                    }, 2000);
                } else {
                    field.classList.remove('is-invalid', 'required-pulse');
                    field.classList.add('is-valid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
            
            // Add loading state with animation
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Mengirim Pengaduan...';
            
            // Show processing indicator
            const processingText = document.getElementById('processingText');
            processingText.style.display = 'block';
            processingText.style.opacity = '0';
            setTimeout(() => {
                processingText.style.transition = 'opacity 0.5s ease';
                processingText.style.opacity = '1';
            }, 100);
            
            // Simulate processing time for better UX
            setTimeout(() => {
                // Form will submit naturally after this
            }, 300);
        });

        // Auto-close modal on success with advanced animations
        <?php if (isset($notif2) && $notif2): ?>
        // Show success overlay
        setTimeout(function() {
            const successOverlay = document.getElementById('successOverlay');
            successOverlay.classList.add('show');
            
            // Play success sound (if browser allows)
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQVdx9lZGLqbqoakU4acrquQ==');
                audio.volume = 0.3;
                audio.play().catch(() => {}); // Ignore if blocked
            } catch(e) {}
            
            // Send success message to parent window
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'pengaduan_success',
                    message: 'Pengaduan berhasil dikirim!',
                    timestamp: new Date().toISOString()
                }, '*');
                
                // Close modal with fade animation
                setTimeout(function() {
                    successOverlay.style.opacity = '0';
                    successOverlay.style.transform = 'scale(0.95)';
                    
                    setTimeout(function() {
                        window.parent.postMessage({
                            type: 'close_modal',
                            reason: 'success'
                        }, '*');
                    }, 500);
                }, 2000);
            } else {
                // If not in modal, just hide overlay after longer delay
                setTimeout(function() {
                    successOverlay.classList.remove('show');
                }, 3000);
            }
        }, 800);
        <?php endif; ?>

        // Phone number formatting with visual feedback
        document.getElementById('nowa').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            // Remove leading zero
            if (value.startsWith('0')) {
                value = value.substring(1);
            }
            
            // Format for display (add separators)
            if (value.length > 3) {
                if (value.length > 7) {
                    value = value.substring(0,3) + '-' + value.substring(3,7) + '-' + value.substring(7,11);
                } else {
                    value = value.substring(0,3) + '-' + value.substring(3);
                }
            }
            
            e.target.value = value;
            
            // Validate phone number length
            const cleanNumber = value.replace(/-/g, '');
            if (cleanNumber.length >= 10 && cleanNumber.length <= 13) {
                e.target.classList.add('is-valid');
                e.target.classList.remove('is-invalid');
            } else if (cleanNumber.length > 0) {
                e.target.classList.add('is-invalid');
                e.target.classList.remove('is-valid');
            }
        });

        // Real-time validation for other fields
        document.querySelectorAll('.form-control').forEach(field => {
            field.addEventListener('blur', function() {
                if (this.hasAttribute('required')) {
                    if (this.value.trim()) {
                        this.classList.add('is-valid');
                        this.classList.remove('is-invalid');
                    } else {
                        this.classList.add('is-invalid');
                        this.classList.remove('is-valid');
                    }
                }
            });
            
            field.addEventListener('input', function() {
                if (this.classList.contains('is-invalid') && this.value.trim()) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                }
            });
        });

        // Listen for messages from parent window
        window.addEventListener('message', function(event) {
            if (event.data === 'modal_opened') {
                // Reset form when modal is opened fresh
                const form = document.getElementById('pengaduanForm');
                form.reset();
                
                // Reset all validation states
                document.querySelectorAll('.form-control').forEach(field => {
                    field.classList.remove('is-valid', 'is-invalid', 'required-pulse');
                });
                
                // Reset submit button
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.classList.remove('loading');
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-send-fill"></i> Kirim Pengaduan';
                
                // Hide any existing alerts
                document.querySelectorAll('.alert').forEach(alert => {
                    alert.style.display = 'none';
                });
                
                // Hide success overlay
                document.getElementById('successOverlay').classList.remove('show');
            }
        });

        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>

</html>