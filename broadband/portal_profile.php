<?php
include 'cek_sesi.php';

?>

<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pelanggan['PEMILIK']); ?> - Internet Service</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    :root {
      --primary-green: #0d6efd;
      --dark-green: #0a58ca;
      --orange: #F7941D;
      --white: #ffffff;
      --light-gray: #f8f9fa;
      --border-color: #e9ecef;
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    body {
      background-color: var(--light-gray);
      color: #333;
      padding-bottom: 80px;
      min-height: 100vh;
    }
    .container {
      max-width: 480px;
      margin: 0 auto;
      padding-top: 32px;
      padding-bottom: 32px;
    }
    .profile-section {
      background: var(--white);
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
      padding: 24px 20px;
      margin-bottom: 24px;
    }
    .profile-title {
      font-weight: bold;
      font-size: 18px;
      color: var(--primary-green);
      margin-bottom: 18px;
      text-align: center;
    }
    .profile-info {
      font-size: 15px;
      margin-bottom: 10px;
      border-bottom: 1px solid var(--border-color);
      padding-bottom: 7px;
    }
    .profile-info:last-child {
      border-bottom: none;
    }
    .action-buttons {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-bottom: 24px;
    }
    .action-btn {
      padding: 14px;
      border-radius: 8px;
      font-weight: bold;
      font-size: 16px;
      border: none;
      cursor: pointer;
      width: 100%;
      transition: box-shadow 0.2s;
      box-shadow: 0 1px 4px rgba(0,0,0,0.04);
    }
    .action-btn:active {
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .btn-danger { background-color: #f44336; color: white; }
    .btn-success { background-color: var(--dark-green); color: white; }
    .btn-warning { background-color: var(--orange); color: white; }
    .modal-xl { 
      max-width: 95vw; 
      margin: 1rem auto;
    }
    .modal-fullscreen {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      margin: 0;
      padding: 0;
    }
    .modal-fullscreen .modal-content {
      height: 100vh;
      border: none;
      border-radius: 0;
      display: flex;
      flex-direction: column;
    }
    .modal-fullscreen .modal-header {
      background: linear-gradient(135deg, var(--primary-green), var(--dark-green));
      color: white;
      border-bottom: none;
      padding: 1rem 1.5rem;
      flex-shrink: 0;
    }
    .modal-fullscreen .modal-header .btn-close {
      background: rgba(255, 255, 255, 0.3);
      border-radius: 50%;
      padding: 0.5rem;
      opacity: 1;
      transition: all 0.3s ease;
    }
    .modal-fullscreen .modal-header .btn-close:hover {
      background: rgba(255, 255, 255, 0.5);
      transform: scale(1.1);
    }
    .modal-fullscreen .modal-body {
      padding: 0;
      flex: 1;
      overflow: hidden;
    }
    .modal-fullscreen .modal-body iframe {
      width: 100% !important;
      height: 100% !important;
      border: none !important;
    }
    .iframe-loading {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, 0.9);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      transition: opacity 0.3s ease;
    }
    .iframe-loading.hidden {
      opacity: 0;
      pointer-events: none;
    }
    .loading-spinner {
      width: 40px;
      height: 40px;
      border: 4px solid var(--border-color);
      border-top: 4px solid var(--primary-green);
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    .modal.fade .modal-dialog {
      transition: transform 0.3s ease-out, opacity 0.3s ease-out;
      transform: scale(0.9) translate(0, -50px);
      opacity: 0;
    }
    .modal.show .modal-dialog {
      transform: scale(1) translate(0, 0);
      opacity: 1;
    }
    .navbar {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background-color: var(--primary-green);
      display: flex;
      justify-content: space-around;
      padding: 10px 0;
      z-index: 100;
      box-shadow: 0 -2px 8px rgba(0,0,0,0.07);
    }
       .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: white;
            font-size: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            padding: 5px 8px;
            border-radius: 8px;
            min-width: 60px;
            outline: none;
        }

        .nav-item:hover,
        .nav-item:focus {
            background-color: var(--orange);
            color: white !important;
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0,0,0,0.10);
            border: 2px solid var(--orange);
            z-index: 2;
        }

        .nav-icon {
            margin-bottom: 5px;
            font-size: 18px;
            transition: all 0.3s ease;
        }

        .nav-item.active,
        .nav-item.active:focus {
            color: white !important;
            background: linear-gradient(135deg, var(--orange) 60%, var(--primary-green) 100%);
            border: 2px solid var(--orange);
            box-shadow: 0 0 0 2px var(--orange), 0 2px 8px rgba(0,0,0,0.10);
            font-weight: bold;
            z-index: 3;
        }
        .nav-item.active .nav-icon {
            transform: scale(1.1);
        }
    .nav-icon { margin-bottom: 5px; font-size: 20px; }
    .nav-item.active { color: var(--orange); }
  </style>

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
<body>
  <div class="container">
    <!-- $logo_path sudah dihitung (server-per-area aware) di cek_sesi.php -->
    <img src="<?= $logo_path ?>?v=<?= time() ?>" id="logoDynamic" alt="Logo" style="display:none;" />
    <div class="profile-section">
      <div class="profile-title">Profil Pelanggan</div>
      <div class="profile-info"><strong>Nama:</strong> <?= htmlspecialchars($pelanggan['NAMA']); ?></div>
      <div class="profile-info"><strong>ID Pelanggan:</strong> <?= htmlspecialchars($pelanggan['IDPEL']); ?></div>
      <div class="profile-info"><strong>Nomor WhatsApp:</strong> <?= htmlspecialchars($pelanggan['NOWA']); ?></div>
      <div class="profile-info"><strong>Paket:</strong> <?= htmlspecialchars($pelanggan['PAKET']); ?></div>
      <div class="profile-info"><strong>Alamat:</strong> <?= htmlspecialchars($pelanggan['ALAMAT']); ?></div>
      <div class="profile-info"><strong>Tanggal Pasang:</strong> <?= htmlspecialchars($pelanggan['TANGGALPASANG']); ?></div>
      <div class="profile-info"><strong>Pemilik:</strong> <?= htmlspecialchars($pelanggan['PEMILIK']); ?></div>
      <div class="profile-info"><strong>Area:</strong> <?= htmlspecialchars($pelanggan['AREA']); ?></div>
    </div>
    <div class="action-buttons">
      <button type="button" class="action-btn btn-success" data-bs-toggle="modal" data-bs-target="#ubahDataModal" data-url="ubahdata.php?idselect=<?= htmlspecialchars($pelanggan['IDPEL']); ?>">Ubah data pelanggan</button>
      <form method="post" style="margin-top:8px;">
        <input type="hidden" name="brand" value="<?= htmlspecialchars(isset($useraccount) ? (string)$useraccount : '', ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" name="logout" class="action-btn btn-danger">Logout</button>
      </form>
    <!-- Modal Ubah Data -->
    <div class="modal fade" id="ubahDataModal" tabindex="-1" aria-labelledby="ubahDataModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
      <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="ubahDataModalLabel">
              <i class="bi bi-person-gear me-2"></i>
              Ubah Data Pelanggan
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body position-relative">
            <!-- Loading Overlay -->
            <div class="iframe-loading" id="iframeLoading">
              <div class="text-center">
                <div class="loading-spinner mb-3"></div>
                <div class="text-muted">Memuat form ubah data...</div>
              </div>
            </div>
            <!-- Iframe Container -->
            <iframe src="" id="ubahDataFrame" style="width:100%; height:100%; border:none;" onload="hideLoading()"></iframe>
          </div>
        </div>
      </div>
    </div>
    </div>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        // Get modal elements
        var ubahDataModal = document.getElementById('ubahDataModal');
        var ubahDataFrame = document.getElementById('ubahDataFrame');
        var iframeLoading = document.getElementById('iframeLoading');
        var modalInstance = null;

        // Show loading function
        window.showLoading = function() {
          iframeLoading.classList.remove('hidden');
        };

        // Hide loading function
        window.hideLoading = function() {
          setTimeout(function() {
            iframeLoading.classList.add('hidden');
          }, 500); // Small delay for better UX
        };

        // Modal show event
        ubahDataModal.addEventListener('show.bs.modal', function (event) {
          var button = event.relatedTarget;
          var url = button.getAttribute('data-url');
          
          // Show loading
          showLoading();
          
          // Set iframe src
          ubahDataFrame.src = url;
          
          // Get modal instance
          modalInstance = bootstrap.Modal.getInstance(ubahDataModal) || new bootstrap.Modal(ubahDataModal);
          
          // Send message to iframe that modal is opened
          setTimeout(function() {
            try {
              ubahDataFrame.contentWindow.postMessage('modal_opened', '*');
            } catch(e) {
              console.log('Cannot send message to iframe:', e);
            }
          }, 1000);
        });

        // Modal hidden event
        ubahDataModal.addEventListener('hidden.bs.modal', function () {
          // Clear iframe src and show loading for next time
          ubahDataFrame.src = "";
          showLoading();
          
          // Refresh the page to show updated data
          setTimeout(function() {
            window.location.reload();
          }, 300);
        });

        // Listen for messages from iframe
        window.addEventListener('message', function(e) {
          console.log('Received message:', e.data);
          
          // Handle different message types
          if (e.data === 'closeUbahDataModal' || e.data === 'close_modal') {
            if (modalInstance) {
              modalInstance.hide();
            }
          } else if (e.data === 'data_updated_successfully') {
            // Show success message and close modal
            showSuccessToast('Data berhasil diubah!');
            setTimeout(function() {
              if (modalInstance) {
                modalInstance.hide();
              }
            }, 1500);
          } else if (typeof e.data === 'object' && e.data.type) {
            switch(e.data.type) {
              case 'close_modal':
                if (modalInstance) {
                  modalInstance.hide();
                }
                break;
              case 'data_saved':
                showSuccessToast('Data berhasil disimpan!');
                setTimeout(function() {
                  if (modalInstance) {
                    modalInstance.hide();
                  }
                }, 1500);
                break;
              case 'form_submitted':
                showLoadingToast('Menyimpan data...');
                break;
            }
          }
        });

        // Success toast function
        function showSuccessToast(message) {
          // Create toast element
          var toastHtml = `
            <div class="toast align-items-center text-white bg-success border-0 position-fixed" style="top: 20px; right: 20px; z-index: 9999;" role="alert" aria-live="assertive" aria-atomic="true">
              <div class="d-flex">
                <div class="toast-body">
                  <i class="bi bi-check-circle-fill me-2"></i>
                  ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
              </div>
            </div>
          `;
          
          // Add to body
          document.body.insertAdjacentHTML('beforeend', toastHtml);
          
          // Show toast
          var toastElement = document.body.lastElementChild;
          var toast = new bootstrap.Toast(toastElement, { delay: 3000 });
          toast.show();
          
          // Remove from DOM after hiding
          toastElement.addEventListener('hidden.bs.toast', function() {
            toastElement.remove();
          });
        }

        // Loading toast function
        function showLoadingToast(message) {
          var toastHtml = `
            <div class="toast align-items-center text-white bg-primary border-0 position-fixed" style="top: 20px; right: 20px; z-index: 9999;" role="alert" aria-live="assertive" aria-atomic="true">
              <div class="d-flex">
                <div class="toast-body">
                  <div class="spinner-border spinner-border-sm me-2" role="status">
                    <span class="visually-hidden">Loading...</span>
                  </div>
                  ${message}
                </div>
              </div>
            </div>
          `;
          
          document.body.insertAdjacentHTML('beforeend', toastHtml);
          var toastElement = document.body.lastElementChild;
          var toast = new bootstrap.Toast(toastElement, { autohide: false });
          toast.show();
          
          // Store reference to hide later
          window.currentLoadingToast = toast;
          window.currentLoadingToastElement = toastElement;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
          // ESC to close modal
          if (e.key === 'Escape' && ubahDataModal.classList.contains('show')) {
            if (modalInstance) {
              modalInstance.hide();
            }
          }
        });

        // Error handling for iframe
        ubahDataFrame.addEventListener('error', function() {
          hideLoading();
          showErrorToast('Gagal memuat form. Silakan coba lagi.');
        });

        function showErrorToast(message) {
          var toastHtml = `
            <div class="toast align-items-center text-white bg-danger border-0 position-fixed" style="top: 20px; right: 20px; z-index: 9999;" role="alert" aria-live="assertive" aria-atomic="true">
              <div class="d-flex">
                <div class="toast-body">
                  <i class="bi bi-exclamation-triangle-fill me-2"></i>
                  ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
              </div>
            </div>
          `;
          
          document.body.insertAdjacentHTML('beforeend', toastHtml);
          var toastElement = document.body.lastElementChild;
          var toast = new bootstrap.Toast(toastElement, { delay: 4000 });
          toast.show();
          
          toastElement.addEventListener('hidden.bs.toast', function() {
            toastElement.remove();
          });
        }
      });
    </script>
<?php
echo $BRANDCEK;
if (isset($_POST['logout'])) {
  $brand_logout = isset($_POST['brand']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$_POST['brand']) : '';
  if ($brand_logout === '' && isset($pelanggan['BRAND'])) {
    $brand_logout = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$pelanggan['BRAND']);
  }
  $logout_login_url = 'portallogin.php' . ($brand_logout !== '' ? ('?brand=' . rawurlencode($brand_logout)) : '');

  session_start();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
      $params['path'], $params['domain'],
      $params['secure'], $params['httponly']
    );
  }
  session_destroy();
  header('Location: ' . $logout_login_url);
  exit;
}
?>
  </div>
  <!-- Navigation Bar -->
  <div class="navbar">
    <a href="portal_baru.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-house-door-fill"></i></div>
      <div>Beranda</div>
    </a>
    <a href="portal_bayar.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-credit-card"></i></div>
      <div>Bayar</div>
    </a>
    <a href="portal_chat.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-chat-dots"></i></div>
      <div>Chat</div>
    </a>
    <a href="portal_mywifi.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-wifi"></i></div>
      <div>WiFi Saya</div>
    </a>
    <a href="portal_riwayat.php?cari=<?= $idpel; ?>" class="nav-item">
      <div class="nav-icon"><i class="bi bi-clock-history"></i></div>
      <div>Riwayat</div>
    </a>
    <a href="portal_profile.php?cari=<?= $idpel; ?>" class="nav-item active">
      <div class="nav-icon"><i class="bi bi-person-circle"></i></div>
      <div>Profile</div>
    </a>
  </div>
</body>
</html>

