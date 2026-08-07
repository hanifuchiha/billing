<?php require 'header.php'; ?>
<?php
$chat_scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$chat_host = $_SERVER['HTTP_HOST'] ?? 'quenbytekniksejahtera.com';
$chat_admin_url = $chat_scheme . '://' . $chat_host . '/crm/chat/index.php?admin=admin';
?>

<div class="container-fluid py-4 px-3 px-md-4">
  <div class="row">
    <div class="col-12">
      <div class="card shadow mb-4">
        <div class="card-header dashboard-dark-header theme-aware-header">
          <h5 class="mb-0"><i class="fas fa-comments me-2"></i>Live Chat Customer Service (QUENBY TEKNIK SEJAHTERA)</h5>
        </div>
        <div class="card-body p-0">
          <iframe
            src="<?= htmlspecialchars($chat_admin_url) ?>"
            style="display:block; width:100%; height:85vh; border:0; border-radius:0 0 0.75rem 0.75rem;"
            title="Live Chat Super Admin"
          ></iframe>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require 'footer.php'; ?>
