<?php
require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('log', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Log.</div></div>';
        require 'footer.php';
        exit;
    }
}

?>

<div class="container-fluid py-4">
  <div class="row">
    <div class="col-12">
      <div class="card mb-4">
        <div class="card-header pb-0">

          <div class="card-header bg-primary text-white">
            Log History
          </div>
          <br>

          <?php if (isset($_GET['status'])): ?>
            <div class="alert alert-<?php echo ($_GET['status']=='success')?'success':'danger'; ?> alert-dismissible fade show" role="alert">
              <?php echo nl2br(htmlspecialchars(urldecode($_GET['msg']))); ?>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

        </div>
        <div class="card-body px-0 pt-0 pb-2">

          <div class="table-responsive p-0 log-history-table-wrap">

            <style>
              .small-text {
                font-size: 10px;
              }

              .log-history-table-wrap {
                overflow-x: auto !important;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                touch-action: pan-x pan-y;
                position: relative;
              }

              .table-wrap {
                table-layout: fixed;
                width: 100%;
                border: 2px solid #000;
                border-collapse: collapse;
              }

              .table-wrap th, .table-wrap td {
                word-wrap: break-word;
                overflow-wrap: anywhere;
                white-space: pre-wrap;
                vertical-align: top;
                border: 2px solid #000;
              }

              .table-wrap th:nth-child(1), .table-wrap td:nth-child(1) { width: 15%; }
              .table-wrap th:nth-child(2), .table-wrap td:nth-child(2) { width: 15%; }
              .table-wrap th:nth-child(3), .table-wrap td:nth-child(3) { width: 70%; }

              @media (max-width: 768px) {
                .table-wrap {
                  min-width: 860px !important;
                  width: 860px !important;
                  table-layout: fixed;
                }

                .table-wrap th,
                .table-wrap td {
                  font-size: 11px;
                  white-space: nowrap;
                }

                .table-wrap th:nth-child(1), .table-wrap td:nth-child(1) { min-width: 120px; }
                .table-wrap th:nth-child(2), .table-wrap td:nth-child(2) { min-width: 160px; }
                .table-wrap th:nth-child(3), .table-wrap td:nth-child(3) {
                  min-width: 420px;
                  white-space: pre-wrap;
                  word-break: break-word;
                }
              }

              @media (max-width: 480px) {
                .table-wrap {
                  min-width: 820px !important;
                  width: 820px !important;
                }

                .table-wrap th,
                .table-wrap td {
                  font-size: 10px;
                }

                .table-wrap th:nth-child(1), .table-wrap td:nth-child(1) { min-width: 110px; }
                .table-wrap th:nth-child(2), .table-wrap td:nth-child(2) { min-width: 140px; }
                .table-wrap th:nth-child(3), .table-wrap td:nth-child(3) { min-width: 360px; }
              }
            </style>
            <div class="mb-2">
              <div class="row">
                <div class="col-md-4">
                  <input type="text" id="filterUsername" class="form-control" placeholder="Filter Sistem" onkeyup="filterLogTable()">
                </div>
                <div class="col-md-4">
                  <input type="text" id="filterTimestamp" class="form-control" placeholder="Filter Waktu" onkeyup="filterLogTable()">
                </div>
                <div class="col-md-4">
                  <input type="text" id="filterEntry" class="form-control" placeholder="Filter Log" onkeyup="filterLogTable()">
                </div>
              </div>
            </div>
            <table class="table align-items-center mb-0 table-wrap" style="font-size: 12px;">
              <thead>
                <tr>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Sistem</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                  <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Log</th>
                </tr>
              </thead>

              <tbody id="logTable">
                <?php
                // Read history for current user
                $history_file = "notifbot/data/history-$ceknama.json";

                if (file_exists($history_file)) {
                  $history = json_decode(file_get_contents($history_file), true);
                  if (is_array($history)) {
                    $history = array_reverse($history); // Reverse to show newest first
                    foreach ($history as $log_entry) {
                      // Parse the log entry: [ system - timestamp ] message
                      if (preg_match('/^\[(.+)\] (.+)$/s', $log_entry, $matches)) {
                        $header = trim($matches[1]);
                        $message = $matches[2];
                        if (strpos($header, ' - ') !== false) {
                          list($log_username, $log_timestamp) = explode(' - ', $header, 2);
                          $log_username = trim($log_username);
                          $log_timestamp = trim($log_timestamp);
                          $log_message = $message;
                        } else {
                          $log_username = $ceknama;
                          $log_timestamp = '';
                          $log_message = $log_entry;
                        }
                      } else {
                        // Fallback if format doesn't match
                        $log_username = $ceknama;
                        $log_timestamp = '';
                        $log_message = $log_entry;
                      }
                      ?>
                      <tr>
                        <td><?php echo htmlspecialchars($log_username); ?></td>
                        <td><?php echo htmlspecialchars($log_timestamp); ?></td>
                        <td><?php echo htmlspecialchars($log_message); ?></td>
                      </tr>
                      <?php
                    }
                  }
                }
                ?>
              </tbody>
            </table>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function filterLogTable() {
  var usernameFilter = document.getElementById('filterUsername').value.toLowerCase();
  var timestampFilter = document.getElementById('filterTimestamp').value.toLowerCase();
  var entryFilter = document.getElementById('filterEntry').value.toLowerCase();
  var table = document.getElementById('logTable');
  var trs = table.getElementsByTagName('tr');
  for (var i = 0; i < trs.length; i++) {
    var tds = trs[i].getElementsByTagName('td');
    if (tds.length >= 3) {
      var username = tds[0].innerText.toLowerCase();
      var timestamp = tds[1].innerText.toLowerCase();
      var entry = tds[2].innerText.toLowerCase();
      var show = true;
      if (usernameFilter && username.indexOf(usernameFilter) === -1) show = false;
      if (timestampFilter && timestamp.indexOf(timestampFilter) === -1) show = false;
      if (entryFilter && entry.indexOf(entryFilter) === -1) show = false;
      trs[i].style.display = show ? '' : 'none';
    }
  }
}

// Auto-refresh isi tabel log tiap 20 detik, tanpa reload halaman penuh --
// pakai log_history_data.php (fragment endpoint, cuma render <tr> baris log)
// yang sudah ada, filter yang sedang diketik user TETAP diterapkan ulang
// setelah data baru masuk.
function refreshLogHistoryTable() {
  fetch('log_history_data.php?_=' + Date.now(), { credentials: 'same-origin' })
    .then(function (res) { return res.text(); })
    .then(function (html) {
      var table = document.getElementById('logTable');
      if (table) {
        table.innerHTML = html;
        filterLogTable();
      }
    })
    .catch(function (err) { console.error('Gagal auto-refresh log history:', err); });
}
setInterval(refreshLogHistoryTable, 20000);

document.addEventListener('DOMContentLoaded', function () {
  var wrapper = document.querySelector('.log-history-table-wrap');
  if (!wrapper) return;

  var startX = 0;
  var startY = 0;
  var isDragging = false;

  wrapper.addEventListener('touchstart', function (e) {
    if (!e.touches || e.touches.length !== 1) return;
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
    isDragging = true;
  }, { passive: true });

  wrapper.addEventListener('touchmove', function (e) {
    if (!isDragging || !e.touches || e.touches.length !== 1) return;

    var currentX = e.touches[0].clientX;
    var currentY = e.touches[0].clientY;
    var deltaX = startX - currentX;
    var deltaY = startY - currentY;

    // Prioritaskan drag horizontal untuk scroll tabel.
    if (Math.abs(deltaX) > Math.abs(deltaY)) {
      wrapper.scrollLeft += deltaX;
      startX = currentX;
      startY = currentY;
      e.preventDefault();
    }
  }, { passive: false });

  wrapper.addEventListener('touchend', function () {
    isDragging = false;
  }, { passive: true });
});
</script>

<?php require 'footer.php'; ?>