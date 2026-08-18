<?php
/**
 * cs_call_center_incall.php - Halaman KHUSUS 1 panggilan yang SUDAH diterima,
 * sengaja dibuka di tab/window BARU (window.open() dari
 * cs_call_center_agent_widget.php) supaya agent bisa lanjut pindah2 menu di
 * tab utama TANPA memutus panggilan -- navigasi halaman biasa (bukan SPA)
 * SELALU mematikan javascript+RTCPeerConnection yang sedang berjalan, jadi
 * satu2nya cara panggilan tetap hidup selama agent kerja di tab lain adalah
 * dengan menaruh panggilannya di tab SENDIRI yang tidak ikut ke-navigate.
 *
 * Halaman ini SENGAJA berdiri sendiri (bukan require 'header.php') -- ringan,
 * cuma fokus ke 1 panggilan, tidak perlu sidebar/menu billing lengkap.
 */
require 'cek-sesi.php';
require_once __DIR__ . '/callcenter_access_helper.php';
require_once __DIR__ . '/cs_call_center_helper.php';
csCallCenterEnsureTables($conn);

if ($AKSES === 'ASSISTANT' && !(is_array($akses_menu) && in_array('CS_Call_Center', $akses_menu))) {
    echo 'Anda tidak memiliki akses ke menu CS Call Center.';
    exit;
}

$callId = trim((string) ($_GET['call_id'] ?? ''));
$agentUsername = ($AKSES === 'ASSISTANT') ? $asistant_name : $ceknama;
$ownerKey = csCallCenterScopeKey($AKSES, $ceknama);
$webrtc = csCallCenterResolveWebrtcConfig($conn, $ownerKey);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panggilan Aktif - CS Call Center</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(180deg, #0a1628 0%, #1a2e48 60%, #0d1b2a 100%);
        color: #fff;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: space-between;
        padding: 30px 20px;
        text-align: center;
    }
    .customer-name { font-size: 20px; font-weight: 700; margin-top: 24px; }
    .status { margin-top: 10px; font-size: 14px; opacity: .85; }
    .timer { font-size: 26px; font-weight: 700; margin-top: 8px; }
    .timer.d-none { display: none !important; }
    .actions { display: flex; justify-content: center; gap: 22px; margin-bottom: 10px; width: 100%; }
    .actions.d-none { display: none !important; }
    .actions button {
        width: 58px; height: 58px; border-radius: 50%; border: none;
        font-size: 20px; display: flex; align-items: center; justify-content: center;
        background: rgba(255,255,255,.15); color: #fff;
    }
    .actions button.active { background: #fff; color: #0a1628; }
    .actions button.hangup { background: #e53935; color: #fff; width: 68px; height: 68px; font-size: 24px; }
    .close-note { font-size: 12px; opacity: .6; margin-top: 6px; }
    .cancel-btn {
        background: rgba(229,57,53,.85);
        color: #fff;
        border: none;
        border-radius: 24px;
        padding: 10px 24px;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 10px;
    }
    .cancel-btn.d-none { display: none !important; }
</style>
</head>
<body>
    <div>
        <div class="customer-name" id="customerName">Memuat...</div>
        <div class="status" id="statusText">Menghubungkan...</div>
        <div class="timer d-none" id="timerEl">00:00</div>
    </div>
    <div>
        <button type="button" class="cancel-btn" id="btnCancelConnecting">
            <i class="bi bi-x-circle me-1"></i>Batal / Tutup
        </button>
        <div class="actions d-none" id="actionsRow">
            <button type="button" id="btnMute" title="Mute"><i class="bi bi-mic-fill"></i></button>
            <button type="button" class="hangup" id="btnHangup" title="Akhiri"><i class="bi bi-telephone-x-fill"></i></button>
        </div>
        <div class="close-note" id="closeNote"></div>
    </div>
    <audio id="remoteAudio" autoplay></audio>
    <?php if (!empty($webrtc['debug_mode'])): ?>
    <div id="debugLog" style="width:100%;max-width:360px;margin-top:12px;background:#000;color:#0f0;font-family:monospace;font-size:10px;padding:8px;border-radius:6px;max-height:160px;overflow-y:auto;text-align:left;white-space:pre-wrap;"></div>
    <?php endif; ?>

<script>
window.CSCC_INCALL_ICE_SERVERS = {
    iceServers: <?php echo json_encode(csCallCenterBuildIceServersArray($webrtc)); ?>
};
window.CSCC_DEBUG_MODE = <?php echo !empty($webrtc['debug_mode']) ? 'true' : 'false'; ?>;
</script>
<script>
(function () {
    const callId = <?php echo json_encode($callId); ?>;
    const customerNameEl = document.getElementById('customerName');
    const statusText = document.getElementById('statusText');
    const timerEl = document.getElementById('timerEl');
    const actionsRow = document.getElementById('actionsRow');
    const closeNote = document.getElementById('closeNote');
    const remoteAudio = document.getElementById('remoteAudio');
    const cancelBtn = document.getElementById('btnCancelConnecting');
    const debugLog = document.getElementById('debugLog');
    function dbg(msg) {
        if (!window.CSCC_DEBUG_MODE || !debugLog) return;
        const t = new Date().toISOString().substr(11, 8);
        debugLog.textContent += '[' + t + '] AGENT: ' + msg + '\n';
        debugLog.scrollTop = debugLog.scrollHeight;
    }
    dbg('Halaman dimuat. ICE servers: ' + JSON.stringify(window.CSCC_INCALL_ICE_SERVERS.iceServers.map(s => s.urls)));

    if (!callId) {
        statusText.textContent = 'Call ID tidak valid.';
        return;
    }

    let pc = null;
    let localStream = null;
    let pollTimer = null;
    let pollInFlight = false;
    let timerInterval = null;
    let seconds = 0;
    let seenRemoteIce = {};
    let ended = false;
    let answered = false;

    function post(action, data) {
        data = data || {};
        data.action = action;
        return fetch('cs_call_center.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        }).then(r => r.json());
    }

    function cleanupAndClose(message) {
        if (ended) return;
        ended = true;
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (timerInterval) { clearInterval(timerInterval); timerInterval = null; }
        if (pc) { try { pc.close(); } catch (_) {} pc = null; }
        if (localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
        statusText.textContent = message || 'Panggilan berakhir.';
        actionsRow.classList.add('d-none');
        cancelBtn.classList.add('d-none');
        closeNote.textContent = 'Jendela ini akan tertutup otomatis...';
        setTimeout(() => { window.close(); }, 1500);
    }

    async function start() {
        const detail = await post('get_call', { call_id: callId }).catch(() => null);
        if (!detail || !detail.success || detail.status !== 'ringing' || !detail.offer_sdp) {
            cleanupAndClose('Panggilan tidak ditemukan atau sudah tidak berlaku.');
            return;
        }
        customerNameEl.textContent = detail.customer_name || 'Pelanggan';

        try {
            localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            dbg('getUserMedia OK, audio tracks: ' + localStream.getAudioTracks().length);
        } catch (err) {
            dbg('getUserMedia GAGAL: ' + err.message);
            statusText.textContent = 'Tidak dapat mengakses mikrofon: ' + err.message;
            await post('decline', { call_id: callId });
            closeNote.textContent = 'Jendela ini akan tertutup otomatis...';
            setTimeout(() => { window.close(); }, 2000);
            return;
        }

        pc = new RTCPeerConnection(window.CSCC_INCALL_ICE_SERVERS);
        localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
        pc.ontrack = (e) => {
            dbg('ontrack diterima (audio remote datang)');
            remoteAudio.srcObject = e.streams[0];
            // Sama seperti sisi pelanggan (cs_call_widget.php) -- atribut
            // `autoplay` saja kadang diam-diam diblokir browser tanpa error
            // yang kelihatan, jadi status sudah "Tersambung" tapi suara mati.
            // Paksa play() eksplisit + retry.
            const tryPlayRemoteAudio = () => {
                remoteAudio.play().then(() => dbg('remoteAudio.play() OK')).catch((e2) => { dbg('remoteAudio.play() GAGAL: ' + e2.message + ', retry 1s'); setTimeout(tryPlayRemoteAudio, 1000); });
            };
            tryPlayRemoteAudio();
        };
        pc.onicecandidate = (e) => {
            if (!e.candidate) { dbg('ICE gathering selesai (kandidat habis)'); return; }
            const c = e.candidate.candidate || '';
            const typeMatch = c.match(/typ (\w+)/);
            dbg('Kirim kandidat ICE lokal: type=' + (typeMatch ? typeMatch[1] : '?') + ' -> ' + c.substring(0, 60));
            post('ice', { call_id: callId, candidate: JSON.stringify(e.candidate) });
        };
        pc.oniceconnectionstatechange = () => { dbg('iceConnectionState -> ' + pc.iceConnectionState); };
        pc.onicegatheringstatechange = () => { dbg('iceGatheringState -> ' + pc.iceGatheringState); };
        // WAJIB: kalau PC tidak PERNAH mencapai 'connected' sama sekali dalam
        // waktu wajar -- browser KADANG macet permanen di 'new'/'connecting'/
        // 'checking' TANPA PERNAH transisi ke 'failed' (terutama kalau TURN
        // server lagi flaky/lambat merespon Allocate). Tanpa timeout ini,
        // panggilan macet begini nyangkut SELAMANYA tanpa ada tindakan apa
        // pun. Pola sama persis dgn createPc() di crm/mynvr/assets/js/walkie.js.
        const stuckConnectingTimer = setTimeout(() => {
            if (pc && pc.connectionState !== 'connected') {
                const cid = callId;
                cleanupAndClose('Gagal tersambung (timeout).');
                post('end', { call_id: cid });
            }
        }, 15000);
        // 'disconnected' beda dari 'failed'/'closed' -- browser masih coba
        // pulihkan sendiri di background (blip jaringan sesaat, WAJAR &
        // sering pulih sendiri dalam beberapa detik, apalagi lintas jaringan
        // yang jelas lebih tidak stabil dari LAN). Sebelumnya kondisi ini
        // langsung dianggap gagal & call diputus paksa -- akibatnya panggilan
        // yang sebenarnya bisa pulih malah keburu di-hang-up, persis gejala
        // "Tersambung tapi durasi pendek/langsung berakhir". Pola sama dengan
        // createPc() di crm/mynvr/assets/js/walkie.js (masa tenggang 8 detik).
        let disconnectedGraceTimer = null;
        pc.onconnectionstatechange = () => {
            if (!pc) return;
            dbg('connectionState -> ' + pc.connectionState);
            if (pc.connectionState === 'connected') {
                clearTimeout(stuckConnectingTimer);
                if (disconnectedGraceTimer) { clearTimeout(disconnectedGraceTimer); disconnectedGraceTimer = null; }
                return;
            }
            if (['failed', 'closed'].includes(pc.connectionState)) {
                clearTimeout(stuckConnectingTimer);
                if (disconnectedGraceTimer) { clearTimeout(disconnectedGraceTimer); disconnectedGraceTimer = null; }
                const cid = callId;
                cleanupAndClose('Panggilan terputus.');
                post('end', { call_id: cid });
                return;
            }
            if (pc.connectionState === 'disconnected' && !disconnectedGraceTimer) {
                disconnectedGraceTimer = setTimeout(() => {
                    disconnectedGraceTimer = null;
                    if (pc && pc.connectionState === 'disconnected') {
                        const cid = callId;
                        cleanupAndClose('Panggilan terputus.');
                        post('end', { call_id: cid });
                    }
                }, 8000);
            }
        };

        let answerRes;
        try {
            await pc.setRemoteDescription(JSON.parse(detail.offer_sdp));
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            answerRes = await post('answer', { call_id: callId, answer_sdp: JSON.stringify(pc.localDescription) });
        } catch (err) {
            cleanupAndClose('Gagal memulai panggilan: ' + err.message);
            return;
        }
        // post() resolve normal (bukan exception) walau server balas
        // success:false (mis. call sudah declined/expired duluan) -- WAJIB
        // dicek eksplisit, kalau tidak UI tetap nampilin "Tersambung" walau
        // sebenarnya gagal.
        if (!answerRes || !answerRes.success) {
            dbg('POST answer GAGAL: ' + JSON.stringify(answerRes));
            cleanupAndClose('Panggilan sudah tidak berlaku (kemungkinan sudah berakhir/dialihkan).');
            return;
        }
        dbg('POST answer SUKSES, mulai negosiasi ICE...');

        answered = true;
        statusText.textContent = 'Tersambung';
        cancelBtn.classList.add('d-none');
        actionsRow.classList.remove('d-none');
        timerEl.classList.remove('d-none');
        seconds = 0;
        timerInterval = setInterval(() => {
            seconds++;
            const m = String(Math.floor(seconds / 60)).padStart(2, '0');
            const s = String(seconds % 60).padStart(2, '0');
            timerEl.textContent = m + ':' + s;
        }, 1000);

        pollTimer = setInterval(() => {
            if (pollInFlight || ended) return;
            pollInFlight = true;
            post('get_call', { call_id: callId }).finally(() => { pollInFlight = false; }).then(res => {
                if (!res || !res.success) return;
                if (res.status === 'ended' || res.status === 'declined') {
                    cleanupAndClose(res.status === 'declined' ? 'Panggilan berakhir.' : 'Panggilan berakhir.');
                    return;
                }
                try {
                    const list = JSON.parse(res.ice_customer || '[]');
                    list.forEach(cand => {
                        const key = JSON.stringify(cand);
                        if (seenRemoteIce[key]) return;
                        seenRemoteIce[key] = true;
                        pc.addIceCandidate(cand).catch(() => {});
                    });
                } catch (_) {}
            }).catch(() => {});
        }, 2000);
    }

    document.getElementById('btnHangup').addEventListener('click', function () {
        const cid = callId;
        cleanupAndClose('Panggilan diakhiri.');
        post('end', { call_id: cid });
    });

    // Tombol ini yang muncul SELAMA fase "Menghubungkan..." (sebelum
    // tersambung) -- sebelumnya sama sekali TIDAK ADA cara membatalkan/
    // menutup popup di fase ini selain nutup tab manual. Pakai 'decline'
    // (bukan 'end') supaya pelanggan otomatis dialihkan ke agent lain,
    // bukan langsung dianggap "panggilan berakhir" tanpa ada yg jawab.
    cancelBtn.addEventListener('click', function () {
        const cid = callId;
        cleanupAndClose('Dibatalkan.');
        post('decline', { call_id: cid });
    });

    document.getElementById('btnMute').addEventListener('click', function () {
        if (!localStream) return;
        const track = localStream.getAudioTracks()[0];
        if (!track) return;
        track.enabled = !track.enabled;
        this.classList.toggle('active', !track.enabled);
        this.innerHTML = track.enabled ? '<i class="bi bi-mic-fill"></i>' : '<i class="bi bi-mic-mute-fill"></i>';
    });

    // Popup ini SATU-SATUNYA tempat panggilan hidup -- kalau ditutup manual
    // (klik X tab) tanpa lewat tombol Akhiri/Batal, tetap kirim aksi yang
    // sesuai supaya busy-lock agent ke-clear & tidak nyangkut "Sibuk"
    // selamanya. PENTING: guard-nya cuma `!ended` (BUKAN `!ended && pc`) --
    // kalau tab ditutup SEBELUM `pc` sempat dibuat (mis. masih nunggu
    // get_call/getUserMedia), call itu masih 'ringing'+busy-lock tapi
    // sebelumnya sama sekali tidak ke-cleanup krn syarat `pc` truthy gagal.
    // Kalau belum sempat terjawab (`!answered`), pakai 'decline' supaya
    // pelanggan dialihkan ke agent lain; kalau sudah tersambung, 'end'.
    // Tidak perlu dialog konfirmasi kayak halaman utama (nutup jendela ini
    // MEMANG berarti mengakhiri call, sudah jelas dari fungsinya).
    window.addEventListener('unload', function () {
        if (!ended) {
            const action = answered ? 'end' : 'decline';
            navigator.sendBeacon('cs_call_center.php', new URLSearchParams({ action: action, call_id: callId }));
        }
    });

    start();
})();
</script>
</body>
</html>
