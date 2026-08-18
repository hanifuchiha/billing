<?php require 'header.php';
if ($AKSES == 'ASSISTANT') {
    if (!isset($akses_menu) || !is_array($akses_menu) || !in_array('Live_Chat', $akses_menu, true)) {
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Anda tidak memiliki akses ke menu Live Chat.</div></div>';
        require 'footer.php';
        exit;
    }
}
 ?>

<?php
// Fetch all bots owned by this user
$bots = [];
if ($AKSES == 'USER' || $AKSES == 'ADMIN' || $AKSES == 'ASSISTANT') {
    if ($AKSES == 'ADMIN') {
        $sql_bots = "SELECT id, namebot, addressbot, webhook FROM botwa ORDER BY id DESC";
        $result_bots = mysqli_query($conn, $sql_bots);
    } elseif ($AKSES == 'ASSISTANT') {
        // Tab Webhook per-bot cuma boleh nampilin bot yg memang di-assign/
        // dibuat sendiri assistant ini -- pola sama dgn wabot.php, lihat
        // notifbot/bot_access_helper.php.
        $botWhere = botAccessWhereClause($conn, $AKSES, $assigned_bot_ids ?? [], $asistant_name ?? '');
        $sql_bots = "SELECT id, namebot, addressbot, webhook FROM botwa WHERE pemilik = '" . mysqli_real_escape_string($conn, $ceknama) . "'" . $botWhere . " ORDER BY id DESC";
        $result_bots = mysqli_query($conn, $sql_bots);
    } else {
        $sql_bots = "SELECT id, namebot, addressbot, webhook FROM botwa WHERE pemilik = ? ORDER BY id DESC";
        $stmt_bots = $conn->prepare($sql_bots);
        $stmt_bots->bind_param('s', $ceknama);
        $stmt_bots->execute();
        $result_bots = $stmt_bots->get_result();
    }

    if ($result_bots && mysqli_num_rows($result_bots) > 0) {
        while ($bot = mysqli_fetch_assoc($result_bots)) {
            $bots[] = $bot;
        }
    }
}
?>

<?php if ($AKSES == 'USER' || $AKSES == 'ADMIN' || $AKSES == 'ASSISTANT') { ?>
<div class="container-fluid py-4">
  <div class="card shadow">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="fas fa-comments"></i> Live Chat & Bot Webhooks</h5>
      
    </div>
    
    <!-- Tabs Navigation -->
    <div class="card-body p-0 border-bottom">
      <ul class="nav nav-tabs mb-0" role="tablist" style="display: flex; flex-wrap: nowrap; overflow-x: auto;">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="livechat-tab" data-tab-target="#livechat-panel" type="button" role="tab" aria-controls="livechat-panel" aria-selected="true">
            <i class="fas fa-headset"></i> Live Chat
            <span class="badge badge-unread badge-livechat" style="display:none; margin-left: 6px; background-color: #dc3545;">0</span>
          </button>
        </li>
        <?php foreach ($bots as $bot): ?>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="webhook-tab-<?php echo $bot['id']; ?>" data-tab-target="#webhook-panel-<?php echo $bot['id']; ?>" type="button" role="tab" aria-controls="webhook-panel-<?php echo $bot['id']; ?>" aria-selected="false">
            <i class="fas fa-webhook"></i> <?php echo htmlspecialchars($bot['namebot']); ?>
            <span class="badge badge-unread badge-webhook-<?php echo $bot['id']; ?>" style="display:none; margin-left: 6px; background-color: #dc3545;">0</span>
          </button>
        </li>
        <?php endforeach; ?>
        <li class="nav-item ms-auto" role="presentation" style="margin-left:auto;">
          <a href="wabot.php" class="nav-link tab-add-bot" style="color:#fff; background:#22c55e; border-radius:0 0.5rem 0 0; font-weight:700; display:flex; align-items:center; gap:6px;">
            <i class="fas fa-plus"></i> Tambah WA Bot
          </a>
        </li>
      </ul>
    </div>

    <!-- Tab Content -->
    <div class="tab-content" style="height: calc(100vh - 280px); overflow: hidden;">
      
      <!-- Live Chat Panel -->
      <div class="tab-pane fade show active" id="livechat-panel" role="tabpanel" aria-labelledby="livechat-tab" style="height: 100%;">
        <iframe src="<?php
          if ($AKSES == 'ADMIN') {
              // admin=admin: akun ADMIN adalah master admin pemilik web ini, dan
              // pelanggan miliknya sendiri tersimpan dgn PEMILIK='admin' (bukan
              // username login-nya) -- jadi "admin" di sini SUDAH BENAR sbg
              // identitas tenant asli, dipakai apa adanya oleh crm/chat/index.php
              // (termasuk utk scope AI Bot Live Chat, lihat livechatAiScopeKey()).
              echo $config['URL'] . '/crm/chat/index.php?admin=admin';
          } else {
              $chatUrl = $config['URL'] . '/crm/chat/index.php?admin=' . urlencode($ceknama);
              if ($AKSES == 'ASSISTANT') {
                  // Assign Live Chat per-assistant (seperti Bot WA) -- kalau
                  // assistant ini tidak di-assign AREA spesifik, fallback ke
                  // area umum dia ($arealist). Lihat livechat_access_helper.php.
                  $chatAllowedAreas = livechatAccessResolveAreas($AKSES, $assigned_livechat_areas ?? [], $arealist ?? []);
                  $chatUrl .= '&areas=' . urlencode(json_encode($chatAllowedAreas));
              }
              echo $chatUrl;
          }
        ?>" style="width:100%;height:100%;border:0;display:block;margin:0;padding:0;"></iframe>
      </div>

      <!-- Bot Webhook Panels -->
      <?php foreach ($bots as $bot): ?>
      <div class="tab-pane fade" id="webhook-panel-<?php echo $bot['id']; ?>" role="tabpanel" aria-labelledby="webhook-tab-<?php echo $bot['id']; ?>" style="height: 100%;">
        <div style="height: 100%; overflow: hidden; margin: 0; padding: 0;">
          <?php 
            $webhookUrl = $bot['webhook'];
            if (!empty($webhookUrl)) {
              // Generate secure token untuk auto-login
              $tokenData = [
                'namebot' => $bot['namebot'],
                'timestamp' => time(),
                'user' => $ceknama
              ];
              $token = base64_encode(json_encode($tokenData));
              $webhookUrlWithToken = $webhookUrl . (strpos($webhookUrl, '?') !== false ? '&' : '?') . 'auth_token=' . urlencode($token);
              echo '<iframe src="' . htmlspecialchars($webhookUrlWithToken) . '" style="width:100%;height:100%;border:none;border-radius:0;display:block;margin:0;padding:0;"></iframe>';
            } else {
              echo '<div class="alert alert-warning">Webhook URL tidak tersedia untuk bot ini.</div>';
            }
          ?>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Empty State if No Bots -->
      <?php if (count($bots) == 0): ?>
      <div class="tab-pane fade" role="tabpanel" style="height: 100%;">
        <div class="text-center d-flex flex-column align-items-center justify-content-center" style="height: 100%;">
          <div class="mb-3">
            <i class="fas fa-robot" style="font-size: 48px; color: #ccc;"></i>
          </div>
          <h5 class="text-muted">Belum ada Bot WhatsApp</h5>
          <p class="text-muted mb-3">Anda belum membuat bot WhatsApp. Klik tombol di atas untuk menambahkan bot pertama Anda.</p>
          <a href="wabot.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Buat Bot Sekarang
          </a>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<style>
/* Tab & sidebar style: kontras, konsisten tema */
.nav-tabs .nav-link.active {
  border-bottom: 3px solid var(--bs-primary, #f97316);
  color: var(--bs-primary, #f97316) !important;
  font-weight: 700;
  background: var(--bs-body-bg, #fff);
  box-shadow: 0 2px 8px 0 #e9ecef33;
}
.nav-tabs .nav-link {
  color: var(--bs-body-color, #71717a);
  border: none;
  border-bottom: 3px solid transparent;
  transition: all 0.3s ease;
  padding: 12px 16px !important;
  position: relative;
  background: transparent;
  font-weight: 500;
}
.nav-tabs .nav-link:hover {
  color: var(--bs-primary, #f97316);
  border-bottom: 3px solid var(--bs-primary-bg-subtle, #fde3d0);
}
.nav-tabs .nav-link i {
  margin-right: 6px;
}
.tab-pane {
  animation: fadeIn 0.3s ease-in;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
/* Unread badge styling */
.badge-unread {
  font-size: 11px;
  padding: 3px 6px;
  border-radius: 10px;
  font-weight: bold;
  animation: pulse-badge 1.5s infinite;
}
@keyframes pulse-badge {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.6; }
}
.nav-tabs .nav-link.has-unread {
  background-color: var(--bs-warning-bg-subtle, #fff3cd);
  border-radius: 4px 4px 0 0;
}
/* Force deterministic tab visibility without Bootstrap JS dependency */
.tab-content > .tab-pane {
  display: none;
}
.tab-content > .tab-pane.active {
  display: block;
  background: var(--bs-body-bg, #fff);
color: var(--bs-primary, #f97316);
  font-weight: 600;
}


/* Tombol WA Bot hijau statis, tanpa animasi, tanpa perubahan warna di semua state */
.btn-theme-toggle,
.btn-theme-toggle:hover,
.btn-theme-toggle:focus,
.btn-theme-toggle:active,
.btn-theme-toggle:visited,
.btn-theme-toggle:disabled,
[data-bs-theme="dark"] .btn-theme-toggle,
[data-bs-theme="dark"] .btn-theme-toggle:hover,
[data-bs-theme="dark"] .btn-theme-toggle:focus,
[data-bs-theme="dark"] .btn-theme-toggle:active,
[data-bs-theme="dark"] .btn-theme-toggle:visited,
[data-bs-theme="dark"] .btn-theme-toggle:disabled {
  background: #22c55e !important;
  color: #fff !important;
  border: 1px solid #22c55e !important;
  font-weight: 700;
  box-shadow: none !important;
  text-shadow: none !important;
  outline: none !important;
  transition: none !important;
  filter: none !important;
}


/* Tab Add Bot styling */
.nav-tabs .tab-add-bot {
  color: #fff !important;
  background: #22c55e !important;
  border: none !important;
  font-weight: 700;
  border-radius: 0 0.5rem 0 0 !important;
  margin-left: 8px;
  transition: none !important;
}
.nav-tabs .tab-add-bot:hover,
.nav-tabs .tab-add-bot:focus,
.nav-tabs .tab-add-bot:active {
  color: #fff !important;
  background: #16a34a !important;
  text-decoration: none;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Track unread messages per tab
    const unreadCounts = {
        'livechat': 0,
        'webhooks': {}
    };
    
    // Listen for postMessage events from iframes
    window.addEventListener('message', function(event) {
        // Basic security check (in production, verify origin)
        if (!event.data || !event.data.type) return;
        
        if (event.data.type === 'NEW_MESSAGE') {
            const source = event.data.source || 'livechat'; // 'livechat' or 'webhook-{id}'
            
            if (source === 'livechat') {
                unreadCounts.livechat++;
                updateBadge('livechat', unreadCounts.livechat);
            } else if (source.startsWith('webhook-')) {
                const botId = source.replace('webhook-', '');
                unreadCounts.webhooks[botId] = (unreadCounts.webhooks[botId] || 0) + 1;
                updateBadge('webhook-' + botId, unreadCounts.webhooks[botId]);
            }
            
            // Play notification sound
            playNotificationSound();
        }
    }, false);
    
    // Update badge display
    function updateBadge(target, count) {
        let badgeElement;
        
        if (target === 'livechat') {
            badgeElement = document.querySelector('.badge-livechat');
        } else if (target.startsWith('webhook-')) {
            const botId = target.replace('webhook-', '');
            badgeElement = document.querySelector('.badge-webhook-' + botId);
        }
        
        if (badgeElement) {
            if (count > 0) {
                badgeElement.textContent = count;
                badgeElement.style.display = 'inline-block';
                
                // Add has-unread class to tab button
                let tabButton;
                if (target === 'livechat') {
                    tabButton = document.getElementById('livechat-tab');
                } else if (target.startsWith('webhook-')) {
                    const botId = target.replace('webhook-', '');
                    tabButton = document.getElementById('webhook-tab-' + botId);
                }
                
                if (tabButton && !tabButton.classList.contains('has-unread')) {
                    tabButton.classList.add('has-unread');
                }
            } else {
                badgeElement.style.display = 'none';
            }
        }
    }
    
    // Play notification sound
    function playNotificationSound() {
        const audio = new Audio('data:audio/wav;base64,UklGRiYAAABXQVZFZm10IBAAAAABAAEAQB8AAAB9AAACABAAZGF0YQIAAAAAAA==');
        audio.play().catch(() => {
            // Silently fail if audio can't play
        });
    }
    
    // Handle tab switching with custom deterministic system.
    const tabButtons = document.querySelectorAll('[data-tab-target]');

    function clearUnreadForTab(tabButton) {
      if (!tabButton || !tabButton.id) return;
      const tabId = tabButton.id;

      if (tabId === 'livechat-tab') {
        unreadCounts.livechat = 0;
        updateBadge('livechat', 0);
        tabButton.classList.remove('has-unread');
      } else if (tabId.startsWith('webhook-tab-')) {
        const botId = tabId.replace('webhook-tab-', '');
        unreadCounts.webhooks[botId] = 0;
        updateBadge('webhook-' + botId, 0);
        tabButton.classList.remove('has-unread');
      }
    }

    function activateTabManually(tabButton) {
      if (!tabButton) return;
      const targetSelector = tabButton.getAttribute('data-tab-target');
      if (!targetSelector) return;
      const targetPane = document.querySelector(targetSelector);
      if (!targetPane) return;

      tabButtons.forEach((btn) => {
        btn.classList.remove('active');
        btn.setAttribute('aria-selected', 'false');
      });

      document.querySelectorAll('.tab-content .tab-pane').forEach((pane) => {
        pane.classList.remove('show', 'active');
      });

      tabButton.classList.add('active');
      tabButton.setAttribute('aria-selected', 'true');
      targetPane.classList.add('show', 'active');

      clearUnreadForTab(tabButton);
      console.log('✅ Tab switched (manual) to:', tabButton.id);
    }

    tabButtons.forEach((button) => {
      button.addEventListener('click', function(event) {
        event.preventDefault();
        activateTabManually(this);
      });
    });

    console.log('✅ Tab system initialized. Found', tabButtons.length, 'tabs');
});
</script>

<?php } else { ?>
<div class="container mt-4">
  <div class="alert alert-info mb-0">
    <i class="fas fa-lock"></i> Live Chat hanya tersedia untuk USER dan ADMIN.
  </div>
</div>
<?php } ?>

<?php require 'footer.php'; ?>
