<?php
// cs_call_center.php - Halaman CS Call Center (WebRTC voice call antara portal
// pelanggan dan agent/assistant). Routing otomatis: 1 agent cuma bisa terima
// 1 call, kalau agent sibuk/decline otomatis dialihkan ke agent lain yang
// available di area yang sama (lihat cs_call_center_helper.php).
//
// Owner (USER/ADMIN) lihat 3 tab: "Agent Aktif" (roster/monitoring ASSISTANT +
// toggle diri sendiri sbg agent, TIDAK dibatasi area), "Riwayat Telpon" (log
// call selesai/timeout/no_agent), + "Pengaturan CS Agent" (WebRTC STUN/TURN,
// ringtone, ring timeout, toggle). ASSISTANT cuma lihat 1 tab: toggle on/off
// diri sendiri sbg agent (dibatasi area). Modal panggilan masuk + logic
// WebRTC sama utk keduanya begitu toggle-nya ON.
//
// Signaling (offer/answer/ICE) disimpan di tabel `cs_call_center_calls`
// (bukan file JSON per-call spt crm/qchat) supaya routing bisa query SQL
// langsung. Sisi pelanggan (portal) pakai endpoint TERPISAH
// broadband/cs_call_center_api.php krn beda sistem sesi (portal vs admin).

$csCallCenterActions = ['toggle_agent', 'agent_poll', 'answer', 'decline', 'ice', 'get_call', 'end', 'roster', 'call_history', 'get_settings', 'save_settings', 'get_owner_areas', 'save_agent_areas'];
if (isset($_POST['action']) && in_array($_POST['action'], $csCallCenterActions, true)) {
    require_once __DIR__ . '/cek-sesi.php';
    require_once __DIR__ . '/callcenter_access_helper.php';
    require_once __DIR__ . '/cs_call_center_helper.php';
    csCallCenterEnsureTables($conn);

    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'];
    $ownerKey = csCallCenterScopeKey($AKSES, $ceknama);

    // Siapa yang bertindak sbg agent di request ini -- ASSISTANT pakai
    // username sendiri, akun utama (USER/ADMIN) pakai username login-nya
    // sendiri ($ceknama). Akun utama SEKARANG juga boleh jadi agent (bukan
    // cuma monitor/settings), makanya bagian "Assistant actions" di bawah
    // dibuka juga utk USER/ADMIN, tinggal beda identitas username-nya saja.
    $isAgentRole = in_array($AKSES, ['ASSISTANT', 'USER', 'ADMIN'], true);
    $agentUsername = ($AKSES === 'ASSISTANT') ? $asistant_name : $ceknama;

    // ───────────────────────── Agent actions (assistant ATAU akun utama) ─────────────────────────

    if ($action === 'toggle_agent') {
        if (!$isAgentRole) {
            echo json_encode(['success' => false, 'message' => 'Role ini tidak bisa mengubah status agent.']);
            exit;
        }
        $enable = isset($_POST['enable']) ? (bool)(int)$_POST['enable'] : false;
        $enabledInt = $enable ? 1 : 0;
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        // Kalau di-nonaktifkan pas lagi in-call, JANGAN paksa clear call yang
        // sedang aktif -- biar call itu selesai natural, cukup matikan toggle
        // supaya tidak dapat call BARU sesudahnya.
        mysqli_query($conn, "UPDATE user SET callcenter_agent_enabled=$enabledInt, callcenter_last_heartbeat=NOW() WHERE USERNAME='$usernameEsc'");
        echo json_encode(['success' => true, 'enabled' => $enable]);
        exit;
    }

    if ($action === 'agent_poll') {
        if (!$isAgentRole) {
            echo json_encode(['success' => false]);
            exit;
        }
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        mysqli_query($conn, "UPDATE user SET callcenter_last_heartbeat=NOW() WHERE USERNAME='$usernameEsc'");

        $res = mysqli_query($conn, "SELECT callcenter_current_call_id, callcenter_agent_enabled FROM user WHERE USERNAME='$usernameEsc' LIMIT 1");
        $row = $res ? mysqli_fetch_assoc($res) : null;
        $currentCallId = $row['callcenter_current_call_id'] ?? null;
        $enabled = !empty($row['callcenter_agent_enabled']);

        $incoming = null;
        $callStatus = null;
        if ($currentCallId) {
            $callIdEsc = mysqli_real_escape_string($conn, $currentCallId);
            $resCall = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$callIdEsc' LIMIT 1");
            $call = $resCall ? mysqli_fetch_assoc($resCall) : null;
            if ($call) {
                $call = csCallCenterExpireStale($conn, $call);
                $callStatus = $call['status'];
                if ($call['status'] === 'ringing') {
                    $incoming = [
                        'call_id' => $call['id'],
                        'customer_name' => $call['customer_name'],
                        'customer_idpel' => $call['customer_idpel'],
                        'offer_sdp' => $call['offer_sdp'],
                    ];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'enabled' => $enabled,
            'status' => $currentCallId ? 'busy' : 'free',
            'call_status' => $callStatus,
            'incoming' => $incoming,
        ]);
        exit;
    }

    if ($action === 'answer') {
        if (!$isAgentRole) {
            echo json_encode(['success' => false]);
            exit;
        }
        $callId = trim((string)($_POST['call_id'] ?? ''));
        $answerSdp = (string)($_POST['answer_sdp'] ?? '');
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        $callIdEsc = mysqli_real_escape_string($conn, $callId);
        $res = mysqli_query($conn, "SELECT id FROM cs_call_center_calls WHERE id='$callIdEsc' AND agent_username='$usernameEsc' AND status='ringing' LIMIT 1");
        if (!$res || mysqli_num_rows($res) === 0) {
            echo json_encode(['success' => false, 'message' => 'Call tidak ditemukan atau sudah tidak berlaku.']);
            exit;
        }
        $sdpEsc = mysqli_real_escape_string($conn, $answerSdp);
        mysqli_query($conn, "UPDATE cs_call_center_calls SET status='active', answer_sdp='$sdpEsc', answered_at=NOW() WHERE id='$callIdEsc'");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'decline') {
        if (!$isAgentRole) {
            echo json_encode(['success' => false]);
            exit;
        }
        $callId = trim((string)($_POST['call_id'] ?? ''));
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        $callIdEsc = mysqli_real_escape_string($conn, $callId);
        $res = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$callIdEsc' AND agent_username='$usernameEsc' LIMIT 1");
        $call = $res ? mysqli_fetch_assoc($res) : null;
        if (!$call) {
            echo json_encode(['success' => false]);
            exit;
        }
        csCallCenterRerouteAfterDecline($conn, $call);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'ice') {
        // Ditulis dari sisi agent selalu ke kolom ice_agent (server yg tentukan
        // kolom mana, bukan client, sama pola spt qchat/api/signal.php).
        if (!$isAgentRole) {
            echo json_encode(['success' => false]);
            exit;
        }
        $callId = trim((string)($_POST['call_id'] ?? ''));
        $candidate = (string)($_POST['candidate'] ?? '');
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        $callIdEsc = mysqli_real_escape_string($conn, $callId);
        $decoded = json_decode($candidate, true);
        if ($decoded === null) {
            echo json_encode(['success' => false]);
            exit;
        }
        // Baca-lalu-tulis di PHP (SELECT ice_agent -> decode -> append -> UPDATE)
        // adalah race condition: browser kirim beberapa kandidat ICE hampir
        // bersamaan (onicecandidate), request yang overlap saling menimpa hasil
        // tulis (lost update) -- kandidat TURN relay (satu2nya yang bisa
        // nembus lintas jaringan/NAT, beda dari kandidat host yang cukup utk
        // LAN) yang paling sering hilang krn biasanya datang belakangan.
        // Ganti jadi SATU pernyataan atomic (JSON_ARRAY_APPEND) supaya MySQL
        // sendiri yang jamin read-modify-write-nya konsisten -- pola sama
        // dengan fix jsonStoreUpdate() di project_mynvr_walkie_talkie_ice_race_fix.
        $candidateJsonEsc = mysqli_real_escape_string($conn, json_encode($decoded));
        mysqli_query($conn, "UPDATE cs_call_center_calls
            SET ice_agent = JSON_ARRAY_APPEND(COALESCE(ice_agent, '[]'), '$', CAST('$candidateJsonEsc' AS JSON))
            WHERE id='$callIdEsc' AND agent_username='$usernameEsc'");
        if (mysqli_affected_rows($conn) === 0) {
            echo json_encode(['success' => false]);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'get_call') {
        if (!$isAgentRole) {
            echo json_encode(['success' => false]);
            exit;
        }
        $callId = trim((string)($_POST['call_id'] ?? ''));
        $usernameEsc = mysqli_real_escape_string($conn, $agentUsername);
        $callIdEsc = mysqli_real_escape_string($conn, $callId);
        $res = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$callIdEsc' AND agent_username='$usernameEsc' LIMIT 1");
        $call = $res ? mysqli_fetch_assoc($res) : null;
        if (!$call) {
            echo json_encode(['success' => false]);
            exit;
        }
        $call = csCallCenterExpireStale($conn, $call);
        echo json_encode([
            'success' => true,
            'status' => $call['status'],
            'ice_customer' => $call['ice_customer'] ?: '[]',
            // offer_sdp+customer_name ikut disertakan (dipakai
            // cs_call_center_incall.php sekali di awal load utk setup
            // RTCPeerConnection-nya, tidak dipakai polling ICE biasa).
            'offer_sdp' => $call['offer_sdp'],
            'customer_name' => $call['customer_name'],
        ]);
        exit;
    }

    if ($action === 'end') {
        $callId = trim((string)($_POST['call_id'] ?? ''));
        $callIdEsc = mysqli_real_escape_string($conn, $callId);
        $res = mysqli_query($conn, "SELECT * FROM cs_call_center_calls WHERE id='$callIdEsc' LIMIT 1");
        $call = $res ? mysqli_fetch_assoc($res) : null;
        // Boleh diakhiri oleh: agent yang sedang pegang call ini, ATAU owner
        // tenant pemilik call ini (utk intervensi admin) -- bukan sembarang
        // user CRM lain yang kebetulan tahu call_id-nya.
        $isCallOwnerTenant = ($AKSES !== 'ASSISTANT') && $call && $call['owner_key'] === $ownerKey;
        $isCallAgent = $call && $call['agent_username'] === $agentUsername;
        if ($call && !$isCallOwnerTenant && !$isCallAgent) {
            echo json_encode(['success' => false]);
            exit;
        }
        if ($call && $call['status'] !== 'ended') {
            if (!empty($call['agent_username'])) {
                csCallCenterReleaseAgent($conn, $call['agent_username']);
            }
            mysqli_query($conn, "UPDATE cs_call_center_calls SET status='ended', ended_at=NOW(), ended_by='agent' WHERE id='$callIdEsc'");
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ───────────────────────── Owner (USER/ADMIN) actions ─────────────────────────

    if ($action === 'roster') {
        if ($AKSES === 'ASSISTANT') {
            echo json_encode(['success' => false]);
            exit;
        }
        $ownerIdForRoster = (int) $USER_ID;
        $res = mysqli_query($conn, "SELECT USERNAME, inisial, callcenter_agent_enabled, callcenter_last_heartbeat, callcenter_current_call_id, assigned_callcenter_areas
            FROM user
            WHERE (STATUS='ASSISTANT' AND grup=$ownerIdForRoster) OR (id=$ownerIdForRoster AND STATUS IN ('USER','ADMIN'))
            ORDER BY (STATUS='ASSISTANT') ASC, USERNAME ASC");
        $agents = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $isOnline = !empty($row['callcenter_last_heartbeat']) && (strtotime($row['callcenter_last_heartbeat']) > time() - 45);
                $status = 'nonaktif';
                if ($row['callcenter_agent_enabled']) {
                    $status = !$isOnline ? 'offline' : ($row['callcenter_current_call_id'] ? 'sibuk' : 'bebas');
                }
                $areasDecoded = json_decode((string)($row['assigned_callcenter_areas'] ?? ''), true);
                $agents[] = [
                    'username' => $row['USERNAME'],
                    'inisial' => $row['inisial'],
                    'status' => $status,
                    'areas' => is_array($areasDecoded) ? array_values($areasDecoded) : [],
                    'is_owner' => ($row['USERNAME'] === $ceknama),
                ];
            }
        }
        echo json_encode(['success' => true, 'agents' => $agents]);
        exit;
    }

    // Daftar semua AREA milik akun utama (owner) -- dipakai modal "Area yang
    // Dapat Dilayani" di tab Roster supaya owner bisa assign per-assistant,
    // sama pola dgn Live Chat: Area Diizinkan di user.php.
    if ($action === 'get_owner_areas') {
        if ($AKSES === 'ASSISTANT') {
            echo json_encode(['success' => false]);
            exit;
        }
        $ownerIdForAreas = (int) $USER_ID;
        $res = mysqli_query($conn, "SELECT DISTINCT AREA FROM server WHERE user_id = $ownerIdForAreas AND AREA IS NOT NULL AND AREA != '' ORDER BY AREA ASC");
        $areas = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $areas[] = $row['AREA'];
            }
        }
        echo json_encode(['success' => true, 'areas' => $areas]);
        exit;
    }

    if ($action === 'save_agent_areas') {
        if ($AKSES === 'ASSISTANT') {
            echo json_encode(['success' => false]);
            exit;
        }
        $targetUsername = trim((string) ($_POST['username'] ?? ''));
        $areasPosted = json_decode((string) ($_POST['areas'] ?? '[]'), true);
        if (!is_array($areasPosted)) $areasPosted = [];
        $areasClean = array_values(array_unique(array_filter(array_map('strval', $areasPosted), fn($a) => trim($a) !== '')));

        $ownerIdForSave = (int) $USER_ID;
        $usernameEsc = mysqli_real_escape_string($conn, $targetUsername);
        // WAJIB pastikan target beneran ASSISTANT milik owner ini, ATAU akun
        // utama itu sendiri -- cegah owner A iseng edit assigned_callcenter_areas
        // milik assistant/owner LAIN cuma dgn tebak username (IDOR).
        $checkRes = mysqli_query($conn, "SELECT id FROM user
            WHERE USERNAME='$usernameEsc'
              AND ((STATUS='ASSISTANT' AND grup=$ownerIdForSave) OR (id=$ownerIdForSave AND STATUS IN ('USER','ADMIN')))
            LIMIT 1");
        if (!$checkRes || mysqli_num_rows($checkRes) === 0) {
            echo json_encode(['success' => false, 'message' => 'Agent tidak ditemukan.']);
            exit;
        }
        $targetIdForSave = (int) mysqli_fetch_assoc($checkRes)['id'];
        $areasJsonEsc = mysqli_real_escape_string($conn, json_encode($areasClean));
        mysqli_query($conn, "UPDATE user SET assigned_callcenter_areas='$areasJsonEsc' WHERE id=$targetIdForSave");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'call_history') {
        if ($AKSES === 'ASSISTANT') {
            echo json_encode(['success' => false]);
            exit;
        }
        $ownerKeyEsc = mysqli_real_escape_string($conn, $ownerKey);
        $res = mysqli_query($conn, "SELECT customer_name, customer_idpel, customer_area, agent_username, status, ended_by, created_at, answered_at, ended_at
            FROM cs_call_center_calls WHERE owner_key='$ownerKeyEsc' AND status IN ('ended','no_agent','declined')
            ORDER BY created_at DESC LIMIT 50");
        $calls = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $durationSeconds = null;
                if (!empty($row['answered_at']) && !empty($row['ended_at'])) {
                    $durationSeconds = max(0, strtotime($row['ended_at']) - strtotime($row['answered_at']));
                }
                $calls[] = [
                    'customer_name' => $row['customer_name'],
                    'customer_idpel' => $row['customer_idpel'],
                    'customer_area' => $row['customer_area'],
                    'agent_username' => $row['agent_username'],
                    'status' => $row['status'],
                    'ended_by' => $row['ended_by'],
                    'answered' => !empty($row['answered_at']),
                    'duration_seconds' => $durationSeconds,
                    'created_at' => $row['created_at'],
                ];
            }
        }
        echo json_encode(['success' => true, 'calls' => $calls]);
        exit;
    }

    if ($action === 'get_settings') {
        if ($AKSES === 'ASSISTANT') {
            echo json_encode(['success' => false]);
            exit;
        }
        $settings = csCallCenterGetSettings($conn, $ownerKey);
        if (!$settings) {
            $settings = $AKSES === 'ADMIN'
                ? array_merge(['enabled' => 0, 'agent_ringtone' => 'bell1.mp3', 'share_with_users' => 0, 'ring_timeout_seconds' => 30], csCallCenterDefaultWebrtc())
                : ['enabled' => 0, 'webrtc_stun_url' => '', 'webrtc_turn_url' => '', 'webrtc_turn_username' => '', 'webrtc_turn_credential' => '', 'agent_ringtone' => 'bell1.mp3', 'share_with_users' => 0, 'ring_timeout_seconds' => 30];
        }
        echo json_encode(['success' => true, 'settings' => $settings, 'is_admin' => $AKSES === 'ADMIN']);
        exit;
    }

    if ($action === 'save_settings') {
        if ($AKSES === 'ASSISTANT') {
            echo json_encode(['success' => false, 'message' => 'Assistant tidak berhak mengubah pengaturan.']);
            exit;
        }
        $ok = csCallCenterSaveSettings($conn, $ownerKey, $_POST);
        echo json_encode(['success' => $ok, 'message' => $ok ? 'Pengaturan berhasil disimpan.' : 'Gagal menyimpan pengaturan.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    exit;
}

require 'cek-sesi.php';
require_once __DIR__ . '/callcenter_access_helper.php';
require_once __DIR__ . '/cs_call_center_helper.php';
csCallCenterEnsureTables($conn);

if ($AKSES === 'ASSISTANT' && !(is_array($akses_menu) && in_array('CS_Call_Center', $akses_menu))) {
    echo "<script>alert('Anda tidak memiliki akses ke menu CS Call Center.'); window.location.href='dashboard.php';</script>";
    exit;
}

$page_title = "CS Call Center";
require 'header.php';

$_csOwnerKey = csCallCenterScopeKey($AKSES, $ceknama);
$_csSettings = csCallCenterGetSettings($conn, $_csOwnerKey);
if (!$_csSettings && $AKSES === 'ADMIN') {
    $_csSettings = array_merge(['enabled' => 0, 'agent_ringtone' => 'bell1.mp3', 'share_with_users' => 0, 'ring_timeout_seconds' => 30], csCallCenterDefaultWebrtc());
} elseif (!$_csSettings) {
    $_csSettings = ['enabled' => 0, 'webrtc_stun_url' => '', 'webrtc_turn_url' => '', 'webrtc_turn_username' => '', 'webrtc_turn_credential' => '', 'agent_ringtone' => 'bell1.mp3', 'share_with_users' => 0, 'ring_timeout_seconds' => 30];
}
$_csIsAssistant = ($AKSES === 'ASSISTANT');
// Akun utama (USER/ADMIN) SEKARANG juga bisa jadi agent sendiri, bukan cuma
// monitor/settings -- pakai username login sendiri ($ceknama), sama identitas
// yang dipakai action handler agent_* di atas.
$_csAgentUsername = $_csIsAssistant ? $asistant_name : $ceknama;
$_csMyEnabled = false;
$_usernameEsc = mysqli_real_escape_string($conn, $_csAgentUsername);
$_resMe = mysqli_query($conn, "SELECT callcenter_agent_enabled FROM user WHERE USERNAME='$_usernameEsc' LIMIT 1");
$_rowMe = $_resMe ? mysqli_fetch_assoc($_resMe) : null;
$_csMyEnabled = !empty($_rowMe['callcenter_agent_enabled']);
$_csRingtoneOptions = ['bell1.mp3' => 'Bell 1', 'bell2.mp3' => 'Bell 2', 'bell3.mp3' => 'Bell 3'];
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <h4 class="mb-3"><i class="fas fa-headset me-2"></i>CS Call Center</h4>

            <?php if (!$_csIsAssistant): ?>
            <!-- Owner: 3 Tab -->
            <ul class="nav nav-tabs mb-3" id="csccTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-roster-btn" type="button" role="tab">
                        <i class="fas fa-users me-1"></i>Agent Aktif
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-history-btn" type="button" role="tab">
                        <i class="fas fa-history me-1"></i>Riwayat Telpon
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-setting-btn" type="button" role="tab">
                        <i class="fas fa-sliders-h me-1"></i>Pengaturan CS Agent
                    </button>
                </li>
            </ul>
            <div class="tab-content" id="csccTabsContent">
                <div class="tab-pane fade show active" id="tab-roster" role="tabpanel">
                    <div class="card shadow-sm mb-4" id="cardAgentToggle">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-headset"></i>
                                <span class="fw-bold">Saya (Akun Utama) sebagai Agent</span>
                                <span id="agentStatusBadge" class="badge <?php echo $_csMyEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo $_csMyEnabled ? 'AKTIF' : 'NONAKTIF'; ?>
                                </span>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="toggleAgent"
                                    <?php echo $_csMyEnabled ? 'checked' : ''; ?> style="width:3em;height:1.5em;cursor:pointer;">
                                <label class="form-check-label text-white" for="toggleAgent">
                                    <span id="toggleAgentLabel"><?php echo $_csMyEnabled ? 'ON' : 'OFF'; ?></span>
                                </label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info py-2 small mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                Kalau ON, akun utama ini juga ikut menerima panggilan pelanggan (tidak dibatasi AREA
                                tertentu) -- panggilan masuk terdeteksi di halaman MANAPUN Anda sedang buka di
                                billing ini. Panggilan yang diterima terbuka di tab/window baru.
                            </div>
                            <div class="mt-2">Status saat ini: <strong id="agentFreeStatus">-</strong></div>
                        </div>
                    </div>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <span class="fw-bold"><i class="fas fa-users me-2"></i>Agent Aktif</span>
                            <button class="btn btn-sm btn-outline-light" id="btnRefreshRoster"><i class="fas fa-sync-alt"></i></button>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="fas fa-info-circle me-1"></i>
                                Agent = akun ASSISTANT yang toggle Call Center-nya aktif. Klik <strong>Edit Area</strong>
                                di tiap baris untuk atur AREA pelanggan mana saja yang boleh dilayani agent itu -- kalau
                                tidak diatur (kosong), agent tetap dibatasi ke area umum yang di-assign lewat menu
                                <a href="user.php">Kelola User</a>. Akun utama sendiri (di atas) tidak dibatasi area.
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Agent</th><th>Status</th><th>Area yang Dilayani</th><th></th></tr></thead>
                                    <tbody id="rosterBody">
                                        <tr><td colspan="4" class="text-muted text-center">Memuat...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-history" role="tabpanel">
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                            <span class="fw-bold"><i class="fas fa-history me-2"></i>Riwayat Telpon</span>
                            <button class="btn btn-sm btn-outline-light" id="btnRefreshHistory"><i class="fas fa-sync-alt"></i></button>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="fas fa-info-circle me-1"></i>
                                Menampilkan 50 panggilan terakhir yang sudah selesai (dijawab, ditolak, timeout, atau tidak ada agent).
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead><tr><th>Waktu</th><th>Pelanggan</th><th>Agent</th><th>Status</th><th>Durasi</th></tr></thead>
                                    <tbody id="callHistoryBody">
                                        <tr><td colspan="5" class="text-muted text-center">Memuat...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab-setting" role="tabpanel">
                    <?php include __DIR__ . '/cs_call_center_setting_tab.php'; ?>
                </div>
            </div>

            <?php else: ?>
            <!-- Assistant: 1 Tab (toggle on/off) -->
            <div class="card shadow-sm mb-4" id="cardAgentToggle">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-headset"></i>
                        <span class="fw-bold">Status Agent Call Center</span>
                        <span id="agentStatusBadge" class="badge <?php echo $_csMyEnabled ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $_csMyEnabled ? 'AKTIF' : 'NONAKTIF'; ?>
                        </span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="toggleAgent"
                            <?php echo $_csMyEnabled ? 'checked' : ''; ?> style="width:3em;height:1.5em;cursor:pointer;">
                        <label class="form-check-label text-white" for="toggleAgent">
                            <span id="toggleAgentLabel"><?php echo $_csMyEnabled ? 'ON' : 'OFF'; ?></span>
                        </label>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Kalau ON, Anda bisa menerima panggilan pelanggan -- panggilan masuk akan terdeteksi di
                        halaman MANAPUN Anda sedang buka di billing ini, tidak wajib tetap di halaman ini. 1 agent
                        hanya bisa menerima 1 panggilan pada satu waktu -- panggilan baru akan otomatis dialihkan
                        ke agent lain kalau Anda sedang sibuk. Panggilan yang diterima terbuka di tab/window baru.
                    </div>
                    <div>Status saat ini: <strong id="agentFreeStatus">-</strong></div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php if (!$_csIsAssistant): ?>
<!-- Modal: Edit Area yang Dapat Dilayani (per-agent) -->
<div class="modal fade" id="modalAgentAreas" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title">Area yang Dapat Dilayani -- <span id="agentAreasModalUsername"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="agentAreasHint" class="alert alert-secondary py-2 small mb-3">
          Centang AREA yang boleh dilayani agent ini di CS Call Center. Kosongkan semua (tidak centang apapun) supaya
          agent ini kembali ikut area umum yang di-assign lewat menu Kelola User.
        </div>
        <div class="form-check mb-2">
          <input type="checkbox" class="form-check-input" id="agentAreasSelectAll">
          <label class="form-check-label fw-bold" for="agentAreasSelectAll">Pilih Semua</label>
        </div>
        <div id="agentAreasList" class="border rounded p-2" style="max-height:320px;overflow-y:auto;">
          <div class="text-muted small text-center">Memuat...</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" id="btnSaveAgentAreas">
            <span class="btn-label"><i class="fas fa-save me-1"></i>Simpan</span>
            <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1"></span>Menyimpan...</span>
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
    function post(action, data) {
        data = data || {};
        data.action = action;
        return fetch('cs_call_center.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        }).then(r => r.json());
    }

    // ───────────── Tab switcher manual (JS murni, tidak pakai data-bs-toggle) ─────────────
    const csccTabDefs = [
        { btn: 'tab-roster-btn', pane: 'tab-roster' },
        { btn: 'tab-history-btn', pane: 'tab-history' },
        { btn: 'tab-setting-btn', pane: 'tab-setting' }
    ];
    csccTabDefs.forEach(function (def) {
        const btnEl = document.getElementById(def.btn);
        if (!btnEl) return;
        btnEl.addEventListener('click', function (e) {
            e.preventDefault();
            csccTabDefs.forEach(function (def2) {
                const isTarget = def2.btn === def.btn;
                const btnEl2 = document.getElementById(def2.btn);
                const paneEl2 = document.getElementById(def2.pane);
                if (btnEl2) btnEl2.classList.toggle('active', isTarget);
                if (paneEl2) {
                    if (isTarget) {
                        paneEl2.classList.add('show', 'active');
                    } else {
                        paneEl2.classList.remove('show', 'active');
                    }
                }
            });
        });
    });

    // ───────────── Owner: roster tab ─────────────
    const rosterBody = document.getElementById('rosterBody');
    function escCscc(s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function loadRoster() {
        if (!rosterBody) return;
        post('roster', {}).then(res => {
            if (!res.success) return;
            if (!res.agents.length) {
                rosterBody.innerHTML = '<tr><td colspan="4" class="text-muted text-center">Belum ada agent.</td></tr>';
                return;
            }
            const statusBadge = { bebas: 'bg-success', sibuk: 'bg-warning text-dark', offline: 'bg-secondary', nonaktif: 'bg-light text-dark' };
            const statusLabel = { bebas: 'Aktif & Bebas', sibuk: 'Aktif & Sibuk', offline: 'Nonaktif (Offline)', nonaktif: 'Nonaktif' };
            rosterBody.innerHTML = res.agents.map(a => {
                const areas = Array.isArray(a.areas) ? a.areas : [];
                const emptyAreaHint = a.is_owner ? 'Semua area (tidak dibatasi)' : 'Ikut area umum';
                const areasText = areas.length ? areas.map(escCscc).join(', ') : '<span class="text-muted">' + emptyAreaHint + '</span>';
                const ownerBadge = a.is_owner ? ' <span class="badge bg-dark-subtle text-dark border">Akun Utama</span>' : '';
                return '<tr><td>' + escCscc(a.inisial || a.username) + ownerBadge + ' <span class="text-muted small">(' + escCscc(a.username) + ')</span></td>' +
                '<td><span class="badge ' + (statusBadge[a.status] || 'bg-secondary') + '">' + (statusLabel[a.status] || a.status) + '</span></td>' +
                '<td class="small">' + areasText + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-primary btn-edit-agent-areas" data-username="' + escCscc(a.username) + '" data-areas="' + escCscc(JSON.stringify(areas)) + '" data-is-owner="' + (a.is_owner ? '1' : '0') + '"><i class="fas fa-map-marker-alt me-1"></i>Edit Area</button></td></tr>';
            }).join('');
        });
    }
    if (rosterBody) {
        loadRoster();
        setInterval(loadRoster, 8000);
        const btnRefresh = document.getElementById('btnRefreshRoster');
        if (btnRefresh) btnRefresh.addEventListener('click', loadRoster);
    }

    // ───────────── Owner: modal edit Area yang Dapat Dilayani per-agent ─────────────
    const modalAgentAreasEl = document.getElementById('modalAgentAreas');
    if (modalAgentAreasEl && rosterBody) {
        // Lazy-init: JANGAN panggil `new bootstrap.Modal()` di top-level di
        // sini -- footer.php (yang memuat bootstrap.min.js) di-include SETELAH
        // blok <script> ini di halaman, jadi `bootstrap` belum tentu ada saat
        // baris ini jalan (beda dgn pola halaman lain spt vlan.php yang
        // require footer.php duluan). Kalau dipanggil eager, ReferenceError di
        // sini bikin SEMUA event listener sesudahnya di script ini (termasuk
        // toggle agent, riwayat telpon, dll) gagal ke-daftar sama sekali.
        function getAgentAreasModal() {
            return bootstrap.Modal.getOrCreateInstance(modalAgentAreasEl);
        }
        const agentAreasModalUsername = document.getElementById('agentAreasModalUsername');
        const agentAreasList = document.getElementById('agentAreasList');
        const agentAreasSelectAll = document.getElementById('agentAreasSelectAll');
        const btnSaveAgentAreas = document.getElementById('btnSaveAgentAreas');
        const agentAreasHint = document.getElementById('agentAreasHint');
        let _ownerAreasCache = null;
        let _editingUsername = null;

        function fetchOwnerAreas() {
            if (_ownerAreasCache) return Promise.resolve(_ownerAreasCache);
            return post('get_owner_areas', {}).then(res => {
                _ownerAreasCache = (res && res.success) ? (res.areas || []) : [];
                return _ownerAreasCache;
            });
        }

        rosterBody.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-edit-agent-areas');
            if (!btn) return;
            _editingUsername = btn.getAttribute('data-username');
            const isOwner = btn.getAttribute('data-is-owner') === '1';
            let assignedAreas = [];
            try { assignedAreas = JSON.parse(btn.getAttribute('data-areas') || '[]'); } catch (_) {}
            agentAreasModalUsername.textContent = _editingUsername;
            agentAreasHint.textContent = isOwner
                ? 'Centang AREA yang boleh dilayani akun utama ini. Kosongkan semua supaya akun utama tetap unrestricted (bisa terima panggilan dari area manapun), seperti perilaku default.'
                : 'Centang AREA yang boleh dilayani agent ini di CS Call Center. Kosongkan semua (tidak centang apapun) supaya agent ini kembali ikut area umum yang di-assign lewat menu Kelola User.';
            agentAreasList.innerHTML = '<div class="text-muted small text-center">Memuat...</div>';
            getAgentAreasModal().show();
            fetchOwnerAreas().then(areas => {
                if (!areas.length) {
                    agentAreasList.innerHTML = '<div class="text-muted small">Belum ada Server Area terdaftar -- tambah dulu di menu Server.</div>';
                    return;
                }
                agentAreasList.innerHTML = areas.map(area => {
                    const checked = assignedAreas.includes(area) ? 'checked' : '';
                    const id = 'agentArea_' + btoa(unescape(encodeURIComponent(area))).replace(/[^a-zA-Z0-9]/g, '');
                    return '<div class="form-check"><input type="checkbox" class="form-check-input agent-area-checkbox" id="' + id + '" value="' + escCscc(area) + '" ' + checked + '>' +
                        '<label class="form-check-label" for="' + id + '">' + escCscc(area) + '</label></div>';
                }).join('');
                const allChecked = areas.every(area => assignedAreas.includes(area));
                agentAreasSelectAll.checked = allChecked;
            });
        });

        agentAreasSelectAll.addEventListener('change', function () {
            const checked = this.checked;
            agentAreasList.querySelectorAll('.agent-area-checkbox').forEach(cb => { cb.checked = checked; });
        });

        btnSaveAgentAreas.addEventListener('click', function () {
            if (!_editingUsername) return;
            const selected = Array.from(agentAreasList.querySelectorAll('.agent-area-checkbox:checked')).map(cb => cb.value);
            const label = btnSaveAgentAreas.querySelector('.btn-label');
            const loading = btnSaveAgentAreas.querySelector('.btn-loading');
            btnSaveAgentAreas.disabled = true;
            label.classList.add('d-none');
            loading.classList.remove('d-none');
            post('save_agent_areas', { username: _editingUsername, areas: JSON.stringify(selected) })
                .then(res => {
                    if (!res || !res.success) {
                        alert((res && res.message) || 'Gagal menyimpan.');
                        return;
                    }
                    getAgentAreasModal().hide();
                    loadRoster();
                })
                .catch(() => alert('Gagal menghubungi server.'))
                .finally(() => {
                    btnSaveAgentAreas.disabled = false;
                    label.classList.remove('d-none');
                    loading.classList.add('d-none');
                });
        });
    }

    // ───────────── Owner: riwayat telpon tab ─────────────
    const callHistoryBody = document.getElementById('callHistoryBody');
    function formatDuration(sec) {
        if (sec === null || sec === undefined) return '-';
        sec = Math.max(0, parseInt(sec, 10) || 0);
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return m + ':' + String(s).padStart(2, '0');
    }
    function formatCallResult(c) {
        if (c.status === 'ended') {
            if (c.ended_by === 'agent') return '<span class="badge bg-success">Selesai (agent akhiri)</span>';
            if (c.ended_by === 'customer') return '<span class="badge bg-success">Selesai (pelanggan akhiri)</span>';
            if (c.ended_by === 'timeout') return '<span class="badge bg-secondary">Selesai (timeout durasi max)</span>';
            return '<span class="badge bg-success">Selesai</span>';
        }
        if (c.status === 'declined') return '<span class="badge bg-warning text-dark">Ditolak agent</span>';
        if (c.status === 'no_agent') {
            if (c.ended_by === 'timeout') return '<span class="badge bg-danger">Tidak dijawab (timeout)</span>';
            return '<span class="badge bg-danger">Semua agent sibuk/tidak ada</span>';
        }
        return '<span class="badge bg-secondary">' + c.status + '</span>';
    }
    function loadCallHistory() {
        if (!callHistoryBody) return;
        post('call_history', {}).then(res => {
            if (!res.success) return;
            if (!res.calls.length) {
                callHistoryBody.innerHTML = '<tr><td colspan="5" class="text-muted text-center">Belum ada riwayat panggilan.</td></tr>';
                return;
            }
            callHistoryBody.innerHTML = res.calls.map(c =>
                '<tr><td>' + c.created_at + '</td>' +
                '<td>' + (c.customer_name || '-') + ' <span class="text-muted small">(' + (c.customer_idpel || '-') + ')</span></td>' +
                '<td>' + (c.agent_username || '-') + '</td>' +
                '<td>' + formatCallResult(c) + '</td>' +
                '<td>' + (c.answered ? formatDuration(c.duration_seconds) : '-') + '</td></tr>'
            ).join('');
        });
    }
    if (callHistoryBody) {
        loadCallHistory();
        const btnRefreshHistory = document.getElementById('btnRefreshHistory');
        if (btnRefreshHistory) btnRefreshHistory.addEventListener('click', loadCallHistory);
        const tabHistoryBtn = document.getElementById('tab-history-btn');
        if (tabHistoryBtn) tabHistoryBtn.addEventListener('click', loadCallHistory);
    }

    // Bagian di bawah ini (toggle + call flow) jalan kalau elemen togglenya
    // ada di DOM -- baik utk ASSISTANT (halaman 1-tab) MAUPUN akun utama
    // (toggle "Saya sebagai Agent" di Tab 1). Owner yang belum toggle apapun
    // tetap aman krn semua wiring di bawah dibungkus null-check elemen.

    // ───────────── Toggle on/off jadi agent (assistant ATAU akun utama) ─────────────
    const toggle = document.getElementById('toggleAgent');
    const badge = document.getElementById('agentStatusBadge');
    const label = document.getElementById('toggleAgentLabel');
    const freeStatusEl = document.getElementById('agentFreeStatus');

    if (toggle) {
        toggle.addEventListener('change', function () {
            const enabled = toggle.checked;
            label.textContent = enabled ? 'ON' : 'OFF';
            badge.textContent = enabled ? 'AKTIF' : 'NONAKTIF';
            badge.className = 'badge ' + (enabled ? 'bg-success' : 'bg-secondary');
            post('toggle_agent', { enable: enabled ? '1' : '0' }).then(res => {
                if (!res.success) {
                    alert(res.message || 'Gagal mengubah status.');
                    toggle.checked = !enabled;
                    label.textContent = !enabled ? 'ON' : 'OFF';
                }
            });
        });
    }

    // ───────────── Status Bebas/Sibuk ringan (opsional, cuma utk teks status
    // di kartu ini) -- deteksi PANGGILAN MASUK yang sesungguhnya (modal,
    // ringtone, buka tab call) sudah ditangani GLOBAL oleh
    // cs_call_center_agent_widget.php (di-include dari header.php, jalan di
    // SEMUA halaman billing, bukan cuma di sini) supaya status "agent aktif"
    // konsisten kemanapun agent pindah menu. ─────────────
    if (freeStatusEl) {
        function pollAgentStatus() {
            post('agent_poll', {}).then(res => {
                if (!res || !res.success) return;
                freeStatusEl.textContent = res.status === 'busy' ? 'Sibuk (sedang dalam panggilan)' : 'Bebas';
            }).catch(() => {});
        }
        setInterval(pollAgentStatus, 8000);
        pollAgentStatus();
    }
})();
</script>

<?php require 'footer.php'; ?>
