<?php
/**
 * cs_call_widget.php - Widget tombol Telepon CS + overlay panggilan fullscreen
 * (WebRTC, sisi pelanggan). Di-include dari halaman portal manapun yang ingin
 * menampilkan tombol call (portal_chat.php, portal_baru.php, dst) -- supaya
 * tidak duplikat ~250 baris JS di tiap halaman.
 *
 * WAJIB: file pemanggil harus sudah `include 'cek_sesi.php'` DULU (baik
 * langsung atau lewat cek_sesi include lain) sebelum include file ini,
 * supaya $idpel & $logo_path sudah terisi.
 *
 * Tombol trigger-nya (id="btnOpenCall") BUKAN bagian dari file ini -- taruh
 * sendiri di markup header masing2 halaman (pakai class cc-call-btn biar
 * gaya konsisten), supaya nempel wajar di header, bukan floating. File ini
 * cuma nyediain CSS + overlay fullscreen + audio + JS-nya; JS-nya sendiri
 * cari elemen #btnOpenCall di manapun dia ditaruh di DOM.
 */
?>
<style>
    .cc-call-btn {
        background-color: #4caf50;
        color: #fff;
        border: none;
        border-radius: 50%;
        width: 38px;
        height: 38px;
        font-size: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .cc-call-btn.d-none { display: none !important; }

    /* Overlay panggilan fullscreen */
    #ccOverlay {
        position: fixed;
        inset: 0;
        background: linear-gradient(180deg, #0a1628 0%, #1a2e48 60%, #0d1b2a 100%);
        color: #fff;
        z-index: 2000;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: space-between;
        padding: 30px 20px;
        text-align: center;
    }
    #ccOverlay.d-none { display: none !important; }
    #ccOverlay img.cc-logo { width: 90px; height: 90px; object-fit: contain; border-radius: 16px; background: #fff; padding: 8px; margin-top: 20px; }
    #ccOverlay .cc-status { margin-top: 16px; font-size: 15px; opacity: .85; }
    #ccOverlay .cc-timer { margin-top: 6px; font-size: 22px; font-weight: 600; }
    #ccOverlay .cc-timer.d-none { display: none !important; }
    #ccOverlay .cc-actions { display: flex; justify-content: center; gap: 22px; margin-bottom: 10px; width: 100%; }
    #ccOverlay .cc-actions button {
        width: 58px; height: 58px; border-radius: 50%; border: none;
        font-size: 20px; display: flex; align-items: center; justify-content: center;
        background: rgba(255,255,255,.15); color: #fff;
    }
    #ccOverlay .cc-actions button.active { background: #fff; color: #0a1628; }
    #ccOverlay .cc-actions button.cc-hangup { background: #e53935; color: #fff; width: 68px; height: 68px; font-size: 24px; }
    #ccOverlay .cc-cancel-ringing {
        background: rgba(229,57,53,.9);
        color: #fff;
        border: none;
        border-radius: 50%;
        width: 68px;
        height: 68px;
        font-size: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 10px;
    }
    #ccOverlay .cc-cancel-ringing.d-none { display: none !important; }
    #ccOverlay .cc-mic-permission {
        background: #ffc107;
        color: #1a1a1a;
        border: none;
        border-radius: 10px;
        padding: 12px 18px;
        font-weight: 700;
        width: 100%;
        max-width: 360px;
        margin-bottom: 8px;
    }
    #ccOverlay .cc-mic-permission.d-none { display: none !important; }
    #ccOverlay .cc-mic-note { font-size: 12px; opacity: .8; max-width: 360px; margin-bottom: 16px; }
    #btnCcCloseOverlay.d-none { display: none !important; }

    /* Layar kunci -- supaya HP tidak "kesenggol" pas ditempel ke telinga saat call */
    #ccLockScreen {
        position: fixed;
        inset: 0;
        z-index: 2100;
        background: linear-gradient(180deg, #0a1628 0%, #1a2e48 60%, #0d1b2a 100%);
        color: #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 22px;
        text-align: center;
        padding: 20px;
    }
    #ccLockScreen.d-none { display: none !important; }
    .cc-lock-status { font-size: 16px; opacity: .85; }
    .cc-lock-timer { font-size: 26px; font-weight: 700; margin-top: -12px; }
    .cc-lock-track {
        position: relative;
        width: 280px;
        max-width: 82vw;
        height: 56px;
        background: rgba(255,255,255,.12);
        border-radius: 28px;
        display: flex;
        align-items: center;
        touch-action: none;
        user-select: none;
        margin-top: 10px;
    }
    .cc-lock-handle {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #fff;
        color: #0a1628;
        display: flex;
        align-items: center;
        justify-content: center;
        position: absolute;
        left: 4px;
        font-size: 18px;
        cursor: grab;
        transition: left .18s ease;
    }
    .cc-lock-track.dragging .cc-lock-handle { transition: none; }
    .cc-lock-label {
        width: 100%;
        text-align: center;
        font-size: 13px;
        opacity: .7;
        pointer-events: none;
    }
    #ccOverlay .cc-actions button.cc-lock-toggle { background: rgba(255,255,255,.15); }
</style>

<div id="ccOverlay" class="d-none">
    <div>
        <img class="cc-logo" src="<?= htmlspecialchars($logo_path); ?>" alt="Logo" onerror="this.style.display='none';">
        <div class="cc-status" id="ccStatusText">Menyiapkan panggilan...</div>
        <div class="cc-timer d-none" id="ccTimer">00:00</div>
    </div>
    <div>
        <button type="button" class="cc-cancel-ringing d-none" id="btnCcCancelRinging" title="Batalkan Panggilan">
            <i class="bi bi-telephone-x-fill"></i>
        </button>
        <div class="cc-actions" id="ccActiveActions" style="display:none;">
            <button type="button" id="btnCcMute" title="Silent"><i class="bi bi-mic-fill"></i></button>
            <button type="button" class="cc-hangup" id="btnCcHangup" title="Tutup Telepon"><i class="bi bi-telephone-x-fill"></i></button>
            <button type="button" id="btnCcSpeaker" title="Loudspeaker"><i class="bi bi-volume-up-fill"></i></button>
            <button type="button" class="cc-lock-toggle" id="btnCcLock" title="Kunci Layar"><i class="bi bi-lock-fill"></i></button>
        </div>
        <button type="button" class="cc-mic-permission d-none" id="btnCcMicPermission">
            <i class="bi bi-mic-fill me-1"></i>Izinkan Akses Mikrofon
        </button>
        <div class="cc-mic-note" id="ccMicNote"></div>
        <button type="button" class="btn btn-sm btn-outline-light d-none" id="btnCcCloseOverlay">Tutup</button>
    </div>
    <div id="ccDebugLog" class="d-none" style="width:100%;max-width:360px;margin-top:12px;background:#000;color:#0f0;font-family:monospace;font-size:10px;padding:8px;border-radius:6px;max-height:160px;overflow-y:auto;text-align:left;white-space:pre-wrap;"></div>
</div>

<!-- Layar kunci -- muncul setelah tombol Kunci ditekan, cegah kesenggol layar saat HP ditempel ke telinga -->
<div id="ccLockScreen" class="d-none">
    <div class="cc-lock-status">Panggilan terkunci</div>
    <div class="cc-lock-timer" id="ccLockTimer">00:00</div>
    <div class="cc-lock-track" id="ccLockTrack">
        <div class="cc-lock-handle" id="ccLockHandle"><i class="bi bi-unlock-fill"></i></div>
        <span class="cc-lock-label">Geser untuk Buka Kunci</span>
    </div>
</div>

<audio id="ccRemoteAudio" autoplay></audio>
<audio id="ccRingbackAudio" loop preload="auto" src="../assets/notifcall/ringging.mp3"></audio>

<script>
(function () {
    const idpel = <?= json_encode((string)$idpel) ?>;
    const btnOpen = document.getElementById('btnOpenCall');
    const overlay = document.getElementById('ccOverlay');
    const statusText = document.getElementById('ccStatusText');
    const timerEl = document.getElementById('ccTimer');
    const activeActions = document.getElementById('ccActiveActions');
    const micPermBtn = document.getElementById('btnCcMicPermission');
    const micNote = document.getElementById('ccMicNote');
    const closeBtn = document.getElementById('btnCcCloseOverlay');
    const cancelRingingBtn = document.getElementById('btnCcCancelRinging');
    const remoteAudio = document.getElementById('ccRemoteAudio');
    const ringback = document.getElementById('ccRingbackAudio');
    const btnLock = document.getElementById('btnCcLock');
    const lockScreen = document.getElementById('ccLockScreen');
    const lockTimerEl = document.getElementById('ccLockTimer');
    const lockTrack = document.getElementById('ccLockTrack');
    const lockHandle = document.getElementById('ccLockHandle');

    const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
    micNote.textContent = isMobile
        ? 'Mikrofon HP Anda biasanya sudah otomatis tersedia -- cukup izinkan akses saat diminta browser.'
        : 'Pastikan PC/Laptop Anda memiliki mikrofon yang terpasang dan berfungsi sebelum menelepon. Di HP (Android/browser mobile), mikrofon bawaan biasanya langsung terdeteksi tanpa perlu perangkat tambahan.';

    const ccDebugLog = document.getElementById('ccDebugLog');
    let ccDebugMode = false;
    function dbg(msg) {
        if (!ccDebugMode || !ccDebugLog) return;
        const t = new Date().toISOString().substr(11, 8);
        ccDebugLog.textContent += '[' + t + '] PELANGGAN: ' + msg + '\n';
        ccDebugLog.scrollTop = ccDebugLog.scrollHeight;
    }

    let iceServers = { iceServers: [] };
    let pc = null;
    let localStream = null;
    let activeCallId = null;
    let getPollTimer = null;
    let getPollInFlight = false;
    let callTimerInterval = null;
    let callSeconds = 0;
    let seenRemoteIce = {};
    let ringbackRetryTimer = null;
    let ccQueuedIceCandidates = [];

    function flushCcQueuedIceCandidates() {
        if (!activeCallId || !ccQueuedIceCandidates.length) return;
        const pending = ccQueuedIceCandidates.slice();
        ccQueuedIceCandidates = [];
        pending.forEach(candidate => {
            post('ice', { call_id: activeCallId, candidate: JSON.stringify(candidate) });
        });
    }

    function post(action, data) {
        // cek_sesi.php (di-include API) resolve pelanggan dari $_GET['cari'],
        // BUKAN $_POST -- makanya idpel WAJIB ikut di query string URL,
        // bukan cuma di body POST. Path selalu absolut ke folder broadband/
        // supaya widget ini tetap jalan dipanggil dari halaman manapun.
        data = data || {};
        data.action = action;
        return fetch('cs_call_center_api.php?cari=' + encodeURIComponent(idpel), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        }).then(r => r.json());
    }

    let _ccAgentAvailLogged = false;
    function checkAgentAvailable() {
        post('agent_available', {}).then(res => {
            if (!res || !res.success) return;
            if (res.debug_mode && !ccDebugMode) {
                ccDebugMode = true;
                if (ccDebugLog) ccDebugLog.classList.remove('d-none');
            }
            if (!_ccAgentAvailLogged) {
                _ccAgentAvailLogged = true;
                dbg('agent_available: ' + (res.available ? 'ADA agent' : 'TIDAK ADA agent') + ', ICE servers: ' + JSON.stringify((res.ice_servers || []).map(s => s.urls)));
            }
            if (res.available) {
                btnOpen.classList.remove('d-none');
                iceServers = { iceServers: res.ice_servers || [] };
            } else {
                btnOpen.classList.add('d-none');
            }
        }).catch(() => {});
    }
    checkAgentAvailable();
    setInterval(checkAgentAvailable, 20000);

    function startRingback() {
        stopRingback();
        ringback.currentTime = 0;
        ringback.play().catch(() => {
            ringbackRetryTimer = setInterval(() => {
                ringback.play().then(() => { clearInterval(ringbackRetryTimer); ringbackRetryTimer = null; }).catch(() => {});
            }, 1500);
        });
    }
    function stopRingback() {
        if (ringbackRetryTimer) { clearInterval(ringbackRetryTimer); ringbackRetryTimer = null; }
        ringback.pause();
        ringback.currentTime = 0;
    }

    function resetOverlayState() {
        activeActions.style.display = 'none';
        timerEl.classList.add('d-none');
        micPermBtn.classList.add('d-none');
        closeBtn.classList.add('d-none');
        cancelRingingBtn.classList.add('d-none');
        lockScreen.classList.add('d-none');
        resetLockHandlePosition();
    }

    function cleanupCall(finalMessage) {
        stopRingback();
        if (getPollTimer) { clearInterval(getPollTimer); getPollTimer = null; }
        if (callTimerInterval) { clearInterval(callTimerInterval); callTimerInterval = null; }
        callSeconds = 0;
        if (pc) { try { pc.close(); } catch (_) {} pc = null; }
        if (localStream) { localStream.getTracks().forEach(t => t.stop()); localStream = null; }
        seenRemoteIce = {};
        ccQueuedIceCandidates = [];
        const callId = activeCallId;
        activeCallId = null;
        resetOverlayState();
        if (finalMessage) {
            statusText.textContent = finalMessage;
            closeBtn.classList.remove('d-none');
        } else {
            overlay.classList.add('d-none');
        }
        return callId;
    }

    async function startCallFlow() {
        overlay.classList.remove('d-none');
        resetOverlayState();
        statusText.textContent = 'Menyiapkan mikrofon...';

        try {
            localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
            dbg('getUserMedia OK, audio tracks: ' + localStream.getAudioTracks().length);
        } catch (err) {
            dbg('getUserMedia GAGAL: ' + err.message);
            statusText.textContent = 'Tidak bisa mengakses mikrofon.';
            micPermBtn.classList.remove('d-none');
            closeBtn.classList.remove('d-none');
            return;
        }

        statusText.textContent = 'Memanggil CS...';
        dbg('ICE servers dipakai: ' + JSON.stringify(iceServers.iceServers.map(s => s.urls)));
        pc = new RTCPeerConnection(iceServers);
        localStream.getTracks().forEach(t => pc.addTrack(t, localStream));
        pc.ontrack = (e) => {
            dbg('ontrack diterima (audio remote datang)');
            remoteAudio.srcObject = e.streams[0];
            // Atribut `autoplay` saja kadang diam-diam diblokir browser (audio
            // context ter-suspend / kebijakan autoplay ketat) tanpa error yang
            // kelihatan -- suara jadi mati padahal koneksi sudah "Tersambung".
            // Paksa play() eksplisit + retry, sama pola dgn startRingtone() di
            // cs_call_center_agent_widget.php.
            const tryPlayRemoteAudio = () => {
                remoteAudio.play().then(() => dbg('remoteAudio.play() OK')).catch((e2) => { dbg('remoteAudio.play() GAGAL: ' + e2.message + ', retry 1s'); setTimeout(tryPlayRemoteAudio, 1000); });
            };
            tryPlayRemoteAudio();
        };
        pc.onicecandidate = (e) => {
            if (!e.candidate) { dbg('ICE gathering selesai (kandidat habis)'); return; }
            const cstr = e.candidate.candidate || '';
            const typeMatch = cstr.match(/typ (\w+)/);
            dbg((activeCallId ? 'Kirim' : 'ANTRE (call_id blm ada)') + ' kandidat ICE: type=' + (typeMatch ? typeMatch[1] : '?') + ' -> ' + cstr.substring(0, 60));
            // WAJIB antre kalau activeCallId belum ada -- setLocalDescription()
            // di bawah mulai proses gathering ICE SEKARANG JUGA, tapi
            // activeCallId baru terisi SETELAH round-trip request start_call
            // selesai. Tanpa antrian ini, SEMUA kandidat yang keluar di jendela
            // itu DIBUANG DIAM-DIAM (bukan cuma di-skip sementara) -- di LAN
            // jarang kerasa krn round-trip start_call cepat, tapi lintas
            // jaringan (round-trip lebih lambat + kandidat TURN/relay yg
            // paling penting juga lebih lambat keluar) inilah yang bikin
            // panggilan "connect" (SDP sukses) tapi TANPA SUARA. Pola antrian
            // sama persis dgn queuedIceCandidates/flushQueuedIceCandidates()
            // di qchat/assets/js/webrtc.js.
            if (!activeCallId) {
                ccQueuedIceCandidates.push(e.candidate);
                return;
            }
            post('ice', { call_id: activeCallId, candidate: JSON.stringify(e.candidate) });
        };
        // 'disconnected' beda dari 'failed'/'closed' -- browser masih coba
        // pulihkan sendiri di background (blip jaringan sesaat, WAJAR &
        // sering pulih sendiri dalam beberapa detik, apalagi lintas jaringan
        // yang jelas lebih tidak stabil dari LAN). Sebelumnya kondisi ini
        // langsung dianggap gagal & call diputus paksa -- akibatnya panggilan
        // yang sebenarnya bisa pulih malah keburu di-hang-up. Pola sama
        // dengan createPc() di crm/mynvr/assets/js/walkie.js (masa tenggang
        // 8 detik) -- lihat juga cs_call_center_incall.php (sisi agent).
        let ccDisconnectedGraceTimer = null;
        let ccStuckConnectingTimer = null;
        pc.oniceconnectionstatechange = () => { dbg('iceConnectionState -> ' + pc.iceConnectionState); };
        pc.onicegatheringstatechange = () => { dbg('iceGatheringState -> ' + pc.iceGatheringState); };
        pc.onconnectionstatechange = () => {
            if (!pc) return;
            dbg('connectionState -> ' + pc.connectionState);
            if (pc.connectionState === 'connected') {
                if (ccStuckConnectingTimer) { clearTimeout(ccStuckConnectingTimer); ccStuckConnectingTimer = null; }
                if (ccDisconnectedGraceTimer) { clearTimeout(ccDisconnectedGraceTimer); ccDisconnectedGraceTimer = null; }
                return;
            }
            if (['failed', 'closed'].includes(pc.connectionState)) {
                if (ccStuckConnectingTimer) { clearTimeout(ccStuckConnectingTimer); ccStuckConnectingTimer = null; }
                if (ccDisconnectedGraceTimer) { clearTimeout(ccDisconnectedGraceTimer); ccDisconnectedGraceTimer = null; }
                const cid = cleanupCall('Panggilan terputus.');
                if (cid) post('end', { call_id: cid });
                return;
            }
            if (pc.connectionState === 'disconnected' && !ccDisconnectedGraceTimer) {
                ccDisconnectedGraceTimer = setTimeout(() => {
                    ccDisconnectedGraceTimer = null;
                    if (pc && pc.connectionState === 'disconnected') {
                        const cid = cleanupCall('Panggilan terputus.');
                        if (cid) post('end', { call_id: cid });
                    }
                }, 8000);
            }
        };

        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);

        const res = await post('start_call', { offer_sdp: JSON.stringify(pc.localDescription) }).catch(() => null);
        if (!res || !res.success) {
            cleanupCall('Gagal memulai panggilan, coba lagi.');
            return;
        }

        if (res.status === 'no_agent') {
            cleanupCall('Semua agent sedang sibuk, silakan coba lagi nanti.');
            return;
        }

        activeCallId = res.call_id;
        dbg('start_call sukses, call_id=' + activeCallId + ', flush ' + ccQueuedIceCandidates.length + ' kandidat yg sempat antre');
        flushCcQueuedIceCandidates();
        statusText.textContent = 'Menghubungi agent...';
        startRingback();
        // Sebelumnya TIDAK ADA cara membatalkan panggilan selama fase
        // menunggu diangkat -- pelanggan cuma bisa nunggu/nutup tab paksa.
        cancelRingingBtn.classList.remove('d-none');

        getPollTimer = setInterval(() => {
            if (getPollInFlight || !activeCallId) return;
            getPollInFlight = true;
            post('get', { call_id: activeCallId }).finally(() => { getPollInFlight = false; }).then(async (r) => {
                if (!r || !r.success || !activeCallId) return;

                if (r.status === 'declined' || r.status === 'ended' || r.status === 'no_agent') {
                    cleanupCall(r.status === 'declined' ? 'Panggilan ditolak.' : 'Panggilan berakhir.');
                    return;
                }

                if (r.status === 'active' && r.answer_sdp && pc && pc.currentRemoteDescription === null) {
                    stopRingback();
                    await pc.setRemoteDescription(JSON.parse(r.answer_sdp));
                    // WAJIB: kalau PC tidak PERNAH mencapai 'connected' dalam
                    // waktu wajar SETELAH remote description terpasang --
                    // browser kadang macet permanen di 'checking'/'connecting'
                    // tanpa pernah transisi ke 'failed' (TURN lambat/flaky).
                    // Dihitung dari sini (bukan dari awal panggilan) krn
                    // sebelum ini pc memang belum mulai negosiasi ICE sama
                    // sekali -- masih nunggu agent jawab. Pola sama dgn
                    // createPc() di crm/mynvr/assets/js/walkie.js.
                    const pcForTimeout = pc;
                    ccStuckConnectingTimer = setTimeout(() => {
                        if (pc === pcForTimeout && pc && pc.connectionState !== 'connected') {
                            const cid = cleanupCall('Gagal tersambung (timeout).');
                            if (cid) post('end', { call_id: cid });
                        }
                    }, 15000);
                    cancelRingingBtn.classList.add('d-none');
                    activeActions.style.display = 'flex';
                    timerEl.classList.remove('d-none');
                    statusText.textContent = 'Tersambung';
                    callSeconds = 0;
                    callTimerInterval = setInterval(() => {
                        callSeconds++;
                        const m = String(Math.floor(callSeconds / 60)).padStart(2, '0');
                        const s = String(callSeconds % 60).padStart(2, '0');
                        const formatted = m + ':' + s;
                        timerEl.textContent = formatted;
                        lockTimerEl.textContent = formatted;
                    }, 1000);
                    // Otomatis kunci begitu call terangkat/tersambung (angka
                    // durasi mulai muncul) -- pelanggan tinggal geser sendiri
                    // kalau mau buka, tidak perlu tap tombol kunci manual dulu.
                    engageLock();
                }

                if (pc && pc.currentRemoteDescription !== null) {
                    try {
                        const list = JSON.parse(r.ice_agent || '[]');
                        list.forEach(cand => {
                            const key = JSON.stringify(cand);
                            if (seenRemoteIce[key]) return;
                            seenRemoteIce[key] = true;
                            pc.addIceCandidate(cand).catch(() => {});
                        });
                    } catch (_) {}
                }
            }).catch(() => {});
        }, 1500);
    }

    if (btnOpen) btnOpen.addEventListener('click', startCallFlow);
    if (micPermBtn) micPermBtn.addEventListener('click', startCallFlow);

    document.getElementById('btnCcHangup').addEventListener('click', function () {
        const cid = cleanupCall(null);
        if (cid) post('end', { call_id: cid });
    });

    // Batalkan panggilan SELAGI MASIH RINGING (belum diangkat agent) --
    // sebelumnya tombol ini tidak ada sama sekali di fase ini.
    cancelRingingBtn.addEventListener('click', function () {
        const cid = cleanupCall(null);
        if (cid) post('end', { call_id: cid });
    });

    document.getElementById('btnCcMute').addEventListener('click', function () {
        if (!localStream) return;
        const track = localStream.getAudioTracks()[0];
        if (!track) return;
        track.enabled = !track.enabled;
        this.classList.toggle('active', !track.enabled);
        this.innerHTML = track.enabled ? '<i class="bi bi-mic-fill"></i>' : '<i class="bi bi-mic-mute-fill"></i>';
    });

    document.getElementById('btnCcSpeaker').addEventListener('click', function () {
        const btn = this;
        if (typeof remoteAudio.setSinkId !== 'function' || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
            alert('Perangkat/browser ini tidak mendukung pengalihan speaker manual.');
            return;
        }
        const wantSpeaker = !btn.classList.contains('active');
        navigator.mediaDevices.enumerateDevices().then(devices => {
            const outputs = devices.filter(d => d.kind === 'audiooutput');
            let target;
            if (wantSpeaker) {
                target = outputs.find(d => /speaker/i.test(d.label));
            } else {
                target = outputs.find(d => /earpiece|receiver/i.test(d.label));
            }
            // Fallback: kalau perangkat tidak expose label earpiece/speaker
            // yang cocok (browser/HP tertentu), coba device output "default"
            // sebagai pengganti earpiece.
            if (!target && !wantSpeaker) {
                target = outputs.find(d => d.deviceId === 'default') || outputs[0];
            }
            if (!target) {
                alert('Perangkat output "' + (wantSpeaker ? 'speaker' : 'earpiece') + '" tidak terdeteksi di HP ini.');
                return;
            }
            remoteAudio.setSinkId(target.deviceId).then(() => {
                btn.classList.toggle('active', wantSpeaker);
            }).catch(() => {
                alert('Gagal mengalihkan output audio ke ' + (wantSpeaker ? 'speaker' : 'earpiece') + '.');
            });
        }).catch(() => {});
    });

    closeBtn.addEventListener('click', function () {
        overlay.classList.add('d-none');
    });

    // ───────────── Kunci layar (slide to unlock) -- cegah kesenggol tombol
    // pas HP ditempel ke telinga selama call berlangsung ─────────────
    const LOCK_HANDLE_MARGIN = 4; // sama dgn `left: 4px` di CSS .cc-lock-handle
    function lockTrackTravel() {
        return lockTrack.clientWidth - lockHandle.offsetWidth - (LOCK_HANDLE_MARGIN * 2);
    }
    function resetLockHandlePosition() {
        lockTrack.classList.remove('dragging');
        lockHandle.style.left = LOCK_HANDLE_MARGIN + 'px';
    }
    function engageLock() {
        lockTimerEl.textContent = timerEl.textContent;
        resetLockHandlePosition();
        lockScreen.classList.remove('d-none');
    }

    if (btnLock) {
        btnLock.addEventListener('click', engageLock);
    }

    (function setupLockDrag() {
        let dragging = false;
        let startX = 0;
        let startLeft = LOCK_HANDLE_MARGIN;

        lockHandle.addEventListener('pointerdown', function (e) {
            dragging = true;
            startX = e.clientX;
            startLeft = parseFloat(lockHandle.style.left) || LOCK_HANDLE_MARGIN;
            lockTrack.classList.add('dragging');
            try { lockHandle.setPointerCapture(e.pointerId); } catch (_) {}
        });

        lockHandle.addEventListener('pointermove', function (e) {
            if (!dragging) return;
            const travel = lockTrackTravel();
            let next = startLeft + (e.clientX - startX);
            next = Math.max(LOCK_HANDLE_MARGIN, Math.min(LOCK_HANDLE_MARGIN + travel, next));
            lockHandle.style.left = next + 'px';
        });

        function endDrag(e) {
            if (!dragging) return;
            dragging = false;
            lockTrack.classList.remove('dragging');
            const travel = lockTrackTravel();
            const current = (parseFloat(lockHandle.style.left) || LOCK_HANDLE_MARGIN) - LOCK_HANDLE_MARGIN;
            if (travel > 0 && current / travel >= 0.8) {
                // Terbuka -- sembunyikan layar kunci, kembalikan posisi handle utk lain kali.
                lockScreen.classList.add('d-none');
                resetLockHandlePosition();
            } else {
                // Belum cukup jauh -- kembali ke kiri (animasi via transisi CSS).
                resetLockHandlePosition();
            }
        }
        lockHandle.addEventListener('pointerup', endDrag);
        lockHandle.addEventListener('pointercancel', endDrag);
    })();

    // Sama spt sisi agent -- pindah menu (Beranda/Bayar/dst di bottom nav) =
    // full page navigation yg mematikan javascript+RTCPeerConnection halaman
    // ini. Warn dulu lewat dialog konfirmasi native browser di 'beforeunload'
    // (kasih kesempatan Batal), baru kirim 'end' di 'unload' KALAU pelanggan
    // jadi pindah -- jangan kirim 'end' di beforeunload langsung, supaya
    // klik Batal tidak ikut mengakhiri call.
    window.addEventListener('beforeunload', function (e) {
        if (activeCallId) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    window.addEventListener('unload', function () {
        if (activeCallId) {
            navigator.sendBeacon('cs_call_center_api.php?cari=' + encodeURIComponent(idpel), new URLSearchParams({ action: 'end', call_id: activeCallId }));
        }
    });
})();
</script>
