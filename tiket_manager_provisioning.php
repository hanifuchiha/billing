<?php
require 'cek-sesi.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once 'koneksidb.php';
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo 'Koneksi database tidak tersedia.';
    exit;
}

$session_user_id = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$owner_user_id = isset($USER_ID) ? (int)$USER_ID : 0;
$is_assistant = isset($AKSES) && strtoupper((string)$AKSES) === 'ASSISTANT';

$ticket_id = (int)($_GET['ticket_id'] ?? 0);
if ($ticket_id <= 0) {
    echo '<div style="padding:16px;font-family:Arial">Ticket tidak valid.</div>';
    exit;
}

$allowed_servers = [];
if ($is_assistant) {
    $sql_srv = 'SELECT id FROM server WHERE id IN (SELECT server_id FROM billing_tiket_manager WHERE teknisi_user_id = ' . (int)$session_user_id . ')';
} else {
    $sql_srv = 'SELECT id FROM server WHERE user_id = ' . (int)$owner_user_id;
}
$res_srv = mysqli_query($conn, $sql_srv);
if ($res_srv) {
    while ($row_srv = mysqli_fetch_assoc($res_srv)) {
        $allowed_servers[(int)$row_srv['id']] = true;
    }
}
if (empty($allowed_servers)) {
    echo '<div style="padding:16px;font-family:Arial">Tidak ada server yang dapat diakses.</div>';
    exit;
}

$in_ids = implode(',', array_map('intval', array_keys($allowed_servers)));
$sql_ticket = 'SELECT t.*, u.USERNAME AS teknisi_nama FROM billing_tiket_manager t LEFT JOIN user u ON u.id = t.teknisi_user_id WHERE t.id = ' . $ticket_id . ' AND t.server_id IN (' . $in_ids . ') LIMIT 1';
$res_ticket = mysqli_query($conn, $sql_ticket);
$ticket = $res_ticket ? mysqli_fetch_assoc($res_ticket) : null;
if (!$ticket) {
    echo '<div style="padding:16px;font-family:Arial">Tiket tidak ditemukan atau tidak diizinkan.</div>';
    exit;
}

$tiket_id = (string)$ticket_id;
$project_name = trim((string)($ticket['project_name'] ?? ''));
if ($project_name === '') {
    $project_name = trim((string)($ticket['brand'] ?? ''));
}
$data_tiket = trim((string)($ticket['detail'] ?? ''));
if (trim((string)($ticket['report'] ?? '')) !== '') {
    $data_tiket .= "\n" . trim((string)$ticket['report']);
}
$team = trim((string)($ticket['teknisi_nama'] ?? ''));

$_POST['tiket_id'] = $tiket_id;
$_POST['project_name'] = $project_name;
$_POST['data_tiket'] = $data_tiket;
$_POST['team'] = $team;
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Provisioning Tiket #<?php echo (int)$ticket_id; ?></title>
  <link rel="stylesheet" href="/crm/assets/css/soft-ui-dashboard.css?v=1.1.0">
  <base href="../joblist/evidence/">
  <style>
    body { background:#f8fafc; font-family: Arial, sans-serif; }
    .wrap { max-width: 1200px; margin: 20px auto; background:#fff; border:1px solid #dbe4ef; border-radius:10px; padding:16px; }
    .top { display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:12px; }
    .btn-back { display:inline-block; padding:8px 12px; border:1px solid #d0d7e2; border-radius:8px; text-decoration:none; color:#1f2937; }
    .btn-back:hover { background:#f1f5f9; color:#111827; }
    #loadingOverlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.55);
      z-index: 9999;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-weight: 700;
      font-size: 14px;
    }
  </style>
  <script>
    // Compatibility shim: provisioning.php expects jQuery modal object (#provisioningModal).
    // In Billing iframe context we provide a minimal no-op adapter so the success UI can render safely.
    (function() {
      if (window.jQuery || window.$) return;
      var shim = function(selector) {
        var nodes = [];
        try { nodes = Array.prototype.slice.call(document.querySelectorAll(selector)); } catch (e) {}
        return {
          length: nodes.length,
          data: function() {
            return { _config: { backdrop: 'static', keyboard: false } };
          }
        };
      };
      window.$ = shim;
      window.jQuery = shim;
    })();
  </script>
</head>
<body>
  <div id="loadingOverlay">Memproses dan menyimpan tiket...</div>

  <div id="provisioningModal" style="display:none;"></div>
  <button id="provisioningModalClose" type="button" style="display:none;"></button>

  <div class="wrap">
    <div class="top">
      <h5 style="margin:0;">Form Provisioning Tiket #<?php echo (int)$ticket_id; ?></h5>
    </div>
    <?php include __DIR__ . '/../joblist/evidence/provisioning.php'; ?>

    <form id="evidenceForm" action="#" method="post" style="display:none;">
      <textarea name="datek" id="billing_datek_holder"></textarea>
    </form>
  </div>

  <script>
    (function() {
      var ticketId = '<?php echo (int)$ticket_id; ?>';

      function appendIdpelToReport(reportText, idpel) {
        var text = String(reportText || '');
        if (!idpel) return text;
        if (/ID\s*Pelanggan\s*:/i.test(text)) {
          return text.replace(/(ID\s*Pelanggan\s*:\s*)([^\n\r]*)/i, '$1' + idpel);
        }
        return (text ? (text + '\n') : '') + 'ID Pelanggan: ' + idpel;
      }

      function submitParentTicket(idpel) {
        try {
          if (!window.parent || window.parent === window) return false;
          var pdoc = window.parent.document;
          if (!pdoc) return false;
          var form = pdoc.getElementById('ticketUpdateForm' + ticketId);
          if (!form) return false;

          var statusEl = form.querySelector('select[name="status"]');
          if (statusEl) statusEl.value = 'DONE';

          var reportEl = form.querySelector('.ticket-report-input');
          if (reportEl) {
            reportEl.value = appendIdpelToReport(reportEl.value, idpel);
          }

          var processInput = form.querySelector('input[name="process_submit"]');
          if (!processInput) {
            processInput = pdoc.createElement('input');
            processInput.type = 'hidden';
            processInput.name = 'process_submit';
            form.appendChild(processInput);
          }
          processInput.value = 'REPORT_ONLY';

          var updateInput = form.querySelector('input[name="update_ticket"]');
          if (!updateInput) {
            updateInput = pdoc.createElement('input');
            updateInput.type = 'hidden';
            updateInput.name = 'update_ticket';
            form.appendChild(updateInput);
          }
          updateInput.value = '1';

          form.submit();
          return true;
        } catch (err) {
          return false;
        }
      }

      function installEvidenceBridge() {
        var evidenceForm = document.getElementById('evidenceForm');
        if (!evidenceForm) return;

        // provisioning.php calls evidenceForm.submit() directly.
        evidenceForm.submit = function() {
          var idpelField = document.getElementById('provisioning_idpel_result');
          var idpel = idpelField ? String(idpelField.value || '').trim() : '';
          var overlay = document.getElementById('loadingOverlay');
          if (overlay) overlay.style.display = 'flex';

          var sent = submitParentTicket(idpel);
          if (!sent) {
            if (overlay) overlay.style.display = 'none';
            alert('Provisioning sudah selesai. Form tiket tidak ditemukan di parent, silakan simpan manual dari tiket manager.');
          }
        };
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', installEvidenceBridge);
      } else {
        installEvidenceBridge();
      }
    })();
  </script>
</body>
</html>
