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
$sql_ticket = 'SELECT * FROM billing_tiket_manager WHERE id = ' . $ticket_id . ' AND server_id IN (' . $in_ids . ') LIMIT 1';
$res_ticket = mysqli_query($conn, $sql_ticket);
$ticket = $res_ticket ? mysqli_fetch_assoc($res_ticket) : null;
if (!$ticket) {
    echo '<div style="padding:16px;font-family:Arial">Tiket tidak ditemukan atau tidak diizinkan.</div>';
    exit;
}

$source = trim((string)($ticket['report'] ?? '')) . "\n" . trim((string)($ticket['detail'] ?? ''));
$idpel = '';
if (preg_match('/ID\s*PELANGGAN\s*:?\s*([A-Za-z0-9@._-]+)/i', $source, $m)) {
    $idpel = trim((string)$m[1]);
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dismantle Tiket #<?php echo (int)$ticket_id; ?></title>
  <style>
    body { background:#f8fafc; font-family: Arial, sans-serif; }
    .wrap { max-width: 760px; margin: 24px auto; background:#fff; border:1px solid #dbe4ef; border-radius:10px; padding:16px; }
    .row { margin-bottom:10px; }
    .label { font-size:12px; color:#475569; margin-bottom:4px; }
    .input { width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; }
    .btn { padding:10px 14px; border:0; border-radius:8px; cursor:pointer; font-weight:700; }
    .btn-danger { background:#dc2626; color:#fff; }
    .btn-success { background:#16a34a; color:#fff; }
    .actions { display:flex; gap:10px; align-items:center; }
    .out { margin-top:14px; white-space:pre-wrap; font-size:13px; }
    .result-box { border:1px solid #d1fae5; background:#f0fdf4; border-radius:10px; padding:12px; }
    .result-err { border-color:#fecaca; background:#fef2f2; }
    .result-title { font-weight:800; margin-bottom:8px; }
  </style>
</head>
<body>
  <div class="wrap">
    <h3 style="margin-top:0;">Proses Dismantle Tiket #<?php echo (int)$ticket_id; ?></h3>
    <div class="row">
      <div class="label">ID Pelanggan</div>
      <input id="idpel" class="input" value="<?php echo htmlspecialchars($idpel, ENT_QUOTES, 'UTF-8'); ?>" placeholder="contoh: QTS-001@250526">
    </div>
    <div class="actions">
      <button id="btnRun" class="btn btn-danger">PROSES DISMANTLE</button>
    </div>
    <div id="out" class="out"></div>
  </div>

  <script>
    function appendIdpelToReport(reportText, idpel) {
      const text = String(reportText || '');
      if (!idpel) return text;
      if (/ID\s*Pelanggan\s*:/i.test(text)) {
        return text.replace(/(ID\s*Pelanggan\s*:\s*)([^\n\r]*)/i, '$1' + idpel);
      }
      return (text ? (text + '\n') : '') + 'ID Pelanggan: ' + idpel;
    }

    function submitParentTicket(idpel, message) {
      try {
        if (!window.parent || window.parent === window) return false;
        const pdoc = window.parent.document;
        if (!pdoc) return false;
        const form = pdoc.getElementById('ticketUpdateForm<?php echo (int)$ticket_id; ?>');
        if (!form) return false;

        const statusEl = form.querySelector('select[name="status"]');
        if (statusEl) statusEl.value = 'DONE';

        const reportEl = form.querySelector('.ticket-report-input');
        if (reportEl) {
          let nextReport = appendIdpelToReport(reportEl.value, idpel);
          if (message) {
            nextReport = (nextReport ? nextReport + '\n' : '') + 'DISMANTLE: ' + message;
          }
          reportEl.value = nextReport;
        }

        let processInput = form.querySelector('input[name="process_submit"]');
        if (!processInput) {
          processInput = pdoc.createElement('input');
          processInput.type = 'hidden';
          processInput.name = 'process_submit';
          form.appendChild(processInput);
        }
        processInput.value = 'REPORT_ONLY';

        let skipInput = form.querySelector('input[name="skip_dismantle_process"]');
        if (!skipInput) {
          skipInput = pdoc.createElement('input');
          skipInput.type = 'hidden';
          skipInput.name = 'skip_dismantle_process';
          form.appendChild(skipInput);
        }
        skipInput.value = '1';

        let updateInput = form.querySelector('input[name="update_ticket"]');
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

    document.getElementById('btnRun').addEventListener('click', function() {
      const idpel = String(document.getElementById('idpel').value || '').trim();
      const out = document.getElementById('out');
      if (idpel === '') {
        out.textContent = 'ID Pelanggan wajib diisi.';
        return;
      }
      this.disabled = true;
      out.textContent = 'Memproses...';
      fetch('../joblist/evidence/proses_dismntle.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'idpel=' + encodeURIComponent(idpel) + '&id_tiket=' + encodeURIComponent('<?php echo (int)$ticket_id; ?>')
      })
      .then(r => r.json())
      .then(j => {
        const msg = (j && j.message) ? String(j.message) : 'Selesai';
        if (j && j.success) {
          out.innerHTML = ''
            + '<div class="result-box">'
            + '  <div class="result-title">Dismantle berhasil diproses</div>'
            + '  <div>' + msg + '</div>'
            + '  <div style="margin-top:12px;">'
            + '    <button id="btnSaveToTicket" class="btn btn-success" type="button">LANJUT SIMPAN LAPORAN</button>'
            + '  </div>'
            + '</div>';

          document.getElementById('btnSaveToTicket').addEventListener('click', function() {
            const ok = submitParentTicket(idpel, msg);
            if (!ok) {
              alert('Proses dismantle sudah berhasil. Form tiket parent tidak ditemukan, silakan simpan manual dari tiket manager.');
            }
          });
        } else {
          out.innerHTML = ''
            + '<div class="result-box result-err">'
            + '  <div class="result-title">Proses dismantle gagal</div>'
            + '  <div>' + msg + '</div>'
            + '</div>';
        }
      })
      .catch(() => {
        out.textContent = 'Gagal memanggil proses dismantle.';
      })
      .finally(() => {
        document.getElementById('btnRun').disabled = false;
      });
    });
  </script>
</body>
</html>
