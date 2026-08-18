<?php
/**
 * cs_call_center_agent_widget.php - Widget GLOBAL deteksi "agent aktif" +
 * panggilan masuk utk CS Call Center. Di-include dari header.php (SEMUA
 * halaman billing yang sudah login), BUKAN cuma cs_call_center.php --
 * supaya heartbeat (`callcenter_last_heartbeat`) tetap ke-update & panggilan
 * masuk tetap terdeteksi di halaman MANAPUN si agent lagi buka, bukan cuma
 * pas dia lagi buka menu CS Call Center itu sendiri.
 *
 * Toggle ON/OFF sendiri TETAP di cs_call_center.php (Tab 1 Agent Aktif /
 * halaman 1-tab assistant) -- widget ini cuma jaga heartbeat + tangani
 * panggilan masuk begitu toggle-nya sudah ON, di manapun agent itu browsing.
 *
 * Panggilan yang DITERIMA dibuka di TAB/WINDOW BARU (cs_call_center_incall.php)
 * yang berdiri sendiri -- supaya agent bisa lanjut pindah2 menu di tab utama
 * TANPA memutus call (navigasi halaman biasa selalu mematikan javascript+
 * RTCPeerConnection, jadi call HARUS hidup di tab terpisah yang tidak ikut
 * ke-navigate).
 */
require_once __DIR__ . '/callcenter_access_helper.php';
require_once __DIR__ . '/cs_call_center_helper.php';

$_csccAgentUsername = ($AKSES === 'ASSISTANT') ? $asistant_name : $ceknama;
$_csccOwnerKey = csCallCenterScopeKey($AKSES, $ceknama);
$_csccSettingsForRingtone = csCallCenterGetSettings($conn, $_csccOwnerKey);
$_csccRingtone = ($_csccSettingsForRingtone['agent_ringtone'] ?? '') ?: 'bell1.mp3';
?>
<div class="modal fade" id="csccGlobalIncomingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-phone-alt me-2"></i>Panggilan Masuk</h5>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-2 text-muted">Pelanggan</div>
                <h4 id="csccGlobalIncomingCustomerName">-</h4>
                <div class="text-muted small" id="csccGlobalIncomingCustomerIdpel"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <button class="btn btn-danger px-4" id="btnCsccGlobalDecline"><i class="fas fa-phone-slash me-1"></i>Tolak</button>
                <button class="btn btn-success px-4" id="btnCsccGlobalAccept"><i class="fas fa-phone me-1"></i>Terima</button>
            </div>
        </div>
    </div>
</div>
<audio id="csccGlobalRingtone" loop preload="auto" src="assets/notifcall/<?php echo htmlspecialchars($_csccRingtone); ?>"></audio>

<script>
(function () {
    const modalEl = document.getElementById('csccGlobalIncomingModal');
    const ringtone = document.getElementById('csccGlobalRingtone');

    function getModal() {
        // Lazy -- jangan construct bootstrap.Modal() sebelum bootstrap.min.js
        // (dimuat footer.php, di bawah widget ini di HTML) selesai load.
        return bootstrap.Modal.getOrCreateInstance(modalEl);
    }

    function post(action, data) {
        data = data || {};
        data.action = action;
        return fetch('cs_call_center.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString()
        }).then(r => r.json());
    }

    let pollInFlight = false;
    let pendingCallId = null;
    let ringtoneRetryTimer = null;

    function startRingtone() {
        stopRingtone();
        ringtone.currentTime = 0;
        ringtone.play().catch(() => {
            ringtoneRetryTimer = setInterval(() => {
                ringtone.play().then(() => { clearInterval(ringtoneRetryTimer); ringtoneRetryTimer = null; }).catch(() => {});
            }, 1500);
        });
    }
    function stopRingtone() {
        if (ringtoneRetryTimer) { clearInterval(ringtoneRetryTimer); ringtoneRetryTimer = null; }
        ringtone.pause();
        ringtone.currentTime = 0;
    }

    function agentPoll() {
        if (pollInFlight) return;
        pollInFlight = true;
        post('agent_poll', {}).finally(() => { pollInFlight = false; }).then(res => {
            if (!res || !res.success) return;
            if (res.incoming && !pendingCallId) {
                pendingCallId = res.incoming.call_id;
                document.getElementById('csccGlobalIncomingCustomerName').textContent = res.incoming.customer_name || 'Pelanggan';
                document.getElementById('csccGlobalIncomingCustomerIdpel').textContent = res.incoming.customer_idpel || '';
                startRingtone();
                getModal().show();
                if (document.hidden || !document.hasFocus()) {
                    try { navigator.vibrate([220, 120, 220, 120, 220]); } catch (_) {}
                }
            } else if (!res.incoming && pendingCallId) {
                // Akun yang sama bisa login di beberapa sesi/tab/device sekaligus
                // (masing-masing polling independen) -- kalau panggilan ini sudah
                // diterima/ditolak/expired di SESI LAIN, status di server berubah
                // duluan, jadi sesi ini juga harus berhenti berdering & tutup modal
                // supaya tidak "nyangkut" berdering selamanya.
                pendingCallId = null;
                stopRingtone();
                getModal().hide();
            }
        }).catch(() => {});
    }
    // Heartbeat + deteksi panggilan masuk jalan tiap 5 detik, DI HALAMAN
    // MANAPUN widget ini ke-load (semua halaman billing) -- inilah yang
    // bikin status "agent aktif" konsisten kemanapun agent pindah menu,
    // bukan cuma pas lagi buka halaman CS Call Center.
    setInterval(agentPoll, 5000);
    agentPoll();

    document.getElementById('btnCsccGlobalDecline').addEventListener('click', function () {
        stopRingtone();
        getModal().hide();
        const callId = pendingCallId;
        pendingCallId = null;
        if (callId) post('decline', { call_id: callId });
    });

    document.getElementById('btnCsccGlobalAccept').addEventListener('click', function () {
        const callId = pendingCallId;
        if (!callId) return;
        stopRingtone();
        getModal().hide();
        pendingCallId = null;
        // window.open HARUS dipanggil SINKRON di dalam click handler ini
        // (bukan setelah await/fetch) supaya tidak diblokir popup blocker.
        // Halaman baru itu sendiri yang urus getUserMedia/RTCPeerConnection/
        // answer -- lihat cs_call_center_incall.php.
        window.open(
            'cs_call_center_incall.php?call_id=' + encodeURIComponent(callId),
            'cscc_incall_' + callId,
            'width=380,height=640,noopener'
        );
    });
})();
</script>
